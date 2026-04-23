<?php

namespace BCC\Trust\Core;

use BCC\Trust\Core\Application\Disputes\DisputeAdjudicator;
use BCC\Trust\Core\Application\ScoreContributorService;
use BCC\Trust\Core\Application\ScoreReadService;
use BCC\Trust\Core\Application\TrustReadService;
use BCC\Trust\Core\Controllers\GitHubController;
use BCC\Trust\Core\Controllers\TrustRestController;
use BCC\Trust\Core\Controllers\UserStatusController;
use BCC\Trust\Core\Controllers\XController;
use BCC\Trust\Core\Controllers\AdminStatsController;
use BCC\Trust\Core\Repositories\EdgeRepository;
use BCC\Trust\Core\Repositories\EndorsementRepository;
use BCC\Trust\Core\Repositories\FraudAnalysisRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VerificationRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\REST\DiscoveryEndpoint;
use BCC\Trust\Core\REST\PageEndpoint;
use BCC\Trust\Core\Security\BehavioralAnalyzer;
use BCC\Trust\Core\Security\DeviceFingerprinter;
use BCC\Trust\Core\Security\TrustGraph;
use BCC\Trust\Core\Repositories\QuestProgressRepository;
use BCC\Trust\Core\Services\EndorsementService;
use BCC\Trust\Core\Services\Quest\QuestProgressService;
use BCC\Trust\Core\Services\VerificationService;
use BCC\Trust\Core\Services\VoteService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Singleton service container for the Trust Engine plugin.
 *
 * Provides lazy-initialized singleton accessors for all repositories,
 * services, and security classes.
 */
final class Plugin
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Repositories ────────────────────────────────────────────────────

    private ?VoteRepository $voteRepository = null;
    public function voteRepository(): VoteRepository
    {
        return $this->voteRepository ??= new VoteRepository();
    }

    private ?ScoreRepository $scoreRepository = null;
    public function scoreRepository(): ScoreRepository
    {
        return $this->scoreRepository ??= new ScoreRepository();
    }

    private ?ReputationRepository $reputationRepository = null;
    public function reputationRepository(): ReputationRepository
    {
        return $this->reputationRepository ??= new ReputationRepository();
    }

    private ?UserInfoRepository $userInfoRepository = null;
    public function userInfoRepository(): UserInfoRepository
    {
        return $this->userInfoRepository ??= new UserInfoRepository();
    }

    private ?EndorsementRepository $endorsementRepository = null;
    public function endorsementRepository(): EndorsementRepository
    {
        return $this->endorsementRepository ??= new EndorsementRepository();
    }

    private ?VerificationRepository $verificationRepository = null;
    public function verificationRepository(): VerificationRepository
    {
        return $this->verificationRepository ??= new VerificationRepository();
    }

    private ?FraudAnalysisRepository $fraudAnalysisRepository = null;
    public function fraudAnalysisRepository(): FraudAnalysisRepository
    {
        return $this->fraudAnalysisRepository ??= new FraudAnalysisRepository();
    }

    private ?EdgeRepository $edgeRepository = null;
    public function edgeRepository(): EdgeRepository
    {
        return $this->edgeRepository ??= new EdgeRepository();
    }

    private ?Repositories\FlagsRepository $flagsRepository = null;
    public function flagsRepository(): Repositories\FlagsRepository
    {
        return $this->flagsRepository ??= new Repositories\FlagsRepository();
    }

    private ?Repositories\PageReadModelRepository $pageReadModelRepository = null;
    public function pageReadModelRepository(): Repositories\PageReadModelRepository
    {
        return $this->pageReadModelRepository ??= new Repositories\PageReadModelRepository();
    }

    private ?Repositories\SuspensionRepository $suspensionRepository = null;
    public function suspensionRepository(): Repositories\SuspensionRepository
    {
        return $this->suspensionRepository ??= new Repositories\SuspensionRepository();
    }

    private ?Repositories\PatternRepository $patternRepository = null;
    public function patternRepository(): Repositories\PatternRepository
    {
        return $this->patternRepository ??= new Repositories\PatternRepository();
    }

    private ?Repositories\ReadModelHealthRepository $readModelHealthRepository = null;
    public function readModelHealthRepository(): Repositories\ReadModelHealthRepository
    {
        return $this->readModelHealthRepository ??= new Repositories\ReadModelHealthRepository();
    }

    // ── Security services ───────────────────────────────────────────────

    private ?DeviceFingerprinter $deviceFingerprinter = null;
    public function deviceFingerprinter(): DeviceFingerprinter
    {
        return $this->deviceFingerprinter ??= new DeviceFingerprinter();
    }

    private ?BehavioralAnalyzer $behavioralAnalyzer = null;
    public function behavioralAnalyzer(): BehavioralAnalyzer
    {
        return $this->behavioralAnalyzer ??= new BehavioralAnalyzer();
    }

    private ?TrustGraph $trustGraph = null;
    public function trustGraph(): TrustGraph
    {
        return $this->trustGraph ??= new TrustGraph(
            $this->userInfoRepository(),
            $this->edgeRepository()
        );
    }

    // ── Core services ───────────────────────────────────────────────────

    private ?VoteService $voteService = null;
    public function voteService(): VoteService
    {
        return $this->voteService ??= new VoteService(
            $this->voteRepository(),
            $this->scoreRepository(),
            $this->reputationRepository(),
            $this->userInfoRepository(),
            $this->deviceFingerprinter()
        );
    }

    private ?Services\ReputationCalculatorService $reputationCalculatorService = null;
    public function reputationCalculatorService(): Services\ReputationCalculatorService
    {
        return $this->reputationCalculatorService ??= new Services\ReputationCalculatorService(
            $this->reputationRepository()
        );
    }

    private ?EndorsementService $endorsementService = null;
    public function endorsementService(): EndorsementService
    {
        return $this->endorsementService ??= new EndorsementService(
            $this->endorsementRepository(),
            $this->scoreRepository(),
            $this->userInfoRepository(),
            $this->verificationRepository()
        );
    }

    private ?TrustReadService $trustReadService = null;
    public function trustReadService(): TrustReadService
    {
        return $this->trustReadService ??= new TrustReadService(
            $this->voteRepository()
        );
    }

    private ?DisputeAdjudicator $disputeAdjudicator = null;
    public function disputeAdjudicator(): DisputeAdjudicator
    {
        return $this->disputeAdjudicator ??= new DisputeAdjudicator(
            $this->voteRepository(),
            $this->scoreRepository(),
            $this->userInfoRepository()
        );
    }

    private ?ScoreContributorService $scoreContributorService = null;
    public function scoreContributorService(): ScoreContributorService
    {
        return $this->scoreContributorService ??= new ScoreContributorService(
            $this->scoreRepository()
        );
    }

    private ?ScoreReadService $scoreReadService = null;
    public function scoreReadService(): ScoreReadService
    {
        return $this->scoreReadService ??= new ScoreReadService();
    }

    private ?VerificationService $verificationService = null;
    public function verificationService(): VerificationService
    {
        return $this->verificationService ??= new VerificationService();
    }

    private ?Services\wallet\WalletVerificationService $walletVerificationService = null;
    public function walletVerificationService(): Services\wallet\WalletVerificationService
    {
        return $this->walletVerificationService ??= new Services\wallet\WalletVerificationService();
    }

    private ?Services\PageDiscoveryService $pageDiscoveryService = null;
    public function pageDiscoveryService(): Services\PageDiscoveryService
    {
        return $this->pageDiscoveryService ??= new Services\PageDiscoveryService();
    }

    // ── Phase 3 services ────────────────────────────────────────────────

    private ?Services\CronService $cronService = null;
    public function cronService(): Services\CronService
    {
        return $this->cronService ??= new Services\CronService(
            $this->voteRepository(),
            $this->scoreRepository()
        );
    }

    private ?Services\UserSyncService $userSyncService = null;
    public function userSyncService(): Services\UserSyncService
    {
        return $this->userSyncService ??= new Services\UserSyncService(
            $this->userInfoRepository()
        );
    }

    private ?Services\UserLifecycleService $userLifecycleService = null;
    public function userLifecycleService(): Services\UserLifecycleService
    {
        return $this->userLifecycleService ??= new Services\UserLifecycleService(
            $this->userSyncService(),
            $this->scoreRepository()
        );
    }

    // ── Phase 5 services (frontend + admin) ────────────────────────────

    private ?QuestProgressService $questProgressService = null;
    public function questProgressService(): QuestProgressService
    {
        return $this->questProgressService ??= new QuestProgressService(
            new QuestProgressRepository()
        );
    }

    private ?Services\PageOwnerResolver $pageOwnerResolver = null;
    public function pageOwnerResolver(): Services\PageOwnerResolver
    {
        return $this->pageOwnerResolver ??= new Services\PageOwnerResolver();
    }

    private ?Integration\PeepSoIntegration $peepSoIntegration = null;
    public function peepSoIntegration(): Integration\PeepSoIntegration
    {
        return $this->peepSoIntegration ??= new Integration\PeepSoIntegration(
            $this->scoreRepository(),
            $this->userInfoRepository(),
            $this->verificationRepository()
        );
    }

    private ?Repositories\AdminDashboardRepository $adminDashboardRepository = null;
    public function adminDashboardRepository(): Repositories\AdminDashboardRepository
    {
        return $this->adminDashboardRepository ??= new Repositories\AdminDashboardRepository();
    }

    private ?Services\Admin\ModerationService $moderationService = null;
    public function moderationService(): Services\Admin\ModerationService
    {
        return $this->moderationService ??= new Services\Admin\ModerationService(
            $this->scoreRepository(),
            $this->userInfoRepository(),
            $this->voteRepository()
        );
    }

    private ?Services\Admin\RepairService $repairService = null;
    public function repairService(): Services\Admin\RepairService
    {
        return $this->repairService ??= new Services\Admin\RepairService();
    }

    // ── Route registration ──────────────────────────────────────────────

    /**
     * Register all REST API routes.
     *
     * Consolidates the logic from the deleted app/routes/ files into a
     * single method called from bootstrap.php via rest_api_init.
     */
    public function registerRoutes(): void
    {
        // ── REST Namespace Convention ───────────────────────────────────
        //
        // bcc-trust/v1  = Trust-engine-internal routes: mutations (vote,
        //                 endorse, revoke), user status, admin stats,
        //                 OAuth callbacks, health checks. Consumers:
        //                 trust-header.js, trust-frontend.js, admin.js.
        //
        // bcc/v1        = Shared cross-plugin read API consumed by blocks,
        //                 bcc-disputes, bcc-search, and external integrations.
        //                 Routes: /page/{id}, /discover, /endorsements/*,
        //                 /validators/top, /flag, /claim.
        //
        // Do NOT mix: new read endpoints → bcc/v1.
        //             New mutations     → bcc-trust/v1.
        // ────────────────────────────────────────────────────────────────

        // Core trust routes — TrustRestController registers all routes
        // including those that delegate to UserStatusController and
        // AdminStatsController.
        TrustRestController::register_routes();

        // GitHub OAuth + verification
        GitHubController::register_routes();

        // X (Twitter) OAuth + verification
        XController::register_routes();

        // Wallet verification is handled by bcc-onchain-signals (AJAX).
        // Trust-engine listens to bcc_wallet_verified / bcc_wallet_disconnected
        // hooks in CronService::registerCacheInvalidation() for scoring updates.

        // Discovery feed
        DiscoveryEndpoint::register();

        // Page data endpoint
        PageEndpoint::register();

        // Page flag endpoint (signal only — no score impact)
        \BCC\Trust\Core\REST\FlagEndpoint::register();

        // Endorsement leaderboard endpoint (read model)
        \BCC\Trust\Core\REST\EndorsementLeaderboardEndpoint::register();

        // User endorsements endpoint (my endorsements)
        \BCC\Trust\Core\REST\UserEndorsementsEndpoint::register();

        // Validator leaderboard endpoint (replaces admin-ajax load-more)
        \BCC\Trust\Core\REST\ValidatorLeaderboardEndpoint::register();

        // Entity claim endpoint (replaces admin-ajax bcc_claim_entity)
        \BCC\Trust\Core\REST\EntityClaimEndpoint::register();

        // Read model health monitoring (admin-only)
        \BCC\Trust\Core\REST\ReadModelHealthEndpoint::register();

        // API index
        register_rest_route('bcc-trust/v1', '/', [
            'methods'             => 'GET',
            'callback'            => [$this, 'apiIndex'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Register all async job handlers in one place.
     *
     * Each hook is scheduled by its respective service after a transaction
     * commits. Handlers run via wp-cron or Action Scheduler on the next tick.
     *
     * Centralised here for discoverability: "what happens after a vote?" is
     * answered by reading this method, not grepping 3 files.
     */
    public function registerAsyncJobs(): void
    {
        // ── Post-vote pipeline ──────────────────────────────────────────

        // Fraud re-analysis (async, delegates to FraudDetector)
        add_action(
            \BCC\Trust\Core\Services\Vote\VoteFraudAnalyzer::HOOK,
            function (int $voteId) {
                (new \BCC\Trust\Core\Services\Vote\VoteFraudAnalyzer(
                    $this->voteRepository()
                ))->run($voteId);
            }
        );

        // Incremental trust rank refresh for voter + page owner
        add_action('bcc_trust_async_trust_graph_update', function (int $voteId) {
            $vote = $this->voteRepository()->getById($voteId);
            if (!$vote || (int) $vote->status !== 1) return;
            $voterId = (int) $vote->voter_user_id;
            $ownerId = (int) Services\PeepSoPageResolver::getOwnerId((int) $vote->page_id);
            $dirty   = array_filter([$voterId, $ownerId ?: null]);
            try {
                $this->trustGraph()->incrementalUpdate($dirty);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] TrustGraph incrementalUpdate failed', ['users' => $dirty, 'error' => $e->getMessage()]);
            }
        });

        // Reputation tier recalculation for page owner
        add_action('bcc_trust_async_reputation_recalculate', function (int $voteId) {
            $vote = $this->voteRepository()->getById($voteId);
            if (!$vote || (int) $vote->status !== 1) return;
            $ownerId = (int) Services\PeepSoPageResolver::getOwnerId((int) $vote->page_id);
            if ($ownerId) {
                try {
                    $this->reputationCalculatorService()->recalculateUserReputation($ownerId);
                } catch (\Throwable $e) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] ReputationRecalc failed', ['user_id' => $ownerId, 'error' => $e->getMessage()]);
                }
            }
        });

        // Voter stats refresh (votes_cast counter)
        add_action('bcc_trust_async_stats_refresh', function (int $voteId) {
            $vote = $this->voteRepository()->getById($voteId);
            if (!$vote) return;
            $voterId   = (int) $vote->voter_user_id;
            $voteCount = $this->voteRepository()->countByVoter($voterId);
            $this->userInfoRepository()->updateVotesCast($voterId, $voteCount);
        });

        // Composite post-vote handler: single async job that dispatches all
        // sub-tasks internally. Reduces advisory lock overhead from 4→1 per vote.
        add_action('bcc_trust_async_post_vote', [\BCC\Trust\Core\Services\Vote\VoteJobDispatcher::class, 'handlePostVote']);

        // Edge recalculation after vote removal. Resolve the page owner
        // here: recalculateVoteEdge expects (source_user_id, target_user_id)
        // — passing $pageId as the target silently wrote a zero-weight edge
        // against a non-existent owner id and left the real voter→owner edge
        // stale after every remove-vote / dispute-accept path.
        add_action('bcc_trust_async_edge_recalculate', function (int $voterId, int $pageId): void {
            $ownerId = \BCC\Trust\Core\Services\PeepSoPageResolver::getOwnerId($pageId);
            if ($ownerId > 0 && $ownerId !== $voterId) {
                $this->edgeRepository()->recalculateVoteEdge($voterId, $ownerId);
            }
        }, 10, 2);

        // ── Post-endorsement pipeline ───────────────────────────────────

        // Endorsement fraud re-analysis
        add_action(
            \BCC\Trust\Core\Services\EndorsementFraudAnalyzer::HOOK,
            function (int $endorserUserId, int $pageId) {
                (new \BCC\Trust\Core\Services\EndorsementFraudAnalyzer(
                    $this->endorsementRepository()
                ))->run($endorserUserId, $pageId);
            },
            10, 2
        );

        // ── Fraud threshold-crossing async fan-out ─────────────────────
        //
        // `UserInfoRepository::updateFraudScore()` and `incrementFraudScore()`
        // must never run the unbounded UPDATE…JOIN flag-fanout on the
        // synchronous user request path. This handler does the expensive
        // work after the request has returned.
        //
        // Idempotency: the repository's INSERT IGNORE cooldown flag is the
        // dedup gate BEFORE this hook is scheduled — the handler just runs
        // the flags unconditionally once dispatched.
        add_action('bcc_trust_async_fraud_crossing_fanout', function (int $userId, int $previousScore, int $newScore): void {
            try {
                $repo = $this->userInfoRepository();
                $repo->markVotedPagesForRecalculation($userId);
                $repo->markEndorsedPagesForRecalculation($userId);
                \BCC\Core\Log\Logger::info('[bcc-trust] fraud_crossing_fanout complete', [
                    'user_id'        => $userId,
                    'previous_score' => $previousScore,
                    'new_score'      => $newScore,
                ]);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] fraud_crossing_fanout failed', [
                    'user_id' => $userId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 10, 3);

        // ── Wallet verification ─────────────────────────────────────────

        // Async blockchain RPC role check
        add_action(
            Services\wallet\WalletVerificationService::CHAIN_CHECK_HOOK,
            function (int $userId, string $chain, string $walletAddress, string $contractAddress, array $extra) {
                (new Services\wallet\WalletVerificationService())->completeChainCheck(
                    $userId, $chain, $walletAddress, $contractAddress, $extra
                );
            },
            10, 5
        );

        // ── System health filter ───────────────────────────────────────
        // Wire trust-engine health data into bcc-core's /system/health endpoint.
        add_filter('bcc_system_health', function (array $health): array {
            $repo = $this->readModelHealthRepository();

            $recalcPending = $repo->countPendingRecalculations();
            $driftCount    = $repo->countDriftedPages(100);
            $rmCount       = $repo->countReadModelRows();
            $totalPages    = $repo->countPublishedPages();

            $health['trust_engine'] = [
                'recalc_queue_depth'    => $recalcPending,
                'read_model_drift'      => $driftCount,
                'read_model_coverage'   => $totalPages > 0
                    ? round(($rmCount / $totalPages) * 100, 1)
                    : 0.0,
            ];

            return $health;
        });

        // ── NullObject detection: admin warning ────────────────────────
        // If any critical service resolved to a NullObject in production,
        // show a persistent admin notice. This makes silent degradation
        // LOUD instead of invisible.
        add_action('admin_notices', function (): void {
            if (!current_user_can('manage_options')) {
                return;
            }

            $critical = [
                \BCC\Core\Contracts\TrustReadServiceInterface::class    => 'Trust Read Service',
                \BCC\Core\Contracts\ScoreContributorInterface::class    => 'Score Contributor',
                \BCC\Core\Contracts\DisputeAdjudicationInterface::class => 'Dispute Adjudicator',
                \BCC\Core\Contracts\PageOwnerResolverInterface::class   => 'Page Owner Resolver',
            ];

            $missing = [];
            foreach ($critical as $contract => $label) {
                if (!\BCC\Core\ServiceLocator::hasRealService($contract)) {
                    $missing[] = $label;
                }
            }

            if (empty($missing)) {
                return;
            }

            echo '<div class="notice notice-error"><p>';
            echo '<strong>BCC Trust Engine:</strong> ';
            echo 'The following critical services are running on NullObject fallbacks (degraded mode): ';
            echo '<strong>' . esc_html(implode(', ', $missing)) . '</strong>. ';
            echo 'Check that all required plugins are activated.';
            echo '</p></div>';
        });
    }

    /**
     * API index endpoint — lists all registered bcc-trust/v1 routes.
     */
    public function apiIndex(): \WP_REST_Response
    {
        $routes    = rest_get_server()->get_routes('bcc-trust/v1');
        $endpoints = [];

        foreach ($routes as $route => $handlers) {
            foreach ($handlers as $handler) {
                $methods = [];
                if (isset($handler['methods'])) {
                    $methods = is_array($handler['methods'])
                        ? array_keys($handler['methods'])
                        : [$handler['methods']];
                }

                $callback = 'unknown';
                if (isset($handler['callback'])) {
                    if (is_array($handler['callback'])) {
                        $callback = (is_object($handler['callback'][0])
                            ? get_class($handler['callback'][0])
                            : $handler['callback'][0])
                            . '::' . $handler['callback'][1];
                    } elseif (is_string($handler['callback'])) {
                        $callback = $handler['callback'];
                    }
                }

                $endpoints[] = [
                    'route'    => $route,
                    'methods'  => $methods,
                    'callback' => $callback,
                ];
            }
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'namespace' => 'bcc-trust/v1',
                'routes'    => $endpoints,
            ],
        ], 200);
    }
}
