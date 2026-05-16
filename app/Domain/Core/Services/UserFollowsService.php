<?php
/**
 * UserFollowsService — composes the §3.1 Watching tab roster.
 *
 * Two related list shapes, both `{items: MemberSummary[], pagination}`
 * to match the /members directory and /groups/:id/members:
 *
 *   listFollowers($targetId, $offset, $limit) → "Being Watched"
 *     People who follow $target.
 *
 *   listFollowing($targetId, $offset, $limit) → "Keeping Tabs"
 *     People $target follows.
 *
 * Privacy: respects `MemberPrivacy.watching_hidden` — when the target
 * has hidden their watching list AND the viewer is NOT the target,
 * the service returns the privacy sentinel. The endpoint maps that
 * to 403 with reason_code = "watching_hidden" so the frontend can
 * render the right empty-state instead of a generic error.
 *
 * Hydration: routes through {@see MemberSummaryPrefetcher::primeFor()}
 * for the eleven-key per-user signal map UserViewService::getSummary
 * reads. The same helper powers /members, /groups/:id/members, and
 * the entity-card Watchers tab.
 *
 * Per §1 the only DB seam is repositories. Per §8 the return value
 * is a plain array; the endpoint wraps it in ApiResponse::ok().
 *
 * @package BCC\Trust\Core\Services
 * @since   §3.1 Watching tab (2026-05-14)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoFollowerRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\MemberSummaryPrefetcher;
use BCC\Trust\Core\Support\PrivacySettings;

if (!defined('ABSPATH')) {
    exit;
}

final class UserFollowsService
{
    public const MAX_LIMIT     = 100;
    public const DEFAULT_LIMIT = 24;

    public const RELATION_FOLLOWERS = 'followers';
    public const RELATION_FOLLOWING = 'following';

    /**
     * Followers of $targetId — i.e. people who follow $target.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{offset: int, limit: int, total: int, has_more: bool}}|array{error: string, message: string}
     */
    public function listFollowers(int $targetId, int $viewerId, int $offset, int $limit): array
    {
        return $this->list(self::RELATION_FOLLOWERS, $targetId, $viewerId, $offset, $limit);
    }

    /**
     * Users that $targetId follows.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{offset: int, limit: int, total: int, has_more: bool}}|array{error: string, message: string}
     */
    public function listFollowing(int $targetId, int $viewerId, int $offset, int $limit): array
    {
        return $this->list(self::RELATION_FOLLOWING, $targetId, $viewerId, $offset, $limit);
    }

    /**
     * @param self::RELATION_* $relation
     * @return array{items: list<array<string, mixed>>, pagination: array{offset: int, limit: int, total: int, has_more: bool}}|array{error: string, message: string}
     */
    private function list(string $relation, int $targetId, int $viewerId, int $offset, int $limit): array
    {
        if ($targetId <= 0) {
            return $this->emptyPage($offset, $limit);
        }

        $isSelf = ($viewerId > 0 && $viewerId === $targetId);

        // Privacy gate — watching_hidden hides BOTH followers and
        // following from non-self viewers. The single flag covers
        // both surfaces by design (§K2 single-toggle UX).
        if (!$isSelf && $this->watchingHidden($targetId)) {
            return [
                'error'   => 'bcc_permission_denied',
                'message' => 'This member has hidden their watching list.',
            ];
        }

        // Clamp pagination defensively — endpoint clamps too, but a
        // service-level caller (admin, cron) shouldn't need to know.
        if ($offset < 0) {
            $offset = 0;
        }
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }
        if ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        $counts = PeepSoFollowerRepository::getCounts($targetId);
        $total  = $relation === self::RELATION_FOLLOWERS
            ? $counts['followers']
            : $counts['following'];

        if ($total === 0) {
            return [
                'items'      => [],
                'pagination' => $this->pagination($offset, $limit, 0, false),
            ];
        }

        $userIds = $relation === self::RELATION_FOLLOWERS
            ? PeepSoFollowerRepository::getFollowers($targetId, $limit, $offset)
            : PeepSoFollowerRepository::getFollowing($targetId, $limit, $offset);

        if ($userIds === []) {
            return [
                'items'      => [],
                'pagination' => $this->pagination($offset, $limit, $total, false),
            ];
        }

        $prefetched = MemberSummaryPrefetcher::primeFor($userIds);

        $userView = Plugin::instance()->userViewService();
        $items    = [];
        foreach ($userIds as $userId) {
            $summary = $userView->getSummary($userId, $viewerId, $prefetched);
            if ($summary === null) {
                // Follower row references a user that no longer exists
                // in wp_users (deleted/anonymized). Skip rather than
                // 500 — total/items.length will momentarily disagree
                // and self-heal on the next paginate.
                continue;
            }
            $items[] = $summary;
        }

        $hasMore = ($offset + count($userIds)) < $total;

        return [
            'items'      => $items,
            'pagination' => $this->pagination($offset, $limit, $total, $hasMore),
        ];
    }

    /**
     * Single-flag read for `watching_hidden` via the canonical
     * PrivacySettings reader — handles the legacy `binder_hidden`
     * lazy migration so a profile written before the rename still
     * gates the same way.
     */
    private function watchingHidden(int $userId): bool
    {
        $profile = PrivacySettings::readProfile($userId);
        return $profile['watching_hidden'] === true;
    }

    /**
     * @return array{offset: int, limit: int, total: int, has_more: bool}
     */
    private function pagination(int $offset, int $limit, int $total, bool $hasMore): array
    {
        return [
            'offset'   => $offset,
            'limit'    => $limit,
            'total'    => $total,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array{offset: int, limit: int, total: int, has_more: bool}}
     */
    private function emptyPage(int $offset, int $limit): array
    {
        return [
            'items'      => [],
            'pagination' => $this->pagination($offset, $limit, 0, false),
        ];
    }
}
