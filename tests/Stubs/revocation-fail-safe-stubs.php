<?php

declare(strict_types=1);

/**
 * PR 7.14 doubles: drive the ownership evaluator, the holder-group join and
 * the revoke sweep end to end, with every read able to FAIL.
 *
 * ── WHAT IS REAL ────────────────────────────────────────────────────────
 * `HoldingsService`, `NftGroupGateService`, `NftGroupRevokeService`,
 * `EligibilityVerdict`, `HoldingsCount`, `JoinResult`, `GatedGroupConfig`,
 * `GateIdentity`, `NftCollectionIdentifier` and `ProviderRequestBudget`. Nothing
 * that decides is faked.
 *
 * ── WHAT IS FAKED, AND WHY ──────────────────────────────────────────────
 * Every repository the evaluator reads, because the defects under test are
 * what happens when one of those reads FAILS — a branch a healthy database
 * never takes. Each fake offers BOTH readers where production has both: the
 * fail-safe one folds a failure to `[]` / `null` exactly as `$rows ?: []`
 * does, and the fail-closed `*OrThrow` sibling throws the REAL
 * {@see \BCC\Trust\Onchain\Repositories\RepositoryReadFailure}. A test that
 * passes can therefore only pass by consulting the fail-closed reader.
 *
 * PeepSo membership is a real paging list: `listGroupMembers()` applies
 * LIMIT/OFFSET to the rows as they exist AT CALL TIME and `leave()` deletes
 * the row, so the offset-shift defect reproduces exactly as it does against
 * `wp_peepso_group_members`.
 *
 * ⚠ The doubles RECORD calls, removals and writes rather than asserting on
 * them. "No provider was asked" and "nobody was removed" are the claims.
 */

namespace {
    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            public function __construct(private string $code = '', private string $message = '')
            {
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }

    if (!class_exists('BccRevokeWorld', false)) {
        final class BccRevokeWorld
        {
            // ── WP state ────────────────────────────────────────────────
            /** @var array<string, mixed> */
            public static array $transients = [];
            /** @var list<string> */
            public static array $transientWrites = [];
            /** @var array<string, mixed> */
            public static array $options = [];
            /** @var array<string, mixed> hook => forced return */
            public static array $filters = [];
            public static int $now = 1_800_000_000;

            // ── Repositories ────────────────────────────────────────────
            /** @var array<int, list<object>> userId => wallet rows */
            public static array $wallets = [];
            public static bool $failWalletRead = false;

            /** @var array<int, object> chainId => chain row (incl. is_active) */
            public static array $chains = [];
            public static bool $failChainRead = false;

            /** @var array<string, bool> chain_type => has a driver */
            public static array $drivers = ['evm' => true, 'solana' => true, 'cosmos' => true];

            /** @var array<string, list<string>> chain_type => supports_feature list */
            public static array $features = [];

            /** @var array<string, ?string> "chainId|contract" => token standard */
            public static array $tokenStandards = [];
            public static bool $failTokenStandardRead = false;

            /** @var array<int, int> walletLinkId => indexed visible balance */
            public static array $index = [];
            public static bool $failIndexRead = false;

            /** @var array<int, object> chainId => checkpoint row */
            public static array $checkpoints = [];
            public static bool $failCheckpointRead = false;

            /**
             * Scripted provider answers keyed "wallet|contract":
             *   ['exact', N] | ['atLeast', N] | ['unknown'] | ['throw']
             *
             * @var array<string, array{0: string, 1?: int}>
             */
            public static array $answers = [];

            /** When true the factory builds a fetcher WITHOUT the evidence interface. */
            public static bool $legacyFetcher = false;

            /** Worst-case requests the fake declares per evidence read. */
            public static int $maxRequestsPerRead = 1;

            /** @var list<string> "method:wallet|contract" for every fetcher call */
            public static array $fetcherCalls = [];

            /** @var list<array<string, mixed>> scripted list_holdings answers */
            public static array $listAnswers = [];

            // ── Gates + membership ──────────────────────────────────────
            /** @var array<int, \BCC\Trust\Onchain\ValueObjects\GatedGroupConfig> */
            public static array $gateConfigs = [];
            /** @var array<int, \BCC\Trust\Onchain\ValueObjects\GateIdentity> */
            public static array $identities = [];
            /** @var array<int, list<object>> groupId => ordered member rows {user_id, role} */
            public static array $members = [];
            /** @var list<string> "groupId:userId" removals, in order */
            public static array $leaves = [];
            /** @var list<string> "groupId:userId" joins */
            public static array $joins = [];
            /** @var list<array<string, mixed>> */
            public static array $audit = [];

            // ── Request surfaces (listing, profile, stance) ─────────────
            public static int $currentUser = 0;
            /** @var array<int, array<string, mixed>> userId => meta key => value */
            public static array $userMeta = [];
            /** @var list<string> "userId|chainId|contract|stance" */
            public static array $stanceWrites = [];
            /** @var list<string> "update|delete:userId:key" for every user-meta write */
            public static array $userMetaWrites = [];

            // ── Stored holdings index (the stance panel's evidence) ─────
            /** @var array<int, list<object>> walletLinkId => visible stored rows */
            public static array $storedRows = [];
            /** @var list<int> walletLinkIds whose stored rows were read */
            public static array $storedReads = [];

            public static function reset(): void
            {
                self::$currentUser    = 0;
                self::$userMeta       = [];
                self::$stanceWrites   = [];
                self::$userMetaWrites = [];
                self::$storedRows     = [];
                self::$storedReads    = [];
                self::$transients = self::$options = self::$filters = [];
                self::$transientWrites = [];
                self::$now = 1_800_000_000;
                self::$wallets = self::$chains = self::$checkpoints = self::$index = [];
                self::$failWalletRead = self::$failChainRead = self::$failTokenStandardRead = false;
                self::$failIndexRead = self::$failCheckpointRead = false;
                self::$drivers = ['evm' => true, 'solana' => true, 'cosmos' => true];
                self::$features = [
                    'evm'    => ['collection', 'top_collections', 'holdings_count'],
                    'solana' => ['collection', 'holdings_count', 'holdings_list'],
                    'cosmos' => ['validator', 'delegations', 'holdings_count', 'holdings_list'],
                ];
                self::$tokenStandards = self::$answers = self::$listAnswers = [];
                self::$legacyFetcher = false;
                self::$maxRequestsPerRead = 1;
                self::$fetcherCalls = [];
                self::$gateConfigs = self::$identities = self::$members = [];
                self::$leaves = self::$joins = self::$audit = [];
            }

            public static function seedChain(int $id, string $slug, string $type, bool $active = true): void
            {
                self::$chains[$id] = (object) [
                    'id'         => $id,
                    'slug'       => $slug,
                    'chain_type' => $type,
                    'is_active'  => $active ? 1 : 0,
                    'rest_url'   => 'https://cosmos-api.polkachu.com',
                    'rpc_url'    => 'https://rpc.invalid',
                ];
            }

            public static function linkWallet(int $userId, int $walletLinkId, int $chainId, string $address): void
            {
                self::$wallets[$userId][] = (object) [
                    'id'             => $walletLinkId,
                    'user_id'        => $userId,
                    'chain_id'       => $chainId,
                    'wallet_address' => $address,
                ];
            }

            /** Number of fetcher calls that could have reached a provider. */
            public static function providerCalls(): int
            {
                $n = 0;
                foreach (self::$fetcherCalls as $call) {
                    if (str_starts_with($call, 'count_holdings') || str_starts_with($call, 'list_holdings')) {
                        $n++;
                    }
                }

                return $n;
            }
        }
    }

    if (!function_exists('get_transient')) {
        function get_transient(string $key)
        {
            return \BccRevokeWorld::$transients[$key] ?? false;
        }
    }
    if (!function_exists('set_transient')) {
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            \BccRevokeWorld::$transients[$key]  = $value;
            \BccRevokeWorld::$transientWrites[] = $key;

            return true;
        }
    }
    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            unset(\BccRevokeWorld::$transients[$key]);

            return true;
        }
    }
    if (!function_exists('get_option')) {
        function get_option(string $key, $default = false)
        {
            return array_key_exists($key, \BccRevokeWorld::$options) ? \BccRevokeWorld::$options[$key] : $default;
        }
    }
    if (!function_exists('update_option')) {
        function update_option(string $key, $value, $autoload = null): bool
        {
            \BccRevokeWorld::$options[$key] = $value;

            return true;
        }
    }
    if (!function_exists('apply_filters')) {
        function apply_filters(string $hook, $value, ...$args)
        {
            return array_key_exists($hook, \BccRevokeWorld::$filters) ? \BccRevokeWorld::$filters[$hook] : $value;
        }
    }
    if (!function_exists('do_action')) {
        function do_action(string $hook, ...$args): void
        {
        }
    }
    if (!function_exists('get_user_meta')) {
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            return \BccRevokeWorld::$userMeta[$userId][$key] ?? '';
        }
    }
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return \BccRevokeWorld::$currentUser;
        }
    }
    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            /** @return mixed */
            public function get_param(string $key)
            {
                return null;
            }
        }
    }
    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            /** @var array<string, string> */
            public array $headers = [];

            /** @param mixed $data */
            public function __construct(public $data = null, public int $status = 200)
            {
            }

            public function header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }

            /** @return mixed */
            public function get_data()
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }
        }
    }
    if (!function_exists('update_user_meta')) {
        function update_user_meta(int $userId, string $key, $value): bool
        {
            \BccRevokeWorld::$userMeta[$userId][$key] = $value;
            \BccRevokeWorld::$userMetaWrites[]        = 'update:' . $userId . ':' . $key;

            return true;
        }
    }
    if (!function_exists('delete_user_meta')) {
        function delete_user_meta(int $userId, string $key): bool
        {
            unset(\BccRevokeWorld::$userMeta[$userId][$key]);
            \BccRevokeWorld::$userMetaWrites[] = 'delete:' . $userId . ':' . $key;

            return true;
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }
    if (!function_exists('current_time')) {
        function current_time(string $type, bool $gmt = false)
        {
            return $type === 'mysql' ? gmdate('Y-m-d H:i:s', \BccRevokeWorld::$now) : \BccRevokeWorld::$now;
        }
    }
    foreach (['DAY_IN_SECONDS' => 86400, 'HOUR_IN_SECONDS' => 3600, 'MINUTE_IN_SECONDS' => 60] as $c => $v) {
        if (!defined($c)) {
            define($c, $v);
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public static array $lines = [];

            public static function reset(): void
            {
                self::$lines = [];
            }

            public static function warning(string $m, array $c = []): void
            {
                self::$lines[] = ['level' => 'warning', 'message' => $m, 'context' => $c];
            }

            public static function error(string $m, array $c = []): void
            {
                self::$lines[] = ['level' => 'error', 'message' => $m, 'context' => $c];
            }

            public static function info(string $m, array $c = []): void
            {
                self::$lines[] = ['level' => 'info', 'message' => $m, 'context' => $c];
            }

            public static function debug(string $m, array $c = []): void
            {
                self::$lines[] = ['level' => 'debug', 'message' => $m, 'context' => $c];
            }
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        final class Throttle
        {
            public static function allow(string $action, int $limit = 10, int $window = 60, ?string $key = null): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    if (!class_exists(ApiResponse::class, false)) {
        final class ApiResponse
        {
            /** @param mixed $data */
            public static function ok($data, int $status = 200): \WP_REST_Response
            {
                return new \WP_REST_Response(['data' => $data], $status);
            }

            public static function error(string $code, string $message, int $status): \WP_REST_Response
            {
                return new \WP_REST_Response(['error' => ['code' => $code]], $status);
            }
        }
    }
}

namespace BCC\Trust\Core {
    if (!class_exists(Plugin::class, false)) {
        /** Only what the holder-group listing reads: an activity block per group. */
        final class Plugin
        {
            public static function instance(): self
            {
                return new self();
            }

            public function groupActivityHeatService(): object
            {
                return new class {
                    /** @param list<int> $groupIds @return array<int, array<string, mixed>> */
                    public function forGroups(array $groupIds): array
                    {
                        return [];
                    }
                };
            }
        }
    }
}

namespace BCC\Trust\Onchain {
    if (!class_exists(OnchainPlugin::class, false)) {
        final class OnchainPlugin
        {
            public static function instance(): self
            {
                return new self();
            }

            public function nftGroupGateService(): \BCC\Trust\Onchain\Services\NftGroupGateService
            {
                return new \BCC\Trust\Onchain\Services\NftGroupGateService();
            }
        }
    }
}

namespace BCC\Core\Permissions {
    if (!class_exists(Permissions::class, false)) {
        final class Permissions
        {
            public static function is_not_suspended(int $userId, bool $adminBypass = true): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        final class PeepSoGroupRepository
        {
            /** @return list<object> rows as they exist NOW, LIMIT/OFFSET applied */
            public static function listGroupMembers(int $groupId, int $offset, int $limit): array
            {
                $rows = \BccRevokeWorld::$members[$groupId] ?? [];

                return array_values(array_slice($rows, max(0, $offset), min(100, max(0, $limit))));
            }

            /**
             * @param list<int> $groupIds
             * @return array<int, string>
             */
            public static function findUserMemberships(int $userId, array $groupIds): array
            {
                $out = [];
                foreach ($groupIds as $gid) {
                    foreach (\BccRevokeWorld::$members[$gid] ?? [] as $row) {
                        if ((int) $row->user_id === $userId) {
                            $out[$gid] = (string) $row->role;
                        }
                    }
                }

                return $out;
            }

            /**
             * @param list<int> $groupIds
             * @return array<int, object>
             */
            public static function findManyByIds(array $groupIds): array
            {
                return [];
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoGroupWriter::class, false)) {
        final class PeepSoGroupWriter
        {
            public static function leave(int $userId, int $groupId): bool
            {
                foreach (\BccRevokeWorld::$members[$groupId] ?? [] as $i => $row) {
                    if ((int) $row->user_id !== $userId) {
                        continue;
                    }
                    if ((string) $row->role === 'member_owner') {
                        return false; // mirrors production: owners are never removed
                    }
                    unset(\BccRevokeWorld::$members[$groupId][$i]);
                    \BccRevokeWorld::$members[$groupId] = array_values(\BccRevokeWorld::$members[$groupId]);
                    \BccRevokeWorld::$leaves[] = $groupId . ':' . $userId;

                    return true;
                }

                return false;
            }

            public static function join(int $userId, int $groupId): bool
            {
                \BccRevokeWorld::$joins[] = $groupId . ':' . $userId;
                \BccRevokeWorld::$members[$groupId][] = (object) ['user_id' => $userId, 'role' => 'member'];

                return true;
            }
        }
    }
}

namespace BCC\Trust\Core\Security {
    if (!class_exists(AuditLogger::class, false)) {
        final class AuditLogger
        {
            public static function log(string $action, int $targetId, array $meta = [], string $targetType = '', int $userId = 0): void
            {
                \BccRevokeWorld::$audit[] = [
                    'action'      => $action,
                    'target_id'   => $targetId,
                    'target_type' => $targetType,
                    'user_id'     => $userId,
                    'meta'        => $meta,
                ];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(WalletRepository::class, false)) {
        final class WalletRepository
        {
            /** @return list<object> */
            public static function getForUser(int $userId, ?string $walletType = null, bool $verifiedOnly = false): array
            {
                // Mirrors production's `return $rows ?: [];` on a failed query.
                return \BccRevokeWorld::$failWalletRead ? [] : (\BccRevokeWorld::$wallets[$userId] ?? []);
            }

            /** @return list<object> */
            public static function getForUserOrThrow(int $userId, ?string $walletType = null, bool $verifiedOnly = false): array
            {
                if (\BccRevokeWorld::$failWalletRead) {
                    throw new RepositoryReadFailure(self::class, 'getForUserOrThrow', 'injected fault');
                }

                return \BccRevokeWorld::$wallets[$userId] ?? [];
            }

            public static function markHoldingsRefreshed(int $walletLinkId): bool
            {
                return true;
            }
        }
    }

    if (!class_exists(ChainRepository::class, false)) {
        final class ChainRepository
        {
            /** Mirrors production: ACTIVE chains only; a failed read looks like "none". */
            public static function getBySlug(string $slug): ?object
            {
                if (\BccRevokeWorld::$failChainRead) {
                    return null;
                }
                foreach (\BccRevokeWorld::$chains as $chain) {
                    if ($chain->slug === $slug && (int) $chain->is_active === 1) {
                        return $chain;
                    }
                }

                return null;
            }

            /** Mirrors production: answers for inactive chains too. */
            public static function getById(int $chainId): ?object
            {
                return \BccRevokeWorld::$failChainRead ? null : (\BccRevokeWorld::$chains[$chainId] ?? null);
            }
        }
    }

    if (!class_exists(CollectionRepository::class, false)) {
        final class CollectionRepository
        {
            public static function findTokenStandard(int $chainId, string $contract): ?string
            {
                return \BccRevokeWorld::$failTokenStandardRead
                    ? null
                    : (\BccRevokeWorld::$tokenStandards[$chainId . '|' . $contract] ?? null);
            }

            public static function findTokenStandardOrThrow(int $chainId, string $contract): ?string
            {
                if (\BccRevokeWorld::$failTokenStandardRead) {
                    throw new RepositoryReadFailure(self::class, 'findTokenStandardOrThrow', 'injected fault');
                }

                return \BccRevokeWorld::$tokenStandards[$chainId . '|' . $contract] ?? null;
            }

            /**
             * @param list<int> $ids
             * @return array<int, object>
             */
            public static function findManyByIds(array $ids): array
            {
                return [];
            }
        }
    }

    if (!class_exists(CollectionSignalRepository::class, false)) {
        final class CollectionSignalRepository
        {
            public const STANCE_WAITLIST = 'waitlist';
            public const STANCE_SPAM     = 'spam';
            public const STANCES         = [self::STANCE_WAITLIST, self::STANCE_SPAM];

            public static function setStance(int $userId, int $chainId, string $contract, string $stance): bool
            {
                \BccRevokeWorld::$stanceWrites[] = $userId . '|' . $chainId . '|' . $contract . '|' . $stance;

                return true;
            }
        }
    }

    if (!class_exists(NftHoldingsRepository::class, false)) {
        final class NftHoldingsRepository
        {
            /**
             * @param list<int> $walletLinkIds
             * @return array<int, int>
             */
            public static function countVisibleByContract(int $chainId, string $contract, array $walletLinkIds): array
            {
                if (\BccRevokeWorld::$failIndexRead) {
                    return []; // mirrors production's silent fold
                }

                return array_intersect_key(\BccRevokeWorld::$index, array_flip($walletLinkIds));
            }

            /**
             * @param list<int> $walletLinkIds
             * @return array<int, int>
             */
            public static function countVisibleByContractOrThrow(int $chainId, string $contract, array $walletLinkIds): array
            {
                if (\BccRevokeWorld::$failIndexRead) {
                    throw new RepositoryReadFailure(self::class, 'countVisibleByContractOrThrow', 'injected fault');
                }

                return array_intersect_key(\BccRevokeWorld::$index, array_flip($walletLinkIds));
            }

            /** The stance panel's stored-index lookup. Every read is recorded. @return list<object> */
            public static function findVisibleForWallet(int $walletLinkId, int $chainId): array
            {
                \BccRevokeWorld::$storedReads[] = $walletLinkId;

                return \BccRevokeWorld::$storedRows[$walletLinkId] ?? [];
            }
        }
    }

    if (!class_exists(ChainCheckpointRepository::class, false)) {
        final class ChainCheckpointRepository
        {
            public const STATE_HEALTHY      = 'healthy';
            public const STATE_DEGRADED     = 'degraded';
            public const STATE_BREAKER_OPEN = 'breaker_open';
            public const STATE_DISABLED     = 'disabled';

            public static function get(int $chainId): ?object
            {
                return \BccRevokeWorld::$failCheckpointRead ? null : (\BccRevokeWorld::$checkpoints[$chainId] ?? null);
            }
        }
    }

    if (!class_exists(GatedGroupRepository::class, false)) {
        final class GatedGroupRepository
        {
            public static function getGateConfig(int $groupId): ?\BCC\Trust\Onchain\ValueObjects\GatedGroupConfig
            {
                return \BccRevokeWorld::$gateConfigs[$groupId] ?? null;
            }

            /** @return list<int> */
            public static function listAllGatedGroupIds(int $limit = 500): array
            {
                $ids = array_keys(\BccRevokeWorld::$gateConfigs);
                sort($ids);

                return $ids;
            }

            /** @return list<\BCC\Trust\Onchain\ValueObjects\GatedGroupConfig> */
            public static function listAllGatedGroupConfigs(int $limit = 500): array
            {
                ksort(\BccRevokeWorld::$gateConfigs);

                return array_values(\BccRevokeWorld::$gateConfigs);
            }

            /**
             * @param int[] $groupIds
             * @return array<int, \BCC\Trust\Onchain\ValueObjects\GatedGroupConfig>
             */
            public static function findManyByGroupIds(array $groupIds): array
            {
                return array_intersect_key(\BccRevokeWorld::$gateConfigs, array_flip($groupIds));
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!class_exists(GateIdentityResolver::class, false)) {
        final class GateIdentityResolver
        {
            public static function resolve(\BCC\Trust\Onchain\ValueObjects\GatedGroupConfig $config): \BCC\Trust\Onchain\ValueObjects\GateIdentity
            {
                return \BccRevokeWorld::$identities[$config->groupId]
                    ?? \BCC\Trust\Onchain\ValueObjects\GateIdentity::unresolved();
            }
        }
    }
}

namespace BCC\Trust\Onchain\Fetchers {
    if (!trait_exists(BccGateFetcherBody::class, false)) {
        /** Everything a scripted gate fetcher does, shared by both variants. */
        trait BccGateFetcherBody
        {
            public function __construct(private object $chain)
            {
            }

            public function fetch_validator(string $address): array { return []; }
            public function fetch_all_validators(?\BCC\Trust\Onchain\Support\ProviderOutcomeReceipt $outcome = null): array { return []; }
            public function enrich_validator(string $address, ?object $existingRow = null): array { return []; }
            public function fetch_delegations(string $delegatorAddress): array { return []; }
            public function fetch_collections(string $walletAddress, int $chainId = 0): array { return []; }
            public function fetch_top_collections(int $limit = 100): array { return []; }
            public function get_chain(): object { return $this->chain; }
            public function last_fetch_error(): ?string { return null; }

            public function supports_feature(string $feature): bool
            {
                return in_array($feature, \BccRevokeWorld::$features[(string) $this->chain->chain_type] ?? [], true);
            }

            public function list_holdings(string $wallet, ?string $cursor = null): array
            {
                \BccRevokeWorld::$fetcherCalls[] = 'list_holdings:' . $wallet;
                if (\BccRevokeWorld::$listAnswers === []) {
                    return ['items' => [], 'truncated' => false, 'cursor' => null, 'complete' => true, 'served_from_cache' => false];
                }

                return array_shift(\BccRevokeWorld::$listAnswers);
            }

            public function count_holdings(string $wallet, string $contract): ?int
            {
                \BccRevokeWorld::$fetcherCalls[] = 'count_holdings:' . $wallet . '|' . $contract;
                $answer = \BccRevokeWorld::$answers[$wallet . '|' . $contract] ?? ['unknown'];

                return match ($answer[0]) {
                    'exact', 'atLeast' => (int) ($answer[1] ?? 0),
                    'throw'            => throw new \RuntimeException('scripted provider exception'),
                    default            => null,
                };
            }

            private function evidenceAnswer(string $wallet, string $contract): ?\BCC\Trust\Onchain\ValueObjects\HoldingsCount
            {
                \BccRevokeWorld::$fetcherCalls[] = 'count_holdings_evidence:' . $wallet . '|' . $contract;
                $answer = \BccRevokeWorld::$answers[$wallet . '|' . $contract] ?? ['unknown'];

                return match ($answer[0]) {
                    'exact'   => \BCC\Trust\Onchain\ValueObjects\HoldingsCount::exact((int) ($answer[1] ?? 0)),
                    'atLeast' => \BCC\Trust\Onchain\ValueObjects\HoldingsCount::atLeast((int) ($answer[1] ?? 0)),
                    'throw'   => throw new \RuntimeException('scripted provider exception'),
                    default   => null,
                };
            }
        }
    }

    if (!class_exists(BccGateEvidenceFetcher::class, false)) {
        final class BccGateEvidenceFetcher implements
            \BCC\Trust\Onchain\Contracts\FetcherInterface,
            \BCC\Trust\Onchain\Contracts\CountsHoldingsWithCompleteness
        {
            use BccGateFetcherBody;

            public function count_holdings_evidence(string $wallet, string $contract): ?\BCC\Trust\Onchain\ValueObjects\HoldingsCount
            {
                return $this->evidenceAnswer($wallet, $contract);
            }

            public function max_requests_per_evidence_read(): int
            {
                return \BccRevokeWorld::$maxRequestsPerRead;
            }
        }
    }

    if (!class_exists(BccGateLegacyFetcher::class, false)) {
        /** A driver that never vouched for completeness — integers only. */
        final class BccGateLegacyFetcher implements \BCC\Trust\Onchain\Contracts\FetcherInterface
        {
            use BccGateFetcherBody;
        }
    }
}

namespace BCC\Trust\Onchain\Factories {
    if (!class_exists(FetcherFactory::class, false)) {
        final class FetcherFactory
        {
            public static function has_driver(string $chainType): bool
            {
                return (\BccRevokeWorld::$drivers[$chainType] ?? false) === true;
            }

            public static function make_for_chain(object $chain): object
            {
                return \BccRevokeWorld::$legacyFetcher
                    ? new \BCC\Trust\Onchain\Fetchers\BccGateLegacyFetcher($chain)
                    : new \BCC\Trust\Onchain\Fetchers\BccGateEvidenceFetcher($chain);
            }
        }
    }
}
