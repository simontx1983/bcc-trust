<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\REST;

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\HeliusSeenSignaturesRepository;
use BCC\Trust\Onchain\Services\NftHoldingsIndexer;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helius webhook receiver (V2 Phase 1b).
 *
 * One route:
 *   POST /bcc/v1/onchain/helius/webhook
 *
 * Auth posture (per spike 2):
 *   - Helius does NOT use HMAC. The customer-set Authorization header
 *     value (BCC_HELIUS_WEBHOOK_SECRET) is echoed back verbatim per
 *     delivery; verify with hash_equals.
 *   - **Always returns 200** regardless of signature validity to avoid
 *     leaking secret state through response behavior. This is a
 *     crypto-facing endpoint and WILL be probed.
 *   - Signature failures are logged via a per-source-IP rate-limited
 *     bucket (60s window): only the first failure in a window writes
 *     a detailed log row; subsequent failures bump a counter.
 *
 * Replay protection (per spike 2):
 *   - Helius does NOT include verifiable timestamps in payloads.
 *     Replay protection comes from `tx_signature` LRU dedupe via
 *     HeliusSeenSignaturesRepository::markSeen.
 *
 * Always-200 + dedupe = an attacker who steals the auth header can
 * only re-deliver real Helius events (which we already processed) or
 * forge events whose signatures we then refuse to re-process. They
 * cannot poison ownership state.
 *
 * @package BCC\Trust\Onchain\REST
 * @since V2 Phase 1b
 */
final class HeliusWebhookEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const ROUTE_PATH      = '/onchain/helius/webhook';
    private const SIGFAIL_TRANSIENT_PREFIX = 'bcc_helius_sigfail_';
    private const SIGFAIL_WINDOW_SECONDS   = 60;
    private const COUNTER_OPTION_PREFIX    = 'bcc_helius_signature_';

    public static function register(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE_PATH,
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'handle'],
                // Public endpoint — auth is the Authorization header, not WP nonce/cookie.
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Returns the absolute callback URL operators should configure on
     * Helius's side. Used by the admin Helius panel + the
     * `provisionSharedWebhook` flow.
     */
    public static function callbackUrl(): string
    {
        return rest_url(self::ROUTE_NAMESPACE . self::ROUTE_PATH);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Step 1: signature verification. Always-200 — never branch
        // response shape on auth result.
        $expectedAuth = self::expectedAuthHeader();
        $providedAuth = (string) $request->get_header('authorization');

        if ($expectedAuth === '' || !hash_equals($expectedAuth, $providedAuth)) {
            self::logSignatureFailure();
            return self::ok();
        }

        // Step 2: parse body. Helius posts an array of enriched
        // transactions (or a single object — we accept both).
        $params = $request->get_json_params();
        if (!is_array($params)) {
            return self::ok();
        }
        $transactions = self::isAssoc($params) ? [$params] : $params;
        if ($transactions === []) {
            return self::ok();
        }

        // Step 3: resolve Solana chain + fetcher once.
        $chain = ChainRepository::getBySlug('solana');
        if ($chain === null) {
            return self::ok();
        }
        $chainId = (int) $chain->id;
        if (!FetcherFactory::has_driver((string) $chain->chain_type)) {
            return self::ok();
        }
        $fetcher = FetcherFactory::make_for_chain($chain);
        if (!($fetcher instanceof SolanaFetcher)) {
            return self::ok();
        }

        // Step 4: per-transaction dedupe + normalize + ingest.
        $totalEvents = 0;
        $totalReplays = 0;
        foreach ($transactions as $tx) {
            if (!is_array($tx)) {
                continue;
            }
            $signature = isset($tx['signature']) && is_string($tx['signature']) ? $tx['signature'] : '';
            if ($signature === '') {
                continue;
            }
            // Replay protection. markSeen returns false on duplicate.
            if (!HeliusSeenSignaturesRepository::markSeen($signature)) {
                $totalReplays++;
                self::bumpCounter('seen_total');
                continue;
            }
            self::bumpCounter('new_total');

            $events = $fetcher->normalizeWebhookPayload($tx, $chainId);
            if ($events === []) {
                continue;
            }

            try {
                NftHoldingsIndexer::ingest($chainId, $events);
                $totalEvents += count($events);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[HeliusWebhookEndpoint] ingest failed', [
                    'signature' => $signature,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        \BCC\Core\Log\Logger::info('[HeliusWebhookEndpoint] delivery processed', [
            'transactions'  => count($transactions),
            'events_ingested' => $totalEvents,
            'replays_blocked' => $totalReplays,
        ]);

        return self::ok();
    }

    // ── Internal helpers ────────────────────────────────────────────

    private static function ok(): WP_REST_Response
    {
        // Always-200, minimal body — never leak processing detail.
        $response = new WP_REST_Response(['ok' => true], 200);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * @param array<int|string, mixed> $array
     */
    private static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private static function expectedAuthHeader(): string
    {
        return defined('BCC_HELIUS_WEBHOOK_SECRET')
            ? (string) constant('BCC_HELIUS_WEBHOOK_SECRET')
            : '';
    }

    /**
     * Log signature-mismatch attempts at most once per
     * SIGFAIL_WINDOW_SECONDS per source IP. Defends against
     * log-flood amplification while preserving evidence of probing.
     */
    private static function logSignatureFailure(): void
    {
        $ip = self::resolveSourceIp();
        $key = self::SIGFAIL_TRANSIENT_PREFIX . md5($ip);
        $count = (int) (get_transient($key) ?: 0);

        if ($count === 0) {
            \BCC\Core\Log\Logger::warning('[HeliusWebhookEndpoint] signature mismatch', [
                'source_ip' => $ip,
            ]);
        }

        set_transient($key, $count + 1, self::SIGFAIL_WINDOW_SECONDS);
        self::bumpCounter('sigfail_total');
    }

    private static function bumpCounter(string $name): void
    {
        $option = self::COUNTER_OPTION_PREFIX . $name;
        $current = (int) get_option($option, 0);
        update_option($option, $current + 1, false);
    }

    private static function resolveSourceIp(): string
    {
        // REMOTE_ADDR only — do not trust X-Forwarded-For. If the site
        // sits behind a reverse proxy, the operator must configure
        // PHP/wp-cli to set REMOTE_ADDR to the real client.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return $ip !== '' ? $ip : 'unknown';
    }
}
