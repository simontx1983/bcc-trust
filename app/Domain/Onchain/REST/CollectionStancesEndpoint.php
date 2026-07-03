<?php
/**
 * CollectionStancesEndpoint — /bcc/v1/me/collection-stances.
 *
 * Three routes (§4.31, contract v1.32):
 *
 *   GET    /me/collection-stances/panel — the wallet-connect/onboarding
 *          panel: every collection the viewer's wallets hold, with the
 *          state that picks the button pair (live → Join community,
 *          waitlist → Join waitlist) + the viewer's current stance.
 *   POST   /me/collection-stances       — set/switch a stance
 *          (waitlist | spam). Holder-gated server-side.
 *   DELETE /me/collection-stances       — clear the stance (retract).
 *
 * Auth: required (self-only). The join action itself is NOT here — the
 * panel's "Join community" button drives the existing gate-checked
 * POST /me/holder-groups/{id}/join.
 *
 * @package BCC\Trust\Onchain\REST
 * @since Collection stances (2026-07)
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\REST;

use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\Repositories\CollectionSignalRepository;
use BCC\Trust\Onchain\Services\CollectionStanceService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class CollectionStancesEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/collection-stances/panel',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'getPanel'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/collection-stances',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$instance, 'postStance'],
                    'permission_callback' => '__return_true',
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [$instance, 'deleteStance'],
                    'permission_callback' => '__return_true',
                ],
            ]
        );
    }

    public function getPanel(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // The cosmos branch reads 6h-cached marketplace rollups and the
        // EVM branch bounded holdings reads, but a hostile refresh loop
        // still shouldn't get to hammer them.
        if (!\BCC\Core\Security\Throttle::allow('collection_stance_panel:' . $userId, 20, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        return ApiResponse::ok([
            'items' => CollectionStanceService::panelForUser($userId),
        ]);
    }

    public function postStance(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Tighter than the panel: each set is a holder check (cached
        // per wallet/contract, but the cold path is an RPC).
        if (!\BCC\Core\Security\Throttle::allow('collection_stance_set:' . $userId, 10, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $chainId  = (int) $request->get_param('chain_id');
        $contract = (string) $request->get_param('contract_address');
        $stance   = (string) $request->get_param('stance');

        $result = CollectionStanceService::setStance($userId, $chainId, $contract, $stance);
        if (!$result['ok']) {
            return $this->translateFailure($result['error'] ?? 'bcc_internal_error');
        }

        return ApiResponse::ok([
            'chain_id'         => $chainId,
            'contract_address' => strtolower(trim($contract)),
            'stance'           => $stance,
        ]);
    }

    public function deleteStance(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('collection_stance_set:' . $userId, 10, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $chainId  = (int) $request->get_param('chain_id');
        $contract = (string) $request->get_param('contract_address');

        $result = CollectionStanceService::clearStance($userId, $chainId, $contract);
        if (!$result['ok']) {
            return $this->translateFailure($result['error'] ?? 'bcc_internal_error');
        }

        return ApiResponse::ok([
            'chain_id'         => $chainId,
            'contract_address' => strtolower(trim($contract)),
            'stance'           => null,
        ]);
    }

    private function translateFailure(string $code): WP_REST_Response
    {
        return match ($code) {
            'bcc_invalid_request' => ApiResponse::error(
                'bcc_invalid_request',
                'Invalid chain, contract, or stance. Stance must be one of: '
                    . implode(', ', CollectionSignalRepository::STANCES) . '.',
                400
            ),
            'bcc_nft_not_owned' => ApiResponse::error(
                'bcc_nft_not_owned',
                'Your linked wallets do not hold this collection.',
                403
            ),
            'bcc_unavailable' => ApiResponse::error(
                'bcc_unavailable',
                'Could not verify your holdings right now. Try again shortly.',
                503
            ),
            default => ApiResponse::error('bcc_internal_error', 'Could not save your choice.', 500),
        };
    }
}
