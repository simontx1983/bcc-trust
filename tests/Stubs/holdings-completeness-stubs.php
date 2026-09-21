<?php

declare(strict_types=1);

/**
 * Doubles for driving {@see \BCC\Trust\Onchain\Services\HoldingsService}'s
 * walk-cache-gate chain end to end.
 *
 * ── WHY A DEDICATED SET ─────────────────────────────────────────────────
 * `nft-indexer-stubs.php` fakes a `FetcherFactory` that only ever builds an
 * EVM fetcher and a `WalletRepository` with no `markHoldingsRefreshed()`.
 * Both are exactly right for what that file was written for, and neither can
 * express the thing under test here: a wallet walk that PARTLY failed, and
 * whether the service then wrote anything.
 *
 * ── WHAT IS REAL ────────────────────────────────────────────────────────
 * `HoldingsService` itself, and `NftCollectionIdentifier` underneath it.
 * The fetcher is a scriptable double because the point of these tests is the
 * SERVICE's reaction to each return shape — the fetcher's own production of
 * those shapes is pinned separately, over a real wire, in
 * `CosmosEndpointEnforcementTest` and `CosmosFetcherListHoldingsTest`.
 *
 * ⚠ THE DOUBLE RECORDS WRITES RATHER THAN ASSERTING THEM. "No transient was
 * written" and "no freshness stamp was left" are the whole claim, and a
 * double that quietly accepted either would make the suite green for the
 * wrong reason.
 */

namespace {
    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            /** @var array<string, list<string>> */
            private array $errors = [];

            /** @param mixed $data */
            public function __construct(string $code = '', string $message = '', $data = null)
            {
                if ($code !== '') {
                    $this->errors[$code][] = $message;
                }
            }

            public function get_error_code(): string
            {
                $keys = array_keys($this->errors);

                return $keys === [] ? '' : (string) $keys[0];
            }
        }
    }

    if (!class_exists('BccHoldingsWorld', false)) {
        /**
         * Everything the service is allowed to touch, and everything it did.
         */
        final class BccHoldingsWorld
        {
            /** @var array<string, mixed> transient key => value */
            public static array $transients = [];

            /** @var list<string> every transient key WRITTEN, in order */
            public static array $transientWrites = [];

            /** @var list<int> every wallet link id stamped as freshly refreshed */
            public static array $refreshStamps = [];

            /**
             * Scripted `list_holdings()` answers, oldest first.
             *
             * @var list<array<string, mixed>>
             */
            public static array $listAnswers = [];

            /** Scripted `count_holdings()` answer: an int, or null for UNKNOWN. */
            public static ?int $countAnswer = null;

            /** True when `count_holdings()` should report UNKNOWN. */
            public static bool $countIsUnknown = false;

            /** @var list<string> every fetcher method actually called */
            public static array $fetcherCalls = [];

            public static function reset(): void
            {
                self::$transients      = [];
                self::$transientWrites = [];
                self::$refreshStamps   = [];
                self::$listAnswers     = [];
                self::$countAnswer     = null;
                self::$countIsUnknown  = false;
                self::$fetcherCalls    = [];
            }
        }
    }

    if (!function_exists('get_transient')) {
        /** @return mixed */
        function get_transient(string $key)
        {
            return \BccHoldingsWorld::$transients[$key] ?? false;
        }
    }

    if (!function_exists('set_transient')) {
        /** @param mixed $value */
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            \BccHoldingsWorld::$transients[$key]  = $value;
            \BccHoldingsWorld::$transientWrites[] = $key;

            return true;
        }
    }

    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            unset(\BccHoldingsWorld::$transients[$key]);

            return true;
        }
    }

    if (!function_exists('is_wp_error')) {
        /** @param mixed $thing */
        function is_wp_error($thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }

    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }
    if (!defined('MINUTE_IN_SECONDS')) {
        define('MINUTE_IN_SECONDS', 60);
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @var list<string> */
            public static array $lines = [];

            public static function reset(): void
            {
                self::$lines = [];
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $m, array $context = []): void
            {
                self::$lines[] = 'warning ' . $m;
            }

            /** @param array<string, mixed> $context */
            public static function error(string $m, array $context = []): void
            {
                self::$lines[] = 'error ' . $m;
            }

            /** @param array<string, mixed> $context */
            public static function info(string $m, array $context = []): void
            {
                self::$lines[] = 'info ' . $m;
            }

            /** @param array<string, mixed> $context */
            public static function debug(string $m, array $context = []): void
            {
                self::$lines[] = 'debug ' . $m;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Fetchers {
    if (!class_exists(BccScriptedFetcher::class, false)) {
        /**
         * A fetcher whose every answer is scripted.
         *
         * ⚠ DECLARED AGAINST THE REAL `FetcherInterface`, not merely
         * duck-typed. `countFromCacheOrFetch()` type-hints the interface, so
         * a double that only *looked* like a fetcher could not reach the gate
         * path at all — and a double that drifted from the interface would
         * stop being able to, loudly, which is the point.
         *
         * The three methods this path uses carry the scripting; the rest
         * return their empty shapes and are never called here.
         */
        final class BccScriptedFetcher implements \BCC\Trust\Onchain\Contracts\FetcherInterface
        {
            /** @return array<string, mixed> */
            public function fetch_validator(string $address): array
            {
                return [];
            }

            /** @return array<int, array<string, mixed>> */
            public function fetch_all_validators(?\BCC\Trust\Onchain\Support\ProviderOutcomeReceipt $outcome = null): array
            {
                return [];
            }

            /** @return array<string, mixed> */
            public function enrich_validator(string $address, ?object $existingRow = null): array
            {
                return [];
            }

            /** @return array<int, array{validator_address: string, shares?: string|null, amount?: float|null}> */
            public function fetch_delegations(string $delegatorAddress): array
            {
                return [];
            }

            /** @return array<int, array<string, mixed>> */
            public function fetch_collections(string $walletAddress, int $chainId = 0): array
            {
                return [];
            }

            /** @return array<int, array<string, mixed>> */
            public function fetch_top_collections(int $limit = 100): array
            {
                return [];
            }

            public function get_chain(): object
            {
                return (object) ['id' => 8, 'slug' => 'cosmos', 'chain_type' => 'cosmos'];
            }

            public function last_fetch_error(): ?string
            {
                return null;
            }

            public function supports_feature(string $feature): bool
            {
                \BccHoldingsWorld::$fetcherCalls[] = 'supports_feature:' . $feature;

                return $feature === 'holdings_list';
            }

            /** @return array<string, mixed> */
            public function list_holdings(string $wallet, ?string $cursor = null): array
            {
                \BccHoldingsWorld::$fetcherCalls[] = 'list_holdings';

                if (\BccHoldingsWorld::$listAnswers === []) {
                    return ['items' => [], 'truncated' => false, 'cursor' => null, 'complete' => true];
                }

                return array_shift(\BccHoldingsWorld::$listAnswers);
            }

            public function count_holdings(string $wallet, string $contract): ?int
            {
                \BccHoldingsWorld::$fetcherCalls[] = 'count_holdings';

                return \BccHoldingsWorld::$countIsUnknown ? null : \BccHoldingsWorld::$countAnswer;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Factories {
    if (!class_exists(FetcherFactory::class, false)) {
        final class FetcherFactory
        {
            public static function has_driver(string $chainType): bool
            {
                return $chainType !== '';
            }

            public static function make_for_chain(object $chain): object
            {
                return new \BCC\Trust\Onchain\Fetchers\BccScriptedFetcher();
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(WalletRepository::class, false)) {
        final class WalletRepository
        {
            /** @return list<object> */
            public static function getForUser(int $userId, ?string $slug = null, bool $verifiedOnly = false): array
            {
                return [];
            }

            /**
             * ⚠ THE FRESHNESS STAMP. Recorded, never silently accepted: "we
             * last reached this chain successfully at T" is a CLAIM, and a
             * partial read must not be able to make it.
             */
            public static function markHoldingsRefreshed(int $walletLinkId): bool
            {
                \BccHoldingsWorld::$refreshStamps[] = $walletLinkId;

                return true;
            }
        }
    }

    if (!class_exists(ChainRepository::class, false)) {
        final class ChainRepository
        {
            /** @var array<int, object> */
            public static array $chains = [];

            public static function reset(): void
            {
                self::$chains = [];
            }

            public static function seed(int $id, string $slug, string $chainType): void
            {
                self::$chains[$id] = (object) [
                    'id'         => $id,
                    'slug'       => $slug,
                    'chain_type' => $chainType,
                    'rest_url'   => 'https://cosmos-api.polkachu.com',
                ];
            }

            public static function getById(int $chainId): ?object
            {
                return self::$chains[$chainId] ?? null;
            }

            public static function getBySlug(string $slug): ?object
            {
                foreach (self::$chains as $c) {
                    if ($c->slug === $slug) {
                        return $c;
                    }
                }

                return null;
            }
        }
    }

    if (!class_exists(NftHoldingsRepository::class, false)) {
        final class NftHoldingsRepository
        {
            /** @var array<int, int> wallet link id => visible balance */
            public static array $byWallet = [];

            /**
             * @param  list<int> $walletLinkIds
             * @return array<int, int>
             */
            public static function countVisibleByContract(int $chainId, string $contract, array $walletLinkIds): array
            {
                return self::$byWallet;
            }
        }
    }
}
