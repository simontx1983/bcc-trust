<?php

declare(strict_types=1);

namespace BCC\Core\Log {
    // bcc-core is not autoloaded in the unit context.
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @var list<string> */
            public static array $errors = [];

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                self::$errors[] = $m;
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
        }
    }
}

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Core\Log\Logger;
    use BCC\Trust\Onchain\Fetchers\PolkadotFetcher;
    use BCC\Trust\Onchain\Support\ProviderOutcomeReceipt;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    /**
     * F-68 review L1 — a SUCCESSFUL Subscan response (code 0) can carry
     * "data": null, e.g. the page past the end when the validator count is an
     * exact multiple of 100. apiPost() returned that as null — the FAILURE
     * value — so after F-68 fetch_all_validators() threw and Polkadot was
     * marked partial on every such run. It is now an empty answer ([]).
     */
    #[CoversClass(PolkadotFetcher::class)]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class PolkadotSubscanNullDataTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            if (!defined('ABSPATH')) {
                define('ABSPATH', sys_get_temp_dir() . '/');
            }
            Logger::$errors = [];
        }

        private static function parse(string $body): ?array
        {
            $m = new ReflectionMethod(PolkadotFetcher::class, 'parseSubscanBody');
            $m->setAccessible(true);
            /** @var array<string, mixed>|null $out */
            $out = $m->invoke(null, $body, '/api/scan/staking/validators');
            return $out;
        }

        public function testCodeZeroWithNullDataIsAnEmptyAnswerNotAFailure(): void
        {
            self::assertSame([], self::parse('{"code":0,"message":"Success","data":null}'));
            self::assertSame([], Logger::$errors, 'an empty answer is not logged as an error');
        }

        public function testCodeZeroWithNoDataKeyIsAnEmptyAnswerToo(): void
        {
            self::assertSame([], self::parse('{"code":0,"message":"Success"}'));
        }

        public function testCodeZeroWithDataIsReturnedAsIs(): void
        {
            self::assertSame(['list' => [['x' => 1]], 'count' => 1], self::parse('{"code":0,"data":{"list":[{"x":1}],"count":1}}'));
        }

        public function testANonZeroCodeIsStillAFailure(): void
        {
            self::assertNull(self::parse('{"code":10004,"message":"Record Not Found","data":null}'));
            self::assertNotSame([], Logger::$errors);
        }

        public function testNonJsonAndMalformedDataAreStillFailures(): void
        {
            self::assertNull(self::parse('<html>502</html>'));
            self::assertNull(self::parse('{"code":0,"data":"oops"}'), 'a non-object data field is malformed, not empty');
        }

        public function testAnExactMultipleOfOneHundredEndsCleanlyInsteadOfThrowing(): void
        {
            // 100 validators, then the past-the-end page: code 0, data null → [].
            $f = new NullDataPagesPolkadotFetcher((object) [
                'id' => 19, 'slug' => 'polkadot', 'name' => 'Polkadot', 'chain_type' => 'polkadot',
                'decimals' => 10, 'rest_url' => 'https://polkadot.api.subscan.io',
            ]);

            $rows = $f->fetch_all_validators(new ProviderOutcomeReceipt());

            self::assertNotSame([], $rows, 'the 100 validators are indexed');
            self::assertSame([0, 1], $f->requestedPages);
        }
    }

    /** Page 0 = 100 validators; page 1 = Subscan's real past-the-end body, parsed by the real parser. */
    final class NullDataPagesPolkadotFetcher extends PolkadotFetcher
    {
        /** @var list<int> */
        public array $requestedPages = [];

        protected function apiPost(string $path, array $body, ?ProviderOutcomeReceipt $outcome = null): ?array
        {
            $page = (int) ($body['page'] ?? -1);
            $this->requestedPages[] = $page;
            if ($page === 0) {
                $list = [];
                for ($i = 0; $i < 100; $i++) {
                    $list[] = [
                        'stash_account_display'      => ['address' => sprintf('1stash%05d', $i)],
                        'controller_account_display' => ['address' => sprintf('1ctrl%05d', $i)],
                        'bonded_total'               => (string) (1000000 - $i),
                        'validator_prefs_value'      => 0,
                    ];
                }
                return ['list' => $list];
            }
            $m = new ReflectionMethod(PolkadotFetcher::class, 'parseSubscanBody');
            $m->setAccessible(true);
            /** @var array<string, mixed>|null $out */
            $out = $m->invoke(null, '{"code":0,"message":"Success","data":null}', $path);
            return $out;
        }
    }
}
