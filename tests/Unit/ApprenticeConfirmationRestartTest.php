<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Application\WalletVerificationReadService;
use BCC\Trust\Core\Repositories\ContentReportRepository;
use BCC\Trust\Rank\Repositories\RankPendingRepository;
use BCC\Trust\Rank\Repositories\RankStateRepository;
use BCC\Trust\Rank\Services\ApprenticeReadinessService;
use BCC\Trust\Tests\Stubs\RankUnitWpFakes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Stubs/rank-unit-stubs.php';

/**
 * F-71 — a voided Apprentice confirmation is NOT a permanent bar.
 *
 * Pins the service half of the fix: every qualifying contribution from a
 * ready New Member re-arms a voided clock, and the readiness view stops
 * reporting a voided row as a standing qualifying contribution. The SQL half
 * (the guarded UPDATE's truth table) is pinned on both engines by
 * RankPendingRestartIntegrationTest.
 */
final class ApprenticeConfirmationRestartTest extends TestCase
{
    private const USER = 7;

    protected function setUp(): void
    {
        RankUnitWpFakes::reset();
        \BCC\Core\Permissions\Permissions::$suspended = [];
        global $wpdb;
        $wpdb = new \BCC\Trust\Tests\Stubs\FakeReportWpdb();
    }

    public function testAQualifyingContributionReArmsAVoidedClock(): void
    {
        [$service, $pending] = $this->service();

        $service->onQualifyingContribution(self::USER, 'post', 202, 666);

        self::assertSame([[self::USER, 'post', 202, 666, 86400]], $pending->started);
        self::assertSame(
            [[self::USER, 'post', 202, 666, 86400]],
            $pending->restarted,
            'a voided confirmation must be re-armed by the next contribution'
        );
    }

    public function testASuspendedVoidStillRestarts(): void
    {
        // Suspension is re-checked by the sweep at due_at, so restarting is
        // safe: a still-suspended member voids again and is never awarded.
        // The entry point does not refuse the restart on its own.
        [$service, $pending] = $this->service(voidReason: 'suspended');

        $service->onQualifyingContribution(self::USER, 'comment', 303, 777);

        self::assertCount(1, $pending->restarted);
    }

    public function testRankedMembersAndUnreadyMembersNeverTouchTheClock(): void
    {
        [$ranked, $pendingA] = $this->service(ranked: true);
        $ranked->onQualifyingContribution(self::USER, 'post', 202, 666);
        self::assertSame([], $pendingA->started);
        self::assertSame([], $pendingA->restarted);

        [$unready, $pendingB] = $this->service(ready: false);
        $unready->onQualifyingContribution(self::USER, 'post', 202, 666);
        self::assertSame([], $pendingB->started);
        self::assertSame([], $pendingB->restarted);
    }

    public function testReadinessReportsAVoidedRowAsNotQualifying(): void
    {
        [$service] = $this->service();

        $view = $service->readinessFor(self::USER);

        self::assertFalse(
            $view['qualifying_contribution'],
            'a voided row must read "contribute again", not a dead done-but-no-clock state'
        );
        self::assertNull($view['confirmation_due_at']);
    }

    public function testReadinessReportsAPendingRowAsQualifying(): void
    {
        [$service] = $this->service(status: 'pending');

        $view = $service->readinessFor(self::USER);

        self::assertTrue($view['qualifying_contribution']);
        self::assertSame('2026-11-11 00:00:00', $view['confirmation_due_at']);
    }

    /**
     * @return array{0: ApprenticeReadinessService, 1: object}
     */
    private function service(
        string $status = 'voided',
        string $voidReason = 'report_upheld',
        bool $ranked = false,
        bool $ready = true
    ): array {
        $pending = new class ($status, $voidReason) extends RankPendingRepository {
            /** @var list<array{0: int, 1: string, 2: int, 3: int, 4: int}> */
            public array $started = [];
            /** @var list<array{0: int, 1: string, 2: int, 3: int, 4: int}> */
            public array $restarted = [];
            public function __construct(private string $rowStatus, private string $reason)
            {
            }
            public function start(int $userId, string $sourceType, int $sourceId, int $contentActId, int $dueInSeconds): bool
            {
                $this->started[] = [$userId, $sourceType, $sourceId, $contentActId, $dueInSeconds];
                return true; // INSERT IGNORE "succeeds" as a no-op on an existing row
            }
            public function restartIfVoided(int $userId, string $sourceType, int $sourceId, int $contentActId, int $dueInSeconds): bool
            {
                $this->restarted[] = [$userId, $sourceType, $sourceId, $contentActId, $dueInSeconds];
                return $this->rowStatus === 'voided';
            }
            public function getForUser(int $userId): ?object
            {
                return (object) [
                    'user_id'        => (string) $userId,
                    'source_type'    => 'post',
                    'source_id'      => '101',
                    'content_act_id' => '555',
                    'due_at'         => '2026-11-11 00:00:00',
                    'status'         => $this->rowStatus,
                    'voided_reason'  => $this->rowStatus === 'voided' ? $this->reason : null,
                ];
            }
        };

        $state = new class ($ranked) extends RankStateRepository {
            public function __construct(private bool $ranked)
            {
            }
            public function getForUser(int $userId): ?object
            {
                return $this->ranked ? (object) ['user_id' => (string) $userId] : null;
            }
        };

        $wallets = new class extends WalletVerificationReadService {
            public function __construct()
            {
            }
        };

        $service = new class ($state, $pending, new ContentReportRepository(), $wallets, $ready) extends ApprenticeReadinessService {
            public function __construct(
                RankStateRepository $s,
                RankPendingRepository $p,
                ContentReportRepository $r,
                WalletVerificationReadService $w,
                private bool $ready
            ) {
                parent::__construct($s, $p, $r, $w);
            }
            protected function profileReady(int $userId): bool
            {
                return $this->ready;
            }
            protected function identityVerified(int $userId): bool
            {
                return $this->ready;
            }
        };

        return [$service, $pending];
    }
}
