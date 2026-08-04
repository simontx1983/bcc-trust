<?php
/**
 * Poll Repository — owns `wp_bcc_trust_polls`, the generic
 * meaningful-voting poll rows (Rank redesign Phase 6, Wave 2).
 *
 * Pure persistence: window math, quorum/majority evaluation, and every
 * eligibility decision live in PollService (and Wave 3's dispute
 * layer). The DB-level one-OPEN-poll-per-subject constraint
 * (uniq_open_subject over the STORED generated `open_lock` column —
 * see schema-polls.php) makes create() the concurrency arbiter: a
 * losing racer's INSERT fails and returns 0.
 *
 * close() is row-guarded (WHERE status='open') so concurrent sweep
 * ticks cannot double-close — exactly one caller observes true.
 *
 * Pattern: RankStateRepository — explicit COLUMNS, bounded queries, §5
 * generation-counter cache invalidation keyed per poll.
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
 * @phpstan-type PollRow object{
 *     id: numeric-string,
 *     poll_type: string,
 *     subject_type: string,
 *     subject_id: numeric-string,
 *     status: string,
 *     outcome: string|null,
 *     opened_at: string,
 *     binding_earliest_at: string,
 *     expires_at: string,
 *     closed_at: string|null
 * }
 */
class PollRepository
{
    /** Cache group for per-poll generation counters (§5). */
    private const CACHE_GROUP = 'bcc_rank_polls';

    /** Cached getById rows live this long within one generation. */
    private const CACHE_TTL = 300;

    private const COLUMNS = 'id, poll_type, subject_type, subject_id, status, outcome, opened_at, binding_earliest_at, expires_at, closed_at';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::polls();
    }

    /**
     * Open a poll row. The uniq_open_subject constraint rejects a
     * second OPEN poll for the same (poll_type, subject_type,
     * subject_id) — the losing INSERT returns 0 and the caller surfaces
     * the domain error.
     *
     * @param string $openedAt          UTC 'Y-m-d H:i:s' (caller-computed — never NOW()).
     * @param string $bindingEarliestAt UTC 'Y-m-d H:i:s' (day-7 window).
     * @param string $expiresAt         UTC 'Y-m-d H:i:s' (day-90 window).
     * @return int New poll id, or 0 on failure (including duplicate open).
     */
    public function create(
        string $pollType,
        string $subjectType,
        int $subjectId,
        string $openedAt,
        string $bindingEarliestAt,
        string $expiresAt
    ): int {
        global $wpdb;

        // The duplicate-open INSERT failure is EXPECTED under race —
        // suppress the error-log noise; the caller turns 0 into a
        // domain exception.
        $suppressed = $wpdb->suppress_errors(true);
        $result     = $wpdb->insert(
            $this->table,
            [
                'poll_type'           => $pollType,
                'subject_type'        => $subjectType,
                'subject_id'          => $subjectId,
                'status'              => 'open',
                'opened_at'           => $openedAt,
                'binding_earliest_at' => $bindingEarliestAt,
                'expires_at'          => $expiresAt,
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );
        $wpdb->suppress_errors($suppressed);

        if ($result === false) {
            return 0;
        }

        $id = (int) $wpdb->insert_id;
        if ($id > 0) {
            $this->invalidateCache($id);
        }
        return $id;
    }

    /**
     * Generation-keyed read (§5): the cached row is valid only for the
     * poll's current generation; every write path bumps it.
     *
     * @phpstan-return PollRow|null
     */
    public function getById(int $pollId): ?object
    {
        if ($pollId <= 0) {
            return null;
        }

        $cacheKey = "poll:{$pollId}:g" . $this->generation($pollId);
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if (is_object($cached)) {
            /** @var PollRow $cached */
            return $cached;
        }
        if ($cached === 'none') {
            return null;
        }

        global $wpdb;

        /** @var PollRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE id = %d",
            $pollId
        ));

        wp_cache_set($cacheKey, $row ?? 'none', self::CACHE_GROUP, self::CACHE_TTL);

        return $row;
    }

    /**
     * The single OPEN poll for a subject, if any (uniq_open_subject
     * guarantees at most one).
     *
     * @phpstan-return PollRow|null
     */
    public function getOpenBySubject(string $pollType, string $subjectType, int $subjectId): ?object
    {
        if ($subjectId <= 0) {
            return null;
        }

        global $wpdb;

        /** @var PollRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE poll_type = %s AND subject_type = %s AND subject_id = %d AND status = 'open'
              LIMIT 1",
            $pollType,
            $subjectType,
            $subjectId
        ));

        return $row;
    }

    /**
     * The most recent poll for a subject regardless of status —
     * Wave 3's dispute layer resolves the closed poll behind a
     * still-reviewing dispute (backstop reconcile) and the viewer
     * state for closed votes through this. Uncached: callers are
     * per-entity reads or the daily backstop sweep.
     *
     * @phpstan-return PollRow|null
     */
    public function getLatestBySubject(string $pollType, string $subjectType, int $subjectId): ?object
    {
        if ($subjectId <= 0) {
            return null;
        }

        global $wpdb;

        /** @var PollRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE poll_type = %s AND subject_type = %s AND subject_id = %d
              ORDER BY id DESC
              LIMIT 1",
            $pollType,
            $subjectType,
            $subjectId
        ));

        return $row;
    }

    /**
     * Open polls whose binding window (day-7) has started — the close
     * sweep's evaluation batch. Deliberately uncached (sweep-only).
     *
     * @param string $nowSql UTC 'Y-m-d H:i:s'.
     * @return list<object>
     * @phpstan-return list<PollRow>
     */
    public function listOpenPastBinding(string $nowSql, int $limit): array
    {
        global $wpdb;

        /** @var list<PollRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE status = 'open' AND binding_earliest_at <= %s
              ORDER BY id ASC
              LIMIT %d",
            $nowSql,
            max(1, $limit)
        ));

        return $rows ?: [];
    }

    /**
     * Open polls past their day-90 expiry — the inconclusive-close
     * batch. Deliberately uncached (sweep-only).
     *
     * @param string $nowSql UTC 'Y-m-d H:i:s'.
     * @return list<object>
     * @phpstan-return list<PollRow>
     */
    public function listOpenPastExpiry(string $nowSql, int $limit): array
    {
        global $wpdb;

        /** @var list<PollRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE status = 'open' AND expires_at <= %s
              ORDER BY id ASC
              LIMIT %d",
            $nowSql,
            max(1, $limit)
        ));

        return $rows ?: [];
    }

    /**
     * Close a poll. Row-guarded on status='open' so exactly one of N
     * concurrent sweep ticks wins — the losers observe false and skip
     * their audit writes/actions.
     *
     * @param string $outcome  'passed' | 'failed' | 'inconclusive'.
     * @param string $closedAt UTC 'Y-m-d H:i:s'.
     * @return bool True when THIS call transitioned the row.
     */
    public function close(int $pollId, string $outcome, string $closedAt): bool
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
                SET status = 'closed', outcome = %s, closed_at = %s
              WHERE id = %d AND status = 'open'",
            $outcome,
            $closedAt,
            $pollId
        ));

        $closed = $result !== false && (int) $result > 0;
        if ($closed) {
            $this->invalidateCache($pollId);
        }
        return $closed;
    }

    /**
     * Current generation for a poll's read caches. Tolerant numeric
     * read (NOT is_int) — persistent backends return integers as
     * numeric strings cross-process (HiddenActivityRepository::getGeneration
     * precedent).
     */
    private function generation(int $pollId): int
    {
        $genKey = "poll_gen:{$pollId}";
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
        $genKey = "poll_gen:{$pollId}";
        $result = wp_cache_incr($genKey, 1, self::CACHE_GROUP);
        if ($result === false) {
            wp_cache_set($genKey, 2, self::CACHE_GROUP, 0);
        }
    }
}
