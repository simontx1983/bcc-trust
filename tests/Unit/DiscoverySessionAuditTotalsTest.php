<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\DiscoveryRunService;
use BCC\Trust\Onchain\Services\DiscoveryScanSession;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Workers\DiscoveryRunExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * What the permanent record says a session did.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 * `auditTerminal()` was handed `$counts` — the telemetry of the ONE chunk
 * that happened to be in hand when the session ended. On 2026-09-06 the
 * Cosmos Hub ledger row correctly recorded 1,136 requests / 371 families /
 * 2 collections across 25 chunks, and audit row #15 recorded **41 / 9 / 0**:
 * the last chunk, presented as the session.
 *
 * The ledger was right. The record an operator reads back was wrong, which
 * is worse than a missing record — it is a plausible one.
 *
 * ── WHY THE ASSERTIONS COMPARE AGAINST THE ROW ──────────────────────────
 * Every test below asserts `audit meta == the persisted row`, never against
 * a literal the test also computed. A hardcoded expectation would pass just
 * as happily if both sides drifted; the row is the authority precisely
 * because it is the thing the accumulation SQL wrote.
 *
 * ⚠ WHAT THIS FILE DOES NOT PROVE. The repository here is the in-memory
 * double, so it demonstrates which VALUES the executor hands the audit, not
 * that MySQL accumulates them. The accumulation and the persisted audit row
 * are proven against a real database in
 * {@see \BCC\Trust\Tests\Integration\DiscoverySessionCumulativeAuditIntegrationTest}.
 */
#[CoversClass(DiscoveryRunExecutor::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoverySessionAuditTotalsTest extends TestCase
{
    private const CHAIN    = 17;
    private const OPERATOR = 2;

    /** The totals a session had already accumulated before its last chunk. */
    private const CARRIED = [
        'requests_used'       => 900,
        'pages_fetched'       => 30,
        'families_seen'       => 300,
        'contracts_seen'      => 1000,
        'collections_emitted' => 2,
        'collections_denied'  => 1,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-cli-stubs.php';

        define('WP_CLI', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN);
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);
    }

    private function queueRun(): int
    {
        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://lcd.example', 'cosmos', 1, 1);

        $result = (new DiscoveryRunService())->request(self::CHAIN, self::OPERATOR);
        self::assertTrue($result['ok'], 'precondition: the run must be genuinely queued');

        return (int) $result['run_id'];
    }

    private function seedBacklog(int $count = 40): void
    {
        for ($codeId = 1; $codeId <= $count; $codeId++) {
            CosmwasmCodeFamilyRepository::seed(self::CHAIN, $codeId, CosmwasmClassifier::INCONCLUSIVE);
        }

        // ⚠ A PROVIDER THAT ALWAYS ANSWERS. An unanswered request makes the
        // pass record an error, and a chunk with pass-level errors ends the
        // session on `session_provider_errors` — correctly, but before the
        // ceiling under test is ever reached, so the test would measure the
        // fixture. URL-routed rather than a FIFO queue because the pass is
        // multi-stage. Same responder as DiscoverySessionExecutorTest.
        ApiRetry::$responder = static function (string $url): array {
            if (str_contains($url, '/contracts')) {
                return ['code' => 200, 'body' => '{"contracts":[],"pagination":{"next_key":null}}'];
            }
            if (str_contains($url, '/cosmwasm/wasm/v1/code')) {
                return ['code' => 200, 'body' => '{"code_infos":[],"pagination":{"next_key":null}}'];
            }

            return ['code' => 200, 'body' => '{"data":{}}'];
        };
    }

    /**
     * Give the run the totals of a session already 20 chunks deep.
     *
     * ⚠ THE CARRIED TOTALS ARE THE WHOLE TEST. If the audit reported only
     * the final chunk, every assertion here would see a number far below
     * 900 — which is exactly the shape of the live defect.
     */
    private function carryPreviousChunks(int $runId, int $chunks = 20): void
    {
        foreach (self::CARRIED as $column => $value) {
            DiscoveryRunRepository::$rows[$runId][$column] = $value;
        }

        DiscoveryRunRepository::$rows[$runId]['chunks_used'] = $chunks;
    }

    /** @return array<string, mixed> */
    private function theRun(int $runId): array
    {
        return (array) (DiscoveryRunRepository::$rows[$runId] ?? []);
    }

    /**
     * The one terminal audit entry.
     *
     * @return array<string, mixed>
     */
    private function terminalAudit(): array
    {
        $terminal = array_values(array_filter(
            AuditLogger::$entries,
            static fn(array $e): bool => in_array(
                $e['action'],
                [DiscoveryRunExecutor::AUDIT_COMPLETED, DiscoveryRunExecutor::AUDIT_FAILED],
                true
            )
        ));

        self::assertCount(1, $terminal, 'exactly one terminal audit row per session');

        return $terminal[0]['meta'];
    }

    /** Assert the audit reports the ROW, whole. */
    private function assertAuditMatchesRow(int $runId): void
    {
        $meta = $this->terminalAudit();
        $row  = $this->theRun($runId);

        foreach (['requests_used', 'pages_fetched', 'families_seen', 'contracts_seen', 'collections_emitted', 'collections_denied', 'chunks_used'] as $count) {
            self::assertSame(
                (int) ($row[$count] ?? 0),
                $meta[$count] ?? null,
                $count . ' must be the session total the ledger holds'
            );
        }

        self::assertSame((string) $row['run_uuid'], $meta['run_uuid']);
        self::assertSame((string) $row['status'], $meta['status']);
        self::assertSame(self::CHAIN, $meta['chain_id']);
    }

    // ── (3)(18) THE CHUNK CEILING ───────────────────────────────────────

    /**
     * ⚠ THE REGRESSION, at the executor. The session ends at its chunk
     * ceiling carrying 900 requests; the audit must not report the handful
     * the final chunk spent.
     */
    public function testTheChunkCeilingAuditReportsTheSessionNotTheFinalChunk(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();
        $this->carryPreviousChunks($runId, DiscoveryScanSession::MAX_CHUNKS - 1);

        $result = DiscoveryRunExecutor::execute($runId);
        self::assertSame(DiscoveryScanSession::STOP_CHUNK_CEILING, $result['reason'], 'precondition');

        $meta = $this->terminalAudit();

        self::assertGreaterThanOrEqual(
            self::CARRIED['requests_used'],
            $meta['requests_used'],
            'the audit dropped every chunk but the last'
        );
        self::assertGreaterThanOrEqual(self::CARRIED['families_seen'], $meta['families_seen']);
        self::assertSame(self::CARRIED['collections_emitted'], $meta['collections_emitted']);
        self::assertSame(DiscoveryScanSession::MAX_CHUNKS, $meta['chunks_used']);
        self::assertSame(DiscoveryScanSession::STOP_CHUNK_CEILING, $meta['stop_reason']);

        $this->assertAuditMatchesRow($runId);
    }

    /**
     * ⚠ THE FINAL CHUNK IS COUNTED EXACTLY ONCE.
     *
     * `markSucceeded()` adds it; a caller that also added it by hand would
     * double it. The row is the arbiter, and the audit repeats the row.
     */
    public function testTheFinalChunkIsCountedExactlyOnce(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();
        $this->carryPreviousChunks($runId, DiscoveryScanSession::MAX_CHUNKS - 1);

        DiscoveryRunExecutor::execute($runId);

        $row  = $this->theRun($runId);
        $meta = $this->terminalAudit();
        $spentOnTheLastChunk = (int) $row['requests_used'] - self::CARRIED['requests_used'];

        self::assertGreaterThan(0, $spentOnTheLastChunk, 'the final chunk did real work');
        self::assertSame(
            self::CARRIED['requests_used'] + $spentOnTheLastChunk,
            $meta['requests_used'],
            'the final chunk appears once, not twice'
        );
        self::assertSame(DiscoveryScanSession::MAX_CHUNKS, (int) $row['chunks_used'], 'one chunk, one increment');
    }

    // ── (19)(20)(22) EVERY OTHER TERMINAL CEILING ───────────────────────

    /**
     * @param string $prepare  which ceiling to trip
     * @param string $expected the stop reason it must record
     */
    #[DataProvider('ceilings')]
    public function testEveryCeilingAuditIsCumulative(string $prepare, string $expected): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();
        $this->carryPreviousChunks($runId);

        match ($prepare) {
            'chunks'   => DiscoveryRunRepository::$rows[$runId]['chunks_used'] = DiscoveryScanSession::MAX_CHUNKS - 1,
            'requests' => DiscoveryRunRepository::$rows[$runId]['requests_used'] = DiscoveryScanSession::MAX_REQUESTS,
            'age'      => DiscoveryRunRepository::$rows[$runId]['requested_at'] =
                gmdate('Y-m-d H:i:s', time() - DiscoveryScanSession::MAX_AGE_SECONDS - 60),
        };

        $result = DiscoveryRunExecutor::execute($runId);
        self::assertSame($expected, $result['reason'], 'precondition: the intended ceiling');

        $meta = $this->terminalAudit();

        self::assertSame($expected, $meta['stop_reason']);
        self::assertGreaterThanOrEqual(self::CARRIED['families_seen'], $meta['families_seen']);
        self::assertSame(self::CARRIED['collections_emitted'], $meta['collections_emitted']);

        $this->assertAuditMatchesRow($runId);
    }

    /** @return array<string, array{string, string}> */
    public static function ceilings(): array
    {
        return [
            'chunk ceiling'      => ['chunks', DiscoveryScanSession::STOP_CHUNK_CEILING],
            'request ceiling'    => ['requests', DiscoveryScanSession::STOP_REQUEST_CEILING],
            'wall-clock ceiling' => ['age', DiscoveryScanSession::STOP_AGE_CEILING],
        ];
    }

    /**
     * A completed pass — the session drained the queue — is cumulative too.
     */
    public function testACompletedSessionAuditIsCumulative(): void
    {
        $runId = $this->queueRun();
        $this->carryPreviousChunks($runId);

        $result = DiscoveryRunExecutor::execute($runId);
        self::assertSame('succeeded', $result['status'], 'precondition');

        $meta = $this->terminalAudit();

        // ⚠ AT LEAST the carried total, not exactly it: a pass with no
        // backlog still spends a request draining the code listing, and
        // pinning the number here would make this test about the fixture.
        self::assertGreaterThanOrEqual(self::CARRIED['requests_used'], $meta['requests_used']);
        self::assertSame(self::CARRIED['collections_emitted'], $meta['collections_emitted']);
        self::assertSame(21, $meta['chunks_used'], 'twenty carried chunks plus this one');

        $this->assertAuditMatchesRow($runId);
    }

    // ── (21) A REFUSAL DOES NOT ERASE COMMITTED WORK ────────────────────

    /**
     * ⚠ READINESS WITHDRAWN BETWEEN CHUNKS. The session may already have run
     * twenty chunks; auditing only `chain_id / error_code / attempt_count`
     * left an operator with no account of what those chunks achieved.
     */
    public function testARefusedSessionStillAuditsWhatItAchieved(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();

        // One real chunk, then let its inter-chunk delay elapse.
        self::assertSame('chunk_complete', DiscoveryRunExecutor::execute($runId)['status']);
        DiscoveryRunRepository::$rows[$runId]['next_retry_at'] = null;

        $this->carryPreviousChunks($runId);

        // The administrator withdraws product support mid-session.
        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://lcd.example', 'cosmos', 1, 0);
        AuditLogger::reset();

        DiscoveryRunExecutor::execute($runId);

        $meta = $this->terminalAudit();

        self::assertSame(self::CARRIED['requests_used'], $meta['requests_used'], 'committed work survives a refusal');
        self::assertSame(self::CARRIED['collections_emitted'], $meta['collections_emitted']);
        self::assertSame(20, $meta['chunks_used']);

        // …and the refusal's own facts are still there, and still win.
        self::assertArrayHasKey('error_code', $meta);
        self::assertArrayHasKey('attempt_count', $meta);
        self::assertSame('failed', $meta['status']);
    }

    // ── (22) A WITHDRAWAL IS A TERMINAL OUTCOME TOO ─────────────────────

    /**
     * Cancelling mid-session records what the session had already done —
     * and not one chunk more.
     *
     * ⚠ CANCEL IS ONLY OFFERED ON A QUEUED RUN, which for a session means
     * BETWEEN chunks. So there is no chunk in flight to account for, and the
     * audit must neither drop the twenty that ran nor invent a twenty-first.
     */
    public function testCancellationAuditsCommittedWorkAndInventsNoFinalChunk(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();

        self::assertSame('chunk_complete', DiscoveryRunExecutor::execute($runId)['status']);
        $this->carryPreviousChunks($runId);
        AuditLogger::reset();

        $result = (new DiscoveryRunService())->cancel($runId, self::OPERATOR);
        self::assertTrue($result['ok'], 'precondition: the withdrawal must land');

        $cancelled = array_values(array_filter(
            AuditLogger::$entries,
            static fn(array $e): bool => $e['action'] === DiscoveryRunService::AUDIT_CANCELLED
        ));
        self::assertCount(1, $cancelled);
        $meta = $cancelled[0]['meta'];

        self::assertSame(self::CARRIED['requests_used'], $meta['requests_used'], 'committed work is reported');
        self::assertSame(self::CARRIED['collections_emitted'], $meta['collections_emitted']);
        self::assertSame(20, $meta['chunks_used'], 'no twenty-first chunk is invented');

        // ⚠ THE POST-CANCEL STATE, not the pre-read one.
        self::assertSame('cancelled', $meta['status']);
        self::assertSame('queued', $meta['previous_status']);
        self::assertSame(self::OPERATOR, $meta['operator_user_id']);
    }

    // ── UNCONFIRMABLE TOTALS DEGRADE, THEY DO NOT GUESS ─────────────────

    /**
     * ⚠ NEVER FABRICATE. If the row cannot be read back after a confirmed
     * terminal write we do not know what was persisted, so no totals are
     * audited and the run is marked degraded — the existing terminal-audit
     * degradation path, not a new one.
     *
     * ⚠ AND THE PROVIDER WORK STANDS. An audit problem has never rolled back
     * a terminal result; the run stays `succeeded`.
     */
    public function testUnconfirmableTotalsDegradeTheAuditRatherThanGuessing(): void
    {
        $runId = $this->queueRun();
        $this->carryPreviousChunks($runId);

        DiscoveryRunRepository::$hideTerminalRow = true;

        $result = DiscoveryRunExecutor::execute($runId);

        self::assertSame('succeeded', $result['status'], 'the committed result stands');

        $row = $this->theRun($runId);
        self::assertSame('succeeded', (string) $row['status']);
        self::assertSame(1, (int) $row['audit_degraded'], 'the gap is recorded, not hidden');

        $terminal = array_filter(
            AuditLogger::$entries,
            static fn(array $e): bool => in_array(
                $e['action'],
                [DiscoveryRunExecutor::AUDIT_COMPLETED, DiscoveryRunExecutor::AUDIT_FAILED],
                true
            )
        );

        self::assertSame([], $terminal, 'no totals may be written that were not confirmed');
    }

    // ── (25)(27) THE RULES PR 7.4 MUST NOT BREAK ────────────────────────

    /**
     * The supervised one-pass CLI still audits cumulatively, and still does
     * not host a session.
     */
    public function testTheSupervisedOnePassPathIsUnchanged(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();
        $this->carryPreviousChunks($runId);

        $result = DiscoveryRunExecutor::execute($runId, false);

        self::assertContains($result['status'], ['succeeded', 'failed'], 'one pass, inline, never chunk_complete');
        self::assertSame(self::CARRIED['requests_used'] > 0, true);

        $meta = $this->terminalAudit();
        self::assertGreaterThanOrEqual(self::CARRIED['requests_used'], $meta['requests_used']);
        $this->assertAuditMatchesRow($runId);
    }

    /**
     * ⚠ NOTHING UNBOUNDED REACHES DURABLE STORAGE — no provider body, no URL,
     * no SQL, no exception text. The PR 5b rule, unchanged.
     */
    public function testTheAuditMetaStaysBounded(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();
        $this->carryPreviousChunks($runId, DiscoveryScanSession::MAX_CHUNKS - 1);

        DiscoveryRunExecutor::execute($runId);

        foreach ($this->terminalAudit() as $key => $value) {
            self::assertIsScalar($value, $key . ' must be scalar');

            if (is_string($value)) {
                self::assertLessThanOrEqual(255, strlen($value), $key . ' must stay bounded');
                self::assertStringNotContainsString('http', $value, 'no URL may be recorded');
                self::assertStringNotContainsString('SELECT', $value, 'no SQL may be recorded');
            }
        }
    }
}
