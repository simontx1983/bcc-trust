<?php

/**
 * Stubs for the on-chain admin action handlers (Batch 1 safety hardening).
 *
 * Loaded ONLY from inside @runInSeparateProcess subprocesses, same isolation
 * strategy as nft-indexer-stubs.php. The REAL handler classes load via PSR-4
 * in the subprocess; everything they touch — WordPress, the logger, the audit
 * logger, repositories, workers and remote services — is stubbed here at the
 * production FQNs behind class_exists()/function_exists() guards so the
 * autoloader leaves them alone.
 *
 * Two control-flow shims carry most of the weight:
 *   - wp_die()          throws BccAdminDie
 *   - wp_safe_redirect() throws BccAdminRedirect
 * Both of those production paths terminate the request (wp_die exits,
 * redirect is followed by exit), and `exit` cannot be intercepted in-process.
 * Throwing lets a test assert that the request stopped at exactly that point
 * AND that nothing ran afterwards.
 */

declare(strict_types=1);

// ── Global WP shims ─────────────────────────────────────────────────────────

namespace {

    if (!class_exists('BccAdminDie', false)) {
        /** Thrown by the wp_die() shim. */
        class BccAdminDie extends \RuntimeException
        {
            public int $status;

            public function __construct(string $message = '', int $status = 0)
            {
                parent::__construct($message);
                $this->status = $status;
            }
        }
    }

    if (!class_exists('BccAdminRedirect', false)) {
        /** Thrown by the wp_safe_redirect() shim. */
        class BccAdminRedirect extends \RuntimeException
        {
            public string $url;

            /** @var array<string, string> */
            public array $args = [];

            public function __construct(string $url)
            {
                parent::__construct('redirect: ' . $url);
                $this->url = $url;

                $query = (string) parse_url($url, PHP_URL_QUERY);
                if ($query !== '') {
                    parse_str($query, $parsed);
                    /** @var array<string, string> $parsed */
                    $this->args = $parsed;
                }
            }
        }
    }

    if (!class_exists('BccAdminTestState', false)) {
        /** Mutable per-test control surface for the shims below. */
        final class BccAdminTestState
        {
            public static bool $can = true;
            public static int $userId = 7;

            /** Nonce action the shimmed check_admin_referer() will accept. */
            public static ?string $validNonceAction = null;

            /** @var list<array{action: string, arg: string}> */
            public static array $nonceChecks = [];

            /**
             * PR 6 operator-identity control.
             *
             * `null` means "every id resolves" — the default, so existing
             * tests are unaffected. A list makes `get_userdata()` return
             * false for anything outside it, which is how a test exercises
             * "the recorded administrator no longer exists".
             *
             * @var list<int>|null
             */
            public static ?array $knownUserIds = null;

            /**
             * Ids that hold `manage_options`. `null` means "everyone does".
             * Kept separate from $knownUserIds because the two failures are
             * different: a deleted account and a demoted one.
             *
             * @var list<int>|null
             */
            public static ?array $capableUserIds = null;

            public static function reset(): void
            {
                self::$can = true;
                self::$userId = 7;
                self::$validNonceAction = null;
                self::$nonceChecks = [];
                self::$knownUserIds = null;
                self::$capableUserIds = null;
            }
        }
    }

    if (!function_exists('current_user_can')) {
        /** @param mixed ...$args */
        function current_user_can(...$args): bool
        {
            return \BccAdminTestState::$can;
        }
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return \BccAdminTestState::$userId;
        }
    }

    if (!function_exists('wp_die')) {
        /**
         * @param mixed $message
         * @param mixed $title
         * @param array<string, mixed> $args
         */
        function wp_die($message = '', $title = '', $args = []): void
        {
            throw new \BccAdminDie(
                is_string($message) ? $message : 'wp_die',
                (int) ($args['response'] ?? 0)
            );
        }
    }

    if (!function_exists('check_admin_referer')) {
        /**
         * Accepts ONLY the exact action in BccAdminTestState::$validNonceAction.
         * That is what makes cross-action and cross-target nonce rejection
         * testable: a nonce minted for action A must not satisfy action B.
         */
        function check_admin_referer(string $action = '-1', string $queryArg = '_wpnonce'): int
        {
            \BccAdminTestState::$nonceChecks[] = ['action' => $action, 'arg' => $queryArg];

            if (\BccAdminTestState::$validNonceAction !== $action) {
                throw new \BccAdminDie('nonce_failed:' . $action, 403);
            }

            return 1;
        }
    }

    if (!function_exists('wp_safe_redirect')) {
        function wp_safe_redirect(string $location, int $status = 302): bool
        {
            throw new \BccAdminRedirect($location);
        }
    }

    if (!function_exists('add_query_arg')) {
        /**
         * @param array<string, string|int> $args
         */
        function add_query_arg(array $args, string $url = ''): string
        {
            return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args);
        }
    }

    if (!function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . $path;
        }
    }

    if (!function_exists('current_action')) {
        function current_action(): string
        {
            return $GLOBALS['bcc_test_current_action'] ?? '';
        }
    }

    if (!function_exists('sanitize_key')) {
        function sanitize_key(string $key): string
        {
            return strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $key));
        }
    }

    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field(string $str): string
        {
            return trim(strip_tags($str));
        }
    }

    if (!function_exists('sanitize_textarea_field')) {
        function sanitize_textarea_field(string $str): string
        {
            return trim(strip_tags($str));
        }
    }

    if (!function_exists('sanitize_hex_color')) {
        function sanitize_hex_color(string $color): ?string
        {
            return preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color) === 1 ? $color : null;
        }
    }

    if (!function_exists('esc_url_raw')) {
        function esc_url_raw(string $url): string
        {
            return preg_match('#^https?://#', $url) === 1 ? $url : '';
        }
    }

    if (!function_exists('wp_unslash')) {
        /** @param mixed $value @return mixed */
        function wp_unslash($value)
        {
            return is_string($value) ? stripslashes($value) : $value;
        }
    }

    if (!function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES);
        }
    }

    if (!function_exists('esc_html__')) {
        function esc_html__(string $text, ?string $domain = null): string
        {
            return $text;
        }
    }

    if (!function_exists('__')) {
        function __(string $text, ?string $domain = null): string
        {
            return $text;
        }
    }

    if (!function_exists('add_action')) {
        /** @param mixed ...$args */
        function add_action(...$args): bool
        {
            $GLOBALS['bcc_test_registered_actions'][] = (string) $args[0];
            return true;
        }
    }
}

// ── bcc-core: logger ────────────────────────────────────────────────────────

namespace BCC\Core\Log {

    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public static array $entries = [];

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
                self::$entries[] = ['level' => 'info', 'message' => $message, 'context' => $context];
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                self::$entries[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                self::$entries[] = ['level' => 'error', 'message' => $message, 'context' => $context];
            }

            public static function reset(): void
            {
                self::$entries = [];
            }

            /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
            public static function ofLevel(string $level): array
            {
                return array_values(array_filter(self::$entries, static fn($e) => $e['level'] === $level));
            }
        }
    }
}

// ── bcc-trust: audit logger ─────────────────────────────────────────────────

namespace BCC\Trust\Core\Security {

    if (!class_exists(AuditLogger::class, false)) {
        final class AuditLogger
        {
            /**
             * @var list<array{action: string, targetId: int|null, meta: array<string, mixed>, targetType: string|null}>
             */
            public static array $rows = [];

            /** @param array<string, mixed> $meta */
            public static function log(
                string $action,
                ?int $targetId = null,
                array $meta = [],
                ?string $targetType = null,
                ?int $userId = null
            ): void {
                self::$rows[] = [
                    'action'     => $action,
                    'targetId'   => $targetId,
                    'meta'       => $meta,
                    'targetType' => $targetType,
                ];
            }

            /**
             * PR 6: the CHECKED variant. Returns the inserted row id, or
             * null when the write could not be made.
             *
             * `$failChecked` exists so a test can prove the caller ROLLS BACK
             * on a failed audit rather than reporting success — the property
             * that makes an unattributable state change impossible. A fake
             * that could only succeed would let that guard rot unnoticed.
             *
             * @param array<string, mixed> $meta
             */
            public static function logChecked(
                string $action,
                ?int $targetId = null,
                array $meta = [],
                ?string $targetType = null,
                ?int $userId = null
            ): ?int {
                if (self::$failChecked) {
                    return null;
                }

                self::log($action, $targetId, $meta, $targetType, $userId);

                return count(self::$rows);
            }

            public static bool $failChecked = false;

            public static function reset(): void
            {
                self::$rows = [];
                self::$failChecked = false;
            }

            /** @return list<string> */
            public static function actions(): array
            {
                return array_map(static fn(array $r): string => $r['action'], self::$rows);
            }
        }
    }

    if (!class_exists(TransactionManager::class, false)) {
        /**
         * PR 6: a transaction fake that actually behaves like one.
         *
         * It runs the callback, and on a throw it REWINDS the fake
         * repositories' recorded writes to the mark taken before the callback
         * started. Without the rewind, a test asserting "a failed audit rolls
         * the state change back" would pass against a fake that never rolled
         * anything back — the mutation controls plant exactly that defect.
         */
        final class TransactionManager
        {
            public static int $runs = 0;
            public static int $rollbacks = 0;

            /** @param callable():mixed $callback */
            public static function run(callable $callback, ?int $retryAttempts = null)
            {
                self::$runs++;

                $auditMark = count(AuditLogger::$rows);
                $repoMark  = \BCC\Trust\Onchain\Repositories\CollectionRepository::writeMark();

                try {
                    $result = $callback();
                } catch (\Throwable $e) {
                    self::$rollbacks++;
                    AuditLogger::$rows = array_slice(AuditLogger::$rows, 0, $auditMark);
                    \BCC\Trust\Onchain\Repositories\CollectionRepository::rewindTo($repoMark);
                    throw $e;
                }

                // The real TransactionManager converts a bare `false` into an
                // exception and rolls back; mirroring that here keeps the
                // "never return false from a callback" contract testable.
                if ($result === false) {
                    self::$rollbacks++;
                    AuditLogger::$rows = array_slice(AuditLogger::$rows, 0, $auditMark);
                    \BCC\Trust\Onchain\Repositories\CollectionRepository::rewindTo($repoMark);
                    throw new \Exception('Transaction callback returned false');
                }

                return $result;
            }

            public static function isInRunTransaction(): bool
            {
                return false;
            }

            public static function reset(): void
            {
                self::$runs = 0;
                self::$rollbacks = 0;
            }
        }
    }
}

// ── Onchain collaborators ───────────────────────────────────────────────────

namespace BCC\Trust\Onchain\Services {

    if (!class_exists(ChainRefreshService::class, false)) {
        final class ChainRefreshService
        {
            /** @var list<string> Steps executed, in order. */
            public static array $calls = [];

            /** @var array<string, \Throwable> Step name → exception to throw. */
            public static array $throwOn = [];

            public static function index_validators(): void
            {
                self::record('validators');
            }

            public static function index_collections(): void
            {
                self::record('collections');
            }

            public static function refresh_validators(): void
            {
                self::record('enrichment');
            }

            private static function record(string $step): void
            {
                if (isset(self::$throwOn[$step])) {
                    throw self::$throwOn[$step];
                }
                self::$calls[] = $step;
            }

            public static function reset(): void
            {
                self::$calls = [];
                self::$throwOn = [];
            }
        }
    }

    if (!class_exists(HeliusSubscriptionManager::class, false)) {
        final class HeliusSubscriptionManager
        {
            public static int $provisionCalls = 0;
            public static int $resyncCalls = 0;

            public static ?string $provisionResult = 'wh_123';
            public static ?\Throwable $provisionThrows = null;

            /** @var array{applied: bool, remote_count: int, local_count: int}|null */
            public static ?array $resyncResult = ['applied' => true, 'remote_count' => 5, 'local_count' => 5];
            public static ?\Throwable $resyncThrows = null;

            public static function provisionSharedWebhook(string $callbackUrl): ?string
            {
                self::$provisionCalls++;
                if (self::$provisionThrows !== null) {
                    throw self::$provisionThrows;
                }
                return self::$provisionResult;
            }

            /** @return array{applied: bool, remote_count: int, local_count: int}|null */
            public static function resyncFromWalletLinks(): ?array
            {
                self::$resyncCalls++;
                if (self::$resyncThrows !== null) {
                    throw self::$resyncThrows;
                }
                return self::$resyncResult;
            }

            public static function reset(): void
            {
                self::$provisionCalls = 0;
                self::$resyncCalls = 0;
                self::$provisionResult = 'wh_123';
                self::$provisionThrows = null;
                self::$resyncResult = ['applied' => true, 'remote_count' => 5, 'local_count' => 5];
                self::$resyncThrows = null;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(ChainRepository::class, false)) {
        final class ChainRepository
        {
            /** @var array<int, object> */
            public static array $chains = [];

            public static function getById(int $id): ?object
            {
                return self::$chains[$id] ?? null;
            }

            public static function seed(int $id, string $slug = 'ethereum', string $chainType = 'evm'): void
            {
                self::$chains[$id] = (object) [
                    'id'          => $id,
                    'slug'        => $slug,
                    'name'        => ucfirst($slug),
                    'chain_type'  => $chainType,
                    'is_active'   => 1,
                    'description' => null,
                    'icon_url'    => null,
                    'color'       => null,
                ];
            }

            public static function updateIdentity(
                int $chainId,
                ?string $description,
                ?string $iconUrl,
                ?string $color
            ): bool {
                self::$identityWrites[] = [
                    'chain_id'    => $chainId,
                    'description' => $description,
                    'icon_url'    => $iconUrl,
                    'color'       => $color,
                ];
                if (self::$identityThrows !== null) {
                    throw self::$identityThrows;
                }
                return self::$identityResult;
            }

            /** @var list<array<string, mixed>> */
            public static array $identityWrites = [];
            public static bool $identityResult = true;
            public static ?\Throwable $identityThrows = null;

            public static function reset(): void
            {
                self::$chains = [];
                self::$identityWrites = [];
                self::$identityResult = true;
                self::$identityThrows = null;
            }
        }
    }

    if (!class_exists(ChainCheckpointRepository::class, false)) {
        final class ChainCheckpointRepository
        {
            public const STATE_HEALTHY  = 'healthy';
            public const STATE_DISABLED = 'disabled';

            // The `cw_*` half of the row. It lives on the SAME record as the
            // EVM indexer's state — the table is shared — which is exactly
            // why NftChainCapability only consults it for Cosmos chains.
            public const CW_STATE_IDLE        = 'idle';
            public const CW_STATE_BACKFILLING = 'backfilling';
            public const CW_STATE_BACKFILLED  = 'backfilled';
            public const CW_STATE_UNSUPPORTED = 'unsupported';
            public const CW_STATE_PAUSED      = 'paused';

            /** @var list<array{chain_id: int, state: string}> */
            public static array $stateWrites = [];
            public static bool $setStateResult = true;

            /** @var array<int, object> */
            public static array $rows = [];

            public static function setState(int $chainId, string $state): bool
            {
                self::$stateWrites[] = ['chain_id' => $chainId, 'state' => $state];
                return self::$setStateResult;
            }

            /**
             * No seeded row means "never measured", which production treats
             * as NOT refused — the first pass is what creates the
             * measurement, so refusing an unmeasured chain would be a
             * permanent deadlock dressed up as caution.
             */
            public static function get(int $chainId): ?object
            {
                return self::$rows[$chainId] ?? null;
            }

            public static function seedCwState(int $chainId, string $cwState): void
            {
                self::$rows[$chainId] = (object) ['cw_discovery_state' => $cwState];
            }

            public static function reset(): void
            {
                self::$stateWrites = [];
                self::$setStateResult = true;
                self::$rows = [];
            }
        }
    }

    if (!class_exists(NftSpamContractRepository::class, false)) {
        final class NftSpamContractRepository
        {
            // VC-B1: Hide/Unhide names these directly. Added here rather
            // than in a second fake so one production class keeps one fake.
            public const RULE_DENY  = 'deny';
            public const RULE_ALLOW = 'allow';

            /** @var list<array<string, mixed>> */
            public static array $added = [];
            /** @var list<array<string, mixed>> */
            public static array $removed = [];

            public static bool $addResult = true;
            public static bool $removeResult = true;
            public static ?\Throwable $addThrows = null;

            public static function addRule(int $chainId, string $contract, string $rule, string $reason = ''): bool
            {
                if (self::$addThrows !== null) {
                    throw self::$addThrows;
                }
                self::$added[] = compact('chainId', 'contract', 'rule', 'reason');
                return self::$addResult;
            }

            public static function removeRule(int $chainId, string $contract): bool
            {
                self::$removed[] = compact('chainId', 'contract');
                return self::$removeResult;
            }

            public static function reset(): void
            {
                self::$added = [];
                self::$removed = [];
                self::$addResult = true;
                self::$removeResult = true;
                self::$addThrows = null;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Workers {

    if (!class_exists(NftEthIndexerWorker::class, false)) {
        final class NftEthIndexerWorker
        {
            /** @var list<int> */
            public static array $runs = [];
            public static ?\Throwable $throws = null;

            public static function runForChain(int $chainId): void
            {
                if (self::$throws !== null) {
                    throw self::$throws;
                }
                self::$runs[] = $chainId;
            }

            public static function reset(): void
            {
                self::$runs = [];
                self::$throws = null;
            }
        }
    }
}

namespace BCC\Trust\Onchain\REST {

    if (!class_exists(HeliusWebhookEndpoint::class, false)) {
        final class HeliusWebhookEndpoint
        {
            public static function callbackUrl(): string
            {
                return 'https://example.test/wp-json/bcc/v1/onchain/helius';
            }
        }
    }
}
