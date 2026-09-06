<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Admin\Views\DiscoveryScanPanel;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\DiscoveryScanProgress;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The panel's CONTROL, rendered — the coverage PR 7.2 did not have.
 *
 * ── WHY THIS TEST EXISTS ────────────────────────────────────────────────
 * PR 7.2 gave `DiscoveryScanProgress::actionLabel()` the word `Continue
 * scan` and proved it as a pure function. It never reached a screen.
 * `renderControl()` decided liveness as `$current !== null`, and
 * `DiscoveryRunStatusReader` deliberately falls back to the MOST RECENT run
 * when none is active — so once a chain had ever been scanned, the panel
 * took the "already running" branch forever, disabled the button, and
 * returned BEFORE the label was ever computed.
 *
 * On staging that rendered, beside a run that had finished days earlier:
 *
 *     [Scan On-Chain for Easy Discovery]  A scan is already running for
 *                                         this chain.
 *
 * Both halves false. The progress sentence one line above it was correct
 * the whole time, which is what made it convincing.
 *
 * ⚠ NO UNIT TEST COULD HAVE CAUGHT THIS. The label function was right; the
 * counts were right; the sentence was right. Only rendering the view and
 * reading its output shows that the correct label never appears in it. That
 * is the lesson worth keeping: a pure function verified in isolation is not
 * evidence that anything renders it.
 *
 * ⚠ AND THE FIX IS REUSE, NOT NEW LOGIC. `DiscoveryRunStatus::isTerminal()`
 * already existed and is what `DiscoveryRunService` and the reader's own
 * `retry_allowed` decide against. The bug was a second, weaker definition
 * of "active" living in a view.
 */
#[CoversClass(DiscoveryScanPanel::class)]
#[Group('integration')]
final class DiscoveryScanPanelControlIntegrationTest extends TestCase
{
    /** A chain id no fixture or shipped registry row uses. */
    private const CHAIN = 90802;

    private const OPERATOR = 4242;

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

    /** A chain row shaped as the page passes it in. */
    private function chain(): object
    {
        return (object) ['id' => self::CHAIN, 'slug' => 'cosmos', 'name' => 'Cosmos Hub'];
    }

    /**
     * The canary's family state: enumeration drained, 5 of 737 settled.
     *
     * Written through the real writers so `classified_at` and
     * `classifier_version` come from the repository rather than this test.
     */
    private function seedCanaryFamilies(): void
    {
        $families = [];
        for ($codeId = 1; $codeId <= 737; $codeId++) {
            $families[] = ['code_id' => $codeId, 'checksum' => sprintf('%064x', $codeId)];
        }
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN, $families);

        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::recordCwCodeProgress(self::CHAIN, null, 737, true);

        foreach ([
            1 => CosmwasmClassifier::NOT_CW721,
            2 => CosmwasmClassifier::NOT_CW721,
            3 => CosmwasmClassifier::UNREACHABLE,
            4 => CosmwasmClassifier::UNREACHABLE,
            5 => CosmwasmClassifier::INCONCLUSIVE,
        ] as $codeId => $verdict) {
            CosmwasmCodeFamilyRepository::recordClassification(
                self::CHAIN,
                $codeId,
                [
                    'classification'     => $verdict,
                    'reason'             => 'sampled:1',
                    'probes_ok'          => 'contract_info',
                    'probes_failed'      => '',
                    'last_error'         => '',
                    'classifier_version' => CosmwasmClassifier::VERSION,
                ],
                'cosmos1testcontractaddressforpanelfixture00000000',
                0
            );
        }
    }

    /** Queue a run and return its id. */
    private function queueRun(): int
    {
        $created = DiscoveryRunRepository::insertQueued(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            DiscoveryScanMode::HISTORICAL,
            self::CHAIN,
            self::OPERATOR
        );

        self::assertIsArray($created, 'fixture could not queue a run');

        return (int) $created['id'];
    }

    /** Drive a run all the way to `succeeded`, through the real writers. */
    private function succeedRun(): void
    {
        $runId = $this->queueRun();
        $lease = DiscoveryRunRepository::claim($runId);
        self::assertIsString($lease, 'fixture could not claim the run');

        self::assertTrue(DiscoveryRunRepository::markSucceeded(
            $runId,
            $lease,
            'pass_completed',
            false,
            ['requests_used' => 48, 'families_seen' => 7, 'contracts_seen' => 25, 'collections_emitted' => 0]
        ));
    }

    /** Render the panel as an eligible chain and return its HTML. */
    private function render(bool $scannable = true, string $whyNot = ''): string
    {
        ob_start();
        DiscoveryScanPanel::render($this->chain(), $scannable, $whyNot);

        return (string) ob_get_clean();
    }

    // ── (1) THE DEFECT THIS FIX EXISTS FOR ──────────────────────────────

    /**
     * A finished pass offers `Continue scan` — enabled, with no claim that
     * anything is running.
     */
    public function testAFinishedRunOffersAnEnabledContinueScan(): void
    {
        $this->seedCanaryFamilies();
        $this->succeedRun();

        $html = $this->render();

        // ⚠ THE ASSERTION THE FIX EXISTS FOR — and it matches the BUTTON'S
        // OWN TEXT, not a bare substring. A first draft asserted only
        // `'Continue scan'`, which the `aria-label` one line above satisfies
        // on its own: the visible label could have reverted to the fixed
        // string and the test would still have passed. A mutation control
        // that swapped exactly that survived and said so.
        self::assertStringContainsString(
            '>Continue scan</button>',
            $html,
            'a terminal run must offer to continue — the label PR 7.2 added was unreachable'
        );
        self::assertStringContainsString('aria-label="Continue scan — Cosmos Hub"', $html);
        self::assertStringNotContainsString(
            'A scan is already running for this chain.',
            $html,
            'the run finished; saying it is running is false'
        );

        // ⚠ AND THE PASS OUTCOME IS STILL THERE. Completeness is the OTHER
        // half of the sentence, never a replacement for it — the reader
        // falls back to the most recent run precisely so a finished pass
        // does not vanish from the screen, and this asserts that context
        // survives beside the new progress line.
        // ⚠ `Pass finished`, NOT `Finished` (PR 7.3). The standalone word was
        // the largest text on the panel and read as a statement about the
        // CHAIN while 732 families were still queued. The heading is now
        // derived from the SAME `scan_complete` answer as the sentence below
        // it, so the two cannot disagree.
        self::assertStringContainsString('<strong>Pass finished</strong>', $html);
        self::assertStringNotContainsString('<strong>Finished</strong>', $html);
        self::assertStringContainsString('Last successful scan:', $html);

        // Enabled, and a real request form rather than a dead button.
        self::assertStringContainsString('type="submit"', $html);
        self::assertStringContainsString('bcc_discovery_scan_request', $html);
        self::assertStringContainsString('name="_wpnonce"', $html);
        self::assertStringNotContainsString('disabled aria-disabled="true"', $html);

        // And the hint that says what pressing it does.
        self::assertStringContainsString('resumes where the last pass stopped', $html);
    }

    /** The progress sentence and the control now agree. */
    public function testTheProgressSentenceAndTheControlAgree(): void
    {
        $this->seedCanaryFamilies();
        $this->succeedRun();

        $html = $this->render();

        self::assertStringContainsString('Checked 5 of 737 contract families', $html);
        self::assertStringContainsString('732 families still need review', $html);
        self::assertStringContainsString('>Continue scan</button>', $html);

        // The four sentences that must never appear over this state.
        self::assertStringNotContainsString('Scan complete', $html);
        self::assertStringNotContainsString('Start over', $html);
        self::assertStringNotContainsString('Nothing remains', $html);
        self::assertStringNotContainsString('All 737', $html);
    }

    // ── (2) THE CASES THE OLD BRANCH WAS ACTUALLY FOR ───────────────────

    /**
     * A RUNNING run still disables the control and says so.
     *
     * ⚠ This is the half the old code got right, and the fix must not trade
     * one wrong answer for the opposite one.
     */
    public function testARunningRunStillDisablesTheControl(): void
    {
        $this->seedCanaryFamilies();
        $runId = $this->queueRun();
        self::assertIsString(DiscoveryRunRepository::claim($runId));

        $html = $this->render();

        self::assertStringContainsString('A scan is already running for this chain.', $html);
        self::assertStringContainsString('disabled aria-disabled="true"', $html);
        self::assertStringNotContainsString('Continue scan', $html);
        self::assertStringNotContainsString('type="submit"', $html);
    }

    /** A QUEUED run is also live, and additionally offers Withdraw. */
    public function testAQueuedRunIsLiveAndOffersWithdraw(): void
    {
        $this->seedCanaryFamilies();
        $this->queueRun();

        $html = $this->render();

        self::assertStringContainsString('A scan is already running for this chain.', $html);
        self::assertStringContainsString('Withdraw this scan', $html);
        self::assertStringNotContainsString('Continue scan', $html);
    }

    // ── (3) EVERY TERMINAL STATE FREES THE CONTROL ──────────────────────

    /**
     * A FAILED run frees the control.
     *
     * ⚠ A failure that left the button disabled under "already running"
     * would strand the operator with no way to retry at all.
     */
    public function testAFailedRunFreesTheControl(): void
    {
        $this->seedCanaryFamilies();
        $runId = $this->queueRun();
        $lease = DiscoveryRunRepository::claim($runId);
        self::assertIsString($lease);
        // ⚠ A REAL error code. `markFailed()` refuses anything outside
        // `DiscoveryRunError`, so an invented string returns false and
        // leaves the run RUNNING — the fixture would then have been
        // asserting against a live run while claiming to test a failed one.
        self::assertTrue(DiscoveryRunRepository::markFailed(
            $runId,
            $lease,
            DiscoveryRunError::EXECUTION_FAILED
        ));

        $html = $this->render();

        self::assertStringNotContainsString('A scan is already running for this chain.', $html);
        self::assertStringContainsString('Continue scan', $html);
    }

    /**
     * A CANCELLED run frees the control too.
     *
     * ⚠ This is exactly why `retry_allowed` was NOT reused as the liveness
     * predicate: the reader computes it as `isTerminal() && !== CANCELLED`,
     * so a withdrawn run would have kept the button disabled forever.
     */
    public function testACancelledRunFreesTheControl(): void
    {
        $this->seedCanaryFamilies();
        $runId = $this->queueRun();
        self::assertTrue(DiscoveryRunRepository::markCancelled($runId));

        $html = $this->render();

        self::assertStringNotContainsString('A scan is already running for this chain.', $html);
        self::assertStringContainsString('Continue scan', $html);
        self::assertStringNotContainsString('Withdraw this scan', $html);
    }

    /**
     * A status this build does not recognise reads as LIVE, not terminal.
     *
     * ⚠ FAIL-CLOSED, and the reason `isTerminal()` is written as membership
     * in the terminal list rather than "not queued and not running". A token
     * from a newer build that read as terminal would let this panel offer a
     * second run beside a possibly-live one. `status` is VARCHAR(16), so the
     * row is written directly — no writer in this build can produce it, and
     * that is exactly the point.
     */
    public function testAnUnrecognisedStatusIsTreatedAsLive(): void
    {
        $this->seedCanaryFamilies();
        $runId = $this->queueRun();

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . DiscoveryRunRepository::table() . '` SET status = %s WHERE id = %d',
            'future_state',
            $runId
        ));
        self::assertSame(
            'future_state',
            (string) $wpdb->get_var('SELECT status FROM `' . DiscoveryRunRepository::table() . '` WHERE id = ' . $runId),
            'fixture failed to plant the unrecognised status'
        );

        $html = $this->render();

        self::assertStringContainsString('disabled aria-disabled="true"', $html);
        self::assertStringNotContainsString('Continue scan', $html);
        self::assertStringNotContainsString('type="submit"', $html);
    }

    // ── (4) THE FIRST-EVER SCAN, AND THE FINAL ZERO ─────────────────────

    /** With no run at all the control is offered — and does not say Continue. */
    public function testNoRunAtAllStillOffersTheControl(): void
    {
        $this->seedCanaryFamilies();

        $html = $this->render();

        self::assertStringContainsString('type="submit"', $html);
        self::assertStringNotContainsString('A scan is already running for this chain.', $html);
    }

    /**
     * A genuinely finished chain says so, and still offers no false work.
     *
     * ⚠ The completion sentence is reachable ONLY when the queue is proven
     * empty, so this is the one state where `Continue scan` must NOT appear.
     */
    public function testAGenuinelyCompleteScanDoesNotOfferToContinue(): void
    {
        $families = [];
        for ($codeId = 1; $codeId <= 3; $codeId++) {
            $families[] = ['code_id' => $codeId, 'checksum' => sprintf('%064x', $codeId)];
        }
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN, $families);
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::recordCwCodeProgress(self::CHAIN, null, 3, true);

        foreach ([1, 2, 3] as $codeId) {
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
                'cosmos1testcontractaddressforpanelfixture00000000',
                0
            );
        }
        $this->succeedRun();

        $progress = DiscoveryScanProgress::forChain(self::CHAIN);
        self::assertSame(DiscoveryScanProgress::YES, $progress['scan_complete']);
        self::assertSame(0, $progress['remaining_families']);

        $html = $this->render();

        self::assertStringNotContainsString('Continue scan', $html);
        self::assertStringNotContainsString('still need review', $html);
    }

    // ── (5) AN INELIGIBLE CHAIN IS STILL REFUSED ────────────────────────

    /**
     * `$scannable = false` still wins, whatever the run history says.
     *
     * ⚠ The eligibility refusal must be checked BEFORE liveness — otherwise
     * a chain BCC does not support would get an actionable button as soon as
     * its last run went terminal.
     */
    public function testAnIneligibleChainIsRefusedRegardlessOfRunHistory(): void
    {
        $this->seedCanaryFamilies();
        $this->succeedRun();

        $html = $this->render(false, 'nft_discovery_unsupported');

        self::assertStringContainsString('disabled aria-disabled="true"', $html);
        self::assertStringNotContainsString('type="submit"', $html);
        self::assertStringNotContainsString('Continue scan', $html);
    }

    // ── (6) NOTHING LEAKS AND NOTHING IS WRITTEN ────────────────────────

    /** Rendering the panel writes nothing. */
    public function testRenderingWritesNothing(): void
    {
        $this->seedCanaryFamilies();
        $this->succeedRun();

        $wpdb   = $GLOBALS['wpdb'];
        $before = [
            (string) $wpdb->get_var('SELECT COUNT(*) FROM `' . DiscoveryRunRepository::table() . '` WHERE chain_id = ' . self::CHAIN),
            (string) $wpdb->get_var('SELECT COUNT(*) FROM `' . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN),
            (string) $wpdb->get_var('SELECT COALESCE(MD5(GROUP_CONCAT(code_id, COALESCE(classified_at, "-"))), "") FROM `'
                . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN),
        ];

        $this->render();
        $this->render();
        $this->render();

        $after = [
            (string) $wpdb->get_var('SELECT COUNT(*) FROM `' . DiscoveryRunRepository::table() . '` WHERE chain_id = ' . self::CHAIN),
            (string) $wpdb->get_var('SELECT COUNT(*) FROM `' . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN),
            (string) $wpdb->get_var('SELECT COALESCE(MD5(GROUP_CONCAT(code_id, COALESCE(classified_at, "-"))), "") FROM `'
                . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN),
        ];

        self::assertSame($before, $after, 'rendering an admin view must not write');
    }

    /** No SQL, no exception text, no credentials reach the page. */
    public function testTheControlLeaksNothing(): void
    {
        $this->seedCanaryFamilies();
        $this->succeedRun();

        $html = $this->render();

        // ⚠ CASE-SENSITIVE for the SQL keywords, and deliberately so. The
        // first draft matched `WHERE ` case-insensitively and failed on the
        // hint's own English — "it resumes WHERE the last pass stopped".
        // Leaked SQL is the plugin's own uppercase query text, so matching
        // the real shape is both stricter and free of that collision.
        foreach (['SELECT ', ' FROM `', 'WHERE ', 'INSERT INTO', 'UPDATE '] as $sql) {
            self::assertStringNotContainsString($sql, $html, $sql . ' must never reach the page');
        }

        foreach (['wpdb', 'Exception', 'Stack trace', 'DB_PASSWORD', 'DB_USER', 'AUTH_KEY', 'NONCE_SALT'] as $secret) {
            self::assertStringNotContainsStringIgnoringCase($secret, $html, $secret . ' must never reach the page');
        }
    }
}
