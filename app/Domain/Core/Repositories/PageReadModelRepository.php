<?php
/**
 * Page Read Model Repository
 *
 * Manages the denormalized bcc_page_read_model table that pre-aggregates
 * trust score, votes, endorsements, followers, and metadata for every
 * page. The discovery endpoint, page header, and search results read
 * from this table instead of joining 4+ tables at query time.
 *
 * Write path: sync hooks on vote, endorsement, score recalculation.
 * Read path:  PageDiscoveryService, REST endpoints, shortcodes.
 *
 * @package BCC\Trust\Core\Repositories
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\WalletSignalRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Row shape returned by bcc_page_read_model reads. Values come back from
 * $wpdb->get_row() as string|null for scalar columns; numeric fields are
 * widened to int|numeric-string / float|numeric-string so consumers can
 * continue to rely on the existing loose-comparison / cast behaviour.
 *
 * @phpstan-type PageReadModelRow object{
 *   page_id: int|numeric-string,
 *   owner_id: int|numeric-string,
 *   trust_score: float|numeric-string,
 *   reputation_tier: string,
 *   confidence_score: float|numeric-string,
 *   positive_score: float|numeric-string,
 *   negative_score: float|numeric-string,
 *   onchain_bonus: float|numeric-string,
 *   attestation_bonus: float|numeric-string,
 *   vote_count: int|numeric-string,
 *   unique_voters: int|numeric-string,
 *   endorsement_count: int|numeric-string,
 *   follower_count: int|numeric-string,
 *   page_type: string,
 *   is_verified: int|numeric-string,
 *   has_verified_claim: int|numeric-string,
 *   github_username: string|null,
 *   github_followers: int|numeric-string,
 *   x_username: string|null,
 *   x_followers: int|numeric-string,
 *   has_wallet: int|numeric-string,
 *   last_vote_at: string|null,
 *   last_endorsement_at: string|null,
 *   updated_at: string
 * }
 */
class PageReadModelRepository
{
    /** @var string Object-cache group. */
    private const CACHE_GROUP = 'bcc_page_rm';

    /** @var int Cache TTL in seconds (10 minutes). */
    private const CACHE_TTL = 600;

    /**
     * Bounded reason codes for a sync that did not complete. A code is the
     * ENTIRE failure message — never SQL, never the driver's error text, never
     * a page id — so it is safe to log and to count.
     */
    private const FAILURE_AGGREGATE_READ = 'aggregate_score_read_failed';
    private const FAILURE_FALLBACK_READ  = 'fallback_score_read_failed';
    private const FAILURE_UPSERT         = 'read_model_upsert_failed';
    private const FAILURE_PAGE_LIST_READ = 'page_list_read_failed';
    private const FAILURE_OTHER          = 'sync_failed';

    /** @var string Explicit column list — must match schema-project.php. */
    private const COLUMNS = 'page_id, owner_id, trust_score, reputation_tier, confidence_score,
                 positive_score, negative_score, onchain_bonus, attestation_bonus,
                 vote_count, unique_voters, endorsement_count, follower_count,
                 page_type, is_verified, has_verified_claim, github_username, github_followers,
                 x_username, x_followers, has_wallet, last_vote_at, last_endorsement_at, updated_at';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::pageReadModel();
    }

    // ──────────────────────────────────────────────────────────
    //  Read
    // ──────────────────────────────────────────────────────────

    /**
     * Get a single page's read model row.
     *
     * @phpstan-return PageReadModelRow|null
     */
    public function getByPageId(int $pageId): ?object
    {
        $cached = wp_cache_get('rm_' . $pageId, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached ?: null;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$this->table} WHERE page_id = %d",
            $pageId
        ));

        wp_cache_set('rm_' . $pageId, $row ?: '', self::CACHE_GROUP, self::CACHE_TTL);

        return $row;
    }

    /**
     * Batch-fetch read model rows for multiple pages.
     *
     * @param  int[] $pageIds
     * @return array<int, object> Keyed by page_id.
     * @phpstan-return array<int, PageReadModelRow>
     */
    public function getByPageIds(array $pageIds): array
    {
        if (empty($pageIds)) {
            return [];
        }

        global $wpdb;
        $pageIds      = array_map('intval', array_unique($pageIds));
        $placeholders = implode(',', array_fill(0, count($pageIds), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$this->table} WHERE page_id IN ({$placeholders})",
            $pageIds
        ));

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row->page_id] = $row;
            wp_cache_set('rm_' . $row->page_id, $row, self::CACHE_GROUP, self::CACHE_TTL);
        }

        // Negative-cache priming: pages absent from the read model get an
        // empty string cached so subsequent getByPageId() calls don't hit DB.
        foreach ($pageIds as $id) {
            if (!isset($indexed[$id])) {
                wp_cache_set('rm_' . $id, '', self::CACHE_GROUP, self::CACHE_TTL);
            }
        }

        return $indexed;
    }

    /**
     * Read just the reputation_tier for a single page.
     *
     * Bounded by the page_id primary key. Returns null when the read-model
     * row hasn't projected yet or the tier column is empty — the same null
     * semantics WatchingService::buildItem already handles. Reuses the
     * full-row getByPageId() cache so a tier lookup that follows a row read
     * (or vice-versa) is served from cache.
     */
    public function getReputationTier(int $pageId): ?string
    {
        $row = $this->getByPageId($pageId);
        if ($row === null) {
            return null;
        }
        $tier = $row->reputation_tier;
        if ($tier === '') {
            return null;
        }
        return $tier;
    }

    /**
     * Check whether the read model table has been populated.
     *
     * Used by PageDiscoveryService to decide whether to use the read model
     * or fall back to the legacy aggregation query.
     */
    public function hasData(): bool
    {
        $cached = wp_cache_get('rm_has_data', self::CACHE_GROUP);
        if ($cached !== false) {
            return (bool) $cached;
        }

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT EXISTS (SELECT 1 FROM {$this->table} LIMIT 1)");

        wp_cache_set('rm_has_data', $count, self::CACHE_GROUP, 3600);

        return (bool) $count;
    }

    // ──────────────────────────────────────────────────────────
    //  Write (sync from source tables)
    // ──────────────────────────────────────────────────────────

    /**
     * Upsert a single page's read model from the current source-of-truth tables.
     *
     * Pulls trust score, votes, endorsements from bcc_trust_page_scores,
     * owner from PageOwnerResolver, followers from usermeta, page_type
     * from postmeta, verified status from user_info.
     *
     * @throws \Throwable when the sync did not complete. Nothing was written:
     *                    a score read that did not run is never mistaken for
     *                    "this page has no score".
     */
    public function syncPage(int $pageId): void
    {
        $this->syncPageInternal($pageId, true);
    }

    /**
     * The sync itself. `$logFailure` is false only for batch callers, which
     * summarise every failure in one bounded line instead of one per page.
     *
     * @throws \Throwable when the sync did not complete; see syncPage().
     */
    private function syncPageInternal(int $pageId, bool $logFailure): void
    {
        global $wpdb;

        // ── Ghost-row guard ──────────────────────────────────────────
        // UserLifecycleService::onPageDelete removes the row on
        // before_delete_post, but a later syncPage($staleId) — dirty-queue
        // straggler, cleanup script, race with deletion — would re-insert
        // a row from empty meta for a post that no longer exists. Treat
        // sync-of-missing/unpublished as delete instead of upsert.
        $post = get_post($pageId);
        if ($post === null || $post->post_type !== 'peepso-page' || $post->post_status !== 'publish') {
            $wpdb->delete($this->table, ['page_id' => $pageId], ['%d']);
            $this->invalidateCache($pageId);
            return;
        }

        // ── Pre-fetch WP meta outside the transaction ────────────────
        // These WP API calls may run uncached queries against wp_postmeta /
        // wp_usermeta. Fetching them before the transaction avoids holding
        // shared score-row locks while WordPress resolves meta caches.
        $pageType = get_post_meta($pageId, '_bcc_page_type', true) ?: 'builder';

        // Pre-resolve owner so we can fetch follower count before locking.
        $resolver = \BCC\Trust\Core\Plugin::instance()->pageOwnerResolver();
        $preOwnerId = $resolver->getPageOwner($pageId);
        $followerCount = $preOwnerId
            ? (int) get_user_meta($preOwnerId, 'peepso_followers_count', true)
            : 0;

        // Wrap the entire read-compute-write in a transaction to prevent
        // a concurrent vote/endorsement from landing between the source
        // reads and the read-model upsert, which would leave the read
        // model with a stale snapshot. Use TransactionManager so nested
        // calls become SAVEPOINTs (callers inside an outer TX will not
        // silently auto-commit via raw START TRANSACTION).
        try {
            \BCC\Trust\Core\Security\TransactionManager::run(function () use ($pageId, $pageType, $preOwnerId, $followerCount) {
                global $wpdb;

                $scores_table    = TableRegistry::scores();
        $user_info_table = TableRegistry::userInfo();

        // ── Fetch score row ─────────────────────────────────────────────
        // Try the aggregate row (category_id=0) first; fall back to the
        // highest-voted category row so pages with only category-specific
        // scores are not silently stuck at the 50.00 default.
        // LOCK IN SHARE MODE: acquire a shared lock on the score row so it
        // cannot be modified between this read and the read-model upsert
        // below. Concurrent reads (other syncPage calls for different pages)
        // are unaffected. Concurrent writes (vote deltas) will block until we
        // commit — ensuring the snapshot we write to the read model is
        // consistent with the score row at commit time.
        //
        // ⚠ NOT `FOR SHARE`. That is MySQL 8 syntax; production MariaDB 11.8
        // rejects it (errno 1064), and a rejected read used to fall through
        // to the neutral defaults below — overwriting every page's real score.
        // `LOCK IN SHARE MODE` takes the same lock on both engines. Guarded by
        // NoForShareLockingClauseTest.
        //
        // Each read FAILS CLOSED: null from get_row() is "no score row" only
        // when the statement actually ran. See readScoreRowOrThrow().
        $score = self::readScoreRowOrThrow($wpdb->prepare(
            "SELECT total_score, reputation_tier, confidence_score,
                    positive_score, negative_score, onchain_bonus,
                    attestation_bonus, vote_count, unique_voters,
                    endorsement_count, page_owner_id, last_vote_at
             FROM {$scores_table}
             WHERE page_id = %d AND category_id = 0
             LOCK IN SHARE MODE",
            $pageId
        ), self::FAILURE_AGGREGATE_READ);

        if ($score === null) {
            $score = self::readScoreRowOrThrow($wpdb->prepare(
                "SELECT total_score, reputation_tier, confidence_score,
                        positive_score, negative_score, onchain_bonus,
                        attestation_bonus, vote_count, unique_voters,
                        endorsement_count, page_owner_id, last_vote_at
                 FROM {$scores_table}
                 WHERE page_id = %d AND vote_count > 0
                 ORDER BY vote_count DESC
                 LIMIT 1
                 LOCK IN SHARE MODE",
                $pageId
            ), self::FAILURE_FALLBACK_READ);
        }

        // ── Resolve owner ───────────────────────────────────────────────
        // Prefer the authoritative owner from the score row; fall back to
        // the pre-resolved value used for meta fetching above.
        $ownerId = $score ? (int) $score->page_owner_id : 0;
        if (!$ownerId) {
            $ownerId = $preOwnerId;
        }

        // If the authoritative owner differs from the pre-resolved one,
        // re-fetch follower count for the correct user.
        if ($ownerId && $ownerId !== $preOwnerId) {
            $followerCount = (int) get_user_meta($ownerId, 'peepso_followers_count', true);
        }

        // ── Verified status ─────────────────────────────────────────────
        $isVerified = 0;
        if ($ownerId) {
            $userInfo = $wpdb->get_var($wpdb->prepare(
                "SELECT is_verified FROM {$user_info_table} WHERE user_id = %d",
                $ownerId
            ));
            $isVerified = $userInfo ? (int) $userInfo : 0;
        }

        // ── On-chain claim-verified status ──────────────────────────────
        // "Claim-verified" = this page has at least one verified
        // operator/creator claim (validator or collection), resolved via
        // the same filter ClaimRepository::getPrimaryClaimsByPageIds uses.
        // Distinct from is_verified (owner EMAIL verification) — this is
        // on-chain ownership. A single bounded batch call for one page.
        $hasVerifiedClaim = \BCC\Trust\Onchain\Repositories\ClaimRepository::getPrimaryClaimsByPageIds([$pageId]) !== []
            ? 1
            : 0;

        // ── Social verification + wallet data ──────────────────────────
        $ghUsername  = null;
        $ghFollowers = 0;
        $xUsername   = null;
        $hasWallet   = 0;

        if ($ownerId) {
            $verif_table = TableRegistry::userVerifications();

            $gh = $wpdb->get_row($wpdb->prepare(
                "SELECT provider_username, meta FROM {$verif_table}
                 WHERE user_id = %d AND type = 'github' AND status = 'active'",
                $ownerId
            ));
            if ($gh && $gh->provider_username) {
                $ghUsername  = $gh->provider_username;
                $ghMeta     = $gh->meta ? json_decode($gh->meta, true) : [];
                $ghFollowers = (int) ($ghMeta['followers'] ?? 0);
            }

            $x = $wpdb->get_row($wpdb->prepare(
                "SELECT provider_username, meta FROM {$verif_table}
                 WHERE user_id = %d AND type = 'x' AND status = 'active'
                 LIMIT 1",
                $ownerId
            ));
            if ($x && $x->provider_username) {
                $xUsername = $x->provider_username;
            }

            $hasWallet = !empty(WalletSignalRepository::getAllForUser($ownerId)) ? 1 : 0;
        }

        // ── X followers ─────────────────────────────────────────────
        $xFollowers = 0;
        if ($ownerId && $xUsername && isset($x) && $x->meta) {
            $xMeta      = json_decode($x->meta, true);
            $xFollowers = (int) ($xMeta['followers'] ?? 0);
        }

        // ── Last endorsement date ──────────────────────────────────
        // Attestation-backed since the legacy endorsements-table
        // retirement: newest ACTIVE kind=vouch attestation on this page
        // target. Read-model rows only exist for real peepso-page posts
        // (the ghost-row guard above), so the card target_kinds are the
        // complete key space here — member self-pages never reach this.
        $lastEndorsementAt = null;
        if ($score) {
            $attestations_table = TableRegistry::trustAttestations();
            $kindPlaceholders   = implode(
                ',',
                array_fill(0, count(\BCC\Trust\Core\Repositories\AttestationRepository::PAGE_TARGET_KINDS), '%s')
            );
            $lastEndorsementAt = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(created_at) FROM {$attestations_table}
                 WHERE target_id = %d
                   AND target_kind IN ({$kindPlaceholders})
                   AND kind = 'vouch'
                   AND revoked_at IS NULL",
                $pageId,
                ...\BCC\Trust\Core\Repositories\AttestationRepository::PAGE_TARGET_KINDS
            ));
        }

        $upserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->table}
                (page_id, owner_id, trust_score, reputation_tier, confidence_score,
                 positive_score, negative_score, onchain_bonus, attestation_bonus,
                 vote_count, unique_voters, endorsement_count, follower_count,
                 page_type, is_verified, has_verified_claim, github_username, github_followers,
                 x_username, x_followers, has_wallet, last_vote_at, last_endorsement_at, updated_at)
             VALUES (%d, %d, %f, %s, %f, %f, %f, %f, %f, %d, %d, %d, %d, %s, %d, %d, %s, %d, %s, %d, %d, %s, %s, NOW())
             ON DUPLICATE KEY UPDATE
                owner_id            = VALUES(owner_id),
                trust_score         = VALUES(trust_score),
                reputation_tier     = VALUES(reputation_tier),
                confidence_score    = VALUES(confidence_score),
                positive_score      = VALUES(positive_score),
                negative_score      = VALUES(negative_score),
                onchain_bonus       = VALUES(onchain_bonus),
                attestation_bonus   = VALUES(attestation_bonus),
                vote_count          = VALUES(vote_count),
                unique_voters       = VALUES(unique_voters),
                endorsement_count   = VALUES(endorsement_count),
                follower_count      = VALUES(follower_count),
                page_type           = VALUES(page_type),
                is_verified         = VALUES(is_verified),
                has_verified_claim  = VALUES(has_verified_claim),
                github_username     = VALUES(github_username),
                github_followers    = VALUES(github_followers),
                x_username          = VALUES(x_username),
                x_followers         = VALUES(x_followers),
                has_wallet          = VALUES(has_wallet),
                last_vote_at        = VALUES(last_vote_at),
                last_endorsement_at = VALUES(last_endorsement_at),
                updated_at          = NOW()",
            $pageId,
            $ownerId,
            $score ? (float) $score->total_score : (float) \BCC\Trust\Core\Services\TrustScoreService::neutral(),
            $score ? $score->reputation_tier : 'neutral',
            $score ? (float) $score->confidence_score : 0.00,
            $score ? (float) ($score->positive_score ?? 0) : 0.00,
            $score ? (float) ($score->negative_score ?? 0) : 0.00,
            $score ? (float) ($score->onchain_bonus ?? 0) : 0.00,
            $score ? (float) ($score->attestation_bonus ?? 0) : 0.00,
            $score ? (int) $score->vote_count : 0,
            $score ? (int) $score->unique_voters : 0,
            $score ? (int) $score->endorsement_count : 0,
            $followerCount,
            $pageType,
            $isVerified,
            $hasVerifiedClaim,
            $ghUsername,
            $ghFollowers,
            $xUsername,
            $xFollowers,
            $hasWallet,
            $score && !empty($score->last_vote_at) ? $score->last_vote_at : null,
            $lastEndorsementAt
        ));

        // The write fails closed too. An unchecked failed upsert used to let
        // TransactionManager commit nothing and report success, so syncAll()
        // counted the page as synced and the dirty queue deleted its entry —
        // leaving a stale row with nothing left to retry it. Only `false` is
        // a failure: an ON DUPLICATE KEY UPDATE that changes nothing reports
        // 0 affected rows, which is a success.
        if ($upserted === false) {
            throw new RepositoryException(self::FAILURE_UPSERT);
        }
            });
        } catch (\Throwable $e) {
            if ($logFailure && class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] syncPage transaction failed', [
                    'page_id' => $pageId,
                    'error'   => $e->getMessage(),
                ]);
            }
            // Audit HIGH-1: re-throw so PageReadModelSync::processDirtyPagesInner
            // records the failure and leaves the dirty-queue entry in place.
            // Silent `return` caused the outer loop to treat this page as
            // successfully synced, delete the dirty row, and leave the read
            // model permanently stale until the 30s drift scan caught up.
            throw $e;
        }

        // Invalidate AFTER commit so concurrent reads cannot cache
        // the pre-commit state between delete and the upsert commit.
        $this->invalidateCache($pageId);

        // Clear the "has data" flag so PageDiscoveryService switches
        // from the legacy path to the read-model path immediately
        // after the first page is synced (instead of waiting up to 1 hour).
        wp_cache_delete('rm_has_data', self::CACHE_GROUP);
    }

    /**
     * Run ONE score read and fail closed if the statement did not run.
     *
     * `$wpdb->get_row()` returns null both for "this page has no score row"
     * and for "the statement failed" — a rejected `FOR SHARE`, a lock-wait
     * timeout, a dropped connection. Treating the second as the first is what
     * wrote neutral defaults over every page's real score. `last_error` is the
     * only thing that separates them.
     *
     * Scoping — an error can only be THIS read's error:
     *   - it is cleared here immediately before the read, so a stale error
     *     left by an unrelated earlier query cannot be attributed to it
     *     (WordPress's own `wpdb::query()` also `flush()`es it first);
     *   - an empty statement (prepare() refused to build it) is a failure in
     *     its own right, because WordPress returns null for it WITHOUT setting
     *     any error.
     *
     * Same idiom as RateLimitRepository::getBucketCount(). Deliberately not
     * GuardsReadFailures: that trait logs every failed read with the driver's
     * error text, and a batch over thousands of pages must log once.
     *
     * @param  string|null $sql        the prepared statement
     * @param  string      $reasonCode one of the FAILURE_* codes
     * @return \stdClass|null the score row (OBJECT output), or null when there
     *                        genuinely is none
     * @throws RepositoryException carrying only $reasonCode
     */
    private static function readScoreRowOrThrow(?string $sql, string $reasonCode): ?\stdClass
    {
        global $wpdb;

        if ($sql === null || $sql === '') {
            throw new RepositoryException($reasonCode);
        }

        $wpdb->last_error = '';
        $row = $wpdb->get_row($sql);

        // Local copy + strlen: PHPStan's wpdb stub narrows last_error to the
        // literal '' (see RateLimitRepository::getBucketCount()).
        $lastError = (string) $wpdb->last_error;
        if (strlen($lastError) > 0) {
            throw new RepositoryException($reasonCode);
        }

        return $row instanceof \stdClass ? $row : null;
    }

    /**
     * Bulk-sync all published pages. Intended for cron or WP-CLI.
     *
     * One broken page never stops the rest: each page is synced on its own
     * and a failure is COUNTED, not thrown. The run ends with ONE summary line
     * carrying only numeric counts and bounded reason codes — no page ids, no
     * SQL, no scores — so a systematic failure costs one log line, not one per
     * page. (It used to be 5,688 SQL errors a day, all reported as success.)
     *
     * @return int Number of pages that ACTUALLY synced — failures excluded.
     */
    public function syncAll(int $batchSize = 200): int
    {
        global $wpdb;

        $synced = 0;
        $failed = 0;
        /** @var array<string, int> $failureReasons */
        $failureReasons = [];
        $offset = 0;

        do {
            $wpdb->last_error = '';
            $pageIds = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'peepso-page' AND post_status = 'publish'
                 ORDER BY ID ASC
                 LIMIT %d OFFSET %d",
                $batchSize,
                $offset
            ));

            // A page list that could not be read is not an empty site.
            $listError = (string) $wpdb->last_error;
            if (strlen($listError) > 0) {
                $failureReasons[self::FAILURE_PAGE_LIST_READ] = ($failureReasons[self::FAILURE_PAGE_LIST_READ] ?? 0) + 1;
                break;
            }

            foreach ($pageIds as $id) {
                try {
                    $this->syncPageInternal((int) $id, false);
                    $synced++;
                } catch (\Throwable $e) {
                    $failed++;
                    $reason = self::failureReason($e);
                    $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
                }
            }

            $offset += $batchSize;
        } while (count($pageIds) === $batchSize);

        wp_cache_set('rm_has_data', $synced > 0 ? 1 : 0, self::CACHE_GROUP, 3600);

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            $summary = [
                'pages_synced'    => $synced,
                'pages_failed'    => $failed,
                'failure_reasons' => $failureReasons,
            ];
            if ($failureReasons === []) {
                \BCC\Core\Log\Logger::info('[bcc-trust] read model full sync finished', $summary);
            } else {
                \BCC\Core\Log\Logger::error('[bcc-trust] read model full sync finished with failures', $summary);
            }
        }

        return $synced;
    }

    /**
     * Map a sync failure to a bounded reason code. Anything that is not one
     * of the score-read codes collapses to FAILURE_OTHER, so an exception's
     * free text can never reach the summary.
     */
    private static function failureReason(\Throwable $e): string
    {
        if ($e instanceof RepositoryException
            && in_array($e->getMessage(), [self::FAILURE_AGGREGATE_READ, self::FAILURE_FALLBACK_READ, self::FAILURE_UPSERT], true)
        ) {
            return $e->getMessage();
        }

        return self::FAILURE_OTHER;
    }

    /**
     * Invalidate object cache for a page.
     */
    public function invalidateCache(int $pageId): void
    {
        wp_cache_delete('rm_' . $pageId, self::CACHE_GROUP);

        // Cascade to PageDataLoader's caches. Without this a call to
        // invalidate only the read-model layer leaves PageDataLoader's
        // 5-min `page_<id>` + 10-min `stale_<id>` caches populated with
        // pre-write data — a concurrent reader repopulates `rm_<id>`
        // from that stale upstream and the invalidation is defeated.
        if (class_exists('\\BCC\\Trust\\Core\\Services\\PageDataLoader')) {
            \BCC\Trust\Core\Services\PageDataLoader::bust($pageId);
        }
    }

    // ── Dirty queue (DB-backed) ─────────────────────────────────────────

    /**
     * Mark a page as dirty in the durable DB queue.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE so a mutation that arrives
     * while the cron is mid-sync *bumps* the existing row's created_at to
     * NOW(6) instead of being silently dropped (the previous INSERT IGNORE
     * behaviour permanently lost any second mutation that landed between
     * fetchDirtyPages and removeDirtyPages). The cron's removeDirtyPages
     * caller passes a cutoff equal to the snapshot it took before fetching,
     * so a row whose created_at has been bumped past the cutoff survives
     * cleanup and is re-processed on the next tick.
     */
    public static function enqueueDirty(int $pageId): void
    {
        global $wpdb;
        $table = TableRegistry::dirtyQueue();
        // Use GREATEST so a quarantined row whose created_at is set to a
        // future timestamp (NOW+1h, see quarantineDirtyPage) is NOT pulled
        // back to NOW(6) by an intervening mutation. Without this, any
        // post-quarantine mutation bypassed the cooldown and exposed the
        // pathological page to 5 fresh retries immediately.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (page_id, created_at) VALUES (%d, NOW(6))
             ON DUPLICATE KEY UPDATE created_at = GREATEST(created_at, NOW(6))",
            $pageId
        ));
    }

    /**
     * Fetch the oldest DUE dirty pages (FIFO) up to $limit.
     *
     * The `created_at <= NOW(6)` gate is what makes quarantine work: a
     * quarantined page is pushed to a FUTURE created_at (see
     * quarantineDirtyPage), and without this gate it was re-fetched on the
     * very next tick whenever the queue fit inside one batch — so the 1h/4h/24h
     * backoff never actually happened and the CRITICAL escalation log looped.
     * NOW(6) matches the local clock every other queue write uses (enqueueDirty
     * NOW(6), quarantine, the nowSnapshot cutoff).
     *
     * @return list<\stdClass>  Each row has ->page_id.
     */
    public static function fetchDirtyPages(int $limit): array
    {
        global $wpdb;
        $table = TableRegistry::dirtyQueue();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT page_id FROM {$table}
             WHERE created_at <= NOW(6)
             ORDER BY created_at ASC LIMIT %d",
            $limit
        )) ?: [];
    }

    /**
     * Capture the database's current high-precision timestamp.
     *
     * Callers MUST take this snapshot BEFORE fetchDirtyPages() so the value
     * can later be passed to removeDirtyPages() as the cutoff. Any enqueue
     * that lands AFTER the snapshot (and therefore bumps created_at past
     * the cutoff via the ON DUPLICATE KEY UPDATE in enqueueDirty) will
     * survive the post-sync cleanup and be picked up on the next cycle.
     */
    public static function nowSnapshot(): string
    {
        global $wpdb;
        $now = $wpdb->get_var('SELECT NOW(6)');
        return is_string($now) && $now !== '' ? $now : current_time('mysql');
    }

    /**
     * Remove successfully processed pages from the dirty queue.
     *
     * When $createdAtCutoff is provided, only rows whose created_at is
     * less than or equal to that snapshot are deleted — this is the
     * race-safe variant the cron uses. Concurrent re-enqueues bump
     * created_at past the snapshot via ON DUPLICATE KEY UPDATE, so they
     * survive cleanup and get re-synced on the next tick.
     *
     * When $createdAtCutoff is null, every matching row is deleted
     * unconditionally — only safe when the caller is the sole writer (e.g.
     * a syncAll bulk pass over the entire posts table) or when no further
     * mutations can race the cleanup.
     *
     * @param int[]       $pageIds
     * @param string|null $createdAtCutoff DATETIME(6) string from nowSnapshot().
     */
    public static function removeDirtyPages(array $pageIds, ?string $createdAtCutoff = null): void
    {
        if (empty($pageIds)) {
            return;
        }
        global $wpdb;
        $table        = TableRegistry::dirtyQueue();
        $placeholders = implode(',', array_fill(0, count($pageIds), '%d'));

        if ($createdAtCutoff !== null && $createdAtCutoff !== '') {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table}
                 WHERE page_id IN ({$placeholders})
                   AND created_at <= %s",
                ...array_merge($pageIds, [$createdAtCutoff])
            ));
            return;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE page_id IN ({$placeholders})",
            ...$pageIds
        ));
    }

    /**
     * Quarantine a page: push it to the back of the queue with a delay.
     *
     * Uses NOW(6) (MySQL session tz) — NOT UTC_TIMESTAMP() — so the future
     * cutoff is on the SAME clock as enqueueDirty's NOW(6) and the
     * fetchDirtyPages `<= NOW(6)` gate. With UTC_TIMESTAMP() on a non-UTC
     * MySQL the delay was skewed by the session offset (e.g. a 1h quarantine
     * became 1h + offset), on top of the missing fetch gate.
     */
    public static function quarantineDirtyPage(int $pageId, int $delayHours = 1): void
    {
        global $wpdb;
        $table = TableRegistry::dirtyQueue();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET created_at = DATE_ADD(NOW(6), INTERVAL %d HOUR)
             WHERE page_id = %d",
            $delayHours,
            $pageId
        ));
    }

    // ── Bulk cleanup (user/page deletion) ───────────────────────────────

    /**
     * Soft-delete all votes for a page (set status = 0).
     */
    public static function softDeleteVotesForPage(int $pageId): void
    {
        global $wpdb;
        $table = TableRegistry::votes();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 0 WHERE page_id = %d AND status = 1",
            $pageId
        ));
    }

    /**
     * Hard-delete derived/materialized data for a deleted page.
     *
     * @param int $pageId
     * @param array<string,string> $tableColumnMap  table => column name
     */
    public static function deletePageData(int $pageId, array $tableColumnMap): void
    {
        global $wpdb;
        foreach ($tableColumnMap as $table => $column) {
            $wpdb->delete($table, [$column => $pageId], ['%d']);
        }

        // Audit MED-7: the rm_has_data flag is cached for 3600s, so after
        // a bulk delete that empties the read model, PageDiscoveryService
        // would keep serving legacy-source results for up to an hour.
        // Bust both rm_has_data and the bulk read-model cache so the next
        // query reflects the post-delete state.
        wp_cache_delete('rm_has_data', self::CACHE_GROUP);
        wp_cache_delete('rm_bulk_' . $pageId, self::CACHE_GROUP);
    }

    /**
     * Hard-delete rows across multiple tables for a deleted user.
     *
     * @param int $userId
     * @param array<string,string> $tableColumnMap  table => column name
     */
    public static function deleteUserData(int $userId, array $tableColumnMap): void
    {
        global $wpdb;
        foreach ($tableColumnMap as $table => $column) {
            $wpdb->delete($table, [$column => $userId], ['%d']);
        }
    }
}
