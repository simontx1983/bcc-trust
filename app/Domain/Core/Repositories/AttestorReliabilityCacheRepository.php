<?php
/**
 * Attestor Reliability Cache Repository — §J.3.2 self-mirror memoization
 * (Slice 3).
 *
 * DB access layer for bcc_attestor_reliability_cache. Per §1 this is the
 * ONLY file that touches the table via $wpdb. The daily
 * `bcc_attestor_reliability_sweep` cron owns all writes (upsert); the
 * read paths (/me/reliability, ReliabilityStandingComputer) call
 * getByUserId() read-only and fall back to a live compute on cache miss.
 *
 * §5 cache invalidation: getByUserId is generation-keyed (the generation
 * counter is bumped atomically on every upsert), mirroring
 * ScoreRepository — old entries are orphaned rather than deleted, so
 * there is no stale-read window and no cache stampede.
 *
 * @package BCC\Trust\Core\Repositories
 * @since V2 Trust Attestation Layer (Slice 3, 2026-06-28)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type ReliabilityCacheRow object{
 *   user_id: int|numeric-string,
 *   operator_reliability: float|numeric-string,
 *   reliability_standing: string,
 *   consensus_reliability: float|numeric-string,
 *   early_read_accuracy: float|numeric-string,
 *   attestation_count: int|numeric-string,
 *   trend: string,
 *   reliability_baseline: float|numeric-string,
 *   baseline_at: string|null,
 *   targets_disputed_and_upheld: int|numeric-string,
 *   targets_disputed_and_dismissed: int|numeric-string,
 *   targets_received_further_attestations: int|numeric-string,
 *   targets_clean_and_active: int|numeric-string,
 *   computed_at: string
 * }
 */
final class AttestorReliabilityCacheRepository
{
    /** Explicit column list for bcc_attestor_reliability_cache (no SELECT *). */
    private const COLUMNS = 'user_id, operator_reliability, reliability_standing, consensus_reliability, early_read_accuracy, attestation_count, trend, reliability_baseline, baseline_at, targets_disputed_and_upheld, targets_disputed_and_dismissed, targets_received_further_attestations, targets_clean_and_active, computed_at';

    /** Cache group for reliability-cache reads. */
    private const CACHE_GROUP = 'bcc_trust_reliability_cache';

    /** Cache TTL in seconds. */
    private const CACHE_TTL = 300;

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::attestorReliabilityCache();
    }

    /** @var array<int, int> Request-scoped generation memo to avoid repeated DB hits. */
    private array $generationCache = [];

    /**
     * Read the cached reliability row for an attestor. Generation-keyed
     * (§5): the cron's upsert bumps the generation, so a read after a
     * write always lands on a fresh cache key. Returns null when no row
     * exists yet (operator not yet swept) — the caller falls back to a
     * live compute.
     *
     * @return object|null
     */
    public function getByUserId(int $userId): ?object
    {
        if ($userId <= 0) {
            return null;
        }

        global $wpdb;

        $gen      = $this->getCacheGeneration($userId);
        $cacheKey = 'reliability_' . $userId . '_g' . $gen;
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if ($cached !== false) {
            return $cached === 'null' ? null : $cached;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table} WHERE user_id = %d LIMIT 1",
                $userId
            )
        );

        if (!is_object($row)) {
            wp_cache_set($cacheKey, 'null', self::CACHE_GROUP, self::CACHE_TTL);
            return null;
        }

        wp_cache_set($cacheKey, $row, self::CACHE_GROUP, self::CACHE_TTL);
        return $row;
    }

    /**
     * Upsert one attestor's recomputed reliability snapshot. Called by
     * the daily sweep only. INSERT … ON DUPLICATE KEY UPDATE on the
     * user_id PRIMARY KEY. consensus_reliability / early_read_accuracy
     * stay at their column defaults (Slice 4 populates them).
     *
     * The four `$outcomes` counts are the classifier's
     * track_record['outcomes'] breakdown — persisted so a cache HIT on
     * the read path serves the same track_record as a cache MISS.
     *
     * Bumps the cache generation after the write so the next getByUserId
     * for this user re-reads from the DB.
     *
     * @param array{
     *   targets_disputed_and_upheld: int,
     *   targets_disputed_and_dismissed: int,
     *   targets_received_further_attestations: int,
     *   targets_clean_and_active: int
     * } $outcomes
     */
    public function upsert(
        int $userId,
        float $reliability,
        string $standing,
        int $attestationCount,
        string $trend,
        float $baseline,
        ?string $baselineAt,
        array $outcomes,
        string $computedAtMysqlUtc
    ): void {
        if ($userId <= 0) {
            return;
        }

        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->table}
                    (user_id, operator_reliability, reliability_standing,
                     attestation_count, trend, reliability_baseline,
                     baseline_at,
                     targets_disputed_and_upheld, targets_disputed_and_dismissed,
                     targets_received_further_attestations, targets_clean_and_active,
                     computed_at)
                 VALUES (%d, %f, %s, %d, %s, %f, %s, %d, %d, %d, %d, %s)
                 ON DUPLICATE KEY UPDATE
                     operator_reliability = VALUES(operator_reliability),
                     reliability_standing = VALUES(reliability_standing),
                     attestation_count    = VALUES(attestation_count),
                     trend                = VALUES(trend),
                     reliability_baseline = VALUES(reliability_baseline),
                     baseline_at          = VALUES(baseline_at),
                     targets_disputed_and_upheld           = VALUES(targets_disputed_and_upheld),
                     targets_disputed_and_dismissed        = VALUES(targets_disputed_and_dismissed),
                     targets_received_further_attestations = VALUES(targets_received_further_attestations),
                     targets_clean_and_active              = VALUES(targets_clean_and_active),
                     computed_at          = VALUES(computed_at)",
                $userId,
                $reliability,
                $standing,
                $attestationCount,
                $trend,
                $baseline,
                $baselineAt,
                (int) ($outcomes['targets_disputed_and_upheld'] ?? 0),
                (int) ($outcomes['targets_disputed_and_dismissed'] ?? 0),
                (int) ($outcomes['targets_received_further_attestations'] ?? 0),
                (int) ($outcomes['targets_clean_and_active'] ?? 0),
                $computedAtMysqlUtc
            )
        );

        $this->bumpCacheGeneration($userId);
    }

    /**
     * List the stalest-by-computed_at user_ids already present in the
     * cache. Bounded (§4) by $limit. Stale-first ordering uses the
     * idx_computed key. Used to refresh existing rows oldest-first; the
     * cron's primary work-list is the DISTINCT attestor cursor in
     * AttestationRepository (which also discovers never-swept operators).
     *
     * @return list<int>
     */
    public function listStalestUserIds(int $limit): array
    {
        $limit = max(1, min(5000, $limit));

        global $wpdb;

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$this->table}
                 ORDER BY computed_at ASC
                 LIMIT %d",
                $limit
            )
        );

        $out = [];
        foreach ($rows as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $out[] = $intId;
            }
        }
        return $out;
    }

    // ── Generation-counter cache helpers (§5) ──────────────────────────

    /**
     * Current cache generation for an attestor. Persistent object cache
     * reads from wp_cache; otherwise from wp_options via the atomic
     * counter. Request-scoped memo avoids repeated lookups.
     */
    private function getCacheGeneration(int $userId): int
    {
        if (isset($this->generationCache[$userId])) {
            return $this->generationCache[$userId];
        }

        if (wp_using_ext_object_cache()) {
            $gen    = wp_cache_get('reliability_gen_' . $userId, self::CACHE_GROUP);
            $result = $gen !== false ? (int) $gen : 0;
            $this->generationCache[$userId] = $result;
            return $result;
        }

        global $wpdb;
        $optionName = '_bcc_reliability_gen_' . $userId;
        /** @var string|null $val */
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $optionName
        ));
        $result = $val !== null ? (int) $val : 0;
        $this->generationCache[$userId] = $result;
        return $result;
    }

    /**
     * Atomically bump the cache generation for an attestor. Persistent
     * object cache: wp_cache_incr. Otherwise: INSERT … ON DUPLICATE KEY
     * UPDATE in wp_options. Both atomic under concurrency.
     */
    private function bumpCacheGeneration(int $userId): void
    {
        unset($this->generationCache[$userId]);

        if (wp_using_ext_object_cache()) {
            $key    = 'reliability_gen_' . $userId;
            $result = wp_cache_incr($key, 1, self::CACHE_GROUP);
            if ($result === false) {
                wp_cache_set($key, 1, self::CACHE_GROUP, 3600);
            }
            return;
        }

        global $wpdb;
        $optionName = '_bcc_reliability_gen_' . $userId;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            $optionName
        ));
    }
}
