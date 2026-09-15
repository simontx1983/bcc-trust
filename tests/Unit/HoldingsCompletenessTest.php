<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\HoldingsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: an unavailable provider never becomes a confident zero.
 *
 * ── THE CHAIN THIS CLOSES ───────────────────────────────────────────────
 * Three steps, each individually defensible, that together turned a provider
 * outage into a holder-gate denial lasting a day:
 *
 *   1. `CosmosFetcher::list_holdings()` dropped a contract whose query had
 *      FAILED with the same `continue` it used for a contract the wallet
 *      genuinely owns nothing under. The two are indistinguishable in the
 *      result.
 *   2. `HoldingsService::fetchWalletHoldings()` wrote whatever came back to
 *      a 24-hour transient and called `markHoldingsRefreshed()` — with no
 *      success check at all.
 *   3. `countFromCacheOrFetch()` read that list back and returned a count,
 *      under a comment asserting "a cache HIT is by definition a previously
 *      SUCCESSFUL list walk, so its count is real". It was not.
 *
 * Nothing in that chain is a crash, a log line or a failed request. The
 * wallet simply appears to own nothing, and the gate agrees.
 *
 * ── WHY THE ASSERTIONS ARE ABOUT WRITES ─────────────────────────────────
 * The fix is not "return a better value" — a partial list is still returned,
 * because showing a user the NFTs we did find beats showing them nothing.
 * The fix is that a partial list may not be STORED, and may not be read back
 * as authoritative. So every test here asserts on what was written: the
 * transient, the freshness stamp, and whether the gate fell through to ask
 * the chain rather than trusting the cache.
 *
 * @see \BCC\Trust\Onchain\Contracts\FetcherInterface::list_holdings()
 */
#[CoversClass(HoldingsService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HoldingsCompletenessTest extends TestCase
{
    private const CHAIN_ID     = 8;
    private const WALLET_LINK  = 4242;
    private const WALLET       = 'cosmos1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';
    private const CONTRACT     = 'cosmos12gsv9tmjhhg86wg9fnd9cnju28jx3fxva9cn8dh9meketkfxxajqmg3exz';

    private const CACHE_KEY = 'bcc_holdings_w_' . self::WALLET_LINK;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/holdings-completeness-stubs.php';

        \BccHoldingsWorld::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN_ID, 'cosmos', 'cosmos');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return array{items: list<array<string, mixed>>, truncated: bool, complete: bool}|null */
    private function walk(bool $force = false): ?array
    {
        $m = new \ReflectionMethod(HoldingsService::class, 'fetchWalletHoldings');
        $m->setAccessible(true);

        /** @var array{items: list<array<string, mixed>>, truncated: bool, complete: bool}|null $r */
        $r = $m->invoke(
            null,
            self::WALLET_LINK,
            self::WALLET,
            \BCC\Trust\Onchain\Repositories\ChainRepository::getById(self::CHAIN_ID),
            $force
        );

        return $r;
    }

    private function gateCount(): ?int
    {
        $m = new \ReflectionMethod(HoldingsService::class, 'countFromCacheOrFetch');
        $m->setAccessible(true);

        /** @var int|null $r */
        $r = $m->invoke(
            null,
            new \BCC\Trust\Onchain\Fetchers\BccScriptedFetcher(),
            self::WALLET_LINK,
            self::WALLET,
            self::CONTRACT,
            self::CHAIN_ID,
            null
        );

        return $r;
    }

    /** @param list<array<string, mixed>> $items */
    private function scriptWalk(array $items, bool $complete, bool $truncated = false): void
    {
        \BccHoldingsWorld::$listAnswers = [[
            'items'     => $items,
            'truncated' => $truncated,
            'cursor'    => null,
            'complete'  => $complete,
        ]];
    }

    /** @return array<string, mixed> */
    private function item(string $contract, string $tokenId): array
    {
        return [
            'contract_address' => $contract,
            'token_id'         => $tokenId,
            'chain_id'         => self::CHAIN_ID,
            'collection_name'  => 'A Collection',
            'name'             => null,
            'image_url'        => null,
            'metadata_uri'     => null,
            'token_standard'   => 'CW-721',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. AN INCOMPLETE WALK IS NOT STORED
    // ═══════════════════════════════════════════════════════════════════

    public function testAnIncompleteWalkIsNeverCached(): void
    {
        $this->scriptWalk([], false);

        $result = $this->walk();

        self::assertNotNull($result);
        self::assertFalse($result['complete'], 'the caller is told the walk was partial');
        self::assertSame([], \BccHoldingsWorld::$transientWrites, 'a failed read must not populate the 24h cache');
        self::assertArrayNotHasKey(self::CACHE_KEY, \BccHoldingsWorld::$transients);
    }

    /**
     * ⚠ THE STAMP IS A CLAIM ABOUT THE CHAIN, NOT ABOUT THE ATTEMPT.
     * `markHoldingsRefreshed()` drives a freshness badge that says "we last
     * reached this chain at T". A partial read reached it and did not finish;
     * recording that as a refresh tells the operator the opposite.
     */
    public function testAnIncompleteWalkLeavesNoFreshnessStamp(): void
    {
        $this->scriptWalk([], false);

        $this->walk();

        self::assertSame([], \BccHoldingsWorld::$refreshStamps);
    }

    /**
     * It is still RETURNED. The gallery renders what we did find — dropping
     * it would turn a partial outage into a blank screen, which is a worse
     * answer and an equally false one.
     */
    public function testAnIncompleteWalkStillReturnsWhatItFound(): void
    {
        $this->scriptWalk([$this->item(self::CONTRACT, '7')], false);

        $result = $this->walk();

        self::assertNotNull($result);
        self::assertCount(1, $result['items']);
        self::assertFalse($result['complete']);
        self::assertSame([], \BccHoldingsWorld::$transientWrites, 'shown, but not stored');
    }

    /** And it says so, once, in the log — bounded, with no provider prose. */
    public function testAnIncompleteWalkIsRecorded(): void
    {
        $this->scriptWalk([], false);

        $this->walk();

        $warnings = array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn(string $l): bool => str_starts_with($l, 'warning')
        );
        self::assertCount(1, $warnings);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. A COMPLETE WALK BEHAVES EXACTLY AS BEFORE
    // ═══════════════════════════════════════════════════════════════════
    //
    //  Anti-vacuity: a service that cached NOTHING would satisfy every
    //  assertion above.

    public function testACompleteWalkIsCachedAndStamped(): void
    {
        $this->scriptWalk([$this->item(self::CONTRACT, '1')], true);

        $result = $this->walk();

        self::assertNotNull($result);
        self::assertTrue($result['complete']);
        self::assertSame([self::CACHE_KEY], \BccHoldingsWorld::$transientWrites);
        self::assertSame([self::WALLET_LINK], \BccHoldingsWorld::$refreshStamps);
    }

    /**
     * ⚠ A COMPLETE WALK THAT FOUND NOTHING IS A REAL EMPTY, and it must be
     * cached. Refusing to store it would re-walk the chain on every gallery
     * load for every wallet that genuinely holds no NFTs — which is most of
     * them — and the cost would land on the provider whose protection this
     * whole PR is about.
     */
    public function testACompleteEmptyWalkIsCached(): void
    {
        $this->scriptWalk([], true);

        $result = $this->walk();

        self::assertNotNull($result);
        self::assertSame([], $result['items']);
        self::assertTrue($result['complete']);
        self::assertSame([self::CACHE_KEY], \BccHoldingsWorld::$transientWrites);
        self::assertSame([self::WALLET_LINK], \BccHoldingsWorld::$refreshStamps);
    }

    /**
     * A missing flag fails CLOSED.
     *
     * Every driver in the tree sets `complete`; an absent key can only come
     * from an implementation whose completeness nobody can vouch for. The
     * cost of being wrong in this direction is one uncached walk. The cost in
     * the other direction is the defect this file exists for.
     */
    public function testAWalkWithNoCompletenessFlagIsTreatedAsIncomplete(): void
    {
        \BccHoldingsWorld::$listAnswers = [[
            'items'     => [],
            'truncated' => false,
            'cursor'    => null,
            // no `complete` key at all
        ]];

        $result = $this->walk();

        self::assertNotNull($result);
        self::assertFalse($result['complete']);
        self::assertSame([], \BccHoldingsWorld::$transientWrites);
        self::assertSame([], \BccHoldingsWorld::$refreshStamps);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. THE GATE NEVER READS A ZERO OUT OF AN UNWHOLE LIST
    // ═══════════════════════════════════════════════════════════════════

    /**
     * THE DENIAL THIS PREVENTS. A cached list that is present, has zero
     * matches, and is not known to be whole must send the gate to the chain —
     * which answers `null` (UNKNOWN) when the provider is still down, and the
     * gate's own fail-open rules take it from there. It must NOT answer 0,
     * because 0 means "this wallet holds none" and revokes access.
     */
    public function testAnIncompleteCachedListDoesNotProduceAGateZero(): void
    {
        \BccHoldingsWorld::$transients[self::CACHE_KEY] = [
            'items'     => [],
            'truncated' => false,
            'complete'  => false,
        ];
        \BccHoldingsWorld::$countIsUnknown = true;

        $count = $this->gateCount();

        self::assertNull($count, 'UNKNOWN, never a zero');
        self::assertContains('count_holdings', \BccHoldingsWorld::$fetcherCalls, 'the chain was asked');
    }

    /**
     * ⚠ AND A PAYLOAD FROM THE PREVIOUS RELEASE IS TREATED THE SAME WAY.
     * The flag did not exist yesterday, and a transient written yesterday
     * lives for 24 hours. An unflagged payload is not evidence of a
     * successful read, so it fails closed exactly like an incomplete one —
     * at a cost of one RPC per wallet while the old payloads age out.
     */
    public function testAPreFlagCachedListDoesNotProduceAGateZero(): void
    {
        \BccHoldingsWorld::$transients[self::CACHE_KEY] = [
            'items'     => [],
            'truncated' => false,
            // written by the previous release: no `complete` key
        ];
        \BccHoldingsWorld::$countIsUnknown = true;

        self::assertNull($this->gateCount());
        self::assertContains('count_holdings', \BccHoldingsWorld::$fetcherCalls);
    }

    /**
     * A COMPLETE list with zero matches IS a real zero, and is answered from
     * the cache without a request. Without this the fix would have cost an
     * RPC on every gate check forever.
     */
    public function testACompleteCachedListStillAnswersAGateZeroFromCache(): void
    {
        \BccHoldingsWorld::$transients[self::CACHE_KEY] = [
            'items'     => [],
            'truncated' => false,
            'complete'  => true,
        ];

        self::assertSame(0, $this->gateCount());
        self::assertNotContains('count_holdings', \BccHoldingsWorld::$fetcherCalls, 'no request was needed');
    }

    /**
     * ⚠ POSITIVE EVIDENCE SURVIVES INCOMPLETENESS. A partial list that DID
     * turn up the target proves ownership — the tail could only have added
     * more. Falling through here would spend a request to re-learn something
     * already known, and would risk answering UNKNOWN for a holder we can
     * see is a holder.
     */
    public function testAMatchInAnIncompleteListStillCounts(): void
    {
        \BccHoldingsWorld::$transients[self::CACHE_KEY] = [
            'items'     => [$this->item(self::CONTRACT, '1'), $this->item(self::CONTRACT, '2')],
            'truncated' => false,
            'complete'  => false,
        ];

        self::assertSame(2, $this->gateCount());
        self::assertNotContains('count_holdings', \BccHoldingsWorld::$fetcherCalls);
    }

    /**
     * The pre-existing whale rule is unchanged: truncated + zero matches
     * still falls through, complete or not. This is here so a later
     * simplification of the new condition cannot quietly drop it.
     */
    public function testTheTruncatedWhaleFallThroughSurvives(): void
    {
        \BccHoldingsWorld::$transients[self::CACHE_KEY] = [
            'items'     => [],
            'truncated' => true,
            'complete'  => true,
        ];
        \BccHoldingsWorld::$countAnswer = 3;

        self::assertSame(3, $this->gateCount(), 'the tail is asked about, not assumed empty');
        self::assertContains('count_holdings', \BccHoldingsWorld::$fetcherCalls);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  4. THE CACHE READ-BACK CARRIES THE FLAG
    // ═══════════════════════════════════════════════════════════════════

    /**
     * A cache HIT returns a normalised payload, so a caller reading
     * `complete` off a hit gets a boolean rather than a missing key — and an
     * unflagged stored payload reads as NOT complete.
     */
    public function testACacheHitNormalisesTheFlagAndFailsClosed(): void
    {
        \BccHoldingsWorld::$transients[self::CACHE_KEY] = [
            'items'     => [],
            'truncated' => false,
        ];

        $result = $this->walk();

        self::assertNotNull($result);
        self::assertArrayHasKey('complete', $result);
        self::assertFalse($result['complete']);
        self::assertSame([], \BccHoldingsWorld::$fetcherCalls, 'a hit is still served from cache');
    }
}
