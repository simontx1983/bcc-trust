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
use BCC\Trust\Core\Repositories\FraudAnalysisRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\ScoreEventRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VerificationRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\REST\Envelope;
use BCC\Trust\Core\Security\BehavioralAnalyzer;
use BCC\Trust\Core\Security\DeviceFingerprinter;
use BCC\Trust\Core\Security\TrustGraph;
use BCC\Trust\Core\Repositories\QuestProgressRepository;
use BCC\Trust\Core\Services\AttestationService;
use BCC\Trust\Core\Services\EndorsementService;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Services\GroupActivityHeatService;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\Quest\QuestProgressService;
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

    private ?ScoreEventRepository $scoreEventRepository = null;
    public function scoreEventRepository(): ScoreEventRepository
    {
        return $this->scoreEventRepository ??= new ScoreEventRepository();
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

    // ── V1 frontend support repositories ────────────────────────────────

    private ?Repositories\WatchMetaRepository $watchMetaRepository = null;
    public function watchMetaRepository(): Repositories\WatchMetaRepository
    {
        return $this->watchMetaRepository ??= new Repositories\WatchMetaRepository();
    }

    // NOTE: UserLocalRepository removed — Locals membership reads from
    // PeepSo's peepso_group_members directly via PeepSoGroupRepository
    // (single graph rule). LocalsService composes membership view-models
    // from PeepSo + the bcc_primary_local_group_id user-meta pointer.
    //
    // NOTE: PageClaimRepository removed — page claims merged into the
    // existing onchain ClaimRepository (entity_type='page'); the
    // createExclusiveClaim() advisory lock enforces §B5 single-claim-wins.

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

    // ReputationCalculatorService is now a static-only helper
    // (ReputationCalculatorService::blendContribution, called by
    // ContributionRecoveryEvaluator). The instance accessor + its
    // reputation/userInfo deps were removed in the reputation cutover —
    // calculateRecommendedVoteWeight had zero callers.

    /** Trust Recovery Through Contribution — per-user bonus computation. */
    private ?Services\ContributionScoreService $contributionScoreService = null;
    public function contributionScoreService(): Services\ContributionScoreService
    {
        return $this->contributionScoreService ??= new Services\ContributionScoreService(
            $this->commentRepository(),
            $this->contentReportRepository(),
            $this->peepSoReactionRepository(),
            $this->reputationRepository(),
            $this->userInfoRepository()
        );
    }

    /** Trust Recovery Through Contribution — the daily batch evaluator. */
    private ?Services\ContributionRecoveryEvaluator $contributionRecoveryEvaluator = null;
    public function contributionRecoveryEvaluator(): Services\ContributionRecoveryEvaluator
    {
        return $this->contributionRecoveryEvaluator ??= new Services\ContributionRecoveryEvaluator(
            $this->contributionScoreService(),
            $this->reputationRepository()
        );
    }

    private ?EndorsementService $endorsementService = null;
    public function endorsementService(): EndorsementService
    {
        // Endorse-retirement final slice: every read here is attestation-
        // backed (active kind=vouch rows) — the legacy endorsements table
        // and its repository are deleted. What remains is the vouch-aligned
        // can_endorse eligibility gate + the §4.22/§4.30 given-direction
        // reads and their §J.6 hydration (external shapes preserved).
        return $this->endorsementService ??= new EndorsementService(
            $this->attestationService()
        );
    }

    // V2 Trust Attestation Layer — generalized successor to
    // EndorsementService per §J.11 endorse→vouch migration.
    // EndorsementService stays in place during Phase 1; this owns
    // the new bcc_trust_attestations table.

    private ?AttestationRepository $attestationRepository = null;
    public function attestationRepository(): AttestationRepository
    {
        return $this->attestationRepository ??= new AttestationRepository();
    }

    private ?\BCC\Trust\Core\Repositories\AttestorReliabilityCacheRepository $attestorReliabilityCacheRepository = null;
    public function attestorReliabilityCacheRepository(): \BCC\Trust\Core\Repositories\AttestorReliabilityCacheRepository
    {
        return $this->attestorReliabilityCacheRepository ??= new \BCC\Trust\Core\Repositories\AttestorReliabilityCacheRepository();
    }

    private ?\BCC\Trust\Core\Services\WalletAgeWeighter $walletAgeWeighter = null;
    public function walletAgeWeighter(): \BCC\Trust\Core\Services\WalletAgeWeighter
    {
        return $this->walletAgeWeighter ??= new \BCC\Trust\Core\Services\WalletAgeWeighter();
    }

    private ?\BCC\Trust\Core\Services\ReciprocityPenaltyResolver $reciprocityResolver = null;
    public function reciprocityResolver(): \BCC\Trust\Core\Services\ReciprocityPenaltyResolver
    {
        return $this->reciprocityResolver ??= new \BCC\Trust\Core\Services\ReciprocityPenaltyResolver(
            $this->attestationRepository()
        );
    }

    private ?\BCC\Trust\Core\Services\DormancyDetector $dormancyDetector = null;
    public function dormancyDetector(): \BCC\Trust\Core\Services\DormancyDetector
    {
        return $this->dormancyDetector ??= new \BCC\Trust\Core\Services\DormancyDetector(
            $this->attestationRepository()
        );
    }

    private ?\BCC\Trust\Core\Services\ReliabilityStandingComputer $reliabilityStandingComputer = null;
    public function reliabilityStandingComputer(): \BCC\Trust\Core\Services\ReliabilityStandingComputer
    {
        return $this->reliabilityStandingComputer ??= new \BCC\Trust\Core\Services\ReliabilityStandingComputer(
            $this->attestationRepository(),
            $this->attestorReliabilityCacheRepository()
        );
    }

    private ?\BCC\Trust\Core\Services\CohortOverlapDampener $cohortOverlapDampener = null;
    public function cohortOverlapDampener(): \BCC\Trust\Core\Services\CohortOverlapDampener
    {
        return $this->cohortOverlapDampener ??= new \BCC\Trust\Core\Services\CohortOverlapDampener(
            $this->attestationRepository()
        );
    }

    private ?\BCC\Trust\Core\Services\NewEntityReputationVelocityCap $newEntityVelocityCap = null;
    public function newEntityVelocityCap(): \BCC\Trust\Core\Services\NewEntityReputationVelocityCap
    {
        return $this->newEntityVelocityCap ??= new \BCC\Trust\Core\Services\NewEntityReputationVelocityCap();
    }

    private ?\BCC\Trust\Core\Services\DivergenceStateClassifier $divergenceClassifier = null;
    public function divergenceClassifier(): \BCC\Trust\Core\Services\DivergenceStateClassifier
    {
        return $this->divergenceClassifier ??= new \BCC\Trust\Core\Services\DivergenceStateClassifier(
            $this->attestationRepository()
        );
    }

    private ?\BCC\Trust\Core\Services\ContestedStateExplainer $contestedStateExplainer = null;
    public function contestedStateExplainer(): \BCC\Trust\Core\Services\ContestedStateExplainer
    {
        return $this->contestedStateExplainer ??= new \BCC\Trust\Core\Services\ContestedStateExplainer();
    }

    private ?\BCC\Trust\Core\Repositories\TargetDivergenceStateRepository $targetDivergenceStateRepository = null;
    public function targetDivergenceStateRepository(): \BCC\Trust\Core\Repositories\TargetDivergenceStateRepository
    {
        return $this->targetDivergenceStateRepository ??= new \BCC\Trust\Core\Repositories\TargetDivergenceStateRepository();
    }

    private ?\BCC\Trust\Core\Services\PolarizationTransitionNotifier $polarizationTransitionNotifier = null;
    public function polarizationTransitionNotifier(): \BCC\Trust\Core\Services\PolarizationTransitionNotifier
    {
        return $this->polarizationTransitionNotifier ??= new \BCC\Trust\Core\Services\PolarizationTransitionNotifier(
            $this->attestationRepository(),
            $this->targetDivergenceStateRepository(),
            $this->divergenceClassifier(),
            $this->notificationDispatcher()
        );
    }

    private ?\BCC\Trust\Core\Services\MetaDisputeFilerEligibility $metaDisputeFilerEligibility = null;
    public function metaDisputeFilerEligibility(): \BCC\Trust\Core\Services\MetaDisputeFilerEligibility
    {
        return $this->metaDisputeFilerEligibility ??= new \BCC\Trust\Core\Services\MetaDisputeFilerEligibility(
            $this->attestationRepository()
        );
    }

    private ?\BCC\Trust\Core\Services\AttestationOutcomeClassifier $attestationOutcomeClassifier = null;
    public function attestationOutcomeClassifier(): \BCC\Trust\Core\Services\AttestationOutcomeClassifier
    {
        return $this->attestationOutcomeClassifier ??= new \BCC\Trust\Core\Services\AttestationOutcomeClassifier(
            $this->attestationRepository()
        );
    }

    private ?AttestationService $attestationService = null;
    public function attestationService(): AttestationService
    {
        return $this->attestationService ??= new AttestationService(
            $this->attestationRepository(),
            $this->reputationRepository(),
            $this->userInfoRepository(),
            $this->walletAgeWeighter(),
            $this->reciprocityResolver(),
            $this->dormancyDetector(),
            $this->cohortOverlapDampener(),
            $this->divergenceClassifier(),
            $this->contestedStateExplainer(),
            $this->attestationOutcomeClassifier(),
            $this->attestorReliabilityCacheRepository()
        );
    }

    private ?Services\AttestationScoreSynthesis $attestationScoreSynthesis = null;
    public function attestationScoreSynthesis(): Services\AttestationScoreSynthesis
    {
        return $this->attestationScoreSynthesis ??= new Services\AttestationScoreSynthesis(
            $this->attestationRepository(),
            $this->reputationRepository(),
            $this->scoreRepository(),
            $this->memberSelfPageService()
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

    private ?Services\PageDiscoveryService $pageDiscoveryService = null;
    public function pageDiscoveryService(): Services\PageDiscoveryService
    {
        return $this->pageDiscoveryService ??= new Services\PageDiscoveryService();
    }

    // ── V1 contract services (per docs/api-contract-v1.md) ──────────────

    private ?Services\TrustScoreService $trustScoreService = null;
    public function trustScoreService(): Services\TrustScoreService
    {
        return $this->trustScoreService ??= new Services\TrustScoreService(
            $this->scoreRepository(),
            $this->pageReadModelRepository()
        );
    }

    private ?Services\FeatureAccessService $featureAccessService = null;
    public function featureAccessService(): Services\FeatureAccessService
    {
        return $this->featureAccessService ??= new Services\FeatureAccessService(
            $this->voteRepository(),
            $this->reputationRepository()
        );
    }

    private ?Services\RankService $rankService = null;
    public function rankService(): Services\RankService
    {
        return $this->rankService ??= new Services\RankService(
            $this->featureAccessService()
        );
    }

    private ?Services\RankProgressionListener $rankProgressionListener = null;
    public function rankProgressionListener(): Services\RankProgressionListener
    {
        return $this->rankProgressionListener ??= new Services\RankProgressionListener(
            $this->rankService()
        );
    }

    /**
     * §O1.2 first-action celebrations — one-shot Heavy stashing on first
     * post / first review / first blog. See FirstActionListener docstring
     * for the deferred kinds (first_watcher, first_local_joined, etc.).
     */
    private ?Services\FirstActionListener $firstActionListener = null;
    public function firstActionListener(): Services\FirstActionListener
    {
        return $this->firstActionListener ??= new Services\FirstActionListener();
    }

    /** §C1 / §N11 / §O1.2 — fires "your card is now Rare" Heavy moment. */
    private ?Services\TierUpgradeListener $tierUpgradeListener = null;
    public function tierUpgradeListener(): Services\TierUpgradeListener
    {
        return $this->tierUpgradeListener ??= new Services\TierUpgradeListener(
            $this->reputationRepository()
        );
    }

    // ── §I1 notifications ────────────────────────────────────────────

    private ?Repositories\NotificationRepository $notificationRepository = null;
    public function notificationRepository(): Repositories\NotificationRepository
    {
        return $this->notificationRepository ??= new Repositories\NotificationRepository();
    }

    private ?Services\NotificationViewService $notificationViewService = null;
    public function notificationViewService(): Services\NotificationViewService
    {
        return $this->notificationViewService ??= new Services\NotificationViewService(
            $this->notificationRepository()
        );
    }

    private ?Services\NotificationDispatcher $notificationDispatcher = null;
    public function notificationDispatcher(): Services\NotificationDispatcher
    {
        return $this->notificationDispatcher ??= new Services\NotificationDispatcher(
            $this->pageOwnerResolver(),
            $this->pushDispatcher()
        );
    }

    private ?Services\PushDispatcher $pushDispatcher = null;
    public function pushDispatcher(): Services\PushDispatcher
    {
        return $this->pushDispatcher ??= new Services\PushDispatcher(
            new \BCC\Trust\Core\Repositories\PushSubscriptionRepository()
        );
    }

    private ?Services\DigestService $digestService = null;
    public function digestService(): Services\DigestService
    {
        return $this->digestService ??= new Services\DigestService(
            $this->notificationRepository()
        );
    }

    private ?Services\LocalsService $localsService = null;
    public function localsService(): Services\LocalsService
    {
        return $this->localsService ??= new Services\LocalsService(
            $this->groupContextResolver()
        );
    }

    private ?Services\GroupsService $groupsService = null;
    public function groupsService(): Services\GroupsService
    {
        return $this->groupsService ??= new Services\GroupsService(
            $this->reputationRepository()
        );
    }

    private ?Services\GroupMembersService $groupMembersService = null;
    public function groupMembersService(): Services\GroupMembersService
    {
        return $this->groupMembersService ??= new Services\GroupMembersService();
    }

    private ?Services\ShiftLogService $shiftLogService = null;
    public function shiftLogService(): Services\ShiftLogService
    {
        return $this->shiftLogService ??= new Services\ShiftLogService();
    }

    private ?Repositories\PeepSoReactionRepository $peepSoReactionRepository = null;
    public function peepSoReactionRepository(): Repositories\PeepSoReactionRepository
    {
        return $this->peepSoReactionRepository ??= new Repositories\PeepSoReactionRepository();
    }

    private ?Repositories\StokeRepository $stokeRepository = null;
    public function stokeRepository(): Repositories\StokeRepository
    {
        return $this->stokeRepository ??= new Repositories\StokeRepository();
    }

    private ?Repositories\CommentRepository $commentRepository = null;
    public function commentRepository(): Repositories\CommentRepository
    {
        return $this->commentRepository ??= new Repositories\CommentRepository();
    }

    private ?Repositories\PhotoRepository $photoRepository = null;
    public function photoRepository(): Repositories\PhotoRepository
    {
        return $this->photoRepository ??= new Repositories\PhotoRepository();
    }

    private ?Repositories\PhotoAltRepository $photoAltRepository = null;
    public function photoAltRepository(): Repositories\PhotoAltRepository
    {
        return $this->photoAltRepository ??= new Repositories\PhotoAltRepository();
    }

    private ?Repositories\GifRepository $gifRepository = null;
    public function gifRepository(): Repositories\GifRepository
    {
        return $this->gifRepository ??= new Repositories\GifRepository();
    }

    private ?Services\CommentService $commentService = null;
    public function commentService(): Services\CommentService
    {
        return $this->commentService ??= new Services\CommentService(
            $this->commentRepository(),
            $this->mentionOverlayService(),
            $this->authorBadgeResolver(),
            $this->attestationService(),
            $this->stokeRepository(),
            $this->userMiniRepository()
        );
    }

    private ?Services\AuthorBadgeResolver $authorBadgeResolver = null;
    public function authorBadgeResolver(): Services\AuthorBadgeResolver
    {
        return $this->authorBadgeResolver ??= new Services\AuthorBadgeResolver(
            $this->reputationRepository(),
            $this->featureAccessService()
        );
    }

    private ?Repositories\UserMiniRepository $userMiniRepository = null;
    public function userMiniRepository(): Repositories\UserMiniRepository
    {
        return $this->userMiniRepository ??= new Repositories\UserMiniRepository();
    }

    private ?Services\MessagesService $messagesService = null;
    public function messagesService(): Services\MessagesService
    {
        return $this->messagesService ??= new Services\MessagesService(
            $this->userMiniRepository()
        );
    }

    private ?Services\BadgesService $badgesService = null;
    public function badgesService(): Services\BadgesService
    {
        return $this->badgesService ??= new Services\BadgesService(
            $this->notificationRepository(),
            $this->messagesService()
        );
    }

    private ?Services\LivingService $livingService = null;
    public function livingService(): Services\LivingService
    {
        return $this->livingService ??= new Services\LivingService(
            $this->voteRepository(),
            $this->peepSoReactionRepository()
        );
    }

    private ?Services\UserViewService $userViewService = null;
    public function userViewService(): Services\UserViewService
    {
        return $this->userViewService ??= new Services\UserViewService(
            $this->voteRepository(),
            $this->reputationRepository(),
            $this->rankService(),
            $this->featureAccessService(),
            $this->livingService(),
            $this->scoreEventRepository(),
            $this->peepSoReactionRepository(),
            \BCC\Trust\Disputes\DisputesPlugin::instance()->disputeParticipationRepository(),
            $this->attestationService(),
            $this->questProgressService()
        );
    }

    private ?Services\MemberProfileComposer $memberProfileComposer = null;
    public function memberProfileComposer(): Services\MemberProfileComposer
    {
        return $this->memberProfileComposer ??= new Services\MemberProfileComposer(
            $this->userViewService(),
            $this->cardViewService(),
            $this->shiftLogService(),
            $this->attestationService()
        );
    }

    private ?Services\Feed\FeedRankingService $feedRankingService = null;
    public function feedRankingService(): Services\Feed\FeedRankingService
    {
        return $this->feedRankingService ??= new Services\Feed\FeedRankingService(
            new \BCC\Core\Feed\ActivityFeedService(
                new \BCC\Core\PeepSo\PeepSoGraphService()
            ),
            $this->reputationRepository(),
            $this->hiddenActivityRepository(),
            $this->groupContextResolver(),
            $this->feedHydrationPipeline()
        );
    }

    private ?Services\Feed\FeedHydrationPipeline $feedHydrationPipeline = null;
    public function feedHydrationPipeline(): Services\Feed\FeedHydrationPipeline
    {
        return $this->feedHydrationPipeline ??= new Services\Feed\FeedHydrationPipeline(
            $this->peepSoReactionRepository(),
            $this->stokeRepository(),
            $this->groupContextResolver(),
            $this->commentRepository(),
            $this->gifRepository(),
            $this->mentionOverlayService(),
            $this->authorBadgeResolver(),
            $this->attestationService(),
            $this->blogService(),
            $this->watchBatchBodyHydrator(),
            $this->reviewBodyHydrator(),
            $this->photoBodyHydrator(),
            $this->gifBodyHydrator(),
            $this->pageClaimBodyHydrator(),
            $this->postShortcodeRepository()
        );
    }

    private ?Services\Feed\WatchBatchBodyHydrator $watchBatchBodyHydrator = null;
    public function watchBatchBodyHydrator(): Services\Feed\WatchBatchBodyHydrator
    {
        return $this->watchBatchBodyHydrator ??= new Services\Feed\WatchBatchBodyHydrator(
            $this->watchBatchRepository(),
            $this->watchMetaRepository(),
            $this->watchingRepository()
        );
    }

    private ?Services\Feed\ReviewBodyHydrator $reviewBodyHydrator = null;
    public function reviewBodyHydrator(): Services\Feed\ReviewBodyHydrator
    {
        return $this->reviewBodyHydrator ??= new Services\Feed\ReviewBodyHydrator(
            $this->voteRepository()
        );
    }

    private ?Services\Feed\PhotoBodyHydrator $photoBodyHydrator = null;
    public function photoBodyHydrator(): Services\Feed\PhotoBodyHydrator
    {
        return $this->photoBodyHydrator ??= new Services\Feed\PhotoBodyHydrator(
            $this->photoRepository(),
            $this->photoAltRepository()
        );
    }

    private ?Services\Feed\GifBodyHydrator $gifBodyHydrator = null;
    public function gifBodyHydrator(): Services\Feed\GifBodyHydrator
    {
        return $this->gifBodyHydrator ??= new Services\Feed\GifBodyHydrator();
    }

    private ?Services\Feed\PageClaimBodyHydrator $pageClaimBodyHydrator = null;
    public function pageClaimBodyHydrator(): Services\Feed\PageClaimBodyHydrator
    {
        return $this->pageClaimBodyHydrator ??= new Services\Feed\PageClaimBodyHydrator();
    }

    private ?Services\CardViewService $cardViewService = null;
    public function cardViewService(): Services\CardViewService
    {
        return $this->cardViewService ??= new Services\CardViewService(
            $this->pageReadModelRepository(),
            $this->reputationRepository(),
            $this->voteRepository(),
            $this->featureAccessService(),
            $this->voteService(),
            $this->endorsementService(),
            $this->attestationService(),
            $this->newEntityVelocityCap(),
            $this->divergenceClassifier(),
            $this->userViewService()
        );
    }

    private ?Repositories\WatchingRepository $watchingRepository = null;
    public function watchingRepository(): Repositories\WatchingRepository
    {
        return $this->watchingRepository ??= new Repositories\WatchingRepository();
    }

    private ?Services\WatchingService $watchingService = null;
    public function watchingService(): Services\WatchingService
    {
        return $this->watchingService ??= new Services\WatchingService(
            $this->watchingRepository(),
            $this->watchMetaRepository(),
            $this->reputationRepository(),
            $this->pageFollowRepository()
        );
    }

    private ?Repositories\PageFollowRepository $pageFollowRepository = null;
    public function pageFollowRepository(): Repositories\PageFollowRepository
    {
        return $this->pageFollowRepository ??= new Repositories\PageFollowRepository();
    }

    private ?Services\PostsService $postsService = null;
    public function postsService(): Services\PostsService
    {
        return $this->postsService ??= new Services\PostsService(
            $this->voteService(),
            $this->voteRepository(),
            $this->featureAccessService(),
            $this->blogChainTagRepository()
        );
    }

    /** §D6 PR-A — post ↔ chain join. Tagged on blog create; read on body hydration. */
    private ?Repositories\BlogChainTagRepository $blogChainTagRepository = null;
    public function blogChainTagRepository(): Repositories\BlogChainTagRepository
    {
        return $this->blogChainTagRepository ??= new Repositories\BlogChainTagRepository();
    }

    /** §D6 PR-B — cover-image upload writer for /bcc/v1/blog/cover-image. */
    private ?Services\BlogCoverImageWriter $blogCoverImageWriter = null;
    public function blogCoverImageWriter(): Services\BlogCoverImageWriter
    {
        return $this->blogCoverImageWriter ??= new Services\BlogCoverImageWriter();
    }

    /**
     * §3.3.12 — composer @-mention picker (search-by-prefix).
     * Stateless service; the constructor is parameter-less today
     * but the field stays per-request-memoized so the singleton
     * pattern matches the rest of Plugin.
     */
    private ?Services\Mentions\MentionSearchService $mentionSearchService = null;
    public function mentionSearchService(): Services\Mentions\MentionSearchService
    {
        return $this->mentionSearchService ??= new Services\Mentions\MentionSearchService();
    }

    /**
     * §3.3.12 — read-side overlay builder. Used by FeedRankingService
     * (status / photo / gif body hydration) and CommentService
     * (per-comment overlay). Stateless; same singleton pattern.
     */
    private ?Services\Mentions\MentionOverlayService $mentionOverlayService = null;
    public function mentionOverlayService(): Services\Mentions\MentionOverlayService
    {
        return $this->mentionOverlayService ??= new Services\Mentions\MentionOverlayService();
    }

    /** §D6 — per-user blog tab, paginated. */
    private ?Services\BlogService $blogService = null;
    public function blogService(): Services\BlogService
    {
        return $this->blogService ??= new Services\BlogService(
            $this->hiddenActivityRepository(),
            $this->blogChainTagRepository(),
            $this->postShortcodeRepository()
        );
    }

    /** §V1.5 — per-user reviews tab, paginated + privacy-honouring. */
    private ?Services\UserReviewsService $userReviewsService = null;
    public function userReviewsService(): Services\UserReviewsService
    {
        return $this->userReviewsService ??= new Services\UserReviewsService(
            $this->voteRepository()
        );
    }

    /** §V1.5 — per-user disputes tab, paginated + privacy-honouring. */
    private ?Services\UserDisputesService $userDisputesService = null;
    public function userDisputesService(): Services\UserDisputesService
    {
        return $this->userDisputesService ??= new Services\UserDisputesService();
    }

    /** §K1 Phase A — viewer's own blocked-users list, paginated. */
    private ?Services\MyBlocksService $myBlocksService = null;
    public function myBlocksService(): Services\MyBlocksService
    {
        return $this->myBlocksService ??= new Services\MyBlocksService();
    }

    /** §K1 Phase B — content-report write side. */
    private ?Repositories\ContentReportRepository $contentReportRepository = null;
    public function contentReportRepository(): Repositories\ContentReportRepository
    {
        return $this->contentReportRepository ??= new Repositories\ContentReportRepository();
    }

    private ?Services\ContentReportService $contentReportService = null;
    public function contentReportService(): Services\ContentReportService
    {
        return $this->contentReportService ??= new Services\ContentReportService(
            $this->contentReportRepository()
        );
    }

    /** §K1 Phase C — sidecar table marking activities hidden by moderation. */
    private ?Repositories\HiddenActivityRepository $hiddenActivityRepository = null;
    public function hiddenActivityRepository(): Repositories\HiddenActivityRepository
    {
        return $this->hiddenActivityRepository ??= new Repositories\HiddenActivityRepository();
    }

    /** Post-shortcode permalink sidecar — /u/{handle}/post/{code}. */
    private ?Repositories\PostShortcodeRepository $postShortcodeRepository = null;
    public function postShortcodeRepository(): Repositories\PostShortcodeRepository
    {
        return $this->postShortcodeRepository ??= new Repositories\PostShortcodeRepository();
    }

    /** §K1 Phase C — threshold-based auto-hide subscriber. */
    private ?Services\AutoHideService $autoHideService = null;
    public function autoHideService(): Services\AutoHideService
    {
        return $this->autoHideService ??= new Services\AutoHideService(
            $this->contentReportRepository(),
            $this->hiddenActivityRepository()
        );
    }

    /** §K1 Phase C — admin queue read + resolve actions. */
    private ?Services\ModerationQueueService $moderationQueueService = null;
    public function moderationQueueService(): Services\ModerationQueueService
    {
        return $this->moderationQueueService ??= new Services\ModerationQueueService(
            $this->contentReportRepository(),
            $this->hiddenActivityRepository()
        );
    }

    private ?Services\WatchingSummaryService $watchingSummaryService = null;
    public function watchingSummaryService(): Services\WatchingSummaryService
    {
        return $this->watchingSummaryService ??= new Services\WatchingSummaryService(
            $this->watchingRepository(),
            $this->voteRepository(),
            $this->peepSoReactionRepository()
        );
    }

    private ?Services\WatchBatchAggregator $watchBatchAggregator = null;
    public function watchBatchAggregator(): Services\WatchBatchAggregator
    {
        return $this->watchBatchAggregator ??= new Services\WatchBatchAggregator(
            $this->watchingRepository(),
            $this->watchMetaRepository()
        );
    }

    private ?Repositories\WatchBatchRepository $watchBatchRepository = null;
    public function watchBatchRepository(): Repositories\WatchBatchRepository
    {
        return $this->watchBatchRepository ??= new Repositories\WatchBatchRepository();
    }

    private ?Repositories\PeepSoActivityWriter $peepSoActivityWriter = null;
    public function peepSoActivityWriter(): Repositories\PeepSoActivityWriter
    {
        return $this->peepSoActivityWriter ??= new Repositories\PeepSoActivityWriter();
    }

    private ?Services\Feed\ActivityStreamWriter $activityStreamWriter = null;
    public function activityStreamWriter(): Services\Feed\ActivityStreamWriter
    {
        return $this->activityStreamWriter ??= new Services\Feed\ActivityStreamWriter(
            $this->watchBatchRepository(),
            $this->peepSoActivityWriter()
        );
    }

    /** §D6 PR-A — bridges WP's transition_post_status into BCC events for blog posts. */
    private ?Services\Feed\BlogStatusTransitionHandler $blogStatusTransitionHandler = null;
    public function blogStatusTransitionHandler(): Services\Feed\BlogStatusTransitionHandler
    {
        return $this->blogStatusTransitionHandler ??= new Services\Feed\BlogStatusTransitionHandler(
            $this->peepSoActivityWriter()
        );
    }

    private ?Services\HighlightsService $highlightsService = null;
    public function highlightsService(): Services\HighlightsService
    {
        // §O2 retention wiring (2026-05-13):
        //   - LivingService + RankService drive the POSITIVE slot
        //     (today's reviews, solids, percentile, streak, cold-user
        //     welcome) without duplicating queries.
        //   - ScoreEventRepository drives the EXTERNAL slot — recent
        //     civic-significant events on watched-entity pages
        //     (rank changes, dispute resolutions, endorsements,
        //     high-impact votes). Reads via PeepSoFollowerRepository +
        //     PeepSoPageRepository (both static utilities, no DI needed).
        // All three deps are nullable on the service so legacy bare-
        // construct paths still work; the resolver falls through to
        // a V1.0 null stub when any are missing.
        return $this->highlightsService ??= new Services\HighlightsService(
            $this->livingService(),
            $this->rankService(),
            $this->scoreEventRepository()
        );
    }

    private ?Services\HandleService $handleService = null;
    public function handleService(): Services\HandleService
    {
        return $this->handleService ??= new Services\HandleService();
    }

    private ?Services\FeedColdStartService $feedColdStartService = null;
    public function feedColdStartService(): Services\FeedColdStartService
    {
        // Cold-start bridge surface (home-feed empty state). Composes
        // locals + recently-active operators + hot posts without
        // duplicating any primitive — reuses UserViewService for
        // operator hydration and FeedRankingService for the hot block.
        // See Services/FeedColdStartService.php doctype for the
        // load-bearing rules (civic map, not a recommendation engine).
        return $this->feedColdStartService ??= new Services\FeedColdStartService(
            $this->userViewService(),
            $this->feedRankingService()
        );
    }

    private ?Services\SuggestionService $suggestionService = null;
    public function suggestionService(): Services\SuggestionService
    {
        // Who-to-follow recommender (AFFINITY-only; see the service's
        // doctype). Reuses UserViewService for member view-model
        // hydration and ReputationRepository/SuspensionRepository for
        // the exclusion-only reputation gate. No new graph/exclusion
        // primitives — every read is a repository call.
        return $this->suggestionService ??= new Services\SuggestionService(
            $this->userViewService(),
            $this->reputationRepository(),
            $this->suspensionRepository()
        );
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

    /** People-as-first-class-trust-subjects keystone — member self-pages. */
    private ?Services\MemberSelfPageService $memberSelfPageService = null;
    public function memberSelfPageService(): Services\MemberSelfPageService
    {
        return $this->memberSelfPageService ??= new Services\MemberSelfPageService(
            $this->scoreRepository()
        );
    }

    private ?Integration\PeepSoIntegration $peepSoIntegration = null;
    public function peepSoIntegration(): Integration\PeepSoIntegration
    {
        return $this->peepSoIntegration ??= new Integration\PeepSoIntegration(
            $this->scoreRepository(),
            $this->userInfoRepository()
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

    // ── Onchain holder-groups (NFT-gated PeepSo groups) ─────────────────

    private ?GroupContextResolver $groupContextResolver = null;
    public function groupContextResolver(): GroupContextResolver
    {
        return $this->groupContextResolver ??= new GroupContextResolver();
    }

    private ?GroupActivityHeatService $groupActivityHeatService = null;
    public function groupActivityHeatService(): GroupActivityHeatService
    {
        return $this->groupActivityHeatService ??= new GroupActivityHeatService();
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
        // ── Response Envelope (must register before any endpoint) ───────
        // Wraps all bcc/v1 + bcc-trust/v1 responses in {data, _meta} per
        // docs/api-contract-v1.md §1.4. The frontend's bccFetch wrapper
        // requires this shape for envelope-vs-error discrimination.
        Envelope::init();

        // ── REST Namespace Convention ───────────────────────────────────
        //
        // bcc-trust/v1  = Trust-engine-internal routes: mutations (vote,
        //                 endorse, revoke), user status, admin stats,
        //                 OAuth callbacks, health checks. Consumers:
        //                 trust-header.js, trust-frontend.js, admin.js.
        //
        // bcc/v1        = Shared cross-plugin read API consumed by blocks,
        //                 the Disputes domain, bcc-search, and external integrations.
        //                 Routes: /page/{id}, /discover, /endorsements/*,
        //                 /validators/top, /flag, /claim.
        //
        // Do NOT mix: new read endpoints → bcc/v1.
        //             New mutations     → bcc-trust/v1.
        //
        // Documented exception: Disputes mutations (`POST /disputes`,
        // `POST /disputes/{id}/vote`, `POST /report-user`,
        // `POST /disputes/{id}/resolve`) live under bcc/v1 — not
        // bcc-trust/v1 — because they are consumed cross-plugin (the
        // headless frontend, the legacy disputes admin surface, and
        // the moderation tooling) and need to share the public read
        // namespace's CORS allowlist + auth path. Treat this as the only
        // sanctioned exception; new bcc-trust mutations still go to
        // bcc-trust/v1. See DisputeController::register_routes (NS = 'bcc/v1').
        // ────────────────────────────────────────────────────────────────

        // Core trust routes — TrustRestController registers all routes
        // including those that delegate to UserStatusController and
        // AdminStatsController.
        TrustRestController::register_routes();

        // GitHub OAuth + verification
        GitHubController::register_routes();

        // X (Twitter) OAuth + verification
        XController::register_routes();

        // Whitelist BCC_FRONTEND_ORIGIN host(s) for wp_safe_redirect so
        // OAuth callbacks can return to the headless Next.js app instead
        // of being silently rewritten to home_url().
        \BCC\Trust\Core\Support\FrontendRedirect::register();

        // Wallet verification is handled by the Onchain domain (AJAX).
        // The Core domain listens to bcc_wallet_verified / bcc_wallet_disconnected
        // hooks in CronService::registerCacheInvalidation() for scoring updates.

        // User endorsements endpoint (my endorsements)
        \BCC\Trust\Core\REST\UserEndorsementsEndpoint::register();

        // Read model health monitoring (admin-only)
        \BCC\Trust\Core\REST\ReadModelHealthEndpoint::register();

        // V1 contract: rank catalog + viewer's current rank (§4.8)
        \BCC\Trust\Core\REST\RanksEndpoint::register();

        // V1 contract: paginated Locals catalog + viewer membership (§4.7)
        \BCC\Trust\Core\REST\LocalsEndpoint::register();

        // V1 contract: per-user 52-week shift-log activity grid (§4.4)
        \BCC\Trust\Core\REST\UsersEndpoint::register();

        // V1 contract: hot feed (§F2 zero-follow fallback) — routes through
        // the §F3 single-brain FeedRankingService.
        \BCC\Trust\Core\REST\FeedEndpoint::register();

        // V1 contract: trending hashtags (GET /hashtags/trending) — a
        // read-only projection over PeepSo's peepso_hashtags counter via
        // bcc-core's PeepSoHashtagRepository. Non-personalized; public cache.
        \BCC\Trust\Core\REST\HashtagsEndpoint::register();

        // V1.25 contract: personalized who-to-follow recommender
        // (GET /suggestions/users). Auth-required; AFFINITY-only scoring
        // (reciprocity / mutual follows / shared Locals + communities /
        // shared validator backing) — NEVER ranked by trust or follower
        // count. Reputation is exclusion-only. See SuggestionService.
        \BCC\Trust\Core\REST\SuggestionsEndpoint::register();

        // Sprint 3 cold-start bridge surface — GET /feed/cold-start.
        // Composes three blocks for the home-feed empty state (locals +
        // recently-active operators + hot posts). Auth-permissive; anon
        // viewers get the same shape minus chain-alignment personalization.
        // See Services/FeedColdStartService.php doctype for the load-
        // bearing civic-map (NOT recommendation-engine) rules.
        \BCC\Trust\Core\REST\FeedColdStartEndpoint::register();

        // V1 contract: polymorphic card view-model (§L5) — validator,
        // project, creator, member.
        \BCC\Trust\Core\REST\CardsEndpoint::register();

        // V1 contract: paginated cards list for the §G1/§G2 directory.
        // Same per-item shape as CardsEndpoint; filter + sort outside.
        \BCC\Trust\Core\REST\CardsListEndpoint::register();

        // V1 contract: §G1 global autocomplete. Thin wrapper over
        // bcc-search that maps results to canonical SearchSuggestions
        // (card_kind, card_tier, headless route prefix) per §A2.
        \BCC\Trust\Core\REST\CardsSearchEndpoint::register();

        // V1 contract: §I1 notifications. List + unread-count + mark-read.
        // Backed by peepso_notifications scoped to BCC_NOTIFICATION_MODULE_ID
        // (single-graph rule); writes route through PeepSoNotificationWriter.
        \BCC\Trust\Core\REST\NotificationsEndpoint::register();

        // V1 contract: §H1 NFT creator gallery. Reads from the existing
        // bcc_onchain_collections table via CollectionService; on stale
        // data, dispatches an async holdings-refresh per the SWR pattern.
        \BCC\Trust\Core\REST\CreatorGalleryEndpoint::register();

        // V1 contract: REST wallet auth pair for the headless Next.js
        // frontend — GET /auth/nonce + POST /auth/wallet-link. Pure glue
        // over WalletIdentityService (per §A4).
        \BCC\Trust\Core\REST\AuthEndpoint::register();

        // V1 contract: page claim flow (§B5 single-claim-wins) — pure
        // orchestration over the existing onchain ClaimService.
        \BCC\Trust\Core\REST\PagesEndpoint::register();

        // V1 contract: watchlist (§C2 — projection of PeepSo follows +
        // bcc_watch_meta sidecar). Registers the /me/watching/* family.
        \BCC\Trust\Core\REST\WatchingEndpoint::register();

        // V1 contract: highlights strip (§O2 / §O2.1) — three slots,
        // strict priority order, max one item per slot, dismissal.
        // V1.0 ships scaffolding with stub scorers; data fills in as
        // aggregators land.
        \BCC\Trust\Core\REST\HighlightsEndpoint::register();

        // V1 contract: Phase 2 onboarding surface — handle change
        // (§B6 cooldown), admin-curated first-pull suggestions (§B4),
        // and the onboarding-complete flag flip.
        \BCC\Trust\Core\REST\OnboardingEndpoint::register();

        // V1 contract: §D5 reaction set/remove — POST /reactions and
        // DELETE /reactions/:feed_id. Routes through PeepSoReactionWriter
        // (single-graph rule); response shape matches FeedItem.reactions
        // so the frontend can patch its cache without translation.
        \BCC\Trust\Core\REST\ReactionsEndpoint::register();

        // Stoke — POST/DELETE /feed/{id}/stoke. NOT a reaction-kind
        // write (accumulates, capped, own bcc_trust_stokes table via
        // StokeRepository); cosmetic for trust, real feed-ranking input.
        \BCC\Trust\Core\REST\StokeEndpoint::register();

        // v1.5 hybrid PeepSo-proxy comments — GET / POST / DELETE under
        // /posts/:feed_id/comments. Reads peepso_activities directly via
        // CommentRepository (joined to wp_posts + wp_users); writes
        // route through PeepSoCommentWriter (single-graph rule, mirrors
        // PeepSoReactionWriter). Holder-groups gate is per-parent-post.
        \BCC\Trust\Core\REST\CommentsEndpoint::register();

        // Stoke on comments — POST / DELETE /comments/{id}/stoke. A comment
        // is a peepso_activities row, so it shares bcc_trust_stokes +
        // StokeRepository with the post rail; dedicated endpoint because the
        // group gate resolves off the PARENT post (the comment row has no
        // peepso_group_id). Plain X-"like" toggle, no heat_stage.
        \BCC\Trust\Core\REST\CommentStokeEndpoint::register();

        // V1 contract: §D1 Composer status posts — POST /posts.
        // Wraps PeepSoActivity::add_post via PeepSoStatusWriter
        // (single-graph rule); fires bcc_post_created on the §A3 bus.
        // v1.5: also handles POST /posts/photo (multipart) and
        // POST /posts/gif (Giphy URL) for the social-grammar slices.
        // §D6 PR-B (2026-05): also handles PATCH /posts/{id} (owner
        // edit), which wraps WP-native wp_save_post_revision +
        // wp_update_post + the existing BlogStatusTransitionHandler.
        \BCC\Trust\Core\REST\PostsEndpoint::register();

        // §D6 PR-B — crypto-blog composer support endpoints:
        //   POST /blog/cover-image  — multipart cover upload (writes
        //                             a WP attachment; the create /
        //                             update paths pin it via
        //                             set_post_thumbnail).
        //   GET  /blog/chain-options — picker source for the chain-tag
        //                              widget. Anonymous-readable, 1h
        //                              cache. Reads ChainRepository::getActive.
        \BCC\Trust\Core\REST\BlogCoverImageEndpoint::register();
        \BCC\Trust\Core\REST\BlogChainOptionsEndpoint::register();

        // v1.5 a11y: PATCH /photos/:pho_id/alt — author-only write
        // for the §3.3.9 alt-text deferred-debt slot. Stores into the
        // BCC-owned bcc_photo_alts sidecar (PeepSo's peepso_photos has
        // no native alt column).
        \BCC\Trust\Core\REST\PhotoAltEndpoint::register();

        // v1.5 integrations surface — exposes PeepSo admin-configured
        // integration toggles (currently Giphy) to the BCC frontend
        // so the composer can show/hide affordances based on what
        // the admin has actually enabled. Single source of truth:
        // PeepSo's wp_options.
        \BCC\Trust\Core\REST\IntegrationsEndpoint::register();

        // V1 contract: §O1.2 Heavy celebration delivery — pending +
        // consume. Backs the rank-up / level-up / tier-upgrade toast;
        // single-slot stash, last-write-wins.
        \BCC\Trust\Core\REST\CelebrationsEndpoint::register();

        // V1 contract: §K2 privacy toggles — GET + PATCH /me/privacy.
        // Storage in wp_usermeta.bcc_privacy_*; UserViewService applies
        // the seven profile flags to non-self responses; the eighth
        // (discovery_optout) hooks PeepSo's search via the filter
        // registered in registerHooks().
        \BCC\Trust\Core\REST\MyPrivacyEndpoint::register();

        // V1 contract: §K1 Phase A blocks — GET / POST / DELETE /me/blocks.
        // Storage in PeepSo's peepso_blocks table (read/write directly,
        // bypassing the user_blocking_enable option gate so blocks are
        // always enforceable). FeedRankingService merges viewer blocks
        // into the §O4.1 excluded-author list.
        \BCC\Trust\Core\REST\MyBlocksEndpoint::register();

        // V1.5: §I1 notification preferences — GET / PATCH
        // /me/notification-prefs. Per-event bell toggles + email-digest
        // opt-in. NotificationDispatcher::dispatch reads bell prefs to
        // gate writes; DigestService::sendWeeklyDigest reads the
        // email_digest flag to enumerate cron recipients.
        \BCC\Trust\Core\REST\MyNotificationPrefsEndpoint::register();

        // V2 Phase 2: §3.1 Profile + account self-edit:
        //   PATCH  /me/profile           — bio (text)
        //   POST   /me/profile/avatar    — upload avatar (multipart)
        //   DELETE /me/profile/avatar
        //   POST   /me/profile/cover     — upload cover photo (multipart)
        //   DELETE /me/profile/cover
        // Bio writes wp_users.description directly. Avatar + cover
        // wrap PeepSoUser's public methods so we don't reimplement
        // image processing (resize, multi-size, hash-named storage).
        \BCC\Trust\Core\REST\MyProfileEndpoint::register();

        // V2 Phase 2: PeepSo-backed messaging preferences:
        //   GET   /me/messages-prefs   — read chat_enabled + chat_friends_only
        //   PATCH /me/messages-prefs   — partial update
        // Writes peepso_chat_enabled + peepso_chat_friends_only user_meta;
        // peepso-messages/classes/chatmodel.php reads these to gate
        // direct message delivery.
        \BCC\Trust\Core\REST\MyMessagesPrefsEndpoint::register();

        // v1.5: §4.X Direct Messages — inbox + thread + send + read.
        // Composes bcc-core PeepSoMessageWriter (single-graph rule) +
        // PeepSoMessageRepository over PeepSo's peepso_message_*
        // tables and the peepso-message CPT. All gating (chat_enabled,
        // chat_friends_only + friendship, mutual blocks, rate limit)
        // lives inside MessagesService.
        \BCC\Trust\Core\REST\MessagesEndpoint::register();

        // v2 polling-coalesce: one cached payload replaces the two
        // unread-count polls + the per-thread "did my conversation
        // move forward?" 5s poll. See BadgesService for the cache
        // shape; invalidated from NotificationDispatcher::dispatch,
        // NotificationsEndpoint::markRead, MessagesService::sendMessage,
        // and MessagesService::markRead.
        \BCC\Trust\Core\REST\MeBadgesEndpoint::register();

        // V2 Phase 2.5: PeepSo profile-fields mirror — the headless
        // analogue of PeepSo's About sub-tab:
        //   GET   /me/profile/fields                       — schema + values + visibility
        //   PATCH /me/profile/fields/{key}                 — value
        //   PATCH /me/profile/fields/{key}/visibility      — public/members/private
        // Field catalogue lives in WP posts of type peepso_user_field
        // (admin-configured). We never invent fields — we delegate to
        // PeepSoField::save / save_acc so PeepSo's own surfaces
        // (search index, completeness counter) stay coherent.
        \BCC\Trust\Core\REST\MyProfileFieldsEndpoint::register();

        // V2 Phase 2.5: Account sub-tab — email / password / delete:
        //   PATCH  /me/account/email     — change email (requires current_password)
        //   PATCH  /me/account/password  — change password (requires current_password)
        //   DELETE /me/account           — delete account (requires current_password + confirm)
        // Every route re-verifies the current password rather than
        // relying on session elevation. Account deletion mirrors
        // PeepSo's `site_registration_allowdelete` toggle.
        \BCC\Trust\Core\REST\MyAccountEndpoint::register();

        // Wallet recovery (phase 2): let a wallet-only account attach a real,
        // verified recovery email, authenticated by a fresh wallet signature
        // instead of the password it never had. Verify-before-promote.
        //   POST /me/account/recovery-email         — prove wallet, mail OTP
        //   POST /me/account/recovery-email/verify  — confirm OTP, set user_email
        \BCC\Trust\Core\REST\RecoveryEmailEndpoint::register();

        // Tier D: in-app account-activity timeline — paginated read of
        // the user's own bcc_trust_activity rows filtered to the 6
        // user-facing security events (email change, password change,
        // account deletion, wallet link/unlink, sessions revoked all).
        //   GET /me/account-activity
        // Self-only; server-side action allowlist; IP masked at the
        // boundary. Pairs with AccountSecurityMailer for in-app/email
        // cross-channel audit trail.
        \BCC\Trust\Core\REST\MyAccountActivityEndpoint::register();

        // V2 Phase 2.5: Preferences sub-tab — profile-wide visibility +
        // post-privacy default + hide-birthday-year toggle:
        //   GET   /me/profile-prefs   — read current values
        //   PATCH /me/profile-prefs   — partial update
        // Writes peepso_users.usr_profile_acc (profile-wide gate),
        // peepso_profile_post_acc user_meta (wall-post default), and
        // peepso_hide_birthday_year user_meta. PeepSo's user-search joins
        // against usr_profile_acc, so writing through here keeps search
        // and feed gating coherent.
        \BCC\Trust\Core\REST\MyProfilePrefsEndpoint::register();

        // V2: NFT-gated holder groups — suggest-don't-auto-join model.
        //   GET  /me/holder-groups               — joined + eligible_to_join + opted_out
        //   POST /me/holder-groups/{id}/join     — explicit user-initiated join
        //   POST /me/holder-groups/{id}/leave    — leave + record TTL'd opt-out
        // Closed-group privacy + server-side gate (defense in depth);
        // PeepSoGroupWriter lands eligible holders directly as `member`.
        \BCC\Trust\Onchain\REST\HolderGroupsEndpoint::register();

        // Collection stances — the airdrop-proof demand + scam signals:
        //   GET    /me/collection-stances/panel — held collections + state
        //   POST   /me/collection-stances       — waitlist|spam (holder-gated)
        //   DELETE /me/collection-stances       — retract
        // Waitlist counts rank the admin Verify Collections queue; spam
        // tallies soft-hide at threshold (operator RULE_DENY = hard kill).
        \BCC\Trust\Onchain\REST\CollectionStancesEndpoint::register();

        // V2 Trust Attestation Layer — Slice C mutation endpoints:
        //   POST   /me/attestations              — cast new (vouch / stand_behind)
        //   DELETE /me/attestations/{id}         — revoke (soft-delete)
        //   POST   /me/attestations/{id}/reaffirm — refresh decay baseline
        // Locked §4.20 §J wire contract. Idempotent on
        // (attestor, target_kind, target_id, kind); Stand Behind
        // bandwidth slot enforcement per §J.1 (Elite 7 / Trusted 5 /
        // Neutral 3 / Caution+Risky 0). Audit + notification + cache
        // invalidation gated on real state transitions per §I1
        // destructive-mutation-hardening invariants.
        \BCC\Trust\Core\REST\MeAttestationsEndpoint::register();

        // V2 Trust Attestation Layer — Slice D roster read surface:
        //   GET /entities/{target_kind}/{target_id}/attestations  (§4.20 §J.4)
        // Paged + sorted roster for any of the four target kinds
        // (user_profile / validator_card / project_card / creator_card).
        // Auth-optional; anonymous viewers see the same row shape since
        // §J.4 rows carry no per-viewer state. Server-side ORDER BY by
        // decayed_weight / recency / reliability; numeric weights NEVER
        // surface in the response per §J.4.1 synthesis invisibility.
        \BCC\Trust\Core\REST\EntityAttestationsEndpoint::register();

        // Phase 2 entity tab parity (2026-05-14) — Reviews / Disputes /
        // Watchers tabs on /v, /p, /c profiles. Same /entities/{kind}/{id}
        // namespace as EntityAttestationsEndpoint so the by-target shape
        // stays consistent. Each service paginates a single bcc_trust_*
        // table read + hydrates authors/flaggers/watchers via the shared
        // MemberSummaryPrefetcher.
        \BCC\Trust\Core\REST\CardReviewsEndpoint::register();
        \BCC\Trust\Core\REST\CardWatchersEndpoint::register();

        // V2 Trust Attestation Layer — PR-2 self-mirror surface:
        //   GET /me/reliability  (§4.20 §J.5)
        // The asymmetric-display companion to the public roster — the
        // operator's own reliability numeric, trends, and slot
        // allocation. Self-only per §J.10 q14; the numeric never flows
        // to a third-party endpoint in V1. V1 baseline: live counts
        // (slots_used, since_attestation_count, slots_total) + zero'd
        // synthesis math (operator_reliability=0.0, trends.direction=
        // steady, outcomes={0,0,0,0}). Slice E populates the synthesis
        // via the bcc_attestor_reliability_cache read-model.
        \BCC\Trust\Core\REST\MeReliabilityEndpoint::register();

        // V2 Phase 6 (§H1): NFT-piece detail surface.
        //   GET /nft-pieces/{chain}/{contract}/{tokenId} — §3.7 NftPiece
        // Anonymous OR Bearer; response shape is identical for both
        // (V2 Phase 6 has no viewer-aware fields). Indexed chains hit
        // bcc_onchain_collection_pieces; Cosmos is read-time per
        // pattern-registry.
        \BCC\Trust\Onchain\REST\NftPieceEndpoint::register();

        // V2: Profile Groups tab — cross-kind list with viewer-aware
        // permissions and server-built action URLs (per `type`).
        \BCC\Trust\Core\REST\UserGroupsEndpoint::register();

        // §3.1 Photos tab → Albums sub-tab. Read-only over PeepSo
        // peepso_photos_album with viewer-aware privacy filter
        // mirroring PeepSoPhotosAlbumModel::get_user_photos_album.
        \BCC\Trust\Core\REST\UserAlbumsEndpoint::register();

        // §3.1 Photos drill-down — per-album photo list. Same privacy
        // gate re-checked here so stale IDs from the list endpoint
        // can't bypass a later friend-state flip.
        \BCC\Trust\Core\REST\UserAlbumPhotosEndpoint::register();

        // §3.1 Watching tab — followers ("Being Watched") + following
        // ("Keeping Tabs") sub-tabs. Respects watching_hidden privacy;
        // hydrates each row via UserViewService::getSummary.
        \BCC\Trust\Core\REST\UserFollowsEndpoint::register();

        // V2: Plain (non-gated, non-Local) group join/leave for the
        // residual case. Holder groups → /me/holder-groups; Locals →
        // /me/locals; this endpoint covers user/system groups.
        \BCC\Trust\Core\REST\MyGroupsEndpoint::register();

        // V2: Group discovery surface — verified-first, then heat-aware
        // ranking. ?verified=1 filters to On-Chain Verified groups only.
        \BCC\Trust\Core\REST\GroupsDiscoveryEndpoint::register();

        // V2: Cross-kind single-group detail (§4.7.5) + group-scoped
        // feed (§4.7.6). Defense-in-depth privacy gates: secret + non-
        // member 404s; closed/NFT-gated + non-member returns the view
        // model with feed_visible=false (detail) or 403 + unlock_hint
        // (feed sub-route).
        \BCC\Trust\Core\REST\GroupsDetailEndpoint::register();

        // V2: Paginated group members (§4.7.7). Same privacy model as
        // §4.7.5 + §4.7.6 — secret-non-member 404s; closed-non-member
        // 403s; open is public. Roster ordered by (role_rank, joined_at
        // DESC) so offset pagination is stable.
        \BCC\Trust\Core\REST\GroupMembersEndpoint::register();

        // V1.5: §I1 one-click email-digest unsubscribe — public route
        // that verifies a signed token (HMAC-SHA256, 90-day TTL) and
        // flips email_digest = false. Returns HTML, not JSON.
        \BCC\Trust\Core\REST\DigestUnsubscribeEndpoint::register();

        // V1.5: §I1 admin manual-trigger for the weekly digest cron.
        // POST /admin/digest/run-now — manage_options gated, 5-min
        // cooldown. For smoke-test convenience and post-incident
        // recovery when the scheduled run was missed.
        \BCC\Trust\Core\REST\AdminDigestEndpoint::register();

        // V2 Phase 1: §I1 web push subscription registration —
        //   GET    /me/push-subscriptions/vapid-public-key
        //   POST   /me/push-subscriptions
        //   DELETE /me/push-subscriptions/{id}
        // Side-effect on first POST: flips push_master = true so the
        // dispatcher (added in sub-phase 1.3) gates correctly.
        \BCC\Trust\Core\REST\MyPushSubscriptionEndpoint::register();

        // V1 contract: §K1 Phase B content reports — POST /me/reports.
        // Idempotent per (reporter, target) via UNIQUE constraint;
        // throttled to FLAG limits (5 per 5 min). Phase C subscribers
        // (auto-hide threshold, admin queue) attach to bcc_content_reported.
        \BCC\Trust\Core\REST\MyReportsEndpoint::register();

        // V1 contract: §K1 Phase C admin moderation —
        // GET /admin/reports + POST /admin/reports/:id/resolve.
        // Capability-gated to manage_options (V1 = admins only).
        \BCC\Trust\Core\REST\AdminReportsEndpoint::register();
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

        // Architecture A: votes on an ENTITY page no longer recompute the
        // page owner's PERSONAL trust tier (deliberate decoupling — a member's
        // tier is their self-page total_score, driven by reviews/endorsements
        // ON the person, not by reviews of pages they happen to own). The
        // legacy owner-reputation recalc listener was removed here; the
        // entity-page score itself is updated by the vote write path.

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

        // ── Suspension fanout ──────────────────────────────────────────
        //
        // Suspending/unsuspending a user changes their votes' and endorsements'
        // effective weight (suspended weight → 0). Scheduled async from
        // UserInfoRepository::suspendUser / unsuspendUser so admin actions
        // don't block on potentially large fan-outs for high-volume voters.
        add_action('bcc_trust_async_suspension_fanout', function (int $userId): void {
            try {
                $repo = $this->userInfoRepository();
                $repo->markVotedPagesForRecalculation($userId);
                $repo->markEndorsedPagesForRecalculation($userId);
                \BCC\Core\Log\Logger::info('[bcc-trust] suspension_fanout complete', [
                    'user_id' => $userId,
                ]);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] suspension_fanout failed', [
                    'user_id' => $userId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 10, 1);

        // ── §C3 watch-batch aggregator ──────────────────────────────────
        //
        // 1-minute recurring sweep that finds users whose watch batches
        // have been quiet for ≥10 minutes and closes them. The handler
        // is idempotent (UPDATE ... WHERE batch_id IS NULL guards races)
        // so missed ticks under cron pressure are safe.
        //
        // Hook name renamed 2026-05-13 from `bcc_pull_batch_sweep` to
        // `bcc_watch_batch_sweep`. Self-heal block below unschedules the
        // legacy hook on every plugins_loaded to drain stale events from
        // sites that upgraded without reactivating (cron-drift mitigation
        // mirrors V2 NFT Phase 1a/1c pattern).
        //
        // Custom cron interval registered via the cron_schedules filter;
        // the interval slug (`bcc_minute`) is unchanged — only the hook
        // name moved.
        add_filter('cron_schedules', static function (array $schedules): array {
            if (!isset($schedules[Services\WatchBatchAggregator::SWEEP_INTERVAL])) {
                $schedules[Services\WatchBatchAggregator::SWEEP_INTERVAL] = [
                    'interval' => 60,
                    'display'  => 'BCC: Every Minute',
                ];
            }
            return $schedules;
        });

        // Self-heal: unschedule the legacy hook on every load (idempotent
        // no-op once drained). The literal `bcc_pull_batch_sweep` string
        // MUST stay — it names the pre-rename hook so stale cron events
        // on mid-upgrade sites get cleared.
        wp_clear_scheduled_hook('bcc_pull_batch_sweep');

        if (!wp_next_scheduled(Services\WatchBatchAggregator::SWEEP_HOOK)) {
            wp_schedule_event(
                time(),
                Services\WatchBatchAggregator::SWEEP_INTERVAL,
                Services\WatchBatchAggregator::SWEEP_HOOK
            );
        }

        add_action(Services\WatchBatchAggregator::SWEEP_HOOK, function (): void {
            try {
                $this->watchBatchAggregator()->sweep();
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] watch_batch_sweep failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // ── §A3 activity-stream writer ──────────────────────────────────
        //
        // Translates BCC domain events into peepso_activities rows so
        // the feed surfaces (/feed, /feed/hot) include BCC content
        // alongside PeepSo-native posts. Single-source-of-truth per §A4
        // — no other code path should INSERT into peepso_activities for
        // BCC-owned modules (watch_batch, page_claim).
        //
        // bcc_card_watched is intentionally NOT subscribed: watches are
        // silent until the §C3 batch closes, and unwatches don't
        // retroactively edit emitted batch items.

        add_action('bcc_watch_batch_emitted', function (int $userId, string $batchId, int $cardCount, array $topCards, int $moreCount): void {
            try {
                $this->activityStreamWriter()->handleWatchBatchEmitted(
                    $userId, $batchId, $cardCount, $topCards, $moreCount
                );
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] activity_stream watch_batch failed', [
                    'user_id'  => $userId,
                    'batch_id' => $batchId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }, 10, 5);

        add_action('bcc_page_claimed', function (int $userId, int $pageId, string $entityType, int $entityId, string $role): void {
            try {
                $this->activityStreamWriter()->handlePageClaimed(
                    $userId, $pageId, $entityType, $entityId, $role
                );
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] activity_stream page_claim failed', [
                    'user_id'   => $userId,
                    'entity_id' => $entityId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }, 10, 5);

        add_action('bcc_review_published', function (int $authorId, int $pageId, int $voteId, string $explanation): void {
            try {
                $this->activityStreamWriter()->handleReviewPublished(
                    $authorId, $pageId, $voteId, $explanation
                );
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] activity_stream review failed', [
                    'user_id' => $authorId,
                    'vote_id' => $voteId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 10, 4);

        // §D6 — blog activity-row insertion at priority 10 (same tier as
        // pull/claim/review). Rank progression and notifications layer on
        // at higher priorities below.
        add_action('bcc_blog_post_created', function (int $authorId, int $postId): void {
            try {
                $this->activityStreamWriter()->handleBlogPostCreated($authorId, $postId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] activity_stream blog failed', [
                    'user_id' => $authorId,
                    'post_id' => $postId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 10, 2);

        // §D6 PR-A — WP transition_post_status bridge for blog posts.
        //
        // Fires on every WP post status transition; the handler scopes
        // itself to peepso-activity-status + _bcc_activity_module='blog'
        // before doing anything. Wiring here (not in createBlog) ensures
        // the handler also fires for status changes initiated from
        // wp-admin (rare today, but the Edit Post screen exists).
        //
        // Priority 10 — same tier as the blog event subscriber. WP
        // passes three positional args: ($new_status, $old_status, $post).
        add_action('transition_post_status', function (string $newStatus, string $oldStatus, mixed $post): void {
            try {
                $this->blogStatusTransitionHandler()->handle($newStatus, $oldStatus, $post);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] blog_status_transition failed', [
                    'new_status' => $newStatus,
                    'old_status' => $oldStatus,
                    'post_id'    => $post instanceof \WP_Post ? $post->ID : 0,
                    'error'      => $e->getMessage(),
                ]);
            }
        }, 10, 3);

        // ── §E2 / §O1.2 rank progression listener ──────────────────────
        //
        // Detects auto-derived rank changes off the back of activity
        // events and stashes a Heavy celebration when the user's rank
        // climbs. Single entry point on the listener; each subscriber
        // funnels the right user_id through.
        //
        // Priority 20 (later than the activity-stream writer at 10) so
        // the rank check sees any reputation deltas that recompute
        // synchronously inside the originating service. For deltas
        // that recompute via async jobs, the next activity event the
        // user touches will catch the promotion — eventual consistency
        // is acceptable for celebrations (see RankProgressionListener
        // class docstring).

        add_action('bcc_post_created', function (int $authorId): void {
            try {
                $this->rankProgressionListener()->onActivityEvent($authorId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] rank_progression post_created failed', [
                    'user_id' => $authorId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 20, 1);

        add_action('bcc_review_published', function (int $authorId): void {
            try {
                $this->rankProgressionListener()->onActivityEvent($authorId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] rank_progression review failed', [
                    'user_id' => $authorId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 20, 1);

        add_action('bcc_blog_post_created', function (int $authorId): void {
            try {
                $this->rankProgressionListener()->onActivityEvent($authorId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] rank_progression blog failed', [
                    'user_id' => $authorId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 20, 1);

        // Subscribed to the canonical `bcc_card_watched` event —
        // WatchingService's single watch event.
        add_action('bcc_card_watched', function (int $viewerId): void {
            try {
                $this->rankProgressionListener()->onActivityEvent($viewerId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] rank_progression watch failed', [
                    'user_id' => $viewerId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 20, 1);

        add_action('bcc_trust_vote_cast', function (int $voterId): void {
            try {
                $this->rankProgressionListener()->onActivityEvent($voterId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] rank_progression vote failed', [
                    'user_id' => $voterId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 20, 1);

        // ── §O1.2 first-action celebrations (retention pass 2026-05-13) ──
        //
        // One-shot stashings for first_post / first_review / first_blog.
        // Each is gated by a dedicated user_meta flag so they fire at
        // most once per user lifetime. See FirstActionListener docstring
        // for the deferred kinds (first_watcher, first_local_joined,
        // first_reply_received) — those need hook changes or owner-of-
        // target lookups not available with the current actor-only
        // signatures.
        //
        // Priority 20 matches the rank-progression listener block — same
        // semantics: fires after the originating service has committed
        // its mutation; failures here never break the request path.

        add_action('bcc_post_created', function (int $authorId): void {
            try {
                $this->firstActionListener()->onPostCreated($authorId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] first_action post failed', [
                    'user_id' => $authorId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 20, 1);

        add_action('bcc_review_published', function (int $authorId): void {
            try {
                $this->firstActionListener()->onReviewPublished($authorId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] first_action review failed', [
                    'user_id' => $authorId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 20, 1);

        add_action('bcc_blog_post_created', function (int $authorId): void {
            try {
                $this->firstActionListener()->onBlogPostCreated($authorId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] first_action blog failed', [
                    'user_id' => $authorId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 20, 1);

        // Social-gravity celebrations (2026-05-13 retention pass):
        // first_watcher fires on the RECIPIENT (page owner) when their
        // page or user-self is followed for the first time. The hook
        // carries 4 args; we capture all so the resolver can branch on
        // targetKind. Self-pulls and corrupted events no-op inside the
        // listener.
        add_action('bcc_card_watched', function (int $viewerId, int $followId, string $targetKind, int $targetId): void {
            try {
                $this->firstActionListener()->onCardWatched($viewerId, $followId, $targetKind, $targetId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] first_action card_watched failed', [
                    'viewer_id'   => $viewerId,
                    'target_kind' => $targetKind,
                    'target_id'   => $targetId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }, 20, 4);

        // ── §C1 / §N11 / §O1.2 tier-upgrade listener ─────────────────────
        //
        // Detects reputation_tier crossings off the back of activity
        // events and stashes a Heavy "Your card is now Rare" celebration
        // when the user's tier ladder climbs. Mirrors the rank listener
        // shape; same activity events drive both.
        //
        // Priority 25 — after rank progression (20) so a rank promotion
        // and a tier upgrade emitted by the same event don't fight: the
        // tier toast wins (last-write-wins on the single-slot stash),
        // which matches the §N11 "your card is now Rare" copy users
        // expect to see when both fire together.

        add_action('bcc_post_created', function (int $authorId): void {
            try {
                $this->tierUpgradeListener()->onActivityEvent($authorId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] tier_upgrade post_created failed', [
                    'user_id' => $authorId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 25, 1);

        add_action('bcc_review_published', function (int $authorId): void {
            try {
                $this->tierUpgradeListener()->onActivityEvent($authorId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] tier_upgrade review failed', [
                    'user_id' => $authorId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 25, 1);

        add_action('bcc_blog_post_created', function (int $authorId): void {
            try {
                $this->tierUpgradeListener()->onActivityEvent($authorId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] tier_upgrade blog failed', [
                    'user_id' => $authorId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 25, 1);

        add_action('bcc_card_watched', function (int $viewerId): void {
            try {
                $this->tierUpgradeListener()->onActivityEvent($viewerId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] tier_upgrade watch failed', [
                    'user_id' => $viewerId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 25, 1);

        add_action('bcc_trust_vote_cast', function (int $voterId): void {
            try {
                $this->tierUpgradeListener()->onActivityEvent($voterId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] tier_upgrade vote failed', [
                    'user_id' => $voterId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }, 25, 1);

        // ── §K1 Phase C — auto-hide threshold subscriber ─────────────────
        //
        // Counts pending reports against the target after each new
        // report; when the count crosses the configured threshold
        // (default 3), inserts a bcc_hidden_activities row. The next
        // feed read filters the act_id out for non-mods.
        //
        // Priority 30 — after the report row is written by the
        // originating endpoint and after activity-stream writers (10).

        add_action(
            'bcc_content_reported',
            function (int $reporterUserId, string $targetKind, int $targetId, int $reportId, string $reasonCode): void {
                try {
                    $this->autoHideService()->onContentReported(
                        $reporterUserId, $targetKind, $targetId, $reportId, $reasonCode
                    );
                } catch (\Throwable $e) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] autohide_subscriber failed', [
                        'target_kind' => $targetKind,
                        'target_id'   => $targetId,
                        'report_id'   => $reportId,
                        'error'       => $e->getMessage(),
                    ]);
                }
            },
            30,
            5
        );

        // ── §I1 notifications dispatcher ────────────────────────────────
        //
        // Translates BCC events into peepso_notifications rows so users
        // see a record of activity that touched their content. Each
        // subscriber is wrapped in try/catch inside the dispatcher
        // method itself — failures log at warning level but never
        // break the originating request.
        //
        // Priority 30 (after activity-stream writers at 10 and rank
        // progression at 20) so the message can reference any state
        // those subscribers wrote first.

        add_action('bcc_reaction_added', function (int $actorId, int $actId, string $kind): void {
            $this->notificationDispatcher()->onReactionAdded($actorId, $actId, $kind);
        }, 30, 3);

        // Vouch is no longer a post reaction — it relocated to the first-class
        // per-author byline Vouch toggle (full-weight cast() via
        // /me/attestations). The old reaction→light-attestation subscribers
        // were retired here; existing light vouches age out (DecayResolver).

        add_action('bcc_review_published', function (int $authorId, int $pageId, int $voteId, string $explanation): void {
            $this->notificationDispatcher()->onReviewPublished($authorId, $pageId, $voteId, $explanation);
        }, 30, 4);

        add_action('bcc_card_watched', function (int $viewerId, int $followId, string $targetKind, int $targetId): void {
            $this->notificationDispatcher()->onCardWatched($viewerId, $followId, $targetKind, $targetId);
        }, 30, 4);

        add_action('bcc_rank_awarded', function (int $userId, string $newRank, string $oldRank): void {
            $this->notificationDispatcher()->onRankAwarded($userId, $newRank, $oldRank);
        }, 30, 3);

        // Slice E — fold the attestation into the subject's trust score. Runs
        // at priority 25, BEFORE the notification dispatch (30) so a freshly
        // recomputed score is visible to the notification + any read that
        // follows. recomputeFor() resolves the score-row page via the locked
        // target_id invariant (selfPageId for user_profile, raw id for *_card).
        // Per-event try/catch → a synthesis failure surfaces as an
        // `attestation_synthesis` DegradationMetric and NEVER throws into the
        // event chain (mirrors ContributionRecoveryEvaluator isolation).
        $bcc_attestation_score_recompute = function (
            int $attestorId,
            int $attestationId,
            string $targetKind,
            int $targetId
        ): void {
            try {
                $this->attestationScoreSynthesis()->recomputeFor($targetKind, $targetId);
            } catch (\Throwable $e) {
                \BCC\Core\Observability\DegradationMetrics::record('attestation_synthesis', 'event_recompute_failed');
                \BCC\Core\Log\Logger::error('attestation score recompute failed', [
                    'target_kind' => $targetKind,
                    'target_id'   => $targetId,
                    'error'       => $e->getMessage(),
                ]);
            }
        };
        add_action('bcc_attestation_created', $bcc_attestation_score_recompute, 25, 4);
        add_action('bcc_attestation_revoked', $bcc_attestation_score_recompute, 25, 4);
        add_action('bcc_attestation_reaffirmed', $bcc_attestation_score_recompute, 25, 4);

        // §I1 V2 Trust Attestation Layer — bell + push dispatch for
        // attestation lifecycle events. Fired by AttestationService::cast
        // / ::revoke / ::reaffirm post-commit; each subscriber wraps in
        // try/catch (dispatcher itself also wraps) so a notification
        // failure never affects the originating mutation.
        //
        // Self-attest is structurally rejected upstream (§N7); the
        // dispatcher methods include defense-in-depth self-skip.
        add_action(
            'bcc_attestation_created',
            function (
                int $attestorId,
                int $attestationId,
                string $targetKind,
                int $targetId,
                string $kind,
                int $targetOwnerId
            ): void {
                $this->notificationDispatcher()->onAttestationCreated(
                    $attestorId,
                    $attestationId,
                    $targetKind,
                    $targetId,
                    $kind,
                    $targetOwnerId
                );
            },
            30,
            6
        );

        // Collection-stances slice — waitlist go-live bell. Fired by
        // GatedGroupProvisioningService when a verified collection's
        // holder community is created. Dispatcher is idempotent per
        // (user, collection) via CollectionSignalRepository.notified_at,
        // so re-provision sweeps can't double-bell.
        add_action(
            'bcc_gated_group_provisioned',
            function (int $groupId, int $collectionId, int $chainId, string $contract): void {
                $this->notificationDispatcher()->onHolderCommunityProvisioned(
                    $groupId,
                    $collectionId,
                    $chainId,
                    $contract
                );
            },
            30,
            4
        );

        add_action(
            'bcc_attestation_revoked',
            function (
                int $attestorId,
                int $attestationId,
                string $targetKind,
                int $targetId,
                string $kind,
                int $targetOwnerId
            ): void {
                $this->notificationDispatcher()->onAttestationRevoked(
                    $attestorId,
                    $attestationId,
                    $targetKind,
                    $targetId,
                    $kind,
                    $targetOwnerId
                );
            },
            30,
            6
        );

        add_action(
            'bcc_attestation_reaffirmed',
            function (
                int $attestorId,
                int $attestationId,
                string $targetKind,
                int $targetId,
                string $kind,
                int $targetOwnerId
            ): void {
                $this->notificationDispatcher()->onAttestationReaffirmed(
                    $attestorId,
                    $attestationId,
                    $targetKind,
                    $targetId,
                    $kind,
                    $targetOwnerId
                );
            },
            30,
            6
        );

        // V2 Phase 2 retention slice — first-touch welcome on signup.
        // Hooks WordPress core's user_register so it fires for ANY new
        // user creation path (/auth/signup, wp-admin Add User, wp-cli,
        // partner integrations). The dispatcher self-gates on the
        // `bcc_welcomed` user_meta idempotency flag, so a duplicate
        // user_register event would no-op. Priority 30 keeps it
        // consistent with the other notification dispatchers.
        add_action('user_register', function (int $userId): void {
            $this->notificationDispatcher()->onUserSignup($userId);
        }, 30, 1);

        // §I1 V2 — @-mention bell + push dispatch. Two subscribers,
        // both ORIGINAL-WRITE ONLY (no `*_edited` action exists in
        // bcc-trust by design, so an edit-as-ping abuse vector cannot
        // fire). The dispatcher orchestrator extracts mentions fresh
        // from the post body, re-applies MentionPolicy (defense in
        // depth — write-time validation already filtered, but a future
        // path that skips it would still be safe), and dedupes via
        // MentionExtractor::extractUserIds' unique-id contract.
        add_action('bcc_post_created', function (int $authorId, int $postId, int $actId): void {
            $post = get_post($postId);
            if (!$post instanceof \WP_Post) {
                return;
            }
            $this->notificationDispatcher()->dispatchMentionsFor(
                $authorId,
                $postId,
                (string) $post->post_content,
                $actId,
                false
            );
        }, 30, 3);

        // Comment mentions deep-link to the PARENT post (act_id =
        // $parentActId), not the comment activity row — the FE has no
        // comment-anchor consumer in V1. The user lands on the post on
        // the floor and scrolls to find the comment.
        add_action('bcc_comment_created', function (int $authorId, int $parentActId, int $newActId, int $newCommentPostId): void {
            unset($newActId);
            $comment = get_post($newCommentPostId);
            if (!$comment instanceof \WP_Post) {
                return;
            }
            $this->notificationDispatcher()->dispatchMentionsFor(
                $authorId,
                $newCommentPostId,
                (string) $comment->post_content,
                $parentActId,
                true
            );
        }, 30, 4);

        // §I1 V2 — comment-on-your-post dispatch. Second bcc_comment_created
        // subscriber at priority 31 so it runs AFTER the mention
        // dispatcher (priority 30). If the commenter @-tagged the post
        // author, BOTH events fire for the same recipient — they're
        // semantically distinct (mention = "called out"; comment-received
        // = "your post has activity"), each independently toggleable
        // in prefs. Recipient resolution + self-skip lives inside
        // onCommentReceived; the subscriber stays a thin trigger.
        add_action('bcc_comment_created', function (int $authorId, int $parentActId, int $newActId, int $newCommentPostId): void {
            unset($newActId);
            $this->notificationDispatcher()->onCommentReceived(
                $authorId,
                $parentActId,
                $newCommentPostId
            );
        }, 31, 4);

        // §I1 V2 — primary-Local post fan-out. Second bcc_post_created
        // subscriber at priority 31 so it runs AFTER the mention
        // dispatcher (priority 30). Pre-gates the post on
        // (group_id > 0) AND (group is a Local) before paying the
        // async-enqueue cost — non-Local posts (NFT-gated holder groups,
        // plain user groups, system groups, personal-wall posts) never
        // touch the dispatcher. Always async via AsyncDispatcher because
        // a popular Local could fan out to thousands of recipients;
        // sync would blow the §L1 300ms request budget.
        add_action('bcc_post_created', function (int $authorId, int $postId, int $actId): void {
            $groupId = (int) get_post_meta($postId, 'peepso_group_id', true);
            if ($groupId <= 0) {
                return;
            }
            // Pre-gate: is this group actually a Local? PeepSoGroupRepository's
            // findOneById applies `post_title LIKE 'Local %'` automatically.
            $group = \BCC\Core\Repositories\PeepSoGroupRepository::findOneById($groupId);
            if ($group === null) {
                return;
            }
            \BCC\Core\Cron\AsyncDispatcher::enqueueAsync(
                'bcc_primary_local_post_fanout',
                [$authorId, $postId, $actId, $groupId]
            );
        }, 31, 3);

        // Async worker — invoked by the Action Scheduler / wp-cron
        // event scheduled above. Hands off to the dispatcher orchestrator
        // which resolves the recipient set + per-recipient bell + push.
        add_action('bcc_primary_local_post_fanout', function (int $authorId, int $postId, int $actId, int $groupId): void {
            $this->notificationDispatcher()->dispatchPrimaryLocalPostFor(
                $authorId,
                $postId,
                $actId,
                $groupId
            );
        }, 10, 4);

        // ── §H1 NFT gallery refresh ─────────────────────────────────────
        //
        // Dispatched by CreatorGalleryEndpoint when the visible page has
        // any expired collection rows (or the creator has wallets but no
        // rows at all). Single-shot per request — the endpoint's
        // 5-minute transient lock dedupes concurrent dispatches.
        //
        // Per fetcher behaviour (see FetcherInterface): chains without
        // an NFT data path return [] without burning API budget. Chains
        // that DO support fetch_collections silently no-op when the
        // required API key constant isn't defined — this lets the
        // architecture ship now and key wiring land later without
        // touching the dispatcher.

        add_action(
            \BCC\Trust\Core\REST\CreatorGalleryEndpoint::REFRESH_HOOK,
            function (int $postId): void {
                if ($postId <= 0) {
                    return;
                }
                try {
                    $wallets = \BCC\Trust\Onchain\Repositories\WalletRepository::getForProject($postId);
                    if (empty($wallets)) {
                        return;
                    }
                    foreach ($wallets as $wallet) {
                        $chainId = isset($wallet->chain_id) ? (int) $wallet->chain_id : 0;
                        $address = isset($wallet->wallet_address) && is_string($wallet->wallet_address)
                            ? $wallet->wallet_address
                            : '';
                        $walletId = isset($wallet->id) ? (int) $wallet->id : 0;
                        if ($chainId <= 0 || $address === '' || $walletId <= 0) {
                            continue;
                        }

                        $chain = \BCC\Trust\Onchain\Repositories\ChainRepository::getById($chainId);
                        if ($chain === null) {
                            continue;
                        }
                        if (!\BCC\Trust\Onchain\Factories\FetcherFactory::has_driver(
                            (string) $chain->chain_type
                        )) {
                            continue;
                        }
                        $fetcher = \BCC\Trust\Onchain\Factories\FetcherFactory::make_for_chain($chain);
                        // Gate on 'collection' — the capability fetch_collections()
                        // actually requires. No fetcher advertises 'nft' (the enum
                        // key was never implemented), so the old gate skipped every
                        // chain and this refresh silently no-op'd. [audit M]
                        if (!$fetcher->supports_feature('collection')) {
                            continue;
                        }

                        $collections = $fetcher->fetch_collections($address, $chainId);
                        foreach ($collections as $row) {
                            \BCC\Trust\Onchain\Repositories\CollectionRepository::upsert(
                                $row,
                                $walletId
                            );
                        }
                        \BCC\Trust\Onchain\Repositories\WalletRepository::markHoldingsRefreshed($walletId);
                    }
                } catch (\Throwable $e) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] gallery_refresh failed', [
                        'post_id' => $postId,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
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

            // Phase 3 observability: per-canonical-cron next-run timestamps.
            // `null` means "not currently scheduled" — that's the alarm
            // signal (a hook should ALWAYS have a wp_next_scheduled when
            // the activation registry is healthy). Operators correlate
            // these against the current time + the registered interval
            // (see docs/cron-registry.md) to detect missed cron windows.
            $now = time();
            $nextRun = static function (string $hook) use ($now): ?array {
                $ts = wp_next_scheduled($hook);
                if ($ts === false) {
                    return null;
                }
                return [
                    'next_run_ts'   => (int) $ts,
                    'in_seconds'    => (int) $ts - $now,
                ];
            };
            $health['cron_status'] = [
                'bcc_disputes_reconcile_orphans' => $nextRun(\BCC\Trust\Disputes\Services\DisputeScheduler::EVENT_RECONCILE),
                'bcc_gated_group_provision'      => $nextRun('bcc_gated_group_provision'),
                'bcc_gated_group_reconcile_sweep' => $nextRun('bcc_gated_group_reconcile_sweep'),
                'bcc_nft_eth_indexer_tick'       => $nextRun(\BCC\Trust\Onchain\Workers\NftEthIndexerWorker::CRON_HOOK),
                'bcc_nft_enrichment_tick'        => $nextRun(\BCC\Trust\Onchain\Services\NftEnrichmentService::CRON_HOOK),
                'bcc_helius_dedupe_sweep'        => $nextRun('bcc_helius_dedupe_sweep'),
                'bcc_trust_daily_graph_update'   => $nextRun('bcc_trust_daily_graph_update'),
                'bcc_trust_process_recalculations' => $nextRun('bcc_trust_process_recalculations'),
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

}
