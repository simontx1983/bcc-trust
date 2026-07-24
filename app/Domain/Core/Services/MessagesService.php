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
 *   1. Sender not suspended (Permissions::is_not_suspended, no admin
 *      bypass) and not PeepSo-banned (usr_role 'ban') → bcc_fraud_locked
 *   2. Sender's `peepso_chat_enabled` user_meta — false → bcc_forbidden
 *   3. Recipient's `peepso_chat_enabled` user_meta — false → bcc_forbidden
 *   4. Recipient's `peepso_chat_friends_only` AND PeepSoFriendsModel::are_friends
 *      returns false → bcc_forbidden
 *   5. PeepSoBlockRepository::isMutuallyBlocked(sender, recipient) →
 *      bcc_not_found (404, info-leak shielded; never reveals the block)
 *
 * Recipient-facing gates 3–5 are applied by the ONE policy evaluator
 * (`evaluatePolicy`) under a named MessagingPolicy context — the
 * conversation-start path and the reply peer re-check both consume it,
 * so the rules cannot drift between paths. See MessagingPolicy for the
 * full context matrix (NORMAL_DM vs VALIDATOR_FIRST_CLAIM_RELEASE).
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
use BCC\Trust\Core\Services\BadgesService;
use BCC\Trust\Core\Support\PeepSoFriendGate;
use BCC\Trust\Onchain\Repositories\ValidatorMsgQueueRepository;

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

    /**
     * Pre-claim queue backpressure. Accepted messages never expire and
     * are never silently dropped, so these caps are the ONLY
     * backpressure — both surface as truthful 409s rather than a
     * silent discard.
     *   - per sender per page: enough to say anything legitimate,
     *     while stopping one account from monologue-flooding a future
     *     operator's day-one inbox. Slots free as messages deliver.
     *   - per page: a validator's whole pending backlog.
     */
    public const QUEUE_MAX_PENDING_PER_SENDER_PER_PAGE = 3;
    public const QUEUE_MAX_PENDING_PER_PAGE = 500;

    /** delivery_state reported for an accepted pre-claim message. */
    public const DELIVERY_STATE_AWAITING_OPERATOR = 'awaiting_first_verified_operator';

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

        // Bump the viewer's badge gen — the messages_unread count just
        // dropped (or was already 0; bumping is cheap).
        BadgesService::bumpForUser($viewerId);

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

        // Suspension / ban gate — permanent authorization rule, so it
        // runs before any content handling, mirroring every other BCC
        // mutating surface (endorse, disputes, locals join). No admin
        // bypass: a suspended admin gets no free pass on participant
        // paths. bcc_fraud_locked matches the TrustRestController
        // suspension mapping.
        $restricted = self::senderRestricted($viewerId);
        if ($restricted !== null) {
            return $restricted;
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

    // ── Validator pages (entity-addressed messages) ─────────────────────

    /**
     * Send to a VALIDATOR PAGE rather than a user.
     *
     * The server re-resolves the page's destination on EVERY submission
     * (the frontend never decides):
     *   - claimed by exactly one verified operator → an ordinary
     *     NORMAL_DM to that operator, via the unchanged sendMessage
     *     path (so it inherits every gate, the writer and badge bumps).
     *   - never claimed → the message is durably QUEUED against the
     *     page and delivered to its FIRST verified operator when the
     *     page is claimed. No fake conversation, no fabricated
     *     recipient, no conversation id.
     *   - previously claimed but currently unclaimed, or AMBIGUOUS
     *     (more than one verified operator) → fail closed with a
     *     generic unavailable state. The first-claim queue is never
     *     reopened, and competing claimants are never revealed.
     *
     * @return array{conversation_id: int, message_id: int, is_new_conversation: bool}
     *         |array{queued: true, page_id: int, accepted_at: string, delivery_state: string}
     *         |array{error: string, message: string}
     */
    public function sendMessageToPage(int $viewerId, int $pageId, string $body): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }
        if (!self::validatorMessagingEnabled()) {
            return self::messagingUnavailable();
        }

        // Must be a published page post.
        $post = $pageId > 0 ? get_post($pageId) : null;
        if (!$post instanceof \WP_Post || $post->post_type !== 'peepso-page' || $post->post_status !== 'publish') {
            return ['error' => 'bcc_not_found', 'message' => 'Page not found.'];
        }

        // V1 scope: validator pages only. Project/creator claim
        // semantics need their own audit before they accept messages.
        if (\BCC\Trust\Onchain\Repositories\ValidatorRepository::findFirstByPageId($pageId) === null) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Messaging is not available for this page.',
            ];
        }

        $resolution = \BCC\Trust\Onchain\Repositories\ClaimRepository::resolveVerifiedOperatorForPage($pageId);

        if ($resolution['state'] === \BCC\Trust\Onchain\Repositories\ClaimRepository::OPERATOR_RESOLVED
            && $resolution['user_id'] !== null) {
            // Live send under ordinary DM rules.
            return $this->sendMessage($viewerId, $resolution['user_id'], null, $body);
        }

        if ($resolution['state'] === \BCC\Trust\Onchain\Repositories\ClaimRepository::OPERATOR_AMBIGUOUS) {
            // Fail closed. The metric + audit row already fired inside
            // the resolver; the caller learns nothing about the claims.
            return self::messagingUnavailable();
        }

        // UNCLAIMED — queue only when this page has NEVER been
        // activated. A previously activated page that is currently
        // unclaimed must not reopen the first-claim backlog.
        if (\BCC\Trust\Onchain\Repositories\ValidatorMsgActivationRepository::getByPageId($pageId) !== null) {
            return self::messagingUnavailable();
        }

        return $this->enqueueForPage($viewerId, $pageId, $body);
    }

    /**
     * Submission gates for the queue path. Mirrors the sender-side
     * rules of sendMessage (there is no recipient yet, so the
     * recipient-facing policy runs later, at delivery time). Nothing
     * accepted here is ever silently dropped: once this returns
     * `queued`, the row is durable until delivered, suppressed by an
     * explicit safety rule, or parked as a visible failure.
     *
     * @return array{queued: true, page_id: int, accepted_at: string, delivery_state: string}
     *         |array{error: string, message: string}
     */
    private function enqueueForPage(int $viewerId, int $pageId, string $body): array
    {
        $restricted = self::senderRestricted($viewerId);
        if ($restricted !== null) {
            return $restricted;
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

        // Shared throttle budget: PeepSo sends AND queue inserts count
        // against the same 30/5min window, so the queue can't be used
        // as a rate-limit escape hatch.
        $recent = PeepSoMessageRepository::countRecentByAuthor($viewerId, self::RATE_LIMIT_WINDOW_SECONDS)
            + ValidatorMsgQueueRepository::countRecentBySender($viewerId, self::RATE_LIMIT_WINDOW_SECONDS);
        if ($recent >= self::RATE_LIMIT_PER_WINDOW) {
            return [
                'error'   => 'bcc_rate_limited',
                'message' => 'You\'re sending messages too fast. Take a breath and try again in a moment.',
            ];
        }

        if (!self::chatEnabled($viewerId)) {
            return [
                'error'   => 'bcc_forbidden',
                'message' => 'Messaging is disabled in your account settings.',
            ];
        }

        // Backpressure. Truthful errors — a rejected message is never
        // promised delivery.
        if (ValidatorMsgQueueRepository::countPendingForPageBySender($pageId, $viewerId)
            >= self::QUEUE_MAX_PENDING_PER_SENDER_PER_PAGE) {
            return [
                'error'   => 'bcc_queue_limit',
                'message' => 'You already have the maximum queued messages for this validator.',
            ];
        }
        if (ValidatorMsgQueueRepository::countPendingForPage($pageId) >= self::QUEUE_MAX_PENDING_PER_PAGE) {
            return [
                'error'   => 'bcc_queue_full',
                'message' => 'This validator\'s message queue is full.',
            ];
        }

        $rowId = ValidatorMsgQueueRepository::enqueue($pageId, $viewerId, $body);
        if ($rowId <= 0) {
            return ['error' => 'bcc_unavailable', 'message' => 'Could not queue your message.'];
        }

        return [
            'queued'         => true,
            'page_id'        => $pageId,
            'accepted_at'    => gmdate('Y-m-d\TH:i:s\Z'),
            'delivery_state' => self::DELIVERY_STATE_AWAITING_OPERATOR,
        ];
    }

    /**
     * The single generic "can't message this page right now" response.
     * Deliberately identical for the previously-claimed-now-unclaimed
     * state, the AMBIGUOUS state, and the kill-switch — the wire must
     * not distinguish them (no claimant identities, no hint that
     * competing claims exist).
     *
     * @return array{error: string, message: string}
     */
    private static function messagingUnavailable(): array
    {
        return [
            'error'   => 'bcc_messaging_unavailable',
            'message' => 'Messaging for this page is temporarily unavailable.',
        ];
    }

    /**
     * Operator kill-switch — arms/disarms the whole validator-messaging
     * surface (submissions + card destinations + worker) without a
     * deploy. Queued rows are never dropped while off.
     *
     * Default OFF (ship-dark): the verified-operator uniqueness
     * constraint (scripts/claims-verified-operator-constraint.php) is a
     * MANDATORY rollout gate. The feature must stay dark on any
     * environment until an operator has run the duplicate audit clean
     * AND applied the constraint there, then flips this option on. This
     * prevents an auto-deploy from enabling messaging on staging/prod
     * before its DB backstop against a second verified operator exists.
     */
    public static function validatorMessagingEnabled(): bool
    {
        return (bool) get_option('bcc_validator_messaging_enabled', false);
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

        $gate = self::evaluatePolicy(
            MessagingPolicy::NORMAL_DM,
            $viewerId,
            $recipientId,
            'Recipient not found.'
        );
        if ($gate !== null) {
            return ['error' => $gate['error'], 'message' => $gate['message']];
        }

        $result = PeepSoMessageWriter::sendNewMessage($viewerId, $recipientId, $body);
        if ($result === null) {
            return ['error' => 'bcc_unavailable', 'message' => 'Could not send your message.'];
        }

        // Invalidate badge caches: sender's "open thread hint" needs
        // to advance immediately, recipient's unread count needs to
        // tick up on the next /me/badges poll.
        BadgesService::bumpForUsers([$viewerId, $recipientId]);

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
        // Same evaluator as the start path — replies always run
        // NORMAL_DM (a first-claim backlog delivery never creates an
        // ongoing exemption; see MessagingPolicy).
        $participants = PeepSoMessageRepository::getParticipantUserIds($rootMsgId);
        $peerId = self::resolvePeerId($participants, $viewerId, count($participants) > 2);
        if ($peerId !== null) {
            $gate = self::evaluatePolicy(
                MessagingPolicy::NORMAL_DM,
                $viewerId,
                $peerId,
                'Conversation not found.'
            );
            if ($gate !== null) {
                return ['error' => $gate['error'], 'message' => $gate['message']];
            }
        }

        $messageId = PeepSoMessageWriter::sendInConversation($viewerId, $rootMsgId, $body);
        if ($messageId <= 0) {
            return ['error' => 'bcc_unavailable', 'message' => 'Could not send your message.'];
        }

        // Invalidate every participant's badge cache. $participants
        // was already fetched above for the peer-gate re-check, so
        // we reuse it here (no extra query).
        BadgesService::bumpForUsers($participants);

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

        // Viewer's unread-message count just dropped; bump badge gen
        // so the next poll surfaces the change. Only the viewer's
        // count changes here — peers' counts are unchanged.
        BadgesService::bumpForUser($viewerId);

        return ['ok' => true];
    }

    public function getUnreadCount(int $viewerId): int
    {
        if ($viewerId <= 0) {
            return 0;
        }
        return PeepSoMessageRepository::getUnreadConversationCountForUser($viewerId);
    }

    // ── Read eligibility (card view-models) ─────────────────────────────

    /**
     * Read-side mirror of the send gates — "would a DM to this
     * recipient be accepted right now?" — in the card permission shape
     * {allowed, unlock_hint, reason_code}.
     *
     * Consumes the SAME evaluatePolicy the write paths use, so read and
     * write cannot disagree on any PERMANENT rule. The transient rate
     * limit is deliberately excluded (it is a POST-time condition, not
     * an eligibility fact; mirrors how endorse eligibility excludes
     * transient state).
     *
     * Static because every gate it needs is static — this keeps the
     * single-source guarantee available to CardViewService,
     * UserViewService and PageCardPrefetcher without threading an
     * instance through their constructors.
     *
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    public static function canMessage(int $viewerId, int $recipientId): array
    {
        if ($recipientId <= 0) {
            return self::eligibilityDeny(null, 'not_applicable');
        }
        if ($viewerId <= 0) {
            // Anon: aspirational copy, zero queries (§N7 visible path).
            return self::eligibilityDeny('Sign in to send a message.', 'auth_required');
        }
        if ($viewerId === $recipientId) {
            return self::eligibilityDeny(null, 'self_action_blocked');
        }
        if (self::senderRestricted($viewerId) !== null) {
            return self::eligibilityDeny(null, 'sender_restricted');
        }
        if (!self::chatEnabled($viewerId)) {
            return self::eligibilityDeny(
                'Turn on direct messages in your settings to send messages.',
                'sender_chat_disabled'
            );
        }
        if (!self::userExists($recipientId)) {
            return self::eligibilityDeny(null, 'messaging_unavailable');
        }

        $gate = self::evaluatePolicy(MessagingPolicy::NORMAL_DM, $viewerId, $recipientId);
        if ($gate !== null) {
            return self::eligibilityDeny($gate['unlock_hint'], $gate['reason_code']);
        }
        return ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
    }

    /**
     * Batch sibling of canMessage for list paths. Flat query cost
     * regardless of N: the sender-side gates resolve once, recipient
     * usermeta is primed in one round trip, blocks are one query, and
     * the viewer's friend set is fetched at most once (lazily, only if
     * a friends_only recipient appears).
     *
     * @param list<int> $recipientIds
     * @return array<int, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>
     *         keyed by recipient user id
     */
    public static function canMessageMany(int $viewerId, array $recipientIds): array
    {
        $ids = [];
        foreach ($recipientIds as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $ids = array_keys($ids);

        // Anon / restricted / chat-off senders deny uniformly with zero
        // per-recipient work.
        if ($viewerId <= 0) {
            $hint = 'Sign in to send a message.';
            return array_fill_keys($ids, self::eligibilityDeny($hint, 'auth_required'));
        }
        if (self::senderRestricted($viewerId) !== null) {
            return array_fill_keys($ids, self::eligibilityDeny(null, 'sender_restricted'));
        }
        if (!self::chatEnabled($viewerId)) {
            return array_fill_keys($ids, self::eligibilityDeny(
                'Turn on direct messages in your settings to send messages.',
                'sender_chat_disabled'
            ));
        }

        // Prime recipient usermeta so chatEnabled/chatFriendsOnly are
        // cache hits (same trick PageCardPrefetcher uses for owners).
        cache_users($ids);
        $blocked = \BCC\Core\Repositories\PeepSoBlockRepository::getMutuallyBlockedSet($viewerId, $ids);

        /** @var array<int, true>|null $friendIds lazily fetched */
        $friendIds = null;

        $out = [];
        foreach ($ids as $recipientId) {
            if ($recipientId === $viewerId) {
                $out[$recipientId] = self::eligibilityDeny(null, 'self_action_blocked');
                continue;
            }
            // Block and recipient-chat-off intentionally share one
            // payload — the §4.19 info-leak shield holds on reads too.
            if (isset($blocked[$recipientId]) || !self::userExists($recipientId) || !self::chatEnabled($recipientId)) {
                $out[$recipientId] = self::eligibilityDeny(null, 'messaging_unavailable');
                continue;
            }
            if (self::chatFriendsOnly($recipientId)) {
                if ($friendIds === null) {
                    $friendIds = PeepSoFriendGate::friendIdsOf($viewerId);
                }
                if (!isset($friendIds[$recipientId])) {
                    $out[$recipientId] = self::eligibilityDeny(
                        'This member only accepts messages from friends.',
                        'friends_only'
                    );
                    continue;
                }
            }
            $out[$recipientId] = ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
        }
        return $out;
    }

    /**
     * @return array{allowed: false, unlock_hint: string|null, reason_code: string}
     */
    private static function eligibilityDeny(?string $unlockHint, string $reasonCode): array
    {
        return ['allowed' => false, 'unlock_hint' => $unlockHint, 'reason_code' => $reasonCode];
    }

    // ── Policy evaluator ────────────────────────────────────────────────

    /**
     * THE recipient-facing gate evaluator — the only place the mutual
     * block / recipient chat_enabled / friends_only rules live. Both
     * write paths (conversation start + reply peer re-check) and the
     * card-read eligibility consume it, keyed by a MessagingPolicy
     * context, so read and write can never drift.
     *
     * Returns null when every gate passes. On a denial:
     *   - `error` / `message` — the wire error for write paths.
     *     `$shieldMessage` parameterizes the mutual-block 404 copy
     *     because the two call sites shield with different nouns
     *     ('Recipient not found.' vs 'Conversation not found.').
     *   - `gate` — the INTERNAL gate identifier (`mutual_block` /
     *     `recipient_chat_disabled` / `friends_only`). Server-side
     *     only (queue suppression reasons, logs); never on the wire.
     *   - `reason_code` / `unlock_hint` — the wire-safe read-model
     *     shape. Block and chat-off deliberately emit the IDENTICAL
     *     (`messaging_unavailable`, null) pair so the §4.19 info-leak
     *     shield holds on card reads too.
     *
     * Under VALIDATOR_FIRST_CLAIM_RELEASE the two preference gates
     * (recipient chat_enabled, friends_only) are bypassed — the
     * mutual-block safety rule always runs. See MessagingPolicy.
     *
     * @return array{
     *     error: string,
     *     message: string,
     *     gate: string,
     *     reason_code: string,
     *     unlock_hint: string|null
     * }|null
     */
    private static function evaluatePolicy(
        string $context,
        int $senderId,
        int $recipientId,
        string $shieldMessage = 'Recipient not found.'
    ): ?array {
        // Mutual-block shield — enforced in EVERY context (404, never
        // reveals the block).
        if (PeepSoBlockRepository::isMutuallyBlocked($senderId, $recipientId)) {
            return [
                'error'       => 'bcc_not_found',
                'message'     => $shieldMessage,
                'gate'        => 'mutual_block',
                'reason_code' => 'messaging_unavailable',
                'unlock_hint' => null,
            ];
        }

        if ($context === MessagingPolicy::VALIDATOR_FIRST_CLAIM_RELEASE) {
            // Preference gates bypassed for the one-time backlog
            // release only.
            return null;
        }

        if (!self::chatEnabled($recipientId)) {
            return [
                'error'       => 'bcc_forbidden',
                'message'     => 'This member has direct messages turned off.',
                'gate'        => 'recipient_chat_disabled',
                'reason_code' => 'messaging_unavailable',
                'unlock_hint' => null,
            ];
        }
        if (self::chatFriendsOnly($recipientId) && !PeepSoFriendGate::areFriends($senderId, $recipientId)) {
            return [
                'error'       => 'bcc_forbidden',
                'message'     => 'This member only accepts messages from friends.',
                'gate'        => 'friends_only',
                'reason_code' => 'friends_only',
                'unlock_hint' => 'This member only accepts messages from friends.',
            ];
        }
        return null;
    }

    /**
     * §7 policy matrix: suspended or PeepSo-banned senders cannot send
     * DMs in ANY context. Suspension resolves through the cross-plugin
     * Permissions seam (60s-cached, fail-closed via NullTrustReadService);
     * the PeepSo ban check mirrors the usr_role='ban' state PeepSo's
     * own writers refuse.
     *
     * @return array{error: string, message: string}|null
     */
    private static function senderRestricted(int $viewerId): ?array
    {
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($viewerId, false)) {
            return ['error' => 'bcc_fraud_locked', 'message' => 'Your account is currently restricted.'];
        }
        if (class_exists('PeepSoUser')) {
            $role = \PeepSoUser::get_instance($viewerId)->get_user_role();
            if ($role === 'ban') {
                return ['error' => 'bcc_fraud_locked', 'message' => 'Your account is currently restricted.'];
            }
        }
        return null;
    }

    // ── Privacy primitives ──────────────────────────────────────────────

    private static function chatEnabled(int $userId): bool
    {
        if (!class_exists('PeepSoChatModel')) {
            // peepso-messages plugin missing — treat as disabled rather
            // than silently allowing writes.
            return false;
        }
        return (bool) \PeepSoChatModel::chat_enabled($userId);
    }

    private static function chatFriendsOnly(int $userId): bool
    {
        if (!class_exists('PeepSoChatModel')) {
            return false;
        }
        return (bool) \PeepSoChatModel::chat_friends_only($userId);
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
     * the avatar URL and resolves the handle fallback. Avatars resolve
     * through the cached PeepSoMediaCache seam (one wp_cache_get_multiple
     * round-trip for the whole page, and it honors the avatar-change
     * bust hook) rather than raw per-row get_avatar_url.
     *
     * @param list<int> $userIds
     * @return array<int, array{id: int, handle: string, display_name: string, avatar_url: string}>
     */
    private function resolveUserMinisById(array $userIds): array
    {
        $rows    = $this->userMiniRepo->getRowsByIds($userIds);
        $avatars = \BCC\Core\PeepSo\PeepSoMediaCache::avatarUrlBulk(array_keys($rows));
        $out = [];
        foreach ($rows as $uid => $row) {
            $handle  = $row['handle'] !== null && $row['handle'] !== ''
                ? $row['handle']
                : $row['user_login'];
            $display = $row['display_name'];

            $out[$uid] = [
                'id'           => $uid,
                'handle'       => $handle,
                'display_name' => $display !== '' ? $display : $handle,
                'avatar_url'   => $avatars[$uid] ?? '',
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
