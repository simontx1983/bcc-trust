<?php

declare(strict_types=1);

namespace BCC\Trust\Disputes\Tests\Unit;

use BCC\Trust\Disputes\Services\DisputeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DisputeResolver::handle().
 *
 * ## Isolation strategy
 *
 * Every test method runs in its OWN PHP subprocess (see the
 * RunTestsInSeparateProcesses + PreserveGlobalState(false) attributes
 * below). Inside the subprocess, setUp() pulls in
 * tests/Stubs/resolver-stubs.php which defines in-namespace test doubles
 * (DisputeRepository, DisputeNotificationService, ServiceLocator, Logger,
 * and the DisputeAdjudicationInterface itself) at the exact production
 * FQNs, then manually requires the real DisputeResolver.php. Because the
 * stubs are registered before the resolver references them, Composer's
 * autoloader never loads the real production classes in the subprocess.
 *
 * The main PHPUnit process — where ComputeVerdictTest runs — is
 * completely untouched: the stubs file is never included there, so
 * the real DisputeRepository loads normally via PSR-4.
 */
#[CoversClass(DisputeResolver::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DisputeResolverTest extends TestCase
{
    private const DISPUTE_ID  = 42;
    private const VOTE_ID     = 101;
    private const PAGE_ID     = 77;
    private const VOTER_ID    = 31;
    private const REPORTER_ID = 29;
    private const ACTOR_ID    = 99;

    /** @var object Fake adjudicator with call-log array and configurable results. */
    private object $adjudicator;

    protected function setUp(): void
    {
        parent::setUp();

        // Load stubs inside the subprocess. Cannot live at file scope:
        // PHPUnit parses all test files in the main process during
        // discovery, and file-scope class definitions would leak into
        // ComputeVerdictTest's process.
        require_once __DIR__ . '/../Stubs/resolver-stubs.php';

        \BCC\Trust\Disputes\Repositories\DisputeRepository::reset();
        \BCC\Trust\Disputes\Services\DisputeNotificationService::reset();
        \BCC\Core\ServiceLocator::reset();

        $this->adjudicator = $this->makeFakeAdjudicator();
        \BCC\Core\ServiceLocator::$adjudicator = $this->adjudicator;
    }

    /**
     * Instantiate a fake that implements the interface. Built lazily
     * inside the subprocess because the interface is only defined after
     * the stubs file loads.
     */
    private function makeFakeAdjudicator(): object
    {
        return new class implements \BCC\Core\Contracts\DisputeAdjudicationInterface {
            /** @var list<array<string,int|bool|string>> */
            public array $calls = [];
            public bool $acceptResult = true;
            public bool $rejectResult = true;

            public function acceptVoteDispute(
                int $disputeId,
                int $voteId,
                int $pageId,
                int $voterId,
                int $resolvedBy
            ): bool {
                $this->calls[] = [
                    'method'      => 'accept',
                    'dispute_id'  => $disputeId,
                    'vote_id'     => $voteId,
                    'page_id'     => $pageId,
                    'voter_id'    => $voterId,
                    'resolved_by' => $resolvedBy,
                ];
                return $this->acceptResult;
            }

            public function rejectVoteDispute(
                int $disputeId,
                int $voteId,
                int $pageId,
                int $reporterId,
                int $resolvedBy,
                bool $quorumMet
            ): bool {
                $this->calls[] = [
                    'method'      => 'reject',
                    'dispute_id'  => $disputeId,
                    'vote_id'     => $voteId,
                    'page_id'     => $pageId,
                    'reporter_id' => $reporterId,
                    'resolved_by' => $resolvedBy,
                    'quorum_met'  => $quorumMet,
                ];
                return $this->rejectResult;
            }
        };
    }

    private function resolver(): \BCC\Trust\Disputes\Services\DisputeResolver
    {
        return new \BCC\Trust\Disputes\Services\DisputeResolver();
    }

    // ── 1) Accepted dispute ───────────────────────────────────────────────

    public function testAcceptedDisputeCallsAccept(): void
    {
        $ok = $this->resolver()->handle(
            self::DISPUTE_ID,
            self::VOTE_ID,
            self::PAGE_ID,
            self::VOTER_ID,
            self::REPORTER_ID,
            'accepted',
            self::ACTOR_ID
        );

        self::assertTrue($ok, 'happy-path accept must return true');
        $this->assertCallLogged('beginResolveTransaction', [self::DISPUTE_ID, 'accepted']);
        $this->assertCallLogged('commitTransaction');

        self::assertCount(1, $this->adjudicator->calls);
        self::assertSame('accept', $this->adjudicator->calls[0]['method']);
        $this->assertCallLogged('setAdjudicationStatus', [self::DISPUTE_ID, 'completed']);
    }

    // ── 2) Rejected WITH quorum ───────────────────────────────────────────

    public function testRejectedWithQuorumAppliesPenalty(): void
    {
        \BCC\Trust\Disputes\Repositories\DisputeRepository::$quorumMet = true;

        $ok = $this->resolver()->handle(
            self::DISPUTE_ID,
            self::VOTE_ID,
            self::PAGE_ID,
            self::VOTER_ID,
            self::REPORTER_ID,
            'rejected',
            self::ACTOR_ID
        );

        self::assertTrue($ok);
        self::assertCount(1, $this->adjudicator->calls);
        $call = $this->adjudicator->calls[0];

        self::assertSame('reject', $call['method']);
        self::assertTrue(
            $call['quorum_met'],
            'rejected + quorum=true must propagate quorumMet=true so penalty fires'
        );
        self::assertSame(self::REPORTER_ID, $call['reporter_id']);
    }

    // ── 3) Rejected WITHOUT quorum ────────────────────────────────────────

    public function testRejectedWithoutQuorumSkipsPenalty(): void
    {
        \BCC\Trust\Disputes\Repositories\DisputeRepository::$quorumMet = false;

        $ok = $this->resolver()->handle(
            self::DISPUTE_ID,
            self::VOTE_ID,
            self::PAGE_ID,
            self::VOTER_ID,
            self::REPORTER_ID,
            'rejected',
            self::ACTOR_ID
        );

        self::assertTrue($ok);
        self::assertCount(1, $this->adjudicator->calls);
        $call = $this->adjudicator->calls[0];

        self::assertSame('reject', $call['method']);
        self::assertFalse(
            $call['quorum_met'],
            'quorum=false must propagate so trust-engine skips reporter penalty'
        );
    }

    // ── 4) timeout_no_quorum ──────────────────────────────────────────────

    public function testTimeoutNoQuorumDoesNotCallAdjudicator(): void
    {
        $ok = $this->resolver()->handle(
            self::DISPUTE_ID,
            self::VOTE_ID,
            self::PAGE_ID,
            self::VOTER_ID,
            self::REPORTER_ID,
            'timeout_no_quorum',
            self::ACTOR_ID
        );

        self::assertTrue($ok);
        self::assertCount(0, $this->adjudicator->calls, 'timeout must not invoke the adjudicator');

        // Status still transitioned — dispute is not left stuck.
        $this->assertCallLogged('beginResolveTransaction', [self::DISPUTE_ID, 'timeout_no_quorum']);
        $this->assertCallLogged('commitTransaction');
        $this->assertCallLogged('setAdjudicationStatus', [self::DISPUTE_ID, 'completed']);
    }

    // ── 5) Idempotency (concurrent resolve race) ─────────────────────────

    public function testConcurrentResolveRaceReturnsFalseWithoutSideEffects(): void
    {
        // Simulate: another worker already flipped status out of 'reviewing',
        // so beginResolveTransaction's UPDATE matched zero rows.
        \BCC\Trust\Disputes\Repositories\DisputeRepository::$beginResult = [
            'success'       => false,
            'affected_rows' => 0,
            'db_error'      => null,
            'race'          => true,
        ];

        $ok = $this->resolver()->handle(
            self::DISPUTE_ID,
            self::VOTE_ID,
            self::PAGE_ID,
            self::VOTER_ID,
            self::REPORTER_ID,
            'accepted',
            self::ACTOR_ID
        );

        self::assertFalse($ok);
        self::assertCount(0, $this->adjudicator->calls, 'race must not invoke adjudicator');
        $this->assertCallNotLogged('commitTransaction');
        $this->assertCallNotLogged('setAdjudicationStatus');
    }

    // ── 6) Trust-engine unavailable (pre-commit gate) ────────────────────

    public function testAdjudicatorUnavailableRollsBackAndAborts(): void
    {
        \BCC\Core\ServiceLocator::$hasReal[\BCC\Core\Contracts\DisputeAdjudicationInterface::class] = false;

        $ok = $this->resolver()->handle(
            self::DISPUTE_ID,
            self::VOTE_ID,
            self::PAGE_ID,
            self::VOTER_ID,
            self::REPORTER_ID,
            'rejected',
            self::ACTOR_ID
        );

        self::assertFalse($ok);
        $this->assertCallLogged('rollbackTransaction');
        $this->assertCallNotLogged('commitTransaction');
        self::assertCount(0, $this->adjudicator->calls);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param list<mixed> $expectedArgs
     */
    private function assertCallLogged(string $method, array $expectedArgs = []): void
    {
        foreach (\BCC\Trust\Disputes\Repositories\DisputeRepository::$calls as $call) {
            if ($call[0] !== $method) {
                continue;
            }
            if ($expectedArgs === []) {
                self::assertSame($method, $call[0]);
                return;
            }
            $actualArgs = array_slice($call, 1);
            if ($actualArgs === $expectedArgs) {
                self::assertSame($expectedArgs, $actualArgs);
                return;
            }
        }

        self::fail(sprintf(
            "Expected repository call '%s' with args %s, got call log:\n%s",
            $method,
            json_encode($expectedArgs),
            json_encode(\BCC\Trust\Disputes\Repositories\DisputeRepository::$calls, JSON_PRETTY_PRINT)
        ));
    }

    private function assertCallNotLogged(string $method): void
    {
        foreach (\BCC\Trust\Disputes\Repositories\DisputeRepository::$calls as $call) {
            if ($call[0] === $method) {
                self::fail(sprintf(
                    "Expected repository call '%s' NOT to be made, but found it in call log:\n%s",
                    $method,
                    json_encode(\BCC\Trust\Disputes\Repositories\DisputeRepository::$calls, JSON_PRETTY_PRINT)
                ));
            }
        }
        self::assertTrue(true);
    }
}
