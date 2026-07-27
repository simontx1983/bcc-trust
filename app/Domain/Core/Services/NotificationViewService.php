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
 *   - REACTION       → `/feed?focus=<act_id>` (jump back to the post
 *                       that was reacted to)
 *   - REVIEW         → `/v/<page_handle>` etc. via PageTypeMap +
 *                       page_id (the reviewed page)
 *   - CARD_WATCHED   → `/u/<actor_handle>` (the watcher's profile)
 *   - RANK_UP        → `/u/<recipient_handle>` (your own profile —
 *                       progression strip lives there)
 *   - MENTION        → `/?focus=<act_id>` (jump to the floor focused
 *                       on the post containing the mention; mirrors
 *                       REACTION). For comment mentions, act_id is the
 *                       PARENT post's act_id so the user lands on the
 *                       post on the floor — the FE has no comment-anchor
 *                       consumer in V1.
 *   - HALL_POST      → `/halls/<slug>` resolved from `external_id`
 *                       (the Hall's group_id). Falls back to
 *                       `/halls` if the group is no longer a Hall
 *                       (deleted, renamed off-prefix).
 *   - COMMENT_RECEIVED → `/?focus=<act_id>` (jump to the floor focused
 *                       on the parent post that received the comment;
 *                       mirrors REACTION + MENTION shape — the FE has
 *                       no comment-anchor consumer in V1).
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
use BCC\Trust\Core\Repositories\NotificationRepository;
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

    public function __construct(
        private readonly NotificationRepository $repo
    ) {
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
            if ($fid > 0) {
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
                $actorsById[$id] = self::resolveActor($id, $avatarById[$id] ?? '');
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $shape = self::shapeRow($row, $actorsById);
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
     * @return array<string, mixed>|null
     */
    private static function shapeRow(object $row, array $actorsById = []): ?array
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

        $actor = $actorsById[$fromUserId] ?? self::resolveActor($fromUserId, '');
        $link  = self::resolveLink($type, $externalId, $actId, $actor['handle']);

        return [
            'id'         => $id,
            'type'       => $type,
            'message'    => $message,
            'created_at' => self::toIso8601($timestamp),
            'read'       => $isRead,
            'actor'      => $actor,
            'link'       => $link,
        ];
    }

    /**
     * @param string $avatarUrl Pre-resolved (bulk) avatar; '' lets callers
     *                          on the single-actor path pass empty.
     * @return array{id: int, handle: string, display_name: string, avatar_url: string}
     */
    private static function resolveActor(int $userId, string $avatarUrl): array
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
        $handle = (string) get_user_meta($userId, 'bcc_handle', true);
        if ($handle === '') {
            $handle = $user->user_login;
        }
        $displayName = $user->display_name !== '' ? $user->display_name : $handle;

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
            NotificationType::REACTION    => $actId > 0 ? '/?focus=' . $actId : '/',
            NotificationType::REVIEW      => self::resolvePageLink($externalId),
            NotificationType::CARD_WATCHED => $actorHandle !== '' ? '/u/' . $actorHandle : '/',
            NotificationType::RANK_UP     => $actorHandle !== '' ? '/u/' . $actorHandle : '/',
            // Welcome notification routes to the floor — the user is
            // probably already there when they see it (it fires within
            // seconds of signup), but tapping the bell row should still
            // do something coherent.
            NotificationType::WELCOME     => '/',
            // Mention links to the floor focused on the post containing
            // the @-tag. For comment mentions the dispatcher passes the
            // PARENT post's act_id so this lands on the post; the FE
            // has no comment-anchor consumer in V1.
            NotificationType::MENTION     => $actId > 0 ? '/?focus=' . $actId : '/',
            // HALL_POST: bell row's external_id is the Hall's group_id.
            // Deep-link to /halls/{slug} so the user lands on the Hall
            // detail page (where they'll see the new post in the feed
            // section). Falls back to /halls (the directory) if the
            // group is no longer resolvable.
            NotificationType::HALL_POST   => self::resolveHallLink($externalId),
            // COMMENT_RECEIVED: bell row's act_id is the parent post's
            // activity row; mirrors REACTION + MENTION shape. The FE
            // has no comment-anchor consumer in V1.
            NotificationType::COMMENT_RECEIVED => $actId > 0 ? '/?focus=' . $actId : '/',
            // V2 Trust Attestation Layer — all four events link to the
            // attestor's profile (the source of the change). Revoke
            // uses the same shape since the (former) attestor is the
            // person whose action prompted the bell. Falls back to /
            // if the actor handle is empty (handle blanked, deleted).
            NotificationType::ATTESTATION_VOUCH_RECEIVED       => $actorHandle !== '' ? '/u/' . $actorHandle : '/',
            NotificationType::ATTESTATION_STAND_BEHIND_RECEIVED => $actorHandle !== '' ? '/u/' . $actorHandle : '/',
            NotificationType::ATTESTATION_REVOKED              => $actorHandle !== '' ? '/u/' . $actorHandle : '/',
            NotificationType::ATTESTATION_REAFFIRMED           => $actorHandle !== '' ? '/u/' . $actorHandle : '/',
            default                       => '/',
        };
    }

    /**
     * Resolve the deep-link for a HALL_POST bell row. `external_id` is
     * the Hall's group_id; we look up the slug via the shared
     * PeepSoGroupRepository (which automatically filters by the hall-kind
     * meta — null means "not a Hall anymore" or "deleted").
     * Falls back to /halls on any resolution failure so the bell row
     * never produces a 404 URL.
     */
    private static function resolveHallLink(int $groupId): string
    {
        if ($groupId <= 0) {
            return '/halls';
        }
        $group = PeepSoGroupRepository::findHallById($groupId);
        if ($group === null) {
            return '/halls';
        }
        $slug = isset($group->post_name) ? (string) $group->post_name : '';
        return $slug !== '' ? '/halls/' . $slug : '/halls';
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
