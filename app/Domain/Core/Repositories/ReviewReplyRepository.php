<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Onchain\Repositories\GuardsReadFailures;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * F-345 — reads for review replies.
 *
 * A reply is a thin `peepso-post` (post_author = the replier, post_content =
 * the reply body) carrying `_bcc_activity_module = 'review_reply'` and
 * `_bcc_activity_sidecar_id = <the review's vote id>`, plus ONE activity row
 * (module 206). That activity is what makes a reply reportable and hideable
 * through the machinery reviews already use: `POST /me/reports` (feed_item,
 * act_id) and HiddenActivityRepository (act-level hide). Writes go through
 * ActivityStreamWriter; this class only reads.
 *
 * Every read returns the reply that OCCUPIES a review's one slot, hidden or
 * not, with a `hidden` flag. Callers decide: a review list renders only
 * `hidden === false` (a hidden reply reads as "no reply"), while can_reply and
 * the write paths treat a hidden reply as still occupying the slot, so a
 * moderator hiding a reply does not let a second one be posted, and its author
 * can still delete it.
 *
 * FAIL-CLOSED: both read through GuardsReadFailures::runRead(…, true), so a
 * failed query throws RepositoryReadFailure instead of answering "no reply".
 * For the write path that matters most: "no reply" would allow a duplicate.
 */
final class ReviewReplyRepository
{
    use GuardsReadFailures;

    public const MODULE_NAME = 'review_reply';

    /** post_meta key: when the reply's author last edited it (UTC MySQL datetime). */
    public const META_EDITED_AT = '_bcc_review_reply_edited_at';

    /** Bound for one batch: a review list page is at most 100 rows. */
    private const MAX_BATCH = 100;

    /**
     * The replies for a page of reviews, keyed by vote id, hidden included.
     *
     * @param list<int> $voteIds
     * @return array<int, array{vote_id: int, post_id: int, act_id: int, author_id: int, body: string, posted_at: string, edited_at: string|null, hidden: bool}>
     * @throws RepositoryReadFailure when the read did not run
     */
    public function findByVoteIds(array $voteIds): array
    {
        $ids = self::positiveIds($voteIds);
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach ($this->read($ids) as $row) {
            // One slot per review; the oldest row wins if a race ever wrote two.
            $out[$row['vote_id']] ??= $row;
        }
        return $out;
    }

    /**
     * The reply occupying this review's slot, hidden or not, or null.
     *
     * Impure: it reads the database, so a later call may see a write.
     *
     * @phpstan-impure
     *
     * @return array{vote_id: int, post_id: int, act_id: int, author_id: int, body: string, posted_at: string, edited_at: string|null, hidden: bool}|null
     * @throws RepositoryReadFailure when the read did not run
     */
    public function findForVote(int $voteId): ?array
    {
        if ($voteId <= 0) {
            return null;
        }
        $rows = $this->read([$voteId]);
        return $rows[0] ?? null;
    }

    /**
     * @param list<int> $voteIds
     * @return list<array{vote_id: int, post_id: int, act_id: int, author_id: int, body: string, posted_at: string, edited_at: string|null, hidden: bool}>
     * @throws RepositoryReadFailure
     */
    private function read(array $voteIds): array
    {
        global $wpdb;

        $activities = $wpdb->prefix . 'peepso_activities';
        $hidden     = TableRegistry::hiddenActivities();
        $hasHidden  = TableRegistry::exists($hidden);
        $moduleId   = PeepSoActivityWriter::getNumericId(self::MODULE_NAME);
        if ($moduleId === null) {
            return [];
        }

        $hiddenExpr = $hasHidden
            ? "EXISTS (SELECT 1 FROM {$hidden} h WHERE h.act_id = a.act_id)"
            : '0';

        $placeholders = implode(',', array_fill(0, count($voteIds), '%s'));
        $args         = array_map('strval', $voteIds);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT CAST(pm_sc.meta_value AS UNSIGNED) AS vote_id,
                    p.ID                AS post_id,
                    a.act_id            AS act_id,
                    p.post_author       AS author_id,
                    p.post_content      AS body,
                    p.post_date_gmt     AS posted_at,
                    pm_ed.meta_value    AS edited_at,
                    {$hiddenExpr}       AS is_hidden
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} pm_mod
                       ON pm_mod.post_id = p.ID
                      AND pm_mod.meta_key = '_bcc_activity_module'
                      AND pm_mod.meta_value = %s
               INNER JOIN {$wpdb->postmeta} pm_sc
                       ON pm_sc.post_id = p.ID
                      AND pm_sc.meta_key = '_bcc_activity_sidecar_id'
               INNER JOIN {$activities} a
                       ON a.act_external_id = p.ID
                      AND a.act_module_id = %d
               LEFT JOIN {$wpdb->postmeta} pm_ed
                      ON pm_ed.post_id = p.ID
                     AND pm_ed.meta_key = %s
              WHERE p.post_type = 'peepso-post'
                AND p.post_status = 'publish'
                AND pm_sc.meta_value IN ({$placeholders})
              ORDER BY p.ID ASC
              LIMIT %d",
            self::MODULE_NAME,
            $moduleId,
            self::META_EDITED_AT,
            ...array_merge($args, [self::MAX_BATCH * 2])
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        /** @var list<object{vote_id: int|numeric-string, post_id: int|numeric-string, act_id: int|numeric-string, author_id: int|numeric-string, body: string, posted_at: string, edited_at: string|null, is_hidden: int|numeric-string}> $rows */
        $rows = self::runRead((string) $sql, __FUNCTION__, true);

        $out = [];
        foreach ($rows as $row) {
            $editedAt = isset($row->edited_at) && is_string($row->edited_at) && $row->edited_at !== ''
                ? $row->edited_at
                : null;
            $out[] = [
                'vote_id'     => (int) $row->vote_id,
                'post_id'     => (int) $row->post_id,
                'act_id'      => (int) $row->act_id,
                'author_id'   => (int) $row->author_id,
                'body'        => (string) $row->body,
                'posted_at'   => (string) $row->posted_at,
                'edited_at'   => $editedAt,
                'hidden'      => (int) ($row->is_hidden ?? 0) === 1,
            ];
        }
        return $out;
    }

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    private static function positiveIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $i = (int) $id;
            if ($i > 0) {
                $out[$i] = $i;
            }
            if (count($out) >= self::MAX_BATCH) {
                break;
            }
        }
        return array_values($out);
    }
}
