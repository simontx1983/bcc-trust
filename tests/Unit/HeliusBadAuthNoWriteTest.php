<?php

declare(strict_types=1);

// ── Recording stubs (defined BEFORE the shared helius stubs; first wins) ─────
namespace {
    $GLOBALS['__f49'] = [
        'options'    => [],
        'transients' => [],
        'writes'     => [],   // every option/transient write, in order
        'log_rows'   => [],   // HeliusDeliveryLog::record() calls
        'ip'         => '198.51.100.7',
    ];

    if (!function_exists('get_option')) {
        /** @param mixed $default @return mixed */
        function get_option(string $name, $default = false)
        {
            return $GLOBALS['__f49']['options'][$name] ?? $default;
        }
    }
    if (!function_exists('update_option')) {
        /** @param mixed $value */
        function update_option(string $name, $value, $autoload = null): bool
        {
            $GLOBALS['__f49']['writes'][]          = 'option:' . $name;
            $GLOBALS['__f49']['options'][$name] = $value;
            return true;
        }
    }
    if (!function_exists('get_transient')) {
        /** @return mixed */
        function get_transient(string $key)
        {
            return $GLOBALS['__f49']['transients'][$key] ?? false;
        }
    }
    if (!function_exists('set_transient')) {
        /** @param mixed $value */
        function set_transient(string $key, $value, int $exp = 0): bool
        {
            $GLOBALS['__f49']['writes'][]            = 'transient:' . $key;
            $GLOBALS['__f49']['transients'][$key] = $value;
            return true;
        }
    }
}

namespace BCC\Trust\Core\Security {
    if (!class_exists(__NAMESPACE__ . '\\IpResolver', false)) {
        class IpResolver
        {
            public static function getClientIp(): string
            {
                return (string) $GLOBALS['__f49']['ip'];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!class_exists(__NAMESPACE__ . '\\HeliusDeliveryLog', false)) {
        class HeliusDeliveryLog
        {
            public const OUTCOME_PROCESSED   = 'processed';
            public const OUTCOME_AUTH_FAILED = 'auth_failed';
            public const OUTCOME_NO_PAYLOAD  = 'no_payload';
            public const OUTCOME_NO_CHAIN    = 'no_chain';

            /** @param array<string, mixed> $data */
            public static function record(array $data): void
            {
                // The real record() is an option read-modify-write.
                $GLOBALS['__f49']['writes'][]   = 'delivery_log';
                $GLOBALS['__f49']['log_rows'][] = $data;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Trust\Onchain\REST\HeliusWebhookEndpoint;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-49 — the Helius webhook wrote to the DB on every unauthenticated
     * request: a transient write, a sigfail_total read-modify-write and a
     * read-modify-write of the whole 200-row delivery-log option. A junk
     * flood was write load on demand and evicted the real delivery history.
     *
     * Now the first bad-auth request from an IP in a SIGFAIL window writes
     * (window marker, counter, one auth_failed log row); every further one
     * from that IP in the window writes NOTHING. The response stays 200.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class HeliusBadAuthNoWriteTest extends TestCase
    {
        private const SECRET = 'Bearer real-secret';

        protected function setUp(): void
        {
            parent::setUp();
            require_once __DIR__ . '/../Stubs/helius-webhook-stubs.php';
            if (!defined('BCC_HELIUS_WEBHOOK_SECRET')) {
                define('BCC_HELIUS_WEBHOOK_SECRET', self::SECRET);
            }
        }

        private static function badRequest(): \WP_REST_Response
        {
            return HeliusWebhookEndpoint::handle(new \WP_REST_Request('Bearer wrong', [['signature' => 'x']]));
        }

        public function testAFloodFromOneIpWritesOnlyOncePerWindow(): void
        {
            for ($i = 0; $i < 500; $i++) {
                self::assertSame(200, self::badRequest()->get_status(), 'always-200 must hold');
            }

            self::assertSame(
                ['transient:bcc_helius_sigfail_' . md5('198.51.100.7'), 'option:bcc_helius_signature_sigfail_total', 'delivery_log'],
                $GLOBALS['__f49']['writes'],
                '500 bad requests from one IP in a window = exactly one set of writes'
            );
            self::assertCount(1, $GLOBALS['__f49']['log_rows']);
            self::assertSame('auth_failed', $GLOBALS['__f49']['log_rows'][0]['outcome']);
            self::assertFalse($GLOBALS['__f49']['log_rows'][0]['auth_passed']);
            self::assertSame(1, $GLOBALS['__f49']['options']['bcc_helius_signature_sigfail_total']);
        }

        public function testEachNewIpIsRecordedOnce(): void
        {
            foreach (['203.0.113.1', '203.0.113.2', '203.0.113.1', '203.0.113.2'] as $ip) {
                $GLOBALS['__f49']['ip'] = $ip;
                self::badRequest();
            }

            self::assertCount(2, $GLOBALS['__f49']['log_rows']);
            self::assertSame(2, $GLOBALS['__f49']['options']['bcc_helius_signature_sigfail_total']);
        }

        public function testANewWindowRecordsAgain(): void
        {
            self::badRequest();
            $GLOBALS['__f49']['transients'] = []; // window expired
            self::badRequest();

            self::assertCount(2, $GLOBALS['__f49']['log_rows']);
            self::assertSame(2, $GLOBALS['__f49']['options']['bcc_helius_signature_sigfail_total']);
        }
    }
}
