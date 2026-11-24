<?php
/**
 * Notification View Service — composes /me/notifications view-models
 * per §A2 / §L5 / §I1.
 *
 * Reads raw rows from NotificationRepository, hydrates the actor
 * (handle + display_name + avatar) and builds the click-through
 * `link` server-side so the frontend never derives a URL from a
 * type code.
 *
 * Per-type link resolution:
 *   - REACTION       → `/post/<act_id>` (the permalink of the post that
 *                       was reacted to; F-245 — `?focus=` was never read)
 *   - REVIEW         → `/v/<page_handle>` etc. via PageTypeMap +
 *                       page_id (the reviewed page)
 *   - CARD_WATCHED   → `/u/<actor_handle>` (the watcher's profile)
 *   - RANK_UP / RANK_DEMOTED / RANK_RECOVERY_* / RANK_DECAY_WARNING /
 *     RANK_FINDING_* / RANK_APPEAL_OUTCOME
 *                    → `/me/progression` (the §N11 standing file —
 *                       the member's own progression surface, where
 *                       grade, recovery, and findings detail render)
 *   - MENTION        → `/post/<act_id>` (the permalink of the post
 *                       containing the mention; mirrors
 *                       REACTION). For comment mentions, act_id is the
 *                       PARENT post's act_id so the user lands on the
 *                       post on the floor — the FE has no comment-anchor
 *                       consumer in V1.
 *   - DELEGATOR_GROUP_REMOVED
 *                    → `/communities/<slug>` from `external_id` (the group),
 *                       with the message rendered alongside it; `/` and the
 *                       row's fallback message if the group has since gone
 *   - HALL_POST      → `/halls/<slug>` resolved from `external_id`
 *                       (the Hall's group_id). Falls back to
 *                       `/halls` if the group is no longer a Hall
 *                       (deleted, renamed off-prefix).
 *   - COMMENT_RECEIVED → `/post/<act_id>` (the permalink of the parent
 *                       post that received the comment;
 *                       mirrors REACTION + MENTION shape — the FE has
 *                       no comment-anchor consumer in V1).
 *   - GROUP_JOIN_REQUESTED
 *                    → `/communities/<slug>/members#requests` (F-389: the
 *                       Members tab's requests block, where the owner or
 *                       manager decides); `/communities` if the group has
 *                       since gone. GROUP_JOIN_APPROVED + GROUP_INVITED
 *                       → `/communities/<slug>`, same fallback.
 *
 * Rows whose type is unrecognized (e.g. a future migration or a
 * corrupt row) are filtered out rather than surfaced as "Unknown" —
 * better to show 9 valid items than 10 with one mystery row.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §I1)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\NotificationRepository;
use BCC\Trust\Core\Repositories\PeepSoQueryRepository;
use BCC\Trust\Core\Support\NotificationType;
use BCC\Trust\Core\Support\PageTypeMap;

if (!defined('ABSPATH')) {
    exit;
}

final class NotificationViewService
{
    /**
     * Frontend route prefix per card_kind. Mirrors
     * CardsSearchEndpoint::KIND_TO_ROUTE_PREFIX — duplicated rather
     * than imported because that constant is private to the search
     * endpoint, and a "shared route map" support class is over-
     * engineering for two callsites. Keep in sync if a new kind lands.
     *
     * @var array<string, string>
     */
    private const KIND_TO_ROUTE_PREFIX = [
        'validator' => '/v/',
        'project'   => '/p/',
        'creator'   => '/c/',
    ];

    /**
     * Types whose `link` is the permalink of the post they are about
     * (`/post/<act_id>`, F-245), and so carry a meaningful
     * `target_available`.
     */
    private const POST_LINKED_TYPES = [
        NotificationType::REACTION,
        NotificationType::MENTION,
        NotificationType::COMMENT_RECEIVED,
    ];

    public function __construct(
        private readonly NotificationRepository $repo,
        private readonly ?HiddenActivityRepository $hiddenRepo = null
    ) {
    }

    /**
     * F-245: the act ids (of $actIds) whose post is KNOWN to be gone —
     * deleted or unpublished, or moderation-hidden.
     *
     * Unknown is not gone: when either read fails this answers "none gone",
     * so a row keeps `target_available: true` and its link, and the
     * permalink makes the final call (it 404s a missing post). Nothing here
     * is cached, so a failure is never remembered.
     *
     * @param list<int> $actIds
     * @return array<int, true>
     */
    private function goneActIds(array $actIds): array
    {
        if ($actIds === []) {
            return [];
        }

        $live = PeepSoQueryRepository::liveActivityIds($actIds);
        if ($live === null) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] notifications: post availability unreadable; links left as-is', [
                'act_ids' => count($actIds),
            ]);
            return [];
        }

        // Only a post that still exists can be moderation-hidden, so only
        // the live ids are checked.
        $hidden  = $this->hiddenAmong(array_values(array_intersect($actIds, $live)));
        $liveSet = array_flip($live);
        $gone    = [];
        foreach ($actIds as $actId) {
            if (!isset($liveSet[$actId]) || isset($hidden[$actId])) {
                $gone[$actId] = true;
            }
        }

        return $gone;
    }

    /**
     * F-353: which of these (live) act ids are moderation-hidden, read for
     * just this page's ids instead of the whole hidden list.
     *
     * Failure behaviour is unchanged. If the scoped read fails, the answer
     * comes from getAllHiddenIds() as it always did: its cached list, else
     * the last-known-good list (F-98), so a failure never reads as "nothing
     * hidden" while a known list exists. Only when that is unavailable too
     * does existence alone decide (unknown is not gone; see goneActIds).
     *
     * @param list<int> $actIds
     * @return array<int, int> a set keyed by hidden act id
     */
    private function hiddenAmong(array $actIds): array
    {
        if ($this->hiddenRepo === null || $actIds === []) {
            return [];
        }

        try {
            $hidden = $this->hiddenRepo->hiddenAmong($actIds) ?? $this->hiddenRepo->getAllHiddenIds();
        } catch (\Throwable $e) {
            // Moderation state unreadable: judge on existence alone.
            return [];
        }

        return array_flip($hidden);
    }

    /**
     * Build the paginated /me/notifications response.
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   pagination: array{has_more: bool, next_cursor: string|null}
     * }
     */
    public function getNotifications(int $userId, int $limit, ?string $cursor): array
    {
        $beforeId = self::parseCursor($cursor);
        // Fetch one extra to detect has_more without a separate count.
        $rows = $this->repo->findForUser($userId, $limit + 1, $beforeId);

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        // Batch-prime actor identity: the bell is polled frequently and
        // resolveActor otherwise does get_userdata + get_user_meta +
        // avatar resolution per row (a full N+1, repeated actors included).
        $actorIds = [];
        foreach ($rows as $row) {
            $fid = isset($row->not_from_user_id) ? (int) $row->not_from_user_id : 0;
            // F-578: a row that speaks as a page shows no person, so its
            // sender is not read at all.
            $asPage = self::speaksAsPage(
                isset($row->not_type) && is_string($row->not_type) ? $row->not_type : '',
                isset($row->not_external_id) ? (int) $row->not_external_id : 0
            );
            if ($fid > 0 && !$asPage) {
                $actorIds[$fid] = true;
            }
        }
        $actorsById = [];
        if ($actorIds !== []) {
            $ids = array_keys($actorIds);
            get_users(['include' => $ids]);          // primes the WP user cache (get_userdata hits)
            update_meta_cache('user', $ids);          // primes bcc_handle reads
            $avatarById = \BCC\Core\PeepSo\PeepSoMediaCache::avatarUrlBulk($ids);
            foreach ($ids as $id) {
                // The recipient is the viewer: their own name stays theirs (F-513).
                $actorsById[$id] = self::resolveActor($id, $avatarById[$id] ?? '', $userId);
            }
        }

        // Batched for the same reason as the actors above. See
        // DelegatorGroupView::removalNotices() for why these aren't in the row.
        $removedFrom = [];
        foreach ($rows as $row) {
            $groupId = isset($row->not_external_id) ? (int) $row->not_external_id : 0;
            if ($groupId > 0 && ($row->not_type ?? '') === NotificationType::DELEGATOR_GROUP_REMOVED) {
                $removedFrom[$groupId] = true;
            }
        }
        $removalNotices = \BCC\Trust\Onchain\Services\DelegatorGroupView::removalNotices(array_keys($removedFrom));

        // F-245: one batched "is the post gone" check for the rows that link
        // to a post, instead of letting the bell send the user to a 404.
        $postActIds = [];
        foreach ($rows as $row) {
            $actId = isset($row->not_act_id) ? (int) $row->not_act_id : 0;
            if ($actId > 0 && in_array($row->not_type ?? '', self::POST_LINKED_TYPES, true)) {
                $postActIds[$actId] = true;
            }
        }
        $goneActIds = $this->goneActIds(array_keys($postActIds));

        $items = [];
        foreach ($rows as $row) {
            $shape = self::shapeRow($row, $actorsById, $removalNotices, $goneActIds);
            if ($shape !== null) {
                $items[] = $shape;
            }
        }

        $nextCursor = null;
        if ($hasMore && count($items) > 0) {
            $last = $items[count($items) - 1];
            $lastId = isset($last['id']) ? (int) $last['id'] : 0;
            if ($lastId > 0) {
                $nextCursor = (string) $lastId;
            }
        }

        return [
            'items'      => $items,
            'pagination' => [
                'has_more'    => $hasMore,
                'next_cursor' => $nextCursor,
            ],
        ];
    }

    public function getUnreadCount(int $userId): int
    {
        return $this->repo->countUnreadForUser($userId);
    }

    // ──────────────────────────────────────────────────────────────────
    // Row → view-model
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array<int, array{id: int, handle: string, display_name: string, avatar_url: string}> $actorsById
     * @param array<int, array{message: string, link: string}> $removalNotices keyed by group id
     * @param array<int, true> $goneActIds act ids whose post is known to be gone (F-245)
     * @return array<string, mixed>|null
     */
    private static function shapeRow(object $row, array $actorsById = [], array $removalNotices = [], array $goneActIds = []): ?array
    {
        $type = isset($row->not_type) && is_string($row->not_type) ? $row->not_type : '';
        if (!NotificationType::isValid($type)) {
            return null;
        }

        $id          = isset($row->not_id) ? (int) $row->not_id : 0;
        $fromUserId  = isset($row->not_from_user_id) ? (int) $row->not_from_user_id : 0;
        $externalId  = isset($row->not_external_id) ? (int) $row->not_external_id : 0;
        $actId       = isset($row->not_act_id) ? (int) $row->not_act_id : 0;
        $message     = isset($row->not_message) && is_string($row->not_message) ? $row->not_message : '';
        $timestamp   = isset($row->not_timestamp) && is_string($row->not_timestamp) ? $row->not_timestamp : '';
        $isRead      = isset($row->not_read) && (int) $row->not_read === 1;

        if ($id <= 0 || $message === '') {
            return null;
        }

        $recipientId = isset($row->not_user_id) ? (int) $row->not_user_id : 0;
        // F-578: a reply on a card page's review speaks as the PAGE: no
        // person as its actor, and the text re-rendered in the page's voice
        // (which also covers rows written before, in the "@handle …" form).
        $asPage  = self::speaksAsPage($type, $externalId);
        $actor   = $asPage
            ? self::resolveActor(0, '', $recipientId)
            : ($actorsById[$fromUserId] ?? self::resolveActor($fromUserId, '', $recipientId));
        $link    = self::resolveLink($type, $externalId, $actId, $actor['handle']);
        $message = $asPage
            ? self::pageVoiceReplyMessage($externalId)
            : self::withCurrentActorHandle($message, $actor['handle']);

        if ($type === NotificationType::DELEGATOR_GROUP_REMOVED && isset($removalNotices[$externalId])) {
            $message = $removalNotices[$externalId]['message'];
            $link    = $removalNotices[$externalId]['link'];
        }

        return [
            'id'               => $id,
            'type'             => $type,
            'message'          => $message,
            'created_at'       => self::toIso8601($timestamp),
            'read'             => $isRead,
            'actor'            => $actor,
            'link'             => $link,
            // F-245: false only when this row links to a post that is KNOWN
            // to be gone (deleted, unpublished or moderation-hidden). True
            // for every other row, and when availability could not be read.
            'target_available' => !(in_array($type, self::POST_LINKED_TYPES, true) && isset($goneActIds[$actId])),
        ];
    }

    /** The most a rendered message holds: PeepSo's column width, which it cuts stored messages to. */
    public const MESSAGE_MAX_LENGTH = 200;

    /**
     * F-578: does this bell speak as a PAGE rather than a person? A review
     * reply on a card page's review: on that page's Reviews tab the reply
     * can stand as the page's own response, so its bell names the page and
     * shows no person (no actor, no @handle), on the bell list and in the
     * digest alike. Decided by the target alone: the reviewer is not the
     * request's user when the bell is written, so a per-viewer profile check
     * cannot be asked there. A reply on a member's own profile review still
     * names the member, who is the subject. Rationale: F-578 (private
     * tracker).
     */
    public static function speaksAsPage(string $type, int $externalId): bool
    {
        return $type === NotificationType::REVIEW_REPLY
            && $externalId > 0
            && !\BCC\Trust\Core\Services\MemberSelfPageService::isSelfPage($externalId);
    }

    /**
     * F-578: the page-voice text of a review-reply bell: "{page} replied to
     * your review.", or a neutral line when the page has no title. The
     * dispatcher writes it and the readers re-render it, so the stored row
     * and the shown text agree, old rows included.
     */
    public static function pageVoiceReplyMessage(int $pageId): string
    {
        $page = $pageId > 0 ? get_post($pageId) : null;
        $name = $page instanceof \WP_Post ? trim((string) $page->post_title) : '';

        return $name !== '' ? sprintf('%s replied to your review.', $name) : 'Your review got a reply.';
    }

    /**
     * F-412: name a message's actor by their CURRENT @handle.
     *
     * NotificationDispatcher writes the actor's handle into the stored text
     * ("@handle reacted to your post."). After a handle change, the bell
     * showed that old handle beside `actor.handle`, which is resolved now, and
     * the digest mailed it. Either way, the old and new handles were linked
     * for every member who saw the row. A rename retires the old handle
     * precisely so the two are not tied together publicly.
     *
     * Every dispatcher message that starts with "@" starts with its
     * from-user's handle; system messages (rank, welcome, claims, disputes,
     * announcements) never start with "@". So only a leading "@token" is
     * replaced, and only when the actor's current handle is known. An actor
     * that no longer resolves keeps the stored text.
     *
     * The result is cut to MESSAGE_MAX_LENGTH as PeepSo cuts the stored one,
     * since a longer new handle can push a full-length message past it.
     */
    public static function withCurrentActorHandle(string $message, string $actorHandle): string
    {
        if ($actorHandle === '' || preg_match('/^@\S+/u', $message, $m) !== 1) {
            return $message;
        }
        $rendered = '@' . $actorHandle . substr($message, strlen($m[0]));

        return mb_strlen($rendered, 'UTF-8') > self::MESSAGE_MAX_LENGTH
            ? mb_substr($rendered, 0, self::MESSAGE_MAX_LENGTH, 'UTF-8')
            : $rendered;
    }

    /** The current public @handle the bell shows for $userId ('' when the account no longer resolves). */
    public static function actorHandle(int $userId): string
    {
        return self::resolveActor($userId, '')['handle'];
    }

    /**
     * @param int    $viewerId  Who reads the bell (the recipient): a member sees
     *                          their own real name; others see the §K2 mask.
     * @param string $avatarUrl Pre-resolved (bulk) avatar; '' lets callers
     *                          on the single-actor path pass empty.
     * @return array{id: int, handle: string, display_name: string, avatar_url: string}
     */
    private static function resolveActor(int $userId, string $avatarUrl, int $viewerId = 0): array
    {
        if ($userId <= 0) {
            return [
                'id'           => 0,
                'handle'       => '',
                'display_name' => '',
                'avatar_url'   => '',
            ];
        }
        $user = get_userdata($userId);
        if ($user === false) {
            return [
                'id'           => $userId,
                'handle'       => '',
                'display_name' => '',
                'avatar_url'   => '',
            ];
        }
        $identity    = \BCC\Trust\Core\Support\PublicIdentity::forUser($user, $viewerId);
        $handle      = $identity['handle'];
        $displayName = $identity['display_name'];

        return [
            'id'           => $userId,
            'handle'       => $handle,
            'display_name' => $displayName,
            'avatar_url'   => $avatarUrl,
        ];
    }

    private static function resolveLink(string $type, int $externalId, int $actId, string $actorHandle): string
    {
        return match ($type) {
            // F-245: reaction, mention and comment rows open the post's own
            // permalink (/post/<act_id>, served by GET /feed/{id}). They used
            // to link `/?focus=<act_id>`, which no frontend route reads, so
            // every one of them dead-ended on the Floor.
            NotificationType::REACTION    => $actId > 0 ? '/post/' . $actId : '/',
            NotificationType::REVIEW      => self::resolvePageLink($externalId),
            // F-345: a member self-page has no wp_post; the replier IS that
            // member, so the bell opens their profile.
            NotificationType::REVIEW_REPLY => \BCC\Trust\Core\Services\MemberSelfPageService::isSelfPage($externalId) && $actorHandle !== ''
                ? '/u/' . $actorHandle
                : self::resolvePageLink($externalId),
            NotificationType::CARD_WATCHED => $actorHandle !== '' ? '/u/' . $actorHandle : '/',
            // Rank standing changes + §15 findings all deep-link to the
            // member's own progression surface (/me/progression — the
            // §N11 standing file, same route the FE's nav-items.tsx
            // registers as "My Progression"). These are system events:
            // external_id/act_id carry nothing routable and the actor
            // is the recipient (RANK_UP) or nobody, so before this arm
            // most of them fell to the '/' default and the bell row
            // dead-ended on the floor.
            NotificationType::RANK_UP,
            NotificationType::RANK_DEMOTED,
            NotificationType::RANK_RECOVERY_STARTED,
            NotificationType::RANK_RECOVERY_REMINDER,
            NotificationType::RANK_DECAY_WARNING,
            NotificationType::RANK_FINDING_ISSUED,
            NotificationType::RANK_APPEAL_OUTCOME,
            NotificationType::RANK_FINDING_REVERSED => '/me/progression',
            // Welcome notification routes to the floor — the user is
            // probably already there when they see it (it fires within
            // seconds of signup), but tapping the bell row should still
            // do something coherent.
            NotificationType::WELCOME     => '/',
            // Mention links to the post containing the @-tag. For comment
            // mentions the dispatcher passes the PARENT post's act_id so
            // this lands on the post; the FE has no comment-anchor consumer
            // in V1.
            NotificationType::MENTION     => $actId > 0 ? '/post/' . $actId : '/',
            // HALL_POST: bell row's external_id is the Hall's group_id.
            // Deep-link to /halls/{slug} so the user lands on the Hall
            // detail page (where they'll see the new post in the feed
            // section). Falls back to /halls (the directory) if the
            // group is no longer resolvable.
            NotificationType::HALL_POST   => self::resolveHallLink($externalId),
            // COMMENT_RECEIVED: bell row's act_id is the parent post's
            // activity row; mirrors REACTION + MENTION shape. The FE
            // has no comment-anchor consumer in V1.
            NotificationType::COMMENT_RECEIVED => $actId > 0 ? '/post/' . $actId : '/',
            // V2 Trust Attestation Layer — all four events link to the
            // attestor's profile (the source of the change). Revoke
            // uses the same shape since the (former) attestor is the
            // person whose action prompted the bell. Falls back to /
            // if the actor handle is empty (handle blanked, deleted).
            NotificationType::ATTESTATION_VOUCH_RECEIVED       => $actorHandle !== '' ? '/u/' . $actorHandle : '/',
            NotificationType::ATTESTATION_STAND_BEHIND_RECEIVED => $actorHandle !== '' ? '/u/' . $actorHandle : '/',
            NotificationType::ATTESTATION_REVOKED              => $actorHandle !== '' ? '/u/' . $actorHandle : '/',
            NotificationType::ATTESTATION_REAFFIRMED           => $actorHandle !== '' ? '/u/' . $actorHandle : '/',
            // F-73 auto-hide notice: deliberately NOT '/post/<act>' — the
            // post is moderation-hidden, so every feed (the author's
            // included) excludes it and a permalink would dead-end.
            NotificationType::CONTENT_AUTO_HIDDEN => '/',
            // F-272: external_id is the group. Invites land the invitee on
            // the group page (an invitee may view a secret group);
            // approvals land the new member there. F-389: a request lands
            // the owner/manager on the Members tab's "Requests &
            // invitations" block, where it is decided (the frontend's
            // `id="requests"` anchor; without it, the tab's top, which is
            // that block).
            NotificationType::GROUP_JOIN_REQUESTED => self::resolveCommunityLink($externalId, '/members#requests'),
            NotificationType::GROUP_JOIN_APPROVED,
            NotificationType::GROUP_INVITED => self::resolveCommunityLink($externalId),
            // F-307: a taken-down notice 404s for everyone but its owner's
            // manage view, so neither notice deep-links to it.
            NotificationType::ANNOUNCEMENT_HIDDEN   => '/',
            NotificationType::ANNOUNCEMENT_RESTORED => '/',
            default                       => '/',
        };
    }

    /**
     * Resolve the deep-link for a HALL_POST bell row. `external_id` is
     * the Hall's group_id; we look up the slug via the shared
     * PeepSoGroupRepository (which automatically filters by the hall-kind
     * meta — null means "not a Hall anymore" or "deleted").
     * Falls back to the Halls list on any resolution failure so the
     * bell row never produces a 404 URL.
     */
    /**
     * A plain (user/system) community's page, plus `$suffix` (a sub-route
     * and/or anchor, e.g. '/members#requests'), or the bare directory when
     * the group no longer resolves. The suffix never rides on the fallback:
     * '/communities/members' is not a route.
     */
    private static function resolveCommunityLink(int $groupId, string $suffix = ''): string
    {
        $group = $groupId > 0 ? (PeepSoGroupRepository::findManyByIds([$groupId])[$groupId] ?? null) : null;
        $slug  = $group !== null && isset($group->post_name) ? (string) $group->post_name : '';
        return $slug !== ''
            ? \BCC\Trust\Core\Support\CardUrlMap::groupUrl('user', $slug) . $suffix
            : '/communities';
    }

    private static function resolveHallLink(int $groupId): string
    {
        if ($groupId <= 0) {
            return \BCC\Trust\Core\Support\CardUrlMap::HALLS_LIST_URL;
        }
        $group = PeepSoGroupRepository::findHallById($groupId);
        if ($group === null) {
            return \BCC\Trust\Core\Support\CardUrlMap::HALLS_LIST_URL;
        }
        $slug = isset($group->post_name) ? (string) $group->post_name : '';
        return $slug !== ''
            ? \BCC\Trust\Core\Support\CardUrlMap::groupUrl(\BCC\Trust\Core\ValueObjects\GroupType::Hall->value, $slug)
            : \BCC\Trust\Core\Support\CardUrlMap::HALLS_LIST_URL;
    }

    private static function resolvePageLink(int $pageId): string
    {
        if ($pageId <= 0) {
            return '/';
        }
        $post = get_post($pageId);
        if (!$post instanceof \WP_Post) {
            return '/';
        }
        $pageType = (string) get_post_meta($pageId, '_bcc_page_type', true);
        if ($pageType === '') {
            $pageType = 'builder';
        }
        $kind = PageTypeMap::kindForPageType($pageType);
        if ($kind === null || !isset(self::KIND_TO_ROUTE_PREFIX[$kind])) {
            return '/';
        }
        $handle = $post->post_name !== '' ? $post->post_name : (string) $pageId;
        return self::KIND_TO_ROUTE_PREFIX[$kind] . $handle;
    }

    private static function parseCursor(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        if (!ctype_digit($cursor)) {
            return 0;
        }
        $value = (int) $cursor;
        return $value > 0 ? $value : 0;
    }

    private static function toIso8601(string $mysqlTimestamp): string
    {
        if ($mysqlTimestamp === '') {
            return '';
        }
        $epoch = strtotime($mysqlTimestamp . ' UTC');
        if ($epoch === false) {
            return '';
        }
        return gmdate('Y-m-d\TH:i:s\Z', $epoch);
    }
}
