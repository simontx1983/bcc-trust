<?php

declare(strict_types=1);

/**
 * Collaborator fakes so ChainRefreshService::index_validators() can be RUN by
 * the unit suite (F-62).
 *
 * Every collaborator that can change persistent state records its calls in
 * IndexRunRecorder, so a test can assert which destructive steps a run
 * reached. The fetcher is a fake that returns whatever the test queued per
 * chain id; CosmosFetcherBondedValidatorsTest covers the real fetcher's
 * "incomplete walk → []" half.
 *
 * ⚠ LOAD ONLY IN A SEPARATE PROCESS, BEFORE ANY OF THESE CLASSES IS
 * AUTOLOADED. Each fake is behind class_exists(…, false), so if the real class
 * was already loaded the fake is silently skipped and the test would exercise
 * real code against no database. IndexRunPartialSkipsDestructiveStepsTest
 * asserts the fakes are the ones in play.
 */

namespace {
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }

    final class IndexRunRecorder
    {
        /** @var list<object> */
        public static array $chains = [];

        /** @var array<int, list<array<string, mixed>>> chain id → what fetch_all_validators returns */
        public static array $fetchResults = [];

        /** @var array<string, mixed> */
        public static array $options = [];

        /** @var list<array{0: string, 1: mixed}> every call, in order */
        public static array $calls = [];

        public static function reset(): void
        {
            self::$chains       = [];
            self::$fetchResults = [];
            self::$options      = [];
            self::$calls        = [];
        }

        public static function record(string $what, mixed $detail = null): void
        {
            self::$calls[] = [$what, $detail];
        }

        /** @return list<mixed> */
        public static function callsOf(string $what): array
        {
            $out = [];
            foreach (self::$calls as [$name, $detail]) {
                if ($name === $what) {
                    $out[] = $detail;
                }
            }
            return $out;
        }
    }
}

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

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void
            {
                \IndexRunRecorder::record('log.info', $m);
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
                \IndexRunRecorder::record('log.warning', $m);
            }

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                \IndexRunRecorder::record('log.error', $m);
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\ChainRepository', false)) {
        final class ChainRepository
        {
            /** @return list<object> */
            public static function getActive(?string $chainType = null): array
            {
                return array_values(array_filter(
                    \IndexRunRecorder::$chains,
                    static fn (object $c): bool => $chainType === null || $c->chain_type === $chainType
                ));
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\ValidatorRepository', false)) {
        final class ValidatorRepository
        {
            /** @return array<int, object> */
            public static function getCountsByChain(): array
            {
                return [];
            }

            /**
             * @param list<array<string, mixed>> $validators
             * @return array{total: int, new: int, updated: int, unchanged: int, refreshed: int}
             */
            public static function bulkUpsert(array $validators, int $ttlSeconds = 3600): array
            {
                \IndexRunRecorder::record('validators.bulkUpsert', count($validators));
                $n = count($validators);
                return ['total' => $n, 'new' => $n, 'updated' => 0, 'unchanged' => 0, 'refreshed' => 0];
            }

            /**
             * @param list<string> $operatorAddresses
             * @return list<int>
             */
            public static function idsForOperatorAddresses(int $chainId, array $operatorAddresses): array
            {
                \IndexRunRecorder::record('validators.idsForOperatorAddresses', $chainId);
                return array_keys($operatorAddresses);
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
                return true;
            }

            public static function make_for_chain(object $chain): object
            {
                $chainId = (int) $chain->id;

                return new class ($chainId) {
                    public function __construct(private readonly int $chainId)
                    {
                    }

                    public function supports_feature(string $feature): bool
                    {
                        return $feature === 'validator';
                    }

                    /** @return list<array<string, mixed>> */
                    public function fetch_all_validators(?object $outcome = null): array
                    {
                        \IndexRunRecorder::record('fetch_all_validators', $this->chainId);
                        return \IndexRunRecorder::$fetchResults[$this->chainId] ?? [];
                    }
                };
            }
        }
    }
}

namespace BCC\Trust\Onchain\Support {
    if (!class_exists(__NAMESPACE__ . '\\OnchainCircuitBreaker', false)) {
        final class OnchainCircuitBreaker
        {
            public static function isResting(int $chainId): bool
            {
                return false;
            }

            public static function recordFailure(int $chainId): void
            {
                \IndexRunRecorder::record('breaker.recordFailure', $chainId);
            }

            public static function recordSuccess(int $chainId): void
            {
                \IndexRunRecorder::record('breaker.recordSuccess', $chainId);
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!class_exists(__NAMESPACE__ . '\\EnrichmentScheduler', false)) {
        final class EnrichmentScheduler
        {
            public static function markDeadValidators(): int
            {
                \IndexRunRecorder::record('markDeadValidators');
                return 0;
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\ValidatorPageMinter', false)) {
        final class ValidatorPageMinter
        {
            /**
             * @param mixed $scope
             * @return array{scanned: int, minted: int, skipped: int, errors: int}
             */
            public static function mintMissing(?int $chainId, bool $dryRun, int $max, mixed $scope = null): array
            {
                \IndexRunRecorder::record('mintMissing', $chainId);
                return ['scanned' => 0, 'minted' => 0, 'skipped' => 0, 'errors' => 0];
            }
        }
    }

    // Namespace-local, so the service's unqualified calls resolve here first
    // and the global function table other suites share is never touched.
    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return array_key_exists($name, \IndexRunRecorder::$options)
                ? \IndexRunRecorder::$options[$name]
                : $default;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\update_option')) {
        function update_option(string $name, mixed $value, mixed $autoload = null): bool
        {
            \IndexRunRecorder::record('update_option', $name);
            \IndexRunRecorder::$options[$name] = $value;
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\current_time')) {
        function current_time(string $type, bool $gmt = false): string
        {
            return '2026-11-11 12:00:00';
        }
    }
}
