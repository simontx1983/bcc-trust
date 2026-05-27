<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\REST;

use BCC\Trust\Core\Security\IpResolver;
use BCC\Trust\Onchain\Workers\NftEthIndexerWorker;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * IndexerTickEndpoint — signed internal relay for the V2 NFT
 * indexer tick.
 *
 * One route:
 *   POST /wp-json/bcc/v1/internal/indexer/tick
 *
 * Why this exists:
 *   The indexer is registered as a WP-Cron hook at 1-minute intervals
 *   ({@see NftEthIndexerWorker::CRON_HOOK}). Hostinger Business shared
 *   hosting caps real cron frequency at 5–15 minutes depending on
 *   plan, so the indexer would silently fall behind — new wallet
 *   links would sit unindexed for hours. Vercel Cron supports 1-min
 *   schedules on the free tier and forwards a POST here. WP-Cron stays
 *   registered as a fallback for local dev and any host that supports
 *   it; the per-chain AdvisoryLock in NftEthIndexerWorker::runForChain
 *   makes duplicate ticks a no-op so running both is safe.
 *
 * Auth posture:
 *   - Header `X-Bcc-Internal` is required and compared in constant
 *     time against the `BCC_INTERNAL_CRON_SECRET` constant defined in
 *     wp-config.php. NO Authorization-bearer / WP-nonce / cookie path;
 *     this is a machine-to-machine endpoint, not a user session.
 *   - Missing constant → 503 (deployment misconfigured; operator must
 *     define the secret before Vercel can call us).
 *   - Missing/empty/mismatched header → 401, with per-IP rate-limited
 *     warning log (same recipe as HeliusWebhookEndpoint::logSignatureFailure
 *     so probing doesn't flood the log).
 *   - Unlike the Helius webhook, this endpoint does NOT always-200 —
 *     real status codes help debug Vercel-cron-to-WP connectivity.
 *
 * Idempotency:
 *   Inherited from NftEthIndexerWorker::runForChain's per-chain
 *   advisory lock + checkpoint advance semantics. A WP-Cron tick and
 *   a Vercel-Cron tick racing for the same chain → the late one
 *   silently skips (lock not acquired).
 *
 * @package BCC\Trust\Onchain\REST
 * @since v2 (2026-05, Hostinger-cron-compat)
 */
final class IndexerTickEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const ROUTE_PATH      = '/internal/indexer/tick';

    private const SIGFAIL_TRANSIENT_PREFIX = 'bcc_indexer_tick_sigfail_';
    private const SIGFAIL_WINDOW_SECONDS   = 60;

    public static function register(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE_PATH,
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'handle'],
                // Public REST route — auth is the X-Bcc-Internal header,
                // verified inside `handle()`. WP's permission_callback
                // can't see custom headers cleanly, so the check lives
                // at the top of the handler.
                'permission_callback' => '__return_true',
            ]
        );
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Step 1: deployment-misconfiguration check. If the secret
        // constant isn't defined / is empty, refuse — we can't safely
        // accept anything until an operator pins the secret on both
        // ends.
        $expected = self::expectedSecret();
        if ($expected === '') {
            \BCC\Core\Log\Logger::error('[IndexerTickEndpoint] BCC_INTERNAL_CRON_SECRET not configured');
            return self::error(500, 'bcc_internal', 'Internal cron secret not configured.');
        }

        // Step 2: header check.
        $provided = (string) $request->get_header('x-bcc-internal');
        if ($provided === '' || !hash_equals($expected, $provided)) {
            self::logSignatureFailure();
            return self::error(401, 'bcc_unauthorized', 'Invalid or missing internal secret.');
        }

        // Step 3: run the tick. Wrapped in try/catch so an exception
        // surfaces as a structured 5xx rather than a fatal whitescreen
        // to the caller (Vercel logs the response body; we want
        // diagnostic value there).
        $startedAt = microtime(true);
        try {
            NftEthIndexerWorker::runAllChains();
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[IndexerTickEndpoint] runAllChains threw', [
                'error' => $e->getMessage(),
            ]);
            return self::error(500, 'bcc_internal', 'Indexer run threw: ' . $e->getMessage());
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $response = new WP_REST_Response([
            'ok'         => true,
            'ran_at'     => gmdate('Y-m-d\TH:i:s\Z'),
            'elapsed_ms' => $elapsedMs,
        ], 200);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private static function expectedSecret(): string
    {
        return defined('BCC_INTERNAL_CRON_SECRET')
            ? (string) constant('BCC_INTERNAL_CRON_SECRET')
            : '';
    }

    /**
     * Log signature-mismatch attempts at most once per
     * SIGFAIL_WINDOW_SECONDS per source IP. Same shape as
     * HeliusWebhookEndpoint::logSignatureFailure — defends against
     * log-flood amplification while preserving evidence of probing.
     */
    private static function logSignatureFailure(): void
    {
        $ip = IpResolver::getClientIp();
        $key = self::SIGFAIL_TRANSIENT_PREFIX . md5($ip);
        $count = (int) (get_transient($key) ?: 0);

        if ($count === 0) {
            \BCC\Core\Log\Logger::warning('[IndexerTickEndpoint] signature mismatch', [
                'source_ip' => $ip,
            ]);
        }

        set_transient($key, $count + 1, self::SIGFAIL_WINDOW_SECONDS);
    }

    private static function error(int $status, string $code, string $message): WP_REST_Response
    {
        $resp = new WP_REST_Response([
            'error' => [
                'code'    => $code,
                'message' => $message,
                'status'  => $status,
            ],
        ], $status);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }
}
