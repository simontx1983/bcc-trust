<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Trust\Onchain\Services\ClaimReverifySweepService;
    use BCC\Trust\Onchain\Support\ProviderRequestBudget;
    use BCC\Trust\Onchain\ValueObjects\EligibilityVerdict;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-14 PR B2: the weekly re-verify sweep.
     *
     * KEEP / REVOKE / UNKNOWN per claim; UNKNOWN (or any throw) NEVER revokes.
     * Report mode (the default) only audits what it would revoke; enforce mode
     * revokes and rings the claimant's bell. Validator: a gone row or a wallet
     * that no longer proves the operator revokes; a jailed validator is kept.
     * Holder: INELIGIBLE revokes, an exhausted budget stops the tick on that
     * claim. Creator: always UNKNOWN until PR C.
     */
    #[CoversClass(ClaimReverifySweepService::class)]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class ClaimReverifySweepTest extends TestCase
    {
        // A Cosmos operator and the same 20 bytes as an account address.
        private const VALOPER = 'cosmosvaloper1qy352euf40x77qfrg4ncn27dauqjx3t8vjukmd';
        private const ACCOUNT = 'cosmos1qy352euf40x77qfrg4ncn27dauqjx3t8fxgrh7';
        /** A valid account address with DIFFERENT bytes from the operator. */
        private const OTHER_ACCOUNT = 'cosmos1lmwt4xrk2sepplkuh2v8v4pjzrldew5c9hfvs5';

        protected function setUp(): void
        {
            parent::setUp();
            if (!class_exists('\\BCC\\Core\\Log\\Logger', false)) {
                eval('namespace BCC\\Core\\Log; final class Logger { public static function info(string $m, array $c = []): void {} public static function warning(string $m, array $c = []): void {} public static function error(string $m, array $c = []): void {} }');
            }
            TestSweep::reset();
        }

        /** @return object */
        private static function validatorClaim(int $id, ?int $entityRow = 9, string $wallet = self::ACCOUNT): object
        {
            return (object) [
                'id' => (string) $id, 'user_id' => '5', 'entity_type' => 'validator', 'entity_id' => '9',
                'wallet_address' => $wallet, 'chain_id' => '3', 'claim_role' => 'operator',
                'entity_row_id' => $entityRow === null ? null : (string) $entityRow,
                'operator_address' => self::VALOPER, 'contract_address' => null,
                'entity_name' => 'Iqlusion', 'chain_slug' => 'cosmos', 'chain_type' => 'cosmos',
            ];
        }

        private static function holderClaim(int $id, string $role = 'holder'): object
        {
            return (object) [
                'id' => (string) $id, 'user_id' => '6', 'entity_type' => 'collection', 'entity_id' => '4',
                'wallet_address' => '0xabc', 'chain_id' => '1', 'claim_role' => $role,
                'entity_row_id' => '4', 'operator_address' => null, 'contract_address' => '0xc0ffee',
                'entity_name' => 'Bad Kids', 'chain_slug' => 'ethereum', 'chain_type' => 'evm',
            ];
        }

        private static function wallet(string $address = self::ACCOUNT, int $chainId = 3): object
        {
            return (object) ['id' => '1', 'wallet_address' => $address, 'chain_id' => (string) $chainId];
        }

        // ── validator claims ────────────────────────────────────────────────

        public function testAValidatorClaimWhoseWalletStillProvesTheOperatorIsKept(): void
        {
            TestSweep::$rows    = [self::validatorClaim(1)];
            TestSweep::$wallets = [5 => [self::wallet()]];

            $stats = (new TestSweep())->sweep();

            self::assertSame(1, $stats['kept']);
            self::assertSame([], TestSweep::$wouldRevoke);
        }

        public function testAGoneWalletLinkIsAWouldRevokeInReportMode(): void
        {
            TestSweep::$rows    = [self::validatorClaim(1)];
            TestSweep::$wallets = [5 => []];

            $stats = (new TestSweep())->sweep();

            self::assertSame('report', $stats['mode']);
            self::assertSame(1, $stats['would_revoke']);
            self::assertSame([[1, 'wallet_link_gone']], TestSweep::$wouldRevoke);
            self::assertSame([], TestSweep::$revoked, 'report mode never revokes');
            self::assertSame([], TestSweep::$bells, 'report mode never rings the bell');
        }

        public function testAWalletOnAnotherChainOrAnotherAddressDoesNotCount(): void
        {
            TestSweep::$rows    = [self::validatorClaim(1)];
            TestSweep::$wallets = [5 => [self::wallet(self::ACCOUNT, 99), self::wallet('cosmos1other')]];

            (new TestSweep())->sweep();

            self::assertSame([[1, 'wallet_link_gone']], TestSweep::$wouldRevoke);
        }

        public function testALinkThatNoLongerProvesTheOperatorRevokes(): void
        {
            // The claim's wallet is still linked, but it is not the operator's
            // account (the operator address changed underneath it).
            $claim = self::validatorClaim(1, 9, self::OTHER_ACCOUNT);
            TestSweep::$rows    = [$claim];
            TestSweep::$wallets = [5 => [self::wallet(self::OTHER_ACCOUNT)]];

            (new TestSweep())->sweep();

            self::assertSame([[1, 'wallet_link_gone']], TestSweep::$wouldRevoke);
        }

        public function testAGoneValidatorRowRevokes(): void
        {
            TestSweep::$rows = [self::validatorClaim(1, null)];

            (new TestSweep())->sweep();

            self::assertSame([[1, 'validator_row_gone']], TestSweep::$wouldRevoke);
        }

        public function testAFailedWalletReadIsUnknownNeverARevoke(): void
        {
            TestSweep::$rows        = [self::validatorClaim(1)];
            TestSweep::$walletsThrow = true;

            $stats = (new TestSweep())->sweep();

            self::assertSame([], TestSweep::$wouldRevoke);
            self::assertSame(['error' => 1], $stats['skipped_reasons']);
        }

        // ── holder / creator claims ─────────────────────────────────────────

        public function testHolderVerdictsMapToKeepRevokeAndUnknown(): void
        {
            TestSweep::$rows = [self::holderClaim(1), self::holderClaim(2), self::holderClaim(3)];
            TestSweep::$verdicts = [
                EligibilityVerdict::eligible(1, 1),
                EligibilityVerdict::ineligible(1, 0),
                EligibilityVerdict::unknownBecause(1, null, EligibilityVerdict::REASON_PROVIDER_UNAVAILABLE),
            ];

            $stats = (new TestSweep())->sweep();

            self::assertSame(1, $stats['kept']);
            self::assertSame([[2, 'no_longer_holder']], TestSweep::$wouldRevoke);
            self::assertSame([EligibilityVerdict::REASON_PROVIDER_UNAVAILABLE => 1], $stats['skipped_reasons']);
        }

        public function testACreatorClaimIsNeverDecidedYet(): void
        {
            TestSweep::$rows = [self::holderClaim(1, 'creator')];

            $stats = (new TestSweep())->sweep();

            self::assertSame([], TestSweep::$wouldRevoke);
            self::assertSame(['creator_check_pending' => 1], $stats['skipped_reasons']);
            self::assertSame(0, TestSweep::$verdictCalls, 'no provider call for a creator claim');
        }

        public function testAnExhaustedBudgetStopsTheTickOnThatClaimAndContinues(): void
        {
            TestSweep::$rows = [self::holderClaim(1), self::holderClaim(2), self::holderClaim(3)];
            TestSweep::$verdicts = [
                EligibilityVerdict::eligible(1, 1),
                EligibilityVerdict::unknownBecause(1, null, EligibilityVerdict::REASON_BUDGET_EXHAUSTED),
            ];

            $stats = (new TestSweep())->sweep();

            self::assertTrue($stats['stopped_on_budget']);
            self::assertSame(1, $stats['checked']);
            self::assertSame(1, TestSweep::$cursor, 'the cursor stays BEFORE claim 2 so it is retried');
            self::assertSame(1, TestSweep::$continuations);
        }

        public function testAClaimThatAloneExhaustsAFreshBudgetIsSkippedNotLoopedOn(): void
        {
            TestSweep::$rows = [self::holderClaim(1), self::holderClaim(2)];
            TestSweep::$verdicts = [
                EligibilityVerdict::unknownBecause(1, null, EligibilityVerdict::REASON_BUDGET_EXHAUSTED),
                EligibilityVerdict::eligible(1, 1),
            ];

            $stats = (new TestSweep())->sweep();

            self::assertFalse($stats['stopped_on_budget']);
            self::assertSame([EligibilityVerdict::REASON_BUDGET_EXHAUSTED => 1], $stats['skipped_reasons']);
            self::assertSame(1, $stats['kept'], 'the sweep moved past it');
        }

        // ── enforce mode ────────────────────────────────────────────────────

        public function testEnforceModeRevokesAndRingsTheBell(): void
        {
            TestSweep::$enforce = true;
            TestSweep::$rows    = [self::validatorClaim(1), self::holderClaim(2)];
            TestSweep::$wallets = [5 => []];
            TestSweep::$verdicts = [EligibilityVerdict::ineligible(1, 0)];

            $stats = (new TestSweep())->sweep();

            self::assertSame('enforce', $stats['mode']);
            self::assertSame([[1, 'wallet_link_gone'], [2, 'no_longer_holder']], TestSweep::$revoked);
            self::assertSame([[5, 1, 'Iqlusion', 'wallet_link_gone'], [6, 2, 'Bad Kids', 'no_longer_holder']], TestSweep::$bells);
            self::assertSame([], TestSweep::$wouldRevoke, 'enforce mode does not write would-apply rows');
            self::assertSame(2, $stats['revoked']);
        }

        public function testAnAlreadyRevokedClaimRingsNoBell(): void
        {
            TestSweep::$enforce     = true;
            TestSweep::$revokeResult = false;
            TestSweep::$rows        = [self::validatorClaim(1)];
            TestSweep::$wallets     = [5 => []];

            $stats = (new TestSweep())->sweep();

            self::assertSame(1, $stats['already_revoked']);
            self::assertSame([], TestSweep::$bells);
        }

        public function testAFailedRevokeIsUnknownAndRingsNoBell(): void
        {
            TestSweep::$enforce     = true;
            TestSweep::$revokeThrow = true;
            TestSweep::$rows        = [self::validatorClaim(1)];
            TestSweep::$wallets     = [5 => []];

            $stats = (new TestSweep())->sweep();

            self::assertSame(0, $stats['revoked']);
            self::assertSame(['revoke_failed' => 1], $stats['skipped_reasons']);
            self::assertSame([], TestSweep::$bells);
        }

        // ── lock, cursor, continuation ──────────────────────────────────────

        public function testAHeldLockSkipsTheTick(): void
        {
            TestSweep::$lockHeldElsewhere = true;
            TestSweep::$rows = [self::validatorClaim(1)];

            $stats = (new TestSweep())->sweep();

            self::assertTrue($stats['locked']);
            self::assertSame(0, $stats['checked']);
            self::assertSame(0, TestSweep::$releases, 'a lock this run did not take is not released');
        }

        public function testTheDeadlineSavesTheCursorAndContinuesAndTheLockIsReleased(): void
        {
            TestSweep::$secondsPerClaim = 20; // 30 s budget: 2 claims a tick.
            TestSweep::$rows    = [self::validatorClaim(1), self::validatorClaim(2), self::validatorClaim(3)];
            TestSweep::$wallets = [5 => [self::wallet()]];

            $stats = (new TestSweep())->sweep();

            self::assertTrue($stats['continued']);
            self::assertSame(2, TestSweep::$cursor);
            self::assertSame(1, TestSweep::$continuations);
            self::assertSame(1, TestSweep::$releases);
        }

        public function testAFailedClaimReadKeepsTheCursorAndReleasesTheLock(): void
        {
            TestSweep::$cursor     = 7;
            TestSweep::$listThrows = true;

            try {
                (new TestSweep())->sweep();
                self::fail('a failed read must propagate, not read as "no more claims"');
            } catch (\RuntimeException) {
            }

            self::assertSame(7, TestSweep::$cursor);
            self::assertSame(1, TestSweep::$releases);
        }

        public function testACompleteCycleClearsTheCursor(): void
        {
            TestSweep::$cursor  = 0;
            TestSweep::$rows    = [self::validatorClaim(1)];
            TestSweep::$wallets = [5 => [self::wallet()]];

            $stats = (new TestSweep())->sweep();

            self::assertTrue($stats['complete']);
            self::assertNull(TestSweep::$cursor);
        }
    }

    /** The sweep with every collaborator replaced. */
    final class TestSweep extends ClaimReverifySweepService
    {
        /** @var list<object> */
        public static array $rows = [];
        public static bool $listThrows = false;
        /** @var array<int, list<object>> */
        public static array $wallets = [];
        public static bool $walletsThrow = false;
        /** @var list<EligibilityVerdict> */
        public static array $verdicts = [];
        public static int $verdictCalls = 0;
        public static bool $enforce = false;
        public static bool $revokeResult = true;
        public static bool $revokeThrow = false;
        /** @var list<array{int, string}> */
        public static array $revoked = [];
        /** @var list<array{int, string}> */
        public static array $wouldRevoke = [];
        /** @var list<array{int, int, string, string}> */
        public static array $bells = [];
        public static ?int $cursor = null;
        public static int $continuations = 0;
        public static bool $lockHeldElsewhere = false;
        public static int $releases = 0;
        public static int $clock = 0;
        public static int $secondsPerClaim = 0;

        public static function reset(): void
        {
            self::$rows = []; self::$listThrows = false; self::$wallets = []; self::$walletsThrow = false;
            self::$verdicts = []; self::$verdictCalls = 0; self::$enforce = false; self::$revokeResult = true;
            self::$revokeThrow = false; self::$revoked = []; self::$wouldRevoke = []; self::$bells = [];
            self::$cursor = null; self::$continuations = 0; self::$lockHeldElsewhere = false; self::$releases = 0;
            self::$clock = 0; self::$secondsPerClaim = 0;
        }

        public function enforcing(): bool { return self::$enforce; }
        protected function now(): int { return self::$clock; }
        protected function acquireLock(): bool { return !self::$lockHeldElsewhere; }
        protected function releaseLock(): void { self::$releases++; }
        protected function readCursor(): int { return self::$cursor ?? 0; }
        protected function writeCursor(int $claimId): void { self::$cursor = $claimId; }
        protected function clearCursor(): void { self::$cursor = null; }
        protected function scheduleContinuation(): void { self::$continuations++; }

        protected function listClaims(int $afterId, int $limit): array
        {
            if (self::$listThrows) {
                throw new \RuntimeException('read failed');
            }
            $out = array_values(array_filter(self::$rows, static fn (object $r): bool => (int) $r->id > $afterId));
            return array_slice($out, 0, $limit);
        }

        protected function verifiedWallets(int $userId): array
        {
            self::$clock += self::$secondsPerClaim;
            if (self::$walletsThrow) {
                throw new \RuntimeException('wallet read failed');
            }
            return self::$wallets[$userId] ?? [];
        }

        protected function newBudget(): ProviderRequestBudget
        {
            return new ProviderRequestBudget(300, 20);
        }

        protected function holdingVerdict(int $userId, string $chainSlug, string $contract, ProviderRequestBudget $budget): EligibilityVerdict
        {
            self::$clock += self::$secondsPerClaim;
            $v = self::$verdicts[self::$verdictCalls] ?? EligibilityVerdict::eligible(1, 1);
            self::$verdictCalls++;
            return $v;
        }

        protected function revoke(int $claimId, string $reason): bool
        {
            if (self::$revokeThrow) {
                throw new \RuntimeException('revoke failed before commit');
            }
            self::$revoked[] = [$claimId, $reason];
            return self::$revokeResult;
        }

        protected function notify(int $userId, int $claimId, string $entityName, string $reason): void
        {
            self::$bells[] = [$userId, $claimId, $entityName, $reason];
        }

        protected function auditWouldRevoke(object $row, string $reason): void
        {
            self::$wouldRevoke[] = [(int) $row->id, $reason];
        }

        protected function logStats(array $stats): void {}
    }
}
