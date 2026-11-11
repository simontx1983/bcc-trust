<?php
/**
 * The COMBINED announcements candidate — #323's interactions with storage
 * (#321), visibility (#324) and the routes (#327), through real dispatch.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * #323 is based on `main` and is NOT an ancestor of #327, so the linear
 * chain never exercised them together. Everything below is a property that
 * only exists where both halves are present.
 *
 * ⚠⚠ THE ONE THAT MATTERS MOST: §4.32.16 accepted reportability WITHOUT
 * automatic hiding. `AutoHideService` hides content at a threshold of 3
 * reports, and announcements are excluded by module id. So a reported
 * announcement must stay PUBLICLY VISIBLE no matter how many reports it
 * carries — which is a claim about #323 (the exclusion), #324 (the
 * visibility predicate) and #327 (the read routes) at the same time, and is
 * unprovable from any one of them alone.
 */

use BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver as Caps;
use BCC\Trust\Core\Announcements\AnnouncementModerationPolicy as Policy;
use BCC\Trust\Core\Announcements\AnnouncementPostType as CPT;
use BCC\Trust\Core\Announcements\AnnouncementVisibility as VIS;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as AUTH;

global $wpdb;

function show($v): string
{
    return (is_scalar($v) || $v === null) ? var_export($v, true) : json_encode($v);
}
function ck(string $label, $expected, $actual): void
{
    $ok = $expected === $actual;
    if ($ok) { $GLOBALS['P'] = ($GLOBALS['P'] ?? 0) + 1; }
    else      { $GLOBALS['F'] = ($GLOBALS['F'] ?? 0) + 1; }
    printf("%-4s %-64s exp=%-16s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, show($expected), show($actual));
}
function dsp(string $route, array $params = array(), int $asUser = 0): array
{
    wp_set_current_user($asUser);
    $req = new WP_REST_Request('GET', $route);
    foreach ($params as $k => $v) { $req->set_param($k, $v); }
    $res  = rest_get_server()->dispatch($req);
    $data = $res->get_data();
    return array('status' => $res->get_status(), 'data' => $data, 'json' => json_encode($data));
}

echo "=== 0. ALL THREE HALVES ARE PRESENT IN THIS TREE ===\n";
ck('#321 storage: the CPT class', true, class_exists(CPT::class));
ck('#324 visibility: the predicate producer', true, class_exists(VIS::class));
ck('#327 routes: the endpoint', true, class_exists('BCC\Trust\Core\REST\AnnouncementsEndpoint'));
ck('#323 reportability: the moderation policy', true, class_exists(Policy::class));
ck('#328 members fix rode along', true, class_exists('BCC\Trust\Core\Support\BoundedIntParam'));

echo "\n=== 1. #323 x #321 — THE MODULE ID MATCHES THE STORED KIND ===\n";
// The policy keys off a module id; the report repository maps a post kind to
// it. If those two drift, reports land under a kind nothing excludes.
ck('policy module id is the string 205', '205', Policy::ANNOUNCEMENT_MODULE_ID);
$repo = new ReflectionClass('BCC\Trust\Core\Repositories\ContentReportRepository');
$kinds = $repo->getConstant('ALLOWED_POST_KINDS');
$map   = $repo->getConstant('KIND_TO_MODULE_ID');
ck('announcement is a reportable kind', true, is_array($kinds) && in_array('announcement', $kinds, true));
ck('and it maps to module 205', 205, is_array($map) ? ($map['announcement'] ?? null) : null);
ck('the two agree on the number', true, (string) ($map['announcement'] ?? '') === Policy::ANNOUNCEMENT_MODULE_ID);

echo "\n=== 2. #323 AUTO-HIDE: THE FAIL-CLOSED DIRECTION ===\n";
// ⚠⚠ CORRECTED TWICE, and both corrections are worth keeping visible.
//
// (a) `mayAutoHide(int $actId)` takes an ACTIVITY ID and resolves the module
//     itself via PeepSoActivityRepository. An earlier version of this probe
//     passed module-id STRINGS ('201', '205'), which under strict_types threw
//     a TypeError and killed the run — after three assertions had already
//     "failed" for the meaningless reason that int 201 is not an activity.
//
// (b) `wp_peepso_activities` DOES NOT EXIST on this install, so no activity
//     id resolves to a module here. That makes the two interesting branches —
//     module 205 refused, an ordinary module allowed — UNREACHABLE in this
//     environment. They are covered by #323's own 360-line
//     AnnouncementModerationPolicyTest, which doubles the repository.
//     Reported rather than faked: asserting them here would mean asserting
//     that an unresolvable activity is not hideable and calling it an
//     announcement exclusion.
//
// What IS provable here is the direction that matters for safety: anything
// the policy cannot identify is refused, never allowed.
foreach (array(0, -1, -999, 999999999) as $actId) {
    ck("actId $actId is NOT hideable (unidentifiable => refused)", false, Policy::mayAutoHide($actId));
    ck("actId $actId decides BLOCKED_UNRESOLVED", Policy::AUTO_HIDE_BLOCKED_UNRESOLVED, Policy::autoHideDecision($actId));
}
ck('the announcement block reason is a distinct constant', true,
    Policy::AUTO_HIDE_BLOCKED_ANNOUNCEMENT !== Policy::AUTO_HIDE_BLOCKED_UNRESOLVED);
echo "  ⚠ LIMITATION: no wp_peepso_activities table here, so the 205-vs-ordinary\n";
echo "    module branches are not exercised by this probe. See #323's unit suite.\n";

echo "\n=== 3. SEED: the full claim chain + one PUBLIC announcement ===\n";
$op = wp_insert_user(array('user_login' => 'cmb_op_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber'));
$st = wp_insert_user(array('user_login' => 'cmb_st_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber'));
$pageId = wp_insert_post(array('post_type' => 'peepso-page', 'post_status' => 'publish', 'post_title' => 'Combined Probe Validator', 'post_author' => $op));
$chain  = $wpdb->get_row("SELECT id FROM {$wpdb->prefix}bcc_chains LIMIT 1");
$cid    = (int) $chain->id;
$wpdb->insert($wpdb->prefix . 'bcc_wallet_links', array('user_id' => $op, 'post_id' => $pageId, 'wallet_address' => 'cosmosvaloper1cmb', 'chain_id' => $cid, 'wallet_type' => 'validator', 'verified_at' => current_time('mysql', true)));
$linkId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', array('wallet_link_id' => $linkId, 'operator_address' => 'cosmosvaloper1cmb', 'chain_id' => $cid, 'moniker' => 'Combined', 'status' => 'active', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)));
$valId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', array('user_id' => $op, 'entity_type' => 'validator', 'entity_id' => $valId, 'wallet_address' => 'cosmosvaloper1cmb', 'chain_id' => $cid, 'claim_role' => 'operator', 'status' => 'verified', 'verified_at' => current_time('mysql', true)));
ck('authority resolves the operator', 'operator', AUTH::decide($pageId, $op)['decision']);

$past = gmdate('Y-m-d H:i:s', time() - 7200);
$annId = wp_insert_post(array('post_type' => CPT::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Public notice', 'post_content' => 'body', 'post_author' => $op, 'post_parent' => $pageId));
$wpdb->update($wpdb->posts, array('post_status' => 'publish', 'post_date_gmt' => $past, 'post_date' => $past), array('ID' => $annId));
clean_post_cache($annId);
update_post_meta($annId, VIS::META_AUTHORIZED_AT, $past);
update_post_meta($annId, VIS::META_AUTHORIZED_USER_ID, (string) $op);
update_option('bcc_announcements_enabled', '1');

$pub = '/bcc/v1/validators/' . $pageId . '/announcements';
$r = dsp($pub);
ck('the announcement is publicly listed', true, strpos($r['json'], 'ann_' . $annId) !== false);

echo "\n=== 4. ⚠⚠ #323 x #324 x #327 — REPORTS DO NOT HIDE AN ANNOUNCEMENT ===\n";
// Pile on reports well past AutoHideService's threshold of 3.
$reportTable = $wpdb->prefix . 'bcc_content_reports';
$haveTable = (string) $wpdb->get_var("SHOW TABLES LIKE '{$reportTable}'") === $reportTable;
ck('the content-reports table exists', true, $haveTable);

if ($haveTable) {
    // ⚠⚠ THE COLUMNS ARE NAMED, NOT GUESSED. An earlier version of this probe
    // built the row by probing SHOW COLUMNS for a list of candidate names —
    // `reporter_id`, `post_kind`, `post_id`, `module_id`. NONE of them exist:
    // the table is (target_kind, target_id, reporter_user_id, reason_code,
    // status, …). Every insert silently failed, the count was 0, and the
    // assertion that the announcement is "STILL listed after 6 reports"
    // PASSED — with zero reports on the row. A vacuous green on the single
    // most important claim in this file.
    //
    // $wpdb->insert() returns false on failure, so each one is asserted.
    // ⚠⚠ SIX DISTINCT REPORTERS, not one reporting six times. A unique index
    // `unique_reporter_target` on (reporter_user_id, target_kind, target_id)
    // allows one report per person per target — which is the right rule, and
    // which an earlier version of this probe fell foul of: it reported six
    // times as the SAME user, five inserts came back
    // `Duplicate entry … for key 'unique_reporter_target'`, and one report
    // sat on the row while the assertion claimed six.
    //
    // ⚠ That matters beyond fixture hygiene: the auto-hide threshold of 3 is
    // three DISTINCT reporters, so a fixture that cannot produce distinct
    // reporters cannot reach the threshold at all — and a probe that never
    // reaches the threshold proves nothing about an exclusion from it.
    $reporters = array();
    $inserted  = 0;
    for ($i = 0; $i < 6; $i++) {
        $uid = wp_insert_user(array(
            'user_login' => 'cmb_rep' . $i . '_' . wp_rand(1000, 9999),
            'user_pass'  => wp_generate_password(),
            'role'       => 'subscriber',
        ));
        if (!is_int($uid)) { continue; }
        $reporters[] = $uid;
        $wpdb->last_error = '';
        $ok = $wpdb->insert($reportTable, array(
            'target_kind'      => 'announcement',
            'target_id'        => $annId,
            'reporter_user_id' => $uid,
            'reason_code'      => 'spam',
            'status'           => 0,
            'created_at'       => current_time('mysql', true),
        ));
        if ($ok !== false) {
            $inserted++;
        } else {
            echo "       insert failed: " . substr($wpdb->last_error, 0, 100) . "\n";
        }
    }
    ck('six distinct reporters created', 6, count($reporters));
    ck('all six inserts succeeded', 6, $inserted);

    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$reportTable} WHERE target_kind = %s AND target_id = %d",
        'announcement',
        $annId
    ));
    ck('six reports are ON THE ROW (threshold is 3)', 6, $count);

    // THE CLAIM: still public, still listed, still readable by detail.
    wp_cache_flush();
    $after = dsp($pub);
    ck('STILL publicly listed after 6 reports', true, strpos($after['json'], 'ann_' . $annId) !== false);
    ck('public list still answers 200', 200, $after['status']);
    $det = dsp('/bcc/v1/validators/' . $pageId . '/announcements/' . $annId);
    ck('detail STILL answers 200 after 6 reports', 200, $det['status']);
    ck('the row is not marked hidden on the wire', false, strpos($det['json'], '"is_hidden":true') !== false);
}

echo "\n=== 5. #323 x #327 — can_report APPEARS ONLY WHERE CAPABILITIES DO ===\n";
$anon = dsp($pub, array(), 0);
$oper = dsp($pub, array(), $op);
ck('public list carries NO can_report (no capabilities at all)', false, strpos($anon['json'], 'can_report') !== false);
ck('public list still byte-identical anon vs operator', $anon['json'], $oper['json']);

$detS = dsp('/bcc/v1/validators/' . $pageId . '/announcements/' . $annId, array(), $st);
ck('detail (stranger) carries capabilities', true, isset($detS['data']['data']['capabilities']));
ck('detail (stranger) carries can_report', true, isset($detS['data']['data']['capabilities']['can_report']));

$detO = dsp('/bcc/v1/validators/' . $pageId . '/announcements/' . $annId, array(), $op);
ck('detail (operator) also carries can_report', true, isset($detO['data']['data']['capabilities']['can_report']));

echo "\n=== 6. #323 ADDS NO ENGAGEMENT — the §A guarantee still holds ===\n";
foreach (array('can_react', 'can_stoke', 'can_mark_helpful', 'counts', 'viewer_reaction') as $forbidden) {
    ck("detail carries no $forbidden", false, strpos($detS['json'], '"' . $forbidden . '"') !== false);
}

echo "\n=== 7. FEATURE OFF STILL WINS, WITH REPORTS PRESENT ===\n";
delete_option('bcc_announcements_enabled');
$offPub = dsp($pub, array(), 0);
$offDet = dsp('/bcc/v1/validators/' . $pageId . '/announcements/' . $annId, array(), $op);
ck('feature-off public list -> 404', 404, $offPub['status']);
ck('feature-off detail -> 404 even for the operator', 404, $offDet['status']);
ck('feature-off body leaks no row id', false, strpos($offPub['json'], (string) $annId) !== false);

echo "\n=== TEARDOWN ===\n";
if ($haveTable) { $wpdb->delete($reportTable, array('target_kind' => 'announcement', 'target_id' => $annId)); }
wp_delete_post($annId, true);
wp_delete_post($pageId, true);
$wpdb->delete($wpdb->prefix . 'bcc_onchain_claims', array('entity_id' => $valId));
$wpdb->delete($wpdb->prefix . 'bcc_onchain_validators', array('id' => $valId));
$wpdb->delete($wpdb->prefix . 'bcc_wallet_links', array('id' => $linkId));
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($op);
wp_delete_user($st);
foreach ($reporters ?? array() as $rid) { wp_delete_user($rid); }
delete_option('bcc_announcements_enabled');
ck('no announcement rows left behind', 0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", CPT::POST_TYPE)));

printf("\n==== COMBINED INTERACTIONS: pass=%d fail=%d ====\n", $GLOBALS['P'] ?? 0, $GLOBALS['F'] ?? 0);
