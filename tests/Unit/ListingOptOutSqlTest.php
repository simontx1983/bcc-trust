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
 * - the live hide key, always;
 * - the legacy key only while the caller says the fold is not done
 *   (`$withLegacy`, from PrivacySettings::legacyOptOutStillRead());
 * - the truthy set, because PeepSo's own form stores the client's value raw;
 * - no `%` (it runs through prepare() beside other clauses);
 * - only a column reference may be spliced in.
 * What it EXCLUDES on real rows, before and after the fold, is proven in
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

    /** Until the fold is done: the live key and the legacy key. */
    public function testTheExactFragmentWithTheLegacyKey(): void
    {
        self::assertSame(
            'NOT EXISTS (SELECT 1 FROM wp_usermeta bcc_optout'
            . ' WHERE bcc_optout.user_id = wp_users.ID'
            . " AND bcc_optout.meta_key IN ('peepso_is_hide_profile_from_user_listing', 'bcc_privacy_discovery_optout')"
            . " AND bcc_optout.meta_value IN ('1', 'true', 'on', 'yes'))",
            UserSyncRepository::listingOptOutSql('wp_users.ID', true)
        );
    }

    /**
     * Once the fold is done: the live key ALONE. The fold left the legacy
     * rows in place, so reading them now would re-hide a member who turned
     * listing back on (flag '0', stale legacy '1').
     */
    public function testTheExactFragmentWithoutTheLegacyKey(): void
    {
        self::assertSame(
            'NOT EXISTS (SELECT 1 FROM wp_usermeta bcc_optout'
            . ' WHERE bcc_optout.user_id = wp_users.ID'
            . " AND bcc_optout.meta_key IN ('peepso_is_hide_profile_from_user_listing')"
            . " AND bcc_optout.meta_value IN ('1', 'true', 'on', 'yes'))",
            UserSyncRepository::listingOptOutSql('wp_users.ID', false)
        );
    }

    #[DataProvider('bothModes')]
    public function testItCarriesNoPercentForPrepare(bool $withLegacy): void
    {
        self::assertStringNotContainsString('%', UserSyncRepository::listingOptOutSql('wp_users.ID', $withLegacy));
    }

    #[DataProvider('bothModes')]
    public function testItAcceptsABareColumn(bool $withLegacy): void
    {
        self::assertStringContainsString('bcc_optout.user_id = ID ', UserSyncRepository::listingOptOutSql('ID', $withLegacy));
    }

    /** @return iterable<string, array{0: bool}> */
    public static function bothModes(): iterable
    {
        yield 'with the legacy key'    => [true];
        yield 'without the legacy key' => [false];
    }

    #[DataProvider('refusedArguments')]
    public function testItRefusesAnythingThatIsNotAColumnReference(string $argument): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('listingOptOutSql() takes a column reference');
        UserSyncRepository::listingOptOutSql($argument, true);
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

    /**
     * anyRowOn() is the PHP form of the fragment's `meta_value IN (…)`: the
     * same set, any case, nothing trimmed, any row.
     *
     * @param list<string> $rows
     */
    #[DataProvider('rowShapes')]
    public function testAnyRowOnMatchesAsTheFragmentDoes(array $rows, bool $on): void
    {
        self::assertSame($on, UserSyncRepository::anyRowOn($rows));
    }

    /** @return iterable<string, array{list<string>, bool}> */
    public static function rowShapes(): iterable
    {
        yield 'no row'       => [[], false];
        yield "'1'"          => [['1'], true];
        yield "'true'"       => [['true'], true];
        yield "'on'"         => [['on'], true];
        yield "'yes'"        => [['yes'], true];
        yield "'YES'"        => [['YES'], true];
        yield "'True'"       => [['True'], true];
        yield "'0' then '1'" => [['0', '1'], true];
        yield "'1' then '0'" => [['1', '0'], true];
        yield "'0'"          => [['0'], false];
        yield "''"           => [[''], false];
        yield "'2'"          => [['2'], false];
        yield "'y'"          => [['y'], false];
        yield "' 1'"         => [[' 1'], false];
        yield "'1\\n'"       => [["1\n"], false];
        yield "' yes'"       => [[' yes'], false];
        yield "'1 '"         => [['1 '], false];
    }
}
