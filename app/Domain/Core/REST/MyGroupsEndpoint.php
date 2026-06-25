<?php
/**
 * MyGroupsEndpoint — handles /bcc/v1/me/groups for plain (non-gated,
 * non-Local) user/system PeepSo groups.
 *
 *   POST /me/groups/{id}/join     — join an open group
 *   POST /me/groups/{id}/leave    — leave any group I'm a member of
 *
 * Holder groups use /me/holder-groups; Locals use /me/locals — both
 * have their own gate/policy. This endpoint is for the residual case:
 * plain peepso-groups (created by users via PeepSo's UI) where the
 * frontend wants a uniform action URL on the profile Groups tab.
 *
 * Closed and secret groups are rejected with `bcc_permission_denied`
 * + a hint pointing to PeepSo's group page (where the request flow
 * with admin approval / invitation lives). We don't replicate
 * PeepSo's pending_admin / invitation machinery here.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 (Profile Groups tab)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyGroupsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/groups/(?P<id>\d+)/join',
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
            '/me/groups/(?P<id>\d+)/leave',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'postLeave'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => ['required' => true, 'sanitize_callback' => 'absint'],
                ],
            ]
        );

        // V1.6 — create a plain peepso-group owned by the viewer.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/groups',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'postCreate'],
                'permission_callback' => '__return_true',
                'args' => [
                    'name' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'description' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'wp_kses_post',
                    ],
                    'privacy' => [
                        'required'          => false,
                        'type'              => 'string',
                        // Four canonical privacy modes:
                        //   open    → anyone can join, public read
                        //   closed  → admin approves each join
                        //   secret  → invite-only; hidden from discovery
                        //   trust   → join gated on viewer's reputation
                        //             score ≥ trust_min (separate field)
                        'enum'              => ['open', 'closed', 'secret', 'trust'],
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    // Required only when privacy=trust. One of the
                    // canonical tier values (25/50/75) — server rejects
                    // anything else so a client can't smuggle in an
                    // arbitrary threshold that trivializes or breaks
                    // the gate.
                    'trust_min' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'enum'              => [25, 50, 75],
                        'sanitize_callback' => 'absint',
                    ],
                    // Required chain-tag slug. Locks the new group to
                    // one chain — immutable after creation per the
                    // create-flow contract. Validated against active
                    // chains so a typo can't smuggle in junk.
                    'chain' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function postJoin(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Per-user rate limit on plain group joins. Lower than the holder
        // group bucket since this surface doesn't gate behind an NFT check
        // and a flood could create more peepso_group_user rows faster.
        if (!\BCC\Core\Security\Throttle::allow('group_join:' . $userId, 10, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $groupId = (int) $request->get_param('id');
        $context = Plugin::instance()->groupContextResolver()->forGroup($groupId);
        if ($context === null) {
            return ApiResponse::error('bcc_invalid_request', 'Group not found.', 404);
        }

        // Holder groups + Locals route through their own endpoints; reject
        // here so the frontend doesn't accidentally call the wrong path.
        if ($context->type === GroupType::Nft || $context->type === GroupType::Local) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'This community has its own join endpoint. Use /me/holder-groups or /me/locals.',
                400
            );
        }

        // Trust gate — checked BEFORE the privacy reject so trust-gated
        // groups (which use PeepSo's open privacy under the hood) don't
        // fall through to the privacy=closed branch. The meta value is
        // the canonical threshold (25/50/75); reputation is fetched
        // via the same ReputationRepository the rest of the trust engine
        // already uses so there's a single source of truth for the score.
        $trustMin = (int) get_post_meta($groupId, '_bcc_trust_gate_min', true);
        if ($trustMin > 0) {
            $viewerScore = (int) round(
                Plugin::instance()->reputationRepository()->getScore($userId)
            );
            if ($viewerScore < $trustMin) {
                return ApiResponse::error(
                    'bcc_permission_denied',
                    sprintf(
                        'Earn a reputation score of %d to join this community. You\'re at %d.',
                        $trustMin,
                        $viewerScore
                    ),
                    403
                );
            }
            // Score passes — fall through to the PeepSoGroupWriter::join
            // call below. PeepSo privacy for trust groups is `open` per
            // the create-flow mapping, so the privacy rejects below are
            // a no-op for this path.
        }

        if ($context->privacy === PeepSoPrivacy::Closed) {
            return ApiResponse::error(
                'bcc_permission_denied',
                'This community requires admin approval. Visit the group page to request access.',
                403
            );
        }
        if ($context->privacy === PeepSoPrivacy::Secret) {
            return ApiResponse::error(
                'bcc_permission_denied',
                'This community is invite-only.',
                403
            );
        }

        // PeepSoGroupWriter::join returns true for both INSERT-happened and
        // already-a-member-no-op cases (its contract documents this at
        // bcc-core/src/PeepSo/PeepSoGroupWriter.php:46-50). Determine the
        // actual state transition BEFORE the writer call so the audit log
        // captures only real joins, not idempotent re-attempts.
        $wasAlreadyMember = \BCC\Core\Repositories\PeepSoGroupRepository::getMembershipStatus(
            $userId,
            $groupId
        ) !== null;

        \BCC\Core\PeepSo\PeepSoGroupWriter::join($userId, $groupId);

        // Audit only on the non-member → member transition. Disambiguated
        // from holder_group_join (Onchain/REST/HolderGroupsEndpoint) so
        // admin queries can segment NFT-gated joins from plain peepso-group
        // joins. Privacy = open was already enforced above; closed/secret
        // were rejected before reaching the writer.
        if (!$wasAlreadyMember) {
            AuditLogger::log('group_join', $groupId, [
                'group_type' => $context->type->value,
                'privacy'    => $context->privacy->value,
            ], 'group', $userId);
        }

        return ApiResponse::ok([
            'joined'   => true,
            'group_id' => $groupId,
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function postLeave(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('group_leave:' . $userId, 10, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $groupId = (int) $request->get_param('id');
        $context = Plugin::instance()->groupContextResolver()->forGroup($groupId);
        if ($context === null) {
            return ApiResponse::error('bcc_invalid_request', 'Group not found.', 404);
        }

        // Holder groups need to record an opt-out; Locals have their own
        // leave behavior. Route through this endpoint only for plain user/system groups.
        if ($context->type === GroupType::Nft || $context->type === GroupType::Local) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'This community has its own leave endpoint. Use /me/holder-groups or /me/locals.',
                400
            );
        }

        // PeepSoGroupWriter::leave returns true for both real-deletion and
        // no-existing-row-to-delete cases. Determine the actual state
        // transition BEFORE the writer call so the audit log captures only
        // real leaves, not idempotent re-attempts on a non-membership.
        $wasMember = \BCC\Core\Repositories\PeepSoGroupRepository::getMembershipStatus(
            $userId,
            $groupId
        ) !== null;

        $left = \BCC\Core\PeepSo\PeepSoGroupWriter::leave($userId, $groupId);
        if (!$left) {
            return ApiResponse::error(
                'bcc_permission_denied',
                'Owners cannot leave their own community. Hand off ownership or delete the group first.',
                403
            );
        }

        // Audit only on the member → non-member transition. Disambiguated
        // from holder_group_leave — plain group leaves do not record an
        // opt-out (no 90-day cooldown), so they're a distinct admin signal.
        if ($wasMember) {
            AuditLogger::log('group_leave', $groupId, [
                'group_type' => $context->type->value,
                'privacy'    => $context->privacy->value,
            ], 'group', $userId);
        }

        return ApiResponse::ok([
            'left'     => true,
            'group_id' => $groupId,
        ]);
    }

    /**
     * POST /me/groups — create a plain peepso-group owned by the viewer.
     *
     * V1: name + optional description + privacy=open|closed only.
     * Secret groups are excluded from the open-create surface (they'd
     * never appear in /communities discovery — the user creating one
     * via this endpoint would lose their own group). Holder + Locals
     * have separate create paths (admin-driven for holder groups via
     * GatedGroupProvisioningService; Locals are infra-managed).
     *
     * Rate-limited (5 per hour per user) to prevent abuse — group
     * creation is a heavier write than join/leave (wp_posts + many
     * meta rows + member_join + activity hook).
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function postCreate(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('group_create:' . $userId, 5, 3600)) {
            return ApiResponse::error(
                'bcc_rate_limited',
                'You\'ve created enough communities for now. Try again in an hour.',
                429
            );
        }

        $name         = trim((string) $request->get_param('name'));
        $description  = trim((string) $request->get_param('description'));
        $privacyRaw   = (string) ($request->get_param('privacy') ?: 'open');
        $trustMinRaw  = (int) $request->get_param('trust_min');
        $chainSlug    = (string) $request->get_param('chain');

        if ($name === '' || strlen($name) < 3) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Community name must be at least 3 characters.',
                400
            );
        }
        if (mb_strlen($name) > 100) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Community name must be 100 characters or fewer.',
                400
            );
        }
        if (mb_strlen($description) > 2000) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Description must be 2000 characters or fewer.',
                400
            );
        }

        // Chain tag — required, immutable. Resolve slug → id at the
        // boundary so the writer always receives the canonical id and
        // a stale slug gets rejected with a clear 400 instead of being
        // silently dropped.
        if ($chainSlug === '') {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Pick a chain tag for your community. This locks at creation and can\'t be changed later.',
                400
            );
        }
        $chain = \BCC\Core\ServiceLocator::resolveChainRead()->getBySlug($chainSlug);
        if ($chain === null) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Unknown chain tag. Pick a chain from the list.',
                400
            );
        }
        $chainTagId = (int) $chain->id;

        // Map BCC's four-option privacy field to PeepSo's three native
        // privacy values + the optional BCC trust-gate meta:
        //   open    → privacy=0,     no trust gate
        //   closed  → privacy=1,     no trust gate (admin approval flow)
        //   secret  → privacy=2,     no trust gate (invite-only, hidden)
        //   trust   → privacy=0,     trust_gate_min=<25|50|75>
        //
        // "trust" intentionally uses PeepSo's open privacy because the
        // gate is at JOIN time (BCC's reputation check), not at READ
        // time. Non-members can still read trust-gated group feeds —
        // joining + posting is what's gated. If the product later wants
        // gated reading, we'd flip this to privacy=1 + adjust the join
        // handler to bypass PeepSo's pending_admin status.
        $trustMin = 0;
        switch ($privacyRaw) {
            case 'closed':
                $privacyInt = 1;
                break;
            case 'secret':
                $privacyInt = 2;
                break;
            case 'trust':
                if (!in_array($trustMinRaw, [25, 50, 75], true)) {
                    return ApiResponse::error(
                        'bcc_invalid_request',
                        'Trust-gated communities need a threshold of 25, 50, or 75.',
                        400
                    );
                }
                $privacyInt = 0;
                $trustMin   = $trustMinRaw;
                break;
            case 'open':
            default:
                $privacyInt = 0;
                break;
        }

        $groupId = \BCC\Core\PeepSo\PeepSoGroupWriter::createPlainGroup(
            $userId,
            $name,
            $description,
            $privacyInt,
            $chainTagId,
            $trustMin
        );
        if ($groupId === 0) {
            return ApiResponse::error(
                'bcc_internal_error',
                'Could not create the community. Try again in a moment.',
                500
            );
        }

        AuditLogger::log('group_create', $groupId, [
            'privacy'   => $privacyRaw,
            'chain_tag' => $chain->slug,
            'trust_min' => $trustMin > 0 ? $trustMin : null,
        ], 'group', $userId);

        $post = get_post($groupId);
        $slug = $post instanceof \WP_Post ? (string) $post->post_name : '';

        $response = ApiResponse::ok([
            'group_id'  => $groupId,
            'slug'      => $slug,
            'name'      => $name,
            'privacy'   => $privacyRaw,
            'chain_tag' => $chain->slug,
            // Echo trust_min when set so the client can show a
            // confirmation toast ("Trust 50+ group created"); null
            // for the other three privacy modes.
            'trust_min' => $trustMin > 0 ? $trustMin : null,
        ]);
        $response->set_status(201);
        return $response;
    }
}
