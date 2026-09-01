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

    /**
     * PR 6: the operator identity checks CommunityRequestService performs.
     *
     * It resolves the administrator by id and checks the capability on that
     * NAMED user rather than via `current_user_can()`, because the same
     * service runs from the cron where there is no current user. These stubs
     * therefore have to answer for an id, not for an ambient session.
     */
    /**
     * Records cache busts so a test can assert WHICH keys were dropped.
     *
     * `collection_counts_by_chain` must be dropped when a collection ROW is
     * added (the per-chain census changed) and must NOT be dropped for a
     * verification or provisioning change — that query counts rows per chain
     * and reads neither field, so busting it there is pure cache churn.
     */
    if (!class_exists('BccObjectCacheSpy')) {
        final class BccObjectCacheSpy
        {
            /** @var list<array{key: int|string, group: string}> */
            public static array $deleted = [];

            public static function reset(): void
            {
                self::$deleted = [];
            }
        }
    }

    if (!function_exists('wp_cache_delete')) {
        /** @param int|string $key */
        function wp_cache_delete($key, string $group = ''): bool
        {
            \BccObjectCacheSpy::$deleted[] = ['key' => $key, 'group' => $group];
            return true;
        }
    }

    if (!function_exists('clean_post_cache')) {
        /** @param int|\WP_Post $post */
        function clean_post_cache($post): void
        {
            \BccObjectCacheSpy::$deleted[] = ['key' => is_object($post) ? 0 : (int) $post, 'group' => 'posts'];
        }
    }

    if (!function_exists('get_userdata')) {
        function get_userdata(int $userId)
        {
            return \BccAdminTestState::$knownUserIds === null
                || in_array($userId, \BccAdminTestState::$knownUserIds, true)
                ? (object) ['ID' => $userId]
                : false;
        }
    }

    if (!function_exists('user_can')) {
        function user_can($user, string $capability): bool
        {
            $id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;

            if (\BccAdminTestState::$capableUserIds === null) {
                return $capability === 'manage_options';
            }

            return $capability === 'manage_options'
                && in_array($id, \BccAdminTestState::$capableUserIds, true);
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

            // ── PR 6: provisioning intent ──────────────────────────────
            //
            // The fake tracks state PER COLLECTION so a test can assert the
            // real invariants: that a withdrawal never reaches `provisioned`,
            // and that an illegal transition is refused rather than written.

            /** @var array<int, array{state: string, at: string|null, by: int|null, code: string|null}> */
            public static array $provisioning = [];

            /** @var list<array{id: int, from: string, to: string, by: int|null, at: string|null, code: string|null}> */
            public static array $stateWrites = [];

            /** @var list<int> */
            public static array $withdrawals = [];

            public static function readProvisioningRow(int $collectionId, bool $forUpdate = false): ?object
            {
                $row = self::$rows[$collectionId] ?? null;
                if ($row === null) {
                    return null;
                }

                $p = self::$provisioning[$collectionId]
                    ?? ['state' => 'none', 'at' => null, 'by' => null, 'code' => null];

                return (object) [
                    'id'                        => (string) $collectionId,
                    'is_verified'               => (string) ((int) ($row->is_verified ?? 0)),
                    'canonical_identifier'      => $row->canonical_identifier ?? null,
                    'collection_name'           => $row->collection_name ?? null,
                    'chain_id'                  => (string) ((int) ($row->chain_id ?? 0)),
                    'provisioning_state'        => $p['state'],
                    'provisioning_requested_at' => $p['at'],
                    'provisioning_requested_by' => $p['by'] === null ? null : (string) $p['by'],
                    'provisioning_failure_code' => $p['code'],
                ];
            }

            public static function setProvisioningState(
                int $collectionId,
                string $expectedFrom,
                string $to,
                ?int $requestedBy = null,
                ?string $requestedAt = null,
                ?string $failureCode = null
            ): bool {
                $current = self::$provisioning[$collectionId]['state'] ?? 'none';

                // Mirrors the real guarded UPDATE: a row that is not in the
                // expected state does not move, and the caller is told so.
                if ($current !== $expectedFrom) {
                    return false;
                }

                self::$provisioning[$collectionId] = [
                    'state' => $to,
                    'at'    => $requestedAt,
                    'by'    => $requestedBy,
                    'code'  => $failureCode,
                ];
                self::$stateWrites[] = [
                    'id'   => $collectionId,
                    'from' => $expectedFrom,
                    'to'   => $to,
                    'by'   => $requestedBy,
                    'at'   => $requestedAt,
                    'code' => $failureCode,
                ];

                return true;
            }

            public static function withdrawPendingProvisioning(int $collectionId): int
            {
                $current = self::$provisioning[$collectionId]['state'] ?? 'none';

                // The real method's WHERE clause names only these two states,
                // which is what makes `provisioned` unreachable from here.
                if ($current !== 'requested' && $current !== 'failed') {
                    return 0;
                }

                self::$provisioning[$collectionId] =
                    ['state' => 'none', 'at' => null, 'by' => null, 'code' => null];
                self::$withdrawals[] = $collectionId;

                return 1;
            }

            /** @return list<object> */
            public static function listRequested(int $afterId = 0, int $limit = 50): array
            {
                $out = [];
                foreach (self::$provisioning as $id => $p) {
                    if ($p['state'] !== 'requested' || $id <= $afterId) {
                        continue;
                    }
                    $row = self::readProvisioningRow((int) $id);
                    if ($row !== null) {
                        $out[] = $row;
                    }
                }
                usort($out, static fn($a, $b): int => (int) $a->id <=> (int) $b->id);

                return array_slice($out, 0, max(1, min(200, $limit)));
            }

            /**
             * A mark the transaction fake can rewind every recorded write to.
             *
             * @return array<string, mixed>
             */
            public static function writeMark(): array
            {
                return [
                    'bulk'         => count(self::$bulkCalls),
                    'stateWrites'  => count(self::$stateWrites),
                    'withdrawals'  => count(self::$withdrawals),
                    'provisioning' => self::$provisioning,
                ];
            }

            /** @param array<string, mixed> $mark */
            public static function rewindTo(array $mark): void
            {
                self::$bulkCalls    = array_slice(self::$bulkCalls, 0, (int) $mark['bulk']);
                self::$stateWrites  = array_slice(self::$stateWrites, 0, (int) $mark['stateWrites']);
                self::$withdrawals  = array_slice(self::$withdrawals, 0, (int) $mark['withdrawals']);
                /** @var array<int, array{state: string, at: string|null, by: int|null, code: string|null}> $prior */
                $prior              = $mark['provisioning'];
                self::$provisioning = $prior;
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
                self::$provisioning = [];
                self::$stateWrites = [];
                self::$withdrawals = [];
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
