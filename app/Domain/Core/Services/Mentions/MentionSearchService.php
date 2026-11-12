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
 *      primary_hall / owned_pages_by_type / type-counts. The mention
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
 *     hide_from_search (= discovery_optout since F-257).
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
     * Bound on each half of the name/handle candidate set handed to
     * PeepSoUserSearch. A short keystroke prefix can match many members;
     * the picker shows MAX_LIMIT of them after PeepSo's privacy filter.
     */
    public const MAX_CANDIDATES = 200;

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

        // MATCHING is MemberSearchCandidates (display name or @handle,
        // never the login; a hidden real name matches by handle only), the
        // same matcher /members uses. ⛔ The raw query is NOT passed to
        // PeepSoUserSearch: its own search matches first/last name plus
        // user_login, which made this picker a login-existence probe and
        // found members by a real name they had hidden.
        $candidates = \BCC\Trust\Core\Support\MemberSearchCandidates::ids($needle, self::MAX_CANDIDATES);
        if ($candidates === []) {
            return [];
        }

        // PRIVACY stays PeepSo's: PeepSoUserSearch force-sets fields=ID and
        // applies its privacy filter set in pre_user_query, over the
        // candidate set passed as `include`, with an EMPTY search term (its
        // own search clause is skipped when the term is empty). We pass
        // `orderby=username` for stable alphabetical results; the picker is
        // keystroke-driven, so a predictable order matters.
        $search = new \PeepSoUserSearch(
            [
                'number'  => $limit,
                'orderby' => 'username',
                'order'   => 'ASC',
                'include' => $candidates,
            ],
            $viewerId > 0 ? $viewerId : null,
            ''
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
        // One cached bulk avatar resolve for the whole result set.
        $avatars = \BCC\Core\PeepSo\PeepSoMediaCache::avatarUrlBulk($userIds);

        $out = [];
        foreach ($userIds as $userId) {
            $candidate = self::projectCandidate($userId, $avatars[$userId] ?? '');
            if ($candidate !== null) {
                $out[] = $candidate;
            }
        }
        return $out;
    }

    /**
     * @return array{user_id: int, handle: string, display_name: string, avatar_url: string}|null
     */
    private static function projectCandidate(int $userId, string $avatarUrl): ?array
    {
        $user = get_user_by('id', $userId);
        if (!$user instanceof \WP_User) {
            return null;
        }

        $identity    = \BCC\Trust\Core\Support\PublicIdentity::forUser($user);
        $handle      = $identity['handle'];
        $displayName = $identity['display_name'];

        return [
            'user_id'      => $userId,
            'handle'       => $handle,
            'display_name' => $displayName,
            'avatar_url'   => $avatarUrl,
        ];
    }
}
