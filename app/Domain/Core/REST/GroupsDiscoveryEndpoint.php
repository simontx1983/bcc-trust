<?php
/**
 * GroupsDiscoveryEndpoint — handles GET /bcc/v1/groups.
 *
 * Cross-kind discovery list. Sort key (per plan):
 *   verified DESC, heat_score DESC, member_count DESC
 *
 * Verified groups (those with `_bcc_group_kind = 'holders'`) rank
 * above non-verified — but active verified groups beat sleepy ones,
 * so a "verified but dead" community doesn't dominate the discovery
 * surface.
 *
 * Filters:
 *   ?verified=1 → only on-chain verified groups
 *   ?page / ?page_size → standard pagination (default 20, max 50)
 *
 * Privacy: secret groups never appear here regardless of viewer.
 * Closed groups appear but content is private at PeepSo's layer.
 *
 * Each item carries the same `verification` + `activity` blocks the
 * holder-groups endpoint emits, so the frontend can render heat
 * indicators and the "On-Chain Verified" badge consistently.
 *
 * Cache strategy: 60s public cache. Heat is recomputed on each fetch
 * so cold groups warming up surface within a minute.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 (Verified-has-teeth wiring)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\ValueObjects\GroupContext;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupsDiscoveryEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE     = 50;

    /** Limit on the candidate pool fetched + sorted in PHP before pagination. */
    private const CANDIDATE_LIMIT   = 500;

    public static function register(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/groups',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'getList'],
                'permission_callback' => '__return_true',
                'args' => [
                    'verified'  => ['sanitize_callback' => 'absint'],
                    'page'      => ['sanitize_callback' => 'absint'],
                    'page_size' => ['sanitize_callback' => 'absint'],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getList(WP_REST_Request $request): WP_REST_Response
    {
        $verifiedOnly = (int) $request->get_param('verified') === 1;
        $page         = max(1, (int) $request->get_param('page') ?: 1);
        $pageSize     = (int) $request->get_param('page_size') ?: self::DEFAULT_PAGE_SIZE;
        $pageSize     = max(1, min(self::MAX_PAGE_SIZE, $pageSize));

        $candidateIds = PeepSoGroupRepository::listBrowsableGroupIds(self::CANDIDATE_LIMIT);
        if ($candidateIds === []) {
            return $this->respond([], $page, $pageSize, 0);
        }

        $resolver = Plugin::instance()->groupContextResolver();
        $contexts = $resolver->forManyGroups($candidateIds);

        if ($verifiedOnly) {
            $contexts = array_filter(
                $contexts,
                static fn(GroupContext $c): bool => $c->isVerified()
            );
            if ($contexts === []) {
                return $this->respond([], $page, $pageSize, 0);
            }
        }

        $orderedIds  = array_keys($contexts);
        $displays    = PeepSoGroupRepository::findManyByIds($orderedIds);
        $activity    = Plugin::instance()->groupActivityHeatService()->forGroups($orderedIds);

        // Build sortable rows.
        $rows = [];
        foreach ($contexts as $groupId => $ctx) {
            $display = $displays[$groupId] ?? null;
            $heat    = $activity[$groupId] ?? [
                'posts_last_7d'    => 0,
                'last_activity_at' => null,
                'heat'             => 'cold',
            ];
            $rows[] = [
                'group_id'         => $groupId,
                'context'          => $ctx,
                'display'          => $display,
                'activity'         => $heat,
                'sort_verified'    => $ctx->isVerified() ? 1 : 0,
                'sort_heat'        => (int) $heat['posts_last_7d'],
                'sort_member_cnt'  => $display !== null ? (int) $display->member_count : 0,
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                if ($a['sort_verified'] !== $b['sort_verified']) {
                    return $b['sort_verified'] <=> $a['sort_verified'];
                }
                if ($a['sort_heat'] !== $b['sort_heat']) {
                    return $b['sort_heat'] <=> $a['sort_heat'];
                }
                return $b['sort_member_cnt'] <=> $a['sort_member_cnt'];
            }
        );

        $total      = count($rows);
        $offset     = ($page - 1) * $pageSize;
        $pagedRows  = array_slice($rows, $offset, $pageSize);

        $items = [];
        foreach ($pagedRows as $row) {
            $items[] = $this->composeItem($row['context'], $row['display'], $row['activity']);
        }

        return $this->respond($items, $page, $pageSize, $total);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function respond(array $items, int $page, int $pageSize, int $total): WP_REST_Response
    {
        $response = ApiResponse::ok([
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'page_size'   => $pageSize,
                'total'       => $total,
                'total_pages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
            ],
        ]);
        $response->header('Cache-Control', 'public, max-age=60');
        return $response;
    }

    /**
     * @param object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}|null $display
     * @param array{posts_last_7d: int, last_activity_at: string|null, heat: string} $activity
     * @return array<string, mixed>
     */
    private function composeItem(GroupContext $ctx, ?object $display, array $activity): array
    {
        return [
            'group_id'     => $ctx->groupId,
            'slug'         => $display !== null ? (string) $display->post_name : '',
            'name'         => $display !== null ? (string) $display->post_title : '',
            'type'         => $ctx->type->value,
            'member_count' => $display !== null ? (int) $display->member_count : 0,
            'privacy'      => $ctx->privacy->value,
            'verification' => $ctx->verification?->toApiResponse(),
            'activity'     => $activity,
        ];
    }
}
