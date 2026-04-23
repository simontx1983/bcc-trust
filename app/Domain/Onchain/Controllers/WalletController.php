<?php

namespace BCC\Trust\Onchain\Controllers;

use BCC\Core\Wallet\WalletIdentityService;
use BCC\Core\Wallet\WalletVerificationRequest;
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
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    // ── AJAX: Generate Challenge ─────────────────────────────────────────────

    public static function ajax_challenge(): void
    {
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
        if (!\BCC\Core\Security\Throttle::allow('list_wallets', 30, 60)) {
            return new \WP_REST_Response(['message' => 'Too many requests.'], 429);
        }

        $wallets = WalletRepository::getForUser(get_current_user_id());
        return rest_ensure_response(array_map([self::class, 'projectWalletFields'], $wallets));
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

    // ── Frontend Assets ──────────────────────────────────────────────────────

    public static function enqueue_assets(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        wp_enqueue_script(
            'bcc-wallet-connect',
            BCC_TRUST_URL . 'assets/js/bcc-wallet-connect.js',
            [],
            BCC_TRUST_VERSION,
            true
        );

        wp_localize_script('bcc-wallet-connect', 'bccWallet', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bcc_wallet_nonce'),
            'chains'  => self::getChainsForJs(),
            'i18n'    => [
                'connect'        => __('Connect Wallet', 'bcc-onchain'),
                'disconnect'     => __('Disconnect', 'bcc-onchain'),
                'verify'         => __('Verify Ownership', 'bcc-onchain'),
                'signing'        => __('Signing…', 'bcc-onchain'),
                'verifying'      => __('Verifying…', 'bcc-onchain'),
                'verified'       => __('Verified', 'bcc-onchain'),
                'failed'         => __('Verification failed', 'bcc-onchain'),
                'expired'        => __('Challenge expired, try again', 'bcc-onchain'),
                'no_wallet'      => __('No wallet detected', 'bcc-onchain'),
                'already_linked' => __('This wallet is already linked', 'bcc-onchain'),
            ],
        ]);

        wp_enqueue_style(
            'bcc-wallet-connect',
            BCC_TRUST_URL . 'assets/css/bcc-wallet-connect.css',
            [],
            BCC_TRUST_VERSION
        );
    }

    // ── AJAX: Toggle Collection Profile Visibility ─────────────────────────

    public static function ajax_toggle_collection_profile(): void
    {
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


    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private static function getChainsForJs(): array
    {
        $chains = ChainRepository::getActive();
        $result = [];

        foreach ($chains as $chain) {
            $result[] = [
                'id'           => (int) $chain->id,
                'slug'         => $chain->slug,
                'name'         => $chain->name,
                'chain_type'   => $chain->chain_type,
                'chain_id_hex' => $chain->chain_id_hex,
                'explorer_url' => $chain->explorer_url,
                'native_token' => $chain->native_token,
                'icon_url'     => $chain->icon_url,
            ];
        }

        return $result;
    }

    /**
     * Validate wallet address format against the chain type.
     */
    private static function validateAddressFormat(string $address, string $chainType): bool
    {
        return match ($chainType) {
            'evm'    => (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $address),
            'solana' => (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address),
            'cosmos' => (bool) preg_match('/^[a-z]{1,20}1[a-z0-9]{38,58}$/', $address),
            default  => strlen($address) >= 10 && strlen($address) <= 128,
        };
    }
}
