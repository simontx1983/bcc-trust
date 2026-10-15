<?php
/**
 * A5b prerequisites — `summary` and `comments_enabled` on the write routes,
 * through REAL WordPress REST dispatch.
 *
 * ⚠⚠ RUN ONLY IN A DISPOSABLE ENVIRONMENT. It enables
 * `bcc_announcements_enabled`, creates users, a validator page, a claim chain
 * and announcement rows, then removes all of it. Shared staging keeps the
 * switch OFF and that is a deliberate hold.
 *
 *   wp --allow-root --skip-themes eval-file \
 *     wp-content/plugins/bcc-trust/scripts/tests/announcement-summary-comments-prereq.php
 *
 * ── WHAT THIS EXISTS TO PROVE ───────────────────────────────────────────────
 * The read side has projected `summary`, `comment_count` and `comments_enabled`
 * since A2.3b, and `can_comment` / `can_manage_comments` have existed in the
 * capability resolver since then too. None of it had a WRITER: the create and
 * edit routes accepted neither field, and the contract said so in as many words
 * ("Fields in §4.32's request sketch that are not yet accepted: `summary` and
 * `comments_enabled`"). This closes that, and every case below is paired with a
 * control so a refusal cannot pass for the wrong reason.
 *
 * ⚠ THE FAILURE MODE THIS FILE IS SHAPED AROUND: making `summary` required
 * means a request that omits it now fails — so any assertion that expects a
 * 400 for a DIFFERENT rule would start passing on the missing summary instead.
 * Three such assertions existed in the sibling probes (a past `publish_at`, a
 * missing instant, a `blocked` status enum) and were caught by exactly that.
 * Every negative case here therefore asserts the CODE and the REASON, never
 * just the status.
 */

// ⚠ No declare(strict_types=1): `wp eval-file` EVALS this file.
if (!defined('ABSPATH')) {
    fwrite(STDERR, "must run inside WordPress\n");
    exit(1);
}

use BCC\Trust\Core\Announcements\AnnouncementPostType as CPT;
use BCC\Trust\Core\Announcements\AnnouncementVisibility as VIS;
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
    printf("%-4s %-66s exp=%-24s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, $f($expected), $f($actual));
}

/** Dispatch and return status + the error code + the reason_code + raw data. */
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
        // ⚠ Two envelope shapes exist: the house ApiResponse wrapper, and
        // WordPress's own for a schema refusal that never reaches the handler.
        // Both are read, because distinguishing them is the whole point of the
        // "which layer refused" assertions below.
        'code'   => is_array($data) ? ($data['error']['code'] ?? ($data['code'] ?? null)) : null,
        'reason' => is_array($data) ? ($data['error']['data']['reason_code'] ?? null) : null,
        'id'     => is_array($data) ? ($data['data']['id'] ?? null) : null,
        'data'   => is_array($data) ? ($data['data'] ?? []) : [],
    ];
}

function rawId($viewId): int
{
    return (int) preg_replace('/\D+/', '', (string) $viewId);
}

// ─────────────────────────────────────────────────────────────────────────
echo "=== SEED: users, validator page, verified claim chain ===\n";

$op = wp_insert_user(['user_login' => 'pq_op_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$st = wp_insert_user(['user_login' => 'pq_st_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
ck('operator created', true, is_int($op) && $op > 0);

$pageId = wp_insert_post([
    'post_type'   => 'peepso-page',
    'post_status' => 'publish',
    'post_title'  => 'Prereq Probe Validator',
    'post_author' => $op,
]);
ck('validator page created', true, is_int($pageId) && $pageId > 0);

$chainId = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}bcc_chains LIMIT 1");
$addr    = 'cosmosvaloper1prereq';

$wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
    'user_id' => $op, 'post_id' => $pageId, 'wallet_address' => $addr,
    'chain_id' => $chainId, 'wallet_type' => 'validator', 'verified_at' => current_time('mysql', true),
]);
$linkId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
    'wallet_link_id' => $linkId, 'operator_address' => $addr, 'chain_id' => $chainId,
    'moniker' => 'Prereq', 'status' => 'active', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
]);
$valId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', [
    'user_id' => $op, 'entity_type' => 'validator', 'entity_id' => $valId,
    'wallet_address' => $addr, 'chain_id' => $chainId, 'claim_role' => 'operator',
    'status' => 'verified', 'verified_at' => current_time('mysql', true),
]);
$claimId = (int) $wpdb->insert_id;
ck('AUTHORITY resolves the operator', 'operator', AUTH::decide($pageId, $op)['decision']);

$base  = '/bcc/v1/validators/' . $pageId . '/announcements';
$mbase = '/bcc/v1/me/validators/' . $pageId . '/announcements';

update_option('bcc_announcements_enabled', '1');

$ok = ['status' => 'publish', 'title' => 'T', 'summary' => 'A one-line summary.', 'body' => 'Body.'];

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== A. SUMMARY IS REQUIRED, and the two failure modes differ ===\n";

// A1 — absent. Refused by the SCHEMA, before the handler runs.
$r = dsp('POST', $base, ['status' => 'publish', 'title' => 'T', 'body' => 'B'], $op);
ck('A1 absent summary -> 400', 400, $r['status']);
ck('A1 and the SCHEMA refuses it', 'rest_missing_callback_param', $r['code']);

// A2 — present but blank. Reaches the handler, which names the reason.
$r = dsp('POST', $base, ['status' => 'publish', 'title' => 'T', 'summary' => '   ', 'body' => 'B'], $op);
ck('A2 blank summary -> 400', 400, $r['status']);
ck('A2 the HANDLER refuses it', 'bcc_invalid_request', $r['code']);
ck('A2 ⚠ reason_code is the §4.32.9 code', 'summary_required', $r['reason']);

// A3 — oversized. A DIFFERENT mistake, and it must not claim "required".
$r = dsp('POST', $base, ['status' => 'publish', 'title' => 'T', 'summary' => str_repeat('x', 301), 'body' => 'B'], $op);
ck('A3 301 chars -> 400', 400, $r['status']);
ck('A3 code is the ordinary invalid-request', 'bcc_invalid_request', $r['code']);
ck('A3 ⚠⚠ and NOT summary_required — a length error is not a missing field', null, $r['reason']);

// A4 — the boundary, accepted.
$r = dsp('POST', $base, ['status' => 'publish', 'title' => 'T', 'summary' => str_repeat('y', 300), 'body' => 'B'], $op);
ck('A4 exactly 300 chars -> 201', 201, $r['status']);
$a300 = rawId($r['id']);
ck('A4 stored at full length', 300, mb_strlen((string) get_post_field('post_excerpt', $a300)));

// A5 — a normal one, and WHERE it is stored.
$r = dsp('POST', $base, $ok, $op);
ck('A5 valid summary -> 201', 201, $r['status']);
$a = rawId($r['id']);
ck('A5 ⚠ stored in post_excerpt (§4.32.3)', 'A one-line summary.', (string) get_post_field('post_excerpt', $a));

// A6 — §4.32.3: "never inferred from the body".
ck('A6 ⚠ summary is NOT the body', true, (string) get_post_field('post_excerpt', $a) !== (string) get_post_field('post_content', $a));

// A7 — and it reaches the WIRE on the public list, which had nothing to show before.
$pub = dsp('GET', $base, [], 0);
$found = null;
foreach (($pub['data']['items'] ?? []) as $item) {
    if (rawId($item['id'] ?? '') === $a) {
        $found = $item;
    }
}
ck('A7 the row is on the public list', true, $found !== null);
ck('A7 ⚠ and carries the summary on the wire', 'A one-line summary.', (string) ($found['summary'] ?? ''));

// A8 — CONTROL: authority is checked BEFORE the fields, so a stranger sending
// rubbish still gets 403, not a 400 that would disclose the validation rules.
$r = dsp('POST', $base, ['status' => 'publish', 'title' => 'T', 'summary' => '', 'body' => 'B'], $st);
ck('A8 control — a stranger gets 403, not a field 400', 403, $r['status']);
ck('A8 control — with the generic reason', 'not_claimer', $r['reason']);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== B. SUMMARY ON EDIT (§4.32.8 says an edit updates it) ===\n";

$r = dsp('PATCH', $base . '/' . $a, ['summary' => 'Revised summary.'], $op);
ck('B1 edit summary alone -> 200', 200, $r['status']);
ck('B1 ⚠ not refused as "nothing to change"', 'Revised summary.', (string) get_post_field('post_excerpt', $a));

// B2 — sending it BLANK is an attempt to clear a required field.
$r = dsp('PATCH', $base . '/' . $a, ['summary' => '  '], $op);
ck('B2 blank on edit -> 400', 400, $r['status']);
ck('B2 ⚠ reason_code says required', 'summary_required', $r['reason']);
ck('B2 and the stored value is untouched', 'Revised summary.', (string) get_post_field('post_excerpt', $a));

// B3 — oversized on edit is the length error, not "required".
$r = dsp('PATCH', $base . '/' . $a, ['summary' => str_repeat('z', 301)], $op);
ck('B3 oversized on edit -> 400', 400, $r['status']);
ck('B3 and NOT summary_required', null, $r['reason']);

// B4 — CONTROL: a truly empty edit is still refused, so B1 is not passing
// because the service accepts everything.
$r = dsp('PATCH', $base . '/' . $a, [], $op);
ck('B4 control — an empty edit is still 400', 400, $r['status']);
ck('B4 control — and it is the invalid-request code', 'bcc_invalid_request', $r['code']);
ck('B4 control — with NO summary reason', null, $r['reason']);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== C. comments_enabled — stored, and READ by the capability gate ===\n";

/** The can_comment reason for a row, as an authenticated non-operator sees it. */
$reasonFor = static function (int $id) use ($base, $st): ?string {
    $d = dsp('GET', $base . '/' . $id, [], $st);
    return $d['data']['capabilities']['can_comment']['reason_code'] ?? null;
};

// C1 — omitted: no meta is written, and the gate does NOT say comments_disabled.
$r = dsp('POST', $base, $ok, $op);
$cNone = rawId($r['id']);
ck('C1 create without the field -> 201', 201, $r['status']);
ck('C1 ⚠ NO meta is written (absent must stay absent)', '', (string) get_post_meta($cNone, VIS::META_COMMENTS_ENABLED, true));
ck('C1 view-model reports enabled', true, (bool) (dsp('GET', $base . '/' . $cNone, [], 0)['data']['comments_enabled'] ?? null));
ck('C1 ⚠ the gate does NOT refuse on comments', true, $reasonFor($cNone) !== 'comments_disabled');

// C2 — explicit false: stored, projected, and the gate refuses on it.
$r = dsp('POST', $base, $ok + ['comments_enabled' => false], $op);
$cOff = rawId($r['id']);
ck('C2 create with false -> 201', 201, $r['status']);
ck('C2 meta stored as 0', '0', (string) get_post_meta($cOff, VIS::META_COMMENTS_ENABLED, true));
ck('C2 view-model reports disabled', false, (bool) (dsp('GET', $base . '/' . $cOff, [], 0)['data']['comments_enabled'] ?? null));
ck('C2 ⚠⚠ the gate refuses with comments_disabled', 'comments_disabled', $reasonFor($cOff));

// C3 — explicit true: stored as '1', and the gate is back past the comments check.
$r = dsp('POST', $base, $ok + ['comments_enabled' => true], $op);
$cOn = rawId($r['id']);
ck('C3 create with true -> 201', 201, $r['status']);
ck('C3 meta stored as 1', '1', (string) get_post_meta($cOn, VIS::META_COMMENTS_ENABLED, true));
ck('C3 view-model reports enabled', true, (bool) (dsp('GET', $base . '/' . $cOn, [], 0)['data']['comments_enabled'] ?? null));
ck('C3 the gate does NOT refuse on comments', true, $reasonFor($cOn) !== 'comments_disabled');

// C4 — edit flips it both ways, and the gate follows.
$r = dsp('PATCH', $base . '/' . $cOn, ['comments_enabled' => false], $op);
ck('C4 edit to false -> 200', 200, $r['status']);
ck('C4 ⚠ accepted as the ONLY changed field', '0', (string) get_post_meta($cOn, VIS::META_COMMENTS_ENABLED, true));
ck('C4 and the gate now refuses', 'comments_disabled', $reasonFor($cOn));

$r = dsp('PATCH', $base . '/' . $cOn, ['comments_enabled' => true], $op);
ck('C4 edit back to true -> 200', 200, $r['status']);
ck('C4 the gate stops refusing', true, $reasonFor($cOn) !== 'comments_disabled');

// C5 — ⚠⚠ INVERTED BY A5b, DELIBERATELY. The previous version of this
// case RECORDED A DEFECT as the expected value: it asserted the gate stopped
// at `suspended`, because `AnnouncementsEndpoint::viewerStanding()` returned
// only `['authenticated' => bool]` and the resolver fails closed on an absent
// key, so `good_standing` read FALSE for every viewer and `can_comment` could
// never be `allowed` through a live route. Its own comment said the standing
// context "is A5b's to supply and is reported as a prerequisite".
//
// A5b supplied it (`AnnouncementViewerStanding`), so the pin fired exactly as
// designed — the assertion began failing the moment the prerequisite landed.
// It is now the REGRESSION GUARD for the fix rather than a record of the bug:
// an eligible member must reach `allowed`, with NO reason at all.
//
// ⚠ This probe is not CI-wired, so main stayed green while this assertion
// was stale. The failure surfaced only on a manual run during closeout.
$r = $reasonFor($cNone);
ck('C5 ⚠⚠ an eligible member now reaches ALLOWED, not `suspended`', null, $r);
ck('C5 ...and the standing producer supplies all four keys for that viewer', 4, count(
    \BCC\Trust\Core\Announcements\AnnouncementViewerStanding::forViewer($st)
));

// C6 — ⚠⚠ THE CONTROL THAT MAKES SECTION C MEAN SOMETHING, and it is stronger
// than the one first written here. An earlier version asserted that an
// anonymous viewer sees NO capabilities block on detail; that was simply wrong
// — the detail route is viewer-dependent and returns the block to everyone,
// with `can_comment` denied for `auth_required`.
//
// The real control is that the gate produces THREE DISTINCT reasons for three
// distinct viewer/row states. A gate that returned one constant, or that
// ignored `comments_enabled`, could not do that:
//
//     anonymous                      -> auth_required
//     authenticated, comments ON     -> suspended       (falls through to standing)
//     authenticated, comments OFF    -> comments_disabled
$anon = dsp('GET', $base . '/' . $cNone, [], 0);
$anonReason = $anon['data']['capabilities']['can_comment']['reason_code'] ?? null;
ck('C6 control — anonymous gets auth_required', 'auth_required', $anonReason);
ck('C6 control — and an unlock hint to sign in', true, ($anon['data']['capabilities']['can_comment']['unlock_hint'] ?? '') !== '');

$three = [$anonReason, $reasonFor($cNone), $reasonFor($cOff)];
ck('C6 ⚠⚠ three states -> three DISTINCT reasons', 3, count(array_unique($three)));
ck('C6 and comments_disabled is one of them', true, in_array('comments_disabled', $three, true));

// C7 — `can_manage_comments`, the other named prerequisite capability.
$asOp = dsp('GET', $base . '/' . $cNone, [], $op);
ck('C7 operator may manage comments', true, (bool) ($asOp['data']['capabilities']['can_manage_comments']['allowed'] ?? false));
ck('C7 a stranger may not', false, (bool) ($anon['data']['capabilities']['can_manage_comments']['allowed'] ?? true));
ck('C7 ⚠ and the refusal is the GENERIC ownership reason', 'not_claimer', $anon['data']['capabilities']['can_manage_comments']['reason_code'] ?? null);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== D. comment_count is projected (A5a gave it a writer; still 0 here) ===\n";
$d = dsp('GET', $base . '/' . $cNone, [], 0);
ck('D1 comment_count is present', true, array_key_exists('comment_count', $d['data']));
ck('D1 and is a MEASURED zero, not a missing key', 0, $d['data']['comment_count'] ?? -1);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== E. FEATURE OFF — the switch still wins for a well-formed request ===\n";
delete_option('bcc_announcements_enabled');
$r = dsp('POST', $base, $ok, $op);
ck('E1 well-formed create -> 404', 404, $r['status']);
ck('E1 and the handler own refusal', 'bcc_not_found', $r['code']);

// ⚠⚠ E2 RECORDS A PRE-EXISTING LEAK RATHER THAN CLAIMING IT IS FIXED.
// A request missing a SCHEMA-REQUIRED field is refused by WordPress BEFORE the
// handler's feature check runs, so it answers 400 while the feature is off.
// This is not new to `summary`: `status` and `title` were already declared
// `required`, so the leak predates this change and `summary` joins it as a
// third trigger. §4.32.10 says every route returns 404 while off, so the
// divergence is real and is reported, not papered over.
$r = dsp('POST', $base, ['status' => 'publish', 'title' => 'T', 'body' => 'B'], $op);
ck('E2 ⚠ missing required field while OFF -> 400, not 404', 400, $r['status']);
ck('E2 ⚠ refused by the schema, above the feature gate', 'rest_missing_callback_param', $r['code']);
$r = dsp('POST', $base, ['summary' => 'S', 'title' => 'T', 'body' => 'B'], $op);
ck('E2 control — the same holds for the pre-existing `status` field', 400, $r['status']);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== TEARDOWN ===\n";
delete_option('bcc_announcements_enabled');
ck('switch deleted, not set to 0', null, get_option('bcc_announcements_enabled', null));

$ids = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d", CPT::POST_TYPE, $pageId
));
// ⚠⚠ ACTIVITY ROWS MUST BE DELETED TOO, and this is NEW once A4's feed
// projection exists. Before it, publishing an announcement created no
// `peepso_activities` row, so deleting the posts was a complete teardown. With
// the projection, every PUBLISHED row leaves one card — and once the post is
// gone the card points at nothing, so the leak is invisible to any join and
// ACCUMULATES across runs.
//
// Measured in the disposable stack: this probe leaked 5 rows per run and the
// interval probe 1, which only showed up when a sibling's teardown assertion
// was scoped tightly enough to notice. Deleted BEFORE the posts, because
// afterwards there is no way left to identify which rows were ours.
$actTeardown = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}peepso_activities'");
if ($actTeardown) {
    foreach ($ids as $id) {
        $wpdb->delete($wpdb->prefix . 'peepso_activities', [
            'act_module_id'   => 205,
            'act_external_id' => (int) $id,
        ], ['%d', '%d']);
    }
}
foreach ($ids as $id) {
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

printf("\n==== A5b PREREQ (summary + comments_enabled): pass=%d fail=%d ====\n", $GLOBALS['P'], $GLOBALS['F']);
