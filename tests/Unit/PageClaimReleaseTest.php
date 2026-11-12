<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Integration\PeepSoIntegration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * F-334 (user decision) — a REVOKED claim frees the page, and the next
 * verified operator's claim takes it over, score rows included.
 *
 * Runs the real PeepSoIntegration against tests/Stubs/page-follow-drain-stubs.php
 * (post_author, PeepSo page membership, score-row owner, the "still held"
 * read, audit rows — all in memory).
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
final class PageClaimReleaseTest extends TestCase
{
    private const PAGE = 7;
    private const OLD  = 500;
    private const NEW  = 600;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wp/');
        }

        $GLOBALS['__bcc_drain'] = [
            'rows' => [], 'reads' => 0, 'follows' => 0, 'delete_calls' => [], 'deleted' => [],
            'by_page_id' => 0, 'meta' => [], 'info' => [], 'errors' => [], 'warnings' => [],
            'post_author' => [], 'author_reads' => 0, 'page_members' => [], 'page_writes' => [],
            'page_counts' => [], 'page_count_drops' => [], 'score_owner' => [], 'page_held' => [],
            'audits' => [],
        ];

        require_once __DIR__ . '/../Stubs/page-follow-drain-stubs.php';

        // The state a verified claim leaves (F-299): author + PeepSo owner.
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE]               = self::OLD;
        $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD] = 'member_owner';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_drain']);
        parent::tearDown();
    }

    private function integration(): PeepSoIntegration
    {
        $instance = (new ReflectionClass(PeepSoIntegration::class))->newInstanceWithoutConstructor();
        foreach (['userInfoRepo' => \BCC\Trust\Core\Repositories\UserInfoRepository::class,
                  'scoreRepo'    => \BCC\Trust\Core\Repositories\ScoreRepository::class] as $prop => $class) {
            $p = new ReflectionProperty(PeepSoIntegration::class, $prop);
            $p->setAccessible(true);
            $p->setValue($instance, new $class());
        }

        return $instance;
    }

    /** @return list<array{0: string, 1: int|null, 2: array<string, mixed>}> */
    private static function audits(string $action): array
    {
        return array_values(array_filter($GLOBALS['__bcc_drain']['audits'], static fn (array $a): bool => $a[0] === $action));
    }

    // ── the release ─────────────────────────────────────────────────────

    public function testARevokedClaimFreesThePage(): void
    {
        self::assertTrue($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(0, $GLOBALS['__bcc_drain']['post_author'][self::PAGE], 'post_author is reset to 0');
        self::assertSame('member', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD], 'owner demoted, not removed');
        self::assertSame([[self::OLD, self::PAGE]], $GLOBALS['__bcc_drain']['page_count_drops']);

        $released = self::audits('page_owner_released');
        self::assertCount(1, $released, 'one audit row per page');
        self::assertSame(self::PAGE, $released[0][1]);
        self::assertSame(['old_owner' => self::OLD, 'author_reset' => true, 'owner_row' => 'demoted'], $released[0][2]);
    }

    /** A multi-chain page can still be held through another validator. */
    public function testAPageStillHeldByAnotherVerifiedClaimIsNotTouched(): void
    {
        $GLOBALS['__bcc_drain']['page_held'][self::PAGE] = true;

        self::assertFalse($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(self::OLD, $GLOBALS['__bcc_drain']['post_author'][self::PAGE]);
        self::assertSame('member_owner', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD]);
        self::assertSame([], $GLOBALS['__bcc_drain']['audits']);
    }

    /** Unknown is never "free": a failed hold read changes nothing. */
    public function testAnUnreadableHoldStateNeverFreesThePage(): void
    {
        $GLOBALS['__bcc_drain']['held_read_fails'] = true;

        self::assertFalse($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(self::OLD, $GLOBALS['__bcc_drain']['post_author'][self::PAGE]);
        self::assertSame('member_owner', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD]);
        self::assertCount(1, $GLOBALS['__bcc_drain']['errors'], 'logged, never thrown');
    }

    /** An admin reassignment since the claim is not undone by a release. */
    public function testAPageReassignedSinceKeepsItsNewAuthor(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE] = 999;

        self::assertTrue($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(999, $GLOBALS['__bcc_drain']['post_author'][self::PAGE]);
        self::assertSame([], $GLOBALS['__bcc_drain']['page_count_drops']);
        self::assertSame('member', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD], 'their owner row still goes');
        self::assertFalse(self::audits('page_owner_released')[0][2]['author_reset']);
    }

    // ── the takeover ────────────────────────────────────────────────────

    public function testTheNextOperatorsClaimTakesThePageOverIncludingTheScoreRow(): void
    {
        $GLOBALS['__bcc_drain']['score_owner'][self::PAGE] = self::OLD; // history under the old owner
        $integration = $this->integration();

        self::assertTrue($integration->releaseClaimedPage(self::PAGE, self::OLD));
        $integration->onPageClaimed(self::NEW, self::PAGE);

        self::assertSame(self::NEW, $GLOBALS['__bcc_drain']['post_author'][self::PAGE], 'the new operator is the author');
        self::assertSame('member_owner', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::NEW], 'and the PeepSo owner');
        self::assertSame('member', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD]);
        self::assertSame(self::NEW, $GLOBALS['__bcc_drain']['score_owner'][self::PAGE], 'the score rows move to the new owner');

        $changed = self::audits('page_owner_changed');
        self::assertCount(1, $changed);
        self::assertSame(['old_owner' => self::OLD, 'new_owner' => self::NEW, 'via' => 'claim_after_release'], $changed[0][2]);
    }

    public function testAFirstEverClaimMovesNoScoreRow(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE] = 0; // a never-claimed placeholder: no score row

        $this->integration()->onPageClaimed(self::NEW, self::PAGE);

        self::assertArrayNotHasKey(self::PAGE, $GLOBALS['__bcc_drain']['score_owner']);
        self::assertSame([], self::audits('page_owner_changed'));
    }
}
