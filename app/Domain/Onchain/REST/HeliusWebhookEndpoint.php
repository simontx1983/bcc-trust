<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\REST;

use BCC\Trust\Core\Security\IpResolver;
use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\HeliusSeenSignaturesRepository;
use BCC\Trust\Onchain\Services\HeliusDeliveryLog;
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
 *     crypto-facing endpoint and WILL be probed. The single exception
 *     (F-67) is a 503 for an AUTHENTICATED delivery whose dedupe write
 *     failed, so Helius redelivers it; an unauthenticated request can
 *     never reach that branch.
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

    /**
     * GLOBAL cap on auth_failed rows appended to the shared 200-row
     * delivery log per SIGFAIL window, across ALL source networks (F-49
     * review M1). The per-network gate alone still let 200 addresses (a
     * botnet, or one IPv6 /64 before /64 keying) evict every real delivery
     * row each minute; with this cap, junk can take at most this many
     * slots per window and real `processed` history survives.
     */
    private const AUTH_FAILED_LOG_MAX_PER_WINDOW = 5;
    private const AUTH_FAILED_LOG_COUNT_KEY      = 'bcc_helius_sigfail_logged';

    /**
     * Unix timestamp of the most recent authenticated Helius delivery.
     * Single source of truth for "is Solana ingestion alive?" — read
     * by `NftIndexerHealthSnapshot::buildSummary` to derive the X5
     * freshness signal (YELLOW >60min, RED >4h). `autoload=false`
     * keeps it off the autoloaded options blob.
     */
    public const OPTION_LAST_DELIVERY_AT = 'bcc_helius_last_delivery_at';

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

        // X5 freshness mark: any authenticated delivery — including
        // empty-payload pings during quiet periods — proves Helius is
        // reaching us. Updated BEFORE body parsing because the
        // "ingestion alive" signal is about successful provider
        // delivery, not about the payload's downstream usefulness.
        // `autoload=false` keeps this off the autoloaded-options blob;
        // it's read only by the admin dashboard.
        update_option(self::OPTION_LAST_DELIVERY_AT, time(), false);

        $clientIp = IpResolver::getClientIp();

        // Step 2: parse body. Helius posts a list of enriched transactions
        // (or a single object — we accept both via array_is_list).
        $params = $request->get_json_params();
        if (!is_array($params)) {
            HeliusDeliveryLog::record([
                'outcome'     => HeliusDeliveryLog::OUTCOME_NO_PAYLOAD,
                'auth_passed' => true,
                'ip'          => $clientIp,
            ]);
            return self::ok();
        }
        $transactions = array_is_list($params) ? $params : [$params];
        if ($transactions === []) {
            HeliusDeliveryLog::record([
                'outcome'     => HeliusDeliveryLog::OUTCOME_NO_PAYLOAD,
                'auth_passed' => true,
                'ip'          => $clientIp,
            ]);
            return self::ok();
        }

        // Step 3: resolve Solana chain + fetcher once.
        $chain = ChainRepository::getBySlug('solana');
        if ($chain === null) {
            HeliusDeliveryLog::record([
                'outcome'     => HeliusDeliveryLog::OUTCOME_NO_CHAIN,
                'auth_passed' => true,
                'tx_count'    => count($transactions),
                'ip'          => $clientIp,
            ]);
            return self::ok();
        }
        $chainId = (int) $chain->id;
        if (!FetcherFactory::has_driver((string) $chain->chain_type)) {
            HeliusDeliveryLog::record([
                'outcome'     => HeliusDeliveryLog::OUTCOME_NO_CHAIN,
                'auth_passed' => true,
                'tx_count'    => count($transactions),
                'ip'          => $clientIp,
            ]);
            return self::ok();
        }
        $fetcher = FetcherFactory::make_for_chain($chain);
        if (!($fetcher instanceof SolanaFetcher)) {
            HeliusDeliveryLog::record([
                'outcome'     => HeliusDeliveryLog::OUTCOME_NO_CHAIN,
                'auth_passed' => true,
                'tx_count'    => count($transactions),
                'ip'          => $clientIp,
            ]);
            return self::ok();
        }

        // Step 4: per-transaction dedupe + normalize. Accumulate events
        // across the whole batch and ingest ONCE — every ingest call
        // re-resolves wallets, opens its own transaction, and re-bumps
        // generation counters, so per-transaction ingest at webhook
        // delivery rates becomes a write-amp problem.
        $allEvents        = [];
        $markedThisDelivery = [];
        $totalReplays     = 0;
        foreach ($transactions as $tx) {
            if (!is_array($tx)) {
                continue;
            }
            $signature = isset($tx['signature']) && is_string($tx['signature']) ? $tx['signature'] : '';
            if ($signature === '') {
                continue;
            }
            // Replay protection. markSeen returns false on duplicate, and
            // NULL when the dedupe write failed (F-67).
            $seen = HeliusSeenSignaturesRepository::markSeen($signature);
            if ($seen === null) {
                // Unknown is NOT "already seen". Skipping it as a replay
                // lost the transaction for good (no Solana walker). Fail
                // closed for the whole delivery: ingest nothing, reopen the
                // signatures this delivery already marked (the failed-ingest
                // path's reopen), and answer 503 so Helius REDELIVERS the
                // batch. The only non-200 this endpoint sends, and only
                // after auth passed, so it reveals nothing about the secret.
                \BCC\Core\Log\Logger::error('[HeliusWebhookEndpoint] dedupe write failed — delivery not ingested; answering 503 so Helius redelivers; signatures this delivery marked are reopened', [
                    'transactions'  => count($transactions),
                    'marked_before' => count($markedThisDelivery),
                ]);
                self::reopenDedupe($markedThisDelivery);
                return self::retryLater();
            }
            if ($seen === false) {
                $totalReplays++;
                self::bumpCounter('seen_total');
                // Surface dedup-skipped events on /system/health alongside
                // the admin-panel `seen_total` counter. Sustained
                // activation = legitimate replay (Helius double-sent) or
                // attacker re-delivering with a stolen auth header. Both
                // are operationally interesting; the dedup itself is
                // working (replay was correctly refused).
                \BCC\Core\Observability\DegradationMetrics::record('helius_dedup', 'replay_skipped');
                continue;
            }
            self::bumpCounter('new_total');
            // Track for unmark-on-failure. Only newly-marked signatures —
            // replay-skipped ones belong to a prior delivery.
            $markedThisDelivery[] = $signature;

            $events = $fetcher->normalizeWebhookPayload($tx, $chainId);
            if ($events !== []) {
                array_push($allEvents, ...$events);
            }
        }

        $totalEvents = count($allEvents);
        if ($totalEvents > 0) {
            try {
                NftHoldingsIndexer::ingest($chainId, $allEvents);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[HeliusWebhookEndpoint] ingest failed', [
                    'transactions' => count($transactions),
                    'events'       => $totalEvents,
                    'error'        => $e->getMessage(),
                ]);
                // Reopen dedup for this delivery's signatures so Helius's
                // redelivery can re-process the batch. Without this the
                // markSeen rows (committed above) would refuse the resend
                // as a replay — permanent loss, since Solana has no polling
                // walker. Always-200 posture is unchanged (below).
                self::reopenDedupe($markedThisDelivery);
            }
        }

        // Only log when something happened — every Helius delivery hits
        // this endpoint, so info-level per delivery floods the log.
        if ($totalEvents > 0 || $totalReplays > 0) {
            \BCC\Core\Log\Logger::info('[HeliusWebhookEndpoint] delivery processed', [
                'transactions'    => count($transactions),
                'events_ingested' => $totalEvents,
                'replays_blocked' => $totalReplays,
            ]);
        }

        HeliusDeliveryLog::record([
            'outcome'     => HeliusDeliveryLog::OUTCOME_PROCESSED,
            'auth_passed' => true,
            'tx_count'    => count($transactions),
            'events'      => $totalEvents,
            'replays'     => $totalReplays,
            'ip'          => $clientIp,
        ]);

        return self::ok();
    }

    // ── Internal helpers ────────────────────────────────────────────

    /**
     * Reopen dedupe for the signatures THIS delivery marked, so a redelivery
     * is processed instead of refused as a replay. Shared by the two
     * "nothing was ingested" paths: a failed ingest, and (F-67) a failed
     * dedupe write part-way through the batch.
     *
     * @param list<string> $markedThisDelivery
     */
    private static function reopenDedupe(array $markedThisDelivery): void
    {
        if ($markedThisDelivery === []) {
            // Nothing marked yet, so nothing to reopen — but the batch was
            // still not ingested. Count it on the same "reopened" event.
            \BCC\Core\Observability\DegradationMetrics::record('helius_dedup', 'ingest_failed_unmarked');
            return;
        }

        $deleted = HeliusSeenSignaturesRepository::deleteSignatures($markedThisDelivery);
        if ($deleted === null || $deleted < count($markedThisDelivery)) {
            // The batch is lost AND its signatures stay marked, so Helius's
            // retry will be refused. Manual replay needed — surface loudly.
            \BCC\Core\Log\Logger::error('[HeliusWebhookEndpoint] unmark after failed ingest incomplete — dedup NOT reopened; batch requires manual replay', [
                'expected' => count($markedThisDelivery),
                'deleted'  => $deleted,
            ]);
            \BCC\Core\Observability\DegradationMetrics::record('helius_dedup', 'unmark_failed');
            return;
        }

        \BCC\Core\Observability\DegradationMetrics::record('helius_dedup', 'ingest_failed_unmarked');
    }

    private static function ok(): WP_REST_Response
    {
        // Always-200, minimal body — never leak processing detail.
        $response = new WP_REST_Response(['ok' => true], 200);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * F-67: the ONE non-200. Sent only for an authenticated delivery whose
     * dedupe write failed, so Helius redelivers it. Same minimal body shape.
     */
    private static function retryLater(): WP_REST_Response
    {
        $response = new WP_REST_Response(['ok' => false], 503);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private static function expectedAuthHeader(): string
    {
        return defined('BCC_HELIUS_WEBHOOK_SECRET')
            ? (string) constant('BCC_HELIUS_WEBHOOK_SECRET')
            : '';
    }

    /**
     * Record a signature mismatch at most ONCE per SIGFAIL_WINDOW_SECONDS
     * per source NETWORK (F-49).
     *
     * An unauthenticated caller used to cost a transient write, a counter
     * read-modify-write AND a read-modify-write of the whole 200-row
     * HeliusDeliveryLog option on EVERY request — write load on demand,
     * and a junk flood evicted the real delivery history the admin
     * dashboard reads.
     *
     * Now:
     *   - the first failure from a network in a window sets the window
     *     marker, logs the warning and bumps sigfail_total; every further
     *     failure from that network in the window is one read and NO write.
     *     A network is the exact IPv4 address, or the IPv6 /64 (one client
     *     can rotate through its /64; same rule as #498);
     *   - an auth_failed row is appended to the shared delivery log only
     *     while fewer than AUTH_FAILED_LOG_MAX_PER_WINDOW have been logged
     *     in the window ACROSS ALL networks, so many sources cannot evict
     *     the real delivery history either.
     *
     * sigfail_total counts (network, window) failures, not raw requests. It
     * still climbs while signatures keep failing — e.g. a rotated secret
     * makes Helius's own IPs fail each window — which is the signal
     * NftIndexerHealthSnapshot points operators at.
     */
    private static function logSignatureFailure(): void
    {
        // IpResolver returns CF-Connecting-IP when the request comes from a
        // verified Cloudflare IP, otherwise REMOTE_ADDR. Critical here:
        // the SIGFAIL transient is keyed on the IP, so collapsing every
        // probe behind a CDN to one bucket would silently disable the
        // attack-detection signal.
        $ip  = IpResolver::getClientIp();
        $key = self::SIGFAIL_TRANSIENT_PREFIX . md5(self::networkKey($ip));

        if (get_transient($key) !== false) {
            return; // Already recorded for this network in this window — no write.
        }

        set_transient($key, 1, self::SIGFAIL_WINDOW_SECONDS);
        \BCC\Core\Log\Logger::warning('[HeliusWebhookEndpoint] signature mismatch', [
            'source_ip' => $ip,
        ]);
        self::bumpCounter('sigfail_total');

        $logged = (int) (get_transient(self::AUTH_FAILED_LOG_COUNT_KEY) ?: 0);
        if ($logged >= self::AUTH_FAILED_LOG_MAX_PER_WINDOW) {
            return; // Global cap reached — keep the ring for real deliveries.
        }
        set_transient(self::AUTH_FAILED_LOG_COUNT_KEY, $logged + 1, self::SIGFAIL_WINDOW_SECONDS);
        HeliusDeliveryLog::record([
            'outcome'     => HeliusDeliveryLog::OUTCOME_AUTH_FAILED,
            'auth_passed' => false,
            'ip'          => $ip,
        ]);
    }

    /**
     * Throttle identity for a source address: the exact IPv4 address, or
     * the /64 an IPv6 address sits in (first 8 bytes of inet_pton; same
     * rule as AuditLogRepository's shared-network check, #498). Anything
     * unparseable falls back to the raw string.
     */
    private static function networkKey(string $ip): string
    {
        $binary = @inet_pton($ip);
        if (!is_string($binary) || strlen($binary) !== 16) {
            return $ip;
        }
        return bin2hex(substr($binary, 0, 8)) . '::/64';
    }

    private static function bumpCounter(string $name): void
    {
        $option = self::COUNTER_OPTION_PREFIX . $name;
        $current = (int) get_option($option, 0);
        update_option($option, $current + 1, false);
    }
}
