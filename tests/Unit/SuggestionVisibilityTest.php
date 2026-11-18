<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\UserSyncRepository;
use BCC\Trust\Core\Services\SuggestionService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * §F-02 on member suggestions (GET /suggestions/users). Suggestions are
 * DISCOVERY, so they apply the directory's rule, the REAL
 * ProfileAccess::filterVisible (the profile ladder, banned accounts, blocks,
 * hide_from_search) over a PeepSoUserSearch double that admits only the
 * `visible` fixture. Before, a private or search-hidden member was
 * suggested by handle, name and avatar.
 *
 * Covered on all three places a member reaches the response: the ranked
 * pool, the cold-start top-up, and the mutual_follows reason's sample
 * avatars.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SuggestionVisibilityTest extends TestCase
{
    private const VIEWER = 5101;

    protected function setUp(): void
    {
        // Doubles first: they must exist before the real classes autoload.
        require_once dirname(__DIR__) . '/Stubs/suggestion-visibility-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/filter-visible-stubs.php';

        $GLOBALS['__bcc_fv']        = ['visible' => [], 'admin' => false, 'constructed' => []];
        $GLOBALS['__bcc_fv_viewer'] = self::VIEWER;
        $GLOBALS['__bcc_sv']        = ['users' => [], 'avatar_calls' => []];
        UserSyncRepository::$recent = [];
    }

    // ── the ranked pool ─────────────────────────────────────────────────

    public function testAHiddenCandidateNeverTakesASlotAndTheNextVisibleOneMovesUp(): void
    {
        self::visible([22, 23, 24]); // 21, the best score, is hidden

        $ranked = self::rank(self::pool([21 => 9.0, 22 => 8.0, 23 => 7.0, 24 => 6.0]), 2);

        self::assertSame([22, 23], array_column($ranked, 'id'));
        self::assertSame(8.0, $ranked[0]['score']);
        self::assertArrayHasKey('follows_you', $ranked[0]['signals'], 'the row keeps its signals for the reason');
    }

    public function testTheOrderIsStillScoreThenId(): void
    {
        self::visible([30, 31, 32]);

        $ranked = self::rank(self::pool([31 => 5.0, 30 => 5.0, 32 => 9.0]), 3);

        self::assertSame([32, 30, 31], array_column($ranked, 'id'));
    }

    /**
     * filterVisible REFUSES a set over FILTER_VISIBLE_MAX (answers []), and
     * the pool can be larger, so it is asked in rank-ordered chunks.
     */
    public function testAPoolLargerThanOneVisibilityCallIsAskedInChunks(): void
    {
        $scores = [];
        for ($id = 1; $id <= 600; $id++) {
            $scores[$id] = (float) (1000 - $id); // rank order is 1, 2, … 600
        }
        self::visible(range(551, 600));

        $ranked = self::rank(self::pool($scores), 3);

        self::assertSame([551, 552, 553], array_column($ranked, 'id'));
        $asked = array_map(static fn (array $c): int => count($c['args']['include']), $GLOBALS['__bcc_fv']['constructed']);
        self::assertSame([500, 100], $asked, 'two calls, neither over the bound');
    }

    public function testTheWalkStopsOnceEnoughAreVisible(): void
    {
        $scores = [];
        for ($id = 1; $id <= 600; $id++) {
            $scores[$id] = (float) (1000 - $id);
        }
        self::visible(range(1, 600));

        self::assertSame([1, 2, 3], array_column(self::rank(self::pool($scores), 3), 'id'));
        self::assertCount(1, $GLOBALS['__bcc_fv']['constructed'], 'one query when the first chunk is enough');
    }

    // ── the cold-start top-up ───────────────────────────────────────────

    public function testTheColdStartTopUpNeverOffersAHiddenMember(): void
    {
        UserSyncRepository::$recent = [41, 42, 43, 44];
        self::visible([42, 43, 44]);

        $two = $this->topUp([], 2);
        self::assertCount(2, $two);
        self::assertNotContains(41, $two);

        $all = $this->topUp([], 4);
        sort($all);
        self::assertSame([42, 43, 44], $all, 'a hidden member never fills a slot');
    }

    public function testTheColdStartTopUpStillAppliesTheExclusions(): void
    {
        UserSyncRepository::$recent = [41, 42, 43];
        self::visible([41, 42, 43]);

        $out = $this->topUp([42 => true], 3);
        sort($out);

        self::assertSame([41, 43], $out);
    }

    // ── the reason's sample avatars ─────────────────────────────────────

    public function testAHiddenSampleMemberIsNeverShownInTheReason(): void
    {
        $GLOBALS['__bcc_sv']['users'] = [51 => true, 52 => true];
        self::visible([51]);

        $avatars = self::sampleAvatars([51, 52]);

        self::assertSame([51], array_keys($avatars));
        self::assertSame('h51', $avatars[51]['handle']);
        self::assertSame([[51]], $GLOBALS['__bcc_sv']['avatar_calls'], "the hidden member's avatar is never resolved");
    }

    public function testNoVisibleSampleMeansNoAvatarLookup(): void
    {
        $GLOBALS['__bcc_sv']['users'] = [51 => true];

        self::assertSame([], self::sampleAvatars([51]));
        self::assertSame([], $GLOBALS['__bcc_sv']['avatar_calls']);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @param list<int> $ids */
    private static function visible(array $ids): void
    {
        $GLOBALS['__bcc_fv']['visible'] = array_fill_keys($ids, true);
    }

    /**
     * @param array<int, float> $scores
     * @return array<int, array{score: float, signals: array<string, array{contribution: float, label: string}>}>
     */
    private static function pool(array $scores): array
    {
        $pool = [];
        foreach ($scores as $id => $score) {
            $pool[$id] = ['score' => $score, 'signals' => ['follows_you' => ['contribution' => $score, 'label' => 'Watches you']]];
        }

        return $pool;
    }

    /**
     * @param array<int, array<string, mixed>> $pool
     * @return list<array{id: int, score: float, signals: array<string, mixed>}>
     */
    private static function rank(array $pool, int $limit): array
    {
        /** @var list<array{id: int, score: float, signals: array<string, mixed>}> $out */
        $out = (new \ReflectionMethod(SuggestionService::class, 'rankVisible'))->invoke(null, $pool, $limit);

        return $out;
    }

    /**
     * @param array<int, true> $exclude
     * @return list<int>
     */
    private function topUp(array $exclude, int $need): array
    {
        $service = (new \ReflectionClass(SuggestionService::class))->newInstanceWithoutConstructor();

        /** @var list<int> $out */
        $out = (new \ReflectionMethod(SuggestionService::class, 'coldStartTopUp'))->invoke($service, self::VIEWER, $exclude, [], $need);

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{handle: string, avatar_url: string}>
     */
    private static function sampleAvatars(array $ids): array
    {
        /** @var array<int, array{handle: string, avatar_url: string}> $out */
        $out = (new \ReflectionMethod(SuggestionService::class, 'sampleAvatars'))->invoke(null, $ids);

        return $out;
    }
}
