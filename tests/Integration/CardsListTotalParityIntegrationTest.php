<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoPagePrivacy;
use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\PageDiscoveryRepository;
use BCC\Trust\Core\REST\CardsListEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Structure A: GET /cards carries `pagination.total`, and it agrees with
 * the rows, on a real server.
 *
 * The total is the discovery statement's own COUNT (same filters, same
 * secret-page exclusion as the rows). The one place they could disagree is
 * a page type with no card kind (e.g. 'dao'): counted, given a slot, then
 * rendered as nothing. So a list with no `kind` now asks discovery for the
 * card page types only (CardsListEndpoint::cardPageTypes()).
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(CardsListEndpoint::class)]
#[CoversClass(PageDiscoveryRepository::class)]
final class CardsListTotalParityIntegrationTest extends TestCase
{
    /** @var array<int, array{string, string, bool}> page => [title, page_type, secret] */
    private const PAGES = [
        995301 => ['Alpha Validator', 'validator', false],
        995302 => ['Alpha Builder', 'builder', false],
        995303 => ['Beta Creator', 'nft', false],
        995304 => ['Alpha DAO', 'dao', false],          // a page type with no card kind
        995305 => ['Alpha Secret', 'validator', true],  // never listed, never counted
    ];

    protected function setUp(): void
    {
        $this->ensureTables();
        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->purge();
    }

    /** @return array{rows: list<object>, total: int, pages: int} */
    private static function discover(array $types, string $search, int $limit, int $page): array
    {
        /** @var array{rows: list<object>, total: int, pages: int} */
        return PageDiscoveryRepository::queryFromReadModel($types, 'newest', false, '', 0.0, false, 0, $limit, $page, $search);
    }

    /** @param list<object> $rows */
    private static function ids(array $rows): array
    {
        $ids = array_map(static fn (object $r): int => (int) $r->ID, $rows);
        sort($ids);
        return $ids;
    }

    /**
     * ⭐ With no `kind`, every counted row is a card. Anti-vacuity: asking
     * for "all page types" (the old default) counts the DAO page too.
     */
    public function testWithNoKindTheTotalCountsOnlyCardPageTypes(): void
    {
        $all = self::discover([], '', 50, 1);
        self::assertSame(4, $all['total'], 'precondition: "all types" counts the DAO page (and never the secret one)');

        $cards = self::discover(CardsListEndpoint::cardPageTypes(), '', 50, 1);
        self::assertSame(3, $cards['total']);
        self::assertSame([995301, 995302, 995303], self::ids($cards['rows']), 'total and rows are one set');
    }

    /** Under a search and across pages, the total is exactly the rows. */
    public function testTheTotalIsTheRowsAcrossPagesUnderASearch(): void
    {
        $seen  = [];
        $first = self::discover(CardsListEndpoint::cardPageTypes(), 'alpha', 1, 1);
        foreach ([1, 2, 3] as $page) {
            $seen = array_merge($seen, self::ids(self::discover(CardsListEndpoint::cardPageTypes(), 'alpha', 1, $page)['rows']));
        }
        sort($seen);

        self::assertSame(2, $first['total'], 'Alpha Validator and Alpha Builder: not the DAO, not the secret page');
        self::assertSame(2, $first['pages']);
        self::assertSame([995301, 995302], $seen, 'every counted row is listed exactly once, and nothing else');
    }

    /** The endpoint's pagination carries discovery's total beside its pages. */
    public function testThePaginationCarriesTheTotal(): void
    {
        $result = self::discover(CardsListEndpoint::cardPageTypes(), 'alpha', 1, 1);

        self::assertSame(
            ['page' => 1, 'per_page' => 1, 'total' => 2, 'total_pages' => 2, 'has_more' => true],
            CardsListEndpoint::pagination(1, 1, $result)
        );
    }

    // ── fixture ─────────────────────────────────────────────────────────

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
        if (!in_array('post_date', $existing, true)) {
            self::assertNotFalse(
                $wpdb->query("ALTER TABLE {$wpdb->posts} ADD COLUMN post_date DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'"),
                $wpdb->last_error
            );
        }
    }

    private function seed(): void
    {
        global $wpdb;

        $day = 1;
        foreach (self::PAGES as $pageId => [$title, $type, $secret]) {
            self::assertNotFalse($wpdb->insert($wpdb->posts, [
                'ID' => $pageId, 'post_title' => $title, 'post_name' => 'cards-total-' . $pageId,
                'post_type' => 'peepso-page', 'post_status' => 'publish', 'post_date' => sprintf('2026-01-%02d 00:00:00', $day++),
            ]), 'page fixture: ' . $wpdb->last_error);
            self::assertNotFalse($wpdb->insert(TableRegistry::pageReadModel(), [
                'page_id' => $pageId, 'trust_score' => 50.0, 'page_type' => $type,
            ]), 'read-model fixture: ' . $wpdb->last_error);
            if ($secret) {
                self::assertNotFalse($wpdb->insert($wpdb->postmeta, [
                    'post_id' => $pageId, 'meta_key' => PeepSoPagePrivacy::META_KEY, 'meta_value' => (string) PeepSoPagePrivacy::SECRET,
                ]), 'privacy fixture: ' . $wpdb->last_error);
            }
        }

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
        $wpdb->query('DELETE FROM `' . TableRegistry::pageReadModel() . '` WHERE page_id BETWEEN 995301 AND 995399');
    }
}
