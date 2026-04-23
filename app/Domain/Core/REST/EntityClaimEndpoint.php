<?php
/**
 * Entity Claim REST Endpoint
 *
 * Proxies entity claim requests to bcc-onchain-signals ClaimService
 * via REST API, replacing the legacy admin-ajax bcc_claim_entity handler.
 *
 * Route: POST /bcc/v1/claim
 *
 * @package BCC\Trust\Core\REST
 */

namespace BCC\Trust\Core\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class EntityClaimEndpoint
{
    /**
     * Register REST routes.
     */
    public static function register(): void
    {
        register_rest_route('bcc/v1', '/claim', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle'],
            'permission_callback' => function () {
                return is_user_logged_in() && \BCC\Core\Permissions\Permissions::is_not_suspended();
            },
            'args'                => [
                'entity_type' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                ],
                'entity_id' => [
                    'type'     => 'integer',
                    'required' => true,
                    'minimum'  => 1,
                ],
            ],
        ]);
    }

    /**
     * Handle POST /bcc/v1/claim
     */
    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('claim_entity', 10, 60)) {
            return new WP_REST_Response(
                ['code' => 'rate_limited', 'message' => 'Too many requests. Please wait.'],
                429
            );
        }

        $user_id     = get_current_user_id();
        $entity_type = $request->get_param('entity_type');
        $entity_id   = (int) $request->get_param('entity_id');

        if (!$entity_type || !$entity_id) {
            return new WP_REST_Response(
                ['code' => 'invalid_params', 'message' => 'Missing entity type or ID.'],
                400
            );
        }

        // Fail-loud: Onchain is a hard in-plugin dependency. A missing class here is a
        // deployment bug, not a fallback case — do not reintroduce class_exists guards.
        if (!class_exists(\BCC\Trust\Onchain\Services\ClaimService::class)) {
            throw new \RuntimeException('Onchain domain classes not autoloaded');
        }

        $result = \BCC\Trust\Onchain\Services\ClaimService::claim($user_id, $entity_type, $entity_id);

        if ($result['success']) {
            \BCC\Core\Log\Logger::audit('entity_claimed', [
                'user_id' => $user_id,
                'type'    => $entity_type,
                'id'      => $entity_id,
            ]);
            return rest_ensure_response($result);
        }

        $status = ($result['needs_wallet'] ?? false) ? 412 : 400;
        return new WP_REST_Response($result, $status);
    }
}
