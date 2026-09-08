<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\DiscoveryScanSession;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Provider-safe pacing — the numbers a live circuit breaker chose for us.
 *
 * ── THE EVIDENCE ────────────────────────────────────────────────────────
 * Run 5 on 2026-09-07 (`b04efa96-…`) was the first full-shape session:
 * 16 chunks, 772 requests, 970 s against the free public Cosmos Hub LCD at
 * `rest.cosmos.directory`. At chunk 16 `OnchainCircuitBreaker::isOpen(8)`
 * returned true, `prepareChain()` refused, and the run ended honestly as
 * `chain_not_ready` / `chain_refused_to_prepare`.
 *
 * The breaker did exactly its job. The lesson is not "make the breaker
 * quieter" — it is that ~3 requests/second sustained is too fast for an
 * endpoint nobody is paying for. PR 7.5 halves the chunk budget, quadruples
 * the gap and halves the session ceiling.
 *
 * ⚠ EVERY NUMBER HERE IS A PROMISE TO A THIRD PARTY. A future edit that
 * "tunes performance" by restoring 50/1250/15 is reverting a decision a
 * production provider made for us, so each is pinned individually.
 */
#[CoversClass(DiscoveryScanSession::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoveryPacingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-cli-stubs.php';
    }

    // ── the four numbers ────────────────────────────────────────────────

    public function testTheDefaultChunkRequestBudgetIsTwentyFive(): void
    {
        self::assertSame(25, CosmwasmDiscoveryGate::DEFAULT_REQUEST_BUDGET);
        self::assertSame(25, CosmwasmDiscoveryGate::requestBudget(), 'no override defined');
        self::assertSame(25, (new CosmwasmTickBudget())->remaining(), 'the budget object agrees');
    }

    public function testTheSessionRequestCeilingIsSixTwentyFive(): void
    {
        self::assertSame(625, DiscoveryScanSession::MAX_REQUESTS);
    }

    public function testTheContinuationDelayIsSixtySeconds(): void
    {
        self::assertSame(60, DiscoveryScanSession::CHUNK_DELAY_SECONDS);
    }

    /** The ceilings PR 7.5 deliberately did NOT move. */
    public function testTheUnchangedCeilingsStayUnchanged(): void
    {
        self::assertSame(25, DiscoveryScanSession::MAX_CHUNKS, 'chunks per session');
        self::assertSame(3600, DiscoveryScanSession::MAX_AGE_SECONDS, 'session wall clock');
        self::assertSame(1, DiscoveryScanSession::MAX_ERROR_CHUNKS, 'provider-error allowance');
        self::assertSame(20, CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS, 'per-chunk runtime');
    }

    /**
     * ⚠ THE PACE IS BELOW WHAT TRIPPED THE BREAKER, BY CONSTRUCTION.
     *
     * 25 chunks × 25 requests = 625, which is less than the 772 that opened
     * it. Asserted as arithmetic rather than as a literal so that raising
     * either factor without lowering the other fails here.
     */
    public function testTheWholeSessionCannotReachTheVolumeThatTrippedTheBreaker(): void
    {
        $maxPossible = DiscoveryScanSession::MAX_CHUNKS * CosmwasmDiscoveryGate::DEFAULT_REQUEST_BUDGET;

        self::assertSame(625, $maxPossible, '25 chunks x 25 requests');
        self::assertSame($maxPossible, DiscoveryScanSession::MAX_REQUESTS, 'the ceiling matches the arithmetic');
        self::assertLessThan(772, DiscoveryScanSession::MAX_REQUESTS, 'must stay under the observed breaker trip');
    }

    // ── the override cannot uncap a session ─────────────────────────────

    /**
     * ⚠ THE OVERRIDE BOUNDS ONE CHUNK; THE SESSION BOUNDS THE SESSION.
     *
     * `BCC_COSMWASM_REQUEST_BUDGET` is capped at 500. Before PR 7.5, 25
     * chunks at 500 would have authorized 12,500 requests — the session
     * ceiling only noticed AFTER a chunk had already spent its budget.
     *
     * @param int $used      requests the session has already spent
     * @param int $expected  what this chunk may spend
     */
    #[DataProvider('allowanceCases')]
    public function testTheChunkAllowanceNeverExceedsWhatTheSessionHasLeft(int $used, int $expected): void
    {
        self::assertSame($expected, DiscoveryScanSession::chunkRequestAllowance($used));
    }

    /** @return array<string, array{int, int}> */
    public static function allowanceCases(): array
    {
        return [
            'fresh session'          => [0, 25],
            'mid session'            => [300, 25],
            'near the ceiling'       => [610, 15],
            'one request left'       => [624, 1],
            'exactly at the ceiling' => [625, 0],
            'past the ceiling'       => [900, 0],
            // ⚠ A corrupt negative must clamp, never wrap into "unlimited".
            'absurd negative'        => [-5000, 25],
        ];
    }

    /**
     * ⚠ 25 CHUNKS CANNOT AUTHORIZE MORE THAN 625, whatever the override.
     *
     * Walks the whole session the way the executor does — allowance, spend,
     * accumulate — with the override at its 500 maximum.
     */
    public function testTwentyFiveChunksCannotExceedTheSessionCeilingEvenWithAMaxOverride(): void
    {
        define('BCC_COSMWASM_REQUEST_BUDGET', 500);
        self::assertSame(500, CosmwasmDiscoveryGate::requestBudget(), 'precondition: the override is live');

        $used = 0;
        for ($chunk = 1; $chunk <= DiscoveryScanSession::MAX_CHUNKS; $chunk++) {
            $used += DiscoveryScanSession::chunkRequestAllowance($used);
        }

        self::assertSame(DiscoveryScanSession::MAX_REQUESTS, $used, 'a maxed override still stops at 625');
        self::assertLessThanOrEqual(625, $used);
    }

    /** An invalid or absurd override falls back to the safe default. */
    #[DataProvider('badOverrides')]
    public function testAnInvalidOverrideFallsBackToTwentyFive(int $value): void
    {
        define('BCC_COSMWASM_REQUEST_BUDGET', $value);

        self::assertSame(25, CosmwasmDiscoveryGate::requestBudget());
    }

    /** @return array<string, array{int}> */
    public static function badOverrides(): array
    {
        return [
            'zero'      => [0],
            'negative'  => [-1],
            'over cap'  => [501],
            'absurd'    => [100000],
        ];
    }

    /** A valid override is honoured, bounded by the session. */
    public function testAValidOverrideIsHonouredWithinTheSession(): void
    {
        define('BCC_COSMWASM_REQUEST_BUDGET', 40);

        self::assertSame(40, CosmwasmDiscoveryGate::requestBudget());
        self::assertSame(40, DiscoveryScanSession::chunkRequestAllowance(0));
        self::assertSame(25, DiscoveryScanSession::chunkRequestAllowance(600), 'the session still wins');
    }

    // ── ⚠ THE GAP IS SCHEDULED, NEVER SLEPT ─────────────────────────────

    /**
     * No discovery code may hold a PHP worker open waiting for the gap.
     *
     * ⚠ A `sleep(60)` would be killed by `max_execution_time` long before
     * the gap elapsed AND would hold a shared-hosting worker slot for a
     * minute per chunk. The delay belongs to Action Scheduler, which is why
     * `releaseForNextChunk()` writes `next_retry_at` instead.
     */
    #[DataProvider('discoverySources')]
    public function testNoInProcessSleepingIsIntroduced(string $relative): void
    {
        // Strip comments so a docblock DISCUSSING sleeping cannot fail this.
        //
        // ⚠ VIA THE TOKENIZER, NOT A REGEX. A block-plus-line-comment regex
        // also eats the `//` in every `'https://…'` literal and everything
        // after it on that line — which would delete real code and make this
        // not-contains assertion pass without having read the file.
        $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        $code = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        // ⚠ THE ANTI-VACUITY FLOOR. If stripping ever eats the file, this
        // test would report "no sleeping" about an empty string.
        self::assertGreaterThan(1000, strlen($code), $relative . ' stripped to nothing');
        self::assertStringContainsString('function', $code, $relative . ' lost its code');

        foreach (['sleep(', 'usleep(', 'time_nanosleep(', 'time_sleep_until('] as $banned) {
            self::assertStringNotContainsString($banned, $code, $relative . ' must not sleep in-process');
        }
    }

    /** @return array<string, array{string}> */
    public static function discoverySources(): array
    {
        return [
            'session'  => ['app/Domain/Onchain/Services/DiscoveryScanSession.php'],
            'executor' => ['app/Domain/Onchain/Workers/DiscoveryRunExecutor.php'],
            'worker'   => ['app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php'],
            'gate'     => ['app/Domain/Onchain/Support/CosmwasmDiscoveryGate.php'],
            'budget'   => ['app/Domain/Onchain/Support/CosmwasmTickBudget.php'],
            'fetcher'  => ['app/Domain/Onchain/Fetchers/CosmosFetcher.php'],
        ];
    }

    // ── the session decision still honours the new ceiling ──────────────

    /** A session that has spent its requests stops on the request ceiling. */
    public function testTheDecisionStopsAtTheNewRequestCeiling(): void
    {
        $decision = DiscoveryScanSession::decide([
            'cancelled'     => false,
            'ready'         => true,
            'error_chunks'  => 0,
            'chunks_used'   => 3,
            'requests_used' => DiscoveryScanSession::MAX_REQUESTS,
            'age_seconds'   => 10,
            'eligible_now'  => 500,
        ]);

        self::assertFalse($decision['continue']);
        self::assertSame(DiscoveryScanSession::STOP_REQUEST_CEILING, $decision['reason']);
    }

    /** Just under the ceiling, with work left, it continues. */
    public function testJustUnderTheCeilingItContinues(): void
    {
        $decision = DiscoveryScanSession::decide([
            'cancelled'     => false,
            'ready'         => true,
            'error_chunks'  => 0,
            'chunks_used'   => 3,
            'requests_used' => DiscoveryScanSession::MAX_REQUESTS - 1,
            'age_seconds'   => 10,
            'eligible_now'  => 500,
        ]);

        self::assertTrue($decision['continue']);
    }
}
