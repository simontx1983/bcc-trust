<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit\BlogTabGroupVisibility;

use BCC\Core\Log\Logger;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Services\BlogService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The profile Blog tab (`GET /users/{handle}/blog`) once a blog post can
 * live inside a PeepSo group.
 *
 * SCOPE, stated plainly: these tests pin WHICH GATE INPUTS BlogService
 * hands the repository, not whether a row is filtered. The filtering is
 * SQL and is pinned in bcc-core's own suite against a rendering `$wpdb`
 * double. Asserting "a non-member cannot see it" here would mean
 * re-implementing the predicate inside a stub and then testing the stub —
 * a test that passes because it agrees with itself. What bcc-trust is
 * actually responsible for is the three decisions below, and a mistake in
 * any one of them is how a leak would start:
 *
 *   1. null, never [], when the non-open set cannot be PROVEN.
 *   2. the author-scoped mode only for the signed-in owner of the profile.
 *   3. the viewer taken from the SESSION, never from the argument.
 *
 * Separate processes because every stubbed class here is also stubbed by
 * other Stubs/ files, and PHPUnit loads all test files into one process.
 */
#[CoversClass(BlogService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BlogTabGroupVisibilityTest extends TestCase
{
    private const OWNER   = 42;
    private const VISITOR = 7;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/blog-group-visibility-stubs.php';
        PeepSoActivityRepository::reset();
        PeepSoGroupRepository::reset();
        Logger::reset();
        HiddenActivityRepository::$hidden = [];
        unset($GLOBALS['bcc_test_session_user']);
    }

    private function session(int $userId): void
    {
        $GLOBALS['bcc_test_session_user'] = $userId;
    }

    private function service(): BlogService
    {
        return new BlogService(new HiddenActivityRepository());
    }

    /** @return array<string, mixed> */
    private function lastCall(): array
    {
        self::assertNotSame([], PeepSoActivityRepository::$calls, 'the repository was never called');

        return PeepSoActivityRepository::$calls[count(PeepSoActivityRepository::$calls) - 1];
    }

    // ── 1. Degradation must send null, never [] ──────────────────────

    /**
     * THE M1 PIN. When the non-open lookup cannot answer — a failed query
     * or a truncated one — the strict lookup returns null, and this caller
     * must forward null and ask for NO mode. Forwarding [] would assert
     * "this viewer is restricted from nothing" and relax the gate with
     * nothing behind it, publishing every group blog on the profile.
     */
    public function testADegradedLookupSendsNullAndNoModeSoTheGateStays(): void
    {
        $this->session(self::VISITOR);
        PeepSoGroupRepository::$strictNonOpen = null; // cannot prove

        $this->service()->getUserBlog(self::OWNER, self::VISITOR);

        $call = $this->lastCall();
        self::assertNull($call['restrictedGroupIds'], 'a degraded lookup must send null, never []');
        self::assertNull($call['groupGateMode'], 'no mode means the global public_all gate stays');
        self::assertNull($call['viewerMemberGroupIds']);
    }

    /**
     * A PROVEN-empty non-open set is different from a degraded one: the
     * query ran and nothing on the site is non-open, so [] is honest and
     * the viewer-access rule still applies.
     */
    public function testAProvenEmptyNonOpenSetSendsAnEmptyListWithViewerAccess(): void
    {
        $this->session(self::VISITOR);
        PeepSoGroupRepository::$strictNonOpen = [];

        $this->service()->getUserBlog(self::OWNER, self::VISITOR);

        $call = $this->lastCall();
        self::assertSame([], $call['restrictedGroupIds']);
        self::assertSame(PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, $call['groupGateMode']);
    }

    public function testTheDenyListIsNonOpenMinusTheViewersMemberships(): void
    {
        $this->session(self::VISITOR);
        PeepSoGroupRepository::$strictNonOpen = [3, 4, 5];
        PeepSoGroupRepository::$memberships   = [4];

        $this->service()->getUserBlog(self::OWNER, self::VISITOR);

        $call = $this->lastCall();
        self::assertSame([3, 5], array_values((array) $call['restrictedGroupIds']));
        self::assertSame([4], $call['viewerMemberGroupIds'], 'parity needs the memberships too');
    }

    /**
     * The STRICT lookup must be the one consulted. The lenient stub returns
     * a sentinel the strict one never does, so reaching for the wrong seam
     * shows up here instead of silently under-restricting in production.
     */
    public function testTheStrictLookupIsTheOneConsulted(): void
    {
        $this->session(self::VISITOR);
        PeepSoGroupRepository::$strictNonOpen = [3];

        $this->service()->getUserBlog(self::OWNER, self::VISITOR);

        $call = $this->lastCall();
        self::assertSame(1, PeepSoGroupRepository::$strictCalls);
        self::assertNotContains(999999, (array) $call['restrictedGroupIds'], 'that is the lenient sentinel');
    }

    // ── 2. Author scope belongs to the owner alone ───────────────────

    /**
     * The author reading their own tab: author-scoped, no deny list
     * consulted, and the viewer id carried so bcc-core can PROVE the
     * claim. This is also what keeps their own group blogs visible to them
     * after they leave the group.
     */
    public function testTheSignedInOwnerGetsAuthorScope(): void
    {
        $this->session(self::OWNER);
        PeepSoGroupRepository::$strictNonOpen = [3, 4];

        $this->service()->getUserBlog(self::OWNER, self::OWNER);

        $call = $this->lastCall();
        self::assertSame(PeepSoActivityRepository::GROUP_GATE_AUTHOR_SCOPED, $call['groupGateMode']);
        self::assertSame([], $call['restrictedGroupIds']);
        self::assertSame(self::OWNER, $call['viewerId'], 'bcc-core proves the claim against this');
        self::assertSame([self::OWNER], $call['authorIds']);
    }

    /**
     * A DIFFERENT signed-in viewer must never reach author scope.
     */
    public function testANonOwnerViewerNeverReachesAuthorScope(): void
    {
        $this->session(self::VISITOR);
        PeepSoGroupRepository::$strictNonOpen = [3];

        $this->service()->getUserBlog(self::OWNER, self::VISITOR);

        $call = $this->lastCall();
        self::assertNotSame(PeepSoActivityRepository::GROUP_GATE_AUTHOR_SCOPED, $call['groupGateMode']);
        self::assertSame(PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, $call['groupGateMode']);
        self::assertSame(self::VISITOR, $call['viewerId']);
    }

    /**
     * An ANONYMOUS reader must never reach author scope either, including
     * on an anonymous read of user 0's profile.
     */
    public function testAnAnonymousViewerNeverReachesAuthorScope(): void
    {
        $this->session(0);
        PeepSoGroupRepository::$strictNonOpen = [3];

        $this->service()->getUserBlog(self::OWNER, 0);

        $call = $this->lastCall();
        self::assertNotSame(PeepSoActivityRepository::GROUP_GATE_AUTHOR_SCOPED, $call['groupGateMode']);
        self::assertSame(0, $call['viewerId']);
        self::assertSame([], $call['viewerMemberGroupIds'], 'anonymous belongs to nothing');
    }

    // ── 3. The viewer comes from the session, not the argument ───────

    /**
     * THE PREDICTED BUG, PINNED. bcc-core's author-scope proof compares two
     * ids the caller supplies, so it can only prove the caller is
     * self-consistent. A caller passing the PROFILE OWNER as the viewer
     * would make them agree — and every visitor to that profile would get
     * the owner's own unrestricted view. BlogService therefore reads the
     * session and IGNORES the argument.
     */
    public function testPassingTheProfileOwnerAsTheViewerDoesNotGrantAuthorScope(): void
    {
        $this->session(self::VISITOR);              // who is really signed in
        PeepSoGroupRepository::$strictNonOpen = [3];

        // A caller wrongly forwards the profile owner's id as the viewer.
        $this->service()->getUserBlog(self::OWNER, self::OWNER);

        $call = $this->lastCall();
        self::assertNotSame(
            PeepSoActivityRepository::GROUP_GATE_AUTHOR_SCOPED,
            $call['groupGateMode'],
            'the argument must not be able to grant author scope'
        );
        self::assertSame(self::VISITOR, $call['viewerId'], 'the session wins');
    }

    /**
     * An anonymous session with an authenticated-looking argument is the
     * same bug from the other direction.
     */
    public function testAnArgumentCannotInventAViewerForAnAnonymousSession(): void
    {
        $this->session(0);
        PeepSoGroupRepository::$strictNonOpen = [3];
        PeepSoGroupRepository::$memberships   = [3];

        $this->service()->getUserBlog(self::OWNER, self::VISITOR);

        $call = $this->lastCall();
        self::assertSame(0, $call['viewerId']);
        // Memberships are resolved for the SESSION viewer (0 → none), so
        // the restricted group is not quietly lifted by the argument.
        self::assertSame([3], array_values((array) $call['restrictedGroupIds']));
        self::assertSame([], $call['viewerMemberGroupIds']);
    }

    /**
     * Disagreement is REPORTED. The gate silently does the right thing, so
     * without this the caller bug would be invisible.
     */
    public function testAMismatchedViewerArgumentIsLogged(): void
    {
        $this->session(self::VISITOR);

        $this->service()->getUserBlog(self::OWNER, self::OWNER);

        self::assertCount(1, Logger::$warnings);
        self::assertStringContainsString('viewer argument ignored', Logger::$warnings[0][0]);
        self::assertSame(self::OWNER, Logger::$warnings[0][1]['argument_viewer_id']);
        self::assertSame(self::VISITOR, Logger::$warnings[0][1]['session_viewer_id']);
    }

    public function testAMatchingViewerArgumentLogsNothing(): void
    {
        $this->session(self::VISITOR);

        $this->service()->getUserBlog(self::OWNER, self::VISITOR);

        self::assertSame([], Logger::$warnings);
    }

    // ── Shape guarantees that must not regress ───────────────────────

    /**
     * The INERT `$excludedGroupIds` must stay untouched. Passing the deny
     * list there would filter nothing — the trap bcc-core documents.
     */
    public function testTheInertExcludedGroupIdsParamIsNeverUsed(): void
    {
        $this->session(self::VISITOR);
        PeepSoGroupRepository::$strictNonOpen = [3];

        $this->service()->getUserBlog(self::OWNER, self::VISITOR);

        self::assertNull($this->lastCall()['excludedGroupIds']);
    }

    /**
     * This is the profile tab, not a group feed: the read must stay on the
     * global path, and it must still over-fetch by one so has_more is
     * truthful AFTER the group filter has been applied in SQL.
     */
    public function testTheReadStaysGlobalAndStillOverFetchesByOne(): void
    {
        $this->session(self::VISITOR);
        PeepSoGroupRepository::$strictNonOpen = [];

        $this->service()->getUserBlog(self::OWNER, self::VISITOR, null, 5);

        $call = $this->lastCall();
        self::assertNull($call['onlyForGroupId']);
        self::assertSame(6, $call['limit']);
        self::assertSame(['blog'], $call['moduleIds']);
    }
}
