<?php

namespace BCC\Trust\Onchain\Controllers;

use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\AccountRecoveryService;
use BCC\Trust\Core\Services\AccountSecurityMailer;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use BCC\Trust\Onchain\Repositories\NftSelectionRepository;
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

        $userId  = get_current_user_id();
        $wallets = WalletRepository::getForUser($userId);
        $items   = array_map([self::class, 'projectWalletFields'], $wallets);

        // Account-recovery posture for the settings UI: drives the
        // "set up a recovery method" banner and mirrors the unlink
        // self-lockout guard client-side. has_recovery_email = a real
        // (non-placeholder) email is set; verified_wallet_count = wallets
        // usable for wallet login.
        $resp = ApiResponse::ok([
            'items'    => $items,
            'recovery' => [
                'has_recovery_email'    => AccountRecoveryService::hasRecoveryEmail($userId),
                'verified_wallet_count' => WalletRepository::getVerifiedCountsForUsers([$userId])[$userId] ?? 0,
            ],
        ]);
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

        // Self-lockout guard: refuse to remove the user's LAST verified
        // wallet when the account has no real recovery email. A wallet-only
        // signup carries a random, never-shown password and an
        // undeliverable placeholder email, so its last verified wallet is
        // the sole way back in — deleting it locks the user out for good.
        // Only trips for the caller's own, verified wallet; foreign/absent
        // ids fall through to the idempotent no-op below, and users with a
        // real recovery email or a second verified wallet are unaffected.
        if (
            $existing !== null
            && (int) $existing->user_id === $userId
            && !empty($existing->verified_at)
            && !AccountRecoveryService::hasRecoveryEmail($userId)
        ) {
            $verifiedCount = WalletRepository::getVerifiedCountsForUsers([$userId])[$userId] ?? 0;
            if ($verifiedCount <= 1) {
                return ApiResponse::error(
                    'bcc_last_recovery_method',
                    'This is your only verified wallet and your account has no recovery email. Add a recovery email or link another wallet before removing this one.',
                    409
                );
            }
        }

        $removed = WalletRepository::delete($walletLinkId, $userId);

        // Audit only on a true state transition (own-wallet deletion) — a
        // double-tap unlink against an already-gone row yields removed=false
        // and gets no log line, mirroring the unblock pattern.
        if ($removed && $existing !== null && (int) $existing->user_id === $userId) {
            $chainSlug     = (string) ($existing->chain_slug ?? '');
            $walletAddress = (string) ($existing->wallet_address ?? '');

            // Per-wallet on-chain data is keyed by wallet_link_id, which is
            // gone after this request. Clear it now, while we still hold the
            // id, so the disconnected wallet's NFTs stop showing in the
            // gallery/profile and a later re-link starts clean. The
            // bcc_wallet_disconnected listeners below can't do this — they
            // only receive (userId, chainSlug, walletAddress).
            NftHoldingsRepository::deleteForWalletLink($walletLinkId);
            NftSelectionRepository::deleteForWalletLink($walletLinkId, $userId);

            AuditLogger::log('wallet_unlinked', $walletLinkId, [
                'chain' => $chainSlug,
                'via'   => 'rest',
            ], 'wallet', $userId);

            // Side-channel security notification — narrows the auth
            // surface but still worth telling the user so an attacker
            // who removes their wallet (e.g. to block account recovery)
            // can't do it silently. Best-effort; never throws.
            AccountSecurityMailer::walletUnlinked($userId, $chainSlug, $walletAddress);

            // Fire the canonical disconnect event so the trust-signal
            // teardown, claim revocation + score recalc, and Helius
            // unsubscribe listeners run. This REST path deletes the row
            // directly (it does not route through
            // WalletIdentityService::unlinkWallet), so without this the
            // event — and every listener registered on it — never fires
            // and the user keeps stale trust/claims from a wallet they no
            // longer control. Listeners key on (userId, chainSlug,
            // walletAddress); the wallet_links row being already gone is
            // the expected, documented state for them.
            do_action('bcc_wallet_disconnected', $userId, $chainSlug, $walletAddress);
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
