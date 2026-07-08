<?php
/**
 * User Disputes Service — composes the §V1.5 /users/:handle/disputes
 * paginated list. Mirrors MemberProfileComposer's `disputes` field
 * shape so the frontend DisputesPanel renders the same MemberDispute[]
 * regardless of whether it came pre-baked or lazy-fetched.
 *
 * Data source (2026-07-08): the LIVE `bcc_disputes` panel-adjudication
 * table, read reporter-keyed via
 * {@see \BCC\Trust\Disputes\Repositories\DisputeRepository::getByReporterPaginated}.
 * A dispute is filed by the page owner contesting a downvote on a page
 * they own (`reporter_id` = the filer). For a member, disputes key on
 * their self-page — `reporter_id` = the member's user id — so the
 * per-user query is direct.
 *
 * This replaced the retired `bcc_trust_flags` / FlagsRepository path,
 * which was write-dead (its writer was the deleted `report_vote`
 * route), so the tab always rendered empty. The response shape is
 * unchanged — only the source table moved.
 *
 * Privacy honouring: same defense-in-depth pattern as UserReviewsService
 * — when `disputes_hidden=true` AND viewer ≠ owner, return empty +
 * `hidden: true`.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §V1.5 lazy-load profile tabs)
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Support\PrivacySettings;
use BCC\Trust\Disputes\Domain\DisputeStatus;
use BCC\Trust\Disputes\DTO\DisputeDetailDTO;
use BCC\Trust\Disputes\Repositories\DisputeRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class UserDisputesService
{
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE     = 50;

    /**
     * `bcc_disputes.status` → frontend status enum + label.
     *
     * The FE `MemberDispute.status` enum is the stable 3-value contract
     * (open|resolved|dismissed) driving the status-pill colour; the
     * label carries the richer real outcome. A dispute is the reporter's
     * OWN filing, so accepted/rejected are meaningful to them:
     *   - reviewing         → open      · "OPEN"        (panel still deliberating)
     *   - accepted          → resolved  · "ACCEPTED"    (downvote invalidated)
     *   - rejected          → resolved  · "REJECTED"    (downvote upheld)
     *   - timeout_no_quorum → resolved  · "NOT DECIDED" (TTL, no penalty)
     *   - dismissed         → dismissed · "DISMISSED"
     * Unknown values fall back to "open" so a row never renders empty.
     *
     * @var array<string, array{status: 'open'|'resolved'|'dismissed', label: string}>
     */
    private const STATUS_MAP = [
        DisputeStatus::REVIEWING         => ['status' => 'open',      'label' => 'OPEN'],
        DisputeStatus::ACCEPTED          => ['status' => 'resolved',  'label' => 'ACCEPTED'],
        DisputeStatus::REJECTED          => ['status' => 'resolved',  'label' => 'REJECTED'],
        DisputeStatus::TIMEOUT_NO_QUORUM => ['status' => 'resolved',  'label' => 'NOT DECIDED'],
        DisputeStatus::DISMISSED         => ['status' => 'dismissed', 'label' => 'DISMISSED'],
    ];

    /**
     * Return paginated disputes filed by $userId.
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

        $total = DisputeRepository::countByReporter($userId);
        if ($total === 0) {
            return self::emptyPage($page, $perPage, false);
        }

        $rows = DisputeRepository::getByReporterPaginated($userId, $perPage, $offset);

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
    private static function shapeDispute(DisputeDetailDTO $row, int $now): array
    {
        $mapped = self::STATUS_MAP[$row->status] ?? self::STATUS_MAP[DisputeStatus::REVIEWING];

        $subjectTitle = $row->page_title !== null ? trim($row->page_title) : '';

        return [
            'id'              => $row->id,
            'status'          => $mapped['status'],
            'status_label'    => $mapped['label'],
            'subject'         => $subjectTitle !== '' ? $subjectTitle : 'Page removed',
            'body'            => $row->reason,
            // Disputes are always page-scoped (they contest a vote on a
            // page). Kept as a constant so the tab stays in lockstep with
            // UserReviewsService's scope vocabulary.
            'scope_label'     => 'PAGE',
            'posted_at_label' => UserReviewsService::relativeLabel($row->created_at, $now),
        ];
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
