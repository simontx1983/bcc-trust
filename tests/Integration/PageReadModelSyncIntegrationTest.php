<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\PageReadModelRepository;
use BCC\Trust\Core\Services\PageReadModelSync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The page read model must copy a page's REAL score, and must never replace
 * it with neutral defaults because a score read failed.
 *
 * ── THE DEFECT THIS PINS ────────────────────────────────────────────────
 * `syncPage()` read the score row with `FOR SHARE`, a MySQL-8-only clause.
 * Production runs MariaDB 11.8, which rejects it (errno 1064). `get_row()`
 * then returned null — the same value it returns for "this page has no score
 * row" — so the sync upserted neutral defaults (score 50, tier `neutral`,
 * zero votes) and COMMITTED. Every page, every day: 5,688 SQL errors per run.
 *
 * It hid for months because this harness ran only against MySQL 8, which
 * accepts the clause. That is why every test here is tagged `mariadb`: the CI
 * job for that engine runs exactly this group.
 *
 * ── WHAT IS PROVEN, AND AGAINST WHAT ────────────────────────────────────
 * Against a REAL server on BOTH engines:
 *   - a real score, a category fallback and a genuine "no score row" each
 *     produce the right row, byte for byte;
 *   - a failed score read throws, sends NO read-model write (proved at the
 *     statement level, not merely by rollback), and leaves an existing row
 *     and the source scores untouched;
 *   - a batch keeps going past a broken page, counts honestly, and logs once;
 *   - the dirty queue keeps a page whose sync failed;
 *   - the locking clause really takes a shared lock (a held exclusive lock
 *     makes the read fail with a GENUINE lock-wait timeout, no injection).
 *
 * ── HARNESS PARITY ──────────────────────────────────────────────────────
 * WordPress removes strict SQL modes from every connection
 * (`wpdb::set_sql_mode()`), and production runs non-strict. MySQL 8's default
 * session IS strict, which would make an upsert of an empty DATETIME fail here
 * and succeed in production. This class applies WordPress's own strip for its
 * lifetime and restores the session afterwards.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(PageReadModelRepository::class)]
#[CoversClass(PageReadModelSync::class)]
final class PageReadModelSyncIntegrationTest extends TestCase
{
    /** Page with an aggregate (category 0) score row. */
    private const PAGE_AGGREGATE = 9101;

    /** Page with ONLY category-specific score rows. */
    private const PAGE_CATEGORY_ONLY = 9102;

    /** Published page with no score row at all. */
    private const PAGE_UNSCORED = 9103;

    private const OWNER_AGGREGATE = 501;
    private const OWNER_CATEGORY  = 502;
    private const OWNER_UNSCORED  = 503;

    /** Verbatim from `wpdb::$incompatible_modes` (WordPress 7.0.2). */
    private const WP_INCOMPATIBLE_SQL_MODES = [
        'NO_ZERO_DATE', 'ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES', 'TRADITIONAL', 'ANSI',
    ];

    /** Columns compared byte-for-byte (everything except the ON UPDATE clock). */
    private const ROW_COLUMNS = 'page_id, owner_id, trust_score, reputation_tier, confidence_score, positive_score, '
        . 'negative_score, onchain_bonus, attestation_bonus, vote_count, unique_voters, endorsement_count, '
        . 'follower_count, page_type, is_verified, has_verified_claim, github_username, github_followers, '
        . 'x_username, x_followers, has_wallet, last_vote_at, last_endorsement_at';

    private static string $originalSqlMode = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once dirname(__DIR__) . '/Stubs/page-read-model-sync-stubs.php';

        $wpdb = $GLOBALS['wpdb'];
        $root = dirname(__DIR__, 2);

        $installers = [
            [TableRegistry::pageReadModel(), $root . '/includes/database/schema-project.php', 'bcc_trust_create_page_tables'],
            [TableRegistry::userVerifications(), $root . '/includes/database/schema-user-verifications.php', 'bcc_trust_create_user_verifications_table'],
            [TableRegistry::trustAttestations(), $root . '/includes/database/schema-trust-attestations.php', 'bcc_trust_create_trust_attestations_table'],
        ];
        foreach ($installers as [$table, $file, $installer]) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                require_once $file;
                $installer();
            }
            self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)), "{$table} must exist");
        }

        self::$originalSqlMode = (string) $wpdb->get_var('SELECT @@SESSION.sql_mode');
        $wordpressModes = array_values(array_filter(
            explode(',', self::$originalSqlMode),
            static fn (string $mode): bool => $mode !== '' && !in_array(strtoupper($mode), self::WP_INCOMPATIBLE_SQL_MODES, true)
        ));
        $wpdb->query("SET SESSION sql_mode = '" . implode(',', $wordpressModes) . "'");
    }

    public static function tearDownAfterClass(): void
    {
        $GLOBALS['wpdb']->query("SET SESSION sql_mode = '" . self::$originalSqlMode . "'");

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        \BCC\Core\Log\Logger::reset();

        foreach ([
            TableRegistry::pageReadModel(),
            TableRegistry::scores(),
            TableRegistry::dirtyQueue(),
            TableRegistry::userInfo(),
            TableRegistry::userVerifications(),
            TableRegistry::trustAttestations(),
        ] as $table) {
            $wpdb->query("TRUNCATE TABLE `{$table}`");
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->posts}` WHERE ID IN (%d, %d, %d)",
            self::PAGE_AGGREGATE,
            self::PAGE_CATEGORY_ONLY,
            self::PAGE_UNSCORED
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->postmeta}` WHERE post_id IN (%d, %d, %d)",
            self::PAGE_AGGREGATE,
            self::PAGE_CATEGORY_ONLY,
            self::PAGE_UNSCORED
        ));

        $GLOBALS['__bcc_test_object_cache'] = [];
        $GLOBALS['__bcc_test_transients']   = [];
        $GLOBALS['__bcc_rm_test_post_authors'] = [self::PAGE_UNSCORED => self::OWNER_UNSCORED];
        $GLOBALS['__bcc_rm_test_user_meta']    = [
            self::OWNER_AGGREGATE => ['peepso_followers_count' => '33'],
            self::OWNER_UNSCORED  => ['peepso_followers_count' => '11'],
        ];

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();

        parent::tearDown();
    }

    // ── 1–3: the three legitimate outcomes ─────────────────────────────────

    public function testAggregateScoreIsCopiedByteForByte(): void
    {
        $this->repo()->syncPage(self::PAGE_AGGREGATE);

        self::assertSame([
            'page_id'             => '9101',
            'owner_id'            => '501',
            'trust_score'         => '61.25',
            'reputation_tier'     => 'trusted',
            'confidence_score'    => '0.73',
            'positive_score'      => '12.50',
            'negative_score'      => '1.25',
            'onchain_bonus'       => '3.00',
            'attestation_bonus'   => '4.50',
            'vote_count'          => '7',
            'unique_voters'       => '5',
            'endorsement_count'   => '2',
            'follower_count'      => '33',
            'page_type'           => 'validator',
            'is_verified'         => '1',
            'has_verified_claim'  => '0',
            'github_username'     => 'gh-owner-501',
            'github_followers'    => '42',
            'x_username'          => 'x-owner-501',
            'x_followers'         => '17',
            'has_wallet'          => '0',
            'last_vote_at'        => '2026-09-01 10:00:00',
            'last_endorsement_at' => '2026-09-02 11:00:00',
        ], $this->readModelRow(self::PAGE_AGGREGATE), 'the aggregate (category 0) score must be copied exactly — not neutral defaults, not a category row');
    }

    public function testCategoryOnlyScoreUsesTheHighestVotedCategory(): void
    {
        $this->repo()->syncPage(self::PAGE_CATEGORY_ONLY);

        $row = $this->readModelRow(self::PAGE_CATEGORY_ONLY);
        self::assertNotNull($row, 'a category-only page must still get a read-model row');
        self::assertSame(
            ['9102', '502', '58.40', 'emerging', '0.41', '9', '4', '2026-08-30 09:15:00'],
            [$row['page_id'], $row['owner_id'], $row['trust_score'], $row['reputation_tier'], $row['confidence_score'], $row['vote_count'], $row['unique_voters'], $row['last_vote_at']],
            'the fallback must copy the category row with the MOST votes (category 4), not the first, the lowest, or the zero-vote one'
        );
    }

    public function testPageWithNoScoreRowGetsNeutralDefaults(): void
    {
        $this->repo()->syncPage(self::PAGE_UNSCORED);

        self::assertSame([
            'page_id'             => '9103',
            'owner_id'            => '503',
            'trust_score'         => '50.00',
            'reputation_tier'     => 'neutral',
            'confidence_score'    => '0.00',
            'positive_score'      => '0.00',
            'negative_score'      => '0.00',
            'onchain_bonus'       => '0.00',
            'attestation_bonus'   => '0.00',
            'vote_count'          => '0',
            'unique_voters'       => '0',
            'endorsement_count'   => '0',
            'follower_count'      => '11',
            'page_type'           => 'builder',
            'is_verified'         => '0',
            'has_verified_claim'  => '0',
            'github_username'     => '',
            'github_followers'    => '0',
            'x_username'          => '',
            'x_followers'         => '0',
            'has_wallet'          => '0',
            // WordPress's prepare() turns the intended NULL into '' and a
            // non-strict session stores that as the zero date. This is what
            // production writes today; it is pinned, not endorsed.
            'last_vote_at'        => '0000-00-00 00:00:00',
            'last_endorsement_at' => '0000-00-00 00:00:00',
        ], $this->readModelRow(self::PAGE_UNSCORED), 'a page that genuinely has no score row must still receive the neutral defaults');
    }

    // ── 4–5, 9: a failed score read writes nothing ─────────────────────────

    public function testFailedAggregateReadThrowsAndWritesNothing(): void
    {
        $sentinel = $this->plantSentinelRow(self::PAGE_AGGREGATE);
        $scores   = $this->scoresFingerprint();

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = '/' . $this->aggregateReadPattern(self::PAGE_AGGREGATE) . '|' . $this->readModelUpsertPattern() . '/';

        $failure = $this->captureFailure(fn () => $this->repo()->syncPage(self::PAGE_AGGREGATE));

        // Statement count FIRST: once the upsert result is checked, an upsert
        // attempted after an undetected read failure also throws — so "did it
        // throw?" alone can no longer tell the two defects apart.
        self::assertSame(1, $wpdb->injectedFailures, 'exactly ONE statement may fail: the read. A second means the read-model upsert was still attempted');
        self::assertInstanceOf(RepositoryException::class, $failure, 'a failed aggregate score read must throw, not fall through to neutral defaults');
        self::assertSame('aggregate_score_read_failed', $failure->getMessage(), 'the failure must carry only its bounded reason code');
        self::assertSame($sentinel, $this->fullReadModelRow(self::PAGE_AGGREGATE), 'the existing read-model row must be untouched, including updated_at');
        self::assertSame($scores, $this->scoresFingerprint(), 'the source score rows must be untouched');
    }

    public function testFailedFallbackReadThrowsAndWritesNothing(): void
    {
        $sentinel = $this->plantSentinelRow(self::PAGE_CATEGORY_ONLY);
        $scores   = $this->scoresFingerprint();

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = '/' . $this->fallbackReadPattern(self::PAGE_CATEGORY_ONLY) . '|' . $this->readModelUpsertPattern() . '/';

        $failure = $this->captureFailure(fn () => $this->repo()->syncPage(self::PAGE_CATEGORY_ONLY));

        self::assertSame(1, $wpdb->injectedFailures, 'exactly ONE statement may fail: the fallback read. A second means the upsert was still attempted');
        self::assertInstanceOf(RepositoryException::class, $failure, 'a failed fallback score read must throw, not fall through to neutral defaults');
        self::assertSame('fallback_score_read_failed', $failure->getMessage(), 'the failure must carry only its bounded reason code');
        self::assertSame($sentinel, $this->fullReadModelRow(self::PAGE_CATEGORY_ONLY), 'the existing read-model row must be untouched, including updated_at');
        self::assertSame($scores, $this->scoresFingerprint(), 'the source score rows must be untouched');
    }

    public function testSingleSyncFailureLogsOnlyTheReasonCode(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/' . $this->aggregateReadPattern(self::PAGE_AGGREGATE) . '/';

        $this->captureFailure(fn () => $this->repo()->syncPage(self::PAGE_AGGREGATE));

        $errors = $this->logLines('error');
        self::assertCount(1, $errors, 'a single-page sync failure must log exactly once');
        self::assertSame('aggregate_score_read_failed', $errors[0]['context']['error'] ?? null, 'the logged error must be the bounded reason code, never SQL or the driver message');
    }

    public function testAnEarlierFailedQueryDoesNotFailALaterSync(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->get_row('SELECT no_such_column FROM `' . TableRegistry::scores() . '`');
        self::assertNotSame('', $wpdb->last_error, 'precondition: the connection must be carrying a stale error');

        $this->repo()->syncPage(self::PAGE_AGGREGATE);

        self::assertSame('61.25', $this->readModelRow(self::PAGE_AGGREGATE)['trust_score'] ?? null, 'a stale error from an unrelated earlier query must not be mistaken for a failed score read');
    }

    // ── 6–7: batch resilience and honest counting ──────────────────────────

    public function testOneFailingPageDoesNotStopLaterPages(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/' . $this->aggregateReadPattern(self::PAGE_AGGREGATE) . '/';

        $this->repo()->syncAll();

        self::assertNull($this->readModelRow(self::PAGE_AGGREGATE), 'the broken page must not receive a row');
        self::assertSame('58.40', $this->readModelRow(self::PAGE_CATEGORY_ONLY)['trust_score'] ?? null, 'a page AFTER the broken one must still sync');
        self::assertSame('50.00', $this->readModelRow(self::PAGE_UNSCORED)['trust_score'] ?? null, 'the last page must still sync');
    }

    public function testBatchCountsSuccessesAndFailuresAndLogsOnce(): void
    {
        $scores = $this->scoresFingerprint();
        $GLOBALS['wpdb']->failQueriesMatching = '/' . $this->aggregateReadPattern(self::PAGE_AGGREGATE) . '/';

        $synced = $this->repo()->syncAll();

        self::assertSame(2, $synced, 'syncAll() must count only pages that actually synced');
        self::assertSame([], $this->logLines('info'), 'a run with failures must not also log an info summary');
        $errors = $this->logLines('error');
        self::assertCount(1, $errors, 'one bounded summary per run — never one line per page');
        self::assertSame(
            ['pages_synced' => 2, 'pages_failed' => 1, 'failure_reasons' => ['aggregate_score_read_failed' => 1]],
            $errors[0]['context'],
            'the summary may carry only numeric counts and bounded reason codes'
        );
        self::assertSame($scores, $this->scoresFingerprint(), 'the source score rows must be untouched');
    }

    public function testCleanBatchLogsOneInfoSummary(): void
    {
        self::assertSame(3, $this->repo()->syncAll(), 'all three published pages must sync');

        self::assertSame([], $this->logLines('error'), 'a clean run must not log an error');
        $infos = array_values(array_filter(
            $this->logLines('info'),
            static fn (array $line): bool => str_contains($line['message'], 'read model full sync')
        ));
        self::assertCount(1, $infos, 'one summary per run');
        self::assertSame(['pages_synced' => 3, 'pages_failed' => 0, 'failure_reasons' => []], $infos[0]['context']);
    }

    public function testEveryPageFailingReportsNoDataInsteadOfSuccess(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/FROM wp_bcc_trust_page_scores\s+WHERE page_id = \d+ AND category_id = 0\b/';

        self::assertSame(0, $this->repo()->syncAll(), 'no page synced, so none may be reported');
        self::assertSame(0, wp_cache_get('rm_has_data', 'bcc_page_rm'), 'a run where nothing synced must not flag the read model as populated');
        self::assertSame(
            ['pages_synced' => 0, 'pages_failed' => 3, 'failure_reasons' => ['aggregate_score_read_failed' => 3]],
            $this->logLines('error')[0]['context'] ?? null
        );
    }

    public function testFailedPageListReadIsReportedNotTreatedAsAnEmptySite(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = "/SELECT ID FROM wp_posts\\s+WHERE post_type = 'peepso-page'/";

        self::assertSame(0, $this->repo()->syncAll());
        self::assertSame(
            ['pages_synced' => 0, 'pages_failed' => 0, 'failure_reasons' => ['page_list_read_failed' => 1]],
            $this->logLines('error')[0]['context'] ?? null,
            'a page list that could not be read must be reported as a failure, not as a clean run over zero pages'
        );
    }

    // ── 8: the dirty queue ─────────────────────────────────────────────────

    public function testDirtyQueueSyncsAndClearsAHealthyPage(): void
    {
        PageReadModelRepository::enqueueDirty(self::PAGE_AGGREGATE);

        PageReadModelSync::processDirtyPages();

        self::assertSame(0, $this->dirtyRowCount(self::PAGE_AGGREGATE), 'healthy twin: a page that synced must leave the queue');
        self::assertSame('61.25', $this->readModelRow(self::PAGE_AGGREGATE)['trust_score'] ?? null);
    }

    public function testDirtyQueueKeepsAPageWhoseSyncFailed(): void
    {
        PageReadModelRepository::enqueueDirty(self::PAGE_AGGREGATE);
        $GLOBALS['wpdb']->failQueriesMatching = '/' . $this->aggregateReadPattern(self::PAGE_AGGREGATE) . '/';

        PageReadModelSync::processDirtyPages();

        self::assertSame(1, $this->dirtyRowCount(self::PAGE_AGGREGATE), 'a page whose sync failed must stay queued for retry');
        self::assertSame(1, get_transient('bcc_sync_fail_' . self::PAGE_AGGREGATE), 'the failure must be counted toward quarantine');
        self::assertNull($this->readModelRow(self::PAGE_AGGREGATE), 'no read-model row may be written for the failed page');
    }

    public function testRepeatedDirtyQueueFailuresQuarantineInsteadOfDropping(): void
    {
        PageReadModelRepository::enqueueDirty(self::PAGE_AGGREGATE);
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = '/' . $this->aggregateReadPattern(self::PAGE_AGGREGATE) . '/';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            PageReadModelSync::processDirtyPages();
        }

        self::assertSame(1, $this->dirtyRowCount(self::PAGE_AGGREGATE), 'after five failures the page must still be queued');
        self::assertSame('1', (string) $wpdb->get_var($wpdb->prepare(
            'SELECT created_at > NOW(6) FROM `' . TableRegistry::dirtyQueue() . '` WHERE page_id = %d',
            self::PAGE_AGGREGATE
        )), 'the fifth failure must push the page into a future quarantine slot, not delete it');
        self::assertSame(1, get_transient('bcc_sync_quar_' . self::PAGE_AGGREGATE), 'the quarantine must be recorded');
    }

    // ── 10: idempotence ────────────────────────────────────────────────────

    public function testResyncingIsIdempotent(): void
    {
        $scores = $this->scoresFingerprint();

        $this->repo()->syncPage(self::PAGE_AGGREGATE);
        $first = $this->readModelRow(self::PAGE_AGGREGATE);
        $this->repo()->syncPage(self::PAGE_AGGREGATE);

        self::assertSame($first, $this->readModelRow(self::PAGE_AGGREGATE), 'a second sync must reproduce the same row');
        self::assertSame(1, (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM `' . TableRegistry::pageReadModel() . '`'), 'upsert, not duplicate');
        self::assertSame($scores, $this->scoresFingerprint(), 'syncing must never write the source scores');
    }

    // ── a failed WRITE is a failed sync ────────────────────────────────────
    //
    // Before this was checked, a read-model upsert that failed after a good
    // score read was invisible: syncPage() returned normally, syncAll() counted
    // the page as synced, and the dirty queue deleted the entry — measured on
    // both engines, for an injected failure and for a genuine lock-wait
    // timeout alike. The stale row then stayed stale with nothing left to
    // retry it.

    public function testFailedUpsertThrowsAndLeavesTheExistingRowUntouched(): void
    {
        $sentinel = $this->plantSentinelRow(self::PAGE_AGGREGATE);
        $scores   = $this->scoresFingerprint();

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = '/' . $this->readModelUpsertPattern() . '/';

        $failure = $this->captureFailure(fn () => $this->repo()->syncPage(self::PAGE_AGGREGATE));

        self::assertSame(1, $wpdb->injectedFailures, 'precondition: exactly the upsert must have failed');
        self::assertInstanceOf(RepositoryException::class, $failure, 'a failed read-model upsert must throw, not report the page as synced');
        self::assertSame('read_model_upsert_failed', $failure->getMessage(), 'the failure must carry only its bounded reason code — never the driver error');
        self::assertSame($sentinel, $this->fullReadModelRow(self::PAGE_AGGREGATE), 'the existing read-model row must be untouched, including updated_at');
        self::assertSame($scores, $this->scoresFingerprint(), 'the source score rows must be untouched');

        $errors = $this->logLines('error');
        self::assertCount(1, $errors, 'a single-page upsert failure must log exactly once');
        self::assertSame('read_model_upsert_failed', $errors[0]['context']['error'] ?? null, 'the logged error must be the bounded reason code');
    }

    public function testUpsertBlockedByARealRowLockFailsClosed(): void
    {
        $sentinel = $this->plantSentinelRow(self::PAGE_AGGREGATE);

        $failure = $this->whileRowIsExclusivelyLocked(
            TableRegistry::pageReadModel(),
            'page_id = ' . self::PAGE_AGGREGATE,
            fn () => $this->captureFailure(fn () => $this->repo()->syncPage(self::PAGE_AGGREGATE))
        );

        self::assertInstanceOf(RepositoryException::class, $failure, 'an upsert that genuinely fails on the engine (lock-wait timeout, no injection) must throw');
        self::assertSame('read_model_upsert_failed', $failure->getMessage());
        self::assertSame($sentinel, $this->fullReadModelRow(self::PAGE_AGGREGATE), 'the stale row must stay exactly as it was');

        $this->repo()->syncPage(self::PAGE_AGGREGATE);
        self::assertSame('61.25', $this->readModelRow(self::PAGE_AGGREGATE)['trust_score'] ?? null, 'once the lock is released the same sync must succeed');
    }

    public function testBatchCountsAFailedUpsertAsAFailure(): void
    {
        $scores = $this->scoresFingerprint();
        $GLOBALS['wpdb']->failQueriesMatching = '/' . $this->readModelUpsertPattern() . '.*VALUES \(' . self::PAGE_AGGREGATE . ',/s';

        $synced = $this->repo()->syncAll();

        self::assertSame(2, $synced, 'a page whose upsert failed must not be counted as synced');
        self::assertNull($this->readModelRow(self::PAGE_AGGREGATE), 'the page whose upsert failed must have no row');
        self::assertSame('58.40', $this->readModelRow(self::PAGE_CATEGORY_ONLY)['trust_score'] ?? null, 'a later page must still sync');
        self::assertSame('50.00', $this->readModelRow(self::PAGE_UNSCORED)['trust_score'] ?? null, 'the last page must still sync');

        self::assertSame([], $this->logLines('info'), 'a run with a failed upsert must not log an info summary');
        $errors = $this->logLines('error');
        self::assertCount(1, $errors, 'one bounded summary per run');
        self::assertSame(
            ['pages_synced' => 2, 'pages_failed' => 1, 'failure_reasons' => ['read_model_upsert_failed' => 1]],
            $errors[0]['context'],
            'the summary must attribute the failure to its own bounded reason code'
        );
        self::assertSame($scores, $this->scoresFingerprint(), 'the source score rows must be untouched');
    }

    public function testDirtyQueueKeepsAPageWhoseUpsertFailed(): void
    {
        PageReadModelRepository::enqueueDirty(self::PAGE_AGGREGATE);
        $GLOBALS['wpdb']->failQueriesMatching = '/' . $this->readModelUpsertPattern() . '/';

        PageReadModelSync::processDirtyPages();

        self::assertSame(1, $this->dirtyRowCount(self::PAGE_AGGREGATE), 'a page whose upsert failed must stay queued for retry');
        self::assertSame(1, get_transient('bcc_sync_fail_' . self::PAGE_AGGREGATE), 'the upsert failure must be counted toward quarantine');
        self::assertNull($this->readModelRow(self::PAGE_AGGREGATE), 'no read-model row may exist for the failed page');
    }

    public function testUnchangedResyncIsNotMistakenForAFailedUpsert(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        // Pin the session clock so NOW() cannot move between the two syncs:
        // the second upsert then writes identical values, which the engine
        // reports as ZERO affected rows — a success that is falsy in PHP.
        $wpdb->query('SET SESSION timestamp = 1789000000');
        try {
            $this->repo()->syncPage(self::PAGE_AGGREGATE);
            $first = $this->fullReadModelRow(self::PAGE_AGGREGATE);

            // Engine precondition, on this exact row: an ON DUPLICATE KEY
            // UPDATE that changes nothing returns 0, not false.
            $noChange = $wpdb->query($wpdb->prepare(
                'INSERT INTO `' . TableRegistry::pageReadModel() . '` (page_id, trust_score) VALUES (%d, %s)
                 ON DUPLICATE KEY UPDATE trust_score = VALUES(trust_score)',
                self::PAGE_AGGREGATE,
                (string) ($first['trust_score'] ?? '')
            ));
            self::assertSame(0, $noChange, 'precondition: an unchanged upsert must report 0 affected rows on this engine');

            $failure = $this->captureFailure(fn () => $this->repo()->syncPage(self::PAGE_AGGREGATE));
        } finally {
            $wpdb->query('SET SESSION timestamp = DEFAULT');
        }

        self::assertNull($failure, 'an upsert that changed nothing (0 affected rows) is a success, not a failure');
        self::assertSame($first, $this->fullReadModelRow(self::PAGE_AGGREGATE), 'the row must be identical, updated_at included');
    }

    // ── 11: the lock is real on this engine ────────────────────────────────

    public function testAggregateReadTakesARealSharedLock(): void
    {
        $sentinel = $this->plantSentinelRow(self::PAGE_AGGREGATE);

        $failure = $this->whileRowIsExclusivelyLocked(TableRegistry::scores(), 'page_id = ' . self::PAGE_AGGREGATE . ' AND category_id = 0', fn () => $this->captureFailure(
            fn () => $this->repo()->syncPage(self::PAGE_AGGREGATE)
        ));

        self::assertInstanceOf(RepositoryException::class, $failure, 'the aggregate read must WAIT on an exclusively locked score row (a non-locking read would not) and fail closed when the wait times out');
        self::assertSame('aggregate_score_read_failed', $failure->getMessage());
        self::assertSame($sentinel, $this->fullReadModelRow(self::PAGE_AGGREGATE), 'a genuine engine error must write nothing either');

        $this->repo()->syncPage(self::PAGE_AGGREGATE);
        self::assertSame('61.25', $this->readModelRow(self::PAGE_AGGREGATE)['trust_score'] ?? null, 'once the lock is released the same sync must succeed');
    }

    public function testFallbackReadTakesARealSharedLock(): void
    {
        $failure = $this->whileRowIsExclusivelyLocked(TableRegistry::scores(), 'page_id = ' . self::PAGE_CATEGORY_ONLY . ' AND category_id = 4', fn () => $this->captureFailure(
            fn () => $this->repo()->syncPage(self::PAGE_CATEGORY_ONLY)
        ));

        self::assertInstanceOf(RepositoryException::class, $failure, 'the fallback read must wait on an exclusively locked category row and fail closed on timeout');
        self::assertSame('fallback_score_read_failed', $failure->getMessage(), 'the aggregate miss must not block; only the fallback read may');
        self::assertNull($this->readModelRow(self::PAGE_CATEGORY_ONLY), 'nothing may be written');
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    private function seedFixtures(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        foreach ([self::PAGE_AGGREGATE, self::PAGE_CATEGORY_ONLY, self::PAGE_UNSCORED] as $pageId) {
            $wpdb->insert($wpdb->posts, ['ID' => $pageId, 'post_type' => 'peepso-page', 'post_status' => 'publish']);
        }
        $wpdb->insert($wpdb->postmeta, ['post_id' => self::PAGE_AGGREGATE, 'meta_key' => '_bcc_page_type', 'meta_value' => 'validator']);

        $scores = TableRegistry::scores();
        $this->seedScore(self::PAGE_AGGREGATE, 0, self::OWNER_AGGREGATE, '61.25', '12.50', '1.25', '3.00', '4.50', 7, 5, '0.73', 'trusted', 2, '2026-09-01 10:00:00');
        // A category row on the SAME page with different numbers: proves the
        // aggregate row wins when it exists.
        $this->seedScore(self::PAGE_AGGREGATE, 1, self::OWNER_AGGREGATE, '70.00', '20.00', '0.00', '0.00', '0.00', 30, 20, '0.90', 'elite', 9, '2026-09-03 12:00:00');

        $this->seedScore(self::PAGE_CATEGORY_ONLY, 3, self::OWNER_CATEGORY, '55.00', '5.00', '0.00', '0.00', '0.00', 2, 2, '0.20', 'neutral', 0, '2026-08-29 08:00:00');
        $this->seedScore(self::PAGE_CATEGORY_ONLY, 4, self::OWNER_CATEGORY, '58.40', '8.40', '0.00', '0.00', '0.00', 9, 4, '0.41', 'emerging', 0, '2026-08-30 09:15:00');
        $this->seedScore(self::PAGE_CATEGORY_ONLY, 5, self::OWNER_CATEGORY, '72.00', '22.00', '0.00', '0.00', '0.00', 0, 0, '0.00', 'trusted', 0, '2026-08-31 07:30:00');
        self::assertSame(5, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$scores}`"), 'fixture: five score rows');

        $wpdb->insert(TableRegistry::userInfo(), [
            'user_id' => self::OWNER_AGGREGATE, 'user_login' => 'owner501', 'user_email' => 'owner501@example.test',
            'display_name' => 'Owner 501', 'is_verified' => 1,
        ]);
        $verifications = TableRegistry::userVerifications();
        $wpdb->insert($verifications, [
            'user_id' => self::OWNER_AGGREGATE, 'type' => 'github', 'provider_id' => 'gh-501',
            'provider_username' => 'gh-owner-501', 'meta' => '{"followers":42}', 'status' => 'active',
        ]);
        $wpdb->insert($verifications, [
            'user_id' => self::OWNER_AGGREGATE, 'type' => 'x', 'provider_id' => 'x-501',
            'provider_username' => 'x-owner-501', 'meta' => '{"followers":17}', 'status' => 'active',
        ]);
        $wpdb->insert(TableRegistry::trustAttestations(), [
            'attestor_user_id' => 777, 'target_kind' => 'validator_card', 'target_id' => self::PAGE_AGGREGATE,
            'kind' => 'vouch', 'created_at' => '2026-09-02 11:00:00',
        ]);
    }

    private function seedScore(
        int $pageId,
        int $categoryId,
        int $ownerId,
        string $total,
        string $positive,
        string $negative,
        string $onchain,
        string $attestation,
        int $votes,
        int $voters,
        string $confidence,
        string $tier,
        int $endorsements,
        string $lastVoteAt
    ): void {
        $wpdb = $GLOBALS['wpdb'];
        $ok   = $wpdb->insert(TableRegistry::scores(), [
            'page_id' => $pageId, 'category_id' => $categoryId, 'page_owner_id' => $ownerId,
            'total_score' => $total, 'positive_score' => $positive, 'negative_score' => $negative,
            'onchain_bonus' => $onchain, 'attestation_bonus' => $attestation, 'vote_count' => $votes,
            'unique_voters' => $voters, 'confidence_score' => $confidence, 'reputation_tier' => $tier,
            'endorsement_count' => $endorsements, 'last_vote_at' => $lastVoteAt,
            'last_calculated_at' => '2026-09-01 00:00:00',
        ]);
        self::assertNotFalse($ok, "fixture score row {$pageId}/{$categoryId} must insert: {$wpdb->last_error}");
    }

    /**
     * An existing read-model row with values no sync could produce, so ANY
     * write — including a same-value upsert — would show in updated_at.
     *
     * @return array<string, string|null>
     */
    private function plantSentinelRow(int $pageId): array
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->insert(TableRegistry::pageReadModel(), [
            'page_id' => $pageId, 'owner_id' => 999, 'trust_score' => '77.77', 'reputation_tier' => 'sentinel',
            'vote_count' => 99, 'updated_at' => '2026-01-01 00:00:00',
        ]);

        $row = $this->fullReadModelRow($pageId);
        self::assertNotNull($row, 'fixture: sentinel row must exist');
        self::assertSame('2026-01-01 00:00:00', $row['updated_at']);

        return $row;
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function repo(): PageReadModelRepository
    {
        return \BCC\Trust\Core\Plugin::instance()->pageReadModelRepository();
    }

    private function aggregateReadPattern(int $pageId): string
    {
        return 'FROM wp_bcc_trust_page_scores\s+WHERE page_id = ' . $pageId . ' AND category_id = 0\b';
    }

    private function fallbackReadPattern(int $pageId): string
    {
        return 'FROM wp_bcc_trust_page_scores\s+WHERE page_id = ' . $pageId . ' AND vote_count > 0\b';
    }

    private function readModelUpsertPattern(): string
    {
        return 'INSERT INTO wp_bcc_page_read_model\b';
    }

    /** @return array<string, string|null>|null */
    private function readModelRow(int $pageId): ?array
    {
        $wpdb = $GLOBALS['wpdb'];
        $row  = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::ROW_COLUMNS . ' FROM `' . TableRegistry::pageReadModel() . '` WHERE page_id = %d',
            $pageId
        ));

        return $row === null ? null : (array) $row;
    }

    /** @return array<string, string|null>|null */
    private function fullReadModelRow(int $pageId): ?array
    {
        $wpdb = $GLOBALS['wpdb'];
        $row  = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM `' . TableRegistry::pageReadModel() . '` WHERE page_id = %d',
            $pageId
        ));

        return $row === null ? null : (array) $row;
    }

    private function scoresFingerprint(): string
    {
        $rows = $GLOBALS['wpdb']->get_results(
            'SELECT * FROM `' . TableRegistry::scores() . '` ORDER BY page_id, category_id'
        );
        self::assertCount(5, $rows, 'fingerprint must cover all five fixture score rows');

        return md5((string) json_encode($rows));
    }

    private function dirtyRowCount(int $pageId): int
    {
        $wpdb = $GLOBALS['wpdb'];

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM `' . TableRegistry::dirtyQueue() . '` WHERE page_id = %d',
            $pageId
        ));
    }

    private function captureFailure(callable $action): ?\Throwable
    {
        try {
            $action();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    private function logLines(string $level): array
    {
        return array_values(array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn (array $line): bool => $line['level'] === $level
        ));
    }

    /**
     * Hold an exclusive lock on one existing row from a SECOND connection while
     * $action runs on the harness connection with a 1-second lock wait.
     *
     * @template T
     * @param  string        $table   fully-prefixed table name
     * @param  string        $rowWhere a condition matching exactly one row (test constants only)
     * @param  callable(): T $action
     * @return T
     */
    private function whileRowIsExclusivelyLocked(string $table, string $rowWhere, callable $action)
    {
        $wpdb   = $GLOBALS['wpdb'];
        $holder = mysqli_connect(
            getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1',
            getenv('BCC_TEST_DB_USER') ?: 'root',
            getenv('BCC_TEST_DB_PASS') ?: 'root',
            getenv('BCC_TEST_DB_NAME') ?: 'bcc_test',
            (int) (getenv('BCC_TEST_DB_PORT') ?: 10005)
        );
        self::assertInstanceOf(\mysqli::class, $holder, 'a second connection is required to hold the lock');

        $originalWait = (string) $wpdb->get_var('SELECT @@SESSION.innodb_lock_wait_timeout');

        self::assertTrue($holder->query('START TRANSACTION'));
        $locked = $holder->query(sprintf('SELECT page_id FROM `%s` WHERE %s FOR UPDATE', $table, $rowWhere));
        self::assertInstanceOf(\mysqli_result::class, $locked, 'the holder must acquire the exclusive row lock');
        self::assertSame(1, $locked->num_rows, 'the holder must actually lock an existing row');

        $wpdb->query('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            return $action();
        } finally {
            $wpdb->query('SET SESSION innodb_lock_wait_timeout = ' . (int) $originalWait);
            $holder->query('ROLLBACK');
            $holder->close();
        }
    }
}
