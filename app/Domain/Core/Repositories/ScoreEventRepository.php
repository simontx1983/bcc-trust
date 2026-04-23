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
}
