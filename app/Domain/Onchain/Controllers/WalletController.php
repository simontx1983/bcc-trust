<?php

namespace BCC\Trust\Onchain\Controllers;

use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\AccountSecurityMailer;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wallet REST surface for the bcc/v1 namespace.
 *
 * Exposes the self-service wallet endpoints consumed by the Next.js
 * settings + claim flows (§4.24 of the API contract):
 *
 *  - GET    /wallets                       — list current user's wallets
 *  - DELETE /wallets/{id}                  — unlink (idempotent)
 *  - GET    /wallets/project/{post_id}     — wallets on a project page
 *  - GET    /chains                        — enabled-chain catalog
 *
 * Wallet linking + signature verification for credential auth lives in
 * \BCC\Trust\Core\REST\AuthEndpoint (§4.1: /auth/nonce, /auth/wallet-link,
 * /auth/wallet-nonce, /auth/wallet-login, /auth/wallet-signup).
 *
 * @phpstan-import-type WalletWithChain from WalletRepository
 */
class WalletController
{
    /**
     * Boot hooks.
     */
    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    // ── REST API Routes ──────────────────────────────────────────────────────

    public static function register_rest_routes(): void
    {
        register_rest_route('bcc/v1', '/wallets', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_list_wallets'],
            'permission_callback' => function () {
                return is_user_logged_in() && \BCC\Core\Permissions\Permissions::is_not_suspended();
            },
        ]);

        register_rest_route('bcc/v1', '/wallets/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [__CLASS__, 'rest_unlink_wallet'],
            'permission_callback' => function () {
                return is_user_logged_in() && \BCC\Core\Permissions\Permissions::is_not_suspended();
            },
            'args' => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route('bcc/v1', '/wallets/project/(?P<post_id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_project_wallets'],
            'permission_callback' => function () {
                return is_user_logged_in() && \BCC\Core\Permissions\Permissions::is_not_suspended();
            },
        ]);

        register_rest_route('bcc/v1', '/chains', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_list_chains'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function rest_list_wallets(\WP_REST_Request $req): \WP_REST_Response
    {
        unset($req);
        if (!\BCC\Core\Security\Throttle::allow('list_wallets', 30, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $wallets = WalletRepository::getForUser(get_current_user_id());
        $items   = array_map([self::class, 'projectWalletFields'], $wallets);

        $resp = ApiResponse::ok(['items' => $items]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    /**
     * DELETE /wallets/:id — unlink a wallet owned by the current user.
     *
     * The repository's delete enforces (id, user_id) match in the WHERE
     * clause, so passing a foreign wallet_link_id is a no-op (returns
     * `removed: false`). We don't 404 in that case because it would
     * leak whether `:id` exists for someone else.
     */
    public static function rest_unlink_wallet(\WP_REST_Request $req): \WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }
        if (!\BCC\Core\Security\Throttle::allow('unlink_wallet:' . $userId, 10, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $walletLinkId = (int) $req->get_param('id');
        if ($walletLinkId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'Wallet id is required.', 400);
        }

        // Capture chain context BEFORE the delete so the audit-log meta
        // remains informative even though the wallet row is gone after the
        // call returns. We do NOT 404 on a foreign id here — see comment
        // above re: leaking existence; absent rows just produce removed=false.
        $existing = WalletRepository::getById($walletLinkId);

        $removed = WalletRepository::delete($walletLinkId, $userId);

        // Audit only on a true state transition (own-wallet deletion) — a
        // double-tap unlink against an already-gone row yields removed=false
        // and gets no log line, mirroring the unblock pattern.
        if ($removed && $existing !== null && (int) $existing->user_id === $userId) {
            AuditLogger::log('wallet_unlinked', $walletLinkId, [
                'chain' => (string) ($existing->chain_slug ?? ''),
                'via'   => 'rest',
            ], 'wallet', $userId);

            // Side-channel security notification — narrows the auth
            // surface but still worth telling the user so an attacker
            // who removes their wallet (e.g. to block account recovery)
            // can't do it silently. Best-effort; never throws.
            AccountSecurityMailer::walletUnlinked(
                $userId,
                (string) ($existing->chain_slug ?? ''),
                (string) ($existing->wallet_address ?? '')
            );
        }

        // Idempotent: removed=false on a foreign or already-deleted id
        // lets a double-tap unlink succeed without confusing the UI.
        $resp = ApiResponse::ok([
            'ok'      => true,
            'id'      => $walletLinkId,
            'removed' => $removed,
        ]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    public static function rest_project_wallets(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('project_wallets', 30, 60)) {
            return new \WP_REST_Response(['message' => 'Too many requests.'], 429);
        }

        $post_id = (int) $req->get_param('post_id');
        $wallets = WalletRepository::getForProject($post_id);

        // Ownership check: only the post author or admins see full wallet addresses.
        $current_user = get_current_user_id();
        $post         = get_post($post_id);
        $is_owner     = $post && (int) $post->post_author === $current_user;
        $is_admin     = current_user_can('manage_options');

        if (!$is_owner && !$is_admin) {
            // Strip wallet_address for non-owners.
            return rest_ensure_response(array_map(
                /** @param WalletWithChain $w */
                static function (object $w): array {
                    $fields = self::projectWalletFields($w);
                    unset($fields['wallet_address']);
                    return $fields;
                },
                $wallets
            ));
        }

        return rest_ensure_response(array_map([self::class, 'projectWalletFields'], $wallets));
    }

    /**
     * Strip internal IDs (user_id, chain_id, post_id) from wallet REST responses.
     *
     * @param WalletWithChain $w
     * @return array<string, mixed>
     */
    private static function projectWalletFields(object $w): array
    {
        return [
            'id'            => (int) $w->id,
            'wallet_address'=> $w->wallet_address ?? '',
            'chain_slug'    => $w->chain_slug ?? '',
            'chain_name'    => $w->chain_name ?? '',
            'chain_type'    => $w->chain_type ?? '',
            'explorer_url'  => $w->explorer_url ?? '',
            'wallet_type'   => $w->wallet_type ?? '',
            'label'         => $w->label ?? '',
            'is_primary'    => (bool) ($w->is_primary ?? false),
            'verified'      => !empty($w->verified_at),
            'created_at'    => $w->created_at ?? null,
        ];
    }

    public static function rest_list_chains(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('list_chains', 30, 60)) {
            return new \WP_REST_Response(['message' => 'Too many requests.'], 429);
        }

        $chains = ChainRepository::getActive();
        $safe = array_map(function (object $chain): array {
            return [
                'id'            => (int) $chain->id,
                'slug'          => $chain->slug,
                'name'          => $chain->name,
                'chain_type'    => $chain->chain_type,
                'chain_id_hex'  => $chain->chain_id_hex ?? null,
                'explorer_url'  => $chain->explorer_url ?? null,
                'native_token'  => $chain->native_token ?? null,
                'icon_url'      => $chain->icon_url ?? null,
            ];
        }, $chains);
        return rest_ensure_response($safe);
    }

}
