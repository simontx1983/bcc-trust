<?php
/**
 * HolderGroupsEndpoint — handles /bcc/v1/me/holder-groups.
 *
 * Five routes:
 *
 *   GET   /me/holder-groups               — joined + eligible_to_join + opted_out
 *   POST  /me/holder-groups/{id}/join     — explicit user-initiated join
 *   POST  /me/holder-groups/{id}/leave    — explicit leave (records opt-out)
 *   GET   /me/holder-groups/preferences   — read auto_join flag
 *   PATCH /me/holder-groups/preferences   — toggle auto_join (default off)
 *
 * Auth: required (self-only — every route is `me/...`).
 *
 * The GET response is the suggest-don't-auto-join surface. Three
 * buckets so the frontend can render "Your communities" + "You qualify
 * for these" + "Left previously" tabs without client-side filtering.
 *
 * Each item carries an `activity` block (heat / posts_last_7d /
 * last_activity_at) so the frontend can render heat indicators and
 * filter out ghost-town suggestions before the user clicks in. Heat
 * thresholds are server-tunable via the `bcc_group_heat_thresholds`
 * filter — see GroupActivityHeatService.
 *
 * @package BCC\Trust\Onchain\REST
 * @since V2 (NFT-gated holder groups)
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\REST;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\ValueObjects\GroupVerification;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Services\HoldingsService;
use BCC\Trust\Onchain\ValueObjects\GatedGroupConfig;
use BCC\Trust\Onchain\ValueObjects\JoinResult;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class HolderGroupsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/holder-groups',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'getList'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/holder-groups/(?P<id>\d+)/join',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'postJoin'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => ['required' => true, 'sanitize_callback' => 'absint'],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/holder-groups/(?P<id>\d+)/leave',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'postLeave'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => ['required' => true, 'sanitize_callback' => 'absint'],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/holder-groups/preferences',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$instance, 'getPreferences'],
                    'permission_callback' => '__return_true',
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [$instance, 'patchPreferences'],
                    'permission_callback' => '__return_true',
                ],
            ]
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // GET /me/holder-groups
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getList(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $configs = GatedGroupRepository::listAllGatedGroupConfigs();
        if ($configs === []) {
            $response = ApiResponse::ok([
                'joined'           => [],
                'eligible_to_join' => [],
                'opted_out'        => [],
            ]);
            $response->header('Cache-Control', 'private, no-store');
            return $response;
        }

        $groupIds = array_map(
            static fn(GatedGroupConfig $c): int => $c->groupId,
            $configs
        );
        $collectionIds = array_values(array_filter(array_map(
            static fn(GatedGroupConfig $c): ?int => $c->collectionId,
            $configs
        )));

        $displays    = PeepSoGroupRepository::findManyByIds($groupIds);
        $memberships = PeepSoGroupRepository::findUserMemberships($userId, $groupIds);
        $collections = CollectionRepository::findManyByIds($collectionIds);
        $activity    = Plugin::instance()->groupActivityHeatService()->forGroups($groupIds);

        // Resolve chain slug per config once, then batched holdings query.
        $slugByGroup = [];
        $pairs       = [];
        foreach ($configs as $cfg) {
            $chain = ChainRepository::getById($cfg->chainId);
            if ($chain === null) {
                continue;
            }
            $slug = (string) $chain->slug;
            $slugByGroup[$cfg->groupId] = $slug;
            $pairs[] = [$slug, $cfg->contractAddress];
        }
        $balances = HoldingsService::ownsAnyMany($userId, $pairs);

        $gateService = Plugin::instance()->nftGroupGateService();

        $joined    = [];
        $eligible  = [];
        $optedOut  = [];

        foreach ($configs as $cfg) {
            $display    = $displays[$cfg->groupId] ?? null;
            $collection = $cfg->collectionId !== null ? ($collections[$cfg->collectionId] ?? null) : null;
            $activityBlock = $activity[$cfg->groupId] ?? [
                'posts_last_7d'    => 0,
                'last_activity_at' => null,
                'heat'             => 'cold',
                'heat_label'       => 'Quiet',
            ];
            $item = $this->composeItem($cfg, $display, $collection, $activityBlock);

            if (isset($memberships[$cfg->groupId])) {
                $joined[] = $item;
                continue;
            }

            if ($gateService->isOptOutActive($userId, $cfg->groupId)) {
                $optedOut[] = $item;
                continue;
            }

            $slug = $slugByGroup[$cfg->groupId] ?? null;
            if ($slug === null) {
                continue;
            }
            $balance = $balances[$slug . ':' . $cfg->contractAddress] ?? 0;
            if ($balance >= $cfg->minBalance) {
                $eligible[] = $item;
            }
        }

        $response = ApiResponse::ok([
            'joined'           => $joined,
            'eligible_to_join' => $eligible,
            'opted_out'        => $optedOut,
        ]);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /me/holder-groups/{id}/join
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function postJoin(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Rate-limit the trusted-backend join door. The gate service already
        // does eligibility / opt-out / chain / balance checks, but each call
        // touches the holdings RPC — so unbounded retry by a buggy client
        // amplifies upstream load. Per-user bucket prevents one viewer from
        // flooding without affecting others.
        if (!\BCC\Core\Security\Throttle::allow('holder_group_join:' . $userId, 10, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $groupId = (int) $request->get_param('id');
        $result  = Plugin::instance()->nftGroupGateService()->joinIfEligible($userId, $groupId);

        if ($result->success) {
            // §CRIT-05 accountability surface — holder-group joins are the
            // primary trusted-backend door (PeepSoGroupWriter::join bypasses
            // PeepSo's UI approval check). Audit only on an actual state
            // transition (CODE_OK): joinIfEligible returns success=true with
            // CODE_ALREADY_MEMBER for no-op re-join attempts (no PeepSo
            // write happened), and we don't want those inflating the audit
            // trail. The 200 OK response shape is unchanged either way so
            // the SPA can still surface "you're in this community" without
            // distinguishing first-join from re-join.
            if ($result->code === JoinResult::CODE_OK) {
                AuditLogger::log('holder_group_join', $groupId, [
                    'code' => $result->code,
                    'via'  => 'explicit',
                ], 'group', $userId);
            }

            return ApiResponse::ok([
                'joined'   => true,
                'group_id' => $groupId,
                'code'     => $result->code,
            ]);
        }

        return $this->translateFailure($result, $groupId);
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /me/holder-groups/{id}/leave
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function postLeave(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('holder_group_leave:' . $userId, 10, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $groupId = (int) $request->get_param('id');
        $config  = GatedGroupRepository::getGateConfig($groupId);
        if ($config === null) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Not a holder group.',
                400
            );
        }

        $left = \BCC\Core\PeepSo\PeepSoGroupWriter::leave($userId, $groupId);
        if (!$left) {
            // Most likely cause: caller is the group owner.
            // PeepSoGroupWriter refuses to leave a group ownerless.
            return ApiResponse::error(
                'bcc_permission_denied',
                'Owners cannot leave their own community. Hand off ownership or delete the group first.',
                403
            );
        }

        Plugin::instance()->nftGroupGateService()->recordOptOut($userId, $groupId);

        // §CRIT-05 — mirror of holder_group_join; records the 90-day opt-out
        // that prevents the reconcile sweep from re-adding the user.
        AuditLogger::log('holder_group_leave', $groupId, [], 'group', $userId);

        return ApiResponse::ok([
            'left'     => true,
            'group_id' => $groupId,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // GET / PATCH /me/holder-groups/preferences
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getPreferences(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $response = ApiResponse::ok([
            'auto_join' => Plugin::instance()->nftGroupGateService()->autoJoinEnabled($userId),
        ]);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function patchPreferences(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // PATCH preferences can trigger reconcileForUser which fires N
        // PeepSoGroupWriter::join calls — flooding this endpoint amplifies
        // RPC + audit-log writes. Higher bucket than join/leave since the
        // toggle itself is cheap; the cost is in the optional reconcile.
        if (!\BCC\Core\Security\Throttle::allow('holder_group_prefs:' . $userId, 20, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $autoJoinRaw = $request->get_param('auto_join');
        if ($autoJoinRaw === null) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'No preference fields provided. Pass `auto_join`.',
                422
            );
        }

        $service = Plugin::instance()->nftGroupGateService();
        $enabled = filter_var($autoJoinRaw, FILTER_VALIDATE_BOOLEAN);
        $service->setAutoJoinEnabled($userId, $enabled);

        // If the user just turned auto-join ON, reconcile right now so
        // they don't have to wait for the next cron sweep — joining
        // their eligible groups immediately is what they asked for.
        $reconciled = ['joined' => 0, 'skipped' => 0];
        if ($enabled) {
            $reconciled = $service->reconcileForUser($userId);

            // Audit the user-triggered reconcile only when it actually joined
            // groups — toggling the flag with zero eligible groups is not a
            // mutation worth a log line. The cron sweep that performs the
            // same operation is intentionally NOT audited here because it is
            // a server-side reconciliation, not a Next.js mutation.
            if ($reconciled['joined'] > 0) {
                AuditLogger::log('holder_group_auto_reconciled', $userId, [
                    'joined'  => $reconciled['joined'],
                    'skipped' => $reconciled['skipped'],
                ], 'user', $userId);
            }
        }

        $response = ApiResponse::ok([
            'auto_join'  => $enabled,
            'reconciled' => $reconciled,
        ]);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param object{id: numeric-string, post_name: string, post_title: string, post_content: string, member_count: numeric-string}|null $display
     * @param object{id: string, chain_id: string, contract_address: string, collection_name: string|null, image_url: string|null, chain_slug: string, chain_type: string}|null $collection
     * @param array{posts_last_7d: int, last_activity_at: string|null, heat: string, heat_label: string} $activity
     * @return array<string, mixed>
     */
    private function composeItem(
        GatedGroupConfig $cfg,
        ?object $display,
        ?object $collection,
        array $activity
    ): array {
        return [
            'group_id'     => $cfg->groupId,
            'slug'         => $display !== null ? (string) $display->post_name : '',
            'name'         => $display !== null ? (string) $display->post_title : '',
            'member_count' => $display !== null ? (int) $display->member_count : 0,
            'collection'   => [
                'chain'     => $collection !== null ? (string) $collection->chain_slug : null,
                'contract'  => $cfg->contractAddress,
                'name'      => $collection !== null ? $collection->collection_name : null,
                'image_url' => $collection !== null ? $collection->image_url : null,
            ],
            'verification' => GroupVerification::onChain()->toApiResponse(),
            'activity'     => $activity,
        ];
    }

    private function translateFailure(JoinResult $result, int $groupId): WP_REST_Response
    {
        switch ($result->code) {
            case JoinResult::CODE_NOT_A_HOLDER_GROUP:
                return ApiResponse::error(
                    'bcc_invalid_request',
                    'Not a holder group.',
                    400
                );

            case JoinResult::CODE_OPT_OUT_ACTIVE:
                return ApiResponse::error(
                    'bcc_permission_denied',
                    'You opted out of this community recently. Try again later or rejoin from the discovery page.',
                    403
                );

            case JoinResult::CODE_NOT_ELIGIBLE:
                $hint = $this->buildNotEligibleHint($groupId, $result->minBalance ?? 1);
                $response = ApiResponse::error(
                    'bcc_permission_denied',
                    $hint,
                    403
                );
                return $response;

            case JoinResult::CODE_CHAIN_UNSUPPORTED:
                return ApiResponse::error(
                    'bcc_internal_error',
                    'This community is on an unsupported chain right now. Please try again later.',
                    503
                );
        }

        return ApiResponse::error(
            'bcc_internal_error',
            'Could not join community.',
            500
        );
    }

    private function buildNotEligibleHint(int $groupId, int $minBalance): string
    {
        $config = GatedGroupRepository::getGateConfig($groupId);
        $name   = null;
        if ($config !== null && $config->collectionId !== null) {
            $collections = CollectionRepository::findManyByIds([$config->collectionId]);
            $row = $collections[$config->collectionId] ?? null;
            if ($row !== null && !empty($row->collection_name)) {
                $name = (string) $row->collection_name;
            }
        }

        if ($minBalance > 1) {
            return $name !== null
                ? sprintf('Hold at least %d %s NFTs to join this community.', $minBalance, $name)
                : sprintf('Hold at least %d NFTs from this collection to join.', $minBalance);
        }

        return $name !== null
            ? sprintf('Hold a %s NFT to join this community.', $name)
            : 'Hold an NFT from this collection to join.';
    }
}
