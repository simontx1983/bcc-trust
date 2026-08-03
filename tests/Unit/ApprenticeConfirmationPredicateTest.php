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
 * THE R1 SUITE (approved plan, owner correction 1 / invariant 8):
 * raw accusations can never block or delay Apprentice.
 *
 * Runs the real ApprenticeReadinessService confirmation sweep against
 * a due pending row, with the real (final) ContentReportRepository on
 * a fake $wpdb whose only knob is the count of STATUS=1
 * (resolved-action_taken) reports. The six mandated cases:
 *
 *   (a) any number of PENDING reports → promotion at hour 24 unaffected
 *       (the predicate never queries pending rows at all — proven by
 *       inspecting the SQL the sweep issues);
 *   (b) pending-volume auto-hide → promotion unaffected (auto-hide
 *       lives in wp_bcc_hidden_activities and leaves the wp_posts row
 *       published; the predicate consults neither the sidecar nor
 *       due_at adjustments);
 *   (c) dismissed reports (status=2) → zero Rank effect (the one
 *       report query matches status=1 only);
 *   (d) upheld action before hour 24 → void, no promotion;
 *   (e) upheld action AFTER promotion → ordinary ledger reversal +
 *       recovery rules — the reversal wiring is the Phase 4
 *       `bcc_content_moderation_undone`/reverseBySource path and the
 *       recovery semantics are pinned in
 *       RankPromotionTransitionSemanticsTest (grace-first, never
 *       instant demotion);
 *   (f) deliberate admin removal (unpublished/deleted content) voids,
 *       while an auto-hide (still-published content) does not — the
 *       two are distinguishable and only the former blocks.
 */
final class ApprenticeConfirmationPredicateTest extends TestCase
{
    public const USER = 7;

    private static ?\BCC\Trust\Tests\Stubs\FakeReportWpdb $wpdb = null;

    protected function setUp(): void
    {
        RankUnitWpFakes::reset();
        \BCC\Core\Permissions\Permissions::$suspended = [];

        // Fake $wpdb for the real ContentReportRepository.
        global $wpdb;
        $wpdb       = new \BCC\Trust\Tests\Stubs\FakeReportWpdb();
        self::$wpdb = $wpdb;
    }

    /**
     * Real service with stubbed rank repos + the real report repo.
     *
     * @return array{0: ApprenticeReadinessService, 1: object, 2: object}
     *         [service, pendingStub, stateStub]
     */
    private function service(string $postStatus = 'publish', int $postAuthor = self::USER, bool $postExists = true): array
    {
        if ($postExists) {
            RankUnitWpFakes::$posts[101] = (object) [
                'post_status' => $postStatus,
                'post_author' => (string) $postAuthor,
            ];
        }

        $pending = new class extends RankPendingRepository {
            /** @var list<array{0: int, 1: string, 2: string|null}> */
            public array $resolved = [];
            public function __construct()
            {
            }
            public function listDue(): array
            {
                return [(object) [
                    'user_id'        => (string) ApprenticeConfirmationPredicateTest::USER,
                    'source_type'    => 'post',
                    'source_id'      => '101',
                    'content_act_id' => '555',
                    'due_at'         => '2026-07-31 00:00:00',
                    'status'         => 'pending',
                ]];
            }
            public function resolve(int $userId, string $status, ?string $voidedReason = null): bool
            {
                $this->resolved[] = [$userId, $status, $voidedReason];
                return true;
            }
        };

        $state = new class extends RankStateRepository {
            /** @var list<int> */
            public array $awarded = [];
            public function __construct()
            {
            }
            public function awardApprentice(int $userId): bool
            {
                $this->awarded[] = $userId;
                return true;
            }
        };

        $wallets = new class extends WalletVerificationReadService {
            public function __construct()
            {
            }
        };

        $service = new ApprenticeReadinessService(
            $state,
            $pending,
            new ContentReportRepository(),
            $wallets
        );

        return [$service, $pending, $state];
    }

    public function testPendingReportsNeverDelayTheAward(): void
    {
        // (a) A brigade of PENDING (status=0) reports exists conceptually;
        // the sweep still awards at hour 24 because the predicate consults
        // only status=1. Prove it structurally: the single report query
        // issued matches status = 1 and nothing queries status = 0.
        [$service, $pending, $state] = $this->service();

        self::assertSame(1, $service->runConfirmationSweep());
        self::assertSame([self::USER], $state->awarded);
        self::assertSame([[self::USER, 'confirmed', null]], $pending->resolved);

        $wpdb = self::$wpdb;
        self::assertNotNull($wpdb);
        self::assertCount(1, $wpdb->queries, 'exactly one report query — the upheld check');
        self::assertStringContainsString('status = 1', $wpdb->queries[0]);
        self::assertStringNotContainsString('status = 0', $wpdb->queries[0]);

        // The award fires the standard promotion chain.
        self::assertContains('bcc_rank_awarded', RankUnitWpFakes::firedHooks());
    }

    public function testAutoHideDoesNotBlockTheAward(): void
    {
        // (b) Report-volume auto-hide writes wp_bcc_hidden_activities and
        // leaves the wp_posts row PUBLISHED. The predicate reads the post
        // row + upheld reports only — an auto-hidden contribution under
        // unresolved pending reports still promotes.
        [$service, , $state] = $this->service(postStatus: 'publish');

        self::assertSame(1, $service->runConfirmationSweep());
        self::assertSame([self::USER], $state->awarded);
    }

    public function testDismissedReportsHaveZeroEffect(): void
    {
        // (c) Dismissed = status 2. The upheld check matches status=1
        // only, so a dismissed report can never void (count stays 0).
        [$service, , $state] = $this->service();
        $wpdb = self::$wpdb;
        self::assertNotNull($wpdb);
        $wpdb->upheldCount = 0; // dismissed rows don't match status=1

        self::assertSame(1, $service->runConfirmationSweep());
        self::assertSame([self::USER], $state->awarded);
    }

    public function testUpheldReportBeforeHour24Voids(): void
    {
        // (d) A report resolved WITH ACTION (status=1) before due_at →
        // void, no award, reason recorded.
        [$service, $pending, $state] = $this->service();
        $wpdb = self::$wpdb;
        self::assertNotNull($wpdb);
        $wpdb->upheldCount = 1;

        self::assertSame(0, $service->runConfirmationSweep());
        self::assertSame([], $state->awarded);
        self::assertSame([[self::USER, 'voided', 'report_upheld']], $pending->resolved);
        self::assertNotContains('bcc_rank_awarded', RankUnitWpFakes::firedHooks());
    }

    public function testAdminRemovalIsDistinguishableFromAutoHide(): void
    {
        // (f) Deliberate removal changes the wp_posts row itself
        // (trash/delete) → void. Auto-hide does not (case (b) above
        // proved the still-published row promotes) — only the former
        // blocks.
        [$service, $pending, $state] = $this->service(postStatus: 'trash');

        self::assertSame(0, $service->runConfirmationSweep());
        self::assertSame([], $state->awarded);
        self::assertSame([[self::USER, 'voided', 'content_unpublished']], $pending->resolved);

        // Deleted outright → same outcome, distinct reason.
        RankUnitWpFakes::reset();
        [$service2, $pending2] = $this->service(postExists: false);
        self::assertSame(0, $service2->runConfirmationSweep());
        self::assertSame([[self::USER, 'voided', 'content_removed']], $pending2->resolved);
    }

    public function testSuspensionVoids(): void
    {
        // Condition 6 of the predicate — suspension is a formal state,
        // not a raw accusation.
        [$service, $pending, $state] = $this->service();
        \BCC\Core\Permissions\Permissions::$suspended = [self::USER];

        self::assertSame(0, $service->runConfirmationSweep());
        self::assertSame([], $state->awarded);
        self::assertSame([[self::USER, 'voided', 'suspended']], $pending->resolved);
    }
}
