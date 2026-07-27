<?php
/**
 * Halls Service — composes the GET /bcc/v1/halls view-model per §4.7 of
 * the API contract.
 *
 * A Hall is an auto-provisioned, one-per-chain, open-membership union
 * hall (system-created by HallProvisioningService). It replaced the
 * retired member-less numbered-chapter "Local" model — no title parsing,
 * no chapter numbers; the discriminator is the `_bcc_group_kind='hall'`
 * post-meta and chain association is the `_bcc_chain_tag` meta (resolved
 * to a slug via ChainRepository).
 *
 * Single-graph rule (LOCKED): PeepSo's peepso_group_members IS the
 * membership ledger. BCC stores ONLY ONE piece of per-user state
 * about Halls:
 *
 *     wp_usermeta.bcc_primary_hall_group_id (int)
 *
 * Membership existence + joined_at come from peepso_group_members
 * (read via PeepSoGroupRepository::findUserMemberships). is_primary is
 * derived by comparing the iterated group_id against the user's
 * bcc_primary_hall_group_id pointer. There is NO bcc_user_halls table.
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\PeepSo\PeepSoGroupWriter;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use BCC\Trust\Onchain\Repositories\ChainRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class HallsService
{
    private const MAX_PAGE_SIZE = 50;

    /**
     * wp_usermeta key for the viewer's primary-Hall pointer (single-graph
     * rule — the ONLY per-user Halls state BCC stores; membership lives in
     * PeepSo's peepso_group_members ledger). Paired-coupling with
     * bcc-core PeepSoGroupRepository::PRIMARY_HALL_META_KEY — change in
     * lockstep.
     */
    private const META_PRIMARY_GROUP = 'bcc_primary_hall_group_id';

    private GroupContextResolver $groupContext;

    public function __construct(GroupContextResolver $groupContext)
    {
        $this->groupContext = $groupContext;
    }

    /**
     * Render the /halls response payload.
     *
     * @return array{
     *   items: list<array{
     *     id: int,
     *     slug: string,
     *     name: string,
     *     chain: string|null,
     *     member_count: int,
     *     viewer_membership: array{is_member: bool, is_primary: bool, joined_at: string|null}|null,
     *     links: array{self: string}
     *   }>,
     *   pagination: array{page: int, page_size: int, total: int, total_pages: int}
     * }
     */
    public function getHalls(int $viewerId, int $page, int $pageSize, ?string $chain): array
    {
        $page     = max(1, $page);
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $offset   = ($page - 1) * $pageSize;

        // Resolve the optional chain-slug filter to a numeric chain id.
        // A requested-but-unknown chain yields an empty page rather than
        // silently ignoring the filter and listing every Hall.
        $chainId = null;
        if ($chain !== null) {
            $chainId = ChainRepository::resolveIdAnyState($chain);
            if ($chainId === null) {
                return $this->emptyPage($page, $pageSize);
            }
        }

        $rows  = PeepSoGroupRepository::listHalls($chainId, $offset, $pageSize);
        $total = PeepSoGroupRepository::countHalls($chainId);

        $groupIds       = array_map(static fn($r) => (int) $r->id, $rows);
        $myMemberships  = $this->loadViewerMemberships($viewerId, $groupIds);
        $primaryGroupId = $this->loadPrimaryGroupId($viewerId);
        $chainSlugs     = $groupIds === [] ? [] : ChainRepository::resolveSlugsForGroups($groupIds);

        $items = [];
        foreach ($rows as $row) {
            $groupId = (int) $row->id;
            $items[] = [
                'id'                => $groupId,
                'slug'              => $row->post_name,
                'name'              => $row->post_title,
                'chain'             => $chainSlugs[$groupId] ?? null,
                'member_count'      => (int) $row->member_count,
                'viewer_membership' => $this->renderViewerMembership(
                    $viewerId,
                    $groupId,
                    $myMemberships[$groupId] ?? null,
                    $primaryGroupId
                ),
                'links'             => ['self' => '/halls/' . $row->post_name],
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
     * @return array{
     *   items: list<array{
     *     id: int,
     *     slug: string,
     *     name: string,
     *     chain: string|null,
     *     member_count: int,
     *     viewer_membership: array{is_member: bool, is_primary: bool, joined_at: string|null}|null,
     *     links: array{self: string}
     *   }>,
     *   pagination: array{page: int, page_size: int, total: int, total_pages: int}
     * }
     */
    private function emptyPage(int $page, int $pageSize): array
    {
        return [
            'items'      => [],
            'pagination' => [
                'page'        => $page,
                'page_size'   => $pageSize,
                'total'       => 0,
                'total_pages' => 0,
            ],
        ];
    }

    /**
     * Render the /halls/:slug single-item payload.
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
     *   chain: string|null,
     *   member_count: int,
     *   viewer_membership: array{is_member: bool, is_primary: bool, joined_at: string|null}|null,
     *   links: array{self: string}
     * }|null
     */
    public function getHall(int $viewerId, string $slug): ?array
    {
        $row = PeepSoGroupRepository::findHallBySlug($slug);
        if ($row === null) {
            return null;
        }

        $groupId        = (int) $row->id;
        $myMemberships  = $this->loadViewerMemberships($viewerId, [$groupId]);
        $primaryGroupId = $this->loadPrimaryGroupId($viewerId);
        $chainSlugs     = ChainRepository::resolveSlugsForGroups([$groupId]);

        return [
            'id'                => $groupId,
            'slug'              => $row->post_name,
            'name'              => $row->post_title,
            'chain'             => $chainSlugs[$groupId] ?? null,
            'member_count'      => (int) $row->member_count,
            'viewer_membership' => $this->renderViewerMembership(
                $viewerId,
                $groupId,
                $myMemberships[$groupId] ?? null,
                $primaryGroupId
            ),
            'links'             => ['self' => '/halls/' . $row->post_name],
        ];
    }

    /**
     * Mark a group as the viewer's primary Hall. Gated on actual
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
    public function setPrimaryHall(int $viewerId, int $groupId): array
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
            return ['error' => 'bcc_forbidden', 'message' => 'Join the Hall before setting it as your home Hall.'];
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
     * Clear the viewer's primary-Hall pointer. Idempotent — clearing
     * an already-empty pointer is a successful no-op.
     *
     * @return array{ok: true, group_id: null}|array{error: string, message: string}
     */
    public function clearPrimaryHall(int $viewerId): array
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
     *   - group exists AND is a Hall      → bcc_not_found
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
    public function joinHall(int $viewerId, int $groupId): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }
        if ($groupId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid group id.'];
        }

        // Gate BEFORE the PeepSoGroupWriter::join door. The writer lands the
        // user as `member` unconditionally — it bypasses PeepSo's UI approval
        // (see PeepSoGroupWriter docblock), so without a server-side gate any
        // peepso-group id, including a closed/secret or NFT-gated holder group,
        // could be joined through this door. Halls are open by definition;
        // NFT and plain groups have their own gated endpoints
        // (/me/holder-groups, /me/groups) and MUST NOT be joinable here.
        $context = $this->groupContext->forGroup($groupId);
        if ($context === null || $context->type !== GroupType::Hall) {
            return ['error' => 'bcc_not_found', 'message' => 'Hall not found.'];
        }
        if ($context->privacy !== PeepSoPrivacy::Open) {
            return ['error' => 'bcc_forbidden', 'message' => 'This Hall does not accept open membership.'];
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
            // The group was gated to an open Hall above, so member_join
            // always yields an active `member` row. A null here means the
            // PeepSo write silently failed — surface as unavailable.
            return ['error' => 'bcc_unavailable', 'message' => 'Group membership service is unavailable.'];
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
     * Atomic primary cleanup: if the user is leaving their primary Hall,
     * we clear `bcc_primary_hall_group_id` BEFORE removing the membership
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
    public function leaveHall(int $viewerId, int $groupId): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }
        if ($groupId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid group id.'];
        }
        if (PeepSoGroupRepository::findHallById($groupId) === null) {
            return ['error' => 'bcc_not_found', 'message' => 'Hall not found.'];
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
     * Read the viewer's primary-Hall pointer from user-meta. Returns
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

    private static function toIso8601(string $mysqlDatetime): ?string
    {
        if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return null;
        }
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
