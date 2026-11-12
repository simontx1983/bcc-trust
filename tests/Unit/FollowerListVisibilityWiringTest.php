<?php
/**
 * §F-02 — the follower/following reads opt in to bcc-core's
 * profile-visibility gate, and the COUNT opts in identically.
 *
 * What this suite is for: the behaviour under test is WHICH ARGUMENTS
 * bcc-trust passes, which no return-value assertion can reach. bcc-core
 * owns the SQL and has its own tests for it; what can go wrong HERE is
 * passing the gate to one query and not its sibling, or handing it the
 * wrong shape of friend list.
 *
 * ⚠⚠ The list and the count are SEPARATE QUERIES. Gate one and not the
 * other and the page renders "128 following" above seven rows — and the
 * `$total === 0` short-circuit would return an empty page while the
 * count insisted there were followers. That is the defect this file
 * exists to prevent, and it is invisible to any test that only checks
 * the rows.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\UserFollowsService;
use BCC\Trust\Core\Support\PeepSoFriendGate;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FollowerListVisibilityWiringTest extends TestCase
{
    private const TARGET = 42;
    private const VIEWER = 7;

    protected function setUp(): void
    {
        parent::setUp();
        // ⛔ Never alongside profile-access-stubs.php — both declare
        // \BCC\Core\Permissions\Permissions. See the stub's header.
        require_once __DIR__ . '/../Stubs/follower-wiring-stubs.php';
        $GLOBALS['__bcc_flw'] = [
            'is_admin'   => false,
            'friend_ids' => [],
            'counts'     => ['followers' => 5, 'following' => 5],
            'calls'      => [],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_flw']);
        parent::tearDown();
    }

    /** @return list<array{method: string, args: list<mixed>}> */
    private function calls(): array
    {
        $c = $GLOBALS['__bcc_flw']['calls'] ?? [];

        return is_array($c) ? $c : [];
    }

    /** The (viewerId, friendIds, enforce) triple each call received. */
    private function gateArgsByMethod(): array
    {
        $out = [];
        foreach ($this->calls() as $call) {
            $out[$call['method']] = array_slice($call['args'], 1);
        }

        return $out;
    }

    // ── the equality that matters ───────────────────────────────────

    public function testTheCountAndTheFollowersListAreGatedIdentically(): void
    {
        (new UserFollowsService())->listFollowers(self::TARGET, self::VIEWER, 0, 24);

        $args = $this->gateArgsByMethod();

        self::assertArrayHasKey('getCounts', $args, 'the count must be asked at all');
        self::assertArrayHasKey('getFollowers', $args, 'the list must be asked at all');
        self::assertSame(
            $args['getCounts'],
            $args['getFollowers'],
            'a total gated differently from the rows renders a count that does not match the page'
        );
    }

    public function testTheCountAndTheFollowingListAreGatedIdentically(): void
    {
        (new UserFollowsService())->listFollowing(self::TARGET, self::VIEWER, 0, 24);

        $args = $this->gateArgsByMethod();

        self::assertArrayHasKey('getCounts', $args);
        self::assertArrayHasKey('getFollowing', $args);
        self::assertSame($args['getCounts'], $args['getFollowing']);
    }

    // ── the gate is actually ON, and off for admins ─────────────────

    public function testAnOrdinaryViewerEnforcesTheGate(): void
    {
        (new UserFollowsService())->listFollowers(self::TARGET, self::VIEWER, 0, 24);

        foreach ($this->gateArgsByMethod() as $method => $args) {
            self::assertSame(self::VIEWER, $args[0], "{$method} must be told who is asking");
            self::assertTrue($args[2], "{$method} must enforce for a non-admin");
        }
    }

    /**
     * Admins pass `false`, because bcc-core's list gates deliberately
     * hold no capability check — an admin read is expressed by the
     * caller. The exemption must match the profile gate's, or an admin
     * sees a row whose profile then answers 403.
     */
    public function testAnAdminViewerTurnsTheGateOff(): void
    {
        $GLOBALS['__bcc_flw']['is_admin'] = true;

        (new UserFollowsService())->listFollowers(self::TARGET, self::VIEWER, 0, 24);

        $args = $this->gateArgsByMethod();
        self::assertNotSame([], $args, 'the repository must still be called for an admin');
        foreach ($args as $method => $a) {
            self::assertFalse($a[2], "{$method} must NOT enforce for an admin");
        }
    }

    // ── the friend list's SHAPE ─────────────────────────────────────

    /**
     * ⚠⚠ A MAP WOULD SILENTLY GATE THE WRONG PEOPLE.
     * `PeepSoFriendGate::friendIdsOf()` returns `[id => true]`, and
     * bcc-core's cleaning loop iterates VALUES:
     * `foreach ($viewerFriendIds as $fid) { $f = (int) $fid; … }`. Hand
     * it the map and every entry becomes `(int) true` = 1, so user 1
     * becomes "a friend" and the real friends are dropped. Nothing
     * throws. `viewerFriendIdList()` exists to convert, and this is the
     * test that it is the converted value which reaches the repository.
     */
    public function testTheRepositoryReceivesAFriendLISTNotAFriendMap(): void
    {
        $GLOBALS['__bcc_flw']['friend_ids'] = [11, 12];

        (new UserFollowsService())->listFollowers(self::TARGET, self::VIEWER, 0, 24);

        foreach ($this->gateArgsByMethod() as $method => $args) {
            self::assertSame([11, 12], $args[1], "{$method} must get a list of ids");
            self::assertNotSame([11 => true, 12 => true], $args[1], "{$method} must not get the map");
        }
    }

    /** The conversion itself, including that it preserves the ids. */
    public function testViewerFriendIdListConvertsTheMapToAList(): void
    {
        $GLOBALS['__bcc_flw']['friend_ids'] = [11, 12, 11];

        self::assertSame([11 => true, 12 => true], PeepSoFriendGate::friendIdsOf(self::VIEWER));
        self::assertSame([11, 12], PeepSoFriendGate::viewerFriendIdList(self::VIEWER));
    }

    /**
     * Control: with no friend graph — production today, since
     * peepso-friends was removed — the list is empty rather than null or
     * a map. bcc-core reads `[]` as "a friend of nobody", which is the
     * intended owner-only resolution for level 30.
     */
    public function testWithNoFriendGraphTheListIsEmpty(): void
    {
        $GLOBALS['__bcc_flw']['friend_ids'] = [];

        (new UserFollowsService())->listFollowers(self::TARGET, self::VIEWER, 0, 24);

        foreach ($this->gateArgsByMethod() as $args) {
            self::assertSame([], $args[1]);
        }
    }
}
