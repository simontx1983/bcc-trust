<?php
/**
 * REAL WordPress REST dispatch for the announcement read routes.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────
 * `tests/Unit/AnnouncementsEndpointTest.php` cannot dispatch: WordPress
 * core is not loadable in either PHPUnit harness, so it drives the real
 * handlers through a helper that applies each route's own captured `args`
 * schema. That is a simulation of dispatch, and a simulation can be WRONG
 * IN THE LENIENT DIRECTION — the first version of it applied
 * `minimum`/`maximum` unconditionally and so reported green on a bound
 * that real WordPress does not enforce (see M5 in
 * announcement-endpoint-mutation-controls.sh).
 *
 * This script is the answer to that. It boots a real WordPress, seeds the
 * full claim chain and seven announcements covering every §4.32.1
 * visibility case, and pushes real `WP_REST_Request`s through
 * `WP_REST_Server::dispatch()`. Route-regex matching, `args` validation,
 * `permission_callback`, response headers and the SQL actually issued are
 * all WordPress's own.
 *
 * ⚠ IT CANNOT RUN IN CI. The CI jobs install the plugin tree and a database
 * but no WordPress core, so there is no `WP_REST_Server` there either. This
 * is a local/staging instrument, run deliberately, and its evidence belongs
 * in the PR description rather than in a job log.
 *
 * ── HOW TO RUN IT ───────────────────────────────────────────────────────
 * Against a disposable stack (WordPress 6.7 + PHP 8.2 + MariaDB 11.8.9):
 *
 *   # a compose file with: wordpress:6.7-php8.2-apache + mariadb:11.8.9 +
 *   # wordpress:cli-php8.2, with this plugin and bcc-core bind-mounted
 *   docker compose up -d
 *   docker compose exec cli wp eval-file \
 *       wp-content/plugins/bcc-trust/scripts/tests/announcement-real-dispatch.php
 *
 * Expected: `pass=99 fail=0`, and `no announcement rows left behind` — it
 * tears down every row, user and option it created.
 *
 * ⚠ Section 8 deliberately RENAMES the postmeta table to force a read
 * failure, then renames it back and re-reads to prove the restore. If the
 * script is killed between those two steps the install is left with
 * `wp_postmeta_a24parked` and no `wp_postmeta` — recoverable with one
 * `RENAME TABLE`, but a reason not to run this anywhere that matters.
 * WordPress logs each deliberate failure as a database error; that noise in
 * the output is the control working, not a defect.
 *
 * ⚠ NEVER point it at staging or production. It writes posts, users,
 * wallet-link, validator and claim rows, and it enables the feature switch
 * for the duration of the run.
 *
 * ⚠ It leaves `bcc_announcements_enabled` DELETED, not merely reset — which
 * is the correct resting state (§4.32.10 default off), but it means running
 * this against an install where the feature was deliberately on would turn
 * it off.
 */

use BCC\Trust\Core\Announcements\AnnouncementPostType as CPT;
use BCC\Trust\Core\Announcements\AnnouncementVisibility as VIS;
use BCC\Trust\Core\Repositories\AnnouncementRepository as REPO;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as AUTH;

global $wpdb;

$PASS = 0;
$FAIL = 0;
$NOTES = array();

function ck(string $label, $expected, $actual): void
{
    $GLOBALS['__ck'] = true;
    $ok = $expected === $actual;
    if ($ok) {
        $GLOBALS['PASS2'] = ($GLOBALS['PASS2'] ?? 0) + 1;
    } else {
        $GLOBALS['FAIL2'] = ($GLOBALS['FAIL2'] ?? 0) + 1;
    }
    $f = static function ($v) {
        return (is_scalar($v) || $v === null) ? var_export($v, true) : json_encode($v);
    };
    printf("%-4s %-60s exp=%-18s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, $f($expected), $f($actual));
}

function dsp(string $route, array $params = array(), int $asUser = 0): array
{
    wp_set_current_user($asUser);
    $req = new WP_REST_Request('GET', $route);
    foreach ($params as $k => $v) {
        $req->set_param($k, $v);
    }
    $res  = rest_get_server()->dispatch($req);
    $data = $res->get_data();
    return array(
        'status'  => $res->get_status(),
        'code'    => is_array($data) ? ($data['code'] ?? ($data['error']['code'] ?? null)) : null,
        'reason'  => is_array($data) ? ($data['error']['data']['reason_code'] ?? null) : null,
        'headers' => $res->get_headers(),
        'data'    => $data,
        'json'    => json_encode($data),
    );
}

function ids_of(array $r): array
{
    $out = array();
    foreach ($r['data']['data']['items'] ?? array() as $it) {
        $out[] = $it['id'] ?? '?';
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
echo "=== SEED ===\n";

$opUser = wp_insert_user(array('user_login' => 'a24_op_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber'));
$stUser = wp_insert_user(array('user_login' => 'a24_st_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber'));
ck('operator user created', true, is_int($opUser) && $opUser > 0);
ck('stranger user created', true, is_int($stUser) && $stUser > 0);

$pageId = wp_insert_post(array(
    'post_type'   => 'peepso-page',
    'post_status' => 'publish',
    'post_title'  => 'A24 Probe Validator',
    'post_author' => $opUser,
));
ck('validator page created', true, is_int($pageId) && $pageId > 0);

$chain = $wpdb->get_row("SELECT id FROM {$wpdb->prefix}bcc_chains LIMIT 1");
$chainId = (int) $chain->id;

$wpdb->insert($wpdb->prefix . 'bcc_wallet_links', array(
    'user_id' => $opUser, 'post_id' => $pageId, 'wallet_address' => 'cosmosvaloper1a24probe',
    'chain_id' => $chainId, 'wallet_type' => 'validator', 'verified_at' => current_time('mysql', true),
));
$linkId = (int) $wpdb->insert_id;

$wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', array(
    'wallet_link_id' => $linkId, 'operator_address' => 'cosmosvaloper1a24probe',
    'chain_id' => $chainId, 'moniker' => 'A24 Probe', 'status' => 'active',
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
));
$valId = (int) $wpdb->insert_id;

$wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', array(
    'user_id' => $opUser, 'entity_type' => 'validator', 'entity_id' => $valId,
    'wallet_address' => 'cosmosvaloper1a24probe', 'chain_id' => $chainId,
    'claim_role' => 'operator', 'status' => 'verified', 'verified_at' => current_time('mysql', true),
));
ck('claim chain rows inserted', true, $linkId > 0 && $valId > 0 && $wpdb->insert_id > 0);

$d = AUTH::decide($pageId, $opUser);
ck('AUTHORITY resolves the operator', 'operator', $d['decision']);
$d2 = AUTH::decide($pageId, $stUser);
ck('AUTHORITY denies the stranger', 'denied', $d2['decision']);
ck('AUTHORITY private reason for stranger', 'not_claimer', $d2['reason']);

$past   = gmdate('Y-m-d H:i:s', time() - 7200);
$future = gmdate('Y-m-d H:i:s', time() + 7200);

/** Create an announcement row directly, bypassing WP's own date/status juggling. */
function ann(int $pageId, int $author, string $title, string $status, string $gmt, array $meta): int
{
    global $wpdb;
    $id = wp_insert_post(array(
        'post_type'    => CPT::POST_TYPE,
        'post_status'  => 'draft',
        'post_title'   => $title,
        'post_content' => 'Body of ' . $title,
        'post_author'  => $author,
        'post_parent'  => $pageId,
    ));
    // Force status and GMT date past WP's scheduling rules.
    $wpdb->update($wpdb->posts, array('post_status' => $status, 'post_date_gmt' => $gmt, 'post_date' => $gmt), array('ID' => $id));
    clean_post_cache($id);
    foreach ($meta as $k => $v) {
        update_post_meta($id, $k, $v);
    }
    return (int) $id;
}

$A = ann($pageId, $opUser, 'A public', 'publish', $past, array(
    VIS::META_AUTHORIZED_AT => $past, VIS::META_AUTHORIZED_USER_ID => (string) $opUser));
$B = ann($pageId, $opUser, 'B draft', 'draft', $past, array());
$C = ann($pageId, $opUser, 'C future', 'publish', $future, array(
    VIS::META_AUTHORIZED_AT => $past, VIS::META_AUTHORIZED_USER_ID => (string) $opUser));
$D = ann($pageId, $opUser, 'D no marker', 'publish', $past, array());
$E = ann($pageId, $opUser, 'E wrong marker user', 'publish', $past, array(
    VIS::META_AUTHORIZED_AT => $past, VIS::META_AUTHORIZED_USER_ID => (string) $stUser));
$F = ann($pageId, $opUser, 'F archived', 'publish', $past, array(
    VIS::META_AUTHORIZED_AT => $past, VIS::META_AUTHORIZED_USER_ID => (string) $opUser,
    VIS::META_ARCHIVED_AT => $past));
// G is the REAL scheduled shape: WordPress expresses "scheduled" as the
// post_status `future`, which is what listForOwner()'s wpStatusFor() asks
// for. C (publish + a future date) is a DIFFERENT case — see below.
$G = ann($pageId, $opUser, 'G scheduled', 'future', $future, array(
    REPO::META_PUBLISH_AT => $future));
ck('seven announcements seeded', 7, count(array_filter(array($A, $B, $C, $D, $E, $F, $G))));

update_option('bcc_announcements_enabled', '1');

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== 1. THE THREE §4.32.1 PREDICATES, THROUGH REAL DISPATCH ===\n";
$pub = '/bcc/v1/validators/' . $pageId . '/announcements';
$r = dsp($pub);
$seen = ids_of($r);
ck('public list status', 200, $r['status']);
ck('A (published + valid marker) IS listed', true, in_array('ann_' . $A, $seen, true));
ck('B (draft) is NOT listed', false, in_array('ann_' . $B, $seen, true));
ck('C (future-dated) is NOT listed', false, in_array('ann_' . $C, $seen, true));
ck('D (no publication marker) is NOT listed', false, in_array('ann_' . $D, $seen, true));
ck('E (marker names another user) is NOT listed', false, in_array('ann_' . $E, $seen, true));
ck('F (archived) is NOT in the ACTIVE list', false, in_array('ann_' . $F, $seen, true));
ck('active list holds exactly one row', 1, count($seen));

$ra = dsp($pub, array('state' => 'archived'));
$seenA = ids_of($ra);
ck('F IS in the ARCHIVED list', true, in_array('ann_' . $F, $seenA, true));
ck('A is NOT in the archived list', false, in_array('ann_' . $A, $seenA, true));

echo "\n=== 2. THE ASSERTION THAT MATTERS MOST — VIEWER INDEPENDENCE ===\n";
$anon = dsp($pub, array(), 0);
$oper = dsp($pub, array(), $opUser);
$stra = dsp($pub, array(), $stUser);
ck('public list BYTE-IDENTICAL anon vs operator', $anon['json'], $oper['json']);
ck('public list BYTE-IDENTICAL anon vs stranger', $anon['json'], $stra['json']);
ck('operator sees no capabilities on the public list', false, strpos($oper['json'], 'capabilities') !== false);
ck('operator sees no status on the public list', false, strpos($oper['json'], '"status"') !== false);
ck('operator sees no body on the public list', false, strpos($oper['json'], '"body"') !== false);
ck("operator's own draft absent from their public list", false, strpos($oper['json'], 'ann_' . $B) !== false);

$rotA = dsp('/bcc/v1/validators/' . $pageId . '/announcements/rotator', array(), 0);
$rotO = dsp('/bcc/v1/validators/' . $pageId . '/announcements/rotator', array(), $opUser);
ck('rotator BYTE-IDENTICAL anon vs operator', $rotA['json'], $rotO['json']);

echo "\n=== 3. DETAIL — 404 TO NON-OWNERS, NEVER 403 ===\n";
$dA = dsp('/bcc/v1/validators/' . $pageId . '/announcements/' . $A, array(), 0);
ck('public row, anonymous -> 200', 200, $dA['status']);
ck('detail carries body', true, isset($dA['data']['data']['body']));
ck('detail carries capabilities', true, isset($dA['data']['data']['capabilities']));

$dBanon = dsp('/bcc/v1/validators/' . $pageId . '/announcements/' . $B, array(), 0);
$dBstr  = dsp('/bcc/v1/validators/' . $pageId . '/announcements/' . $B, array(), $stUser);
$dMiss  = dsp('/bcc/v1/validators/' . $pageId . '/announcements/99999999', array(), 0);
ck('draft, anonymous -> 404 (never 403)', 404, $dBanon['status']);
ck('draft, stranger  -> 404 (never 403)', 404, $dBstr['status']);
ck('missing row      -> 404', 404, $dMiss['status']);
ck('draft-to-stranger body == missing-row body', $dMiss['json'], $dBstr['json']);
ck('draft-to-anon body    == missing-row body', $dMiss['json'], $dBanon['json']);

$dBop = dsp('/bcc/v1/validators/' . $pageId . '/announcements/' . $B, array(), $opUser);
ck('draft, OPERATOR -> 200', 200, $dBop['status']);
ck("operator's draft reports status 'draft'", 'draft', $dBop['data']['data']['status'] ?? null);
ck('no blocked state is ever emitted', false, strpos($dBop['json'], 'blocked') !== false);

$dF = dsp('/bcc/v1/validators/' . $pageId . '/announcements/' . $F, array(), 0);
ck('ARCHIVED row still resolves by permalink (§4.32.6)', 200, $dF['status']);

echo "\n=== 4. DETAIL / OWNER CACHE HEADERS ON A REACHED PATH ===\n";
ck('detail Cache-Control', 'private, no-store', $dA['headers']['Cache-Control'] ?? null);
ck('detail Vary', 'Authorization, Cookie', $dA['headers']['Vary'] ?? null);

$ownO = dsp('/bcc/v1/me/validators/' . $pageId . '/announcements', array(), $opUser);
ck('owner list, operator -> 200', 200, $ownO['status']);
ck('owner list Cache-Control', 'private, no-store', $ownO['headers']['Cache-Control'] ?? null);
ck('owner list Vary', 'Authorization, Cookie', $ownO['headers']['Vary'] ?? null);
$ownIds = ids_of($ownO);
ck('owner list holds the draft', true, in_array('ann_' . $B, $ownIds, true));
ck('owner list does NOT hold the published row', false, in_array('ann_' . $A, $ownIds, true));
ck('owner list holds the SCHEDULED row (post_status=future)', true, in_array('ann_' . $G, $ownIds, true));
ck('owner list does NOT hold the archived row', false, in_array('ann_' . $F, $ownIds, true));

// ⚠⚠ A HAZARD FOR A3, found by real dispatch rather than reasoned about.
// C is post_status='publish' with a post_date_gmt in the FUTURE. It is
// invisible on BOTH surfaces, and both exclusions are individually correct:
// the public list refuses it on §4.32.1 predicate 2 (not yet due), and the
// owner list never asks for `publish` because that status is the public
// list's business. So a writer that "schedules" by setting `publish` plus a
// future date would hide an announcement from its own author with no error
// anywhere. A3 must schedule with post_status='future' — which is what
// wpStatusFor('scheduled') already expects.
ck('C (publish + future date) is absent from the OWNER list', false, in_array('ann_' . $C, $ownIds, true));
$cPub = dsp($pub, array(), $opUser);
ck('C (publish + future date) is absent from the PUBLIC list', false, strpos($cPub['json'], 'ann_' . $C) !== false);
$cDet = dsp('/bcc/v1/validators/' . $pageId . '/announcements/' . $C, array(), $opUser);
ck('C IS still reachable by its own author via detail', 200, $cDet['status']);

$ownScheduled = dsp('/bcc/v1/me/validators/' . $pageId . '/announcements', array('status' => 'scheduled'), $opUser);
$schedIds = ids_of($ownScheduled);
ck('?status=scheduled returns G', true, in_array('ann_' . $G, $schedIds, true));
ck('?status=scheduled excludes the draft', false, in_array('ann_' . $B, $schedIds, true));
$ownDraft = dsp('/bcc/v1/me/validators/' . $pageId . '/announcements', array('status' => 'draft'), $opUser);
$draftIds = ids_of($ownDraft);
ck('?status=draft returns B', true, in_array('ann_' . $B, $draftIds, true));
ck('?status=draft excludes the scheduled row', false, in_array('ann_' . $G, $draftIds, true));

$ownS = dsp('/bcc/v1/me/validators/' . $pageId . '/announcements', array(), $stUser);
ck('owner list, stranger -> 403', 403, $ownS['status']);
ck('owner list, stranger reason_code', 'not_claimer', $ownS['reason']);
$ownAnon = dsp('/bcc/v1/me/validators/' . $pageId . '/announcements', array(), 0);
ck('owner list, anonymous -> 403', 403, $ownAnon['status']);
ck('anonymous and stranger denials are byte-identical', $ownAnon['json'], $ownS['json']);

echo "\n=== 5. limit BOUNDS NOW BIND (the real-dispatch finding) ===\n";
foreach (array(51, 0, 1000) as $bad) {
    $r = dsp($pub, array('limit' => $bad), 0);
    ck("limit=$bad -> 400", 400, $r['status']);
    ck("limit=$bad -> rest_invalid_param", 'rest_invalid_param', $r['code']);
}
$rOk = dsp($pub, array('limit' => 50), 0);
ck('limit=50 (the maximum) -> 200', 200, $rOk['status']);
$rOwn = dsp('/bcc/v1/me/validators/' . $pageId . '/announcements', array('limit' => 51), $opUser);
ck('owner limit=51 -> 400 too', 400, $rOwn['status']);

echo "\n=== 6. FEATURE OFF, WITH REAL PUBLISHED DATA PRESENT ===\n";
delete_option('bcc_announcements_enabled');
$offPub = dsp($pub, array(), 0);
$offDet = dsp('/bcc/v1/validators/' . $pageId . '/announcements/' . $A, array(), 0);
$offOwn = dsp('/bcc/v1/me/validators/' . $pageId . '/announcements', array(), $opUser);
ck('feature-off public list -> 404 despite a published row', 404, $offPub['status']);
ck('feature-off detail -> 404 despite a published row', 404, $offDet['status']);
ck('feature-off owner list -> 404 for the REAL operator', 404, $offOwn['status']);
ck('feature-off body mentions no announcement', false, stripos($offPub['json'], 'announcement') !== false);
ck('feature-off leaks no row id', false, strpos($offPub['json'], (string) $A) !== false);
ck('feature-off owner 404 == public 404 (byte-identical)', $offPub['json'], $offOwn['json']);
ck('feature-off emits NO public cache header', null, $offPub['headers']['Cache-Control'] ?? null);

echo "\n=== 7. FEATURE-OFF DOES NO AUTHORIZATION AND NO DATA READ ===\n";
// Every $wpdb->query() passes through the `query` filter, so this counts
// real SQL rather than inferring from a response. The feature is still off
// from section 6.
$seenSql = array();
$spy = static function ($sql) use (&$seenSql) {
    $seenSql[] = $sql;
    return $sql;
};
add_filter('query', $spy);

$seenSql = array();
dsp($pub, array(), $opUser);
$annSql   = array_filter($seenSql, static fn ($q) => stripos($q, 'bcc_announcement') !== false);
$claimSql = array_filter($seenSql, static fn ($q) => stripos($q, 'bcc_onchain_claims') !== false);
ck('feature-off public list: 0 announcement queries', 0, count($annSql));
ck('feature-off public list: 0 claim-resolution queries', 0, count($claimSql));

$seenSql = array();
dsp('/bcc/v1/me/validators/' . $pageId . '/announcements', array(), $opUser);
$annSql   = array_filter($seenSql, static fn ($q) => stripos($q, 'bcc_announcement') !== false);
$claimSql = array_filter($seenSql, static fn ($q) => stripos($q, 'bcc_onchain_claims') !== false);
ck('feature-off OWNER list: 0 announcement queries', 0, count($annSql));
ck('feature-off OWNER list: 0 claim-resolution queries', 0, count($claimSql));

$seenSql = array();
dsp('/bcc/v1/validators/' . $pageId . '/announcements/' . $A, array(), $opUser);
ck('feature-off detail: 0 announcement queries', 0, count(array_filter($seenSql, static fn ($q) => stripos($q, 'bcc_announcement') !== false)));

// Positive control: with the feature ON the same dispatches DO query, so the
// zeroes above measure the gate rather than a broken spy.
update_option('bcc_announcements_enabled', '1');
$seenSql = array();
dsp($pub, array(), $opUser);
ck('CONTROL feature-on public list DOES query announcements', true,
    count(array_filter($seenSql, static fn ($q) => stripos($q, 'bcc_announcement') !== false)) > 0);
$seenSql = array();
dsp('/bcc/v1/me/validators/' . $pageId . '/announcements', array(), $opUser);
ck('CONTROL feature-on owner list DOES resolve the claim', true,
    count(array_filter($seenSql, static fn ($q) => stripos($q, 'bcc_onchain_claims') !== false)) > 0);
remove_filter('query', $spy);
delete_option('bcc_announcements_enabled');

echo "\n=== 8. A READ FAILURE IS 503 ON ALL FOUR ROUTES, INCLUDING THE OWNER LIST ===\n";
// ⚠ The unit suite can prove this for the three routes that need no
// authority, by swapping in a $wpdb whose reads set last_error. It CANNOT
// reach the owner list's read, because that needs Authority::decide() to
// return `operator` — a real validator page and a real verified claim. Here
// both exist, so breaking the meta table underneath them exercises the one
// arm the unit suite has to leave to a source-shape assertion.
update_option('bcc_announcements_enabled', '1');
$metaTable = $wpdb->postmeta;
$parked    = $metaTable . '_a24parked';
$wpdb->query("RENAME TABLE {$metaTable} TO {$parked}");
$renamed = (string) $wpdb->get_var("SHOW TABLES LIKE '{$parked}'") === $parked;
ck('CONTROL the meta table really is gone', true, $renamed);

if ($renamed) {
    $cases = array(
        'public list'   => array($pub, array(), 0),
        'archived list' => array($pub, array('state' => 'archived'), 0),
        'rotator'       => array('/bcc/v1/validators/' . $pageId . '/announcements/rotator', array(), 0),
        'detail'        => array('/bcc/v1/validators/' . $pageId . '/announcements/' . $A, array(), 0),
        'OWNER list'    => array('/bcc/v1/me/validators/' . $pageId . '/announcements', array(), $opUser),
    );
    foreach ($cases as $name => $spec) {
        $r = dsp($spec[0], $spec[1], $spec[2]);
        ck('broken read: ' . $name . ' -> 503', 503, $r['status']);
        ck('broken read: ' . $name . ' -> bcc_unavailable', 'bcc_unavailable', $r['code']);
        ck('broken read: ' . $name . ' carries no payload', false, isset($r['data']['data']));
    }
    // ⚠⚠ THE FAULT MUST WIN OVER THE 404 SHIELD, and the first version of
    // this check asserted the opposite.
    //
    // It asserted that a missing id still answers 404 while the table is
    // broken — which is incoherent: with the meta table gone, the lookup
    // for a nonexistent row fails for the same reason every other read
    // does. And 503 is the RIGHT answer, because 404 is a claim ABOUT THE
    // DATA ("there is no such announcement") and the server is in no
    // position to make it. Answering 404 here would turn an outage into a
    // statement that an operator's notice does not exist.
    //
    // That the same id answers 404 with the table INTACT is asserted in
    // section 3.
    $rMissing = dsp('/bcc/v1/validators/' . $pageId . '/announcements/99999999', array(), 0);
    ck('a missing id ALSO 503s while the read is broken', 503, $rMissing['status']);

    $wpdb->query("RENAME TABLE {$parked} TO {$metaTable}");
    ck('meta table restored', true, (string) $wpdb->get_var("SHOW TABLES LIKE '{$metaTable}'") === $metaTable);
    $wpdb->flush();
    $ok = dsp($pub, array(), 0);
    ck('and the public list reads again', 200, $ok['status']);
}
delete_option('bcc_announcements_enabled');

echo "\n=== TEARDOWN ===\n";
foreach (array($A, $B, $C, $D, $E, $F, $G, $pageId) as $p) {
    wp_delete_post($p, true);
}
$wpdb->delete($wpdb->prefix . 'bcc_onchain_claims', array('entity_id' => $valId));
$wpdb->delete($wpdb->prefix . 'bcc_onchain_validators', array('id' => $valId));
$wpdb->delete($wpdb->prefix . 'bcc_wallet_links', array('id' => $linkId));
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($opUser);
wp_delete_user($stUser);
delete_option('bcc_announcements_enabled');
$left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", CPT::POST_TYPE));
ck('no announcement rows left behind', 0, $left);

printf("\n==== SEEDED REAL DISPATCH: pass=%d fail=%d ====\n", $GLOBALS['PASS2'] ?? 0, $GLOBALS['FAIL2'] ?? 0);
