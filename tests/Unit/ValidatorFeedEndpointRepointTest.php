<?php

declare(strict_types=1);

namespace BCC\Core\DB {
    // bcc-core is not autoloaded in the unit context; stub the resolver.
    if (!class_exists(__NAMESPACE__ . '\\DB', false)) {
        final class DB
        {
            public static function table(string $name): string
            {
                return 'wp_bcc_' . $name;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Trust\Onchain\Fetchers\PolkadotFetcher;
    use BCC\Trust\Onchain\Fetchers\ThorchainFetcher;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * THORChain's and Polkadot's validator indexes have not run since their
     * chain rows' endpoints stopped serving the validator list. The live
     * default replaces only a row still holding EXACTLY a retired seeded
     * value; any other value is never touched, and a failed read or write is
     * never marked done.
     *
     * The real compare-and-swap (binary comparison, both engines) is proven
     * in ValidatorFeedEndpointRepointIntegrationTest; this suite pins the
     * decision and the statement it sends.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class ValidatorFeedEndpointRepointTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            require_once __DIR__ . '/../Stubs/breaker-real-stubs.php';
            require_once dirname(__DIR__, 2) . '/includes/database/migration-runner.php';
            require_once dirname(__DIR__, 2) . '/includes/database/repoint-validator-feed-endpoints.php';
            $GLOBALS['wpdb'] = new FeedRepointWpdb();
            \BccBreakerStore::$log = [];
        }

        private static function row(string $slug, ?string $restUrl, int $isActive = 1, ?string $type = null): object
        {
            return (object) [
                'id'         => '11',
                'slug'       => $slug,
                'chain_type' => $type ?? $slug,
                'rest_url'   => $restUrl,
                'is_active'  => (string) $isActive,
            ];
        }

        /** @return list<string> the UPDATE statements sent */
        private static function updates(): array
        {
            return array_values(array_filter($GLOBALS['wpdb']->sql, static fn (string $s): bool => str_starts_with(ltrim($s), 'UPDATE')));
        }

        /** @return array<string, array{0: callable-string, 1: class-string, 2: string}> */
        private static function feeds(): array
        {
            return [
                'thorchain' => ['bcc_trust_repoint_thorchain_endpoint', ThorchainFetcher::class, 'thorchain'],
                'polkadot'  => ['bcc_trust_repoint_polkadot_endpoint', PolkadotFetcher::class, 'polkadot'],
            ];
        }

        /** @return iterable<string, array{0: callable-string, 1: class-string, 2: string}> */
        public static function feedCases(): iterable
        {
            yield from self::feeds();
        }

        // ── Moved ───────────────────────────────────────────────────────

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
        public function testARowOnARetiredSeedMovesToTheLiveDefault(string $fn, string $fetcher, string $slug, string $retired, int $isActive): void
        {
            $GLOBALS['wpdb']->row = self::row($slug, $retired, $isActive);
            $GLOBALS['wpdb']->updateResult = 1;

            self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, $fn());

            $updates = self::updates();
            self::assertCount(1, $updates);
            self::assertStringContainsString("SET rest_url = '" . $fetcher::DEFAULT_REST_URL . "'", $updates[0]);
            self::assertStringContainsString("CAST(rest_url AS BINARY) = CAST('{$retired}' AS BINARY)", $updates[0], 'only if it still holds exactly that value');
            self::assertStringContainsString("CAST(slug AS BINARY) = CAST('{$slug}' AS BINARY)", $updates[0]);
            self::assertStringContainsString("is_active = {$isActive}", $updates[0], 'is_active is bound, never changed');
            self::assertStringContainsString('WHERE id = 11', $updates[0]);
            self::assertContains('info: [bcc-trust] Chain moved off a retired seeded endpoint to the live default', \BccBreakerStore::$log);
        }

        // ── Never touched ───────────────────────────────────────────────

        /** @return iterable<string, array{0: callable-string, 1: class-string, 2: string, 3: ?string, 4?: string}> */
        public static function untouched(): iterable
        {
            foreach (self::feeds() as $name => [$fn, $fetcher, $slug]) {
                $retired = $fetcher::RETIRED_REST_URLS[0];
                yield "{$name}: an operator's own node"     => [$fn, $fetcher, $slug, 'https://node.example.org'];
                yield "{$name}: already the live default"   => [$fn, $fetcher, $slug, $fetcher::DEFAULT_REST_URL];
                yield "{$name}: NULL (the code default)"    => [$fn, $fetcher, $slug, null];
                yield "{$name}: a retired host with a slash" => [$fn, $fetcher, $slug, $retired . '/'];
                yield "{$name}: a retired host with a space" => [$fn, $fetcher, $slug, $retired . ' '];
                yield "{$name}: a retired host in upper case" => [$fn, $fetcher, $slug, strtoupper($retired)];
                yield "{$name}: not that chain type"        => [$fn, $fetcher, $slug, $retired, 'cosmos'];
            }
        }

        /** @param class-string $fetcher */
        #[DataProvider('untouched')]
        public function testAnyOtherValueIsNeverTouched(string $fn, string $fetcher, string $slug, ?string $restUrl, ?string $type = null): void
        {
            $GLOBALS['wpdb']->row = self::row($slug, $restUrl, 1, $type);

            self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, $fn());
            self::assertSame([], self::updates());
        }

        /** @param class-string $fetcher */
        #[DataProvider('feedCases')]
        public function testNoRowIsDoneWithNothingToTouch(string $fn, string $fetcher, string $slug): void
        {
            $GLOBALS['wpdb']->row = null;

            self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, $fn());
            self::assertSame([], self::updates());
        }

        // ── Never marked done on a failure ──────────────────────────────

        /** @param class-string $fetcher */
        #[DataProvider('feedCases')]
        public function testAFailedReadIsRetriedNotDone(string $fn, string $fetcher, string $slug): void
        {
            $GLOBALS['wpdb']->readFails = true;

            self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, $fn());
            self::assertSame([], self::updates());
        }

        /** @param class-string $fetcher */
        #[DataProvider('feedCases')]
        public function testAFailedWriteIsRetriedNotDone(string $fn, string $fetcher, string $slug): void
        {
            $GLOBALS['wpdb']->row = self::row($slug, $fetcher::RETIRED_REST_URLS[0]);
            $GLOBALS['wpdb']->updateResult = false;

            self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, $fn());
        }

        /**
         * The row changed between the read and the swap: read it again next time.
         *
         * @param class-string $fetcher
         */
        #[DataProvider('feedCases')]
        public function testARowThatChangedUnderneathIsReadAgain(string $fn, string $fetcher, string $slug): void
        {
            $GLOBALS['wpdb']->row = self::row($slug, $fetcher::RETIRED_REST_URLS[count($fetcher::RETIRED_REST_URLS) - 1]);
            $GLOBALS['wpdb']->updateResult = 0;

            self::assertSame(BCC_TRUST_MIGRATION_MORE, $fn());
        }

        // ── Registered, loaded, and one source for each URL ─────────────

        /** @return iterable<string, array{0: string, 1: string, 2: string}> */
        public static function registry(): iterable
        {
            yield 'thorchain' => ['repoint_thorchain_endpoint_v1', 'bcc_trust_thorchain_endpoint_repointed_v1', 'bcc_trust_repoint_thorchain_endpoint'];
            yield 'polkadot'  => ['repoint_polkadot_endpoint_v1', 'bcc_trust_polkadot_endpoint_repointed_v1', 'bcc_trust_repoint_polkadot_endpoint'];
        }

        #[DataProvider('registry')]
        public function testEachIsRegisteredWithTheRunner(string $id, string $doneOption, string $callback): void
        {
            $entries = array_values(array_filter(
                bcc_trust_pending_migrations(),
                static fn (array $m): bool => $m['id'] === $id
            ));

            self::assertCount(1, $entries);
            self::assertSame($doneOption, $entries[0]['done_option']);
            self::assertSame($callback, $entries[0]['callback']);
            self::assertStringContainsString(
                "require_once BCC_TRUST_PATH . 'includes/database/repoint-validator-feed-endpoints.php';",
                (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php'),
                'the callbacks are loaded, or the runner skips them forever'
            );
        }

        /** @param class-string $fetcher */
        #[DataProvider('feedCases')]
        public function testTheSeedAndTheFetcherDefaultAreTheLiveEndpoint(string $fn, string $fetcher, string $slug): void
        {
            $seed = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/database/schema-chains.php');
            self::assertSame(1, preg_match("/'slug'\\s*=>\\s*'{$slug}',.*?'rest_url'\\s*=>\\s*'([^']+)'/s", $seed, $m));
            self::assertSame($fetcher::DEFAULT_REST_URL, $m[1]);
            self::assertNotContains($fetcher::DEFAULT_REST_URL, $fetcher::RETIRED_REST_URLS);
        }

        /** @return iterable<string, array{0: class-string, 1: string, 2: ?string, 3: string}> */
        public static function fetcherUrls(): iterable
        {
            foreach (self::feeds() as $name => [, $fetcher, $slug]) {
                yield "{$name}: no endpoint"    => [$fetcher, $slug, null, $fetcher::DEFAULT_REST_URL];
                yield "{$name}: an empty one"   => [$fetcher, $slug, '', $fetcher::DEFAULT_REST_URL];
                yield "{$name}: an operator's" => [$fetcher, $slug, 'https://node.example.org/', 'https://node.example.org'];
            }
        }

        /** @param class-string $fetcher */
        #[DataProvider('fetcherUrls')]
        public function testTheFetcherFallsBackToTheLiveDefault(string $fetcher, string $slug, ?string $restUrl, string $expected): void
        {
            $instance = new $fetcher(self::row($slug, $restUrl));
            $base     = new \ReflectionProperty($fetcher, 'base_url');
            $base->setAccessible(true);

            self::assertSame($expected, $base->getValue($instance));
        }
    }

    /** One scripted chain row; records every statement. */
    final class FeedRepointWpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var list<object> */
        public array $last_result = [];
        /** @var list<string> */
        public array $sql = [];
        public ?object $row = null;
        public bool $readFails = false;
        /** @var int|false */
        public $updateResult = 1;

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            $sql = str_replace(['%d', '%s'], ['%d', "'%s'"], $sql);
            return vsprintf($sql, $args);
        }

        /** @return int|false */
        public function query(string $sql)
        {
            $this->sql[] = $sql;
            if (str_starts_with(ltrim($sql), 'SELECT')) {
                if ($this->readFails) {
                    $this->last_error = 'MySQL server has gone away';
                    return false;
                }
                $this->last_result = $this->row === null ? [] : [$this->row];
                return count($this->last_result);
            }

            return $this->updateResult;
        }
    }
}
