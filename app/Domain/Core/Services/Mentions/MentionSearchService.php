<?php
declare(strict_types=1);

/**
 * MentionSearchService — backs the §4.4 GET /users/mention-search
 * endpoint. Slim prefix lookup + view-model projection.
 *
 * Distinct from `/members?q=` because keystroke autocomplete needs:
 *
 *   1. A tighter privacy gate. The directory grid runs through
 *      UserViewService::getSummary which composes follower counts /
 *      primary_local / owned_pages_by_type / type-counts. The mention
 *      picker MUST NOT leak more than {id, handle, display_name,
 *      avatar_url} — a leaner shape is also a smaller scrape surface
 *      (no follower counts, no group affiliation, no rep tier).
 *
 *   2. A smaller per-row payload (8 rows vs 24, no prefetch shape).
 *      Keystroke autocomplete is a high-frequency endpoint; the cost
 *      of UserViewService's getSummary call would be wasted bytes.
 *
 *   3. Routing through PeepSoUserSearch — the same privacy-aware
 *      wrapper bcc-search's UserSearchRepository now uses (search#4
 *      remediation). The two stay separate implementations because the
 *      mention picker's shape/limits differ (points 1–2), not because
 *      of any privacy gap in bcc-search.
 *
 * The privacy filter set inherited from PeepSoUserSearch:
 *   - ban filter, profile_acc != PRIVATE, members-only gate when
 *     viewer is anon, peepso_user_blocked (both directions),
 *     allow_hide_user_from_user_listing, plus the
 *     `peepso_user_search_args` hook BCC's
 *     PrivacySettings::filterPeepSoSearchArgs attaches for
 *     bcc_privacy_discovery_optout.
 *
 * Output shape (`MentionCandidate`):
 *
 *   array{
 *     user_id:      int,
 *     handle:       string,
 *     display_name: string,
 *     avatar_url:   string
 *   }
 *
 * @package BCC\Trust\Core\Services\Mentions
 * @since v1.5 (Phase 1d)
 */

namespace BCC\Trust\Core\Services\Mentions;

if (!defined('ABSPATH')) {
    exit;
}

final class MentionSearchService
{
    public const DEFAULT_LIMIT = 8;
    public const MAX_LIMIT     = 8;
    public const MAX_QUERY_LEN = 32;

    /**
     * Execute a prefix search against PeepSoUserSearch's privacy-aware
     * WP_User_Query wrapper. Returns the §4.4 view-model shape; empty
     * list when no rows match or PeepSo isn't installed.
     *
     * @return list<array{user_id: int, handle: string, display_name: string, avatar_url: string}>
     */
    public function search(int $viewerId, string $query, int $limit): array
    {
        $needle = mb_substr(trim($query), 0, self::MAX_QUERY_LEN);
        if ($needle === '') {
            return [];
        }
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }
        if ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        if (!class_exists('PeepSoUserSearch')) {
            return [];
        }

        // PeepSoUserSearch force-sets fields=ID and applies the
        // privacy filter set in its pre_user_query callback. We pass
        // `orderby=username` for stable alphabetical results — the
        // mention picker is keystroke-driven, so prefix bias + alpha
        // sort gives the most predictable typeahead UX.
        //
        // The constructor itself escapes $needle via $wpdb->esc_like;
        // we trim + length-cap upstream so a pathological query can't
        // inflate the LIKE planner.
        $search = new \PeepSoUserSearch(
            [
                'number'  => $limit,
                'orderby' => 'username',
                'order'   => 'ASC',
            ],
            $viewerId > 0 ? $viewerId : null,
            $needle
        );

        /** @var list<int|numeric-string> $rawIds */
        $rawIds = $search->results;
        $userIds = [];
        foreach ($rawIds as $id) {
            $idInt = (int) $id;
            if ($idInt > 0) {
                $userIds[] = $idInt;
            }
        }
        if ($userIds === []) {
            return [];
        }

        // Prime usermeta cache once for handle lookups.
        update_meta_cache('user', $userIds);

        $out = [];
        foreach ($userIds as $userId) {
            $candidate = self::projectCandidate($userId);
            if ($candidate !== null) {
                $out[] = $candidate;
            }
        }
        return $out;
    }

    /**
     * @return array{user_id: int, handle: string, display_name: string, avatar_url: string}|null
     */
    private static function projectCandidate(int $userId): ?array
    {
        $user = get_user_by('id', $userId);
        if (!$user instanceof \WP_User) {
            return null;
        }

        $handleRaw = get_user_meta($userId, 'bcc_handle', true);
        $handle = is_string($handleRaw) && $handleRaw !== ''
            ? $handleRaw
            : (string) $user->user_login;

        $displayName = (string) $user->display_name;
        if ($displayName === '') {
            $displayName = (string) $user->user_login;
        }

        $avatarUrlRaw = get_avatar_url($userId, ['size' => 96]);
        $avatarUrl    = is_string($avatarUrlRaw) ? $avatarUrlRaw : '';

        return [
            'user_id'      => $userId,
            'handle'       => $handle,
            'display_name' => $displayName,
            'avatar_url'   => $avatarUrl,
        ];
    }
}
