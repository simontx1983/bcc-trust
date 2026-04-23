<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Idempotency-claim row storage for dispute adjudication.
 *
 * Owns the wp_options-backed claim marker that prevents double-adjudication
 * when the reconciliation cron retries a dispute whose resolve transaction
 * is still in flight.
 *
 * Design invariant: {@see claim()} MUST be called from inside the same
 * TransactionManager::run() closure that performs the actual adjudication
 * mutation. Because InnoDB row-locks the wp_options UNIQUE key, two
 * concurrent callers serialise: the second's INSERT IGNORE blocks until
 * the first commits (then sees the row and returns 0) or rolls back (then
 * its own claim row is rolled back with the mutation). A committed claim
 * therefore means "mutation also committed" — not "mutation is in flight".
 *
 * Extracting this out of the DisputeAdjudicator is purely an architecture
 * fix: raw $wpdb lives in Repositories/, never in the Application/ layer.
 * The SQL and its semantics are unchanged.
 */
final class AdjudicationClaimRepository
{
    private const OPTION_PREFIX = '_bcc_adjudicated_dispute_';

    /**
     * Attempt to claim exclusive adjudication rights for a dispute.
     *
     * Atomic via the wp_options.option_name UNIQUE key: INSERT IGNORE
     * returns 1 on first-writer win, 0 on already-claimed.
     *
     * MUST be called inside the same transaction as the adjudication
     * mutation it gates. See class-level design invariant.
     *
     * @return bool True if this caller won the claim (first writer),
     *              false if another caller already holds it.
     */
    public function claim(int $disputeId, string $outcome): bool
    {
        global $wpdb;

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, 'no')",
            self::OPTION_PREFIX . $disputeId,
            $outcome . ':' . time()
        ));

        return $inserted === 1;
    }

    /**
     * Release the idempotency marker so a future retry can reprocess.
     *
     * Used only for post-commit edge cases where the adjudication
     * transaction committed successfully but no real mutation was applied
     * (e.g. locked-vote rows were already gone — the dispute row was vaped
     * between status change and adjudication). In-transaction failures
     * do NOT need this: the ROLLBACK unwinds the claim along with the
     * mutation.
     */
    public function release(int $disputeId): void
    {
        global $wpdb;

        $wpdb->delete(
            $wpdb->options,
            ['option_name' => self::OPTION_PREFIX . $disputeId],
            ['%s']
        );
    }
}
