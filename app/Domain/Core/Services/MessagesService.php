<?php
/**
 * MessagesService — orchestrator for the §4.X DM surface.
 *
 * Composes:
 *   - bcc-core PeepSoMessageRepository (read path against PeepSo's
 *     conversation graph)
 *   - bcc-core PeepSoMessageWriter (only write path that hits
 *     peepso_message_* + the peepso-message CPT)
 *   - bcc-core PeepSoBlockRepository (mutual-block gate)
 *   - peepso-messages PeepSoChatModel (chat_enabled + chat_friends_only
 *     primitives — exposed via MyMessagesPrefsEndpoint)
 *   - peepso-friends PeepSoFriendsModel (are_friends gate)
 *   - bcc-trust UserViewService (slim author view-models for the
 *     conversation list + thread participants)
 *
 * Privacy gates (server-enforced — frontend never decides):
 *   1. Sender's `peepso_chat_enabled` user_meta — false → bcc_forbidden
 *   2. Recipient's `peepso_chat_enabled` user_meta — false → bcc_forbidden
 *   3. Recipient's `peepso_chat_friends_only` AND PeepSoFriendsModel::are_friends
 *      returns false → bcc_forbidden
 *   4. PeepSoBlockRepository::isMutuallyBlocked(sender, recipient) →
 *      bcc_not_found (404, info-leak shielded; never reveals the block)
 *
 * Per-write rate limit:
 *   30 messages per 5 minutes per sender across all conversations
 *   (bcc_rate_limited 429 on excess). Bounded SQL via
 *   PeepSoMessageRepository::countRecentByAuthor.
 *
 * Per-message length cap:
 *   5000 chars after trim. PeepSo's wp_posts.post_content is LONGTEXT
 *   and has no upstream cap; we set our own to bound abuse + DoS.
 *
 * @package BCC\Trust\Core\Services
 * @since v1.5 (2026-05, BCC messages adapter)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\PeepSo\PeepSoMessageWriter;
use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Core\Repositories\PeepSoMessageRepository;
use BCC\Trust\Core\Repositories\UserMiniRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type UserMini array{
 *     id: int,
 *     handle: string,
 *     display_name: string,
 *     avatar_url: string
 * }
 */
final class MessagesService
{
    /** Hard cap on a single message's length (chars after trim). */
    public const MESSAGE_BODY_MAX_LENGTH = 5000;

    /** Burst-seatbelt rate limit: 30 messages per 5 minutes per sender. */
    public const RATE_LIMIT_PER_WINDOW = 30;
    public const RATE_LIMIT_WINDOW_SECONDS = 300;

    /** Inbox / thread / preview defaults. */
    public const INBOX_PER_PAGE_DEFAULT = 20;
    public const INBOX_PER_PAGE_MAX     = 50;
    public const THREAD_PER_PAGE_DEFAULT = 30;
    public const THREAD_PER_PAGE_MAX     = 100;

    /** Last-message preview cap on the inbox list (chars after strip). */
    private const PREVIEW_MAX_CHARS = 200;

    public function __construct(
        private readonly UserMiniRepository $userMiniRepo
    ) {
    }

    // ── Inbox ────────────────────────────────────────────────────────────

    /**
     * Paginated conversation list for a viewer.
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, total_pages: int}
     * }|array{error: string, message: string}
     */
    public function listInbox(int $viewerId, int $page, int $perPage): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        $page    = max(1, $page);
        $perPage = max(1, min($perPage, self::INBOX_PER_PAGE_MAX));
        $offset  = ($page - 1) * $perPage;

        $rows = PeepSoMessageRepository::findConversationsForUser($viewerId, $perPage, $offset);
        $total = PeepSoMessageRepository::countConversationsForUser($viewerId);

        if ($rows === []) {
            return [
                'items'      => [],
                'pagination' => [
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'total'       => $total,
                    'total_pages' => max(1, (int) ceil($total / $perPage)),
                ],
            ];
        }

        // Batched unread counts — single SQL across the whole page.
        $rootIds = array_map(static fn (array $r): int => (int) $r['root_msg_id'], $rows);
        $unreadByRoot = PeepSoMessageRepository::getUnreadCountsByConversation($viewerId, $rootIds);

        // Batched author + participant view-models — collect every
        // user_id we need, then resolve them in one pass via
        // UserViewService.
        $authorIds      = [];
        $participantMap = []; // root_msg_id => list<int>
        foreach ($rows as $r) {
            $rootId = (int) $r['root_msg_id'];
            $authorIds[(int) $r['last_msg_author_id']] = true;
            $participantMap[$rootId] = PeepSoMessageRepository::getParticipantUserIds($rootId, $viewerId);
            foreach ($participantMap[$rootId] as $pid) {
                $authorIds[$pid] = true;
            }
        }
        $userViews = $this->resolveUserMinisById(array_keys($authorIds));

        $items = [];
        foreach ($rows as $r) {
            $rootId       = (int) $r['root_msg_id'];
            $participants = $participantMap[$rootId] ?? [];
            $peerId       = self::resolvePeerId($participants, $viewerId, (bool) $r['is_group']);

            $items[] = [
                'id'             => $rootId,
                'is_group'       => (bool) $r['is_group'],
                'participants'   => self::resolveUserMinis($participants, $userViews),
                'peer'           => $peerId !== null ? ($userViews[$peerId] ?? null) : null,
                'last_message'   => [
                    'id'          => (int) $r['last_msg_id'],
                    'author'      => $userViews[(int) $r['last_msg_author_id']] ?? null,
                    'preview'     => self::previewBody((string) $r['last_msg_content']),
                    'posted_at'   => self::isoFromMysql((string) $r['last_msg_posted_at']),
                ],
                'unread_count'   => $unreadByRoot[$rootId] ?? 0,
                'last_activity'  => $r['last_activity'] !== null
                    ? self::isoFromMysql((string) $r['last_activity'])
                    : self::isoFromMysql((string) $r['last_msg_posted_at']),
            ];
        }

        return [
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    // ── Thread ───────────────────────────────────────────────────────────

    /**
     * Paginated message history for a single conversation. Caller is
     * responsible for the URL `{id}` mapping to a root msg id; we
     * normalise to the root via PeepSoMessageRepository::findRootConversationId
     * here so deep-link clicks on a specific message id still work.
     *
     * @return array{
     *     conversation: array<string, mixed>,
     *     items: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int|null, has_more: bool}
     * }|array{error: string, message: string}
     */
    public function getThread(int $viewerId, int $rootMsgId, int $page, int $perPage): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }
        $rootMsgId = PeepSoMessageRepository::findRootConversationId($rootMsgId);
        if ($rootMsgId <= 0) {
            return ['error' => 'bcc_not_found', 'message' => 'Conversation not found.'];
        }
        if (!PeepSoMessageRepository::userIsParticipant($viewerId, $rootMsgId)) {
            // 404, NOT 403 — non-participants don't get to learn the
            // conversation exists.
            return ['error' => 'bcc_not_found', 'message' => 'Conversation not found.'];
        }

        $page    = max(1, $page);
        $perPage = max(1, min($perPage, self::THREAD_PER_PAGE_MAX));
        $offset  = ($page - 1) * $perPage;

        $rows = PeepSoMessageRepository::findMessagesInConversation(
            $rootMsgId,
            $viewerId,
            // Fetch one extra to compute has_more without a COUNT
            $perPage + 1,
            $offset
        );

        $hasMore = count($rows) > $perPage;
        if ($hasMore) {
            // Drop the OLDEST one we fetched only to test the boundary
            // — the slice was DESC by recipient row id but the rows we
            // got back are ASC by post_date, so the trailing extra is
            // at index 0 (the deepest in history). Strip it so we
            // return exactly `$perPage` messages on the page.
            array_shift($rows);
        }

        $participants = PeepSoMessageRepository::getParticipantUserIds($rootMsgId, $viewerId);
        $authorIds = $participants;
        foreach ($rows as $r) {
            $authorIds[] = (int) $r['author_id'];
        }
        $userViews = $this->resolveUserMinisById(array_values(array_unique($authorIds)));
        $peerId = self::resolvePeerId(
            $participants,
            $viewerId,
            count($participants) > 2
        );

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id'                => (int) $r['id'],
                'author'            => $userViews[(int) $r['author_id']] ?? null,
                'body'              => (string) $r['body'],
                'posted_at'         => self::isoFromMysql((string) $r['posted_at']),
                'is_inline_notice'  => $r['post_type'] === 'peepso-message-notic',
            ];
        }

        // After serving the page, mark this conversation viewed so the
        // unread badge clears. Doing it here keeps the contract simple
        // (no separate POST /read needed for the common case) — clients
        // with edge-case "read but don't mark" needs would call
        // GET / dontmark in a follow-up.
        PeepSoMessageRepository::markConversationAsViewed($viewerId, $rootMsgId);

        return [
            'conversation' => [
                'id'           => $rootMsgId,
                'is_group'     => count($participants) > 2,
                'participants' => self::resolveUserMinis($participants, $userViews),
                'peer'         => $peerId !== null ? ($userViews[$peerId] ?? null) : null,
            ],
            'items'      => $items,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => null, // unbounded — clients increment offset until !has_more
                'has_more'  => $hasMore,
            ],
        ];
    }

    // ── Send ─────────────────────────────────────────────────────────────

    /**
     * Send a message. If `$rootMsgId` is null, finds-or-creates a 1-on-1
     * conversation with `$recipientId`. If `$rootMsgId` is non-null,
     * appends to that conversation (recipient_id ignored / inferred).
     *
     * @return array{conversation_id: int, message_id: int, is_new_conversation: bool}|array{error: string, message: string}
     */
    public function sendMessage(
        int $viewerId,
        ?int $recipientId,
        ?int $rootMsgId,
        string $body
    ): array {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        $body = trim($body);
        if ($body === '') {
            return ['error' => 'bcc_invalid_request', 'message' => 'Message cannot be empty.'];
        }
        if (mb_strlen($body) > self::MESSAGE_BODY_MAX_LENGTH) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => sprintf('Message is too long (max %d characters).', self::MESSAGE_BODY_MAX_LENGTH),
            ];
        }

        // Per-sender rate limit. Cheap range scan on (post_author,
        // post_date_gmt). Fires before the writer so a throttled user
        // doesn't even get to PeepSo's machinery.
        $recent = PeepSoMessageRepository::countRecentByAuthor($viewerId, self::RATE_LIMIT_WINDOW_SECONDS);
        if ($recent >= self::RATE_LIMIT_PER_WINDOW) {
            return [
                'error'   => 'bcc_rate_limited',
                'message' => 'You\'re sending messages too fast. Take a breath and try again in a moment.',
            ];
        }

        // Sender-side gate: can the viewer chat at all?
        if (!self::chatEnabled($viewerId)) {
            return [
                'error'   => 'bcc_forbidden',
                'message' => 'Messaging is disabled in your account settings.',
            ];
        }

        if ($rootMsgId !== null && $rootMsgId > 0) {
            return $this->sendToExistingConversation($viewerId, $rootMsgId, $body);
        }

        if ($recipientId === null || $recipientId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Recipient is required.'];
        }
        if ($recipientId === $viewerId) {
            return ['error' => 'bcc_invalid_request', 'message' => 'You can\'t message yourself.'];
        }
        return $this->sendToRecipient($viewerId, $recipientId, $body);
    }

    /**
     * @return array{conversation_id: int, message_id: int, is_new_conversation: bool}|array{error: string, message: string}
     */
    private function sendToRecipient(int $viewerId, int $recipientId, string $body): array
    {
        // Recipient must exist as a WP user.
        if (!self::userExists($recipientId)) {
            return ['error' => 'bcc_not_found', 'message' => 'Recipient not found.'];
        }

        // Mutual-block 404 (info-leak shield).
        if (PeepSoBlockRepository::isMutuallyBlocked($viewerId, $recipientId)) {
            return ['error' => 'bcc_not_found', 'message' => 'Recipient not found.'];
        }

        // Recipient-side gates.
        if (!self::chatEnabled($recipientId)) {
            return [
                'error'   => 'bcc_forbidden',
                'message' => 'This member has direct messages turned off.',
            ];
        }
        if (self::chatFriendsOnly($recipientId) && !self::areFriends($viewerId, $recipientId)) {
            return [
                'error'   => 'bcc_forbidden',
                'message' => 'This member only accepts messages from friends.',
            ];
        }

        $result = PeepSoMessageWriter::sendNewMessage($viewerId, $recipientId, $body);
        if ($result === null) {
            return ['error' => 'bcc_unavailable', 'message' => 'Could not send your message.'];
        }
        return $result;
    }

    /**
     * @return array{conversation_id: int, message_id: int, is_new_conversation: bool}|array{error: string, message: string}
     */
    private function sendToExistingConversation(int $viewerId, int $rootMsgId, string $body): array
    {
        $rootMsgId = PeepSoMessageRepository::findRootConversationId($rootMsgId);
        if ($rootMsgId <= 0) {
            return ['error' => 'bcc_not_found', 'message' => 'Conversation not found.'];
        }
        if (!PeepSoMessageRepository::userIsParticipant($viewerId, $rootMsgId)) {
            return ['error' => 'bcc_not_found', 'message' => 'Conversation not found.'];
        }

        // For 1-on-1 conversations, re-check the privacy gate against
        // the peer at send time (a peer may have flipped their
        // chat_enabled / chat_friends_only since the last message).
        $participants = PeepSoMessageRepository::getParticipantUserIds($rootMsgId);
        $peerId = self::resolvePeerId($participants, $viewerId, count($participants) > 2);
        if ($peerId !== null) {
            if (PeepSoBlockRepository::isMutuallyBlocked($viewerId, $peerId)) {
                return ['error' => 'bcc_not_found', 'message' => 'Conversation not found.'];
            }
            if (!self::chatEnabled($peerId)) {
                return [
                    'error'   => 'bcc_forbidden',
                    'message' => 'This member has direct messages turned off.',
                ];
            }
            if (self::chatFriendsOnly($peerId) && !self::areFriends($viewerId, $peerId)) {
                return [
                    'error'   => 'bcc_forbidden',
                    'message' => 'This member only accepts messages from friends.',
                ];
            }
        }

        $messageId = PeepSoMessageWriter::sendInConversation($viewerId, $rootMsgId, $body);
        if ($messageId <= 0) {
            return ['error' => 'bcc_unavailable', 'message' => 'Could not send your message.'];
        }

        return [
            'conversation_id'      => $rootMsgId,
            'message_id'           => $messageId,
            'is_new_conversation'  => false,
        ];
    }

    // ── Mark read / unread count ────────────────────────────────────────

    /**
     * @return array{ok: true}|array{error: string, message: string}
     */
    public function markRead(int $viewerId, int $rootMsgId): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }
        $rootMsgId = PeepSoMessageRepository::findRootConversationId($rootMsgId);
        if ($rootMsgId <= 0 || !PeepSoMessageRepository::userIsParticipant($viewerId, $rootMsgId)) {
            return ['error' => 'bcc_not_found', 'message' => 'Conversation not found.'];
        }
        PeepSoMessageRepository::markConversationAsViewed($viewerId, $rootMsgId);
        return ['ok' => true];
    }

    public function getUnreadCount(int $viewerId): int
    {
        if ($viewerId <= 0) {
            return 0;
        }
        return PeepSoMessageRepository::getUnreadConversationCountForUser($viewerId);
    }

    // ── Privacy primitives ──────────────────────────────────────────────

    private static function chatEnabled(int $userId): bool
    {
        if (!class_exists('PeepSoChatModel')) {
            // peepso-messages plugin missing — treat as disabled rather
            // than silently allowing writes.
            return false;
        }
        return (bool) \PeepSoChatModel::check_chat_enabled($userId);
    }

    private static function chatFriendsOnly(int $userId): bool
    {
        if (!class_exists('PeepSoChatModel')) {
            return false;
        }
        return (bool) \PeepSoChatModel::chat_friends_only($userId);
    }

    private static function areFriends(int $a, int $b): bool
    {
        if (!class_exists('PeepSoFriendsModel')) {
            // No friends plugin — friends_only effectively becomes
            // "no one can DM"; the chat_friends_only gate above will
            // already short-circuit. Return false defensively.
            return false;
        }
        $model = new \PeepSoFriendsModel();
        return (bool) $model->are_friends($a, $b);
    }

    private static function userExists(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        return get_userdata($userId) !== false;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * For a 1-on-1 conversation (2 participants OR explicit
     * !is_group flag), returns the OTHER user's id. For groups
     * returns null — the caller renders the participants list
     * instead of a "peer" card.
     *
     * @param list<int> $participants
     */
    private static function resolvePeerId(array $participants, int $viewerId, bool $isGroup): ?int
    {
        if ($isGroup) {
            return null;
        }
        foreach ($participants as $pid) {
            if ($pid !== $viewerId) {
                return $pid;
            }
        }
        return null;
    }

    /**
     * @param list<int>                                           $userIds
     * @param array<int, array<string, mixed>>                    $userViews
     * @return list<array<string, mixed>>
     */
    private static function resolveUserMinis(array $userIds, array $userViews): array
    {
        $out = [];
        foreach ($userIds as $uid) {
            if (isset($userViews[$uid])) {
                $out[] = $userViews[$uid];
            }
        }
        return $out;
    }

    /**
     * Compose user-mini view-models for the inbox / thread surfaces.
     * SQL lives in UserMiniRepository (per §1 — Service layer must
     * not touch $wpdb directly); this method decorates the rows with
     * the avatar URL (a computed value via WP's filterable
     * `get_avatar_url`) and resolves the handle fallback.
     *
     * @param list<int> $userIds
     * @return array<int, array{id: int, handle: string, display_name: string, avatar_url: string}>
     */
    private function resolveUserMinisById(array $userIds): array
    {
        $rows = $this->userMiniRepo->getRowsByIds($userIds);
        $out = [];
        foreach ($rows as $uid => $row) {
            $handle  = $row['handle'] !== null && $row['handle'] !== ''
                ? $row['handle']
                : $row['user_login'];
            $display = $row['display_name'];
            $avatar  = get_avatar_url($uid, ['size' => 96]);

            $out[$uid] = [
                'id'           => $uid,
                'handle'       => $handle,
                'display_name' => $display !== '' ? $display : $handle,
                'avatar_url'   => is_string($avatar) ? $avatar : '',
            ];
        }
        return $out;
    }

    /**
     * Strip HTML and collapse whitespace, then truncate to the inbox
     * preview cap. PeepSo stores message bodies in `wp_posts.post_content`
     * which can carry filtered HTML / shortcodes; previews want plain
     * text.
     */
    private static function previewBody(string $body): string
    {
        $stripped = wp_strip_all_tags($body, true);
        $collapsed = (string) preg_replace('/\s+/u', ' ', $stripped);
        $collapsed = trim($collapsed);
        if (mb_strlen($collapsed) <= self::PREVIEW_MAX_CHARS) {
            return $collapsed;
        }
        return rtrim(mb_substr($collapsed, 0, self::PREVIEW_MAX_CHARS - 1)) . '…';
    }

    /**
     * MySQL `Y-m-d H:i:s` (UTC, from `*_gmt` columns) → ISO 8601 with `Z`.
     * Matches the §1.7 timestamp convention used everywhere else in the
     * contract.
     */
    private static function isoFromMysql(string $mysqlTime): string
    {
        if ($mysqlTime === '' || $mysqlTime === '0000-00-00 00:00:00') {
            return '';
        }
        return str_replace(' ', 'T', $mysqlTime) . 'Z';
    }
}
