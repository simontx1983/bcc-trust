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
 * Hydration: routes through {@see MemberCardPrefetcher::primeFor()}
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
use BCC\Trust\Core\Support\CountPrivacy;
use BCC\Trust\Core\Support\MemberCardPrefetcher;
use BCC\Trust\Core\Support\PeepSoFriendGate;
use BCC\Trust\Core\Support\ProfileAccess;
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

        // §F-02 — a member whose profile is private must not be listed in
        // someone else's followers or following. bcc-core owns the rule;
        // this decides whether to apply it.
        //
        // ⚠⚠ THE COUNT AND THE LIST GET THE SAME ARGUMENTS. They are two
        // queries, so gating one and not the other renders "128
        // following" above seven rows — and the `$total === 0`
        // short-circuit below would return an empty page while the count
        // insisted otherwise. Any change here must change both.
        //
        // ⚠ Admins pass `false`, which is how bcc-core expects an admin
        // read to be expressed: the repository deliberately holds no
        // capability check. Same narrower `manage_options` notion the
        // profile gate uses, so a row and its profile always agree.
        $enforce   = !ProfileAccess::viewerIsExemptFromVisibility();
        $friendIds = PeepSoFriendGate::viewerFriendIdList($viewerId);

        $counts = PeepSoFollowerRepository::getCounts($targetId, $viewerId, $friendIds, $enforce);
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
            ? PeepSoFollowerRepository::getFollowers($targetId, $limit, $offset, $viewerId, $friendIds, $enforce)
            : PeepSoFollowerRepository::getFollowing($targetId, $limit, $offset, $viewerId, $friendIds, $enforce);

        if ($userIds === []) {
            return [
                'items'      => [],
                'pagination' => $this->pagination(
                    $offset,
                    $limit,
                    $this->totalFor($targetId, $viewerId, $total, $relation),
                    false
                ),
            ];
        }

        $prefetched = MemberCardPrefetcher::primeFor($userIds, $viewerId);

        $cardView = Plugin::instance()->cardViewService();
        $items    = [];
        foreach ($userIds as $userId) {
            $card = $cardView->getMemberCardForList($userId, $viewerId, $prefetched);
            if ($card === null) {
                // Follower row references a user that no longer exists
                // in wp_users (deleted/anonymized). Skip rather than
                // 500 — total/items.length will momentarily disagree
                // and self-heal on the next paginate.
                continue;
            }
            $items[] = $card;
        }

        $hasMore = ($offset + count($userIds)) < $total;

        return [
            'items'      => $items,
            // ⚠ $hasMore above is computed from the REAL total, so paging
            // still terminates correctly when the count is hidden.
            'pagination' => $this->pagination(
                $offset,
                $limit,
                $this->totalFor($targetId, $viewerId, $total, $relation),
                $hasMore
            ),
        ];
    }

    /**
     * Single-flag read for `watching_hidden` via the canonical
     * PrivacySettings reader.
     */
    private function watchingHidden(int $userId): bool
    {
        $profile = PrivacySettings::readProfile($userId);
        return $profile['watching_hidden'] === true;
    }

    /**
     * ⚠⚠ `total` IS A DISCLOSURE, and it was the one F-283 named.
     *
     * This service refuses the whole LIST when `watching_hidden` is set,
     * but that is a DIFFERENT flag from `follower_count_hidden`. A member
     * who hid only their follower count still had the true number served
     * here as `pagination.total`, while their profile reported 0 — so the
     * setting was observably ineffective to anyone who paged the list.
     *
     * Zeroed rather than refused on purpose: the list itself is permitted
     * (that is what the other flag governs), so the rows still come back
     * and only the aggregate the member hid is withheld. `has_more` is
     * computed from the real total by the caller and is unaffected.
     */
    private function totalFor(int $targetId, int $viewerId, int $total, string $relation): int
    {
        // ⚠ FOLLOWERS ONLY. `follower_count_hidden` governs the count of
        // people following this member, not the count they follow — that
        // is `watching_hidden`, and this service already refuses the whole
        // list under it, so by the time we are here the following total is
        // permitted. An earlier revision applied the followers flag to
        // both and zeroed `/following`'s total for no reason (review, LOW).
        if ($relation !== self::RELATION_FOLLOWERS) {
            return $total;
        }

        $isSelf = $viewerId > 0 && $viewerId === $targetId;

        return CountPrivacy::hides(
            PrivacySettings::readProfile($targetId),
            CountPrivacy::FLAG_FOLLOWERS,
            $isSelf
        ) ? 0 : $total;
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
