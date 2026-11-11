<?php
/**
 * ProfileAccess — the §F-02 profile-visibility gate.
 *
 * What this suite is actually for: proving the gate's OWN guards, and
 * proving it can answer BOTH ways. A gate that always denied would be a
 * catastrophic regression (every profile 403s) and would pass every
 * deny-path assertion, so the allow-path test below is an anti-vacuity
 * control, not a nicety.
 *
 * PeepSo's ladder itself (owner-exempt, admin-exempt, 10/20/40, and the
 * `peepso_user_is_accessible` filter where peepso-friends grants 30) is
 * deliberately NOT reimplemented or re-tested here. It is PeepSo's, and
 * restating it in a stub would only assert that the stub matches the
 * stub. What IS tested is that ProfileAccess delegates to it with the
 * right arguments, and refuses before reaching it when it must.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\ProfileAccess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProfileAccessTest extends TestCase
{
    private const OWNER = 42;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/profile-access-stubs.php';
        $GLOBALS['__bcc_profile_access'] = [
            'levels'   => [],
            'verdicts' => [],
            'calls'    => [],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_profile_access']);
        parent::tearDown();
    }

    /** @return list<array{acc: mixed, user_id: int}> */
    private function calls(): array
    {
        $c = $GLOBALS['__bcc_profile_access']['calls'] ?? [];
        return is_array($c) ? $c : [];
    }

    // ── the allow path: the anti-vacuity control ────────────────────

    public function testAllowsWhenPeepSoReportsTheProfileAccessible(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => 10];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [10 => true];

        self::assertTrue(
            ProfileAccess::canViewProfile(self::OWNER),
            'the gate must be able to ALLOW — without this every other '
            . 'assertion here would also pass against a hardcoded deny-all'
        );
    }

    public function testDeniesWhenPeepSoReportsTheProfileInaccessible(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => 40];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [40 => false];

        self::assertFalse(ProfileAccess::canViewProfile(self::OWNER));
    }

    public function testPassesTheStoredLevelAndOwnerThroughToPeepSo(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => 30];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [30 => true];

        ProfileAccess::canViewProfile(self::OWNER);

        // Delegation is the whole design: if the arguments were wrong,
        // the gate would consult PeepSo about the wrong user or the
        // wrong level and still return a plausible boolean.
        self::assertSame(
            [['acc' => 30, 'user_id' => self::OWNER]],
            $this->calls(),
            'the stored level and the profile owner must reach PeepSo verbatim'
        );
    }

    // ── guards that must refuse BEFORE consulting PeepSo ────────────

    public function testZeroOwnerIdDeniesWithoutConsultingPeepSo(): void
    {
        $GLOBALS['__bcc_profile_access']['verdicts'] = [10 => true];

        self::assertFalse(ProfileAccess::canViewProfile(0));
        self::assertSame(
            [],
            $this->calls(),
            'a non-resolving user must not reach PeepSo at all'
        );
    }

    public function testNegativeOwnerIdDeniesWithoutConsultingPeepSo(): void
    {
        $GLOBALS['__bcc_profile_access']['verdicts'] = [10 => true];

        self::assertFalse(ProfileAccess::canViewProfile(-7));
        self::assertSame([], $this->calls());
    }

    /**
     * The stored column is read through PeepSo and comes back as
     * whatever is in the row. A non-numeric value means we do not know
     * the level, and not knowing must DENY — never fall through to a
     * default that happens to be public.
     */
    public function testNonNumericStoredLevelDeniesWithoutConsultingPeepSo(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => 'nonsense'];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [0 => true, 10 => true];

        self::assertFalse(
            ProfileAccess::canViewProfile(self::OWNER),
            'an unreadable level must fail CLOSED'
        );
        self::assertSame(
            [],
            $this->calls(),
            'the numeric guard must run before PeepSo is consulted — '
            . 'otherwise "nonsense" would be cast to 0 and 0 is PUBLIC '
            . 'on PeepSo\'s ladder, turning a broken row into an open profile'
        );
    }

    public function testNullStoredLevelDeniesWithoutConsultingPeepSo(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => null];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [0 => true];

        self::assertFalse(ProfileAccess::canViewProfile(self::OWNER));
        self::assertSame([], $this->calls());
    }

    /**
     * A numeric STRING is how a DB column legitimately arrives, so it
     * must be honoured rather than swept into the fail-closed branch.
     * Paired with the test above, this pins the boundary: numeric-ish
     * passes through, non-numeric denies.
     */
    public function testNumericStringLevelIsHonouredAndForwardedAsInt(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => '20'];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [20 => true];

        self::assertTrue(ProfileAccess::canViewProfile(self::OWNER));
        self::assertSame([['acc' => 20, 'user_id' => self::OWNER]], $this->calls());
    }

    // ── the refusal envelope is part of the §9 contract ─────────────

    public function testForbiddenCarriesTheContractedCodeStatusAndReason(): void
    {
        $response = ProfileAccess::forbidden();

        self::assertSame(403, $response->get_status());

        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertArrayHasKey('error', $data);
        self::assertIsArray($data['error']);
        self::assertSame('bcc_forbidden', $data['error']['code']);
        self::assertSame(403, $data['error']['status']);

        // The machine-readable reason is what lets the frontend render
        // "this profile is private" instead of a generic permission
        // error, so it is contract surface, not a log detail.
        self::assertArrayHasKey('data', $data['error']);
        self::assertIsArray($data['error']['data']);
        self::assertSame('profile_private', $data['error']['data']['reason']);
        self::assertSame(ProfileAccess::REASON_PRIVATE, $data['error']['data']['reason']);
    }

    // ── §F-182: a suspended member's profile is hidden from others ───
    //
    // ⚠ Every test ABOVE now also traverses the suspension branch, with
    // the fixture's "nobody suspended" default. That is what keeps the
    // allow-path control above meaningful for this feature too: if the
    // branch denied unconditionally, that test would fail.

    private function suspensionCalls(): array
    {
        $c = $GLOBALS['__bcc_profile_access']['susp_calls'] ?? [];
        return is_array($c) ? $c : [];
    }

    /** Make the profile itself unambiguously public, so only suspension can hide it. */
    private function givenAPublicProfile(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => 10];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [10 => true];
    }

    public function testHidesASuspendedMembersProfileFromAnotherViewer(): void
    {
        $this->givenAPublicProfile();
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 7;
        $GLOBALS['__bcc_profile_access']['suspended'] = [self::OWNER => true];

        self::assertFalse(
            ProfileAccess::canViewProfile(self::OWNER),
            'a suspended account must not serve its profile to a stranger, '
            . 'even though the profile itself is public'
        );
    }

    public function testTheMemberStillSeesTheirOwnSuspendedProfile(): void
    {
        $this->givenAPublicProfile();
        $GLOBALS['__bcc_profile_access']['viewer_id'] = self::OWNER;
        $GLOBALS['__bcc_profile_access']['suspended'] = [self::OWNER => true];

        self::assertTrue(
            ProfileAccess::canViewProfile(self::OWNER),
            'a suspension restricts what someone may DO; it must not hide '
            . 'their own account from them'
        );
        self::assertSame(
            [],
            $this->suspensionCalls(),
            'the owner exemption must short-circuit BEFORE bcc-core is asked'
        );
    }

    public function testAnAdminStillSeesASuspendedProfile(): void
    {
        $this->givenAPublicProfile();
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 7;
        $GLOBALS['__bcc_profile_access']['is_admin']  = true;
        $GLOBALS['__bcc_profile_access']['suspended'] = [self::OWNER => true];

        self::assertTrue(
            ProfileAccess::canViewProfile(self::OWNER),
            'admins are the only people for whom a moderated account must '
            . 'stay reviewable'
        );
        self::assertSame(
            [],
            $this->suspensionCalls(),
            'the admin exemption must short-circuit BEFORE bcc-core is asked'
        );
    }

    /**
     * The bug this guards against, and the reason the stub reproduces
     * bcc-core's `$user_id ?: get_current_user_id()` fallback: ask about
     * the VIEWER instead of the TARGET and the feature inverts — a
     * suspended viewer loses everyone's profile, while a suspended
     * member's profile stays public to everyone. Both return plausible
     * booleans, so only the identity of the id proves which happened.
     */
    public function testTheSuspensionQuestionIsAskedAboutTheTargetNotTheViewer(): void
    {
        $this->givenAPublicProfile();
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 7;
        $GLOBALS['__bcc_profile_access']['suspended'] = [7 => true];

        self::assertTrue(
            ProfileAccess::canViewProfile(self::OWNER),
            "a suspended VIEWER still reads other members' profiles — this "
            . 'gate hides the suspended SUBJECT, not the suspended reader'
        );
        self::assertSame(
            [self::OWNER],
            array_column($this->suspensionCalls(), 'user_id'),
            'bcc-core must be asked about the profile owner, exactly once'
        );
    }

    /**
     * Admin bypass is left at bcc-core's default ON, and that is a
     * decision rather than an omission: it concerns the SUBJECT (a
     * suspended admin's own profile stays visible), and turning it off
     * would make bcc-core log "Suspended admin blocked from dispute
     * participation" every time anyone opened such a profile.
     */
    public function testTheSuspensionCheckLeavesAdminBypassAtItsDefault(): void
    {
        $this->givenAPublicProfile();
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 7;

        ProfileAccess::canViewProfile(self::OWNER);

        self::assertSame(
            [true],
            array_column($this->suspensionCalls(), 'bypass'),
            'passing false here would change who is hidden AND emit a '
            . 'dispute-participation log line on a read path'
        );
    }

    /**
     * Ordering guard. bcc-core resolves a falsy `$user_id` to
     * `get_current_user_id()`, so reaching it with 0 would silently
     * answer a question about the viewer. The id guard must win first.
     */
    public function testANonPositiveOwnerIdNeverReachesTheSuspensionCheck(): void
    {
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 7;
        $GLOBALS['__bcc_profile_access']['suspended'] = [7 => true];

        self::assertFalse(ProfileAccess::canViewProfile(0));
        self::assertFalse(ProfileAccess::canViewProfile(-1));
        self::assertSame([], $this->suspensionCalls());
    }

    /**
     * Control: suspension must not become the only thing the gate looks
     * at. A private profile stays private even for a member in perfectly
     * good standing — otherwise F-182 would have quietly replaced F-02.
     */
    public function testAPrivateProfileIsStillPrivateWhenTheMemberIsNotSuspended(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']    = [self::OWNER => 40];
        $GLOBALS['__bcc_profile_access']['verdicts']  = [40 => false];
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 7;

        self::assertFalse(ProfileAccess::canViewProfile(self::OWNER));
    }

    // ── §F-182 part 2: the same exclusion in user LISTINGS ──────────
    //
    // The gate above hides the profile; without this the member
    // directory still lists the person, so a viewer sees a row they
    // cannot open. These tests measure the SQL the filter appends,
    // because that is the whole behaviour — there is no return value.

    /**
     * `filterSuspendedFromListing()` reads the global $wpdb, so the
     * double is installed here rather than in setUp(): every test above
     * must keep running without one, which is itself a guarantee that
     * the profile gate does no SQL.
     *
     * @param array<string, mixed> $queryVars
     */
    private function runListingFilter(array $queryVars): \WP_User_Query
    {
        $GLOBALS['wpdb'] = new \BCC_F182_Wpdb();

        $query = new \WP_User_Query($queryVars);
        ProfileAccess::filterSuspendedFromListing($query);

        return $query;
    }

    public function testTheListingFilterIsRegisteredOnPreUserQuery(): void
    {
        $GLOBALS['__bcc_profile_access']['hooks'] = [];

        ProfileAccess::registerListingFilter();

        self::assertSame(
            [[
                'hook'     => 'pre_user_query',
                'priority' => 20,
                'accepted' => 1,
                'callback' => ProfileAccess::class . '::filterSuspendedFromListing',
            ]],
            $GLOBALS['__bcc_profile_access']['hooks'],
            'the hook name is the behaviour: registered on the wrong one, '
            . 'or in registerRoutes(), and no listing is ever filtered'
        );
    }

    public function testASuspendedMemberIsExcludedFromAPeepSoListing(): void
    {
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 7;

        $query = $this->runListingFilter(['_peepso_user_id' => 7]);

        self::assertStringContainsString(
            'wp_bcc_trust_user_info',
            $query->query_from,
            'the filter must join the SAME column the profile gate reads'
        );
        self::assertStringContainsString('is_suspended', $query->query_where);
    }

    /**
     * A LEFT JOIN, and the reason is the fail-open decision: a member
     * with no `user_info` row is NOT suspended and must stay listed. An
     * INNER JOIN would silently drop every member who has never been
     * scored — almost certainly most of them — and the listing would
     * still look plausible.
     */
    public function testTheJoinIsLeftSoAMemberWithNoRowStaysListed(): void
    {
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 7;

        $query = $this->runListingFilter(['_peepso_user_id' => 7]);

        self::assertStringContainsString('LEFT JOIN', $query->query_from);
        self::assertStringNotContainsString('INNER JOIN', $query->query_from);
        self::assertStringContainsString(
            'is_suspended IS NULL',
            $query->query_where,
            'the NULL arm is what keeps an unscored member visible'
        );
    }

    public function testTheViewerStillSeesThemselvesInAListing(): void
    {
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 7;

        $query = $this->runListingFilter(['_peepso_user_id' => 7]);

        self::assertStringContainsString(
            'wp_users.ID = 7',
            $query->query_where,
            "the viewer's own id must be rendered into the clause, or a "
            . 'suspended member vanishes from their own directory results'
        );
    }

    public function testANonPeepSoUserQueryIsLeftCompletelyAlone(): void
    {
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 7;

        // No `_peepso_user_id`: wp-admin's Users screen, or any other
        // plain WP_User_Query. Narrowing to PeepSo's own queries is what
        // keeps this filter off admin screens.
        $query = $this->runListingFilter(['number' => 20]);

        self::assertSame('FROM wp_users', $query->query_from);
        self::assertSame('WHERE 1=1', $query->query_where);
    }

    /**
     * `pre_user_query` is not guaranteed to fire once per query —
     * `WP_User_Query::prepare_query()` can run again on the same object.
     * A second copy of the join is not a cosmetic duplicate: MySQL
     * rejects the whole statement with "Not unique table/alias", so the
     * listing fails hard rather than returning slightly wrong rows.
     */
    public function testFilteringTheSameQueryTwiceAppendsTheJoinOnlyOnce(): void
    {
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 7;

        $GLOBALS['wpdb'] = new \BCC_F182_Wpdb();
        $query = new \WP_User_Query(['_peepso_user_id' => 7]);

        ProfileAccess::filterSuspendedFromListing($query);
        $afterFirst = [$query->query_from, $query->query_where];
        ProfileAccess::filterSuspendedFromListing($query);

        // ⚠ Count 'LEFT JOIN', not the alias: one append contains the
        // alias TWICE (its declaration and the ON clause), so counting
        // the alias reads 2 after a single, correct append.
        self::assertSame(
            1,
            substr_count($query->query_from, 'LEFT JOIN'),
            'a second firing must not add a second join'
        );
        self::assertSame(
            $afterFirst,
            [$query->query_from, $query->query_where],
            'the second firing must be a complete no-op, WHERE arm included'
        );
    }

    public function testAnAdminSeesSuspendedMembersInListingsToo(): void
    {
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 7;
        $GLOBALS['__bcc_profile_access']['is_admin']  = true;

        $query = $this->runListingFilter(['_peepso_user_id' => 7]);

        self::assertSame(
            'FROM wp_users',
            $query->query_from,
            'the admin exemption must match the profile gate: an admin who '
            . 'can open a suspended profile must also see its row'
        );
        self::assertSame('WHERE 1=1', $query->query_where);
    }
}
