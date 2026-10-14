<?php
/**
 * A4 — §4.32.8 announcement feed projection, through REAL WordPress.
 *
 * ⚠⚠ RUN ONLY IN A DISPOSABLE ENVIRONMENT. It enables
 * `bcc_announcements_enabled`, creates users, a validator page, a claim chain,
 * announcement rows and `peepso_activities` rows, then removes all of it. It
 * must never be pointed at staging or production: shared staging keeps the
 * switch OFF, and that is a deliberate hold, not an oversight.
 *
 *   wp --allow-root --skip-themes eval-file \
 *     wp-content/plugins/bcc-trust/scripts/tests/announcement-feed-projection.php
 *
 * ── WHY A LIVE PROBE AND NOT ONLY THE UNIT SUITE ────────────────────────────
 * `tests/Unit/AnnouncementFeedProjectionTest.php` can prove the wiring, the
 * delegation and the ordering from source. It cannot prove that a row lands in
 * `peepso_activities` with module 205, that an edit leaves the act_id alone, or
 * that a revoked claim at due time produces NO card — all three need the real
 * event graph, a real cron dispatch and a real table.
 *
 * ⚠ The ORDERING FINDING this probe exists to defend: `publish()` calls
 * `wp_update_post()` BEFORE writing the publication marker, so a projection
 * listening only on `transition_post_status` is refused by its own authority
 * gate on every direct publish. Case A is the regression test for that.
 */

// ⚠ No declare(strict_types=1): `wp eval-file` EVALS this file, and a
// strict_types declaration must be the first statement of a script.
if (!defined('ABSPATH')) {
    fwrite(STDERR, "must run inside WordPress\n");
    exit(1);
}

use BCC\Trust\Core\Announcements\AnnouncementPostType as CPT;
use BCC\Trust\Core\Announcements\AnnouncementPublicationGuard as ARMC;
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
        'code'   => is_array($data) ? ($data['error']['code'] ?? ($data['code'] ?? null)) : null,
        'id'     => is_array($data) ? ($data['data']['id'] ?? null) : null,
        'json'   => (string) json_encode($data),
    ];
}

/** Raw numeric announcement id from the prefixed view-model id (`ann_123`). */
function rawId($viewId): int
{
    return (int) preg_replace('/\D+/', '', (string) $viewId);
}

// ⚠⚠ `wp eval-file` EVALS this file inside a function scope, so a plain
// top-level `$ACT = ...` is NOT a global and `global $ACT` in a helper binds
// an EMPTY string. That produced `SELECT COUNT(*) FROM `` `, which errors,
// returns null, and `(int) null` is 0 — so every "ZERO activities" assertion
// PASSED VACUOUSLY while the real counts were never read. A green run was the
// bug. $GLOBALS is used explicitly for the same reason the P/F counters are.
$GLOBALS['ACT'] = $wpdb->prefix . 'peepso_activities';
$ACT = $GLOBALS['ACT'];

/** How many module-205 activity rows point at this announcement. */
function acts(int $announcementId): int
{
    global $wpdb;
    $t = (string) ($GLOBALS['ACT'] ?? '');

    // Refuse to answer rather than answer 0 from a broken query.
    if ($t === '') {
        throw new \RuntimeException('activity table name unresolved');
    }

    $n = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$t}` WHERE act_module_id = 205 AND act_external_id = %d",
        $announcementId
    ));

    if ($n === null || $wpdb->last_error !== '') {
        throw new \RuntimeException('activity count unreadable: ' . $wpdb->last_error);
    }

    return (int) $n;
}

/** The single act_id for this announcement, or 0. */
function actId(int $announcementId): int
{
    global $wpdb;
    $t = (string) ($GLOBALS['ACT'] ?? '');

    if ($t === '') {
        throw new \RuntimeException('activity table name unresolved');
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT act_id FROM `{$t}` WHERE act_module_id = 205 AND act_external_id = %d LIMIT 1",
        $announcementId
    ));
}

// ─────────────────────────────────────────────────────────────────────────
echo "=== SEED: activity table, users, validator page, verified claim chain ===\n";

// ⚠ Create the SHARED peepso_activities table only when absent, and remember
// whether we made it so teardown drops it ONLY in that case. The auto-hide
// integration suite uses the same discipline; dropping a table PeepSo owns
// because a probe assumed it owned it is how neighbours get poisoned.
$madeTable = false;
if ($wpdb->get_var("SHOW TABLES LIKE '{$ACT}'") !== $ACT) {
    $wpdb->query(
        "CREATE TABLE `{$ACT}` (
            act_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            act_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            act_module_id INT NOT NULL DEFAULT 0,
            act_external_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            act_access INT NOT NULL DEFAULT 0,
            PRIMARY KEY (act_id),
            KEY idx_mod_ext (act_module_id, act_external_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $madeTable = true;
}
ck('activity table available', $ACT, (string) $wpdb->get_var("SHOW TABLES LIKE '{$ACT}'"));

$op = wp_insert_user(['user_login' => 'a4_op_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$st = wp_insert_user(['user_login' => 'a4_st_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
ck('operator user created', true, is_int($op) && $op > 0);

$pageId = wp_insert_post([
    'post_type'   => 'peepso-page',
    'post_status' => 'publish',
    'post_title'  => 'A4 Probe Validator',
    'post_author' => $op,
]);
ck('validator page created', true, is_int($pageId) && $pageId > 0);

$chainId = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}bcc_chains LIMIT 1");
$addr    = 'cosmosvaloper1a4probe';

$wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
    'user_id' => $op, 'post_id' => $pageId, 'wallet_address' => $addr,
    'chain_id' => $chainId, 'wallet_type' => 'validator', 'verified_at' => current_time('mysql', true),
]);
$linkId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
    'wallet_link_id' => $linkId, 'operator_address' => $addr, 'chain_id' => $chainId,
    'moniker' => 'A4 Probe', 'status' => 'active', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
]);
$valId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', [
    'user_id' => $op, 'entity_type' => 'validator', 'entity_id' => $valId,
    'wallet_address' => $addr, 'chain_id' => $chainId, 'claim_role' => 'operator',
    'status' => 'verified', 'verified_at' => current_time('mysql', true),
]);
$claimId = (int) $wpdb->insert_id;
ck('claim chain inserted', true, $linkId > 0 && $valId > 0 && $claimId > 0);
ck('AUTHORITY resolves the operator', 'operator', AUTH::decide($pageId, $op)['decision']);

$base = '/bcc/v1/validators/' . $pageId . '/announcements';

// ⚠ Arm C and the projection subscribers are registered at BOOT by the plugin.
// Assert they are actually hooked: a probe that exercises an unhooked event
// proves nothing, and this exact mistake (REST-only registration) was already
// made once on arm C.
ck('published subscriber is hooked', true, has_action('bcc_announcement_published') !== false);
ck('archived subscriber is hooked', true, has_action('bcc_announcement_archived') !== false);
ck('arm C veto is hooked', true, has_action('publish_future_post') !== false);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== A. DIRECT PUBLISH projects EXACTLY ONE module-205 activity ===\n";
// ⚠ This is the regression test for the ordering finding: wp_update_post()
// fires the transition BEFORE the marker exists, so if the event were wired
// only to transition_post_status this case would see ZERO activities.
update_option('bcc_announcements_enabled', '1');

$r = dsp('POST', $base, ['status' => 'publish', 'title' => 'A4 direct', 'body' => 'Body'], $op);
ck('A: create+publish returned 201/200', true, in_array($r['status'], [200, 201], true));
$a = rawId($r['id']);
ck('A: got an announcement id', true, $a > 0);
ck('A: row is publicly visible', true, (new REPO())->findPublic($pageId, $a) !== null);
ck('A: EXACTLY ONE activity row', 1, acts($a));
$actA = actId($a);
ck('A: act_id is real', true, $actA > 0);
ck('A: module meta tags the post', 'announcement', (string) get_post_meta($a, '_bcc_activity_module', true));
ck('A: sidecar points at itself', (string) $a, (string) get_post_meta($a, '_bcc_activity_sidecar_id', true));
ck('A: the card cannot host a discussion', '1', (string) get_post_meta($a, 'peepso_disable_comments', true));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== B. AN EDIT DOES NOT BUMP THE FEED ===\n";
$r = dsp('PATCH', $base . '/' . $a, ['title' => 'A4 direct, edited'], $op);
ck('B: edit succeeded', 200, $r['status']);
ck('B: STILL exactly one activity', 1, acts($a));
ck('B: the SAME act_id (identity retained)', $actA, actId($a));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== C. ARCHIVAL WITHDRAWS THE ACTIVITY ===\n";
$r = dsp('POST', $base . '/' . $a . '/archive', [], $op);
ck('C: archive succeeded', 200, $r['status']);
ck('C: activity WITHDRAWN', 0, acts($a));
// §4.32.6 — archival keeps the permalink, so the row stays publicly readable.
ck('C: the announcement itself is STILL public (§4.32.6)', true, (new REPO())->findPublic($pageId, $a) !== null);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== D. A DRAFT PROJECTS NOTHING ===\n";
$r = dsp('POST', $base, ['status' => 'draft', 'title' => 'A4 draft', 'body' => 'B'], $op);
$d = rawId($r['id']);
ck('D: draft created', true, $d > 0);
ck('D: NOT publicly visible', false, (new REPO())->findPublic($pageId, $d) !== null);
ck('D: ZERO activities', 0, acts($d));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== E. A FUTURE SCHEDULE PROJECTS NOTHING — early projection refused ===\n";
$future = gmdate('Y-m-d H:i:s', time() + 3600);
$r = dsp('POST', $base, ['status' => 'schedule', 'title' => 'A4 sched', 'body' => 'B', 'publish_at' => $future], $op);
$s = rawId($r['id']);
ck('E: scheduled created', true, $s > 0);
ck('E: stored as future', 'future', get_post_status($s));
ck('E: NOT publicly visible', false, (new REPO())->findPublic($pageId, $s) !== null);
ck('E: ZERO activities while pending', 0, acts($s));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== F. SCHEDULED + DUE + AUTHORITY HOLDS -> real cron projects ONE ===\n";
// Make it due, then drive WordPress's REAL publication path.
$due = gmdate('Y-m-d H:i:s', time() - 60);
$wpdb->update($wpdb->posts, ['post_date_gmt' => $due, 'post_date' => $due], ['ID' => $s]);
clean_post_cache($s);
ck('F: due instant is in the past', true, strtotime($due) < time());

do_action('publish_future_post', $s);
clean_post_cache($s);

ck('F: core published it', 'publish', get_post_status($s));
ck('F: not blocked', false, ARMC::isBlocked($s));
ck('F: publicly visible', true, (new REPO())->findPublic($pageId, $s) !== null);
ck('F: EXACTLY ONE activity', 1, acts($s));
ck('F: discussion disabled on the card', '1', (string) get_post_meta($s, 'peepso_disable_comments', true));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== G. SCHEDULED + DUE + AUTHORITY REVOKED -> blocked, NO card ===\n";
$future2 = gmdate('Y-m-d H:i:s', time() + 3600);
$r = dsp('POST', $base, ['status' => 'schedule', 'title' => 'A4 revoked', 'body' => 'B', 'publish_at' => $future2], $op);
$g = rawId($r['id']);
ck('G: scheduled created', true, $g > 0);

$due2 = gmdate('Y-m-d H:i:s', time() - 60);
$wpdb->update($wpdb->posts, ['post_date_gmt' => $due2, 'post_date' => $due2], ['ID' => $g]);
clean_post_cache($g);

// Revoke the claim BEFORE the due dispatch.
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'revoked'], ['id' => $claimId]);
wp_cache_flush();
ck('G: authority is now denied', 'denied', AUTH::decide($pageId, $op)['decision']);

do_action('publish_future_post', $g);
clean_post_cache($g);

ck('G: arm C reverted it to draft', 'draft', get_post_status($g));
ck('G: arm C marked it blocked', true, ARMC::isBlocked($g));
ck('G: NOT publicly visible', false, (new REPO())->findPublic($pageId, $g) !== null);
ck('G: ⚠⚠ ZERO activities — no card for an unauthorized notice', 0, acts($g));

// Restore authority for the remaining cases.
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'verified'], ['id' => $claimId]);
wp_cache_flush();
ck('G: authority restored', 'operator', AUTH::decide($pageId, $op)['decision']);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== H. A BLOCKED ROW CANNOT BE PROJECTED EVEN BY A DIRECT EVENT ===\n";
// Fire the event straight at the blocked row. The projection must refuse on
// its own, not because nothing called it.
do_action('bcc_announcement_published', $op, $g);
ck('H: still ZERO activities after a direct event', 0, acts($g));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== I. FEATURE OFF -> projection stops (§4.32.10) ===\n";
delete_option('bcc_announcements_enabled');
ck('I: switch is absent', null, get_option('bcc_announcements_enabled', null));

// A fresh, genuinely publishable row, projected only by a direct event.
$i = wp_insert_post([
    'post_type'     => CPT::POST_TYPE,
    'post_status'   => 'publish',
    'post_parent'   => $pageId,
    'post_author'   => $op,
    'post_title'    => 'A4 off',
    'post_date_gmt' => gmdate('Y-m-d H:i:s', time() - 60),
    'post_date'     => gmdate('Y-m-d H:i:s', time() - 60),
]);
update_post_meta($i, VIS::META_AUTHORIZED_AT, gmdate('Y-m-d H:i:s', time() - 60));
update_post_meta($i, VIS::META_AUTHORIZED_USER_ID, (string) $op);
ck('I: the row itself satisfies all three predicates', true, (new REPO())->findPublic($pageId, $i) !== null);

do_action('bcc_announcement_published', $op, $i);
ck('I: ⚠ ZERO activities while the switch is OFF', 0, acts($i));

// ── and the CONTROL that makes case I mean something ──────────────────────
echo "  --- control: the same row projects once the switch is back on\n";
update_option('bcc_announcements_enabled', '1');
do_action('bcc_announcement_published', $op, $i);
ck('I: control — EXACTLY ONE activity once ON', 1, acts($i));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== J. WITHDRAWAL IS **NOT** GATED ON THE SWITCH ===\n";
// Turning a feature off must never strand a card that was already projected.
$actI = actId($i);
ck('J: a card exists to withdraw', true, $actI > 0);
delete_option('bcc_announcements_enabled');
do_action('bcc_announcement_archived', $i);
ck('J: ⚠⚠ withdrawn even with the switch OFF', 0, acts($i));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== K. NO ENGAGEMENT on a projected announcement (§4.32.15) ===\n";
update_option('bcc_announcements_enabled', '1');
ck('K: the kind takes no reactions',
    true,
    \BCC\Trust\Core\Support\NoReactionsGate::kindTakesNoReactions('announcement'));
ck('K: module 205 takes no reactions',
    true,
    \BCC\Trust\Core\Support\NoReactionsGate::moduleTakesNoReactions('205'));
ck('K: control — an ordinary status DOES take reactions',
    false,
    \BCC\Trust\Core\Support\NoReactionsGate::kindTakesNoReactions('status'));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== TEARDOWN ===\n";
delete_option('bcc_announcements_enabled');
ck('switch deleted, not set to 0', null, get_option('bcc_announcements_enabled', null));

$ids = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d", CPT::POST_TYPE, $pageId
));
foreach ($ids as $id) {
    $wpdb->delete($ACT, ['act_module_id' => 205, 'act_external_id' => (int) $id]);
    wp_delete_post((int) $id, true);
}
wp_delete_post($pageId, true);
$wpdb->delete($wpdb->prefix . 'bcc_onchain_claims', ['entity_id' => $valId]);
$wpdb->delete($wpdb->prefix . 'bcc_onchain_validators', ['id' => $valId]);
$wpdb->delete($wpdb->prefix . 'bcc_wallet_links', ['id' => $linkId]);
wp_delete_user($op);
wp_delete_user($st);

ck('no announcement rows remain', 0, (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", CPT::POST_TYPE
)));
ck('no module-205 activity rows remain', 0, (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$ACT}` WHERE act_module_id = 205"
));

// Drop the shared table ONLY if this probe created it.
if ($madeTable) {
    $wpdb->query("DROP TABLE IF EXISTS `{$ACT}`");
    ck('probe-created activity table dropped', null, $wpdb->get_var("SHOW TABLES LIKE '{$ACT}'"));
}

printf("\n==== A4 FEED PROJECTION: pass=%d fail=%d ====\n", $GLOBALS['P'], $GLOBALS['F']);
