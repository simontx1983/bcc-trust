<?php
/**
 * ValidatorGroupsEndpoint — handles /bcc/v1/me/validator-groups.
 *
 * Three routes (§4.7.6 — mirror of the §4.7.1 holder-groups shape with
 * a `validator_stats` block instead of `collection`):
 *
 *   GET           /me/validator-groups            — joined + eligible_to_join + opted_out
 *   POST          /me/validator-groups/{id}/join  — explicit delegation-verified join
 *   POST|DELETE   /me/validator-groups/{id}/leave — explicit leave (records TTL'd opt-out)
 *
 * Auth: required (self-only — every route is `me/...`). No preferences
 * routes: auto-join is a V1 cut for delegator communities; every join is
 * explicit.
 *
 * Fail-closed: an UNKNOWN delegation verdict (LCD outage / unreadable
 * amounts) 503s the join and silently omits the group from
 * `eligible_to_join` — never a false-positive suggestion that 503s on
 * click, and never a join we couldn't prove.
 *
 * @package BCC\Trust\Onchain\REST
 * @since Communities V1 (validator/delegator communities)
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\REST;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\ValueObjects\GroupVerification;
use BCC\Trust\Onchain\OnchainPlugin;
use BCC\Trust\Onchain\Repositories\ValidatorGroupRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\ValueObjects\JoinResult;
use BCC\Trust\Onchain\ValueObjects\ValidatorGatedGroupConfig;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidatorGroupsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/validator-groups',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'getList'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/validator-groups/(?P<id>\d+)/join',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'postJoin'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => ['required' => true, 'sanitize_callback' => 'absint'],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/validator-groups/(?P<id>\d+)/leave',
            [
                'methods'             => 'POST, DELETE',
                'callback'            => [$instance, 'postLeave'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => ['required' => true, 'sanitize_callback' => 'absint'],
                ],
            ]
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // GET /me/validator-groups
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getList(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Per-viewer throttle FIRST: the eligibility pass below costs one
        // LCD call per verified Cosmos wallet (5-min cached), so a
        // hostile refresh loop must not hammer the LCD. Same posture and
        // bucket size as HolderGroupsEndpoint::getList.
        if (!\BCC\Core\Security\Throttle::allow('validator_groups_list:' . $userId, 20, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $configs = ValidatorGroupRepository::listAllValidatorGroupConfigs();
        if ($configs === []) {
            $response = ApiResponse::ok([
                'joined'           => [],
                'eligible_to_join' => [],
                'opted_out'        => [],
            ]);
            $response->header('Cache-Control', 'private, no-store');
            return $response;
        }

        $groupIds = array_map(
            static fn(ValidatorGatedGroupConfig $c): int => $c->groupId,
            $configs
        );
        $validatorIds = array_map(
            static fn(ValidatorGatedGroupConfig $c): int => $c->validatorId,
            $configs
        );

        $displays    = PeepSoGroupRepository::findManyByIds($groupIds);
        $memberships = PeepSoGroupRepository::findUserMemberships($userId, $groupIds);
        $statsRows   = ValidatorRepository::findCommunityStatsByIds($validatorIds);
        $activity    = Plugin::instance()->groupActivityHeatService()->forGroups($groupIds);

        $optOuts = OnchainPlugin::instance()->nftGroupGateService();

        // Non-member, non-opted-out groups need a live verdict for the
        // eligible bucket — batch them so each wallet's LCD fetch is
        // shared across every config on that chain.
        $verdictCandidates = [];
        foreach ($configs as $cfg) {
            if (isset($memberships[$cfg->groupId])) {
                continue;
            }
            if ($optOuts->isOptOutActive($userId, $cfg->groupId)) {
                continue;
            }
            $verdictCandidates[] = $cfg;
        }
        $verdicts = $verdictCandidates !== []
            ? OnchainPlugin::instance()->delegationEligibilityService()->verdictsForUser($userId, $verdictCandidates)
            : [];

        $joined   = [];
        $eligible = [];
        $optedOut = [];

        foreach ($configs as $cfg) {
            $display       = $displays[$cfg->groupId] ?? null;
            $statsRow      = $statsRows[$cfg->validatorId] ?? null;
            $activityBlock = $activity[$cfg->groupId] ?? [
                'posts_last_7d'    => 0,
                'last_activity_at' => null,
                'heat'             => 'cold',
                'heat_label'       => 'Quiet',
            ];
            $item = $this->composeItem($cfg, $display, $statsRow, $activityBlock);

            if (isset($memberships[$cfg->groupId])) {
                $joined[] = $item;
                continue;
            }

            if ($optOuts->isOptOutActive($userId, $cfg->groupId)) {
                $optedOut[] = $item;
                continue;
            }

            // UNKNOWN (LCD outage) or missing verdict → NOT surfaced as
            // eligible. A suggestion that 503s on the actual join is
            // worse than omitting it this poll — fail closed, it
            // reappears once verification succeeds.
            $verdict = $verdicts[$cfg->groupId] ?? null;
            if ($verdict !== null && $verdict->isEligible()) {
                $eligible[] = $item;
            }
        }

        $response = ApiResponse::ok([
            'joined'           => $joined,
            'eligible_to_join' => $eligible,
            'opted_out'        => $optedOut,
        ]);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /me/validator-groups/{id}/join
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function postJoin(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Rate-limit FIRST (throttle-before-credentials rule): the cheap
        // fail-closed counter must reject floods before the suspension
        // gate's permission read runs. Each gate call can cost a live LCD
        // fetch, so unbounded retry amplifies upstream load. Same bucket
        // sizing as the holder-group join.
        if (!\BCC\Core\Security\Throttle::allow('validator_group_join:' . $userId, 10, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        // Suspended accounts must not walk back into a community —
        // delegating stake is the eligibility gate, not an override of
        // moderation. The join lands members via the trusted
        // PeepSoGroupWriter door (bypassing PeepSo's own UI approval),
        // so the suspension gate is enforced HERE; the gate service
        // checks delegation/opt-out, never suspension. Admin bypass off.
        // Parity with HolderGroupsEndpoint::postJoin / MyGroupsEndpoint
        // ::postJoin [audit M — group-rejoin].
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)) {
            return ApiResponse::error('bcc_forbidden', 'Your account is suspended.', 403);
        }

        $groupId = (int) $request->get_param('id');
        $result  = OnchainPlugin::instance()->validatorGroupGateService()->joinIfEligible($userId, $groupId);

        if ($result->success) {
            // §CRIT-05 accountability — audit only on an actual state
            // transition (CODE_OK); already_member no-ops don't inflate
            // the trail. Response shape identical either way.
            if ($result->code === JoinResult::CODE_OK) {
                AuditLogger::log('validator_group_join', $groupId, [
                    'code' => $result->code,
                    'via'  => 'explicit',
                ], 'group', $userId);
            }

            return ApiResponse::ok([
                'joined'   => true,
                'group_id' => $groupId,
                'code'     => $result->code,
            ]);
        }

        return $this->translateFailure($result, $groupId);
    }

    // ──────────────────────────────────────────────────────────────────
    // POST|DELETE /me/validator-groups/{id}/leave
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function postLeave(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('validator_group_leave:' . $userId, 10, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $groupId = (int) $request->get_param('id');
        $config  = ValidatorGroupRepository::getGateConfig($groupId);
        if ($config === null) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Not a delegator community.',
                400
            );
        }

        // Capture the real state transition BEFORE the writer call —
        // PeepSoGroupWriter::leave returns true for the no-existing-row
        // idempotent case too, and audit rows are only written on real
        // transitions (destructive-mutation-hardening invariant).
        $wasMember = PeepSoGroupRepository::getMembershipStatus($userId, $groupId) !== null;

        $left = \BCC\Core\PeepSo\PeepSoGroupWriter::leave($userId, $groupId);
        if (!$left) {
            // Most likely cause: caller is the operator-owner.
            return ApiResponse::error(
                'bcc_permission_denied',
                'Owners cannot leave their own community. Hand off ownership or delete the group first.',
                403
            );
        }

        if ($wasMember) {
            // TTL'd opt-out via the SHARED (group-id-keyed) opt-out
            // machinery so the GET buckets and the mod-eviction listener
            // treat delegator communities exactly like holder groups.
            OnchainPlugin::instance()->nftGroupGateService()->recordOptOut($userId, $groupId);

            AuditLogger::log('validator_group_leave', $groupId, [], 'group', $userId);
        }

        return ApiResponse::ok([
            'left'     => true,
            'group_id' => $groupId,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Wire-shape helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Compose the §4.7.6 / §4.7.4 `validator_stats` block from a
     * {@see ValidatorRepository::findCommunityStatsByIds} row. SINGLE
     * SOURCE for this wire shape — GroupsDiscoveryEndpoint and
     * GroupsService import it so the block can never drift between
     * surfaces. Null row → null block (validator not indexed yet).
     *
     * @param object{
     *     moniker: string|null,
     *     status: string|null,
     *     commission_rate: string|null,
     *     total_stake: string|null,
     *     delegator_count: string|null,
     *     native_token: string|null,
     *     page_slug: string|null
     * }|null $row
     * @return array{
     *     moniker: string|null,
     *     status: string|null,
     *     commission: float|null,
     *     total_stake: float|null,
     *     delegator_count: int|null,
     *     min_stake_display: string|null,
     *     validator_page: string|null
     * }|null
     */
    public static function composeValidatorStats(?object $row, ?float $minStake): ?array
    {
        if ($row === null) {
            return null;
        }

        $pageSlug = ($row->page_slug !== null && $row->page_slug !== '')
            ? (string) $row->page_slug
            : null;

        return [
            'moniker'           => $row->moniker !== null && $row->moniker !== '' ? (string) $row->moniker : null,
            'status'            => $row->status !== null && $row->status !== '' ? (string) $row->status : null,
            'commission'        => $row->commission_rate !== null ? (float) $row->commission_rate : null,
            'total_stake'       => $row->total_stake !== null ? (float) $row->total_stake : null,
            'delegator_count'   => $row->delegator_count !== null ? (int) $row->delegator_count : null,
            'min_stake_display' => self::formatMinStakeDisplay($minStake, $row->native_token),
            // CardUrlMap is the locked kind→URL composer — never inline
            // the '/v/' prefix.
            'validator_page'    => $pageSlug !== null
                ? \BCC\Trust\Core\Support\CardUrlMap::frontendUrl('validator', $pageSlug)
                : null,
        ];
    }

    /**
     * "1 ATOM" / "2.5 OSMO" / "1" (token unknown). Null when the gate is
     * unset — mirrors formatMinBalanceDisplay's null-when-ungated rule.
     */
    private static function formatMinStakeDisplay(?float $minStake, ?string $nativeToken): ?string
    {
        if ($minStake === null || $minStake <= 0.0) {
            return null;
        }
        $amount = (floor($minStake) === $minStake)
            ? number_format($minStake, 0)
            : rtrim(rtrim(number_format($minStake, 6, '.', ','), '0'), '.');

        $token = ($nativeToken !== null && $nativeToken !== '') ? strtoupper($nativeToken) : null;
        return $token !== null ? $amount . ' ' . $token : $amount;
    }

    /**
     * @param object{id: numeric-string, post_name: string, post_title: string, post_content: string, member_count: numeric-string}|null $display
     * @param object{
     *     moniker: string|null,
     *     status: string|null,
     *     commission_rate: string|null,
     *     total_stake: string|null,
     *     delegator_count: string|null,
     *     native_token: string|null,
     *     page_slug: string|null
     * }|null $statsRow
     * @param array{posts_last_7d: int, last_activity_at: string|null, heat: string, heat_label: string} $activity
     * @return array<string, mixed>
     */
    private function composeItem(
        ValidatorGatedGroupConfig $cfg,
        ?object $display,
        ?object $statsRow,
        array $activity
    ): array {
        return [
            'group_id'        => $cfg->groupId,
            'slug'            => $display !== null ? (string) $display->post_name : '',
            'name'            => $display !== null ? (string) $display->post_title : '',
            'member_count'    => $display !== null ? (int) $display->member_count : 0,
            'validator_stats' => self::composeValidatorStats($statsRow, $cfg->minStake),
            'verification'    => GroupVerification::onChain()->toApiResponse(),
            'activity'        => $activity,
        ];
    }

    private function translateFailure(JoinResult $result, int $groupId): WP_REST_Response
    {
        switch ($result->code) {
            case JoinResult::CODE_NOT_A_HOLDER_GROUP:
                // Gate-service sentinel for "no delegator gate config."
                return ApiResponse::error(
                    'bcc_invalid_request',
                    'Not a delegator community.',
                    400
                );

            case JoinResult::CODE_OPT_OUT_ACTIVE:
                return ApiResponse::error(
                    'bcc_permission_denied',
                    'You opted out of this community recently. Try again later or rejoin from the discovery page.',
                    403
                );

            case JoinResult::CODE_NOT_ELIGIBLE:
                return ApiResponse::error(
                    'bcc_permission_denied',
                    $this->buildNotEligibleHint($groupId),
                    403
                );

            case JoinResult::CODE_CHAIN_UNSUPPORTED:
                return ApiResponse::error(
                    'bcc_internal_error',
                    'This community is on an unsupported chain right now. Please try again later.',
                    503
                );

            case JoinResult::CODE_VERIFY_UNAVAILABLE:
                // LCD couldn't verify the delegation (timeout / 429 /
                // unreadable amounts). NOT a "you don't qualify" — a
                // transient "we couldn't check." 503 + retry framing.
                // Fail-closed: we did NOT join them.
                return ApiResponse::error(
                    'bcc_upstream_unavailable',
                    'We could not verify your delegation right now. Please try again in a moment.',
                    503
                );
        }

        return ApiResponse::error(
            'bcc_internal_error',
            'Could not join community.',
            500
        );
    }

    private function buildNotEligibleHint(int $groupId): string
    {
        $config = ValidatorGroupRepository::getGateConfig($groupId);
        if ($config === null) {
            return 'Delegate to this validator to join its community.';
        }

        $statsRows = ValidatorRepository::findCommunityStatsByIds([$config->validatorId]);
        $row       = $statsRows[$config->validatorId] ?? null;

        $moniker = ($row !== null && $row->moniker !== null && $row->moniker !== '')
            ? (string) $row->moniker
            : null;
        $minDisplay = self::formatMinStakeDisplay(
            $config->minStake,
            $row !== null ? $row->native_token : null
        );

        if ($minDisplay !== null && $config->minStake > 1.0) {
            return $moniker !== null
                ? sprintf('Delegate at least %s to %s to join this community.', $minDisplay, $moniker)
                : sprintf('Delegate at least %s to this validator to join.', $minDisplay);
        }

        return $moniker !== null
            ? sprintf('Delegate to %s to join this community.', $moniker)
            : 'Delegate to this validator to join its community.';
    }
}
