<?php
/**
 * PageDelegatorGroupEndpoint — the operator's two writes on a validator page's
 * delegator community.
 *
 *   POST  /pages/{id}/delegator-group — create it, choosing the validator and
 *                                       the minimum delegation
 *   PATCH /pages/{id}/delegator-group — change the minimum
 *
 * ⚠ THE ONLY AUTHORITY IS ValidatorPageOperatorAuthority, resolved fresh per
 * request. Group ownership confers nothing here: the owner is whoever created
 * the group, and that person may have since lost the page. `can_post_as_entity`,
 * the page mirror claim row and `post_author` are not inputs either.
 *
 * ⚠ permission_callback IS __return_true AND NO BODY ARG IS DECLARED, for the
 * same reason as AnnouncementWriteEndpoint. WordPress's own refusal would answer
 * in its envelope, and a declared `min_stake` arg would be rejected before the
 * check order below ever ran, handing a stranger a 400 for a body error when the
 * answer owed to them is 403. The handler reads the raw value because it must
 * refuse a bad one, not receive a sanitized one: `sanitize_text_field` would
 * quietly accept " 1 " as "1".
 *
 * Check order, POST: auth, throttle, authority, binding, chain, min_stake, exists.
 * Check order, PATCH: auth, throttle, authority, min_stake, exists.
 *
 * @package BCC\Trust\Onchain\REST
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\REST;

use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\OnchainPlugin;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Services\ClaimService;
use BCC\Trust\Onchain\Services\DelegatorGroupView;
use BCC\Trust\Onchain\Services\ValidatorGroupProvisioningService as Provisioning;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as Authority;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class PageDelegatorGroupEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const ROUTE           = '/pages/(?P<id>\d+)/delegator-group';

    /** Requests per minute, per user, across both verbs. */
    private const THROTTLE_LIMIT  = 10;
    private const THROTTLE_WINDOW = 60;

    private const SUPPORTED_CHAIN_TYPE = 'cosmos';

    public static function register(): void
    {
        $instance = new self();
        $args     = ['id' => ['required' => true, 'sanitize_callback' => 'absint']];

        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE, [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$instance, 'create'],
            'permission_callback' => '__return_true',
            'args'                => $args,
        ]);

        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE, [
            'methods'             => 'PATCH',
            'callback'            => [$instance, 'updateMinStake'],
            'permission_callback' => '__return_true',
            'args'                => $args,
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $refusal = self::gate($request);
        if ($refusal !== null) {
            return $refusal;
        }

        $userId = get_current_user_id();
        $pageId = (int) $request->get_param('id');

        $validatorId = self::positiveInt($request->get_param('validator_id'));
        if (!ClaimService::isEntityBoundToPage('validator', $validatorId, $pageId)) {
            return ApiResponse::error('bcc_not_found', 'That validator is not on this page.', 404);
        }

        $validator = ValidatorRepository::getByIdWithChain($validatorId);
        if ($validator === null) {
            return ApiResponse::error('bcc_not_found', 'That validator is not on this page.', 404);
        }

        if ((string) $validator->chain_type !== self::SUPPORTED_CHAIN_TYPE) {
            return ApiResponse::error(
                'bcc_delegator_group_unavailable',
                "Delegator groups aren't available on this chain yet.",
                400
            );
        }

        $minStake = Provisioning::parseMinStake($request->get_param('min_stake'));
        if ($minStake === null) {
            return self::invalidMinStake();
        }

        $result = OnchainPlugin::instance()
            ->validatorGroupProvisioningService()
            ->createForPage($userId, $pageId, $validatorId, $minStake);

        if ($result['status'] === Provisioning::STATUS_EXISTS) {
            return ApiResponse::error(
                'bcc_conflict',
                'This page already has a delegator group.',
                409,
                ['group' => DelegatorGroupView::forPage($pageId)]
            );
        }
        if ($result['status'] !== Provisioning::STATUS_CREATED) {
            return self::unavailable();
        }

        return self::groupResponse($pageId, 201);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function updateMinStake(WP_REST_Request $request): WP_REST_Response
    {
        $refusal = self::gate($request);
        if ($refusal !== null) {
            return $refusal;
        }

        $minStake = Provisioning::parseMinStake($request->get_param('min_stake'));
        if ($minStake === null) {
            return self::invalidMinStake();
        }

        $pageId = (int) $request->get_param('id');
        $result = OnchainPlugin::instance()
            ->validatorGroupProvisioningService()
            ->updateMinStake(get_current_user_id(), $pageId, $minStake);

        if ($result['status'] === Provisioning::STATUS_NO_GROUP) {
            return ApiResponse::error('bcc_not_found', 'This page has no delegator group.', 404);
        }
        if ($result['status'] !== Provisioning::STATUS_UPDATED
            && $result['status'] !== Provisioning::STATUS_UNCHANGED
        ) {
            return self::unavailable();
        }

        return self::groupResponse($pageId, 200);
    }

    /**
     * Auth, then throttle, then suspension, then authority: the part both verbs
     * share.
     *
     * ⚠ publicReason() and never the decision's own `reason`, which tells a
     * stranger whether this page is unclaimed, held by someone else, or
     * contested. A failed authority check costs a throttle slot deliberately:
     * that is what bounds a stranger probing it.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    private static function gate(WP_REST_Request $request): ?WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('delegator_group_write:' . $userId, self::THROTTLE_LIMIT, self::THROTTLE_WINDOW)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        // A suspended account doesn't create or reconfigure a community, same
        // gate and same place as the join route. Admin bypass off.
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)) {
            return ApiResponse::error('bcc_forbidden', 'Your account is suspended.', 403);
        }

        $decision = Authority::decide((int) $request->get_param('id'), $userId);
        if ($decision['decision'] !== Authority::DECISION_OPERATOR) {
            return ApiResponse::error(
                'bcc_forbidden',
                'Not permitted.',
                403,
                ['reason_code' => Authority::publicReason($decision)]
            );
        }

        return null;
    }

    private static function groupResponse(int $pageId, int $status): WP_REST_Response
    {
        $group = DelegatorGroupView::forPage($pageId);
        if ($group === null) {
            return self::unavailable();
        }

        $response = ApiResponse::ok(['group' => $group], $status);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    private static function invalidMinStake(): WP_REST_Response
    {
        return ApiResponse::error(
            'bcc_invalid_request',
            'Enter a minimum above 0 and up to 1000000000, with at most 6 decimal places.',
            422
        );
    }

    private static function unavailable(): WP_REST_Response
    {
        return ApiResponse::error(
            'bcc_internal_error',
            'The delegator group could not be saved. Please try again in a moment.',
            503
        );
    }

    /**
     * @param mixed $raw
     */
    private static function positiveInt($raw): int
    {
        if (is_int($raw)) {
            return max(0, $raw);
        }
        return (is_string($raw) && ctype_digit($raw) && strlen($raw) <= 18) ? (int) $raw : 0;
    }
}
