<?php
/**
 * Reconciler arm C — scheduled publication under lapsed authority, through
 * WordPress's REAL cron event.
 *
 * ⚠⚠ DISPOSABLE ENVIRONMENT ONLY. It enables the feature switch, seeds users,
 * a validator page and a claim chain, and drives `publish_future_post`
 * directly. Never point it at staging or production.
 *
 *   wp --allow-root --skip-themes eval-file \
 *     wp-content/plugins/bcc-trust/scripts/tests/announcement-cron-publication-safety.php
 *
 * ── WHY IT DRIVES THE EVENT RATHER THAN WAITING ─────────────────────────────
 * `do_action('publish_future_post', $id)` is EXACTLY what wp-cron invokes —
 * `check_and_publish_future_post()` is registered on that action at priority
 * 10, and the guard at priority 1. Firing the action runs the real handler
 * chain in the real order; waiting for a wall-clock tick would test the
 * scheduler's timing, which is core's business, not ours.
 *
 * ⚠ Rows are seeded with a PAST `post_date_gmt` so core's own "someone jumped
 * the gun" check passes and it genuinely tries to publish. A future date would
 * make core re-schedule and return, and every assertion below would pass for
 * the wrong reason.
 */

// ⚠ No declare(strict_types=1): `wp eval-file` EVALS this file, and a
// strict_types declaration must be the first statement of a script.

if (!defined('ABSPATH')) {
    fwrite(STDERR, "must run inside WordPress\n");
    exit(1);
}

use BCC\Trust\Core\Announcements\AnnouncementFeature as FEATURE;
use BCC\Trust\Core\Announcements\AnnouncementPostType as CPT;
use BCC\Trust\Core\Announcements\AnnouncementPublicationGuard as GUARD;
use BCC\Trust\Core\Announcements\AnnouncementVisibility as VIS;
use BCC\Trust\Core\Repositories\AnnouncementRepository as REPO;
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
    printf("%-4s %-64s exp=%-18s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, $f($expected), $f($actual));
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
        'code'   => is_array($data) ? ($data['error']['code'] ?? null) : null,
        // ⚠⚠ RAW STRINGS, NOT ints. The view model emits PREFIXED ids like
        // `ann_123`. The first version of this probe cast them with (int),
        // which made every id 0 — so every "absent from the list" assertion
        // could NEVER match and passed vacuously, while the single "present"
        // assertion correctly failed and exposed it.
        'ids'    => array_map(
            static fn ($i) => (string) ($i['id'] ?? ''),
            is_array($data) ? ($data['data']['items'] ?? []) : []
        ),
        // the read STATE on a detail view-model, so `blocked` can be asserted
        // on the wire rather than only in the database.
        'rstatus' => is_array($data) ? ($data['data']['status'] ?? null) : null,
        'json'   => (string) json_encode($data),
    ];
}

/** Seed a row already stored as `future` with a PAST due date. */
function seedDue(int $pageId, int $author, string $title): int
{
    global $wpdb;

    $past = gmdate('Y-m-d H:i:s', time() - 600);

    $id = wp_insert_post([
        'post_type'    => CPT::POST_TYPE,
        'post_status'  => 'draft',
        'post_title'   => $title,
        'post_content' => 'body',
        'post_author'  => $author,
        'post_parent'  => $pageId,
    ]);
    // ⚠ Forced directly: wp_insert_post() refuses to store `future` with a past
    // date (it normalises to publish), which would defeat the whole fixture.
    $wpdb->update($wpdb->posts, ['post_status' => 'future', 'post_date_gmt' => $past, 'post_date' => $past], ['ID' => $id]);
    clean_post_cache($id);

    // The marker A3 writes at schedule time.
    update_post_meta($id, VIS::META_AUTHORIZED_AT, gmdate('Y-m-d H:i:s', time() - 3600));
    update_post_meta($id, VIS::META_AUTHORIZED_USER_ID, (string) $author);

    return (int) $id;
}

function isPublic(int $pageId, int $id): bool
{
    return (new REPO())->findPublic($pageId, $id) !== null;
}

function statusOf(int $id): string
{
    global $wpdb;

    return (string) $wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $id));
}

// ─────────────────────────────────────────────────────────────────────────
echo "=== 0. THE HOOK CHAIN IS REAL — guard at 1, core at 10 ===\n";
ck('guard is on publish_future_post', true, has_action('publish_future_post', [GUARD::class, 'vetoScheduledPublication']) !== false);
ck('guard priority is 1 (before core)', 1, has_action('publish_future_post', [GUARD::class, 'vetoScheduledPublication']));
ck('core handler is still on the same action at 10', 10, has_action('publish_future_post', 'check_and_publish_future_post'));
ck('guard is on transition_post_status', true, has_action('transition_post_status', [GUARD::class, 'onTransition']) !== false);

echo "\n=== SEED ===\n";
$op = wp_insert_user(['user_login' => 'cron_op_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$st = wp_insert_user(['user_login' => 'cron_st_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$pageId = wp_insert_post(['post_type' => 'peepso-page', 'post_status' => 'publish', 'post_title' => 'Cron Probe Validator', 'post_author' => $op]);
$chainId = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}bcc_chains LIMIT 1");
$addr = 'cosmosvaloper1cronprobe';

$wpdb->insert($wpdb->prefix . 'bcc_wallet_links', ['user_id' => $op, 'post_id' => $pageId, 'wallet_address' => $addr, 'chain_id' => $chainId, 'wallet_type' => 'validator', 'verified_at' => current_time('mysql', true)]);
$linkId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', ['wallet_link_id' => $linkId, 'operator_address' => $addr, 'chain_id' => $chainId, 'moniker' => 'Cron Probe', 'status' => 'active', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)]);
$valId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', ['user_id' => $op, 'entity_type' => 'validator', 'entity_id' => $valId, 'wallet_address' => $addr, 'chain_id' => $chainId, 'claim_role' => 'operator', 'status' => 'verified', 'verified_at' => current_time('mysql', true)]);
$claimId = (int) $wpdb->insert_id;
ck('authority resolves the operator', 'operator', AUTH::decide($pageId, $op)['decision']);

update_option('bcc_announcements_enabled', '1');
$base  = '/bcc/v1/validators/' . $pageId . '/announcements';
$mbase = '/bcc/v1/me/validators/' . $pageId . '/announcements';

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== 1. CONTROL — authority INTACT: the real cron event DOES publish ===\n";
$good = seedDue($pageId, $op, 'Due, still authorized');
ck('seeded as future', 'future', statusOf($good));
ck('not public before the event', false, isPublic($pageId, $good));

do_action('publish_future_post', $good);
clean_post_cache($good);
wp_cache_flush();

ck('⚠ CONTROL: cron published it', 'publish', statusOf($good));
ck('CONTROL: it IS publicly readable', true, isPublic($pageId, $good));
ck('CONTROL: not blocked', false, GUARD::isBlocked($good));
// ⚠ Without this control passing, every refusal below would be meaningless —
// a guard that blocks everything proves nothing.

echo "\n=== 2. ⚠⚠ REVOKED claim at due time — cron must NOT publish ===\n";
$revoked = seedDue($pageId, $op, 'Due, claim revoked');
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'revoked'], ['id' => $claimId]);
wp_cache_flush();
ck('authority now denies', 'denied', AUTH::decide($pageId, $op)['decision']);

do_action('publish_future_post', $revoked);
clean_post_cache($revoked);
wp_cache_flush();

ck('⚠⚠ status is NOT publish', true, statusOf($revoked) !== 'publish');
ck('status is draft', 'draft', statusOf($revoked));
ck('⚠⚠ NOT publicly readable', false, isPublic($pageId, $revoked));
ck('publication marker was stripped', '', (string) get_post_meta($revoked, VIS::META_AUTHORIZED_AT, true));
ck('marker user id stripped too', '', (string) get_post_meta($revoked, VIS::META_AUTHORIZED_USER_ID, true));
ck('blocked marker stamped', true, GUARD::isBlocked($revoked));
ck('blocked reason is the coarse one', GUARD::REASON_AUTHORITY_LOST, (string) get_post_meta($revoked, GUARD::META_BLOCKED_REASON, true));
ck('no pending cron event remains', false, (bool) wp_next_scheduled('publish_future_post', [$revoked]));

echo "\n=== 3. PUBLIC SURFACES EXCLUDE IT — list, detail, rotator ===\n";
$list = dsp('GET', $base, [], 0);
ck('public list 200', 200, $list['status']);
ck('⚠ blocked row absent from the public LIST', false, in_array('ann_' . $revoked, $list['ids'], true));
ck('the control row IS in the public list', true, in_array('ann_' . $good, $list['ids'], true));
$detail = dsp('GET', $base . '/' . $revoked, [], 0);
ck('⚠ public DETAIL 404s for the blocked row', 404, $detail['status']);
$rot = dsp('GET', $base . '/rotator', [], 0);
ck('rotator 200', 200, $rot['status']);
ck('⚠ blocked row absent from the ROTATOR', false, in_array('ann_' . $revoked, $rot['ids'], true));
// And it is not leaked to a stranger through the owner surface either.
// ⚠ 403, not 404, and that is SHIPPED A2.4 behaviour — its own real-dispatch
// probe asserts `owner list, stranger -> 403`. Recorded as an asymmetry worth
// raising (A3 write refusals are 404; this owner READ is 403) rather than
// changed here: the read contract is not this slice to alter.
ck('owner list refuses a stranger (403, shipped A2.4 behaviour)', 403, dsp('GET', $mbase, [], $st)['status']);

echo "\n=== 4. FEED EXCLUSION ===\n";
$act = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}peepso_activities'");
if ($act) {
    ck('zero module-205 activity rows for any announcement', 0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}peepso_activities WHERE act_module_id = '205'"));
} else {
    echo "SKIP no peepso_activities table here — the structural absence is asserted below instead\n";
}
// ⚠ The real guarantee is STRUCTURAL: nothing projects an announcement into
// the feed at all, so a blocked row cannot be projected either. Asserted as
// the absence of a verb rather than as a filtered query.
$cold = file_get_contents(WP_PLUGIN_DIR . '/bcc-trust/app/Domain/Core/Services/FeedColdStartService.php');
ck('FeedColdStartService has NO announcement verb', false, strpos((string) $cold, "'205'") !== false);
ck('and no announcement module key', false, strpos((string) $cold, 'announcement') !== false);

echo "\n=== 5. AMBIGUOUS claim at due time — same refusal ===\n";
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'verified'], ['id' => $claimId]);
$wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', ['user_id' => $st, 'entity_type' => 'validator', 'entity_id' => $valId, 'wallet_address' => $addr, 'chain_id' => $chainId, 'claim_role' => 'operator', 'status' => 'verified', 'verified_at' => current_time('mysql', true)]);
$dupId = (int) $wpdb->insert_id;
wp_cache_flush();
ck('authority reports ambiguity', 'claim_ambiguous', AUTH::decide($pageId, $op)['reason']);

$amb = seedDue($pageId, $op, 'Due, claim ambiguous');
do_action('publish_future_post', $amb);
clean_post_cache($amb); wp_cache_flush();
ck('⚠⚠ ambiguous: not published', 'draft', statusOf($amb));
ck('ambiguous: not publicly readable', false, isPublic($pageId, $amb));
ck('ambiguous: blocked', true, GUARD::isBlocked($amb));
// ⚠ The blocked reason must NOT disclose which private reason applied.
ck('blocked reason does not leak ambiguity', GUARD::REASON_AUTHORITY_LOST, (string) get_post_meta($amb, GUARD::META_BLOCKED_REASON, true));

echo "\n=== 6. ABSENT claim — the claim row is gone entirely ===\n";
$wpdb->delete($wpdb->prefix . 'bcc_onchain_claims', ['id' => $dupId]);
$wpdb->delete($wpdb->prefix . 'bcc_onchain_claims', ['id' => $claimId]);
wp_cache_flush();
ck('authority says claim_absent', 'claim_absent', AUTH::decide($pageId, $op)['reason']);

$absent = seedDue($pageId, $op, 'Due, claim absent');
do_action('publish_future_post', $absent);
clean_post_cache($absent); wp_cache_flush();
ck('⚠⚠ absent claim: not published', 'draft', statusOf($absent));
ck('absent claim: not publicly readable', false, isPublic($pageId, $absent));
ck('absent claim: blocked', true, GUARD::isBlocked($absent));

echo "\n=== 7. ⚠ GENERIC WP TRANSITIONS, not just our routes ===\n";
// Restore authority so only the generic path is under test.
$wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', ['user_id' => $op, 'entity_type' => 'validator', 'entity_id' => $valId, 'wallet_address' => $addr, 'chain_id' => $chainId, 'claim_role' => 'operator', 'status' => 'verified', 'verified_at' => current_time('mysql', true)]);
$claimId = (int) $wpdb->insert_id;
wp_cache_flush();
ck('authority restored', 'operator', AUTH::decide($pageId, $op)['decision']);

// A row with NO marker at all, published by a generic path.
$generic = wp_insert_post(['post_type' => CPT::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Generic path', 'post_content' => 'b', 'post_author' => $op, 'post_parent' => $pageId]);
// Now revoke, then publish via core's own wp_publish_post() — the path a
// wp-admin edit, WP-CLI or another plugin would take.
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'revoked'], ['id' => $claimId]);
wp_cache_flush();
wp_publish_post($generic);
clean_post_cache($generic); wp_cache_flush();
ck('⚠ wp_publish_post() reverted by the backstop', 'draft', statusOf($generic));
ck('generic path: not publicly readable', false, isPublic($pageId, $generic));
ck('generic path: blocked', true, GUARD::isBlocked($generic));

// And wp_update_post() straight to publish.
$generic2 = wp_insert_post(['post_type' => CPT::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Generic path 2', 'post_content' => 'b', 'post_author' => $op, 'post_parent' => $pageId]);
wp_update_post(['ID' => $generic2, 'post_status' => 'publish']);
clean_post_cache($generic2); wp_cache_flush();
ck('⚠ wp_update_post() to publish reverted too', 'draft', statusOf($generic2));
ck('generic path 2: not publicly readable', false, isPublic($pageId, $generic2));

echo "\n=== 8. ⚠ THE PERMITTED RECOVERY PATH ===\n";
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'verified'], ['id' => $claimId]);
wp_cache_flush();
ck('authority restored for recovery', 'operator', AUTH::decide($pageId, $op)['decision']);
ck('the row is still blocked before recovery', true, GUARD::isBlocked($revoked));
// ⚠ Asserted HERE and not in section 2: there the claim was still revoked, so
// the ex-operator legitimately could not read their own unpublished row at all
// (404, which is correct). The read STATE is only observable once authority is
// restored and the row is still blocked — which is exactly this moment.
// ⚠⚠ THE READ STATE IS NOW REAL. Before arm C existed, AnnouncementViewService
// mapped every non-published, non-scheduled row to `draft` and recorded the gap
// in its own docblock: "when reconciler arm C lands it must define its marker
// and this method must read it". It has landed, so a blocked row reports
// `blocked` to its operator instead of being mislabelled a draft.
$ownDetail = dsp('GET', $base . '/' . $revoked, [], $op);
ck('operator can still reach their own blocked row', 200, $ownDetail['status']);
ck('⚠⚠ its read status is BLOCKED, not draft', 'blocked', $ownDetail['rstatus']);

$rec = dsp('POST', $base . '/' . $revoked . '/publish', [], $op);
clean_post_cache($revoked); wp_cache_flush();
ck('recovery publish -> 200', 200, $rec['status']);
ck('⚠ blocked marker CLEARED by recovery', false, GUARD::isBlocked($revoked));
ck('recovered row is publicly readable', true, isPublic($pageId, $revoked));
ck('recovered row carries a fresh marker', true, trim((string) get_post_meta($revoked, VIS::META_AUTHORIZED_USER_ID, true)) !== '');
$list2 = dsp('GET', $base, [], 0);
ck('recovered row is back in the public list', true, in_array('ann_' . $revoked, $list2['ids'], true));

echo "\n=== 9. ⚠ RECOVERY IS NOT A BYPASS — a stranger cannot clear a block ===\n";
$blocked2 = seedDue($pageId, $op, 'Blocked for the bypass test');
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'revoked'], ['id' => $claimId]);
wp_cache_flush();
do_action('publish_future_post', $blocked2);
clean_post_cache($blocked2); wp_cache_flush();
ck('row is blocked', true, GUARD::isBlocked($blocked2));
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'verified'], ['id' => $claimId]);
wp_cache_flush();
ck('stranger publish -> 404', 404, dsp('POST', $base . '/' . $blocked2 . '/publish', [], $st)['status']);
ck('⚠ still blocked after the stranger tried', true, GUARD::isBlocked($blocked2));
ck('still not publicly readable', false, isPublic($pageId, $blocked2));

echo "\n=== 10. FEATURE OFF must not OPEN a publication path ===\n";
delete_option('bcc_announcements_enabled');
ck('feature is off', false, FEATURE::isEnabled());
$offRow = seedDue($pageId, $op, 'Due while the feature is off');
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'revoked'], ['id' => $claimId]);
wp_cache_flush();
do_action('publish_future_post', $offRow);
clean_post_cache($offRow); wp_cache_flush();
// ⚠⚠ The guard is registered OUTSIDE the feature switch precisely so this
// holds. If it were gated on the switch, turning the feature off between
// scheduling and the due instant would REMOVE the guard and let a lapsed
// claim publish.
ck('⚠⚠ guard still blocked it with the feature OFF', 'draft', statusOf($offRow));
ck('feature-off: blocked marker stamped', true, GUARD::isBlocked($offRow));
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'verified'], ['id' => $claimId]);
wp_cache_flush();

echo "\n=== 11. FAILURE DURING PUBLICATION — marker first, so a failed status write still fails closed ===\n";
update_option('bcc_announcements_enabled', '1');
$partial = seedDue($pageId, $op, 'Partial write');
ck('seeded future with a marker', 'future', statusOf($partial));
// Simulate the marker-stripped-but-status-unchanged half state that block()
// leaves if forceStatus() throws: strip the marker, leave status `future`.
delete_post_meta($partial, VIS::META_AUTHORIZED_AT);
wp_cache_flush();
ck('⚠ marker gone => predicate 3 fails => NOT public even as future', false, isPublic($pageId, $partial));
// Now force it public and confirm the absent marker alone keeps it private.
(new REPO())->forceStatus($partial, 'publish');
wp_cache_flush();
ck('⚠⚠ status=publish but NO marker => still NOT public', false, isPublic($pageId, $partial));
ck('and the status really is publish', 'publish', statusOf($partial));

echo "\n=== TEARDOWN ===\n";
delete_option('bcc_announcements_enabled');
ck('switch deleted', null, get_option('bcc_announcements_enabled', null));
$ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d", CPT::POST_TYPE, $pageId));
foreach ($ids as $id) {
    wp_clear_scheduled_hook('publish_future_post', [(int) $id]);
    wp_delete_post((int) $id, true);
}
wp_delete_post($pageId, true);
$wpdb->delete($wpdb->prefix . 'bcc_onchain_claims', ['entity_id' => $valId]);
$wpdb->delete($wpdb->prefix . 'bcc_onchain_validators', ['id' => $valId]);
$wpdb->delete($wpdb->prefix . 'bcc_wallet_links', ['id' => $linkId]);
wp_delete_user($op);
wp_delete_user($st);
ck('no announcement rows remain', 0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", CPT::POST_TYPE)));
ck('no probe claims remain', 0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bcc_onchain_claims WHERE wallet_address = %s", $addr)));

printf("\n==== CRON PUBLICATION SAFETY: pass=%d fail=%d ====\n", $GLOBALS['P'], $GLOBALS['F']);
