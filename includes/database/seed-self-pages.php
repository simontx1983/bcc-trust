<?php
/**
 * One-time cutover seed (Architecture A — a member is a self-page).
 *
 * Relocates every member's legacy `bcc_trust_reputation.reputation_score`
 * onto their self-page (`bcc_trust_page_scores`, page_id = 1e9 + user_id)
 * so the tier is preserved when the reputation reads cut over to the
 * self-page (Slice 1c, Stage B). The actual per-row write lives in
 * ScoreRepository::seedSelfPageFromReputation (canonical formula, pristine-
 * guarded); this file only orchestrates the bounded scan of the legacy
 * table — kept here, NOT on ReputationRepository, because that repo's
 * reads repoint to the self-page during the cutover.
 *
 * Idempotent: guarded by the `bcc_trust_self_pages_seeded` option and a
 * per-row pristine check, so it is safe to re-run and a no-op once the
 * legacy table is dropped (Stage E). Mirrors the existing one-time
 * backfill pattern in schema-user-info.php.
 *
 * Fires on `init` (priority 20) so the ServiceLocator is bootstrapped.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_seed_self_pages')) {
    function bcc_trust_seed_self_pages(): void
    {
        if (get_option('bcc_trust_self_pages_seeded')) {
            return;
        }

        global $wpdb;
        $repTable = $wpdb->prefix . 'bcc_trust_reputation';

        // Legacy table already gone (post-Stage-E or fresh install) → nothing
        // to relocate; mark done so we stop checking.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $repTable));
        if ($exists !== $repTable) {
            update_option('bcc_trust_self_pages_seeded', time(), false);
            return;
        }

        if (!class_exists('\\BCC\\Trust\\Core\\Plugin')) {
            return; // ServiceLocator not ready; try again next request.
        }
        $scoreRepo = \BCC\Trust\Core\Plugin::instance()->scoreRepository();

        $after  = 0;
        $seeded = 0;
        $chunk  = 500; // keyset pagination — bounded per query (§4).
        do {
            /** @var list<object{user_id: int|numeric-string, reputation_score: float|numeric-string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, reputation_score
                   FROM {$repTable}
                  WHERE user_id > %d
                  ORDER BY user_id ASC
                  LIMIT %d",
                $after,
                $chunk
            ));

            foreach ($rows ?: [] as $row) {
                $uid = (int) $row->user_id;
                if ($uid > 0 && $scoreRepo->seedSelfPageFromReputation($uid, (float) $row->reputation_score)) {
                    $seeded++;
                }
                $after = $uid;
            }
        } while (!empty($rows) && count($rows) === $chunk);

        update_option('bcc_trust_self_pages_seeded', time(), false);

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] self-page cutover seed complete', [
                'seeded' => $seeded,
            ]);
        }
    }

    add_action('init', 'bcc_trust_seed_self_pages', 20);
}
