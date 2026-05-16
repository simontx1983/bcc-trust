<?php
/**
 * Card Reviews Service — composes the §V1.5
 * /entities/{kind}/{id}/reviews paginated list for the entity-profile
 * Reviews tab.
 *
 * Sibling to {@see UserReviewsService}: the user-side query is
 * "reviews this user wrote" (filter by `voter_user_id`); the card-side
 * query is "reviews filed against this entity" (filter by `page_id`).
 *
 * Each row carries the review body + grade + posted-at label PLUS a
 * nested `author` MemberSummary (the user who wrote it). The author
 * hydration goes through the shared {@see MemberSummaryPrefetcher} so
 * we get the same author card the /members directory uses on each row
 * without N+1ing per author.
 *
 * Privacy: entity reviews are public — they're a trust-evaluation
 * signal viewers need regardless of session state. No privacy gate
 * here; the row set is the same for anonymous + logged-in viewers.
 *
 * @package BCC\Trust\Core\Services
 * @since 2026-05-14 (Phase 2 entity tab parity)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Support\MemberSummaryPrefetcher;

if (!defined('ABSPATH')) {
    exit;
}

final class CardReviewsService
{
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE     = 50;

    /**
     * vote_type → grade key. Mirrors UserReviewsService's map so a
     * review written via the composer round-trips with the same grade
     * regardless of which surface lists it.
     *
     * @var array<int, string>
     */
    private const VOTE_TYPE_TO_GRADE = [
        1  => 'A',
        0  => 'B',
        -1 => 'C',
    ];

    public function __construct(
        private readonly VoteRepository $voteRepository
    ) {
    }

    /**
     * @return array{
     *   items: list<array{
     *     id: int,
     *     grade: string,
     *     text: string,
     *     posted_at_label: string,
     *     author: array<string, mixed>
     *   }>,
     *   pagination: array{
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     total_pages: int
     *   }
     * }
     */
    public function getReviews(
        int $pageId,
        int $viewerId,
        int $page = 1,
        int $perPage = self::DEFAULT_PAGE_SIZE
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));

        if ($pageId <= 0) {
            return self::emptyPage($page, $perPage);
        }

        $offset = ($page - 1) * $perPage;
        $total  = $this->voteRepository->countByPageId($pageId);
        if ($total === 0) {
            return self::emptyPage($page, $perPage);
        }

        $rows = $this->voteRepository->findByPageIdPaginated($pageId, $perPage, $offset);
        if ($rows === []) {
            return self::emptyPage($page, $perPage);
        }

        // Hydrate authors via the shared prefetch — same author card
        // shape the /members directory ships, bounded SQL irrespective
        // of `$perPage`.
        $authorIds = [];
        foreach ($rows as $row) {
            $uid = (int) $row->voter_user_id;
            if ($uid > 0) {
                $authorIds[] = $uid;
            }
        }
        $authorIds  = array_values(array_unique($authorIds));
        $prefetched = $authorIds === [] ? null : MemberSummaryPrefetcher::primeFor($authorIds);

        $userView = Plugin::instance()->userViewService();
        $now      = time();
        $items    = [];
        foreach ($rows as $row) {
            $authorId = (int) $row->voter_user_id;
            if ($authorId <= 0) {
                continue;
            }
            $author = $userView->getSummary($authorId, $viewerId, $prefetched);
            if ($author === null) {
                // Author row no longer exists in wp_users (deleted /
                // anonymized). Skip rather than render a faceless
                // review — preserves "this review came from a real
                // operator" framing.
                continue;
            }

            $items[] = self::shapeReview($row, $author, $now);
        }

        return [
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * @param object{
     *   id: int|numeric-string,
     *   voter_user_id: int|numeric-string,
     *   vote_type: int|numeric-string,
     *   explanation: string|null,
     *   reason: string|null,
     *   created_at: string
     * } $row
     * @param array<string, mixed> $author Hydrated MemberSummary.
     * @return array{
     *   id: int,
     *   grade: string,
     *   text: string,
     *   posted_at_label: string,
     *   author: array<string, mixed>
     * }
     */
    private static function shapeReview(object $row, array $author, int $now): array
    {
        $voteType = (int) $row->vote_type;
        $grade    = self::VOTE_TYPE_TO_GRADE[$voteType] ?? 'B';

        $explanation = $row->explanation;
        $body = is_string($explanation) ? trim($explanation) : '';
        if ($body === '') {
            $reason = $row->reason;
            $body = is_string($reason) ? trim($reason) : '';
        }

        return [
            'id'              => (int) $row->id,
            'grade'           => $grade,
            'text'            => $body,
            // Reuse UserReviewsService's static formatter so both
            // surfaces emit identical relative-time labels.
            'posted_at_label' => UserReviewsService::relativeLabel((string) $row->created_at, $now),
            'author'          => $author,
        ];
    }

    /**
     * @return array{
     *   items: list<array{
     *     id: int,
     *     grade: string,
     *     text: string,
     *     posted_at_label: string,
     *     author: array<string, mixed>
     *   }>,
     *   pagination: array{
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     total_pages: int
     *   }
     * }
     */
    private static function emptyPage(int $page, int $perPage): array
    {
        return [
            'items'      => [],
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => 0,
                'total_pages' => 0,
            ],
        ];
    }
}
