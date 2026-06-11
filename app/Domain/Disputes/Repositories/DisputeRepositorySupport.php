<?php

namespace BCC\Trust\Disputes\Repositories;

use BCC\Core\DTO\RowAssert;
use BCC\Trust\Disputes\Domain\DisputeStatus;
use BCC\Trust\Disputes\DTO\DisputeDetailDTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared internals for the dispute repository family
 * (DisputeRepository, DisputeAdminRepository, DisputePanelRepository,
 * UserReportRepository).
 *
 * Holds the §1.0 raw-transaction guards, the generation-counter cache
 * helpers, and the shared cache group / TTL constants so the sibling
 * repositories cannot drift apart on transaction or invalidation
 * semantics. Not a repository itself — it issues no queries against
 * domain tables.
 */
final class DisputeRepositorySupport
{
    /** Cache group for all dispute-related keys. */
    public const CACHE_GROUP = 'bcc_disputes';

    /** TTL for data that changes frequently (counts, active queues). */
    public const TTL_HOT  = 60;

    /** TTL for data that changes less often (individual dispute lookups). */
    public const TTL_WARM = 300;

    // ── Raw-transaction guards ──────────────────────────────────────────────
    //
    // Every raw `$wpdb->query('START TRANSACTION')` site MUST route through
    // these helpers.  `$wpdb->query()` returns false on driver error
    // (connection reset, managed-MySQL failover, implicit-commit at DDL),
    // and a silent false return leaves the session in autocommit — which
    // turns every FOR UPDATE into a plain read and every subsequent
    // ROLLBACK into a no-op.  The dispute-create path in particular relies
    // on gap locks for the per-page limit, per-reporter limit, and
    // duplicate-vote protection; losing the transaction silently bypasses
    // all three.
    //
    // These helpers are intentionally minimal — they log and surface
    // failure to the caller, leaving retry/return-shape decisions where
    // they belong (per-method).

    /**
     * Begin a transaction.  Returns false on driver error so the caller
     * can return a structured failure to its API layer instead of
     * proceeding under autocommit.
     */
    public static function beginTx(): bool
    {
        global $wpdb;
        return $wpdb->query('START TRANSACTION') !== false;
    }

    /**
     * Commit.  A false return means MySQL rolled the transaction back on
     * its own (commit-time deadlock, serialization failure, connection
     * drop).  Callers MUST NOT treat a failed COMMIT as a successful
     * write — the persisted row may have been auto-incremented and then
     * immediately rolled back, yielding a dead insert_id.
     */
    public static function commitTx(string $op): bool
    {
        global $wpdb;
        if ($wpdb->query('COMMIT') !== false) {
            return true;
        }
        \BCC\Core\Log\Logger::error('[bcc-disputes] COMMIT failed', [
            'op'       => $op,
            'db_error' => (string) $wpdb->last_error,
        ]);
        return false;
    }

    /**
     * Rollback.  Never throws — rollback always runs on the error path
     * and secondary failures there must not mask the primary cause.
     * Logs on driver error so the forensic trail survives.
     */
    public static function rollbackTx(string $op): void
    {
        global $wpdb;
        if ($wpdb->query('ROLLBACK') === false) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] ROLLBACK failed', [
                'op'       => $op,
                'db_error' => (string) $wpdb->last_error,
            ]);
        }
    }

    // ── Cache helpers ────────────────────────────────────────────────────────

    /**
     * Get the current generation counter for a cache namespace.
     *
     * Generation counters solve the wildcard-deletion problem: instead of
     * deleting panel_queue:{userId}:* (impossible with wp_cache), we
     * increment the generation and all old keys become unreachable,
     * expiring naturally via TTL.
     */
    public static function getGeneration(string $genKey): int
    {
        $gen = wp_cache_get($genKey, self::CACHE_GROUP);
        if ($gen === false) {
            $gen = 1;
            wp_cache_set($genKey, $gen, self::CACHE_GROUP, 0);
        }
        return (int) $gen;
    }

    /**
     * Atomically increment a generation counter, invalidating all keys that embed it.
     *
     * Uses wp_cache_incr() which maps to Redis INCR — a single atomic operation
     * when a persistent object cache is available. The fallback (no object cache)
     * uses set-if-missing which is not fully atomic but acceptable for cache
     * invalidation (worst case: one extra DB query).
     */
    public static function bumpGeneration(string $genKey): void
    {
        $result = wp_cache_incr($genKey, 1, self::CACHE_GROUP);
        if ($result === false) {
            // incr failed — key may not exist. Verify before overwriting.
            $current = wp_cache_get($genKey, self::CACHE_GROUP);
            if ($current === false) {
                // Key truly doesn't exist — set to 2 (first bump from implicit gen=1).
                wp_cache_set($genKey, 2, self::CACHE_GROUP, 0);
            } else {
                // Key exists but incr failed (transient backend issue) — force set incremented value.
                wp_cache_set($genKey, (int) $current + 1, self::CACHE_GROUP, 0);
            }
        }
    }

    /**
     * Validate a cached value as list<$dtoClass>. Returns the typed list when
     * valid (even when empty), null otherwise. For display-tier list caches
     * only — callers treat null as a miss and re-fetch. Trust-critical
     * single-entry caches (see getDisputeById) fail-fast on poisoning instead.
     *
     * @template T of object
     * @param mixed $cached
     * @param class-string<T> $dtoClass
     * @return list<T>|null
     */
    public static function validateCachedDtoList($cached, string $dtoClass): ?array
    {
        if (!is_array($cached) || !array_is_list($cached)) {
            return null;
        }
        foreach ($cached as $entry) {
            if (!$entry instanceof $dtoClass) {
                return null;
            }
        }
        return $cached;
    }

    /**
     * Strict hydration into DisputeDetailDTO. Shared by getByReporterPaginated
     * and getDisputeDetailForAdmin (both SELECT the same 16 columns).
     *
     * @param array<string, scalar|null> $row
     */
    public static function hydrateDisputeDetail(array $row): DisputeDetailDTO
    {
        return new DisputeDetailDTO(
            id:            RowAssert::requireDigitInt($row, 'id'),
            vote_id:       RowAssert::requireDigitInt($row, 'vote_id'),
            page_id:       RowAssert::requireDigitInt($row, 'page_id'),
            voter_id:      RowAssert::requireDigitInt($row, 'voter_id'),
            reporter_id:   RowAssert::requireDigitInt($row, 'reporter_id'),
            reason:        RowAssert::requireString($row, 'reason'),
            evidence_url:  RowAssert::optString($row, 'evidence_url'),
            status:        DisputeStatus::assert(RowAssert::requireString($row, 'status')),
            panel_accepts: RowAssert::requireDigitInt($row, 'panel_accepts'),
            panel_rejects: RowAssert::requireDigitInt($row, 'panel_rejects'),
            panel_size:    RowAssert::requireDigitInt($row, 'panel_size'),
            created_at:    RowAssert::requireString($row, 'created_at'),
            resolved_at:   RowAssert::optString($row, 'resolved_at'),
            page_title:    RowAssert::optString($row, 'page_title'),
            reporter_name: RowAssert::optString($row, 'reporter_name'),
            voter_name:    RowAssert::optString($row, 'voter_name'),
        );
    }
}
