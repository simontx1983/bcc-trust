<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\ValueObjects\CosmwasmEnumerationFailure;
use BCC\Trust\Onchain\ValueObjects\ProviderFailureKind;
use BCC\Trust\Onchain\ValueObjects\ProviderRequestClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: every breaker charge leaves a bounded, validated reason —
 * and nothing that does not charge leaves anything at all.
 *
 * ── THE DEFECT THIS PINS ────────────────────────────────────────────────
 * Run 8 (2026-09-09) re-opened chain 8's breaker with eight charges and no
 * durable explanation. PR 7.7's `cw_last_error` was correctly NULL — every
 * enumeration read had succeeded — so the charges came from a request class
 * with no telemetry hook of its own. Path-by-path attribution cannot close
 * that: the breaker is shared, so any un-hooked caller charges invisibly.
 *
 * ── WHY THE WHOLE STACK IS REAL ─────────────────────────────────────────
 * The claim is an agreement between `ApiRetry`'s charging rule and the
 * attribution stored by `OnchainCircuitBreaker`. Faking either would compare
 * a rule to a copy of itself — how PR 7.6 shipped a breaker fix that unified
 * three of its four readers. Only the WIRE is scripted.
 */
#[CoversClass(ProviderFailureKind::class)]
#[CoversClass(ProviderRequestClass::class)]
#[CoversClass(OnchainCircuitBreaker::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BreakerAttributionTest extends TestCase
{
    private const CHAIN = 91;

    private const WIRE_FAILURE = '__wire_failure__';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/enumeration-real-transport-stubs.php';

        \BccWire::reset();
        \BccBreakerStore::reset();
    }

    /** @param array<string,mixed> $options */
    private function fire(mixed $response, array $options = []): void
    {
        \BccWire::$always = $response === self::WIRE_FAILURE
            ? new \WP_Error('http_request_failed', 'cURL error 28: https://user:secret@lcd.test/p')
            : $response;

        ApiRetry::get('https://lcd.test/p', [], $options + [
            'chain_id' => self::CHAIN,
            'label'    => 'test',
        ]);
    }

    private function charges(): int
    {
        return \BccBreakerStore::counter(self::CHAIN) ?? 0;
    }

    // ── 1/2/3: each charging outcome records its bounded kind ───────────

    /**
     * @return array<string, array{0: mixed, 1: int, 2: string|null}>
     *         [scripted response, expected charges, expected kind]
     */
    public static function chargingOutcomes(): array
    {
        return [
            'HTTP 429 rate limit'  => [['code' => 429, 'body' => '{}'], 1, ProviderFailureKind::RATE_LIMITED],
            'HTTP 500'             => [['code' => 500, 'body' => '{}'], 4, ProviderFailureKind::HTTP_5XX],
            'HTTP 502'             => [['code' => 502, 'body' => '{}'], 4, ProviderFailureKind::HTTP_5XX],
            'HTTP 503'             => [['code' => 503, 'body' => '{}'], 4, ProviderFailureKind::HTTP_5XX],
            'HTTP 504'             => [['code' => 504, 'body' => '{}'], 4, ProviderFailureKind::HTTP_5XX],
            // ⚠ 501 IS A 5xx AND CHARGES. It is a durable "unsupported" state
            // on the CosmWasm code path, which is a different question; here
            // it is simply a server error that cost four charges.
            'HTTP 501'             => [['code' => 501, 'body' => '{}'], 4, ProviderFailureKind::HTTP_5XX],
            'wire failure'         => [self::WIRE_FAILURE, 4, ProviderFailureKind::TRANSPORT],
        ];
    }

    #[DataProvider('chargingOutcomes')]
    public function testEveryChargingOutcomeRecordsItsBoundedKind(
        mixed $response,
        int $expectedCharges,
        ?string $expectedKind
    ): void {
        $this->fire($response);

        self::assertNotSame([], \BccWire::$urls, 'anti-vacuity: the real transport must have been called');
        self::assertSame($expectedCharges, $this->charges(), 'charge count must be unchanged by attribution');

        $attr = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertSame($expectedKind, $attr['kind']);
        self::assertTrue(ProviderFailureKind::isValid((string) $attr['kind']));
        self::assertSame(ProviderRequestClass::STANDARD_REQUEST, $attr['request_class']);
    }

    // ── 4: nothing that does not charge is recorded ─────────────────────

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonChargingOutcomes(): array
    {
        return [
            'HTTP 400' => [['code' => 400, 'body' => '{}']],
            'HTTP 401' => [['code' => 401, 'body' => '{}']],
            'HTTP 403' => [['code' => 403, 'body' => '{}']],
            'HTTP 404' => [['code' => 404, 'body' => '{}']],
            'HTTP 409' => [['code' => 409, 'body' => '{}']],
            'HTTP 301' => [['code' => 301, 'body' => '{}']],
            'HTTP 200' => [['code' => 200, 'body' => '{}']],
        ];
    }

    #[DataProvider('nonChargingOutcomes')]
    public function testAnOutcomeThatDoesNotChargeRecordsNothing(mixed $response): void
    {
        $this->fire($response);

        self::assertNotSame([], \BccWire::$urls, 'anti-vacuity: the transport must have been called');
        self::assertSame(0, $this->charges(), 'this outcome must not charge the breaker');

        $attr = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertNull($attr['kind'], 'a non-charging outcome must leave no attribution');
        self::assertNull($attr['request_class']);
    }

    /**
     * ⚠ A CONTRACT'S OWN REFUSAL IS NOT A PROVIDER FAILURE.
     *
     * The `application_error` opt-in tells the transport that this 5xx is the
     * CONTRACT answering, not the node failing. It must skip the retry, skip
     * the charge, and therefore leave no attribution — otherwise a healthy
     * non-NFT contract saying "I don't support that query" would be recorded
     * as the provider being broken.
     */
    public function testAnApplicationErrorRecordsNothingAndDoesNotCharge(): void
    {
        $this->fire(
            ['code' => 500, 'body' => (string) json_encode([
                'code'    => 2,
                'message' => 'Error parsing into type cw20_base::msg::QueryMsg: unknown variant `num_tokens`',
                'details' => [],
            ])],
            ['application_error' => static fn(string $body, int $code): bool => true]
        );

        self::assertNotSame([], \BccWire::$urls);
        self::assertSame(0, $this->charges(), 'a contract answer must not charge the breaker');
        self::assertSame([], \BccWire::$sleeps, 'a contract answer must not be retried');

        $attr = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertNull($attr['kind']);
    }

    /** …but a 5xx the opt-in DECLINES is still the node's problem. */
    public function testADeclinedApplicationErrorIsStillAProviderFailure(): void
    {
        $this->fire(
            ['code' => 500, 'body' => '{}'],
            ['application_error' => static fn(string $body, int $code): bool => false]
        );

        self::assertSame(4, $this->charges());
        $attr = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertSame(ProviderFailureKind::HTTP_5XX, $attr['kind']);
        // The opt-in is present, so this WAS a smart query.
        self::assertSame(ProviderRequestClass::SMART_QUERY, $attr['request_class']);
    }

    // ── 5: nothing raw can reach stored state ───────────────────────────

    /**
     * The stored state is scanned as a whole — not just the fields this test
     * expects — so a future field that leaked prose would still fail here.
     */
    public function testNoProviderOrExceptionTextCanReachStoredState(): void
    {
        $this->fire(self::WIRE_FAILURE);

        $blob = (string) json_encode([
            \BccBreakerStore::state(self::CHAIN),
            \BccBreakerStore::$options,
            \BccBreakerStore::$cache,
            \BccBreakerStore::$transients,
        ]);

        foreach (['cURL', 'secret', 'lcd.test', 'https://', 'error 28', 'Exception', 'Fatal'] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $blob, "stored state must not contain '{$leak}'");
        }

        $attr = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertContains($attr['kind'], ProviderFailureKind::all());
    }

    /** A hostile value handed straight to the breaker is refused, not stored. */
    public function testAHostileKindIsRefusedRatherThanStored(): void
    {
        foreach ([
            'rpc error: code = Unavailable desc = connection refused',
            '<script>alert(1)</script>',
            "'; DROP TABLE wp_options; --",
            'https://user:secret@node.example/p',
            str_repeat('X', 500),
            '',
        ] as $hostile) {
            \BccBreakerStore::reset();
            OnchainCircuitBreaker::recordFailure(self::CHAIN, $hostile, $hostile);

            $attr = OnchainCircuitBreaker::attribution(self::CHAIN);
            self::assertNull($attr['kind'], 'a non-token must be refused');
            self::assertNull($attr['request_class']);
            self::assertStringNotContainsString(
                'DROP TABLE',
                (string) json_encode(\BccBreakerStore::$transients)
            );
        }
    }

    // ── 6/7: recovery clears everything, consistently ───────────────────

    public function testASuccessClearsCounterOpenStateAndAttributionTogether(): void
    {
        $this->fire(['code' => 503, 'body' => '{}']);
        self::assertSame(4, $this->charges());
        self::assertSame(ProviderFailureKind::HTTP_5XX, OnchainCircuitBreaker::attribution(self::CHAIN)['kind']);

        \BccWire::reset();
        $this->fire(['code' => 200, 'body' => '{}']);

        self::assertNull(\BccBreakerStore::counter(self::CHAIN), 'the counter row must be deleted');
        $state = \BccBreakerStore::state(self::CHAIN);
        self::assertSame(0, (int) ($state['failures'] ?? -1));
        self::assertSame(0, (int) ($state['opened_at'] ?? -1));

        $attr = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertNull($attr['kind'], 'a recovered chain must not still display why it broke');
        self::assertNull($attr['request_class']);
        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN));
    }

    /** HALF-OPEN + success ⇒ CLOSED, counter gone, attribution gone. */
    public function testHalfOpenSuccessClosesAndClearsTheBreaker(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 8, time() - (OnchainCircuitBreaker::COOLDOWN_SECONDS + 60));
        \BccBreakerStore::$transients['bcc_cb_' . self::CHAIN]['kind'] = ProviderFailureKind::HTTP_5XX;
        \BccBreakerStore::$cache['bcc_circuit:cb_' . self::CHAIN]['kind'] = ProviderFailureKind::HTTP_5XX;

        self::assertSame(OnchainCircuitBreaker::PHASE_HALF_OPEN, OnchainCircuitBreaker::phase(self::CHAIN));

        $this->fire(['code' => 200, 'body' => '{}']);

        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN));
        self::assertNull(\BccBreakerStore::counter(self::CHAIN));
        self::assertNull(OnchainCircuitBreaker::attribution(self::CHAIN)['kind']);
    }

    /** HALF-OPEN + failure ⇒ OPEN again, WITH a bounded reason. */
    public function testHalfOpenFailureReopensWithBoundedAttribution(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 8, time() - (OnchainCircuitBreaker::COOLDOWN_SECONDS + 60));
        self::assertSame(OnchainCircuitBreaker::PHASE_HALF_OPEN, OnchainCircuitBreaker::phase(self::CHAIN));

        $this->fire(['code' => 503, 'body' => '{}']);

        self::assertSame(OnchainCircuitBreaker::PHASE_OPEN, OnchainCircuitBreaker::phase(self::CHAIN));
        $attr = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertSame(
            ProviderFailureKind::HTTP_5XX,
            $attr['kind'],
            'the reason a probe failed is exactly what Run 8 could not answer'
        );
    }

    // ── 8: pre-attribution records stay readable and behave identically ──

    /**
     * ⚠ AN OLD RECORD HAS NEITHER KEY. It must read back as null attribution
     * and drive exactly the phase it drove before — the phase calculation
     * never consults the new fields.
     */
    public function testPreAttributionRecordsRemainReadableAndUnchanged(): void
    {
        $now = time();
        // Written the way the previous version wrote it: two keys only.
        \BccBreakerStore::$transients['bcc_cb_' . self::CHAIN] = ['failures' => 8, 'opened_at' => $now - 10];
        \BccBreakerStore::$options['_bcc_cb_counter_' . self::CHAIN] = 8;

        self::assertSame(OnchainCircuitBreaker::PHASE_OPEN, OnchainCircuitBreaker::phase(self::CHAIN));
        $attr = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertNull($attr['kind'], 'absent attribution reads as null, never as a guess');
        self::assertNull($attr['request_class']);

        $status = OnchainCircuitBreaker::getAllStatus([self::CHAIN]);
        self::assertSame(8, $status[self::CHAIN]['failures']);
        self::assertSame('open', $status[self::CHAIN]['status']);
        self::assertNull($status[self::CHAIN]['kind']);
    }

    /** A corrupt attribution value is dropped on read, not rendered. */
    public function testACorruptAttributionValueIsDroppedOnRead(): void
    {
        $now = time();
        \BccBreakerStore::$transients['bcc_cb_' . self::CHAIN] = [
            'failures'      => 8,
            'opened_at'     => $now - 10,
            'kind'          => 'rpc error: connection refused',
            'request_class' => '/cosmwasm/wasm/v1/code/565/contracts',
        ];

        $attr = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertNull($attr['kind'], 'a value outside the vocabulary must not survive a read');
        self::assertNull($attr['request_class']);
        self::assertSame(OnchainCircuitBreaker::PHASE_OPEN, OnchainCircuitBreaker::phase(self::CHAIN));
    }

    // ── 9: every reader still agrees on the phase ───────────────────────

    /**
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public static function phaseScenarios(): array
    {
        $cd = OnchainCircuitBreaker::COOLDOWN_SECONDS;

        return [
            'below threshold'        => [2, 0, OnchainCircuitBreaker::PHASE_CLOSED],
            'threshold, opened_at 0' => [8, 0, OnchainCircuitBreaker::PHASE_OPEN],
            'inside cooldown'        => [8, -10, OnchainCircuitBreaker::PHASE_OPEN],
            'cooldown elapsed'       => [8, -($cd + 60), OnchainCircuitBreaker::PHASE_HALF_OPEN],
        ];
    }

    #[DataProvider('phaseScenarios')]
    public function testAllBreakerReadersAgreeOnPhase(int $failures, int $openedOffset, string $expected): void
    {
        $now      = time();
        $openedAt = $openedOffset === 0 ? 0 : $now + $openedOffset;
        \BccBreakerStore::$transients['bcc_cb_' . self::CHAIN] = [
            'failures'  => $failures,
            'opened_at' => $openedAt,
            'kind'      => ProviderFailureKind::TRANSPORT,
        ];
        \BccBreakerStore::$options['bcc_onchain_last_success_' . self::CHAIN] = $now - 999999;

        self::assertSame($expected, OnchainCircuitBreaker::phase(self::CHAIN), 'phase()');

        $all = OnchainCircuitBreaker::getAllStatus([self::CHAIN]);
        self::assertSame(
            str_replace('_', '-', $expected),
            $all[self::CHAIN]['status'],
            'getAllStatus() must agree with phase()'
        );

        $stale = OnchainCircuitBreaker::getStaleChains([self::CHAIN], -1);
        self::assertSame(
            strtoupper(str_replace('_', '-', $expected)),
            $stale[self::CHAIN]['circuit_status'],
            'getStaleChains() must agree with phase()'
        );

        // ⚠ isOpen() LAST — it claims the half-open probe lock, so calling it
        // earlier would change what the pure readers above observe.
        //
        // ⚠ AND ITS CONTRACT IS NOT "is the phase open". It answers "should I
        // block THIS caller", which in the half-open window is FALSE for the
        // one caller that wins the probe and TRUE for the next. Asserting it
        // equals the phase would be asserting the wrong thing.
        if ($expected === OnchainCircuitBreaker::PHASE_CLOSED) {
            self::assertFalse(OnchainCircuitBreaker::isOpen(self::CHAIN), 'closed lets traffic through');
        } elseif ($expected === OnchainCircuitBreaker::PHASE_OPEN) {
            self::assertTrue(OnchainCircuitBreaker::isOpen(self::CHAIN), 'open blocks');
        } else {
            self::assertFalse(
                OnchainCircuitBreaker::isOpen(self::CHAIN),
                'half-open admits the FIRST caller — it claims the probe'
            );
            self::assertTrue(
                OnchainCircuitBreaker::isOpen(self::CHAIN),
                '…and blocks the second, because the probe is already claimed'
            );
        }
    }

    // ── 11: every executable charge call site is accounted for ──────────

    /**
     * ⚠ THE COVERAGE CLAIM, MADE EXPLICIT.
     *
     * Twelve executable call sites charge this breaker. Four are inside
     * ApiRetry and MUST now pass a kind; eight are domain judgements with no
     * wire outcome and MUST NOT invent one. This test fails when a new charge
     * site appears anywhere, so "which callers are attributed?" can never
     * again be answered by reading and hoping.
     */
    public function testEveryExecutableBreakerChargeSiteIsAccountedFor(): void
    {
        $root = dirname(__DIR__, 2) . '/app';
        self::assertDirectoryExists($root);

        $attributed   = [];
        $unattributed = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            // ⚠ COMMENTS STRIPPED WITH token_get_all(), NEVER A REGEX. A
            // docblock {@see …::recordFailure()} is not a call site, and a raw
            // grep over this tree counts two of them.
            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $t) {
                if (is_array($t)) {
                    if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                        $code .= str_repeat("\n", substr_count($t[1], "\n"));
                        continue;
                    }
                    $code .= $t[1];
                } else {
                    $code .= $t;
                }
            }
            if (strpos($code, 'recordFailure(') === false) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            foreach (explode("\n", $code) as $i => $line) {
                if (strpos($line, 'OnchainCircuitBreaker::recordFailure(') === false) {
                    continue;
                }
                // A site is "attributed" when the argument list spans more
                // than one line — the multi-argument form. A single-argument
                // call closes on the same line.
                //
                // ⚠ STRUCTURAL, NOT A REGEX OVER ARGUMENT SHAPES. An earlier
                // version matched the argument text and misread
                // `recordFailure((int) $row->chain_id)` as attributed, because
                // its character class happened to omit `$`. Asking "does the
                // call close on this line?" cannot be defeated by a cast, a
                // property access or a nested call.
                $site  = $rel . ':' . ($i + 1);
                $after = substr($line, strpos($line, 'recordFailure(') + strlen('recordFailure('));
                if (strpos($after, ')') === false) {
                    $attributed[] = $site;
                } else {
                    $unattributed[] = $site;
                }
            }
        }

        self::assertNotSame([], $attributed, 'anti-vacuity: some site must be attributed');
        self::assertCount(
            4,
            $attributed,
            "exactly the four ApiRetry sites carry attribution; got:\n  " . implode("\n  ", $attributed)
        );
        foreach ($attributed as $site) {
            self::assertStringContainsString('Support/ApiRetry.php', $site);
        }
        self::assertCount(
            8,
            $unattributed,
            "the eight domain-level sites must stay unattributed; got:\n  " . implode("\n  ", $unattributed)
        );
        foreach ($unattributed as $site) {
            self::assertStringNotContainsString('Support/ApiRetry.php', $site);
        }
    }

    // ── 13: no extra provider request or retry is introduced ────────────

    /**
     * @return array<string, array{0: mixed, 1: int, 2: int}>
     *         [response, expected attempts, expected sleeps]
     */
    public static function retryBudgets(): array
    {
        return [
            'HTTP 200 — one attempt, no retry' => [['code' => 200, 'body' => '{}'], 1, 0],
            'HTTP 429 — one attempt, no sleep' => [['code' => 429, 'body' => '{}'], 1, 0],
            'HTTP 404 — one attempt, no retry' => [['code' => 404, 'body' => '{}'], 1, 0],
            'HTTP 500 — four attempts'         => [['code' => 500, 'body' => '{}'], 4, 3],
            'wire failure — four attempts'     => [self::WIRE_FAILURE, 4, 3],
        ];
    }

    #[DataProvider('retryBudgets')]
    public function testAttributionAddsNoRequestAndNoRetry(mixed $response, int $attempts, int $sleeps): void
    {
        $this->fire($response);

        self::assertCount($attempts, \BccWire::$urls, 'attribution must not change the attempt count');
        self::assertCount($sleeps, \BccWire::$sleeps, 'attribution must not change the backoff count');
    }

    /**
     * The Run 8 arithmetic, pinned: ONE failing request charges FOUR times,
     * so TWO open a threshold-five breaker. Not a change request — a pin, so
     * that any future retuning of per-attempt accounting is deliberate.
     */
    public function testOneFailingRequestStillChargesFourTimes(): void
    {
        $this->fire(['code' => 503, 'body' => '{}']);
        self::assertSame(4, $this->charges());
        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN));

        \BccWire::reset();
        $this->fire(['code' => 503, 'body' => '{}']);
        self::assertSame(8, $this->charges(), 'the 8 = 2 x 4 the canary measured');
        self::assertSame(OnchainCircuitBreaker::PHASE_OPEN, OnchainCircuitBreaker::phase(self::CHAIN));
        self::assertSame(ProviderFailureKind::HTTP_5XX, OnchainCircuitBreaker::attribution(self::CHAIN)['kind']);
    }

    // ── vocabulary integrity ────────────────────────────────────────────

    /**
     * ⚠ THE TWO VOCABULARIES SHARE THREE LITERALS AND MUST NEVER DRIFT.
     *
     * {@see CosmwasmEnumerationFailure} names an ENUMERATION OUTCOME (seven
     * tokens). {@see ProviderFailureKind} names a BREAKER CHARGE (three). They
     * are deliberately separate — one is richer and domain-specific, the other
     * is exactly as wide as the charging rule — but where they overlap they
     * must be the same strings, or one operator surface would say `http_5xx`
     * and another `http5xx` for the same event.
     */
    public function testTheSharedTokensAreByteIdenticalAcrossBothVocabularies(): void
    {
        self::assertSame(CosmwasmEnumerationFailure::RATE_LIMITED, ProviderFailureKind::RATE_LIMITED);
        self::assertSame(CosmwasmEnumerationFailure::HTTP_5XX, ProviderFailureKind::HTTP_5XX);
        self::assertSame(CosmwasmEnumerationFailure::TRANSPORT, ProviderFailureKind::TRANSPORT);

        foreach (ProviderFailureKind::all() as $kind) {
            self::assertTrue(
                CosmwasmEnumerationFailure::isValid($kind),
                "every breaker token must also be a valid enumeration token: {$kind}"
            );
        }

        // …and the breaker vocabulary stays exactly as wide as the charge rule.
        self::assertCount(3, ProviderFailureKind::all());
        self::assertSame(count(ProviderFailureKind::all()), count(array_unique(ProviderFailureKind::all())));
    }

    /** `timeout` and `dns` are never guessed, here as in PR 7.6. */
    public function testTimeoutAndDnsAreNeverInTheBreakerVocabulary(): void
    {
        self::assertNotContains('timeout', ProviderFailureKind::all());
        self::assertNotContains('dns', ProviderFailureKind::all());
        self::assertFalse(ProviderFailureKind::isValid('timeout'));
        self::assertFalse(ProviderFailureKind::isValid('dns'));
    }

    /** The mapper returns null for everything that does not charge. */
    public function testTheMapperNamesOnlyChargingOutcomes(): void
    {
        self::assertSame(ProviderFailureKind::TRANSPORT, ProviderFailureKind::fromOutcome(true, 0));
        self::assertSame(ProviderFailureKind::RATE_LIMITED, ProviderFailureKind::fromOutcome(false, 429));
        self::assertSame(ProviderFailureKind::HTTP_5XX, ProviderFailureKind::fromOutcome(false, 500));
        self::assertSame(ProviderFailureKind::HTTP_5XX, ProviderFailureKind::fromOutcome(false, 501));

        foreach ([0, 200, 204, 301, 400, 401, 403, 404, 409, 422] as $status) {
            self::assertNull(
                ProviderFailureKind::fromOutcome(false, $status),
                "HTTP {$status} does not charge and must not be named"
            );
        }
    }

    /** The request class is derived from options, never from a URL. */
    public function testTheRequestClassIsDerivedFromOptionsOnly(): void
    {
        self::assertSame(
            ProviderRequestClass::STANDARD_REQUEST,
            ProviderRequestClass::fromOptions(['chain_id' => 8, 'label' => 'x'])
        );
        self::assertSame(
            ProviderRequestClass::SMART_QUERY,
            ProviderRequestClass::fromOptions(['application_error' => static fn(): bool => true])
        );
        // A non-callable must not be mistaken for the opt-in.
        self::assertSame(
            ProviderRequestClass::STANDARD_REQUEST,
            ProviderRequestClass::fromOptions(['application_error' => 'yes'])
        );

        foreach (ProviderRequestClass::all() as $class) {
            self::assertTrue(ProviderRequestClass::isValid($class));
            self::assertDoesNotMatchRegularExpression('#[/:.]#', $class, 'no path- or host-shaped value');
        }
    }
}
