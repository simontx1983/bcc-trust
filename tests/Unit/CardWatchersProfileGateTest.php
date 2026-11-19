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
 * A SECRET page the viewer may not see answers exactly like a missing page,
 * and before the owner's profile gate, whose 403 would give it away
 * (PageAccess, over the REAL bcc-core PeepSoPagePrivacy).
 *
 * Real CardWatchersService + real ProfileAccess + real PageAccess; PeepSo,
 * bcc-core Permissions, the post lookup and the follower repository are
 * doubled (tests/Stubs/card-watchers-gate-stubs.php → profile-access-stubs.php),
 * and `$wpdb` answers the page-privacy reads
 * (tests/Stubs/secret-page-list-stubs.php).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CardWatchersProfileGateTest extends TestCase
{
    private const OWNER  = 70;
    private const PAGE   = 3101;
    private const VIEWER = 5;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/card-watchers-gate-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/secret-page-list-stubs.php';
        \bcc_spl_load_core();
        $GLOBALS['__bcc_spl'] = [];
        $GLOBALS['wpdb']      = new \BccSecretPageListWpdb();

        // The owner's profile is public and PeepSo's ladder admits it.
        $GLOBALS['__bcc_profile_access'] = ['levels' => [self::OWNER => 10], 'verdicts' => [10 => true], 'calls' => []];
        $GLOBALS['__bcc_cw'] = [
            'posts'          => [self::PAGE => self::post('peepso-page', 'publish')],
            'meta'           => [self::PAGE => 'validator'],
            'follower_reads' => [],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['__bcc_spl']);
    }

    private static function post(string $type, string $status, int $author = self::OWNER): \WP_Post
    {
        return \bcc_spl_post(self::PAGE, $author, $type, $status);
    }

    /** @return array<string, mixed>|null */
    private static function watchers(string $kind = 'validator', int $viewer = 0): ?array
    {
        return (new CardWatchersService())->listWatchers(self::PAGE, $viewer, 0, 24, $kind);
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

    /** A legacy page with no `_bcc_page_type` is a project page (the card layer's '' → 'builder' default). */
    public function testAnUntypedPageIsAProjectPage(): void
    {
        $GLOBALS['__bcc_cw']['meta'][self::PAGE] = '';

        $result = self::watchers('project');
        self::assertNotNull($result);
        self::assertSame([self::OWNER], $GLOBALS['__bcc_cw']['follower_reads'], 'project_card on an untyped page lists the watchers');

        $GLOBALS['__bcc_cw']['follower_reads'] = [];
        $result = self::watchers('validator');
        self::assertNotNull($result);
        self::assertSame([], $result['items']);
        self::assertSame([], $GLOBALS['__bcc_cw']['follower_reads'], 'validator_card on an untyped page is an empty page');
    }

    /**
     * Plain strings: a data provider runs in the PARENT process, where the
     * WP_Post double does not exist.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function notAnEntityPage(): iterable
    {
        yield 'a status post (the old back door)' => ['peepso-post', 'publish', 'validator'];
        yield 'a draft entity page'               => ['peepso-page', 'draft', 'validator'];
        yield 'a page of another kind'            => ['peepso-page', 'publish', 'project'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notAnEntityPage')]
    public function testANonMatchingTargetAnswersLikeAMissingOne(string $type, string $status, string $kind): void
    {
        $GLOBALS['__bcc_cw']['posts'][self::PAGE] = self::post($type, $status);

        $result = self::watchers($kind);

        self::assertNotNull($result, 'a non-matching target is an empty page, not a 403 (no oracle)');
        self::assertSame([], $result['items']);
        self::assertSame(0, $result['pagination']['total']);
        self::assertSame([], $GLOBALS['__bcc_cw']['follower_reads'], 'no follower read for a non-entity post');
    }
    // ── SECRET pages (PageAccess) ───────────────────────────────────────

    /** @return iterable<string, array{0: int}> */
    public static function viewersTheSecretPageDoesNotAdmit(): iterable
    {
        yield 'a signed-in stranger' => [self::VIEWER];
        yield 'anonymous'            => [0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('viewersTheSecretPageDoesNotAdmit')]
    public function testASecretPageAnswersExactlyLikeAMissingOne(int $viewer): void
    {
        $GLOBALS['__bcc_spl']['secret'] = [self::PAGE => true];
        $hidden = self::watchers('validator', $viewer);

        unset($GLOBALS['__bcc_cw']['posts'][self::PAGE]);
        $missing = self::watchers('validator', $viewer);

        self::assertNotNull($hidden);
        self::assertSame($missing, $hidden);
        self::assertSame([], $GLOBALS['__bcc_cw']['follower_reads'], 'no follower read for a hidden page');
    }

    public function testTheSecretPageGateRunsBeforeTheOwnersProfileGate(): void
    {
        // A private owner would be a 403, and a 403 says "this page exists".
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => 40];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [40 => false];
        $GLOBALS['__bcc_spl']['secret']              = [self::PAGE => true];

        $result = self::watchers('validator', self::VIEWER);

        self::assertNotNull($result, 'the empty page a missing page gets, not the 403');
        self::assertSame([], $result['items']);
        self::assertSame(0, $result['pagination']['total']);
        self::assertSame([], $GLOBALS['__bcc_profile_access']['calls'], 'the profile gate is never asked');
    }

    /** @return iterable<string, array{0: list<string>}> */
    public static function admittedBySecretPage(): iterable
    {
        yield 'a member' => [['member']];
        yield 'invited'  => [['pending_user']];
    }

    /** @param list<string> $statuses */
    #[\PHPUnit\Framework\Attributes\DataProvider('admittedBySecretPage')]
    public function testThoseTheSecretPageAdmitsGetTheList(array $statuses): void
    {
        $GLOBALS['__bcc_spl']['secret']                          = [self::PAGE => true];
        $GLOBALS['__bcc_spl']['statuses'][self::PAGE][self::VIEWER] = $statuses;

        self::assertNotNull(self::watchers('validator', self::VIEWER));
        self::assertSame([self::OWNER], $GLOBALS['__bcc_cw']['follower_reads']);
    }

    public function testTheSecretPagesOwnerAndAdminsGetTheList(): void
    {
        $GLOBALS['__bcc_spl']['secret'] = [self::PAGE => true];

        self::assertNotNull(self::watchers('validator', self::OWNER));
        self::assertSame([self::OWNER], $GLOBALS['__bcc_cw']['follower_reads'], 'the author');

        $GLOBALS['__bcc_cw']['follower_reads']       = [];
        $GLOBALS['__bcc_profile_access']['is_admin'] = true;
        self::assertNotNull(self::watchers('validator', self::VIEWER));
        self::assertSame([self::OWNER], $GLOBALS['__bcc_cw']['follower_reads'], 'an admin');
    }
}
