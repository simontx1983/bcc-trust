<?php
/**
 * The at-most-one-pin invariant under REAL CONCURRENCY.
 *
 * ⚠⚠ DISPOSABLE ENVIRONMENT ONLY.
 *
 * ── WHY THIS EXISTS SEPARATELY FROM THE JOURNEY PROBE ───────────────────────
 * The journey probe pins twice SEQUENTIALLY and finds one pin left. That proves
 * the clear-then-set logic, and nothing about concurrency: a sequential test
 * cannot interleave, so it cannot observe the read-then-write race it would
 * take two processes to produce. "Two pins leave one" is not a concurrency
 * proof, and treating it as one is how a race ships.
 *
 * ── HOW IT PRODUCES REAL CONTENTION ─────────────────────────────────────────
 * One role per invocation, selected by BCC_PIN_ROLE:
 *
 *   seed  — create the page, claim chain and N published announcements, then
 *           print the ids for the shell to fan out over
 *   pin   — pin ONE announcement (BCC_PIN_ID) through real REST dispatch
 *   check — assert exactly one pin survives, then tear everything down
 *
 * The shell launches the `pin` role N times as SEPARATE OS PROCESSES, each with
 * its own PHP runtime, its own WordPress boot and its own database connection.
 * That is genuine parallelism against one MySQL server, which is the only way
 * the interleaving under test can happen at all.
 *
 * ⚠ Each child waits on a shared start barrier (BCC_PIN_AT, a unix timestamp)
 * before dispatching, so the processes converge on the critical section instead
 * of serialising behind their own boot times. Without the barrier the slowest
 * boot finishes after the fastest child has already committed, and the race
 * window is never entered — the test would pass while proving nothing.
 */

// ⚠ No declare(strict_types=1): `wp eval-file` EVALS this file.

if (!defined('ABSPATH')) {
    fwrite(STDERR, "must run inside WordPress\n");
    exit(1);
}

use BCC\Trust\Core\Announcements\AnnouncementPostType as CPT;
use BCC\Trust\Core\Announcements\AnnouncementVisibility as VIS;
use BCC\Trust\Core\Repositories\AnnouncementRepository as REPO;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as AUTH;

global $wpdb;

$role  = (string) getenv('BCC_PIN_ROLE');
$addr  = 'cosmosvaloper1pinrace';
$state = '/tmp/bcc-pin-race.json';

/** @return array<string,mixed> */
function st(string $path): array
{
    $raw = @file_get_contents($path);

    return is_string($raw) ? (array) json_decode($raw, true) : [];
}

// ─────────────────────────────────────────────────────────────── seed ──────
if ($role === 'seed') {
    update_option('bcc_announcements_enabled', '1');

    $op = wp_insert_user(['user_login' => 'race_op_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
    $pageId = wp_insert_post(['post_type' => 'peepso-page', 'post_status' => 'publish', 'post_title' => 'Pin Race Validator', 'post_author' => $op]);
    $chainId = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}bcc_chains LIMIT 1");

    $wpdb->insert($wpdb->prefix . 'bcc_wallet_links', ['user_id' => $op, 'post_id' => $pageId, 'wallet_address' => $addr, 'chain_id' => $chainId, 'wallet_type' => 'validator', 'verified_at' => current_time('mysql', true)]);
    $linkId = (int) $wpdb->insert_id;
    $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', ['wallet_link_id' => $linkId, 'operator_address' => $addr, 'chain_id' => $chainId, 'moniker' => 'Pin Race', 'status' => 'active', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)]);
    $valId = (int) $wpdb->insert_id;
    $wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', ['user_id' => $op, 'entity_type' => 'validator', 'entity_id' => $valId, 'wallet_address' => $addr, 'chain_id' => $chainId, 'claim_role' => 'operator', 'status' => 'verified', 'verified_at' => current_time('mysql', true)]);

    if ((AUTH::decide($pageId, $op)['decision'] ?? '') !== 'operator') {
        echo "SEED-FAIL authority did not resolve\n";
        exit(1);
    }

    // N published, pinnable announcements.
    $past = gmdate('Y-m-d H:i:s', time() - 600);
    $ids  = [];
    for ($i = 0; $i < 6; $i++) {
        $id = wp_insert_post([
            'post_type' => CPT::POST_TYPE, 'post_status' => 'draft',
            'post_title' => 'Race ' . $i, 'post_content' => 'b',
            'post_author' => $op, 'post_parent' => $pageId,
        ]);
        $wpdb->update($wpdb->posts, ['post_status' => 'publish', 'post_date_gmt' => $past, 'post_date' => $past], ['ID' => $id]);
        clean_post_cache($id);
        update_post_meta($id, VIS::META_AUTHORIZED_AT, $past);
        update_post_meta($id, VIS::META_AUTHORIZED_USER_ID, (string) $op);
        $ids[] = (int) $id;
    }

    file_put_contents($state, (string) json_encode([
        'page' => $pageId, 'op' => $op, 'ids' => $ids,
        'val' => $valId, 'link' => $linkId,
    ]));

    printf("SEED page=%d op=%d ids=%s\n", $pageId, $op, implode(',', $ids));
    exit(0);
}

// ──────────────────────────────────────────────────────────────── pin ──────
if ($role === 'pin') {
    $s  = st($state);
    $id = (int) getenv('BCC_PIN_ID');
    $at = (int) getenv('BCC_PIN_AT');

    // ⚠ The start barrier. Busy-wait to the shared instant so every child
    // enters the critical section together; sleep() granularity is too coarse.
    if ($at > 0) {
        // ⚠ usleep polling, not a busy spin. Six PHP processes spinning on
        // microtime() in one container starved its CPU and five of them
        // produced no output at all — which made the first run of this
        // probe report "exactly one pin" when only ONE child had run.
        while (microtime(true) < $at) {
            usleep(2000);
        }
    }

    wp_set_current_user((int) $s['op']);
    $req = new WP_REST_Request('POST', '/bcc/v1/validators/' . (int) $s['page'] . '/announcements/' . $id . '/pin');
    $req->set_param('pageId', (int) $s['page']);
    $req->set_param('id', $id);
    $req->set_param('pinned', true);
    $res = rest_get_server()->dispatch($req);

    printf("PIN id=%d status=%d\n", $id, $res->get_status());
    exit(0);
}

// ────────────────────────────────────────────────────────────── check ──────
if ($role === 'check') {
    $s = st($state);
    $pageId = (int) ($s['page'] ?? 0);
    wp_cache_flush();

    $pinned = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
          INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
         WHERE p.post_type = %s AND p.post_parent = %d AND m.meta_key = %s",
        CPT::POST_TYPE,
        $pageId,
        REPO::META_PINNED_AT
    ));
    $count = count(is_array($pinned) ? $pinned : []);

    $ok = $count === 1;
    printf(
        "%-4s %-56s exp=%-6s got=%s\n",
        $ok ? 'PASS' : 'FAIL',
        'EXACTLY ONE pin survives concurrent pinning',
        '1',
        (string) $count
    );
    if (!$ok) {
        printf("     surviving pinned ids: %s\n", implode(',', (array) $pinned));
    }

    // ⚠ Anti-vacuity: the race is only meaningful if the children actually
    // pinned something. Zero pins would mean every child failed and the "one
    // pin" assertion would be measuring nothing.
    printf(
        "%-4s %-56s exp=%-6s got=%s\n",
        $count >= 1 ? 'PASS' : 'FAIL',
        'at least one child actually pinned (anti-vacuity)',
        '>=1',
        (string) $count
    );

    // teardown
    delete_option('bcc_announcements_enabled');
    foreach ((array) ($s['ids'] ?? []) as $id) {
        wp_delete_post((int) $id, true);
    }
    wp_delete_post($pageId, true);
    $wpdb->delete($wpdb->prefix . 'bcc_onchain_claims', ['entity_id' => (int) ($s['val'] ?? 0)]);
    $wpdb->delete($wpdb->prefix . 'bcc_onchain_validators', ['id' => (int) ($s['val'] ?? 0)]);
    $wpdb->delete($wpdb->prefix . 'bcc_wallet_links', ['id' => (int) ($s['link'] ?? 0)]);
    wp_delete_user((int) ($s['op'] ?? 0));
    @unlink($state);

    $left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", CPT::POST_TYPE));
    printf("%-4s %-56s exp=%-6s got=%s\n", $left === 0 ? 'PASS' : 'FAIL', 'teardown left no announcement rows', '0', (string) $left);
    exit($ok ? 0 : 1);
}

fwrite(STDERR, "set BCC_PIN_ROLE to seed|pin|check\n");
exit(2);
