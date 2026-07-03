<?php

/**
 * NFT indexer test stubs.
 *
 * Loaded ONLY from inside @runInSeparateProcess subprocesses (see
 * EvmFetcherTransfersTest + NftEthIndexerWorkerTest) so the main PHPUnit
 * process never sees these definitions. Same isolation strategy as
 * resolver-stubs.php.
 *
 * The REAL EvmFetcher and NftEthIndexerWorker load via PSR-4 in the
 * subprocess; everything they touch (transport, repositories, locks,
 * observability) is stubbed here at the production FQNs with
 * class_exists() guards so the autoloader leaves them alone.
 */

declare(strict_types=1);

// ── Global WP shims ─────────────────────────────────────────────────────────

namespace {

    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            private string $message;

            public function __construct(string $message = 'transport down')
            {
                $this->message = $message;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }

    if (!function_exists('is_wp_error')) {
        /** @param mixed $thing */
        function is_wp_error($thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }

    if (!function_exists('wp_json_encode')) {
        /** @param mixed $data */
        function wp_json_encode($data): string
        {
            return (string) json_encode($data);
        }
    }

    if (!function_exists('wp_remote_retrieve_body')) {
        /** @param mixed $response */
        function wp_remote_retrieve_body($response): string
        {
            return is_array($response) ? (string) ($response['body'] ?? '') : '';
        }
    }

    if (!function_exists('wp_remote_retrieve_response_code')) {
        /** @param mixed $response */
        function wp_remote_retrieve_response_code($response): int
        {
            // Queued fake responses default to 200 unless they carry an
            // explicit 'code'.
            return is_array($response) ? (int) ($response['code'] ?? 200) : 0;
        }
    }

    if (!function_exists('esc_url_raw')) {
        function esc_url_raw(string $url): string
        {
            return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
        }
    }

    if (!class_exists('BccTestObjectCache', false)) {
        /** In-memory wp_cache fake shared by the shims below. */
        final class BccTestObjectCache
        {
            /** @var array<string, mixed> */
            public static array $store = [];

            public static function reset(): void
            {
                self::$store = [];
            }
        }
    }

    if (!function_exists('wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get(string $key, string $group = '')
        {
            return \BccTestObjectCache::$store[$group . ':' . $key] ?? false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        /** @param mixed $value */
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            \BccTestObjectCache::$store[$group . ':' . $key] = $value;
            return true;
        }
    }

    if (!function_exists('wp_cache_delete')) {
        function wp_cache_delete(string $key, string $group = ''): bool
        {
            unset(\BccTestObjectCache::$store[$group . ':' . $key]);
            return true;
        }
    }

    if (!function_exists('apply_filters')) {
        /**
         * @param mixed $value
         * @return mixed
         */
        function apply_filters(string $hook, $value)
        {
            return $value; // No filter registry in unit scope — pass through.
        }
    }

    if (!defined('MINUTE_IN_SECONDS')) {
        define('MINUTE_IN_SECONDS', 60);
    }
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }
}

// ── Core\Log\Logger ─────────────────────────────────────────────────────────

namespace BCC\Core\Log {

    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @var list<array{level: string, message: string, context: array<string,mixed>}> */
            public static array $lines = [];

            public static function reset(): void
            {
                self::$lines = [];
            }

            /** @param array<string,mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                self::$lines[] = ['level' => 'error', 'message' => $m, 'context' => $c];
            }

            /** @param array<string,mixed> $c */
            public static function info(string $m, array $c = []): void
            {
                self::$lines[] = ['level' => 'info', 'message' => $m, 'context' => $c];
            }

            /** @param array<string,mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
                self::$lines[] = ['level' => 'warning', 'message' => $m, 'context' => $c];
            }
        }
    }
}

// ── Core\Observability\DegradationMetrics ───────────────────────────────────

namespace BCC\Core\Observability {

    if (!class_exists(__NAMESPACE__ . '\\DegradationMetrics', false)) {
        final class DegradationMetrics
        {
            /** @var list<array{subsystem: string, event: string}> */
            public static array $records = [];

            public static function reset(): void
            {
                self::$records = [];
            }

            public static function record(string $subsystem, string $event): void
            {
                self::$records[] = ['subsystem' => $subsystem, 'event' => $event];
            }
        }
    }
}

// ── Core\DB\AdvisoryLock ────────────────────────────────────────────────────

namespace BCC\Core\DB {

    if (!class_exists(__NAMESPACE__ . '\\AdvisoryLock', false)) {
        final class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool
            {
                return true;
            }

            public static function release(string $key): void
            {
            }
        }
    }
}

// ── Trust\Onchain\Support\ApiRetry — queued-response transport fake ─────────

namespace BCC\Trust\Onchain\Support {

    if (!class_exists(__NAMESPACE__ . '\\ApiRetry', false)) {
        final class ApiRetry
        {
            /** @var list<mixed> Responses returned FIFO; empty queue = WP_Error. */
            public static array $queue = [];

            /** @var list<array{url: string, body: string}> */
            public static array $calls = [];

            /**
             * Keyed by full URL → batch entry (array{code,body}|WP_Error).
             * getBatchSameHost() returns one entry per input URL, defaulting
             * to a 404 for any URL the test did not register.
             *
             * @var array<string, array{code: int, body: string}|\WP_Error>
             */
            public static array $batchResponses = [];

            /** @var list<array{urls: list<string>, chain_id: int}> */
            public static array $batchCalls = [];

            public static function reset(): void
            {
                self::$queue          = [];
                self::$calls          = [];
                self::$batchResponses = [];
                self::$batchCalls     = [];
            }

            /**
             * @param list<string>         $urls
             * @param array<string, mixed> $args
             * @param array<string, mixed> $options
             * @return array<int, array{code: int, body: string}|\WP_Error>
             */
            public static function getBatchSameHost(array $urls, array $args = [], array $options = []): array
            {
                self::$batchCalls[] = [
                    'urls'     => array_values($urls),
                    'chain_id' => (int) ($options['chain_id'] ?? 0),
                ];
                $out = [];
                foreach (array_values($urls) as $i => $url) {
                    $out[$i] = self::$batchResponses[$url]
                        ?? ['code' => 404, 'body' => ''];
                }
                return $out;
            }

            /**
             * @param array<string,mixed> $args
             * @param array<string,mixed> $options
             * @return mixed
             */
            public static function post(string $url, array $args = [], array $options = [])
            {
                self::$calls[] = ['url' => $url, 'body' => (string) ($args['body'] ?? '')];
                if (self::$queue === []) {
                    return new \WP_Error('queue exhausted');
                }
                return array_shift(self::$queue);
            }

            /**
             * @param array<string,mixed> $args
             * @param array<string,mixed> $options
             * @return mixed
             */
            public static function get(string $url, array $args = [], array $options = [])
            {
                self::$calls[] = ['url' => $url, 'body' => ''];
                if (self::$queue === []) {
                    return new \WP_Error('queue exhausted');
                }
                return array_shift(self::$queue);
            }
        }
    }
}

// ── Trust\Onchain repositories + services (worker collaborators) ────────────

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(__NAMESPACE__ . '\\CollectionRepository', false)) {
        final class CollectionRepository
        {
            /** @var array<int, array<int, object>> chainId → known collection rows */
            public static array $knownByChain = [];

            public static function reset(): void
            {
                self::$knownByChain = [];
            }

            /** @return array<int, object> */
            public static function listKnownByChain(int $chainId, int $limit): array
            {
                return array_slice(self::$knownByChain[$chainId] ?? [], 0, $limit);
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\ChainCheckpointRepository', false)) {
        final class ChainCheckpointRepository
        {
            public const STATE_HEALTHY      = 'healthy';
            public const STATE_DEGRADED     = 'degraded';
            public const STATE_BREAKER_OPEN = 'breaker_open';
            public const STATE_DISABLED     = 'disabled';

            public static ?object $checkpoint = null;
            public static int $cuRemaining    = 50000;

            /** @var list<array{chain_id: int, block: int, head: int}> */
            public static array $successes = [];

            /** @var list<array{chain_id: int, state: string, error: string}> */
            public static array $failures = [];

            /** @var list<int> */
            public static array $cuAdds = [];

            public static function reset(): void
            {
                self::$checkpoint  = null;
                self::$cuRemaining = 50000;
                self::$successes   = [];
                self::$failures    = [];
                self::$cuAdds      = [];
            }

            public static function ensureExists(int $chainId): void
            {
            }

            public static function get(int $chainId): ?object
            {
                return self::$checkpoint;
            }

            public static function cuRemainingForToday(int $chainId, int $dailyBudget): int
            {
                return self::$cuRemaining;
            }

            public static function addCuUsage(int $chainId, int $cu): int
            {
                self::$cuAdds[] = $cu;
                return array_sum(self::$cuAdds);
            }

            public static function recordSuccess(int $chainId, int $lastProcessedBlock, int $headBlock): void
            {
                self::$successes[] = [
                    'chain_id' => $chainId,
                    'block'    => $lastProcessedBlock,
                    'head'     => $headBlock,
                ];
            }

            public static function recordFailure(int $chainId, string $state, string $lastError): void
            {
                self::$failures[] = [
                    'chain_id' => $chainId,
                    'state'    => $state,
                    'error'    => $lastError,
                ];
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\ChainRepository', false)) {
        final class ChainRepository
        {
            public static ?object $chain = null;

            public static function reset(): void
            {
                self::$chain = null;
            }

            public static function getById(int $chainId): ?object
            {
                return self::$chain;
            }

            public static function getBySlug(string $slug): ?object
            {
                return (self::$chain !== null && (string) (self::$chain->slug ?? '') === $slug)
                    ? self::$chain
                    : null;
            }

            /** @return list<object> */
            public static function getActive(): array
            {
                return self::$chain !== null ? [self::$chain] : [];
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\WalletRepository', false)) {
        final class WalletRepository
        {
            public static bool $hasLinks = true;

            /** @var list<int> */
            public static array $checkedChains = [];

            /** @var list<string> */
            public static array $chainAddresses = [];

            public static function reset(): void
            {
                self::$hasLinks       = true;
                self::$checkedChains  = [];
                self::$chainAddresses = [];
            }

            public static function hasAnyLinksForChain(int $chainId): bool
            {
                self::$checkedChains[] = $chainId;
                return self::$hasLinks;
            }

            /** @return list<string> */
            public static function listAddressesForChain(int $chainId, int $limit = 200): array
            {
                return array_slice(self::$chainAddresses, 0, $limit);
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\NftSpamContractRepository', false)) {
        final class NftSpamContractRepository
        {
            public const RULE_DENY  = 'deny';
            public const RULE_ALLOW = 'allow';

            /** @var array<string, string> Keyed "chainId|contract(lower)". */
            public static array $rules = [];

            public static function reset(): void
            {
                self::$rules = [];
            }

            public static function getRule(int $chainId, string $contract): ?string
            {
                return self::$rules[$chainId . '|' . strtolower($contract)] ?? null;
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\NftHoldingsRepository', false)) {
        final class NftHoldingsRepository
        {
            /** @var list<object{chain_id: string, contract_address: string, wallets: string}> */
            public static array $walletCounts = [];

            public static function reset(): void
            {
                self::$walletCounts = [];
            }

            /** @return list<object{chain_id: string, contract_address: string, wallets: string}> */
            public static function countDistinctWalletsPerContract(int $limit = 500): array
            {
                return array_slice(self::$walletCounts, 0, $limit);
            }
        }
    }
}

namespace BCC\Trust\Onchain\Support {

    if (!class_exists(__NAMESPACE__ . '\\OnchainCircuitBreaker', false)) {
        final class OnchainCircuitBreaker
        {
            public static bool $open = false;

            /** @var list<int> */
            public static array $successChains = [];

            /** @var list<int> */
            public static array $failureChains = [];

            public static function reset(): void
            {
                self::$open          = false;
                self::$successChains = [];
                self::$failureChains = [];
            }

            public static function isOpen(int $chainId): bool
            {
                return self::$open;
            }

            public static function recordSuccess(int $chainId): void
            {
                self::$successChains[] = $chainId;
            }

            public static function recordFailure(int $chainId): void
            {
                self::$failureChains[] = $chainId;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {

    if (!class_exists(__NAMESPACE__ . '\\NftHoldingsIndexer', false)) {
        final class NftHoldingsIndexer
        {
            /** @var list<array{chain_id: int, count: int}> */
            public static array $batches = [];

            public static function reset(): void
            {
                self::$batches = [];
            }

            /**
             * @param  list<array<string,mixed>> $transfers
             * @return array{inserts: int, deletes: int, skipped: int}
             */
            public static function ingest(int $chainId, array $transfers): array
            {
                self::$batches[] = ['chain_id' => $chainId, 'count' => count($transfers)];
                return ['inserts' => count($transfers), 'deletes' => 0, 'skipped' => 0];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Factories {

    if (!class_exists(__NAMESPACE__ . '\\FetcherFactory', false)) {
        final class FetcherFactory
        {
            public static function has_driver(string $chainType): bool
            {
                return $chainType === 'evm';
            }

            /** @param object $chain */
            public static function make_for_chain(object $chain): object
            {
                return new \BCC\Trust\Onchain\Fetchers\EvmFetcher($chain);
            }
        }
    }
}
