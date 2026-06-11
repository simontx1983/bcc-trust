<?php
/**
 * Review body hydration for the §F3 feed brain.
 *
 * Extracted verbatim from FeedRankingService (Phase 3.2 split): the
 * per-kind `review` body loader. FeedRankingService remains the
 * orchestrator — it buckets feed items by post_kind and delegates the
 * sidecar reads here.
 *
 * @package BCC\Trust\Core\Services\Feed
 */

namespace BCC\Trust\Core\Services\Feed;

use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Support\PageTypeMap;

if (!defined('ABSPATH')) {
    exit;
}

final class ReviewBodyHydrator
{
    public function __construct(
        private readonly VoteRepository $voteRepo
    ) {
    }

    /**
     * Bulk-load review bodies. Returns map keyed by bcc_trust_votes.id.
     *
     * Body shape (per FeedItemNormalizer's review contract):
     *   {
     *     grade:        'trust' | 'neutral' | 'caution',  // symbolic, not vote_type int
     *     text:         string,                           // the explanation column
     *     page_id:      int,                              // target peepso-page id
     *     page_handle:  string,                           // target page slug
     *     page_name:    string,                           // target page display name
     *     page_kind:    'validator'|'project'|'creator'|'',// drives /v|/p|/c link prefix
     *   }
     *
     * Two queries: votes by id (bulk) + posts by id (WP's get_posts
     * batch). The page lookup is fast — WP's per-process post cache
     * makes repeats free, and the feed page caps at 50 items.
     *
     * @param list<int> $voteIds
     * @return array<int, array<string, mixed>>
     */
    public function loadReviewBodies(array $voteIds): array
    {
        if ($voteIds === []) {
            return [];
        }

        $votes = $this->voteRepo->findManyByIds($voteIds);
        if ($votes === []) {
            return [];
        }

        // Bulk-resolve target page handles. WP's get_posts caches
        // per-process, so per-id lookups are fine after this prime.
        $pageIds = [];
        foreach ($votes as $vote) {
            $pageIds[(int) ($vote->page_id ?? 0)] = true;
        }
        unset($pageIds[0]);
        $pages = [];
        if ($pageIds !== []) {
            /** @var list<\WP_Post> $rows */
            $rows = get_posts([
                'post_type'      => 'any',
                'post__in'       => array_keys($pageIds),
                'posts_per_page' => count($pageIds),
                'post_status'    => 'any',
                // Suppress WP's default ordering so unstable orderings
                // don't shuffle the FK lookup; we re-key by id below.
                'orderby'        => 'post__in',
            ]);
            foreach ($rows as $post) {
                $pages[$post->ID] = $post;
            }
        }

        $bodies = [];
        foreach ($votes as $voteId => $vote) {
            $voteType   = (int) ($vote->vote_type ?? 0);
            $explanation = (string) ($vote->explanation ?? '');
            $pageId     = (int) ($vote->page_id ?? 0);
            $page       = $pages[$pageId] ?? null;

            $pageKind = '';
            if ($page !== null) {
                $rawType = (string) get_post_meta($page->ID, '_bcc_page_type', true);
                if ($rawType !== '') {
                    $pageKind = PageTypeMap::kindForPageType($rawType) ?? '';
                }
            }

            $bodies[$voteId] = [
                'grade'       => self::voteTypeToGrade($voteType),
                'text'        => $explanation,
                'page_id'     => $pageId,
                'page_handle' => $page !== null ? (string) $page->post_name  : '',
                'page_name'   => $page !== null ? (string) $page->post_title : '',
                'page_kind'   => $pageKind,
            ];
        }
        return $bodies;
    }

    /**
     * Convert the integer vote_type stored in bcc_trust_votes back to
     * the symbolic grade key the frontend speaks. Mirror of
     * PostsService::REVIEW_GRADE_TO_VOTE_TYPE.
     */
    private static function voteTypeToGrade(int $voteType): string
    {
        if ($voteType > 0) return 'trust';
        if ($voteType < 0) return 'caution';
        return 'neutral';
    }
}
