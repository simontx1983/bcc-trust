<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Admin\Views\DiscoveryScanPanel;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\DiscoveryScanProgress;
use BCC\Trust\Onchain\Services\DiscoveryScanSession;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * What an administrator actually reads during and after a session.
 *
 * ── WHY RENDERED, NOT ASSERTED ON A FUNCTION ────────────────────────────
 * PR 7.2 proved `actionLabel()` returned `Continue scan` and shipped it
 * unreachable, because nothing rendered the view. Every claim about wording
 * here therefore comes out of `render()`.
 */
#[CoversClass(DiscoveryScanPanel::class)]
#[Group('integration')]
final class DiscoverySessionPanelIntegrationTest extends TestCase
{
    private const CHAIN = 90804;

    private const OPERATOR = 4244;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . DiscoveryRunRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
        $wpdb->query('DELETE FROM `' . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
        $wpdb->query('DELETE FROM `' . ChainCheckpointRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    private function chain(): object
    {
        return (object) ['id' => self::CHAIN, 'slug' => 'cosmos', 'name' => 'Cosmos Hub'];
    }

    private function render(): string
    {
        ob_start();
        DiscoveryScanPanel::render($this->chain(), true, '');

        return (string) ob_get_clean();
    }

    /** Enumeration done, `$settled` of `$total` families examined. */
    private function seedFamilies(int $total, int $settled): void
    {
        $families = [];
        for ($codeId = 1; $codeId <= $total; $codeId++) {
            $families[] = ['code_id' => $codeId, 'checksum' => sprintf('%064x', $codeId)];
        }
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN, $families);

        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::recordCwCodeProgress(self::CHAIN, null, $total, true);

        for ($codeId = 1; $codeId <= $settled; $codeId++) {
            CosmwasmCodeFamilyRepository::recordClassification(
                self::CHAIN,
                $codeId,
                [
                    'classification'     => CosmwasmClassifier::NOT_CW721,
                    'reason'             => 'sampled:1',
                    'probes_ok'          => 'contract_info',
                    'probes_failed'      => '',
                    'last_error'         => '',
                    'classifier_version' => CosmwasmClassifier::VERSION,
                ],
                'cosmos1testcontractaddressforsessionpanel00000000',
                0
            );
        }
    }

    private function queueRun(): int
    {
        $created = DiscoveryRunRepository::insertQueued(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            DiscoveryScanMode::INCREMENTAL,
            self::CHAIN,
            self::OPERATOR
        );
        self::assertIsArray($created);

        return (int) $created['id'];
    }

    private function endSession(int $runId, string $stopReason): void
    {
        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::markSucceeded($runId, $token, $stopReason, false, [
            'requests_used' => 48,
            'families_seen' => 7,
        ]));
    }

    // ── (1) `Pass finished`, NEVER the bare `Finished` ──────────────────

    /**
     * With work remaining the heading says `Pass finished`.
     *
     * ⚠ THE WORDING DEFECT PR 7.3 FIXES. The standalone `Finished` was the
     * largest text on the panel and sat above a sentence saying 732 families
     * still needed review. It is derived from the SAME `scan_complete`
     * answer as that sentence, so the two cannot disagree.
     */
    public function testTheHeadingSaysPassFinishedWhileWorkRemains(): void
    {
        $this->seedFamilies(737, 5);
        $runId = $this->queueRun();
        $this->endSession($runId, 'pass_completed');

        $html = $this->render();

        self::assertStringContainsString('<strong>Pass finished</strong>', $html);
        self::assertStringNotContainsString('<strong>Finished</strong>', $html);
        self::assertStringNotContainsString('<strong>Scan complete</strong>', $html);
        self::assertStringContainsString('732 families still need review', $html);
        self::assertStringContainsString('>Continue scan</button>', $html);
    }

    /** A session that hit a ceiling says `Session finished`. */
    public function testACappedSessionSaysSessionFinished(): void
    {
        $this->seedFamilies(737, 5);
        $runId = $this->queueRun();
        $this->endSession($runId, DiscoveryScanSession::STOP_CHUNK_CEILING);

        $html = $this->render();

        self::assertStringContainsString('<strong>Session finished</strong>', $html);
        self::assertStringNotContainsString('<strong>Finished</strong>', $html);

        // …with the reason, exact counts, and the way to continue.
        self::assertStringContainsString('protected batches', $html);
        self::assertStringContainsString('5 of 737 contract families checked', $html);
        self::assertStringContainsString('>Continue scan</button>', $html);
    }

    /**
     * Only a proven-empty queue may say `Scan complete`.
     */
    public function testOnlyAnEmptyQueueSaysScanComplete(): void
    {
        $this->seedFamilies(4, 4);
        $runId = $this->queueRun();
        $this->endSession($runId, 'pass_completed');

        $progress = DiscoveryScanProgress::forChain(self::CHAIN);
        self::assertSame(DiscoveryScanProgress::YES, $progress['scan_complete'], 'precondition');

        $html = $this->render();

        self::assertStringContainsString('<strong>Scan complete</strong>', $html);
        self::assertStringNotContainsString('<strong>Pass finished</strong>', $html);
        self::assertStringNotContainsString('>Continue scan</button>', $html);
    }

    // ── (2) THE PASS-SCOPED ZERO ────────────────────────────────────────

    /**
     * "No collection found" is scoped to the PASS, never to the chain.
     */
    public function testTheZeroResultIsScopedToThePass(): void
    {
        $this->seedFamilies(737, 5);
        $runId = $this->queueRun();
        $this->endSession($runId, 'pass_completed');

        $html = $this->render();

        self::assertStringContainsString('This pass completed successfully.', $html);
        self::assertStringContainsString('It did not confirm a new NFT collection.', $html);

        // The old wording, and every stronger claim, must be gone.
        self::assertStringNotContainsString('nothing new was found', $html);
        self::assertStringNotContainsString('has no NFT', $html);
        self::assertStringNotContainsString('Nothing remains', $html);
    }

    // ── (3) A LIVE SESSION ──────────────────────────────────────────────

    /**
     * Between chunks the panel says it is running in batches, shows the
     * batch number, and offers Stop.
     */
    public function testALiveSessionExplainsItselfAndOffersStop(): void
    {
        $this->seedFamilies(737, 5);
        $runId = $this->queueRun();

        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::releaseForNextChunk($runId, $token, ['requests_used' => 48], 60));

        $html = $this->render();

        self::assertStringContainsString('<strong>Scanning in batches</strong>', $html);
        self::assertStringContainsString('An administrator started this scan.', $html);
        self::assertStringContainsString('small protected batches', $html);
        self::assertStringContainsString('Batch 1 of up to', $html);

        // ⚠ CANCELLATION MUST BE REACHABLE. A session an operator cannot stop
        // is a session they have to wait out.
        self::assertStringContainsString('>Stop this scan</button>', $html);
        self::assertStringContainsString('bcc_discovery_scan_cancel', $html);

        // …and the stale "it has not started yet" wording must be gone.
        self::assertStringNotContainsString('It has not started yet', $html);
        self::assertStringNotContainsString('>Continue scan</button>', $html);
    }

    /** No completion time is ever promised. */
    public function testNoCompletionTimeIsPromised(): void
    {
        $this->seedFamilies(737, 5);
        $runId = $this->queueRun();
        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::releaseForNextChunk($runId, $token, ['requests_used' => 48], 60));

        $html = strtolower($this->render());

        foreach (['minutes remaining', 'estimated', 'will finish', 'time left', 'eta'] as $promise) {
            self::assertStringNotContainsString($promise, $html, 'no completion-time promise');
        }
    }

    // ── (4) DELAYED AND EXHAUSTED ARE NAMED, NOT HIDDEN ─────────────────

    /**
     * Retry-exhausted families are reported as UNRESOLVED, never as a
     * negative verdict.
     *
     * ⚠ The schema stores "we could not reach it" and "this is not an NFT"
     * in the same column. If the panel does not separate them, nothing does.
     */
    public function testExhaustedFamiliesAreReportedAsUnresolvedNotNegative(): void
    {
        $this->seedFamilies(20, 2);

        // One family that gave up after MAX_RETRIES, still non-terminal.
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . CosmwasmCodeFamilyRepository::table() . '`
                SET classification = %s, retry_count = %d
              WHERE chain_id = %d AND code_id = %d',
            CosmwasmClassifier::UNREACHABLE,
            CosmwasmClassifier::MAX_RETRIES,
            self::CHAIN,
            19
        ));

        $progress = DiscoveryScanProgress::forChain(self::CHAIN);
        self::assertSame(1, $progress['exhausted_families'], 'precondition');

        $html = $this->render();

        self::assertStringContainsString('could not be resolved after repeated attempts', $html);
        self::assertStringContainsString('unresolved, not a negative result', $html);
    }

    /** Delayed work is named as waiting, and never as complete. */
    public function testDelayedFamiliesAreReportedAsWaiting(): void
    {
        $this->seedFamilies(20, 2);

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . CosmwasmCodeFamilyRepository::table() . '`
                SET next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 6 HOUR)
              WHERE chain_id = %d AND code_id > %d',
            self::CHAIN,
            2
        ));

        $progress = DiscoveryScanProgress::forChain(self::CHAIN);
        self::assertGreaterThan(0, $progress['delayed_families'], 'precondition');
        self::assertSame(0, $progress['eligible_now'], 'nothing is claimable now');
        self::assertSame(DiscoveryScanProgress::NO, $progress['scan_complete'], 'delayed is NOT complete');

        $html = $this->render();

        self::assertStringContainsString('waiting to be retried later', $html);
        self::assertStringNotContainsString('<strong>Scan complete</strong>', $html);
    }

    // ── (5) NOTHING LEAKS, NOTHING IS WRITTEN ───────────────────────────

    /** Rendering a session panel writes nothing. */
    public function testRenderingASessionPanelWritesNothing(): void
    {
        $this->seedFamilies(737, 5);
        $runId = $this->queueRun();
        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::releaseForNextChunk($runId, $token, ['requests_used' => 48], 60));

        $wpdb   = $GLOBALS['wpdb'];
        $before = (string) $wpdb->get_var(
            'SELECT MD5(GROUP_CONCAT(id, status, chunks_used, requests_used)) FROM `'
            . DiscoveryRunRepository::table() . '` WHERE chain_id = ' . self::CHAIN
        );

        // ⚠ SCHEDULING IS A WRITE TOO, and the row hash cannot see it. A
        // mutation that scheduled a continuation from inside the view
        // survived until this was asserted: the ledger was untouched, so
        // "writes nothing" looked true while every page load queued another
        // chunk. Polling an admin screen must never advance a scan.
        $GLOBALS['bcc_scheduled'] = [];

        $this->render();
        $this->render();
        $this->render();

        $after = (string) $wpdb->get_var(
            'SELECT MD5(GROUP_CONCAT(id, status, chunks_used, requests_used)) FROM `'
            . DiscoveryRunRepository::table() . '` WHERE chain_id = ' . self::CHAIN
        );

        self::assertSame($before, $after, 'rendering must not advance a session');
        self::assertSame(
            [],
            $GLOBALS['bcc_scheduled'],
            'rendering must not schedule work — three page loads, zero events'
        );
    }

    /** No SQL, no credentials, no session internals. */
    public function testTheSessionPanelLeaksNothing(): void
    {
        $this->seedFamilies(737, 5);
        $runId = $this->queueRun();
        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::releaseForNextChunk($runId, $token, ['requests_used' => 48], 60));

        $html = $this->render();

        foreach (['SELECT ', ' FROM `', 'WHERE ', 'INSERT INTO', 'UPDATE '] as $sql) {
            self::assertStringNotContainsString($sql, $html, $sql . ' must never reach the page');
        }
        foreach (['DB_PASSWORD', 'AUTH_KEY', 'Exception', 'Stack trace'] as $secret) {
            self::assertStringNotContainsStringIgnoringCase($secret, $html);
        }

        // ⚠ THE LEASE IS A CAPABILITY, not a fact about the run.
        self::assertStringNotContainsString('lease_token', $html);
        self::assertStringNotContainsString((string) $token, $html, 'the lease token must never render');
    }
}
