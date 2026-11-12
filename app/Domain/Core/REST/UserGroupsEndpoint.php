<?php
/**
 * UserGroupsEndpoint — handles GET /bcc/v1/users/{slug}/groups.
 *
 * Cross-kind list of all PeepSo groups the target user is an active
 * member of: holder groups, Halls, plain user groups, and system
 * groups all flow through the same shape via GroupContextResolver.
 *
 * Privacy filtering (server-authoritative):
 *   - secret groups → only included if the *viewer* is also a member
 *   - closed groups → included; non-members get name + member_count,
 *     content stays private at PeepSo's layer
 *   - open groups   → always included
 *
 * Each item carries `actions` (server-built URLs varying by group
 * `type`) and `permissions` (viewer-aware can_join / can_leave per §A4).
 * Holder-group eligibility is computed for the *viewer* via batched
 * HoldingsService::ownsAnyMany so a profile with N gated groups costs
 * one holdings call, not N.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 (Profile Groups tab)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\ProfileAccess;
use BCC\Trust\Core\Support\ProfileGroupVisibility;
use BCC\Trust\Core\Support\PublicAllPolicy;
use BCC\Trust\Core\Support\UserSlugResolver;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use BCC\Trust\Core\REST\UsersEndpoint;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Services\HoldingsService;
use BCC\Trust\Onchain\ValueObjects\GatedGroupConfig;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class UserGroupsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const REST_BASE       = '/wp-json/bcc/v1/me';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/users/(?P<slug>' . UsersEndpoint::HANDLE_PATTERN . ')/groups',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'getList'],
                'permission_callback' => '__return_true',
                'args' => [
                    'slug' => ['required' => true, 'sanitize_callback' => 'sanitize_user'],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getList(WP_REST_Request $request): WP_REST_Response
    {
        $slug      = (string) $request->get_param('slug');
        $targetId  = UserSlugResolver::resolve($slug);
        if ($targetId === 0) {
            return ApiResponse::error('bcc_not_found', 'User not found.', 404);
        }

        // §F-02 profile-wide gate. This route already narrows the GROUP
        // list by the viewer's own access, but that is a per-group
        // decision — it does not stop a stranger confirming which groups
        // an "only me" profile belongs to via the public ones.
        if (!ProfileAccess::canViewProfile($targetId)) {
            return ProfileAccess::forbidden();
        }

        $viewerId = get_current_user_id();
        $isSelf   = ($viewerId > 0 && $viewerId === $targetId);

        $groupIds = PeepSoGroupRepository::getUserMemberGroupIds($targetId);
        if ($groupIds === []) {
            return $this->respond([]);
        }

        $resolver  = Plugin::instance()->groupContextResolver();
        $contexts  = $resolver->forManyGroups($groupIds);
        $displays  = PeepSoGroupRepository::findManyByIds($groupIds);

        // Privacy filter — drop secret groups the viewer can't see.
        $visibleContexts = $this->filterByPrivacy($contexts, $viewerId, $isSelf, $targetId);
        if ($visibleContexts === []) {
            return $this->respond([]);
        }

        // Pre-compute holder-group eligibility for the viewer in one batched call.
        $holderEligibility = $this->computeViewerHolderEligibility($viewerId, $visibleContexts);

        // Bulk-resolve chain + trust enrichment so the profile-tab rows
        // carry the same chips the /communities discovery surface emits.
        // One JOIN'd SELECT for chain slugs + one warmed meta cache for
        // trust thresholds — keeps the surface consistent without N+1.
        $visibleIds       = array_keys($visibleContexts);
        $chainSlugByGroup = \BCC\Core\ServiceLocator::resolveChainRead()->resolveSlugsForGroups($visibleIds);
        update_meta_cache('post', $visibleIds);
        $trustMinByGroup = [];
        foreach ($visibleIds as $gid) {
            $v = (int) get_post_meta($gid, '_bcc_trust_gate_min', true);
            if ($v > 0) {
                $trustMinByGroup[$gid] = $v;
            }
        }

        // Posting capability, for the §D6 blog-editor group picker. One
        // batched membership read, in the same spirit as the enrichment
        // above — the alternative is a per-group status query, which on a
        // 200-group profile is 200 extra reads.
        //
        // Self only. For anyone else's profile the question is not just
        // unanswerable but meaningless: the picker asks "where may I post",
        // and a viewer is never offered a composer on another member's tab.
        $postingStatusByGroup = $isSelf
            ? $this->loadPostingStatuses($viewerId, $visibleIds)
            : [];

        $items = [];
        foreach ($visibleContexts as $groupId => $ctx) {
            $display = $displays[$groupId] ?? null;
            $items[] = $this->composeItem(
                $ctx,
                $display,
                $isSelf,
                $holderEligibility[$groupId] ?? null,
                $chainSlugByGroup[$groupId] ?? null,
                $trustMinByGroup[$groupId] ?? null,
                $postingStatusByGroup[$groupId] ?? null
            );
        }

        return $this->respond($items);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function respond(array $items): WP_REST_Response
    {
        $response = ApiResponse::ok(['items' => $items]);
        $response->header('Cache-Control', 'private, max-age=30');
        return $response;
    }

    /**
     * Privacy filter for the Groups tab.
     *
     * ⚠ THE RULES NO LONGER LIVE HERE. They moved to
     * {@see ProfileGroupVisibility::filter()} because the user
     * view-model's `halls` field publishes the same fact and had NO
     * filter at all — it leaked secret-group membership to any profile
     * reader (F-323). Two copies of a privacy rule is how that happened;
     * this method is now a thin adapter so there is exactly one.
     *
     * What it gained besides the move (user decisions, 2026-11-12): the
     * "Show my groups on my profile" toggle, and holder/delegator groups
     * being withheld from anonymous readers (F-270).
     *
     * @param array<int, GroupContext> $contexts
     * @return array<int, GroupContext>
     */
    private function filterByPrivacy(array $contexts, int $viewerId, bool $isSelf, int $ownerId): array
    {
        if ($isSelf) {
            return $contexts; // viewer is the member, sees all their own groups
        }

        return ProfileGroupVisibility::filter($contexts, $ownerId, $viewerId);
    }

    /**
     * For each NFT-gated group in the visible set, check whether the
     * *viewer* (not the target) qualifies. Used to set
     * permissions.can_join when viewing someone else's profile.
     *
     * Two batched lookups regardless of how many NFT groups the
     * profile carries:
     *   1. `GatedGroupRepository::findManyByGroupIds` — one
     *      update_meta_cache for all NFT group_ids, no per-group DB
     *      round-trip.
     *   2. `ChainRepository::getById` is called once per *unique*
     *      chain_id (typically 1–3 across a user's NFT memberships),
     *      not once per group.
     *
     * Returns a map keyed by group_id of (eligible: bool, balance: int).
     * Plain/Hall/System groups are absent from the map.
     *
     * @param array<int, GroupContext> $contexts
     * @return array<int, array{eligible: bool, min_balance: int, balance: int}>
     */
    private function computeViewerHolderEligibility(int $viewerId, array $contexts): array
    {
        if ($viewerId === 0) {
            return [];
        }

        $nftGroupIds = [];
        foreach ($contexts as $groupId => $ctx) {
            if ($ctx->type === GroupType::Nft) {
                $nftGroupIds[] = $groupId;
            }
        }
        if ($nftGroupIds === []) {
            return [];
        }

        $configs = GatedGroupRepository::findManyByGroupIds($nftGroupIds);
        if ($configs === []) {
            return [];
        }

        // PR 5b: the identity a gate is evaluated on comes from its linked
        // collection row, never from `$cfg->contractAddress` (the legacy
        // display alias — a Magic Eden symbol on the eight Solana gates).
        // A gate that cannot be resolved contributes no pair, so no
        // provider is asked, and it is simply absent from the result — the
        // caller already treats an absent group as "no eligibility info"
        // and renders no badge, which is the correct fail-closed answer.
        $balanceKeyByGroup = [];
        $pairs             = [];
        foreach ($configs as $cfg) {
            $identity = \BCC\Trust\Onchain\Services\GateIdentityResolver::resolve($cfg);
            if (!$identity->isResolved()) {
                continue;
            }
            $balanceKeyByGroup[$cfg->groupId] = $identity->chainSlug() . ':' . $identity->canonical();
            $pairs[] = [$identity->chainSlug(), $identity->canonical(), $cfg->minBalance];
        }
        if ($pairs === []) {
            return [];
        }

        // PR 7.14: a profile render gets the profile budget. A group it cannot
        // reach comes back null and renders `eligible: false`.
        $balances = HoldingsService::ownsAnyMany(
            $viewerId,
            $pairs,
            HoldingsService::verificationBudget(HoldingsService::SURFACE_PROFILE)
        );

        $out = [];
        foreach ($configs as $groupId => $cfg) {
            $balKey = $balanceKeyByGroup[$cfg->groupId] ?? null;
            if ($balKey === null) {
                continue;
            }
            // null = UNKNOWN (provider couldn't verify). Fail closed on the
            // eligibility flag — never render a false "eligible" badge from
            // an outage. The `balance` field stays an int for wire-shape
            // stability (coerce the unknown to 0); `eligible:false` is what
            // the frontend actually gates on.
            //
            // array_key_exists (NOT `?? 0`): a present null is UNKNOWN and
            // `??` would collapse it to a false real-0 before we can test it.
            $rawBalance = array_key_exists($balKey, $balances) ? $balances[$balKey] : 0;
            $out[$groupId] = [
                'eligible'    => $rawBalance !== null && $rawBalance >= $cfg->minBalance,
                'min_balance' => $cfg->minBalance,
                'balance'     => $rawBalance ?? 0,
            ];
        }
        return $out;
    }

    /**
     * @param object{id: numeric-string, post_name: string, post_title: string, post_content: string, member_count: numeric-string}|null $display
     * @param array{eligible: bool, min_balance: int, balance: int}|null $holderEligibility
     * @return array<string, mixed>
     */
    private function composeItem(
        GroupContext $ctx,
        ?object $display,
        bool $isSelf,
        ?array $holderEligibility,
        ?string $chainTag,
        ?int $trustMin,
        ?string $memberStatus = null
    ): array {
        $route = $this->routeKeyForType($ctx->type);

        return [
            'group_id'     => $ctx->groupId,
            'slug'         => $display !== null ? (string) $display->post_name : '',
            'name'         => $display !== null ? (string) $display->post_title : '',
            'type'         => $ctx->type->value,
            'type_label'   => self::typeLabel($ctx->type),
            'member_count' => $display !== null ? (int) $display->member_count : 0,
            'privacy'      => $ctx->privacy->value,
            'verification' => $ctx->verification?->toApiResponse(),
            // Same key vocabulary as discovery + detail surfaces. Profile
            // Groups tab carries them too so the same group renders with
            // the same chips wherever it appears.
            'chain_tag'    => $chainTag,
            'trust_min'    => $trustMin,
            'actions'      => [
                'join'  => ['url' => self::REST_BASE . '/' . $route . '/' . $ctx->groupId . '/join'],
                'leave' => ['url' => self::REST_BASE . '/' . $route . '/' . $ctx->groupId . '/leave'],
            ],
            'permissions'  => [
                'can_join'      => $this->canJoin($ctx, $isSelf, $holderEligibility),
                'can_leave'     => $this->canLeave($isSelf),
                'can_post_blog' => $this->canPostBlog($isSelf, $memberStatus),
            ],
        ];
    }

    private function routeKeyForType(GroupType $type): string
    {
        return match ($type) {
            GroupType::Nft       => 'holder-groups',
            GroupType::Validator => 'validator-groups',
            GroupType::Hall      => 'halls',
            default              => 'groups',
        };
    }

    /**
     * Server-authoritative display label for a group kind. Frontend
     * renders verbatim per §A2 / §S — no client-side enum→label
     * mapping. Filterable via `bcc_group_type_label` so the copy can
     * be re-tuned (or localized) without a frontend release.
     */
    private static function typeLabel(GroupType $type): string
    {
        $defaults = [
            GroupType::Nft->value       => 'On-Chain Holders',
            GroupType::Validator->value => 'Validator Delegators',
            GroupType::Hall->value      => 'Hall',
            GroupType::System->value    => 'System',
            GroupType::User->value      => 'Community',
        ];
        $label = $defaults[$type->value] ?? 'Community';

        /** @var string $filtered */
        $filtered = apply_filters('bcc_group_type_label', $label, $type->value);
        return (string) $filtered;
    }

    /**
     * @param array{eligible: bool, min_balance: int, balance: int}|null $holderEligibility
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    private function canJoin(GroupContext $ctx, bool $isSelf, ?array $holderEligibility): array
    {
        if ($isSelf) {
            // Viewing own profile — listed groups are already joined.
            return [
                'allowed'     => false,
                'unlock_hint' => null,
                'reason_code' => 'already_member',
            ];
        }

        if ($ctx->type === GroupType::Nft) {
            if ($holderEligibility !== null && $holderEligibility['eligible']) {
                return [
                    'allowed'     => true,
                    'unlock_hint' => null,
                    'reason_code' => null,
                ];
            }
            return [
                'allowed'     => false,
                'unlock_hint' => 'Hold an NFT from this collection to join.',
                'reason_code' => 'not_eligible',
            ];
        }

        // Delegator communities are stored `closed`, so without this branch
        // they'd fall through to the "request to join" copy below — wrong:
        // there is no approval queue, the gate is on-chain delegation.
        // Live eligibility is deliberately NOT computed here (it would cost
        // an LCD call per profile render); the FE does the canonical
        // round-trip via /me/validator-groups, same posture as the holder
        // tab. Copy mirrors ValidatorGroupsEndpoint's server-pinned hint.
        if ($ctx->type === GroupType::Validator) {
            return [
                'allowed'     => false,
                'unlock_hint' => 'Delegate to this validator to join its community.',
                'reason_code' => 'not_eligible',
            ];
        }

        if ($ctx->privacy === PeepSoPrivacy::Secret) {
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
     * May the viewer publish a long-form blog into this group?
     *
     * ⚠ THIS IS NOT "IS A MEMBER". The membership read filters
     * `gm_user_status LIKE 'member%'`, which ADMITS `member_readonly` — a
     * status that reads but cannot contribute. So row presence says yes to
     * a read-only member, and a picker built on presence would list the
     * group, let the author write the whole blog, and have the create call
     * refuse it. The decision has to read the ROLE and compare it against
     * the posting-capable set.
     *
     * {@see PublicAllPolicy::POSTING_CAPABLE_STATUSES} is the SAME constant
     * `GroupsService::viewerCanAuthorInGroup()` uses, deliberately: these
     * two must never drift, and a test pins that they agree — including
     * `member_readonly` → false. Reused rather than restated, per §11.
     *
     * Shape matches its siblings — `{allowed, unlock_hint, reason_code}`,
     * all three keys — rather than being a bare boolean, so the frontend
     * reads every permission on this item the same way and can branch on a
     * code instead of parsing prose.
     *
     * `allowed: false` with `reason_code: not_self` on ANOTHER member's
     * profile means "not applicable" rather than "refused" — the picker
     * only ever asks this of the viewer's own listing.
     *
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    private function canPostBlog(bool $isSelf, ?string $memberStatus): array
    {
        if (!$isSelf) {
            return ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'not_self'];
        }

        if ($memberStatus === null) {
            // Listed but no active membership row came back — including the
            // case where the batched read FAILED, which yields no rows and
            // so lands here. Refusing is the closed direction.
            return ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'not_member'];
        }

        if (in_array($memberStatus, PublicAllPolicy::POSTING_CAPABLE_STATUSES, true)) {
            return ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
        }

        return [
            'allowed'     => false,
            'unlock_hint' => 'Read-only members cannot post in this group.',
            'reason_code' => 'read_only_member',
        ];
    }

    /**
     * The viewer's membership ROLE per group, in one batched read.
     *
     * Reads `user_status` from `findUserMemberships`, which carries it
     * precisely so this decision does not become a per-group query. A
     * failed read returns no rows, so every group lands on
     * `$memberStatus === null` and {@see canPostBlog} refuses — closed.
     *
     * @param list<int> $groupIds
     * @return array<int, string>
     */
    private function loadPostingStatuses(int $viewerId, array $groupIds): array
    {
        if ($viewerId <= 0 || $groupIds === []) {
            return [];
        }

        $out = [];
        foreach (PeepSoGroupRepository::findUserMemberships($viewerId, $groupIds) as $groupId => $row) {
            $status = isset($row->user_status) ? (string) $row->user_status : '';
            if ($status !== '') {
                $out[(int) $groupId] = $status;
            }
        }

        return $out;
    }

    /**
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    private function canLeave(bool $isSelf): array
    {
        return [
            'allowed'     => $isSelf,
            'unlock_hint' => null,
            'reason_code' => $isSelf ? null : 'not_self',
        ];
    }
}
