<?php

declare(strict_types=1);

/**
 * Collaborator fakes for SignalFetcher::fetchSolana (F-12). Load only in a
 * separate process; every fake sits behind class_exists(…, false). The
 * service's WordPress calls are unqualified inside BCC\Trust\Onchain\Services,
 * so they are faked in that namespace.
 */

namespace {
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }

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

    final class SolanaWalkWorld
    {
        /** @var list<string|\WP_Error> RPC response bodies, FIFO */
        public static array $responses = [];

        /** @var list<array<string, mixed>> decoded request params, in order */
        public static array $requests = [];

        public static function reset(): void
        {
            self::$responses = [];
            self::$requests  = [];
        }

        /** @param mixed $result */
        public static function respond(mixed $result): void
        {
            self::$responses[] = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\ChainRepository', false)) {
        final class ChainRepository
        {
            public static function resolveId(string $slug): ?int
            {
                return 41;
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
             * @return array{body: string}|\WP_Error
             */
            public static function post(string $url, array $args = [], array $options = [])
            {
                $req = json_decode((string) ($args['body'] ?? ''), true);
                \SolanaWalkWorld::$requests[] = is_array($req) ? $req : [];
                if (\SolanaWalkWorld::$responses === []) {
                    return new \WP_Error('down', 'no response queued');
                }
                $next = array_shift(\SolanaWalkWorld::$responses);
                return $next instanceof \WP_Error ? $next : ['body' => $next];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!function_exists(__NAMESPACE__ . '\\wp_json_encode')) {
        function wp_json_encode(mixed $data): string|false
        {
            return json_encode($data);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\is_wp_error')) {
        function is_wp_error(mixed $thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body(mixed $response): string
        {
            return is_array($response) ? (string) ($response['body'] ?? '') : '';
        }
    }
}
