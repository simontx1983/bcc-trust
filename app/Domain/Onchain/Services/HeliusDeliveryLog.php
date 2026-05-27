<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded per-delivery audit trail for inbound Helius webhooks.
 *
 * Operator OS v1 Phase 2 item #2 — closes the diagnostic gap for the
 * single external surface that can stall Solana indexing silently.
 * Complements but does NOT replace the existing observability:
 *
 *   - bcc_helius_signature_*_total wp_options counters (lifetime)
 *   - bcc_helius_last_delivery_at wp_options (X5 freshness signal)
 *   - helius_dedup.replay_skipped DegradationMetric (2h bucketed)
 *   - Logger::info/warning/error for narrative log lines
 *
 * The pieces above answer "is the pipe alive?" + "are we being
 * probed?" + "any errors?" — this class answers "show me the last
 * N deliveries with shape + outcome" so an operator triaging an
 * incident can look at concrete receipts instead of inferring from
 * counters.
 *
 * Storage: single `autoload=false` wp_options key holding a JSON
 * array of the last MAX_ENTRIES delivery rows. Same persistence
 * substrate as the existing counters; no schema migration needed.
 * MAX_ENTRIES = 200 — enough to span ~10 minutes at peak Helius
 * delivery rates while staying small enough that a full read for
 * the admin page is sub-millisecond.
 *
 * Trade-off accepted: a probe storm of auth_failed entries will
 * push legitimate deliveries out of the 200-entry window. That is
 * itself an operational signal (operators see lots of auth_failed
 * with the same IP and react), and the existing
 * SIGFAIL_TRANSIENT rate-limiter inside HeliusWebhookEndpoint
 * already caps the LOG line volume per IP — only the delivery-log
 * row is unrate-limited here so the audit trail is honest.
 *
 * @phpstan-type DeliveryEntry array{
 *     received_at: int,
 *     outcome: string,
 *     auth_passed: bool,
 *     tx_count: int,
 *     events: int,
 *     replays: int,
 *     ip: string,
 * }
 */
final class HeliusDeliveryLog
{
    public const OPTION_KEY   = 'bcc_helius_delivery_log';
    public const MAX_ENTRIES  = 200;

    /** Valid outcome values. Mirrored in the admin renderer for badge coloring. */
    public const OUTCOME_AUTH_FAILED = 'auth_failed';
    public const OUTCOME_NO_PAYLOAD  = 'no_payload';
    public const OUTCOME_NO_CHAIN    = 'no_chain';
    public const OUTCOME_PROCESSED   = 'processed';

    /**
     * Append a delivery row + LRU-trim to MAX_ENTRIES.
     *
     * Non-blocking by intent: failure to record should never break
     * the webhook handler, which always returns 200 to Helius.
     *
     * @param array{
     *     outcome: string,
     *     auth_passed?: bool,
     *     tx_count?: int,
     *     events?: int,
     *     replays?: int,
     *     ip?: string,
     * } $entry
     */
    public static function record(array $entry): void
    {
        $normalized = [
            'received_at' => time(),
            'outcome'     => (string) ($entry['outcome']     ?? 'unknown'),
            'auth_passed' => (bool)   ($entry['auth_passed'] ?? false),
            'tx_count'    => (int)    ($entry['tx_count']    ?? 0),
            'events'      => (int)    ($entry['events']      ?? 0),
            'replays'     => (int)    ($entry['replays']     ?? 0),
            'ip'          => (string) ($entry['ip']          ?? ''),
        ];

        $current = get_option(self::OPTION_KEY, []);
        if (!is_array($current)) {
            $current = [];
        }

        $current[] = $normalized;
        if (count($current) > self::MAX_ENTRIES) {
            $current = array_slice($current, -self::MAX_ENTRIES);
        }

        // autoload=false: the log is read only by the admin page, not
        // on every request. Keeps it off the autoloaded-options blob.
        update_option(self::OPTION_KEY, $current, false);
    }

    /**
     * Return the most-recent N entries, newest first.
     *
     * @return list<array{received_at:int,outcome:string,auth_passed:bool,tx_count:int,events:int,replays:int,ip:string}>
     */
    public static function recent(int $limit = 50): array
    {
        $current = get_option(self::OPTION_KEY, []);
        if (!is_array($current)) {
            return [];
        }

        $limit = max(1, $limit);
        $tail  = array_slice($current, -$limit);

        return array_values(array_reverse($tail));
    }

    /**
     * Clear the entire log. Used by the "Clear log" admin action.
     */
    public static function clear(): void
    {
        delete_option(self::OPTION_KEY);
    }
}
