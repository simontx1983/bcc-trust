<?php
/**
 * MyGroupsEndpoint — handles /bcc/v1/me/groups for plain (non-gated,
 * non-Local) user/system PeepSo groups.
 *
 *   POST /me/groups/{id}/join     — join an open group
 *   POST /me/groups/{id}/leave    — leave any group I'm a member of
 *
 * Holder groups use /me/holder-groups; Locals use /me/locals — both
 * have their own gate/policy. This endpoint is for the residual case:
 * plain peepso-groups (created by users via PeepSo's UI) where the
 * frontend wants a uniform action URL on the profile Groups tab.
 *
 * Closed and secret groups are rejected with `bcc_permission_denied`
 * + a hint pointing to PeepSo's group page (where the request flow
 * with admin approval / invitation lives). We don't replicate
 * PeepSo's pending_admin / invitation machinery here.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 (Profile Groups tab)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyGroupsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/groups/(?P<id>\d+)/join',
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
            '/me/groups/(?P<id>\d+)/leave',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'postLeave'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => ['required' => true, 'sanitize_callback' => 'absint'],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function postJoin(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Per-user rate limit on plain group joins. Lower than the holder
        // group bucket since this surface doesn't gate behind an NFT check
        // and a flood could create more peepso_group_user rows faster.
        if (!\BCC\Core\Security\Throttle::allow('group_join:' . $userId, 10, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $groupId = (int) $request->get_param('id');
        $context = Plugin::instance()->groupContextResolver()->forGroup($groupId);
        if ($context === null) {
            return ApiResponse::error('bcc_invalid_request', 'Group not found.', 404);
        }

        // Holder groups + Locals route through their own endpoints; reject
        // here so the frontend doesn't accidentally call the wrong path.
        if ($context->type === GroupType::Nft || $context->type === GroupType::Local) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'This community has its own join endpoint. Use /me/holder-groups or /me/locals.',
                400
            );
        }

        if ($context->privacy === PeepSoPrivacy::Closed) {
            return ApiResponse::error(
                'bcc_permission_denied',
                'This community requires admin approval. Visit the group page to request access.',
                403
            );
        }
        if ($context->privacy === PeepSoPrivacy::Secret) {
            return ApiResponse::error(
                'bcc_permission_denied',
                'This community is invite-only.',
                403
            );
        }

        // PeepSoGroupWriter::join returns true for both INSERT-happened and
        // already-a-member-no-op cases (its contract documents this at
        // bcc-core/src/PeepSo/PeepSoGroupWriter.php:46-50). Determine the
        // actual state transition BEFORE the writer call so the audit log
        // captures only real joins, not idempotent re-attempts.
        $wasAlreadyMember = \BCC\Core\Repositories\PeepSoGroupRepository::getMembershipStatus(
            $userId,
            $groupId
        ) !== null;

        \BCC\Core\PeepSo\PeepSoGroupWriter::join($userId, $groupId);

        // Audit only on the non-member → member transition. Disambiguated
        // from holder_group_join (Onchain/REST/HolderGroupsEndpoint) so
        // admin queries can segment NFT-gated joins from plain peepso-group
        // joins. Privacy = open was already enforced above; closed/secret
        // were rejected before reaching the writer.
        if (!$wasAlreadyMember) {
            AuditLogger::log('group_join', $groupId, [
                'group_type' => $context->type->value,
                'privacy'    => $context->privacy->value,
            ], 'group', $userId);
        }

        return ApiResponse::ok([
            'joined'   => true,
            'group_id' => $groupId,
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function postLeave(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('group_leave:' . $userId, 10, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $groupId = (int) $request->get_param('id');
        $context = Plugin::instance()->groupContextResolver()->forGroup($groupId);
        if ($context === null) {
            return ApiResponse::error('bcc_invalid_request', 'Group not found.', 404);
        }

        // Holder groups need to record an opt-out; Locals have their own
        // leave behavior. Route through this endpoint only for plain user/system groups.
        if ($context->type === GroupType::Nft || $context->type === GroupType::Local) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'This community has its own leave endpoint. Use /me/holder-groups or /me/locals.',
                400
            );
        }

        // PeepSoGroupWriter::leave returns true for both real-deletion and
        // no-existing-row-to-delete cases. Determine the actual state
        // transition BEFORE the writer call so the audit log captures only
        // real leaves, not idempotent re-attempts on a non-membership.
        $wasMember = \BCC\Core\Repositories\PeepSoGroupRepository::getMembershipStatus(
            $userId,
            $groupId
        ) !== null;

        $left = \BCC\Core\PeepSo\PeepSoGroupWriter::leave($userId, $groupId);
        if (!$left) {
            return ApiResponse::error(
                'bcc_permission_denied',
                'Owners cannot leave their own community. Hand off ownership or delete the group first.',
                403
            );
        }

        // Audit only on the member → non-member transition. Disambiguated
        // from holder_group_leave — plain group leaves do not record an
        // opt-out (no 90-day cooldown), so they're a distinct admin signal.
        if ($wasMember) {
            AuditLogger::log('group_leave', $groupId, [
                'group_type' => $context->type->value,
                'privacy'    => $context->privacy->value,
            ], 'group', $userId);
        }

        return ApiResponse::ok([
            'left'     => true,
            'group_id' => $groupId,
        ]);
    }
}
