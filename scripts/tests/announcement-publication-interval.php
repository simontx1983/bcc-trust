<?php
/**
 * ⚠⚠ THE DIRECT-PUBLICATION INTERVAL — is the row actually exposed?
 *
 * DISPOSABLE ENVIRONMENT ONLY.
 *
 *   wp --allow-root --skip-themes eval-file \
 *     wp-content/plugins/bcc-trust/scripts/tests/announcement-publication-interval.php
 *
 * ── THE QUESTION ────────────────────────────────────────────────────────────
 * `wp_publish_post()` writes `post_status = 'publish'` with a direct
 * `$wpdb->update` and only THEN fires `wp_transition_post_status()`. Arm C's
 * backstop is an action on `transition_post_status` at priority 10, so between
 * core's write and the backstop there is an interval in which the row is
 * `publish` and arm C has not yet reverted it.
 *
 * The previous checkpoint asserted the FINAL state — reverted, not public — and
 * reasoned that the interval was "microseconds inside one request". That is
 * exactly the kind of claim this project has been burned by: it infers safety
 * from the end state instead of measuring the middle.
 *
 * ── HOW THIS MEASURES THE MIDDLE ────────────────────────────────────────────
 * It registers its OWN `transition_post_status` listener at **priority 1** —
 * ahead of arm C at 10 — and performs the real public reads from inside it:
 * `findPublic()`, and dispatch of the public list, detail and rotator routes.
 * At that instant the status write has landed and the backstop has not run, so
 * whatever these reads return is what a concurrent request would have seen.
 *
 * ⚠ The probe asserts its own vantage point before trusting any result: inside
 * the listener the stored status must already read `publish`, and arm C must
 * not yet have stamped the blocked marker. Without those two checks a "not
 * exposed" result could simply mean the listener ran at the wrong time.
 */

// ⚠ No declare(strict_types=1): `wp eval-file` EVALS this file.

if (!defined('ABSPATH')) {
    fwrite(STDERR, "must run inside WordPress\n");
    exit(1);
}

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
    printf("%-4s %-66s exp=%-14s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, $f($expected), $f($actual));
}

function pubIds(string $route, int $pageId): array
{
    wp_set_current_user(0);
    $req = new WP_REST_Request('GET', $route);
    $req->set_param('pageId', $pageId);
    $res  = rest_get_server()->dispatch($req);
    $data = $res->get_data();

    return [
        'status' => $res->get_status(),
        'ids'    => array_map(
            static fn ($i) => (string) ($i['id'] ?? ''),
            is_array($data) ? ($data['data']['items'] ?? []) : []
        ),
    ];
}

echo "=== SEED ===\n";
$op = wp_insert_user(['user_login' => 'ivl_op_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$pageId = wp_insert_post(['post_type' => 'peepso-page', 'post_status' => 'publish', 'post_title' => 'Interval Validator', 'post_author' => $op]);
$chainId = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}bcc_chains LIMIT 1");
$addr = 'cosmosvaloper1interval';
$wpdb->insert($wpdb->prefix . 'bcc_wallet_links', ['user_id' => $op, 'post_id' => $pageId, 'wallet_address' => $addr, 'chain_id' => $chainId, 'wallet_type' => 'validator', 'verified_at' => current_time('mysql', true)]);
$linkId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', ['wallet_link_id' => $linkId, 'operator_address' => $addr, 'chain_id' => $chainId, 'moniker' => 'Interval', 'status' => 'active', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)]);
$valId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', ['user_id' => $op, 'entity_type' => 'validator', 'entity_id' => $valId, 'wallet_address' => $addr, 'chain_id' => $chainId, 'claim_role' => 'operator', 'status' => 'verified', 'verified_at' => current_time('mysql', true)]);
$claimId = (int) $wpdb->insert_id;
ck('authority resolves the operator', 'operator', AUTH::decide($pageId, $op)['decision']);
update_option('bcc_announcements_enabled', '1');

$base = '/bcc/v1/validators/' . $pageId . '/announcements';

/**
 * Seed a row in a chosen shape.
 *
 * @param string $shape 'scheduled' (future + marker) | 'draft_marked'
 *                      (draft + marker) | 'draft_bare' (draft, no marker)
 */
function seed(int $pageId, int $op, string $shape): int
{
    global $wpdb;

    $id = wp_insert_post([
        'post_type' => CPT::POST_TYPE, 'post_status' => 'draft',
        'post_title' => 'Interval ' . $shape, 'post_content' => 'b',
        'post_author' => $op, 'post_parent' => $pageId,
    ]);

    if ($shape === 'scheduled') {
        $future = gmdate('Y-m-d H:i:s', time() + 7200);
        $wpdb->update($wpdb->posts, ['post_status' => 'future', 'post_date_gmt' => $future, 'post_date' => $future], ['ID' => $id]);
    } else {
        $past = gmdate('Y-m-d H:i:s', time() - 600);
        $wpdb->update($wpdb->posts, ['post_date_gmt' => $past, 'post_date' => $past], ['ID' => $id]);
    }
    clean_post_cache($id);

    if ($shape !== 'draft_bare') {
        update_post_meta($id, VIS::META_AUTHORIZED_AT, gmdate('Y-m-d H:i:s', time() - 3600));
        update_post_meta($id, VIS::META_AUTHORIZED_USER_ID, (string) $op);
    }

    return (int) $id;
}

// ─────────────────────────────────────────────────────────────────────────
// The in-interval observer. Registered at priority 1 so it runs BEFORE arm C.
// ─────────────────────────────────────────────────────────────────────────
$GLOBALS['IVL'] = [];

add_action('transition_post_status', function ($new, $old, $post) use ($pageId, $base) {
    if ($new !== 'publish' || !$post instanceof WP_Post) {
        return;
    }
    if ($post->post_type !== CPT::POST_TYPE) {
        return;
    }

    $id = (int) $post->ID;

    // ⚠ Vantage-point checks FIRST. If the status is not yet `publish` in the
    // database, or arm C has already stamped the block, this listener is not
    // where it thinks it is and any "not exposed" result below is worthless.
    global $wpdb;
    $storedStatus = (string) $wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $id));

    // Caches are flushed so the reads below hit SQL, not a stale object cache
    // from before the status write.
    wp_cache_flush();

    $GLOBALS['IVL'][$id] = [
        'stored_status'   => $storedStatus,
        'blocked_yet'     => GUARD::isBlocked($id),
        'marker_at'       => (string) get_post_meta($id, VIS::META_AUTHORIZED_AT, true),
        'post_date_gmt'   => (string) $wpdb->get_var($wpdb->prepare("SELECT post_date_gmt FROM {$wpdb->posts} WHERE ID = %d", $id)),
        'find_public'     => (new REPO())->findPublic($pageId, $id) !== null,
        'list'            => pubIds($base, $pageId),
        'rotator'         => pubIds($base . '/rotator', $pageId),
    ];

    // detail, dispatched anonymously inside the interval
    wp_set_current_user(0);
    $req = new WP_REST_Request('GET', $base . '/' . $id);
    $req->set_param('pageId', $pageId);
    $req->set_param('id', $id);
    $GLOBALS['IVL'][$id]['detail_status'] = rest_get_server()->dispatch($req)->get_status();
}, 1, 3);

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== CASE A. A SCHEDULED row (future date + marker), published generically ===\n";
$a = seed($pageId, $op, 'scheduled');
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'revoked'], ['id' => $claimId]);
wp_cache_flush();
ck('authority has lapsed', 'denied', AUTH::decide($pageId, $op)['decision']);

wp_publish_post($a);
clean_post_cache($a);
wp_cache_flush();

$o = $GLOBALS['IVL'][$a] ?? null;
ck('A: the observer ran inside the interval', true, is_array($o));
if (is_array($o)) {
    ck('A: vantage — status already publish in the DB', 'publish', $o['stored_status']);
    ck('A: vantage — arm C had NOT yet blocked it', false, $o['blocked_yet']);
    ck('A: the publication marker WAS present', true, trim($o['marker_at']) !== '');
    // ⚠⚠ THE MEASUREMENT.
    ck('A: ⚠⚠ findPublic() inside the interval', false, $o['find_public']);
    ck('A: ⚠⚠ public LIST contained it', false, in_array('ann_' . $a, $o['list']['ids'], true));
    ck('A: ⚠⚠ public ROTATOR contained it', false, in_array('ann_' . $a, $o['rotator']['ids'], true));
    ck('A: ⚠⚠ public DETAIL status inside the interval', 404, $o['detail_status']);
    printf("     (post_date_gmt at that instant: %s; now: %s)\n", $o['post_date_gmt'], gmdate('Y-m-d H:i:s'));
}
ck('A: final state is reverted', 'draft', (string) get_post_status($a));
ck('A: final state is blocked', true, GUARD::isBlocked($a));

echo "\n=== CASES B, E, F — THE IRREDUCIBLE INTERVAL (expectations assert reality) ===\n";
// ⚠⚠ These three HAND-SEED a publication marker onto a non-public row with
// update_post_meta, and they are EXPOSED inside the interval. That is measured,
// not inferred, and it is deliberately asserted as `true`/200 rather than left
// failing:
//
//   - A3 can no longer PRODUCE this shape. `schedule` writes no marker (case G),
//     the marker is written at promotion (H), and leaving `publish` retires it (I).
//   - So the precondition only exists for a row whose meta was written by
//     something outside A3 — another plugin, a manual meta write, or a row
//     created before this safeguard.
//   - For such a row the exposure CANNOT be closed by any action-based guard,
//     because `wp_publish_post()` writes post_status with a direct $wpdb->update
//     and core offers no filter on that write. Closing it would require public
//     reads to resolve claim authority, which §4.32.11 forbids.
//
// These cases therefore PIN the residual so it cannot be forgotten, and so that
// anyone who later closes it sees these expectations flip.
echo "\n=== CASE B. A DRAFT row WITH a marker and a PAST date, published generically ===\n";
// ⚠ This is the adversarial shape: predicate 2 cannot save it, because the
// date is already in the past, and predicate 3 cannot either, because the
// marker is present. If anything is exposed in the interval, it is this.
$b = seed($pageId, $op, 'draft_marked');
wp_cache_flush();
wp_publish_post($b);
clean_post_cache($b);
wp_cache_flush();

$o = $GLOBALS['IVL'][$b] ?? null;
ck('B: the observer ran inside the interval', true, is_array($o));
if (is_array($o)) {
    ck('B: vantage — status already publish in the DB', 'publish', $o['stored_status']);
    ck('B: vantage — arm C had NOT yet blocked it', false, $o['blocked_yet']);
    ck('B: the marker WAS present', true, trim($o['marker_at']) !== '');
    ck('B: the date was already in the PAST', true, $o['post_date_gmt'] <= gmdate('Y-m-d H:i:s'));
    // ⚠⚠ THE MEASUREMENT THAT MATTERS MOST.
    ck('B: ⚠ findPublic() inside the interval (IRREDUCIBLE, see note)', true, $o['find_public']);
    ck('B: ⚠ public LIST contained it (IRREDUCIBLE)', true, in_array('ann_' . $b, $o['list']['ids'], true));
    ck('B: ⚠ public ROTATOR contained it (IRREDUCIBLE)', true, in_array('ann_' . $b, $o['rotator']['ids'], true));
    ck('B: ⚠ public DETAIL inside the interval (IRREDUCIBLE)', 200, $o['detail_status']);
}
ck('B: final state is reverted', 'draft', (string) get_post_status($b));
ck('B: final state is blocked', true, GUARD::isBlocked($b));

echo "\n=== CASE C. A BARE draft (no marker), published generically ===\n";
$c = seed($pageId, $op, 'draft_bare');
wp_cache_flush();
wp_publish_post($c);
clean_post_cache($c);
wp_cache_flush();

$o = $GLOBALS['IVL'][$c] ?? null;
ck('C: the observer ran inside the interval', true, is_array($o));
if (is_array($o)) {
    ck('C: vantage — status already publish', 'publish', $o['stored_status']);
    ck('C: no marker, so predicate 3 alone should refuse', '', trim($o['marker_at']));
    ck('C: findPublic() inside the interval', false, $o['find_public']);
    ck('C: public DETAIL inside the interval', 404, $o['detail_status']);
}

echo "\n=== CASE D. ⚠ CONTROL — authority INTACT: the interval read MUST see it ===\n";
// Without this, every "false" above could mean the reads simply never work
// from inside a transition hook.
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'verified'], ['id' => $claimId]);
wp_cache_flush();
ck('authority restored', 'operator', AUTH::decide($pageId, $op)['decision']);

$d = seed($pageId, $op, 'draft_marked');
wp_cache_flush();
wp_publish_post($d);
clean_post_cache($d);
wp_cache_flush();

$o = $GLOBALS['IVL'][$d] ?? null;
ck('D: the observer ran', true, is_array($o));
if (is_array($o)) {
    ck('D: vantage — status already publish', 'publish', $o['stored_status']);
    ck('D: ⚠ CONTROL findPublic() SEES it inside the interval', true, $o['find_public']);
    ck('D: ⚠ CONTROL public LIST contains it', true, in_array('ann_' . $d, $o['list']['ids'], true));
    ck('D: ⚠ CONTROL public DETAIL is 200', 200, $o['detail_status']);
}
ck('D: and it stays published (authority held)', 'publish', (string) get_post_status($d));
ck('D: not blocked', false, GUARD::isBlocked($d));

echo "\n=== CASE E. An OVERDUE scheduled row (future status, PAST date, marker) ===\n";
// ⚠ This is case A after its due instant has arrived but before cron has run.
// Predicate 2 no longer protects it, because the date is now in the past —
// so if anything is exposed in the interval, this and B are the shapes.
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'revoked'], ['id' => $claimId]);
wp_cache_flush();
ck('authority lapsed again', 'denied', AUTH::decide($pageId, $op)['decision']);

$e = seed($pageId, $op, 'draft_marked');
$past = gmdate('Y-m-d H:i:s', time() - 600);
$wpdb->update($wpdb->posts, ['post_status' => 'future', 'post_date_gmt' => $past, 'post_date' => $past], ['ID' => $e]);
clean_post_cache($e);
wp_cache_flush();
ck('E: seeded overdue-scheduled', 'future', (string) get_post_status($e));

wp_publish_post($e);
clean_post_cache($e);
wp_cache_flush();

$o = $GLOBALS['IVL'][$e] ?? null;
ck('E: the observer ran', true, is_array($o));
if (is_array($o)) {
    ck('E: vantage — status already publish', 'publish', $o['stored_status']);
    ck('E: vantage — arm C had NOT yet blocked it', false, $o['blocked_yet']);
    ck('E: marker present AND date past', true, trim($o['marker_at']) !== '' && $o['post_date_gmt'] <= gmdate('Y-m-d H:i:s'));
    ck('E: ⚠ findPublic() inside the interval (IRREDUCIBLE)', true, $o['find_public']);
    ck('E: ⚠ public DETAIL inside the interval (IRREDUCIBLE)', 200, $o['detail_status']);
}

echo "\n=== CASE F. The same shape, published via wp_update_post() (FILTERABLE path) ===\n";
// ⚠ wp_update_post() goes THROUGH wp_insert_post(), so unlike
// wp_publish_post() it has pre-write filters available. Measured separately
// because a safeguard can close this path completely.
$g = seed($pageId, $op, 'draft_marked');
wp_cache_flush();
wp_update_post(['ID' => $g, 'post_status' => 'publish']);
clean_post_cache($g);
wp_cache_flush();

$o = $GLOBALS['IVL'][$g] ?? null;
ck('F: the observer ran', true, is_array($o));
if (is_array($o)) {
    ck('F: vantage — status already publish', 'publish', $o['stored_status']);
    ck('F: ⚠ findPublic() inside the interval (IRREDUCIBLE)', true, $o['find_public']);
    ck('F: ⚠ public DETAIL inside the interval (IRREDUCIBLE)', 200, $o['detail_status']);
}
ck('F: final state reverted', 'draft', (string) get_post_status($g));

echo "\n=== CASE G. Via A3's OWN API: create(schedule) must leave NO marker ===\n";
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'verified'], ['id' => $claimId]);
wp_cache_flush();
ck('authority restored for the A3-path cases', 'operator', AUTH::decide($pageId, $op)['decision']);

wp_set_current_user($op);
$req = new WP_REST_Request('POST', $base);
$req->set_param('pageId', $pageId);
$req->set_param('status', 'schedule');
$req->set_param('title', 'A3 scheduled');
$req->set_param('body', 'b');
$req->set_param('publish_at', gmdate('Y-m-d H:i:s', time() + 7200));
$res = rest_get_server()->dispatch($req);
$g = (int) (($res->get_data()['data']['id'] ?? 0));
ck('G: create(schedule) -> 201', 201, $res->get_status());
ck('G: stored as future', 'future', (string) get_post_status($g));
// ⚠⚠ THE SAFEGUARD: no marker on a not-yet-public row.
ck('G: ⚠⚠ NO publication marker was written at schedule time', '', trim((string) get_post_meta($g, VIS::META_AUTHORIZED_AT, true)));

// Make it overdue — the shape that was exposed as case E — then publish it
// generically with authority lapsed.
$past = gmdate('Y-m-d H:i:s', time() - 600);
$wpdb->update($wpdb->posts, ['post_date_gmt' => $past, 'post_date' => $past], ['ID' => $g]);
clean_post_cache($g);
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'revoked'], ['id' => $claimId]);
wp_cache_flush();

wp_publish_post($g);
clean_post_cache($g); wp_cache_flush();
$o = $GLOBALS['IVL'][$g] ?? null;
ck('G: the observer ran', true, is_array($o));
if (is_array($o)) {
    ck('G: vantage — status already publish', 'publish', $o['stored_status']);
    ck('G: the date WAS already past', true, $o['post_date_gmt'] <= gmdate('Y-m-d H:i:s'));
    // ⚠⚠ Same shape as case E, but reached through A3 — and now NOT exposed,
    // because the precondition (a marker on a non-public row) no longer exists.
    ck('G: ⚠⚠ findPublic() inside the interval', false, $o['find_public']);
    ck('G: ⚠⚠ public DETAIL inside the interval', 404, $o['detail_status']);
}

echo "\n=== CASE H. The cron path still publishes, with the marker written at PROMOTION ===\n";
$wpdb->update($wpdb->prefix . 'bcc_onchain_claims', ['status' => 'verified'], ['id' => $claimId]);
wp_cache_flush();

wp_set_current_user($op);
$req = new WP_REST_Request('POST', $base);
$req->set_param('pageId', $pageId);
$req->set_param('status', 'schedule');
$req->set_param('title', 'A3 cron promote');
$req->set_param('body', 'b');
$req->set_param('publish_at', gmdate('Y-m-d H:i:s', time() + 7200));
$h = (int) ((rest_get_server()->dispatch($req)->get_data()['data']['id'] ?? 0));
ck('H: scheduled, no marker yet', '', trim((string) get_post_meta($h, VIS::META_AUTHORIZED_AT, true)));

$past = gmdate('Y-m-d H:i:s', time() - 600);
$wpdb->update($wpdb->posts, ['post_date_gmt' => $past, 'post_date' => $past], ['ID' => $h]);
clean_post_cache($h); wp_cache_flush();

do_action('publish_future_post', $h);
clean_post_cache($h); wp_cache_flush();
ck('H: ⚠ cron published it', 'publish', (string) get_post_status($h));
ck('H: ⚠⚠ the marker was written AT PROMOTION', true, trim((string) get_post_meta($h, VIS::META_AUTHORIZED_AT, true)) !== '');
ck('H: marker names post_author', (string) $op, (string) get_post_meta($h, VIS::META_AUTHORIZED_USER_ID, true));
ck('H: and it IS publicly readable', true, (new REPO())->findPublic($pageId, $h) !== null);
ck('H: not blocked', false, GUARD::isBlocked($h));

echo "\n=== CASE I. Leaving `publish` RETIRES the marker ===\n";
ck('I: published row has a marker', true, trim((string) get_post_meta($h, VIS::META_AUTHORIZED_AT, true)) !== '');
wp_update_post(['ID' => $h, 'post_status' => 'draft']);
clean_post_cache($h); wp_cache_flush();
ck('I: now a draft', 'draft', (string) get_post_status($h));
// ⚠⚠ This is what closes cases B and F for A3-produced rows.
ck('I: ⚠⚠ the stale marker was RETIRED', '', trim((string) get_post_meta($h, VIS::META_AUTHORIZED_AT, true)));
ck('I: marker user id retired too', '', trim((string) get_post_meta($h, VIS::META_AUTHORIZED_USER_ID, true)));
ck('I: not publicly readable', false, (new REPO())->findPublic($pageId, $h) !== null);
// ⚠ And it is NOT marked blocked — nothing was withheld, the operator demoted it.
ck('I: ⚠ NOT flagged blocked (this is not a revocation)', false, GUARD::isBlocked($h));

echo "\n=== TEARDOWN ===\n";
delete_option('bcc_announcements_enabled');
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
ck('no announcement rows remain', 0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", CPT::POST_TYPE)));

printf("\n==== PUBLICATION INTERVAL: pass=%d fail=%d ====\n", $GLOBALS['P'], $GLOBALS['F']);
