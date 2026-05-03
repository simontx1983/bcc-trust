<?php
/**
 * User Reviews Service — composes the §V1.5 /users/:handle/reviews
 * paginated list. Mirrors MemberProfileComposer's `reviews` field
 * shape so the frontend ReviewsPanel renders the same MemberReview[]
 * regardless of whether it came pre-baked on the profile load or
 * lazy-fetched on tab open.
 *
 * Privacy honouring: when the target user has `reviews_hidden=true`
 * AND the viewer isn't the owner, we return an empty list with
 * `hidden: true`. The frontend's ProfileTabs already intercepts the
 * hidden tab before mounting ReviewsPanel; this is defense-in-depth
 * so a direct API call can't bypass the toggle.
 *
 * Out of scope (V1):
 *   - Filtering by grade or page kind
 *   - Edit / delete (separate composer endpoint)
 *   - Embedded card thumbnails (the panel renders a subject_handle
 *     link; the deep navigation lands on the full page)
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §V1.5 lazy-load profile tabs)
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Support\PrivacySettings;

if (!defined('ABSPATH')) {
    exit;
}

final class UserReviewsService
{
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE     = 50;

    /**
     * vote_type → grade key mapping. Matches PostsService's inverse
     * map (REVIEW_GRADE_TO_VOTE_TYPE) so a review written via the
     * composer round-trips with the same grade label.
     *
     * @var array<int, string>
     */
    private const VOTE_TYPE_TO_GRADE = [
        1  => 'A',
        0  => 'B',
        -1 => 'C',
    ];

    /**
     * Page post_type → §A2 scope label rendered above the subject.
     *
     * @var array<string, string>
     */
    private const PAGE_TYPE_TO_SCOPE = [
        'peepso-page' => 'PAGE',
    ];

    public function __construct(
        private readonly VoteRepository $voteRepository
    ) {
    }

    /**
     * Return paginated reviews authored by $userId.
     *
     * @return array{
     *   items: list<array{
     *     id: int,
     *     grade: string,
     *     subject: string,
     *     subject_handle: string,
     *     text: string,
     *     scope_label: string,
     *     posted_at_label: string
     *   }>,
     *   pagination: array{
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     total_pages: int
     *   },
     *   hidden: bool
     * }
     */
    public function getReviews(
        int $userId,
        int $viewerId,
        int $page = 1,
        int $perPage = self::DEFAULT_PAGE_SIZE
    ): array {
        if ($userId <= 0) {
            return self::emptyPage($page, $perPage, false);
        }

        $isSelf = $viewerId > 0 && $viewerId === $userId;
        if (!$isSelf && PrivacySettings::flag($userId, 'reviews_hidden')) {
            return self::emptyPage($page, $perPage, true);
        }

        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));
        $offset  = ($page - 1) * $perPage;

        $total = $this->voteRepository->countByVoter($userId);
        if ($total === 0) {
            return self::emptyPage($page, $perPage, false);
        }

        $rows = $this->voteRepository->findByVoterPaginated($userId, $perPage, $offset);

        $items = [];
        $now   = time();
        foreach ($rows as $row) {
            $items[] = self::shapeReview($row, $now);
        }

        return [
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
            'hidden' => false,
        ];
    }

    /**
     * @param object{
     *   id: int|numeric-string,
     *   page_id: int|numeric-string,
     *   vote_type: int|numeric-string,
     *   explanation: string|null,
     *   reason: string|null,
     *   created_at: string,
     *   page_title: string|null,
     *   page_name: string|null,
     *   page_type: string|null
     * } $row
     * @return array{
     *   id: int,
     *   grade: string,
     *   subject: string,
     *   subject_handle: string,
     *   text: string,
     *   scope_label: string,
     *   posted_at_label: string
     * }
     */
    private static function shapeReview(object $row, int $now): array
    {
        $voteType = (int) $row->vote_type;
        $grade    = self::VOTE_TYPE_TO_GRADE[$voteType] ?? 'B';

        $explanation = $row->explanation;
        $body = is_string($explanation) ? trim($explanation) : '';
        // Bare-vote rows (no long-form explanation) fall back to the
        // 100-char `reason` slug so the panel doesn't show empty quotes.
        if ($body === '') {
            $reason = $row->reason;
            $body = is_string($reason) ? trim($reason) : '';
        }

        $subjectTitle = is_string($row->page_title) ? $row->page_title : '';
        $subjectName  = is_string($row->page_name)  ? $row->page_name  : '';
        $pageType     = is_string($row->page_type)  ? $row->page_type  : '';

        return [
            'id'              => (int) $row->id,
            'grade'           => $grade,
            'subject'         => $subjectTitle !== '' ? $subjectTitle : $subjectName,
            'subject_handle'  => $subjectName,
            'text'            => $body,
            'scope_label'     => self::PAGE_TYPE_TO_SCOPE[$pageType] ?? 'PAGE',
            'posted_at_label' => self::relativeLabel((string) $row->created_at, $now),
        ];
    }

    /**
     * "3d ago" / "Jan 2024" style label. Same compact policy as the
     * live-shift panel; relative under 30d, absolute thereafter.
     */
    public static function relativeLabel(string $mysqlTime, int $now): string
    {
        if ($mysqlTime === '' || $mysqlTime === '0000-00-00 00:00:00') {
            return '';
        }
        $epoch = strtotime($mysqlTime . ' UTC');
        if ($epoch === false) {
            return '';
        }
        $diff = max(0, $now - $epoch);
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return ((int) floor($diff / 60)) . 'm ago';
        }
        if ($diff < 86400) {
            return ((int) floor($diff / 3600)) . 'h ago';
        }
        if ($diff < 86400 * 30) {
            return ((int) floor($diff / 86400)) . 'd ago';
        }
        return gmdate('M Y', $epoch);
    }

    /**
     * @return array{
     *   items: list<array{
     *     id: int,
     *     grade: string,
     *     subject: string,
     *     subject_handle: string,
     *     text: string,
     *     scope_label: string,
     *     posted_at_label: string
     *   }>,
     *   pagination: array{
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     total_pages: int
     *   },
     *   hidden: bool
     * }
     */
    private static function emptyPage(int $page, int $perPage, bool $hidden): array
    {
        return [
            'items'      => [],
            'pagination' => [
                'page'        => max(1, $page),
                'per_page'    => max(1, $perPage),
                'total'       => 0,
                'total_pages' => 0,
            ],
            'hidden' => $hidden,
        ];
    }
}
