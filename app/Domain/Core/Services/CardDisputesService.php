<?php
/**
 * Card Disputes Service — composes the §V1.5
 * /entities/{kind}/{id}/disputes paginated list for the entity-profile
 * Disputes tab.
 *
 * Sibling to {@see UserDisputesService}: user-side query is "disputes
 * this user signed" (filter by `flagger_user_id`); card-side query is
 * "open disputes filed against this entity" (filter by `vote.page_id`,
 * V1 surfaces only `status=0` open rows).
 *
 * Each row carries the dispute body + posted-at + a nested `flagger`
 * MemberSummary (the user who opened the dispute), hydrated through
 * the shared {@see MemberSummaryPrefetcher}.
 *
 * Privacy: entity disputes are public — adversarial-signal visibility
 * is intentional per §D5 ("disputes are evidence; entity profile
 * surfaces them publicly so viewers can weigh the warning"). No
 * privacy gate.
 *
 * @package BCC\Trust\Core\Services
 * @since 2026-05-14 (Phase 2 entity tab parity)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\FlagsRepository;
use BCC\Trust\Core\Support\MemberSummaryPrefetcher;

if (!defined('ABSPATH')) {
    exit;
}

final class CardDisputesService
{
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE     = 50;

    public function __construct(
        private readonly FlagsRepository $flagsRepository
    ) {
    }

    /**
     * @return array{
     *   items: list<array{
     *     id: int,
     *     body: string,
     *     posted_at_label: string,
     *     flagger: array<string, mixed>
     *   }>,
     *   pagination: array{
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     total_pages: int
     *   }
     * }
     */
    public function getDisputes(
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
        $total  = $this->flagsRepository->countByPageId($pageId);
        if ($total === 0) {
            return self::emptyPage($page, $perPage);
        }

        $rows = $this->flagsRepository->findByPageIdPaginated($pageId, $perPage, $offset);
        if ($rows === []) {
            return self::emptyPage($page, $perPage);
        }

        $flaggerIds = [];
        foreach ($rows as $row) {
            $uid = (int) $row->flagger_user_id;
            if ($uid > 0) {
                $flaggerIds[] = $uid;
            }
        }
        $flaggerIds = array_values(array_unique($flaggerIds));
        $prefetched = $flaggerIds === [] ? null : MemberSummaryPrefetcher::primeFor($flaggerIds);

        $userView = Plugin::instance()->userViewService();
        $now      = time();
        $items    = [];
        foreach ($rows as $row) {
            $flaggerId = (int) $row->flagger_user_id;
            if ($flaggerId <= 0) {
                continue;
            }
            $flagger = $userView->getSummary($flaggerId, $viewerId, $prefetched);
            if ($flagger === null) {
                // Flagger no longer exists in wp_users — skip so a
                // ghost-signed dispute doesn't render anonymously.
                continue;
            }

            $items[] = [
                'id'              => (int) $row->id,
                'body'            => trim((string) $row->reason),
                'posted_at_label' => UserReviewsService::relativeLabel((string) $row->created_at, $now),
                'flagger'         => $flagger,
            ];
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
     * @return array{
     *   items: list<array{
     *     id: int,
     *     body: string,
     *     posted_at_label: string,
     *     flagger: array<string, mixed>
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
