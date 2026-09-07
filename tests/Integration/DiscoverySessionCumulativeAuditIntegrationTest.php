<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Services\DiscoveryScanSession;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;
use BCC\Trust\Onchain\ValueObjects\DiscoverySessionTotals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The 2026-09-06 Cosmos Hub session, replayed against a real database.
 *
 * ── WHY REAL MySQL ──────────────────────────────────────────────────────
 * The accumulation is not PHP arithmetic. `releaseForNextChunk()` and
 * `markSucceeded()` both write `col = col + %d`, so twenty-five chunks add up
 * inside the database and the row is the only place the session total ever
 * exists. A double asked "did you accumulate?" answers whatever it was told —
 * which is exactly how a caller could pass one chunk's counts for months
 * without a red test.
 *
 * The audit half is real too: the meta goes through `AuditLogger::logChecked`
 * into the live activity table and is read back and decoded, so the encoding
 * and redaction rules are exercised rather than assumed.
 *
 * ── WHAT THIS FILE DOES NOT PROVE ───────────────────────────────────────
 * ⚠ It does not drive `DiscoveryRunExecutor::execute()` — that needs a
 * provider, and this suite contacts nothing. Which VALUES the executor hands
 * the audit is proven in
 * {@see \BCC\Trust\Onchain\Tests\Unit\DiscoverySessionAuditTotalsTest}
 * against the in-memory ledger. Here: the arithmetic, and the round trip.
 */
#[CoversClass(DiscoverySessionTotals::class)]
#[Group('integration')]
final class DiscoverySessionCumulativeAuditIntegrationTest extends TestCase
{
    private const CHAIN = 90806;

    private const OPERATOR = 4246;

    /** The live session's cumulative ledger totals. */
    private const SESSION_REQUESTS = 1136;
    private const SESSION_FAMILIES = 371;
    private const SESSION_EMITTED  = 2;
    private const SESSION_CHUNKS   = 25;

    /** …and its FINAL chunk, which audit row #15 reported as the session. */
    private const LAST_CHUNK_REQUESTS = 41;
    private const LAST_CHUNK_FAMILIES = 9;
    private const LAST_CHUNK_EMITTED  = 0;

    private static string $activity = '';

    public static function setUpBeforeClass(): void
    {
        self::$activity = TableRegistry::activity();
    }

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . DiscoveryRunRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
        $wpdb->query("DELETE FROM `" . self::$activity . "` WHERE action LIKE 'itest\\_%'");
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    private function queue(): int
    {
        $created = DiscoveryRunRepository::insertQueued(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            DiscoveryScanMode::INCREMENTAL,
            self::CHAIN,
            self::OPERATOR
        );
        self::assertIsArray($created, 'fixture could not queue a run');

        return (int) $created['id'];
    }

    private function row(int $runId): object
    {
        $row = DiscoveryRunRepository::findById($runId);
        self::assertIsObject($row);

        return $row;
    }

    /**
     * Let the inter-chunk delay elapse without sleeping 15 s per chunk.
     *
     * The gate itself is asserted in DiscoverySessionLedgerIntegrationTest;
     * winding the row's clock back leaves every other guard exactly as
     * production has it.
     */
    private function elapse(int $runId): void
    {
        $GLOBALS['wpdb']->query(
            'UPDATE `' . DiscoveryRunRepository::table() . '`
                SET next_retry_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND)
              WHERE id = ' . $runId . ' AND next_retry_at IS NOT NULL'
        );
    }

    /**
     * The 24 chunks that ran before the last one.
     *
     * ⚠ THE FIXTURE IS SELF-CHECKING. The per-chunk numbers are derived from
     * the live totals minus the live final chunk, and
     * {@see testTheFixtureAddsUpToTheLiveSession} asserts the derivation —
     * so a wrong constant fails as a fixture error rather than silently
     * becoming the expectation.
     *
     * @return list<array<string, int>>
     */
    private static function earlierChunks(): array
    {
        $chunks    = self::SESSION_CHUNKS - 1;
        $requests  = self::SESSION_REQUESTS - self::LAST_CHUNK_REQUESTS;
        $families  = self::SESSION_FAMILIES - self::LAST_CHUNK_FAMILIES;
        $emitted   = self::SESSION_EMITTED - self::LAST_CHUNK_EMITTED;

        $out = [];
        for ($i = 0; $i < $chunks; $i++) {
            $out[] = [
                // The first chunk carries the remainder, so the totals land
                // exactly on the live numbers rather than near them.
                'requests_used'       => intdiv($requests, $chunks) + ($i === 0 ? $requests % $chunks : 0),
                'pages_fetched'       => $i === 0 ? 20 : 1,
                'families_seen'       => intdiv($families, $chunks) + ($i === 0 ? $families % $chunks : 0),
                'contracts_seen'      => 50,
                'collections_emitted' => $i === 0 ? $emitted : 0,
                'collections_denied'  => 0,
            ];
        }

        return $out;
    }

    /** @return array<string, int> */
    private static function finalChunk(): array
    {
        return [
            'requests_used'       => self::LAST_CHUNK_REQUESTS,
            'pages_fetched'       => 1,
            'families_seen'       => self::LAST_CHUNK_FAMILIES,
            'contracts_seen'      => 50,
            'collections_emitted' => self::LAST_CHUNK_EMITTED,
            'collections_denied'  => 0,
        ];
    }

    /** Run the 24 released chunks, leaving the run queued and mid-session. */
    private function runEarlierChunks(int $runId): void
    {
        foreach (self::earlierChunks() as $i => $counts) {
            $token = DiscoveryRunRepository::claim($runId);
            self::assertIsString($token, 'chunk ' . ($i + 1) . ' could not claim');
            self::assertTrue(
                DiscoveryRunRepository::releaseForNextChunk($runId, $token, $counts, 1),
                'chunk ' . ($i + 1) . ' could not be released'
            );
            $this->elapse($runId);
        }
    }

    // ── (1)(2)(4)(5)(6) THE LIVE ACCUMULATION ───────────────────────────

    /** The fixture reproduces the live session exactly, or it is not a fixture. */
    public function testTheFixtureAddsUpToTheLiveSession(): void
    {
        $earlier = self::earlierChunks();

        self::assertCount(self::SESSION_CHUNKS - 1, $earlier);
        self::assertSame(
            self::SESSION_REQUESTS - self::LAST_CHUNK_REQUESTS,
            array_sum(array_column($earlier, 'requests_used'))
        );
        self::assertSame(
            self::SESSION_FAMILIES - self::LAST_CHUNK_FAMILIES,
            array_sum(array_column($earlier, 'families_seen'))
        );
        self::assertSame(
            self::SESSION_EMITTED - self::LAST_CHUNK_EMITTED,
            array_sum(array_column($earlier, 'collections_emitted'))
        );
    }

    /**
     * ⚠ TWENTY-FIVE CHUNKS, ONE ROW, THE LIVE TOTALS.
     *
     * Every number here is the database's own arithmetic across 25 separate
     * UPDATE statements — never a value PHP computed and wrote.
     */
    public function testTwentyFiveChunksAccumulateToTheLiveSessionTotals(): void
    {
        $runId = $this->queue();

        $this->runEarlierChunks($runId);

        $beforeLast = $this->row($runId);
        self::assertSame(self::SESSION_CHUNKS - 1, (int) $beforeLast->chunks_used, '24 chunks so far');
        self::assertSame(
            self::SESSION_REQUESTS - self::LAST_CHUNK_REQUESTS,
            (int) $beforeLast->requests_used,
            'the previous chunks are in the row before the last one runs'
        );

        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::markSucceeded(
            $runId,
            $token,
            DiscoveryScanSession::STOP_CHUNK_CEILING,
            true,
            self::finalChunk()
        ));

        $row = $this->row($runId);

        self::assertSame(self::SESSION_CHUNKS, (int) $row->chunks_used, 'the terminal chunk counts too');
        self::assertSame(self::SESSION_REQUESTS, (int) $row->requests_used);
        self::assertSame(self::SESSION_FAMILIES, (int) $row->families_seen);
        self::assertSame(self::SESSION_EMITTED, (int) $row->collections_emitted);
        self::assertSame(DiscoveryScanSession::STOP_CHUNK_CEILING, (string) $row->stop_reason);
        self::assertSame(1, (int) $row->partial, 'a capped session is partial');

        // …and one run row, throughout.
        self::assertSame(
            1,
            (int) $GLOBALS['wpdb']->get_var(
                'SELECT COUNT(*) FROM `' . DiscoveryRunRepository::table() . '` WHERE chain_id = ' . self::CHAIN
            )
        );
    }

    // ── (3) THE AUDIT, PERSISTED AND READ BACK ──────────────────────────

    /**
     * ⚠ THE DEFECT, END TO END. Audit row #15 recorded 41 / 9 / 0 for a
     * session that spent 1,136 requests, saw 371 families and stored 2
     * collections. The meta written from the persisted row must carry the
     * session's numbers and must not carry the final chunk's.
     */
    public function testTheTerminalAuditMetaIsCumulativeOncePersisted(): void
    {
        $runId = $this->completeTheLiveSession();

        $totals = DiscoverySessionTotals::fromPersistedRow(DiscoveryRunRepository::findById($runId));
        self::assertNotNull($totals, 'the terminal row must be readable');

        $auditId = AuditLogger::logChecked('itest_discovery_run_completed', $runId, $totals->toAuditMeta(), 'discovery_run', null);
        self::assertIsInt($auditId, 'the audit row must be written');

        $meta = $this->readMeta($auditId);

        self::assertSame(self::SESSION_REQUESTS, $meta['requests_used']);
        self::assertSame(self::SESSION_FAMILIES, $meta['families_seen']);
        self::assertSame(self::SESSION_EMITTED, $meta['collections_emitted']);
        self::assertSame(self::SESSION_CHUNKS, $meta['chunks_used']);

        // ⚠ AND EXPLICITLY NOT THE LAST CHUNK. Named separately because
        // "equals 1136" would still pass if 41 happened to equal it.
        self::assertNotSame(self::LAST_CHUNK_REQUESTS, $meta['requests_used']);
        self::assertNotSame(self::LAST_CHUNK_FAMILIES, $meta['families_seen']);

        self::assertSame(DiscoveryScanSession::STOP_CHUNK_CEILING, $meta['stop_reason']);
        self::assertSame('succeeded', $meta['status']);
        self::assertSame(self::CHAIN, $meta['chain_id']);
        self::assertSame(1, $meta['partial']);
        self::assertSame((string) $this->row($runId)->run_uuid, $meta['run_uuid']);
    }

    /**
     * (27) The encoding and redaction rules are unchanged: every value is a
     * scalar, and it survives the round trip through the activity table with
     * its type intact.
     */
    public function testTheAuditMetaSurvivesEncodingUnchanged(): void
    {
        $runId  = $this->completeTheLiveSession();
        $totals = DiscoverySessionTotals::fromPersistedRow(DiscoveryRunRepository::findById($runId));
        self::assertNotNull($totals);

        $written = $totals->toAuditMeta();
        $auditId = AuditLogger::logChecked('itest_discovery_run_completed', $runId, $written, 'discovery_run', null);
        self::assertIsInt($auditId);

        self::assertSame($written, $this->readMeta($auditId), 'the stored meta is what was handed over');

        foreach ($written as $key => $value) {
            self::assertIsScalar($value, $key . ' must be scalar');
            if (is_string($value)) {
                self::assertLessThanOrEqual(255, strlen($value), $key . ' must stay bounded');
            }
        }
    }

    // ── (23)(24) THE FINAL CHUNK, EXACTLY ONCE ──────────────────────────

    /**
     * ⚠ AT-LEAST-ONCE DELIVERY IS NOT HYPOTHETICAL. Action Scheduler can
     * deliver the same action twice, and the live session saw 26 deliveries
     * for 25 chunks. A second terminal write must change nothing.
     */
    public function testADuplicateTerminalDeliveryCannotDoubleAddTheTotals(): void
    {
        $runId = $this->completeTheLiveSession();

        $before = $this->row($runId);

        // The duplicate arrives: it cannot claim a terminal run…
        self::assertNull(DiscoveryRunRepository::claim($runId), 'a settled run cannot be claimed again');

        // …and even a replayed terminal write with a stale lease no-ops.
        self::assertFalse(DiscoveryRunRepository::markSucceeded(
            $runId,
            'stale-lease-token',
            DiscoveryScanSession::STOP_CHUNK_CEILING,
            true,
            self::finalChunk()
        ));

        $after = $this->row($runId);

        self::assertSame((int) $before->requests_used, (int) $after->requests_used, 'totals must not double');
        self::assertSame(self::SESSION_REQUESTS, (int) $after->requests_used);
        self::assertSame(self::SESSION_CHUNKS, (int) $after->chunks_used);
        self::assertSame(self::SESSION_EMITTED, (int) $after->collections_emitted);
    }

    // ── (21) A FAILURE STILL REPORTS WHAT WAS COMMITTED ─────────────────

    /**
     * A session refused after twenty-four chunks keeps their totals: the
     * provider work committed, and an audit that dropped it would understate
     * what the operator's click actually cost.
     */
    public function testAFailedTerminalOutcomeStillCarriesTheCommittedTotals(): void
    {
        $runId = $this->queue();
        $this->runEarlierChunks($runId);

        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::markFailed($runId, $token, DiscoveryRunError::EXECUTION_FAILED));

        $totals = DiscoverySessionTotals::fromPersistedRow(DiscoveryRunRepository::findById($runId));
        self::assertNotNull($totals);

        $meta = $totals->toAuditMeta();

        self::assertSame(self::SESSION_REQUESTS - self::LAST_CHUNK_REQUESTS, $meta['requests_used']);
        self::assertSame(self::SESSION_CHUNKS - 1, $meta['chunks_used'], 'a failed chunk adds no chunk');
        self::assertSame(self::SESSION_EMITTED, $meta['collections_emitted'], 'emitted collections are already stored');
        self::assertSame('failed', $meta['status']);
    }

    // ── (26) SINGLE-PASS HISTORY IS UNAFFECTED ──────────────────────────

    /**
     * ⚠ THE NO-REGRESSION CASE. Runs 1 and 2 on staging are single-pass rows
     * with `chunks_used = 0`; the totals for such a run are simply its own
     * counts, because every counter starts at zero.
     */
    public function testASinglePassRunReportsExactlyItsOwnCounts(): void
    {
        $runId = $this->queue();

        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::markSucceeded($runId, $token, 'pass_completed', false, [
            'requests_used'       => 48,
            'families_seen'       => 5,
            'collections_emitted' => 0,
        ]));

        $meta = DiscoverySessionTotals::fromPersistedRow(DiscoveryRunRepository::findById($runId))?->toAuditMeta();

        self::assertIsArray($meta);
        self::assertSame(48, $meta['requests_used']);
        self::assertSame(5, $meta['families_seen']);
        self::assertSame(0, $meta['collections_emitted']);
        self::assertSame(1, $meta['chunks_used'], 'one pass is one chunk');
        self::assertSame('pass_completed', $meta['stop_reason']);
        self::assertSame(0, $meta['partial']);
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** Drive the full 25-chunk session and return its run id. */
    private function completeTheLiveSession(): int
    {
        $runId = $this->queue();
        $this->runEarlierChunks($runId);

        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::markSucceeded(
            $runId,
            $token,
            DiscoveryScanSession::STOP_CHUNK_CEILING,
            true,
            self::finalChunk()
        ));

        return $runId;
    }

    /** @return array<string, mixed> */
    private function readMeta(int $auditId): array
    {
        $raw = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
            'SELECT meta FROM `' . self::$activity . '` WHERE id = %d',
            $auditId
        ));

        self::assertIsString($raw, 'the meta column must hold the encoded payload');

        // ⚠ THE COLUMN HOLDS JSON, and there is deliberately no decoder in
        // AuditMeta — the encoder's job is one-way, into durable storage.
        // Reading it back as JSON here is what an operator's tooling does.
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'the stored meta must be well-formed JSON');
        self::assertArrayNotHasKey('_truncated', $decoded, 'session totals must fit the encoded-size limit');

        return $decoded;
    }
}
