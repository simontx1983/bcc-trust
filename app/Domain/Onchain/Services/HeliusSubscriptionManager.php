<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use BCC\Trust\Onchain\Support\ApiRetry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helius subscription lifecycle (V2 Phase 1b).
 *
 * Per spike 2: Helius supports up to 100 000 addresses per webhook.
 * A single shared webhook covers our entire user base — per-wallet
 * webhook provisioning is unnecessary and would burn the rate-limit
 * unnecessarily.
 *
 * This service handles three flows:
 *   1. One-time provisioning  — `provisionSharedWebhook($callbackUrl)`
 *      creates the webhook via POST /v0/webhooks and stores its id
 *      in the `bcc_helius_shared_webhook_id` option. Operator-driven.
 *   2. Address membership      — `addAddress` / `removeAddress` PATCH
 *      the address list. Hooked from `bcc_wallet_verified` /
 *      `bcc_wallet_disconnected` for Solana wallets only.
 *   3. Resync                   — `resyncFromWalletLinks` rebuilds the
 *      webhook's address list from the canonical wallet_links table.
 *      Used by the admin "Resync addresses" button + as a recovery
 *      tool when bookkeeping drift is detected.
 *
 * The webhook auth header (the value Helius echoes back per delivery)
 * MUST be defined as `BCC_HELIUS_WEBHOOK_SECRET` in wp-config.php
 * before provisioning.
 */
final class HeliusSubscriptionManager
{
    public const OPTION_WEBHOOK_ID = 'bcc_helius_shared_webhook_id';
    public const HELIUS_API_BASE   = 'https://api.helius.xyz';
    private const HTTP_TIMEOUT     = 15;
    private const MAX_ADDRESSES    = 100000;

    /**
     * Provision the shared webhook ONCE. No-op (returns existing id) if
     * the option is already set. Returns the webhook id on success or
     * null on failure.
     */
    public static function provisionSharedWebhook(string $callbackUrl): ?string
    {
        $existing = (string) get_option(self::OPTION_WEBHOOK_ID, '');
        if ($existing !== '') {
            return $existing;
        }

        $apiKey  = self::apiKey();
        $authVal = self::authHeader();
        if ($apiKey === '' || $authVal === '' || $callbackUrl === '') {
            return null;
        }

        $body = wp_json_encode([
            'webhookURL'         => $callbackUrl,
            'transactionTypes'   => ['NFT_MINT', 'NFT_SALE', 'TRANSFER'],
            'accountAddresses'   => [],
            'webhookType'        => 'enhanced',
            'authHeader'         => $authVal,
        ]);

        $response = ApiRetry::post(
            self::HELIUS_API_BASE . '/v0/webhooks?api-key=' . rawurlencode($apiKey),
            [
                'timeout'   => self::HTTP_TIMEOUT,
                'headers'   => ['Content-Type' => 'application/json'],
                'body'      => $body,
                'sslverify' => true,
            ],
            ['label' => 'Helius webhook provision']
        );

        $id = self::extractWebhookId($response);
        if ($id !== null) {
            update_option(self::OPTION_WEBHOOK_ID, $id, false);
        }
        return $id;
    }

    /**
     * Add a Solana wallet address to the shared webhook. Idempotent —
     * if the address is already on the list, returns true without a
     * remote call. Marks the wallet_link `helius_managed = 1` on success.
     */
    public static function addAddress(int $walletLinkId, string $walletAddress): bool
    {
        if ($walletLinkId <= 0 || $walletAddress === '') {
            return false;
        }

        $webhookId = (string) get_option(self::OPTION_WEBHOOK_ID, '');
        if ($webhookId === '') {
            return false;
        }

        $current = self::fetchAddresses($webhookId);
        if ($current === null) {
            return false;
        }
        if (in_array($walletAddress, $current, true)) {
            WalletRepository::markHeliusManaged($walletLinkId, true);
            return true;
        }
        if (count($current) >= self::MAX_ADDRESSES) {
            \BCC\Core\Log\Logger::error('[HeliusSubscriptionManager] address cap reached', [
                'cap' => self::MAX_ADDRESSES,
            ]);
            return false;
        }

        $current[] = $walletAddress;
        if (!self::patchAddresses($webhookId, $current)) {
            return false;
        }

        WalletRepository::markHeliusManaged($walletLinkId, true);
        return true;
    }

    /**
     * Remove a Solana wallet address from the shared webhook. Idempotent.
     *
     * `$walletLinkId` may be 0 when the wallet_links row has already been
     * deleted (the canonical case — `bcc_wallet_disconnected` fires AFTER
     * `WalletRepository::delete()` per `WalletIdentityService::unlinkWallet`).
     * In that case we still PATCH the remote address list (drift is the
     * real risk) but skip the local-flag update because there's no row
     * to flip. When the caller does have a valid id (e.g., manual
     * operator action on a still-present row), we update the flag too.
     */
    public static function removeAddress(int $walletLinkId, string $walletAddress): bool
    {
        if ($walletAddress === '') {
            return false;
        }

        $webhookId = (string) get_option(self::OPTION_WEBHOOK_ID, '');
        if ($webhookId === '') {
            // No webhook provisioned — flip the local flag if a row
            // exists so we don't leave stale bookkeeping behind.
            if ($walletLinkId > 0) {
                WalletRepository::markHeliusManaged($walletLinkId, false);
            }
            return true;
        }

        $current = self::fetchAddresses($webhookId);
        if ($current === null) {
            return false;
        }
        $next = array_values(array_filter($current, static fn ($a) => $a !== $walletAddress));
        if (count($next) === count($current)) {
            // Address wasn't on the remote list — still flip the local flag.
            if ($walletLinkId > 0) {
                WalletRepository::markHeliusManaged($walletLinkId, false);
            }
            return true;
        }

        if (!self::patchAddresses($webhookId, $next)) {
            return false;
        }
        if ($walletLinkId > 0) {
            WalletRepository::markHeliusManaged($walletLinkId, false);
        }
        return true;
    }

    /**
     * Rebuild the webhook address list from the canonical wallet_links
     * table. Used by the admin "Resync addresses" button + as a
     * recovery tool when bookkeeping drift is detected.
     *
     * @return array{remote_count: int, local_count: int, applied: bool}|null
     */
    public static function resyncFromWalletLinks(): ?array
    {
        $webhookId = (string) get_option(self::OPTION_WEBHOOK_ID, '');
        if ($webhookId === '') {
            return null;
        }

        $solanaChain = ChainRepository::getBySlug('solana');
        if ($solanaChain === null) {
            return null;
        }

        $managed   = WalletRepository::listForChainBySolanaSync((int) $solanaChain->id, true);
        $unmanaged = WalletRepository::listForChainBySolanaSync((int) $solanaChain->id, false);
        $localAddresses = [];
        foreach ($managed as $w) {
            $localAddresses[$w['wallet_address']] = true;
        }
        foreach ($unmanaged as $w) {
            $localAddresses[$w['wallet_address']] = true;
        }
        $localList = array_keys($localAddresses);
        if (count($localList) > self::MAX_ADDRESSES) {
            $localList = array_slice($localList, 0, self::MAX_ADDRESSES);
        }

        $remote = self::fetchAddresses($webhookId);
        if ($remote === null) {
            return null;
        }

        $applied = false;
        sort($remote);
        $localSorted = $localList;
        sort($localSorted);
        if ($remote !== $localSorted) {
            if (!self::patchAddresses($webhookId, $localList)) {
                return null;
            }
            $applied = true;

            // Bring the helius_managed flag in line with reality.
            foreach ($managed as $w) {
                WalletRepository::markHeliusManaged($w['id'], false);
            }
            foreach ($unmanaged as $w) {
                WalletRepository::markHeliusManaged($w['id'], true);
            }
        }

        return [
            'remote_count' => count($remote),
            'local_count'  => count($localList),
            'applied'      => $applied,
        ];
    }

    /**
     * Operator-readable status. Used by the admin Helius panel.
     *
     * @return array{webhook_id: string, remote_address_count: ?int, local_managed_count: int, callback_url: ?string}
     */
    public static function status(): array
    {
        $webhookId = (string) get_option(self::OPTION_WEBHOOK_ID, '');
        $solanaChain = ChainRepository::getBySlug('solana');
        $localManaged = $solanaChain !== null
            ? count(WalletRepository::listForChainBySolanaSync((int) $solanaChain->id, true))
            : 0;

        if ($webhookId === '') {
            return [
                'webhook_id'           => '',
                'remote_address_count' => null,
                'local_managed_count'  => $localManaged,
                'callback_url'         => null,
            ];
        }

        $detail   = self::fetchWebhook($webhookId);
        $remoteCt = is_array($detail) && isset($detail['accountAddresses']) && is_array($detail['accountAddresses'])
            ? count($detail['accountAddresses'])
            : null;
        $callback = is_array($detail) && isset($detail['webhookURL']) && is_string($detail['webhookURL'])
            ? $detail['webhookURL']
            : null;

        return [
            'webhook_id'           => $webhookId,
            'remote_address_count' => $remoteCt,
            'local_managed_count'  => $localManaged,
            'callback_url'         => $callback,
        ];
    }

    // ── Internal HTTP helpers ───────────────────────────────────────

    /**
     * @return list<string>|null  null on transport error (preserve caller-side state)
     */
    private static function fetchAddresses(string $webhookId): ?array
    {
        $detail = self::fetchWebhook($webhookId);
        if ($detail === null) {
            return null;
        }
        $addresses = $detail['accountAddresses'] ?? [];
        if (!is_array($addresses)) {
            return [];
        }
        $clean = [];
        foreach ($addresses as $a) {
            if (is_string($a) && $a !== '') {
                $clean[] = $a;
            }
        }
        return $clean;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchWebhook(string $webhookId): ?array
    {
        $apiKey = self::apiKey();
        if ($apiKey === '') {
            return null;
        }

        $response = ApiRetry::get(
            self::HELIUS_API_BASE . '/v0/webhooks/' . rawurlencode($webhookId)
            . '?api-key=' . rawurlencode($apiKey),
            ['timeout' => self::HTTP_TIMEOUT, 'sslverify' => true],
            ['label' => 'Helius webhook fetch']
        );

        if (is_wp_error($response)) {
            return null;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ((int) $code !== 200) {
            return null;
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($json) ? $json : null;
    }

    /**
     * @param list<string> $addresses
     */
    private static function patchAddresses(string $webhookId, array $addresses): bool
    {
        $apiKey = self::apiKey();
        if ($apiKey === '') {
            return false;
        }

        $body = wp_json_encode(['accountAddresses' => array_values($addresses)]);
        if ($body === false) {
            return false;
        }

        $response = ApiRetry::request(
            static fn () => wp_remote_request(
                self::HELIUS_API_BASE . '/v0/webhooks/' . rawurlencode($webhookId)
                . '?api-key=' . rawurlencode($apiKey),
                [
                    'method'    => 'PUT',
                    'timeout'   => self::HTTP_TIMEOUT,
                    'headers'   => ['Content-Type' => 'application/json'],
                    'body'      => $body,
                    'sslverify' => true,
                ]
            ),
            ['label' => 'Helius webhook patch addresses']
        );

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[HeliusSubscriptionManager] patch failed', [
                'error' => $response->get_error_message(),
            ]);
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300;
    }

    /**
     * @param array<string, mixed>|\WP_Error $response
     */
    private static function extractWebhookId($response): ?string
    {
        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json) || !isset($json['webhookID']) || !is_string($json['webhookID'])) {
            return null;
        }
        return $json['webhookID'];
    }

    private static function apiKey(): string
    {
        return defined('BCC_HELIUS_API_KEY')
            ? (string) constant('BCC_HELIUS_API_KEY')
            : '';
    }

    private static function authHeader(): string
    {
        return defined('BCC_HELIUS_WEBHOOK_SECRET')
            ? (string) constant('BCC_HELIUS_WEBHOOK_SECRET')
            : '';
    }
}
