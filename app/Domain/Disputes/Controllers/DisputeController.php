<?php

namespace BCC\Trust\Disputes\Controllers;

use BCC\Core\Contracts\TrustReadServiceInterface;
use BCC\Core\Log\Logger as CoreLogger;
use BCC\Core\Permissions\Permissions;
use BCC\Core\ServiceLocator;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Disputes\DisputesPlugin;
use BCC\Trust\Disputes\DTO\DisputeDetailDTO;
use BCC\Trust\Disputes\DTO\VoteContextDTO;
use BCC\Trust\Disputes\Repositories\DisputeAdminRepository;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use BCC\Trust\Disputes\Repositories\UserReportRepository;
use BCC\Trust\Disputes\Services\DisputeNotificationService;
use BCC\Trust\Disputes\Services\DisputeVoteException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

class DisputeController
{
    const NS = 'bcc/v1';

    public function register_routes(): void
    {
        // Submit a dispute (page owner)
        register_rest_route(self::NS, '/disputes', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'submit'],
            'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(null, false); },
            'args'                => [
                'vote_id'      => ['required' => true,  'type' => 'integer', 'minimum' => 1],
                'reason'       => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field', 'minLength' => BCC_DISPUTES_MIN_REASON_LENGTH, 'maxLength' => BCC_DISPUTES_MAX_REASON_LENGTH,
                                   'validate_callback' => function ($value) { return strlen(trim($value)) >= BCC_DISPUTES_MIN_REASON_LENGTH ? true : new \WP_Error('too_short', 'Reason must be at least ' . BCC_DISPUTES_MIN_REASON_LENGTH . ' non-whitespace characters.'); }],
                'evidence_url' => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'esc_url_raw', 'maxLength' => 2083],
            ],
        ]);

        // List votes for a page (so owner can pick which one to dispute)
        register_rest_route(self::NS, '/disputes/votes/(?P<page_id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'list_votes'],
            'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(null, false); },
        ]);

        // Page owner's disputes
        register_rest_route(self::NS, '/disputes/mine', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'mine'],
            'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(null, false); },
        ]);

        // Community dispute vote (Rank Phase 6 — rides the poll engine).
        // POST casts or (when an active ballot exists) changes; DELETE
        // withdraws; GET returns the viewer's C10-safe vote state.
        register_rest_route(self::NS, '/disputes/(?P<id>\d+)/vote', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'cast_dispute_vote'],
                'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(null, false); },
                'args'                => [
                    'choice' => ['required' => true, 'type' => 'string', 'enum' => ['uphold', 'reject']],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'withdraw_dispute_vote'],
                'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(null, false); },
            ],
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_dispute_vote'],
                'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(null, false); },
            ],
        ]);

        // Report a user
        register_rest_route(self::NS, '/report-user', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'report_user'],
            'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(null, false); },
            'args'                => [
                'reported_user_id' => ['required' => true,  'type' => 'integer', 'minimum' => 1],
                'reason_key'       => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_key',
                                       'enum'     => ['spam','harassment','fraud','misinformation','inappropriate','impersonation','other']],
                'reason_detail'    => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field', 'maxLength' => 1000,
                                       'default'  => ''],
            ],
        ]);

        // Admin force-resolve
        register_rest_route(self::NS, '/disputes/(?P<id>\d+)/resolve', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'force_resolve'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'args'                => [
                'decision' => ['required' => true, 'type' => 'string', 'enum' => ['accepted', 'rejected']],
            ],
        ]);

        // Operational health endpoint (admin only).
        register_rest_route(self::NS, '/disputes/health', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'health'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
    }

    // ── Submit dispute ────────────────────────────────────────────────────────

    public function submit(WP_REST_Request $req): WP_REST_Response
    {
        $current_user_id = get_current_user_id();

        // Hard-fail submission when the DB-level UNIQUE constraint on
        // active disputes is missing. Without `uq_active_vote`, the
        // only duplicate-dispute protection is the app-layer FOR UPDATE
        // inside createDispute — which relies on gap locks that
        // can be bypassed if two concurrent writers race through an
        // empty index range. Rather than silently accept a second
        // dispute on the same vote, return 503 until an operator runs
        // DisputeRepository::ensureActiveVoteConstraint() successfully.
        if (get_option('bcc_disputes_constraint_missing')) {
            return $this->error(
                'dispute_subsystem_unhealthy',
                'Dispute submission is temporarily unavailable (DB constraint missing). An administrator has been notified.',
                503
            );
        }

        $throttled = $this->throttle('dispute_submit', $current_user_id, 60);
        if ($throttled) return $throttled;

        $vote_id         = (int) $req->get_param('vote_id');
        $reason          = $req->get_param('reason');
        $evidence_url    = $req->get_param('evidence_url') ?? '';

        // Verify vote exists
        $vote = $this->getVote($vote_id);
        if (!$vote) {
            return $this->error('vote_not_found', 'Vote not found.', 404);
        }

        $page_id  = $vote->page_id;
        $voter_id = $vote->voter_user_id;

        // Only page owner can dispute
        $ownsPage = Permissions::owns_page($page_id, $current_user_id);
        // Rank Phase 3 shadow canary — log-only, never changes the verdict.
        \BCC\Trust\Core\Services\Capability\CapabilityShadow::observe(
            'open_dispute',
            $ownsPage,
            (int) $current_user_id,
            ['page_id' => (int) $page_id]
        );
        if (!$ownsPage) {
            return $this->error('not_page_owner', 'Only the page owner can dispute votes.', 403);
        }

        // Can't dispute your own vote (shouldn't happen but guard it)
        if ($voter_id === $current_user_id) {
            return $this->error('cannot_self_dispute', 'You cannot dispute your own vote.', 400);
        }

        // Only downvotes can be disputed — upvotes benefit the page owner,
        // so disputing one is either an error or an attempt to weaponize
        // the fraud penalty against the voter.
        if ($vote->vote_type > 0) {
            return $this->error('upvote_not_disputable', 'Only downvotes can be disputed.', 400);
        }

        // One active dispute per vote
        if (DisputeRepository::hasActiveDisputeForVote($vote_id)) {
            return $this->error('already_disputed', 'This vote already has an active dispute.', 409);
        }

        // Insert dispute atomically (includes FOR UPDATE limit checks + the
        // vote-row lock).
        $result = DisputeRepository::createDispute([
            'vote_id'      => $vote_id,
            'page_id'      => $page_id,
            'reporter_id'  => $current_user_id,
            'voter_id'     => $voter_id,
            'reason'       => $reason,
            'evidence_url' => $evidence_url,
            'status'       => 'reviewing',
        ]);

        $dispute_id = $result['id'];

        if (!$dispute_id) {
            // Atomic limit check inside transaction returned this error
            if ($result['db_error'] === 'dispute_limit_reached') {
                return $this->error('dispute_limit_reached', 'This page has reached its dispute limit. Please try again later.', 429);
            }
            if ($result['db_error'] === 'reporter_limit_reached') {
                return $this->error('reporter_limit_reached', 'You have too many active disputes. Please wait for existing disputes to resolve.', 429);
            }
            if ($result['db_error'] === 'vote_no_longer_active') {
                return $this->error('vote_no_longer_active', 'This vote is no longer active and cannot be disputed.', 410);
            }
            if ($result['db_error'] === 'already_disputed') {
                return $this->error('already_disputed', 'This vote already has an active dispute.', 409);
            }
            if ($result['db_error'] === 'commit_failed' || $result['db_error'] === 'tx_begin_failed') {
                // Transient DB failure (commit-time deadlock, connection reset,
                // failover mid-transaction). Surface as 503 so clients retry
                // rather than treating the create as a hard failure.
                return $this->error('db_transient', 'Dispute submission temporarily unavailable — please try again.', 503);
            }
            return $this->error('db_error', 'Failed to create dispute.', 500);
        }

        // Open the community-vote poll — the seam where panel assignment
        // used to trigger. Never throws; a 0 return is self-healed by the
        // daily backstop sweep.
        DisputesPlugin::instance()->disputeVoteService()->openPollForDispute($dispute_id);

        // Notify the disputed voter (a dispute party) that a community
        // vote opened over their vote. Queued async — never block the
        // REST response with SMTP.
        $notified = false;
        try {
            $notified = DisputeNotificationService::enqueueAsync(
                'bcc_disputes_email_voter_opened',
                [$dispute_id, $voter_id]
            );
        } catch (\Throwable $e) {
            CoreLogger::error('[bcc-disputes] voter_opened_enqueue_failed', [
                'dispute_id' => $dispute_id,
                'voter_id'   => $voter_id,
                'error'      => $e->getMessage(),
            ]);
        }
        if (!$notified) {
            CoreLogger::error('[bcc-disputes] voter_opened_enqueue_soft_failed', [
                'dispute_id' => $dispute_id,
                'voter_id'   => $voter_id,
            ]);
        }

        CoreLogger::audit('dispute_submitted', ['dispute_id' => $dispute_id, 'user_id' => $current_user_id, 'vote_id' => $vote_id]);

        // DB audit trail (separate from the bcc-core filesystem Logger above).
        // The filesystem log is for ops grep; the DB row drives admin queries
        // and incident review. We persist both so neither path is single-point.
        AuditLogger::log('dispute_submitted', $dispute_id, [
            'vote_id' => $vote_id,
            'page_id' => $page_id,
        ], 'dispute', $current_user_id);

        return ApiResponse::ok([
            'dispute_id' => $dispute_id,
            'message'    => 'Dispute submitted. The community vote is now open.',
        ]);
    }

    // ── List votes for a page ─────────────────────────────────────────────────

    public function list_votes(WP_REST_Request $req): WP_REST_Response
    {
        $page_id         = (int) $req->get_param('page_id');
        $current_user_id = get_current_user_id();

        if (!Permissions::owns_page($page_id, $current_user_id) && !current_user_can('manage_options')) {
            return $this->error('forbidden', 'Access denied.', 403);
        }

        if (!ServiceLocator::hasRealService(TrustReadServiceInterface::class)) {
            CoreLogger::error('[bcc-disputes] ' .'trust_read_service_missing', [
                'page_id' => $page_id,
                'operation' => 'list_votes',
            ]);

            return $this->error('trust_service_unavailable', 'Trust service unavailable.', 503);
        }

        $service  = ServiceLocator::resolveTrustReadService();
        $page     = max(1, (int) $req->get_param('page'));
        $per_page = min(100, max(1, (int) ($req->get_param('per_page') ?: 50)));
        $offset   = ($page - 1) * $per_page;

        // Pagination pushed into the DB query — only the requested page is fetched.
        $total = $service->countActiveVotesForPage($page_id);
        $votes = $service->getActiveVotesForPage($page_id, $per_page, $offset);

        $voteIds = array_map(static fn(array $vote): int => (int) $vote['id'], $votes);
        $disputedVoteIds = DisputeRepository::getDisputedVoteIds($voteIds);

        $response = ApiResponse::ok(array_map(function (array $vote) use ($disputedVoteIds) {
            return [
                'id' => (int) $vote['id'],
                'voter_name' => $vote['voter_name'] ?? 'Unknown',
                'vote_type' => (int) $vote['vote_type'] > 0 ? 'upvote' : 'downvote',
                'weight' => round((float) $vote['weight'], 2),
                'reason' => $vote['reason'] ?? '',
                'date' => $vote['created_at'] ?? null,
                'already_disputed' => isset($disputedVoteIds[(int) $vote['id']]),
            ];
        }, $votes));
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $per_page)));
        return $response;
    }

    // ── My disputes ───────────────────────────────────────────────────────────

    public function mine(WP_REST_Request $req): WP_REST_Response
    {
        $userId   = get_current_user_id();
        $page     = max(1, (int) $req->get_param('page'));
        $per_page = min(100, max(1, (int) ($req->get_param('per_page') ?: 20)));
        $offset   = ($page - 1) * $per_page;
        $page_id  = $req->get_param('page_id') !== null ? (int) $req->get_param('page_id') : null;

        // When a page_id filter is supplied, the caller is implicitly asking
        // "show me disputes I filed on THIS page" — which only makes sense if
        // they own the page. Without this gate an attacker could probe any
        // page_id to learn whether they have disputes against it. Admins
        // bypass via manage_options.
        if ($page_id !== null && $page_id > 0
            && !Permissions::owns_page($page_id, $userId)
            && !current_user_can('manage_options')
        ) {
            return $this->error('forbidden', 'You do not own this page.', 403);
        }

        $total = DisputeRepository::countByReporter($userId, $page_id);
        $rows  = DisputeRepository::getByReporterPaginated($userId, $per_page, $offset, $page_id);

        $response = ApiResponse::ok(array_map([$this, 'formatDispute'], $rows));
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $per_page)));
        return $response;
    }

    // ── Community dispute vote (poll engine) ──────────────────────────────────

    /**
     * POST /disputes/{id}/vote — cast, or change when an active ballot
     * exists. Eligibility (§18) and the §16.6 weight snapshot live in
     * DisputeVoteService; the engine enforces recast budget + cooldown.
     */
    public function cast_dispute_vote(WP_REST_Request $req): WP_REST_Response
    {
        $dispute_id = (int) $req->get_param('id');
        $choice     = (string) $req->get_param('choice'); // 'uphold' | 'reject'
        $userId     = get_current_user_id();

        $throttled = $this->throttle('dispute_vote', $userId, 10);
        if ($throttled) return $throttled;

        try {
            $state = DisputesPlugin::instance()->disputeVoteService()
                ->castOrChange($dispute_id, $userId, $choice);
        } catch (DisputeVoteException $e) {
            return $this->disputeVoteError($e);
        }

        CoreLogger::audit('dispute_vote_cast', ['dispute_id' => $dispute_id, 'user_id' => $userId, 'choice' => $choice]);
        AuditLogger::log('dispute_vote_cast', $dispute_id, [
            'choice' => $choice,
        ], 'dispute', $userId);

        return $this->noStore(ApiResponse::ok($state));
    }

    /**
     * DELETE /disputes/{id}/vote — withdraw the active ballot (24h
     * cooldown enforced by the engine; re-entry consumes recast budget).
     */
    public function withdraw_dispute_vote(WP_REST_Request $req): WP_REST_Response
    {
        $dispute_id = (int) $req->get_param('id');
        $userId     = get_current_user_id();

        $throttled = $this->throttle('dispute_vote', $userId, 10);
        if ($throttled) return $throttled;

        try {
            $state = DisputesPlugin::instance()->disputeVoteService()
                ->withdraw($dispute_id, $userId);
        } catch (DisputeVoteException $e) {
            return $this->disputeVoteError($e);
        }

        CoreLogger::audit('dispute_vote_withdrawn', ['dispute_id' => $dispute_id, 'user_id' => $userId]);
        AuditLogger::log('dispute_vote_withdrawn', $dispute_id, [], 'dispute', $userId);

        return $this->noStore(ApiResponse::ok($state));
    }

    /**
     * GET /disputes/{id}/vote — the viewer's C10-safe vote state. Open:
     * status + windows + own-ballot facts ONLY (no tallies). Closed:
     * outcome + the counted tally.
     */
    public function get_dispute_vote(WP_REST_Request $req): WP_REST_Response
    {
        $dispute_id = (int) $req->get_param('id');
        $userId     = get_current_user_id();

        try {
            $state = DisputesPlugin::instance()->disputeVoteService()
                ->viewerState($dispute_id, $userId);
        } catch (DisputeVoteException $e) {
            return $this->disputeVoteError($e);
        }

        return $this->noStore(ApiResponse::ok($state));
    }

    /**
     * Map DisputeVoteException kinds onto the `bcc_dispute_vote_*`
     * contract error codes.
     */
    private function disputeVoteError(DisputeVoteException $e): WP_REST_Response
    {
        $resp = match ($e->kind) {
            DisputeVoteException::KIND_FORBIDDEN        => ApiResponse::error('bcc_dispute_vote_forbidden', $e->getMessage(), 403, ['reason' => $e->reason]),
            DisputeVoteException::KIND_RECAST_EXHAUSTED => ApiResponse::error('bcc_dispute_vote_recast_exhausted', $e->getMessage(), 409),
            DisputeVoteException::KIND_COOLDOWN         => ApiResponse::error('bcc_dispute_vote_cooldown', $e->getMessage(), 429),
            DisputeVoteException::KIND_NOT_FOUND        => ApiResponse::error('bcc_dispute_vote_not_found', $e->getMessage(), 404),
            DisputeVoteException::KIND_NO_BALLOT        => ApiResponse::error('bcc_dispute_vote_not_found', $e->getMessage(), 404),
            default                                     => ApiResponse::error('bcc_dispute_vote_closed', $e->getMessage(), 410),
        };
        return $this->noStore($resp);
    }

    /** Authed per-viewer payloads must never be cached by any layer. */
    private function noStore(WP_REST_Response $resp): WP_REST_Response
    {
        $resp->header('Cache-Control', 'private, no-store');
        return $resp;
    }

    // ── Admin force-resolve ───────────────────────────────────────────────────

    public function force_resolve(WP_REST_Request $req): WP_REST_Response
    {
        $dispute_id = (int) $req->get_param('id');
        $decision   = $req->get_param('decision'); // 'accepted' | 'rejected'

        if (!in_array($decision, ['accepted', 'rejected'], true)) {
            return $this->error('invalid_decision', 'Decision must be accepted or rejected.', 400);
        }

        $dispute = DisputeRepository::getDisputeById($dispute_id);

        if (!$dispute) {
            return $this->error('not_found', 'Dispute not found.', 404);
        }

        if ($dispute->status !== 'reviewing') {
            return $this->error('already_resolved', 'This dispute has already been resolved.', 409);
        }

        $adminId = get_current_user_id();

        // Enqueue async resolution instead of running the adjudicator call
        // inline. Admin dashboards previously blocked on trust-engine latency
        // because handle() ran the adjudicator synchronously. The async handler
        // (handleAsyncResolve → DisputeResolver::handle) remains
        // idempotent via beginResolveTransaction's WHERE status='reviewing',
        // so a concurrent poll-close enqueue does not cause double-
        // adjudication — whichever async job runs first flips the status;
        // the second sees 0 rows affected and returns race=true.
        //
        // If the poll already closed and claimed the enqueue slot, the
        // admin request cannot claim and returns 409 — the dispute will
        // resolve on the community vote's terms via the already-queued
        // job. Admin can refresh to see the final state.
        if (!DisputeRepository::claimResolutionEnqueue($dispute_id)) {
            return $this->error(
                'resolution_in_progress',
                'A resolution is already queued for this dispute (community vote or a prior admin action). Refresh to see the final status.',
                409
            );
        }

        $enqueued = false;
        try {
            $enqueued = DisputeNotificationService::enqueueAsync(
                'bcc_disputes_async_resolve',
                [
                    $dispute_id,
                    (int) $dispute->vote_id,
                    (int) $dispute->page_id,
                    (int) $dispute->voter_id,
                    (int) $dispute->reporter_id,
                    $decision,
                ]
            );
        } catch (\Throwable $e) {
            CoreLogger::error('[bcc-disputes] force_resolve_enqueue_exception', [
                'dispute_id' => $dispute_id,
                'admin_id'   => $adminId,
                'decision'   => $decision,
                'error'      => $e->getMessage(),
            ]);
        }

        if (!$enqueued) {
            // resolution_enqueued_at was claimed but no job was queued. Release
            // the claim so a retry can re-claim — otherwise the claim gate 409s
            // every retry and reconciliation won't unstick it (majority not
            // met), silently deferring the admin's decision by up to the full
            // dispute TTL. We own the claim we just won, so releasing it is
            // safe. [audit L-B5]
            DisputeRepository::releaseResolutionEnqueue($dispute_id);
            CoreLogger::error('[bcc-disputes] force_resolve_enqueue_soft_failed', [
                'dispute_id' => $dispute_id,
                'admin_id'   => $adminId,
                'decision'   => $decision,
            ]);
            return $this->error(
                'enqueue_failed',
                'Resolution could not be queued (Action Scheduler unavailable). Please retry in a moment.',
                503
            );
        }

        CoreLogger::audit('dispute_force_resolve_queued', [
            'dispute_id' => $dispute_id,
            'admin_id'   => $adminId,
            'decision'   => $decision,
        ]);

        return ApiResponse::ok([
            'message' => 'Resolution queued as ' . $decision . '. The dispute will update shortly.',
            'status'  => 'queued',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getVote(int $vote_id): ?VoteContextDTO
    {
        if (!ServiceLocator::hasRealService(TrustReadServiceInterface::class)) {
            CoreLogger::error('[bcc-disputes] ' .'trust_read_service_missing', [
                'vote_id' => $vote_id,
            ]);
            return null;
        }

        $service = ServiceLocator::resolveTrustReadService();
        $vote = $service->getVoteById($vote_id);

        if (!is_array($vote)) {
            return null;
        }

        // Strict validation at the trust-engine boundary: page ownership and
        // vote-direction checks drive dispute eligibility, so a malformed or
        // stale contract response must fail fast rather than tripping a guard
        // on coerced zero values.
        foreach (['page_id', 'voter_user_id', 'vote_type'] as $required) {
            if (!array_key_exists($required, $vote)) {
                throw new \LogicException(
                    "TrustReadService::getVoteById({$vote_id}) missing required key: {$required}"
                );
            }
        }
        if (!is_numeric($vote['page_id']) || !is_numeric($vote['voter_user_id']) || !is_numeric($vote['vote_type'])) {
            throw new \LogicException(
                "TrustReadService::getVoteById({$vote_id}) returned non-numeric id or type"
            );
        }

        return new VoteContextDTO(
            page_id:       (int) $vote['page_id'],
            voter_user_id: (int) $vote['voter_user_id'],
            vote_type:     (int) $vote['vote_type'],
        );
    }

    /**
     * Reporter-view dispute row. C10: NO tallies here in any state —
     * while the community vote is open nothing may expose running
     * totals, and the closed tally is served exclusively by
     * GET /disputes/{id}/vote (outcome is carried by `status`).
     *
     * @return array<string, mixed>
     */
    private function formatDispute(DisputeDetailDTO $d): array
    {
        return [
            'id'            => $d->id,
            'vote_id'       => $d->vote_id,
            'page_id'       => $d->page_id,
            'page_title'    => $d->page_title ?? '',
            'voter_name'    => $d->voter_name ?? 'Unknown',
            'reporter_name' => $d->reporter_name,
            'reason'        => $d->reason,
            'evidence_url'  => $d->evidence_url ?? '',
            'status'        => $d->status,
            'created_at'    => $d->created_at,
            'resolved_at'   => $d->resolved_at,
        ];
    }

    // ── Report user ───────────────────────────────────────────────────────────

    public function report_user( WP_REST_Request $req ): WP_REST_Response
    {
        $reporter_id      = get_current_user_id();

        $throttled = $this->throttle('report_user', $reporter_id, 60);
        if ($throttled) return $throttled;

        $reported_id      = (int) $req->get_param('reported_user_id');
        $reason_key       = $req->get_param('reason_key');
        $reason_detail    = (string) $req->get_param('reason_detail');

        if ( $reported_id === $reporter_id ) {
            return $this->error('cannot_self_report', 'You cannot report yourself.', 400);
        }

        $reported_user = get_userdata( $reported_id );
        if ( ! $reported_user ) {
            return $this->error('user_not_found', 'User not found.', 404);
        }

        if ( $reason_key === 'other' && strlen( $reason_detail ) < BCC_DISPUTES_MIN_DETAIL_LENGTH ) {
            return $this->error('detail_required', 'Please provide at least ' . BCC_DISPUTES_MIN_DETAIL_LENGTH . ' characters describing your reason.', 400);
        }

        if ( UserReportRepository::countRecentReportsByReporter($reporter_id) >= 5 ) {
            return $this->error('report_limit_reached', 'You have reached the daily report limit. Please try again later.', 429);
        }

        if ( UserReportRepository::hasActiveReport($reporter_id, $reported_id) ) {
            return $this->error('already_reported', 'You have already submitted an active report against this user.', 409);
        }

        // Protect targets from coordinated report campaigns.
        if ( UserReportRepository::countActiveReportsAgainst($reported_id) >= 10 ) {
            return $this->error('target_report_limit', 'This user already has reports pending review.', 429);
        }

        $report_id = UserReportRepository::createReport($reported_id, $reporter_id, $reason_key, $reason_detail);
        if ( ! $report_id ) {
            return $this->error('db_error', 'Failed to submit report.', 500);
        }

        // Emails queued async — never block the REST response with SMTP.
        // Report row is already committed; isolate enqueue failures so the
        // REST response stays 200 and notified_at / admin transient lock
        // remain NULL for a later retry.
        $reportedOk = false;
        try {
            $reportedOk = DisputeNotificationService::enqueueAsync(
                'bcc_disputes_email_reported_user',
                [$report_id, $reported_id]
            );
        } catch (\Throwable $e) {
            CoreLogger::error('[bcc-disputes] reported_user_enqueue_failed', [
                'report_id' => $report_id,
                'error'     => $e->getMessage(),
            ]);
        }
        if (!$reportedOk) {
            CoreLogger::error('[bcc-disputes] reported_user_enqueue_soft_failed', [
                'report_id' => $report_id,
            ]);
        }

        $adminOk = false;
        try {
            $adminOk = DisputeNotificationService::enqueueAsync(
                'bcc_disputes_email_admin_report',
                [$report_id, $reporter_id, $reported_id, $reason_key, $reason_detail]
            );
        } catch (\Throwable $e) {
            CoreLogger::error('[bcc-disputes] admin_report_enqueue_failed', [
                'report_id' => $report_id,
                'error'     => $e->getMessage(),
            ]);
        }
        if (!$adminOk) {
            CoreLogger::error('[bcc-disputes] admin_report_enqueue_soft_failed', [
                'report_id' => $report_id,
            ]);
        }

        CoreLogger::audit('user_reported', ['reporter' => $reporter_id, 'reported' => $reported_id, 'reason' => $reason_key]);

        return ApiResponse::ok([
            'message' => 'Your report has been submitted. Our team will review it shortly.',
        ]);
    }

    /**
     * Throttle an action per user.
     *
     * Uses trust-engine's atomic RateLimiter when available (gains trust-tier
     * awareness and Cloudflare-aware IP resolution). Falls back to simple
     * transient-based throttle when trust-engine is inactive.
     *
     * @return WP_REST_Response|null  Error response if throttled, null if allowed.
     */
    private function throttle(string $action, int $user_id, int $cooldown_seconds = 60): ?WP_REST_Response
    {
        $key     = "bcc_throttle_{$action}_{$user_id}";
        $allowed = \BCC\Core\Security\Throttle::allow($action, 1, $cooldown_seconds, $key);

        if (!$allowed) {
            return $this->error(
                'rate_limited',
                sprintf('Please wait %d seconds before trying again.', $cooldown_seconds),
                429
            );
        }
        return null;
    }

    // ── Health endpoint ────────────────────────────────────────────────────────

    /**
     * GET /bcc/v1/disputes/health — operational health snapshot (admin only).
     *
     * Reports cron last-run times, queue depths, and service availability
     * so admins can detect stale crons, backlogged queues, or missing
     * trust-engine bindings without SSH access.
     */
    public function health(WP_REST_Request $req): WP_REST_Response
    {
        $now = time();

        // Last auto-resolve run (tracked by DisputeScheduler).
        $lastAutoResolve = (int) get_option('bcc_disputes_auto_resolve_last_run', 0);

        // Count disputes in each status for queue depth.
        $statusCounts = DisputeAdminRepository::getDisputeStatusCounts();

        // Orphaned disputes (committed but adjudication pending/failed).
        $orphanCount = DisputeRepository::countOrphanedDisputes();

        // Trust-engine availability.
        $hasTrustRead = ServiceLocator::hasRealService(TrustReadServiceInterface::class);
        $hasAdjudicator = ServiceLocator::hasRealService(\BCC\Core\Contracts\DisputeAdjudicationInterface::class);

        // Rate-limiter backend readiness — surfaced so operators can see
        // Throttle is fail-closed without needing log access.
        $rateLimiter = \BCC\Core\Security\Throttle::health();

        // Action Scheduler backlog (if available).
        // Use bounded per_page to avoid loading thousands of rows just to count.
        // Both the function AND the store class must be loaded — some AS
        // distributions register the public functions but lazy-load the
        // store class, which would fatal on the constant dereference below.
        $asBacklog = null;
        if (function_exists('as_get_scheduled_actions') && class_exists('\\ActionScheduler_Store')) {
            $maxCheck = 501;
            $pending = as_get_scheduled_actions([
                'group'    => 'bcc-disputes',
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => $maxCheck,
            ], 'ARRAY_A');
            $count = is_array($pending) ? count($pending) : 0;
            $asBacklog = $count >= $maxCheck ? '500+' : $count;
        }

        // WP-Cron status.
        $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $nextAutoResolve = wp_next_scheduled('bcc_disputes_auto_resolve');

        // Throttle degraded state: true when the rate limiter has had to
        // fail-closed on a DB/cache error this request (see Throttle::allow).
        // Flipping to true mid-traffic signals a Redis/options-table issue
        // that would otherwise leak operational fragility silently.
        $throttleDegraded = class_exists('\\BCC\\Core\\Security\\Throttle')
            ? \BCC\Core\Security\Throttle::isDegraded()
            : false;

        // Stuck async resolutions: dispute claimed an enqueue slot but the
        // async handler never advanced the status. Configurable threshold;
        // defaults to 300s (one reconcile cycle).
        $stuckThreshold = defined('BCC_DISPUTES_STUCK_THRESHOLD_SECS')
            ? (int) BCC_DISPUTES_STUCK_THRESHOLD_SECS
            : 300;
        $stuckAsync = DisputeRepository::countStuckAsyncResolutions($stuckThreshold);

        return ApiResponse::ok([
            'status'    => 'ok',
            'timestamp' => gmdate('c'),
            'cron'      => [
                'wp_cron_disabled'         => $cronDisabled,
                'auto_resolve_last_run'    => $lastAutoResolve > 0 ? gmdate('c', $lastAutoResolve) : null,
                'auto_resolve_age_seconds' => $lastAutoResolve > 0 ? $now - $lastAutoResolve : null,
                'next_auto_resolve'        => $nextAutoResolve ? gmdate('c', $nextAutoResolve) : null,
                'action_scheduler_backlog' => $asBacklog,
            ],
            'queues'    => [
                'status_counts' => $statusCounts,
                'orphaned'      => $orphanCount,
            ],
            'services'  => [
                'trust_read_service'    => $hasTrustRead,
                'dispute_adjudicator'   => $hasAdjudicator,
            ],
            'throttle'  => [
                'degraded'           => $throttleDegraded,
                'rate_limiter_ready' => $rateLimiter['rate_limiter_ready'],
                'backend'            => $rateLimiter['backend'],
                // UNIX timestamp of the most recent successful increment
                // (0 = never observed this request-cycle). Pair with
                // rate_limiter_ready to distinguish "backend up and
                // passing traffic" from "backend up but nothing is
                // actually going through".
                'last_success_ts'    => $rateLimiter['last_success_ts'] ?? 0,
            ],
            'mail'      => [
                // Hourly buckets of wp_mail() failures. Missing key → 0, so
                // a quiet hour reads as 0 rather than null. Reads are direct
                // transient lookups (one key each), no full scans.
                'failures_last_hour' => DisputeNotificationService::getMailFailuresLastHour(),
                'failures_prev_hour' => DisputeNotificationService::getMailFailuresPrevHour(),
            ],
            'async'     => [
                // Disputes that claimed an enqueue slot (resolution_enqueued_at
                // set) but never advanced out of 'reviewing' within the
                // threshold. Non-zero means the async handler never ran.
                // Reconciliation Phase A re-enqueues these, but the metric
                // surfaces the backlog immediately.
                'stuck_resolutions'       => $stuckAsync,
                'stuck_threshold_seconds' => $stuckThreshold,
            ],
        ]);
    }

    /**
     * Error responses go through the canonical {error, _meta} envelope so
     * the frontend's `bccFetchAsClient` can parse them uniformly with
     * success responses. The HTTP status mirrors the body's `status` field.
     */
    private function error(string $code, string $message, int $status): WP_REST_Response
    {
        return ApiResponse::error($code, $message, $status);
    }

}
