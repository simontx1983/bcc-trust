<?php

namespace BCC\Trust\Disputes\Controllers;

use BCC\Core\Contracts\TrustReadServiceInterface;
use BCC\Core\Log\Logger as CoreLogger;
use BCC\Core\Permissions\Permissions;
use BCC\Core\Repositories\PeepSoFollowerRepository;
use BCC\Core\ServiceLocator;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Disputes\DTO\DisputeCoreDTO;
use BCC\Trust\Disputes\DTO\DisputeDetailDTO;
use BCC\Trust\Disputes\DTO\PanelistQueueItemDTO;
use BCC\Trust\Disputes\DTO\VoteContextDTO;
use BCC\Trust\Disputes\Repositories\DisputeAdminRepository;
use BCC\Trust\Disputes\Repositories\DisputeParticipationRepository;
use BCC\Trust\Disputes\Repositories\DisputePanelRepository;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use BCC\Trust\Disputes\Repositories\UserReportRepository;
use BCC\Trust\Disputes\Services\DisputeNotificationService;
use BCC\Trust\Disputes\Services\DisputeParticipationService;
use BCC\Trust\Disputes\Services\DisputeScheduler;
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

        // Panelist queue
        register_rest_route(self::NS, '/disputes/panel', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'panel_queue'],
            'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(null, false); },
        ]);

        // Cast panel vote
        register_rest_route(self::NS, '/disputes/(?P<id>\d+)/vote', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'cast_vote'],
            'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(null, false); },
            'args'                => [
                'decision' => ['required' => true, 'type' => 'string', 'enum' => ['accept', 'reject']],
                'note'     => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'maxLength' => 500],
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

        // §D5 — viewer's own participation status (for the /panel header
        // indicator). Auth-only; no admin gate. Returns the three counts
        // a panelist needs to see their progress against the caps.
        register_rest_route(self::NS, '/disputes/participation/me', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'my_participation'],
            'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(null, false); },
        ]);
    }

    // ── Submit dispute ────────────────────────────────────────────────────────

    public function submit(WP_REST_Request $req): WP_REST_Response
    {
        $current_user_id = get_current_user_id();

        // Hard-fail submission when the DB-level UNIQUE constraint on
        // active disputes is missing. Without `uq_active_vote`, the
        // only duplicate-dispute protection is the app-layer FOR UPDATE
        // inside createDisputeWithPanel — which relies on gap locks that
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
        if (!Permissions::owns_page($page_id, $current_user_id)) {
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

        // Insert dispute + panelists atomically (includes FOR UPDATE limit check)
        $panelists = $this->selectPanelists($current_user_id, $voter_id);

        if (count($panelists) < BCC_DISPUTES_PANEL_SIZE) {
            $found = count($panelists);
            return $this->error('insufficient_panelists',
                "Cannot create dispute — only {$found} of " . BCC_DISPUTES_PANEL_SIZE
                . ' qualified panelists are available. Panelists must be Trusted or Elite tier members'
                . ' with clean fraud records. As the community grows, more panelists will become eligible.'
                . ' Your dispute has NOT been filed — please try again later.', 503);
        }

        $result = DisputeRepository::createDisputeWithPanel([
            'vote_id'      => $vote_id,
            'page_id'      => $page_id,
            'reporter_id'  => $current_user_id,
            'voter_id'     => $voter_id,
            'reason'       => $reason,
            'evidence_url' => $evidence_url,
            'status'       => 'reviewing',
            'panel_size'   => BCC_DISPUTES_PANEL_SIZE,
        ], $panelists);

        $dispute_id = $result['id'];

        if (!$dispute_id) {
            // Atomic limit check inside transaction returned this error
            if ($result['db_error'] === 'dispute_limit_reached') {
                return $this->error('dispute_limit_reached', 'This page has reached its dispute limit. Please try again later.', 429);
            }
            if ($result['db_error'] === 'insufficient_panelists') {
                return $this->error('insufficient_panelists',
                    'Cannot create dispute — panelist load caps were exceeded during submission. Please try again later.', 503);
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
            if ($result['failed_panelist'] !== null) {
                CoreLogger::error('[bcc-disputes] ' .'panel_insert_failed', [
                    'panelist_id' => $result['failed_panelist'],
                    'db_error'    => $result['db_error'],
                ]);
                return $this->error('db_error', 'Failed to assign panelists.', 500);
            }
            return $this->error('db_error', 'Failed to create dispute.', 500);
        }

        // Notifications queued async — never block the REST response with SMTP.
        // Dispute is already committed at this point; isolate per-panelist
        // enqueue failures so one bad dispatch doesn't 500 the REST response
        // or skip notifications for the remaining panelists. notified_at is
        // still NULL for skipped rows, so a future reconciliation / admin
        // replay can re-enqueue them.
        foreach ($panelists as $uid) {
            $enqueued = false;
            try {
                $enqueued = DisputeNotificationService::enqueueAsync(
                    'bcc_disputes_notify_panelist',
                    [$uid, $dispute_id, $page_id]
                );
            } catch (\Throwable $e) {
                CoreLogger::error('[bcc-disputes] panelist_enqueue_failed', [
                    'dispute_id'  => $dispute_id,
                    'panelist_id' => $uid,
                    'error'       => $e->getMessage(),
                ]);
            }

            if (!$enqueued) {
                // Soft failure (AS returned 0 / wp_schedule_single_event false).
                // Do NOT set notified_at — the reconciliation sweep in
                // DisputeScheduler::doReconcile will pick up this row (WHERE
                // notified_at IS NULL AND dispute.status='reviewing') on its
                // next tick and re-enqueue.
                CoreLogger::error('[bcc-disputes] panelist_enqueue_soft_failed', [
                    'dispute_id'  => $dispute_id,
                    'panelist_id' => $uid,
                ]);
            }
        }

        CoreLogger::audit('dispute_submitted', ['dispute_id' => $dispute_id, 'user_id' => $current_user_id, 'vote_id' => $vote_id, 'panelists' => count($panelists)]);

        // DB audit trail (separate from the bcc-core filesystem Logger above).
        // The filesystem log is for ops grep; the DB row drives admin queries
        // and incident review. We persist both so neither path is single-point.
        AuditLogger::log('dispute_submitted', $dispute_id, [
            'vote_id'   => $vote_id,
            'page_id'   => $page_id,
            'panelists' => count($panelists),
        ], 'dispute', $current_user_id);

        return ApiResponse::ok([
            'dispute_id' => $dispute_id,
            'panelists'  => count($panelists),
            'message'    => 'Dispute submitted. ' . count($panelists) . ' panelists have been notified.',
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

    // ── Panel queue ───────────────────────────────────────────────────────────

    public function panel_queue(WP_REST_Request $req): WP_REST_Response
    {
        // Safety net: if cron has stopped, resolve severely overdue disputes
        // on-demand so panelists aren't stuck with stale queues forever.
        DisputeScheduler::emergencyResolveIfStale();

        $userId   = get_current_user_id();
        $page     = max(1, (int) $req->get_param('page'));
        $per_page = min(100, max(1, (int) ($req->get_param('per_page') ?: 20)));
        $offset   = ($page - 1) * $per_page;

        $total = DisputePanelRepository::countPanelQueueForUser($userId);
        $rows  = DisputePanelRepository::getPanelQueueForUser($userId, $per_page, $offset);

        $response = ApiResponse::ok(array_map([$this, 'formatDispute'], $rows));
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $per_page)));
        return $response;
    }

    // ── Cast panel vote ───────────────────────────────────────────────────────

    public function cast_vote(WP_REST_Request $req): WP_REST_Response
    {
        $dispute_id = (int) $req->get_param('id');
        $decision   = $req->get_param('decision'); // 'accept' | 'reject'
        $note       = $req->get_param('note') ?? '';
        $userId     = get_current_user_id();

        $throttled = $this->throttle('panel_vote', $userId, 10);
        if ($throttled) return $throttled;

        if (!in_array($decision, ['accept', 'reject'], true)) {
            return $this->error('invalid_decision', 'Decision must be accept or reject.', 400);
        }

        // Confirm this user is assigned to this dispute
        $assignment = DisputePanelRepository::getPanelAssignment($dispute_id, $userId);
        if (!$assignment) {
            return $this->error('not_assigned', 'You are not assigned to this dispute.', 403);
        }
        if ($assignment->decision !== null) {
            return $this->error('already_voted', 'You have already voted on this dispute.', 409);
        }

        $dispute = DisputeRepository::getDisputeById($dispute_id);
        if (!$dispute || $dispute->status !== 'reviewing') {
            return $this->error('dispute_closed', 'This dispute is no longer open.', 410);
        }

        // Atomic transaction: lock → vote → tally → re-read (all in repository).
        /** @var array{status: string, code: string, message: string, http: int, dispute: object|null, accepts: int, rejects: int, step?: string, db_error?: string} $result */
        $result = DisputeRepository::castPanelVoteAtomic($dispute_id, $userId, $decision, $note);

        if ($result['status'] !== 'success') {
            if (isset($result['db_error'])) {
                CoreLogger::error('[bcc-disputes] ' .'cast_vote_rollback', [
                    'dispute_id' => $dispute_id,
                    'user_id'    => $userId,
                    'step'       => $result['step'] ?? 'unknown',
                    'db_error'   => $result['db_error'],
                ]);
            }
            return $this->error($result['code'], $result['message'], $result['http']);
        }

        $accepts = $result['accepts'];
        $rejects = $result['rejects'];
        $dispute = $result['dispute'];

        // Narrow the union: castPanelVoteAtomic success branch guarantees
        // dispute is DisputeCoreDTO, but the shared array shape admits null
        // on error branches. The status check above already returned on error,
        // so this instanceof is a defense-in-depth assertion for the impossible
        // "success with null dispute" case.
        if (!$dispute instanceof DisputeCoreDTO) {
            throw new \LogicException('castPanelVoteAtomic returned success but no dispute DTO');
        }

        $panel_size = $dispute->panel_size;

        // Single source of truth for verdict calculation.
        $verdict = DisputeRepository::computeVerdict($accepts, $rejects, $panel_size);

        CoreLogger::audit('dispute_vote_cast', ['dispute_id' => $dispute_id, 'user_id' => $userId, 'decision' => $decision]);

        // DB audit trail. Decision is captured in meta so admin queries can
        // segment by accept vs reject without joining bcc_dispute_panel.
        // Logged AFTER the atomic vote commit, before the async resolve
        // enqueue, so a logged action always reflects committed state.
        AuditLogger::log('dispute_panel_vote_cast', $dispute_id, [
            'decision' => $decision,
        ], 'dispute', $userId);

        // Resolve asynchronously. Previously the deciding vote paid for
        // DB transaction + adjudicator call + penalty hook + email enqueue
        // inside its HTTP request, which made cast_vote p99 latency
        // unbounded under trust-engine slowness. Now we just enqueue an
        // async resolve and return immediately.
        //
        // Concurrency: multiple panelists can evaluate should_resolve=true
        // when their votes race to the quorum threshold. claimResolutionEnqueue
        // is an atomic UPDATE that flips resolution_enqueued_at from NULL to
        // NOW() only when status='reviewing' AND the column is still NULL —
        // so exactly one voter claims the enqueue slot. The async handler
        // (handleAsyncResolve → DisputeResolver::handle) is separately
        // idempotent via WHERE status='reviewing' in beginResolveTransaction,
        // so a lost/duplicated claim is not a correctness hazard — this
        // guard only prevents wasted Action Scheduler jobs.
        if ($verdict['should_resolve']) {
            $final = $verdict['outcome'];

            if (DisputeRepository::claimResolutionEnqueue($dispute_id)) {
                $enqueued = false;
                try {
                    $enqueued = DisputeNotificationService::enqueueAsync(
                        'bcc_disputes_async_resolve',
                        [
                            $dispute_id,
                            $dispute->vote_id,
                            $dispute->page_id,
                            $dispute->voter_id,
                            $dispute->reporter_id,
                            $final,
                        ]
                    );
                } catch (\Throwable $e) {
                    CoreLogger::error('[bcc-disputes] cast_vote_resolve_enqueue_exception', [
                        'dispute_id' => $dispute_id,
                        'outcome'    => $final,
                        'error'      => $e->getMessage(),
                    ]);
                }

                if (!$enqueued) {
                    // resolution_enqueued_at is now set but no job was queued.
                    // Reconciliation (retryStuckReviewingDisputes PHASE A) will
                    // detect the quorum-reached-but-not-resolved state on its
                    // next 5-minute tick and re-enqueue. System remains
                    // recoverable without operator intervention.
                    CoreLogger::error('[bcc-disputes] cast_vote_resolve_enqueue_soft_failed', [
                        'dispute_id' => $dispute_id,
                        'outcome'    => $final,
                    ]);
                }
            }
        }

        // §D5 — record the panel-vote participation OUTSIDE the vote
        // transaction. The vote is the user's intentional act and is
        // already committed; participation is bookkeeping. A DB hiccup
        // or lock contention while inserting the credit must NOT roll
        // back the vote. We log + continue on any failure here.
        $participationBlock = [
            'credited'         => false,
            'reason'           => 'service_unavailable',
            'credited_today'   => 0,
            'credited_lifetime'=> 0,
        ];
        try {
            $participationService = new DisputeParticipationService(
                new DisputeParticipationRepository()
            );
            $result = $participationService->recordParticipation($userId, $dispute_id, $decision);
            $participationBlock = [
                'credited'          => $result['credited'],
                'reason'            => $result['reason'],
                'credited_today'    => $result['today'],
                'credited_lifetime' => $result['lifetime'],
            ];
        } catch (\Throwable $e) {
            // Log but never propagate — the vote is already committed
            // and that's the user's primary action. Reconciliation can
            // backfill missed credits if it ever becomes necessary.
            CoreLogger::error('[bcc-disputes] participation_record_failed', [
                'dispute_id' => $dispute_id,
                'user_id'    => $userId,
                'decision'   => $decision,
                'error'      => $e->getMessage(),
            ]);
        }

        // Tally intentionally omitted from response to preserve
        // independent deliberation — panelists must not see running
        // totals before all votes are in. The participation block, by
        // contrast, surfaces this user's own credit state only.
        return ApiResponse::ok([
            'message'       => 'Vote recorded.',
            'decision'      => $decision,
            'participation' => $participationBlock,
        ]);
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
        // so a concurrent panel-quorum enqueue does not cause double-
        // adjudication — whichever async job runs first flips the status;
        // the second sees 0 rows affected and returns race=true.
        //
        // If panelists have already committed quorum and claimed the enqueue
        // slot, the admin request cannot claim and returns 409 — the dispute
        // will resolve on the panel's terms via the already-queued job. Admin
        // can refresh to see the final state.
        if (!DisputeRepository::claimResolutionEnqueue($dispute_id)) {
            return $this->error(
                'resolution_in_progress',
                'A resolution is already queued for this dispute (panel quorum or a prior admin action). Refresh to see the final status.',
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
            // resolution_enqueued_at is now set but no job was queued. The
            // reconciliation path (retryStuckReviewingDisputes) will NOT
            // pick this up because majority is not met — so we must surface
            // the failure here. Admin can retry after resolving the AS
            // backlog issue; the claim gate will then trip until the cron
            // reconciliation unsticks the row OR admin manually clears it.
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
     * Pick up to BCC_DISPUTES_PANEL_SIZE Trusted/Elite panelists, with:
     *   1. Tier + fraud + suspension eligibility filter (TrustReadService).
     *   2. Soft-IP-diversity over the eligible pool (TrustReadService).
     *   3. Per-panelist load cap (DisputeRepository).
     *   4. §D5 interest-coupled affinity overlay (scale-hardening 2026-05-13).
     *   5. Explicit outsider quota to preserve "the floor selected qualified
     *      peers" feeling vs "the algorithm picked my tribe."
     *
     * Affinity scoring inputs (Phase 2 MVP — extends as new signals queryable):
     *   - WATCHES THE PAGE OWNER (+2): the panelist follows the disputed
     *     page's owner (=reporter_id; the page owner is the dispute
     *     reporter per the upstream Permissions::owns_page check). A
     *     panelist who watches the page has substantive interest in
     *     the entity's standing and is materially better informed
     *     about its recent activity. Read: PeepSoFollowerRepository.
     *   - RECENT CIVIC ACTIVITY (+2): the panelist has voted in the
     *     last 30 days. Voters paying current attention to the floor
     *     adjudicate better than panelists who have gone quiet.
     *     Read: VoteRepository::countRecentByActor.
     *
     * Affinity inputs deferred (filterable extension points, not yet
     * queryable as cheap reads — schema work needed):
     *   - Chain affinity (+3): requires page→chain resolver.
     *   - Prior panel-vote accuracy (+2): requires
     *     DisputeParticipationRepository aggregator method.
     *   - Category familiarity (+1): requires panelist's own pages by
     *     category — partially derivable but adds 1 query per candidate.
     *
     * Anti-clique enforcement:
     *   `bcc_disputes_outsider_quota_pct` filter (default 0.40 = 40%)
     *   reserves N slots for panelists with affinity ≤ 1. For a 5-person
     *   panel: 2 outsider slots, 3 high-affinity slots. Within each
     *   tier, the candidate order is shuffled so two high-affinity
     *   panelists with the same score have equal probability of
     *   selection — no deterministic "always pick the closest watcher."
     *
     * Why "watches the page owner" instead of "watches the voter":
     *   The voter is the actor being disputed. A panelist who watches
     *   the voter is biased TOWARD the voter's defense. The page owner
     *   is the recipient of the disputed action — a panelist who
     *   watches them is biased toward neither party but has substantive
     *   context. The outsider quota then ensures even high-context
     *   panels include peers without that context — preserving the
     *   "floor of qualified peers" feeling.
     *
     * Backward-compatibility: signature unchanged (reporter_id, voter_id).
     * Existing callers don't have to change. The page_owner_id used for
     * watches-the-owner affinity IS reporter_id by upstream invariant
     * (Permissions::owns_page check at the create-dispute call site).
     *
     * @return int[]
     */
    private function selectPanelists(int $reporter_id, int $voter_id): array
    {
        $needed = BCC_DISPUTES_PANEL_SIZE;

        if (!ServiceLocator::hasRealService(TrustReadServiceInterface::class)) {
            CoreLogger::error('[bcc-disputes] ' .'trust_read_service_missing', [
                'reporter_id' => $reporter_id,
                'voter_id' => $voter_id,
                'operation' => 'select_panelists',
            ]);

            return [];
        }

        $service = ServiceLocator::resolveTrustReadService();
        // Request extra candidates so we can filter out overloaded panelists.
        $candidates = $service->getEligiblePanelistUserIds(
            [$reporter_id, $voter_id],
            $needed * 3
        );

        if (empty($candidates)) {
            return [];
        }

        // Per-panelist load cap: skip panelists already serving on too many
        // active disputes. Prevents reviewer fatigue and ensures review quality.
        // Uses a batch query to avoid N+1 (one query per candidate).
        $maxActivePanels = (int) apply_filters('bcc_disputes_max_active_panels_per_user', 10);

        $loadMap = DisputePanelRepository::batchCountActivePanelAssignments($candidates);

        // Load-filter — collect ALL candidates with load < cap (no truncate
        // here; affinity ranking does the final truncate).
        $availableCandidates = [];
        foreach ($candidates as $uid) {
            if (($loadMap[$uid] ?? 0) < $maxActivePanels) {
                $availableCandidates[] = (int) $uid;
            }
        }

        if (count($availableCandidates) <= $needed) {
            // Insufficient candidates after load filter — return the small
            // pool as-is. The caller's insufficient_panelists check
            // (line 183) will surface a clear error to the reporter.
            return $availableCandidates;
        }

        return $this->rankPanelistsByAffinity($availableCandidates, $reporter_id, $voter_id);
    }

    /**
     * Score candidates by §D5 affinity inputs and apply the outsider
     * quota. See selectPanelists() docblock for design rationale.
     *
     * @param int[] $candidates   Load-filtered pool (Trusted/Elite, low
     *                            fraud, under load cap).
     * @param int   $pageOwnerId  The page owner (== reporter_id by
     *                            upstream Permissions::owns_page invariant).
     * @param int   $voterId      The disputed actor (excluded from
     *                            candidates upstream; passed here for
     *                            audit-log context only).
     * @return int[]              Exactly BCC_DISPUTES_PANEL_SIZE user_ids
     *                            (or fewer if the pool was small).
     */
    private function rankPanelistsByAffinity(array $candidates, int $pageOwnerId, int $voterId): array
    {
        $needed = BCC_DISPUTES_PANEL_SIZE;

        if (count($candidates) <= $needed) {
            return $candidates;
        }

        $voteRepo    = Plugin::instance()->voteRepository();
        $recentDays  = (int) apply_filters('bcc_disputes_recent_activity_days', 30);
        $watcherBoost = 2;
        $recentBoost  = 2;

        // Score every candidate. Affinity is bounded by the input boosts;
        // expansion points (chain match, prior accuracy, category) noted
        // in the parent docblock would add their own bounded boosts.
        $scored = [];
        foreach ($candidates as $uid) {
            $score = 0;

            if (PeepSoFollowerRepository::isFollowing($uid, $pageOwnerId)) {
                $score += $watcherBoost;
            }

            if ($voteRepo->countRecentByActor($uid, $recentDays) > 0) {
                $score += $recentBoost;
            }

            $scored[$uid] = $score;
        }

        // Partition into high-affinity (>= 2) and outsider (<= 1).
        // Threshold is "any single affinity boost is enough to be
        // considered substantively interested." Outsiders are the
        // anti-clique counterweight.
        $high = [];
        $low  = [];
        foreach ($scored as $uid => $score) {
            if ($score >= 2) {
                $high[] = $uid;
            } else {
                $low[] = $uid;
            }
        }

        // Within each tier, shuffle so candidates with the same score
        // have equal probability of selection. Avoids a deterministic
        // "always pick the longest-watcher" bias.
        shuffle($high);
        shuffle($low);

        // Outsider quota: at least N panelists with affinity <= 1.
        // 40% default = 2 of 5; filterable for ops tuning. Capped at
        // $needed - 1 so we always retain at least one high-affinity
        // slot (otherwise affinity scoring would be pointless).
        $outsiderPct   = (float) apply_filters('bcc_disputes_outsider_quota_pct', 0.40);
        $outsiderQuota = max(1, (int) ceil($needed * $outsiderPct));
        $outsiderQuota = min($outsiderQuota, $needed - 1);
        $highQuota     = $needed - $outsiderQuota;

        $selected = array_merge(
            array_slice($high, 0, $highQuota),
            array_slice($low, 0, $outsiderQuota)
        );

        // Backfill: if either pile was undersupplied (rare), draw from
        // the combined remainder. Preserves the panel size when the
        // pool is unbalanced but no quota can be honored.
        if (count($selected) < $needed) {
            $remainder = array_merge(
                array_slice($high, $highQuota),
                array_slice($low, $outsiderQuota)
            );
            foreach ($remainder as $uid) {
                if (count($selected) >= $needed) {
                    break;
                }
                $selected[] = $uid;
            }
        }

        // Final shuffle so the panel order in storage isn't itself a
        // signal (high-affinity-first ordering could leak the affinity
        // signal to anyone reading the raw panel row). shuffle()
        // reindexes the array, so the return value is already a list.
        shuffle($selected);

        return $selected;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDispute(DisputeDetailDTO|PanelistQueueItemDTO $d): array
    {
        $data = [
            'id'            => $d->id,
            'vote_id'       => $d->vote_id,
            'page_id'       => $d->page_id,
            'page_title'    => $d->page_title ?? '',
            'voter_name'    => $d->voter_name ?? 'Unknown',
            'reporter_name' => $d->reporter_name,
            'reason'        => $d->reason,
            'evidence_url'  => $d->evidence_url ?? '',
            'status'        => $d->status,
            'accepts'       => $d->panel_accepts,
            'rejects'       => $d->panel_rejects,
            'panel_size'    => $d->panel_size,
            'my_decision'   => $d instanceof PanelistQueueItemDTO ? $d->my_decision : null,
            'created_at'    => $d->created_at,
            'resolved_at'   => $d->resolved_at,
        ];

        // Hide reporter identity and vote tallies from panelists until
        // ALL panel votes are in. This enforces independent deliberation —
        // even panelists who have already voted must not see running totals
        // to prevent them from sharing tally information with allies.
        //
        // Panelist detection: the presence of `my_decision` on the DTO type
        // (PanelistQueueItemDTO only) signals this is a panelist-view row.
        // Previously used property_exists() on stdClass; now type-discriminated.
        $userId     = get_current_user_id();
        $isPanelist = $d instanceof PanelistQueueItemDTO;
        $isReporter = $d->reporter_id === $userId;
        $isAdmin    = current_user_can('manage_options');
        $totalVoted = $d->panel_accepts + $d->panel_rejects;
        $panelSize  = $d->panel_size;
        // timeout_no_quorum is also a terminal state even though by definition
        // total votes < panel_size — don't leave panelists looking at a
        // stale "reviewing" dispute once it has hit TTL.
        $votingComplete = ($totalVoted >= $panelSize)
            || in_array($d->status, ['accepted', 'rejected', 'timeout_no_quorum'], true);

        if ($isPanelist && !$isReporter && !$isAdmin && !$votingComplete) {
            $data['reporter_name'] = null;
            $data['accepts']       = null;
            $data['rejects']       = null;
            // Mask final outcome to prevent tally inference from status changes.
            if (in_array($data['status'], ['accepted', 'rejected', 'timeout_no_quorum'], true)) {
                $data['status'] = 'closed';
            }
        }

        return $data;
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

    // ── My participation status ────────────────────────────────────────────────

    /**
     * §D5 — return the viewer's own panel-vote participation counters.
     *
     * Powers the `/panel` page's header indicator: "X/5 today · Y/50
     * lifetime · Z correct". Caps come along so the frontend never has
     * to mirror the backend constants.
     */
    public function my_participation(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        $service = new DisputeParticipationService(
            new DisputeParticipationRepository()
        );
        $status = $service->getStatus($userId);

        return ApiResponse::ok([
            // Row-count counters for "X votes today / lifetime" UI.
            'credited_today'    => $status['today'],
            'credited_lifetime' => $status['lifetime'],
            'correct_count'     => $status['correct'],
            // Clamped trust contributions for "Y / Z trust points" UI.
            'earned_today'      => round($status['earned_daily'], 4),
            'earned_lifetime'   => round($status['earned_lifetime'], 4),
            // Caps surfaced so the frontend never mirrors backend constants.
            'caps' => [
                'daily_trust'      => (float) BCC_DISPUTE_PARTICIPATION_DAILY_TRUST_CAP,
                'lifetime_trust'   => (float) BCC_DISPUTE_PARTICIPATION_LIFETIME_TRUST_CAP,
                'min_for_accuracy' => (int)   BCC_DISPUTE_PARTICIPATION_MIN_FOR_ACCURACY,
                'base_weight'      => (float) BCC_DISPUTE_PARTICIPATION_BASE_WEIGHT,
                'accuracy_weight'  => (float) BCC_DISPUTE_PARTICIPATION_ACCURACY_WEIGHT,
            ],
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
