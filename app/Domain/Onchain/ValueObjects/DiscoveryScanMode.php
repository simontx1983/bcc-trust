<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Historical walk, or incremental pass. THE SERVER CHOOSES.
 *
 * ── WHY THE ADMINISTRATOR NEVER PICKS ───────────────────────────────────
 * "Historical" and "incremental" are implementation words. An operator
 * asked to choose between them is being asked to know whether a chain's
 * code-family walk has drained — which is a fact the checkpoint already
 * holds. They see one Scan button; the mode is resolved from
 * `cw_backfill_completed_at` at request time.
 *
 * ── WHY IT IS STORED ON THE RUN ─────────────────────────────────────────
 * Resolved once, at request time, and frozen onto the row. If the
 * checkpoint later completes, a historical run that is still in flight
 * stays labelled `historical` — because that is what it did. Deriving the
 * mode at read time instead would silently rewrite history.
 *
 * ── WHY IT IS NOT PART OF ACTIVE-RUN UNIQUENESS ─────────────────────────
 * Deliberately absent from `uq_active (job_kind, chain_id, active_marker)`.
 * If the mode were part of that key, a historical run and an incremental
 * run could be active on one chain simultaneously — and they write the
 * same `cw_*` checkpoint columns. One scan per chain per job kind, whatever
 * the mode.
 */
final class DiscoveryScanMode
{
    /** The resumable walk of `/cosmwasm/wasm/v1/code` has not drained. */
    public const HISTORICAL = 'historical';

    /** The walk has drained; this is the routine forward pass. */
    public const INCREMENTAL = 'incremental';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::HISTORICAL, self::INCREMENTAL];
    }

    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::all(), true);
    }

    /**
     * Resolve the mode from a checkpoint row.
     *
     * PURE. Performs no I/O and never writes — the caller supplies the row
     * it already read, so the decision cannot itself become a query.
     *
     * A NULL or absent `cw_backfill_completed_at` means the historical walk
     * has not finished. A MISSING checkpoint row means the chain has never
     * been walked at all, which is also historical: treating an absent row
     * as "completed" would skip the entire backfill for every new chain.
     *
     * @param object|null $checkpoint row from ChainCheckpointRepository::get()
     */
    /**
     * PURE. The same rule as {@see forCheckpoint()}, over the completion
     * timestamp alone.
     *
     * ── WHY THE RULE IS FACTORED OUT ────────────────────────────────────
     * PR 7.1 needs this decision in a caller that has NO checkpoint object:
     * the admin panel already derives `backfill_completed_at` for every
     * chain in ONE bounded read, and re-reading each chain's checkpoint to
     * get an object back would undo that. Re-deriving "completed means
     * incremental" at the call site would be a second copy of the one rule
     * this class exists to own — including the zero-date case below, which
     * is exactly the sort of detail a copy forgets.
     *
     * @param string|null $completedAt `cw_backfill_completed_at`, or null
     */
    public static function forCompletedAt(?string $completedAt): string
    {
        if (!is_string($completedAt) || trim($completedAt) === '') {
            return self::HISTORICAL;
        }

        // MySQL hands back the zero date as a string on some configurations;
        // it means "never", not "completed at year zero".
        if (str_starts_with($completedAt, '0000-00-00')) {
            return self::HISTORICAL;
        }

        return self::INCREMENTAL;
    }

    public static function forCheckpoint(?object $checkpoint): string
    {
        if ($checkpoint === null) {
            return self::HISTORICAL;
        }

        $completedAt = $checkpoint->cw_backfill_completed_at ?? null;

        return self::forCompletedAt(is_string($completedAt) ? $completedAt : null);
    }
}
