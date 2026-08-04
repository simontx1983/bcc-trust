<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Disputes\Controllers\DisputeController;
use BCC\Trust\Disputes\DTO\DisputeCoreDTO;
use BCC\Trust\Disputes\Services\DisputeVoteException;
use BCC\Trust\Rank\Support\RankScoringConfig;
use BCC\Trust\Tests\Stubs\InMemoryBallotRepository;
use BCC\Trust\Tests\Stubs\InMemoryPollRepository;
use BCC\Trust\Tests\Stubs\RankUnitWpFakes;
use BCC\Trust\Tests\Stubs\StubClusterFindingsRepository;
use BCC\Trust\Tests\Stubs\TestableDisputeVoteService;
use BCC\Trust\Tests\Stubs\TestPollService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Stubs/rank-unit-stubs.php';
require_once __DIR__ . '/../Stubs/poll-engine-stubs.php';
require_once __DIR__ . '/../Stubs/dispute-vote-stubs.php';

/**
 * Rank Phase 6 Wave 3 — panel retirement transition suite (master-plan
 * R4, no-dual-authority):
 *
 *   RETIREMENT (structural — there is exactly ONE adjudication path):
 *   - DisputePanelRepository no longer exists anywhere;
 *   - DisputeController exposes neither castPanelVote handler
 *     (cast_vote) nor the panel queue, and its route registration
 *     contains no /disputes/panel or participation route;
 *   - DisputeVoteService::onPollClosed is the poll→dispute resolution
 *     entry, matching the 5-positional-arg bcc_trust_poll_closed
 *     contract.
 *
 *   LIVE GATE (service-level, real PollService over in-memory repos):
 *   - all three §18 party exclusions (opener, disputed voter, page
 *     owner) fail closed;
 *   - Apprentice+ (missing rank row) and Neutral+ (tier below neutral)
 *     fail closed; fraud hard-block fails closed;
 *   - choice vocabulary maps uphold→for / reject→against;
 *   - poll outcomes map passed→accepted, failed→rejected, and
 *     inconclusive→timeout_no_quorum (the no-consequence terminal —
 *     DisputeResolverTest pins that executeAdjudication never invokes
 *     the adjudicator for it);
 *   - C10: the viewer state for an OPEN dispute vote carries no tally
 *     keys.
 */
final class DisputePanelRetirementTest extends TestCase
{
    private const DISPUTE_ID = 42;
    private const VOTE_ID    = 101;
    private const PAGE_ID    = 77;
    private const VOTER_ID   = 31; // owner of the disputed vote
    private const REPORTER_ID = 29; // dispute opener (page owner at submit time)
    private const OWNER_ID   = 33; // LIVE page-owner resolution (ownership can move — still excluded)
    private const RANKED_ID  = 500; // an eligible community voter

    private InMemoryPollRepository $polls;

    private InMemoryBallotRepository $ballots;

    private TestableDisputeVoteService $service;

    protected function setUp(): void
    {
        RankUnitWpFakes::reset();

        $this->polls   = new InMemoryPollRepository();
        $this->ballots = new InMemoryBallotRepository();

        $config = RankScoringConfig::fromDefaultFile();

        $this->service = new TestableDisputeVoteService(
            new TestPollService(
                $this->polls,
                $this->ballots,
                new StubClusterFindingsRepository(),
                $config
            ),
            $this->polls,
            $config
        );

        $this->service->disputes[self::DISPUTE_ID] = new DisputeCoreDTO(
            id:          self::DISPUTE_ID,
            status:      'reviewing',
            vote_id:     self::VOTE_ID,
            page_id:     self::PAGE_ID,
            voter_id:    self::VOTER_ID,
            reporter_id: self::REPORTER_ID,
        );
        $this->service->pageOwners[self::PAGE_ID] = self::OWNER_ID;

        // The default community voter is fully eligible.
        $this->service->rankRows[self::RANKED_ID] = (object) ['rank_slug' => 'apprentice'];
        $this->service->tiers[self::RANKED_ID]    = 'neutral';
    }

    // ── retirement: no dual authority ────────────────────────────────

    public function testDisputePanelRepositoryClassIsGone(): void
    {
        self::assertFalse(
            class_exists('BCC\\Trust\\Disputes\\Repositories\\DisputePanelRepository'),
            'DisputePanelRepository must be deleted, not orphaned'
        );
    }

    public function testControllerExposesNoPanelHandlers(): void
    {
        self::assertFalse(
            method_exists(DisputeController::class, 'cast_vote'),
            'the panel castPanelVote handler must be gone'
        );
        self::assertFalse(
            method_exists(DisputeController::class, 'panel_queue'),
            'the panel queue handler must be gone'
        );
        self::assertFalse(
            method_exists(DisputeController::class, 'my_participation'),
            'the §D5 panel-participation endpoint must be gone'
        );
    }

    public function testRouteRegistrationCarriesNoPanelRoutes(): void
    {
        // Grep-free reflection: read exactly the register_routes() body
        // from the shipped controller and assert the retired routes are
        // absent while the poll-engine vote route is present.
        $method = new \ReflectionMethod(DisputeController::class, 'register_routes');
        $file   = (string) $method->getFileName();
        $lines  = file($file);
        self::assertIsArray($lines);
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringNotContainsString('/disputes/panel', $body);
        self::assertStringNotContainsString('participation', $body);
        self::assertStringContainsString("/disputes/(?P<id>\\d+)/vote", $body);
    }

    public function testOnPollClosedIsThePollResolutionEntry(): void
    {
        $method = new \ReflectionMethod(\BCC\Trust\Disputes\Services\DisputeVoteService::class, 'onPollClosed');

        // The bcc_trust_poll_closed hook fires 5 positional args
        // (pollId, outcome, pollType, subjectType, subjectId).
        self::assertSame(5, $method->getNumberOfRequiredParameters());
        self::assertTrue($method->isPublic());
    }

    // ── live gate: §18 party exclusions ──────────────────────────────

    /** @return list<array{0: int, 1: string}> */
    public static function partyProvider(): array
    {
        return [
            'dispute opener'        => [self::REPORTER_ID, 'opener'],
            'disputed voter'        => [self::VOTER_ID, 'disputed voter'],
            'disputed page owner'   => [self::OWNER_ID, 'page owner'],
        ];
    }

    #[DataProvider('partyProvider')]
    public function testPartiesToTheDisputeCannotVote(int $partyId, string $label): void
    {
        $this->openDisputePoll();
        // Parties are otherwise fully eligible — the exclusion itself
        // must be what blocks them.
        $this->service->rankRows[$partyId] = (object) ['rank_slug' => 'veteran'];
        $this->service->tiers[$partyId]    = 'elite';

        try {
            $this->service->castOrChange(self::DISPUTE_ID, $partyId, 'uphold');
            self::fail("{$label} must not be able to vote on the dispute");
        } catch (DisputeVoteException $e) {
            self::assertSame(DisputeVoteException::KIND_FORBIDDEN, $e->kind);
            self::assertSame('party_to_dispute', $e->reason, $label);
        }

        self::assertSame([], $this->ballots->rows, 'no ballot may be written for a party');
    }

    public function testUnresolvablePageOwnerFailsClosed(): void
    {
        $this->openDisputePoll();
        unset($this->service->pageOwners[self::PAGE_ID]);

        $this->expectException(DisputeVoteException::class);
        $this->service->castOrChange(self::DISPUTE_ID, self::RANKED_ID, 'uphold');
    }

    // ── live gate: rank / tier / fraud fail closed ───────────────────

    public function testNewMemberWithoutRankRowCannotVote(): void
    {
        $this->openDisputePoll();
        unset($this->service->rankRows[self::RANKED_ID]);

        try {
            $this->service->castOrChange(self::DISPUTE_ID, self::RANKED_ID, 'uphold');
            self::fail('missing rank_state row must fail closed');
        } catch (DisputeVoteException $e) {
            self::assertSame(DisputeVoteException::KIND_FORBIDDEN, $e->kind);
            self::assertSame('rank_required', $e->reason);
        }
    }

    public function testBelowNeutralTierCannotVote(): void
    {
        $this->openDisputePoll();
        $this->service->tiers[self::RANKED_ID] = 'caution';

        try {
            $this->service->castOrChange(self::DISPUTE_ID, self::RANKED_ID, 'uphold');
            self::fail('tier below neutral must fail closed');
        } catch (DisputeVoteException $e) {
            self::assertSame(DisputeVoteException::KIND_FORBIDDEN, $e->kind);
            self::assertSame('tier_required', $e->reason);
        }
    }

    public function testFraudHardBlockCannotVote(): void
    {
        $this->openDisputePoll();
        $this->service->snapshots[self::RANKED_ID] = [
            'rank_slug'        => 'apprentice',
            'maturity'         => 1.0,
            'rank_multiplier'  => 1.0,
            'trust_multiplier' => 1.0,
            'trust_score'      => 50.0,
            'fraud_discount'   => 0.0, // FraudDiscountCalculator hard block
            'effective_weight' => 0.0,
        ];

        try {
            $this->service->castOrChange(self::DISPUTE_ID, self::RANKED_ID, 'uphold');
            self::fail('fraud hard-block must fail closed');
        } catch (DisputeVoteException $e) {
            self::assertSame(DisputeVoteException::KIND_FORBIDDEN, $e->kind);
            self::assertSame('fraud_blocked', $e->reason);
        }
    }

    // ── choice mapping ───────────────────────────────────────────────

    public function testChoiceVocabularyMapsOntoEngineChoices(): void
    {
        $this->openDisputePoll();

        $this->service->castOrChange(self::DISPUTE_ID, self::RANKED_ID, 'uphold');

        $second = 501;
        $this->service->rankRows[$second] = (object) ['rank_slug' => 'journeyman'];
        $this->service->tiers[$second]    = 'trusted';
        $this->service->castOrChange(self::DISPUTE_ID, $second, 'reject');

        $choices = array_map(static fn(object $row): string => $row->choice, array_values($this->ballots->rows));
        self::assertSame(['for', 'against'], $choices, "uphold→'for', reject→'against'");

        // And the viewer reads their own choice back in dispute vocabulary.
        $state = $this->service->viewerState(self::DISPUTE_ID, self::RANKED_ID);
        self::assertSame('uphold', $state['viewer']['my_choice']);
    }

    // ── outcome mapping (single resolution path) ─────────────────────

    public function testPassedPollEnqueuesAcceptedThroughExistingMachinery(): void
    {
        $this->openDisputePoll();

        $this->service->onPollClosed(9, 'passed', 'dispute', 'dispute', self::DISPUTE_ID);

        self::assertSame(
            [[self::DISPUTE_ID, self::VOTE_ID, self::PAGE_ID, self::VOTER_ID, self::REPORTER_ID, 'accepted']],
            $this->service->enqueued,
            'passed → accepted through the existing async-resolve args'
        );
    }

    public function testFailedPollEnqueuesRejected(): void
    {
        $this->openDisputePoll();

        $this->service->onPollClosed(9, 'failed', 'dispute', 'dispute', self::DISPUTE_ID);

        self::assertSame('rejected', $this->service->enqueued[0][5]);
    }

    public function testInconclusivePollAppliesNoConsequence(): void
    {
        $this->openDisputePoll();

        $this->service->onPollClosed(9, 'inconclusive', 'dispute', 'dispute', self::DISPUTE_ID);

        // timeout_no_quorum is the no-consequence terminal:
        // DisputeResolver::executeAdjudication early-returns on it (no
        // adjudicator call, no reporter penalty) — pinned by
        // DisputeResolverTest::testTimeoutNoQuorumDoesNotCallAdjudicator.
        self::assertSame('timeout_no_quorum', $this->service->enqueued[0][5]);
    }

    public function testForeignPollTypesAreIgnored(): void
    {
        $this->service->onPollClosed(9, 'passed', 'hall', 'page', self::PAGE_ID);

        self::assertSame([], $this->service->enqueued);
    }

    // ── C10: open vote exposes no tallies ────────────────────────────

    public function testOpenDisputeVoteViewCarriesNoTallyKeys(): void
    {
        $this->openDisputePoll();
        $this->service->castOrChange(self::DISPUTE_ID, self::RANKED_ID, 'uphold');

        $state = $this->service->viewerState(self::DISPUTE_ID, self::RANKED_ID);

        self::assertSame('open', $state['status']);
        self::assertArrayNotHasKey('tally', $state);
        self::assertArrayNotHasKey('outcome', $state);
        self::assertSame(
            ['dispute_id', 'status', 'opened_at', 'binding_earliest_at', 'expires_at', 'viewer'],
            array_keys($state),
            'C10: an open vote exposes windows + own ballot facts ONLY'
        );
        self::assertSame(
            ['my_choice', 'my_effective_weight', 'can_change', 'can_withdraw'],
            array_keys($state['viewer'])
        );
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function openDisputePoll(): int
    {
        $pollId = $this->service->openPollForDispute(self::DISPUTE_ID);
        self::assertGreaterThan(0, $pollId, 'test fixture: poll must open');
        return $pollId;
    }
}
