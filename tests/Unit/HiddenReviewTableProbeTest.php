<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\VoteRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A table-existence probe that FAILS is not a missing table, including
 * wpdb's pre-flush() bail, which leaves the previous query's rows behind.
 * Rationale: M7 (private tracker).
 *
 * Pinned here:
 *  - existsOrUnknown() is tri-state. Only a trusted answer is memoised,
 *    only a positive one is cached, and an unknown is asked again;
 *  - exists() still reads an unknown as false, without memoising it;
 *  - hiddenReviewExclusion() returns '1 = 1' only for a CONFIRMED missing
 *    table, and keeps the exclusion for every unknown answer.
 *
 * The real engine, the recalc and the edge write are pinned by
 * HiddenReviewScoreIntegrationTest and TrustEdgeVoteIntegrityIntegrationTest.
 *
 * Separate processes: the stubs declare global wp_cache shims and the real
 * Logger class, and TableRegistry's memo is static.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(TableRegistry::class)]
#[CoversClass(VoteRepository::class)]
final class HiddenReviewTableProbeTest extends TestCase
{
    private const ACTIVITIES = 'wp_peepso_activities';
    private const HIDDEN     = 'wp_bcc_hidden_activities';

    /** A row left behind by an unrelated earlier query. */
    private const STALE_UNRELATED = 'unrelated';

    /** A row left behind by an earlier probe that DID list the table. */
    private const STALE_SAME_TABLE = 'same_table';

    private \BccTableProbeWpdb $db;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/schema-capability-stubs.php';
        require_once __DIR__ . '/../Stubs/table-probe-stubs.php';
        \BccTestSchemaCache::reset();
        \BCC\Core\Log\Logger::$errors = [];

        $this->db        = new \BccTableProbeWpdb();
        $GLOBALS['wpdb'] = $this->db;
        TableRegistry::flushExistenceCache();
        \BccTestSchemaCache::reset();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    /**
     * @param array<string, string> $tables
     * @param self::STALE_*|null    $stale
     */
    private function answers(array $tables, ?string $stale = null): void
    {
        $this->db->tables = $tables;
        if ($stale === self::STALE_UNRELATED) {
            $this->db->last_result = [(object) ['n' => '7']];
        } elseif ($stale === self::STALE_SAME_TABLE) {
            $this->db->last_result = [(object) ['Tables_in_wp' => self::ACTIVITIES]];
        }
    }

    private static function cached(string $table): mixed
    {
        return \BccTestSchemaCache::raw('exists_' . $table, 'bcc_tables');
    }

    private static function exclusion(): string
    {
        $repo = (new \ReflectionClass(VoteRepository::class))->newInstanceWithoutConstructor();

        return $repo->hiddenReviewExclusion('v');
    }

    // ── TableRegistry ─────────────────────────────────────────────────────

    public function testAListedTableIsTrueAndRememberedAcrossRequests(): void
    {
        $this->answers([self::ACTIVITIES => 'present']);

        self::assertTrue(TableRegistry::existsOrUnknown(self::ACTIVITIES));
        self::assertSame('1', self::cached(self::ACTIVITIES), 'a positive answer is cached');
        self::assertTrue(TableRegistry::exists(self::ACTIVITIES));
        self::assertSame([self::ACTIVITIES], $this->db->probes, 'and memoised: one probe');
    }

    public function testATableTheProbeDoesNotListIsFalseForThisRequestOnly(): void
    {
        $this->answers([self::ACTIVITIES => 'missing']);

        self::assertFalse(TableRegistry::existsOrUnknown(self::ACTIVITIES));
        self::assertNull(self::cached(self::ACTIVITIES), 'a negative answer is never cached');
        self::assertFalse(TableRegistry::existsOrUnknown(self::ACTIVITIES));
        self::assertSame([self::ACTIVITIES], $this->db->probes, 'but it is memoised for the request');
    }

    /** @return iterable<string, array{string, self::STALE_*|null}> */
    public static function failedProbes(): iterable
    {
        yield 'a driver error'                               => ['error', null];
        yield 'the pre-flush bail, stale unrelated rows'     => ['bail', self::STALE_UNRELATED];
        yield 'the pre-flush bail, a stale row naming it'    => ['bail', self::STALE_SAME_TABLE];
    }

    /** @param self::STALE_*|null $stale */
    #[DataProvider('failedProbes')]
    public function testAFailedProbeIsUnknownNeverRememberedAndAskedAgain(string $mode, ?string $stale): void
    {
        $this->answers([self::ACTIVITIES => $mode], $stale);

        self::assertNull(TableRegistry::existsOrUnknown(self::ACTIVITIES), 'the probe cannot be trusted');
        self::assertNull(self::cached(self::ACTIVITIES), 'nothing reaches the cross-request cache');
        self::assertNotSame([], \BCC\Core\Log\Logger::$errors, 'the failed probe is logged');

        self::assertFalse(TableRegistry::exists(self::ACTIVITIES), 'exists() still reads an unknown as false');

        // Healthy again: the next call asks rather than replaying a memo.
        $this->answers([self::ACTIVITIES => 'present']);
        self::assertTrue(TableRegistry::existsOrUnknown(self::ACTIVITIES));
        self::assertSame(
            [self::ACTIVITIES, self::ACTIVITIES, self::ACTIVITIES],
            $this->db->probes,
            'every call after a failure probed again'
        );
    }

    // ── hiddenReviewExclusion ─────────────────────────────────────────────

    /** @return iterable<string, array{string, string, self::STALE_*|null, bool}> */
    public static function exclusionCases(): iterable
    {
        // activities, hidden, stale rows, exclusion expected
        yield 'both present'                       => ['present', 'present', null, true];
        yield 'activities confirmed missing'       => ['missing', 'present', null, false];
        yield 'hidden table confirmed missing'     => ['present', 'missing', null, false];
        yield 'activities missing, hidden unknown' => ['missing', 'error', null, false];
        yield 'activities unknown, hidden missing' => ['error', 'missing', null, false];
        yield 'activities: driver error'           => ['error', 'present', null, true];
        yield 'activities: pre-flush bail'         => ['bail', 'present', self::STALE_UNRELATED, true];
        yield 'hidden: driver error'               => ['present', 'error', null, true];
        yield 'hidden: pre-flush bail'             => ['present', 'bail', self::STALE_UNRELATED, true];
        yield 'both unknown'                       => ['error', 'bail', null, true];
    }

    /** @param self::STALE_*|null $stale */
    #[DataProvider('exclusionCases')]
    public function testOnlyAConfirmedMissingTableDropsTheExclusion(string $activities, string $hidden, ?string $stale, bool $excludes): void
    {
        $this->answers([self::ACTIVITIES => $activities, self::HIDDEN => $hidden], $stale);

        $sql = self::exclusion();

        if ($excludes) {
            self::assertStringContainsString('NOT EXISTS', $sql);
            self::assertStringContainsString('FROM ' . self::HIDDEN . ' h', $sql);
            self::assertStringContainsString('= v.id', $sql, 'bound to the caller\'s alias');
        } else {
            self::assertSame('1 = 1', $sql);
        }
    }
}
