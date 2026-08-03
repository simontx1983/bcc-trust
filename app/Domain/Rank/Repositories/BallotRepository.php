<?php
/**
 * Ballot Repository — owns `wp_bcc_trust_ballots`, the per-voter ballot
 * rows for the meaningful-voting poll engine (Rank Phase 6, Wave 2).
 *
 * Pure persistence: recast/withdraw rules, cooldowns, snapshot copying,
 * and close-time evaluation live in PollService. The DB-level
 * one-ACTIVE-ballot-per-voter constraint (uniq_active_ballot over the
 * STORED generated `active_lock` column — see schema-ballots.php) makes
 * insert() the concurrency arbiter: a losing racer's INSERT fails and
 * returns 0.
 *
 * The §16.6 snapshot columns are written once per row and never
 * updated; the only post-insert mutations are status transitions
 * (supersede/withdraw) and the close-time audit column write.
 *
 * Pattern: RankStateRepository — explicit COLUMNS, bounded queries, §5
 * generation-counter cache invalidation keyed per POLL (every write
 * path bumps the owning poll's generation; per-voter reads key off it).
 *
 * @package BCC\Trust\Rank\Repositories
 * @since Rank redesign Phase 6 (2026-08-03)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type BallotRow object{
 *     id: numeric-string,
 *     poll_id: numeric-string,
 *     voter_user_id: numeric-string,
 *     choice: string,
 *     status: string,
 *     recast_count: numeric-string,
 *     rank_slug: string|null,
 *     maturity: numeric-string,
 *     rank_multiplier: numeric-string,
 *     trust_multiplier: numeric-string,
 *     trust_score: numeric-string,
 *     fraud_discount: numeric-string,
 *     effective_weight: numeric-string,
 *     suspected_cluster_id: numeric-string|null,
 *     confirmed_cluster_id: numeric-string|null,
 *     counted_weight: numeric-string|null,
 *     counted: numeric-string|null,
 *     cast_at: string,
 *     last_action_at: string,
 *     withdrawn_at: string|null,
 *     superseded_at: string|null
 * }
 * @phpstan-type BallotSnapshot array{
 *     rank_slug: string|null,
 *     maturity: float,
 *     rank_multiplier: float,
 *     trust_multiplier: float,
 *     trust_score: float,
 *     fraud_discount: float,
 *     effective_weight: float
 * }
 */
class BallotRepository
{
    /** Cache group for per-poll ballot generation counters (§5). */
    private const CACHE_GROUP = 'bcc_rank_ballots';

    /** Cached getActiveForVoter rows live this long within one generation. */
    private const CACHE_TTL = 300;

    /** Hard ceiling for a single poll's active-ballot read. */
    public const MAX_BALLOTS = 5000;

    private const COLUMNS = 'id, poll_id, voter_user_id, choice, status, recast_count, rank_slug, maturity, rank_multiplier, trust_multiplier, trust_score, fraud_discount, effective_weight, suspected_cluster_id, confirmed_cluster_id, counted_weight, counted, cast_at, last_action_at, withdrawn_at, superseded_at';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::ballots();
    }

    /**
     * Insert a new ACTIVE ballot row. The uniq_active_ballot constraint
     * rejects a second active row per (poll, voter) — the losing INSERT
     * returns 0 and the caller surfaces the domain error.
     *
     * @phpstan-param BallotSnapshot $snapshot
     * @param string $castAt       UTC 'Y-m-d H:i:s' (caller-computed — never NOW()).
     * @param string $lastActionAt UTC 'Y-m-d H:i:s'.
     * @return int New ballot id, or 0 on failure (including active-ballot race).
     */
    public function insert(
        int $pollId,
        int $voterId,
        string $choice,
        array $snapshot,
        int $recastCount,
        string $castAt,
        string $lastActionAt
    ): int {
        if ($pollId <= 0 || $voterId <= 0) {
            return 0;
        }

        global $wpdb;

        // A duplicate-active INSERT failure is EXPECTED under race —
        // suppress the error-log noise; the caller turns 0 into a
        // domain exception.
        $suppressed = $wpdb->suppress_errors(true);
        $result     = $wpdb->insert(
            $this->table,
            [
                'poll_id'          => $pollId,
                'voter_user_id'    => $voterId,
                'choice'           => $choice,
                'status'           => 'active',
                'recast_count'     => $recastCount,
                'rank_slug'        => $snapshot['rank_slug'],
                'maturity'         => $snapshot['maturity'],
                'rank_multiplier'  => $snapshot['rank_multiplier'],
                'trust_multiplier' => $snapshot['trust_multiplier'],
                'trust_score'      => $snapshot['trust_score'],
                'fraud_discount'   => $snapshot['fraud_discount'],
                'effective_weight' => $snapshot['effective_weight'],
                'cast_at'          => $castAt,
                'last_action_at'   => $lastActionAt,
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s']
        );
        $wpdb->suppress_errors($suppressed);

        if ($result === false) {
            return 0;
        }

        $id = (int) $wpdb->insert_id;
        if ($id > 0) {
            $this->invalidateCache($pollId);
        }
        return $id;
    }

    /**
     * The voter's ACTIVE ballot on a poll, if any (uniq_active_ballot
     * guarantees at most one). Generation-keyed read (§5).
     *
     * @phpstan-return BallotRow|null
     */
    public function getActiveForVoter(int $pollId, int $voterId): ?object
    {
        if ($pollId <= 0 || $voterId <= 0) {
            return null;
        }

        $cacheKey = "active:{$pollId}:{$voterId}:g" . $this->generation($pollId);
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if (is_object($cached)) {
            /** @var BallotRow $cached */
            return $cached;
        }
        if ($cached === 'none') {
            return null;
        }

        global $wpdb;

        /** @var BallotRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE poll_id = %d AND voter_user_id = %d AND status = 'active'
              LIMIT 1",
            $pollId,
            $voterId
        ));

        wp_cache_set($cacheKey, $row ?? 'none', self::CACHE_GROUP, self::CACHE_TTL);

        return $row;
    }

    /**
     * The voter's most recent ballot on a poll REGARDLESS of status —
     * the re-entry path after a withdrawal reads the recast budget and
     * the original §16.6 snapshot from it.
     *
     * @phpstan-return BallotRow|null
     */
    public function getLatestForVoter(int $pollId, int $voterId): ?object
    {
        if ($pollId <= 0 || $voterId <= 0) {
            return null;
        }

        global $wpdb;

        /** @var BallotRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE poll_id = %d AND voter_user_id = %d
              ORDER BY id DESC
              LIMIT 1",
            $pollId,
            $voterId
        ));

        return $row;
    }

    /**
     * Mark a ballot superseded (recast path). Row-guarded on
     * status='active' — a concurrent transition loses and observes false.
     *
     * @param string $supersededAt UTC 'Y-m-d H:i:s'.
     */
    public function supersede(int $pollId, int $ballotId, string $supersededAt): bool
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
                SET status = 'superseded', superseded_at = %s, last_action_at = %s
              WHERE id = %d AND status = 'active'",
            $supersededAt,
            $supersededAt,
            $ballotId
        ));

        $done = $result !== false && (int) $result > 0;
        if ($done) {
            $this->invalidateCache($pollId);
        }
        return $done;
    }

    /**
     * Mark a ballot withdrawn. Row-guarded on status='active'.
     *
     * @param string $withdrawnAt UTC 'Y-m-d H:i:s'.
     */
    public function withdraw(int $pollId, int $ballotId, string $withdrawnAt): bool
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
                SET status = 'withdrawn', withdrawn_at = %s, last_action_at = %s
              WHERE id = %d AND status = 'active'",
            $withdrawnAt,
            $withdrawnAt,
            $ballotId
        ));

        $done = $result !== false && (int) $result > 0;
        if ($done) {
            $this->invalidateCache($pollId);
        }
        return $done;
    }

    /**
     * All ACTIVE ballots on a poll — the close-time evaluation input
     * and the closed-poll tally source. Bounded; deliberately uncached
     * (close sweep + closed-poll views only).
     *
     * @return list<object>
     * @phpstan-return list<BallotRow>
     */
    public function listActiveForPoll(int $pollId, int $limit = self::MAX_BALLOTS): array
    {
        if ($pollId <= 0) {
            return [];
        }

        global $wpdb;

        /** @var list<BallotRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE poll_id = %d AND status = 'active'
              ORDER BY id ASC
              LIMIT %d",
            $pollId,
            max(1, min(self::MAX_BALLOTS, $limit))
        ));

        return $rows ?: [];
    }

    /**
     * Persist the close-time evaluation audit for one ballot: which
     * cluster findings applied, the post-cap weight actually counted,
     * and whether the ballot counted toward the quorum headcount.
     * Audit-only — snapshot columns are never touched.
     */
    public function recordClosureAudit(
        int $pollId,
        int $ballotId,
        ?int $suspectedClusterId,
        ?int $confirmedClusterId,
        float $countedWeight,
        bool $counted
    ): bool {
        global $wpdb;

        // $wpdb->update() emits `col = NULL` for null values (format
        // entries are ignored for them) — no dynamic SQL needed.
        $result = $wpdb->update(
            $this->table,
            [
                'suspected_cluster_id' => $suspectedClusterId,
                'confirmed_cluster_id' => $confirmedClusterId,
                'counted_weight'       => $countedWeight,
                'counted'              => $counted ? 1 : 0,
            ],
            ['id' => $ballotId],
            ['%d', '%d', '%f', '%d'],
            ['%d']
        );

        $done = $result !== false;
        if ($done) {
            $this->invalidateCache($pollId);
        }
        return $done;
    }

    /** Active-ballot headcount for a poll (raw — pre cluster rules). */
    public function countActiveForPoll(int $pollId): int
    {
        if ($pollId <= 0) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE poll_id = %d AND status = 'active'",
            $pollId
        ));
    }

    /**
     * Current generation for a poll's ballot read caches. Tolerant
     * numeric read (NOT is_int) — persistent backends return integers
     * as numeric strings cross-process
     * (HiddenActivityRepository::getGeneration precedent).
     */
    private function generation(int $pollId): int
    {
        $genKey = "ballots_gen:{$pollId}";
        $gen    = wp_cache_get($genKey, self::CACHE_GROUP);
        if (is_numeric($gen)) {
            return (int) $gen;
        }
        wp_cache_set($genKey, 1, self::CACHE_GROUP, 0);
        return 1;
    }

    /** §5 generation-counter bump — every write path calls this. */
    private function invalidateCache(int $pollId): void
    {
        $genKey = "ballots_gen:{$pollId}";
        $result = wp_cache_incr($genKey, 1, self::CACHE_GROUP);
        if ($result === false) {
            wp_cache_set($genKey, 2, self::CACHE_GROUP, 0);
        }
    }
}
