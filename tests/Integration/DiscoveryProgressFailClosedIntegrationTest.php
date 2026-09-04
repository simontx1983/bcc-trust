<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\DiscoveryScanProgress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A progress read that DID NOT RUN must never become an answer.
 *
 * ── THE FAIL-OPEN THIS CLOSES ───────────────────────────────────────────
 * `countPendingClassification()` guards with `guardRead()`, which LOGS a
 * failed read and lets the empty result through — correct for the worker,
 * where "no work this tick" is safe. On an operator surface the same `0`
 * renders as "nothing left to scan", one short step from "this chain has
 * no NFT collections". The canary is what makes that concrete: had the
 * count failed on 2026-09-04, the panel would have said 0 remaining while
 * 732 families sat unexamined.
 *
 * ── HOW THE FAILURE IS PRODUCED ─────────────────────────────────────────
 * By DROPPING the table, not by doubling the repository. A double would
 * prove only that the code handles an exception someone chose to throw;
 * dropping the table makes MySQL produce the real error through the real
 * query, which is the thing that would actually happen.
 */
#[CoversClass(DiscoveryScanProgress::class)]
#[Group('integration')]
final class DiscoveryProgressFailClosedIntegrationTest extends TestCase
{
    private const CHAIN = 90802;

    /** @var array<string, string> */
    private array $ddl = [];

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
        $wpdb->query('DELETE FROM `' . ChainCheckpointRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
        $this->ddl = [];
    }

    protected function tearDown(): void
    {
        // Rebuild anything this test dropped, before the next test runs.
        $wpdb = $GLOBALS['wpdb'];
        foreach ($this->ddl as $create) {
            $wpdb->query($create);
        }
        $this->ddl = [];
        $wpdb->query('DELETE FROM `' . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
        $wpdb->query('DELETE FROM `' . ChainCheckpointRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
    }

    /** Drop a table after capturing its CREATE, so tearDown can restore it. */
    private function breakTable(string $table): void
    {
        $wpdb = $GLOBALS['wpdb'];
        // ⚠ No ARRAY_N in this harness — get_row() returns an object.
        $row = $wpdb->get_row('SHOW CREATE TABLE `' . $table . '`');
        self::assertNotNull($row, 'precondition: the table must exist to be broken');
        $this->ddl[$table] = (string) $row->{'Create Table'};
        $wpdb->query('DROP TABLE `' . $table . '`');
    }

    private function seed(int $count): void
    {
        $families = [];
        for ($codeId = 1; $codeId <= $count; $codeId++) {
            $families[] = ['code_id' => $codeId, 'checksum' => sprintf('%064x', $codeId)];
        }
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN, $families);
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::recordCwCodeProgress(self::CHAIN, null, $count, true);
    }

    // ── the healthy control, first ──────────────────────────────────────

    /**
     * Without this, every assertion below would also pass against a class
     * that returned "unavailable" unconditionally.
     */
    public function testTheHarnessProducesARealAnswerWhenTheTableIsPresent(): void
    {
        $this->seed(5);

        $p = DiscoveryScanProgress::forChain(self::CHAIN);

        self::assertTrue($p['ok']);
        self::assertSame(5, $p['total_families']);
        self::assertSame(5, $p['remaining_families']);
        self::assertSame(DiscoveryScanProgress::NO, $p['scan_complete']);
    }

    // ── (7)(8) the failure ──────────────────────────────────────────────

    public function testAFailedProgressReadReportsUnavailableRatherThanZero(): void
    {
        $this->seed(5);
        $this->breakTable(CosmwasmCodeFamilyRepository::table());

        $p = DiscoveryScanProgress::forChain(self::CHAIN);

        self::assertFalse($p['ok']);
        self::assertSame('progress_unavailable', $p['reason']);

        // ⚠ NOT zero. Null means "no number", which a renderer must not
        // print; 0 would have been a number an operator could act on.
        self::assertNull($p['total_families']);
        self::assertNull($p['remaining_families']);
        self::assertNull($p['classified_families']);
        self::assertNull($p['collection_families']);

        // ⚠ NOT complete, and NOT "no more work".
        self::assertSame(DiscoveryScanProgress::UNKNOWN, $p['scan_complete']);
        self::assertSame(DiscoveryScanProgress::UNKNOWN, $p['more_work_available']);
        self::assertNotSame(DiscoveryScanProgress::YES, $p['scan_complete']);
    }

    /** And the sentence refuses to conclude anything. */
    public function testTheSentenceForAFailedReadMakesNoConclusion(): void
    {
        $this->seed(5);
        $this->breakTable(CosmwasmCodeFamilyRepository::table());

        $s = DiscoveryScanProgress::summarySentence(DiscoveryScanProgress::forChain(self::CHAIN));

        self::assertStringContainsString('No completion conclusion can be made', $s);
        self::assertStringNotContainsString('Scan complete', $s);
        self::assertStringNotContainsString('No supported NFT collections', $s);
    }

    /**
     * The repository entry point itself throws — proving the tri-state does
     * not depend on the service noticing something subtle.
     */
    public function testTheFailClosedCountThrowsRatherThanReturningZero(): void
    {
        $this->seed(3);
        $this->breakTable(CosmwasmCodeFamilyRepository::table());

        $this->expectException(RepositoryReadFailure::class);
        CosmwasmCodeFamilyRepository::countPendingClassificationOrThrow(self::CHAIN, CosmwasmClassifier::VERSION);
    }

    /**
     * ⚠ AND THE WORKER'S VARIANT STILL FAILS OPEN, DELIBERATELY.
     *
     * Both behaviours are correct for their caller, and this pins that the
     * two entry points really are different — otherwise a future edit could
     * "unify" them and either fatal the worker mid-tick or hand the panel a
     * zero it must never see.
     */
    public function testTheWorkerVariantStillReturnsZeroOnAFailedRead(): void
    {
        $this->seed(3);
        $this->breakTable(CosmwasmCodeFamilyRepository::table());

        self::assertSame(
            0,
            CosmwasmCodeFamilyRepository::countPendingClassification(self::CHAIN, CosmwasmClassifier::VERSION),
            'the worker path absorbs the failure by design'
        );
    }

    // ── (14) reading progress writes nothing ────────────────────────────

    /**
     * Polling is free. The panel renders this on every page load and every
     * status poll, so a write here would be a write on every refresh.
     */
    public function testReadingProgressWritesNothing(): void
    {
        $this->seed(6);

        $wpdb   = $GLOBALS['wpdb'];
        $before = [
            'families'    => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . CosmwasmCodeFamilyRepository::table() . '`'),
            'checkpoints' => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . ChainCheckpointRepository::table() . '`'),
            'ckpt_digest' => (string) $wpdb->get_var(
                'SELECT SHA2(GROUP_CONCAT(CONCAT_WS(":", chain_id, cw_discovery_state, cw_max_code_id,
                    COALESCE(cw_code_cursor, "-"), COALESCE(cw_backfill_completed_at, "-")) ORDER BY chain_id), 256)
                   FROM `' . ChainCheckpointRepository::table() . '`'
            ),
            'fam_digest'  => (string) $wpdb->get_var(
                'SELECT SHA2(GROUP_CONCAT(CONCAT_WS(":", id, classification, COALESCE(classification_reason, "-"),
                    COALESCE(classified_at, "-"), retry_count) ORDER BY id), 256)
                   FROM `' . CosmwasmCodeFamilyRepository::table() . '`'
            ),
        ];

        // Poll repeatedly, as a panel left open would.
        for ($i = 0; $i < 12; $i++) {
            DiscoveryScanProgress::forChain(self::CHAIN);
        }

        $after = [
            'families'    => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . CosmwasmCodeFamilyRepository::table() . '`'),
            'checkpoints' => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . ChainCheckpointRepository::table() . '`'),
            'ckpt_digest' => (string) $wpdb->get_var(
                'SELECT SHA2(GROUP_CONCAT(CONCAT_WS(":", chain_id, cw_discovery_state, cw_max_code_id,
                    COALESCE(cw_code_cursor, "-"), COALESCE(cw_backfill_completed_at, "-")) ORDER BY chain_id), 256)
                   FROM `' . ChainCheckpointRepository::table() . '`'
            ),
            'fam_digest'  => (string) $wpdb->get_var(
                'SELECT SHA2(GROUP_CONCAT(CONCAT_WS(":", id, classification, COALESCE(classification_reason, "-"),
                    COALESCE(classified_at, "-"), retry_count) ORDER BY id), 256)
                   FROM `' . CosmwasmCodeFamilyRepository::table() . '`'
            ),
        ];

        self::assertSame($before, $after, 'reading progress must not move any row');
    }

    /**
     * ⚠ AND IT CREATES NO CHECKPOINT ROW FOR A CHAIN THAT HAS NONE.
     *
     * The test above seeds a checkpoint, so an `ensureExists()` slipped onto
     * the read path would be a harmless no-op there — and a mutation control
     * that planted exactly that SURVIVED. A chain nobody has scanned is the
     * only state where the write is observable, and it is also the state a
     * panel renders most often: every unscanned chain, on every page load.
     */
    public function testReadingProgressCreatesNoCheckpointForAnUnscannedChain(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainCheckpointRepository::table();

        // Deliberately NOT seeded: no families, no checkpoint.
        self::assertSame(
            '0',
            (string) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '` WHERE chain_id = ' . self::CHAIN),
            'precondition: this chain has no checkpoint'
        );
        $rowsBefore = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '`');

        for ($i = 0; $i < 8; $i++) {
            $p = DiscoveryScanProgress::forChain(self::CHAIN);
            self::assertSame(DiscoveryScanProgress::NO, $p['enumeration_complete']);
        }

        self::assertSame(
            '0',
            (string) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '` WHERE chain_id = ' . self::CHAIN),
            'polling must not create a checkpoint row'
        );
        self::assertSame(
            $rowsBefore,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '`'),
            'polling must not create a checkpoint row for any chain'
        );
    }

    // ── (11)(12) a later request resumes rather than restarting ─────────

    /**
     * ⚠ THE RESUME PROPERTY, PROVEN AGAINST THE WORKER'S OWN QUEUE.
     *
     * After enumeration is marked complete, the families the next pass will
     * claim are exactly the ones still pending — asserted through
     * `findPendingClassification()`, the selector the worker actually calls,
     * so this cannot pass while the worker would pick up something else.
     */
    public function testALaterRequestResumesThePendingQueueAfterEnumerationIsComplete(): void
    {
        $this->seed(20);

        // A first pass settles the first five.
        for ($codeId = 1; $codeId <= 5; $codeId++) {
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
                'cosmos1resumefixture000000000000000000000000000000',
                0
            );
        }

        $p = DiscoveryScanProgress::forChain(self::CHAIN);
        self::assertSame(DiscoveryScanProgress::YES, $p['enumeration_complete']);
        self::assertSame(15, $p['remaining_families']);
        self::assertSame(DiscoveryScanProgress::YES, $p['more_work_available']);

        // What the NEXT pass would actually claim.
        // Signature is (chainId, limit, classifierVersion, priority).
        $next = CosmwasmCodeFamilyRepository::findPendingClassification(
            self::CHAIN,
            50,
            CosmwasmClassifier::VERSION,
            []
        );

        self::assertCount(15, $next, 'the queue the worker sees matches the number shown');

        // ⚠ It resumes at 6 — it does NOT restart from code id 1.
        $codeIds = array_map(static fn(object $r): int => (int) $r->code_id, $next);
        self::assertSame(6, min($codeIds), 'the next pass resumes, it does not start over');
        self::assertSame(20, max($codeIds));

        // And the enumeration marker is untouched by classification work.
        $ck = ChainCheckpointRepository::get(self::CHAIN);
        self::assertNotNull($ck->cw_backfill_completed_at);
        self::assertSame(ChainCheckpointRepository::CW_STATE_BACKFILLED, $ck->cw_discovery_state);
    }
}
