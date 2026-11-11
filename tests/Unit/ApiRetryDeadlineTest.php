<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-156(b) — a total deadline for one logical request, and the arithmetic
 * that describes it.
 *
 * ⚠⚠ WHY THE ARITHMETIC IS TESTED AND NOT WRITTEN DOWN. Before this PR the
 * retry schedule was described by a trailing comment on
 * {@see ApiRetry::BACKOFF_MULTIPLIER} reading `2s → 5s → 12.5s → 30s
 * (capped)`. It was wrong twice over — the formula is `BASE * attempt ** 2.5`
 * (2 → 11 → 30, not geometric) and an unnamed `min($delay, 3)` two lines
 * below flattened it to 2 → 3 → 3 before any of that was reached. Two
 * successive attempts to correct that comment produced two further wrong
 * totals (13s, then 5s) because each read one layer and stopped.
 *
 * ⭐ The real total was already pinned, in this directory, by
 * {@see BreakerRetryAccountingTest}: four attempts and `[2, 3, 3]`. A
 * passing test had the answer the whole time while three comments disagreed
 * with it. So the schedule now lives in {@see ApiRetry::backoffDelay()} and
 * {@see ApiRetry::worstCaseSleepTotal()}, both public and both asserted
 * here, and the default deadline is DERIVED from them rather than from a
 * number anyone typed.
 *
 * ── WHAT A DEADLINE ACTUALLY BUYS, STATED UP FRONT ──────────────────────
 * It is NOT "the same four attempts, sooner". At the budgets the two
 * user-facing paths use, one provider timeout is most of the budget: the
 * join gate's 20s against Cosmos's 15s per-attempt timeout fits ONE full
 * attempt. The cases below pin that explicitly rather than leaving a reader
 * to assume a retry tail is being trimmed.
 *
 * ── THE CLOCK ───────────────────────────────────────────────────────────
 * Driven, not waited on. `apiretry-real-stubs.php` shadows `time()` and
 * `sleep()` for `BCC\Trust\Onchain\Support` via PHP's namespace fallback,
 * and the shadowed `sleep()` advances the virtual clock. A test closure
 * advances it to model an attempt that consumed time. Nothing here sleeps.
 */
#[CoversClass(ApiRetry::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ApiRetryDeadlineTest extends TestCase
{
    private const CHAIN = 8;
    private const T0    = 1_700_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/apiretry-real-stubs.php';
        OnchainCircuitBreaker::reset();
        \BccSleepSpy::reset();
        \BccApiRetryOptionStore::reset();
        \BCC\Core\Log\Logger::reset();
    }

    /**
     * Run a failing sequence where each attempt consumes `$attemptSeconds`
     * of the virtual clock.
     *
     * @return array{result: mixed, attempts: int}
     */
    private function runFailing(int $attemptSeconds, array $options = []): array
    {
        \BccSleepSpy::startClock(self::T0);

        $attempts = 0;
        $fn = static function () use (&$attempts, $attemptSeconds) {
            $attempts++;
            // The attempt itself takes time — the thing a deadline is
            // actually spent on. Without this the budget would only ever be
            // consumed by backoff, which is the smaller half.
            \BccSleepSpy::advance($attemptSeconds);

            return \BccHttp::response(500, '{"message":"boom"}');
        };

        $result = ApiRetry::request(
            $fn,
            $options + ['chain_id' => self::CHAIN, 'label' => 'deadline']
        );

        return ['result' => $result, 'attempts' => $attempts];
    }

    // ── the schedule, computed rather than described ────────────────────

    /**
     * ⚠ THE THREE LAYERS, each asserted, because each one was believed by
     * somebody: the comment's geometric sequence, the formula's uncapped
     * one, and what actually runs.
     */
    public function testTheBackoffScheduleIsTwoThenThreeThenThree(): void
    {
        self::assertSame(2, ApiRetry::backoffDelay(1));
        self::assertSame(3, ApiRetry::backoffDelay(2), 'the formula says 11; the cap says 3');
        self::assertSame(3, ApiRetry::backoffDelay(3), 'the formula says 30; the cap says 3');
    }

    /** The total the two wrong corrections both missed. */
    public function testTheWorstCaseSleepTotalIsEight(): void
    {
        self::assertSame(
            8,
            ApiRetry::worstCaseSleepTotal(ApiRetry::DEFAULT_MAX_RETRIES),
            '2 + 3 + 3 — not 13s (formula, uncapped) and not 5s (cap, two sleeps)'
        );
    }

    /**
     * ⚠ `$maxRetries + 1` ATTEMPTS. This is the miscount that produced the
     * 5s figure: the loop is `$attempt = 0; while ($attempt <= $maxRetries)`,
     * so three retries means FOUR executions and THREE sleeps.
     */
    public function testTheWorstCaseCountsOneMoreAttemptThanRetries(): void
    {
        // Cosmos LCD: 15s timeout, 4 attempts, 8s of backoff.
        self::assertSame(68, ApiRetry::worstCaseSeconds(3, 15));
        // EVM: 12s.
        self::assertSame(56, ApiRetry::worstCaseSeconds(3, 12));
        // Solana: 30s — the slowest, and the reason the piece budget is 35s.
        self::assertSame(128, ApiRetry::worstCaseSeconds(3, 30));
    }

    /** With no retries at all there is no backoff to add. */
    public function testTheWorstCaseOfASingleAttemptIsItsTimeout(): void
    {
        self::assertSame(15, ApiRetry::worstCaseSeconds(0, 15));
        self::assertSame(0, ApiRetry::worstCaseSleepTotal(0));
    }

    // ── the behaviour-preservation anchor ───────────────────────────────

    /**
     * ⚠⚠ NO DEADLINE = TODAY, EXACTLY. Every one of the two dozen existing
     * call sites reaches `request()` either without the option or with the
     * derived worst case, so this is the case that must not move. Asserted
     * here as well as in BreakerRetryAccountingTest because a deadline bug
     * that only fired on absent-option paths would otherwise be invisible
     * in this file.
     */
    public function testWithoutADeadlineTheFullSequenceStillRuns(): void
    {
        $run = $this->runFailing(15);

        self::assertSame(4, $run['attempts'], 'one attempt plus three retries, unchanged');
        self::assertSame([2, 3, 3], \BccSleepSpy::$slept);
    }

    /**
     * And a deadline that cannot be reached is the same thing. This is the
     * shape `get()` / `post()` default to, so if the derived default were
     * ever too tight it would fire on healthy traffic — the exact failure a
     * deadline computed from the WRONG sequence would have caused.
     */
    public function testADeadlineAtTheWorstCaseNeverFires(): void
    {
        $run = $this->runFailing(15, ['deadline_at' => self::T0 + ApiRetry::worstCaseSeconds(3, 15)]);

        self::assertSame(4, $run['attempts'], 'the honest worst case must admit the worst case');
        self::assertSame([2, 3, 3], \BccSleepSpy::$slept);
    }

    /**
     * ⚠⚠ THE DEFAULT MUST SURVIVE REAL ELAPSED TIME, NOT JUST THE MODELLED
     * KIND. Found in review of this PR: the default was the EXACT worst
     * case, and zero slack is not the same as unreachable. The last retry's
     * check reduces to `timeout - maxRetries x overhead >= floor`, so at
     * `timeout = 5` — KeybaseLogoResolver's, the smallest here — it was an
     * equality, and any time spent outside the `timeout` budget (WP arg
     * processing, response parsing, the breaker's option round trips, or
     * `time()`'s one-second granularity ticking mid-attempt) silently
     * dropped the fourth attempt.
     *
     * ⭐ DRIVEN THROUGH THE REAL DEFAULT, by reflection, rather than through
     * a restatement of the formula. A test that recomputed the expected
     * default would pass against any formula including the broken one; this
     * one asks `resolveDeadline()` what it actually chose and then checks
     * that a full sequence fits inside it with a second of unmodelled time
     * per attempt. Reverting the `+ 1` fails it.
     */
    public function testTheDefaultDeadlineSurvivesPerAttemptOverhead(): void
    {
        // The smallest timeout in the tree, which is where the margin was nil.
        $timeout = 5;

        \BccSleepSpy::startClock(self::T0);
        $deadlineAt = self::defaultDeadlineFor($timeout);

        $attempts = 0;
        $fn = static function () use (&$attempts, $timeout) {
            $attempts++;
            // The timeout itself, PLUS one second that no budget models.
            \BccSleepSpy::advance($timeout + 1);

            return \BccHttp::response(500, '{"message":"boom"}');
        };

        ApiRetry::request($fn, [
            'chain_id'    => self::CHAIN,
            'label'       => 'deadline',
            'deadline_at' => $deadlineAt,
        ]);

        self::assertSame(
            4,
            $attempts,
            'the DEFAULT deadline must not cost an attempt; it exists to be unreachable'
        );
    }

    /**
     * And the slack is at least a whole attempt, stated as a property of the
     * default rather than as its arithmetic — so a future change to how the
     * margin is derived keeps passing as long as the margin is real.
     */
    public function testTheDefaultDeadlineIsWiderThanTheWorstCase(): void
    {
        \BccSleepSpy::startClock(self::T0);

        foreach ([5, 12, 15, 30] as $timeout) {
            $exact  = ApiRetry::worstCaseSeconds(ApiRetry::DEFAULT_MAX_RETRIES, $timeout);
            $actual = self::defaultDeadlineFor($timeout) - self::T0;

            self::assertGreaterThanOrEqual(
                $exact + $timeout,
                $actual,
                "the default for timeout={$timeout} must leave at least one attempt of slack"
            );
        }
    }

    /**
     * The default `resolveDeadline()` picks for a given timeout, read from
     * the method itself.
     */
    private static function defaultDeadlineFor(int $timeout): int
    {
        $m = (new ReflectionClass(ApiRetry::class))->getMethod('resolveDeadline');
        $m->setAccessible(true);

        /** @var int $out */
        $out = $m->invoke(null, [], ['timeout' => $timeout]);

        return $out;
    }

    // ── the deadline doing its job ──────────────────────────────────────

    /**
     * ⚠⚠ THE HEADLINE, AND THE CONSEQUENCE WORTH SEEING. The join gate's
     * real numbers: a 20s budget against Cosmos's 15s per-attempt timeout.
     * One attempt fits. After it, 5s remain, and a 2s backoff would leave
     * 3s — below MIN_ATTEMPT_BUDGET — so no second attempt is started.
     *
     * The honest reading is that this budget buys ONE attempt, not a
     * shortened four. It is still strictly better than the unbounded 68s
     * per call, over up to 5 pages, per wallet.
     */
    public function testTheJoinGateBudgetAdmitsExactlyOneAttempt(): void
    {
        $run = $this->runFailing(15, ['deadline_at' => self::T0 + 20]);

        self::assertSame(1, $run['attempts']);
        self::assertSame([], \BccSleepSpy::$slept, 'no backoff was paid for a retry that never happened');
    }

    /**
     * ⚠ ANTI-VACUITY FOR THE CASE ABOVE: a deadline must not simply disable
     * retrying. Given a budget that genuinely fits more, more must happen.
     *
     * 40s with 10s attempts: a1 ends at 10 (28 left after a 2s backoff),
     * a2 at 22 (15 left after 3s), a3 at 35 — where 3s of backoff would
     * leave 2s, so the fourth is refused.
     */
    public function testALargerBudgetAdmitsMoreAttempts(): void
    {
        $run = $this->runFailing(10, ['deadline_at' => self::T0 + 40]);

        self::assertSame(3, $run['attempts']);
        self::assertSame([2, 3], \BccSleepSpy::$slept);
    }

    /** The Solana piece path: 35s against a 30s timeout is also one attempt. */
    public function testThePieceBudgetAdmitsOneSolanaAttempt(): void
    {
        $run = $this->runFailing(30, ['deadline_at' => self::T0 + 35]);

        self::assertSame(1, $run['attempts']);
    }

    // ── what a stopped sequence settles ─────────────────────────────────

    /**
     * ⚠ IT IS STILL A FAILED LOGICAL REQUEST, so it still earns exactly one
     * charge. The alternative — exempting a deadline-stopped request — would
     * make a tight budget a way to hide a genuinely broken provider from its
     * own breaker.
     */
    public function testAStoppedSequenceChargesTheBreakerExactlyOnce(): void
    {
        $this->runFailing(15, ['deadline_at' => self::T0 + 20]);

        self::assertSame([self::CHAIN], OnchainCircuitBreaker::$failureChains);
    }

    /** And the caller still receives the provider's own last answer. */
    public function testAStoppedSequenceReturnsTheLastResponse(): void
    {
        $run = $this->runFailing(15, ['deadline_at' => self::T0 + 20]);

        self::assertIsArray($run['result']);
        self::assertSame(500, \wp_remote_retrieve_response_code($run['result']));
    }

    // ── no budget at all ────────────────────────────────────────────────

    /**
     * ⚠⚠ ZERO ATTEMPTS, AND NOTHING BLAMED. An exhausted budget is a fact
     * about the request, not about the provider: no host was contacted, so
     * charging the breaker would let a tight deadline manufacture failures
     * against a healthy chain and eventually open it.
     */
    public function testAnAlreadySpentDeadlineMakesNoAttemptAndChargesNothing(): void
    {
        $run = $this->runFailing(15, ['deadline_at' => self::T0]);

        self::assertSame(0, $run['attempts']);
        self::assertInstanceOf(\WP_Error::class, $run['result']);
        self::assertSame('deadline_exceeded', $run['result']->get_error_code());
        self::assertSame([], OnchainCircuitBreaker::$failureChains);
        self::assertSame([], OnchainCircuitBreaker::$successChains);
    }

    /**
     * ⚠⚠ AND IT MUST NOT CLAIM THE HALF-OPEN PROBE. `isOpen()` is the
     * MUTATING reader: it claims the single probe slot. Refusing after it
     * would strand that slot on a request that never calls out — precisely
     * the failure #265 removed from the four outer preflights. Observed by
     * counting the call, not inferred from where the check sits.
     */
    public function testAnAlreadySpentDeadlineNeverClaimsTheProbe(): void
    {
        $this->runFailing(15, ['deadline_at' => self::T0]);

        self::assertSame([], OnchainCircuitBreaker::$isOpenCalls, 'the probe slot was never claimed');
        self::assertSame([], OnchainCircuitBreaker::$probeReleases, 'and so there was nothing to release');
    }

    /**
     * Anti-vacuity for the two cases above: the ordinary path DOES consult
     * the breaker, so the assertions are observing a difference rather than
     * a stub that never records anything.
     */
    public function testAnAdmittedRequestDoesClaimTheProbe(): void
    {
        $this->runFailing(15, ['deadline_at' => self::T0 + 20]);

        self::assertSame([self::CHAIN], OnchainCircuitBreaker::$isOpenCalls);
        self::assertNotSame([], OnchainCircuitBreaker::$probeReleases);
    }

    /**
     * A budget below the floor is "no budget", not "a tiny budget". With 4s
     * left, a 1s or 4s attempt against an RPC endpoint is a formality that
     * burns the rest of the budget and can still earn a charge.
     */
    public function testABudgetBelowTheFloorIsRefused(): void
    {
        $run = $this->runFailing(15, ['deadline_at' => self::T0 + (ApiRetry::MIN_ATTEMPT_BUDGET - 1)]);

        self::assertSame(0, $run['attempts']);
        self::assertSame('deadline_exceeded', $run['result']->get_error_code());
    }

    /** Exactly at the floor it IS admitted — the boundary, asserted both ways. */
    public function testABudgetExactlyAtTheFloorIsAdmitted(): void
    {
        $run = $this->runFailing(15, ['deadline_at' => self::T0 + ApiRetry::MIN_ATTEMPT_BUDGET]);

        self::assertSame(1, $run['attempts']);
    }

    // ── success is still success ────────────────────────────────────────

    /**
     * ⚠ ANTI-VACUITY FOR THE WHOLE FILE. Every case above ends in failure,
     * so a `request()` that refused everything would pass them all. A
     * deadline present and a healthy provider must still succeed and must
     * still be credited.
     */
    public function testADeadlineDoesNotPreventSuccess(): void
    {
        \BccSleepSpy::startClock(self::T0);

        $result = ApiRetry::request(
            static function () {
                \BccSleepSpy::advance(1);

                return \BccHttp::response(200, '{"ok":true}');
            },
            ['chain_id' => self::CHAIN, 'label' => 'deadline', 'deadline_at' => self::T0 + 20]
        );

        self::assertIsArray($result);
        self::assertSame(200, \wp_remote_retrieve_response_code($result));
        self::assertSame([self::CHAIN], OnchainCircuitBreaker::$successChains);
        self::assertSame([], OnchainCircuitBreaker::$failureChains);
    }

    // ── the per-attempt timeout cap ─────────────────────────────────────

    /**
     * ⚠⚠ WITHOUT THIS THE DEADLINE WOULD BE ADVISORY. The checks above
     * decide whether to START an attempt; nothing stops an admitted attempt
     * from running its own full timeout past the deadline. On the piece
     * route that is a 30s Solana timeout against a 35s budget.
     *
     * Reached by reflection deliberately: `capTimeoutToDeadline()` is a pure
     * function of (args, deadline, now), and asserting it directly is far
     * less fragile than standing up a fake `SafeHttpClient` and
     * `wp_remote_post` to observe the same arithmetic indirectly.
     */
    public function testTheAttemptTimeoutIsCappedToTheRemainingBudget(): void
    {
        \BccSleepSpy::startClock(self::T0);

        // 10s left, a 30s timeout asked for → the attempt may use 10s.
        $capped = self::cap(['timeout' => 30], self::T0 + 10);
        self::assertSame(10, $capped['timeout']);
    }

    /** A timeout that already fits is left alone, not rewritten. */
    public function testATimeoutThatFitsIsUntouched(): void
    {
        \BccSleepSpy::startClock(self::T0);

        $capped = self::cap(['timeout' => 12, 'headers' => ['A' => 'b']], self::T0 + 60);
        self::assertSame(12, $capped['timeout']);
        self::assertSame(['A' => 'b'], $capped['headers'], 'nothing else is disturbed');
    }

    /** With no deadline in force the args are returned unchanged. */
    public function testNoDeadlineMeansNoCap(): void
    {
        \BccSleepSpy::startClock(self::T0);

        $capped = self::cap(['timeout' => 30], 0);
        self::assertSame(30, $capped['timeout']);
    }

    /**
     * ⚠ The cap never goes below the floor. It cannot be reached through
     * `request()` (the budget checks refuse first) but it is the guarantee
     * that a sub-second clock slip between the check and the attempt cannot
     * produce a 1s timeout.
     */
    public function testTheCapNeverFallsBelowTheFloor(): void
    {
        \BccSleepSpy::startClock(self::T0);

        $capped = self::cap(['timeout' => 30], self::T0 + 1);
        self::assertSame(ApiRetry::MIN_ATTEMPT_BUDGET, $capped['timeout']);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function cap(array $args, int $deadlineAt): array
    {
        $m = (new ReflectionClass(ApiRetry::class))->getMethod('capTimeoutToDeadline');
        $m->setAccessible(true);

        /** @var array<string, mixed> $out */
        $out = $m->invoke(null, $args, $deadlineAt);

        return $out;
    }
}
