<?php
/**
 * Group Members Service — composes the §4.7.7 paginated roster behind
 * `GET /bcc/v1/groups/{id}/members`.
 *
 * Privacy model (mirrors §4.7.5 / §4.7.6 — single source of truth via
 * {@see GroupsService::resolveGroupAccess()}):
 *
 *   secret  + non-member → null            (endpoint maps to 404)
 *   secret  + member     → full roster
 *   closed  + non-member → forbidden       (endpoint maps to 403)
 *   closed  + member     → full roster
 *   open    + anyone     → roster minus members THIS viewer may not see
 *                          (ProfileAccess::filterVisible; count unchanged)
 *
 * The privacy gate runs BEFORE the SQL fetch so timing-based existence
 * leaks aren't possible. Non-existence and "exists but you can't see"
 * map to distinct status codes (404 vs 403) by design — leaking
 * existence of a closed group is an explicit choice (it lets the FE
 * surface a "request to join" action), but secret groups MUST stay
 * indistinguishable from missing.
 *
 * Composition:
 *   1. {@see GroupsService::resolveGroupAccess} — ctx + isMember + 404
 *      decision. Cross-kind (nft / hall / system / user).
 *   2. Layer the members-specific 403 policy on top: non-members of
 *      anything except `open` get a forbidden return.
 *   3. {@see PeepSoGroupRepository::listGroupMembers} +
 *      {@see PeepSoGroupRepository::countGroupMembers} for the page.
 *   4. Hydrate user_ids via {@see UserViewService::getSummary} with
 *      the SAME prefetched-batch shape the /members directory uses
 *      — collapses N+1 to bounded SQL across the page.
 *   5. Each item's `card`: the member Card the /members directory shows,
 *      from the SAME builder (CardViewService::getMemberCardForList) and
 *      the same MemberCardPrefetcher bundle, for this viewer. `null` for a
 *      member whose card the directory would not show this viewer
 *      (ProfileAccess::filterVisible: a private profile, a suspended
 *      account, a block), or whose card could not be built.
 *
 * Per §1 the only DB seam is repositories. Per §8 the return value is
 * a plain array; the endpoint wraps it in ApiResponse::ok().
 *
 * @package BCC\Trust\Core\Services
 * @since V2 (group-detail page; PR 2; 2026-05-08)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\MemberCardPrefetcher;
use BCC\Trust\Core\Support\ProfileAccess;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupMembersService
{
    /**
     * Hard cap aligned with the §4.7.7 contract. The endpoint enforces
     * the same cap on its `limit` query arg — duplicating the bound
     * here means a service-level caller (cron, admin tool) can't accidentally
     * blow past 100 either.
     */
    public const MAX_LIMIT = 100;
    public const DEFAULT_LIMIT = 24;

    /**
     * Roster + pagination payload for `GET /bcc/v1/groups/{id}/members`.
     *
     * Three return shapes:
     *   - null                                          → 404 bcc_not_found
     *   - {error: 'bcc_permission_denied', message:..}  → 403 with hint
     *   - {items: [...], pagination: {...}}             → 200 OK payload
     *
     * The error-sentinel matches HallsService's join/leave convention
     * so the endpoint can mechanically map result.error → status code.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{offset: int, limit: int, total: int, has_more: bool}}|array{error: string, message: string}|null
     */
    public function listMembers(int $viewerId, int $groupId, int $offset, int $limit): ?array
    {
        if ($groupId <= 0) {
            return null;
        }

        // ── 1. Privacy gate (delegates to GroupsService — same gate
        //     §4.7.5 + §4.7.6 use; secret-non-member returns null here). ─
        $access = Plugin::instance()->groupsService()->resolveGroupAccess($viewerId, $groupId);
        if ($access === null) {
            return null;
        }

        $ctx      = $access['ctx'];
        $isMember = $access['isMember'];

        // ── 2. Members-specific post-resolution gate. ─────────────────
        // Open groups: roster is public (matches PeepSo's wall-privacy
        // semantics — open groups are fully browsable).
        // Closed/secret + member: full roster.
        // Closed + non-member: 403 — group exists but the roster is
        // private. We DO NOT widen this for NFT-gated groups (which
        // are typically `closed` privacy + an extra eligibility check)
        // because the holder roster of an NFT group is a sensitive
        // fingerprint surface; leaving it gated to members only is the
        // conservative default.
        if (!$isMember && $ctx->privacy !== PeepSoPrivacy::Open) {
            return [
                'error'   => 'bcc_permission_denied',
                'message' => 'Join the group to see its roster.',
            ];
        }

        // ── 3. Fetch the page. ───────────────────────────────────────
        // Bounds-check defensively; the endpoint already clamps but a
        // service-level caller (admin tool, future composer) shouldn't
        // need to memorise the contract caps.
        if ($offset < 0) {
            $offset = 0;
        }
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }
        if ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        $rows  = PeepSoGroupRepository::listGroupMembers($groupId, $offset, $limit);
        $total = PeepSoGroupRepository::countGroupMembers($groupId);

        // has_more follows the RAW page: the filter below can shorten a
        // page, and clients advance by offset + limit, never items.length.
        $hasMore = ($offset + count($rows)) < $total;

        // ── 3b. An open roster omits members this viewer may not see. ─
        [$rows, $visible] = self::visibleRows($ctx->privacy, $rows, $viewerId, $groupId);

        // ── 4. Bulk-prime the member-card prefetch. ──────────────────
        // The SAME bundle `UsersEndpoint::members` primes: a superset of
        // the MemberSummaryPrefetcher map getSummary reads, plus the
        // card-level reads, so the tile and the card share one batch.
        $userIds = [];
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            if ($uid > 0) {
                $userIds[] = $uid;
            }
        }

        $prefetched = $userIds === [] ? null : MemberCardPrefetcher::primeFor($userIds, $viewerId);

        // ── 4b. Whose card this viewer may see: the directory's rule
        //     (ProfileAccess::filterVisible, without the search opt-out,
        //     as for the rows), read once for the page. An open roster's
        //     rows were already judged by it; owner and manager views, and
        //     closed and secret rosters, show every tile, so their cards
        //     are judged here.
        if ($visible === null) {
            $visible = $userIds === [] ? [] : array_fill_keys(ProfileAccess::filterVisible($userIds, false), true);
        }
        $cards   = Plugin::instance()->cardViewService();
        $mentors = $userIds === [] ? [] : Plugin::instance()->mentorListingService()->listedAmong($userIds);

        // ── 5. Hydrate each row. ─────────────────────────────────────
        $userView      = Plugin::instance()->userViewService();
        $withJoinTimes = self::seesJoinTimes($viewerId);
        $items         = [];
        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            if ($userId <= 0) {
                continue;
            }

            $summary = $userView->getSummary($userId, $viewerId, $prefetched);
            if ($summary === null) {
                // User row exists in PeepSo but not in wp_users (deleted).
                // Skip rather than 500 — the count from countGroupMembers
                // will momentarily disagree with items.length but
                // self-heals on the next paginate.
                continue;
            }

            $item         = array_merge($summary, self::rosterFields($row, $withJoinTimes));
            $item['card'] = isset($visible[$userId])
                ? self::cardOrNull(
                    static fn (): ?array => $cards->getMemberCardForList($userId, $viewerId, $prefetched),
                    $userId,
                    $groupId
                )
                : null;
            // The directory's §21.4 mentor badge, added the same way: only
            // for actively listed mentors (UsersEndpoint::members).
            if ($item['card'] !== null && isset($mentors[$userId])) {
                $item['card']['is_mentor'] = true;
            }
            $items[] = $item;
        }

        return [
            'items'      => $items,
            'pagination' => [
                'offset'   => $offset,
                'limit'    => $limit,
                'total'    => $total,
                'has_more' => $hasMore,
            ],
        ];
    }

    /**
     * An OPEN group's roster is readable by anyone, signed out included, so
     * it must not list a member the viewer may not see (user decision): a
     * private profile, a suspended account, a block in either direction —
     * the ONE list rule, {@see ProfileAccess::filterVisible()} (one query
     * for the page; the viewer always sees themselves, admins see
     * everyone). Display only: `total` and the member count are unchanged.
     *
     * NOT hide_from_search: that setting scopes to member search and the
     * directory (user decision 2026-11-12); rosters follow profile privacy.
     *
     * This group's OWNER and MANAGERS (and site admins) see the whole
     * roster — they already decide its membership, and they need every
     * member for managing it and for the transfer-ownership picker.
     *
     * Closed and secret rosters are shown only to the group's own members
     * and are not filtered here.
     *
     * ⚠ filterVisible() judges the CURRENT user, so this is correct only
     * for the REST caller, whose viewer is the current user.
     *
     * Returns the rows this viewer may see, and the visible set it read
     * when it filtered: member id => true, or null when the rows were not
     * filtered (closed and secret rosters, this group's own admins, an
     * empty page). listMembers() reuses the set to gate the cards, so an
     * open roster judges its members once.
     *
     * @template TRow of object{user_id: numeric-string}
     * @param list<TRow> $rows
     * @return array{0: list<TRow>, 1: array<int, true>|null}
     */
    private static function visibleRows(PeepSoPrivacy $privacy, array $rows, int $viewerId, int $groupId): array
    {
        if ($privacy !== PeepSoPrivacy::Open || $rows === []) {
            return [$rows, null];
        }
        if (GroupJoinRequestService::isGroupAdmin($viewerId, $groupId)) {
            return [$rows, null];
        }

        $ids = [];
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            if ($uid > 0) {
                $ids[] = $uid;
            }
        }

        $visible = array_fill_keys(ProfileAccess::filterVisible($ids, false), true);

        return [
            array_values(array_filter(
                $rows,
                static fn (object $row): bool => isset($visible[(int) $row->user_id])
            )),
            $visible,
        ];
    }

    /**
     * One member's card, or null when building it failed: that item keeps
     * its tile fields and shows no card, and the rest of the roster is
     * unaffected (fail closed per item). A read that could not be trusted
     * throws a \RuntimeException (RepositoryReadFailure among them), which
     * is logged here. A PHP \Error is a bug and is not caught.
     *
     * @param \Closure(): (array<string, mixed>|null) $build
     * @return array<string, mixed>|null
     */
    private static function cardOrNull(\Closure $build, int $userId, int $groupId): ?array
    {
        try {
            return $build();
        } catch (\RuntimeException $e) {
            \BCC\Core\Log\Logger::error('[GroupMembersService] member card could not be built; the roster item shows no card', [
                'user_id'  => $userId,
                'group_id' => $groupId,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Whether this viewer receives when each member joined the group: site
     * admins only (`manage_options`). Other users, this group's own owner
     * and managers included, never receive a join time (R4). The roster's
     * order (role, then join time) stays server-side.
     */
    private static function seesJoinTimes(int $viewerId): bool
    {
        return $viewerId > 0 && user_can($viewerId, 'manage_options');
    }

    /**
     * The roster's own fields for one row: `role`, `role_label`, and
     * `joined_at` only when {@see seesJoinTimes()}. The key is absent
     * otherwise, not null, so nothing in the payload stands for it.
     *
     * @return array{role: string, role_label: string, joined_at?: string|null}
     */
    private static function rosterFields(object $row, bool $withJoinTimes): array
    {
        $role   = \BCC\Trust\Core\Support\GroupRole::normalize((string) ($row->role ?? ''));
        $fields = [
            'role'       => $role,
            'role_label' => \BCC\Trust\Core\Support\GroupRole::label($role),
        ];
        if ($withJoinTimes) {
            $fields['joined_at'] = self::joinedAtIso($row);
        }

        return $fields;
    }

    /**
     * Clock-split fix: prefer the DB-computed epoch projection
     * (`UNIX_TIMESTAMP(gm_joined) AS gm_joined_utc` — bcc-core ships
     * it; deploy core FIRST) over PHP-side strtotime of the naive
     * datetime, which skews when MySQL's session time_zone (SYSTEM,
     * UTC−5 locally) disagrees with the BCC norm of UTC. The fallback
     * keeps an old bcc-core (no gm_joined_utc alias) rendering
     * exactly as before.
     */
    private static function joinedAtIso(object $row): ?string
    {
        if (isset($row->gm_joined_utc) && is_numeric($row->gm_joined_utc)) {
            $ts = (int) $row->gm_joined_utc;
            return $ts > 0 ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
        }
        return self::toIso8601((string) ($row->joined_at ?? ''));
    }

    private static function toIso8601(string $mysqlDatetime): ?string
    {
        if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return null;
        }
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
