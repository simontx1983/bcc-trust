<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\UserSyncRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-518 / F-519 — the ONE listing opt-out fragment, shared by the app
 * sitemap's public index and the member directory / search.
 *
 * Pinned exactly, because two consumers splice it into their own SQL:
 * both keys (the live hide flag and its legacy predecessor), always; the
 * truthy set, because PeepSo's own form stores the client's value raw; no
 * `%` (it runs through prepare() beside other clauses); and only a column
 * reference may be spliced in. What it EXCLUDES on real rows is proven in
 * PublicSitemapIndexIntegrationTest.
 *
 * ⚠ SEPARATE PROCESSES, on purpose. Another unit file
 * (ColdStartVisibilityTest) declares a fake UserSyncRepository at file
 * level, and PHPUnit loads every test file into the main process while it
 * collects them, so in the full suite that fake IS the class there. Alone
 * this file passes either way; in the suite only a fresh process loads the
 * real one.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ListingOptOutSqlTest extends TestCase
{
    /** @var mixed */
    private $priorWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public string $usermeta = 'wp_usermeta';
        };
    }

    protected function tearDown(): void
    {
        if ($this->priorWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->priorWpdb;
        }
        parent::tearDown();
    }

    public function testTheExactFragment(): void
    {
        self::assertSame(
            'NOT EXISTS (SELECT 1 FROM wp_usermeta bcc_optout'
            . ' WHERE bcc_optout.user_id = wp_users.ID'
            . " AND bcc_optout.meta_key IN ('peepso_is_hide_profile_from_user_listing', 'bcc_privacy_discovery_optout')"
            . " AND bcc_optout.meta_value IN ('1', 'true', 'on', 'yes'))",
            UserSyncRepository::listingOptOutSql('wp_users.ID')
        );
    }

    public function testItCarriesNoPercentForPrepare(): void
    {
        self::assertStringNotContainsString('%', UserSyncRepository::listingOptOutSql('wp_users.ID'));
    }

    public function testItAcceptsABareColumn(): void
    {
        self::assertStringContainsString('bcc_optout.user_id = ID ', UserSyncRepository::listingOptOutSql('ID'));
    }

    #[DataProvider('refusedArguments')]
    public function testItRefusesAnythingThatIsNotAColumnReference(string $argument): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('listingOptOutSql() takes a column reference');
        UserSyncRepository::listingOptOutSql($argument);
    }

    /** @return iterable<string, array{0: string}> */
    public static function refusedArguments(): iterable
    {
        yield 'empty'             => [''];
        yield 'a literal'         => ['1'];
        yield 'a placeholder'     => ['%d'];
        yield 'quoted'            => ["'wp_users.ID'"];
        yield 'injection attempt' => ['wp_users.ID) OR 1=1 -- '];
        yield 'three parts'       => ['db.wp_users.ID'];
        yield 'trailing space'    => ['wp_users.ID '];
    }
}
