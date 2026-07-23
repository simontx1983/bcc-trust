<?php
/**
 * Groups Service — composes the cross-kind /bcc/v1/groups/{slug}
 * single-group view-model per §A2 + the group-detail page plan.
 *
 * Sibling to {@see LocalsService} but cross-kind (nft / local / system /
 * user). The Locals service stays Local-only because it owns the
 * primary-Local pointer state; this service is the read-only seam for
 * "any group, any kind."
 *
 * Composition (verbatim from the LocalsService recipe, generalized
 * across kinds):
 *
 *   1. PeepSoGroupRepository::findGroupBySlug → display row
 *   2. GroupContextResolver::forGroup → type / privacy / verification
 *   3. PRIVACY GATE (defense-in-depth):
 *       - secret + viewer not member → null (endpoint maps to 404,
 *         never leaks existence)
 *       - closed + viewer not member → view-model with feed_visible
 *         + members_visible = false (description still surfaces;
 *         encourages buying in / requesting access)
 *       - open OR (any privacy + viewer is member) → full view-model
 *   4. GroupActivityHeatService::forGroups → activity heat tile
 *   5. PeepSoGroupRepository::findUserMemberships →
 *      viewer_membership block (anon → null)
 *   6. NFT enrichment (when type === 'nft'):
 *      - GatedGroupRepository::findManyByGroupIds + CollectionRepository
 *        ::findManyByIds → image_url + collection_stats
 *      - Non-holder viewer → permissions.can_join.unlock_hint with
 *        the standard "Hold an NFT…" copy (server-rendered, FE
 *        renders verbatim per §A4 / §N7)
 *
 * Per §1 the only DB seam this service uses is repositories. Per §8
 * the return value is a plain array; the endpoint wraps it in
 * ApiResponse::ok().
 *
 * @package BCC\Trust\Core\Services
 * @since V2 (group-detail page; 2026-05-08)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\GroupPostPolicyRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Support\PublicAllPolicy;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupsService
{
    /**
     * Truncation length for the group description on the detail surface.
     * Same target as the discovery card (§4.7.4) — keeps the wire shape
     * consistent across surfaces.
     */
    private const DESCRIPTION_LIMIT = 200;

    public function __construct(
        private readonly ReputationRepository $reputationRepo,
        private readonly GroupContextResolver $groupContextResolver
    ) {
    }

    /**
     * Per-request memo for `getMembershipStatus` keyed by "viewer:group".
     * A single group-detail render asks the same (viewer, group) up to five
     * times (active-member, owner, can-use, can-manage, author gates); this
     * collapses those to one indexed lookup. GroupsService never mutates
     * membership, so within a request the value can't go stale.
     *
     * @var array<string, string|null>
     */
    private array $membershipStatusCache = [];

    /**
     * Server-pinned copy for the NFT-gate unlock hint surfaced on
     * permissions.can_join.unlock_hint when a non-holder views an
     * NFT-gated group. Mirrors UserGroupsEndpoint's standard copy so
     * the frontend can de-dupe a single string. Filterable so deployments
     * can localize without a code release.
     */
    private const NFT_UNLOCK_HINT_DEFAULT = 'Hold an NFT from this collection to join.';

    /**
     * Compose the /bcc/v1/groups/{slug} detail view-model. Cross-kind.
     *
     * Returns null when:
     *   - the slug doesn't resolve to a published peepso-group, OR
     *   - the group is `secret` AND the viewer isn't an active member
     *     (defense-in-depth — never leak existence).
     *
     * The endpoint maps a null return to a 404 envelope.
     *
     * @return array{
     *   id: int,
     *   slug: string,
     *   name: string,
     *   type: string,
     *   privacy: string,
     *   description: string|null,
     *   image_url: string|null,
     *   member_count: int,
     *   verification: array{kind: string, label: string}|null,
     *   activity: array{posts_last_7d: int, last_activity_at: string|null, heat: string, heat_label: string},
     *   collection_stats: array<string, mixed>|null,
     *   viewer_membership: array{is_member: bool, joined_at: string|null}|null,
     *   permissions: array{
     *     can_join: array{allowed: bool, unlock_hint: string|null, reason_code: string|null},
     *     can_leave: array{allowed: bool, unlock_hint: string|null, reason_code: string|null},
     *     can_read_feed: array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     *   },
     *   feed_visible: bool,
     *   members_visible: bool,
     *   can_use_public_all: bool,
     *   can_manage_public_all_policy: bool,
     *   public_all_members_enabled: bool,
     *   chain_tag: string|null,
     *   trust_min: int|null,
     *   links: array{self: string},
     *   card: array<string, mixed>
     * }|null
     */
    public function getGroup(int $viewerId, string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        $row = PeepSoGroupRepository::findGroupBySlug($slug);
        if ($row === null) {
            return null;
        }

        $groupId = (int) $row->id;
        $ctx     = $this->groupContextResolver->forGroup($groupId);
        if ($ctx === null) {
            // Resolver returned null: post exists but isn't a peepso-group
            // (post-type mismatch). Safe to 404 — the row shape is wrong.
            return null;
        }

        $isMember = $this->viewerIsActiveMember($viewerId, $groupId);
        // Owners get a dedicated `can_leave` branch because
        // PeepSoGroupWriter::leave returns false (group would be
        // orphaned). Surfacing the gate on the permissions block lets
        // the FE render a disabled button with a server-pinned hint
        // instead of a live button that always 403s.
        $isOwner  = $isMember
            ? $this->viewerIsGroupOwner($viewerId, $groupId)
            : false;

        // ── §S defense-in-depth gate #1: secret group, non-member → 404 ──
        if ($ctx->privacy === PeepSoPrivacy::Secret && !$isMember) {
            return null;
        }

        $heat = Plugin::instance()->groupActivityHeatService()->forGroups([$groupId]);
        $activity = $heat[$groupId] ?? [
            'posts_last_7d'    => 0,
            'last_activity_at' => null,
            'heat'             => 'cold',
            'heat_label'       => 'Quiet',
        ];

        $viewerMembership = $this->renderViewerMembership($viewerId, $groupId);

        // NFT enrichment is collection-driven; only NFT-type groups carry
        // image_url + collection_stats. Plain (Local / system / user)
        // groups return null for both — the frontend falls back to the
        // initials cover used on /communities.
        $imageUrl        = null;
        $collectionStats = null;
        $isNftHolder     = false;
        if ($ctx->type === GroupType::Nft) {
            [$imageUrl, $collectionStats, $isNftHolder] =
                $this->resolveNftEnrichment($ctx, $viewerId, $isMember);
        }

        // BCC trust-gate read. Meta value is the canonical threshold
        // (25/50/75) the create flow locked at the moment of creation.
        // Plain-group only — NFT/Local groups use other gate paths.
        $trustGateMin = 0;
        if ($ctx->type !== GroupType::Nft && $ctx->type !== GroupType::Local) {
            $trustGateMin = (int) get_post_meta($groupId, '_bcc_trust_gate_min', true);
        }

        $permissions = $this->buildPermissions(
            $ctx,
            $viewerId,
            $isMember,
            $isNftHolder,
            $isOwner,
            $trustGateMin
        );

        $feedVisible    = $permissions['can_read_feed']['allowed'];
        $membersVisible = $this->resolveMembersVisible($ctx, $isMember);

        // Public-all fields for the group composer + owner control:
        //  - `can_use_public_all` (per-viewer) gates the composer's "PUBLIC"
        //    option. Only members author here, so the query is skipped for
        //    non-members (false).
        //  - `can_manage_public_all_policy` (per-viewer) reveals the owner
        //    control (owner/manager/site-admin — moderator excluded), via the
        //    canonical GroupsService::canManagePublicAllPolicy (no role logic
        //    re-derived here). Secret-non-members never reach this code (§S
        //    gate above 404s them).
        //  - `public_all_members_enabled` is the raw group opt-in. MINIMUM
        //    EXPOSURE: only actionable by managers (to render the toggle
        //    state), so it reflects the real value only when the viewer can
        //    manage it; every other viewer sees `false` (no config disclosure
        //    to ordinary/anon viewers). `can_use_public_all` already tells an
        //    ordinary member whether they may syndicate.
        $canUsePublicAll         = $isMember && $this->canUsePublicAll($viewerId, $groupId);
        $canManagePublicAll      = $this->canManagePublicAllPolicy($viewerId, $groupId);
        $publicAllMembersEnabled = $canManagePublicAll
            ? GroupPostPolicyRepository::publicAllMembersEnabled($groupId)
            : false;

        // Chain-tag resolution: reads from either `_bcc_gate_chain_id`
        // (NFT) or `_bcc_chain_tag` (plain). Locals + legacy untagged
        // groups return null — the FE renders no chip in that case.
        $chainMap = \BCC\Core\ServiceLocator::resolveChainRead()->resolveSlugsForGroups([$groupId]);
        $chainTag = $chainMap[$groupId] ?? null;

        $description  = self::truncateDescription((string) $row->post_content);
        $trustMinWire = $trustGateMin > 0 ? $trustGateMin : null;

        // §4.4 card convergence (additive): the full community Card,
        // composed from the data already resolved above — zero extra
        // queries. New consumers render `group.card` via CardFactory;
        // the flat fields below remain for the migration window.
        $card = Plugin::instance()->cardViewService()->getCommunityCardFromGroupData(
            [
                'group_id'         => $groupId,
                'slug'             => (string) $row->post_name,
                'name'             => (string) $row->post_title,
                'type'             => $ctx->type->value,
                'privacy'          => $ctx->privacy->value,
                'member_count'     => (int) $row->member_count,
                'description'      => $description,
                'image_url'        => $imageUrl,
                'verification'     => $ctx->verification?->toApiResponse(),
                'collection_stats' => $collectionStats,
                'chain_tag'        => $chainTag,
                'trust_min'        => $trustMinWire,
                'viewer_is_member' => $isMember,
                'posts_last_7d'    => (int) $activity['posts_last_7d'],
            ],
            $viewerId
        );

        return [
            'id'                => $groupId,
            'slug'              => (string) $row->post_name,
            'name'              => (string) $row->post_title,
            'type'              => $ctx->type->value,
            'privacy'           => $ctx->privacy->value,
            'description'       => $description,
            'image_url'         => $imageUrl,
            'member_count'      => (int) $row->member_count,
            'verification'      => $ctx->verification?->toApiResponse(),
            'activity'          => $activity,
            'collection_stats'  => $collectionStats,
            'viewer_membership' => $viewerMembership,
            'permissions'       => $permissions,
            'feed_visible'      => $feedVisible,
            'members_visible'   => $membersVisible,
            'can_use_public_all'           => $canUsePublicAll,
            'can_manage_public_all_policy' => $canManagePublicAll,
            'public_all_members_enabled'   => $publicAllMembersEnabled,
            // Same key vocabulary as the create-response on POST /me/groups
            // (MyGroupsEndpoint::postCreate) — `trust_min` is the canonical
            // signal that the group is reputation-gated (privacy === "open"
            // under the hood for trust groups). `chain_tag` is the chain
            // slug; null means untagged.
            'chain_tag'         => $chainTag,
            'trust_min'         => $trustMinWire,
            'links'             => ['self' => '/groups/' . (string) $row->post_name],
            'card'              => $card,
        ];
    }

    /**
     * Privacy-gate check for the group-scoped feed endpoint.
     *
     * Returns:
     *   - {kind: 'not_found'}     — group missing OR secret + non-member
     *   - {kind: 'allowed', group_id: int, public_only: bool}
     *                              — caller proceeds with
     *                                FeedRankingService::getGroupFeed.
     *                                `public_only` is FALSE for members
     *                                (they see members_only posts too) and
     *                                TRUE for non-members of any surviving
     *                                kind (nft / closed / open) — they get
     *                                the PUBLIC-only teaser feed.
     *
     * Phase 2 (per-post visibility teaser): non-members of nft/closed
     * groups are NO LONGER `forbidden` here. Instead they are `allowed`
     * with `public_only = true`, and the SQL filter in
     * PeepSoActivityRepository::getActivities restricts their candidate
     * set to `public_group` / `public_all` posts via an INNER JOIN —
     * `members_only` and absent-meta posts can never reach them. Secret
     * groups still short-circuit to `not_found` above (never leak
     * existence), so the security invariant holds at two layers: the
     * 404 here for secret, and the visibility INNER JOIN downstream for
     * the rest.
     *
     * Same defense-in-depth model as `getGroup()` but returns a typed
     * gate decision rather than a view-model, so the endpoint can
     * separately set 404 vs 403 status codes per the contract.
     *
     * @return array{kind: 'not_found'}|array{kind: 'allowed', group_id: int, public_only: bool}
     */
    public function gateGroupFeed(int $viewerId, int $groupId): array
    {
        $access = $this->resolveGroupAccess($viewerId, $groupId);
        if ($access === null) {
            // Covers: missing group AND secret + non-member (never leaked).
            return ['kind' => 'not_found'];
        }

        $isMember = $access['isMember'];

        if ($isMember) {
            // Members read the full feed, including members_only posts.
            return ['kind' => 'allowed', 'group_id' => $groupId, 'public_only' => false];
        }

        // Non-member of a surviving (non-secret) group — nft / closed /
        // open. They get the PUBLIC-only teaser; the downstream INNER JOIN
        // enforces that members_only / absent-meta posts are excluded.
        return ['kind' => 'allowed', 'group_id' => $groupId, 'public_only' => true];
    }

    /**
     * Resolve the (group context, viewer membership) pair both gate
     * paths need: detail-page read AND members/feed sub-routes. Returns
     * `null` for "missing group OR secret + non-member" — callers map
     * to 404 without needing to peek inside the value object.
     *
     * Extracted from `gateGroupFeed()` so the §4.7.7 members endpoint
     * can apply a different post-resolution policy (closed-non-member
     * → 403 even though feed-non-member of an open group → allowed).
     * Both routes agree on existence (404 logic) and viewer-active-
     * membership; only the post-resolution decision differs.
     *
     * @return array{ctx: GroupContext, isMember: bool}|null
     */
    public function resolveGroupAccess(int $viewerId, int $groupId): ?array
    {
        if ($groupId <= 0) {
            return null;
        }

        $ctx = $this->groupContextResolver->forGroup($groupId);
        if ($ctx === null) {
            return null;
        }

        $isMember = $this->viewerIsActiveMember($viewerId, $groupId);

        // Defense-in-depth: secret + non-member never leaks existence.
        if ($ctx->privacy === PeepSoPrivacy::Secret && !$isMember) {
            return null;
        }

        return ['ctx' => $ctx, 'isMember' => $isMember];
    }

    /**
     * Request-memoized {@see PeepSoGroupRepository::getMembershipStatus}.
     *
     * @return string|null the raw PeepSo status, or null if no membership row.
     */
    private function membershipStatus(int $viewerId, int $groupId): ?string
    {
        if ($viewerId <= 0 || $groupId <= 0) {
            return null;
        }
        $key = $viewerId . ':' . $groupId;
        if (!array_key_exists($key, $this->membershipStatusCache)) {
            $this->membershipStatusCache[$key] = PeepSoGroupRepository::getMembershipStatus($viewerId, $groupId);
        }
        return $this->membershipStatusCache[$key];
    }

    /**
     * May this viewer AUTHOR a post in the group (any visibility)?
     *
     * Active membership (the READ set) admits `member_readonly`, but PeepSo's
     * read-only role is "read but not contribute" — a muted member may see the
     * group feed yet must not post. Authoring is therefore gated on the
     * posting-capable set (owner/manager/moderator/member; readonly excluded) —
     * the same set {@see PublicAllPolicy} uses for its `public_all` sub-gate.
     */
    public function viewerCanAuthorInGroup(int $viewerId, int $groupId): bool
    {
        $status = $this->membershipStatus($viewerId, $groupId);
        return $status !== null
            && in_array($status, PublicAllPolicy::POSTING_CAPABLE_STATUSES, true);
    }

    /**
     * Has the viewer an active membership row in the group? Wraps
     * PeepSoGroupRepository::getMembershipStatus + the canonical
     * READ_ALLOWED_STATUSES set so the gate matches the comment-count
     * gate {@see FeedRankingService::hydrateCommentCounts} byte-for-byte.
     */
    private function viewerIsActiveMember(int $viewerId, int $groupId): bool
    {
        if ($viewerId <= 0 || $groupId <= 0) {
            return false;
        }

        $status = $this->membershipStatus($viewerId, $groupId);
        if ($status === null) {
            return false;
        }
        return in_array($status, CommentService::READ_ALLOWED_STATUSES, true);
    }

    /**
     * Is the viewer the group's owner? `member_owner` is PeepSo's
     * canonical role enum value (one per group). Used to gate
     * `permissions.can_leave` on the detail-page response so the FE
     * renders the button server-disabled instead of a live action
     * that always 403s via PeepSoGroupWriter::leave's owner guard.
     *
     * Cheap: one indexed lookup against peepso_group_members. Same
     * source of truth PeepSoGroupWriter::leave uses for its own guard,
     * so the gate UI + write reject can never drift.
     */
    private function viewerIsGroupOwner(int $viewerId, int $groupId): bool
    {
        if ($viewerId <= 0 || $groupId <= 0) {
            return false;
        }
        $status = $this->membershipStatus($viewerId, $groupId);
        return $status === 'member_owner';
    }

    /**
     * May this author set `visibility='public_all'` on a post in this
     * group — i.e. syndicate it to the global / anonymous feed?
     *
     * Resolves the group privacy, the author's PeepSo role, and the
     * group's ordinary-member opt-in, then delegates the decision to the
     * canonical {@see PublicAllPolicy::canSyndicate}. This is the
     * AUTHORITATIVE backend gate: PostsService::gateGroupPost calls it
     * before every status/photo/gif writer, so a direct REST call cannot
     * bypass it. It is also surfaced read-only on the group-detail
     * `can_use_public_all` flag so the composer can hide the option a
     * member isn't allowed to use.
     *
     * Cost: one indexed membership lookup + one cached post-meta read;
     * the GroupContext is request-memoized by GroupContextResolver.
     */
    public function canUsePublicAll(int $authorId, int $groupId): bool
    {
        if ($authorId <= 0 || $groupId <= 0) {
            return false;
        }

        $ctx = $this->groupContextResolver->forGroup($groupId);
        if ($ctx === null) {
            return false;
        }

        $status = $this->membershipStatus($authorId, $groupId);

        return PublicAllPolicy::canSyndicate(
            $ctx->privacy,
            $status,
            GroupPostPolicyRepository::publicAllMembersEnabled($groupId)
        );
    }

    /**
     * Canonical authorization: may this actor CHANGE the group-wide
     * `_bcc_group_public_all_members` opt-in?
     *
     * Distinct from {@see canUsePublicAll} (may I set `public_all` on MY
     * OWN post): a `member_moderator` may *use* `public_all` on their own
     * post but may **not** flip the group-wide policy. The management set
     * is therefore narrower than the use set:
     *
     *   MAY manage: `member_owner`, `member_manager`, site admin (`manage_options`).
     *   MAY NOT:    `member_moderator`, ordinary member, readonly/pending/
     *               banned, non-member, anonymous.
     *
     * This is the single source of truth for the write route AND the
     * group-detail `can_manage_public_all_policy` flag — the response shaper
     * must not re-derive the role logic. Existence / secret-visibility is
     * the caller's gate ({@see setPublicAllMembersPolicy} via
     * `resolveGroupAccess`; {@see getGroup} via its §S secret-gate), so this
     * method never leaks existence on its own.
     */
    public function canManagePublicAllPolicy(int $actorId, int $groupId): bool
    {
        if ($actorId <= 0 || $groupId <= 0) {
            return false;
        }

        // Site admins manage any group they can resolve (caller-gated).
        if (user_can($actorId, 'manage_options')) {
            return true;
        }

        $status = $this->membershipStatus($actorId, $groupId);
        return $status === 'member_owner' || $status === 'member_manager';
    }

    /**
     * Owner/manager/admin control: enable or disable ordinary-member
     * `public_all` syndication for a closed/secret group (the
     * {@see PublicAllPolicy} opt-in). No-op on open groups where members
     * may already syndicate, but the flag is still stored so the setting
     * round-trips.
     *
     * Authorization is the canonical {@see canManagePublicAllPolicy}
     * (owner / manager / site admin — moderator excluded). Existence uses
     * {@see resolveGroupAccess} so a secret group never leaks to a
     * non-member (404, indistinguishable from missing). Returns the new
     * state on success; an error envelope otherwise (same shape the post
     * gates return, so the endpoint can `return $result`).
     *
     * @return array{ok: true, public_all_members_enabled: bool}|array{error: string, message: string}
     */
    public function setPublicAllMembersPolicy(int $actorId, int $groupId, bool $enabled): array
    {
        if ($actorId <= 0 || $groupId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'A valid group is required.'];
        }

        // Existence + secret-gate: null = missing OR secret-and-not-a-member.
        // Same 404 both ways — never leaks a secret group's existence.
        if ($this->resolveGroupAccess($actorId, $groupId) === null) {
            return ['error' => 'bcc_not_found', 'message' => 'Group not found.'];
        }

        if (!$this->canManagePublicAllPolicy($actorId, $groupId)) {
            return [
                'error'   => 'bcc_permission_denied',
                'message' => 'Only the group owner or a manager can change this setting.',
            ];
        }

        GroupPostPolicyRepository::setPublicAllMembers($groupId, $enabled);

        return ['ok' => true, 'public_all_members_enabled' => $enabled];
    }

    /**
     * Three states per the contract §4.7 viewer_membership block (cross-
     * kind shape — no `is_primary` because that's Local-only):
     *   - viewer anon                       → null
     *   - viewer authed, not active member  → {is_member: false, joined_at: null}
     *   - viewer authed, active member      → {is_member: true, joined_at: <iso>}
     *
     * @return array{is_member: bool, joined_at: string|null}|null
     */
    private function renderViewerMembership(int $viewerId, int $groupId): ?array
    {
        if ($viewerId <= 0) {
            return null;
        }

        $rows = PeepSoGroupRepository::findUserMemberships($viewerId, [$groupId]);
        $row  = $rows[$groupId] ?? null;
        if ($row === null) {
            return [
                'is_member' => false,
                'joined_at' => null,
            ];
        }

        return [
            'is_member' => true,
            'joined_at' => self::toIso8601((string) $row->joined_at),
        ];
    }

    /**
     * Resolve image_url + collection_stats + holder-eligibility for
     * NFT-type groups.
     *
     * Reuses the discovery endpoint's enrichment shape (§4.7.4) so the
     * detail page renders with the same field set as the discovery card
     * — frontend can keep one renderer for `collection_stats` across
     * both surfaces.
     *
     * `$isNftHolder` is computed only when the viewer is authed AND
     * not yet a member (a member doesn't need a re-check; their
     * permissions block already says they can_leave). For anon viewers
     * the holder check is impossible — we leave it false and the
     * permissions block surfaces "Sign in" upstream of the unlock hint.
     *
     * @return array{0: string|null, 1: array<string, mixed>|null, 2: bool}
     *         [image_url, collection_stats, is_nft_holder]
     */
    private function resolveNftEnrichment(GroupContext $ctx, int $viewerId, bool $isMember): array
    {
        $configs = GatedGroupRepository::findManyByGroupIds([$ctx->groupId]);
        $config  = $configs[$ctx->groupId] ?? null;
        if ($config === null) {
            return [null, null, false];
        }

        $collectionId = $config->collectionId;
        if ($collectionId === null || $collectionId <= 0) {
            // NFT-kind group with no resolved collection FK — surface the
            // wire shape with empty enrichment rather than 500'ing.
            return [null, null, false];
        }

        $collections = CollectionRepository::findManyByIds([$collectionId]);
        $row         = $collections[$collectionId] ?? null;
        if ($row === null) {
            return [null, null, false];
        }

        $imageUrl = ($row->image_url !== null && $row->image_url !== '')
            ? (string) $row->image_url
            : null;

        $totalSupply       = $row->total_supply !== null ? (int) $row->total_supply : null;
        $uniqueHolders     = $row->unique_holders !== null ? (int) $row->unique_holders : null;
        $floorPrice        = $row->floor_price !== null ? (string) $row->floor_price : null;
        $floorCurrency     = $row->floor_currency !== null ? (string) $row->floor_currency : null;
        $totalVolume       = $row->total_volume !== null ? (string) $row->total_volume : null;
        $listedPct         = $row->listed_percentage !== null ? (float) $row->listed_percentage : null;
        $royaltyPct        = $row->royalty_percentage !== null ? (float) $row->royalty_percentage : null;
        $distributionPct   = self::distributionPct($uniqueHolders, $totalSupply);
        $minBalance        = $config->minBalance;

        $stats = [
            'token_standard'      => $row->token_standard !== null ? (string) $row->token_standard : null,
            'total_supply'        => $totalSupply,
            'unique_holders'      => $uniqueHolders,
            'floor_price'         => $floorPrice,
            'floor_currency'      => $floorCurrency,
            'total_volume'        => $totalVolume,
            'listed_percentage'   => $listedPct,
            'royalty_percentage'  => $royaltyPct,
            'distribution_pct'    => $distributionPct,
            'min_balance'         => $minBalance,
            'floor_display'       => self::formatPriceDisplay($floorPrice, $floorCurrency),
            'volume_display'      => self::formatVolumeDisplay($totalVolume, $floorCurrency),
            'holders_display'     => self::formatHoldersDisplay($uniqueHolders, $distributionPct),
            'supply_display'      => self::formatIntegerDisplay($totalSupply),
            'listed_display'      => self::formatPercentDisplay($listedPct),
            'royalty_display'     => self::formatPercentDisplay($royaltyPct),
            'min_balance_display' => self::formatMinBalanceDisplay($minBalance),
            'marketplace'         => self::resolveMarketplaceLink(
                (string) $row->chain_slug,
                (string) $row->contract_address
            ),
        ];

        // Holder-eligibility check is currently a one-call probe that
        // the permissions builder can synthesize without re-querying;
        // we keep this slot for V2 phase 2 when a fast holder cache lands.
        // For V1 detail-page semantics the FE's join button does the
        // canonical eligibility round-trip via /me/holder-groups, so
        // false-here just means "show the unlock_hint." Members get
        // can_leave=true from the membership state; eligibility never
        // fires for them.
        $isNftHolder = false;
        unset($viewerId, $isMember);

        return [$imageUrl, $stats, $isNftHolder];
    }

    /**
     * Build the {can_join, can_leave, can_read_feed} block.
     *
     * Mirrors `UserGroupsEndpoint::canJoin` for the join branch (single
     * source of truth for the per-kind copy) and adds the read-feed
     * branch the detail page needs (Locals discovery doesn't carry one).
     *
     * @return array{
     *   can_join: array{allowed: bool, unlock_hint: string|null, reason_code: string|null},
     *   can_leave: array{allowed: bool, unlock_hint: string|null, reason_code: string|null},
     *   can_read_feed: array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     * }
     */
    private function buildPermissions(
        GroupContext $ctx,
        int $viewerId,
        bool $isMember,
        bool $isNftHolder,
        bool $isOwner = false,
        int $trustGateMin = 0
    ): array {
        $canJoin     = $this->resolveCanJoin($ctx, $viewerId, $isMember, $isNftHolder, $trustGateMin);
        $canLeave    = $this->resolveCanLeave($viewerId, $isMember, $isOwner);
        $canReadFeed = $this->resolveCanReadFeed($ctx, $viewerId, $isMember);

        return [
            'can_join'      => $canJoin,
            'can_leave'     => $canLeave,
            'can_read_feed' => $canReadFeed,
        ];
    }

    /**
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    private function resolveCanJoin(
        GroupContext $ctx,
        int $viewerId,
        bool $isMember,
        bool $isNftHolder,
        int $trustGateMin = 0
    ): array {
        if ($viewerId <= 0) {
            return [
                'allowed'     => false,
                'unlock_hint' => 'Sign in to join.',
                'reason_code' => 'auth_required',
            ];
        }

        if ($isMember) {
            return [
                'allowed'     => false,
                'unlock_hint' => null,
                'reason_code' => 'already_member',
            ];
        }

        if ($ctx->type === GroupType::Nft) {
            if ($isNftHolder) {
                return [
                    'allowed'     => true,
                    'unlock_hint' => null,
                    'reason_code' => null,
                ];
            }
            return [
                'allowed'     => false,
                'unlock_hint' => self::nftUnlockHint(),
                'reason_code' => 'not_eligible',
            ];
        }

        // BCC trust gate — ranked above the PeepSo privacy checks
        // because trust-gated groups use PeepSo's `open` privacy at
        // the storage layer (the gate is purely a BCC-layer concept).
        // Same threshold semantics as MyGroupsEndpoint::postJoin so
        // the FE hint and the server reject can't drift.
        if ($trustGateMin > 0) {
            $viewerScore = (int) round($this->reputationRepo->getScore($viewerId));
            if ($viewerScore < $trustGateMin) {
                return [
                    'allowed'     => false,
                    'unlock_hint' => sprintf(
                        'Earn a reputation score of %d to join. You\'re at %d.',
                        $trustGateMin,
                        $viewerScore
                    ),
                    'reason_code' => 'trust_threshold',
                ];
            }
            // Score passes — fall through to the open-privacy allow path
            // below. PeepSo privacy for trust groups is `open` per the
            // create-flow mapping, so the Secret/Closed checks here are
            // no-ops for the trust path.
        }

        if ($ctx->privacy === PeepSoPrivacy::Secret) {
            // Secret-non-member never reaches this branch (gated to 404
            // upstream). Belt-and-braces in case the call shape changes.
            return [
                'allowed'     => false,
                'unlock_hint' => null,
                'reason_code' => 'invite_only',
            ];
        }

        if ($ctx->privacy === PeepSoPrivacy::Closed) {
            return [
                'allowed'     => false,
                'unlock_hint' => 'Visit the group page to request to join.',
                'reason_code' => 'requires_approval',
            ];
        }

        return [
            'allowed'     => true,
            'unlock_hint' => null,
            'reason_code' => null,
        ];
    }

    /**
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    private function resolveCanLeave(int $viewerId, bool $isMember, bool $isOwner = false): array
    {
        if ($viewerId <= 0) {
            return [
                'allowed'     => false,
                'unlock_hint' => null,
                'reason_code' => 'auth_required',
            ];
        }
        if (!$isMember) {
            return [
                'allowed'     => false,
                'unlock_hint' => null,
                'reason_code' => 'not_member',
            ];
        }
        // Owners cannot leave their own group via the regular path —
        // PeepSoGroupWriter::leave guards on member_owner status to
        // prevent orphaning. Mirror that guard at the permissions
        // boundary so the FE renders a disabled button + the same
        // copy MyGroupsEndpoint::postLeave returns on a server-rejected
        // POST. Single source of truth for the unlock_hint.
        if ($isOwner) {
            return [
                'allowed'     => false,
                'unlock_hint' => 'Owners cannot leave their own community. Hand off ownership or delete the group first.',
                'reason_code' => 'owner_cannot_leave',
            ];
        }
        return [
            'allowed'     => true,
            'unlock_hint' => null,
            'reason_code' => null,
        ];
    }

    /**
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    private function resolveCanReadFeed(GroupContext $ctx, int $viewerId, bool $isMember): array
    {
        // Phase 2 (per-post visibility teaser): the feed surface is now
        // readable by EVERYONE who reaches this method — members get the
        // full feed, non-members (and anonymous viewers) get a PUBLIC-only
        // teaser. `feed_visible` therefore mirrors the gate: it is true so
        // the FE renders the (filtered) feed instead of a locked notice.
        //
        // Secret groups never reach here — getGroup() short-circuits to a
        // 404 for secret + non-member upstream (defense-in-depth gate #1),
        // so the only groups whose view-model is built are non-secret
        // (open / closed / nft) plus any privacy for an active member.
        //
        // The members_only / absent-meta posts are never exposed to
        // non-members: the SQL INNER JOIN in
        // PeepSoActivityRepository::getActivities (driven by the gate's
        // `public_only` flag) enforces that, NOT this advisory flag.
        unset($ctx, $viewerId, $isMember);

        return [
            'allowed'     => true,
            'unlock_hint' => null,
            'reason_code' => null,
        ];
    }

    private function resolveMembersVisible(GroupContext $ctx, bool $isMember): bool
    {
        if ($ctx->privacy === PeepSoPrivacy::Open) {
            return true;
        }
        // closed / secret: members list is private to active members.
        return $isMember;
    }

    private static function nftUnlockHint(): string
    {
        /** @var string $copy */
        $copy = apply_filters('bcc_group_nft_unlock_hint', self::NFT_UNLOCK_HINT_DEFAULT);
        return $copy === '' ? self::NFT_UNLOCK_HINT_DEFAULT : $copy;
    }

    private static function truncateDescription(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $stripped = trim((string) wp_strip_all_tags($raw, true));
        $stripped = (string) preg_replace('/\s+/u', ' ', $stripped);
        if ($stripped === '') {
            return null;
        }
        if (function_exists('mb_strlen') && mb_strlen($stripped) > self::DESCRIPTION_LIMIT) {
            return rtrim(mb_substr($stripped, 0, self::DESCRIPTION_LIMIT)) . '…';
        }
        if (strlen($stripped) > self::DESCRIPTION_LIMIT) {
            return rtrim(substr($stripped, 0, self::DESCRIPTION_LIMIT)) . '…';
        }
        return $stripped;
    }

    private static function toIso8601(string $mysqlDatetime): ?string
    {
        if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return null;
        }
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    // ── Formatting helpers (mirror GroupsDiscoveryEndpoint exactly) ───
    // Single source of truth for the *_display strings. If a divergence
    // appears the discovery endpoint is canonical — pull a shared helper
    // out then.

    private static function distributionPct(?int $holders, ?int $supply): ?int
    {
        if ($holders === null || $supply === null || $supply <= 0) {
            return null;
        }
        return (int) round(($holders / $supply) * 100);
    }

    private static function formatPriceDisplay(?string $rawPrice, ?string $currency): ?string
    {
        if ($rawPrice === null || $currency === null) {
            return null;
        }
        $n = (float) $rawPrice;
        if (!is_finite($n) || $n <= 0.0) {
            return '—';
        }
        return number_format($n, 2) . ' ' . $currency;
    }

    private static function formatVolumeDisplay(?string $rawVolume, ?string $currency): ?string
    {
        if ($rawVolume === null || $currency === null) {
            return null;
        }
        $n = (float) $rawVolume;
        if (!is_finite($n) || $n <= 0.0) {
            return '—';
        }
        return self::compactNumber($n) . ' ' . $currency;
    }

    private static function formatHoldersDisplay(?int $holders, ?int $distributionPct): ?string
    {
        if ($holders === null) {
            return null;
        }
        $base = number_format($holders);
        if ($distributionPct === null) {
            return $base;
        }
        return $base . ' (' . $distributionPct . '% dist.)';
    }

    private static function formatIntegerDisplay(?int $n): ?string
    {
        if ($n === null) {
            return null;
        }
        if ($n <= 0) {
            return '—';
        }
        return number_format($n);
    }

    private static function formatPercentDisplay(?float $n): ?string
    {
        if ($n === null) {
            return null;
        }
        return number_format($n, 2) . '%';
    }

    private static function compactNumber(float $n): string
    {
        $abs = abs($n);
        if ($abs >= 1.0e9) {
            return number_format($n / 1.0e9, 1) . 'B';
        }
        if ($abs >= 1.0e6) {
            return number_format($n / 1.0e6, 1) . 'M';
        }
        if ($abs >= 1.0e3) {
            return number_format($n / 1.0e3, 1) . 'K';
        }
        return number_format($n);
    }

    private static function formatMinBalanceDisplay(?int $minBalance): ?string
    {
        if ($minBalance === null || $minBalance < 1) {
            return null;
        }
        if ($minBalance === 1) {
            return '1 NFT';
        }
        return number_format($minBalance) . ' NFTs';
    }

    /**
     * @return array{url: string, label: string}|null
     */
    private static function resolveMarketplaceLink(string $chainSlug, string $contractAddress): ?array
    {
        if ($contractAddress === '' || $chainSlug === '') {
            return null;
        }

        $defaults = [
            // Bare /m/{contract} — the post-Hub-migration app parses a
            // trailing /tokens segment as token_id "tokens" (verified
            // 2026-07-02).
            'cosmos'    => ['template' => 'https://www.stargaze.zone/m/%s',             'label' => 'Stargaze'],
            'ethereum'  => ['template' => 'https://opensea.io/assets/ethereum/%s',     'label' => 'OpenSea'],
            'polygon'   => ['template' => 'https://opensea.io/assets/matic/%s',        'label' => 'OpenSea'],
            'arbitrum'  => ['template' => 'https://opensea.io/assets/arbitrum/%s',     'label' => 'OpenSea'],
            'optimism'  => ['template' => 'https://opensea.io/assets/optimism/%s',     'label' => 'OpenSea'],
            'base'      => ['template' => 'https://opensea.io/assets/base/%s',         'label' => 'OpenSea'],
            'avalanche' => ['template' => 'https://opensea.io/assets/avalanche/%s',    'label' => 'OpenSea'],
            'bsc'       => ['template' => 'https://opensea.io/assets/bsc/%s',          'label' => 'OpenSea'],
        ];

        /** @var array<string, array{template: string, label: string}> $map */
        $map = apply_filters('bcc_marketplace_link_map', $defaults);

        $entry = $map[$chainSlug] ?? null;
        if ($entry === null) {
            return null;
        }

        return [
            'url'   => sprintf($entry['template'], $contractAddress),
            'label' => $entry['label'],
        ];
    }
}
