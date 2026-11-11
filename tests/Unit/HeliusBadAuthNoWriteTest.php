<?php

declare(strict_types=1);

// ── Recording stubs (defined BEFORE the shared helius stubs; first wins) ─────
namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    $GLOBALS['__f49'] = [
        'options'    => [],
        'transients' => [],
        'writes'     => [],   // every option/transient write, in order
        'ip'         => '198.51.100.7',
    ];

    if (!function_exists('get_option')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $name, $default = false)
        {
            return $GLOBALS['__f49']['options'][$name] ?? $default;
        }
    }
    if (!function_exists('update_option')) {
        /**
         * @param mixed     $value
         * @param bool|null $autoload
         */
        function update_option(string $name, $value, $autoload = null): bool
        {
            $GLOBALS['__f49']['writes'][]       = 'option:' . $name;
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
            $GLOBALS['__f49']['writes'][]         = 'transient:' . $key;
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

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Trust\Onchain\REST\HeliusWebhookEndpoint;
    use BCC\Trust\Onchain\Services\HeliusDeliveryLog;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-49 — the Helius webhook wrote to the DB on every unauthenticated
     * request: a transient write, a sigfail_total read-modify-write and a
     * read-modify-write of the whole 200-row delivery-log option. A junk
     * flood was write load on demand and evicted the real delivery history.
     *
     * Now the first bad-auth request from a NETWORK (exact IPv4, or IPv6
     * /64) in a window writes the window marker and the counter, and an
     * auth_failed row only while the GLOBAL per-window cap has room; every
     * further request from that network writes nothing. The response stays
     * 200. Uses the REAL HeliusDeliveryLog (its 200-row trim included).
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class HeliusBadAuthNoWriteTest extends TestCase
    {
        private const SECRET = 'Bearer real-secret';
        private const SIGFAIL_TOTAL = 'bcc_helius_signature_sigfail_total';

        protected function setUp(): void
        {
            parent::setUp();
            // Load the REAL delivery log before the shared stubs, whose
            // guarded no-op HeliusDeliveryLog would otherwise win.
            self::assertTrue(class_exists(HeliusDeliveryLog::class));
            require_once __DIR__ . '/../Stubs/helius-webhook-stubs.php';
            if (!defined('BCC_HELIUS_WEBHOOK_SECRET')) {
                define('BCC_HELIUS_WEBHOOK_SECRET', self::SECRET);
            }
        }

        private static function badRequestFrom(string $ip): void
        {
            $GLOBALS['__f49']['ip'] = $ip;
            $r = HeliusWebhookEndpoint::handle(new \WP_REST_Request('Bearer wrong', [['signature' => 'x']]));
            self::assertSame(200, $r->get_status(), 'always-200 must hold');
        }

        /** @return list<array<string, mixed>> */
        private static function ring(): array
        {
            $rows = $GLOBALS['__f49']['options'][HeliusDeliveryLog::OPTION_KEY] ?? [];
            return is_array($rows) ? array_values($rows) : [];
        }

        /** @return list<array<string, mixed>> */
        private static function rowsWithOutcome(string $outcome): array
        {
            return array_values(array_filter(self::ring(), static fn (array $r): bool => $r['outcome'] === $outcome));
        }

        public function testAFloodFromOneIpWritesOnlyOncePerWindow(): void
        {
            for ($i = 0; $i < 500; $i++) {
                self::badRequestFrom('198.51.100.7');
            }

            self::assertSame(
                [
                    'transient:bcc_helius_sigfail_' . md5('198.51.100.7'),
                    'option:' . self::SIGFAIL_TOTAL,
                    'transient:bcc_helius_sigfail_logged',
                    'option:' . HeliusDeliveryLog::OPTION_KEY,
                ],
                $GLOBALS['__f49']['writes'],
                '500 bad requests from one IP in a window = exactly one set of writes'
            );
            self::assertCount(1, self::rowsWithOutcome(HeliusDeliveryLog::OUTCOME_AUTH_FAILED));
            self::assertSame(1, get_option(self::SIGFAIL_TOTAL));
        }

        public function testEveryAddressInOneIpv6Slash64IsOneNetwork(): void
        {
            self::badRequestFrom('2001:db8:aa:1::1');
            self::badRequestFrom('2001:db8:aa:1:ffff:ffff:ffff:fffe'); // same /64
            self::badRequestFrom('2001:db8:aa:1:1234::9');             // same /64

            self::assertSame(1, get_option(self::SIGFAIL_TOTAL), 'one /64 = one network');

            self::badRequestFrom('2001:db8:aa:2::1'); // next /64
            self::assertSame(2, get_option(self::SIGFAIL_TOTAL));
        }

        public function testManyNetworksCannotEvictRealDeliveriesFromTheRing(): void
        {
            // A full ring of real deliveries.
            $seed = [];
            for ($i = 0; $i < HeliusDeliveryLog::MAX_ENTRIES; $i++) {
                $seed[] = ['received_at' => 1000 + $i, 'outcome' => HeliusDeliveryLog::OUTCOME_PROCESSED,
                           'auth_passed' => true, 'tx_count' => 1, 'events' => 1, 'replays' => 0, 'ip' => '10.0.0.1'];
            }
            $GLOBALS['__f49']['options'][HeliusDeliveryLog::OPTION_KEY] = $seed;

            // 300 distinct IPv4 sources + 300 distinct IPv6 /64s in one window.
            for ($i = 0; $i < 300; $i++) {
                self::badRequestFrom(sprintf('203.0.%d.%d', intdiv($i, 250), ($i % 250) + 1));
                self::badRequestFrom(sprintf('2001:db8:%x::1', $i + 1));
            }

            self::assertCount(5, self::rowsWithOutcome(HeliusDeliveryLog::OUTCOME_AUTH_FAILED), 'auth_failed rows are capped globally per window');
            self::assertCount(HeliusDeliveryLog::MAX_ENTRIES - 5, self::rowsWithOutcome(HeliusDeliveryLog::OUTCOME_PROCESSED), 'real delivery history survives');
            self::assertSame(1000 + 5, self::ring()[0]['received_at'], 'only the 5 oldest real rows were trimmed');
            self::assertSame(600, get_option(self::SIGFAIL_TOTAL), 'the counter still counts every new network');
        }

        public function testANewWindowRecordsAgain(): void
        {
            self::badRequestFrom('198.51.100.7');
            $GLOBALS['__f49']['transients'] = []; // window expired
            self::badRequestFrom('198.51.100.7');

            self::assertCount(2, self::rowsWithOutcome(HeliusDeliveryLog::OUTCOME_AUTH_FAILED));
            self::assertSame(2, get_option(self::SIGFAIL_TOTAL));
        }
    }
}
