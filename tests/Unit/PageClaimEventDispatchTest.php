<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * BEHAVIOURAL tests for the per-page serialisation of `bcc_page_claimed`.
 *
 * Why this exists: every subscriber's idempotency guard is check-then-act
 * with no database constraint behind it — `wp_postmeta` is unique on
 * `meta_id` alone and `wp_peepso_activities` on `act_id` alone, and the
 * activity insert is a plain `$wpdb->insert`. So the structured-identity
 * replay guard added earlier is only safe for deliveries that arrive one
 * AFTER another. Two deliveries arriving at the SAME time both read
 * "absent" and both insert, producing the duplicate wall entry the guard
 * exists to prevent.
 *
 * `dispatchPageClaimed()` closes that by holding one advisory lock, keyed on
 * the page, across the WHOLE dispatch — all three subscribers, not one — and
 * by re-reading `post_author` INSIDE it on the retry path, so the decision to
 * re-fire is made against state that cannot change underneath it.
 *
 * ⚠ The stub's lock models MySQL faithfully, reentrancy included, so none of
 * these cases can pass for a reason a real `GET_LOCK` would not reproduce.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(\BCC\Trust\Core\REST\PagesEndpoint::class)]
final class PageClaimEventDispatchTest extends TestCase
{
    private const PAGE = 1234;
    private const LOCK = 'bcc_page_claim_event:1234';

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wp/');
        }

        $GLOBALS['__bcc_dispatch'] = [
            'session'     => 'A',
            'held'        => [],
            'acquires'    => [],
            'releases'    => [],
            'trace'       => [],
            'post_author' => [],
            'reads'       => 0,
            'dispatched'  => [],
            'on_dispatch' => null,
            'logs'        => [],
        ];

        require_once __DIR__ . '/../Stubs/page-claim-dispatch-stubs.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_dispatch']);
        parent::tearDown();
    }

    /** Call the private static dispatcher as the endpoint does. */
    private function dispatch(
        bool $onlyIfUnowned,
        int $pageId = self::PAGE,
        int $userId = 41
    ): void {
        $m = new ReflectionMethod(\BCC\Trust\Core\REST\PagesEndpoint::class, 'dispatchPageClaimed');
        $m->setAccessible(true);
        $m->invoke(null, $userId, $pageId, 'validator', 900, 'operator', $onlyIfUnowned);
    }

    /** @return list<string> */
    private function hooks(): array
    {
        return array_column($GLOBALS['__bcc_dispatch']['dispatched'], 'hook');
    }

    /** @return list<string> */
    private function levels(): array
    {
        return array_column($GLOBALS['__bcc_dispatch']['logs'], 'level');
    }

    // ------------------------------------------------------- the happy path

    public function testAFirstDeliveryTakesThePageLockFiresOnceAndReleasesIt(): void
    {
        $this->dispatch(onlyIfUnowned: false);

        $this->assertSame(['bcc_page_claimed'], $this->hooks());
        $this->assertSame(
            ['acquire:' . self::LOCK, 'dispatch:bcc_page_claimed', 'release:' . self::LOCK],
            $GLOBALS['__bcc_dispatch']['trace'],
            'the lock must be taken before the dispatch and released after it'
        );
        $this->assertSame([], $GLOBALS['__bcc_dispatch']['held'], 'no lock may be left held');
    }

    public function testTheDispatchCarriesTheCanonicalFiveArguments(): void
    {
        $this->dispatch(onlyIfUnowned: false);

        $this->assertSame(
            [41, self::PAGE, 'validator', 900, 'operator'],
            $GLOBALS['__bcc_dispatch']['dispatched'][0]['args']
        );
    }

    public function testTheLockIsKeyedOnThePageSoOtherPagesAreNotBlocked(): void
    {
        // Session A holds page 1234 and, from inside its dispatch, session B
        // claims a DIFFERENT page. It must not be blocked.
        $GLOBALS['__bcc_dispatch']['on_dispatch'] = function () {
            if ($GLOBALS['__bcc_dispatch']['session'] !== 'A') {
                return;
            }
            $GLOBALS['__bcc_dispatch']['session'] = 'B';
            $this->dispatch(onlyIfUnowned: false, pageId: 9999, userId: 77);
            $GLOBALS['__bcc_dispatch']['session'] = 'A';
        };

        $this->dispatch(onlyIfUnowned: false);

        $this->assertCount(2, $this->hooks(), 'two different pages dispatch independently');
        $this->assertContains('acquire:bcc_page_claim_event:9999', $GLOBALS['__bcc_dispatch']['trace']);
    }

    /**
     * The timeout is a WAIT, not a non-blocking try. A dropped delivery would
     * never write its activity row, and for a distinct second claim on the
     * same page nothing re-drives it.
     */
    public function testTheLockIsWaitedForRatherThanPolled(): void
    {
        $this->dispatch(onlyIfUnowned: false);

        $this->assertGreaterThan(
            0,
            $GLOBALS['__bcc_dispatch']['acquires'][0]['timeout'],
            'a zero timeout would silently drop the second of two overlapping deliveries'
        );
    }

    // --------------------------------------------- OVERLAPPING DELIVERIES

    /**
     * ⭐ THE CASE THIS FIX EXISTS FOR.
     *
     * Two requests deliver the same event at the same time. The second runs
     * on a different connection while the first still holds the page lock, so
     * its `GET_LOCK` fails and it does not dispatch. Exactly one dispatch
     * reaches the subscribers, which is what makes their check-then-act
     * guards sound.
     */
    public function testTwoOverlappingDeliveriesForOnePageProduceExactlyOneDispatch(): void
    {
        $GLOBALS['__bcc_dispatch']['on_dispatch'] = function () {
            if ($GLOBALS['__bcc_dispatch']['session'] !== 'A') {
                return;
            }
            // A peer connection arrives mid-dispatch with the same event.
            $GLOBALS['__bcc_dispatch']['session'] = 'B';
            $this->dispatch(onlyIfUnowned: false);
            $GLOBALS['__bcc_dispatch']['session'] = 'A';
        };

        $this->dispatch(onlyIfUnowned: false);

        $this->assertSame(
            ['bcc_page_claimed'],
            $this->hooks(),
            'the overlapping delivery must NOT reach the subscribers a second time'
        );
        $this->assertCount(2, $GLOBALS['__bcc_dispatch']['acquires'], 'both deliveries asked for the lock');
        $this->assertTrue($GLOBALS['__bcc_dispatch']['acquires'][0]['granted']);
        $this->assertFalse($GLOBALS['__bcc_dispatch']['acquires'][1]['granted']);
        $this->assertSame([], $GLOBALS['__bcc_dispatch']['held'], 'the holder still released');
    }

    public function testAContendedDeliveryIsLoggedAsRetryableAndReleasesNothing(): void
    {
        // A peer connection already holds the page lock.
        $GLOBALS['__bcc_dispatch']['held'][self::LOCK] = ['session' => 'peer', 'depth' => 1];

        $this->dispatch(onlyIfUnowned: false);

        $this->assertSame([], $this->hooks(), 'nothing may be dispatched without the lock');
        $this->assertSame(['warning'], $this->levels(), 'contention must be logged, not swallowed');

        $log = $GLOBALS['__bcc_dispatch']['logs'][0];
        $this->assertStringContainsString('NOT dispatched', $log['message']);
        $this->assertSame(self::PAGE, $log['ctx']['page_id']);
        $this->assertTrue($log['ctx']['retryable'], 'the log must say a later retry can still deliver it');

        $this->assertSame(
            [],
            $GLOBALS['__bcc_dispatch']['releases'],
            'releasing a lock we never took would free the holder early'
        );
        $this->assertSame(
            ['peer'],
            [$GLOBALS['__bcc_dispatch']['held'][self::LOCK]['session']],
            "the peer's lock survives"
        );
    }

    /**
     * Retryability is a property of the STATE, not of a flag: nothing records
     * "this event was delivered", and the retry trigger is the page still
     * having post_author === 0. A contended dispatch therefore leaves the page
     * in exactly the condition that makes the next submission re-fire it.
     */
    public function testAContendedRetryStillRefiresOnceTheLockIsFree(): void
    {
        $GLOBALS['__bcc_dispatch']['held'][self::LOCK] = ['session' => 'peer', 'depth' => 1];
        $GLOBALS['__bcc_dispatch']['post_author'][self::PAGE] = 0;

        $this->dispatch(onlyIfUnowned: true);
        $this->assertSame([], $this->hooks());

        // The peer's request ends without completing the transfer.
        unset($GLOBALS['__bcc_dispatch']['held'][self::LOCK]);

        $this->dispatch(onlyIfUnowned: true);
        $this->assertSame(['bcc_page_claimed'], $this->hooks(), 'the later retry delivers it');
    }

    // ------------------------------- the in-lock re-check (the retry path)

    /**
     * ⭐ ANTI-VACUITY FOR THE WHOLE FIX. The caller's `post_author === 0`
     * read happens OUTSIDE the lock, so a concurrent request can complete
     * the transfer between that read and this dispatch. The authoritative
     * read is this one, and the trace proves it is taken after the lock.
     */
    public function testARetryWhosePageWasTransferredWhileItWaitedDoesNotRefireTheEvent(): void
    {
        $GLOBALS['__bcc_dispatch']['post_author'][self::PAGE] = 55;

        $this->dispatch(onlyIfUnowned: true);

        $this->assertNotContains(
            'bcc_page_claimed',
            $this->hooks(),
            'the transfer is done; re-firing would re-drive three replay guards'
        );
        $this->assertSame(
            [
                'acquire:' . self::LOCK,
                'read:post_author:' . self::PAGE,
                'dispatch:bcc_page_claim_followers_drain',
                'release:' . self::LOCK,
            ],
            $GLOBALS['__bcc_dispatch']['trace'],
            'the ownership read must happen INSIDE the lock, or it decides on stale state'
        );
        $this->assertSame(['info'], $this->levels());
        $this->assertSame([self::LOCK], $GLOBALS['__bcc_dispatch']['releases']);
    }

    /**
     * WHY THE BRANCH ABOVE IS NOT JUST A `return`. `post_author !== 0` means
     * the TRANSFER completed, not that the EVENT completed: the priority-20
     * subscriber moves `post_author` first and drains `bcc_page_follows`
     * last. So a drain that stopped on a DB fault leaves rows behind on a
     * page that now fails this very gate -- and the gate is the only retry
     * trigger there is, which made those rows permanently unreachable.
     *
     * The narrow hook retries that one step without re-firing the event.
     * It must be dispatched INSIDE the lock (asserted in the trace above),
     * because the drain is what the lock exists to serialise.
     */
    public function testARetryWhosePageIsAlreadyOwnedStillRetriesTheFollowerDrain(): void
    {
        $GLOBALS['__bcc_dispatch']['post_author'][self::PAGE] = 55;

        $this->dispatch(onlyIfUnowned: true, userId: 41);

        $this->assertSame(
            [['hook' => 'bcc_page_claim_followers_drain', 'args' => [41, self::PAGE]]],
            $GLOBALS['__bcc_dispatch']['dispatched'],
            'exactly one narrow retry, carrying only the claimer and the page'
        );
    }

    /**
     * The fresh path drains inside `bcc_page_claimed` already. Firing both
     * would run the drain twice in one request -- harmless but wasteful, and
     * it would make the summary log lie about how many passes it took.
     */
    public function testAFreshDeliveryDoesNotAlsoFireTheDrainRetry(): void
    {
        $GLOBALS['__bcc_dispatch']['post_author'][self::PAGE] = 55;

        $this->dispatch(onlyIfUnowned: false);

        $this->assertSame(['bcc_page_claimed'], $this->hooks());
    }

    /**
     * Contention is refused before the ownership read, so neither the event
     * nor the narrow retry is dispatched. The drain MUST NOT run outside the
     * lock: `peepso_user_followers` has no unique index on the pair and
     * PeepSo's writer is SELECT-then-INSERT, so two concurrent drains of one
     * page could duplicate follower rows.
     */
    public function testAContendedRetryOnAnOwnedPageFiresNeither(): void
    {
        $GLOBALS['__bcc_dispatch']['held'][self::LOCK] = ['session' => 'peer', 'depth' => 1];
        $GLOBALS['__bcc_dispatch']['post_author'][self::PAGE] = 55;

        $this->dispatch(onlyIfUnowned: true);

        $this->assertSame([], $this->hooks());
        $this->assertSame(0, $GLOBALS['__bcc_dispatch']['reads'], 'refused before it asks anything');
        $this->assertSame([], $GLOBALS['__bcc_dispatch']['releases']);
    }

    public function testARetryOnAStillUnownedPageFires(): void
    {
        $GLOBALS['__bcc_dispatch']['post_author'][self::PAGE] = 0;

        $this->dispatch(onlyIfUnowned: true);

        $this->assertSame(['bcc_page_claimed'], $this->hooks(), 'an interrupted transfer must still finish');
        $this->assertSame(1, $GLOBALS['__bcc_dispatch']['reads']);
    }

    /**
     * The gate is scoped to the RETRY path. A fresh claim must be announced
     * whoever owns the page: a multi-chain operator's second claim lands on
     * an already-owned page and still needs its own activity row and its own
     * messaging activation.
     */
    public function testAFreshClaimFiresEvenWhenThePageIsAlreadyOwned(): void
    {
        $GLOBALS['__bcc_dispatch']['post_author'][self::PAGE] = 55;

        $this->dispatch(onlyIfUnowned: false);

        $this->assertSame(['bcc_page_claimed'], $this->hooks());
        $this->assertSame(0, $GLOBALS['__bcc_dispatch']['reads'], 'a fresh claim asks no ownership question');
    }

    // ------------------------------------------------------------ cleanup

    /**
     * Priority 20 is bound directly, with no try/catch of its own, so a
     * throwing subscriber reaches this frame. Without the `finally` the lock
     * would stay held for the rest of the connection and every later delivery
     * for this page in the same request would be refused.
     */
    public function testTheLockIsReleasedWhenASubscriberThrows(): void
    {
        $GLOBALS['__bcc_dispatch']['on_dispatch'] = static function (): void {
            throw new \RuntimeException('subscriber exploded');
        };

        try {
            $this->dispatch(onlyIfUnowned: false);
            $this->fail('the exception must propagate — swallowing it would hide a broken subscriber');
        } catch (\RuntimeException $e) {
            $this->assertSame('subscriber exploded', $e->getMessage());
        }

        $this->assertSame([self::LOCK], $GLOBALS['__bcc_dispatch']['releases']);
        $this->assertSame([], $GLOBALS['__bcc_dispatch']['held']);
    }

    // ------------------------------------------------------- the wiring

    /**
     * The dispatcher is private and reachable only through
     * `successResponse()`, so the two call sites are pinned structurally:
     * the idempotent retry must ask for the in-lock re-check, and the fresh
     * claim must not. Behaviour above covers what each setting then does.
     */
    public function testTheRetryPathAsksForTheRecheckAndTheFreshPathDoesNot(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/REST/PagesEndpoint.php'
        );

        $this->assertStringContainsString(
            'self::dispatchPageClaimed(',
            $src,
            'successResponse must dispatch through the locked helper, never do_action directly'
        );
        $this->assertSame(
            1,
            substr_count($src, "do_action('bcc_page_claimed'"),
            'exactly one dispatch site, inside the lock'
        );

        // Comments mention the argument too, so compare POSITIONS rather
        // than counting a literal: the one call-site spelling of it must sit
        // inside the idempotent-retry branch, after that branch's status
        // string, and the fresh-claim call site further down must not carry
        // one.
        $retryAt = strpos($src, 'claimId:    (int) $existing->id');
        $freshAt = strpos($src, "'Verified as '");
        self::assertNotFalse($retryAt);
        self::assertNotFalse($freshAt);
        self::assertLessThan($freshAt, $retryAt, 'the retry branch comes first in this file');

        $callSites = [];
        $at        = 0;
        while (($at = strpos($src, 'onlyIfUnowned: true', $at)) !== false) {
            // Skip the comment mentions, which are backtick-quoted.
            if (substr($src, $at - 1, 1) !== '`') {
                $callSites[] = $at;
            }
            $at++;
        }

        self::assertCount(1, $callSites, 'exactly one call site may ask for the re-check');
        self::assertGreaterThan($retryAt, $callSites[0], 'it must be the idempotent-retry call site');
        self::assertLessThan($freshAt, $callSites[0], 'and NOT the fresh-claim one');
    }
}
