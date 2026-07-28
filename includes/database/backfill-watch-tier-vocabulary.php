<?php
/**
 * One-shot backfill — rewrite historical `tier_at_watch` snapshots from the
 * retired card-rarity vocabulary to reputation tiers (contract v1.56).
 *
 * `bcc_watch_meta.tier_at_watch` and `bcc_page_follows.tier_at_watch` store
 * the followee's tier AS OF the moment of the watch. Until v1.56 the value
 * written was the card RARITY slug (legendary/rare/uncommon/common), not the
 * reputation tier. WatchingService now writes and reads reputation tiers, so
 * without this migration every pre-existing row would render its label
 * through ReputationTierMap::toReputationTierLabel('rare') — an unknown key,
 * which falls back to "Neutral". Every historical watch would silently claim
 * the followee was Neutral at watch time.
 *
 * The mapping is the exact inverse of the retired TIER_TO_CARD:
 *
 *     legendary → elite      rare    → trusted
 *     uncommon  → neutral    common  → caution
 *
 * `risky` is unrecoverable and that is not a defect of this migration — it is
 * the defect that motivated the retirement. Risky had NO rarity slot, so a
 * risky followee was snapshotted as NULL. Those rows stay NULL: the
 * information was never recorded, and inventing a value would be worse than
 * admitting the gap. New watches capture all five tiers.
 *
 * Idempotent: the mapping is keyed on the four rarity slugs, none of which is
 * also a reputation tier, so a second pass matches zero rows. The completion
 * option is owned by the migration runner, not this function.
 *
 * Status contract (bounded batch, returns a status):
 *   - a table is missing            → COMPLETE (nothing to migrate)
 *   - an UPDATE errors              → INCOMPLETE (retryable)
 *   - all rarity slugs drained      → COMPLETE
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since contract v1.56 (rarity vocabulary retirement)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_backfill_watch_tier_vocabulary')) {

    /**
     * Inverse of the retired ReputationTierMap::TIER_TO_CARD.
     *
     * @return array<string, string>
     */
    function bcc_trust_watch_rarity_to_reputation_tier(): array
    {
        return [
            'legendary' => 'elite',
            'rare'      => 'trusted',
            'uncommon'  => 'neutral',
            'common'    => 'caution',
        ];
    }

    function bcc_trust_backfill_watch_tier_vocabulary(): string
    {
        global $wpdb;

        $tables = [
            \BCC\Trust\Core\Database\TableRegistry::watchMeta(),
            \BCC\Trust\Core\Database\TableRegistry::pageFollows(),
        ];

        $hadFailure = false;
        $rewritten  = 0;

        foreach ($tables as $table) {
            if (!is_string($table) || $table === '') {
                continue;
            }

            // Skip cleanly when the table hasn't been created yet (fresh
            // install ordering) rather than reporting a retryable failure
            // forever.
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                continue;
            }

            foreach (bcc_trust_watch_rarity_to_reputation_tier() as $rarity => $tier) {
                // Set-based, not row-by-row: the eligible set is tiny (one
                // row per watch) and each UPDATE is a single indexed scan on
                // idx_tier_at_watch. Bounded by the four known slugs.
                $result = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET tier_at_watch = %s WHERE tier_at_watch = %s",
                    $tier,
                    $rarity
                ));

                if ($result === false) {
                    $hadFailure = true;
                    \BCC\Core\Log\Logger::warning(
                        '[bcc-trust] watch tier-vocabulary backfill: UPDATE failed',
                        ['table' => $table, 'from' => $rarity, 'to' => $tier]
                    );
                    continue;
                }
                $rewritten += (int) $result;
            }
        }

        if ($hadFailure) {
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        \BCC\Core\Log\Logger::info(
            '[bcc-trust] watch tier-vocabulary backfill complete',
            ['rows_rewritten' => $rewritten]
        );

        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
