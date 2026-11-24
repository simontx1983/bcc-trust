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
use BCC\Trust\Core\Support\PageAccess;

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
     * @param string|null $cardKind `validator` / `project` / `creator` for a
     *        card page's tab, whose replies may be shown as the page's own
     *        (see withReply()); null for a member's received reviews
     * @return array{
     *   items: list<array<string, mixed>>,
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
        int $perPage = self::DEFAULT_PAGE_SIZE,
        ?string $cardKind = null
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

        // F-345: each review's reply and the viewer's can_reply, one batched
        // read for the page. Reply authors join the same author prefetch.
        // A card page's reply whose author's profile is closed to this viewer
        // is shown as the page's own response ($cardKind: the three card
        // kinds only; a member's received reviews pass null).
        $replies = Plugin::instance()->reviewReplyService()->forReviews(
            $viewerId,
            array_map(
                static fn (object $row): array => [
                    'id'            => (int) $row->id,
                    'page_id'       => $pageId,
                    'voter_user_id' => (int) $row->voter_user_id,
                ],
                $rows
            ),
            $cardKind !== null
        );
        foreach (ReviewReplyService::replyAuthorIds($replies) as $replyAuthorId) {
            $authorIds[] = $replyAuthorId;
        }
        $pageIdentity = $cardKind !== null && self::anyAsPage($replies)
            ? self::pageIdentity($cardKind, $pageId, $viewerId)
            : null;

        $authorIds  = array_values(array_unique($authorIds));
        $prefetched = $authorIds === [] ? null : MemberSummaryPrefetcher::primeFor($authorIds, $viewerId);

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

            $items[] = self::withReply(
                self::shapeReview($row, $author, $now),
                $replies[(int) $row->id] ?? null,
                $viewerId,
                $prefetched,
                $pageIdentity
            );
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
     * F-345: add `reply` (null when none, or hidden), `viewer_stale_reply`
     * (the viewer's own reply after a hand-over, else null) and
     * `permissions.can_reply` to a shaped review row.
     *
     * A reply forReviews() marked `as_page` is shown as the PAGE's response
     * (ReviewReplyService::asPage()), with $page, the reviewed page as its
     * Card shows it. Without $page (the member's tab, or a page that did not
     * resolve) it is not shown.
     *
     * @param array<string, mixed> $item
     * @param array{reply: array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string, permissions: array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>, as_page?: true}|null, viewer_stale_reply: array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string, permissions: array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>}|null, can_reply: array{allowed: bool, unlock_hint: string|null, reason_code: string|null}}|null $bundle
     * @param array<string, mixed>|null $prefetched
     * @param array{id: int, card_kind: string, name: string, handle: string, avatar_url: string}|null $page
     * @return array<string, mixed>
     */
    public static function withReply(array $item, ?array $bundle, int $viewerId, ?array $prefetched, ?array $page = null): array
    {
        $reply = null;
        if ($bundle !== null && $bundle['reply'] !== null) {
            if (isset($bundle['reply']['as_page'])) {
                $reply = $page !== null ? ReviewReplyService::asPage($bundle['reply'], $page) : null;
            } else {
                $reply = Plugin::instance()->reviewReplyService()->withAuthor($bundle['reply'], $viewerId, $prefetched);
            }
        }

        $item['reply']              = $reply;
        $item['viewer_stale_reply'] = $bundle !== null && $bundle['viewer_stale_reply'] !== null
            ? Plugin::instance()->reviewReplyService()->withAuthor($bundle['viewer_stale_reply'], $viewerId, $prefetched)
            : null;
        $item['permissions'] = [
            'can_reply' => $bundle['can_reply']
                ?? ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'not_applicable'],
        ];
        return $item;
    }

    /**
     * @param array<int, array{reply: array{as_page?: true}|null}> $replies
     */
    private static function anyAsPage(array $replies): bool
    {
        foreach ($replies as $bundle) {
            if ($bundle['reply'] !== null && isset($bundle['reply']['as_page'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * The reviewed page as its own Card shows it to this viewer: the `page`
     * of a reply shown as the page's response. Read from the Card itself,
     * once per list, so it cannot differ from the card the tab sits on.
     * Null (the reply is then not shown) when the viewer may not see the
     * page or its card does not resolve.
     *
     * @return array{id: int, card_kind: string, name: string, handle: string, avatar_url: string}|null
     */
    private static function pageIdentity(string $cardKind, int $pageId, int $viewerId): ?array
    {
        if ($pageId <= 0 || !PageAccess::viewerMaySeeId($pageId, $viewerId)) {
            return null;
        }
        $card = Plugin::instance()->cardViewService()->getCard($cardKind, (string) $pageId, $viewerId);
        if ($card === null) {
            return null;
        }
        $crest = is_array($card['crest'] ?? null) ? $card['crest'] : [];

        return [
            'id'         => (int) ($card['id'] ?? 0),
            'card_kind'  => (string) ($card['card_kind'] ?? $cardKind),
            'name'       => (string) ($card['name'] ?? ''),
            'handle'     => (string) ($card['handle'] ?? ''),
            'avatar_url' => is_string($crest['image_url'] ?? null) ? $crest['image_url'] : '',
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
     *   items: list<array<string, mixed>>,
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
