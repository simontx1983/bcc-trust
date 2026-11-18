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
 * A review can carry MORE THAN ONE reply over time (the hand-over rule, user
 * decision 2026-11-17): when the page changes hands, the old author's reply
 * stops being the page's response and the current party may write their own.
 * So reads return EVERY reply on a review, hidden included, each with a
 * `hidden` flag, and ReviewReplyService decides which one is the page's
 * response (the one whose author currently answers for the page). Each author
 * holds at most one reply per review.
 *
 * FAIL-CLOSED: every read goes through GuardsReadFailures::runRead(…, true),
 * so a failed query throws RepositoryReadFailure instead of answering "no
 * reply". A read that hits its row bound throws too: a truncated result could
 * miss the current reply, and "no reply" would offer a duplicate.
 */
final class ReviewReplyRepository
{
    use GuardsReadFailures;

    public const MODULE_NAME = 'review_reply';

    /** post_meta key: when the reply's author last edited it (UTC MySQL datetime). */
    public const META_EDITED_AT = '_bcc_review_reply_edited_at';

    /** Bound for one batch: a review list page is at most 100 rows. */
    private const MAX_BATCH = 100;

    /** Row bound per review: hand-overs are rare, so a few replies at most. */
    private const MAX_REPLIES_PER_REVIEW = 4;

    /**
     * Every reply on a page of reviews, grouped by vote id, oldest first,
     * hidden included.
     *
     * @param list<int> $voteIds
     * @return array<int, list<array{vote_id: int, post_id: int, act_id: int, author_id: int, body: string, posted_at: string, edited_at: string|null, hidden: bool}>>
     * @throws RepositoryReadFailure when the read did not run or hit its bound
     */
    public function findByVoteIds(array $voteIds): array
    {
        $ids = self::positiveIds($voteIds);
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach ($this->read($ids, null) as $row) {
            $out[$row['vote_id']][] = $row;
        }
        return $out;
    }

    /**
     * This author's reply on this review, hidden or not, or null.
     *
     * Impure: it reads the database, so a later call may see a write.
     *
     * @phpstan-impure
     *
     * @return array{vote_id: int, post_id: int, act_id: int, author_id: int, body: string, posted_at: string, edited_at: string|null, hidden: bool}|null
     * @throws RepositoryReadFailure when the read did not run or hit its bound
     */
    public function findForVoteByAuthor(int $voteId, int $authorId): ?array
    {
        if ($voteId <= 0 || $authorId <= 0) {
            return null;
        }
        $rows = $this->read([$voteId], $authorId);
        return $rows[0] ?? null;
    }

    /**
     * @param list<int> $voteIds
     * @return list<array{vote_id: int, post_id: int, act_id: int, author_id: int, body: string, posted_at: string, edited_at: string|null, hidden: bool}>
     * @throws RepositoryReadFailure
     */
    private function read(array $voteIds, ?int $authorId): array
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
        $authorClause = $authorId !== null ? 'AND p.post_author = %d' : '';
        if ($authorId !== null) {
            $args[] = (string) $authorId;
        }
        $limit = count($voteIds) * self::MAX_REPLIES_PER_REVIEW;

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
                {$authorClause}
              ORDER BY p.ID ASC
              LIMIT %d",
            self::MODULE_NAME,
            $moduleId,
            self::META_EDITED_AT,
            ...array_merge($args, [$limit + 1])
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        /** @var list<object{vote_id: int|numeric-string, post_id: int|numeric-string, act_id: int|numeric-string, author_id: int|numeric-string, body: string, posted_at: string, edited_at: string|null, is_hidden: int|numeric-string}> $rows */
        $rows = self::runRead((string) $sql, __FUNCTION__, true);

        // One row past the bound means the answer may be incomplete: refuse
        // it rather than risk missing the reply that holds the slot.
        if (count($rows) > $limit) {
            throw new RepositoryReadFailure(self::class, __FUNCTION__, 'reply read exceeded its row bound');
        }

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
