<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Services\Admin\ModerationService;
use BCC\Trust\Core\Services\EliteOverridePin;
use BCC\Trust\Core\Services\ScoreOverrideLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-113: an admin score override can be UNDONE, and a manual Elite grant
 * STICKS until it is removed. The REAL ModerationService against the REAL
 * score SQL (applyPenalty's formula recompute), so the arithmetic is proven
 * on the engine.
 *
 * Only the pin and ledger STORAGE is in-memory (their protected hooks;
 * their own logic is pinned by EliteOverridePinTest / ScoreOverrideLedgerTest),
 * because the harness has no user-meta functions. The elite re-evaluation is
 * a recording closure, so the test sees exactly when it is asked for.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(ModerationService::class)]
final class ScoreOverrideUndoIntegrationTest extends TestCase
{
    private const OWNER = 987001;
    /** An ENTITY page id: below MemberSelfPageService::ID_BASE (1e9), so the pin uses post meta. */
    private const PAGE  = 270000001;

    private ScoreRepository $scores;
    private object $pin;
    private object $ledger;
    /** @var list<int> */
    private array $eliteRecomputes = [];
    private ModerationService $mod;
    private float $baseline;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/score-override-stubs.php';
        global $wpdb;
        $this->deleteRow();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
            'page_id' => self::PAGE, 'page_owner_id' => self::OWNER,
        ]), $wpdb->last_error);

        $this->scores = new ScoreRepository();
        // Normalise total_score to the canonical formula before measuring.
        $this->scores->applyPenalty(self::PAGE, 0.0);
        $this->baseline = $this->total();

        $this->pin = new class extends EliteOverridePin {
            /** @var array<int, mixed> */
            public array $post = [];
            protected function readPostMeta(int $pageId): mixed { return $this->post[$pageId] ?? ''; }
            protected function writePostMeta(int $pageId, string $value): bool { $this->post[$pageId] = $value; return true; }
            protected function deletePostMeta(int $pageId): void { unset($this->post[$pageId]); }
        };
        $this->ledger = new class extends ScoreOverrideLedger {
            /** @var array<int, mixed> */
            public array $meta = [];
            protected function readMeta(int $userId): mixed { return $this->meta[$userId] ?? ''; }
            protected function writeMeta(int $userId, string $json): bool { $this->meta[$userId] = $json; return true; }
        };

        $p = Plugin::instance();
        $this->mod = new ModerationService(
            $this->scores,
            $p->userInfoRepository(),
            $p->voteRemovalService(),
            $this->pin,
            $this->ledger,
            function (int $pageId): void { $this->eliteRecomputes[] = $pageId; }
        );
    }

    protected function tearDown(): void
    {
        $this->deleteRow();
    }

    public function testAnOverrideIsUndoneExactly(): void
    {
        $this->quiet(fn () => $this->mod->overrideScore(self::OWNER, 70, 'test'));
        self::assertEqualsWithDelta(70.0, $this->total(), 0.01);

        $r = $this->quiet(fn () => $this->mod->undoScoreOverride(self::OWNER));
        self::assertTrue($r['success']);
        self::assertEqualsWithDelta($this->baseline, $this->total(), 0.01, 'back to the pre-override score');
    }

    public function testUndoCancelsExactlyEvenAfterADisputePenalty(): void
    {
        $this->quiet(fn () => $this->mod->overrideScore(self::OWNER, 70, 'test'));
        $this->scores->applyPenalty(self::PAGE, -5.0); // a dispute penalty lands meanwhile

        $this->quiet(fn () => $this->mod->undoScoreOverride(self::OWNER));
        self::assertEqualsWithDelta($this->baseline - 5.0, $this->total(), 0.01, 'the penalty survives; only the override is removed');
    }

    public function testUndoIsLatestFirstAndRefusesWhenEmpty(): void
    {
        $this->quiet(fn () => $this->mod->overrideScore(self::OWNER, 70, 'one'));
        $this->quiet(fn () => $this->mod->overrideScore(self::OWNER, 60, 'two'));

        $this->quiet(fn () => $this->mod->undoScoreOverride(self::OWNER));
        self::assertEqualsWithDelta(70.0, $this->total(), 0.01, 'undoing the latest restores the earlier override');
        $this->quiet(fn () => $this->mod->undoScoreOverride(self::OWNER));
        self::assertEqualsWithDelta($this->baseline, $this->total(), 0.01);

        $r = $this->quiet(fn () => $this->mod->undoScoreOverride(self::OWNER));
        self::assertFalse($r['success'], 'nothing left to undo');
        self::assertEqualsWithDelta($this->baseline, $this->total(), 0.01, 'a refused undo changes nothing');
    }

    public function testAnEliteOverridePinsAndTheUndoUnpins(): void
    {
        $this->quiet(fn () => $this->mod->overrideScore(self::OWNER, 90, 'grant'));
        self::assertTrue($this->pin->isElitePinned(self::PAGE), 'elite granted manually: pinned');

        $this->quiet(fn () => $this->mod->undoScoreOverride(self::OWNER));
        self::assertFalse($this->pin->isElitePinned(self::PAGE), 'undo removes the pin it added');
        self::assertContains(self::PAGE, $this->eliteRecomputes, 'and re-evaluates the flag');
    }

    public function testAnOverrideDownClearsAnEarlierManualElite(): void
    {
        $this->quiet(fn () => $this->mod->overrideScore(self::OWNER, 90, 'grant'));
        $this->eliteRecomputes = [];

        $this->quiet(fn () => $this->mod->overrideScore(self::OWNER, 60, 'demote'));
        self::assertFalse($this->pin->isElitePinned(self::PAGE), 'a manual grant cannot outlive an override down');
        self::assertContains(self::PAGE, $this->eliteRecomputes);

        // Undo the demotion: the earlier manual grant comes back exactly.
        $this->quiet(fn () => $this->mod->undoScoreOverride(self::OWNER));
        self::assertTrue($this->pin->isElitePinned(self::PAGE));
        self::assertEqualsWithDelta(90.0, $this->total(), 0.01);
    }

    /**
     * The nightly sweep's own path: the REAL EliteEligibilityService::
     * recomputeFor(). This page has no attestations and no tenure, so the
     * evaluated verdict is "not eligible", and only the pin can keep it elite.
     */
    public function testTheNightlyRecomputeKeepsAPinnedPageEligible(): void
    {
        $svc = new \BCC\Trust\Core\Services\EliteEligibilityService(
            $this->scores,
            Plugin::instance()->attestationRepository(),
            $this->pin
        );

        self::assertTrue($svc->recomputeFor(self::PAGE));
        self::assertSame(0, $this->eliteFlag(), 'unpinned: the verdict stands');

        $this->pin->pin(self::PAGE, 940001, '2026-11-11 10:00:00');
        self::assertTrue($svc->recomputeFor(self::PAGE));
        self::assertSame(1, $this->eliteFlag(), 'pinned: the sweep keeps it eligible');

        $this->pin->post[self::PAGE] = ''; // a read error / blip
        self::assertTrue($svc->recomputeFor(self::PAGE));
        self::assertSame(0, $this->eliteFlag(), 'a failed pin read fails CLOSED to not pinned');
    }

    /**
     * F-177: the notice reports the tier the page really gets. A page whose
     * score leans on its on-chain bonus stays below Elite (the native floor
     * counts conduct only, user decision), even though the admin's Elite
     * grant is pinned. The old preview treated native = score and said
     * "elite".
     */
    public function testTheNoticeReportsTheRealTierWhenTheOnchainBonusKeepsNativeBelowTheFloor(): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'bcc_trust_page_scores', ['onchain_bonus' => 30], ['page_id' => self::PAGE]);
        $this->scores->applyPenalty(self::PAGE, 0.0);

        [$result, $notice] = $this->quietWithOutput(fn () => $this->mod->overrideScore(self::OWNER, 90, 'grant'));

        self::assertSame(90.0, round($this->total(), 2), 'control: the score itself lands on the target');
        self::assertTrue($this->pin->isElitePinned(self::PAGE), 'the admin grant is still pinned');
        self::assertSame('trusted', $result['tier'] ?? null, 'native 60 is under the floor of 71');
        self::assertSame([self::PAGE => 'trusted'], $result['page_tiers'] ?? null);
        self::assertStringContainsString('(trusted)', $notice);
        self::assertStringContainsString('1 page(s) stay below Elite until their native score (without the on-chain bonus) reaches 71', $notice);

        $this->scores->applyPenalty(self::PAGE, 0.0); // the canonical recompute
        self::assertSame('trusted', $this->storedTier(), 'the notice agrees with the tier the formula stores');
    }

    public function testWithoutAnOnchainBonusTheEliteOverrideReportsElite(): void
    {
        [$result, $notice] = $this->quietWithOutput(fn () => $this->mod->overrideScore(self::OWNER, 90, 'grant'));

        self::assertSame('elite', $result['tier'] ?? null);
        self::assertStringContainsString('(elite)', $notice);
        self::assertStringNotContainsString('stay below Elite', $notice);

        $this->scores->applyPenalty(self::PAGE, 0.0);
        self::assertSame('elite', $this->storedTier(), 'the notice agrees with the tier the formula stores');
    }

    /**
     * F-193: the notice reports the STORED tier, never a second PHP
     * derivation. A real divergence: the page was evaluated NOT elite
     * eligible, so applyPenalty()'s tierSql stores 'trusted'; the Elite grant
     * then sets the flag, and with no recalc subscriber in this harness the
     * stored tier stays 'trusted' while tierFor() on the re-read row would
     * say 'elite'. The notice must say what is stored.
     */
    public function testTheNoticeReportsTheStoredTierNotARecomputedOne(): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->update($wpdb->prefix . 'bcc_trust_page_scores', [
            'elite_eligible'    => 0,
            'elite_eligible_at' => '2026-11-01 00:00:00',
        ], ['page_id' => self::PAGE]), $wpdb->last_error);
        $this->scores->applyPenalty(self::PAGE, 0.0);

        [$result, $notice] = $this->quietWithOutput(fn () => $this->mod->overrideScore(self::OWNER, 90, 'grant'));

        $stored = $this->storedTier();
        self::assertSame('trusted', $stored, 'precondition: the formula stored trusted (gate failed at write time)');
        self::assertSame($stored, $result['tier'] ?? null, 'the notice reports what is stored');
        self::assertStringContainsString('(' . $stored . ')', $notice);
    }

    private function storedTier(): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT reputation_tier FROM ' . $wpdb->prefix . 'bcc_trust_page_scores WHERE page_id = %d',
            self::PAGE
        ));
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function quietWithOutput(callable $fn): array
    {
        ob_start();
        try {
            $result = $fn();
        } finally {
            $out = (string) ob_get_clean();
        }
        return [$result, $out];
    }

    /** The admin Undo button shows exactly what the next undo will undo. */
    public function testTheUndoButtonTracksTheStack(): void
    {
        self::assertNull($this->mod->latestUndoableOverride(self::OWNER), 'nothing to undo: no button');

        $this->quiet(fn () => $this->mod->overrideScore(self::OWNER, 70, 'one'));
        $this->quiet(fn () => $this->mod->overrideScore(self::OWNER, 60, 'two'));
        $next = $this->mod->latestUndoableOverride(self::OWNER);
        self::assertNotNull($next);
        self::assertSame([60, 'two'], [$next['new_score'], $next['reason']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $next['at']);

        $this->quiet(fn () => $this->mod->undoScoreOverride(self::OWNER));
        $next = $this->mod->latestUndoableOverride(self::OWNER);
        self::assertNotNull($next);
        self::assertSame([70, 'one'], [$next['new_score'], $next['reason']]);

        $this->quiet(fn () => $this->mod->undoScoreOverride(self::OWNER));
        self::assertNull($this->mod->latestUndoableOverride(self::OWNER), 'stack empty: the button goes away');
    }

    public function testAnUnreadableUndoRecordHidesTheButtonInsteadOfBreakingThePage(): void
    {
        $broken = new class extends ScoreOverrideLedger {
            protected function readMeta(int $userId): mixed { throw new \RuntimeException('meta read failed'); }
        };
        $p   = Plugin::instance();
        $mod = new ModerationService($this->scores, $p->userInfoRepository(), $p->voteRemovalService(), $this->pin, $broken);

        self::assertNull($mod->latestUndoableOverride(self::OWNER));
    }

    private function eliteFlag(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT elite_eligible FROM ' . $wpdb->prefix . 'bcc_trust_page_scores WHERE page_id = %d',
            self::PAGE
        ));
    }

    private function total(): float
    {
        global $wpdb;
        return (float) $wpdb->get_var($wpdb->prepare(
            'SELECT total_score FROM ' . $wpdb->prefix . 'bcc_trust_page_scores WHERE page_id = %d',
            self::PAGE
        ));
    }

    /**
     * Run an admin action, swallowing its HTML notice.
     *
     * @param callable(): array<string, mixed> $fn
     * @return array<string, mixed>
     */
    private function quiet(callable $fn): array
    {
        ob_start();
        try {
            return $fn();
        } finally {
            ob_end_clean();
        }
    }

    private function deleteRow(): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'bcc_trust_page_scores', ['page_id' => self::PAGE]);
    }
}
