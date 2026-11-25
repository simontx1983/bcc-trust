<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Application\TrustReadService;
use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-598: dispute creation runs inside a TransactionManager transaction, so
 * the vote lock it takes is held by that transaction.
 *
 * DisputeRepository::createDispute() is driven against the real engine,
 * with the REAL TrustReadService → VoteRepository::lockActiveForDispute()
 * behind it. That lock requires TransactionManager::run(), so a create
 * that reaches it outside one throws instead of creating anything. The
 * first case is therefore the whole point: a dispute IS created.
 *
 * Every refusal is covered on the real engine too, each asserting that
 * nothing was written and that no transaction is left open.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(DisputeRepository::class)]
final class DisputeCreateTransactionIntegrationTest extends TestCase
{
    private const REPORTER = 995981;
    private const VOTER    = 995982;
    private const PAGE     = 995983;
    private const OTHER    = 995984; // another reporter / page, for limit fixtures

    private const LOCATOR_KEY = 'bcc.resolve.trust_read_service';

    /** @var array<string, mixed> */
    private array $locatorBefore = [];

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        // The installer reads DB_NAME (wp-config defines it; the harness does not).
        if (!defined('DB_NAME')) {
            define('DB_NAME', (string) $wpdb->get_var('SELECT DATABASE()'));
        }
        // bcc-trust.php defines these; the harness does not load it. Same values.
        if (!defined('BCC_DISPUTES_MAX_PER_PAGE')) {
            define('BCC_DISPUTES_MAX_PER_PAGE', 3);
        }
        if (!defined('BCC_DISPUTES_REPORTER_MAX_ACTIVE')) {
            define('BCC_DISPUTES_REPORTER_MAX_ACTIVE', 5);
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', DisputeRepository::disputes_table())) !== DisputeRepository::disputes_table()) {
            DisputeRepository::install();
        }
        $GLOBALS['__bcc_test_object_cache'] = [];
        $this->purge();

        // The REAL trust read service, where the harness's pass-through
        // apply_filters would otherwise resolve bcc-core's null service
        // (whose lock always refuses, so nothing could ever be created).
        $cache = new \ReflectionProperty(\BCC\Core\ServiceLocator::class, 'cache');
        $this->locatorBefore = (array) $cache->getValue();
        $resolved = $this->locatorBefore;
        $resolved[self::LOCATOR_KEY] = new TrustReadService(new VoteRepository());
        $cache->setValue(null, $resolved);
    }

    protected function tearDown(): void
    {
        if (method_exists($GLOBALS['wpdb'], 'clearFaultInjection')) {
            $GLOBALS['wpdb']->clearFaultInjection();
        }
        $this->restoreTable();
        $this->purge();
        (new \ReflectionProperty(\BCC\Core\ServiceLocator::class, 'cache'))->setValue(null, $this->locatorBefore);
        $GLOBALS['__bcc_test_object_cache'] = [];
    }

    // ── created ──────────────────────────────────────────────────────────

    /** ⭐ The defect: this create used to throw at the vote lock, every time. */
    public function testADisputeIsCreatedWithTheVoteLockHeldByItsTransaction(): void
    {
        $voteId = $this->seedVote(1);

        $result = DisputeRepository::createDispute($this->disputeData($voteId));

        self::assertNull($result['db_error'], (string) $result['db_error']);
        self::assertIsInt($result['id']);
        self::assertGreaterThan(0, $result['id']);

        $row = $this->disputeRow($result['id']);
        self::assertNotNull($row, 'the dispute was committed');
        self::assertSame($voteId, (int) $row->vote_id);
        self::assertSame(self::REPORTER, (int) $row->reporter_id);
        self::assertSame('reviewing', (string) $row->status);

        $this->assertNoTransactionLeftOpen();
    }

    // ── refused at the vote lock ─────────────────────────────────────────

    public function testAVoteThatIsNoLongerActiveIsRefusedAtTheLockAndNothingIsWritten(): void
    {
        $voteId = $this->seedVote(0); // retracted

        $result = DisputeRepository::createDispute($this->disputeData($voteId));

        self::assertSame(['id' => null, 'db_error' => 'vote_no_longer_active'], $result);
        self::assertSame(0, $this->disputeCount());
        $this->assertNoTransactionLeftOpen();
    }

    // ── refused by the limit checks ──────────────────────────────────────

    public function testThePageLimitRefuses(): void
    {
        $voteId = $this->seedVote(1);
        for ($i = 1; $i <= 3; $i++) {
            $this->seedDispute(880000 + $i, self::PAGE, self::OTHER);
        }

        $result = DisputeRepository::createDispute($this->disputeData($voteId));

        self::assertSame(['id' => null, 'db_error' => 'dispute_limit_reached'], $result);
        self::assertSame(3, $this->disputeCount(), 'nothing added');
        $this->assertNoTransactionLeftOpen();
    }

    public function testTheReporterLimitRefuses(): void
    {
        $voteId = $this->seedVote(1);
        for ($i = 1; $i <= 5; $i++) {
            $this->seedDispute(881000 + $i, self::OTHER + $i, self::REPORTER);
        }

        $result = DisputeRepository::createDispute($this->disputeData($voteId));

        self::assertSame(['id' => null, 'db_error' => 'reporter_limit_reached'], $result);
        self::assertSame(5, $this->disputeCount(), 'nothing added');
        $this->assertNoTransactionLeftOpen();
    }

    public function testAnActiveDisputeOnTheVoteRefuses(): void
    {
        $voteId = $this->seedVote(1);
        $this->seedDispute($voteId, self::OTHER, self::OTHER);

        $result = DisputeRepository::createDispute($this->disputeData($voteId));

        self::assertSame(['id' => null, 'db_error' => 'already_disputed'], $result);
        self::assertSame(1, $this->disputeCount(), 'nothing added');
        $this->assertNoTransactionLeftOpen();
    }

    // ── fail-closed ──────────────────────────────────────────────────────

    /** A read inside the transaction that does not run is `tx_failed`, never "no disputes yet". */
    public function testAFailedReadIsATransactionFailureNotAPass(): void
    {
        $voteId = $this->seedVote(1);
        $table  = DisputeRepository::disputes_table();
        $GLOBALS['wpdb']->query("RENAME TABLE `{$table}` TO `{$table}_away`");

        $result = DisputeRepository::createDispute($this->disputeData($voteId));

        self::assertSame(['id' => null, 'db_error' => 'tx_failed'], $result);
        $this->assertNoTransactionLeftOpen();
        $this->restoreTable();
        self::assertSame(0, $this->disputeCount(), 'nothing written');
    }

    /** @return array<string, array{class-string<\Throwable>}> */
    public static function unexpectedErrors(): array
    {
        return [
            'a LogicException' => [\LogicException::class],
            'a TypeError'      => [\TypeError::class],
        ];
    }

    /**
     * Anything that is not a transaction or read failure is rolled back,
     * logged and answered as `create_failed`, never an escaping exception.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unexpectedErrors')]
    public function testAnUnexpectedErrorIsRolledBackAndReportedNotThrown(string $throwable): void
    {
        $voteId = $this->seedVote(1);
        $cache  = new \ReflectionProperty(\BCC\Core\ServiceLocator::class, 'cache');
        $resolved = (array) $cache->getValue();
        $resolved[self::LOCATOR_KEY] = new class ($throwable) implements \BCC\Core\Contracts\TrustReadServiceInterface {
            /** @param class-string<\Throwable> $throwable */
            public function __construct(private string $throwable)
            {
            }

            public function lockActiveVoteForDispute(int $voteId): bool
            {
                throw new ($this->throwable)('stub: unexpected failure at the vote lock');
            }

            public function getVoteById(int $voteId): ?array
            {
                return null;
            }

            public function getActiveVotesForPage(int $pageId, int $limit = 50, int $offset = 0): array
            {
                return [];
            }

            public function countActiveVotesForPage(int $pageId): int
            {
                return 0;
            }

            public function getVotesByIds(array $voteIds): array
            {
                return [];
            }

            public function isSuspended(int $userId): bool
            {
                return false;
            }
        };
        $cache->setValue(null, $resolved);

        $result = DisputeRepository::createDispute($this->disputeData($voteId));

        self::assertSame(['id' => null, 'db_error' => 'create_failed'], $result);
        self::assertSame(0, $this->disputeCount(), 'nothing written');
        $this->assertNoTransactionLeftOpen();
    }

    // ── the INSERT and the vote lock, failing ────────────────────────────

    /**
     * An INSERT that fails BEFORE the query runs (insert() refusing a value,
     * or one of wpdb's silent bails) leaves insert_id holding the last
     * SUCCESSFUL insert's id: here, the vote just seeded. The failure is
     * reported as one, never as a dispute with that id. (A driver error
     * resets insert_id to 0; the next case covers that path.)
     */
    public function testAFailedInsertIsNotReportedAsAnEarlierRowsId(): void
    {
        $voteId = $this->seedVote(1);
        $wpdb   = $GLOBALS['wpdb'];
        self::assertSame($voteId, (int) $wpdb->insert_id, 'the premise: insert_id holds the vote row');
        $wpdb->failSilentlyMatching = $this->disputeInsertPattern();

        $result = DisputeRepository::createDispute($this->disputeData($voteId));

        self::assertSame(1, $wpdb->silentFailures, 'the dispute INSERT was the statement that failed');
        self::assertSame($voteId, (int) $wpdb->insert_id, 'the premise held: insert_id was still the vote row');
        self::assertSame(['id' => null, 'db_error' => 'insert_failed'], $result);
        self::assertSame(0, $this->disputeCount());
        $this->assertNoTransactionLeftOpen();
    }

    /** A driver-error INSERT (insert_id reset to 0) is reported with its own error. */
    public function testADriverErrorInsertIsReportedWithItsError(): void
    {
        $voteId = $this->seedVote(1);
        $wpdb   = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = $this->disputeInsertPattern();

        $result = DisputeRepository::createDispute($this->disputeData($voteId));

        self::assertSame(1, $wpdb->injectedFailures, 'the dispute INSERT was the statement that failed');
        self::assertSame(0, (int) $wpdb->insert_id, 'a driver error resets insert_id, as wpdb does');
        self::assertSame(['id' => null, 'db_error' => 'injected fault'], $result);
        self::assertSame(0, $this->disputeCount());
        $this->assertNoTransactionLeftOpen();
    }

    /** A deadlocked INSERT throws, so TransactionManager retries the whole body, and the retry creates it. */
    public function testADeadlockedInsertIsRetriedAndTheDisputeCreated(): void
    {
        $voteId = $this->seedVote(1);
        $wpdb   = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching  = $this->disputeInsertPattern();
        $wpdb->injectedErrorMessage = 'Deadlock found when trying to get lock; try restarting transaction';
        $wpdb->failQueriesLimit     = 1;

        $result = DisputeRepository::createDispute($this->disputeData($voteId));

        self::assertSame(1, $wpdb->injectedFailures, 'the first INSERT deadlocked');
        self::assertNull($result['db_error'], (string) $result['db_error']);
        self::assertIsInt($result['id']);
        self::assertNotNull($this->disputeRow($result['id']), 'the retry committed the dispute');
        self::assertSame(1, $this->disputeCount(), 'exactly one dispute');
        $this->assertNoTransactionLeftOpen();
    }

    /** A lock-wait timeout that outlasts TransactionManager's retries is a transaction failure, retried first. */
    public function testALockTimeoutThatOutlastsTheRetriesIsATransactionFailure(): void
    {
        $voteId = $this->seedVote(1);
        $wpdb   = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching  = $this->disputeInsertPattern();
        $wpdb->injectedErrorMessage = 'Lock wait timeout exceeded; try restarting transaction';

        $result = DisputeRepository::createDispute($this->disputeData($voteId));

        self::assertSame(['id' => null, 'db_error' => 'tx_failed'], $result);
        self::assertGreaterThan(1, $wpdb->injectedFailures, 'it was retried before giving up');
        self::assertSame(0, $this->disputeCount());
        $this->assertNoTransactionLeftOpen();
    }

    /** A vote-lock read that did not run is a transaction failure, never "the vote is no longer active". */
    public function testAFailedVoteLockReadIsATransactionFailureNotAnInactiveVote(): void
    {
        $voteId = $this->seedVote(1);
        $wpdb   = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = '/SELECT id FROM ' . preg_quote(TableRegistry::votes(), '/')
            . ' WHERE id = \d+ AND status = 1 LIMIT 1 FOR UPDATE/';

        $result = DisputeRepository::createDispute($this->disputeData($voteId));

        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the vote-lock read was the statement that failed');
        self::assertSame(['id' => null, 'db_error' => 'tx_failed'], $result);
        self::assertSame(0, $this->disputeCount());
        $this->assertNoTransactionLeftOpen();
    }

    public function testTheBodyRefusesToRunOutsideATransaction(): void
    {
        $body = new \ReflectionMethod(DisputeRepository::class, 'createDisputeInTransaction');

        $this->expectException(\LogicException::class);
        $body->invoke(null, $this->disputeData($this->seedVote(1)));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** The dispute INSERT, as the harness's insert() writes it. */
    private function disputeInsertPattern(): string
    {
        return '/^INSERT INTO `' . preg_quote(DisputeRepository::disputes_table(), '/') . '`/';
    }

    /** @return array<string, mixed> */
    private function disputeData(int $voteId): array
    {
        return [
            'vote_id'      => $voteId,
            'page_id'      => self::PAGE,
            'reporter_id'  => self::REPORTER,
            'voter_id'     => self::VOTER,
            'reason'       => 'F-598 integration fixture reason text',
            'evidence_url' => '',
            'status'       => 'reviewing',
        ];
    }

    private function seedVote(int $status): int
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert(TableRegistry::votes(), [
            'voter_user_id' => self::VOTER, 'page_id' => self::PAGE, 'category_id' => 0,
            'vote_type' => 1, 'weight' => 1.0, 'vested_weight' => 1.0, 'status' => $status,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    private function seedDispute(int $voteId, int $pageId, int $reporterId): void
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert(DisputeRepository::disputes_table(), [
            'vote_id' => $voteId, 'page_id' => $pageId, 'reporter_id' => $reporterId, 'voter_id' => self::VOTER,
            'reason' => 'F-598 fixture', 'status' => 'reviewing', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
    }

    private function disputeRow(int $id): ?object
    {
        $wpdb = $GLOBALS['wpdb'];
        $row  = $wpdb->get_row($wpdb->prepare(
            'SELECT vote_id, reporter_id, status FROM `' . DisputeRepository::disputes_table() . '` WHERE id = %d',
            $id
        ));
        return is_object($row) ? $row : null;
    }

    /** Disputes this test's fixtures could own. */
    private function disputeCount(): int
    {
        $wpdb = $GLOBALS['wpdb'];
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM `' . DisputeRepository::disputes_table() . '` WHERE voter_id = %d',
            self::VOTER
        ));
    }

    /** ANTI-VACUITY for the probe below: inside a transaction it says so. */
    public function testTheProbeSeesAnOpenTransaction(): void
    {
        $inside = TransactionManager::run(fn (): bool => $this->connectionIsInTransaction());

        self::assertTrue($inside);
        self::assertFalse($this->connectionIsInTransaction());
    }

    private function assertNoTransactionLeftOpen(): void
    {
        self::assertFalse(TransactionManager::isInRunTransaction(), 'TransactionManager depth back to 0');
        self::assertFalse($this->connectionIsInTransaction(), 'no open transaction on the connection');
    }

    /**
     * SAVEPOINT then RELEASE: RELEASE fails with "SAVEPOINT … does not
     * exist" (1305) when no transaction is open, the same on MySQL 8 and
     * MariaDB (bcc-core's ChallengeRepository::transactionState() probe).
     *
     * ⚠ Not `@@in_transaction` (MariaDB-only; MySQL 8 answers "Unknown
     * system variable"), and not INNODB_TRX (MySQL serves it from a cache
     * refreshed about every 100 ms, so it can describe an earlier moment).
     */
    private function connectionIsInTransaction(): bool
    {
        $wpdb = $GLOBALS['wpdb'];
        $name = 'bcc_f598_probe';

        self::assertNotFalse($wpdb->query("SAVEPOINT {$name}"), 'SAVEPOINT failed: ' . $wpdb->last_error);
        if ($wpdb->query("RELEASE SAVEPOINT {$name}") !== false) {
            return true;
        }
        self::assertStringContainsString('does not exist', $wpdb->last_error, 'RELEASE failed for another reason');
        $wpdb->last_error = '';

        return false;
    }

    private function purge(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare('DELETE FROM `' . DisputeRepository::disputes_table() . '` WHERE voter_id = %d', self::VOTER));
        $wpdb->query($wpdb->prepare('DELETE FROM `' . TableRegistry::votes() . '` WHERE voter_user_id = %d', self::VOTER));
    }

    private function restoreTable(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = DisputeRepository::disputes_table();
        $away  = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s',
            $table . '_away'
        ));
        if ($away === 1) {
            $wpdb->query("RENAME TABLE `{$table}_away` TO `{$table}`");
        }
        $wpdb->last_error = '';
    }
}
