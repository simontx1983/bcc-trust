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
 * ⚠⚠ EVERY ROUTE-CREATE BELOW CARRIES A `summary`, AND IT IS REQUIRED.
 * §4.32.3 makes the summary a first-class authored field and the create route
 * refuses a request without one. This probe was written BEFORE that landed, and
 * on a tree carrying both it failed 23 of 76 assertions — every one of them a
 * cascade from four creates that returned 400 instead of 201. The failure was
 * invisible to either change ALONE and only appeared on a combined tree, which
 * is the reason the three open PRs are reviewed together rather than in series.
 *
 * ⚠ The ORDERING FINDING this probe exists to defend: `publish()` calls
 * `wp_update_post()` BEFORE writing the publication marker, so a projection
 * listening only on `transition_post_status` is refused by its own authority
 * gate on every direct publish. Case A is the regression test for that.
 *
 * ── ⚠⚠ WHAT SECTION L IS FOR, AND THE MISTAKE THAT MADE IT NECESSARY ───────
 * Cases D, E and G look like they test the handler's authority gate. They do
 * NOT, and a mutation control proved it: disabling the `findPublic()` refusal
 * — the whole §4.32.8 "due AND authorized" check — left all of A–K passing.
 *
 * The reason is that NO EVENT IS FIRED on those paths: `draft` and `schedule`
 * both return from `finishPublicationWrite()` before the `do_action`, and a
 * blocked promotion takes arm C's `block()` branch rather than the branch
 * carrying the event. So A–K prove the event WIRING is conservative, which is
 * worth proving, but they say nothing about what the handler refuses.
 *
 * ⚠ And case H, the only one that fires the event directly at a refusable row,
 * is guarded TWICE — by `isBlocked()` and by `findPublic()` — so neither guard
 * is individually killed; only removing both together fails it. Mutually
 * redundant guards are invisible to a single-mutation control.
 *
 * Section L therefore fires the event DIRECTLY at a row in each refusable
 * state, one predicate at a time, each with a positive control on the same
 * row. That is the only construction that can observe the handler's own gate.
 *
 * ⚠ An EDIT is deliberately absent from that list: `edit()` emits no event at
 * all, so "edits do not bump the feed" is true because nothing fires, NOT
 * because the idempotency check catches it. Case B measures the observable
 * guarantee; case B2 pins the date half of it.
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

$r = dsp('POST', $base, ['status' => 'publish', 'summary' => 'A4 probe summary', 'title' => 'A4 direct', 'body' => 'Body'], $op);
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
$dateBefore = (string) get_post_field('post_date_gmt', $a);
$r = dsp('PATCH', $base . '/' . $a, ['title' => 'A4 direct, edited'], $op);
ck('B: edit succeeded', 200, $r['status']);
ck('B: STILL exactly one activity', 1, acts($a));
ck('B: the SAME act_id (identity retained)', $actA, actId($a));

// §4.32.8 — "retains the original `published_at`, feed position and activity
// identity". act_id covers identity; this covers the DATE, which is what the
// feed actually orders on. The suite asserted identity only.
clean_post_cache($a);
ck('B2: published_at UNCHANGED by the edit', $dateBefore, (string) get_post_field('post_date_gmt', $a));

// ⚠ And the status gate that makes it true: a PUBLISHED row refuses a
// re-schedule, so its date cannot be moved by any edit.
$r = dsp('PATCH', $base . '/' . $a, ['publish_at' => gmdate('Y-m-d H:i:s', time() + 7200)], $op);
ck('B2: re-scheduling a PUBLISHED row is refused', 409, $r['status']);
clean_post_cache($a);
ck('B2: and its date still did not move', $dateBefore, (string) get_post_field('post_date_gmt', $a));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== C. ARCHIVAL WITHDRAWS THE ACTIVITY ===\n";
$r = dsp('POST', $base . '/' . $a . '/archive', [], $op);
ck('C: archive succeeded', 200, $r['status']);
ck('C: activity WITHDRAWN', 0, acts($a));
// §4.32.6 — archival keeps the permalink, so the row stays publicly readable.
ck('C: the announcement itself is STILL public (§4.32.6)', true, (new REPO())->findPublic($pageId, $a) !== null);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== D. A DRAFT PROJECTS NOTHING ===\n";
$r = dsp('POST', $base, ['status' => 'draft', 'summary' => 'A4 probe summary', 'title' => 'A4 draft', 'body' => 'B'], $op);
$d = rawId($r['id']);
ck('D: draft created', true, $d > 0);
ck('D: NOT publicly visible', false, (new REPO())->findPublic($pageId, $d) !== null);
ck('D: ZERO activities', 0, acts($d));

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== E. A FUTURE SCHEDULE PROJECTS NOTHING — early projection refused ===\n";
$future = gmdate('Y-m-d H:i:s', time() + 3600);
$r = dsp('POST', $base, ['status' => 'schedule', 'summary' => 'A4 probe summary', 'title' => 'A4 sched', 'body' => 'B', 'publish_at' => $future], $op);
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
$r = dsp('POST', $base, ['status' => 'schedule', 'summary' => 'A4 probe summary', 'title' => 'A4 revoked', 'body' => 'B', 'publish_at' => $future2], $op);
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
echo "\n=== L. ⚠⚠ THE HANDLER'S OWN GATE, one refusable state at a time ===\n";
// Each case below builds a row that is valid in every respect EXCEPT one,
// fires the event DIRECTLY at it, and requires zero activities. Then it
// repairs that one respect and requires exactly one. Without the paired
// control a zero would be unfalsifiable: a handler that refused everything
// would score full marks.
update_option('bcc_announcements_enabled', '1');

/**
 * Build an announcement row that satisfies all three §4.32.1 predicates, then
 * apply one named defect to it. Returns the new post id.
 */
$mk = static function (string $defect) use ($pageId, $op, $wpdb) {
    $past = gmdate('Y-m-d H:i:s', time() - 120);

    $status = $defect === 'draft' ? 'draft' : 'publish';

    $id = wp_insert_post([
        'post_type'     => CPT::POST_TYPE,
        'post_status'   => $status,
        'post_parent'   => $pageId,
        'post_author'   => $op,
        'post_title'    => 'A4 L ' . $defect,
        'post_date_gmt' => $past,
        'post_date'     => $past,
    ]);

    if ($defect === 'future_date') {
        // ⚠⚠ The date is pushed into the future by a DIRECT UPDATE, after the
        // insert, deliberately. `wp_insert_post()` COERCES a `publish` row
        // carrying a future date into status `future` — so inserting it that
        // way would have produced TWO defects (wrong status AND wrong date),
        // and repairing one would leave the other. The paired control caught
        // exactly that: L2 refused even after repair. This shape leaves
        // `post_status = publish` and isolates predicate 2 alone.
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $wpdb->update($wpdb->posts, ['post_date_gmt' => $future, 'post_date' => $future], ['ID' => $id]);
    }

    if ($defect !== 'no_marker') {
        update_post_meta($id, VIS::META_AUTHORIZED_AT, $past);
        update_post_meta($id, VIS::META_AUTHORIZED_USER_ID, (string) $op);
    }
    if ($defect === 'wrong_marker_user') {
        update_post_meta($id, VIS::META_AUTHORIZED_USER_ID, (string) ($op + 9999));
    }
    if ($defect === 'archived') {
        update_post_meta($id, VIS::META_ARCHIVED_AT, $past);
    }
    if ($defect === 'blocked') {
        update_post_meta($id, ARMC::META_BLOCKED_AT, $past);
        update_post_meta($id, ARMC::META_BLOCKED_REASON, ARMC::REASON_AUTHORITY_LOST);
    }

    clean_post_cache($id);

    return (int) $id;
};

/** Repair the one defect, so the paired control can project. */
$repair = static function (int $id, string $defect) use ($op, $wpdb) {
    $past = gmdate('Y-m-d H:i:s', time() - 120);

    if ($defect === 'draft') {
        $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $id]);
    } elseif ($defect === 'future_date') {
        $wpdb->update($wpdb->posts, ['post_date_gmt' => $past, 'post_date' => $past], ['ID' => $id]);
    } elseif ($defect === 'no_marker') {
        update_post_meta($id, VIS::META_AUTHORIZED_AT, $past);
        update_post_meta($id, VIS::META_AUTHORIZED_USER_ID, (string) $op);
    } elseif ($defect === 'wrong_marker_user') {
        update_post_meta($id, VIS::META_AUTHORIZED_USER_ID, (string) $op);
    } elseif ($defect === 'archived') {
        delete_post_meta($id, VIS::META_ARCHIVED_AT);
    } elseif ($defect === 'blocked') {
        delete_post_meta($id, ARMC::META_BLOCKED_AT);
        delete_post_meta($id, ARMC::META_BLOCKED_REASON);
    }

    clean_post_cache($id);
};

$cases = [
    'draft'             => 'L1 predicate 1 — status is not publish',
    'future_date'       => 'L2 predicate 2 — due instant is in the future',
    'no_marker'         => 'L3 predicate 3 — NO publication marker',
    'wrong_marker_user' => 'L4 predicate 3 — marker names a DIFFERENT user',
    'archived'          => 'L5 archived — readable, but projects no NEW card',
    'blocked'           => 'L6 arm C blocked — withheld by the reconciler',
];

foreach ($cases as $defect => $label) {
    $id = $mk($defect);

    // The defect must make the row unprojectable...
    do_action('bcc_announcement_published', $op, $id);
    ck($label, 0, acts($id));

    // ...and repairing ONLY that defect must make it projectable, which is
    // what proves the zero above came from the gate and not from the fixture.
    $repair($id, $defect);
    do_action('bcc_announcement_published', $op, $id);
    ck('   control — the same row projects once repaired', 1, acts($id));
}

// ⚠ L3 and L4 are the two that matter most: they are the ONLY cases in this
// probe that single out predicate 3, the publication-authorization marker.
// Every other refusal in sections A–K is also caught by predicate 1 or 2, so
// before section L existed a projection that dropped the marker check entirely
// would have scored 56/56.

echo "
=== L7. RE-DELIVERY: firing the event twice yields ONE activity ===
";
// ⚠ This is the case that actually exercises the idempotency check, and it
// was missing: a mutation control disabling that check SURVIVED the whole
// suite. Nothing else fires the event twice on a projectable row — an edit
// cannot, because `edit()` emits no event at all.
//
// The real-world shape is a cron tick delivering `publish_future_post` twice,
// or any other caller re-firing after a successful projection.
$rd = $mk('none');
do_action('bcc_announcement_published', $op, $rd);
ck('L7 first delivery projects one', 1, acts($rd));
$rdAct = actId($rd);
ck('L7 and it has a real act_id', true, $rdAct > 0);

do_action('bcc_announcement_published', $op, $rd);
ck('L7 ⚠⚠ SECOND delivery projects NO second activity', 1, acts($rd));
ck('L7 and the act_id is unchanged (position retained)', $rdAct, actId($rd));

do_action('bcc_announcement_published', $op, $rd);
ck('L7 a third delivery still leaves exactly one', 1, acts($rd));

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
// ⚠⚠ ASSERTED ONLY ABOUT THE IDS THIS PROBE CREATED. Getting this right took
// three attempts and the sequence is worth recording:
//
//   1. A global `COUNT(*) WHERE act_module_id = 205` was the original. It broke
//      the moment A4 existed, because a published row legitimately projects one.
//   2. Re-scoping to "no ORPHAN rows anywhere" broke too — measured at 34
//      orphans left by SIBLING probes that publish announcements and whose
//      teardowns predate the projection, so they deleted posts and left the
//      cards pointing at nothing. Those teardowns are fixed in this change too.
//   3. Only an assertion over THIS probe's own ids is both meaningful and
//      immune to whatever else shares the database.
//
// `$ids` is exactly that set — every announcement under this probe's page,
// collected BEFORE deletion, which is the last moment they can be identified.
$leftOver = 0;
foreach ($ids as $mid) {
    $leftOver += (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$GLOBALS['ACT']}` WHERE act_module_id = 205 AND act_external_id = %d",
        (int) $mid
    ));
}
ck('no module-205 rows remain for the ids THIS probe created', 0, $leftOver);
ck('anti-vacuity — this probe did create ids to check', true, count($ids) > 0);

// Drop the shared table ONLY if this probe created it.
if ($madeTable) {
    $wpdb->query("DROP TABLE IF EXISTS `{$ACT}`");
    ck('probe-created activity table dropped', null, $wpdb->get_var("SHOW TABLES LIKE '{$ACT}'"));
}

printf("\n==== A4 FEED PROJECTION: pass=%d fail=%d ====\n", $GLOBALS['P'], $GLOBALS['F']);
