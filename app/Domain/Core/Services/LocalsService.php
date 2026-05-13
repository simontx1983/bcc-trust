<?php
/**
 * Locals Service — composes the GET /bcc/v1/locals view-model per
 * §E3 of the V1 plan and §4.7 of the API contract.
 *
 * Single-graph rule (LOCKED): PeepSo's peepso_group_members IS the
 * membership ledger. BCC stores ONLY ONE piece of per-user state
 * about Locals:
 *
 *     wp_usermeta.bcc_primary_local_group_id (int)
 *
 * Membership existence + joined_at come from peepso_group_members
 * (read via PeepSoGroupRepository::findUserMemberships). is_primary is
 * derived by comparing the iterated group_id against the user's
 * bcc_primary_local_group_id pointer. There is NO bcc_user_locals
 * table; the prior parallel ledger violated the single-graph rule
 * and was removed.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\PeepSo\PeepSoGroupWriter;
use BCC\Core\Repositories\PeepSoGroupRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class LocalsService
{
    private const MAX_PAGE_SIZE = 50;

    /**
     * wp_usermeta key for the viewer's primary-Local pointer (§E3 single-
     * graph rule — the ONLY per-user Locals state BCC stores; membership
     * lives in PeepSo's peepso_group_members ledger).
     */
    private const META_PRIMARY_GROUP = 'bcc_primary_local_group_id';

    /** @var list<string> */
    private const CHAIN_KEYWORDS = [
        'cosmos', 'osmosis', 'injective', 'ethereum', 'solana',
        'polkadot', 'thorchain', 'near',
    ];

    /**
     * Render the /locals response payload.
     *
     * @return array{
     *   items: list<array{
     *     id: int,
     *     slug: string,
     *     name: string,
     *     number: int|null,
     *     chain: string|null,
     *     member_count: int,
     *     viewer_membership: array{is_member: bool, is_primary: bool, joined_at: string|null}|null,
     *     links: array{self: string}
     *   }>,
     *   pagination: array{page: int, page_size: int, total: int, total_pages: int}
     * }
     */
    public function getLocals(int $viewerId, int $page, int $pageSize, ?string $chain): array
    {
        $page     = max(1, $page);
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $offset   = ($page - 1) * $pageSize;

        $rows  = PeepSoGroupRepository::listLocals($chain, $offset, $pageSize);
        $total = PeepSoGroupRepository::countLocals($chain);

        $groupIds       = array_map(static fn($r) => (int) $r->id, $rows);
        $myMemberships  = $this->loadViewerMemberships($viewerId, $groupIds);
        $primaryGroupId = $this->loadPrimaryGroupId($viewerId);

        $items = [];
        foreach ($rows as $row) {
            $groupId = (int) $row->id;
            $items[] = [
                'id'                => $groupId,
                'slug'              => $row->post_name,
                'name'              => $row->post_title,
                'number'            => self::parseNumber($row->post_title),
                'chain'             => self::parseChain($row->post_title),
                'member_count'      => (int) $row->member_count,
                'viewer_membership' => $this->renderViewerMembership(
                    $viewerId,
                    $groupId,
                    $myMemberships[$groupId] ?? null,
                    $primaryGroupId
                ),
                'links'             => ['self' => '/locals/' . $row->post_name],
            ];
        }

        $totalPages = $total === 0 ? 0 : (int) ceil($total / $pageSize);

        return [
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'page_size'   => $pageSize,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    /**
     * Render the /locals/:slug single-item payload.
     *
     * Same item shape as the directory's `items[]` entries — the
     * detail page can reuse the identical client-side renderer. A
     * miss returns null so the endpoint can map to the canonical
     * 404 envelope.
     *
     * @return array{
     *   id: int,
     *   slug: string,
     *   name: string,
     *   number: int|null,
     *   chain: string|null,
     *   member_count: int,
     *   viewer_membership: array{is_member: bool, is_primary: bool, joined_at: string|null}|null,
     *   links: array{self: string}
     * }|null
     */
    public function getLocal(int $viewerId, string $slug): ?array
    {
        $row = PeepSoGroupRepository::findOneBySlug($slug);
        if ($row === null) {
            return null;
        }

        $groupId        = (int) $row->id;
        $myMemberships  = $this->loadViewerMemberships($viewerId, [$groupId]);
        $primaryGroupId = $this->loadPrimaryGroupId($viewerId);

        return [
            'id'                => $groupId,
            'slug'              => $row->post_name,
            'name'              => $row->post_title,
            'number'            => self::parseNumber($row->post_title),
            'chain'             => self::parseChain($row->post_title),
            'member_count'      => (int) $row->member_count,
            'viewer_membership' => $this->renderViewerMembership(
                $viewerId,
                $groupId,
                $myMemberships[$groupId] ?? null,
                $primaryGroupId
            ),
            'links'             => ['self' => '/locals/' . $row->post_name],
        ];
    }

    /**
     * Mark a group as the viewer's primary Local. Gated on actual
     * membership — non-members get `bcc_forbidden` so the §N7 client
     * UI can render a clear "Join first" disabled state without the
     * server silently no-op'ing the write.
     *
     * On success returns the same `viewer_membership` block the
     * client already speaks (member + is_primary=true), so a single
     * cache patch reflects the new state without a refetch.
     *
     * @return array{
     *   ok: true,
     *   group_id: int,
     *   viewer_membership: array{is_member: bool, is_primary: bool, joined_at: string|null}
     * }|array{error: string, message: string}
     */
    public function setPrimaryLocal(int $viewerId, int $groupId): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }
        if ($groupId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid group id.'];
        }

        $myMemberships = $this->loadViewerMemberships($viewerId, [$groupId]);
        $row = $myMemberships[$groupId] ?? null;
        if ($row === null) {
            return ['error' => 'bcc_forbidden', 'message' => 'Join the Local before setting it as primary.'];
        }

        update_user_meta($viewerId, self::META_PRIMARY_GROUP, $groupId);

        return [
            'ok'                => true,
            'group_id'          => $groupId,
            'viewer_membership' => [
                'is_member'  => true,
                'is_primary' => true,
                'joined_at'  => self::toIso8601($row->joined_at),
            ],
        ];
    }

    /**
     * Clear the viewer's primary-Local pointer. Idempotent — clearing
     * an already-empty pointer is a successful no-op.
     *
     * @return array{ok: true, group_id: null}|array{error: string, message: string}
     */
    public function clearPrimaryLocal(int $viewerId): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        delete_user_meta($viewerId, self::META_PRIMARY_GROUP);

        return [
            'ok'       => true,
            'group_id' => null,
        ];
    }

    /**
     * Join the viewer to $groupId via PeepSo's canonical write path.
     *
     * Pre-checks (in order):
     *   - viewer authed                   → bcc_unauthorized
     *   - groupId > 0                     → bcc_invalid_request
     *   - group exists AND is a Local     → bcc_not_found
     *   - already a member                → idempotent success
     *
     * Delegates the actual write to PeepSoGroupWriter::join (single-graph
     * rule §C2 — PeepSo owns peepso_group_members). On success returns
     * the same `viewer_membership` shape the directory + detail endpoints
     * speak, so the client can patch its cache without a refetch.
     *
     * @return array{
     *   ok: true,
     *   group_id: int,
     *   viewer_membership: array{is_member: bool, is_primary: bool, joined_at: string|null}
     * }|array{error: string, message: string}
     */
    public function joinLocal(int $viewerId, int $groupId): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }
        if ($groupId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid group id.'];
        }
        if (PeepSoGroupRepository::findOneById($groupId) === null) {
            return ['error' => 'bcc_not_found', 'message' => 'Local not found.'];
        }

        $existing = $this->loadViewerMemberships($viewerId, [$groupId]);
        if (isset($existing[$groupId])) {
            // Already a member — no-op success with the existing
            // viewer_membership block.
            $primaryGroupId = $this->loadPrimaryGroupId($viewerId);
            return [
                'ok'                => true,
                'group_id'          => $groupId,
                'viewer_membership' => [
                    'is_member'  => true,
                    'is_primary' => $primaryGroupId === $groupId,
                    'joined_at'  => self::toIso8601($existing[$groupId]->joined_at),
                ],
            ];
        }

        $ok = PeepSoGroupWriter::join($viewerId, $groupId);
        if (!$ok) {
            return ['error' => 'bcc_unavailable', 'message' => 'Group membership service is unavailable.'];
        }

        // Re-read so the response carries the canonical joined_at PeepSo
        // wrote (we don't mirror the timestamp ourselves — single source).
        $after = $this->loadViewerMemberships($viewerId, [$groupId]);
        $row   = $after[$groupId] ?? null;
        if ($row === null) {
            // PeepSo accepted the call but no active row appeared — likely
            // a closed group routing to pending_admin (V1 doesn't model
            // pending). Surface as forbidden so the UI can hint at it.
            return ['error' => 'bcc_forbidden', 'message' => 'This Local does not accept open membership.'];
        }

        return [
            'ok'                => true,
            'group_id'          => $groupId,
            'viewer_membership' => [
                'is_member'  => true,
                'is_primary' => false,
                'joined_at'  => self::toIso8601($row->joined_at),
            ],
        ];
    }

    /**
     * Remove the viewer from $groupId via PeepSo's canonical write path.
     *
     * Atomic primary cleanup: if the user is leaving their primary Local,
     * we clear `bcc_primary_local_group_id` BEFORE removing the membership
     * so the pointer never dangles. Idempotent — leaving when not a
     * member returns success with the non-member shape.
     *
     * @return array{
     *   ok: true,
     *   group_id: int,
     *   primary_cleared: bool,
     *   viewer_membership: array{is_member: false, is_primary: false, joined_at: null}
     * }|array{error: string, message: string}
     */
    public function leaveLocal(int $viewerId, int $groupId): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }
        if ($groupId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid group id.'];
        }
        if (PeepSoGroupRepository::findOneById($groupId) === null) {
            return ['error' => 'bcc_not_found', 'message' => 'Local not found.'];
        }

        $wasPrimary = $this->loadPrimaryGroupId($viewerId) === $groupId;

        $ok = PeepSoGroupWriter::leave($viewerId, $groupId);
        if (!$ok) {
            return ['error' => 'bcc_unavailable', 'message' => 'Group membership service is unavailable.'];
        }

        // Clear the pointer ONLY after PeepSo accepts the leave, so a
        // failed write doesn't strand the user with a cleared primary
        // and a still-active membership. A non-member with a stale
        // pointer is harmless — `renderViewerMembership` requires an
        // active row before setting is_primary=true.
        $primaryCleared = false;
        if ($wasPrimary) {
            delete_user_meta($viewerId, self::META_PRIMARY_GROUP);
            $primaryCleared = true;
        }

        return [
            'ok'                => true,
            'group_id'          => $groupId,
            'primary_cleared'   => $primaryCleared,
            'viewer_membership' => [
                'is_member'  => false,
                'is_primary' => false,
                'joined_at'  => null,
            ],
        ];
    }

    /**
     * Bulk-load the viewer's PeepSo group memberships, indexed by
     * group_id. One query for the whole page; no N+1.
     *
     * @param list<int> $groupIds
     * @return array<int, object{group_id: numeric-string, joined_at: string}>
     */
    private function loadViewerMemberships(int $viewerId, array $groupIds): array
    {
        if ($viewerId <= 0 || $groupIds === []) {
            return [];
        }
        return PeepSoGroupRepository::findUserMemberships($viewerId, $groupIds);
    }

    /**
     * Read the viewer's primary-Local pointer from user-meta. Returns
     * null when unset, zero, or non-numeric.
     */
    private function loadPrimaryGroupId(int $viewerId): ?int
    {
        if ($viewerId <= 0) {
            return null;
        }
        $value = get_user_meta($viewerId, self::META_PRIMARY_GROUP, true);
        if (!is_numeric($value)) {
            return null;
        }
        $intVal = (int) $value;
        return $intVal > 0 ? $intVal : null;
    }

    /**
     * Three states (per contract §4.7):
     *   - viewer anonymous           → null
     *   - viewer authed, not member  → {is_member: false, ...}
     *   - viewer authed, member      → {is_member: true, ...}
     *
     * @param object{group_id: numeric-string, joined_at: string}|null $row
     * @return array{is_member: bool, is_primary: bool, joined_at: string|null}|null
     */
    private function renderViewerMembership(
        int $viewerId,
        int $groupId,
        ?object $row,
        ?int $primaryGroupId
    ): ?array {
        if ($viewerId <= 0) {
            return null;
        }

        if ($row === null) {
            return [
                'is_member'  => false,
                'is_primary' => false,
                'joined_at'  => null,
            ];
        }

        return [
            'is_member'  => true,
            'is_primary' => $primaryGroupId === $groupId,
            'joined_at'  => self::toIso8601($row->joined_at),
        ];
    }

    private static function parseNumber(string $title): ?int
    {
        if (preg_match('/^Local\s+(\d+)\b/u', $title, $matches) === 1) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * Extract a chain slug from a Local's post title using
     * {@see CHAIN_KEYWORDS}. Public so the cold-start surface
     * (FeedColdStartService) can reuse the SAME chain detection that
     * /locals uses — keeping a single source of truth for "which Locals
     * count as Cosmos / Solana / etc." Returns null when the title
     * doesn't match any known chain keyword.
     */
    public static function parseChain(string $title): ?string
    {
        $lower = strtolower($title);
        foreach (self::CHAIN_KEYWORDS as $chain) {
            if (str_contains($lower, $chain)) {
                return $chain;
            }
        }
        return null;
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
