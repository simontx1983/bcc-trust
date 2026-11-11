<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\CronService;
use BCC\Trust\Core\Services\VoteService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-166: one page whose recalculation throws a non-\Exception \Throwable
 * (TypeError, Error) must not stall the recalculation queue.
 *
 * Both cron loops caught only \Exception. A TypeError therefore escaped
 * processRecalculations() and aborted the WHOLE tick before
 * incrementRecalcFailures() ran, so:
 *  - the failing page was never quarantined (failures stayed 0),
 *  - it stayed first in getFlaggedPageIds() (ORDER BY last_calculated_at ASC),
 *  - and every page behind it was starved on every tick, forever.
 * hourlyRecalc() had the same hole, aborting its sweep at the bad page.
 *
 * The bad page is seeded with the OLDEST last_calculated_at so it is
 * processed first: under the old catch, nothing after it is reached.
 *
 * ── Why separate processes
 * The cron loops call do_action(), which the shared bootstrap does not
 * define, and the test swaps Plugin::instance()'s VoteService. Both are
 * process-global; isolation keeps them out of every sibling suite (a
 * function_exists-guarded stub declared in-process silently replaces a
 * later suite's own stub).
 */
#[Group('integration')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(CronService::class)]
final class RecalcQueueThrowableIntegrationTest extends TestCase
{
    private const BAD_PAGE   = 960001;
    private const GOOD_PAGES = [960002, 960003];

    /** @var list<int> */
    public static array $recalculated = [];

    protected function setUp(): void
    {
        if (!function_exists('do_action')) {
            eval('function do_action(string $hook, ...$args): void {}');
        }
        if (!function_exists('BCC\\Trust\\Core\\Security\\get_current_user_id')) {
            eval('namespace BCC\\Trust\\Core\\Security; function get_current_user_id(): int { return 0; }');
        }

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_page_scores`');
        self::$recalculated = [];

        // Bad page first in queue order (oldest), good pages behind it.
        $this->seedFlaggedPage(self::BAD_PAGE, '2026-01-01 00:00:00');
        $this->seedFlaggedPage(self::GOOD_PAGES[0], '2026-01-02 00:00:00');
        $this->seedFlaggedPage(self::GOOD_PAGES[1], '2026-01-03 00:00:00');

        $this->installExplodingVoteService();
    }

    private function seedFlaggedPage(int $pageId, string $lastCalculatedAt): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $ok   = $wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
            'page_id'              => $pageId,
            'category_id'          => 0,
            'page_owner_id'        => 1,
            'recalculate_required' => 1,
            'recalc_failures'      => 0,
            'last_calculated_at'   => $lastCalculatedAt,
        ]);
        self::assertNotFalse($ok, $wpdb->last_error);
    }

    /**
     * A VoteService whose recalculateScore() throws a TypeError for the bad
     * page and records every other page. Real VoteService type, so the
     * cron's Plugin::instance()->voteService() call is honoured unchanged.
     */
    private function installExplodingVoteService(): void
    {
        $double = (new \ReflectionClass(ExplodingVoteService::class))->newInstanceWithoutConstructor();

        $prop = new \ReflectionProperty(Plugin::class, 'voteService');
        $prop->setAccessible(true);
        $prop->setValue(Plugin::instance(), $double);
    }

    private function cron(): CronService
    {
        return new CronService(new VoteRepository(), new ScoreRepository());
    }

    /** @return array{required: int, failures: int} */
    private function state(int $pageId): array
    {
        $row = $GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare(
            'SELECT recalculate_required, recalc_failures FROM `' . $GLOBALS['wpdb']->prefix . 'bcc_trust_page_scores`
              WHERE page_id = %d AND category_id = 0',
            $pageId
        ));
        self::assertIsObject($row);
        return ['required' => (int) $row->recalculate_required, 'failures' => (int) $row->recalc_failures];
    }

    public function testATypeErrorPageDoesNotStopTheTickAndIsCounted(): void
    {
        $this->cron()->processRecalculations();

        self::assertSame(self::GOOD_PAGES, self::$recalculated, 'pages behind the failing one must still be processed');
        self::assertSame(['required' => 1, 'failures' => 1], $this->state(self::BAD_PAGE), 'the failure must be counted, page still queued for retry');
    }

    public function testATypeErrorPageIsQuarantinedAfterMaxFailures(): void
    {
        // One attempt per tick (audit M-B2), so three ticks reach the cap.
        for ($tick = 1; $tick <= 3; $tick++) {
            $this->cron()->processRecalculations();
        }

        self::assertSame(['required' => 0, 'failures' => 3], $this->state(self::BAD_PAGE), 'quarantined: out of the queue');
    }

    public function testTheHourlySweepContinuesPastATypeErrorPage(): void
    {
        $this->cron()->hourlyRecalc();

        self::assertSame(self::GOOD_PAGES, self::$recalculated, 'hourly sweep must not abort at the failing page');
    }
}

/**
 * Test double: the real VoteService type with recalculateScore() replaced.
 * Built via newInstanceWithoutConstructor(), so no constructor deps run.
 */
class ExplodingVoteService extends VoteService
{
    public function recalculateScore(int $pageId, bool $clearRecalcFlag = false): array
    {
        if ($pageId === 960001) {
            throw new \TypeError('simulated TypeError from one page');
        }

        RecalcQueueThrowableIntegrationTest::$recalculated[] = $pageId;

        // Mirror the real success path's flag clear so the page leaves the queue.
        $GLOBALS['wpdb']->update(
            $GLOBALS['wpdb']->prefix . 'bcc_trust_page_scores',
            ['recalculate_required' => 0, 'recalc_failures' => 0, 'last_calculated_at' => gmdate('Y-m-d H:i:s')],
            ['page_id' => $pageId]
        );

        return [];
    }
}
