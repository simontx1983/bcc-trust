<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Fetchers\PolkadotFetcher;
use BCC\Trust\Onchain\Fetchers\ThorchainFetcher;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The one-shot THORChain and Polkadot repoints against a real chains table,
 * on both engines: only a row still holding EXACTLY a retired seeded endpoint
 * moves, byte for byte (the column's collation is case-insensitive and pads
 * spaces, so a plain `=` would also have matched the variants below).
 */
#[Group('integration')]
#[Group('mariadb')]
final class ValidatorFeedEndpointRepointIntegrationTest extends TestCase
{
    private const ID = 777_101;

    /** @var list<array<string, mixed>> rows the harness held for the two slugs, restored after */
    private array $saved = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/database/migration-runner.php';
        require_once dirname(__DIR__, 2) . '/includes/database/repoint-validator-feed-endpoints.php';

        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();
        $this->saved = (array) $wpdb->get_results("SELECT * FROM `{$table}` WHERE slug IN ('thorchain', 'polkadot')", ARRAY_A);
        $wpdb->query("DELETE FROM `{$table}` WHERE slug IN ('thorchain', 'polkadot')");
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE id = %d", self::ID));
        ChainRepository::clearCache();
    }

    protected function tearDown(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE id = %d", self::ID));
        $wpdb->query("DELETE FROM `{$table}` WHERE slug IN ('thorchain', 'polkadot')");
        foreach ($this->saved as $row) {
            $wpdb->insert($table, $row);
        }
        ChainRepository::clearCache();
    }

    private function seed(string $slug, ?string $restUrl, int $isActive = 1, ?string $type = null): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();
        $ok    = $wpdb->insert($table, [
            'id'         => self::ID,
            'slug'       => $slug,
            'name'       => $slug . ' fixture',
            'chain_type' => $type ?? $slug,
            'rest_url'   => $restUrl,
            'is_active'  => $isActive,
        ]);
        self::assertSame(1, $ok, 'fixture row: ' . $wpdb->last_error);
    }

    /** @return array{rest_url: string|null, is_active: string} */
    private function stored(): array
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();
        /** @var array{rest_url: string|null, is_active: string} $row */
        $row = $wpdb->get_row($wpdb->prepare("SELECT rest_url, is_active FROM `{$table}` WHERE id = %d", self::ID), ARRAY_A);

        return $row;
    }

    /** @return array<string, array{0: callable-string, 1: class-string, 2: string}> */
    private static function feeds(): array
    {
        return [
            'thorchain' => ['bcc_trust_repoint_thorchain_endpoint', ThorchainFetcher::class, 'thorchain'],
            'polkadot'  => ['bcc_trust_repoint_polkadot_endpoint', PolkadotFetcher::class, 'polkadot'],
        ];
    }

    /** @return iterable<string, array{0: callable-string, 1: class-string, 2: string, 3: string, 4: int}> */
    public static function retired(): iterable
    {
        foreach (self::feeds() as $name => [$fn, $fetcher, $slug]) {
            foreach ($fetcher::RETIRED_REST_URLS as $url) {
                yield "{$name}: {$url}, active"   => [$fn, $fetcher, $slug, $url, 1];
                yield "{$name}: {$url}, inactive" => [$fn, $fetcher, $slug, $url, 0];
            }
        }
    }

    /** @param class-string $fetcher */
    #[DataProvider('retired')]
    public function testARowOnARetiredSeedMovesAndKeepsItsState(string $fn, string $fetcher, string $slug, string $retired, int $isActive): void
    {
        $this->seed($slug, $retired, $isActive);

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, $fn());
        self::assertSame(['rest_url' => $fetcher::DEFAULT_REST_URL, 'is_active' => (string) $isActive], $this->stored());

        // Idempotent: a second run finds the live default and leaves it.
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, $fn());
        self::assertSame($fetcher::DEFAULT_REST_URL, $this->stored()['rest_url']);
    }

    /** @return iterable<string, array{0: callable-string, 1: string, 2: ?string, 3?: string}> */
    public static function untouched(): iterable
    {
        foreach (self::feeds() as $name => [$fn, $fetcher, $slug]) {
            $retired = $fetcher::RETIRED_REST_URLS[0];
            yield "{$name}: an operator's own node"      => [$fn, $slug, 'https://node.example.org'];
            yield "{$name}: already the live default"    => [$fn, $slug, $fetcher::DEFAULT_REST_URL];
            yield "{$name}: NULL"                        => [$fn, $slug, null];
            yield "{$name}: a retired host with a slash" => [$fn, $slug, $retired . '/'];
            yield "{$name}: a retired host with a space" => [$fn, $slug, $retired . ' '];
            yield "{$name}: a retired host in upper case" => [$fn, $slug, strtoupper($retired)];
            yield "{$name}: not that chain type"         => [$fn, $slug, $retired, 'cosmos'];
        }
    }

    #[DataProvider('untouched')]
    public function testAnyOtherValueIsLeftExactlyAsItWas(string $fn, string $slug, ?string $restUrl, ?string $type = null): void
    {
        $this->seed($slug, $restUrl, 1, $type);

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, $fn());
        self::assertSame($restUrl, $this->stored()['rest_url']);
    }

    public function testNoRowIsDone(): void
    {
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_repoint_thorchain_endpoint());
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_repoint_polkadot_endpoint());
    }
}
