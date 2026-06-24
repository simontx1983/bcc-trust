<?php
/**
 * Score Event Repository
 *
 * Records trust score changes for audit trail, dispute evidence,
 * and user-facing score history.
 *
 * @package BCC\Trust\Core\Repositories
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type ScoreEventRow object{
 *     id: int|numeric-string,
 *     page_id: int|numeric-string,
 *     event_type: string,
 *     score_before: float|numeric-string|null,
 *     score_after: float|numeric-string|null,
 *     delta: float|numeric-string|null,
 *     tier_before: string|null,
 *     tier_after: string|null,
 *     reason: string|null,
 *     actor_user_id: int|numeric-string|null,
 *     meta: string|null,
 *     created_at: string
 * }
 */
class ScoreEventRepository
{
    /** @var string */
    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::scoreEvents();
    }

    /**
     * Record a score change event.
     *
     * @param int         $pageId
     * @param string      $eventType   e.g. 'vote_cast', 'vote_removed', 'endorsement_added',
     *                                  'recalculation', 'dispute_resolved', 'moderation'
     * @param float|null  $scoreBefore
     * @param float|null  $scoreAfter
     * @param string|null $tierBefore
     * @param string|null $tierAfter
     * @param string|null $reason      Human-readable reason.
     * @param int|null    $actorUserId Who triggered the change (voter, admin, cron=0).
     * @param array<string, mixed>|null $meta Extra context (vote_id, category_id, etc.)
     */
    public function record(
        int $pageId,
        string $eventType,
        ?float $scoreBefore = null,
        ?float $scoreAfter = null,
        ?string $tierBefore = null,
        ?string $tierAfter = null,
        ?string $reason = null,
        ?int $actorUserId = null,
        ?array $meta = null
    ): void {
        global $wpdb;

        $delta = ($scoreBefore !== null && $scoreAfter !== null)
            ? round($scoreAfter - $scoreBefore, 2)
            : null;

        $wpdb->insert($this->table, [
            'page_id'       => $pageId,
            'event_type'    => $eventType,
            'score_before'  => $scoreBefore,
            'score_after'   => $scoreAfter,
            'delta'         => $delta,
            'tier_before'   => $tierBefore,
            'tier_after'    => $tierAfter,
            'reason'        => $reason ? mb_substr($reason, 0, 255) : null,
            'actor_user_id' => $actorUserId,
            'meta'          => $meta ? wp_json_encode($meta) : null,
        ]);
    }

    /**
     * Recent score events across a SET of pages, since a SQL timestamp.
     *
     * Drives §O2.1 EXTERNAL-slot resolver in HighlightsService — the
     * "something happened to a page you watch" surface. This is the
     * multi-page read seam — the sole read path on this table.
     *
     * Filters:
     *   - `page_id IN ($pageIds)`  bounded by caller (typical: ≤ 500
     *                              from PeepSoPageRepository::getPageIdsOwnedByUsers).
     *   - `created_at > $sinceMysql`  GMT, e.g. 'YYYY-MM-DD HH:MM:SS'.
     *   - `event_type IN ($eventTypes)` when non-empty — typical use is
     *     to whitelist civic-significant types ('vote_cast',
     *     'endorsement_added', 'dispute_resolved') and drop noise
     *     ('recalculation', 'moderation', '_removed' variants).
     *
     * Index used: composite KEY `idx_page_created (page_id, created_at)`
     * for the WHERE; ORDER BY created_at DESC reuses the same composite
     * in reverse-scan mode (MySQL 8.x supports the descending key).
     *
     * Output: joined with `wp_posts` for the page title so the resolver
     * can build the highlight without a second round-trip. The title is
     * exposed as the `page_title` property on each stdClass row.
     *
     * Bounded query, no SELECT *, explicit columns, IN clauses sanitized
     * + sized by caller; defensive caps on $limit (≤ 100) and length of
     * $pageIds applied here regardless.
     *
     * @param list<int>    $pageIds    Bounded set. Empty → empty result.
     * @param string       $sinceMysql 'YYYY-MM-DD HH:MM:SS' GMT.
     * @param int          $limit      Default 24, capped defensively at 100.
     * @param list<string> $eventTypes Optional event_type allow-list.
     * @return list<object>
     */
    public function findForPagesSince(
        array $pageIds,
        string $sinceMysql,
        int $limit = 24,
        array $eventTypes = []
    ): array {
        if ($pageIds === [] || $limit <= 0) {
            return [];
        }

        // Sanitize + dedupe page_ids. Defensive — caller usually bounds.
        $clean = [];
        foreach ($pageIds as $id) {
            $i = (int) $id;
            if ($i > 0) {
                $clean[$i] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $pageIdList = array_keys($clean);
        // Hard cap the IN-clause defensively (caller usually passes ≤ 500).
        if (count($pageIdList) > 1000) {
            $pageIdList = array_slice($pageIdList, 0, 1000);
        }

        $cappedLimit = min(max($limit, 1), 100);

        // Sanitize event-type allow-list.
        $cleanTypes = [];
        foreach ($eventTypes as $t) {
            if (is_string($t) && $t !== '') {
                $cleanTypes[] = $t;
            }
        }

        global $wpdb;
        $idPlaceholders = implode(',', array_fill(0, count($pageIdList), '%d'));

        $params = $pageIdList;
        $eventTypeClause = '';
        if ($cleanTypes !== []) {
            $typePlaceholders = implode(',', array_fill(0, count($cleanTypes), '%s'));
            $eventTypeClause  = " AND se.event_type IN ({$typePlaceholders})";
            $params           = array_merge($params, $cleanTypes);
        }
        $params[] = $sinceMysql;
        $params[] = $cappedLimit;

        $sql = $wpdb->prepare(
            "SELECT se.id, se.page_id, se.event_type,
                    se.score_before, se.score_after, se.delta,
                    se.tier_before, se.tier_after,
                    se.reason, se.actor_user_id, se.meta, se.created_at,
                    p.post_title AS page_title
               FROM {$this->table} AS se
               INNER JOIN {$wpdb->posts} AS p ON p.ID = se.page_id
              WHERE se.page_id IN ({$idPlaceholders})
                {$eventTypeClause}
                AND se.created_at > %s
              ORDER BY se.created_at DESC
              LIMIT %d",
            ...$params
        );

        /** @var list<object>|null $rows */
        $rows = $wpdb->get_results($sql);
        return $rows ?: [];
    }

    /**
     * Recent score events for a SINGLE page, newest first. Drives
     * `progression.trust_score_recent_changes` on the user view-model
     * (§N11) — the "why did my score move" surface.
     *
     * Deliberately has NO `wp_posts` INNER JOIN (unlike findForPagesSince):
     * member self-pages have synthetic page_ids (MemberSelfPageService::
     * ID_BASE + user_id) that are NOT real wp_posts rows, so an inner join
     * would silently drop every self-page event. The caller passes a
     * self-page id; we read straight off the ledger.
     *
     * Bounded: WHERE page_id = %d (single key) + LIMIT (clamped 1–20),
     * fully prepared. Uses idx_page_created (page_id, created_at DESC).
     * Each row exposes `delta`, `reason`, `created_at` (among others).
     *
     * @phpstan-return list<ScoreEventRow>
     */
    public function getRecentForPage(int $pageId, int $limit = 5): array
    {
        if ($pageId <= 0) {
            return [];
        }
        $limit = max(1, min(20, $limit));

        global $wpdb;
        /** @var list<ScoreEventRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, page_id, event_type,
                    score_before, score_after, delta,
                    tier_before, tier_after,
                    reason, actor_user_id, meta, created_at
               FROM {$this->table}
              WHERE page_id = %d
              ORDER BY created_at DESC, id DESC
              LIMIT %d",
            $pageId,
            $limit
        ));
        return $rows ?: [];
    }

    /**
     * Retention sweep: hard-delete score-event rows older than the
     * configured horizon, in bounded batches. This is an audit/debug
     * ledger; reads are all "last N" bounded with no time floor, so aged
     * rows carry no view-model dependency.
     *
     * Mirrors VoteRepository::cleanupOldDeleted (batched, capped, fail-loud).
     * created_at is written by the column's CURRENT_TIMESTAMP default, so
     * the cutoff uses MySQL NOW() to match. Uses idx_created_at.
     *
     * @return int rows deleted
     */
    public function cleanupOld(): int
    {
        global $wpdb;

        $days = defined('BCC_TRUST_CLEANUP_SCORE_EVENTS')
            ? max(1, (int) BCC_TRUST_CLEANUP_SCORE_EVENTS)
            : 90;

        $batchSize     = 5000;
        $maxIterations = 20;
        $total         = 0;

        for ($i = 0; $i < $maxIterations; $i++) {
            $affected = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->table}
                  WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                  LIMIT %d",
                $days,
                $batchSize
            ));

            if ($affected === false) {
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] score_events cleanup DB error', [
                        'iteration' => $i,
                        'total'     => $total,
                        'db_error'  => $wpdb->last_error,
                    ]);
                }
                break;
            }

            $total += (int) $affected;

            if ((int) $affected < $batchSize) {
                break;
            }
        }

        return $total;
    }
}
