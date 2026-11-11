<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint compare-and-swap, against a REAL database. BOTH ENGINES.
 *
 * ── WHY THIS CANNOT BE A UNIT TEST ──────────────────────────────────────
 * The guard's whole value is that it refuses a row whose stored endpoint is not
 * byte-for-byte what the operator reviewed. Whether a comparison is byte-exact
 * is a property of a collation and a cast, so a PHP fake comparing with `===`
 * would pass no matter what the SQL said.
 *
 * ── AND WHY BOTH ENGINES ────────────────────────────────────────────────
 * ⚠⚠⚠ THE TRAILING-SPACE CASE IS ENGINE-DEPENDENT. MySQL gives every collation
 * a pad attribute; only the UCA 9.0.0 (`utf8mb4_0900_*`) family is NO PAD, and
 * everything else — INCLUDING `utf8mb4_bin` — is PAD SPACE, which ignores
 * trailing spaces in comparison. MySQL 8 defaults to a NO PAD collation and
 * MariaDB does not, so a test run on one engine can pass while the other is
 * broken. An earlier revision of this design used `COLLATE utf8mb4_bin` and
 * would have been let through by exactly that gap.
 *
 * `CAST(... AS BINARY)` compares byte-wise with no padding. This file is the
 * evidence for that claim; it is not asserted anywhere else.
 */
#[Group('integration')]
#[Group('mariadb')]
final class CosmosEndpointSwitchCasIntegrationTest extends TestCase
{
    private const CHAIN_ID = 777_001;

    private const TARGET = 'https://cosmos-api.polkachu.com';

    /**
     * ⚠ NOT `cosmos`. `wp_bcc_chains` carries `UNIQUE KEY slug`, and the
     * integration bootstrap seeds 20 default chains — `cosmos` among them — so
     * a fixture row with that slug collides, the INSERT does nothing, and every
     * assertion below is then made against a row that was never there.
     */
    private const SLUG = 'cas-fixture-chain';

    protected function setUp(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE id = %d", self::CHAIN_ID));
        ChainRepository::clearCache();
    }

    protected function tearDown(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE id = %d", self::CHAIN_ID));
        ChainRepository::clearCache();
    }

    /** Seed the one row under test. A null $restUrl exercises the NULL branch. */
    private function seed(?string $restUrl, string $slug = self::SLUG, int $isActive = 1): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        if ($restUrl === null) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$table}` (id, slug, name, chain_type, rest_url, is_active)
                 VALUES (%d, %s, %s, %s, NULL, %d)",
                self::CHAIN_ID,
                $slug,
                'CAS fixture',
                'cosmos',
                $isActive
            ));

            $this->assertFixtureLanded($restUrl);

            return;
        }

        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (id, slug, name, chain_type, rest_url, is_active)
             VALUES (%d, %s, %s, %s, %s, %d)",
            self::CHAIN_ID,
            $slug,
            'CAS fixture',
            'cosmos',
            $restUrl,
            $isActive
        ));

        $this->assertFixtureLanded($restUrl);
    }

    /**
     * ⚠⚠ FIXTURE ANTI-VACUITY. `$wpdb->query()` returns false on a constraint
     * violation and this suite never asked. A silent INSERT failure does not
     * make these tests fail honestly — it makes every ABSENCE assertion among
     * them pass for the wrong reason, because an absent row is also an
     * unchanged one. So the fixture proves itself before anything is measured.
     */
    private function assertFixtureLanded(?string $restUrl): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE id = %d",
            self::CHAIN_ID
        ));

        self::assertSame(
            1,
            $rows,
            'fixture: exactly one row must exist to test against — ' . (string) $wpdb->last_error
        );
        self::assertSame(
            $restUrl,
            $this->storedRaw(),
            'fixture: the seeded endpoint must be stored byte for byte'
        );
    }

    /** The stored value, read straight from the column with no normalisation. */
    private function storedRaw(): ?string
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT rest_url FROM `{$table}` WHERE id = %d",
            self::CHAIN_ID
        ));

        return $value === null ? null : (string) $value;
    }

    /**
     * The whole row, keyed by column.
     *
     * ⚠ Deliberately NOT `get_row(..., ARRAY_A)`. `ARRAY_A` is a WordPress
     * constant that the integration bootstrap does not define, and an undefined
     * constant referenced from inside a namespace is a fatal Error, not a
     * fallback. `get_object_vars()` needs no constant at all.
     *
     * @return array<string, mixed>
     */
    private function rowAsArray(): array
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d",
            self::CHAIN_ID
        ));

        return is_object($row) ? get_object_vars($row) : [];
    }

    /**
     * stored incumbent, operand offered, whether the swap must happen.
     *
     * @return array<string, array{0: string|null, 1: string|null, 2: bool}>
     */
    public static function casCases(): array
    {
        $host = 'https://rest.cosmos.directory/cosmoshub';

        return [
            'exact match swaps'                 => [$host, $host, true],
            'case differs refuses'              => [$host, 'https://rest.cosmos.directory/CosmosHub', false],
            'stored has a trailing space'       => [$host . ' ', $host, false],
            'operand has a trailing space'      => [$host, $host . ' ', false],
            'stored has a trailing slash'       => [$host . '/', $host, false],
            'operand has a trailing slash'      => [$host, $host . '/', false],
            'host case differs refuses'         => ['https://REST.cosmos.directory/cosmoshub', $host, false],
            'malformed stored value is repairable' => ['not a url at all', 'not a url at all', true],
            'null stored with null operand repairs' => [null, null, true],
            'null stored with a non-null operand refuses' => [null, $host, false],
            'non-null stored with a null operand refuses' => [$host, null, false],
        ];
    }

    #[DataProvider('casCases')]
    public function testTheIncumbentComparisonIsByteExact(?string $stored, ?string $operand, bool $mustSwap): void
    {
        $this->seed($stored);

        $affected = ChainRepository::updateRestUrl(self::CHAIN_ID, self::TARGET, [
            'rest_url'  => $operand,
            'slug'      => self::SLUG,
            'is_active' => 1,
        ]);

        if ($mustSwap) {
            self::assertSame(1, $affected, 'the reviewed value matched, so the row must move');
            self::assertSame(self::TARGET, $this->storedRaw());

            return;
        }

        self::assertSame(0, $affected, 'a value that is not byte-identical must not match');
        self::assertSame(
            $stored,
            $this->storedRaw(),
            'and the column must be untouched, byte for byte'
        );
    }

    // ── The identity predicates ─────────────────────────────────────────

    public function testASlugThatDiffersOnlyInCaseRefuses(): void
    {
        $incumbent = 'https://rest.cosmos.directory/cosmoshub';
        $this->seed($incumbent, self::SLUG);

        $affected = ChainRepository::updateRestUrl(self::CHAIN_ID, self::TARGET, [
            'rest_url'  => $incumbent,
            'slug'      => 'Cas-Fixture-Chain',
            'is_active' => 1,
        ]);

        self::assertSame(0, $affected, 'slug is byte-exact too — policy is keyed on it');
        self::assertSame($incumbent, $this->storedRaw());
    }

    public function testASlugWithATrailingSpaceRefuses(): void
    {
        $incumbent = 'https://rest.cosmos.directory/cosmoshub';
        $this->seed($incumbent, self::SLUG);

        $affected = ChainRepository::updateRestUrl(self::CHAIN_ID, self::TARGET, [
            'rest_url'  => $incumbent,
            'slug'      => self::SLUG . ' ',
            'is_active' => 1,
        ]);

        self::assertSame(0, $affected);
        self::assertSame($incumbent, $this->storedRaw());
    }

    public function testAChangedIsActiveRefuses(): void
    {
        $incumbent = 'https://rest.cosmos.directory/cosmoshub';
        $this->seed($incumbent, self::SLUG, 1);

        $affected = ChainRepository::updateRestUrl(self::CHAIN_ID, self::TARGET, [
            'rest_url'  => $incumbent,
            'slug'      => self::SLUG,
            'is_active' => 0,
        ]);

        self::assertSame(0, $affected);
        self::assertSame($incumbent, $this->storedRaw());
    }

    // ── Scope ───────────────────────────────────────────────────────────

    /**
     * The write touches ONE column. Asserted against the whole row rather than
     * the one column, because "it only changed what I meant" is exactly the
     * claim a comment cannot carry.
     */
    public function testItTouchesExactlyOneColumn(): void
    {
        $incumbent = 'https://rest.cosmos.directory/cosmoshub';
        $this->seed($incumbent, self::SLUG);

        $before = $this->rowAsArray();

        $affected = ChainRepository::updateRestUrl(self::CHAIN_ID, self::TARGET, [
            'rest_url'  => $incumbent,
            'slug'      => self::SLUG,
            'is_active' => 1,
        ]);
        self::assertSame(1, $affected);

        $after = $this->rowAsArray();

        self::assertNotSame([], $before, 'anti-vacuity: the row was readable');
        self::assertSame(array_keys($before), array_keys($after));

        foreach ($before as $column => $value) {
            if ($column === 'rest_url') {
                self::assertSame(self::TARGET, $after[$column]);
                continue;
            }

            self::assertSame($value, $after[$column], "column '{$column}' must not have moved");
        }
    }

    public function testASecondPressWithTheNowStaleIncumbentRefuses(): void
    {
        $incumbent = 'https://rest.cosmos.directory/cosmoshub';
        $this->seed($incumbent, self::SLUG);

        $expected = ['rest_url' => $incumbent, 'slug' => self::SLUG, 'is_active' => 1];

        self::assertSame(1, ChainRepository::updateRestUrl(self::CHAIN_ID, self::TARGET, $expected));
        self::assertSame(
            0,
            ChainRepository::updateRestUrl(self::CHAIN_ID, self::TARGET, $expected),
            'the same confirmation cannot apply twice — the row has moved'
        );
        self::assertSame(self::TARGET, $this->storedRaw());
    }
}
