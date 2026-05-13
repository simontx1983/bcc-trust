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
     * Get recent score events for a page.
     *
     * @param int $pageId
     * @param int $limit
     * @return list<object>
     */
    public function getForPage(int $pageId, int $limit = 20): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, page_id, event_type, score_before, score_after, delta,
                    tier_before, tier_after, reason, actor_user_id, meta, created_at
             FROM {$this->table}
             WHERE page_id = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $pageId,
            $limit
        )) ?: [];
    }

    /**
     * Get recent events for a user (pages they affected by voting/endorsing).
     *
     * @param int $actorUserId
     * @param int $limit
     * @return list<object>
     */
    public function getForActor(int $actorUserId, int $limit = 20): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, page_id, event_type, score_before, score_after, delta,
                    tier_before, tier_after, reason, actor_user_id, meta, created_at
             FROM {$this->table}
             WHERE actor_user_id = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $actorUserId,
            $limit
        )) ?: [];
    }

    /**
     * Get tier change events for a page (only events where tier actually changed).
     *
     * @param int $pageId
     * @param int $limit
     * @return list<object>
     */
    public function getTierChanges(int $pageId, int $limit = 10): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, page_id, event_type, score_before, score_after, delta,
                    tier_before, tier_after, reason, created_at
             FROM {$this->table}
             WHERE page_id = %d AND tier_before != tier_after AND tier_before IS NOT NULL
             ORDER BY created_at DESC
             LIMIT %d",
            $pageId,
            $limit
        )) ?: [];
    }

    /**
     * Recent score events across a SET of pages, since a SQL timestamp.
     *
     * Drives §O2.1 EXTERNAL-slot resolver in HighlightsService — the
     * "something happened to a page you watch" surface. Existing single-
     * page reads (`getForPage`, `getTierChanges`) don't compose
     * cross-page; this is the multi-page read seam.
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
}
