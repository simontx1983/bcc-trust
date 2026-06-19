<?php

declare(strict_types=1);

namespace BCC\Trust\Disputes\Tests\Unit;

use BCC\Trust\Disputes\Repositories\DisputeRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DisputeRepository::computeVerdict().
 *
 * computeVerdict is a pure function over (accepts, rejects, panelSize) and
 * has no external dependencies — it can be tested by calling the static
 * method directly. The class file's `use` imports are lazy; only the class
 * definition itself is parsed at load time, and computeVerdict's body uses
 * only stdlib + self::quorumFor.
 */
#[CoversMethod(DisputeRepository::class, 'computeVerdict')]
#[CoversMethod(DisputeRepository::class, 'quorumFor')]
final class ComputeVerdictTest extends TestCase
{
    /**
     * Canonical panel size for every test. Matches production
     * BCC_DISPUTES_PANEL_SIZE = 5 → majority = 3, quorum = 3.
     */
    private const PANEL_SIZE = 5;

    // ── Accept / reject verdicts with quorum ────────────────────────────────

    public function testThreeAcceptsResolveAsAccepted(): void
    {
        $v = DisputeRepository::computeVerdict(3, 0, self::PANEL_SIZE);

        self::assertTrue($v['should_resolve'], '3 accepts should trigger resolution');
        self::assertSame('accepted', $v['outcome']);
    }

    public function testThreeRejectsResolveAsRejected(): void
    {
        $v = DisputeRepository::computeVerdict(0, 3, self::PANEL_SIZE);

        self::assertTrue($v['should_resolve'], '3 rejects should trigger resolution');
        self::assertSame('rejected', $v['outcome']);
    }

    // ── Mid-stream (no resolve yet) ─────────────────────────────────────────

    public function testTwoAcceptsOneRejectDoesNotResolve(): void
    {
        $v = DisputeRepository::computeVerdict(2, 1, self::PANEL_SIZE);

        self::assertFalse($v['should_resolve'], 'quorum met but no side reached majority — wait');
        // Outcome is not meaningful when should_resolve=false, but the
        // ternary should produce 'rejected' (accepts < majority).
        self::assertSame('rejected', $v['outcome']);
    }

    public function testOneAcceptTwoRejectsDoesNotResolve(): void
    {
        $v = DisputeRepository::computeVerdict(1, 2, self::PANEL_SIZE);

        self::assertFalse($v['should_resolve']);
    }

    // ── TTL / zero-vote path ────────────────────────────────────────────────

    public function testZeroVotesDoesNotResolve(): void
    {
        $v = DisputeRepository::computeVerdict(0, 0, self::PANEL_SIZE);

        self::assertFalse($v['should_resolve'], '0 votes cannot trigger a voter-path resolve');
    }

    public function testZeroVotesOutcomeIsTimeoutNoQuorum(): void
    {
        // The scheduler TTL path ignores should_resolve and uses outcome
        // directly. A dispute that timed out with no votes must surface as
        // timeout_no_quorum, NOT silently as rejected.
        $v = DisputeRepository::computeVerdict(0, 0, self::PANEL_SIZE);

        self::assertSame('timeout_no_quorum', $v['outcome']);
    }

    public function testOneVoteTotalIsTimeoutNoQuorum(): void
    {
        // 1 accept + 0 reject → below quorum=3 → TTL path yields timeout.
        $v = DisputeRepository::computeVerdict(1, 0, self::PANEL_SIZE);

        self::assertFalse($v['should_resolve']);
        self::assertSame('timeout_no_quorum', $v['outcome']);
    }

    // ── Quorum boundary ─────────────────────────────────────────────────────

    public function testQuorumIsThreeForPanelOfFive(): void
    {
        self::assertSame(3, DisputeRepository::quorumFor(5));
    }

    public function testQuorumCapsAtThreeForLargerPanels(): void
    {
        self::assertSame(3, DisputeRepository::quorumFor(10));
        self::assertSame(3, DisputeRepository::quorumFor(100));
    }

    public function testQuorumForSmallPanelsIsPanelSize(): void
    {
        self::assertSame(1, DisputeRepository::quorumFor(1));
        self::assertSame(2, DisputeRepository::quorumFor(2));
    }

    public function testExactlyQuorumAcceptsResolves(): void
    {
        // 3 accepts on panel of 5: majority=3, quorum=3 → resolve.
        $v = DisputeRepository::computeVerdict(3, 0, self::PANEL_SIZE);

        self::assertTrue($v['should_resolve']);
        self::assertSame('accepted', $v['outcome']);
    }

    // ── Ties ────────────────────────────────────────────────────────────────

    public function testTwoTwoTieDoesNotResolve(): void
    {
        // 2 accepts + 2 rejects = 4 votes >= quorum, but neither reaches
        // majority (3). Wait for the 5th vote.
        $v = DisputeRepository::computeVerdict(2, 2, self::PANEL_SIZE);

        self::assertFalse($v['should_resolve'], '2-2 tie is below majority; do not resolve');
    }

    // ── Full-panel terminal cases ───────────────────────────────────────────

    public function testFullPanelAllAcceptResolvesAccepted(): void
    {
        $v = DisputeRepository::computeVerdict(5, 0, self::PANEL_SIZE);

        self::assertTrue($v['should_resolve']);
        self::assertSame('accepted', $v['outcome']);
    }

    public function testFullPanelAllRejectResolvesRejected(): void
    {
        $v = DisputeRepository::computeVerdict(0, 5, self::PANEL_SIZE);

        self::assertTrue($v['should_resolve']);
        self::assertSame('rejected', $v['outcome']);
    }

    public function testFullPanelThreeTwoResolvesByMajority(): void
    {
        $accepts3 = DisputeRepository::computeVerdict(3, 2, self::PANEL_SIZE);
        self::assertSame('accepted', $accepts3['outcome']);
        self::assertTrue($accepts3['should_resolve']);

        $rejects3 = DisputeRepository::computeVerdict(2, 3, self::PANEL_SIZE);
        self::assertSame('rejected', $rejects3['outcome']);
        self::assertTrue($rejects3['should_resolve']);
    }
}
