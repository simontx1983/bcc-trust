<?php

/**
 * Stubs for the Verify Collections VC-A handler tests.
 *
 * Layers on the Batch 1 admin-action stubs (wp_die / wp_safe_redirect /
 * check_admin_referer / Logger / AuditLogger shims) and adds the
 * collection-side collaborators plus the transient API the PRG notice
 * carrier uses.
 */

declare(strict_types=1);

namespace {

    // Deliberately the ACTION stubs only. The Batch 1 *render* stubs define
    // their own narrower CollectionRepository, which would win the
    // class_exists guard and shadow the richer one below — so the few render
    // helpers the form-wiring tests need are defined here instead.
    require_once __DIR__ . '/onchain-admin-action-stubs.php';

    if (!function_exists('esc_url')) {
        function esc_url(string $url): string
        {
            return htmlspecialchars($url, ENT_QUOTES);
        }
    }

    if (!function_exists('esc_attr')) {
        function esc_attr(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES);
        }
    }

    if (!function_exists('wp_nonce_field')) {
        /**
         * Emits the nonce ACTION verbatim as a data attribute so a DOM test
         * can assert the exact scope — the security-relevant property.
         */
        function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
        {
            $html = '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES)
                . '" data-nonce-action="' . htmlspecialchars($action, ENT_QUOTES) . '" value="nonce">';
            if ($echo) {
                echo $html;
            }
            return $html;
        }
    }

    if (!function_exists('wp_json_encode')) {
        /**
         * VC-B1: the Hide/Unhide confirmation goes through
         * AdminActionSupport::confirmLiteral(), which JSON-encodes the text
         * so a quote or newline in it cannot break out of the onclick.
         *
         * @param mixed $data
         * @return string|false
         */
        function wp_json_encode($data, int $options = 0, int $depth = 512)
        {
            return json_encode($data, $options, $depth);
        }
    }

    if (!function_exists('esc_url_raw')) {
        function esc_url_raw(string $url): string
        {
            return preg_match('#^https?://[^\s<>"]+$#i', $url) === 1 ? $url : '';
        }
    }

    if (!class_exists('BccTransientStore', false)) {
        final class BccTransientStore
        {
            /** @var array<string, mixed> */
            public static array $data = [];

            public static function reset(): void
            {
                self::$data = [];
            }
        }
    }

    if (!function_exists('set_transient')) {
        /** @param mixed $value */
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            \BccTransientStore::$data[$key] = $value;
            return true;
        }
    }

    if (!function_exists('get_transient')) {
        /** @return mixed */
        function get_transient(string $key)
        {
            return \BccTransientStore::$data[$key] ?? false;
        }
    }

    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            unset(\BccTransientStore::$data[$key]);
            return true;
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(CollectionRepository::class, false)) {
        final class CollectionRepository
        {
            /** @var list<array{verify: list<int>, unverify: list<int>}> */
            public static array $bulkCalls = [];
            public static int $bulkChanged = 0;

            /** @var list<array<string, mixed>> */
            public static array $added = [];
            public static int $addManualResult = 1234;

            /** @var list<int> */
            public static array $deleted = [];
            public static bool $deleteResult = true;

            /** @var array<int, object> */
            public static array $rows = [];

            public static int $upsertWritten = 1;

            /**
             * @param list<int> $verify
             * @param list<int> $unverify
             */
            public static function setVerifiedBulk(array $verify, array $unverify): int
            {
                self::$bulkCalls[] = ['verify' => $verify, 'unverify' => $unverify];
                return self::$bulkChanged;
            }

            /** @param array<string, mixed> $data */
            public static function addManual(...$args): int
            {
                self::$added[] = $args;
                return self::$addManualResult;
            }

            /** @var list<array{rows: list<array<string, mixed>>, ttl: int}> */
            public static array $upsertCalls = [];

            /** @param list<array<string, mixed>> $rows */
            public static function bulkUpsert(array $rows, int $ttl): int
            {
                self::$upsertCalls[] = ['rows' => $rows, 'ttl' => $ttl];
                return self::$upsertWritten;
            }

            public static function deleteById(int $id): bool
            {
                self::$deleted[] = $id;
                return self::$deleteResult;
            }

            /** @param list<int> $ids @return array<int, object> */
            public static function findManyByIds(array $ids): array
            {
                $out = [];
                foreach ($ids as $id) {
                    if (isset(self::$rows[$id])) {
                        $out[$id] = self::$rows[$id];
                    }
                }
                return $out;
            }

            public static function getByIdWithChain(int $id): ?object
            {
                return self::$rows[$id] ?? null;
            }

            /** Resolves through the table's uniqueness key (chain_id, contract). */
            public static function findByChainContract(int $chainId, string $contract): ?object
            {
                if (self::$findByChainContractReturnsNull) {
                    return null;
                }
                foreach (self::$rows as $row) {
                    if ((int) $row->chain_id === $chainId
                        && (string) $row->contract_address === $contract
                    ) {
                        return $row;
                    }
                }
                return null;
            }

            public static bool $findByChainContractReturnsNull = false;

            public static function seed(int $id, int $chainId = 4, string $contract = '0xabc'): void
            {
                self::$rows[$id] = (object) [
                    'id'               => $id,
                    'chain_id'         => $chainId,
                    'contract_address' => $contract,
                    'name'             => 'Seeded',
                    // VC-B1: the hide handler names the collection back to
                    // the operator, falling back to the contract.
                    'collection_name'  => 'Seeded Collection',
                    'chain_type'       => 'cosmos',
                    'slug'             => 'cosmos',
                    // getByIdWithChain() joins the chain, so the probe path
                    // reads this; omitting it produced an undefined-property
                    // warning that masked the behaviour under test.
                    'chain_slug'       => 'cosmos',
                ];
            }

            public static function reset(): void
            {
                self::$bulkCalls = [];
                self::$bulkChanged = 0;
                self::$added = [];
                self::$deleted = [];
                self::$deleteResult = true;
                self::$rows = [];
                self::$upsertWritten = 1;
                self::$upsertCalls = [];
                self::$findByChainContractReturnsNull = false;
            }
        }
    }

    if (!class_exists(GatedGroupRepository::class, false)) {
        final class GatedGroupRepository
        {
            /** @var array<string, int> */
            public static array $groups = [];

            public static function findGroupForCollection(int $chainId, string $contract): ?int
            {
                return self::$groups[$chainId . '|' . $contract] ?? null;
            }

            public static function reset(): void
            {
                self::$groups = [];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {

    // ── VC-B1 Hide/Unhide collaborators ────────────────────────────────

    if (!class_exists(RepositoryReadFailure::class, false)) {
        /** Thrown when a read could not be completed — never "no row". */
        final class RepositoryReadFailure extends \RuntimeException
        {
            public function __construct(private string $method = 'deniedFlag', private string $db = 'db gone')
            {
                parent::__construct('repository read failed: ' . $method);
            }

            public function repositoryMethod(): string
            {
                return $this->method;
            }

            public function dbError(): string
            {
                return $this->db;
            }
        }
    }

    if (!class_exists(CosmwasmContractRepository::class, false)) {
        /** The scanner's CACHED deny flag — downstream of the rule. */
        final class CosmwasmContractRepository
        {
            /** null = the scanner never inventoried this contract. */
            public static ?bool $flag = null;
            public static bool $throwOnRead = false;
            public static int $reads = 0;

            public static function deniedFlag(int $chainId, string $contract): ?bool
            {
                self::$reads++;

                if (self::$throwOnRead) {
                    throw new RepositoryReadFailure('deniedFlag', 'SQLSTATE[HY000] stub');
                }

                return self::$flag;
            }

            public static function reset(): void
            {
                self::$flag        = null;
                self::$throwOnRead = false;
                self::$reads       = 0;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {

    if (!class_exists(CosmwasmDiscoveryService::class, false)) {
        final class CosmwasmDiscoveryService
        {
            /** @var list<array{chain: int, contracts: list<string>}> */
            public static array $syncCalls = [];
            public static ?\Throwable $syncThrows = null;

            /** @param list<string> $contracts */
            public static function syncDenyFlags(int $chainId, array $contracts): int
            {
                self::$syncCalls[] = ['chain' => $chainId, 'contracts' => $contracts];

                if (self::$syncThrows !== null) {
                    throw self::$syncThrows;
                }

                return count($contracts);
            }

            public static function reset(): void
            {
                self::$syncCalls  = [];
                self::$syncThrows = null;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Fetchers {

    if (!class_exists(CosmosFetcher::class, false)) {
        /**
         * The authoritative CW-721 probe seam for both the Test CW-721 button
         * and the Cosmos Add form.
         *
         * `$throws` is an EXPLICIT fault switch rather than a malformed
         * fixture: a test that provokes a fault by feeding the probe garbage
         * proves only that the garbage was rejected. Setting $throws makes
         * the fault unambiguous and deterministic, so an assertion about
         * failure handling is an assertion about failure handling.
         */
        final class CosmosFetcher
        {
            public static ?\Throwable $throws = null;

            /** @var array<string, mixed>|null Probe result when it does not throw. */
            public static ?array $contractInfo = ['name' => 'Seeded CW721', 'symbol' => 'SEED'];

            /** @var list<string> Every contract probed, in call order. */
            public static array $probes = [];

            public ?object $chain;

            public function __construct(?object $chain = null)
            {
                $this->chain = $chain;
            }

            /** @return array<string, mixed>|null */
            public function testCw721ContractInfo(string $contract): ?array
            {
                self::$probes[] = $contract;

                if (self::$throws !== null) {
                    throw self::$throws;
                }

                return self::$contractInfo;
            }

            public static function reset(): void
            {
                self::$throws = null;
                self::$contractInfo = ['name' => 'Seeded CW721', 'symbol' => 'SEED'];
                self::$probes = [];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Factories {

    if (!class_exists(FetcherFactory::class, false)) {
        final class FetcherFactory
        {
            public static bool $hasDriver = true;
            public static int $madeCount = 0;

            public static function has_driver(string $chainType): bool
            {
                return self::$hasDriver && $chainType === 'cosmos';
            }

            public static function make_for_chain(object $chain): \BCC\Trust\Onchain\Fetchers\CosmosFetcher
            {
                self::$madeCount++;
                return new \BCC\Trust\Onchain\Fetchers\CosmosFetcher($chain);
            }

            public static function reset(): void
            {
                self::$hasDriver = true;
                self::$madeCount = 0;
            }
        }
    }
}

namespace {

    // The Cosmos add path passes a TTL to bulkUpsert(); WP's constant is not
    // loaded in the harness.
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }
}
