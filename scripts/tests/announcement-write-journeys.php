<?php
/**
 * A3 — complete read/write journeys through REAL WordPress REST dispatch.
 *
 * ⚠⚠ RUN ONLY IN A DISPOSABLE ENVIRONMENT. It enables
 * `bcc_announcements_enabled`, creates users, a validator page, a claim chain
 * and announcement rows, then removes all of it. It must never be pointed at
 * staging or production: shared staging keeps the switch OFF, and that is a
 * deliberate hold, not an oversight.
 *
 *   wp --allow-root --skip-themes eval-file \
 *     wp-content/plugins/bcc-trust/scripts/tests/announcement-write-journeys.php
 *
 * ── WHY REAL DISPATCH AND NOT THE UNIT HARNESS ──────────────────────────────
 * The A2.4 read slice found THREE defects here that its unit harness had
 * hidden, the worst being `?limit=51` answering 200 with 50 rows because
 * `minimum`/`maximum` beside a `sanitize_callback` are decorative. Only
 * `WP_REST_Server::dispatch()` applies route-regex matching, `args` validation
 * and the sanitize/validate substitution rule the way production does.
 *
 * ⚠⚠ EVERY create payload below carries a `summary`, and that is REQUIRED,
 * not decorative. §4.32.3 makes the summary a first-class authored field and
 * the create route now refuses a request without one. Omitting it would make
 * each of these assertions pass or fail for the WRONG REASON — several of
 * them expect a 400 for a DIFFERENT rule (a past `publish_at`, a missing
 * instant, `publish_at` with the wrong verb), and a missing summary would
 * satisfy that 400 without the rule under test ever being reached.
 *
 * ⚠ The switch is DELETED at the end, not reset to '0', because the closed
 * truthy allowlist treats '0' and 'off' as disabled but an absent option is
 * the shipped default and the only state staging is allowed to be in.
 */

// ⚠ No declare(strict_types=1): `wp eval-file` EVALS this file, and a
// strict_types declaration must be the first statement of a script. The
// sibling probes omit it for the same reason.
if (!defined('ABSPATH')) {
    fwrite(STDERR, "must run inside WordPress\n");
    exit(1);
}

use BCC\Trust\Core\Announcements\AnnouncementPostType as CPT;
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
    printf("%-4s %-62s exp=%-20s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, $f($expected), $f($actual));
}

/** Dispatch with an explicit method, as an explicit user. */
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
        'state'  => is_array($data) ? ($data['data']['state'] ?? null) : null,
        'id'     => is_array($data) ? ($data['data']['id'] ?? null) : null,
        'json'   => (string) json_encode($data),
    ];
}

function isPublic(int $pageId, int $id): bool
{
    return (new REPO())->findPublic($pageId, $id) !== null;
}

// ─────────────────────────────────────────────────────────────────────────
echo "=== SEED: users, validator page, verified claim chain ===\n";

$op = wp_insert_user(['user_login' => 'a3_op_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$st = wp_insert_user(['user_login' => 'a3_st_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
ck('operator user created', true, is_int($op) && $op > 0);
ck('stranger user created', true, is_int($st) && $st > 0);

$pageId = wp_insert_post([
    'post_type'   => 'peepso-page',
    'post_status' => 'publish',
    'post_title'  => 'A3 Probe Validator',
    'post_author' => $op,
]);
ck('validator page created', true, is_int($pageId) && $pageId > 0);

$chainId = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}bcc_chains LIMIT 1");
$addr    = 'cosmosvaloper1a3probe';

$wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
    'user_id' => $op, 'post_id' => $pageId, 'wallet_address' => $addr,
    'chain_id' => $chainId, 'wallet_type' => 'validator', 'verified_at' => current_time('mysql', true),
]);
$linkId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
    'wallet_link_id' => $linkId, 'operator_address' => $addr, 'chain_id' => $chainId,
    'moniker' => 'A3 Probe', 'status' => 'active', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
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
ck('AUTHORITY denies the stranger', 'denied', AUTH::decide($pageId, $st)['decision']);

$base  = '/bcc/v1/validators/' . $pageId . '/announcements';
$mbase = '/bcc/v1/me/validators/' . $pageId . '/announcements';

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== J0. FEATURE OFF — every write route 404s and writes NOTHING ===\n";
delete_option('bcc_announcements_enabled');

$before = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", CPT::POST_TYPE
));

foreach ([
    ['POST', $base, ['status' => 'publish', 'summary' => 'Probe summary', 'title' => 'T', 'body' => 'B']],
    ['PATCH', $base . '/1', ['title' => 'T']],
    ['POST', $base . '/1/publish', []],
    ['POST', $base . '/1/archive', []],
    ['POST', $base . '/1/pin', ['pinned' => true]],
] as [$m, $r, $p]) {
    $res = dsp($m, $r, $p, $op);
    ck('OFF ' . $m . ' ' . substr($r, strrpos($r, '/') ?: 0) . ' -> 404', 404, $res['status']);
    ck('OFF   code is the house not-found', 'bcc_not_found', $res['code']);
}
$after = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", CPT::POST_TYPE
));
ck('OFF created ZERO announcement rows', $before, $after);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== feature ON (disposable environment only) ===\n";
update_option('bcc_announcements_enabled', '1');
ck('switch reads enabled', true, \BCC\Trust\Core\Announcements\AnnouncementFeature::isEnabled());

echo "\n=== J1. CREATE draft — stored, and NOT public ===\n";
$r = dsp('POST', $base, ['status' => 'draft', 'summary' => 'Probe summary', 'title' => 'Draft one', 'body' => 'Body one'], $op);
ck('create draft -> 201', 201, $r['status']);
ck('create draft state', 'draft', $r['state']);
$draftId = (int) $r['id'];
ck('draft id returned', true, $draftId > 0);
ck('draft is NOT publicly visible', false, isPublic($pageId, $draftId));
ck('draft has NO publication marker', '', (string) get_post_meta($draftId, VIS::META_AUTHORIZED_AT, true));

echo "\n=== J2. PUBLISH the draft — becomes public, marker records the actor ===\n";
$r = dsp('POST', $base . '/' . $draftId . '/publish', [], $op);
ck('publish -> 200', 200, $r['status']);
ck('publish state', 'publish', $r['state']);
ck('published row IS publicly visible', true, isPublic($pageId, $draftId));
ck('marker user id is the acting operator', (string) $op, (string) get_post_meta($draftId, VIS::META_AUTHORIZED_USER_ID, true));
ck('marker at is non-empty', true, trim((string) get_post_meta($draftId, VIS::META_AUTHORIZED_AT, true)) !== '');
ck('publishing twice is a conflict', 409, dsp('POST', $base . '/' . $draftId . '/publish', [], $op)['status']);

echo "\n=== J3. ⚠⚠ SCHEDULE — stored `future`, marker PRESENT, and still NOT public ===\n";
$at = gmdate('Y-m-d H:i:s', time() + 7200);
$r  = dsp('POST', $base, ['status' => 'schedule', 'summary' => 'Probe summary', 'title' => 'Scheduled', 'body' => 'Later', 'publish_at' => $at], $op);
ck('create schedule -> 201', 201, $r['status']);
$schedId = (int) $r['id'];
ck('stored status is future, not publish', 'future', (string) get_post_status($schedId));
// ⚠⚠ REVERSED: schedule now writes NO marker. See the interval probe.
ck('⚠⚠ NO marker at schedule time (written at promotion instead)', '', trim((string) get_post_meta($schedId, VIS::META_AUTHORIZED_AT, true)));
// ⚠ The whole safety argument for writing the marker early: predicate 1 is
// what keeps this private, not predicate 3.
ck('scheduled row is NOT publicly visible', false, isPublic($pageId, $schedId));
ck('a past instant is refused, not clamped', 400, dsp('POST', $base, ['status' => 'schedule', 'summary' => 'Probe summary', 'title' => 'X', 'body' => 'Y', 'publish_at' => gmdate('Y-m-d H:i:s', time() - 60)], $op)['status']);
ck('schedule with no instant is refused', 400, dsp('POST', $base, ['status' => 'schedule', 'summary' => 'Probe summary', 'title' => 'X', 'body' => 'Y'], $op)['status']);
// ⚠ §4.32.13: publish_at is sent ONLY with schedule. Sending it with another
// verb is 400, not silently ignored — ignoring it would accept a request whose
// intent the server did not honour.
ck('publish_at with status=draft is refused', 400, dsp('POST', $base, ['status' => 'draft', 'summary' => 'Probe summary', 'title' => 'X', 'body' => 'Y', 'publish_at' => gmdate('Y-m-d H:i:s', time() + 7200)], $op)['status']);
ck('publish_at with status=publish is refused', 400, dsp('POST', $base, ['status' => 'publish', 'summary' => 'Probe summary', 'title' => 'X', 'body' => 'Y', 'publish_at' => gmdate('Y-m-d H:i:s', time() + 7200)], $op)['status']);

echo "\n=== J4. EDIT title/body, and re-schedule only while pending ===\n";
ck('edit title -> 200', 200, dsp('PATCH', $base . '/' . $draftId, ['title' => 'Renamed'], $op)['status']);
ck('title actually changed', 'Renamed', get_post_field('post_title', $draftId));
ck('re-schedule a PENDING row -> 200', 200, dsp('PATCH', $base . '/' . $schedId, ['publish_at' => gmdate('Y-m-d H:i:s', time() + 14400)], $op)['status']);
ck('re-schedule a PUBLISHED row is a conflict', 409, dsp('PATCH', $base . '/' . $draftId, ['publish_at' => $at], $op)['status']);
ck('an empty edit is refused', 400, dsp('PATCH', $base . '/' . $draftId, [], $op)['status']);

echo "\n=== J5. PIN — at most one per page ===\n";
$r2 = dsp('POST', $base, ['status' => 'publish', 'summary' => 'Probe summary', 'title' => 'Second public', 'body' => 'B2'], $op);
$pub2 = (int) $r2['id'];
ck('second published row created', true, $pub2 > 0);
ck('pin the first -> 200', 200, dsp('POST', $base . '/' . $draftId . '/pin', ['pinned' => true], $op)['status']);
ck('pin the second -> 200', 200, dsp('POST', $base . '/' . $pub2 . '/pin', ['pinned' => true], $op)['status']);
$pinned = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
      WHERE p.post_parent = %d AND m.meta_key = %s",
    $pageId,
    REPO::META_PINNED_AT
));
ck('⚠ EXACTLY ONE pinned row remains on the page', 1, $pinned);
ck('unpin -> 200', 200, dsp('POST', $base . '/' . $pub2 . '/pin', ['pinned' => false], $op)['status']);
ck('pinning a SCHEDULED row is a conflict', 409, dsp('POST', $base . '/' . $schedId . '/pin', ['pinned' => true], $op)['status']);

echo "\n=== J6. ARCHIVE — one-way, and the permalink still resolves publicly ===\n";
ck('archive -> 200', 200, dsp('POST', $base . '/' . $pub2 . '/archive', [], $op)['status']);
ck('archived_at stamped', true, trim((string) get_post_meta($pub2, VIS::META_ARCHIVED_AT, true)) !== '');
ck('archiving twice is a conflict', 409, dsp('POST', $base . '/' . $pub2 . '/archive', [], $op)['status']);
ck('⚠ §4.32.6 an archived row is STILL publicly resolvable', true, isPublic($pageId, $pub2));
ck('editing an archived row is a conflict', 409, dsp('PATCH', $base . '/' . $pub2, ['title' => 'nope'], $op)['status']);
ck('pinning an archived row is a conflict', 409, dsp('POST', $base . '/' . $pub2 . '/pin', ['pinned' => true], $op)['status']);

echo "\n=== J7. A STRANGER gets 404 on every write route, never 403, no private reason ===\n";
foreach ([
    ['POST', $base, ['status' => 'publish', 'summary' => 'Probe summary', 'title' => 'T', 'body' => 'B']],
    ['PATCH', $base . '/' . $draftId, ['title' => 'T']],
    ['POST', $base . '/' . $draftId . '/publish', []],
    ['POST', $base . '/' . $draftId . '/archive', []],
    ['POST', $base . '/' . $draftId . '/pin', ['pinned' => true]],
] as [$m, $r3, $p]) {
    $res = dsp($m, $r3, $p, $st);
    ck('stranger ' . $m . ' -> 403 (generic, §4.32.2)', 403, $res['status']);
    ck('stranger   carries the GENERIC reason only', true, strpos($res['json'], 'not_claimer') !== false);
    foreach (['claim_absent', 'claim_ambiguous', 'auth_required'] as $leak) {
        ck('stranger   body omits ' . $leak, false, strpos($res['json'], $leak) !== false);
    }
}
ck('an ANONYMOUS caller is refused too', 403, dsp('POST', $base, ['status' => 'publish', 'summary' => 'Probe summary', 'title' => 'T', 'body' => 'B'], 0)['status']);
ck('the stranger changed nothing', 'Renamed', get_post_field('post_title', $draftId));

echo "\n=== J8. A REVOKED claim refuses, and so does an AMBIGUOUS one ===\n";
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'revoked'], ['id' => $claimId]);
wp_cache_flush();
ck('authority no longer says operator', 'denied', AUTH::decide($pageId, $op)['decision']);
ck('⚠ REVOKED claim: create -> 403', 403, dsp('POST', $base, ['status' => 'publish', 'summary' => 'Probe summary', 'title' => 'T', 'body' => 'B'], $op)['status']);
ck('REVOKED claim: publish -> 403', 403, dsp('POST', $base . '/' . $schedId . '/publish', [], $op)['status']);
ck('REVOKED claim: archive -> 403', 403, dsp('POST', $base . '/' . $schedId . '/archive', [], $op)['status']);

// Restore, then make it AMBIGUOUS with a second verified operator claim.
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'verified'], ['id' => $claimId]);
wp_cache_flush();
ck('authority restored', 'operator', AUTH::decide($pageId, $op)['decision']);

$wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', [
    'user_id' => $st, 'entity_type' => 'validator', 'entity_id' => $valId,
    'wallet_address' => $addr, 'chain_id' => $chainId, 'claim_role' => 'operator',
    'status' => 'verified', 'verified_at' => current_time('mysql', true),
]);
$dupId = (int) $wpdb->insert_id;
wp_cache_flush();
$amb = AUTH::decide($pageId, $op);
ck('authority reports ambiguity', 'denied', $amb['decision']);
ck('ambiguity reason', 'claim_ambiguous', $amb['reason']);
ck('⚠ AMBIGUOUS claim: create -> 403', 403, dsp('POST', $base, ['status' => 'publish', 'summary' => 'Probe summary', 'title' => 'T', 'body' => 'B'], $op)['status']);
ck('AMBIGUOUS claim: body omits the private reason', false, strpos(dsp('POST', $base, ['status' => 'publish', 'summary' => 'Probe summary', 'title' => 'T', 'body' => 'B'], $op)['json'], 'claim_ambiguous') !== false);
$wpdb->delete($wpdb->prefix . 'bcc_onchain_claims', ['id' => $dupId]);
wp_cache_flush();
ck('authority restored after removing the duplicate', 'operator', AUTH::decide($pageId, $op)['decision']);

echo "\n=== J9. `blocked` is REJECTED BY THE SCHEMA, not mapped onto draft ===\n";
// ⚠ A summary is supplied even though this request is MEANT to fail: without
// it the refusal would come from the missing-field check and the assertion
// below would never reach the enum rule it names.
$r = dsp('POST', $base, ['status' => 'blocked', 'summary' => 'Probe summary', 'title' => 'T', 'body' => 'B'], $op);
ck('status=blocked -> 400', 400, $r['status']);
ck('and it is the schema that refuses', 'rest_invalid_param', $r['code']);
ck('no row was created for it', false, strpos($r['json'], '"id"') !== false);
foreach (['published', 'future', 'trash', 'private', 'Publish'] as $bad) {
    ck('status=' . $bad . ' refused', 400, dsp('POST', $base, ['status' => $bad, 'title' => 'T', 'body' => 'B'], $op)['status']);
}

echo "\n=== J10. NO ENGAGEMENT: no activity row, no stokes, no helpful marks ===\n";
$actTable = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}peepso_activities'");
if ($actTable) {
    ck('zero module-205 activity rows', 0, (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}peepso_activities WHERE act_module_id = '205'"
    ));
} else {
    echo "SKIP no peepso_activities table in this environment — the unit inventory covers the absence\n";
}
ck('zero stokes', 0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bcc_trust_stokes"));
ck('zero helpful marks', 0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bcc_trust_helpful_marks"));
ck('zero rank events', 0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bcc_trust_rank_events"));

echo "\n=== J11. ⚠⚠ FAIL-CLOSED: a published row WITHOUT its marker is not public ===\n";
ck('still public with the marker', true, isPublic($pageId, $draftId));
delete_post_meta($draftId, VIS::META_AUTHORIZED_AT);
wp_cache_flush();
ck('marker removed -> row drops out of the PUBLIC read', false, isPublic($pageId, $draftId));
ck('post_status is still publish, so predicate 3 alone did it', 'publish', (string) get_post_status($draftId));
// And a marker dated in the FUTURE must not authorize either.
update_post_meta($draftId, VIS::META_AUTHORIZED_AT, gmdate('Y-m-d H:i:s', time() + 86400));
wp_cache_flush();
ck('a FUTURE-dated marker does not authorize', false, isPublic($pageId, $draftId));
update_post_meta($draftId, VIS::META_AUTHORIZED_AT, gmdate('Y-m-d H:i:s', time() - 60));
wp_cache_flush();
ck('a past-dated marker authorizes again', true, isPublic($pageId, $draftId));
// A marker naming the WRONG user must not authorize (predicate 3's numeric half).
update_post_meta($draftId, VIS::META_AUTHORIZED_USER_ID, (string) $st);
wp_cache_flush();
ck('a marker naming another user does not authorize', false, isPublic($pageId, $draftId));

echo "\n=== J12. FEATURE OFF AGAIN — with real rows present, reads and writes 404 ===\n";
delete_option('bcc_announcements_enabled');
ck('OFF: owner list 404s with rows present', 404, dsp('GET', $mbase, [], $op)['status']);
ck('OFF: public list 404s with rows present', 404, dsp('GET', $base, [], 0)['status']);
ck('OFF: create 404s', 404, dsp('POST', $base, ['status' => 'draft', 'summary' => 'Probe summary', 'title' => 'T', 'body' => 'B'], $op)['status']);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== TEARDOWN ===\n";
delete_option('bcc_announcements_enabled');
ck('switch deleted, not set to 0', null, get_option('bcc_announcements_enabled', null));

$ids = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d", CPT::POST_TYPE, $pageId
));
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
ck('no probe claim rows remain', 0, (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}bcc_onchain_claims WHERE wallet_address = %s", $addr
)));

printf("\n==== A3 WRITE JOURNEYS: pass=%d fail=%d ====\n", $GLOBALS['P'], $GLOBALS['F']);
