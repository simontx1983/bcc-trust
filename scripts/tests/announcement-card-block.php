<?php
/**
 * Gap 1 — `Card.announcements` is the frontend's only feature gate.
 *
 * ⚠⚠ WHY THIS PROBE EXISTS. The frontend mounts BOTH announcement surfaces on
 * `card.announcements != null`, and `CardViewService` emitted no such key, so
 * with the switch ON and all 14 routes live the UI rendered nothing and issued
 * no requests. Fail-closed and correct — and the feature unreachable. No test
 * caught it because every existing test checked the ROUTES, and the gate is on
 * a different surface.
 *
 * Run: wp eval-file .../announcement-card-block.php
 */

use BCC\Trust\Core\Announcements\AnnouncementPostType as CPT;

global $wpdb;
$GLOBALS['G1_P'] = 0;
$GLOBALS['G1_F'] = 0;

function g1(string $l, $e, $g): void
{
    $ok = $e === $g;
    $ok ? $GLOBALS['G1_P']++ : $GLOBALS['G1_F']++;
    printf("%s %-64s exp=%-22s got=%s\n", $ok ? 'PASS' : 'FAIL', $l, var_export($e, true), var_export($g, true));
}

/** The card as one viewer sees it, through the real service. */
function g1_card(string $slug, int $viewerId): array
{
    wp_set_current_user($viewerId);
    $c = \BCC\Trust\Core\Plugin::instance()->cardViewService()->getCard('validator', $slug, $viewerId);
    return is_array($c) ? $c : [];
}

function g1_block(string $slug, int $viewerId): ?array
{
    $c = g1_card($slug, $viewerId);
    return array_key_exists('announcements', $c) ? $c['announcements'] : null;
}

function g1_entry(?array $block, string $key): string
{
    if ($block === null) { return 'KEY-ABSENT'; }
    $e = $block['capabilities'][$key] ?? null;
    if (!is_array($e)) { return 'ENTRY-MISSING'; }
    return ($e['allowed'] ?? false) ? 'allowed' : (string) ($e['reason_code'] ?? 'denied-no-reason');
}

// ── seed: a wallet-link-bound validator page with a verified operator ───
$op     = wp_insert_user(['user_login' => 'g1_op_' . wp_rand(1000, 9999),  'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$member = wp_insert_user(['user_login' => 'g1_mem_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$rival  = wp_insert_user(['user_login' => 'g1_riv_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);

$slug   = 'g1-validator-' . wp_rand(1000, 9999);
$pageId = (int) wp_insert_post(['post_type' => 'peepso-page', 'post_status' => 'publish',
    'post_title' => 'G1 Validator', 'post_name' => $slug]);
update_post_meta($pageId, '_bcc_page_type', 'validator');

$chainId = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}bcc_chains ORDER BY id LIMIT 1");
$addr    = 'g1valoper1' . wp_rand(100000, 999999);
$now     = current_time('mysql', true);

$wpdb->insert($wpdb->prefix . 'bcc_wallet_links', ['user_id' => $op, 'post_id' => $pageId,
    'wallet_address' => $addr, 'chain_id' => $chainId, 'verified_at' => $now, 'is_primary' => 1]);
$linkId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', ['wallet_link_id' => $linkId,
    'operator_address' => $addr, 'chain_id' => $chainId, 'moniker' => 'G1', 'status' => 'active',
    'fetched_at' => $now, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)]);
$valId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', ['user_id' => $op, 'entity_type' => 'validator',
    'entity_id' => $valId, 'wallet_address' => $addr, 'chain_id' => $chainId,
    'claim_role' => 'operator', 'status' => 'verified', 'verified_at' => $now]);
$claimId = (int) $wpdb->insert_id;
wp_cache_flush();

g1('seed: the card itself resolves', true, g1_card($slug, 0) !== []);
g1('seed: operator authority resolves', 'operator',
    \BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority::decide($pageId, $op)['decision']);

// ── A. feature OFF -> the key is OMITTED, never emitted as null ─────────
delete_option('bcc_announcements_enabled');
wp_cache_flush();
$c = g1_card($slug, $op);
g1('A feature OFF: the card still resolves', true, $c !== []);
g1('A ⚠⚠ the `announcements` KEY IS ABSENT, not null', false, array_key_exists('announcements', $c));
g1('A ...even for the resolved operator', 'KEY-ABSENT', g1_entry(g1_block($slug, $op), 'can_create'));

// ── B. feature ON -> the block is present for EVERY viewer ─────────────
update_option('bcc_announcements_enabled', '1');
wp_cache_flush();

$c = g1_card($slug, 0);
g1('B anonymous: the key is PRESENT', true, array_key_exists('announcements', $c));
g1('B anonymous: can_create denied, generic reason', 'not_claimer', g1_entry($c['announcements'], 'can_create'));
g1('B anonymous: can_manage denied, generic reason', 'not_claimer', g1_entry($c['announcements'], 'can_manage'));
// ⚠ Guarded, because on an UNFIXED tree the key is absent and a direct
// subscript fatals — which aborts the run and prints no summary, so the
// control reads as "three failures" instead of "the block is missing".
$caps = (array) (($c['announcements'] ?? [])['capabilities'] ?? []);
g1('B ⚠ the block carries exactly the two page-scoped entries', ['can_create', 'can_manage'],
    array_keys($caps));
g1('B ⚠ and NO row capability leaks into the page scope', false,
    array_key_exists('can_pin', $caps));

$b = g1_block($slug, $op);
g1('C operator: can_create ALLOWED', 'allowed', g1_entry($b, 'can_create'));
g1('C operator: can_manage ALLOWED', 'allowed', g1_entry($b, 'can_manage'));

$b = g1_block($slug, $member);
g1('D non-owner member: can_create denied', 'not_claimer', g1_entry($b, 'can_create'));
g1('D non-owner member: can_manage denied', 'not_claimer', g1_entry($b, 'can_manage'));

// ── E. ⚠⚠ VIEWER CAPABILITIES MUST NOT CROSS ACCOUNTS ──────────────────
// A -> B -> A in one process. If anything cached the card without the
// viewer, the third read would echo the operator's answer back to anonymous.
$first  = g1_entry(g1_block($slug, 0),       'can_create');
$second = g1_entry(g1_block($slug, $op),     'can_create');
$third  = g1_entry(g1_block($slug, 0),       'can_create');
g1('E anonymous -> operator -> anonymous: 1st', 'not_claimer', $first);
g1('E   2nd (operator)', 'allowed', $second);
g1('E ⚠⚠ 3rd is anonymous AGAIN, not the operator answer', 'not_claimer', $third);
$fourth = g1_entry(g1_block($slug, $member), 'can_create');
g1('E   and a different member still gets the denial', 'not_claimer', $fourth);

// ── F. REVOKED claim -> the operator loses the affordances ─────────────
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'revoked'], ['id' => $claimId]);
wp_cache_flush();
g1('F revoked: the former operator is denied', 'not_claimer', g1_entry(g1_block($slug, $op), 'can_create'));
g1('F revoked: the block is STILL emitted (feature is on)', true,
    array_key_exists('announcements', g1_card($slug, 0)));
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'verified'], ['id' => $claimId]);
wp_cache_flush();
g1('F restored: the operator is allowed again', 'allowed', g1_entry(g1_block($slug, $op), 'can_create'));

// ── G. AMBIGUOUS claim -> nobody is the operator, and nothing leaks ────
$wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', ['user_id' => $rival, 'entity_type' => 'validator',
    'entity_id' => $valId, 'wallet_address' => $addr, 'chain_id' => $chainId,
    'claim_role' => 'operator', 'status' => 'verified', 'verified_at' => $now]);
$rivalClaim = (int) $wpdb->insert_id;
wp_cache_flush();
g1('G ambiguous: resolver reports AMBIGUOUS', 'AMBIGUOUS',
    (string) (\BCC\Trust\Onchain\Repositories\ClaimRepository::resolveVerifiedOperatorForPage($pageId)['state'] ?? '?'));
g1('G ambiguous: the first claimant is denied', 'not_claimer', g1_entry(g1_block($slug, $op), 'can_create'));
g1('G ambiguous: the rival is denied too', 'not_claimer', g1_entry(g1_block($slug, $rival), 'can_create'));
// ⚠ §4.32.2 — the wire must not reveal that competing claims exist, so the
// reason is the SAME generic one an ordinary stranger gets.
g1('G ⚠ and the reason is indistinguishable from a stranger', 'not_claimer',
    g1_entry(g1_block($slug, $member), 'can_create'));
$wpdb->delete($wpdb->prefix . 'bcc_onchain_claims', ['id' => $rivalClaim]);
wp_cache_flush();

// ── H. a NON-validator card must not carry the block at all ───────────
$otherSlug = 'g1-builder-' . wp_rand(1000, 9999);
$otherPage = (int) wp_insert_post(['post_type' => 'peepso-page', 'post_status' => 'publish',
    'post_title' => 'G1 Builder', 'post_name' => $otherSlug]);
update_post_meta($otherPage, '_bcc_page_type', 'builder');
wp_cache_flush();
wp_set_current_user($op);
$pc = \BCC\Trust\Core\Plugin::instance()->cardViewService()->getCard('project', $otherSlug, $op);
g1('H a builder card resolves', true, is_array($pc) && $pc !== []);
g1('H ⚠ and carries NO announcements key', false, is_array($pc) && array_key_exists('announcements', $pc));

// ── teardown ──────────────────────────────────────────────────────────
$wpdb->delete($wpdb->prefix . 'bcc_onchain_claims', ['entity_id' => $valId]);
$wpdb->delete($wpdb->prefix . 'bcc_onchain_validators', ['id' => $valId]);
$wpdb->delete($wpdb->prefix . 'bcc_wallet_links', ['id' => $linkId]);
wp_delete_post($pageId, true);
wp_delete_post($otherPage, true);
// ⚠ Section H left $op as the CURRENT user, and wp_delete_user() will not
// remove the acting user — the first version of this teardown silently left
// all three accounts behind while every other assertion passed.
wp_set_current_user(0);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$op, $member, $rival] as $u) { wp_delete_user((int) $u); }
delete_option('bcc_announcements_enabled');
// ⚠ Scoped to what THIS probe made. The first version counted announcement
// posts GLOBALLY and failed against the nine the integration fixture owns —
// this probe creates no announcements at all, so a global count was never its
// business to assert.
g1('teardown: the probe page is gone', null, get_post($pageId));
g1('teardown: its users are gone', 0, (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s", 'g1\_%')));
g1('teardown: its validator row is gone', 0, (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}bcc_onchain_validators WHERE id = %d", $valId)));

printf("\n==== CARD ANNOUNCEMENTS BLOCK: pass=%d fail=%d ====\n", $GLOBALS['G1_P'], $GLOBALS['G1_F']);
