<?php

declare(strict_types=1);

/**
 * Collaborator fakes for HeliusSubscriptionManager (F-64 / F-70).
 *
 * The manager's WordPress calls are unqualified inside
 * BCC\Trust\Onchain\Services, so they are faked IN THAT NAMESPACE and the
 * global function table other suites share is never touched. The repositories,
 * the lock, the logger and ApiRetry are faked behind class_exists(…, false):
 * load this only in a separate process, before any of them is autoloaded.
 * RepositoryReadFailure is the REAL class.
 */

namespace {
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

    final class HeliusWorld
    {
        /** @var array<string, mixed> */
        public static array $options = [];

        /** @var list<array{code: int, body: string}|\WP_Error> responses to webhook GETs, FIFO */
        public static array $gets = [];

        /** @var list<int> status codes for PUTs, FIFO (default 200) */
        public static array $putCodes = [];

        /** @var list<list<string>> the accountAddresses body of every PUT, in order */
        public static array $puts = [];

        public static int $getCalls = 0;

        /** @var list<bool> results for successive lock acquires (default true) */
        public static array $lockResults = [];

        public static int $lockHeld = 0;

        /** @var list<string> acquire/release, in order */
        public static array $lockLog = [];

        /** @var array<int, list<array{id: int, wallet_address: string}>> 1 = managed, 0 = unmanaged */
        public static array $links = [1 => [], 0 => []];

        public static bool $listReadFails = false;

        public static int $otherLinks = 0;

        public static bool $countReadFails = false;

        /** @var list<array{0: int, 1: bool}> */
        public static array $marked = [];

        public static bool $throwInsideLock = false;

        /** Calls that bypassed ApiRetry/SafeHttpClient via a bare wp_remote_request(). */
        public static int $rawRequests = 0;

        /** @var list<array<string, mixed>> the args of every ApiRetry::post call */
        public static array $postArgs = [];

        public static function reset(): void
        {
            self::$rawRequests   = 0;
            self::$postArgs      = [];
            $GLOBALS['wpdb']     = (object) ['prefix' => 'wpsa6_'];
            self::$options       = ['bcc_helius_shared_webhook_id' => 'wh-1'];
            self::$gets          = [];
            self::$putCodes      = [];
            self::$puts          = [];
            self::$getCalls      = 0;
            self::$lockResults   = [];
            self::$lockHeld      = 0;
            self::$lockLog       = [];
            self::$links         = [1 => [], 0 => []];
            self::$listReadFails = false;
            self::$otherLinks    = 0;
            self::$countReadFails = false;
            self::$marked        = [];
            self::$throwInsideLock = false;
        }

        /** @param mixed $addresses */
        public static function remoteList(mixed $addresses): void
        {
            self::$gets[] = ['code' => 200, 'body' => (string) json_encode(['webhookID' => 'wh-1', 'accountAddresses' => $addresses])];
        }

        /** @param array<string, mixed> $detail */
        public static function remoteDetail(array $detail): void
        {
            self::$gets[] = ['code' => 200, 'body' => (string) json_encode($detail)];
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists(__NAMESPACE__ . '\\AdvisoryLock', false)) {
        class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool
            {
                $ok = \HeliusWorld::$lockResults === [] ? true : array_shift(\HeliusWorld::$lockResults);
                \HeliusWorld::$lockLog[] = 'acquire:' . $key . ':' . $timeout . ':' . ($ok ? 'ok' : 'busy');
                if ($ok) {
                    \HeliusWorld::$lockHeld++;
                }
                return $ok;
            }

            public static function release(string $key): void
            {
                \HeliusWorld::$lockLog[] = 'release:' . $key;
                \HeliusWorld::$lockHeld--;
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @var list<string> */
            public static array $lines = [];

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void
            {
                self::$lines[] = 'info:' . $m;
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
                self::$lines[] = 'warning:' . $m;
            }

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                self::$lines[] = 'error:' . $m;
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
             * @return array{code: int, body: string}|\WP_Error
             */
            public static function get(string $url, array $args = [], array $options = [])
            {
                \HeliusWorld::$getCalls++;
                if (\HeliusWorld::$throwInsideLock) {
                    throw new \RuntimeException('boom inside the critical section');
                }
                return \HeliusWorld::$gets === [] ? new \WP_Error('down', 'no response queued') : array_shift(\HeliusWorld::$gets);
            }

            /**
             * @param array<string, mixed> $options
             * @return mixed
             */
            public static function request(callable $fn, array $options = [])
            {
                return $fn();
            }

            /**
             * The PUT now arrives here (SafeHttpClient-hardened path), with
             * `method => PUT` in the args.
             *
             * @param array<string, mixed> $args
             * @param array<string, mixed> $options
             * @return array{code: int, body: string}|\WP_Error
             */
            public static function post(string $url, array $args = [], array $options = [])
            {
                \HeliusWorld::$postArgs[] = $args;
                if (($args['method'] ?? 'POST') !== 'PUT') {
                    return new \WP_Error('unexpected', 'only the address-list PUT is modelled');
                }
                $decoded = json_decode((string) ($args['body'] ?? ''), true);
                \HeliusWorld::$puts[] = is_array($decoded) ? array_values((array) ($decoded['accountAddresses'] ?? [])) : [];
                $code = \HeliusWorld::$putCodes === [] ? 200 : array_shift(\HeliusWorld::$putCodes);
                return ['code' => $code, 'body' => '{}'];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\ChainRepository', false)) {
        final class ChainRepository
        {
            public static function getBySlug(string $slug): ?object
            {
                return $slug === 'solana' ? (object) ['id' => 7, 'slug' => 'solana'] : null;
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\WalletRepository', false)) {
        final class WalletRepository
        {
            /** @return list<array{id: int, wallet_address: string}> */
            public static function listForChainBySolanaSync(int $chainId, bool $heliusManaged): array
            {
                // The FAIL-SAFE read: a failure looks like an empty table.
                return \HeliusWorld::$listReadFails ? [] : \HeliusWorld::$links[$heliusManaged ? 1 : 0];
            }

            /** @return list<array{id: int, wallet_address: string}> */
            public static function listForChainBySolanaSyncOrThrow(int $chainId, bool $heliusManaged): array
            {
                if (\HeliusWorld::$listReadFails) {
                    throw new RepositoryReadFailure(self::class, 'listForChainBySolanaSyncOrThrow', 'Lost connection');
                }
                return \HeliusWorld::$links[$heliusManaged ? 1 : 0];
            }

            public static function countOtherLinksForChainAddress(int $chainId, string $walletAddress, int $excludeId): int
            {
                if (\HeliusWorld::$countReadFails) {
                    throw new RepositoryReadFailure(self::class, 'countOtherLinksForChainAddress', 'Lost connection');
                }
                return \HeliusWorld::$otherLinks;
            }

            public static function markHeliusManaged(int $walletLinkId, bool $managed): bool
            {
                \HeliusWorld::$marked[] = [$walletLinkId, $managed];
                return true;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return array_key_exists($name, \HeliusWorld::$options) ? \HeliusWorld::$options[$name] : $default;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\is_wp_error')) {
        function is_wp_error(mixed $thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code(mixed $response): int
        {
            return is_array($response) ? (int) ($response['code'] ?? 0) : 0;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body(mixed $response): string
        {
            return is_array($response) ? (string) ($response['body'] ?? '') : '';
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_json_encode')) {
        function wp_json_encode(mixed $data): string|false
        {
            return json_encode($data);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_request')) {
        /**
         * @param array<string, mixed> $args
         * @return array{code: int, body: string}
         */
        function wp_remote_request(string $url, array $args = []): array
        {
            \HeliusWorld::$rawRequests++;
            $decoded = json_decode((string) ($args['body'] ?? ''), true);
            \HeliusWorld::$puts[] = is_array($decoded) ? array_values((array) ($decoded['accountAddresses'] ?? [])) : [];
            $code = \HeliusWorld::$putCodes === [] ? 200 : array_shift(\HeliusWorld::$putCodes);
            return ['code' => $code, 'body' => '{}'];
        }
    }
}
