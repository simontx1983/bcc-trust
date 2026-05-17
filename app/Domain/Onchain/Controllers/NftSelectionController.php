<?php

namespace BCC\Trust\Onchain\Controllers;

use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\Repositories\NftSelectionRepository;
use BCC\Trust\Onchain\Services\NftSelectionService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST endpoints for the wallet-connect NFT picker modal and the
 * profile selections list.
 *
 * Routes are registered under bcc/v1/ and require an authenticated,
 * non-suspended user. Throttling uses the existing shared Throttle
 * bucket pattern (see WalletController).
 */
final class NftSelectionController
{
    private const NS = 'bcc/v1';

    public static function register_rest_routes(): void
    {
        $auth = function (): bool {
            return is_user_logged_in() && \BCC\Core\Permissions\Permissions::is_not_suspended();
        };

        register_rest_route(self::NS, '/nft-selections/picker', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_picker'],
            'permission_callback' => $auth,
            'args'                => [
                'force' => [
                    'type'    => 'boolean',
                    'default' => false,
                ],
            ],
        ]);

        register_rest_route(self::NS, '/nft-selections', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_list'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NS, '/nft-selections', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'rest_save'],
            'permission_callback' => $auth,
            'args'                => [
                'chain_id'         => ['type' => 'integer', 'required' => true],
                'contract_address' => ['type' => 'string',  'required' => true],
                'token_id'         => ['type' => 'string',  'required' => true],
            ],
        ]);

        register_rest_route(self::NS, '/nft-selections', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [__CLASS__, 'rest_delete'],
            'permission_callback' => $auth,
            'args'                => [
                'chain_id'         => ['type' => 'integer', 'required' => true],
                'contract_address' => ['type' => 'string',  'required' => true],
                'token_id'         => ['type' => 'string',  'required' => true],
            ],
        ]);

        register_rest_route(self::NS, '/nft-selections/refresh', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'rest_refresh'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NS, '/nft-selections/reorder', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'rest_reorder'],
            'permission_callback' => $auth,
            'args'                => [
                'ordered_ids' => ['type' => 'array', 'required' => true],
            ],
        ]);
    }

    // ── Handlers ───────────────────────────────────────────────────────────

    public static function rest_picker(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('nft_picker', 10, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $userId = get_current_user_id();
        $force  = (bool) $req->get_param('force');

        return ApiResponse::ok(NftSelectionService::buildPickerData($userId, $force));
    }

    public static function rest_list(\WP_REST_Request $req): \WP_REST_Response
    {
        $userId = get_current_user_id();
        $items  = NftSelectionRepository::getForUser($userId);
        return ApiResponse::ok([
            'items' => $items,
        ]);
    }

    public static function rest_save(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('nft_select', 60, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $userId   = get_current_user_id();
        $chainId  = (int) $req->get_param('chain_id');
        $contract = (string) $req->get_param('contract_address');
        $tokenId  = (string) $req->get_param('token_id');

        // Basic format guard before the service layer runs DB work.
        if ($chainId <= 0 || $contract === '' || $tokenId === '') {
            return ApiResponse::error('bcc_invalid_request', 'Invalid selection.', 400);
        }

        $result = NftSelectionService::saveSelection($userId, $chainId, $contract, $tokenId);

        if (!($result['ok'] ?? false)) {
            $error = $result['error'] ?? 'unknown';
            if ($error === 'not_owned') {
                return ApiResponse::error(
                    'bcc_nft_not_owned',
                    'This NFT is not in your connected wallets.',
                    403
                );
            }
            return ApiResponse::error(
                'bcc_internal_error',
                'Could not save selection.',
                500
            );
        }

        return ApiResponse::ok([
            'ok' => true,
            'id' => $result['id'] ?? null,
        ]);
    }

    public static function rest_delete(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('nft_deselect', 60, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $userId   = get_current_user_id();
        $chainId  = (int) $req->get_param('chain_id');
        $contract = (string) $req->get_param('contract_address');
        $tokenId  = (string) $req->get_param('token_id');

        $ok = NftSelectionService::removeSelection($userId, $chainId, $contract, $tokenId);
        return ApiResponse::ok(['ok' => $ok]);
    }

    public static function rest_refresh(\WP_REST_Request $req): \WP_REST_Response
    {
        // Rate-limit refresh aggressively — each call hits chain APIs.
        if (!\BCC\Core\Security\Throttle::allow('nft_refresh', 3, 60)) {
            return ApiResponse::error(
                'bcc_rate_limited',
                'Refreshing too often. Try again in a minute.',
                429
            );
        }

        return ApiResponse::ok(NftSelectionService::refreshHoldings(get_current_user_id()));
    }

    public static function rest_reorder(\WP_REST_Request $req): \WP_REST_Response
    {
        $userId     = get_current_user_id();
        $orderedIds = (array) $req->get_param('ordered_ids');
        $ids        = array_values(array_map('intval', $orderedIds));

        $count = NftSelectionService::reorder($userId, $ids);
        return ApiResponse::ok(['ok' => true, 'updated' => $count]);
    }
}
