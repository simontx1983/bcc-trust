<?php
/**
 * Fixture-backed stubs for DelegationEligibilityTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses).
 * Fakes the wallet/chain repositories, the fetcher factory + Cosmos
 * fetcher, the delegation write-through repository, and the object cache
 * at their FQNs so the REAL DelegationEligibilityService verdict logic
 * runs against scripted LCD answers.
 *
 * Fixture shape ($GLOBALS['__bcc_deleg_fixture']):
 *   wallets      list<object>                     verified wallet rows
 *   chain_type   string|null                      null → chain row missing
 *   responses    array<string, list|null>         wallet_address → LCD answer
 *                                                 (null = transport failure)
 *   fetch_calls  list<string>                     recorded fetch addresses
 *   written      list<array{int,int,int}>         write-through calls
 */

declare(strict_types=1);

namespace {
    // DelegationEligibilityService's CACHE_TTL class constant is evaluated
    // at class-load time, so this WP constant must exist before the
    // autoloader pulls the service in.
    if (!defined('MINUTE_IN_SECONDS')) {
        define('MINUTE_IN_SECONDS', 60);
    }
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(WalletRepository::class, false)) {
        class WalletRepository
        {
            /** @return list<object> */
            public static function getForUser(int $userId, ?string $walletType = null, bool $verifiedOnly = false): array
            {
                return $GLOBALS['__bcc_deleg_fixture']['wallets'] ?? [];
            }
        }
    }

    if (!class_exists(ChainRepository::class, false)) {
        class ChainRepository
        {
            public static function getById(int $chainId): ?object
            {
                $type = array_key_exists('chain_type', $GLOBALS['__bcc_deleg_fixture'])
                    ? $GLOBALS['__bcc_deleg_fixture']['chain_type']
                    : 'cosmos';
                if ($type === null) {
                    return null;
                }
                return (object) ['id' => (string) $chainId, 'slug' => 'cosmos', 'chain_type' => $type];
            }
        }
    }

    if (!class_exists(DelegationRepository::class, false)) {
        class DelegationRepository
        {
            /** @param array<int, array<string, mixed>> $delegations */
            public static function replaceForWalletLink(
                int $walletLinkId,
                int $chainId,
                array $delegations,
                int $ttlSeconds = 21600
            ): int {
                $GLOBALS['__bcc_deleg_fixture']['written'][] = [$walletLinkId, $chainId, count($delegations)];
                return count($delegations);
            }
        }
    }
}

namespace BCC\Trust\Onchain\Fetchers {
    if (!class_exists(CosmosFetcher::class, false)) {
        class CosmosFetcher
        {
            public function supports_feature(string $feature): bool
            {
                return $feature === 'delegations';
            }

            /** @return array<int, array{validator_address: string, shares: string|null, amount: float|null}>|null */
            public function fetch_delegations_result(string $delegatorAddress): ?array
            {
                $GLOBALS['__bcc_deleg_fixture']['fetch_calls'][] = $delegatorAddress;
                $responses = $GLOBALS['__bcc_deleg_fixture']['responses'] ?? [];
                // array_key_exists: a present null is a transport failure and
                // `??` would collapse it into an empty set (fail-open).
                return array_key_exists($delegatorAddress, $responses)
                    ? $responses[$delegatorAddress]
                    : [];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Factories {
    if (!class_exists(FetcherFactory::class, false)) {
        class FetcherFactory
        {
            public static function has_driver(string $chainType): bool
            {
                return $chainType === 'cosmos';
            }

            public static function make_for_chain(object $chain): \BCC\Trust\Onchain\Fetchers\CosmosFetcher
            {
                return new \BCC\Trust\Onchain\Fetchers\CosmosFetcher();
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        class Logger
        {
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void {}
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    // The service calls the object cache unqualified from its own
    // namespace, so namespace-local definitions win over the globals.
    if (!function_exists('BCC\\Trust\\Onchain\\Services\\wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get(string $key, string $group = '', bool $force = false, mixed &$found = null)
        {
            $hit   = isset($GLOBALS['__bcc_deleg_cache'][$group][$key]);
            $found = $hit;
            return $hit ? $GLOBALS['__bcc_deleg_cache'][$group][$key] : false;
        }
    }
    if (!function_exists('BCC\\Trust\\Onchain\\Services\\wp_cache_set')) {
        function wp_cache_set(string $key, mixed $value, string $group = '', int $expire = 0): bool
        {
            $GLOBALS['__bcc_deleg_cache'][$group][$key] = $value;
            return true;
        }
    }
}
