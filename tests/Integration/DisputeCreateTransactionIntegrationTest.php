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

    public function testTheBodyRefusesToRunOutsideATransaction(): void
    {
        $body = new \ReflectionMethod(DisputeRepository::class, 'createDisputeInTransaction');

        $this->expectException(\LogicException::class);
        $body->invoke(null, $this->disputeData($this->seedVote(1)));
    }

    // ── helpers ──────────────────────────────────────────────────────────

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

    /**
     * ⚠ INNODB_TRX, not `@@in_transaction`: that variable is MariaDB-only,
     * and MySQL 8 answers "Unknown system variable". This reads the same on
     * both engines.
     */
    private function assertNoTransactionLeftOpen(): void
    {
        self::assertFalse(TransactionManager::isInRunTransaction(), 'TransactionManager depth back to 0');
        self::assertSame(
            '0',
            (string) $GLOBALS['wpdb']->get_var(
                'SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()'
            ),
            'no open InnoDB transaction on the connection'
        );
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
