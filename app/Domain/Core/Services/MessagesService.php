<?php
/**
 * MessagesService — orchestrator for the §4.X DM surface.
 *
 * Composes:
 *   - bcc-core PeepSoMessageRepository (read path against PeepSo's
 *     conversation graph)
 *   - bcc-core PeepSoMessageWriter (only write path that hits
 *     peepso_message_* + the peepso-message CPT)
 *   - bcc-core PeepSoBlockRepository (the mutual-block SEND gate, and
 *     separately the one-directional READ treatment below)
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
 * Read-side blocked-sender treatment (#122, policy (b) "hide-on-read"):
 *   A message whose author THE VIEWER HAS BLOCKED is annotated
 *   `sender_blocked_by_viewer` and dropped from the unread counts. It is
 *   still delivered, still visible to every other participant, and the
 *   sender is never told — this is a display and notification
 *   treatment, NOT a security boundary.
 *
 *   ⚠ The predicate is ONE-DIRECTIONAL and viewer-relative: "the
 *   viewer blocked the author", never `isMutuallyBlocked`. "The sender
 *   blocked me" must not hide that sender's message FROM me.
 *
 *   ⚠ NEITHER the inbox preview NOR the thread body carries the real
 *   text for a blocked sender. The thread originally did — the client
 *   was to collapse it — but that left the text in the browser whether
 *   the viewer revealed it or not, so the decision became "fetch only
 *   when tapped".
 *
 *   ⚠ The blocker also gets NO EMAIL and NO LIVE PING. Both are
 *   per-recipient vetoes scoped to one send. ⚠ Their "allow" values are
 *   OPPOSITE — the mail filter returns the recipient id, the metadata
 *   filter returns the incoming `$check` (null) — see each factory.
 *   ⚠ Nothing suppresses SSE, because PeepSo's SSE trigger returns at
 *   its first statement and fires for nobody; the live signal it really
 *   uses is the `peepso_should_get_chats` user option.
 *
 *   ⚠ `revealMessageBody` is therefore the ONLY path that returns a
 *   blocked sender's text, and it does so on an explicit per-message
 *   request. It is deliberately NOT a block check: it returns the body
 *   whether or not the sender is blocked, because it is the viewer
 *   asking for one specific message they can already see the metadata
 *   of. Gating it on the block would make it useless the moment the
 *   viewer unblocked someone, and would leak the block state through a
 *   status code.
 *
 *   ⚠ None of this touches a write gate. The existing 1-on-1
 *   mutual-block re-check in `sendToExistingConversation` is unchanged,
 *   and a blocked user's group reply is still delivered — option (c)
 *   ("prevent group reply") was considered and rejected on #122, because
 *   refusing the write tells the blocked user they are blocked and costs
 *   the other participants a message.
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

    /**
     * What the inbox preview AND the thread body say instead of the real
     * text when the viewer has blocked the sender (#122).
     *
     * ⚠ One constant for both surfaces on purpose. The frontend matches
     * these exact words, and two strings that had to agree would be two
     * strings that could drift.
     *
     * ⚠ Substituted SERVER-SIDE. Sending the real preview and hiding it
     * in the client would put the text the viewer asked not to see into
     * the payload, which is not hiding it.
     *
     * ⚠ Operator-facing copy, so it is deliberately flat: it states a
     * fact about the viewer's own earlier action and asks for nothing.
     * No count, no elapsed time, no suggestion to reconsider.
     */
    private const BLOCKED_PREVIEW_PLACEHOLDER = 'Message from someone you have blocked';

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

        // F-300: a failed read is a retryable 503, never an empty inbox
        // ("No conversations yet" over a DB error).
        $rows = self::readInboxRows($viewerId, $perPage, $offset);
        if ($rows === null) {
            return self::dmReadUnavailable();
        }
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

        // #122: which of the LAST-MESSAGE authors has this viewer
        // blocked? One bounded query for the page.
        //
        // ⚠ Scoped to last-message authors (<= INBOX_PER_PAGE_MAX, 50),
        // NOT to $authorIds. That also holds every participant of every
        // conversation on the page, which at 50 conversations can exceed
        // getBlockedSet's 500-candidate cap and silently truncate — and
        // the inbox preview is the only thing that needs this.
        $lastAuthorIds = [];
        foreach ($rows as $r) {
            $lastAuthorIds[(int) $r['last_msg_author_id']] = true;
        }
        // ⚠ FAIL CLOSED. `null` means the block read FAILED, not that
        // nobody is blocked. `[]` would show every sender's text, which
        // is the exact disclosure this feature exists to prevent, so an
        // unreadable block table withholds EVERY preview instead. Ugly
        // on a DB error, safe, and self-correcting on the next request.
        // `$lastAuthorIds` is already keyed `[authorId => true]`, so it IS the
        // "everyone is blocked" set — minus the viewer; see the helper.
        $blockedSet = PeepSoBlockRepository::getBlockedSetOrUnknown($viewerId, array_keys($lastAuthorIds))
            ?? self::withholdAllExceptViewer($lastAuthorIds, $viewerId);

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
                'last_message'   => self::shapeInboxLastMessage($r, $userViews, $blockedSet),
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

    // ── Queued (pre-claim) ───────────────────────────────────────────────

    /**
     * The viewer's own PENDING queued validator messages — the "Queued"
     * tab of /messages. These are messages addressed to a validator
     * that has no verified operator yet; they are NOT conversations
     * (there is no one to converse with), so they never appear in the
     * inbox. When the validator is first claimed, the backlog delivers
     * and each becomes a real thread (in both inboxes); at that point
     * it leaves this list.
     *
     * Sender-visible only: a viewer sees ONLY their own queued rows,
     * and only the pending ones (delivered → moved to inbox;
     * suppressed/failed → not surfaced, so no suppression reason can
     * leak). The body is the viewer's own message, so echoing it back
     * is safe.
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, total_pages: int}
     * }|array{error: string, message: string}
     */
    public function listQueuedForSender(int $viewerId, int $page, int $perPage): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        $page    = max(1, $page);
        $perPage = max(1, min($perPage, self::INBOX_PER_PAGE_MAX));
        $offset  = ($page - 1) * $perPage;

        $total = ValidatorMsgQueueRepository::countPendingBySender($viewerId);
        $rows  = $total > 0
            ? ValidatorMsgQueueRepository::findPendingBySender($viewerId, $perPage, $offset)
            : [];

        $emptyPagination = [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => max(1, (int) ceil(max(1, $total) / $perPage)),
        ];
        if ($rows === []) {
            return ['items' => [], 'pagination' => $emptyPagination];
        }

        // Prime the target page posts (title/slug/thumbnail) in one pass.
        $pageIds = array_values(array_unique(array_map(
            static fn (object $r): int => (int) $r->page_id,
            $rows
        )));
        _prime_post_caches($pageIds, false, true);

        $items = [];
        foreach ($rows as $r) {
            $pageId = (int) $r->page_id;
            $post   = get_post($pageId);
            $name   = $post instanceof \WP_Post && $post->post_title !== ''
                ? $post->post_title
                : 'Unknown validator';
            $slug   = $post instanceof \WP_Post ? (string) $post->post_name : '';
            $avatar = $post instanceof \WP_Post
                ? (string) (get_the_post_thumbnail_url($pageId, 'thumbnail') ?: '')
                : '';

            $items[] = [
                'id'             => (int) $r->id,
                'validator'      => [
                    'page_id'    => $pageId,
                    'name'       => $name,
                    'slug'       => $slug,
                    'avatar_url' => $avatar,
                ],
                'preview'        => self::previewBody((string) $r->body),
                // The sender only needs one honest state — the queue's
                // internal queued/retryable/processing distinctions are
                // all "waiting for the validator to be claimed".
                'delivery_state' => self::DELIVERY_STATE_AWAITING_OPERATOR,
                'created_at'     => self::isoFromMysql((string) $r->created_at),
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

        $rows = self::readThreadRows(
            $rootMsgId,
            $viewerId,
            // Fetch one extra to compute has_more without a COUNT
            $perPage + 1,
            $offset
        );
        // F-300: a failed read is a retryable 503 — and, critically, the
        // conversation is NOT marked read below: the viewer never saw it.
        if ($rows === null) {
            return self::dmReadUnavailable();
        }

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

        // #122: which of THIS PAGE's message authors has the viewer
        // blocked? Scoped to the page's authors (<= THREAD_PER_PAGE_MAX,
        // 100), well inside getBlockedSet's 500-candidate cap.
        $pageAuthorIds = [];
        foreach ($rows as $r) {
            $pageAuthorIds[(int) $r['author_id']] = true;
        }
        // ⚠ FAIL CLOSED. `null` means the block read FAILED, not that
        // nobody is blocked. `[]` would show every sender's text, which
        // is the exact disclosure this feature exists to prevent, so an
        // unreadable block table withholds EVERY preview instead. Ugly
        // on a DB error, safe, and self-correcting on the next request.
        // `$pageAuthorIds` is already keyed `[authorId => true]`, so it IS the
        // "everyone is blocked" set — minus the viewer; see the helper.
        $blockedSet = PeepSoBlockRepository::getBlockedSetOrUnknown($viewerId, array_keys($pageAuthorIds))
            ?? self::withholdAllExceptViewer($pageAuthorIds, $viewerId);

        $peerId = self::resolvePeerId(
            $participants,
            $viewerId,
            count($participants) > 2
        );

        $items = [];
        foreach ($rows as $r) {
            $items[] = self::shapeThreadMessageRow($r, $userViews, $blockedSet);
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
                // F-246: the reply composer learns "can't reply" (and the
                // right copy) up front instead of from a failed POST.
                'can_reply'    => self::canReply($viewerId, $rootMsgId),
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
            'Recipient not found.',
            // ⚠ WRITE path: refuse with a retryable 503 if the block
            // table cannot be read. See evaluatePolicy().
            true
        );
        if ($gate !== null) {
            return ['error' => $gate['error'], 'message' => $gate['message']];
        }

        // #122: no email AND no live chat-ping to a recipient who has
        // blocked this sender. Both vetoes are scoped to this one send.
        $veto     = self::blockedRecipientMailVeto($viewerId);
        $pingVeto = self::blockedRecipientChatPingVeto($viewerId);
        add_filter('peepso_mailqueue_allowed', $veto, 99);
        add_filter('update_user_metadata', $pingVeto, 99, 3);
        try {
            $result = PeepSoMessageWriter::sendNewMessage($viewerId, $recipientId, $body);
        } finally {
            remove_filter('update_user_metadata', $pingVeto, 99);
            remove_filter('peepso_mailqueue_allowed', $veto, 99);
        }
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
                'Conversation not found.',
                // ⚠ WRITE path: refuse with a retryable 503 if the block
                // table cannot be read. See evaluatePolicy().
                true
            );
            if ($gate !== null) {
                return ['error' => $gate['error'], 'message' => $gate['message']];
            }
        }

        // #122: no email to any participant who has blocked this
        // sender. PeepSo fans the notification out per recipient inside
        // the writer, so a per-recipient veto is the only seam that can
        // tell them apart — which is exactly why this is a group-thread
        // fix and not a 1-on-1 one.
        $veto     = self::blockedRecipientMailVeto($viewerId);
        $pingVeto = self::blockedRecipientChatPingVeto($viewerId);
        add_filter('peepso_mailqueue_allowed', $veto, 99);
        add_filter('update_user_metadata', $pingVeto, 99, 3);
        try {
            $messageId = PeepSoMessageWriter::sendInConversation($viewerId, $rootMsgId, $body);
        } finally {
            remove_filter('update_user_metadata', $pingVeto, 99);
            remove_filter('peepso_mailqueue_allowed', $veto, 99);
        }
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

    /**
     * The real body of ONE message in a conversation the viewer
     * participates in — the explicit tap-to-reveal fetch behind the
     * substituted thread body (#122).
     *
     * Reuses the same primitives `getThread` gates on, in the same
     * order, plus two: the message's own root conversation must be the
     * conversation asked for, AND the viewer must actually have a
     * recipient row for it. Nothing here is new machinery.
     *
     * ⚠⚠ THE GOVERNING INVARIANT: a reveal returns no more than the
     * thread read would have returned for this viewer. That is why the
     * recipient check exists and why it is shaped exactly like
     * `findMessagesInConversation`'s own predicate — including the
     * absence of an `mrec_deleted` filter, which the thread read also
     * omits. Too loose discloses a message PeepSo never delivered
     * (notably one sent AFTER the other party blocked the viewer:
     * `add_message` builds recipient rows through a participants query
     * that LEFT JOINs `peepso_blocks` in both directions, so the
     * blocked side gets no row at all). Too strict produces a message
     * the viewer can read in the thread but cannot reveal.
     *
     * ⚠⚠ EVERY refusal is the SAME 404 with the SAME message. "You are
     * not a participant", "that message is not in this conversation",
     * "that message was never sent to you" and "no such message" must be
     * indistinguishable, or the route becomes an oracle for probing
     * which message ids exist and which conversations a stranger
     * belongs to. That is why there is one `notFound` local and every
     * branch returns it.
     *
     * ⚠ It does NOT mark anything read. `getThread` deliberately marks
     * the conversation viewed as a side effect of serving a page;
     * revealing one message is not reading the thread, and making it
     * clear the badge would let a reveal silently dismiss unread
     * messages the viewer never saw.
     *
     * ⚠ It does NOT consult the block. See the class docblock: gating
     * on the block would leak block state through a status code and
     * would break the moment the viewer unblocked someone.
     *
     * @return array{id: int, body: string}|array{error: string, message: string}
     */
    public function revealMessageBody(int $viewerId, int $rootMsgId, int $messageId): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        // One refusal, used by every failure branch below.
        $notFound = ['error' => 'bcc_not_found', 'message' => 'Conversation not found.'];

        if ($messageId <= 0) {
            return $notFound;
        }

        $rootMsgId = PeepSoMessageRepository::findRootConversationId($rootMsgId);
        if ($rootMsgId <= 0) {
            return $notFound;
        }
        if (!PeepSoMessageRepository::userIsParticipant($viewerId, $rootMsgId)) {
            return $notFound;
        }

        // The message must belong to THIS conversation. Resolving the
        // message's own root and comparing is what makes a cross-thread
        // id a 404 rather than a disclosure.
        if (PeepSoMessageRepository::findRootConversationId($messageId) !== $rootMsgId) {
            return $notFound;
        }

        // ⚠⚠ THE FOURTH GATE, and the one review found missing.
        // Participation is NOT the same as having been SENT this
        // message: `getThread` is RECIPIENT-scoped, so a participant
        // who has no recipient row for a message never saw it in the
        // thread and must not be able to reveal it. This is deliberately
        // ANDed with the conversation check above rather than replacing
        // it — the primitive asks only "was this message sent to this
        // viewer", with no conversation in the predicate, so on its own
        // it would let a message the viewer received in ANOTHER
        // conversation be fetched through this one's id.
        if (!PeepSoMessageRepository::viewerReceivedMessage($viewerId, $messageId)) {
            return $notFound;
        }

        $post = get_post($messageId);
        if (!$post instanceof \WP_Post) {
            return $notFound;
        }
        // Same predicates the thread read applies, so a draft, a trashed
        // row or a non-message post type cannot be revealed.
        if (!in_array($post->post_type, ['peepso-message', 'peepso-message-notic'], true)) {
            return $notFound;
        }
        if ($post->post_status !== 'publish') {
            return $notFound;
        }

        return [
            'id'   => (int) $post->ID,
            'body' => (string) $post->post_content,
        ];
    }

    public function getUnreadCount(int $viewerId): int
    {
        if ($viewerId <= 0) {
            return 0;
        }
        return PeepSoMessageRepository::getUnreadConversationCountForUser($viewerId);
    }

    /**
     * The "everyone is blocked" set used when the block read FAILS.
     *
     * ⚠⚠ THE VIEWER MUST BE REMOVED. The author-id maps are built from
     * MESSAGE AUTHORS, and in any conversation the viewer has replied to
     * they author some of those messages — so a naive "treat every
     * author as blocked" fallback makes THE VIEWER'S OWN TEXT render as
     * "Message from someone you have blocked", with
     * `sender_blocked_by_viewer: true` against themselves. Withholding a
     * blocked sender's words is the feature; withholding the viewer's
     * own is just a bug.
     *
     * ⚠ It is also a set the real probe CANNOT RETURN:
     * `getBlockedSetOrUnknown` filters `$id !== $blockerId`, so the
     * viewer never appears in a successful result. A fallback including
     * them would be strictly MORE inclusive than any real answer, which
     * is the tell that it was wrong rather than merely untidy.
     *
     * @param array<int, true> $authorIds
     * @return array<int, true>
     */
    private static function withholdAllExceptViewer(array $authorIds, int $viewerId): array
    {
        unset($authorIds[$viewerId]);
        return $authorIds;
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
        // Sender-side permanent rules — shared with the batch path and
        // the queue-mode card read so all three agree.
        $senderGate = self::senderMessagingGate($viewerId);
        if ($senderGate !== null) {
            return $senderGate;
        }
        if (!self::userExists($recipientId)) {
            return self::eligibilityDeny(null, 'messaging_unavailable');
        }

        // ⚠ `refuseOnUnknownBlock: true` makes an UNREADABLE block table
        // answer `reason_code: 'unavailable'` with `unlock_hint: null`
        // instead of silently reporting "you may message this person"
        // and then 503-ing on submit. The existing `eligibilityDeny`
        // line already produces exactly that shape, so no mapping is
        // added here.
        //
        // ⚠⚠ THIS MUST STAY IN STEP WITH `canMessageMany()` BELOW. They
        // answer the same question on different surfaces (profile vs
        // card lists), and a viewer seeing "unavailable" on one and a
        // live Message button on the other is worse than either being
        // wrong consistently. A test asserts the two payloads are
        // IDENTICAL for the same pair.
        $gate = self::evaluatePolicy(
            MessagingPolicy::NORMAL_DM,
            $viewerId,
            $recipientId,
            refuseOnUnknownBlock: true
        );
        if ($gate !== null) {
            return self::eligibilityDeny($gate['unlock_hint'], $gate['reason_code']);
        }
        return ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
    }

    /**
     * The sender-side PERMANENT eligibility rules, recipient-independent:
     * a suspended/banned sender, or one who has turned their own DMs
     * off, cannot start ANY conversation. Returns a deny entry, or null
     * when the sender may message.
     *
     * The ONE source for these two rules — canMessage, canMessageMany,
     * and senderEligibility (the queue-mode card read, where no
     * recipient exists yet) all consume it, so the rendered button and
     * the send path cannot disagree. Transient state (rate limit,
     * per-sender caps) is deliberately excluded — it is POST-time only.
     *
     * @return array{allowed: false, unlock_hint: string|null, reason_code: string}|null
     */
    private static function senderMessagingGate(int $viewerId): ?array
    {
        if (self::senderRestricted($viewerId) !== null) {
            return self::eligibilityDeny(null, 'sender_restricted');
        }
        if (!self::chatEnabled($viewerId)) {
            return self::eligibilityDeny(
                'Turn on direct messages in your settings to send messages.',
                'sender_chat_disabled'
            );
        }
        return null;
    }

    /**
     * Sender-only eligibility for the QUEUE-mode card read: a
     * never-claimed validator has no operator yet, so only the
     * sender's own permanent rules apply. Mirrors the sender gates
     * canMessage runs before it looks at a recipient — via the shared
     * senderMessagingGate, NOT by probing canMessage($viewer,$viewer)
     * (which returns self_action_blocked before the sender gates run).
     *
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    public static function senderEligibility(int $viewerId): array
    {
        if ($viewerId <= 0) {
            return self::eligibilityDeny('Sign in to send a message.', 'auth_required');
        }
        return self::senderMessagingGate($viewerId)
            ?? ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
    }

    /**
     * The recipient-side gate for a one-time first-claim backlog
     * delivery: under VALIDATOR_FIRST_CLAIM_RELEASE the operator's
     * chat_enabled and friends_only are bypassed, but a mutual block
     * still shields.
     *
     * The delivery worker calls THIS rather than checking the block
     * itself, so what "the one-time bypass" enforces lives in exactly
     * one place (the policy evaluator) — the worker and the policy
     * definition cannot drift on it. That property is preserved: the
     * worker still routes through this method and never touches
     * `PeepSoBlockRepository`.
     *
     * ⚠⚠ TRI-STATE, AND THE THIRD CASE IS THE WHOLE POINT (F-99):
     *
     *     true   suppress — a block really does shield this delivery
     *     false  release  — definitely not blocked
     *     null   DEFER    — the block table could not be read
     *
     * It used to return a plain bool, which collapsed null into
     * "release": a transient DB fault during the sweep would DELIVER a
     * queued first-claim message to an operator who had blocked the
     * sender. Failing the other way is just as wrong, because the
     * worker's suppression is TERMINAL ("never delivered, never
     * retried") — so a blip would permanently destroy a legitimate
     * message. Neither direction is acceptable, which is why the caller
     * is told to DEFER instead of being handed a guess.
     *
     * @return bool|null null = could not determine; the caller must
     *                   neither release nor suppress.
     */
    public static function firstClaimReleaseBlocked(int $senderId, int $operatorId): ?bool
    {
        $gate = self::evaluatePolicy(
            MessagingPolicy::VALIDATOR_FIRST_CLAIM_RELEASE,
            $senderId,
            $operatorId,
            refuseOnUnknownBlock: true
        );

        if ($gate === null) {
            return false;
        }
        if (($gate['error'] ?? '') === 'bcc_unavailable') {
            return null;
        }
        return true;
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
        // per-recipient work — via the SAME sender gate canMessage uses.
        if ($viewerId <= 0) {
            return array_fill_keys($ids, self::eligibilityDeny('Sign in to send a message.', 'auth_required'));
        }
        $senderGate = self::senderMessagingGate($viewerId);
        if ($senderGate !== null) {
            return array_fill_keys($ids, $senderGate);
        }

        // Prime recipient usermeta so chatEnabled/chatFriendsOnly are
        // cache hits (same trick PageCardPrefetcher uses for owners).
        cache_users($ids);
        // ⚠ `null` means the block table could not be READ — not that
        // nobody is blocked. `[]` would hand every candidate a live
        // Message button that 503s on submit (the write gate refuses on
        // the same unknown), so the whole batch answers "unavailable"
        // instead.
        //
        // ⚠ A UNIFORM answer leaks nothing about any individual pair,
        // which is why one payload for every id is safe here. It is also
        // the existing house shape on this method — the anonymous and
        // sender-gate branches above both `array_fill_keys` a single
        // payload.
        //
        // ⚠⚠ `'unavailable'` is deliberately a DIFFERENT code from the
        // `messaging_unavailable` that block / !userExists / !chatEnabled
        // all share below (the §4.19 info-leak shield). That shield hides
        // WHICH of those three applies; this code says "we could not
        // tell", which is a fact about the request rather than about the
        // pair, so it reveals nothing the shield protects.
        $blocked = \BCC\Core\Repositories\PeepSoBlockRepository::getMutuallyBlockedSetOrUnknown(
            $viewerId,
            $ids
        );
        if ($blocked === null) {
            return array_fill_keys($ids, self::eligibilityDeny(null, 'unavailable'));
        }

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
        string $shieldMessage = 'Recipient not found.',
        bool $refuseOnUnknownBlock = false
    ): ?array {
        // ⚠ `isMutuallyBlockedOrUnknown` is the EITHER-DIRECTION probe
        // despite the name — true when A blocked B *or* B blocked A.
        $blockState = PeepSoBlockRepository::isMutuallyBlockedOrUnknown($senderId, $recipientId);

        // ⚠⚠ UNKNOWN IS HANDLED PER CALLER, NOT HERE, AND THAT IS
        // DELIBERATE. `$refuseOnUnknownBlock` is passed true by the two
        // WRITE paths only, so an unreadable block table refuses a send
        // with a RETRYABLE 503 rather than admitting it. The READ paths
        // leave it false and so behave EXACTLY as before this change.
        //
        // Why the reads are not switched at the same time: `canMessage`
        // comes through here, but `canMessageMany` checks blocks via its
        // own batch `getMutuallyBlockedSet` and does NOT. Flipping only
        // this one would make the single and batch reads of the same
        // question disagree for the same viewer and recipient, which is
        // worse than both being wrong consistently. They are changed
        // together in the follow-up, in lockstep.
        if ($blockState === null && $refuseOnUnknownBlock) {
            return [
                'error'       => 'bcc_unavailable',
                'message'     => 'Messaging is temporarily unavailable. Please try again.',
                'gate'        => 'block_read_failed',
                'reason_code' => 'unavailable',
                'unlock_hint' => null,
            ];
        }

        // Mutual-block shield — enforced in EVERY context (404, never
        // reveals the block).
        if ($blockState === true) {
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
            $handle  = \BCC\Trust\Core\Support\PublicIdentity::handle((int) $uid, $row['handle']);
            $display = \BCC\Trust\Core\Support\PublicIdentity::visibleName((int) $uid, $row['display_name'], $row['user_login'], $handle);

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
    /**
     * One thread message, for `getThread`.
     *
     * ⚠ `body` IS SUBSTITUTED when the sender is blocked, and that is a
     * reversal of the first #122 decision, recorded here because the
     * reasoning changed rather than the code being wrong before.
     *
     * The original shape sent the real text and let the client collapse
     * it. That keeps tap-to-reveal free of a round trip, but it also
     * means the text the viewer asked not to see is sitting in their
     * browser whether they reveal it or not. The decision became "fetch
     * only when tapped": the thread carries the placeholder, and
     * `revealMessageBody` is the one path that returns the real text, on
     * an explicit request for one message.
     *
     * ⚠ So the real body must appear NOWHERE in a thread response for a
     * blocked sender — not in `body`, not in any sibling key. The test
     * asserts that over the serialised row rather than over `body`
     * alone.
     *
     * @param array<string, mixed> $r
     * @param array<int, UserMini> $userViews
     * @param array<int, true>     $blockedSet
     * @return array{
     *     id: int,
     *     author: UserMini|null,
     *     body: string,
     *     posted_at: string,
     *     is_inline_notice: bool,
     *     sender_blocked_by_viewer: bool
     * }
     */
    private static function shapeThreadMessageRow(array $r, array $userViews, array $blockedSet): array
    {
        $authorId = (int) $r['author_id'];

        return [
            'id'                       => (int) $r['id'],
            'author'                   => $userViews[$authorId] ?? null,
            'body'                     => isset($blockedSet[$authorId])
                ? self::BLOCKED_PREVIEW_PLACEHOLDER
                : (string) $r['body'],
            'posted_at'                => self::isoFromMysql((string) $r['posted_at']),
            'is_inline_notice'         => $r['post_type'] === 'peepso-message-notic',
            'sender_blocked_by_viewer' => isset($blockedSet[$authorId]),
        ];
    }

    /**
     * The `last_message` block of one inbox row.
     *
     * ⚠ This is the ONE place the real text is withheld, and it is
     * withheld rather than hidden: `previewBody` is not called at all for
     * a blocked sender, so the message text never enters the payload. An
     * inbox row carries no reveal affordance, which is why the inbox and
     * the thread differ.
     *
     * @param array<string, mixed> $r
     * @param array<int, UserMini> $userViews
     * @param array<int, true>     $blockedSet
     * @return array{
     *     id: int,
     *     author: UserMini|null,
     *     preview: string,
     *     posted_at: string,
     *     sender_blocked_by_viewer: bool
     * }
     */
    private static function shapeInboxLastMessage(array $r, array $userViews, array $blockedSet): array
    {
        $authorId = (int) $r['last_msg_author_id'];
        $blocked  = isset($blockedSet[$authorId]);

        return [
            'id'                       => (int) $r['last_msg_id'],
            'author'                   => $userViews[$authorId] ?? null,
            'preview'                  => $blocked
                ? self::BLOCKED_PREVIEW_PLACEHOLDER
                : self::previewBody((string) $r['last_msg_content']),
            'posted_at'                => self::isoFromMysql((string) $r['last_msg_posted_at']),
            'sender_blocked_by_viewer' => $blocked,
        ];
    }

    /**
     * PeepSo's own supported veto point for a per-message email, scoped
     * to ONE send by add-around-the-write / remove-in-`finally`.
     *
     * ⚠ It must be registered around the write and never globally. The
     * hook in `peepso/classes/mailqueue.php` is
     * `apply_filters('peepso_mailqueue_allowed', $user_id, TRUE)` — the
     * filtered VALUE is the recipient and the sender is not passed at
     * all, so the only way to answer "has this recipient blocked the
     * sender" is to close over the sender.
     *
     * ⚠ It returns the ORIGINAL `$user_id` to allow, not `true`. PeepSo
     * consumes the value as `if (!apply_filters(...)) return FALSE;`, so
     * truthiness is what decides — but replacing a user id with `true`
     * for every allowed recipient would corrupt the value for any filter
     * later in the chain. The pre-existing precedent in
     * ValidatorMsgQueueWorker returns a bare `false` because it vetoes
     * EVERY recipient of that send; this one is per-recipient and must
     * not.
     *
     * @return \Closure(mixed): mixed
     */
    private static function blockedRecipientMailVeto(int $senderId): \Closure
    {
        return static function ($mailRecipientId) use ($senderId) {
            $recipientId = (int) $mailRecipientId;
            // ⚠ FAIL CLOSED: `!== false` suppresses on true AND on null
            // ("could not tell"). Suppressing one notification on a DB
            // error costs a recipient one email/ping for one send;
            // letting it through emails and pings someone who blocked
            // this sender about a message PeepSo will not even deliver
            // to them. The cheap direction is the safe one here.
            // ⚠ THE GUARD MUST SHORT-CIRCUIT. Hoisting the probe above
            // `$recipientId > 0` made it run for a non-user recipient
            // (id 0) and cost a query the existing tests correctly
            // forbid — they caught it, which is why the whole suite is
            // run and not just the new file.
            if ($recipientId > 0
                && PeepSoBlockRepository::isBlockingOrUnknown($recipientId, $senderId) !== false) {
                return false;
            }
            return $mailRecipientId;
        };
    }

    /**
     * Per-recipient veto of PeepSo's live "you have new chats" ping
     * (#122), through WordPress's own `update_user_metadata`
     * short-circuit.
     *
     * ⚠⚠ THIS VETO'S "ALLOW" VALUE IS THE OPPOSITE OF THE MAIL ONE,
     * and getting it wrong is silent and site-wide.
     * `peepso_mailqueue_allowed` filters the RECIPIENT ID, so allowing
     * means returning that id. `update_metadata` short-circuits on ANY
     * non-null return (`if (null !== $check) return (bool) $check;`), so
     * allowing means returning the INCOMING `$check` — normally null.
     * Returning `true` here would make every user-meta write in the
     * process report success without writing anything, for the duration
     * of one send. Two vetoes, two polarities, one feature.
     *
     * ⚠ `$check` is returned rather than a literal `null` so an earlier
     * filter in the chain keeps its decision; this veto only ever adds a
     * refusal, never overrides someone else's.
     *
     * ⚠ `accepted_args` MUST be 3. `add_filter` defaults it to 1, and
     * with 1 the callback never receives `$object_id` or `$meta_key` —
     * it would then either fatal on PHP 8 or, with defaults, match every
     * meta key for every user. That is the same footgun as
     * `pre_wp_mail`'s two-argument signature.
     *
     * ⚠ The key is matched by ANCHORED PATTERN rather than by building
     * `$wpdb->get_blog_prefix() . 'peepso_should_get_chats'`, because
     * §1 / arch-guardrails forbid `$wpdb` outside the repository layer
     * and a new waiver for a string prefix would be the wrong trade. The
     * pattern is strictly better anyway: it also matches a multisite
     * prefix (`wp_3_…`), which a hardcoded `wp_` would miss.
     *
     * ⚠ The head is `([a-z0-9]+_)*`, not `[a-z0-9_]*`. The looser form
     * also matched `evilpeepso_should_get_chats` — any key merely
     * ENDING in the literal — because the head could absorb bare
     * letters. Requiring each head segment to end in `_` means only a
     * real table prefix can precede it.
     *
     * ⚠⚠ It carries `/i`, and the reason is MySQL rather than PHP.
     * PeepSo READS the flag with `get_user_option`, which resolves
     * through the user-meta cache — a PHP array lookup, so a
     * differently-cased stored key would never be read, and on the read
     * side `/i` would buy nothing. But this veto sits on the WRITE, and
     * `update_metadata()` chooses between update and insert with a
     * direct `SELECT ... WHERE meta_key = %s`. `wp_usermeta.meta_key` is
     * `utf8mb4_unicode_520_ci` here (measured), so a write keyed
     * `PeepSo_should_get_chats` finds the CANONICAL lowercase row and
     * updates it — setting the real flag. Without `/i` that write
     * passes the veto and the suppression fails silently.
     *
     * ⚠ The tightening above survives it: `/i` loosens the head
     * segments' case, not the requirement that each end in `_`, so
     * `evilpeepso_should_get_chats` is still not a match. Both
     * directions are asserted.
     *
     * ⚠ `peepso_should_get_chats_delay` is deliberately OUT of scope —
     * the pattern is `$`-anchored. Its only writer passes
     * `get_current_user_id()`: it is the viewer's own polling interval,
     * never a recipient's, so it is not part of the send fan-out.
     *
     * ⚠ What this suppresses is NOT SSE. `PeepSoSSEEvents::trigger()`
     * returns at its first statement ("SSE is not working yet"), so
     * every SSE call site is already a no-op. The live signal PeepSo
     * actually uses is this user option, polled by chat.js.
     *
     * @return \Closure(mixed, mixed, mixed): mixed
     */
    private static function blockedRecipientChatPingVeto(int $senderId): \Closure
    {
        return static function ($check, $objectId, $metaKey) use ($senderId) {
            // Only PeepSo's chat-ping flag, under any table prefix.
            if (!is_string($metaKey) || preg_match('/^([a-z0-9]+_)*peepso_should_get_chats$/i', $metaKey) !== 1) {
                return $check;
            }

            $recipientId = (int) $objectId;
            // ⚠ FAIL CLOSED: `!== false` suppresses on true AND on null
            // ("could not tell"). Suppressing one notification on a DB
            // error costs a recipient one email/ping for one send;
            // letting it through emails and pings someone who blocked
            // this sender about a message PeepSo will not even deliver
            // to them. The cheap direction is the safe one here.
            // ⚠ THE GUARD MUST SHORT-CIRCUIT. Hoisting the probe above
            // `$recipientId > 0` made it run for a non-user recipient
            // (id 0) and cost a query the existing tests correctly
            // forbid — they caught it, which is why the whole suite is
            // run and not just the new file.
            if ($recipientId > 0
                && PeepSoBlockRepository::isBlockingOrUnknown($recipientId, $senderId) !== false) {
                // Short-circuit: the write does not happen, so the
                // blocker's client is never told to refetch.
                return false;
            }

            return $check;
        };
    }

    // ── F-246 / F-300 helpers ───────────────────────────────────────────

    /**
     * F-246: would `POST /me/conversations/{id}/messages` be accepted
     * right now? The reply composer's up-front answer, in the card
     * permission shape {allowed, unlock_hint, reason_code}.
     *
     * Runs EXACTLY the gates the reply path runs, in the same order:
     * the sender's permanent rules (sendMessage's suspension + own
     * chat_enabled checks, via the shared senderMessagingGate) and, for
     * a 1-on-1, the policy evaluator against the peer with
     * `refuseOnUnknownBlock` (as sendToExistingConversation passes).
     * The peer is resolved from the UNFILTERED participant list, as the
     * reply path does — the thread's display list drops a blocked peer,
     * which would make a blocked pair look repliable.
     *
     * The transient rate limit is excluded, as on can_message. Block and
     * peer-chat-off share `messaging_unavailable` (§4.19 shield).
     *
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    private static function canReply(int $viewerId, int $rootMsgId): array
    {
        $senderGate = self::senderMessagingGate($viewerId);
        if ($senderGate !== null) {
            return $senderGate;
        }

        $participants = PeepSoMessageRepository::getParticipantUserIds($rootMsgId);
        $peerId       = self::resolvePeerId($participants, $viewerId, count($participants) > 2);
        if ($peerId !== null) {
            $gate = self::evaluatePolicy(
                MessagingPolicy::NORMAL_DM,
                $viewerId,
                $peerId,
                'Conversation not found.',
                true
            );
            if ($gate !== null) {
                return self::eligibilityDeny($gate['unlock_hint'], $gate['reason_code']);
            }
        }

        return ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
    }

    /**
     * F-300: the inbox page, or NULL when the read failed.
     *
     * Feature-detected: bcc-core #113 added the `...OrUnknown` read. On an
     * older bcc-core the old `?: []` read is used, which is exactly the
     * pre-F-300 behaviour, so this file deploys in either order.
     *
     * @return list<array<string, mixed>>|null
     */
    private static function readInboxRows(int $viewerId, int $limit, int $offset): ?array
    {
        // @phpstan-ignore function.alreadyNarrowedType (an older bcc-core may be deployed alongside)
        if (method_exists(PeepSoMessageRepository::class, 'findConversationsForUserOrUnknown')) {
            return PeepSoMessageRepository::findConversationsForUserOrUnknown($viewerId, $limit, $offset);
        }
        return PeepSoMessageRepository::findConversationsForUser($viewerId, $limit, $offset);
    }

    /**
     * F-300: a page of a thread, or NULL when either read failed.
     * Feature-detected exactly like readInboxRows().
     *
     * @return list<array<string, mixed>>|null
     */
    private static function readThreadRows(int $rootMsgId, int $viewerId, int $limit, int $offset): ?array
    {
        // @phpstan-ignore function.alreadyNarrowedType (an older bcc-core may be deployed alongside)
        if (method_exists(PeepSoMessageRepository::class, 'findMessagesInConversationOrUnknown')) {
            return PeepSoMessageRepository::findMessagesInConversationOrUnknown($rootMsgId, $viewerId, $limit, $offset);
        }
        return PeepSoMessageRepository::findMessagesInConversation($rootMsgId, $viewerId, $limit, $offset);
    }

    /**
     * F-300: the wire error for a DM read that did not run. Retryable
     * (503), so the client shows an error state, not "No messages yet".
     *
     * @return array{error: string, message: string}
     */
    private static function dmReadUnavailable(): array
    {
        return [
            'error'   => 'bcc_unavailable',
            'message' => 'Messages could not be loaded. Please try again.',
        ];
    }

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
