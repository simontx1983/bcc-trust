<?php
/**
 * Score Repository
 * 
 * Handles database operations for page trust scores using PageScore value objects
 * Uses config constants for thresholds and limits
 * 
 * @package BCC\Trust\Core\Repositories
 * @version 2.1.0
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Core\ValueObjects\PageScore;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Exceptions\RepositoryException;
use DateTimeImmutable;
use Exception;
use stdClass;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Raw row shape returned by bcc_trust_page_scores reads. Values come back
 * from $wpdb->get_row() as string|null for scalar columns — the numeric
 * union with the native type lets existing consumers cast or compare.
 *
 * @phpstan-type ScoreRow object{
 *   page_id: int|numeric-string,
 *   category_id: int|numeric-string,
 *   page_owner_id: int|numeric-string,
 *   total_score: float|numeric-string,
 *   onchain_bonus: float|numeric-string,
 *   endorsement_bonus: float|numeric-string,
 *   positive_score: float|numeric-string,
 *   negative_score: float|numeric-string,
 *   vote_count: int|numeric-string,
 *   unique_voters: int|numeric-string,
 *   confidence_score: float|numeric-string,
 *   reputation_tier: string,
 *   endorsement_count: int|numeric-string,
 *   last_vote_at: string|null,
 *   last_calculated_at: string,
 *   fraud_metadata: string|null,
 *   recalculate_required: int|numeric-string,
 *   recalc_failures: int|numeric-string
 * }
 */
class ScoreRepository {

    /** Explicit column list for bcc_trust_scores table. */
    private const COLUMNS = 'page_id, category_id, page_owner_id, total_score, onchain_bonus, endorsement_bonus, positive_score, negative_score, vote_count, unique_voters, confidence_score, reputation_tier, endorsement_count, last_vote_at, last_calculated_at, fraud_metadata, recalculate_required, recalc_failures';

    private string $table;

    private int $minVotesReliable;

    public function __construct() {
        $this->table            = \BCC\Trust\Core\Database\TableRegistry::scores();
        $this->minVotesReliable = BCC_TRUST_MIN_VOTES_RELIABLE;
    }

    /** Cache group for page scores. */
    private const CACHE_GROUP = 'bcc_trust_scores';

    /** Cache TTL in seconds. */
    private const CACHE_TTL = 300;

    /**
     * The canonical SQL expression for computing total_score.
     *
     * ALL writes to total_score MUST use the canonical formula from
     * \BCC\Trust\Core\Services\TrustScoreService::formulaSql(). It includes:
     *   - Vote-based score: BCC_TRUST_NEUTRAL_SCORE + (positive_score - negative_score) * 2
     *   - Endorsement bonus: stored in dedicated column, survives recalculation
     *   - On-chain bonus: stored in dedicated column, survives recalculation
     *
     * Clamped to [0, 100]. The prior `private const TOTAL_SCORE_SQL` was
     * removed as part of the §A4 single-source consolidation —
     * TrustScoreService is now the only place the formula's PHP and SQL
     * representations live.
     */

    /**
     * Get score for a page+category pair as a PageScore value object.
     * Pass $categoryId = null to fetch the first row for the page (legacy callers).
     *
     * Results are cached in the WordPress object cache for 300 seconds.
     * Invalidated by invalidateCache() on vote/endorsement changes.
     */
    public function getByPageId(int $pageId, ?int $categoryId = null): ?PageScore {
        global $wpdb;

        // Version-stamped cache key: generation counter is bumped atomically
        // in invalidateCache(). Old entries are orphaned (never read again)
        // rather than deleted, eliminating cache stampedes and the stale-read
        // window between DB commit and cache invalidation.
        $gen      = $this->getCacheGeneration($pageId);
        $cacheKey = 'page_score_' . $pageId . '_' . ($categoryId ?? 'any') . '_g' . $gen;
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if ($cached !== false) {
            return $cached === 'null' ? null : $cached;
        }

        if ($categoryId !== null) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT " . self::COLUMNS . " FROM {$this->table} WHERE page_id = %d AND category_id = %d",
                    $pageId,
                    $categoryId
                )
            );
        } else {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT " . self::COLUMNS . " FROM {$this->table} WHERE page_id = %d LIMIT 1",
                    $pageId
                )
            );
        }

        if (!$row) {
            wp_cache_set($cacheKey, 'null', self::CACHE_GROUP, self::CACHE_TTL);
            return null;
        }

        $score = PageScore::fromDatabaseRow($row);
        wp_cache_set($cacheKey, $score, self::CACHE_GROUP, self::CACHE_TTL);
        return $score;
    }

    /**
     * Invalidate cached page score for a given page.
     *
     * Called by hooks after vote/endorsement changes to ensure
     * subsequent reads see fresh data.
     *
     * When $categoryId is null, ALL cached score keys for the page are
     * cleared — the 'any' key, the uncategorized sentinel (0), and every
     * category_id that exists in the scores table.  This prevents stale
     * reads after endorsement mutations (which don't know the category).
     */
    public function invalidateCache(int $pageId, ?int $categoryId = null): void {
        // Bump the generation counter. All subsequent reads will use a new
        // cache key (page_score_{id}_{cat}_g{N+1}), so stale entries under
        // the old generation are never served — they just expire naturally.
        //
        // This approach:
        // - Eliminates the stale-read window between DB commit and cache clear
        // - Prevents cache stampedes (no mass deletion, old entries still exist)
        // - Works for all category variants at once (single generation per page)
        $this->bumpCacheGeneration($pageId);
    }

    /** @var array<int, int> Request-scoped generation cache to avoid repeated DB hits. */
    private array $generationCache = [];

    /**
     * Get the current cache generation for a page score.
     *
     * With persistent object cache (Redis/Memcached): reads from wp_cache.
     * Without: reads from wp_options via atomic DB counter.
     * This ensures generation-based invalidation works in all environments.
     */
    private function getCacheGeneration(int $pageId): int
    {
        // Request-scoped memoization: avoids repeated DB/cache hits for the
        // same page within a single PHP request (e.g. category loops).
        if (isset($this->generationCache[$pageId])) {
            return $this->generationCache[$pageId];
        }

        if (wp_using_ext_object_cache()) {
            $gen = wp_cache_get('score_gen_' . $pageId, self::CACHE_GROUP);
            $result = $gen !== false ? (int) $gen : 0;
            $this->generationCache[$pageId] = $result;
            return $result;
        }

        // DB fallback: read from wp_options.
        global $wpdb;
        $optionName = '_bcc_score_gen_' . $pageId;
        /** @var string|null $val */
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $optionName
        ));
        $result = $val !== null ? (int) $val : 0;
        $this->generationCache[$pageId] = $result;
        return $result;
    }

    /**
     * Atomically bump the cache generation for a page score.
     *
     * With persistent object cache: wp_cache_incr (Redis INCR / Memcached increment).
     * Without: INSERT ... ON DUPLICATE KEY UPDATE gen = gen + 1 in wp_options.
     * Both paths are atomic under concurrency.
     */
    private function bumpCacheGeneration(int $pageId): void
    {
        // Reset request-scoped cache so subsequent reads see the new generation
        unset($this->generationCache[$pageId]);

        if (wp_using_ext_object_cache()) {
            $key = 'score_gen_' . $pageId;
            $result = wp_cache_incr($key, 1, self::CACHE_GROUP);
            if ($result === false) {
                wp_cache_set($key, 1, self::CACHE_GROUP, 3600);
            }
            return;
        }

        // DB fallback: atomic increment via INSERT ON DUPLICATE KEY UPDATE.
        global $wpdb;
        $optionName = '_bcc_score_gen_' . $pageId;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            $optionName
        ));
    }

    /**
     * @deprecated Use saveRecalculated() inside a transaction with lockForUpdate().
     *             This method uses REPLACE INTO (DELETE+INSERT) which races with
     *             concurrent applyVoteDelta() increments. Only safe when the
     *             caller holds a FOR UPDATE lock (as bulkUpdate() does).
     */
    public function save(PageScore $score): void {
        global $wpdb;

        // Guard: warn if called outside a transaction (no row lock held).
        if (!TransactionManager::isInRunTransaction()) {
            \BCC\Core\Log\Logger::error(
                '[bcc-trust] ScoreRepository::save() called outside transaction — '
                . 'INSERT ON DUPLICATE KEY UPDATE may race with concurrent vote deltas. Use saveRecalculated() instead.',
                ['page_id' => $score->getPageId()]
            );
        }

        $data    = $score->toDatabaseArray();
        $formats = $this->getFormatSpecifiers($data);

        // Build INSERT ... ON DUPLICATE KEY UPDATE instead of REPLACE INTO.
        // REPLACE INTO performs DELETE + INSERT which races with concurrent
        // applyVoteDelta() increments that land between the two operations.
        $columns      = implode(', ', array_keys($data));
        $placeholders = implode(', ', $formats);
        $updateParts  = [];
        foreach (array_keys($data) as $col) {
            if ($col === 'page_id') {
                continue; // Primary key — not in SET clause
            }
            $updateParts[] = "{$col} = VALUES({$col})";
        }
        $updateClause = implode(', ', $updateParts);

        $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})
                ON DUPLICATE KEY UPDATE {$updateClause}";

        $result = $wpdb->query($wpdb->prepare($sql, array_values($data)));

        if ($result === false) {
            throw new Exception('Failed to save page score to database');
        }
    }

    /**
     * Overwrite a recalculated score row using UPDATE (not REPLACE INTO).
     *
     * Must only be called from inside a TransactionManager::run() closure where
     * the row has already been locked with SELECT … FOR UPDATE.  Using UPDATE
     * instead of REPLACE INTO avoids the implicit DELETE + INSERT that REPLACE
     * performs, which would otherwise race with concurrent applyVoteDelta()
     * increments that run between the DELETE and INSERT.
     *
     * @param PageScore $score          The newly recalculated score object.
     * @param int       $categoryId     The category_id of the locked row.  Must
     *                                  be passed explicitly because toDatabaseArray()
     *                                  does not include category_id.
     * @throws Exception
     */
    /**
     * Find the first page_id owned by a user in the scores table.
     */
    public function getPageIdForOwner(int $userId): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT page_id FROM {$this->table} WHERE page_owner_id = %d LIMIT 1",
            $userId
        ));
    }

    public function saveRecalculated(PageScore $score, int $categoryId): void {
        global $wpdb;

        $data = $score->toDatabaseArray();

        // page_id is part of the WHERE clause, not the SET clause.
        $pageId = $data['page_id'];
        unset($data['page_id']);

        $result = $wpdb->update(
            $this->table,
            $data,
            ['page_id' => $pageId, 'category_id' => $categoryId],
            $this->getFormatSpecifiers($data),
            ['%d', '%d']
        );

        if ($result === false) {
            throw new Exception(
                'Failed to update recalculated score for page ' . $pageId
            );
        }
    }

    /**
     * Get format specifiers for database operations
     *
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function getFormatSpecifiers(array $data): array {
        $formats = [];
        
        foreach (array_keys($data) as $field) {
            switch ($field) {
                case 'page_id':
                case 'page_owner_id':
                case 'vote_count':
                case 'unique_voters':
                case 'endorsement_count':
                    $formats[] = '%d';
                    break;
                case 'total_score':
                case 'positive_score':
                case 'negative_score':
                case 'confidence_score':
                case 'endorsement_bonus':
                case 'onchain_bonus':
                    $formats[] = '%f';
                    break;
                case 'reputation_tier':
                case 'last_vote_at':
                case 'last_calculated_at':
                case 'fraud_metadata':
                    $formats[] = '%s';
                    break;
                default:
                    $formats[] = '%s';
            }
        }
        
        return $formats;
    }

    /**
     * Acquire a row-level exclusive lock on a score row.
     *
     * MUST be called inside a TransactionManager::run() closure.
     * Returns the locked row or null if the row does not exist.
     *
     * @param int $pageId
     * @param int $categoryId
     * @return object|null  The locked row.
     */
    public function lockForUpdate( int $pageId, int $categoryId ): ?object {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table}
                 WHERE page_id     = %d
                   AND category_id = %d
                 FOR UPDATE",
                $pageId,
                $categoryId
            )
        );

        return $row ?: null;
    }

    // applyVoteDelta() REMOVED — replaced by applyVoteDeltaUpsert() which
    // uses INSERT … ON DUPLICATE KEY UPDATE for atomic create-or-update.
    // The old method required a pre-existing row and separate lockForUpdate().

    /**
     * Apply a vote delta, creating the score row if it does not yet exist.
     *
     * Uses INSERT … ON DUPLICATE KEY UPDATE so the entire operation is a single
     * atomic SQL statement. Safe to call concurrently for new pages without a
     * prior createIfNotExists() call.
     *
     * @param int   $pageId      Target page.
     * @param int   $ownerId     Page owner — only used when inserting a new row.
     * @param float $delta       Effective weight magnitude (always positive).
     * @param bool  $isUpvote    true = upvote (+), false = downvote (−).
     * @param bool  $isNewVoter  true if this is the voter's first vote on this page.
     * @throws Exception  On DB failure.
     */
    /**
     * @param int   $pageId
     * @param int   $categoryId  Pass 0 for uncategorised pages (sentinel).
     * @param int   $ownerId
     * @param float $delta
     * @param bool  $isUpvote
     * @param bool  $isNewVoter
     * @param bool  $incrementUniqueVoter Whether to increment unique_voters. Should be true
     *                                    only for the FIRST category in a multi-category vote
     *                                    to prevent over-counting. Defaults to matching $isNewVoter
     *                                    for backward compat with single-category callers.
     */
    public function applyVoteDeltaUpsert(
        int   $pageId,
        int   $categoryId,
        int   $ownerId,
        float $delta,
        bool  $isUpvote,
        bool  $isNewVoter,
        ?bool $incrementUniqueVoter = null
    ): void {
        global $wpdb;

        $positiveDelta = $isUpvote  ? $delta : 0.0;
        $negativeDelta = !$isUpvote ? $delta : 0.0;
        $voterDelta    = ($incrementUniqueVoter ?? $isNewVoter) ? 1 : 0;
        $now           = current_time('mysql');

        // ON DUPLICATE KEY UPDATE targets the composite PK (page_id, category_id).
        // INSERT path: new page gets endorsement_bonus=0, onchain_bonus=0.
        // UPDATE path: endorsement_bonus and onchain_bonus are preserved (not in SET).
        //
        // NOTE: The INSERT total_score formula must mirror
        // TrustScoreService::formulaSql(). It uses literal 0.0 for
        // endorsement_bonus + onchain_bonus because column references
        // are unavailable inside VALUES on a new row.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $totalScoreSql = \BCC\Trust\Core\Services\TrustScoreService::formulaSql();
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->table}
                    (page_id, category_id, page_owner_id,
                     positive_score, negative_score, total_score,
                     vote_count, unique_voters,
                     confidence_score, reputation_tier, endorsement_count,
                     endorsement_bonus, onchain_bonus,
                     last_vote_at, last_calculated_at)
                 VALUES (%d, %d, %d, %f, %f,
                     LEAST(100, GREATEST(0, 50 + (%f - %f) * 2 + 0.0 + 0.0)),
                     1, %d,
                     0.0, 'neutral', 0,
                     0.0, 0.0,
                     %s, %s)
                 ON DUPLICATE KEY UPDATE
                     positive_score     = positive_score  + VALUES(positive_score),
                     negative_score     = negative_score  + VALUES(negative_score),
                     total_score        = {$totalScoreSql},
                     vote_count         = vote_count + 1,
                     unique_voters      = unique_voters + VALUES(unique_voters),
                     last_vote_at       = VALUES(last_vote_at),
                     last_calculated_at = VALUES(last_calculated_at)",
                $pageId,
                $categoryId,
                $ownerId,
                $positiveDelta,
                $negativeDelta,
                $positiveDelta,
                $negativeDelta,
                $voterDelta,
                $now,
                $now
            )
        );

        if ($result === false) {
            throw new Exception('Failed to upsert vote delta for page ' . $pageId);
        }
    }

    /**
     * Reverse a previous vote delta and apply a new one atomically.
     *
     * Used when a voter changes their vote type (upvote → downvote or vice versa).
     * Both the reversal and the new application happen in a single SQL UPDATE so
     * there is no window where the score is in an inconsistent intermediate state.
     *
     * GREATEST(0, …) guards prevent floating-point drift from pushing scores below 0.
     *
     * @param int   $pageId
     * @param int   $categoryId
     * @param float $previousDelta    Magnitude of the vote being reversed (always positive).
     * @param bool  $previousWasUpvote true if the original vote was an upvote.
     * @param float $newDelta          Magnitude of the new vote (always positive).
     * @param bool  $newIsUpvote       true if the new vote is an upvote.
     * @throws Exception On DB failure.
     */
    public function reverseAndReapplyDelta(
        int   $pageId,
        int   $categoryId,
        float $previousDelta,
        bool  $previousWasUpvote,
        float $newDelta,
        bool  $newIsUpvote
    ): void {
        global $wpdb;

        $positiveDelta = ($newIsUpvote      ? $newDelta      : 0.0)
                       - ($previousWasUpvote ? $previousDelta : 0.0);
        $negativeDelta = (!$newIsUpvote      ? $newDelta      : 0.0)
                       - (!$previousWasUpvote ? $previousDelta : 0.0);
        $now           = current_time('mysql');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // MySQL evaluates multi-assignment SET left-to-right: by the time
        // total_score is evaluated, positive_score / negative_score already
        // hold the NEW post-GREATEST values. Referencing `positive_score` and
        // `negative_score` directly is therefore correct and avoids
        // double-applying the delta (previous bug: re-added %f inside the
        // total_score expression, drifting total_score by 2*delta per flip).
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET
                   positive_score     = GREATEST(0, positive_score + %f),
                   negative_score     = GREATEST(0, negative_score + %f),
                   total_score        = LEAST(100, GREATEST(0,
                                           50 + (positive_score - negative_score) * 2
                                           + endorsement_bonus + onchain_bonus
                                       )),
                   last_calculated_at = %s
                 WHERE page_id = %d
                   AND category_id = %d",
                $positiveDelta,
                $negativeDelta,
                $now,
                $pageId,
                $categoryId
            )
        );

        if ($result === false) {
            throw new Exception('Failed to reverse-and-reapply vote delta for page ' . $pageId);
        }
    }

    /**
     * Check whether a vote landed on this page within the last $secondsAgo seconds.
     *
     * Used by drift-correction paths that must skip pages currently being
     * written to — otherwise the resync races with the in-flight vote's
     * post-commit sync and can flicker the read-model backward for up to
     * 30 s. Existence query (LIMIT 1) is intentional.
     */
    public function hasVoteSince(int $pageId, int $secondsAgo): bool {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - max(0, $secondsAgo));

        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table}
              WHERE page_id = %d
                AND last_vote_at IS NOT NULL
                AND last_vote_at > %s
              LIMIT 1",
            $pageId,
            $cutoff
        ));

        return $row !== null;
    }

    /**
     * Flag a page for authoritative recalculation by the 5-minute cron.
     *
     * Called after delta-based score writes (vote add, remove, flip) to ensure
     * the full recalculation path corrects any drift caused by the delta using
     * raw stored weights while the score row may contain fraud-adjusted or
     * time-decayed values from a prior recalculation.
     *
     * The delta gives instant feedback (user sees the score change immediately).
     * The recalculation corrects the precise value within ≤5 minutes.
     *
     * Safe to call outside a transaction — it's a single atomic UPDATE.
     *
     * @param int $pageId
     */
    /**
     * Does any score row for this page currently have recalculate_required = 1?
     *
     * Used by PageReadModelSync::correctDrift to avoid the feedback loop
     * where unconditional flagForRecalculation after a successful resync
     * re-flagged already-authoritative pages (audit HIGH-8).
     */
    public function needsRecalculation(int $pageId): bool
    {
        global $wpdb;

        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table}
             WHERE page_id = %d AND recalculate_required = 1
             LIMIT 1",
            $pageId
        ));

        return $val !== null;
    }

    public function flagForRecalculation(int $pageId): void {
        global $wpdb;

        // Audit LOW-5: unconditional SET.  The prior predicate
        // `AND recalculate_required = 0` was an optimisation that saved
        // zero work and complicated the failure-signalling semantics of
        // `$result === 0`.  MySQL short-circuits `SET col = col`, so an
        // unconditional UPDATE on an already-flagged row is effectively
        // a no-op at the storage layer.
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET recalculate_required = 1
             WHERE page_id = %d",
            $pageId
        ));

        if ($result === false) {
            // The flag is the sole mechanism that triggers the 5-minute
            // authoritative recalculation after a delta-based score write.
            // Throwing here rolls back the enclosing transaction (VoteWriter
            // calls this inside TransactionManager::run) so the caller
            // retries instead of leaving the page unflagged and silently
            // drifting. Previously this only logged, which produced
            // unobservable score drift.
            $dbError = $wpdb->last_error;
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] flagForRecalculation failed', [
                    'page_id'  => $pageId,
                    'db_error' => $dbError,
                ]);
            }
            throw new \RuntimeException(
                'flagForRecalculation UPDATE failed for page ' . $pageId . ': ' . $dbError
            );
        }
    }

    /**
     * Flag all pages owned by a user for deferred score recalculation.
     *
     * Issues a single atomic UPDATE instead of N per-page queries.
     * The bcc_trust_process_recalculations cron picks up flagged pages
     * and recalculates scores in batch every 5 minutes.
     *
     * @param int $userId Page owner's WordPress user ID.
     */
    public function flagOwnerPagesForRecalculation(int $userId): void {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET recalculate_required = 1
             WHERE page_owner_id = %d",
            $userId
        ));

        // Fail-loud: a dropped flag means owner-level actions (suspension,
        // moderation, fraud-score crossing) leave scores uncorrected until
        // a future unrelated write re-flags them. Matches the contract on
        // the sibling flagForRecalculation() which also throws.
        if ($result === false) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] flagOwnerPagesForRecalculation failed', [
                    'user_id'  => $userId,
                    'db_error' => $wpdb->last_error,
                ]);
            }
            throw new \RuntimeException(
                'flagOwnerPagesForRecalculation failed: ' . $wpdb->last_error
            );
        }
    }

    /**
     * Reverse a previous vote delta (used when a vote is removed entirely).
     *
     * Decrements the appropriate score column by $previousDelta and rebuilds
     * total_score.  GREATEST(0, …) prevents negative values from floating-point
     * rounding errors.
     *
     * @param int   $pageId
     * @param int   $categoryId
     * @param float $previousDelta       Magnitude of the vote being removed (always positive).
     * @param bool  $previousWasUpvote   true if the vote being removed was an upvote.
     * @param bool  $decrementUniqueVoter Whether to decrement unique_voters. Should be true
     *                                    only for the FIRST category in a multi-category removal
     *                                    to prevent over-decrementing. Defaults to true for
     *                                    backward compatibility with single-category callers.
     * @throws Exception On DB failure.
     */
    public function reverseVoteDelta(
        int   $pageId,
        int   $categoryId,
        float $previousDelta,
        bool  $previousWasUpvote,
        bool  $decrementUniqueVoter = true
    ): void {
        global $wpdb;

        $positiveDelta = $previousWasUpvote  ? -$previousDelta : 0.0;
        $negativeDelta = !$previousWasUpvote ? -$previousDelta : 0.0;
        $now           = current_time('mysql');
        $uniqueDelta   = $decrementUniqueVoter ? 1 : 0;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // MySQL SET is left-to-right: positive_score / negative_score hold
        // the post-GREATEST new values by the time total_score is evaluated.
        // Reference them directly instead of re-applying the delta.
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET
                   positive_score     = GREATEST(0, positive_score + %f),
                   negative_score     = GREATEST(0, negative_score + %f),
                   total_score        = LEAST(100, GREATEST(0,
                                           50 + (positive_score - negative_score) * 2
                                           + endorsement_bonus + onchain_bonus
                                       )),
                   vote_count         = GREATEST(0, vote_count - 1),
                   unique_voters      = GREATEST(0, unique_voters - %d),
                   last_calculated_at = %s
                 WHERE page_id = %d
                   AND category_id = %d",
                $positiveDelta,
                $negativeDelta,
                $uniqueDelta,
                $now,
                $pageId,
                $categoryId
            )
        );

        if ($result === false) {
            throw new Exception('Failed to reverse vote delta for page ' . $pageId);
        }

        // zero rows matched = score row missing (e.g. deleted concurrently).
        // We must not silently succeed — the vote was soft-deleted but the
        // score was never decremented, leaving a permanent +1 drift until
        // the next recalc. Log so the reconciliation cron can pick it up.
        if ((int) $result === 0 && class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] reverseVoteDelta no-op: score row missing', [
                'page_id'      => $pageId,
                'category_id'  => $categoryId,
                'delta'        => $previousDelta,
                'was_upvote'   => $previousWasUpvote,
            ]);
        }
    }

    /**
     * Recompute endorsement_bonus from the endorsements table (source of truth)
     * and update total_score atomically.
     *
     * This is the AUTHORITATIVE derivation path.  It is called during
     * recalculation (cron, repair, manual) to set endorsement_bonus from
     * the actual sum of active endorsement weights.
     *
     * The real-time path (applyEndorsementBonus / removeEndorsementBonus)
     * now also uses absolute derivation via deriveAndWriteEndorsementBonus(),
     * so both paths are idempotent and race-free.
     *
     * @param int $pageId
     * @return float  The recomputed endorsement_bonus value.
     */
    /**
     * Recompute endorsement_bonus from the endorsements table (source of truth)
     * and update total_score atomically.
     *
     * This is the AUTHORITATIVE derivation path.  It is called by the admin
     * repair tool and one-off corrections.  It should NOT be called from the
     * cron recalculation loop — recalculateScore() already derives
     * endorsement_bonus inside its FOR UPDATE transaction and writing it a
     * second time outside that transaction creates a TOCTOU race with live
     * EndorsementService calls (which now also use absolute derivation).
     *
     * To prevent races when called standalone, this method wraps the
     * read + write in a transaction with FOR UPDATE on the scores row.
     *
     * @param int $pageId
     * @return float  The recomputed endorsement_bonus value.
     */
    public function recomputeEndorsementBonus(int $pageId): float {
        global $wpdb;

        $endorsementsTable = \BCC\Trust\Core\Database\TableRegistry::endorsements();
        $totalScoreSql     = \BCC\Trust\Core\Services\TrustScoreService::formulaSql();
        $table             = $this->table;

        return \BCC\Trust\Core\Security\TransactionManager::run(function () use (
            $wpdb, $pageId, $endorsementsTable, $totalScoreSql, $table
        ) {
            // Lock the score row to prevent concurrent delta-based writes
            // (applyEndorsementBonus, applyVoteDelta, applyBonus) from
            // landing between our SUM read and our UPDATE write.
            $wpdb->get_row($wpdb->prepare(
                "SELECT page_id FROM {$table} WHERE page_id = %d FOR UPDATE",
                $pageId
            ));

            // Read endorsement weights under the lock.
            $trueBonus = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(weight), 0) FROM {$endorsementsTable}
                 WHERE page_id = %d AND status = 1",
                $pageId
            ));

            $now = current_time('mysql');

            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET endorsement_bonus  = %f,
                     total_score        = {$totalScoreSql},
                     last_calculated_at = %s
                 WHERE page_id = %d",
                $trueBonus,
                $now,
                $pageId
            ));

            return $trueBonus;
        });
    }

    /**
     * Create default score for a new page.
     *
     * Delegates to createIfNotExists() which uses INSERT IGNORE — the
     * correct pattern for concurrent first-score creation. Previously
     * used save() whose docblock self-describes as "@deprecated — races
     * with concurrent applyVoteDelta() increments" unless wrapped in a
     * transaction that holds FOR UPDATE. Every caller of createDefault
     * runs outside such a transaction, so the safer INSERT IGNORE path
     * is strictly better.
     */
    public function createDefault(int $pageId, int $ownerId): PageScore {
        return $this->createIfNotExists($pageId, $ownerId);
    }

    /**
     * Create if not exists.
     *
     * Uses INSERT IGNORE to avoid the TOCTOU race where two concurrent
     * callers both see "not exists" and both attempt to INSERT via
     * createDefault()'s REPLACE INTO — the second REPLACE deletes the
     * first row (and any concurrent vote deltas applied to it).
     *
     * INSERT IGNORE is a no-op on duplicate key, so the row created by
     * the first caller is preserved intact.
     */
    public function createIfNotExists(int $pageId, ?int $ownerId = null): PageScore {
        global $wpdb;

        // Get page owner if not provided
        if (!$ownerId) {
            $ownerId = \BCC\Trust\Core\Plugin::instance()->pageOwnerResolver()->getPageOwner($pageId);
        }

        if (!$ownerId) {
            throw new Exception('Cannot create score: No owner ID found for page ' . $pageId);
        }

        $now = current_time('mysql');
        $neutralScore = (float) BCC_TRUST_NEUTRAL_SCORE;

        // INSERT IGNORE: no-op if the row already exists (duplicate key).
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table}
                (page_id, category_id, page_owner_id, total_score,
                 positive_score, negative_score, vote_count, unique_voters,
                 confidence_score, reputation_tier, endorsement_count,
                 endorsement_bonus, onchain_bonus, last_calculated_at)
             VALUES (%d, 0, %d, %f,
                     0.0, 0.0, 0, 0,
                     0.0, 'neutral', 0,
                     0.0, 0.0, %s)",
            $pageId,
            $ownerId,
            $neutralScore,
            $now
        ));

        // Row now definitely exists — fetch and return it.
        $existing = $this->getByPageId($pageId);
        if (!$existing) {
            throw new Exception('Failed to create or retrieve score for page ' . $pageId);
        }

        return $existing;
    }

    /**
     * Get scores for multiple pages
     *
     * @param int[] $pageIds
     * @return array<int, PageScore>
     */
    public function getBulk(array $pageIds): array {
        global $wpdb;

        if (empty($pageIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pageIds), '%d'));
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table}
                 WHERE page_id IN ({$placeholders})",
                $pageIds
            )
        );

        // Convert to PageScore objects indexed by page_id
        $indexed = [];
        foreach ($results as $row) {
            $indexed[$row->page_id] = PageScore::fromDatabaseRow($row);
        }

        return $indexed;
    }

    /**
     * Get pages by owner
     *
     * @return stdClass[]
     */
    public function getByOwnerId(int $ownerId): array {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.page_id, s.category_id, s.page_owner_id, s.total_score, s.onchain_bonus, s.endorsement_bonus, s.positive_score, s.negative_score, s.vote_count, s.unique_voters, s.confidence_score, s.reputation_tier, s.endorsement_count, s.last_vote_at, s.last_calculated_at, s.fraud_metadata, s.recalculate_required, p.post_title
                 FROM {$this->table} s
                 LEFT JOIN {$wpdb->posts} p ON s.page_id = p.ID
                 WHERE s.page_owner_id = %d
                 ORDER BY s.total_score DESC
                 LIMIT 200",
                $ownerId
            )
        );

        $scores = [];
        foreach ($results as $row) {
            $scores[] = $this->buildScoreResult($row, PageScore::fromDatabaseRow($row));
        }

        return $scores;
    }

    /**
     * Batch-fetch lightweight score data (total_score, reputation_tier) for multiple pages.
     *
     * @param  int[] $pageIds
     * @return array<int, array{total_score: float, reputation_tier: string}>
     */
    public function getBatchScoreData(array $pageIds): array
    {
        global $wpdb;

        if (empty($pageIds)) {
            return [];
        }

        $pageIds      = array_map('intval', array_unique($pageIds));
        $placeholders = implode(',', array_fill(0, count($pageIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT page_id, total_score, reputation_tier
                 FROM {$this->table}
                 WHERE page_id IN ({$placeholders})",
                ...$pageIds
            )
        );

        $result = [];
        foreach ($rows as $row) {
            $pid = (int) $row->page_id;
            if (!isset($result[$pid])) {
                $result[$pid] = [
                    'total_score'     => (float) $row->total_score,
                    'reputation_tier' => (string) $row->reputation_tier,
                ];
            }
        }

        return $result;
    }

    /**
     * Apply endorsement bonus: derive absolute value from endorsement rows.
     *
     * IMPORTANT: Must be called from inside a TransactionManager::run()
     * closure (the endorsement service's transaction). The endorsement row
     * INSERT is visible to us (same transaction), so SUM(weight) already
     * includes the new endorsement.
     *
     * Previous implementation used additive `endorsement_bonus += weight`.
     * This raced with the cron recalculation path which writes an absolute
     * value derived from the same endorsement rows: if the cron's SUM
     * included the new row and then the additive write fired after the
     * cron committed, the bonus was double-counted until the next recalc.
     *
     * Now both live and cron paths derive from the same source of truth
     * (SUM of active endorsement weights), making them idempotent and
     * immune to ordering races.
     *
     * @throws Exception on database failure
     */
    public function applyEndorsementBonus(int $pageId, float $weight): void {
        $this->deriveAndWriteEndorsementBonus($pageId);
    }

    /**
     * Remove endorsement bonus: derive absolute value from endorsement rows.
     *
     * Same absolute-derivation approach as applyEndorsementBonus(). The
     * endorsement row's status has already been set to 0 (soft-deleted)
     * within the same transaction, so SUM(weight) excludes it.
     *
     * @throws Exception on database failure
     */
    public function removeEndorsementBonus(int $pageId, float $weight): void {
        $this->deriveAndWriteEndorsementBonus($pageId);
    }

    /**
     * Derive endorsement_bonus + endorsement_count from endorsement rows
     * and write to all score rows for a page. Locks score rows FOR UPDATE
     * to serialise with concurrent recalculations and other delta writes.
     *
     * Safe to call from inside an existing transaction (no nested START
     * TRANSACTION). The caller's TransactionManager::run() owns the
     * transaction boundary.
     *
     * @throws Exception on database failure
     */
    private function deriveAndWriteEndorsementBonus(int $pageId): void {
        global $wpdb;

        $totalScoreSql = \BCC\Trust\Core\Services\TrustScoreService::formulaSql();

        // Lock score rows to serialise with concurrent recalculations
        // and other delta writes (applyVoteDelta, applyBonus).
        $wpdb->get_results($wpdb->prepare(
            "SELECT page_id FROM {$this->table} WHERE page_id = %d FOR UPDATE",
            $pageId
        ));

        // SINGLE SOURCE OF TRUTH — DO NOT DIVERGE.
        // Use the shared dynamic formula (base_weight × vesting(current age) ×
        // retroactive_factor) × page_diversity. Stored `weight` is NEVER read
        // — it is frozen at creation time and would oscillate against the
        // cron recalc path, which uses this exact same helper.
        $endorsementRepo = \BCC\Trust\Core\Plugin::instance()->endorsementRepository();
        $rows = $endorsementRepo->getActiveWithFraudScoresForPage($pageId);
        $derived = \BCC\Trust\Core\Services\EndorsementWeightCalculator::computePageEndorsementBonus($rows);

        $bonus = (float) $derived['bonus'];
        $count = (int)   $derived['count'];
        $now   = current_time('mysql');

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET endorsement_count  = %d,
                 endorsement_bonus  = %f,
                 total_score        = {$totalScoreSql},
                 last_calculated_at = %s
             WHERE page_id = %d",
            $count,
            $bonus,
            $now,
            $pageId
        ));

        if ($result === false) {
            throw new Exception(
                'Failed to derive endorsement bonus for page ' . $pageId . ': ' . $wpdb->last_error
            );
        }
    }

    /**
     * Increment endorsement count atomically.
     */
    public function incrementEndorsementCount(int $pageId): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET endorsement_count = endorsement_count + 1,
                     last_calculated_at = %s
                 WHERE page_id = %d",
                current_time('mysql'),
                $pageId
            )
        );
        $this->invalidateCache($pageId);
    }

    /**
     * Decrement endorsement count atomically, clamped to zero.
     */
    public function decrementEndorsementCount(int $pageId): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET endorsement_count = GREATEST(0, endorsement_count - 1),
                     last_calculated_at = %s
                 WHERE page_id = %d",
                current_time('mysql'),
                $pageId
            )
        );
        $this->invalidateCache($pageId);
    }

    /**
     * Update fraud metadata.
     *
     * @param array<string, mixed> $fraudMetadata
     * @throws RepositoryException on database failure
     */
    public function updateFraudMetadata(int $pageId, array $fraudMetadata): void {
        global $wpdb;

        $result = $wpdb->update(
            $this->table,
            [
                'fraud_metadata' => json_encode($fraudMetadata),
                'last_calculated_at' => current_time('mysql')
            ],
            ['page_id' => $pageId],
            ['%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('ScoreRepository::updateFraudMetadata failed for page ' . $pageId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($pageId);
    }

    /**
     * Delete score (for page deletion).
     *
     * @throws RepositoryException on database failure
     */
    public function delete(int $pageId): void {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table,
            ['page_id' => $pageId],
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('ScoreRepository::delete failed for page ' . $pageId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($pageId);
    }

    /**
     * Bulk update scores.
     *
     * Wraps save() inside a transaction with a table-level lock on each
     * page's rows to prevent the REPLACE INTO DELETE+INSERT from racing
     * with concurrent applyVoteDelta() increments during admin repair.
     *
     * @param PageScore[] $scores
     */
    public function bulkUpdate(array $scores): int {
        if (empty($scores)) {
            return 0;
        }

        $updated = 0;

        TransactionManager::run(function () use ($scores, &$updated) {
            foreach ($scores as $score) {
                try {
                    $pageId = $score->getPageId();

                    // Lock ALL category rows for this page.
                    // This serialises with concurrent applyVoteDelta() which also
                    // acquires row-level locks on the same page_id.
                    global $wpdb;
                    $lockedRows = $wpdb->get_results($wpdb->prepare(
                        "SELECT id, category_id FROM {$this->table} WHERE page_id = %d FOR UPDATE",
                        $pageId
                    ));

                    if (!empty($lockedRows)) {
                        // Use UPDATE (not REPLACE INTO) to avoid DELETE+INSERT race.
                        foreach ($lockedRows as $row) {
                            $this->saveRecalculated($score, (int) $row->category_id);
                        }
                    } else {
                        // No existing row — but the FOR UPDATE above returned
                        // nothing only because there was nothing to lock.
                        // A concurrent applyVoteDeltaUpsert can INSERT between
                        // the lock-select and any write we do here.
                        //
                        // Race-safe path: INSERT IGNORE (unique-key serialises
                        // with the concurrent vote INSERT), then re-lock the
                        // resulting row, then UPDATE via saveRecalculated().
                        // The INSERT IGNORE is a no-op when the concurrent
                        // voter already created (page_id, 0); otherwise we
                        // created it. Either way, saveRecalculated runs on
                        // a locked row.
                        $this->createIfNotExists($pageId, $score->getPageOwnerId());
                        $wpdb->get_results($wpdb->prepare(
                            "SELECT id, category_id FROM {$this->table} WHERE page_id = %d FOR UPDATE",
                            $pageId
                        ));
                        $this->saveRecalculated($score, 0);
                    }
                    $this->invalidateCache($pageId);
                    $updated++;
                } catch (Exception $e) {
                    \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'Failed to update score', [
                        'page_id' => $score->getPageId(),
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        });

        return $updated;
    }

    /**
     * Build a standard result object from a DB row and its PageScore.
     */
    private function buildScoreResult(object $row, PageScore $score): stdClass {
        $result = new stdClass();
        $result->score             = $score;
        $result->post_title        = $row->post_title ?? null;
        $result->owner_name        = $row->owner_name ?? null;
        $result->page_id           = $score->getPageId();
        $result->total_score       = $score->getTotalScore();
        $result->reputation_tier   = $score->getReputationTier();
        $result->vote_count        = $score->getVoteCount();
        $result->endorsement_count = $score->getEndorsementCount();
        $result->confidence_score  = $score->getConfidenceScore();
        $result->confidence_percent = $score->getConfidencePercentage();
        $result->has_fraud_alerts  = $score->hasFraudAlerts();
        $result->is_reliable       = $score->getVoteCount() >= $this->minVotesReliable;
        return $result;
    }

    /**
     * Get score distribution by tier.
     *
     * READ MODEL ONLY — DO NOT BYPASS. Counts are aggregated from
     * bcc_page_read_model (single source of truth for tier) and cached
     * for 60s so admin pageloads no longer trigger a full-table scan
     * on every render. Write-path cache invalidation happens via
     * invalidateTierDistributionCache(), fired from read-model sync.
     *
     * @return array<string, int>
     */
    public function getTierDistribution(): array {
        $tiers = ['elite' => 0, 'trusted' => 0, 'neutral' => 0, 'caution' => 0, 'risky' => 0];

        $cacheKey   = 'tier_distribution_v1';
        $cacheGroup = 'bcc_trust_admin';
        $cached = wp_cache_get($cacheKey, $cacheGroup);
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $readModelTable = \BCC\Trust\Core\Database\TableRegistry::pageReadModel();

        // READ MODEL ONLY — DO NOT BYPASS.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant
        $rows = $wpdb->get_results(
            "SELECT reputation_tier, COUNT(*) as cnt FROM {$readModelTable} GROUP BY reputation_tier"
        );

        foreach ($rows as $row) {
            if (isset($tiers[$row->reputation_tier])) {
                $tiers[$row->reputation_tier] = (int) $row->cnt;
            }
        }

        wp_cache_set($cacheKey, $tiers, $cacheGroup, 60);
        return $tiers;
    }

    /**
     * Check whether a page's score is changing suspiciously fast.
     *
     * Alerts if the score has moved more than 10 points in a single hour
     * (positive or negative).  The threshold is hard-coded because it is a
     * security parameter, not a UX preference.
     *
     * @param  int   $pageId
     * @return array{alert: bool, points_per_hour: float, direction: string}
     */
    public function checkScoreVelocity(int $pageId): array {
        global $wpdb;

        $votes_table = \BCC\Trust\Core\Database\TableRegistry::votes();

        // Sum of weighted votes cast in the last hour (up = positive, down = negative).
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(CASE WHEN vote_type = 1 THEN weight ELSE 0 END) AS up_weight,
                SUM(CASE WHEN vote_type = -1 THEN weight ELSE 0 END) AS down_weight
             FROM {$votes_table}
             WHERE page_id = %d
               AND status = 1
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $pageId
        ));

        $netWeight     = (float) ($row->up_weight ?? 0) - (float) ($row->down_weight ?? 0);
        $pointsPerHour = abs($netWeight) * 2; // mirrors the score formula: each weight point = 2 score points

        $alert     = $pointsPerHour >= 10;
        $direction = $netWeight >= 0 ? 'upward' : 'downward';

        if ($alert) {
            \BCC\Trust\Core\Security\AuditLogger::log('score_velocity_alert', $pageId, [
                'points_per_hour' => $pointsPerHour,
                'direction'       => $direction,
                'net_weight'      => $netWeight,
            ], 'page');
        }

        return [
            'alert'          => $alert,
            'points_per_hour' => $pointsPerHour,
            'direction'      => $direction,
        ];
    }

    /**
     * Analyse voter diversity for a page and return alerts where the
     * distribution looks like coordinated manipulation.
     *
     * Checks:
     *  - IP diversity: >50 % of voters share the same /24 subnet.
     *  - Cohort detection: >60 % of voters registered within the same
     *    7-day window.
     *  - Tier concentration: >70 % of votes come from a single tier.
     *
     * @param  int   $pageId
     * @return string[]  List of alert strings (empty = no issues found).
     */
    public function getVoterDiversityAlerts(int $pageId): array {
        global $wpdb;

        $votes_table = \BCC\Trust\Core\Database\TableRegistry::votes();
        $alerts      = [];

        // ── IP diversity ────────────────────────────────────────────────────
        // Sample-bound: heuristic only needs a representative sample. Without
        // LIMIT a popular page (10k+ votes) would load every row into PHP on
        // every vote-change event, degrading vote-cast latency O(votes_on_page).
        $voterIps = $wpdb->get_results($wpdb->prepare(
            "SELECT voter_user_id, ip_address
             FROM {$votes_table}
             WHERE page_id = %d AND status = 1 AND ip_address IS NOT NULL
             ORDER BY id DESC
             LIMIT 1000",
            $pageId
        ));

        if (count($voterIps) >= 5) {
            $subnetCounts = [];
            foreach ($voterIps as $row) {
                $ip = @inet_ntop($row->ip_address);
                if ($ip && strpos($ip, ':') === false) {
                    // IPv4 — bucket by /24
                    $parts  = explode('.', $ip);
                    $subnet = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
                } elseif ($ip) {
                    // IPv6 — bucket by first 48 bits (6 hex groups)
                    $packed   = inet_pton($ip);
                    $expanded = $packed !== false ? (inet_ntop($packed) ?: '') : '';
                    $subnet   = substr($expanded, 0, 14) . '::/48';
                } else {
                    continue;
                }
                $subnetCounts[$subnet] = ($subnetCounts[$subnet] ?? 0) + 1;
            }

            if (!empty($subnetCounts)) {
                $maxSubnet      = max($subnetCounts);
                $concentrationRate = $maxSubnet / count($voterIps);
                if ($concentrationRate > 0.50) {
                    $alerts[] = sprintf(
                        'ip_concentration: %.0f%% of voters share the same /24 subnet.',
                        $concentrationRate * 100
                    );
                }
            }
        }

        // ── Registration cohort ─────────────────────────────────────────────
        $voterIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT voter_user_id FROM {$votes_table}
             WHERE page_id = %d AND status = 1
             LIMIT 500",
            $pageId
        ));

        if (count($voterIds) >= 5) {
            $placeholders = implode(',', array_fill(0, count($voterIds), '%d'));
            $regDates     = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT user_registered FROM {$wpdb->users}
                     WHERE ID IN ({$placeholders})",
                    $voterIds
                )
            );

            // Bucket by ISO week
            $weekCounts = [];
            foreach ($regDates as $date) {
                $week              = date('o-W', strtotime($date));
                $weekCounts[$week] = ($weekCounts[$week] ?? 0) + 1;
            }

            if (!empty($weekCounts)) {
                $maxWeek = max($weekCounts);
                if ($maxWeek / count($voterIds) > 0.60) {
                    $alerts[] = sprintf(
                        'registration_cohort: %.0f%% of voters registered in the same 7-day window.',
                        ($maxWeek / count($voterIds)) * 100
                    );
                }
            }
        }

        // ── Tier concentration ──────────────────────────────────────────────
        $reputationTable = \BCC\Trust\Core\Database\TableRegistry::reputation();
        $tableExists     = \BCC\Trust\Core\Database\TableRegistry::exists($reputationTable);

        if ($tableExists && !empty($voterIds)) {
            $placeholders = implode(',', array_fill(0, count($voterIds), '%d'));
            $tierRows     = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT reputation_tier, COUNT(*) as cnt
                     FROM {$reputationTable}
                     WHERE user_id IN ({$placeholders})
                     GROUP BY reputation_tier",
                    $voterIds
                )
            );

            if (!empty($tierRows)) {
                $totalTierVoters = array_sum(array_column($tierRows, 'cnt'));
                foreach ($tierRows as $tierRow) {
                    if ($tierRow->cnt / $totalTierVoters > 0.70) {
                        $alerts[] = sprintf(
                            'tier_concentration: %.0f%% of voters are in the "%s" tier.',
                            ($tierRow->cnt / $totalTierVoters) * 100,
                            $tierRow->reputation_tier
                        );
                    }
                }
            }
        }

        if (!empty($alerts)) {
            \BCC\Trust\Core\Security\AuditLogger::log('voter_diversity_alert', $pageId, [
                'alerts' => $alerts,
            ], 'page');
        }

        return $alerts;
    }

    // ======================================================
    // DEFENSE: Score Velocity Cap
    // ======================================================

    /**
     * Track and enforce daily score velocity cap for a page.
     *
     * Records the vote delta for the day and returns the clamped delta
     * if the daily cap would be exceeded.
     *
     * Uses INSERT … ON DUPLICATE KEY UPDATE for atomicity — no
     * read-modify-write race conditions.
     *
     * @param  int   $pageId  Target page.
     * @param  float $delta   Proposed weight delta (always positive).
     * @return float Clamped delta (may be less than $delta if cap is hit).
     */
    public function applyVelocityCap(int $pageId, float $delta): float {
        global $wpdb;

        // Runtime assertion: the FOR UPDATE below is only race-safe inside
        // an outer transaction. Autocommit would release the row lock
        // immediately, letting two concurrent voters both read the same
        // score_delta and both apply a cap-eligible delta, bypassing
        // BCC_TRUST_MAX_SCORE_CHANGE_PER_DAY.
        if (!\BCC\Trust\Core\Security\TransactionManager::isInRunTransaction()) {
            throw new \LogicException(
                'ScoreRepository::applyVelocityCap() must be called inside TransactionManager::run(). '
                . 'Its SELECT ... FOR UPDATE is a no-op under autocommit.'
            );
        }

        $velocityTable = \BCC\Trust\Core\Database\TableRegistry::scoreVelocity();
        $today         = current_time('Y-m-d');
        $maxDaily      = (float) BCC_TRUST_MAX_SCORE_CHANGE_PER_DAY;

        // Ensure the row exists so FOR UPDATE can lock it.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$velocityTable} (page_id, track_date, score_delta)
             VALUES (%d, %s, 0.0)",
            $pageId,
            $today
        ));

        // Lock the row for the duration of the enclosing transaction to
        // prevent concurrent voters from both reading the same delta and
        // bypassing the cap.
        $currentDelta = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT score_delta FROM {$velocityTable}
             WHERE page_id = %d AND track_date = %s
             FOR UPDATE",
            $pageId,
            $today
        ));

        // Each weight point = 2 score points (mirrors the score formula)
        $scoreImpact = $delta * 2;
        $remaining   = max(0.0, $maxDaily - ($currentDelta * 2));

        if ($scoreImpact > $remaining && $remaining > 0) {
            $clampedDelta = $remaining / 2;

            AuditLogger::log('score_velocity_capped', $pageId, [
                'original_delta'  => round($delta, 4),
                'clamped_delta'   => round($clampedDelta, 4),
                'daily_used'      => round($currentDelta, 4),
                'daily_cap'       => $maxDaily,
            ], 'page');

            $delta = $clampedDelta;
        } elseif ($remaining <= 0) {
            AuditLogger::log('score_velocity_blocked', $pageId, [
                'blocked_delta' => round($delta, 4),
                'daily_used'    => round($currentDelta, 4),
                'daily_cap'     => $maxDaily,
            ], 'page');

            return 0.0;
        }

        // Record the delta — row is guaranteed to exist from INSERT IGNORE above.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$velocityTable}
             SET score_delta = score_delta + %f
             WHERE page_id = %d AND track_date = %s",
            $delta,
            $pageId,
            $today
        ));

        return $delta;
    }

    /**
     * Get page IDs with stale scores (not recalculated within the given interval).
     *
     * @return int[]
     */
    public function getStalePageIds(int $limit = 100): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT page_id FROM {$this->table}
             WHERE last_calculated_at IS NULL
                OR last_calculated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
             LIMIT %d",
            $limit
        )) ?: []);
    }

    /**
     * Initialize a default score row for a newly created page.
     */
    public function initializeForPage(int $pageId, int $ownerId): bool
    {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT page_id FROM {$this->table} WHERE page_id = %d",
            $pageId
        ));

        if ($exists) {
            return false;
        }

        $result = $wpdb->insert($this->table, [
            'page_id'          => $pageId,
            'page_owner_id'    => $ownerId,
            'total_score'      => (float) BCC_TRUST_NEUTRAL_SCORE,
            'positive_score'   => 0.0,
            'negative_score'   => 0.0,
            'vote_count'       => 0,
            'unique_voters'    => 0,
            'confidence_score' => 0.0,
            'reputation_tier'  => 'neutral',
        ], ['%d', '%d', '%f', '%f', '%f', '%d', '%d', '%f', '%s']);

        return $result !== false;
    }

    /**
     * Get the owner user ID for a page from the scores table.
     */
    public function getPageOwnerId(int $pageId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT page_owner_id FROM {$this->table} WHERE page_id = %d",
            $pageId
        ));
    }

    // -------------------------------------------------------------------------
    // Admin / repair / moderation operations
    // -------------------------------------------------------------------------

    /**
     * Get page IDs owned by a user.
     *
     * @return int[]
     */
    public function getPageIdsByOwner(int $ownerId): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT page_id FROM {$this->table} WHERE page_owner_id = %d",
            $ownerId
        )));
    }

    /**
     * Get page IDs owned by any of the given users — single query.
     *
     * Replaces the N+1 pattern of calling getPageIdsByOwner() inside a loop
     * (see CronService::dailyFraudRefresh). Chunks the IN list to stay under
     * max_allowed_packet on very large inputs.
     *
     * @param int[] $ownerIds
     * @return int[] Deduplicated list of page IDs.
     */
    public function getPageIdsByOwners(array $ownerIds): array
    {
        if (empty($ownerIds)) {
            return [];
        }

        global $wpdb;

        $clean = array_values(array_unique(array_map('intval', $ownerIds)));
        $pageIds = [];

        foreach (array_chunk($clean, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT page_id FROM {$this->table} WHERE page_owner_id IN ($placeholders)",
                ...$chunk
            ));
            foreach ($rows as $pid) {
                $pageIds[(int) $pid] = true;
            }
        }

        return array_keys($pageIds);
    }

    /**
     * Override score and tier for all pages owned by a user.
     *
     * Defence-in-depth: clamps total_score to [0, 100] and validates tier
     * against PageScore::VALID_TIERS. Any UI/CLI path that forwards untrusted
     * input (admin XSS, typo, script error) cannot persist out-of-range
     * values or arbitrary tier strings.
     *
     * @return int Number of rows updated.
     * @throws \InvalidArgumentException on invalid tier.
     */
    public function overrideScoreByOwner(int $ownerId, float $score, string $tier): int
    {
        global $wpdb;

        if (!in_array($tier, \BCC\Trust\Core\ValueObjects\PageScore::validTiers(), true)) {
            throw new \InvalidArgumentException(
                'Invalid reputation tier: ' . $tier
            );
        }

        $result = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET total_score = LEAST(100, GREATEST(0, %f)),
                 reputation_tier = %s,
                 last_calculated_at = %s
             WHERE page_owner_id = %d",
            $score, $tier, current_time('mysql'), $ownerId
        ));

        if ($result > 0) {
            // Invalidate cache for every affected page so moderation
            // decisions become visible immediately (not after 300s TTL).
            $pageIds = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT page_id FROM {$this->table} WHERE page_owner_id = %d",
                $ownerId
            ));
            foreach ($pageIds as $pid) {
                $this->invalidateCache((int) $pid);
            }
        }

        return $result;
    }

    /**
     * Get page_ids from the scores table with pagination.
     *
     * @param int $limit  Maximum rows to return (default 1000).
     * @param int $offset Offset for pagination (default 0).
     * @return int[]
     */
    public function getAllPageIds(int $limit = 1000, int $offset = 0): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT page_id FROM {$this->table} ORDER BY page_id ASC LIMIT %d OFFSET %d",
            $limit,
            $offset
        )));
    }

    /**
     * Get score row (raw DB row) for a page.
     *
     * @phpstan-return ScoreRow|null
     */
    public function getRawRow(int $pageId): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$this->table} WHERE page_id = %d",
            $pageId
        ));
    }

    /**
     * Update a score row with pre-calculated values (admin repair).
     *
     * Defence-in-depth: clamps total_score / positive_score / negative_score
     * and validates reputation_tier against the whitelist so a bad admin
     * payload cannot persist out-of-range values.
     *
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException on invalid tier.
     */
    public function updateCalculatedScore(int $pageId, array $data): void
    {
        global $wpdb;

        if (isset($data['total_score'])) {
            $data['total_score'] = max(0.0, min(100.0, (float) $data['total_score']));
        }
        if (isset($data['positive_score'])) {
            $data['positive_score'] = max(0.0, (float) $data['positive_score']);
        }
        if (isset($data['negative_score'])) {
            $data['negative_score'] = max(0.0, (float) $data['negative_score']);
        }
        if (isset($data['reputation_tier'])
            && !in_array($data['reputation_tier'], \BCC\Trust\Core\ValueObjects\PageScore::validTiers(), true)
        ) {
            throw new \InvalidArgumentException(
                'Invalid reputation tier: ' . (string) $data['reputation_tier']
            );
        }

        $data['last_calculated_at'] = current_time('mysql');

        $formats = [];
        foreach (array_keys($data) as $field) {
            $formats[] = match ($field) {
                'total_score', 'positive_score', 'negative_score',
                'confidence_score', 'endorsement_bonus', 'onchain_bonus' => '%f',
                'vote_count', 'unique_voters', 'endorsement_count' => '%d',
                default => '%s',
            };
        }

        $result = $wpdb->update(
            $this->table,
            $data,
            ['page_id' => $pageId],
            $formats,
            ['%d']
        );

        if ($result !== false && $result > 0) {
            $this->invalidateCache($pageId);
        }
    }

    /**
     * Update page owner in the scores table.
     */
    public function updateOwner(int $pageId, int $ownerId): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table,
            ['page_owner_id' => $ownerId],
            ['page_id' => $pageId],
            ['%d'],
            ['%d']
        );

        $this->invalidateCache($pageId);
    }

    /**
     * Insert a default score row for a page (admin repair).
     *
     * @return bool True on success.
     */
    public function insertDefaultScore(int $pageId, int $ownerId): bool
    {
        global $wpdb;

        $result = $wpdb->insert(
            $this->table,
            [
                'page_id'            => $pageId,
                'page_owner_id'      => $ownerId,
                'total_score'        => (float) BCC_TRUST_NEUTRAL_SCORE,
                'positive_score'     => 0,
                'negative_score'     => 0,
                'vote_count'         => 0,
                'unique_voters'      => 0,
                'confidence_score'   => 0,
                'reputation_tier'    => 'neutral',
                'endorsement_count'  => 0,
                'last_calculated_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%f', '%f', '%f', '%d', '%d', '%f', '%s', '%d', '%s']
        );

        return $result !== false;
    }

    /**
     * Check if a score row exists for a page.
     */
    public function existsForPage(int $pageId): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT page_id FROM {$this->table} WHERE page_id = %d",
            $pageId
        ));
    }

    /**
     * Delete orphaned score rows (pages that no longer exist in wp_posts).
     *
     * @return int Number of rows deleted.
     */
    public function deleteOrphaned(int $limit = 1000): int
    {
        global $wpdb;

        // Bounded DELETE so an admin-invoked cleanup on a site with
        // millions of orphaned rows does not DDL-lock the table. The
        // `AND %d` no-op guard is preserved from the original query.
        $limit = max(1, min(10000, $limit));
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE s FROM {$this->table} s
             LEFT JOIN {$wpdb->posts} p ON s.page_id = p.ID
             WHERE p.ID IS NULL
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Count orphaned score rows (bounded).
     */
    public function countOrphaned(int $limit = 10000): int
    {
        global $wpdb;

        $limit = max(1, min(100000, $limit));
        // Use a bounded subquery so the COUNT cannot runaway on a huge
        // table. Returns up to $limit — callers treat "== $limit" as
        // "at least this many" for display purposes.
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT s.page_id
                FROM {$this->table} s
                LEFT JOIN {$wpdb->posts} p ON s.page_id = p.ID
                WHERE p.ID IS NULL
                LIMIT %d
             ) t",
            $limit
        ));
    }

    /**
     * Check if the scores table exists.
     */
    public function tableExists(): bool
    {
        return \BCC\Trust\Core\Database\TableRegistry::exists($this->table);
    }

    /**
     * Get pages with mismatched owners (scores vs posts table).
     *
     * @return object[]
     */
    public function getMismatchedOwners(int $limit = 500): array
    {
        global $wpdb;

        // Bounded admin-invoked read so the dashboard cannot drag every
        // mismatched row into PHP memory on a large install. 500 is the
        // default surface area; callers may widen within the hard cap.
        $limit = max(1, min(5000, $limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.page_id, p.post_title, s.page_owner_id as score_owner, p.post_author as post_author
             FROM {$this->table} s
             JOIN {$wpdb->posts} p ON s.page_id = p.ID
             WHERE s.page_owner_id != p.post_author
             LIMIT %d",
            $limit
        )) ?: [];
    }

    /**
     * Get PeepSo pages missing from the scores table — cursor paginated.
     *
     * SAFETY: previously unbounded LEFT JOIN scan across wp_posts. On bulk
     * imports this OOMs PHP and locks MySQL during the join. Always call
     * with $afterId looped.
     *
     * @param int $afterId Return rows with p.ID > this. Pass 0 to start.
     * @param int $limit   Max rows per page (default 200, capped at 1000).
     * @return object[]
     * @phpstan-return list<object{
     *   ID: int|numeric-string,
     *   post_title: string,
     *   post_author: int|numeric-string
     * }>
     */
    public function getMissingPages(int $afterId = 0, int $limit = 200): array
    {
        global $wpdb;

        $limit = max(1, min($limit, 1000));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_author
             FROM {$wpdb->posts} p
             LEFT JOIN {$this->table} s ON p.ID = s.page_id
             WHERE p.post_type = %s
               AND s.page_id IS NULL
               AND p.ID > %d
             ORDER BY p.ID ASC
             LIMIT %d",
            'peepso-page',
            $afterId,
            $limit
        )) ?: [];
    }

    /**
     * Reset quarantined pages for retry.
     *
     * @return int Number of rows reset.
     */
    public function resetQuarantinedPages(int $maxFailures): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET recalc_failures = 0,
                 recalculate_required = 1
             WHERE recalc_failures >= %d",
            $maxFailures
        ));
    }

    /**
     * Get page IDs flagged for recalculation.
     *
     * @return int[]
     */
    public function getFlaggedPageIds(int $maxFailures, int $limit): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT page_id FROM {$this->table}
             WHERE recalculate_required = 1
               AND recalc_failures < %d
             ORDER BY last_calculated_at ASC
             LIMIT %d",
            $maxFailures,
            $limit
        )));
    }

    /**
     * Total rows currently flagged for authoritative recalculation.
     *
     * Used by the bcc-core health dashboard to surface queue depth.
     * Bounded by a single aggregate COUNT over the dedicated
     * idx_recalculate index, so it is safe to call synchronously on
     * admin-dashboard requests. Returns 0 on DB error — the caller
     * (RecalcQueueReadService) is documented as non-throwing.
     */
    public function countPendingRecalc(): int
    {
        global $wpdb;

        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE recalculate_required = 1"
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Increment recalculation failure counter for a page.
     */
    public function incrementRecalcFailures(int $pageId): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET recalc_failures = recalc_failures + 1
             WHERE page_id = %d",
            $pageId
        ));
    }

    /**
     * Get the current recalc_failures count for a page.
     */
    public function getRecalcFailures(int $pageId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT recalc_failures FROM {$this->table} WHERE page_id = %d LIMIT 1",
            $pageId
        ));
    }

    /**
     * Set a bonus column and recompute total_score atomically.
     *
     * @param int    $pageId  Target page.
     * @param string $column  Bonus column name (must be 'onchain_bonus' or 'endorsement_bonus').
     * @param float  $value   New value for the bonus column.
     * @return bool True on success, false on failure.
     */
    public function applyBonusColumn(int $pageId, string $column, float $value): bool
    {
        global $wpdb;

        // Whitelist columns to prevent SQL injection
        $allowed = ['onchain_bonus', 'endorsement_bonus'];
        if (!in_array($column, $allowed, true)) {
            return false;
        }

        $totalScoreSql = \BCC\Trust\Core\Services\TrustScoreService::formulaSql();
        $roundedValue  = round($value, 2);
        $now           = current_time('mysql');

        // SET semantics: the caller (ScoreContributorService) receives a
        // final authoritative value from the external contributor (e.g.
        // BonusService::recomputeAndApply, which sums signals + claims and
        // passes the total). The earlier additive form caused monotonic
        // inflation because every sync re-added the same combined total —
        // see the onchain contract comment that explicitly documents SET
        // semantics. Aggregation of multiple sources is now the caller's
        // responsibility, not the repository's.
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET {$column}         = %f,
                 total_score       = {$totalScoreSql},
                 last_calculated_at = %s
             WHERE page_id = %d",
            $roundedValue,
            $now,
            $pageId
        ));

        // SECURITY: distinguish DB error (false) from "no rows matched" (0).
        // 0 rows affected means the page has no score row yet — the bonus
        // would be silently lost. Return false so the caller can create
        // the row and retry (see ScoreContributorService).
        if ($result === false) {
            return false;
        }

        if ($result > 0) {
            $this->invalidateCache($pageId);
            return true;
        }
        return false;
    }

    /**
     * Clear the recalculation flag for a page (quarantine).
     */
    public function clearRecalcFlag(int $pageId): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table,
            ['recalculate_required' => 0],
            ['page_id' => $pageId],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Acquire row-level locks on ALL score rows for a page.
     *
     * Used by recalculateScore() to serialize with concurrent applyVoteDelta()
     * calls that target any category row for this page.
     *
     * MUST be called inside a TransactionManager::run() closure.
     *
     * @param int $pageId
     * @return object[]  Array of locked row objects (page_id, category_id).
     * @phpstan-return list<object{page_id: int|numeric-string, category_id: int|numeric-string}>
     */
    public function lockAllForPage( int $pageId ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT page_id, category_id FROM {$this->table} WHERE page_id = %d FOR UPDATE",
                $pageId
            )
        ) ?: [];
    }

    /**
     * Clear the recalculation flag AND reset recalc_failures atomically.
     *
     * Used inside a FOR UPDATE transaction after a successful recalculation
     * to prevent the race where a live vote sets the flag between COMMIT
     * and an external flag-clear.
     *
     * @param int $pageId
     */
    public function clearRecalcFlagAndFailures( int $pageId ): void {
        global $wpdb;

        $wpdb->update(
            $this->table,
            ['recalculate_required' => 0, 'recalc_failures' => 0],
            ['page_id' => $pageId],
            ['%d', '%d'],
            ['%d']
        );
    }
}