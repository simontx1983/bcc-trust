<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\PageDiscoveryRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-560, against the real database: both discovery lists put NAMED
 * validators first, then the chosen sort, then the tiebreak.
 *
 * The unnamed pages are dated NEWER than the named ones on purpose, so
 * `newest` alone would list them first: that is the reported defect, since
 * the Solana mints are the newest pages. Every placeholder shape our
 * fetchers write is here, plus the cases that must count as named: a real
 * moniker, a page with no validator, and a name that merely looks
 * shortened.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(PageDiscoveryRepository::class)]
final class NamedValidatorsFirstIntegrationTest extends TestCase
{
    private const SOL = 'Vote111111111111111111111111111111111111111';
    private const DOT = '15oF4uVJwmo4TdGW7VfQxNLavjCXviqxT9S1MgbjMNHr6Sp5';
    private const RUNE = 'thor1qpgxwq6ga88u3h5hsdyzlrnhpj5yxc6szq3rmf';

    /**
     * page id => [post_date, page_type, moniker|null (null: no validator), operator address].
     *
     * @var array<int, array{0: string, 1: string, 2: string|null, 3: string}>
     */
    private const PAGES = [
        // Named: listed first, newest first among them.
        994101 => ['2026-01-01 00:00:00', 'validator', 'Chorus One', 'cosmosvaloper1chorus'],
        994102 => ['2026-01-02 00:00:00', 'project', null, ''],
        994108 => ['2026-01-03 00:00:00', 'validator', 'Staked...Fund', self::SOL . 'X'], // looks shortened, is a name
        994107 => ['2026-01-07 00:00:00', 'validator', 'Kiln', 'cosmosvaloper1kiln'],
        // Unnamed: after every named page, however new.
        994103 => ['2026-01-08 00:00:00', 'validator', 'Vote11...1111', self::SOL],      // Solana 6...4
        994104 => ['2026-01-09 00:00:00', 'validator', 'thor1qpg...3rmf', self::RUNE],   // THORChain 8...4
        994105 => ['2026-01-10 00:00:00', 'validator', '', 'cosmosvaloper1blank'],
        994106 => ['2026-01-11 00:00:00', 'validator', 'cosmosvaloper1bare', 'cosmosvaloper1bare'],
        994109 => ['2026-01-12 00:00:00', 'validator', '15oF4u...6Sp5', self::DOT],      // Polkadot 6...4
    ];

    /** `newest`, named first: named by date DESC, then unnamed by date DESC. */
    private const NEWEST_NAMED_FIRST = [994107, 994108, 994102, 994101, 994109, 994106, 994105, 994104, 994103];

    private int $chainId = 0;

    protected function setUp(): void
    {
        $this->ensureTables();
        $GLOBALS['wpdb']->clearFaultInjection();
        $chainId = ChainRepository::resolveIdAnyState('solana');
        self::assertIsInt($chainId, 'the chain registry must be seeded');
        $this->chainId = $chainId;
        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
    }

    /** @param list<object> $rows @return list<int> */
    private static function ids(array $rows): array
    {
        return array_map(static fn (object $r): int => (int) $r->ID, $rows);
    }

    public function testTheReadModelListsNamedValidatorsFirst(): void
    {
        $result = PageDiscoveryRepository::queryFromReadModel([], 'newest', false, '', 0.0, false, 0, 50, 1, '');

        self::assertSame(self::NEWEST_NAMED_FIRST, self::ids($result['rows']));
        self::assertSame(count(self::PAGES), $result['total'], 'the COUNT is untouched');
    }

    public function testTheReadModelKeepsTheChosenSortWithinEachGroup(): void
    {
        // Equal trust scores tie under `trust`; the tiebreak (p.ID DESC)
        // then orders each group, named first.
        $result = PageDiscoveryRepository::queryFromReadModel([], 'trust', false, '', 0.0, false, 0, 50, 1, '');

        self::assertSame([994108, 994107, 994102, 994101, 994109, 994106, 994105, 994104, 994103], self::ids($result['rows']));
    }

    public function testTheReadModelPagesThroughNamedThenUnnamed(): void
    {
        $first  = PageDiscoveryRepository::queryFromReadModel([], 'newest', false, '', 0.0, false, 0, 4, 1, '');
        $second = PageDiscoveryRepository::queryFromReadModel([], 'newest', false, '', 0.0, false, 0, 4, 2, '');

        self::assertSame(array_slice(self::NEWEST_NAMED_FIRST, 0, 4), self::ids($first['rows']), 'page 1: the four named pages');
        self::assertSame(array_slice(self::NEWEST_NAMED_FIRST, 4, 4), self::ids($second['rows']));
    }

    public function testThePostsTableFallbackListsNamedValidatorsFirst(): void
    {
        $result = PageDiscoveryRepository::queryFromPostsTable([], 'newest', false, '', 0.0, false, 0, 50, 1, '');

        self::assertSame(self::NEWEST_NAMED_FIRST, self::ids($result['rows']));
        self::assertSame(count(self::PAGES), $result['total'], 'the COUNT is untouched');
    }

    public function testAPageWithADuplicateValidatorRowIsStillListedOnce(): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->postmeta, [
            'post_id' => 994103, 'meta_key' => '_bcc_onchain_validator_id',
            'meta_value' => (string) $this->validatorIdFor(994103),
        ]), $wpdb->last_error);

        $result = PageDiscoveryRepository::queryFromReadModel([], 'newest', false, '', 0.0, false, 0, 50, 1, '');

        self::assertSame(self::NEWEST_NAMED_FIRST, self::ids($result['rows']));
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function validatorIdFor(int $pageId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_bcc_onchain_validator_id' LIMIT 1",
            $pageId
        ));
    }

    /** Same widening as SecretPageVisibilityIntegrationTest; nothing is dropped in teardown. */
    private function ensureTables(): void
    {
        global $wpdb;

        $readModel = TableRegistry::pageReadModel();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $readModel)) !== $readModel) {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-project.php';
            \bcc_trust_create_page_tables();
        }

        $existing = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $wpdb->posts
        ));
        $adds = [];
        foreach ([
            'post_author' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'post_date'   => "DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'",
        ] as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $adds[] = "ADD COLUMN {$column} {$definition}";
            }
        }
        if ($adds !== []) {
            self::assertNotFalse($wpdb->query("ALTER TABLE {$wpdb->posts} " . implode(', ', $adds)), $wpdb->last_error);
        }
        self::assertNotFalse($wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$wpdb->usermeta} (
                umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255) DEFAULT NULL,
                meta_value LONGTEXT,
                PRIMARY KEY (umeta_id),
                KEY user_id (user_id)
            )"
        ), $wpdb->last_error);
    }

    private function seed(): void
    {
        global $wpdb;

        foreach (self::PAGES as $pageId => [$date, $type, $moniker, $address]) {
            self::assertNotFalse($wpdb->insert($wpdb->posts, [
                'ID' => $pageId, 'post_title' => 'F-560 fixture ' . $pageId, 'post_name' => 'f560-' . $pageId,
                'post_type' => 'peepso-page', 'post_status' => 'publish', 'post_date' => $date,
            ]), 'page fixture: ' . $wpdb->last_error);
            self::assertNotFalse($wpdb->insert(TableRegistry::pageReadModel(), [
                'page_id' => $pageId, 'trust_score' => 50.0, 'page_type' => $type,
            ]), 'read-model fixture: ' . $wpdb->last_error);

            if ($moniker === null) {
                continue;
            }
            self::assertNotFalse($wpdb->insert(ValidatorRepository::table(), [
                'operator_address' => $address, 'chain_id' => $this->chainId, 'moniker' => $moniker,
                'status' => 'active', 'expires_at' => '2030-01-01 00:00:00',
            ]), 'validator fixture: ' . $wpdb->last_error);
            self::assertNotFalse($wpdb->insert($wpdb->postmeta, [
                'post_id' => $pageId, 'meta_key' => '_bcc_onchain_validator_id', 'meta_value' => (string) $wpdb->insert_id,
            ]), 'meta fixture: ' . $wpdb->last_error);
        }

        // ⚠ Asserted, not assumed: a stray page from another suite would
        // break the exact order assertions for the wrong reason.
        self::assertSame(
            count(self::PAGES),
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'peepso-page'"),
            'fixture precondition: exactly the fixture pages'
        );
    }

    /** wp_posts and wp_postmeta are emptied wholesale, as the sibling discovery suites do. */
    private function purge(): void
    {
        global $wpdb;

        $wpdb->query("DELETE FROM {$wpdb->postmeta}");
        $wpdb->query("DELETE FROM {$wpdb->posts}");
        $wpdb->query('DELETE FROM `' . TableRegistry::pageReadModel() . '` WHERE page_id BETWEEN 994101 AND 994199');
        $wpdb->query($wpdb->prepare(
            'DELETE FROM `' . ValidatorRepository::table() . "` WHERE chain_id = %d AND (operator_address IN (%s, %s, %s, %s) OR operator_address LIKE 'cosmosvaloper1%%')",
            $this->chainId, self::SOL, self::SOL . 'X', self::DOT, self::RUNE
        ));
    }
}
