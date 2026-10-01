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

    // PR E: manual intake stamps `metadata_checked_at`, so this stub set now
    // needs the clock shim the other stub files already carry.
    if (!function_exists('current_time')) {
        function current_time(string $type, bool $gmt = false)
        {
            if ($type === 'timestamp') {
                return time();
            }
            if ($type === 'Y-m-d') {
                return gmdate('Y-m-d');
            }

            return gmdate('Y-m-d H:i:s');
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
            /** Rows affected, matching production's `int` return (0 = nothing deleted). */
            public static int $deleteResult = 1;

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

            /**
             * PR E — descriptions imported at intake, recorded for assertion.
             *
             * @var list<array{0: int, 1: mixed, 2: string}>
             */
            public static array $descriptions = [];

            public static bool $importDescriptionResult = true;

            public static function importChainDescription(
                int $collectionId,
                mixed $rawDescription,
                string $source
            ): bool {
                self::$descriptions[] = [$collectionId, $rawDescription, $source];

                return self::$importDescriptionResult;
            }

            /** @var list<array{0: int, 1: string, 2: string}> */
            public static array $descriptionTransitions = [];

            public static bool $setDescriptionStateResult = true;

            public static function setChainDescriptionState(int $collectionId, string $from, string $to): bool
            {
                self::$descriptionTransitions[] = [$collectionId, $from, $to];

                return self::$setDescriptionStateResult;
            }

            /** @var list<array{rows: list<array<string, mixed>>, ttl: int}> */
            public static array $upsertCalls = [];

            /** @param list<array<string, mixed>> $rows */
            public static function bulkUpsert(array $rows, int $ttl): int
            {
                self::$upsertCalls[] = ['rows' => $rows, 'ttl' => $ttl];
                return self::$upsertWritten;
            }

            public static function deleteById(int $id): int
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

            public static function seed(
                int $id,
                int $chainId = 4,
                string $contract = '0xabc',
                ?string $canonical = null
            ): void {
                self::$rows[$id] = (object) [
                    'id'               => $id,
                    'chain_id'         => $chainId,
                    'contract_address' => $contract,
                    // Present so a test can seed a row whose two identity
                    // columns DIVERGE — the legacy-alias shape. Defaults to
                    // the contract, which is the ordinary case.
                    'canonical_identifier' => $canonical ?? $contract,
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

            /**
             * Makes the single-row read FAIL, the way a dropped connection
             * or a locked table does. Distinct from "no such collection",
             * which is what an empty `$rows` already models — the point of
             * the flag is that the two must not be confusable.
             */
            public static bool $readRowUnavailable = false;

            /**
             * Arm the row-read fault only for ids ABOVE this one.
             *
             * Models a database that degrades PART-WAY through a sweep, which
             * is the only way to test that counts for work already completed
             * survive the abort. `$readRowUnavailable` alone fails the very
             * first row, so a run under it can never have completed work to
             * preserve.
             */
            public static int $readRowUnavailableAfter = 0;

            /** @return array{row: object|null, available: bool} */
            public static function readProvisioningRow(int $collectionId, bool $forUpdate = false): array
            {
                if (self::$readRowUnavailable
                    || (self::$readRowUnavailableAfter > 0 && $collectionId > self::$readRowUnavailableAfter)
                ) {
                    return ['row' => null, 'available' => false];
                }

                return ['row' => self::buildProvisioningRow($collectionId), 'available' => true];
            }

            /**
             * Shape a provisioning row without consulting any fault flag.
             *
             * ── WHY THIS IS SEPARATE ────────────────────────────────────
             * `listRequested()` used to build its rows by calling
             * `readProvisioningRow()`, which coupled two queries that are
             * completely independent in production — the queue is one SELECT
             * over the table, the row read is another SELECT by id. The
             * coupling made one scenario UNREACHABLE in tests: the queue read
             * succeeding and the per-row re-read then failing, which is the
             * ordinary shape of a database that degrades mid-sweep. Setting
             * the row-read fault also emptied the queue, so the sweep never
             * reached the row and the bug in its `unavailable` handling could
             * not be observed.
             */
            private static function buildProvisioningRow(int $collectionId): ?object
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

            /**
             * Forces every guarded state write to lose its race, the way a
             * concurrent writer moving the row first would. Exists so a test
             * can prove a lost race is not reported as success.
             */
            public static bool $stateWriteRefuses = false;

            public static function setProvisioningState(
                int $collectionId,
                string $expectedFrom,
                string $to,
                ?int $requestedBy = null,
                ?string $requestedAt = null,
                ?string $failureCode = null
            ): bool {
                if (self::$stateWriteRefuses) {
                    return false;
                }

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

            /**
             * Makes the QUEUE read fail. Seeded requests stay in
             * `$provisioning`, so a test can prove the sweep did not simply
             * find an empty queue — it never got to look at a queue that
             * demonstrably had work in it.
             */
            public static bool $listRequestedUnavailable = false;

            /** @return array{rows: list<object>, available: bool} */
            public static function listRequested(int $afterId = 0, int $limit = 50): array
            {
                if (self::$listRequestedUnavailable) {
                    return ['rows' => [], 'available' => false];
                }

                $out = [];
                foreach (self::$provisioning as $id => $p) {
                    if ($p['state'] !== 'requested' || $id <= $afterId) {
                        continue;
                    }
                    // Deliberately NOT readProvisioningRow(): the queue is its
                    // own query and must stay readable when the per-row read
                    // is not. See buildProvisioningRow().
                    $built = self::buildProvisioningRow((int) $id);
                    if ($built !== null) {
                        $out[] = $built;
                    }
                }
                usort($out, static fn($a, $b): int => (int) $a->id <=> (int) $b->id);

                return [
                    'rows'      => array_slice($out, 0, max(1, min(200, $limit))),
                    'available' => true,
                ];
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
                    // PR E: the description review transition is a WRITE, so a
                    // rolled-back transaction must undo it here too. Without
                    // this the double would report a rollback while still
                    // showing the state as moved — and the atomicity test
                    // would pass against a stub that does not model it.
                    'descStates'   => count(self::$descriptionTransitions),
                    'descImports'  => count(self::$descriptions),
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

                self::$descriptionTransitions = array_slice(
                    self::$descriptionTransitions,
                    0,
                    (int) ($mark['descStates'] ?? 0)
                );
                self::$descriptions = array_slice(
                    self::$descriptions,
                    0,
                    (int) ($mark['descImports'] ?? 0)
                );
            }

            public static function reset(): void
            {
                self::$bulkCalls = [];
                self::$bulkChanged = 0;
                self::$added = [];
                // PR E
                self::$descriptions = [];
                self::$importDescriptionResult = true;
                self::$descriptionTransitions = [];
                self::$setDescriptionStateResult = true;
                self::$deleted = [];
                self::$deleteResult = 1;
                self::$rows = [];
                self::$upsertWritten = 1;
                self::$upsertCalls = [];
                self::$findByChainContractReturnsNull = false;
                self::$provisioning = [];
                self::$stateWriteRefuses = false;
                self::$stateWrites = [];
                self::$withdrawals = [];
                self::$readRowUnavailable = false;
                self::$readRowUnavailableAfter = 0;
                self::$listRequestedUnavailable = false;
            }
        }
    }

    if (!class_exists(GatedGroupRepository::class, false)) {
        final class GatedGroupRepository
        {
            /**
             * LEGACY, contract-keyed: "<chainId>|<contract>" => groupId.
             *
             * ⚠ This fixture no longer drives the delete guard. It is kept
             * so a test can PROVE that — seed only this one and the guard
             * must still permit the delete, which is what fails if the
             * handler ever reverts to the contract-keyed lookup.
             *
             * @var array<string, int>
             */
            public static array $groups = [];

            /**
             * AUTHORITATIVE, collection-id-keyed: collectionId => groupId.
             * Mirrors `_bcc_gate_collection_id` on a PUBLISHED peepso-group.
             *
             * @var array<int, int>
             */
            public static array $groupsByCollectionId = [];

            /** Make the authoritative read FAIL, so fail-closed is testable. */
            public static bool $throwOnCollectionIdRead = false;

            public static int $collectionIdReads = 0;

            public static function findGroupForCollection(int $chainId, string $contract): ?int
            {
                return self::$groups[$chainId . '|' . $contract] ?? null;
            }

            public static function findPublishedGroupIdForCollectionId(int $collectionId): ?int
            {
                // Mirrors production ordering: a bad id is answered before
                // any read, so it cannot be mistaken for a read failure.
                if ($collectionId <= 0) {
                    return null;
                }

                self::$collectionIdReads++;

                if (self::$throwOnCollectionIdRead) {
                    throw new RepositoryReadFailure(
                        'findPublishedGroupIdForCollectionId',
                        'SQLSTATE[HY000] stub'
                    );
                }

                return self::$groupsByCollectionId[$collectionId] ?? null;
            }

            public static function reset(): void
            {
                self::$groups                   = [];
                self::$groupsByCollectionId     = [];
                self::$throwOnCollectionIdRead  = false;
                self::$collectionIdReads        = 0;
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

            // ── PR E: the targeted-validation seam ──────────────────────
            // `$contractInfo === null` keeps meaning "the probe could not
            // confirm", so the existing tests' intent is preserved: it now
            // produces a probe set the classifier reads as undecidable, which
            // is UNAVAILABLE — the same "never claim it is not an NFT" answer
            // those tests were written to pin.

            /** @var int|null `num_tokens` count, when the probe answered. */
            public static ?int $numTokens = 7;

            /** @return list<array{probe: string, ok: bool, kind: string, excerpt: string}> */
            public function probeCw721(string $contract): array
            {
                self::$probes[] = $contract;

                if (self::$throws !== null) {
                    throw self::$throws;
                }

                if (self::$contractInfo === null) {
                    // Undecidable: transport-shaped failure on every probe.
                    return [
                        ['probe' => 'num_tokens', 'ok' => false, 'kind' => 'transport', 'excerpt' => 'timeout'],
                        ['probe' => 'contract_info', 'ok' => false, 'kind' => 'transport', 'excerpt' => 'timeout'],
                        ['probe' => 'get_collection_info_and_extension', 'ok' => false, 'kind' => 'transport', 'excerpt' => 'timeout'],
                    ];
                }

                return [
                    ['probe' => 'num_tokens', 'ok' => true, 'kind' => 'none', 'excerpt' => ''],
                    ['probe' => 'contract_info', 'ok' => true, 'kind' => 'none', 'excerpt' => ''],
                ];
            }

            /** @return array<string, mixed>|null */
            public function fetchContractInfo(string $contract, ?callable $authorizeRequest = null): ?array
            {
                if (self::$throws !== null) {
                    throw self::$throws;
                }

                if (self::$contractInfo === null) {
                    return null;
                }

                return [
                    'name'        => self::$contractInfo['name'] ?? null,
                    'symbol'      => self::$contractInfo['symbol'] ?? null,
                    'description' => self::$contractInfo['description'] ?? null,
                    'image_url'   => self::$contractInfo['image_url'] ?? null,
                ];
            }

            public function numTokensCountFor(string $contract): ?int
            {
                return self::$numTokens;
            }

            public static function reset(): void
            {
                self::$throws = null;
                self::$contractInfo = ['name' => 'Seeded CW721', 'symbol' => 'SEED'];
                self::$probes = [];
                self::$numTokens = 7;
            }
        }

        /**
         * PR E — EVM validation seam.
         *
         * Defaults to a confirmed ERC-721 with readable metadata, so a test
         * that only cares about the row it produces does not have to set it up.
         */
        final class EvmFetcher
        {
            /** interfaceId => hex word. Default: ERC-721 confirmed. */
            public static array $interfaceAnswers = [
                '0x80ac58cd' => '0x0000000000000000000000000000000000000000000000000000000000000001',
            ];

            /** Non-'none' makes every eth_call fail with that kind. */
            public static string $ethCallKind = 'none';

            public static string $metadataKind = 'none';

            /** @var array<string, mixed>|null */
            public static ?array $metadata = ['name' => 'Seeded ERC721', 'symbol' => 'SE721'];

            public static int $calls = 0;

            public ?object $chain;

            public function __construct(?object $chain = null)
            {
                $this->chain = $chain;
            }

            /** @return array{ok: bool, result: ?string, kind: string} */
            public function ethCallResult(string $to, string $data): array
            {
                self::$calls++;

                if (self::$ethCallKind !== 'none') {
                    return ['ok' => false, 'result' => null, 'kind' => self::$ethCallKind];
                }

                $iface = '0x' . substr($data, 10, 8);

                return ['ok' => true, 'result' => self::$interfaceAnswers[$iface] ?? '0x', 'kind' => 'none'];
            }

            /** @return array{ok: bool, data: ?array<string, mixed>, kind: string} */
            public function contractMetadataResult(string $contract): array
            {
                self::$calls++;

                if (self::$metadataKind !== 'none') {
                    return ['ok' => false, 'data' => null, 'kind' => self::$metadataKind];
                }

                return ['ok' => true, 'data' => self::$metadata ?? [], 'kind' => 'none'];
            }

            public static function reset(): void
            {
                self::$interfaceAnswers = [
                    '0x80ac58cd' => '0x0000000000000000000000000000000000000000000000000000000000000001',
                ];
                self::$ethCallKind = 'none';
                self::$metadataKind = 'none';
                self::$metadata = ['name' => 'Seeded ERC721', 'symbol' => 'SE721'];
                self::$calls = 0;
            }
        }

        /**
         * PR E — Solana validation seam. Defaults to a verified collection
         * grouping with a readable name.
         */
        final class SolanaFetcher
        {
            public static string $kind = 'none';

            /** @var array<string, mixed>|null the getAsset result */
            public static ?array $asset = null;

            /** @var list<array<string, mixed>>|null getAssetsByGroup items */
            public static ?array $groupItems = null;

            public static string $groupKind = 'none';

            /** Items the compressed filter matches. Empty = decisive zero. */
            public static array $compressedItems = [];

            /** Non-'none' makes the existence query non-decisive. */
            public static string $compressedKind = 'none';

            public static int $calls = 0;

            public ?object $chain;

            public function __construct(?object $chain = null)
            {
                $this->chain = $chain;
            }

            /**
             * PR E: the documented verification path. Defaults to ONE
             * uncompressed member, which is what a verified collection looks
             * like with `showUnverifiedCollections` false.
             *
             * ⚠ `grouping[]` carries exactly `group_key` and `group_value` —
             * there is no `verified` field in the DAS contract, and an earlier
             * version of this double invented one.
             *
             * @return array{ok: bool, result: ?array<string, mixed>, kind: string}
             */
            public function assetsByGroupResult(string $collectionMint): array
            {
                self::$calls++;

                if (self::$groupKind !== 'none') {
                    return ['ok' => false, 'result' => null, 'kind' => self::$groupKind];
                }

                $items = self::$groupItems ?? [[
                    'interface'   => 'V1_NFT',
                    'id'          => 'SeededMember1111111111111111111111111111111',
                    'compression' => ['compressed' => false],
                    'grouping'    => [['group_key' => 'collection', 'group_value' => $collectionMint]],
                    'content'     => ['metadata' => ['name' => 'Seeded Member #1']],
                ]];

                return [
                    'ok'     => true,
                    'result' => ['total' => count($items), 'limit' => 1, 'page' => 1, 'items' => $items],
                    'kind'   => 'none',
                ];
            }

            /**
             * PR E round 4: the documented `searchAssets` compressed-existence
             * query that actually enforces DECISION 8.
             *
             * ⚠ DEFAULTS TO A DECISIVE EMPTY RESULT — "no compressed members" —
             * because that is what lets the happy path through. A test that
             * wants the exclusion to bite sets `$compressedItems`; a test that
             * wants provider uncertainty sets `$compressedKind`.
             *
             * ⚠⚠ It counts toward `$calls`, so budget assertions include it.
             * Solana validation is THREE calls now, not two.
             *
             * @return array{ok: bool, result: ?array<string, mixed>, kind: string}
             */
            public function compressedMembersExistResult(string $collectionMint): array
            {
                self::$calls++;

                if (self::$compressedKind !== 'none') {
                    return ['ok' => false, 'result' => null, 'kind' => self::$compressedKind];
                }

                $items = self::$compressedItems;

                return [
                    'ok'     => true,
                    'result' => ['total' => count($items), 'limit' => 1, 'page' => 1, 'items' => $items],
                    'kind'   => 'none',
                ];
            }

            /** @return array{ok: bool, result: ?array<string, mixed>, kind: string} */
            public function assetResult(string $mint): array
            {
                self::$calls++;

                if (self::$kind !== 'none') {
                    return ['ok' => false, 'result' => null, 'kind' => self::$kind];
                }

                return [
                    'ok'     => true,
                    'result' => self::$asset ?? [
                        'compression' => ['compressed' => false],
                        'content'     => [
                            'metadata' => ['name' => 'Seeded Solana Collection'],
                            'links'    => ['image' => 'https://example.test/seeded.png'],
                        ],
                    ],
                    'kind'   => 'none',
                ];
            }

            public static function reset(): void
            {
                self::$kind = 'none';
                self::$asset = null;
                self::$groupItems = null;
                self::$groupKind = 'none';
                // ⚠ Reset these too. A leaked `$compressedItems` from one test
                // would make the next test's collection contain a cNFT, and a
                // leaked `$compressedKind` would make it UNAVAILABLE — both
                // failures that look like the code under test.
                self::$compressedItems = [];
                self::$compressedKind = 'none';
                self::$calls = 0;
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

            /**
             * ⚠ PR E: dispatches BY FAMILY. It used to return a CosmosFetcher
             * for every chain, which was harmless while only Cosmos validated
             * anything — the EVM and Solana paths never asked the fetcher a
             * question. Now they do, and handing an EVM chain a CosmosFetcher
             * would make the validator refuse with `family_unsupported`, so the
             * double has to be as family-correct as the real factory.
             */
            public static function make_for_chain(object $chain): object
            {
                self::$madeCount++;

                return match ((string) ($chain->chain_type ?? 'cosmos')) {
                    'evm'    => new \BCC\Trust\Onchain\Fetchers\EvmFetcher($chain),
                    'solana' => new \BCC\Trust\Onchain\Fetchers\SolanaFetcher($chain),
                    default  => new \BCC\Trust\Onchain\Fetchers\CosmosFetcher($chain),
                };
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
