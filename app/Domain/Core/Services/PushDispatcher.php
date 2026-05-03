<?php
/**
 * PushDispatcher — V2 Phase 1 web-push send pipeline.
 *
 * Two-step pipeline:
 *
 *   enqueue($recipientId, $eventType, $payload)
 *     1. Bail if push isn't enabled for this user/event pair.
 *     2. If no pending queue exists for (recipient, event), seed one
 *        with count=1 + the payload, and schedule a flush 5 minutes
 *        out via Action Scheduler.
 *     3. If a queue already exists, increment its count and let the
 *        already-scheduled flush pick up the new total.
 *
 *   flush($recipientId, $eventType)
 *     1. Atomically pop the queue (delete-before-send so a parallel
 *        flush never double-delivers).
 *     2. Render the appropriate single-vs-aggregated body via PushPayload.
 *     3. Fan out to every active subscription for the user via
 *        minishlink/web-push.
 *     4. Tombstone any 404/410 subscriptions; record metrics for
 *        every outcome.
 *
 * The 5-minute debounce + count-aggregation pattern ensures rapid-fire
 * events (3 reviews in a minute) become exactly one push ("3 new
 * reviews on Blacksmith Node"), not three buzzes.
 *
 * The dispatcher is intentionally a separate service from
 * NotificationDispatcher (which writes the bell) — bell is sync,
 * push fans out via Action Scheduler, and the two surfaces have
 * independent failure modes.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Phase 1
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Cron\AsyncDispatcher;
use BCC\Core\Log\Logger;
use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\Support\NotificationPrefs;
use BCC\Trust\Core\Support\PushMetrics;
use BCC\Trust\Core\Support\PushPayload;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

if (!defined('ABSPATH')) {
    exit;
}

final class PushDispatcher
{
    public const FLUSH_HOOK    = 'bcc_trust_push_flush';
    private const AS_GROUP     = 'bcc-push';
    private const DEBOUNCE_SECS = 300;  // P1.E2 — 5-min per-(recipient, type) window
    private const QUEUE_TTL    = 360;   // 1 min more than debounce so a slightly-late flush still finds the queue

    /** Transient key prefix. Keys are bcc_push_q_{user_id}_{event_type}. */
    private const QUEUE_KEY_PREFIX = 'bcc_push_q_';

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptionRepo,
    ) {
    }

    /**
     * Enqueue a push event. Idempotent within the debounce window — a
     * second enqueue for the same (recipient, event) increments the
     * count instead of scheduling a second flush.
     *
     * Bails silently when the recipient has push disabled (master OFF
     * or per-event OFF) — the bell still wrote; we just skip the push
     * channel for this user.
     *
     * @param array<string, mixed> $payload Free-form metadata for body
     *   rendering. Keys consumed by PushPayload: actor_handle, page_id,
     *   page_name, dispute_id, outcome.
     */
    public function enqueue(int $recipientId, string $eventType, array $payload): void
    {
        if ($recipientId <= 0) {
            return;
        }
        if (!in_array($eventType, NotificationPrefs::PUSH_TYPES, true)) {
            return;
        }
        if (!NotificationPrefs::isPushEnabled($recipientId, $eventType)) {
            return;
        }

        $key      = self::queueKey($recipientId, $eventType);
        $existing = get_transient($key);

        if (!is_array($existing)) {
            set_transient(
                $key,
                [
                    'count'         => 1,
                    'first_payload' => $payload,
                    'first_at'      => time(),
                ],
                self::QUEUE_TTL
            );
            AsyncDispatcher::scheduleSingle(
                time() + self::DEBOUNCE_SECS,
                self::FLUSH_HOOK,
                [$recipientId, $eventType],
                self::AS_GROUP
            );
            return;
        }

        // Subsequent event in the same window — accumulate. The
        // already-scheduled flush will pick this up.
        $count                 = isset($existing['count']) && is_int($existing['count']) ? $existing['count'] : 0;
        $existing['count']     = $count + 1;
        if (!isset($existing['first_payload'])) {
            $existing['first_payload'] = $payload;
        }
        set_transient($key, $existing, self::QUEUE_TTL);
    }

    /**
     * Hook handler for `bcc_trust_push_flush`. Pops the queue and
     * sends the resulting push to every device the recipient has
     * registered.
     *
     * Atomic claim: delete-before-send. Two flushes for the same
     * (recipient, event) racing each other will see exactly one
     * non-empty queue; the second exits as a no-op.
     */
    public function flush(int $recipientId, string $eventType): void
    {
        if ($recipientId <= 0) {
            return;
        }
        if (!in_array($eventType, NotificationPrefs::PUSH_TYPES, true)) {
            return;
        }

        $key  = self::queueKey($recipientId, $eventType);
        $data = get_transient($key);
        delete_transient($key);

        if (!is_array($data)) {
            return;
        }
        $count        = isset($data['count']) && is_int($data['count']) ? $data['count'] : 0;
        $firstPayload = isset($data['first_payload']) && is_array($data['first_payload']) ? $data['first_payload'] : [];
        if ($count <= 0) {
            return;
        }

        $subs = $this->subscriptionRepo->findAllForUser($recipientId);
        if ($subs === []) {
            return;
        }

        $body = self::buildBody($eventType, $count, $firstPayload);
        $this->sendToSubscriptions($subs, $body, $eventType);
    }

    /**
     * @param list<object> $subs Rows from PushSubscriptionRepository
     * @param array<string, mixed> $body PushPayload output
     */
    private function sendToSubscriptions(array $subs, array $body, string $eventType): void
    {
        $auth = self::vapidAuth();
        if ($auth === null) {
            // VAPID not configured — log once per flush so admins see
            // why pushes aren't going out, but don't blow up the worker.
            Logger::warning('[PushDispatcher] VAPID keys missing, skipping flush', [
                'event_type' => $eventType,
                'subs'       => count($subs),
            ]);
            return;
        }

        $payloadJson = (string) wp_json_encode($body);

        $webPush = new WebPush(['VAPID' => $auth]);

        // Build endpoint → row-id map so we can tombstone by id when
        // a report comes back as expired. The web-push library only
        // identifies reports by endpoint URL, not by our row id.
        $endpointToId = [];
        foreach ($subs as $row) {
            if (!isset($row->endpoint, $row->id, $row->p256dh_key, $row->auth_key)) {
                continue;
            }
            $endpoint = (string) $row->endpoint;
            $endpointToId[$endpoint] = (int) $row->id;

            try {
                $sub = Subscription::create([
                    'endpoint'        => $endpoint,
                    'publicKey'       => (string) $row->p256dh_key,
                    'authToken'       => (string) $row->auth_key,
                    'contentEncoding' => 'aes128gcm',
                ]);
            } catch (\Throwable $e) {
                Logger::warning('[PushDispatcher] subscription build failed', [
                    'event_type' => $eventType,
                    'sub_id'     => (int) $row->id,
                    'error'      => $e->getMessage(),
                ]);
                PushMetrics::recordFailure($eventType);
                continue;
            }

            PushMetrics::recordAttempt($eventType);
            $webPush->queueNotification($sub, $payloadJson);
        }

        try {
            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getEndpoint();
                $rowId    = $endpointToId[$endpoint] ?? 0;

                if ($report->isSuccess()) {
                    PushMetrics::recordSuccess($eventType);
                    if ($rowId > 0) {
                        $this->subscriptionRepo->touchLastUsed($rowId);
                    }
                    continue;
                }

                if ($report->isSubscriptionExpired()) {
                    PushMetrics::recordTombstone($eventType);
                    if ($rowId > 0) {
                        $this->subscriptionRepo->delete($rowId);
                    }
                    continue;
                }

                // Other failure (5xx, network error, payload too large, etc.)
                PushMetrics::recordFailure($eventType);
                Logger::warning('[PushDispatcher] send failed', [
                    'event_type' => $eventType,
                    'endpoint'   => $endpoint,
                    'reason'     => $report->getReason(),
                ]);
            }
        } catch (\Throwable $e) {
            // Library-level explosion (e.g. malformed VAPID, GMP missing).
            // Mark every queued report as a failure so the metrics don't
            // silently underreport.
            $remaining = count($endpointToId);
            for ($i = 0; $i < $remaining; $i++) {
                PushMetrics::recordFailure($eventType);
            }
            Logger::error('[PushDispatcher] flush exception', [
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Per-event-type body builder. Switch lives here so the dispatcher
     * doesn't grow a knowledge of the message wording.
     *
     * @param array<string, mixed> $first
     * @return array<string, mixed>
     */
    private static function buildBody(string $eventType, int $count, array $first): array
    {
        return match ($eventType) {
            'review'             => PushPayload::forReview($count, $first),
            'endorse'            => PushPayload::forEndorse($count, $first),
            'dispute_outcome'    => PushPayload::forDisputeOutcome($count, $first),
            'panelist_selected'  => PushPayload::forPanelistSelected($count, $first),
            default              => [
                'title' => 'Blue Collar Crypto',
                'body'  => 'You have new activity.',
                'url'   => '/',
            ],
        };
    }

    private static function queueKey(int $recipientId, string $eventType): string
    {
        return self::QUEUE_KEY_PREFIX . $recipientId . '_' . $eventType;
    }

    /**
     * Read VAPID keys from wp-config.php constants. Returns null when
     * any of the three is missing — caller surfaces a single warning
     * log + skips dispatch rather than throwing inside the worker.
     *
     * @return array{subject: string, publicKey: string, privateKey: string}|null
     */
    private static function vapidAuth(): ?array
    {
        if (
            !defined('BCC_PUSH_VAPID_PUBLIC_KEY')
            || !defined('BCC_PUSH_VAPID_PRIVATE_KEY')
            || !defined('BCC_PUSH_VAPID_SUBJECT')
        ) {
            return null;
        }
        $public  = constant('BCC_PUSH_VAPID_PUBLIC_KEY');
        $private = constant('BCC_PUSH_VAPID_PRIVATE_KEY');
        $subject = constant('BCC_PUSH_VAPID_SUBJECT');

        if (
            !is_string($public)  || $public  === ''
            || !is_string($private) || $private === ''
            || !is_string($subject) || $subject === ''
        ) {
            return null;
        }

        return [
            'subject'    => $subject,
            'publicKey'  => $public,
            'privateKey' => $private,
        ];
    }
}
