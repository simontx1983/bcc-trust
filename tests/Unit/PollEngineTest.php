<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Rank\Support\RankScoringConfig;
use BCC\Trust\Tests\Stubs\InMemoryBallotRepository;
use BCC\Trust\Tests\Stubs\InMemoryPollRepository;
use BCC\Trust\Tests\Stubs\RankUnitWpFakes;
use BCC\Trust\Tests\Stubs\StubClusterFindingsRepository;
use BCC\Trust\Tests\Stubs\TestPollService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Stubs/rank-unit-stubs.php';
require_once __DIR__ . '/../Stubs/poll-engine-stubs.php';

/**
 * Phase 6 meaningful-voting poll engine — master-plan R4 / invariants
 * 22–24 / §19.4 mechanics against the REAL shipped RankScoringConfig
 * (quorum 10 voters + 7.5 weight, majority 0.60, binding 7→90 days,
 * suspected cap ratio 0.25, ceiling 1.75):
 *
 *   - quorum: voters AND weight both required, on POST-cap numbers;
 *     7.5 weight exactly qualifies, 7.49 does not;
 *   - majority: exactly 60.00% of counted weight PASSES; 59.94% leaves
 *     the poll open until expiry;
 *   - day-7: nothing evaluates before binding_earliest_at — enforced in
 *     the SERVICE even against a drifting list query;
 *   - day-90: binding never met ⇒ closed 'inconclusive' (with closure
 *     audit persisted so the closed tally stays derivable);
 *   - confirmed clusters: only the representative's ballot counts (for
 *     weight AND headcount); other members keep audit rows at 0;
 *   - suspected cap: pro-rata to 0.25 × W_nc — split-proof (one ballot
 *     of 4 ≡ two ballots of 2);
 *   - recast: max 2 changes, 24h cooldown, ORIGINAL §16.6 snapshot
 *     carried verbatim, one-active-ballot invariant, windows never
 *     reset; withdrawal re-entry consumes the same budget;
 *   - C10: open-poll viewer state carries NO counts/totals/progress;
 *     the tally appears only after close (§17.3).
 */
final class PollEngineTest extends TestCase
{
    private const T0 = '2026-08-01 00:00:00';

    private const BINDING_AT = '2026-08-08 00:00:00';

    private const EXPIRES_AT = '2026-10-30 00:00:00';

    /** After day-7, well before day-90. */
    private const SWEEP_AT = '2026-08-09 00:00:00';

    /** After day-90. */
    private const EXPIRED_SWEEP_AT = '2026-10-31 00:00:00';

    private InMemoryPollRepository $polls;

    private InMemoryBallotRepository $ballots;

    private StubClusterFindingsRepository $clusters;

    private TestPollService $service;

    protected function setUp(): void
    {
        RankUnitWpFakes::reset();

        $this->polls    = new InMemoryPollRepository();
        $this->ballots  = new InMemoryBallotRepository();
        $this->clusters = new StubClusterFindingsRepository();
        $this->service  = new TestPollService(
            $this->polls,
            $this->ballots,
            $this->clusters,
            RankScoringConfig::fromDefaultFile()
        );
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function at(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> A valid §16.6 snapshot with the given effective weight. */
    private function snapshot(float $effectiveWeight, float $maturity = 1.0): array
    {
        return [
            'rank_slug'        => 'apprentice',
            'maturity'         => $maturity,
            'rank_multiplier'  => 1.0,
            'trust_multiplier' => 1.0,
            'trust_score'      => 50.0,
            'fraud_discount'   => 1.0,
            'effective_weight' => $effectiveWeight,
        ];
    }

    private function openPoll(int $subjectId = 123): int
    {
        return $this->service->open('dispute', 'vote', $subjectId, $this->at(self::T0));
    }

    /**
     * Cast one ballot per voter id at T0 + 1h.
     *
     * @param list<int> $voterIds
     */
    private function castMany(int $pollId, array $voterIds, string $choice, float $weight): void
    {
        foreach ($voterIds as $voterId) {
            $this->service->cast(
                $pollId,
                $voterId,
                $choice,
                $this->snapshot($weight),
                $this->at('2026-08-01 01:00:00')
            );
        }
    }

    /** @return list<int> */
    private function voterRange(int $from, int $count): array
    {
        return range($from, $from + $count - 1);
    }

    // ── open() ───────────────────────────────────────────────────────

    public function testOpenComputesWindowsFromConfigAndRejectsDuplicate(): void
    {
        $pollId = $this->openPoll();
        $poll   = $this->polls->rows[$pollId];

        self::assertSame('open', $poll->status);
        self::assertSame(self::T0, $poll->opened_at);
        self::assertSame(self::BINDING_AT, $poll->binding_earliest_at, 'day-7 binding window');
        self::assertSame(self::EXPIRES_AT, $poll->expires_at, 'day-90 expiry window');

        $this->expectException(\RuntimeException::class);
        $this->openPoll(); // Same subject while open → domain exception.
    }

    public function testOpenSurfacesConstraintRaceAsDomainException(): void
    {
        // A racer that slips past the pre-check loses on the
        // uniq_open_subject INSERT (create returns 0) — same exception.
        $polls = new class extends InMemoryPollRepository {
            public function getOpenBySubject(string $pollType, string $subjectType, int $subjectId): ?object
            {
                return null; // Pre-check blind — force the constraint path.
            }

            public function create(
                string $pollType,
                string $subjectType,
                int $subjectId,
                string $openedAt,
                string $bindingEarliestAt,
                string $expiresAt
            ): int {
                // The DB constraint still holds even though the
                // pre-check above is blind.
                foreach ($this->rows as $row) {
                    if ($row->status === 'open'
                        && $row->poll_type === $pollType
                        && $row->subject_type === $subjectType
                        && (int) $row->subject_id === $subjectId
                    ) {
                        return 0;
                    }
                }
                return parent::create($pollType, $subjectType, $subjectId, $openedAt, $bindingEarliestAt, $expiresAt);
            }
        };
        $service = new TestPollService(
            $polls,
            $this->ballots,
            $this->clusters,
            RankScoringConfig::fromDefaultFile()
        );

        self::assertSame(1, $service->open('dispute', 'vote', 5, $this->at(self::T0)));

        $this->expectException(\RuntimeException::class);
        $service->open('dispute', 'vote', 5, $this->at(self::T0));
    }

    // ── quorum (invariant 22: both floors, post-cap) ─────────────────

    public function testNineVotersStayOpenDespiteWeightQuorum(): void
    {
        $pollId = $this->openPoll();
        $this->castMany($pollId, $this->voterRange(1, 9), 'for', 1.5); // 13.5 weight ≥ 7.5, 9 voters < 10.

        $result = $this->service->closeDuePolls($this->at(self::SWEEP_AT));

        self::assertSame(['closed' => 0, 'inconclusive' => 0], $result);
        self::assertSame('open', $this->polls->rows[$pollId]->status);
        self::assertSame([], RankUnitWpFakes::firedHooks());
    }

    public function testTenVotersBelowWeightQuorumStayOpen(): void
    {
        $pollId = $this->openPoll();
        $this->castMany($pollId, $this->voterRange(1, 10), 'for', 0.749); // 7.49 < 7.5.

        $result = $this->service->closeDuePolls($this->at(self::SWEEP_AT));

        self::assertSame(['closed' => 0, 'inconclusive' => 0], $result);
        self::assertSame('open', $this->polls->rows[$pollId]->status);
    }

    public function testWeightQuorumExactlyMetEvaluatesAndCloses(): void
    {
        $pollId = $this->openPoll();
        $this->castMany($pollId, $this->voterRange(1, 10), 'for', 0.75); // Exactly 7.5.

        $result = $this->service->closeDuePolls($this->at(self::SWEEP_AT));

        self::assertSame(['closed' => 1, 'inconclusive' => 0], $result);
        $poll = $this->polls->rows[$pollId];
        self::assertSame('closed', $poll->status);
        self::assertSame('passed', $poll->outcome);
        self::assertSame(self::SWEEP_AT, $poll->closed_at);

        // Wave 3 subscription point — positional args.
        self::assertSame(
            [['bcc_trust_poll_closed', [$pollId, 'passed', 'dispute', 'vote', 123]]],
            RankUnitWpFakes::$actions
        );
    }

    // ── majority (invariant 23: ≥ 60.00% of counted weight) ──────────

    public function testExactlySixtyPercentMajorityPasses(): void
    {
        $pollId = $this->openPoll();
        $this->castMany($pollId, $this->voterRange(1, 6), 'for', 1.0);
        $this->castMany($pollId, $this->voterRange(7, 4), 'against', 1.0); // 6.0 / 10.0 = 60.00%.

        $result = $this->service->closeDuePolls($this->at(self::SWEEP_AT));

        self::assertSame(['closed' => 1, 'inconclusive' => 0], $result);
        self::assertSame('passed', $this->polls->rows[$pollId]->outcome);
    }

    public function testMajorityFailsAgainstWhenAgainstHoldsSixtyPercent(): void
    {
        $pollId = $this->openPoll();
        $this->castMany($pollId, $this->voterRange(1, 4), 'for', 1.0);
        $this->castMany($pollId, $this->voterRange(5, 6), 'against', 1.0); // against 6/10.

        $this->service->closeDuePolls($this->at(self::SWEEP_AT));

        self::assertSame('failed', $this->polls->rows[$pollId]->outcome);
    }

    public function testJustUnderSixtyPercentLeavesPollOpen(): void
    {
        $pollId = $this->openPoll();
        $this->castMany($pollId, $this->voterRange(1, 6), 'for', 0.999);    // 5.994
        $this->castMany($pollId, $this->voterRange(7, 4), 'against', 1.0015); // 4.006 → for-share 59.94%.

        $result = $this->service->closeDuePolls($this->at(self::SWEEP_AT));

        self::assertSame(['closed' => 0, 'inconclusive' => 0], $result);
        self::assertSame('open', $this->polls->rows[$pollId]->status, 'no supermajority either way — open until expiry');
    }

    // ── day-7 binding window ─────────────────────────────────────────

    public function testNotEvaluatedBeforeBindingEvenIfListQueryDrifts(): void
    {
        // The list fake DELIBERATELY ignores the binding window — the
        // service-side §17.2 guard must refuse to evaluate on its own.
        $polls = new class extends InMemoryPollRepository {
            public function listOpenPastBinding(string $nowSql, int $limit): array
            {
                $out = [];
                foreach ($this->rows as $row) {
                    if ($row->status === 'open') {
                        $out[] = $row; // No binding filter — drifted.
                    }
                }
                return array_slice($out, 0, $limit);
            }
        };
        $service = new TestPollService(
            $polls,
            $this->ballots,
            $this->clusters,
            RankScoringConfig::fromDefaultFile()
        );

        $pollId = $service->open('dispute', 'vote', 123, $this->at(self::T0));
        foreach ($this->voterRange(1, 10) as $voterId) {
            $service->cast($pollId, $voterId, 'for', $this->snapshot(1.0), $this->at('2026-08-01 01:00:00'));
        }

        // Quorum + majority satisfied — but day 6 < binding_earliest_at.
        $result = $service->closeDuePolls($this->at('2026-08-07 23:00:00'));
        self::assertSame(['closed' => 0, 'inconclusive' => 0], $result);
        self::assertSame('open', $polls->rows[$pollId]->status);
        self::assertSame([], RankUnitWpFakes::firedHooks());

        // Same poll, same ballots, past day 7 → closes.
        $result = $service->closeDuePolls($this->at(self::SWEEP_AT));
        self::assertSame(['closed' => 1, 'inconclusive' => 0], $result);
        self::assertSame('passed', $polls->rows[$pollId]->outcome);
    }

    // ── day-90 expiry ────────────────────────────────────────────────

    public function testExpiredPollThatNeverMetBindingClosesInconclusive(): void
    {
        $pollId = $this->openPoll();
        $this->castMany($pollId, $this->voterRange(1, 3), 'for', 1.0); // Never reaches quorum.

        $result = $this->service->closeDuePolls($this->at(self::EXPIRED_SWEEP_AT));

        self::assertSame(['closed' => 0, 'inconclusive' => 1], $result);
        $poll = $this->polls->rows[$pollId];
        self::assertSame('closed', $poll->status);
        self::assertSame('inconclusive', $poll->outcome);
        self::assertSame(self::EXPIRED_SWEEP_AT, $poll->closed_at);

        // Closure audit persisted even on an inconclusive close — the
        // closed tally stays derivable (§17.3).
        foreach ($this->ballots->rows as $ballot) {
            self::assertSame('1', $ballot->counted);
            self::assertSame('1.0000', $ballot->counted_weight);
        }

        self::assertSame(
            [['bcc_trust_poll_closed', [$pollId, 'inconclusive', 'dispute', 'vote', 123]]],
            RankUnitWpFakes::$actions
        );
    }

    // ── confirmed clusters (§18 representative rule) ─────────────────

    public function testConfirmedClusterCountsOnlyTheRepresentative(): void
    {
        $this->clusters->confirmed = [
            (object) [
                'id'                     => '11',
                'level'                  => 'confirmed',
                'member_user_ids'        => '[101,102,103,104]',
                'representative_user_id' => '104',
                'selection_method'       => 'auto_oldest_account',
                'effective_at'           => self::T0,
            ],
        ];

        $pollId = $this->openPoll();
        $this->castMany($pollId, [101, 102, 103, 104], 'for', 1.0);
        $this->castMany($pollId, $this->voterRange(1, 9), 'for', 1.0);

        $result = $this->service->closeDuePolls($this->at(self::SWEEP_AT));

        // Headcount: 9 independents + the representative = 10 (quorum
        // met only BECAUSE the representative counts).
        self::assertSame(['closed' => 1, 'inconclusive' => 0], $result);
        self::assertSame('passed', $this->polls->rows[$pollId]->outcome);

        $byVoter = [];
        foreach ($this->ballots->rows as $ballot) {
            $byVoter[(int) $ballot->voter_user_id] = $ballot;
        }

        foreach ([101, 102, 103] as $member) {
            self::assertSame('0', $byVoter[$member]->counted, "member {$member} excluded from headcount");
            self::assertSame('0.0000', $byVoter[$member]->counted_weight, "member {$member} weight zeroed");
            self::assertSame('11', $byVoter[$member]->confirmed_cluster_id, 'audit row retained');
        }
        self::assertSame('1', $byVoter[104]->counted, 'representative counts for headcount');
        self::assertSame('1.0000', $byVoter[104]->counted_weight, 'representative counts full weight');
        self::assertSame('11', $byVoter[104]->confirmed_cluster_id);
    }

    // ── suspected cap (§19: pro-rata, split-proof) ───────────────────

    /**
     * @return array{0: float} Suspected counted total for the scenario.
     */
    private function runSuspectedScenario(string $memberJson, array $suspectedWeights): array
    {
        $this->clusters->suspected = [
            (object) [
                'id'                     => '21',
                'level'                  => 'suspected',
                'member_user_ids'        => $memberJson,
                'representative_user_id' => '201',
                'selection_method'       => 'auto_oldest_account',
                'effective_at'           => self::T0,
            ],
        ];

        $pollId = $this->openPoll();
        $this->castMany($pollId, $this->voterRange(1, 10), 'for', 1.0); // W_nc = 10 → cap 2.5.

        // Seed suspected ballots DIRECTLY (repository level) so weights
        // above the cast-time ceiling exercise the exact plan numbers.
        $voterId = 201;
        foreach ($suspectedWeights as $weight) {
            $this->ballots->insert(
                $pollId,
                $voterId++,
                'for',
                [
                    'rank_slug'        => 'veteran',
                    'maturity'         => 1.0,
                    'rank_multiplier'  => 1.0,
                    'trust_multiplier' => 1.0,
                    'trust_score'      => 90.0,
                    'fraud_discount'   => 1.0,
                    'effective_weight' => $weight,
                ],
                0,
                '2026-08-01 01:00:00',
                '2026-08-01 01:00:00'
            );
        }

        $result = $this->service->closeDuePolls($this->at(self::SWEEP_AT));
        self::assertSame(['closed' => 1, 'inconclusive' => 0], $result);

        $suspectedTotal = 0.0;
        foreach ($this->ballots->rows as $ballot) {
            if ((int) $ballot->voter_user_id >= 201) {
                self::assertSame('21', $ballot->suspected_cluster_id);
                self::assertSame('1', $ballot->counted, 'capped suspected ballots still count for headcount');
                $suspectedTotal += (float) $ballot->counted_weight;
            }
        }
        return [$suspectedTotal];
    }

    public function testSuspectedWeightCappedProRataToQuarterOfNonCluster(): void
    {
        [$total] = $this->runSuspectedScenario('[201,202]', [2.0, 2.0]);

        // W_susp 4.0 > 0.25 × 10 = 2.5 → scaled to exactly 2.5 (1.25 each).
        self::assertEqualsWithDelta(2.5, $total, 0.0001);
        foreach ($this->ballots->rows as $ballot) {
            if ((int) $ballot->voter_user_id >= 201) {
                self::assertSame('1.2500', $ballot->counted_weight);
            }
        }
    }

    public function testSuspectedCapIsSplitProof(): void
    {
        // One identity casting weight 4 must gain nothing by splitting
        // into two identities of weight 2 — C_total is identical.
        [$single] = $this->runSuspectedScenario('[201,202]', [4.0]);

        self::assertEqualsWithDelta(2.5, $single, 0.0001);
    }

    // ── recast / withdraw mechanics (§17.4) ──────────────────────────

    public function testRecastCooldownLimitAndVerbatimSnapshot(): void
    {
        $pollId = $this->openPoll();
        $firstId = $this->service->cast(
            $pollId,
            1,
            'for',
            $this->snapshot(1.2345, 0.5),
            $this->at('2026-08-01 01:00:00')
        );

        // <24h since cast → cooldown.
        try {
            $this->service->changeBallot($pollId, 1, 'against', $this->at('2026-08-01 02:00:00'));
            self::fail('expected cooldown exception');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('cooldown', $e->getMessage());
        }

        // First change at +25h.
        $secondId = $this->service->changeBallot($pollId, 1, 'against', $this->at('2026-08-02 02:00:00'));
        self::assertSame('superseded', $this->ballots->rows[$firstId]->status);

        $second = $this->ballots->rows[$secondId];
        self::assertSame('active', $second->status);
        self::assertSame('against', $second->choice);
        self::assertSame('1', $second->recast_count);

        // ORIGINAL §16.6 snapshot carried verbatim — no re-weighting.
        $first = $this->ballots->rows[$firstId];
        foreach (['rank_slug', 'maturity', 'rank_multiplier', 'trust_multiplier', 'trust_score', 'fraud_discount', 'effective_weight'] as $field) {
            self::assertSame($first->{$field}, $second->{$field}, "snapshot field {$field} copied verbatim");
        }
        self::assertSame('0.5000', $second->maturity);
        self::assertSame('1.2345', $second->effective_weight);

        // Poll windows NEVER reset on recast.
        self::assertSame(self::BINDING_AT, $this->polls->rows[$pollId]->binding_earliest_at);
        self::assertSame(self::EXPIRES_AT, $this->polls->rows[$pollId]->expires_at);

        // Second change at +24h from the first change.
        $thirdId = $this->service->changeBallot($pollId, 1, 'for', $this->at('2026-08-03 03:00:00'));
        self::assertSame('2', $this->ballots->rows[$thirdId]->recast_count);

        // Third change: budget exhausted.
        try {
            $this->service->changeBallot($pollId, 1, 'against', $this->at('2026-08-04 04:00:00'));
            self::fail('expected recast-limit exception');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('recast limit', $e->getMessage());
        }

        // One-active-ballot invariant throughout.
        self::assertSame(1, $this->ballots->countActiveForPoll($pollId));
    }

    public function testWithdrawalReentryConsumesRecastBudgetAndCarriesSnapshot(): void
    {
        $pollId = $this->openPoll();
        $this->service->cast($pollId, 1, 'for', $this->snapshot(1.2), $this->at('2026-08-01 01:00:00'));

        // Withdraw is cooldown-gated too.
        try {
            $this->service->withdraw($pollId, 1, $this->at('2026-08-01 02:00:00'));
            self::fail('expected cooldown exception');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('cooldown', $e->getMessage());
        }

        self::assertTrue($this->service->withdraw($pollId, 1, $this->at('2026-08-02 02:00:00')));
        self::assertNull($this->ballots->getActiveForVoter($pollId, 1));

        // Re-entry via cast(): counts as a recast; the DIFFERENT
        // caller-provided snapshot is ignored in favor of the original.
        $reentryId = $this->service->cast($pollId, 1, 'against', $this->snapshot(0.4), $this->at('2026-08-02 03:00:00'));
        $reentry   = $this->ballots->rows[$reentryId];
        self::assertSame('1', $reentry->recast_count, 're-entry consumed one recast slot');
        self::assertSame('1.2000', $reentry->effective_weight, 'original snapshot carried, not the new one');

        // Burn the second slot, then verify re-entry is refused.
        $this->service->changeBallot($pollId, 1, 'for', $this->at('2026-08-03 04:00:00'));
        self::assertTrue($this->service->withdraw($pollId, 1, $this->at('2026-08-04 05:00:00')));

        try {
            $this->service->cast($pollId, 1, 'for', $this->snapshot(1.2), $this->at('2026-08-05 06:00:00'));
            self::fail('expected recast-limit exception');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('recast limit', $e->getMessage());
        }
    }

    public function testCastRejectsSecondActiveBallotAndBadChoice(): void
    {
        $pollId = $this->openPoll();
        $this->service->cast($pollId, 1, 'for', $this->snapshot(1.0), $this->at('2026-08-01 01:00:00'));

        try {
            $this->service->cast($pollId, 1, 'against', $this->snapshot(1.0), $this->at('2026-08-01 02:00:00'));
            self::fail('expected active-ballot exception');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('active ballot', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->service->cast($pollId, 2, 'abstain', $this->snapshot(1.0), $this->at('2026-08-01 02:00:00'));
    }

    public function testCastClampsEffectiveWeightToCeiling(): void
    {
        $pollId = $this->openPoll();
        $id     = $this->service->cast($pollId, 1, 'for', $this->snapshot(9.9), $this->at('2026-08-01 01:00:00'));

        // Config vote_ceiling = 1.75 (§16.7) — defensive clamp.
        self::assertSame('1.7500', $this->ballots->rows[$id]->effective_weight);
    }

    // ── C10 viewer state ─────────────────────────────────────────────

    public function testViewerStateOnOpenPollExposesNoTallyOrProgress(): void
    {
        $pollId = $this->openPoll();
        $this->castMany($pollId, $this->voterRange(1, 10), 'for', 1.0);

        $state = $this->service->viewerState($pollId, 1, $this->at('2026-08-01 02:00:00'));

        self::assertSame(
            ['status', 'opened_at', 'binding_earliest_at', 'expires_at', 'viewer'],
            array_keys($state),
            'open poll: windows + viewer facts and NOTHING else (C10)'
        );
        self::assertSame(
            ['my_choice', 'my_effective_weight', 'can_change', 'can_withdraw'],
            array_keys($state['viewer'])
        );
        self::assertSame('open', $state['status']);
        self::assertSame('for', $state['viewer']['my_choice']);
        self::assertSame(1.0, $state['viewer']['my_effective_weight']);
        self::assertFalse($state['viewer']['can_change'], 'cooldown active right after cast');
        self::assertFalse($state['viewer']['can_withdraw']);

        // After the cooldown the viewer regains both actions.
        $later = $this->service->viewerState($pollId, 1, $this->at('2026-08-02 02:00:00'));
        self::assertTrue($later['viewer']['can_change']);
        self::assertTrue($later['viewer']['can_withdraw']);

        // A non-voter sees a null ballot and no actions.
        $stranger = $this->service->viewerState($pollId, 999, $this->at('2026-08-02 02:00:00'));
        self::assertNull($stranger['viewer']['my_choice']);
        self::assertNull($stranger['viewer']['my_effective_weight']);
        self::assertFalse($stranger['viewer']['can_change']);
        self::assertFalse($stranger['viewer']['can_withdraw']);
    }

    public function testViewerStateOnClosedPollCarriesOutcomeAndCountedTally(): void
    {
        $pollId = $this->openPoll();
        $this->castMany($pollId, $this->voterRange(1, 6), 'for', 1.0);
        $this->castMany($pollId, $this->voterRange(7, 4), 'against', 1.0);
        $this->service->closeDuePolls($this->at(self::SWEEP_AT));

        $state = $this->service->viewerState($pollId, 1, $this->at(self::SWEEP_AT));

        self::assertSame('closed', $state['status']);
        self::assertSame('passed', $state['outcome']);
        self::assertSame(self::SWEEP_AT, $state['closed_at']);
        self::assertSame(
            ['counted_voters' => 10, 'weight_for' => 6.0, 'weight_against' => 4.0],
            $state['tally']
        );
    }

    // ── sweep load-once (cluster lists read once per run, §18/§19) ────

    public function testSweepReadsEachClusterListExactlyOncePerRun(): void
    {
        // Spy repo counting the two GLOBAL cluster-list reads.
        $clusters = new class extends StubClusterFindingsRepository {
            public int $confirmedCalls = 0;

            public int $suspectedCalls = 0;

            public function listActiveConfirmed(): array
            {
                $this->confirmedCalls++;
                return parent::listActiveConfirmed();
            }

            public function listActiveSuspected(): array
            {
                $this->suspectedCalls++;
                return parent::listActiveSuspected();
            }
        };
        $service = new TestPollService(
            $this->polls,
            $this->ballots,
            $clusters,
            RankScoringConfig::fromDefaultFile()
        );

        // Three independent subjects, each with a decisive quorum, all due
        // to close in the same sweep tick.
        $pollIds = [];
        foreach ([201, 202, 203] as $subjectId) {
            $pollId = $service->open('dispute', 'vote', $subjectId, $this->at(self::T0));
            foreach ($this->voterRange(1, 10) as $voterId) {
                $service->cast($pollId, $voterId, 'for', $this->snapshot(1.0), $this->at('2026-08-01 01:00:00'));
            }
            $pollIds[] = $pollId;
        }

        $result = $service->closeDuePolls($this->at(self::SWEEP_AT));

        // Outcomes unchanged by the load-once refactor — all three pass —
        // but each cluster list was read exactly ONCE for the whole sweep
        // (previously once per closed poll = 3× each).
        self::assertSame(['closed' => 3, 'inconclusive' => 0], $result);
        self::assertSame(1, $clusters->confirmedCalls, 'confirmed cluster list read once per sweep');
        self::assertSame(1, $clusters->suspectedCalls, 'suspected cluster list read once per sweep');
        foreach ($pollIds as $pollId) {
            self::assertSame('passed', $this->polls->rows[$pollId]->outcome);
        }
    }
}
