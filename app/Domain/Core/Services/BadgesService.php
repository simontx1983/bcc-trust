<?php
/**
 * BadgesService — coalesced per-viewer badge payload for the
 * §4.31 /me/badges endpoint.
 *
 * Why this exists:
 *   The frontend used to poll three uncached endpoints continuously
 *   per authenticated tab:
 *     - GET /me/messages/unread-count        (5s → 30s adaptive)
 *     - GET /me/notifications/unread-count   (5s → 60s adaptive)
 *     - GET /me/conversations/{id}/messages  (5s flat while open)
 *
 *   On Hostinger Business shared (~25–40 PHP-FPM workers) this caps
 *   the platform at roughly 30–80 concurrent active users before
 *   COUNT(*) contention saturates a worker. Coalescing the badge
 *   reads into one cached endpoint moves that ceiling ~10×.
 *
 * Cache shape (§5 generation-counter pattern):
 *   - Group: `bcc_badges`
 *   - Generation key:   `me_badges_gen:{userId}`
 *   - Payload key:      `me_badges:{userId}:gen{N}:{openThreadsKey}`
 *   - TTL:              BADGE_CACHE_TTL_SECONDS (30s — safety net so
 *                       a missed bump cannot leave a viewer permanently
 *                       stale; sized at/above the F1 client poll cadence
 *                       so typical polls hit the cache)
 *
 *   Bumped by every mutation that could change a viewer's badge state:
 *     - NotificationDispatcher::dispatch          (bell row created)
 *     - NotificationsEndpoint::markRead           (bell row marked read)
 *     - MessagesService::sendMessage              (DM arrives)
 *     - MessagesService::markRead / getThread     (DM viewed)
 *
 *   The 30s TTL means worst-case staleness is 30s when an invalidation
 *   point is missed (or when PeepSo's own write path emits a bell row
 *   that BCC didn't trigger — PeepSo can do that for plugin-internal
 *   reasons). Acceptable for badge UX.
 *
 * @package BCC\Trust\Core\Services
 * @since v2 (2026-05, polling-coalesce)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoMessageRepository;
use BCC\Trust\Core\Repositories\NotificationRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class BadgesService
{
    /** Cache group for the badge generation counters + payloads. */
    public const CACHE_GROUP = 'bcc_badges';

    /**
     * Payload TTL — safety net if a generation bump is missed.
     *
     * Set to 30s to sit at/above the F1 client poll cadence (dominant
     * unread-but-idle tab polls every 25s). With the prior 15s TTL the poll
     * interval exceeded the TTL, so most single-tab polls missed the per-user
     * cache and recomputed the unread COUNTs every time. 30s lets the typical
     * poll hit cache, cutting badge DB load ~2-3x. Real badge events still
     * refresh immediately via the generation counter (bumpForUser) — only the
     * worst-case missed-bump staleness window widens 15s → 30s.
     */
    public const BADGE_CACHE_TTL_SECONDS = 30;

    /** Hard cap on how many open-thread hints the endpoint will project. */
    public const OPEN_THREADS_MAX = 5;

    public function __construct(
        private readonly NotificationRepository $notificationRepo,
        private readonly MessagesService $messagesService
    ) {
    }

    /**
     * Build (or return cached) badge payload for `$viewerId`.
     *
     * `$openThreadIds` is the frontend's "I'm watching these
     * conversations right now" hint — bounded to OPEN_THREADS_MAX and
     * server-side auth-gated by PeepSoMessageRepository::getLatestPerThreadForViewer
     * (threads the viewer isn't a participant of are silently absent
     * from the map).
     *
     * @param list<int> $openThreadIds
     * @return array{
     *   messages_unread: int,
     *   notifications_unread: int,
     *   open_thread_hints: array<int, array{latest_message_id: int, posted_at: string}>,
     *   polled_at: string
     * }
     */
    public function getBadges(int $viewerId, array $openThreadIds): array
    {
        if ($viewerId <= 0) {
            return self::emptyPayload();
        }

        $threadIds = self::normalizeOpenThreadIds($openThreadIds);
        $threadKey = $threadIds === [] ? 'none' : implode('_', $threadIds);

        $gen      = $this->getGeneration($viewerId);
        $cacheKey = "me_badges:{$viewerId}:gen{$gen}:{$threadKey}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        $cachedPayload = self::reshapeCached($cached);
        if ($cachedPayload !== null) {
            // Refresh polled_at on hit so the client can still detect
            // staleness from request time even though the underlying
            // counts are from up to 15s ago.
            $cachedPayload['polled_at'] = self::nowIso();
            return $cachedPayload;
        }

        $payload = [
            'messages_unread'      => $this->messagesService->getUnreadCount($viewerId),
            'notifications_unread' => $this->notificationRepo->countUnreadForUser($viewerId),
            'open_thread_hints'    => $threadIds === []
                ? []
                : PeepSoMessageRepository::getLatestPerThreadForViewer($viewerId, $threadIds),
            'polled_at'            => self::nowIso(),
        ];

        wp_cache_set($cacheKey, $payload, self::CACHE_GROUP, self::BADGE_CACHE_TTL_SECONDS);

        return $payload;
    }

    /**
     * Bump the badge generation for `$userId`. Called from every
     * mutation that could change a viewer's badge state — bell row
     * created/marked-read, DM sent/viewed. The next /me/badges read
     * misses the cache and reads fresh.
     *
     * No-op for invalid ids so callers can pass through whatever
     * the upstream gave them without pre-checking.
     */
    public static function bumpForUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $key = "me_badges_gen:{$userId}";
        $result = wp_cache_incr($key, 1, self::CACHE_GROUP);
        if ($result === false) {
            // Counter didn't exist yet — initialise to 2 so the next
            // read sees a different generation than the implicit 1
            // returned by getGeneration's fallback.
            wp_cache_set($key, 2, self::CACHE_GROUP, 0);
        }
    }

    /**
     * Bump the badge generation for every `$userId` in the list.
     * Convenience wrapper for fan-out paths (group message → bump for
     * every participant).
     *
     * @param list<int> $userIds
     */
    public static function bumpForUsers(array $userIds): void
    {
        $seen = [];
        foreach ($userIds as $id) {
            $i = (int) $id;
            if ($i > 0 && !isset($seen[$i])) {
                $seen[$i] = true;
                self::bumpForUser($i);
            }
        }
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Validate a cache value retrieved via `wp_cache_get` (which is
     * typed `mixed`) and re-project it into the strict envelope shape.
     * Returns `null` on any structural mismatch — caller falls through
     * to a fresh build. Keeps the public return type tight at PHPStan
     * level 8 without `@var` overrides.
     *
     * @param mixed $cached
     * @return array{
     *   messages_unread: int,
     *   notifications_unread: int,
     *   open_thread_hints: array<int, array{latest_message_id: int, posted_at: string}>,
     *   polled_at: string
     * }|null
     */
    private static function reshapeCached($cached): ?array
    {
        if (!is_array($cached)) {
            return null;
        }
        if (
            !isset($cached['messages_unread'], $cached['notifications_unread'], $cached['open_thread_hints'])
            || !is_int($cached['messages_unread'])
            || !is_int($cached['notifications_unread'])
            || !is_array($cached['open_thread_hints'])
        ) {
            return null;
        }

        $hints = [];
        foreach ($cached['open_thread_hints'] as $rootId => $hint) {
            $rootIdInt = (int) $rootId;
            if (
                $rootIdInt <= 0
                || !is_array($hint)
                || !isset($hint['latest_message_id'], $hint['posted_at'])
                || !is_int($hint['latest_message_id'])
                || !is_string($hint['posted_at'])
            ) {
                continue;
            }
            $hints[$rootIdInt] = [
                'latest_message_id' => $hint['latest_message_id'],
                'posted_at'         => $hint['posted_at'],
            ];
        }

        return [
            'messages_unread'      => $cached['messages_unread'],
            'notifications_unread' => $cached['notifications_unread'],
            'open_thread_hints'    => $hints,
            'polled_at'            => isset($cached['polled_at']) && is_string($cached['polled_at'])
                ? $cached['polled_at']
                : self::nowIso(),
        ];
    }

    private function getGeneration(int $userId): int
    {
        $key = "me_badges_gen:{$userId}";
        $gen = wp_cache_get($key, self::CACHE_GROUP);
        if ($gen === false) {
            $gen = 1;
            wp_cache_set($key, $gen, self::CACHE_GROUP, 0);
        }
        return (int) $gen;
    }

    /**
     * Normalise + bound + sort the open-thread id list so the cache key
     * is deterministic regardless of param order, and abuse can't blow
     * the cache by feeding 10k ids.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private static function normalizeOpenThreadIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $i = (int) $id;
            if ($i > 0) {
                $clean[$i] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $list = array_keys($clean);
        sort($list);
        return array_slice($list, 0, self::OPEN_THREADS_MAX);
    }

    /**
     * @return array{
     *   messages_unread: int,
     *   notifications_unread: int,
     *   open_thread_hints: array<int, array{latest_message_id: int, posted_at: string}>,
     *   polled_at: string
     * }
     */
    private static function emptyPayload(): array
    {
        return [
            'messages_unread'      => 0,
            'notifications_unread' => 0,
            'open_thread_hints'    => [],
            'polled_at'            => self::nowIso(),
        ];
    }

    private static function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
