<?php
/**
 * User Disputes Service — composes the §V1.5 /users/:handle/disputes
 * paginated list. Mirrors MemberProfileComposer's `disputes` field
 * shape so the frontend DisputesPanel renders the same MemberDispute[]
 * regardless of whether it came pre-baked or lazy-fetched.
 *
 * V1 dispute primitive (§D2 / §B5): `bcc_trust_flags` rows where
 * `flagger_user_id` = the user who signed onto the dispute. The
 * subject is the page being voted on (resolved via flag → vote → page
 * join in FlagsRepository::findByFlagger).
 *
 * Privacy honouring: same defense-in-depth pattern as UserReviewsService
 * — when `disputes_hidden=true` AND viewer ≠ owner, return empty +
 * `hidden: true`.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §V1.5 lazy-load profile tabs)
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\FlagsRepository;
use BCC\Trust\Core\Support\PrivacySettings;

if (!defined('ABSPATH')) {
    exit;
}

final class UserDisputesService
{
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE     = 50;

    /**
     * `bcc_trust_flags.status` (TINYINT) → frontend status enum + label.
     * 0=open, 1=resolved, 2=dismissed (per schema-core.php). Unknown
     * values fall back to "open" so a row never renders with an empty pill.
     *
     * @var array<int, array{status: 'open'|'resolved'|'dismissed', label: string}>
     */
    private const STATUS_MAP = [
        0 => ['status' => 'open',      'label' => 'OPEN'],
        1 => ['status' => 'resolved',  'label' => 'RESOLVED'],
        2 => ['status' => 'dismissed', 'label' => 'DISMISSED'],
    ];

    public function __construct(
        private readonly FlagsRepository $flagsRepository
    ) {
    }

    /**
     * Return paginated disputes signed by $userId.
     *
     * @return array{
     *   items: list<array{
     *     id: int,
     *     status: 'open'|'resolved'|'dismissed',
     *     status_label: string,
     *     subject: string,
     *     body: string,
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
    public function getDisputes(
        int $userId,
        int $viewerId,
        int $page = 1,
        int $perPage = self::DEFAULT_PAGE_SIZE
    ): array {
        if ($userId <= 0) {
            return self::emptyPage($page, $perPage, false);
        }

        $isSelf = $viewerId > 0 && $viewerId === $userId;
        if (!$isSelf && PrivacySettings::flag($userId, 'disputes_hidden')) {
            return self::emptyPage($page, $perPage, true);
        }

        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));
        $offset  = ($page - 1) * $perPage;

        $total = $this->flagsRepository->countByFlagger($userId);
        if ($total === 0) {
            return self::emptyPage($page, $perPage, false);
        }

        $rows = $this->flagsRepository->findByFlagger($userId, $perPage, $offset);

        $items = [];
        $now   = time();
        foreach ($rows as $row) {
            $items[] = self::shapeDispute($row, $now);
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
     *   vote_id: int|numeric-string,
     *   reason: string,
     *   status: int|numeric-string,
     *   created_at: string,
     *   page_id: int|numeric-string|null,
     *   page_title: string|null,
     *   page_name: string|null,
     *   page_type: string|null
     * } $row
     * @return array{
     *   id: int,
     *   status: 'open'|'resolved'|'dismissed',
     *   status_label: string,
     *   subject: string,
     *   body: string,
     *   scope_label: string,
     *   posted_at_label: string
     * }
     */
    private static function shapeDispute(object $row, int $now): array
    {
        $statusInt = (int) $row->status;
        $mapped    = self::STATUS_MAP[$statusInt] ?? self::STATUS_MAP[0];

        $subjectTitle = is_string($row->page_title) ? trim($row->page_title) : '';
        $subjectName  = is_string($row->page_name)  ? trim($row->page_name)  : '';
        $pageType     = is_string($row->page_type)  ? $row->page_type        : '';

        return [
            'id'              => (int) $row->id,
            'status'          => $mapped['status'],
            'status_label'    => $mapped['label'],
            'subject'         => $subjectTitle !== ''
                ? $subjectTitle
                : ($subjectName !== '' ? $subjectName : 'Page removed'),
            'body'            => $row->reason,
            'scope_label'     => self::scopeLabel($pageType),
            'posted_at_label' => UserReviewsService::relativeLabel((string) $row->created_at, $now),
        ];
    }

    private static function scopeLabel(string $pageType): string
    {
        // Page types map to the same scope strings UserReviewsService uses
        // — keeping them in lockstep avoids drift when new entity types
        // (e.g., 'peepso-page-creator') land on the §G2 directory.
        return $pageType !== '' ? 'PAGE' : 'UNKNOWN';
    }

    /**
     * @return array{
     *   items: list<array{
     *     id: int,
     *     status: 'open'|'resolved'|'dismissed',
     *     status_label: string,
     *     subject: string,
     *     body: string,
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
