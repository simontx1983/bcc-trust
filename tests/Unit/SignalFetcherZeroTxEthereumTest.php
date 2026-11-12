<?php

declare(strict_types=1);

namespace {
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }
    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            public function __construct(private string $code = '', private string $message = '')
            {
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }

    /** Etherscan response bodies, FIFO. */
    final class EtherscanWorld
    {
        /** @var list<string|\WP_Error> */
        public static array $responses = [];

        public static int $requests = 0;

        public static function reset(): void
        {
            self::$responses = [];
            self::$requests  = 0;
        }

        /** @param mixed $result */
        public static function respond(string $status, string $message, mixed $result): void
        {
            self::$responses[] = (string) json_encode(['status' => $status, 'message' => $message, 'result' => $result]);
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\ChainRepository', false)) {
        final class ChainRepository
        {
            public static function resolveId(string $slug): ?int
            {
                return 1;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Support {
    if (!class_exists(__NAMESPACE__ . '\\ApiRetry', false)) {
        final class ApiRetry
        {
            /**
             * @param array<string, mixed> $args
             * @param array<string, mixed> $options
             * @return array{body: string}|\WP_Error
             */
            public static function get(string $url, array $args = [], array $options = [])
            {
                \EtherscanWorld::$requests++;
                if (\EtherscanWorld::$responses === []) {
                    return new \WP_Error('down', 'no response queued');
                }
                $next = array_shift(\EtherscanWorld::$responses);
                return $next instanceof \WP_Error ? $next : ['body' => $next];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!function_exists(__NAMESPACE__ . '\\add_query_arg')) {
        /** @param array<string, mixed> $args */
        function add_query_arg(array $args, string $url): string
        {
            return $url . '?' . http_build_query($args);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\is_wp_error')) {
        function is_wp_error(mixed $thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body(mixed $response): string
        {
            return is_array($response) ? (string) ($response['body'] ?? '') : '';
        }
    }
}

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Trust\Onchain\Services\SignalFetcher;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    /**
     * F-42 — a zero-transaction Ethereum wallet is a valid, empty answer.
     *
     * Etherscan reports an address with no history as status "0", message
     * "No transactions found", result []. etherscanRequest() treated every
     * status other than "1" as a failure, so fetchEthereum() returned null,
     * nothing was cached, and the wallet was refetched on every cycle.
     */
    #[CoversClass(SignalFetcher::class)]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class SignalFetcherZeroTxEthereumTest extends TestCase
    {
        private const ADDR = '0x00000000000000000000000000000000000000aa';

        protected function setUp(): void
        {
            parent::setUp();
            if (!defined('ABSPATH')) {
                define('ABSPATH', sys_get_temp_dir() . '/');
            }
            if (!defined('BCC_ETHERSCAN_API_KEY')) {
                define('BCC_ETHERSCAN_API_KEY', 'test-key');
            }
            \EtherscanWorld::reset();
        }

        /** @return array<string, mixed>|null */
        private static function fetch(): ?array
        {
            $m = new ReflectionMethod(SignalFetcher::class, 'fetchEthereum');
            /** @var array<string, mixed>|null */
            return $m->invoke(null, self::ADDR);
        }

        public function testAWalletWithNoTransactionsIsAnEmptyAnswerNotAFailure(): void
        {
            \EtherscanWorld::respond('0', 'No transactions found', []);

            $r = self::fetch();

            self::assertIsArray($r, 'a zero-tx wallet must produce a cacheable result, not null');
            self::assertSame(0, $r['tx_count']);
            self::assertSame(0, $r['wallet_age_days']);
            self::assertNull($r['first_tx_at']);
            self::assertSame(0, $r['contract_count']);
        }

        /** Every OTHER status "0" is still a failure — never cache a rate limit as "empty". */
        public function testARateLimitOrKeyErrorIsStillAFailure(): void
        {
            \EtherscanWorld::respond('0', 'NOTOK', 'Max rate limit reached');
            self::assertNull(self::fetch());

            \EtherscanWorld::respond('0', 'NOTOK', 'Invalid API Key');
            self::assertNull(self::fetch());
        }

        /** "No transactions found" but a NON-empty result is malformed, not empty. */
        public function testTheEmptyAnswerRequiresAnEmptyResult(): void
        {
            \EtherscanWorld::respond('0', 'No transactions found', [['hash' => '0x1']]);

            self::assertNull(self::fetch());
        }

        public function testANormalHistoryIsUnchanged(): void
        {
            $ts = time() - 10 * DAY_IN_SECONDS;
            \EtherscanWorld::respond('1', 'OK', [
                ['timeStamp' => (string) $ts, 'from' => self::ADDR, 'contractAddress' => ''],
                ['timeStamp' => (string) ($ts + 60), 'from' => self::ADDR, 'contractAddress' => '0xc0ffee'],
            ]);

            $r = self::fetch();

            self::assertIsArray($r);
            self::assertSame(2, $r['tx_count']);
            self::assertSame(10, $r['wallet_age_days']);
            self::assertSame(1, $r['contract_count']);
        }
    }
}
