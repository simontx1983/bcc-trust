<?php
/**
 * F-75 test world for PushDispatcher's send path.
 *
 * Loaded in SEPARATE processes only (PushDispatcherDnsPinTest), so the
 * class stubs below win over the real autoloaded classes.
 *
 *  - SafeHttpClient: per-host verdicts from PushPinWorld. prepareArgs()
 *    returns a WP_Error, or args carrying the pin; resolveEntryFor() reads
 *    it back. The real decision logic is bcc-core's and is tested there.
 *  - PushMetrics: counts per outcome.
 *  - function_exists() is shadowed in the PushDispatcher namespace so the
 *    "no cURL" path can be exercised; it delegates otherwise.
 */

declare(strict_types=1);

namespace {
    if (!defined('BCC_PUSH_VAPID_PUBLIC_KEY')) {
        define('BCC_PUSH_VAPID_PUBLIC_KEY', 'test-public');
        define('BCC_PUSH_VAPID_PRIVATE_KEY', 'test-private');
        define('BCC_PUSH_VAPID_SUBJECT', 'mailto:ops@example.test');
    }

    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            public function __construct(public string $code = '', public string $message = '')
            {
            }
        }
    }

    if (!function_exists('wp_parse_url')) {
        /** @return mixed */
        function wp_parse_url(string $url, int $component = -1)
        {
            return parse_url($url, $component);
        }
    }
    if (!function_exists('current_time')) {
        function current_time(string $type, bool $gmt = false): string
        {
            return gmdate('Y-m-d H:i:s');
        }
    }
    if (!function_exists('get_transient')) {
        /** @return mixed */
        function get_transient(string $key)
        {
            return $GLOBALS['__push_pin']['transients'][$key] ?? false;
        }
    }
    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            unset($GLOBALS['__push_pin']['transients'][$key]);
            return true;
        }
    }
}

namespace BCC\Trust\Tests\Unit {
    final class PushPinWorld
    {
        /** @var array<string, string|null|false> host => pin entry, null (IP literal), false (refused) */
        public static array $byHost = [];

        /** @var list<string> */
        public static array $validated = [];

        public static bool $noCurl = false;

        /** @var array<string, int> */
        public static array $metrics = [];

        /** @var list<string> Logger::error lines; a test asserts none were unexpected. */
        public static array $errors = [];

        public static function reset(): void
        {
            self::$errors    = [];
            self::$byHost    = [];
            self::$validated = [];
            self::$noCurl    = false;
            self::$metrics   = [];
        }
    }
}

namespace BCC\Core\Http {
    use BCC\Trust\Tests\Unit\PushPinWorld;

    final class SafeHttpClient
    {
        private const PIN_ARG = '_test_pin';

        /**
         * @param array<string, mixed> $args
         * @return array<string, mixed>|\WP_Error
         */
        public static function prepareArgs(string $url, array $args)
        {
            PushPinWorld::$validated[] = $url;
            $host    = (string) parse_url($url, PHP_URL_HOST);
            // array_key_exists: a NULL verdict (IP literal) is not "refused".
            $verdict = array_key_exists($host, PushPinWorld::$byHost) ? PushPinWorld::$byHost[$host] : false;
            if ($verdict === false) {
                return new \WP_Error('ssrf_blocked', 'blocked ' . $host);
            }
            if (is_string($verdict)) {
                $args[self::PIN_ARG] = $verdict;
            }
            return $args;
        }

        /** @param array<string, mixed> $parsedArgs */
        public static function resolveEntryFor(array $parsedArgs, string $url): ?string
        {
            $pin = $parsedArgs[self::PIN_ARG] ?? null;
            return is_string($pin) ? $pin : null;
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $ctx */
            public static function warning(string $msg, array $ctx = []): void
            {
            }
            /** @param array<string, mixed> $ctx */
            public static function error(string $msg, array $ctx = []): void
            {
                \BCC\Trust\Tests\Unit\PushPinWorld::$errors[] = $msg . ' ' . json_encode($ctx);
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    use BCC\Trust\Tests\Unit\PushPinWorld;

    final class PushMetrics
    {
        private static function bump(string $k): void
        {
            PushPinWorld::$metrics[$k] = (PushPinWorld::$metrics[$k] ?? 0) + 1;
        }
        public static function recordAttempt(string $eventType): void { self::bump('attempt'); }
        public static function recordSuccess(string $eventType): void { self::bump('success'); }
        public static function recordTombstone(string $eventType): void { self::bump('tombstone'); }
        public static function recordFailure(string $eventType): void { self::bump('failure'); }
    }
}

namespace BCC\Trust\Core\Services {
    use BCC\Trust\Tests\Unit\PushPinWorld;

    function function_exists(string $name): bool
    {
        if ($name === 'curl_init' && PushPinWorld::$noCurl) {
            return false;
        }
        return \function_exists($name);
    }
}
