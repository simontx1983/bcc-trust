<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\CardWatchersService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-26: `GET /entities/{kind}/{id}/watchers` lists the page OWNER's
 * followers, so it answers to the owner's profile privacy and suspension,
 * via the SAME gate as /users/:handle/followers (ProfileAccess, F-02 +
 * F-182). And it only accepts a published entity page of the requested
 * kind: it used to take ANY post id, so a member's status-post or draft id
 * listed that member's followers.
 *
 * Real CardWatchersService + real ProfileAccess; PeepSo, bcc-core
 * Permissions, the post lookup and the follower repository are doubled
 * (tests/Stubs/card-watchers-gate-stubs.php → profile-access-stubs.php).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CardWatchersProfileGateTest extends TestCase
{
    private const OWNER = 70;
    private const PAGE  = 3101;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/card-watchers-gate-stubs.php';

        // The owner's profile is public and PeepSo's ladder admits it.
        $GLOBALS['__bcc_profile_access'] = ['levels' => [self::OWNER => 10], 'verdicts' => [10 => true], 'calls' => []];
        $GLOBALS['__bcc_cw'] = [
            'posts'          => [self::PAGE => self::post('peepso-page', 'publish')],
            'meta'           => [self::PAGE => 'validator'],
            'follower_reads' => [],
        ];
    }

    private static function post(string $type, string $status, int $author = self::OWNER): object
    {
        return (object) ['post_type' => $type, 'post_status' => $status, 'post_author' => $author];
    }

    /** @return array<string, mixed>|null */
    private static function watchers(string $kind = 'validator'): ?array
    {
        return (new CardWatchersService())->listWatchers(self::PAGE, 0, 0, 24, $kind);
    }

    public function testAPublicOwnersWatchersAreListed(): void
    {
        $result = self::watchers();

        self::assertNotNull($result, 'the anti-vacuity control: a public owner is not refused');
        self::assertSame([self::OWNER], $GLOBALS['__bcc_cw']['follower_reads']);
    }

    public function testAPrivateOwnersWatchersAreRefusedBeforeAnyFollowerRead(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => 40];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [40 => false];

        self::assertNull(self::watchers(), 'null → the endpoint answers ProfileAccess::forbidden()');
        self::assertSame([], $GLOBALS['__bcc_cw']['follower_reads']);
    }

    public function testASuspendedOwnersWatchersAreRefused(): void
    {
        $GLOBALS['__bcc_profile_access']['suspended'] = [self::OWNER => true];

        self::assertNull(self::watchers());
        self::assertSame([], $GLOBALS['__bcc_cw']['follower_reads']);
    }

    /** F-182's rule: an UNKNOWN suspension (unreadable) does NOT hide the profile. */
    public function testAnUnknownSuspensionDoesNotHideTheWatchers(): void
    {
        $GLOBALS['__bcc_profile_access']['suspended'] = [self::OWNER => 'unknown'];

        self::assertNotNull(self::watchers());
    }

    /** @return iterable<string, array{0: object, 1: string}> */
    public static function notAnEntityPage(): iterable
    {
        yield 'a status post (the old back door)' => [self::post('peepso-post', 'publish'), 'validator'];
        yield 'a draft entity page'               => [self::post('peepso-page', 'draft'), 'validator'];
        yield 'a page of another kind'            => [self::post('peepso-page', 'publish'), 'project'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notAnEntityPage')]
    public function testANonMatchingTargetAnswersLikeAMissingOne(object $post, string $kind): void
    {
        $GLOBALS['__bcc_cw']['posts'][self::PAGE] = $post;

        $result = self::watchers($kind);

        self::assertNotNull($result, 'a non-matching target is an empty page, not a 403 (no oracle)');
        self::assertSame([], $result['items']);
        self::assertSame(0, $result['pagination']['total']);
        self::assertSame([], $GLOBALS['__bcc_cw']['follower_reads'], 'no follower read for a non-entity post');
    }
}
