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
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * "We could not find out" is not "there is nothing there".
 *
 * ── THE DEFECT THIS FILE EXISTS FOR ─────────────────────────────────────
 * `remaining_families` counts what a pass could CLAIM, and its predicate
 * excludes `retry_count >= MAX_RETRIES`. So a family that gave up after six
 * attempts leaves the queue WITHOUT ever being resolved — and with only such
 * families left, `remaining` reached 0, `scan_complete` said YES, and the
 * panel said:
 *
 *     "Scan complete. All 10 contract families were checked.
 *      No supported NFT collections were confirmed."
 *
 * Both halves false. Measured on real MySQL before the fix; every assertion
 * below is against the same fixture.
 *
 * ⚠ THE STORAGE CANNOT TELL THEM APART BY ITSELF. `not_cw721` and a
 * six-times-unreachable family differ only by `retry_count`, so the read
 * model is the ONLY place the distinction can be preserved.
 */
#[CoversClass(DiscoveryScanProgress::class)]
#[Group('integration')]
final class DiscoveryUnresolvedFamiliesIntegrationTest extends TestCase
{
    private const CHAIN = 90805;

    private const OPERATOR = 4245;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
        $wpdb->query('DELETE FROM `' . ChainCheckpointRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
        $wpdb->query('DELETE FROM `' . DiscoveryRunRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    /**
     * THE REQUIRED FIXTURE, exactly:
     *
     *   - enumeration complete;
     *   - NO immediately eligible work;
     *   - NO delayed work;
     *   - `$exhausted` families at the retry limit;
     *   - NO confirmed CW-721.
     *
     * Everything else is a terminal negative, so the only thing standing
     * between this chain and "complete" is the unresolved families.
     */
    private function seedExhaustedOnly(int $total = 10, int $exhausted = 1): void
    {
        $families = [];
        for ($codeId = 1; $codeId <= $total; $codeId++) {
            $families[] = ['code_id' => $codeId, 'checksum' => sprintf('%064x', $codeId)];
        }
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN, $families);

        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::recordCwCodeProgress(self::CHAIN, null, $total, true);

        $settled = $total - $exhausted;
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
                'cosmos1testcontractaddressforunresolved0000000000',
                0
            );
        }

        // ⚠ NOT written through recordClassification(): no production writer
        // can produce "exhausted" in one step — it is the state a row REACHES
        // after MAX_RETRIES attempts, and `next_attempt_at` is cleared so the
        // row is not merely delayed. That is the whole point of the fixture.
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . CosmwasmCodeFamilyRepository::table() . '`
                SET classification = %s, retry_count = %d, next_attempt_at = NULL
              WHERE chain_id = %d AND code_id > %d',
            CosmwasmClassifier::UNREACHABLE,
            CosmwasmClassifier::MAX_RETRIES,
            self::CHAIN,
            $settled
        ));
    }

    private function render(): string
    {
        ob_start();
        DiscoveryScanPanel::render(
            (object) ['id' => self::CHAIN, 'slug' => 'cosmos', 'name' => 'Cosmos Hub'],
            true,
            ''
        );

        return (string) ob_get_clean();
    }

    private function finishASession(string $stopReason): void
    {
        $created = DiscoveryRunRepository::insertQueued(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            DiscoveryScanMode::INCREMENTAL,
            self::CHAIN,
            self::OPERATOR
        );
        self::assertIsArray($created);

        $token = DiscoveryRunRepository::claim((int) $created['id']);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::markSucceeded(
            (int) $created['id'],
            $token,
            $stopReason,
            false,
            ['requests_used' => 48, 'families_seen' => 9]
        ));
    }

    // ── (1) THE READ MODEL ──────────────────────────────────────────────

    /**
     * The fixture is what it claims to be, and the scan is NOT complete.
     */
    public function testAnExhaustedFamilyBlocksCompletion(): void
    {
        $this->seedExhaustedOnly(10, 1);

        $p = DiscoveryScanProgress::forChain(self::CHAIN);

        // The preconditions the brief specifies, asserted rather than assumed.
        self::assertSame(DiscoveryScanProgress::YES, $p['enumeration_complete']);
        self::assertSame(0, $p['eligible_now'], 'no immediately eligible work');
        self::assertSame(0, $p['delayed_families'], 'no delayed work');
        self::assertSame(1, $p['exhausted_families'], 'exactly one unresolved family');
        self::assertSame(0, $p['collection_families'], 'no CW-721 confirmed');
        self::assertSame(0, $p['remaining_families'], 'nothing claimable — this is why it looked complete');

        // ⚠ THE ASSERTION THE FIX EXISTS FOR.
        self::assertSame(
            DiscoveryScanProgress::NO,
            $p['scan_complete'],
            'a family that was never resolved must block completion'
        );
    }

    /** All five outcomes stay distinct in the read model. */
    public function testTheFiveOutcomesAreSeparatelyRepresented(): void
    {
        $this->seedExhaustedOnly(10, 2);

        $p = DiscoveryScanProgress::forChain(self::CHAIN);

        self::assertSame(8, $p['negative_families'], 'confirmed negative');
        self::assertSame(0, $p['collection_families'], 'confirmed / probable CW-721');
        self::assertSame(0, $p['delayed_families'], 'temporarily delayed');
        self::assertSame(2, $p['exhausted_families'], 'retry-exhausted unresolved');
        self::assertTrue($p['ok'], 'and a readable progress read');

        // ⚠ negative and exhausted are DIFFERENT NUMBERS over the same rows.
        // If one were derived from the other they could never disagree.
        self::assertNotSame($p['negative_families'], $p['exhausted_families']);
        self::assertSame(10, $p['negative_families'] + $p['exhausted_families']);
    }

    /** An unreadable read is the fifth state, and it is never zero. */
    public function testAnUnreadableProgressReadIsItsOwnState(): void
    {
        $p = DiscoveryScanProgress::forChain(0);

        self::assertFalse($p['ok']);
        self::assertSame(DiscoveryScanProgress::UNKNOWN, $p['scan_complete']);
        self::assertNull($p['exhausted_families'], 'unknown is NULL, never 0');
        self::assertNull($p['negative_families']);
    }

    // ── (2) THE SENTENCE ────────────────────────────────────────────────

    /**
     * The final-zero sentence is FORBIDDEN while any family is unresolved.
     */
    public function testTheFinalZeroSentenceIsForbiddenWhileAFamilyIsUnresolved(): void
    {
        $this->seedExhaustedOnly(10, 1);

        $sentence = DiscoveryScanProgress::summarySentence(DiscoveryScanProgress::forChain(self::CHAIN));

        self::assertStringNotContainsString('Scan complete.', $sentence);
        self::assertStringNotContainsString('All 10 contract families were checked', $sentence);
        self::assertStringNotContainsString('No supported NFT collections were confirmed', $sentence);

        // …and it says what IS true.
        self::assertStringContainsString('Scan session finished.', $sentence);
        self::assertStringContainsString('1 family could not be resolved', $sentence);
        self::assertStringContainsString('still unknown', $sentence);
    }

    /** The count is exact and plural-correct. */
    public function testTheUnresolvedCountIsExact(): void
    {
        $this->seedExhaustedOnly(10, 3);

        $sentence = DiscoveryScanProgress::summarySentence(DiscoveryScanProgress::forChain(self::CHAIN));

        self::assertStringContainsString('3 families could not be resolved', $sentence);
        self::assertStringNotContainsString('1 family could not', $sentence);
    }

    /**
     * With NOTHING unresolved the completion sentence is still reachable.
     *
     * ⚠ Otherwise the fix would simply have made completion impossible, which
     * is a different lie.
     */
    public function testCompletionIsStillReachableWhenNothingIsUnresolved(): void
    {
        $this->seedExhaustedOnly(10, 0);

        $p = DiscoveryScanProgress::forChain(self::CHAIN);

        self::assertSame(0, $p['exhausted_families']);
        self::assertSame(DiscoveryScanProgress::YES, $p['scan_complete']);
        self::assertStringContainsString(
            'Scan complete. All 10 contract families were checked. No supported NFT collections were confirmed.',
            DiscoveryScanProgress::summarySentence($p)
        );
    }

    // ── (3) THE PANEL ───────────────────────────────────────────────────

    /**
     * What an administrator actually reads.
     */
    public function testThePanelReportsUnresolvedRatherThanCompletion(): void
    {
        $this->seedExhaustedOnly(10, 1);
        $this->finishASession('session_provider_errors');

        $html = $this->render();

        // ⚠ NEVER the final zero.
        self::assertStringNotContainsString('Scan complete', $html);
        self::assertStringNotContainsString('No supported NFT collections were confirmed', $html);
        self::assertStringNotContainsString('<strong>Scan complete</strong>', $html);

        // ⚠ NEVER a claim about the chain.
        self::assertStringNotContainsString('has no NFT', $html);
        self::assertStringNotContainsString('Nothing remains', $html);

        // The exact count, and the word that matters.
        self::assertStringContainsString('1 family could not be resolved', $html);
        self::assertStringContainsString('unresolved, not a negative result', $html);
        self::assertStringContainsString('Scan session finished.', $html);

        // ⚠ AND IT IS NOT DESCRIBED AS `not_cw721`. The negative verdict
        // belongs to the eight families that earned it, not to this one.
        self::assertStringNotContainsString('not_cw721', $html);
    }

    /** The heading is pass/session-scoped, never the bare `Finished`. */
    public function testTheHeadingIsSessionScoped(): void
    {
        $this->seedExhaustedOnly(10, 1);
        $this->finishASession('session_provider_errors');

        $html = $this->render();

        self::assertStringContainsString('<strong>Session finished</strong>', $html);
        self::assertStringNotContainsString('<strong>Finished</strong>', $html);
    }

    /**
     * Continue is NOT offered — there is genuinely nothing to claim.
     *
     * ⚠ Offering it would send an operator into an empty pass forever.
     * "Finished with unresolved work" and "more work available" are different
     * facts, and only the second one earns a Continue button.
     */
    public function testContinueIsNotOfferedWhenOnlyUnresolvedFamiliesRemain(): void
    {
        $this->seedExhaustedOnly(10, 1);
        $this->finishASession('session_provider_errors');

        $p = DiscoveryScanProgress::forChain(self::CHAIN);
        self::assertSame(DiscoveryScanProgress::NO, $p['more_work_available']);

        $html = $this->render();
        self::assertStringNotContainsString('>Continue scan</button>', $html);
    }

    /** Rendering this state writes nothing and schedules nothing. */
    public function testRenderingTheUnresolvedStateWritesNothing(): void
    {
        $this->seedExhaustedOnly(10, 1);
        $this->finishASession('session_provider_errors');

        $wpdb   = $GLOBALS['wpdb'];
        $before = (string) $wpdb->get_var(
            'SELECT MD5(GROUP_CONCAT(code_id, classification, retry_count)) FROM `'
            . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN . ' ORDER BY code_id'
        );
        $GLOBALS['bcc_scheduled'] = [];

        $this->render();
        $this->render();

        $after = (string) $wpdb->get_var(
            'SELECT MD5(GROUP_CONCAT(code_id, classification, retry_count)) FROM `'
            . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN . ' ORDER BY code_id'
        );

        self::assertSame($before, $after, 'an unresolved family must not be rewritten by looking at it');
        self::assertSame([], $GLOBALS['bcc_scheduled'], 'and nothing may be scheduled');
    }

    // ── (4) DELAYED IS STILL DIFFERENT FROM EXHAUSTED ───────────────────

    /**
     * A delayed family is NOT unresolved — it still has a future.
     *
     * ⚠ The two must not collapse into one another. Delayed work comes back
     * on its own; exhausted work needs an operator or a classifier-version
     * bump. Reporting either as the other misleads in opposite directions.
     */
    public function testDelayedAndExhaustedAreNotTheSameThing(): void
    {
        $this->seedExhaustedOnly(10, 1);

        // One of the negatives becomes merely delayed.
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . CosmwasmCodeFamilyRepository::table() . '`
                SET classification = %s, retry_count = 1,
                    next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 6 HOUR),
                    classified_at = NULL
              WHERE chain_id = %d AND code_id = 1',
            CosmwasmClassifier::UNREACHABLE,
            self::CHAIN
        ));

        $p = DiscoveryScanProgress::forChain(self::CHAIN);

        self::assertSame(1, $p['delayed_families'], 'one waiting on backoff');
        self::assertSame(1, $p['exhausted_families'], 'one out of attempts');
        self::assertSame(0, $p['eligible_now'], 'and neither is claimable now');
        self::assertSame(DiscoveryScanProgress::NO, $p['scan_complete']);

        $html = $this->render();
        self::assertStringContainsString('waiting to be retried later', $html);
        self::assertStringContainsString('could not be resolved after repeated attempts', $html);
    }
}
