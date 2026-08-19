<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainsPage;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * VC-B3b DOMAIN contract: what Pause, Resume, Backfill and Retry actually
 * do once the request boundary has let them through.
 *
 * ── THE PROPERTY THIS FILE EXISTS FOR ───────────────────────────────────
 * Every one of these four can fail in a way that looks like success. The
 * legacy handlers collapsed those cases: `pauseCwDiscovery()` returning
 * false meant "already paused, OR no CosmWasm module, OR the write failed"
 * and the operator got one vague warning covering all three. Backfill
 * reported "ran one slice" whether or not the advisory lock was even
 * acquired.
 *
 * So each operation is asserted to distinguish its outcomes — no-op, gate
 * refusal, write failure, unconfirmed read-back, exception — and, just as
 * importantly, to write a durable row ONLY for the outcomes it can
 * actually stand behind.
 *
 * ── AND THE ONE IT MUST NOT CROSS ───────────────────────────────────────
 * PR #200 owns the CosmwasmTickBudget reserve sequence inside the worker.
 * This handler constructs the budget once and hands it over. The fake
 * budget counts reserve()/available() so "the boundary did not reach into
 * that sequence" is a measured zero rather than a reading of the source.
 */
#[CoversClass(ChainsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ChainsCwScannerOperationDomainTest extends TestCase
{
    private const CHAIN_ID = 4;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/chains-cw-operations-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        ChainRepository::reset();
        ChainCheckpointRepository::reset();
        CosmwasmCodeFamilyRepository::reset();
        CosmwasmContractRepository::reset();
        CosmwasmDiscoveryWorker::reset();
        CosmwasmTickBudget::reset();
        CosmwasmDiscoveryGate::reset();

        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ChainRepository::seed(self::CHAIN_ID, 'cosmos', true);
    }

    /** Drive one route end to end and return the PRG result code. */
    private function drive(string $route): string
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction = $route . '_' . self::CHAIN_ID;

        $handler = [
            ChainsPage::ACTION_CW_PAUSE    => 'handle_cw_pause',
            ChainsPage::ACTION_CW_RESUME   => 'handle_cw_resume',
            ChainsPage::ACTION_CW_BACKFILL => 'handle_cw_backfill',
            ChainsPage::ACTION_CW_RETRY    => 'handle_cw_retry',
        ][$route];

        try {
            ChainsPage::{$handler}();
        } catch (\BccAdminRedirect $r) {
            return (string) ($r->args['bcc_cwo'] ?? '');
        }

        $this->fail('The handler must terminate in a PRG redirect.');
    }

    /** @return list<string> */
    private function audits(): array
    {
        return \BCC\Trust\Core\Security\AuditLogger::actions();
    }

    private function state(): string
    {
        $row = ChainCheckpointRepository::$rows[self::CHAIN_ID] ?? null;

        return $row === null ? '' : (string) $row->cw_discovery_state;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  PAUSE
    // ═══════════════════════════════════════════════════════════════════

    public function testPauseWritesOnceAndAuditsTheConfirmedResult(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID, ChainCheckpointRepository::CW_STATE_BACKFILLING);

        $this->assertSame('paused', $this->drive(ChainsPage::ACTION_CW_PAUSE));
        $this->assertSame(1, ChainCheckpointRepository::$pauseCalls, 'exactly one write');
        $this->assertSame(ChainCheckpointRepository::CW_STATE_PAUSED, $this->state());
        $this->assertSame(['admin_chain_cw_paused'], $this->audits());
    }

    /**
     * Pause contacts nothing. It is a hold on FUTURE selection, and it
     * cannot cancel a pass already inside the worker's advisory lock —
     * which is why the operator copy does not claim it can.
     */
    public function testPausePerformsNoProviderOrScannerActivity(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);

        $this->drive(ChainsPage::ACTION_CW_PAUSE);

        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes);
        $this->assertSame([], CosmwasmTickBudget::$constructions);
    }

    /** Already paused: no write, no durable row — nothing changed. */
    public function testPausingAPausedChainIsANoOpWithNoAuditRow(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID, ChainCheckpointRepository::CW_STATE_PAUSED);

        $this->assertSame('pause_noop', $this->drive(ChainsPage::ACTION_CW_PAUSE));
        $this->assertSame(0, ChainCheckpointRepository::$pauseCalls);
        $this->assertSame([], $this->audits(), 'a no-op is not a state change');
    }

    /**
     * `unsupported` is TERMINAL and must not be overwritten: it is the
     * durable "this chain answered the code listing with a 501" fact, and
     * pausing over it would put the chain back in the rotation on resume.
     * Reported apart from "already paused" rather than as one vague
     * failure, which is what the legacy handler did.
     */
    public function testPausingAnUnsupportedChainIsRefusedDistinctly(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID, ChainCheckpointRepository::CW_STATE_UNSUPPORTED);

        $this->assertSame('pause_unsupported', $this->drive(ChainsPage::ACTION_CW_PAUSE));
        $this->assertSame(0, ChainCheckpointRepository::$pauseCalls);
        $this->assertSame(ChainCheckpointRepository::CW_STATE_UNSUPPORTED, $this->state());
        $this->assertSame([], $this->audits());
    }

    public function testAFailedPauseWriteIsReportedAsAFailureAndAudited(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);
        ChainCheckpointRepository::$pauseResult = false;

        $this->assertSame('pause_failed', $this->drive(ChainsPage::ACTION_CW_PAUSE));
        $this->assertSame(['admin_chain_cw_pause_failed'], $this->audits());
        $this->assertNotSame(ChainCheckpointRepository::CW_STATE_PAUSED, $this->state());
    }

    /**
     * The write returned true and the read-back disagrees. DISTINCT from a
     * failed write: the difference is whether anything might have changed,
     * and an operator who is told "failed" will retry while one told
     * "could not be confirmed" will go and look.
     */
    public function testAnUnconfirmedPauseIsNotReportedAsDone(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);
        ChainCheckpointRepository::$readBackState = ChainCheckpointRepository::CW_STATE_IDLE;

        $this->assertSame('pause_unconfirmed', $this->drive(ChainsPage::ACTION_CW_PAUSE));
        $this->assertSame(['admin_chain_cw_pause_unconfirmed'], $this->audits());
        $this->assertNotContains('admin_chain_cw_paused', $this->audits());
    }

    /** An unreadable read-back is also not a success. */
    public function testAnUnreadableReadBackIsNotReportedAsDone(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);
        ChainCheckpointRepository::$readBackNull = true;

        $this->assertSame('pause_unconfirmed', $this->drive(ChainsPage::ACTION_CW_PAUSE));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  RESUME
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Resume returns the chain to the state its OWN progress says it is
     * in, computed by the repository before the write. A drained chain
     * resumed to `idle` would make the worker re-walk its entire code
     * listing, so the handler must not reimplement this arithmetic.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function resumeStates(): array
    {
        return [
            'completed backfill' => [
                ['cw_backfill_completed_at' => '2026-08-01 00:00:00'],
                ChainCheckpointRepository::CW_STATE_BACKFILLED,
            ],
            'cursor open' => [
                ['cw_code_cursor' => '4210'],
                ChainCheckpointRepository::CW_STATE_BACKFILLING,
            ],
            'watermark only' => [
                ['cw_code_watermark' => '99'],
                ChainCheckpointRepository::CW_STATE_BACKFILLING,
            ],
            'never started' => [
                [],
                ChainCheckpointRepository::CW_STATE_IDLE,
            ],
        ];
    }

    /** @param array<string, mixed> $progress */
    #[DataProvider('resumeStates')]
    public function testResumeRestoresTheStateItsOwnProgressImplies(array $progress, string $expected): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID, ChainCheckpointRepository::CW_STATE_PAUSED, $progress);

        $this->assertSame('resumed', $this->drive(ChainsPage::ACTION_CW_RESUME));
        $this->assertSame($expected, $this->state());
        $this->assertSame(1, ChainCheckpointRepository::$resumeCalls);
        $this->assertSame(['admin_chain_cw_resumed'], $this->audits());
    }

    public function testResumePerformsNoProviderOrScannerActivity(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID, ChainCheckpointRepository::CW_STATE_PAUSED);

        $this->drive(ChainsPage::ACTION_CW_RESUME);

        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes, 'resume starts nothing');
        $this->assertSame([], CosmwasmTickBudget::$constructions);
    }

    /** @return array<string, array{0: string}> */
    public static function notPausedStates(): array
    {
        return [
            'idle'        => [ChainCheckpointRepository::CW_STATE_IDLE],
            'backfilling' => [ChainCheckpointRepository::CW_STATE_BACKFILLING],
            'backfilled'  => [ChainCheckpointRepository::CW_STATE_BACKFILLED],
            'unsupported' => [ChainCheckpointRepository::CW_STATE_UNSUPPORTED],
        ];
    }

    #[DataProvider('notPausedStates')]
    public function testResumingAChainThatIsNotPausedIsANoOp(string $state): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID, $state);

        $this->assertSame('resume_noop', $this->drive(ChainsPage::ACTION_CW_RESUME));
        $this->assertSame(0, ChainCheckpointRepository::$resumeCalls);
        $this->assertSame($state, $this->state(), 'an unsupported chain is not resumed into the rotation');
        $this->assertSame([], $this->audits());
    }

    public function testAFailedResumeWriteIsReportedAsAFailureAndAudited(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID, ChainCheckpointRepository::CW_STATE_PAUSED);
        ChainCheckpointRepository::$resumeResult = false;

        $this->assertSame('resume_failed', $this->drive(ChainsPage::ACTION_CW_RESUME));
        $this->assertSame(['admin_chain_cw_resume_failed'], $this->audits());
        $this->assertSame(ChainCheckpointRepository::CW_STATE_PAUSED, $this->state(), 'still paused');
    }

    /**
     * The read-back is compared against the state computed BEFORE the
     * write, not against whatever it happens to find — otherwise any
     * value at all would confirm the resume.
     */
    public function testAnUnconfirmedResumeIsNotReportedAsDone(): void
    {
        ChainCheckpointRepository::seed(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_PAUSED,
            ['cw_backfill_completed_at' => '2026-08-01 00:00:00']
        );
        // Expected `backfilled`; the store reports `idle`.
        ChainCheckpointRepository::$readBackState = ChainCheckpointRepository::CW_STATE_IDLE;

        $this->assertSame('resume_unconfirmed', $this->drive(ChainsPage::ACTION_CW_RESUME));
        $this->assertSame(['admin_chain_cw_resume_unconfirmed'], $this->audits());
    }

    // ═══════════════════════════════════════════════════════════════════
    //  BACKFILL — the only provider-consuming control
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ONE budget, 20 requests and 8 seconds, handed to the worker — and
     * the handler never touches the reserve sequence PR #200 owns.
     */
    public function testBackfillConstructsExactlyOneTwentyEightBudgetAndPassesIt(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);

        $this->assertSame('backfill_requested', $this->drive(ChainsPage::ACTION_CW_BACKFILL));

        $this->assertSame(
            [['requests' => 20, 'seconds' => 8]],
            CosmwasmTickBudget::$constructions,
            'exactly one budget, at the documented admin bound'
        );

        $this->assertSame(1, CosmwasmDiscoveryWorker::$passes, 'the worker is invoked at most once');
        $this->assertCount(1, CosmwasmDiscoveryWorker::$budgets);
        $this->assertInstanceOf(CosmwasmTickBudget::class, CosmwasmDiscoveryWorker::$budgets[0]);
        $this->assertSame(20, CosmwasmDiscoveryWorker::$budgets[0]->requests);
        $this->assertSame(8, CosmwasmDiscoveryWorker::$budgets[0]->seconds);
    }

    /** THE PR #200 BOUNDARY, measured rather than read off the source. */
    public function testTheHandlerNeverCallsTheReserveApi(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);

        $this->drive(ChainsPage::ACTION_CW_BACKFILL);

        $this->assertSame(0, CosmwasmTickBudget::$reserveCalls, 'reserve() belongs to the worker');
        $this->assertSame(0, CosmwasmTickBudget::$availableCalls, 'available() belongs to the worker');
    }

    /**
     * `runBackfillForChain()` returns VOID. On a normal return the handler
     * cannot prove the advisory lock was acquired, that a provider request
     * was made, or that progress advanced — a concurrent pass may hold the
     * lock and the worker simply logs and returns.
     *
     * So the event is `_requested`. Naming it `_ran`, `_completed` or
     * `_succeeded` would be a claim the contract does not support, and
     * this asserts the vocabulary directly.
     */
    public function testTheBackfillAuditClaimsOnlyWhatTheContractSupports(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);

        $this->drive(ChainsPage::ACTION_CW_BACKFILL);

        $this->assertSame(['admin_chain_cw_backfill_requested'], $this->audits());

        foreach (['_ran', '_completed', '_succeeded', '_finished'] as $overclaim) {
            $this->assertStringNotContainsString($overclaim, $this->audits()[0]);
        }
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: string}>
     */
    public static function backfillGates(): array
    {
        return [
            'discovery off' => [false, true, 'backfill_discovery_off'],
            'backfill off'  => [true, false, 'backfill_gate_off'],
            'both off'      => [false, false, 'backfill_discovery_off'],
        ];
    }

    /**
     * The button renders only when both gates allow it — but a `disabled`
     * attribute is a UI hint, not authorization, so a crafted POST must
     * reach the same fail-closed answer the cron path does.
     */
    #[DataProvider('backfillGates')]
    public function testBackfillIsRefusedWhenAGateIsClosed(bool $discovery, bool $backfill, string $expected): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);
        CosmwasmDiscoveryGate::$discovery = $discovery;
        CosmwasmDiscoveryGate::$backfill  = $backfill;

        $this->assertSame($expected, $this->drive(ChainsPage::ACTION_CW_BACKFILL));
        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes, 'no pass may start');
        $this->assertSame([], CosmwasmTickBudget::$constructions, 'no provider budget taken');
        $this->assertSame([], $this->audits(), 'a refused gate is not a state change');
    }

    public function testBackfillIsRefusedWhileTheChainIsPaused(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID, ChainCheckpointRepository::CW_STATE_PAUSED);

        $this->assertSame('backfill_paused', $this->drive(ChainsPage::ACTION_CW_BACKFILL));
        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes);
        $this->assertSame([], CosmwasmTickBudget::$constructions);
        $this->assertSame([], $this->audits());
    }

    /** A worker that throws is an error, never a silent success. */
    public function testAThrowingWorkerIsReportedAsAnErrorNotASlice(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);
        CosmwasmDiscoveryWorker::$throws = new \RuntimeException('lcd exploded');

        $this->assertSame('error', $this->drive(ChainsPage::ACTION_CW_BACKFILL));
        $this->assertSame(['admin_chain_cw_backfill_error'], $this->audits());
        $this->assertNotContains('admin_chain_cw_backfill_requested', $this->audits());
    }

    // ═══════════════════════════════════════════════════════════════════
    //  RETRY
    // ═══════════════════════════════════════════════════════════════════

    /**
     * TWO separate limits of 100, not one shared budget of 100 — the
     * families and the contracts are different queues.
     */
    public function testRetryRequeuesBothQueuesAtTheirOwnHundredLimit(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);
        CosmwasmCodeFamilyRepository::$retryResult = 7;
        CosmwasmContractRepository::$retryResult   = 3;

        $this->assertSame('retry_requeued', $this->drive(ChainsPage::ACTION_CW_RETRY));

        $this->assertSame(
            [['chain_id' => self::CHAIN_ID, 'limit' => 100]],
            CosmwasmCodeFamilyRepository::$retryCalls
        );
        $this->assertSame(
            [['chain_id' => self::CHAIN_ID, 'limit' => 100]],
            CosmwasmContractRepository::$retryCalls
        );
        $this->assertSame(['admin_chain_cw_retry_requeued'], $this->audits());
    }

    /** It clears a WAIT. It contacts nothing; the next pass does the work. */
    public function testRetryPerformsNoProviderOrScannerActivity(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);
        CosmwasmCodeFamilyRepository::$retryResult = 5;

        $this->drive(ChainsPage::ACTION_CW_RETRY);

        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes);
        $this->assertSame([], CosmwasmTickBudget::$constructions);
    }

    /**
     * Nothing was waiting. Truthful, and NOT a state change — so no
     * durable row. An audit log that records "retry" for a click that
     * requeued zero rows makes the log a record of intentions.
     */
    public function testRetryWithNothingPendingWritesNoDurableRow(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);
        CosmwasmCodeFamilyRepository::$retryResult = 0;
        CosmwasmContractRepository::$retryResult   = 0;

        $this->assertSame('retry_none_pending', $this->drive(ChainsPage::ACTION_CW_RETRY));
        $this->assertSame([], $this->audits());
    }

    /** One queue alone is still a real requeue. */
    public function testRetryAuditsWhenOnlyOneQueueHadWork(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);
        CosmwasmContractRepository::$retryResult = 1;

        $this->assertSame('retry_requeued', $this->drive(ChainsPage::ACTION_CW_RETRY));
        $this->assertSame(['admin_chain_cw_retry_requeued'], $this->audits());
    }

    /** Preserved from the legacy handler: retry is gated on discovery. */
    public function testRetryIsRefusedWhenDiscoveryIsOff(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);
        CosmwasmDiscoveryGate::$discovery = false;

        $this->assertSame('retry_discovery_off', $this->drive(ChainsPage::ACTION_CW_RETRY));
        $this->assertSame([], CosmwasmCodeFamilyRepository::$retryCalls);
        $this->assertSame([], CosmwasmContractRepository::$retryCalls);
        $this->assertSame([], $this->audits());
    }

    /**
     * PR #198 owns staleness requeueing through `classifier_version`. A
     * second mechanism here would make "why did this requeue?"
     * unanswerable, so the handler must not touch it.
     */
    public function testRetryDoesNotTouchTheClassifierVersion(): void
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../app/Domain/Onchain/Admin/ChainsPage.php'
        );

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringNotContainsString('classifier_version', $code);
        $this->assertStringNotContainsString('CosmwasmClassifier::VERSION', $code);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE AUDIT VOCABULARY AS A WHOLE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Every name this batch can emit, checked as a set.
     *
     * `wp_bcc_trust_activity.action` is a VARCHAR(50); a longer name is
     * silently truncated, which turns two distinct outcomes into one
     * indistinguishable row. The prefix is the shared admin vocabulary so
     * an operator can find every admin-initiated change with one query.
     *
     * @return array<string, array{0: string}>
     */
    public static function auditNames(): array
    {
        $names = [
            'admin_chain_cw_paused',
            'admin_chain_cw_pause_failed',
            'admin_chain_cw_pause_unconfirmed',
            'admin_chain_cw_pause_error',
            'admin_chain_cw_resumed',
            'admin_chain_cw_resume_failed',
            'admin_chain_cw_resume_unconfirmed',
            'admin_chain_cw_resume_error',
            'admin_chain_cw_backfill_requested',
            'admin_chain_cw_backfill_error',
            'admin_chain_cw_retry_requeued',
            'admin_chain_cw_retry_error',
        ];

        $out = [];
        foreach ($names as $n) {
            $out[$n] = [$n];
        }

        return $out;
    }

    #[DataProvider('auditNames')]
    public function testEveryAuditNameFitsTheColumnAndTheVocabulary(string $name): void
    {
        $this->assertLessThanOrEqual(50, strlen($name), 'a truncated action merges distinct outcomes');
        $this->assertStringStartsWith('admin_chain_cw_', $name);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $name);
    }

    /**
     * The audit rows target the CHAIN, with the real chain-row id. A
     * checkpoint id or a slug here would make the log unjoinable to the
     * registry — and `target_type='collection'` on a chain id is the
     * mistake this programme has already made once.
     */
    public function testTheAuditRowTargetsTheChainByItsRealId(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID);

        $this->drive(ChainsPage::ACTION_CW_PAUSE);

        $rows = \BCC\Trust\Core\Security\AuditLogger::$rows;
        $this->assertCount(1, $rows);
        $this->assertSame('chain', $rows[0]['targetType']);
        $this->assertSame(self::CHAIN_ID, (int) $rows[0]['targetId']);
    }

    /**
     * AT MOST ONE ROW PER REQUEST, for every reachable outcome. A handler
     * that audited the attempt and then the result would double-count
     * every operation in the log.
     *
     * @return array<string, array{0: string, 1: callable(): void, 2: int}>
     */
    public static function outcomes(): array
    {
        return [
            'pause ok'          => [ChainsPage::ACTION_CW_PAUSE, 'seedIdle', 1],
            'pause noop'        => [ChainsPage::ACTION_CW_PAUSE, 'seedPaused', 0],
            'pause unsupported' => [ChainsPage::ACTION_CW_PAUSE, 'seedUnsupported', 0],
            'resume ok'         => [ChainsPage::ACTION_CW_RESUME, 'seedPaused', 1],
            'resume noop'       => [ChainsPage::ACTION_CW_RESUME, 'seedIdle', 0],
            'backfill ok'       => [ChainsPage::ACTION_CW_BACKFILL, 'seedIdle', 1],
            'backfill paused'   => [ChainsPage::ACTION_CW_BACKFILL, 'seedPaused', 0],
            'retry none'        => [ChainsPage::ACTION_CW_RETRY, 'seedIdle', 0],
        ];
    }

    #[DataProvider('outcomes')]
    public function testAtMostOneDurableRowPerRequest(string $route, string $seeder, int $expected): void
    {
        $this->{$seeder}();

        $this->drive($route);

        $this->assertCount($expected, $this->audits(), 'one outcome, at most one row');
    }

    private function seedIdle(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID, ChainCheckpointRepository::CW_STATE_IDLE);
    }

    private function seedPaused(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID, ChainCheckpointRepository::CW_STATE_PAUSED);
    }

    private function seedUnsupported(): void
    {
        ChainCheckpointRepository::seed(self::CHAIN_ID, ChainCheckpointRepository::CW_STATE_UNSUPPORTED);
    }
}
