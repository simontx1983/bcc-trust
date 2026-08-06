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
 *   open    + anyone     → full roster
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
use BCC\Trust\Core\Support\MemberSummaryPrefetcher;
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

        // ── 4. Bulk-prime UserSummary prefetch maps. ─────────────────
        // Mirrors `UsersEndpoint::members` exactly so the wire shape
        // for `getSummary` stays identical across the directory and
        // the per-group roster.
        $userIds = [];
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            if ($uid > 0) {
                $userIds[] = $uid;
            }
        }

        $prefetched = $userIds === [] ? null : MemberSummaryPrefetcher::primeFor($userIds);

        // ── 5. Hydrate each row. ─────────────────────────────────────
        $userView = Plugin::instance()->userViewService();
        $items    = [];
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

            $role      = self::normalizeRole((string) $row->role);
            $items[]   = array_merge($summary, [
                'role'       => $role,
                'role_label' => self::roleLabel($role),
                'joined_at'  => self::joinedAtIso($row),
            ]);
        }

        $hasMore = ($offset + count($rows)) < $total;

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
     * Strip PeepSo's `member_` prefix to the canonical wire shape.
     * `member_owner` → `owner`, `member_moderator`/`member_manager`
     * → `moderator`, anything else → `member`.
     *
     * `member_manager` is folded into `moderator` because the FE
     * doesn't need to distinguish the two PeepSo flavors; both are
     * "above-baseline-but-not-owner" roles. If a future surface needs
     * the manager/moderator split it can re-derive from the raw
     * status, but the public contract stays narrow.
     */
    private static function normalizeRole(string $rawStatus): string
    {
        return match ($rawStatus) {
            'member_owner'                       => 'owner',
            'member_moderator', 'member_manager' => 'moderator',
            default                              => 'member',
        };
    }

    /**
     * Server-rendered label per §A2 / §L5. Frontend prints verbatim —
     * if a deployment localizes via `bcc_group_role_label`, that copy
     * lands here too. Single source of truth for "what does a role
     * READ as on the page."
     */
    private static function roleLabel(string $role): string
    {
        $defaults = [
            'owner'     => 'OWNER',
            'moderator' => 'MODERATOR',
            'member'    => 'MEMBER',
        ];

        /** @var array<string, string> $map */
        $map = apply_filters('bcc_group_role_labels', $defaults);

        return $map[$role] ?? $defaults[$role] ?? 'MEMBER';
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
