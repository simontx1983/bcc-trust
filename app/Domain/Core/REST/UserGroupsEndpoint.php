<?php
/**
 * UserGroupsEndpoint — handles GET /bcc/v1/users/{slug}/groups.
 *
 * Cross-kind list of all PeepSo groups the target user is an active
 * member of: holder groups, Locals, plain user groups, and system
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
        $visibleContexts = $this->filterByPrivacy($contexts, $viewerId, $isSelf);
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

        $items = [];
        foreach ($visibleContexts as $groupId => $ctx) {
            $display = $displays[$groupId] ?? null;
            $items[] = $this->composeItem(
                $ctx,
                $display,
                $isSelf,
                $holderEligibility[$groupId] ?? null,
                $chainSlugByGroup[$groupId] ?? null,
                $trustMinByGroup[$groupId] ?? null
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
     * @param array<int, GroupContext> $contexts
     * @return array<int, GroupContext>
     */
    private function filterByPrivacy(array $contexts, int $viewerId, bool $isSelf): array
    {
        if ($isSelf) {
            return $contexts; // viewer is the member, sees all their own groups
        }

        $secretIds = [];
        foreach ($contexts as $groupId => $ctx) {
            if ($ctx->privacy === PeepSoPrivacy::Secret) {
                $secretIds[] = $groupId;
            }
        }

        if ($secretIds === [] || $viewerId === 0) {
            // No secret groups in the result, or viewer is anonymous —
            // strip all secrets either way.
            if ($viewerId === 0) {
                foreach ($secretIds as $sid) {
                    unset($contexts[$sid]);
                }
            }
            return $contexts;
        }

        $viewerSecretMemberships = PeepSoGroupRepository::findUserMemberships($viewerId, $secretIds);
        foreach ($secretIds as $sid) {
            if (!isset($viewerSecretMemberships[$sid])) {
                unset($contexts[$sid]);
            }
        }
        return $contexts;
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
     * Plain/Local/System groups are absent from the map.
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

        // Resolve chain slugs once per unique chain_id, not once per group.
        $slugByChain = [];
        foreach ($configs as $cfg) {
            if (isset($slugByChain[$cfg->chainId])) {
                continue;
            }
            $chain = \BCC\Core\ServiceLocator::resolveChainRead()->getById($cfg->chainId);
            if ($chain !== null) {
                $slugByChain[$cfg->chainId] = (string) $chain->slug;
            }
        }

        $pairs = [];
        foreach ($configs as $cfg) {
            $slug = $slugByChain[$cfg->chainId] ?? null;
            if ($slug === null) {
                continue;
            }
            $pairs[] = [$slug, $cfg->contractAddress];
        }
        if ($pairs === []) {
            return [];
        }

        $balances = HoldingsService::ownsAnyMany($viewerId, $pairs);

        $out = [];
        foreach ($configs as $groupId => $cfg) {
            $slug = $slugByChain[$cfg->chainId] ?? null;
            if ($slug === null) {
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
            $balKey     = $slug . ':' . $cfg->contractAddress;
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
        ?int $trustMin
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
                'can_join'  => $this->canJoin($ctx, $isSelf, $holderEligibility),
                'can_leave' => $this->canLeave($isSelf),
            ],
        ];
    }

    private function routeKeyForType(GroupType $type): string
    {
        return match ($type) {
            GroupType::Nft       => 'holder-groups',
            GroupType::Validator => 'validator-groups',
            GroupType::Local     => 'locals',
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
            GroupType::Local->value     => 'Local',
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
