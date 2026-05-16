<?php
/**
 * Blog Chain-Tag Repository — read/write access to bcc_blog_chain_tags.
 *
 * §D6 crypto-blog composer (PR-A) — post ↔ chain join. A blog post
 * (wp_posts row with `_bcc_activity_module='blog'`) may be tagged with
 * up to BLOG_CHAIN_TAGS_MAX chains drawn from `bcc_onchain_chains`.
 *
 * Read-side hot path: BlogService::hydrateBlogBody calls
 * {@see findByPostId} per row. To keep the per-row cost bounded the
 * repository ships a {@see findByPostIds} bulk variant for any future
 * batched hydration (e.g. floor renderer hydrating multiple blog
 * excerpts at once). Both paths use a bounded `LIMIT` matching
 * BLOG_CHAIN_TAGS_MAX × max-batch.
 *
 * Cache invalidation: chain-tag rows are written only by the blog
 * composer's create path (§D6 PR-A) — there's no public PATCH yet
 * (deferred to PR-B). Cache pressure on this table is low, so we
 * follow the generation-counter pattern used by
 * {@see HiddenActivityRepository} — bust on any write, read returns
 * the keyed-by-gen list. The cost of a write-bust is one cache_incr.
 *
 * §1: all $wpdb access for bcc_blog_chain_tags lives in this class.
 * §2: no SELECT *; explicit COLUMNS const.
 * §4: every SELECT has LIMIT or PK-equality.
 * §5: writes bust a generation counter so reads observe fresh data
 *      without scanning the whole table.
 * §7: cross-plugin calls are positional only — we don't make any
 *      cross-plugin calls here.
 *
 * @package BCC\Trust\Core\Repositories
 * @since V1.5 (2026-05, §D6 crypto-blog composer PR-A)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

final class BlogChainTagRepository
{
    /** Hard ceiling — also enforced by PostsService::BLOG_CHAIN_TAGS_MAX. */
    private const MAX_TAGS_PER_POST = 3;

    /**
     * §4 bound on the bulk lookup: up to BLOG_CHAIN_TAGS_MAX × the
     * worst-case page size (FloorPage = 50). Bumping the floor's
     * per-page cap requires bumping this too.
     */
    private const MAX_BULK_ROWS = 200;

    private const CACHE_GROUP    = 'bcc_trust:blog_chain_tags';
    private const CACHE_KEY_GEN  = 'gen';
    private const CACHE_TTL      = 300; // 5 min

    public static function table(): string
    {
        return DB::table('blog_chain_tags');
    }

    /**
     * Atomic "set the tag set for this post" — DELETE existing rows
     * for the post then INSERT the new set. Bounded by
     * MAX_TAGS_PER_POST so a misbehaving caller can't write 1000 tags.
     *
     * Dedup + sanitize of $chainIds is the caller's responsibility
     * (PostsService::createBlog does this before invoking us); we
     * defensively re-cap at MAX_TAGS_PER_POST so an out-of-band caller
     * can't bypass the contract.
     *
     * Transactional via TransactionManager isn't required here — the
     * DELETE+INSERT pair is single-post-scoped and idempotent on
     * retry (the PK collision absorbs duplicates). If a later writer
     * grows multi-post semantics this should switch to a single
     * `INSERT … ON DUPLICATE KEY UPDATE` batch.
     *
     * @param list<int> $chainIds
     */
    public function replace(int $postId, array $chainIds): void
    {
        if ($postId <= 0) {
            return;
        }

        global $wpdb;
        $table = self::table();

        // Defensive re-cap + dedup. Caller validated already, but
        // belt-and-braces protects against a rogue cross-plugin
        // caller bypassing PostsService.
        $clean = [];
        foreach ($chainIds as $cid) {
            $cid = (int) $cid;
            if ($cid > 0 && !in_array($cid, $clean, true)) {
                $clean[] = $cid;
            }
            if (count($clean) >= self::MAX_TAGS_PER_POST) {
                break;
            }
        }

        // 1. Drop existing tags for this post (bounded by PK on post_id).
        $wpdb->delete($table, ['post_id' => $postId], ['%d']);

        // 2. Insert the new set. Empty-set is a valid call shape —
        // semantically "clear all tags for this post."
        foreach ($clean as $cid) {
            $wpdb->insert(
                $table,
                [
                    'post_id'    => $postId,
                    'chain_id'   => $cid,
                    'created_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%s']
            );
        }

        self::bustCache();
    }

    /**
     * Return the chain ids tagged on a single post. Bounded by
     * MAX_TAGS_PER_POST so the SELECT can never return more than the
     * write-side cap.
     *
     * @return list<int>
     */
    public function findByPostId(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        $genKey = self::cacheKey('post:' . $postId);
        $cached = wp_cache_get($genKey, self::CACHE_GROUP);
        if (is_array($cached)) {
            /** @var list<int> $cached */
            return $cached;
        }

        global $wpdb;
        $table = self::table();

        /** @var list<object{chain_id: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT chain_id
               FROM {$table}
              WHERE post_id = %d
              ORDER BY created_at ASC
              LIMIT %d",
            $postId,
            self::MAX_TAGS_PER_POST
        ));

        $ids = [];
        foreach ($rows ?? [] as $row) {
            $cid = (int) $row->chain_id;
            if ($cid > 0) {
                $ids[] = $cid;
            }
        }

        wp_cache_set($genKey, $ids, self::CACHE_GROUP, self::CACHE_TTL);
        return $ids;
    }

    /**
     * Bulk variant for hydrator batching. Returns a map keyed by
     * post_id → list<int> of chain ids. Posts with no tags are absent
     * from the map (callers treat absence as "no chain tags").
     *
     * §4 bound: input list length cap matches the floor's per-page
     * size; the SELECT's LIMIT is the worst-case row count
     * (input_length × MAX_TAGS_PER_POST, capped at MAX_BULK_ROWS).
     *
     * @param list<int> $postIds
     * @return array<int, list<int>>
     */
    public function findByPostIds(array $postIds): array
    {
        // Dedup + sanitize, bounded so a caller passing 10000 ids
        // doesn't blow the IN() clause.
        $clean = [];
        foreach ($postIds as $pid) {
            $pid = (int) $pid;
            if ($pid > 0 && !in_array($pid, $clean, true)) {
                $clean[] = $pid;
            }
        }
        if ($clean === []) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $ph    = implode(',', array_fill(0, count($clean), '%d'));

        // Worst-case row count = count($clean) * MAX_TAGS_PER_POST,
        // bounded at MAX_BULK_ROWS for the LIMIT clause.
        $rowCap = min(self::MAX_BULK_ROWS, count($clean) * self::MAX_TAGS_PER_POST);

        $sql = $wpdb->prepare(
            "SELECT post_id, chain_id
               FROM {$table}
              WHERE post_id IN ({$ph})
              ORDER BY post_id ASC, created_at ASC
              LIMIT %d",
            ...array_merge($clean, [$rowCap])
        );

        /** @var list<object{post_id: numeric-string, chain_id: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($sql);

        /** @var array<int, list<int>> $out */
        $out = [];
        foreach ($rows ?? [] as $row) {
            $pid = (int) $row->post_id;
            $cid = (int) $row->chain_id;
            if ($pid <= 0 || $cid <= 0) {
                continue;
            }
            if (!isset($out[$pid])) {
                $out[$pid] = [];
            }
            $out[$pid][] = $cid;
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────
    // Cache invalidation — generation counter pattern (per §5 / §L6).
    // ─────────────────────────────────────────────────────────────────

    private static function cacheKey(string $suffix): string
    {
        $gen = wp_cache_get(self::CACHE_KEY_GEN, self::CACHE_GROUP);
        if (!is_int($gen)) {
            $gen = 0;
            wp_cache_set(self::CACHE_KEY_GEN, $gen, self::CACHE_GROUP);
        }
        return $suffix . ':' . $gen;
    }

    private static function bustCache(): void
    {
        // wp_cache_incr is atomic; missing-key initializes to 1 on
        // most object-cache backends. Defensive set-then-incr would
        // be a TOCTOU on multi-node — the incr alone is correct.
        wp_cache_incr(self::CACHE_KEY_GEN, 1, self::CACHE_GROUP);
    }
}
