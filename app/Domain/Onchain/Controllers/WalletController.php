<?php

namespace BCC\Trust\Onchain\Controllers;

use BCC\Core\Wallet\WalletIdentityService;
use BCC\Core\Wallet\WalletVerificationRequest;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\AccountSecurityMailer;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use BCC\Core\Log\Logger;
use BCC\Trust\Onchain\Services\CollectionService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wallet Connect & Verify
 *
 * Handles the full wallet lifecycle:
 *  1. Generate a nonce/challenge message
 *  2. User signs with wallet (MetaMask / Keplr / Phantom)
 *  3. Server verifies signature → inserts wallet_link row → marks verified
 *  4. CRUD: list, set-primary, disconnect
 *
 * Signature verification delegates to \BCC\Core\Crypto\WalletVerifier.
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
        add_action('wp_ajax_bcc_wallet_challenge',    [__CLASS__, 'ajax_challenge']);
        add_action('wp_ajax_bcc_wallet_verify',       [__CLASS__, 'ajax_verify']);
        add_action('wp_ajax_bcc_wallet_disconnect',   [__CLASS__, 'ajax_disconnect']);
        add_action('wp_ajax_bcc_wallet_set_primary',  [__CLASS__, 'ajax_set_primary']);
        add_action('wp_ajax_bcc_wallet_list',         [__CLASS__, 'ajax_list']);
        add_action('wp_ajax_bcc_collection_toggle_profile', [__CLASS__, 'ajax_toggle_collection_profile']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    // ── AJAX: Generate Challenge ─────────────────────────────────────────────

    public static function ajax_challenge(): void
    {
        // Phase 1.7 dead-code observability (2026-05-09): no caller found
        // in any JS / PHP / TS in the codebase; SPA flows go through REST.
        // Recording before the nonce check so we capture failed-nonce hits
        // too (attackers / cached pre-deploy pages / external scripts).
        // 30-day zero-hit window → safe to retire per V-08 Phase D.
        \BCC\Core\Observability\DegradationMetrics::record('legacy_ajax', 'wallet_challenge');
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'Not logged in.'], 401);
        }

        // Rate limit: 10 requests per minute per user (atomic).
        if (!\BCC\Core\Security\Throttle::allow('wallet_challenge', 10, 60)) {
            wp_send_json_error(['message' => 'Too many requests. Please wait.'], 429);
        }

        $chain_slug     = sanitize_text_field($_POST['chain_slug'] ?? '');
        $wallet_address = sanitize_text_field($_POST['wallet_address'] ?? '');

        if (!$chain_slug || !$wallet_address) {
            wp_send_json_error(['message' => 'Missing chain or address.'], 400);
        }

        $chain_id = ChainRepository::resolveId($chain_slug);
        if (!$chain_id) {
            wp_send_json_error(['message' => 'Unsupported chain.'], 400);
        }

        // Validate wallet address format against the chain type before generating a challenge.
        $chain = ChainRepository::getById($chain_id);
        if ($chain && !self::validateAddressFormat($wallet_address, $chain->chain_type ?? '')) {
            wp_send_json_error(['message' => 'Invalid wallet address format.'], 400);
        }

        $challenge = WalletIdentityService::generateChallenge(
            $user_id,
            $chain_slug,
            $chain_id,
            $wallet_address
        );

        wp_send_json_success([
            'message' => $challenge['message'],
            'nonce'   => $challenge['nonce'],
        ]);
    }

    // ── AJAX: Verify Signature ───────────────────────────────────────────────

    public static function ajax_verify(): void
    {
        // Phase 1.7 dead-code observability (2026-05-09) — see ajax_challenge above.
        \BCC\Core\Observability\DegradationMetrics::record('legacy_ajax', 'wallet_verify');
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        if (!\BCC\Core\Security\Throttle::allow('wallet_verify', 5, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'Not logged in.'], 401);
        }

        $wallet_address = sanitize_text_field($_POST['wallet_address'] ?? '');
        $post_id        = (int) ($_POST['post_id'] ?? 0);
        $wallet_type    = sanitize_text_field($_POST['wallet_type'] ?? 'user');
        $label          = sanitize_text_field($_POST['label'] ?? '');

        $raw_sig   = wp_unslash($_POST['signature'] ?? '');
        $signature = wp_strip_all_tags($raw_sig);

        if (!$wallet_address || !$signature) {
            wp_send_json_error(['message' => 'Missing address or signature.'], 400);
        }

        // Peek at the stored challenge (non-destructive) to resolve
        // the chain metadata. The atomic consume happens inside
        // WalletIdentityService::verifyAndLink() so the challenge
        // cannot be used twice even if verification then fails.
        $challenge = WalletIdentityService::peekChallenge($user_id, $wallet_address);

        if (!$challenge) {
            wp_send_json_error(['message' => 'Challenge not found or expired. Please try again.'], 400);
        }

        $chain = ChainRepository::getById((int) $challenge['chain_id']);
        if (!$chain) {
            wp_send_json_error(['message' => 'Chain not found.'], 400);
        }

        // Pre-check: reject re-verification attempts with 409.
        if (WalletRepository::exists($user_id, (int) $chain->id, $wallet_address)) {
            wp_send_json_error(['message' => 'This wallet is already linked to your account.'], 409);
        }

        // Pre-check: reject if wallet is already linked to a different user.
        if (WalletRepository::existsForOtherUser($user_id, (int) $chain->id, $wallet_address)) {
            wp_send_json_error(['message' => 'This wallet is already linked to another account.'], 409);
        }

        // Single execution pipeline: consume challenge + verify signature
        // + link wallet + fire event. challengeMessage is sourced server-
        // side inside verifyAndLink() — never from caller input.
        $result = WalletIdentityService::verifyAndLink(
            WalletVerificationRequest::fromArray([
                'userId'        => $user_id,
                'chainSlug'     => $chain->slug,
                'chainType'     => $chain->chain_type,
                'chainId'       => (int) $chain->id,
                'walletAddress' => $wallet_address,
                'signature'     => $signature,
                'postId'        => $post_id,
                'walletType'    => $wallet_type,
                'label'         => $label,
            ])
        );

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 403);
        }

        Logger::audit('wallet_connected', ['user_id' => $user_id, 'chain' => $chain->slug, 'address' => $wallet_address]);

        wp_send_json_success([
            'wallet_link_id' => $result['wallet_link_id'],
            'chain'          => $chain->slug,
            'chain_name'     => $chain->name,
            'address'        => $wallet_address,
            'wallet_type'    => $wallet_type,
            'verified'       => true,
        ]);
    }

    // ── AJAX: Disconnect Wallet ──────────────────────────────────────────────

    public static function ajax_disconnect(): void
    {
        // Phase 1.7 dead-code observability (2026-05-09) — see ajax_challenge above.
        \BCC\Core\Observability\DegradationMetrics::record('legacy_ajax', 'wallet_disconnect');
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        if (!\BCC\Core\Security\Throttle::allow('wallet_disconnect', 5, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
        }

        $user_id        = get_current_user_id();
        $wallet_link_id = (int) ($_POST['wallet_link_id'] ?? 0);

        if (!$user_id || !$wallet_link_id) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        // Resolve chain + address BEFORE deleting so we can notify listeners.
        $wallet = WalletRepository::getById($wallet_link_id);
        if (!$wallet || (int) $wallet->user_id !== $user_id) {
            wp_send_json_error(['message' => 'Wallet not found or not yours.'], 404);
        }

        // Single execution pipeline: delete + fire event.
        $deleted = WalletIdentityService::unlinkWallet(
            $user_id,
            $wallet->chain_slug,
            $wallet->wallet_address
        );

        if (!$deleted) {
            wp_send_json_error(['message' => 'Failed to disconnect wallet.'], 500);
        }

        Logger::audit('wallet_disconnected', ['user_id' => get_current_user_id(), 'wallet_id' => $wallet_link_id]);

        wp_send_json_success(['deleted' => $wallet_link_id]);
    }

    // ── AJAX: Set Primary ────────────────────────────────────────────────────

    public static function ajax_set_primary(): void
    {
        // Phase 1.7 dead-code observability (2026-05-09) — see ajax_challenge above.
        \BCC\Core\Observability\DegradationMetrics::record('legacy_ajax', 'wallet_set_primary');
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        if (!\BCC\Core\Security\Throttle::allow('wallet_primary', 10, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
        }

        $user_id        = get_current_user_id();
        $wallet_link_id = (int) ($_POST['wallet_link_id'] ?? 0);

        if (!$user_id || !$wallet_link_id) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        $result = WalletRepository::setPrimary($wallet_link_id, $user_id);

        if (!$result) {
            wp_send_json_error(['message' => 'Wallet not found or not yours.'], 404);
        }

        wp_send_json_success(['primary' => $wallet_link_id]);
    }

    // ── AJAX: List Wallets ───────────────────────────────────────────────────

    public static function ajax_list(): void
    {
        // Phase 1.7 dead-code observability (2026-05-09) — see ajax_challenge above.
        \BCC\Core\Observability\DegradationMetrics::record('legacy_ajax', 'wallet_list');
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        if (!\BCC\Core\Security\Throttle::allow('wallet_list', 20, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'Not logged in.'], 401);
        }

        $wallets = WalletRepository::getForUser($user_id);

        wp_send_json_success([
            'wallets' => array_map(function ($w) {
                return [
                    'id'             => (int) $w->id,
                    'wallet_address' => $w->wallet_address,
                    'chain_slug'     => $w->chain_slug,
                    'chain_name'     => $w->chain_name,
                    'chain_type'     => $w->chain_type,
                    'explorer_url'   => $w->explorer_url,
                    'wallet_type'    => $w->wallet_type,
                    'label'          => $w->label,
                    'is_primary'     => (bool) $w->is_primary,
                    'verified'       => !empty($w->verified_at),
                    'created_at'     => $w->created_at,
                ];
            }, $wallets),
        ]);
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

    // ── Signature Verification ───────────────────────────────────────────────
    // All crypto verification is handled by \BCC\Core\Crypto\WalletVerifier.

    // ── AJAX: Toggle Collection Profile Visibility ─────────────────────────

    public static function ajax_toggle_collection_profile(): void
    {
        // Phase 1.7 dead-code observability (2026-05-09) — see ajax_challenge above.
        \BCC\Core\Observability\DegradationMetrics::record('legacy_ajax', 'collection_toggle_profile');
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        if (!\BCC\Core\Security\Throttle::allow('collection_toggle', 10, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
        }

        $user_id       = get_current_user_id();
        $collection_id = (int) ($_POST['collection_id'] ?? 0);
        $show          = filter_var($_POST['show'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if (!$user_id || !$collection_id) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        $updated = CollectionService::toggleProfileVisibility($collection_id, $user_id, $show);

        if (!$updated) {
            wp_send_json_error(['message' => 'Collection not found or not yours.'], 404);
        }

        wp_send_json_success(['collection_id' => $collection_id, 'show_on_profile' => $show]);
    }


    /**
     * Validate wallet address format against the chain type.
     *
     * Delegates to the shared validator so the V1 REST endpoint
     * (AuthEndpoint) and the legacy AJAX path here use identical
     * matching rules — extracting one was the contract-correction
     * trigger; both call sites now route through the same code.
     */
    private static function validateAddressFormat(string $address, string $chainType): bool
    {
        return \BCC\Trust\Core\Support\WalletAddressValidator::validate($address, $chainType);
    }
}
