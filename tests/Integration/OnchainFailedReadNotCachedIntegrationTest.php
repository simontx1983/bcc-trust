<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CollectionSignalRepository;
use BCC\Trust\Onchain\Repositories\HeliusSeenSignaturesRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Repositories\SignalRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-101 (Onchain): a FAILED read is never cached or persisted as "absent".
 *
 * Real SQL errors (the table is renamed away), then the table is restored
 * and read again. ⚠ Real wpdb's get_results() returns [] — not null — on a
 * SQL error, which is why ChainRepository's `!is_array($rows)` guard never
 * fired.
 */
#[Group('integration')]
#[CoversNothing]
final class OnchainFailedReadNotCachedIntegrationTest extends TestCase
{
    private const USER     = 990101;
    private const CONTRACT = '0xf101f101f101f101f101f101f101f101f101f101';
    private const SIZE_OPTION = 'bcc_helius_dedupe_size';

    /** @var list<string> */
    private array $broken = [];

    protected function setUp(): void
    {
        $this->ensureTables();
        $GLOBALS['__bcc_test_object_cache'] = [];
        $GLOBALS['__bcc_test_transients']   = [];
        delete_option(self::SIZE_OPTION);

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . SignalRepository::table() . '` WHERE user_id = ' . self::USER);
        $wpdb->query('DELETE FROM `' . HeliusSeenSignaturesRepository::table() . '`');
        $wpdb->query($wpdb->prepare('DELETE FROM `' . CollectionRepository::table() . '` WHERE contract_address = %s', self::CONTRACT));
        $wpdb->query($wpdb->prepare('DELETE FROM `' . CollectionSignalRepository::table() . '` WHERE contract_address = %s', self::CONTRACT));
    }

    protected function tearDown(): void
    {
        foreach ($this->broken as $t) {
            $this->restore($t);
        }
        $this->broken = [];
        $GLOBALS['__bcc_test_object_cache'] = [];
        $GLOBALS['__bcc_test_transients']   = [];
        delete_option(self::SIZE_OPTION);
    }

    /** Tables the harness bootstrap does not install: use the REAL installers. */
    private function ensureTables(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $db   = dirname(__DIR__, 2) . '/includes/database/';
        foreach ([
            [HeliusSeenSignaturesRepository::table(), 'schema-helius-seen-signatures.php', 'bcc_onchain_create_helius_seen_signatures_table'],
            [CollectionSignalRepository::table(), 'schema-collection-signals.php', 'bcc_onchain_create_collection_signals_table'],
        ] as [$table, $file, $installer]) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                require_once $db . $file;
                $installer();
            }
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', SignalRepository::table())) !== SignalRepository::table()) {
            SignalRepository::install_own_table();
        }
        foreach ([HeliusSeenSignaturesRepository::table(), CollectionSignalRepository::table(), SignalRepository::table(),
                  CollectionRepository::table(), ChainRepository::table()] as $t) {
            self::assertSame($t, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)), "{$t} must be installed");
        }
    }

    private function break(string $table): void
    {
        $GLOBALS['wpdb']->query("RENAME TABLE `{$table}` TO `{$table}_away`");
        $this->broken[] = $table;
    }

    private function restore(string $table): void
    {
        $wpdb   = $GLOBALS['wpdb'];
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s',
            $table . '_away'
        ));
        if ($exists === 1) {
            $wpdb->query("RENAME TABLE `{$table}_away` TO `{$table}`");
        }
        $wpdb->last_error = '';
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): void
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert($table, $row), $wpdb->last_error);
    }

    // ── SignalRepository::getByUser: feeds the bonus WRITERS ─────────────

    public function testAFailedSignalReadThrowsAndCachesNothing(): void
    {
        $this->insert(SignalRepository::table(), [
            'user_id' => self::USER, 'wallet_address' => 'cosmos1f101', 'chain' => 'cosmos',
            'score_contribution' => 2.5, 'fetched_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->break(SignalRepository::table());
        try {
            SignalRepository::getByUser(self::USER);
            self::fail('a failed signal read must throw: its callers SET onchain_bonus from it');
        } catch (RepositoryReadFailure) {
        }
        self::assertFalse(get_transient('bcc_signals_u' . self::USER), 'nothing may reach the shared transient');
        $this->restore(SignalRepository::table());

        $rows = SignalRepository::getByUser(self::USER);
        self::assertCount(1, $rows, 'the failure was not cached');
        self::assertSame('cosmos1f101', $rows[0]['wallet_address'], 'rows keep the ARRAY_A shape');
    }

    // ── ChainRepository::getAllCached ────────────────────────────────────

    public function testAFailedChainReadIsNeverWrittenToTheSharedTransient(): void
    {
        self::assertNotSame([], ChainRepository::getActive(), 'precondition: the installer seeded active chains');
        $GLOBALS['__bcc_test_object_cache'] = [];
        $GLOBALS['__bcc_test_transients']   = [];

        $this->break(ChainRepository::table());
        self::assertSame([], ChainRepository::getActive());
        self::assertFalse(get_transient('bcc_active_chains'), '"no chains" must not reach other workers');
        $this->restore(ChainRepository::table());

        // The short error sentinel is per-node by design; once it expires
        // (or on another worker) the next read must see the chains.
        $GLOBALS['__bcc_test_object_cache'] = [];
        self::assertNotSame([], ChainRepository::getActive(), 'the failure was not cached');
    }

    // ── HeliusSeenSignaturesRepository: the size option has NO TTL ───────

    public function testAFailedDedupeCountIsNeverPersisted(): void
    {
        $this->insert(HeliusSeenSignaturesRepository::table(), ['signature' => 'sigA', 'seen_at' => gmdate('Y-m-d H:i:s')]);
        $this->insert(HeliusSeenSignaturesRepository::table(), ['signature' => 'sigB', 'seen_at' => gmdate('Y-m-d H:i:s')]);

        $this->break(HeliusSeenSignaturesRepository::table());
        self::assertSame(0, HeliusSeenSignaturesRepository::rowCount());
        self::assertSame(-1, (int) get_option(self::SIZE_OPTION, -1), 'rowCount must not persist a failed count');
        HeliusSeenSignaturesRepository::sweep();
        self::assertSame(-1, (int) get_option(self::SIZE_OPTION, -1), 'sweep must not persist a failed count');
        $this->restore(HeliusSeenSignaturesRepository::table());

        self::assertSame(2, HeliusSeenSignaturesRepository::rowCount());
    }

    public function testAFailedRegroundCountTrimsNothingAndPersistsNothing(): void
    {
        // A drifted-high estimate makes the sweep re-ground with a COUNT(*).
        update_option(self::SIZE_OPTION, 999999, false);

        $this->break(HeliusSeenSignaturesRepository::table());
        $r = HeliusSeenSignaturesRepository::sweep();
        $this->restore(HeliusSeenSignaturesRepository::table());

        self::assertSame(0, $r['deleted_overflow']);
        self::assertSame(999999, (int) get_option(self::SIZE_OPTION, -1), 'the estimate is left for the next sweep, not overwritten with 0');
    }

    // ── Per-chain / per-collection counts ────────────────────────────────

    public function testAFailedCollectionCountReadIsNotCached(): void
    {
        $this->insert(CollectionRepository::table(), [
            'contract_address' => self::CONTRACT, 'chain_id' => 991, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);

        $this->break(CollectionRepository::table());
        self::assertSame([], CollectionRepository::getCountsByChain());
        $this->restore(CollectionRepository::table());

        self::assertArrayHasKey(991, CollectionRepository::getCountsByChain(), 'the failure was not cached');
    }

    public function testAFailedSignalCountReadIsNotCached(): void
    {
        $this->insert(CollectionSignalRepository::table(), [
            'user_id' => self::USER, 'chain_id' => 991, 'contract_address' => self::CONTRACT, 'stance' => 'waitlist',
        ]);

        $this->break(CollectionSignalRepository::table());
        self::assertSame([], CollectionSignalRepository::countsByCollection(991));
        $this->restore(CollectionSignalRepository::table());

        self::assertCount(1, CollectionSignalRepository::countsByCollection(991), 'the failure was not cached');
    }
}
