<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Services\BonusRetryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-240: one page's failed bonus write must not stall the retry queue.
 *
 * processAll() used to let a throw from the score contributor escape the
 * per-page loop. That skipped the step-3 write-back entirely: pages that had
 * already been applied stayed queued (and were re-applied next run), and the
 * throwing page never had its attempts bumped, so it never reached
 * MAX_ATTEMPTS / quarantine and broke every later run the same way.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(BonusRetryService::class)]
final class BonusRetryProcessAllTest extends TestCase
{
    private const QUEUE      = 'bcc_onchain_pending_bonus';
    private const QUARANTINE = 'bcc_onchain_quarantined_bonus';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/bonus-retry-stubs.php';
        \BonusRetryWorld::reset();
    }

    /** @param array<int, int> $attemptsByPage page => attempts so far */
    private static function queue(array $attemptsByPage): void
    {
        $q = [];
        foreach ($attemptsByPage as $page => $attempts) {
            $q[$page] = ['bonus' => 1.0, 'queued_at' => 1000 + $page, 'attempts' => $attempts];
        }
        \BonusRetryWorld::$options[self::QUEUE] = $q;
    }

    /** @return array<int, array{bonus: float, queued_at: int, attempts: int}> */
    private static function queued(): array
    {
        /** @var array<int, array{bonus: float, queued_at: int, attempts: int}> */
        return \BonusRetryWorld::$options[self::QUEUE] ?? [];
    }

    public function testAThrowingPageIsAFailedAttemptAndTheOthersStillComplete(): void
    {
        self::queue([101 => 0, 202 => 3, 303 => 0]);
        \BonusRetryWorld::$throwFor = [202 => true];

        BonusRetryService::processAll();

        self::assertSame([101, 202, 303], \BonusRetryWorld::$applied, 'every page is attempted, including those after the failure');
        self::assertSame([202], array_keys(self::queued()), 'the applied pages leave the queue; only the failed one stays');
        self::assertSame(4, self::queued()[202]['attempts'], 'the failure counts as an attempt, so it can reach quarantine');

        $logged = array_values(array_filter(
            \BonusRetryWorld::$errors,
            static fn (array $e): bool => $e[0] === '[bcc-onchain-signals] bonus_retry_entry_failed'
        ));
        self::assertCount(1, $logged);
        self::assertSame(202, $logged[0][1]['page_id']);
    }

    public function testAPageThatAlwaysThrowsIsEventuallyQuarantined(): void
    {
        self::queue([202 => 0]);
        \BonusRetryWorld::$throwFor = [202 => true];

        for ($run = 0; $run < 25; $run++) {
            BonusRetryService::processAll();
        }

        self::assertSame([], self::queued(), 'the poison entry must leave the queue');
        self::assertArrayHasKey(202, \BonusRetryWorld::$options[self::QUARANTINE] ?? [], 'and be quarantined for an admin');
    }
}
