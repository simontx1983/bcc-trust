<?php
/**
 * A5b — the five §4.32.4 comment routes, through REAL WordPress REST dispatch.
 *
 * ⚠⚠ RUN ONLY IN A DISPOSABLE ENVIRONMENT. It enables
 * `bcc_announcements_enabled`, creates users, a validator page, a claim chain,
 * announcements, comments, a SUSPENDED user and a below-neutral score row, then
 * removes all of it. Shared staging keeps the switch OFF and that is a hold.
 *
 *   wp --allow-root --skip-themes eval-file \
 *     wp-content/plugins/bcc-trust/scripts/tests/announcement-comment-routes.php
 *
 * ── ⚠⚠ THE HEADLINE THIS PROBE EXISTS FOR ─────────────────────────────────
 * `can_comment: allowed` WAS UNREACHABLE. `AnnouncementsEndpoint::viewerStanding()`
 * returned only `['authenticated' => bool]`, and the capability resolver reads
 * four keys and fails closed on an absent one — so `good_standing` read as
 * false for every viewer and the gate always fell through to `suspended`. No
 * member, however eligible, could ever be told they may comment.
 *
 * Section C proves the fix in BOTH directions, which is the only way the
 * "allowed" means anything: an eligible member is ALLOWED, and four distinct
 * ineligible viewers are each denied with their OWN reason. A resolver that
 * returned one constant could not produce five different answers.
 *
 * ── STANDING COMES FROM THE ESTABLISHED SERVICES ──────────────────────────
 *   suspended      bcc_trust_user_info.is_suspended, through
 *                  Permissions::is_not_suspended($id, false) — admin bypass OFF
 *   good_standing  reputation tier >= neutral, through
 *                  UserViewService::isInGoodStanding()
 *
 * ⚠ A member with NO score row defaults to `neutral`, which IS good standing —
 * so a brand-new member can comment. Only an explicit `risky` or `caution` tier
 * denies. Section C4 seeds a real `risky` row rather than asserting that from
 * the code.
 */

// ⚠ No declare(strict_types=1): `wp eval-file` EVALS this file.
if (!defined('ABSPATH')) {
    fwrite(STDERR, "must run inside WordPress\n");
    exit(1);
}

use BCC\Trust\Core\Repositories\AnnouncementCommentRepository as CommentRepo;
use BCC\Trust\Core\Announcements\AnnouncementCommentPolicy as Policy;
use BCC\Trust\Core\Announcements\AnnouncementPostType as CPT;
use BCC\Trust\Core\Announcements\AnnouncementVisibility as VIS;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver as CAPS;
use BCC\Trust\Core\Announcements\AnnouncementViewerStanding as STANDING;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as AUTH;

global $wpdb;

$GLOBALS['P'] = 0;
$GLOBALS['F'] = 0;

function ck(string $label, $expected, $actual): void
{
    $ok = $expected === $actual;
    $GLOBALS[$ok ? 'P' : 'F']++;
    $f = static function ($v) {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v)) {
            return json_encode($v);
        }

        return $v === null ? 'null' : (string) $v;
    };
    printf("%-4s %-64s exp=%-22s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, $f($expected), $f($actual));
}

function dsp(string $method, string $route, array $params = [], int $asUser = 0): array
{
    wp_set_current_user($asUser);
    $req = new WP_REST_Request($method, $route);
    foreach ($params as $k => $v) {
        $req->set_param($k, $v);
    }
    $res  = rest_get_server()->dispatch($req);
    $data = $res->get_data();

    return [
        'status' => $res->get_status(),
        'code'   => is_array($data) ? ($data['error']['code'] ?? ($data['code'] ?? null)) : null,
        'reason' => is_array($data) ? ($data['error']['data']['reason_code'] ?? null) : null,
        'data'   => is_array($data) ? ($data['data'] ?? []) : [],
        'json'   => (string) json_encode($data),
    ];
}

function rawId($viewId): int
{
    return (int) preg_replace('/\D+/', '', (string) $viewId);
}

/** Make a user genuinely suspended through the real source of truth. */
function suspend(int $userId, bool $on): void
{
    global $wpdb;
    $t = $wpdb->prefix . 'bcc_trust_user_info';
    $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$t` WHERE user_id = %d", $userId));
    if ($exists > 0) {
        $wpdb->update($t, ['is_suspended' => $on ? 1 : 0], ['user_id' => $userId], ['%d'], ['%d']);
    } else {
        $wpdb->insert($t, ['user_id' => $userId, 'is_suspended' => $on ? 1 : 0], ['%d', '%d']);
    }
    // ⚠ The suspension answer is cached for 60s in the cross-plugin seam, so a
    // probe that only wrote the row would read the stale value.
    \BCC\Core\Permissions\Permissions::flushSuspensionCache($userId);
}

/** Give a user an explicit below-neutral reputation tier. */
function setTier(int $userId, string $tier): void
{
    global $wpdb;
    $t      = $wpdb->prefix . 'bcc_trust_page_scores';
    $pageId = MemberSelfPageService::selfPageId($userId);
    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `$t` WHERE page_id = %d AND category_id = 0", $pageId
    ));
    if ($exists > 0) {
        $wpdb->update($t, ['reputation_tier' => $tier], ['page_id' => $pageId, 'category_id' => 0], ['%s'], ['%d', '%d']);
    } else {
        $wpdb->insert($t, [
            'page_id'       => $pageId,
            'category_id'   => 0,
            'page_owner_id' => $userId,
            'total_score'   => 10.00,
            'reputation_tier' => $tier,
        ], ['%d', '%d', '%d', '%f', '%s']);
    }
}

/** The can_comment entry as a given viewer sees it on the thread envelope. */
function canComment(int $announcementId, int $asUser): array
{
    $r = dsp('GET', '/bcc/v1/announcements/' . $announcementId . '/comments', [], $asUser);

    return (array) ($r['data']['can_comment'] ?? []);
}

// ─────────────────────────────────────────────────────────────────────────
echo "=== SEED: users, validator page, verified claim chain, announcements ===\n";

$op      = wp_insert_user(['user_login' => 'cr_op_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$member  = wp_insert_user(['user_login' => 'cr_mem_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$other   = wp_insert_user(['user_login' => 'cr_oth_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$susp    = wp_insert_user(['user_login' => 'cr_sus_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$risky   = wp_insert_user(['user_login' => 'cr_rsk_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$admin   = wp_insert_user(['user_login' => 'cr_adm_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'administrator']);
ck('six users created', true, min($op, $member, $other, $susp, $risky, $admin) > 0);

$pageId = wp_insert_post([
    'post_type' => 'peepso-page', 'post_status' => 'publish',
    'post_title' => 'Comment Routes Probe', 'post_author' => $op,
]);
$chainId = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}bcc_chains LIMIT 1");
$addr    = 'cosmosvaloper1cmroutes';
$wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
    'user_id' => $op, 'post_id' => $pageId, 'wallet_address' => $addr,
    'chain_id' => $chainId, 'wallet_type' => 'validator', 'verified_at' => current_time('mysql', true),
]);
$linkId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
    'wallet_link_id' => $linkId, 'operator_address' => $addr, 'chain_id' => $chainId,
    'moniker' => 'CR', 'status' => 'active', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
]);
$valId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', [
    'user_id' => $op, 'entity_type' => 'validator', 'entity_id' => $valId,
    'wallet_address' => $addr, 'chain_id' => $chainId, 'claim_role' => 'operator',
    'status' => 'verified', 'verified_at' => current_time('mysql', true),
]);
$claimId = (int) $wpdb->insert_id;
ck('AUTHORITY resolves the operator', 'operator', AUTH::decide($pageId, $op)['decision']);

update_option('bcc_announcements_enabled', '1');

/** Create a published announcement directly, with a valid publication marker. */
$mkAnn = static function (string $title, bool $commentsEnabled = true, bool $archived = false) use ($pageId, $op, $wpdb) {
    $past = gmdate('Y-m-d H:i:s', time() - 300);
    $id = wp_insert_post([
        'post_type' => CPT::POST_TYPE, 'post_status' => 'publish', 'post_parent' => $pageId,
        'post_author' => $op, 'post_title' => $title, 'post_excerpt' => 'summary',
        'post_date_gmt' => $past, 'post_date' => $past,
    ]);
    update_post_meta($id, VIS::META_AUTHORIZED_AT, $past);
    update_post_meta($id, VIS::META_AUTHORIZED_USER_ID, (string) $op);
    if (!$commentsEnabled) {
        update_post_meta($id, VIS::META_COMMENTS_ENABLED, '0');
    }
    if ($archived) {
        update_post_meta($id, VIS::META_ARCHIVED_AT, $past);
    }
    clean_post_cache($id);

    return (int) $id;
};

$ann      = $mkAnn('Open thread');
$annOff   = $mkAnn('Comments off', false);
$annArch  = $mkAnn('Archived', true, true);
$draft    = wp_insert_post([
    'post_type' => CPT::POST_TYPE, 'post_status' => 'draft', 'post_parent' => $pageId,
    'post_author' => $op, 'post_title' => 'Draft', 'post_excerpt' => 's',
    'post_date_gmt' => gmdate('Y-m-d H:i:s'), 'post_date' => gmdate('Y-m-d H:i:s'),
]);
ck('announcements seeded', true, $ann > 0 && $annOff > 0 && $annArch > 0 && $draft > 0);

$base = '/bcc/v1/announcements/' . $ann . '/comments';

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== A. THE THREAD READS, and a bare numeric id is what the route takes ===\n";

$r = dsp('GET', $base, [], 0);
ck('A1 anonymous may read the thread', 200, $r['status']);
ck('A1 items is a list', true, is_array($r['data']['items'] ?? null));
ck('A1 empty thread, has_more false', false, (bool) ($r['data']['pagination']['has_more'] ?? true));
// ⚠ `?? 'x'` cannot distinguish a NULL value from an ABSENT key — both
// yield the default. The contract specifies the key is PRESENT and null, so
// both halves are asserted separately.
ck('A1 next_cursor key is present', true, array_key_exists('next_cursor', (array) ($r['data']['pagination'] ?? [])));
ck('A1 and it is null on an empty thread', null, ($r['data']['pagination'] ?? [])['next_cursor']);

// ⚠ §4.32.4: path ids are BARE NUMERIC. A prefixed id must not route.
$r = dsp('GET', '/bcc/v1/announcements/ann_' . $ann . '/comments', [], 0);
ck('A2 ⚠ a PREFIXED id does not route (bare numeric only)', 404, $r['status']);
ck('A2 and it is WP route-matching that refuses, not the handler', 'rest_no_route', $r['code']);

// ⚠ §4.32.4 read-side rule: a not-yet-public row is 404, hiding EXISTENCE.
$r = dsp('GET', '/bcc/v1/announcements/' . $draft . '/comments', [], 0);
ck('A3 ⚠ a DRAFT announcement thread is 404, not 403', 404, $r['status']);
ck('A3 and the handler own refusal', 'bcc_not_found', $r['code']);
$r = dsp('GET', '/bcc/v1/announcements/' . $draft . '/comments', [], $op);
ck('A3 ⚠⚠ 404 even for the OPERATOR — existence is hidden, not ownership', 404, $r['status']);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== B. CREATE — flat, plain text, and who may ===\n";

$r = dsp('POST', $base, ['body' => 'Thanks for the heads up.'], $member);
ck('B1 ⚠⚠ an ELIGIBLE member may comment', 201, $r['status']);
$c1 = rawId($r['data']['id'] ?? '');
ck('B1 the id is prefixed on the wire', true, strpos((string) ($r['data']['id'] ?? ''), 'acmt_') === 0);
ck('B1 is_removed false', false, (bool) ($r['data']['is_removed'] ?? true));
ck('B1 body is stored verbatim', 'Thanks for the heads up.', (string) ($r['data']['body'] ?? ''));
ck('B1 author block carries the id', $member, (int) ($r['data']['author']['id'] ?? 0));

// ⚠ ANY present parent_id is refused, including 0.
foreach ([5, 0] as $pid) {
    $r = dsp('POST', $base, ['body' => 'reply', 'parent_id' => $pid], $member);
    ck('B2 parent_id=' . $pid . ' -> 400', 400, $r['status']);
    ck('B2 reason is the contract code', 'replies_not_supported', $r['reason']);
}

$r = dsp('POST', $base, ['body' => '   '], $member);
ck('B3 a blank body -> 400', 400, $r['status']);
$r = dsp('POST', $base, ['body' => str_repeat('x', Policy::MAX_LEN + 1)], $member);
ck('B3 an oversized body -> 400', 400, $r['status']);
$r = dsp('POST', $base, [], $member);
ck('B3 an ABSENT body -> 400 from the HANDLER, not the schema', 400, $r['status']);
ck('B3 ⚠ so the feature-off 404 stays reachable', 'bcc_invalid_request', $r['code']);

$r = dsp('POST', $base, ['body' => 'anon'], 0);
ck('B4 anonymous create -> 403', 403, $r['status']);
ck('B4 with the auth reason', 'auth_required', $r['reason']);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== C. ⚠⚠ can_comment — ALLOWED for an eligible viewer, denied otherwise ===\n";
// This is the section the viewerStanding() fix exists for. Before it, EVERY
// row below read `suspended`, including the eligible member.

$cc = canComment($ann, $member);
ck('C1 ⚠⚠ eligible member: ALLOWED', true, (bool) ($cc['allowed'] ?? false));
ck('C1 reason_code key is present', true, array_key_exists('reason_code', $cc));
ck('C1 and it is null when allowed', null, $cc['reason_code']);

$cc = canComment($ann, 0);
ck('C2 anonymous: denied', false, (bool) ($cc['allowed'] ?? true));
ck('C2 reason auth_required', 'auth_required', $cc['reason_code'] ?? null);

suspend($susp, true);
ck('C3 the user really is suspended at the source of truth', false,
    \BCC\Core\Permissions\Permissions::is_not_suspended($susp, false));
$cc = canComment($ann, $susp);
ck('C3 suspended: denied', false, (bool) ($cc['allowed'] ?? true));
ck('C3 reason suspended', 'suspended', $cc['reason_code'] ?? null);
$r = dsp('POST', $base, ['body' => 'nope'], $susp);
ck('C3 and the CREATE is refused too', 403, $r['status']);
ck('C3 with the same reason', 'suspended', $r['reason']);

setTier($risky, 'risky');
$cc = canComment($ann, $risky);
ck('C4 ⚠ below-neutral tier: denied', false, (bool) ($cc['allowed'] ?? true));
ck('C4 reason suspended (standing and suspension present alike)', 'suspended', $cc['reason_code'] ?? null);

$cc = canComment($annOff, $member);
ck('C5 comments disabled: denied', false, (bool) ($cc['allowed'] ?? true));
ck('C5 reason comments_disabled', 'comments_disabled', $cc['reason_code'] ?? null);
$r = dsp('POST', '/bcc/v1/announcements/' . $annOff . '/comments', ['body' => 'x'], $member);
ck('C5 and the CREATE is 403', 403, $r['status']);
ck('C5 with comments_disabled', 'comments_disabled', $r['reason']);

$cc = canComment($annArch, $member);
ck('C6 archived: denied', false, (bool) ($cc['allowed'] ?? true));
ck('C6 reason announcement_archived', 'announcement_archived', $cc['reason_code'] ?? null);
$r = dsp('POST', '/bcc/v1/announcements/' . $annArch . '/comments', ['body' => 'x'], $member);
ck('C6 and the CREATE is 403', 403, $r['status']);

// ⚠⚠ THE CONTROL THAT MAKES SECTION C MEAN SOMETHING: five viewer/row states
// produced FIVE DISTINCT answers. A resolver returning one constant, or a
// standing context missing a key, could not do that — and before the fix every
// one of these read `suspended`.
$answers = [
    (string) (canComment($ann, $member)['reason_code'] ?? 'ALLOWED'),
    (string) (canComment($ann, 0)['reason_code'] ?? 'ALLOWED'),
    (string) (canComment($ann, $susp)['reason_code'] ?? 'ALLOWED'),
    (string) (canComment($annOff, $member)['reason_code'] ?? 'ALLOWED'),
    (string) (canComment($annArch, $member)['reason_code'] ?? 'ALLOWED'),
];
// ⚠ FIVE, not four. An earlier version of this assertion expected 4 on the
// assumption that two of the states would collide; they do not. The five
// outcomes are ALLOWED, auth_required, suspended, comments_disabled and
// announcement_archived — and the count being EXACTLY the number of states is
// the strongest form of this control: every state is distinguishable.
ck('C7 ⚠⚠ five states -> five DISTINCT outcomes', 5, count(array_unique($answers)));
ck('C7 and they are the expected vocabulary', true, $answers === array_unique($answers));
ck('C7 and exactly one of them is ALLOWED', 1, count(array_filter($answers, static fn ($a) => $a === 'ALLOWED')));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== D. TOMBSTONE PRIVACY — four keys, and nothing else ===\n";

$r = dsp('POST', $base, ['body' => 'This will be removed.'], $other);
$c2 = rawId($r['data']['id'] ?? '');
ck('D0 a second comment exists', true, $c2 > 0);

$r = dsp('DELETE', $base . '/' . $c2, [], $other);
ck('D1 the author may remove their own', 200, $r['status']);
ck('D1 the response is a tombstone', true, (bool) ($r['data']['is_removed'] ?? false));

$thread = dsp('GET', $base, [], $member);
$tomb = null;
foreach (($thread['data']['items'] ?? []) as $item) {
    if (rawId($item['id'] ?? '') === $c2) {
        $tomb = $item;
    }
}
ck('D2 the tombstone is still IN the thread', true, $tomb !== null);
ck('D2 ⚠⚠ EXACTLY four keys', ['id', 'is_removed', 'label', 'removed_at'],
    (static function ($t) { $k = array_keys((array) $t); sort($k); return $k; })($tomb));
ck('D2 label is server-authored', Policy::LABEL_AUTHOR, (string) ($tomb['label'] ?? ''));
foreach (['body', 'author', 'avatar_url', 'user_id', 'removed_actor', 'permissions', 'reason'] as $leak) {
    ck('D2 carries NO ' . $leak, false, array_key_exists($leak, (array) $tomb));
}
// ⚠ And the raw JSON of the whole thread must not contain the removed body —
// a key-set check alone would miss it leaking through a nested structure.
ck('D2 ⚠ the removed BODY appears nowhere in the payload', false,
    strpos($thread['json'], 'This will be removed.') !== false);

// §4.32.7 — a tombstone counts toward comment_count.
$det = dsp('GET', '/bcc/v1/validators/' . $pageId . '/announcements/' . $ann, [], 0);
ck('D3 ⚠ the tombstone COUNTS toward comment_count', 2, (int) ($det['data']['comment_count'] ?? -1));

// An operator removing someone else's comment is recorded as `validator`.
$r = dsp('POST', $base, ['body' => 'Operator will remove this.'], $other);
$c3 = rawId($r['data']['id'] ?? '');
$r = dsp('DELETE', $base . '/' . $c3, [], $op);
ck('D4 the operator may remove another member comment', 200, $r['status']);
ck('D4 ⚠ and the label says VALIDATOR', Policy::LABEL_VALIDATOR, (string) ($r['data']['label'] ?? ''));

// ⚠ RELATIONSHIP BEFORE ROLE: the operator removing their OWN comment is
// recorded as `author`, not `validator`.
$r = dsp('POST', $base, ['body' => 'Operator own comment.'], $op);
$c4 = rawId($r['data']['id'] ?? '');
$r = dsp('DELETE', $base . '/' . $c4, [], $op);
ck('D5 ⚠⚠ operator removing their OWN is labelled AUTHOR', Policy::LABEL_AUTHOR, (string) ($r['data']['label'] ?? ''));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== E. REMOVAL PERMISSIONS — one public reason, whoever asks ===\n";

$r = dsp('POST', $base, ['body' => 'Mine.'], $member);
$c5 = rawId($r['data']['id'] ?? '');

$r = dsp('DELETE', $base . '/' . $c5, [], $other);
ck('E1 a stranger may not remove it', 403, $r['status']);
ck('E1 reason not_comment_author', 'not_comment_author', $r['reason']);
$r = dsp('DELETE', $base . '/' . $c5, [], 0);
ck('E2 anonymous may not remove it', 403, $r['status']);
ck('E2 ⚠ the SAME reason, so the author cannot be inferred', 'not_comment_author', $r['reason']);
$r = dsp('DELETE', $base . '/' . $c5, [], $admin);
ck('E3 ⚠ an ADMIN may not remove either (§4.32.7: restore only)', 403, $r['status']);
ck('E3 with the same public reason', 'not_comment_author', $r['reason']);

ck('E4 and the comment is still readable', false,
    (bool) ((static function ($items) use ($c5) {
        foreach ($items as $i) { if (rawId($i['id'] ?? '') === $c5) { return $i['is_removed'] ?? false; } }
        return true;
    })(dsp('GET', $base, [], $member)['data']['items'] ?? [])));

$r = dsp('DELETE', $base . '/' . $c5, [], $member);
ck('E5 the author may', 200, $r['status']);
$r = dsp('DELETE', $base . '/' . $c5, [], $member);
ck('E5 ⚠ removing twice is a conflict, not a re-stamp', 409, $r['status']);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== F. ADMIN SURFACES — the private original, and restore ===\n";

$r = dsp('GET', '/bcc/v1/admin/announcement-comments/' . $c5, [], $admin);
ck('F1 admin may read the private original', 200, $r['status']);
ck('F1 ⚠⚠ and it carries the ORIGINAL body', 'Mine.', (string) ($r['data']['original_body'] ?? ''));
ck('F1 it is labelled removed', true, (bool) ($r['data']['is_removed'] ?? false));
ck('F1 and names the actor (admin surface only)', Policy::ACTOR_AUTHOR, (string) ($r['data']['removed_actor'] ?? ''));

$r = dsp('GET', '/bcc/v1/admin/announcement-comments/' . $c5, [], $member);
ck('F2 ⚠ a MEMBER may not reach the admin surface', true, in_array($r['status'], [401, 403], true));
ck('F2 ⚠ and the original body is NOT in that response', false,
    strpos($r['json'], 'Mine.') !== false);
$r = dsp('GET', '/bcc/v1/admin/announcement-comments/' . $c5, [], 0);
ck('F2 anonymous likewise refused', true, in_array($r['status'], [401, 403], true));

$r = dsp('POST', '/bcc/v1/admin/announcement-comments/' . $c5 . '/restore', [], $member);
ck('F3 ⚠ a member may not restore (§4.32.7 admin-only)', true, in_array($r['status'], [401, 403], true));

$r = dsp('POST', '/bcc/v1/admin/announcement-comments/' . $c5 . '/restore', [], $admin);
ck('F4 admin may restore', 200, $r['status']);
ck('F4 and it reports not-removed', false, (bool) ($r['data']['is_removed'] ?? true));
$restored = null;
foreach ((dsp('GET', $base, [], $member)['data']['items'] ?? []) as $i) {
    if (rawId($i['id'] ?? '') === $c5) { $restored = $i; }
}
ck('F4 ⚠ the body is readable again', 'Mine.', (string) ($restored['body'] ?? ''));
ck('F4 and the author block is back', $member, (int) ($restored['author']['id'] ?? 0));
$r = dsp('POST', '/bcc/v1/admin/announcement-comments/' . $c5 . '/restore', [], $admin);
ck('F5 restoring twice is a conflict', 409, $r['status']);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== G. ORDER AND PAGING — oldest first, tombstones in position ===\n";
$thread = dsp('GET', $base, ['limit' => 50], $member);
$ids = array_map(static fn ($i) => rawId($i['id'] ?? ''), (array) ($thread['data']['items'] ?? []));
$sorted = $ids;
sort($sorted);
ck('G1 ⚠ oldest-first: ids ascend', $sorted, $ids);
ck('G1 the thread holds every comment made', 5, count($ids));

$p1 = dsp('GET', $base, ['limit' => 2], $member);
ck('G2 a limited page returns 2', 2, count((array) ($p1['data']['items'] ?? [])));
ck('G2 and reports more', true, (bool) ($p1['data']['pagination']['has_more'] ?? false));
$cur = (string) ($p1['data']['pagination']['next_cursor'] ?? '');
ck('G2 with a cursor', true, $cur !== '');
$p2 = dsp('GET', $base, ['limit' => 2, 'cursor' => $cur], $member);
$ids2 = array_map(static fn ($i) => rawId($i['id'] ?? ''), (array) ($p2['data']['items'] ?? []));
$ids1 = array_map(static fn ($i) => rawId($i['id'] ?? ''), (array) ($p1['data']['items'] ?? []));
ck('G2 ⚠ page 2 is DISJOINT from page 1', [], array_values(array_intersect($ids1, $ids2)));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== H. ⚠⚠ THE STANDING GAP, MEASURED BOTH WAYS IN ONE PROCESS ===\n";
// The gap A5b closes is a MISMATCH BETWEEN TWO FILES, and the only honest way
// to show it is to run the resolver twice against the same row: once with the
// array the old caller built, once with the array the new producer builds. No
// re-deploy and no second tree, so the difference is attributable to the one
// thing that changed.

$detail   = '/bcc/v1/validators/' . $pageId . '/announcements/' . $ann;
$capsRow  = ['status' => CAPS::STATUS_PUBLISHED, 'is_archived' => false, 'comments_enabled' => true];
$capsDec  = AUTH::decide($pageId, $member);

// ⚠ `['authenticated' => true]` IS the array the shipped caller built, key for
// key. Every other key was absent, and the resolver fails closed on an absent
// key — correctly. That is the entire defect.
$old = CAPS::forRow($capsDec, $capsRow, ['authenticated' => true], true);
$new = CAPS::forRow($capsDec, $capsRow, STANDING::forViewer($member), true);

ck('H1 OLD partial array: can_comment DENIED', false, (bool) ($old['can_comment']['allowed'] ?? true));
ck('H1 ...and the reason was `suspended` for a member who is not', 'suspended',
    $old['can_comment']['reason_code'] ?? null);
ck('H2 ⚠⚠ NEW complete array: can_comment ALLOWED', true, (bool) ($new['can_comment']['allowed'] ?? false));
// ⚠ `?? 'SET'` CANNOT distinguish null from absent, and
// `Permission::allow()` returns `reason_code => null` — so the first
// version of this line failed against correct code. Presence and value
// are two assertions, the same shape section C already uses.
ck('H2 ...the reason key is present', true, array_key_exists('reason_code', $new['can_comment']));
ck('H2 ...and it is null when allowed', null, $new['can_comment']['reason_code']);

// ⚠⚠ AND THE SAME FIX REACHES `can_report`, WHICH IS NOT A COMMENT
// CAPABILITY. `report()` reads three of the same four keys, so the partial
// array denied reporting too, for every viewer, through the live detail route.
// A5b therefore changes a surface beyond comments — disclosed here rather than
// discovered later.
ck('H3 OLD partial array: can_report DENIED too', false, (bool) ($old['can_report']['allowed'] ?? true));
ck('H3 ...also as `suspended`', 'suspended', $old['can_report']['reason_code'] ?? null);
ck('H4 ⚠⚠ NEW complete array: can_report ALLOWED', true, (bool) ($new['can_report']['allowed'] ?? false));

// The live route agrees with the in-process pair, so this is a property of the
// shipped detail read and not of a hand-built call.
$r = dsp('GET', $detail, [], $member);
ck('H5 detail read answers 200 for an eligible member', 200, $r['status']);
$caps = (array) ($r['data']['capabilities'] ?? []);
ck('H5 detail carries capabilities', true, $caps !== []);
ck('H5 ⚠⚠ live can_report is ALLOWED on the detail route', true,
    (bool) ($caps['can_report']['allowed'] ?? false));
ck('H5 live can_comment is ALLOWED on the detail route', true,
    (bool) ($caps['can_comment']['allowed'] ?? false));

// And the denials still hold on that same surface, so J5 is not "everything is
// allowed now".
suspend($susp, true);
$r    = dsp('GET', $detail, [], $susp);
$caps = (array) ($r['data']['capabilities'] ?? []);
ck('H6 a suspended viewer is still denied can_report', false,
    (bool) ($caps['can_report']['allowed'] ?? true));
ck('H6 ...and still denied can_comment', false,
    (bool) ($caps['can_comment']['allowed'] ?? true));
suspend($susp, false);

$r    = dsp('GET', $detail, [], 0);
$caps = (array) ($r['data']['capabilities'] ?? []);
ck('H7 an anonymous viewer is denied with auth_required, not suspended',
    'auth_required', $caps['can_comment']['reason_code'] ?? null);
ck('H7 ...and the same on can_report', 'auth_required',
    $caps['can_report']['reason_code'] ?? null);

// ⚠ `already_reported` is the one $viewer key the producer deliberately does
// NOT supply: its absence means "not yet reported" and ALLOWS, so it is the
// reporting slice's dedupe convenience rather than a standing fact.
$standing = STANDING::forViewer($member);
ck('H8 the producer supplies exactly four keys', 4, count($standing));
ck('H8 and already_reported is not one of them', false,
    array_key_exists('already_reported', $standing));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== I. FEATURE OFF — all five routes 404 (§4.32.10) ===\n";
delete_option('bcc_announcements_enabled');
foreach ([
    ['GET',    $base,                                                        []],
    ['POST',   $base,                                                        ['body' => 'x']],
    ['DELETE', $base . '/' . $c1,                                            []],
    ['GET',    '/bcc/v1/admin/announcement-comments/' . $c1,                 []],
    ['POST',   '/bcc/v1/admin/announcement-comments/' . $c1 . '/restore',    []],
] as [$m, $route, $params]) {
    $user = str_contains($route, '/admin/') ? $admin : $member;
    $res  = dsp($m, $route, $params, $user);
    ck('I ' . $m . ' ' . (str_contains($route, '/admin/') ? 'admin' : 'member') . ' -> 404', 404, $res['status']);
    ck('I   the handler own refusal', 'bcc_not_found', $res['code']);
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== J. ⚠⚠ FEATURE OFF, ANONYMOUS — the case section I could not see ===\n";
// ⚠⚠ Section I probes the two /admin/ routes AS AN ADMINISTRATOR
// (`str_contains($route, '/admin/') ? $admin : $member`), so it only ever
// exercised the path that REACHES the handler. An anonymous caller is stopped
// by the `permission_callback` FIRST, and with `BearerAuth::requireAdmin`
// wired there directly that answered `401 rest_forbidden` — telling an
// unauthenticated caller that a switched-off surface exists.
//
// §4.32.10 is unqualified: "While OFF: every route returns 404." So every one
// of the five is probed here with NO user at all.
delete_option('bcc_announcements_enabled');

foreach ([
    ['GET',    $base,                                                     []],
    ['POST',   $base,                                                     ['body' => 'x']],
    ['DELETE', $base . '/' . $c1,                                         []],
    ['GET',    '/bcc/v1/admin/announcement-comments/' . $c1,              []],
    ['POST',   '/bcc/v1/admin/announcement-comments/' . $c1 . '/restore', []],
] as [$m, $route, $params]) {
    $label = str_contains($route, '/admin/') ? 'admin route' : 'member route';
    $res   = dsp($m, $route, $params, 0);
    ck('J ' . $m . ' ' . $label . ', ANONYMOUS -> 404', 404, $res['status']);
    ck('J   and it is the handler own refusal, not a callback denial',
        'bcc_not_found', $res['code']);
}

// ⚠ The control that keeps the fix honest: with the feature ON, an anonymous
// caller must STILL be refused by the admin gate. If this started answering
// 200 or 404, the fix would have turned a permission check into a no-op.
update_option('bcc_announcements_enabled', '1');
foreach ([
    ['GET',  '/bcc/v1/admin/announcement-comments/' . $c1,              []],
    ['POST', '/bcc/v1/admin/announcement-comments/' . $c1 . '/restore', []],
] as [$m, $route, $params]) {
    $res = dsp($m, $route, $params, 0);
    ck('J ON + anonymous ' . $m . ' admin route is still refused', true,
        in_array((int) $res['status'], [401, 403], true));
    ck('J   ...and it is NOT a 404 (the surface exists once enabled)', false,
        (int) $res['status'] === 404);
}
// ...and a non-administrator member is refused too, not just an anonymous one.
foreach ([
    ['GET',  '/bcc/v1/admin/announcement-comments/' . $c1,              []],
    ['POST', '/bcc/v1/admin/announcement-comments/' . $c1 . '/restore', []],
] as [$m, $route, $params]) {
    $res = dsp($m, $route, $params, $member);
    ck('J ON + non-admin member ' . $m . ' is refused', true,
        in_array((int) $res['status'], [401, 403], true));
}
// An administrator still gets through while ON — so the gate refuses the right
// people rather than everybody.
$res = dsp('GET', '/bcc/v1/admin/announcement-comments/' . $c1, [], $admin);
ck('J ON + administrator reads the private original', 200, $res['status']);

delete_option('bcc_announcements_enabled');

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== TEARDOWN ===\n";
delete_option('bcc_announcements_enabled');
ck('switch deleted, not set to 0', null, get_option('bcc_announcements_enabled', null));

$ids = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d", CPT::POST_TYPE, $pageId
));
$actTeardown = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}peepso_activities'");
foreach ($ids as $id) {
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_type = %s",
        (int) $id, CPT::COMMENT_TYPE
    ));
    if ($actTeardown) {
        $wpdb->delete($wpdb->prefix . 'peepso_activities', ['act_module_id' => 205, 'act_external_id' => (int) $id], ['%d', '%d']);
    }
    wp_delete_post((int) $id, true);
}
wp_delete_post($pageId, true);
$wpdb->delete($wpdb->prefix . 'bcc_onchain_claims', ['entity_id' => $valId]);
$wpdb->delete($wpdb->prefix . 'bcc_onchain_validators', ['id' => $valId]);
$wpdb->delete($wpdb->prefix . 'bcc_wallet_links', ['id' => $linkId]);
foreach ([$op, $member, $other, $susp, $risky, $admin] as $u) {
    $wpdb->delete($wpdb->prefix . 'bcc_trust_user_info', ['user_id' => $u]);
    $wpdb->delete($wpdb->prefix . 'bcc_trust_page_scores', ['page_owner_id' => $u]);
    wp_delete_user($u);
}

ck('no announcement rows remain', 0, (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", CPT::POST_TYPE
)));
ck('no announcement comments remain', 0, (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = %s", CPT::COMMENT_TYPE
)));

// ⚠⚠ ORPHANED REMOVAL META — measured, not assumed. `wp_delete_post()`
// removed the announcement's comments but left their `_bcc_ann_removed*`
// rows in `wp_commentmeta`: 24 rows over 12 comments had accumulated across
// earlier runs of this probe before the sweep below existed.
//
// It is PROBE HYGIENE rather than a product defect — §4.32.6 makes archival
// one-way and there is no delete route, and the CPT is `show_ui => false`, so
// nothing in the product deletes an announcement post. But an accumulating
// orphan set is exactly how a later assertion comes to pass for the wrong
// reason, so the probe now removes them and asserts it.
$orphans = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->commentmeta} m
       LEFT JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id
      WHERE m.meta_key IN ('" . CommentRepo::META_REMOVED_AT . "', '" . CommentRepo::META_REMOVED_ACTOR . "')
        AND c.comment_ID IS NULL"
);
if ($orphans > 0) {
    $wpdb->query(
        "DELETE m FROM {$wpdb->commentmeta} m
           LEFT JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id
          WHERE m.meta_key IN ('" . CommentRepo::META_REMOVED_AT . "', '" . CommentRepo::META_REMOVED_ACTOR . "')
            AND c.comment_ID IS NULL"
    );
}
ck('no orphaned removal meta remains', 0, (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->commentmeta}
      WHERE meta_key IN ('" . CommentRepo::META_REMOVED_AT . "', '" . CommentRepo::META_REMOVED_ACTOR . "')"
));

printf("
==== A5b COMMENT ROUTES: pass=%d fail=%d ====
", $GLOBALS['P'], $GLOBALS['F']);
