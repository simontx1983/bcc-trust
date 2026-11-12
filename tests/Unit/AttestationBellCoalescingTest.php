<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Services {
    // Unqualified get_transient / set_transient inside NotificationDispatcher
    // resolve here first, so the gate runs against an in-memory store.
    if (!function_exists(__NAMESPACE__ . '\\get_transient')) {
        /** @return mixed */
        function get_transient(string $key)
        {
            return $GLOBALS['__bcc_bell_transients'][$key]['value'] ?? false;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\set_transient')) {
        /** @param mixed $value */
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            $GLOBALS['__bcc_bell_transients'][$key] = ['value' => $value, 'ttl' => $ttl];
            return true;
        }
    }
}

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Core\Services\NotificationDispatcher;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    /**
     * F-78 — toggling a vouch on and off must not write a bell row per toggle.
     *
     * Every cast is a new attestation row, so vouch → unvouch → vouch wrote
     * "vouched for you", "revoked their vouch", "vouched for you", … to the
     * recipient's bell. Push already self-debounces; the bell did not.
     *
     * The gate: one bell per (event, attestor, recipient, kind) per 24 h —
     * the hall-post / comment-received transient shape. A storm leaves at
     * most one "vouched" and one "revoked"; a genuine first vouch and a
     * genuine revoke still each notify.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class AttestationBellCoalescingTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['__bcc_bell_transients'] = [];
        }

        private function open(string $event, int $attestor, int $recipient, string $kind): bool
        {
            $d = (new \ReflectionClass(NotificationDispatcher::class))->newInstanceWithoutConstructor();
            $m = new ReflectionMethod(NotificationDispatcher::class, 'attestationBellOpen');
            return (bool) $m->invoke($d, $event, $attestor, $recipient, $kind);
        }

        public function testAToggleStormRingsTheBellOncePerEvent(): void
        {
            $rings = [];
            foreach (['created', 'revoked', 'created', 'revoked', 'created'] as $event) {
                $rings[] = $this->open($event, 7, 42, 'vouch');
            }

            self::assertSame([true, true, false, false, false], $rings);
        }

        public function testTheWindowIsADay(): void
        {
            $this->open('created', 7, 42, 'vouch');

            self::assertSame(86400, $GLOBALS['__bcc_bell_transients']['bcc_attest_bell_created_vouch_7_42']['ttl']);
        }

        public function testOtherPairsAndKindsAreNotCoalescedTogether(): void
        {
            self::assertTrue($this->open('created', 7, 42, 'vouch'));
            self::assertTrue($this->open('created', 7, 42, 'stand_behind'), 'backing is a different notice from vouching');
            self::assertTrue($this->open('created', 8, 42, 'vouch'), 'another attestor still notifies');
            self::assertTrue($this->open('created', 7, 43, 'vouch'), 'another recipient still notifies');
        }

        /**
         * Both bell writes go through the gate. Structural, because the full
         * dispatch path needs PeepSo; a handler that dispatched around the
         * gate would bring the spam back with the gate itself still green.
         */
        public function testBothAttestationHandlersRouteTheBellThroughTheGate(): void
        {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/NotificationDispatcher.php');

            foreach (['onAttestationCreated' => "'created'", 'onAttestationRevoked' => "'revoked'"] as $handler => $event) {
                $start = strpos($src, 'public function ' . $handler . '(');
                self::assertNotFalse($start, $handler);
                $end  = strpos($src, "\n    }\n", $start) ?: strpos($src, "\r\n    }\r\n", $start);
                $body = substr($src, $start, (int) $end - $start);

                $gate     = strpos($body, '$this->attestationBellOpen(' . $event);
                $dispatch = strpos($body, '$this->dispatch(');
                self::assertNotFalse($gate, "{$handler} must consult the bell gate");
                self::assertNotFalse($dispatch, "{$handler} must still write the bell");
                self::assertLessThan($dispatch, $gate, "{$handler}: the gate must come before the write");
                self::assertSame(1, substr_count($body, '$this->dispatch('), "{$handler}: exactly one bell write");
            }
        }
    }
}
