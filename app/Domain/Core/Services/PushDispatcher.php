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
use BCC\Core\Http\SafeHttpClient;
use BCC\Core\Log\Logger;
use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Trust\Core\Repositories\NotificationRepository;
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
    /**
     * How late the flush may run and still find its window (F-78).
     *
     * The window used to live 360s, re-set on every event, while its flush
     * is scheduled ONCE at first event + 300s: a flush more than ~60s after
     * the window's last event found nothing, and the push was silently lost.
     * WP-cron on a quiet site runs on page loads, so minutes late is normal.
     * The window now lives first_at + DEBOUNCE_SECS + this grace, measured
     * from its START, never sliding with later events.
     */
    private const LATE_FLUSH_GRACE = 1800;

    /** Transient key prefix. Keys are bcc_push_q_{user_id}_{event_type}. */
    private const QUEUE_KEY_PREFIX = 'bcc_push_q_';

    /**
     * @param (\Closure(array<string, mixed>, array<string, mixed>): WebPush)|null $webPushFactory
     *   Builds the WebPush client for ONE subscription from (auth, Guzzle
     *   client options). Null = `new WebPush`. A test seam: it lets a test
     *   see the options each send is built with, and never touch the network.
     */
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptionRepo,
        private readonly ?\Closure $webPushFactory = null,
    ) {
    }

    /**
     * Guzzle client options for ONE push endpoint (F-75).
     *
     * ⚠ DNS REBINDING. The send-time check resolves the endpoint's host and
     * refuses a private IP, but Guzzle then resolved it AGAIN to connect, so
     * a hostile DNS server could answer public for the check and private for
     * the request. The fix is SafeHttpClient's own pin: the address the check
     * approved is handed to cURL as CURLOPT_RESOLVE, so the connection goes
     * there and nowhere else.
     *
     *  - `$pin` is SafeHttpClient::resolveEntryFor()'s "host:port:ip", or
     *    null for an IP-literal host (validated as public; no DNS to pin).
     *  - The handler is FORCED to cURL. Guzzle's default can be its stream
     *    handler, which silently ignores the `curl` option, so the pin would
     *    fail OPEN. It is wrapped in HandlerStack::create() because
     *    WebPush's expired-subscription report (410/404) comes from Guzzle's
     *    http_errors middleware; a bare handler would report those as sent.
     *  - Redirects stay off (audit HIGH #3): each hop could target a private
     *    IP and would not be pinned.
     *  - Proxy explicitly OFF. Unset, Guzzle adopts HTTP(S)_PROXY from the
     *    environment, and a proxy resolves the hostname ITSELF, silently
     *    bypassing the pin. An empty string is also cURL's documented way to
     *    disable an environment proxy.
     *
     * @return array<string, mixed>
     */
    public static function clientOptionsFor(?string $pin): array
    {
        $options = [
            'allow_redirects' => false,
            'proxy'           => '',
            'handler'         => \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\CurlMultiHandler()),
        ];
        if ($pin !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$pin]];
        }
        return $options;
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
     * F-74: a push never crosses a block. When the event has a person
     * behind it ($actorId > 0), a block in EITHER direction drops this push,
     * and so does a block table that cannot be read (fail closed, as F-99).
     * The bell is unaffected: PeepSo applies blocks to it on its own path.
     * System events (no actor) pass 0.
     *
     * F-516: `notification_id` in the payload is the bell row this event
     * wrote; anything but a positive int means none (0 nothing owed, -1 a
     * failed write, absent no bell). It is not part of the body's wording; it is
     * kept beside the actor's events, and the push sent at flush lists the
     * ids of the actors still counted, so opening it can mark them read.
     *
     * @param array<string, mixed> $payload Free-form metadata for body
     *   rendering. Keys consumed by PushPayload: actor_handle, page_id,
     *   page_name, dispute_id, outcome.
     */
    public function enqueue(int $recipientId, string $eventType, array $payload, int $actorId = 0): void
    {
        if ($recipientId <= 0) {
            return;
        }
        if (!in_array($eventType, NotificationPrefs::PUSH_TYPES, true)) {
            // Fail closed, but never silently — an unknown type here means
            // a dispatch site and the PUSH_TYPES allowlist have drifted
            // (caught live 2026-07-06: holder_community_live pushes were
            // dropped for every recipient with no trace).
            Logger::warning('[PushDispatcher] enqueue dropped: event type not in PUSH_TYPES allowlist', [
                'event_type'   => $eventType,
                'recipient_id' => $recipientId,
            ]);
            return;
        }
        if (!NotificationPrefs::isPushEnabled($recipientId, $eventType)) {
            return;
        }
        if ($actorId > 0) {
            $blocked = PeepSoBlockRepository::isMutuallyBlockedOrUnknown($recipientId, $actorId);
            if ($blocked !== false) {
                if ($blocked === null) {
                    // Never silent: an unreadable block table drops pushes.
                    Logger::warning('[PushDispatcher] enqueue dropped: block state unreadable', [
                        'event_type'   => $eventType,
                        'recipient_id' => $recipientId,
                    ]);
                }
                return;
            }
        }

        $bellId = isset($payload['notification_id']) && is_int($payload['notification_id']) ? $payload['notification_id'] : 0;
        unset($payload['notification_id']);
        $ids = $bellId > 0 ? [$bellId] : [];

        $key      = self::queueKey($recipientId, $eventType);
        $existing = get_transient($key);

        // F-78: a window past its lifetime is abandoned (its flush was lost
        // or never ran); the next event starts a fresh window with its own
        // flush instead of piling into one nobody will send.
        if (!is_array($existing) || self::windowRemaining($existing) <= 0) {
            set_transient(
                $key,
                [
                    'count'         => 1,
                    'first_payload' => $payload,
                    'first_at'      => time(),
                    // F-189: who contributed, so flush() can re-check blocks.
                    'actors'        => [$actorId => ['n' => 1, 'payload' => $payload, 'ids' => $ids]],
                ],
                self::DEBOUNCE_SECS + self::LATE_FLUSH_GRACE
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
        $actors = isset($existing['actors']) && is_array($existing['actors']) ? $existing['actors'] : [];
        if (self::idCount($actors) >= self::MAX_NOTIFICATION_IDS) {
            // F-516: the push lists at most as many ids as mark-read takes;
            // an event past that is still counted, its bell stays unread.
            $ids = [];
        }
        if (isset($actors[$actorId]) && is_array($actors[$actorId])) {
            $actors[$actorId]['n'] = (int) ($actors[$actorId]['n'] ?? 0) + 1;
            $known = isset($actors[$actorId]['ids']) && is_array($actors[$actorId]['ids']) ? $actors[$actorId]['ids'] : [];
            $actors[$actorId]['ids'] = array_merge($known, $ids);
        } elseif (count($actors) < self::MAX_TRACKED_ACTORS) {
            $actors[$actorId] = ['n' => 1, 'payload' => $payload, 'ids' => $ids];
        } else {
            // F-189: an actor beyond the cap could not be re-checked for a
            // block at flush, so their event is not counted (fail closed).
            return;
        }
        $existing['actors']    = $actors;
        $count                 = isset($existing['count']) && is_int($existing['count']) ? $existing['count'] : 0;
        $existing['count']     = $count + 1;
        if (!isset($existing['first_payload'])) {
            $existing['first_payload'] = $payload;
        }
        // The REMAINING lifetime, so a busy window cannot outlive its flush
        // by sliding its own expiry forward event after event.
        set_transient($key, $existing, self::windowRemaining($existing));
    }

    /**
     * Seconds a queued window has left: first_at + DEBOUNCE_SECS +
     * LATE_FLUSH_GRACE − now. A window without a usable first_at is treated
     * as already over, so it is replaced rather than extended.
     *
     * @param array<string, mixed> $window
     */
    private static function windowRemaining(array $window): int
    {
        $firstAt = isset($window['first_at']) && is_int($window['first_at']) ? $window['first_at'] : 0;
        if ($firstAt <= 0) {
            return 0;
        }
        return $firstAt + self::DEBOUNCE_SECS + self::LATE_FLUSH_GRACE - time();
    }

    /** F-189: distinct actors one debounce window tracks for the flush-time block re-check. */
    private const MAX_TRACKED_ACTORS = 50;

    /** F-516: bell ids one push lists, as many as one mark-read call takes. */
    private const MAX_NOTIFICATION_IDS = NotificationRepository::MARK_READ_MANY_MAX;

    /**
     * Bell ids a queued window already holds, across all its actors.
     *
     * @param array<int|string, mixed> $actors
     */
    private static function idCount(array $actors): int
    {
        $n = 0;
        foreach ($actors as $entry) {
            if (is_array($entry) && isset($entry['ids']) && is_array($entry['ids'])) {
                $n += count($entry['ids']);
            }
        }
        return $n;
    }

    /**
     * F-189: re-check, at send time, every actor counted into a queued push.
     * A block created AFTER an event was queued must still stop it: the
     * enqueue-time check (F-74) only saw the moment of enqueue, and the
     * window stays open for 5 minutes.
     *
     * An actor now blocked in either direction, OR whose block state cannot
     * be read (fail closed, as F-99; the probe has no cache, so a failed
     * read is never remembered), is removed with all of their events. The
     * push names the first actor who remains. System events (actor 0) are
     * never looked up. F-516: `ids` are the bell ids of the actors who
     * remain, never of one who was removed.
     *
     * @param array<int|string, mixed> $actors the queue's 'actors' map
     * @return array{count: int, first_payload: array<string, mixed>, ids: list<int>}
     */
    private function survivingEvents(int $recipientId, string $eventType, array $actors): array
    {
        $count        = 0;
        $firstPayload = null;
        $ids          = [];
        foreach ($actors as $actorKey => $entry) {
            $actorId = (int) $actorKey;
            $n       = is_array($entry) ? (int) ($entry['n'] ?? 0) : 0;
            if ($n <= 0) {
                continue;
            }
            if ($actorId > 0) {
                $blocked = PeepSoBlockRepository::isMutuallyBlockedOrUnknown($recipientId, $actorId);
                if ($blocked !== false) {
                    if ($blocked === null) {
                        Logger::warning('[PushDispatcher] flush dropped an actor: block state unreadable', [
                            'event_type'   => $eventType,
                            'recipient_id' => $recipientId,
                        ]);
                    }
                    continue;
                }
            }
            $count += $n;
            if ($firstPayload === null) {
                $firstPayload = is_array($entry['payload'] ?? null) ? $entry['payload'] : [];
            }
            foreach (is_array($entry['ids'] ?? null) ? $entry['ids'] : [] as $id) {
                if (is_int($id) && $id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        return [
            'count'         => $count,
            'first_payload' => $firstPayload ?? [],
            'ids'           => array_slice(array_values($ids), 0, self::MAX_NOTIFICATION_IDS),
        ];
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
        $ids = [];
        if (isset($data['actors']) && is_array($data['actors'])) {
            // F-189: only the events of actors still unblocked are sent.
            $surviving    = $this->survivingEvents($recipientId, $eventType, $data['actors']);
            $count        = $surviving['count'];
            $firstPayload = $surviving['first_payload'];
            $ids          = $surviving['ids'];
        } else {
            // A window queued before F-189 (no actor list): sent as queued.
            // Its events already passed F-74's enqueue-time check.
            $count        = isset($data['count']) && is_int($data['count']) ? $data['count'] : 0;
            $firstPayload = isset($data['first_payload']) && is_array($data['first_payload']) ? $data['first_payload'] : [];
        }
        if ($count <= 0) {
            return;
        }

        $subs = $this->subscriptionRepo->findAllForUser($recipientId);
        if ($subs === []) {
            return;
        }

        $body = self::buildBody($eventType, $count, $firstPayload);
        if ($ids !== []) {
            // F-516: the bell rows this push stands for. The page the push
            // opens marks them read (POST /me/notifications/mark-read `ids`);
            // the service worker holds no credentials to do it itself.
            $body['notification_ids'] = $ids;
        }
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

        // F-75: the DNS pin only binds on cURL. Without it every send would
        // go out unpinned, so none goes out (as SafeHttpClient refuses).
        if (!function_exists('curl_init')) {
            Logger::error('[PushDispatcher] cURL missing, refusing to send unpinned pushes', [
                'event_type' => $eventType,
                'subs'       => count($subs),
            ]);
            foreach ($subs as $_) {
                PushMetrics::recordFailure($eventType);
            }
            return;
        }

        $payloadJson = (string) wp_json_encode($body);

        foreach ($subs as $row) {
            if (!isset($row->endpoint, $row->id, $row->p256dh_key, $row->auth_key)) {
                continue;
            }
            $endpoint = (string) $row->endpoint;

            // Delivery-time SSRF check + DNS pin (audit HIGH #3, F-75).
            // Store-time validation lives in MyPushSubscriptionEndpoint, but
            // rows predating that gate, or whose host now resolves to a
            // private IP, are caught here. prepareArgs() is SafeHttpClient's
            // single validation (https + public IP) AND returns the address
            // it approved, which the send is then pinned to.
            $pin  = null;
            $args = strtolower((string) wp_parse_url($endpoint, PHP_URL_SCHEME)) === 'https'
                ? SafeHttpClient::prepareArgs($endpoint, [])
                : new \WP_Error('push_endpoint_not_https', 'push endpoint is not https');
            if (!($args instanceof \WP_Error)) {
                $pin = SafeHttpClient::resolveEntryFor($args, $endpoint);
            }
            if ($args instanceof \WP_Error) {
                Logger::warning('[PushDispatcher] skipping subscription with unsafe endpoint', [
                    'event_type' => $eventType,
                    'sub_id'     => (int) $row->id,
                ]);
                PushMetrics::recordFailure($eventType);
                continue;
            }

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
                    'error'      => self::withoutEndpoint($e->getMessage(), $endpoint),
                ]);
                PushMetrics::recordFailure($eventType);
                continue;
            }

            PushMetrics::recordAttempt($eventType);
            $this->sendOne($auth, $pin, $sub, $payloadJson, (int) $row->id, $eventType);
        }
    }

    /**
     * Send ONE subscription through its own client, pinned to its own
     * endpoint's approved address (F-75). One client per subscription
     * because WebPush takes Guzzle options per client and the pin is per
     * endpoint; findAllForUser() caps a user at 25.
     *
     * @param array<string, mixed> $auth
     */
    private function sendOne(array $auth, ?string $pin, Subscription $sub, string $payloadJson, int $rowId, string $eventType): void
    {
        try {
            $vapid   = ['VAPID' => $auth];
            $options = self::clientOptionsFor($pin);
            $webPush = $this->webPushFactory !== null
                ? ($this->webPushFactory)($vapid, $options)
                : new WebPush($vapid, [], 30, $options);

            $webPush->queueNotification($sub, $payloadJson);

            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getEndpoint();

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
                    'sub_id'     => $rowId,
                ] + self::endpointForLog($endpoint) + [
                    'reason'     => self::withoutEndpoint($report->getReason(), $endpoint),
                ]);
            }
        } catch (\Throwable $e) {
            // Library-level explosion (e.g. malformed VAPID, GMP missing).
            // Count it as this subscription's failure so the metrics don't
            // silently underreport; the other subscriptions still send.
            PushMetrics::recordFailure($eventType);
            Logger::error('[PushDispatcher] flush exception', [
                'event_type' => $eventType,
                'sub_id'     => $rowId,
                'error'      => self::withoutEndpoint($e->getMessage(), $sub->getEndpoint()),
            ]);
        }
    }

    /**
     * What a log line may say about a push endpoint.
     *
     * ⚠ Never the URL itself. A Web Push endpoint is a per-device address:
     * with the subscription keys it reaches that one browser, and on its own
     * it still identifies the device. So the logs get the push service's
     * host, and the first 12 hex of the SHA-256 the subscriptions table
     * stores in `endpoint_hash`, which is enough to find the row.
     *
     * @return array{endpoint_host: string, endpoint_fp: string}
     */
    private static function endpointForLog(string $endpoint): array
    {
        return [
            'endpoint_host' => strtolower((string) wp_parse_url($endpoint, PHP_URL_HOST)),
            'endpoint_fp'   => substr(PushSubscriptionRepository::hashEndpoint($endpoint), 0, 12),
        ];
    }

    /**
     * A library message with every copy of the endpoint taken out. Guzzle's
     * errors quote the request line ("POST https://…/send/<token> resulted
     * in …"), and WebPush passes them on as the report's reason.
     *
     * Two passes. The exact copy goes first. Then any URL on the endpoint's
     * own host goes too, because Guzzle quotes the URI it BUILT, not the
     * string it was given: it lower-cases the host and percent-encodes what
     * RFC 3986 does not allow raw ("|" becomes "%7C", a space "%20"), so its
     * copy can differ from $endpoint and slip past the exact match. The
     * host itself is not secret (endpointForLog() logs it). The URL's tail
     * stops at a quote, backtick or angle bracket, which a URI cannot hold
     * raw, so the message keeps its own punctuation. If the pattern cannot
     * run, the message is withheld rather than logged half-redacted.
     */
    private static function withoutEndpoint(string $message, string $endpoint): string
    {
        if ($endpoint === '') {
            return $message;
        }
        $message = str_replace($endpoint, '[endpoint]', $message);
        $host    = (string) wp_parse_url($endpoint, PHP_URL_HOST);
        if ($host === '') {
            return $message;
        }
        return preg_replace(
            '~https?://' . preg_quote($host, '~') . '(?::\d+)?[/?#][^\s`\'"<>]*~i',
            '[endpoint]',
            $message
        ) ?? '[message withheld]';
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
            'review'                              => PushPayload::forReview($count, $first),
            'dispute_outcome'                     => PushPayload::forDisputeOutcome($count, $first),
            'mention'                             => PushPayload::forMention($count, $first),
            'hall_post'                           => PushPayload::forHallPost($count, $first),
            'comment_received'                    => PushPayload::forCommentReceived($count, $first),
            'attestation_vouch_received'          => PushPayload::forAttestationVouchReceived($count, $first),
            'attestation_stand_behind_received'   => PushPayload::forAttestationStandBehindReceived($count, $first),
            'attestation_revoked'                 => PushPayload::forAttestationRevoked($count, $first),
            'attestation_reaffirmed'              => PushPayload::forAttestationReaffirmed($count, $first),
            'holder_community_live'               => PushPayload::forHolderCommunityLive($count, $first),
            default                               => [
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
