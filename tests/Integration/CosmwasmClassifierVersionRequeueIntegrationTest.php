<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The classifier-version bump, and the ORDER it produces.
 *
 * ── WHY THIS CANNOT BE A UNIT TEST ──────────────────────────────────────
 * The question is not "does the constant equal 2". It is: after the real
 * requeue UPDATE runs, does the real `ORDER BY next_attempt_at IS NOT
 * NULL, code_id ASC` put the repair band ahead of 75 never-attempted
 * families? That is MySQL's answer, not PHP's, and the unit fakes do not
 * execute either statement.
 *
 * ── THE STATE THIS REPRODUCES ───────────────────────────────────────────
 * Staging, after the first Dungeon canary:
 *
 *   80-88    inconclusive, version 1, classified_at set   (settled "no contracts")
 *   89       temporarily_unreachable, version 1, classified_at set
 *   90-104   inconclusive, version 0, classified_at NULL, next_attempt_at set
 *            (skipped by the poisoned circuit breaker)
 *   105-179  never attempted
 *
 * Under version 1 the selector returned 105-129: families 80-104 all carry
 * a `next_attempt_at`, which sorts them BEHIND every never-attempted row,
 * and family 89 was excluded outright by
 * `(classified_at IS NULL OR classifier_version < VERSION)`.
 */
#[Group('integration')]
#[CoversClass(CosmwasmClassifier::class)]
#[CoversClass(CosmwasmCodeFamilyRepository::class)]
final class CosmwasmClassifierVersionRequeueIntegrationTest extends TestCase
{
    private const CHAIN = 17;
    private const OTHER = 8;

    /** What the worker passes: REQUEUE_PER_PASS / FAMILIES_PER_PASS. */
    private const REQUEUE_LIMIT = 100;
    private const SELECT_LIMIT  = 25;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('TRUNCATE TABLE `' . CosmwasmCodeFamilyRepository::table() . '`');
        $wpdb->query('TRUNCATE TABLE `' . CosmwasmContractRepository::table() . '`');
    }

    /** Reproduce the preserved Dungeon shape in real rows. */
    private function seedPreservedDungeonState(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CosmwasmCodeFamilyRepository::table();

        $families = [];
        foreach (range(80, 179) as $codeId) {
            $families[] = ['code_id' => $codeId, 'checksum' => sprintf('%064x', $codeId)];
        }
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN, $families);

        // 80-88: settled "no contracts" at version 1.
        $wpdb->query(
            "UPDATE `{$table}`
                SET classification = 'inconclusive', classifier_version = 1,
                    classified_at = '2026-08-19 00:24:29',
                    next_attempt_at = '2026-08-19 12:24:29',
                    retry_count = 1, enumeration_complete = 1
              WHERE chain_id = " . self::CHAIN . " AND code_id BETWEEN 80 AND 88"
        );

        // 89: the White Whale factory, settled temporarily_unreachable at v1.
        $wpdb->query(
            "UPDATE `{$table}`
                SET classification = 'temporarily_unreachable', classifier_version = 1,
                    classified_at = '2026-08-19 00:24:46',
                    next_attempt_at = '2026-08-19 12:24:46',
                    retry_count = 1,
                    sample_contract = 'dungeon14l6gk0aj9859zkalletngjsv6a8p78cs5qpzywj0xe45v67whdkqnfkf7l'
              WHERE chain_id = " . self::CHAIN . " AND code_id = 89"
        );

        // 90-104: breaker-skipped. Never classified, but attempt-failed, so
        // they carry an EXPIRED next_attempt_at and an unresolved error.
        $wpdb->query(
            "UPDATE `{$table}`
                SET classification = 'inconclusive', classifier_version = 0,
                    classified_at = NULL,
                    next_attempt_at = '2026-08-19 12:24:46',
                    retry_count = 1,
                    last_error = 'Circuit breaker open for chain 17'
              WHERE chain_id = " . self::CHAIN . " AND code_id BETWEEN 90 AND 104"
        );

        // 105-179 keep recordDiscovered()'s defaults: never attempted.
    }

    /**
     * Every family row as plain arrays, ordered.
     *
     * `get_results()` hands back stdClass instances, and two queries never
     * return the SAME objects — comparing them directly asserts object
     * identity, not row content.
     *
     * @return list<array<string, mixed>>
     */
    private function allRows(): array
    {
        $rows = $GLOBALS['wpdb']->get_results(
            'SELECT * FROM `' . CosmwasmCodeFamilyRepository::table() . '` ORDER BY chain_id, code_id'
        );

        return array_map(static fn($r): array => (array) $r, $rows ?: []);
    }

    /** @return list<int> */
    private function nextSelection(int $version): array
    {
        $rows = CosmwasmCodeFamilyRepository::findPendingClassification(
            self::CHAIN,
            self::SELECT_LIMIT,
            $version
        );

        return array_map(static fn($r): int => (int) $r->code_id, $rows);
    }

    // ── the constant ────────────────────────────────────────────────────

    public function testTheClassifierVersionIsTwo(): void
    {
        self::assertSame(2, CosmwasmClassifier::VERSION);
    }

    // ── the ordering proof ──────────────────────────────────────────────

    /**
     * THE LOAD-BEARING TEST.
     *
     * Before: the selector returns never-attempted families and misses the
     * whole repair band. After the real requeue at version 2: family 89
     * and all of 90-104 lead the batch.
     */
    public function testTheVersionBumpPutsTheRepairBandAtTheFrontOfTheQueue(): void
    {
        $this->seedPreservedDungeonState();

        // BEFORE — reproduce the measured staging failure exactly.
        $before = $this->nextSelection(1);
        self::assertSame(range(105, 129), $before, 'version 1 selects only never-attempted families');
        self::assertNotContains(89, $before);
        self::assertSame([], array_intersect($before, range(90, 104)));

        // The requeue the worker runs first, every pass, chain-scoped.
        $requeued = CosmwasmCodeFamilyRepository::requeueForClassifierVersion(
            self::CHAIN,
            CosmwasmClassifier::VERSION,
            self::REQUEUE_LIMIT
        );
        // MySQL reports rows CHANGED, not matched. All 100 families are
        // below version 2 and match the WHERE, but 105-179 already hold
        // classified_at NULL / next_attempt_at NULL / retry_count 0, so the
        // UPDATE is a no-op for them. The 25 changed rows are exactly the
        // previously-attempted band this repair aims at.
        self::assertSame(25, $requeued, 'only the attempted band needed changing');
        self::assertSame('100', (string) $GLOBALS['wpdb']->get_var(
            'SELECT COUNT(*) FROM `' . CosmwasmCodeFamilyRepository::table()
            . '` WHERE chain_id = ' . self::CHAIN . ' AND classifier_version < 2'
        ), 'and all 100 are below the new version');

        // AFTER — the repair band leads.
        $after = $this->nextSelection(CosmwasmClassifier::VERSION);
        self::assertSame(range(80, 104), $after, 'the previously-attempted band is now first, in code order');
        self::assertContains(89, $after, 'family 89 is reachable again');
        foreach (range(90, 104) as $codeId) {
            self::assertContains($codeId, $after, sprintf('breaker-skipped family %d is selected', $codeId));
        }
        self::assertSame([], array_intersect($after, range(105, 179)), 'never-attempted rows do not jump ahead');
    }

    /** The mechanism: clearing `next_attempt_at` is what moves the band. */
    public function testTheRequeueClearsExactlyTheOrderingAndRetryFields(): void
    {
        $this->seedPreservedDungeonState();
        CosmwasmCodeFamilyRepository::requeueForClassifierVersion(self::CHAIN, CosmwasmClassifier::VERSION, self::REQUEUE_LIMIT);

        $row = $GLOBALS['wpdb']->get_row(
            'SELECT * FROM `' . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN . ' AND code_id = 89'
        );

        // Cleared — these are what make the row pending and first again.
        self::assertNull($row->classified_at);
        self::assertNull($row->next_attempt_at);
        self::assertSame('0', (string) $row->retry_count);

        // PRESERVED — evidence is not erased by requeueing.
        self::assertSame('temporarily_unreachable', $row->classification, 'the old verdict stands until re-decided');
        self::assertSame('1', (string) $row->classifier_version, 'still below 2, so it stays pending until reclassified');
        self::assertSame(
            'dungeon14l6gk0aj9859zkalletngjsv6a8p78cs5qpzywj0xe45v67whdkqnfkf7l',
            (string) $row->sample_contract
        );
    }

    /** Family 89's exclusion under v1 was the version term, not ordering. */
    public function testFamily89WasExcludedByTheVersionTermNotByOrdering(): void
    {
        $this->seedPreservedDungeonState();

        $table = CosmwasmCodeFamilyRepository::table();
        // Even with every other row removed, v1 cannot see it.
        $GLOBALS['wpdb']->query("DELETE FROM `{$table}` WHERE code_id <> 89");

        self::assertSame([], $this->nextSelection(1), 'settled at the current version = invisible');
        self::assertSame([89], $this->nextSelection(2), 'a higher version makes it pending again');
    }

    // ── what must NOT be swept ──────────────────────────────────────────

    /** Terminal `not_cw721` is never requeued by a version bump. */
    public function testTerminalNotCw721IsNotRequeued(): void
    {
        $table = CosmwasmCodeFamilyRepository::table();
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN, [
            ['code_id' => 10, 'checksum' => str_repeat('a', 64)],
            ['code_id' => 11, 'checksum' => str_repeat('b', 64)],
        ]);
        $GLOBALS['wpdb']->query(
            "UPDATE `{$table}`
                SET classification = 'not_cw721', classifier_version = 1,
                    classified_at = '2026-08-19 00:00:00', next_attempt_at = '2026-08-19 01:00:00', retry_count = 2
              WHERE chain_id = " . self::CHAIN . " AND code_id = 10"
        );
        $GLOBALS['wpdb']->query(
            "UPDATE `{$table}`
                SET classification = 'confirmed_cw721', classifier_version = 1,
                    classified_at = '2026-08-19 00:00:00', next_attempt_at = '2026-08-19 01:00:00', retry_count = 2
              WHERE chain_id = " . self::CHAIN . " AND code_id = 11"
        );

        $requeued = CosmwasmCodeFamilyRepository::requeueForClassifierVersion(self::CHAIN, CosmwasmClassifier::VERSION, self::REQUEUE_LIMIT);
        self::assertSame(0, $requeued, 'neither terminal verdict is swept');

        foreach ([10, 11] as $codeId) {
            $row = $GLOBALS['wpdb']->get_row("SELECT * FROM `{$table}` WHERE chain_id = " . self::CHAIN . " AND code_id = {$codeId}");
            self::assertSame('2026-08-19 00:00:00', (string) $row->classified_at, "code {$codeId} untouched");
            self::assertSame('2', (string) $row->retry_count);
        }

        self::assertSame([], $this->nextSelection(CosmwasmClassifier::VERSION), 'and neither becomes pending');
    }

    // ── scoping and idempotence ─────────────────────────────────────────

    /**
     * A chain-17 requeue must not touch chain 8.
     *
     * DELIBERATELY SEEDED SMALL. An earlier version of this test used the
     * full 100-family Dungeon fixture and passed even with `WHERE chain_id`
     * removed — MySQL's `UPDATE … LIMIT 100` was exhausted by chain 17's
     * own rows before it ever reached chain 8, so the LIMIT was doing the
     * protecting and the scoping was untested. Two chain-17 rows leave the
     * limit nowhere near spent, so only the WHERE can keep chain 8 intact.
     */
    public function testTheRequeueIsChainScoped(): void
    {
        $table = CosmwasmCodeFamilyRepository::table();

        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN, [
            ['code_id' => 89, 'checksum' => str_repeat('d', 64)],
            ['code_id' => 90, 'checksum' => str_repeat('e', 64)],
        ]);
        $GLOBALS['wpdb']->query(
            "UPDATE `{$table}`
                SET classification = 'temporarily_unreachable', classifier_version = 1,
                    classified_at = '2026-08-19 00:24:46',
                    next_attempt_at = '2026-08-19 12:24:46', retry_count = 1
              WHERE chain_id = " . self::CHAIN
        );
        CosmwasmCodeFamilyRepository::recordDiscovered(self::OTHER, [['code_id' => 434, 'checksum' => str_repeat('c', 64)]]);
        $GLOBALS['wpdb']->query(
            "UPDATE `{$table}`
                SET classification = 'inconclusive', classifier_version = 1,
                    classified_at = '2026-08-10 00:00:00', next_attempt_at = '2026-08-10 06:00:00', retry_count = 3
              WHERE chain_id = " . self::OTHER
        );

        CosmwasmCodeFamilyRepository::requeueForClassifierVersion(self::CHAIN, CosmwasmClassifier::VERSION, self::REQUEUE_LIMIT);

        $other = $GLOBALS['wpdb']->get_row("SELECT * FROM `{$table}` WHERE chain_id = " . self::OTHER);
        self::assertSame('2026-08-10 00:00:00', (string) $other->classified_at, 'foreign chain untouched');
        self::assertSame('2026-08-10 06:00:00', (string) $other->next_attempt_at);
        self::assertSame('3', (string) $other->retry_count);
    }

    /** Running it twice reaches the same state. */
    public function testTheRequeueIsIdempotent(): void
    {
        $this->seedPreservedDungeonState();
        $table = CosmwasmCodeFamilyRepository::table();

        CosmwasmCodeFamilyRepository::requeueForClassifierVersion(self::CHAIN, CosmwasmClassifier::VERSION, self::REQUEUE_LIMIT);
        $first = $this->allRows();
        $firstSelection = $this->nextSelection(CosmwasmClassifier::VERSION);

        CosmwasmCodeFamilyRepository::requeueForClassifierVersion(self::CHAIN, CosmwasmClassifier::VERSION, self::REQUEUE_LIMIT);
        $second = $this->allRows();

        self::assertSame($first, $second, 'a second requeue changes nothing');
        self::assertSame($firstSelection, $this->nextSelection(CosmwasmClassifier::VERSION));
    }

    /**
     * Loading the constant writes nothing. Rows move only when a chain's
     * pass calls the chain-scoped requeue.
     */
    public function testReadingTheConstantPerformsNoWrite(): void
    {
        $this->seedPreservedDungeonState();
        $table  = CosmwasmCodeFamilyRepository::table();
        $before = $this->allRows();

        // Everything a deploy does with it: read it.
        self::assertSame(2, CosmwasmClassifier::VERSION);
        self::assertSame(2, CosmwasmClassifier::VERSION);

        self::assertSame($before, $this->allRows());
    }
}
