<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\CLI\ApplyGuard;
use BCC\Trust\Core\CLI\AttestationOrphansCommand;
use BCC\Trust\Core\Services\BlockFollowBackfill;
use BCC\Trust\Core\Services\UserLifecycleService;
use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Attestation rows of deleted accounts (2026-11-18).
 *
 *   - UserLifecycleService::purgeAttestationsFor() is the ONE cleanup, for
 *     account deletion AND the orphan prune CLI: snapshot the targets the
 *     user backed, delete both sides, re-synthesize those targets — and
 *     when the delete did not run, report it and skip the recompute.
 *   - `wp bcc-trust attestations prune-orphans` plans from the repository's
 *     orphan reads, prints counts only, binds its token to the environment
 *     and the exact accounts, and applies that same cleanup per account.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AttestationOrphanPurgeTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/attestation-orphan-stubs.php';
    }

    private static function lifecycle(): UserLifecycleService
    {
        return (new \ReflectionClass(UserLifecycleService::class))->newInstanceWithoutConstructor();
    }

    // ── the one cleanup ─────────────────────────────────────────────────

    public function testThePurgeDeletesBothSidesThenRecomputesEachBackedTargetOnce(): void
    {
        \OrphanWorld::$active[7] = [
            (object) ['target_kind' => 'validator_card', 'target_id' => 10],
            (object) ['target_kind' => 'validator_card', 'target_id' => 10],
            (object) ['target_kind' => 'user_profile', 'target_id' => 8],
        ];

        self::assertTrue(self::lifecycle()->purgeAttestationsFor(7));

        self::assertSame([7], \OrphanWorld::$deleted);
        self::assertSame([['validator_card', 10], ['user_profile', 8]], \OrphanWorld::$recomputed);
    }

    public function testAPurgeThatDidNotRunIsReportedAndRecomputesNothing(): void
    {
        \OrphanWorld::$active[7]    = [(object) ['target_kind' => 'validator_card', 'target_id' => 10]];
        \OrphanWorld::$deleteFails = [7];

        self::assertFalse(self::lifecycle()->purgeAttestationsFor(7));

        self::assertSame([], \OrphanWorld::$recomputed, 'the rows still count, so there is nothing to recompute');
        self::assertCount(1, \OrphanWorld::$errors);
        self::assertSame(7, \OrphanWorld::$errors[0][1]['user_id'] ?? null);
    }

    /**
     * Only the rows cast against the profile survived: the cast rows ARE
     * gone, so the targets the user backed must stop counting now. The purge
     * is still reported as incomplete.
     */
    public function testAFailedTargetedLegStillRecomputesTheBackedTargetsAndIsReported(): void
    {
        \OrphanWorld::$active[7]      = [(object) ['target_kind' => 'validator_card', 'target_id' => 10]];
        \OrphanWorld::$targetedFails = [7];

        self::assertFalse(self::lifecycle()->purgeAttestationsFor(7), 'rows against the profile survived');

        self::assertSame([['validator_card', 10]], \OrphanWorld::$recomputed);
    }

    /** Account deletion goes through the same cleanup, not a second copy. */
    public function testAccountDeletionUsesTheOneCleanup(): void
    {
        $code = GateOrder::code('app/Domain/Core/Services/UserLifecycleService.php');
        $body = GateOrder::body($code, 'onUserDelete');

        self::assertSame(1, substr_count($body, '$this->purgeAttestationsFor($userId)'));
        self::assertStringNotContainsString('attestationRepository()', $body, 'no direct attestation write beside it');
        self::assertSame(1, substr_count($code, 'attestationRepository()->deleteForUser('), 'one delete call in the service');
    }

    // ── the prune CLI ───────────────────────────────────────────────────

    public function testThePlanIsTheOrphanReadsAndAnUnreadablePlanIsNoPlan(): void
    {
        \OrphanWorld::$orphans = [11, 12];
        \OrphanWorld::$rows    = ['cast' => 3, 'targeted' => 1, 'active' => 2];

        self::assertSame(['user_ids' => [11, 12], 'rows' => ['cast' => 3, 'targeted' => 1, 'active' => 2]], AttestationOrphansCommand::plan());

        \OrphanWorld::$readFails = true;
        self::assertNull(AttestationOrphansCommand::plan(), 'unknown is never applied');
    }

    public function testTheSummaryPrintsCountsAndNeverAnId(): void
    {
        $lines = implode("\n", AttestationOrphansCommand::summary([
            'user_ids' => [986611, 986612],
            'rows'     => ['cast' => 3, 'targeted' => 1, 'active' => 2],
        ]));

        self::assertStringContainsString('hold attestation rows: 2', $lines);
        self::assertStringContainsString('Rows they cast: 3 · rows cast against their profile: 1 · still active: 2', $lines);
        self::assertStringNotContainsString('986611', $lines);
        self::assertStringNotContainsString('Capped', $lines);

        $capped = AttestationOrphansCommand::summary(['user_ids' => range(1, AttestationOrphansCommand::MAX_ACCOUNTS), 'rows' => ['cast' => 0, 'targeted' => 0, 'active' => 0]]);
        self::assertStringContainsString('Capped at', implode("\n", $capped));
    }

    public function testTheTokenIsBoundToTheEnvironmentAndTheExactAccounts(): void
    {
        $t = AttestationOrphansCommand::token('production', [11, 12]);

        self::assertSame($t, AttestationOrphansCommand::token('production', [12, 11]), 'order does not matter');
        self::assertNotSame($t, AttestationOrphansCommand::token('staging', [11, 12]), 'another environment');
        self::assertNotSame($t, AttestationOrphansCommand::token('production', [11, 12, 13]), 'a plan that moved');
    }

    /** ApplyGuard::token is the rule block-follows already used, not a new one. */
    public function testTheSharedTokenIsTheBlockFollowsRule(): void
    {
        self::assertSame(
            BlockFollowBackfill::confirmationToken('production', [[1, 2], [3, 4]]),
            ApplyGuard::token('production', ['3:4', '1:2'])
        );
    }

    public function testApplyRunsTheSameCleanupPerAccountAndCountsFailures(): void
    {
        \OrphanWorld::$deleteFails = [12];

        self::assertSame(['purged' => 2, 'failed' => 1], AttestationOrphansCommand::apply([11, 12, 13]));
        self::assertSame([11, 12, 13], \OrphanWorld::$deleted);
    }

    public function testTheSharedFlagsStillRefuseWhatBlockFollowsRefused(): void
    {
        self::assertTrue(ApplyGuard::parse([], [])['ok']);
        self::assertFalse(ApplyGuard::parse([], [])['apply'] ?? true, 'dry run by default');
        self::assertFalse(ApplyGuard::parse([], ['force' => true])['ok'], 'unknown flag');
        self::assertFalse(ApplyGuard::parse([], ['apply' => 'yes'])['ok'], '--apply must be bare');
        self::assertFalse(ApplyGuard::parse(['x'], [])['ok'], 'no positional args');
    }
}
