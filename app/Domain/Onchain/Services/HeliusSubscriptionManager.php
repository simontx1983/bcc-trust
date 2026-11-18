<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
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
     * ⚠ F-64: ONE LOCK FOR EVERY READ-MODIFY-WRITE OF THE SHARED LIST.
     *
     * The webhook holds one address list and every change is GET → edit →
     * PUT of the WHOLE list. The subscribe job, the unsubscribe job and the
     * reconcile ran that unlocked, so two overlapping writers lost whichever
     * change PUT first.
     *
     * A MySQL advisory lock: session-scoped, so a crashed worker's lock is
     * released when its connection drops and cannot wedge subscriptions. The
     * wait is bounded; a busy lock returns false / null, which the async jobs
     * already treat as "retry later" (bounded backoff in bcc-trust.php, with
     * the reconcile as backstop) — a deferred change, never a dropped one.
     */
    /** Lock-name segment; listLockName() resolves it as {prefix}bcc_helius_address_list. */
    private const LIST_LOCK         = 'helius_address_list';

    /**
     * Shrink floor for a resync PUT (F-64 review): with at least this many
     * remote addresses, a resync that would leave fewer than half of them is
     * refused unless an operator overrides it.
     */
    private const SHRINK_FLOOR_MIN_REMOTE = 10;
    private const LIST_LOCK_TIMEOUT = 10;

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

        $result = self::withListLock(static fn (): bool => self::addLocked($webhookId, $walletLinkId, $walletAddress));

        return $result === true;
    }

    /**
     * addAddress()'s read-modify-write. Runs ONLY under the list lock.
     */
    private static function addLocked(string $webhookId, int $walletLinkId, string $walletAddress): bool
    {
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

        $result = self::withListLock(static fn (): bool => self::removeLocked($webhookId, $walletLinkId, $walletAddress));

        return $result === true;
    }

    /**
     * removeAddress()'s read-modify-write. Runs ONLY under the list lock.
     */
    private static function removeLocked(string $webhookId, int $walletLinkId, string $walletAddress): bool
    {
        // ⚠ F-64: the address may still belong to ANOTHER link — two users can
        // link the same Solana address. Taking it off the shared list would
        // stop that user's events. Unknown is not "no": a failed check defers.
        $solanaChain = ChainRepository::getBySlug('solana');
        if ($solanaChain === null) {
            return false;
        }
        try {
            $others = WalletRepository::countOtherLinksForChainAddress((int) $solanaChain->id, $walletAddress, $walletLinkId);
        } catch (RepositoryReadFailure $e) {
            return false;
        }
        if ($others > 0) {
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
     * `$allowShrink` is the OPERATOR override for the shrink floor (see
     * resyncLocked()): the admin Resync form passes it only when an operator
     * has ticked "I have confirmed a mass unlink". The reconcile cron never
     * does.
     *
     * @return array{remote_count: int, local_count: int, applied: bool, refused?: string}|null
     */
    public static function resyncFromWalletLinks(bool $allowShrink = false): ?array
    {
        $webhookId = (string) get_option(self::OPTION_WEBHOOK_ID, '');
        if ($webhookId === '') {
            return null;
        }

        $solanaChain = ChainRepository::getBySlug('solana');
        if ($solanaChain === null) {
            return null;
        }

        return self::withListLock(static fn (): ?array => self::resyncLocked($webhookId, (int) $solanaChain->id, $allowShrink));
    }

    /**
     * resyncFromWalletLinks()'s read-modify-write. Runs ONLY under the list
     * lock.
     *
     * @return array{remote_count: int, local_count: int, applied: bool, refused?: string}|null
     */
    private static function resyncLocked(string $webhookId, int $solanaChainId, bool $allowShrink): ?array
    {
        // ⚠ F-64: FAIL-CLOSED READS. The result is PUT as the WHOLE list, so a
        // failed DB read that came back [] wiped every subscription.
        try {
            $managed   = WalletRepository::listForChainBySolanaSyncOrThrow($solanaChainId, true);
            $unmanaged = WalletRepository::listForChainBySolanaSyncOrThrow($solanaChainId, false);
        } catch (RepositoryReadFailure $e) {
            \BCC\Core\Log\Logger::warning('[HeliusSubscriptionManager] resync skipped: wallet_links read failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
        $localAddresses = [];
        foreach ($managed as $w) {
            $localAddresses[$w['wallet_address']] = true;
        }
        foreach ($unmanaged as $w) {
            $localAddresses[$w['wallet_address']] = true;
        }
        $localList = array_keys($localAddresses);
        if (count($localList) > self::MAX_ADDRESSES) {
            // L3: say so — the addresses past the cap are not subscribed.
            \BCC\Core\Log\Logger::warning('[HeliusSubscriptionManager] local set exceeds the webhook address cap; truncating', [
                'local' => count($localList),
                'cap'   => self::MAX_ADDRESSES,
            ]);
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
            // ⚠ F-64 review: SHRINK FLOOR. The PUT replaces the WHOLE list, so a
            // local read that came back far smaller than reality would
            // unsubscribe everyone missing from it. Fail-closed reads remove the
            // known causes; this is the backstop for an unknown one. Refused:
            // shrinking a non-empty remote list to empty, or — with at least
            // SHRINK_FLOOR_MIN_REMOTE remote addresses — to under half. Small
            // lists are exempt from the ratio so an ordinary unlink converges.
            // A genuine mass unlink needs the operator override.
            $remoteCount = count($remote);
            $localCount  = count($localList);
            $shrinksToEmpty = $remoteCount > 0 && $localCount === 0;
            $shrinksByHalf  = $remoteCount >= self::SHRINK_FLOOR_MIN_REMOTE && $localCount * 2 < $remoteCount;
            if (!$allowShrink && ($shrinksToEmpty || $shrinksByHalf)) {
                \BCC\Core\Log\Logger::warning('[HeliusSubscriptionManager] resync refused: it would shrink the remote list sharply', [
                    'remote_count' => $remoteCount,
                    'local_count'  => $localCount,
                ]);
                return [
                    'remote_count' => $remoteCount,
                    'local_count'  => $localCount,
                    'applied'      => false,
                    'refused'      => 'shrink',
                ];
            }

            if (!self::patchAddresses($webhookId, $localList)) {
                return null;
            }
            $applied = true;
        }

        // Bring the helius_managed flag in line with what the list now holds.
        //
        // ⚠ F-70: THIS WAS INVERTED. It marked every managed row false and
        // every unmanaged row true, right after PUTting both onto the list,
        // so the admin status misreported and the next resync flipped them
        // all back. A row is managed iff its address is on the list: true for
        // every listed address, false only for rows the cap left out. Only
        // rows whose flag actually changes are written.
        $listed = array_fill_keys($localList, true);
        foreach ($managed as $w) {
            if (!isset($listed[$w['wallet_address']])) {
                WalletRepository::markHeliusManaged($w['id'], false);
            }
        }
        foreach ($unmanaged as $w) {
            if (isset($listed[$w['wallet_address']])) {
                WalletRepository::markHeliusManaged($w['id'], true);
            }
        }

        return [
            'remote_count' => count($remote),
            'local_count'  => count($localList),
            'applied'      => $applied,
        ];
    }

    /** F-314: what the remote side said about the stored webhook id. */
    public const REMOTE_OK              = 'ok';
    public const REMOTE_MISSING         = 'missing';
    public const REMOTE_UNREACHABLE     = 'unreachable';
    public const REMOTE_NOT_PROVISIONED = 'not_provisioned';

    /**
     * Operator-readable status. Used by the admin Helius panel.
     *
     * F-314: `remote_state` says WHY the remote fields may be empty — a 404
     * (`missing`: deleted on Helius's side) is told apart from any other
     * failure (`unreachable`), and `callback_matches` compares the remote
     * webhookURL with $expectedCallback when both are known.
     *
     * @return array{webhook_id: string, remote_address_count: ?int, local_managed_count: int, callback_url: ?string, remote_state: string, callback_matches: ?bool}
     */
    public static function status(?string $expectedCallback = null): array
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
                'remote_state'         => self::REMOTE_NOT_PROVISIONED,
                'callback_matches'     => null,
            ];
        }

        $result   = self::fetchWebhookResult($webhookId);
        $detail   = $result['code'] === 200 ? $result['body'] : null;
        $remoteCt = is_array($detail) && isset($detail['accountAddresses']) && is_array($detail['accountAddresses'])
            ? count($detail['accountAddresses'])
            : null;
        $callback = is_array($detail) && isset($detail['webhookURL']) && is_string($detail['webhookURL'])
            ? $detail['webhookURL']
            : null;

        $state = $detail !== null
            ? self::REMOTE_OK
            : ($result['code'] === 404 ? self::REMOTE_MISSING : self::REMOTE_UNREACHABLE);

        return [
            'webhook_id'           => $webhookId,
            'remote_address_count' => $remoteCt,
            'local_managed_count'  => $localManaged,
            'callback_url'         => $callback,
            'remote_state'         => $state,
            'callback_matches'     => ($callback !== null && $expectedCallback !== null && $expectedCallback !== '')
                ? $callback === $expectedCallback
                : null,
        ];
    }

    /**
     * F-314: point the stored webhook at $callbackUrl again, or recreate it
     * when Helius no longer has it. Mutates EXTERNAL provider state.
     *
     *   - not configured / no stored id → nothing (`not_configured` /
     *     `not_provisioned`; use Provision);
     *   - remote 404 → the stored id names nothing: forget it, provision a
     *     fresh webhook, refill its address list (`recreated`);
     *   - remote 200, URL already right → nothing (`already_current`);
     *   - remote 200, URL differs → a FULL-body PUT under the address-list
     *     lock (`updated`; `busy` when the lock is held);
     *   - anything else → nothing (`unreachable`). Only a 404 means missing:
     *     a transport error or an auth failure must never orphan a live
     *     webhook.
     *
     * @return array{outcome: string, webhook_id: string}
     */
    public static function reregisterSharedWebhook(string $callbackUrl): array
    {
        $webhookId = (string) get_option(self::OPTION_WEBHOOK_ID, '');
        if (self::apiKey() === '' || self::authHeader() === '' || $callbackUrl === '') {
            return ['outcome' => 'not_configured', 'webhook_id' => $webhookId];
        }
        if ($webhookId === '') {
            return ['outcome' => 'not_provisioned', 'webhook_id' => ''];
        }

        $result = self::fetchWebhookResult($webhookId);

        if ($result['code'] === 404) {
            delete_option(self::OPTION_WEBHOOK_ID);
            $newId = self::provisionSharedWebhook($callbackUrl);
            if ($newId === null) {
                return ['outcome' => 'recreate_failed', 'webhook_id' => ''];
            }
            // The fresh webhook watches nothing yet. Growing from empty is
            // never a "shrink", so no override is needed.
            self::resyncFromWalletLinks(false);
            return ['outcome' => 'recreated', 'webhook_id' => $newId];
        }

        if ($result['code'] !== 200 || $result['body'] === null) {
            return ['outcome' => 'unreachable', 'webhook_id' => $webhookId];
        }
        if (($result['body']['webhookURL'] ?? null) === $callbackUrl) {
            return ['outcome' => 'already_current', 'webhook_id' => $webhookId];
        }

        // Under the address-list lock: the full body carries the address
        // list, so an add/remove racing this PUT must not be overwritten.
        $ok = self::withListLock(static fn (): bool => self::putWebhookUrl($webhookId, $callbackUrl));
        if ($ok === null) {
            return ['outcome' => 'busy', 'webhook_id' => $webhookId];
        }
        return ['outcome' => $ok ? 'updated' : 'update_failed', 'webhook_id' => $webhookId];
    }

    /**
     * PUT the webhook with a FULL body (Helius's update takes every field as
     * optional and does not document whether unsent ones are kept, so none
     * are left out). The address list and filters are re-read under the
     * caller's lock, so what is sent is the live configuration plus the new
     * URL and our auth header.
     */
    private static function putWebhookUrl(string $webhookId, string $callbackUrl): bool
    {
        $current = self::fetchWebhookResult($webhookId);
        if ($current['code'] !== 200 || $current['body'] === null) {
            return false;
        }
        $live = $current['body'];

        $body = wp_json_encode([
            'webhookURL'       => $callbackUrl,
            'transactionTypes' => isset($live['transactionTypes']) && is_array($live['transactionTypes'])
                ? array_values($live['transactionTypes'])
                : ['NFT_MINT', 'NFT_SALE', 'TRANSFER'],
            'accountAddresses' => isset($live['accountAddresses']) && is_array($live['accountAddresses'])
                ? array_values($live['accountAddresses'])
                : [],
            'webhookType'      => isset($live['webhookType']) && is_string($live['webhookType'])
                ? $live['webhookType']
                : 'enhanced',
            'authHeader'       => self::authHeader(),
        ]);
        if ($body === false) {
            return false;
        }

        $response = ApiRetry::post(
            self::HELIUS_API_BASE . '/v0/webhooks/' . rawurlencode($webhookId)
            . '?api-key=' . rawurlencode(self::apiKey()),
            [
                'method'    => 'PUT',
                'timeout'   => self::HTTP_TIMEOUT,
                'headers'   => ['Content-Type' => 'application/json'],
                'body'      => $body,
                'sslverify' => true,
            ],
            ['label' => 'Helius webhook re-register']
        );
        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[HeliusSubscriptionManager] re-register PUT failed', [
                'error' => $response->get_error_message(),
            ]);
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300;
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
        // ⚠ F-64: COMPLETE OR null. Callers PUT an edited copy of this as
        // the WHOLE list, so returning [] for a missing or malformed list
        // made addAddress() PUT just the new address — wiping every other
        // subscriber — and dropping an unreadable entry dropped that
        // subscription on the next write. A list this cannot read entirely
        // is unknown; callers treat null as "do not write, retry later".
        if (!array_key_exists('accountAddresses', $detail)) {
            return null;
        }
        $addresses = $detail['accountAddresses'];
        if (!is_array($addresses) || !array_is_list($addresses)) {
            return null;
        }
        $clean = [];
        foreach ($addresses as $a) {
            if (!is_string($a) || $a === '') {
                return null;
            }
            $clean[] = $a;
        }
        return $clean;
    }

    /**
     * Run one read-modify-write of the shared address list under the list
     * lock. Returns null — without running $fn — when the lock is busy, so
     * callers defer rather than write over a concurrent change.
     *
     * ⚠ The release is in `finally`, on every path including a throw, and is
     * matched one-for-one with the acquire (GET_LOCK is reentrant per
     * session, so an unmatched acquire would keep the lock held).
     *
     * @template T
     * @param  callable(): T $fn
     * @return T|null
     */
    private static function withListLock(callable $fn): mixed
    {
        $lock = self::listLockName();
        if (!\BCC\Core\DB\AdvisoryLock::acquire($lock, self::LIST_LOCK_TIMEOUT)) {
            \BCC\Core\Log\Logger::info('[HeliusSubscriptionManager] address list busy; deferring');
            return null;
        }
        try {
            return $fn();
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lock);
        }
    }

    /**
     * The list lock's name, carrying this site's table prefix.
     *
     * ⚠ GET_LOCK names are SERVER-WIDE. Staging and production sharing one
     * MySQL server would otherwise serialise against each other's Helius
     * writes. The prefix comes from DB::table() — the sanctioned accessor —
     * because a service may not touch $wpdb (arch guardrail
     * WPDB_OUTSIDE_REPO); it yields "{prefix}bcc_helius_address_list". The
     * value names a lock, not a table. MySQL caps a lock name at 64
     * characters, so it is truncated defensively rather than letting
     * GET_LOCK error.
     */
    private static function listLockName(): string
    {
        return substr(\BCC\Core\DB\DB::table(self::LIST_LOCK), 0, 64);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchWebhook(string $webhookId): ?array
    {
        $result = self::fetchWebhookResult($webhookId);
        return $result['code'] === 200 ? $result['body'] : null;
    }

    /**
     * F-314: the GET with its HTTP status kept, so a 404 (gone on Helius's
     * side) can be told apart from a transport or auth failure. `code` is
     * null for a transport error or a missing API key.
     *
     * @return array{code: ?int, body: ?array<string, mixed>}
     */
    private static function fetchWebhookResult(string $webhookId): array
    {
        $apiKey = self::apiKey();
        if ($apiKey === '') {
            return ['code' => null, 'body' => null];
        }

        $response = ApiRetry::get(
            self::HELIUS_API_BASE . '/v0/webhooks/' . rawurlencode($webhookId)
            . '?api-key=' . rawurlencode($apiKey),
            ['timeout' => self::HTTP_TIMEOUT, 'sslverify' => true],
            ['label' => 'Helius webhook fetch']
        );

        if (is_wp_error($response)) {
            return ['code' => null, 'body' => null];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['code' => $code, 'body' => null];
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        return ['code' => 200, 'body' => is_array($json) ? $json : null];
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

        // ⚠ Through ApiRetry::post — which runs SafeHttpClient::prepareArgs()
        // (metadata-host blocklist, public-IP validation, the resolved-IP pin,
        // the negative DNS cache) — not a bare wp_remote_request(). The
        // webhook GET and the provisioning POST already went this way; only
        // this PUT did not. wp_remote_post() lets the caller's `method`
        // override POST, so the request is still a PUT.
        $response = ApiRetry::post(
            self::HELIUS_API_BASE . '/v0/webhooks/' . rawurlencode($webhookId)
            . '?api-key=' . rawurlencode($apiKey),
            [
                'method'    => 'PUT',
                'timeout'   => self::HTTP_TIMEOUT,
                'headers'   => ['Content-Type' => 'application/json'],
                'body'      => $body,
                'sslverify' => true,
            ],
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
