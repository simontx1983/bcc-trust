<?php
/**
 * F-270 / F-323 — which of a member's groups a given viewer may see on
 * that member's profile.
 *
 * This is the policy, so it is tested as a MATRIX rather than one case
 * per rule: the interesting failures are at the intersections (toggle OFF
 * plus a hall; toggle ON plus anonymous plus a holder group; secret plus
 * a signed-in non-member), and a per-rule test suite would pass while an
 * intersection leaked.
 *
 * ⭐ Two of these are CONTROLS rather than confirmations:
 * `testTheOwnerSeesEverything` and `testAnAdminSeesEverything`. Without
 * them, a filter that returned `[]` unconditionally would satisfy every
 * other assertion here — the classic vacuous-gate pass.
 *
 * ⚠ The policy decision itself is the REAL ProfileAccess and the REAL
 * GroupContext; only WordPress, PeepSo's membership table and the admin
 * capability are stubbed. See the stub header.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\ProfileGroupVisibility;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProfileGroupVisibilityTest extends TestCase
{
    private const OWNER     = 11;
    private const VIEWER    = 22;
    private const ANON      = 0;

    private const G_HALL      = 100;
    private const G_PLAIN     = 200;
    private const G_NFT       = 300;
    private const G_VALIDATOR = 400;
    private const G_SECRET    = 500;

    protected function setUp(): void
    {
        parent::setUp();
        // ⛔ Never alongside profile-access-stubs.php or
        // feed-f02-wiring-stubs.php — all three declare Permissions.
        require_once __DIR__ . '/../Stubs/profile-group-visibility-stubs.php';
        $GLOBALS['__bcc_pgv'] = [
            'is_admin'         => false,
            'show_groups'      => [],
            'memberships'      => [],
            'membership_calls' => [],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_pgv']);
        parent::tearDown();
    }

    private static function ctx(int $id, GroupType $type, PeepSoPrivacy $privacy): GroupContext
    {
        return new GroupContext($id, $type, $privacy, false, null, null, null);
    }

    /**
     * One of each kind the policy distinguishes.
     *
     * @return array<int, GroupContext>
     */
    private static function allKinds(): array
    {
        return [
            self::G_HALL      => self::ctx(self::G_HALL, GroupType::Hall, PeepSoPrivacy::Open),
            self::G_PLAIN     => self::ctx(self::G_PLAIN, GroupType::User, PeepSoPrivacy::Closed),
            self::G_NFT       => self::ctx(self::G_NFT, GroupType::Nft, PeepSoPrivacy::Closed),
            self::G_VALIDATOR => self::ctx(self::G_VALIDATOR, GroupType::Validator, PeepSoPrivacy::Closed),
            self::G_SECRET    => self::ctx(self::G_SECRET, GroupType::User, PeepSoPrivacy::Secret),
        ];
    }

    /** @param array<int, GroupContext> $result */
    private static function ids(array $result): array
    {
        return array_values(array_map(static fn(GroupContext $c): int => $c->groupId, $result));
    }

    // ── controls: the gate is not vacuous ───────────────────────────

    /**
     * ⭐ CONTROL. The owner always sees their own groups — they chose the
     * setting and have to be able to see what it hides. Asserted with
     * the toggle explicitly OFF, which is the state that hides the most.
     */
    public function testTheOwnerSeesEverything(): void
    {
        $GLOBALS['__bcc_pgv']['show_groups'] = [self::OWNER => '0'];

        $out = ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::OWNER);

        self::assertSame(
            [self::G_HALL, self::G_PLAIN, self::G_NFT, self::G_VALIDATOR, self::G_SECRET],
            self::ids($out)
        );
    }

    /** ⭐ CONTROL. The F-02 admin exemption reaches this surface too. */
    public function testAnAdminSeesEverything(): void
    {
        $GLOBALS['__bcc_pgv']['is_admin']    = true;
        $GLOBALS['__bcc_pgv']['show_groups'] = [self::OWNER => '0'];

        $out = ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::VIEWER);

        self::assertCount(5, $out);
    }

    // ── the toggle ──────────────────────────────────────────────────

    /**
     * ⚠ THE DEFAULT, and the one permissive default in profile privacy.
     * An untouched account (no meta row at all) keeps listing its groups,
     * which is what "default ON, matching today's behaviour" means.
     *
     * A signed-in viewer therefore sees the hall, the plain group and
     * both on-chain groups; only the secret one is withheld, because the
     * viewer is not a member of it.
     */
    public function testAbsentMetaMeansTheToggleIsOn(): void
    {
        $out = ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::VIEWER);

        self::assertSame(
            [self::G_HALL, self::G_PLAIN, self::G_NFT, self::G_VALIDATOR],
            self::ids($out)
        );
    }

    /**
     * Toggle OFF: halls survive and NOTHING else does, including an
     * ordinary group. Halls are auto-provisioned one-per-chain with open
     * membership, so they disclose nothing — and keeping them is what
     * preserves the home-Hall chip on the profile header.
     */
    public function testToggleOffLeavesOnlyHalls(): void
    {
        $GLOBALS['__bcc_pgv']['show_groups'] = [self::OWNER => '0'];

        $out = ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::VIEWER);

        self::assertSame([self::G_HALL], self::ids($out));
    }

    /** Toggle OFF and anonymous: same answer, halls only. */
    public function testToggleOffLeavesOnlyHallsForAnonymousToo(): void
    {
        $GLOBALS['__bcc_pgv']['show_groups'] = [self::OWNER => '0'];

        $out = ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::ANON);

        self::assertSame([self::G_HALL], self::ids($out));
    }

    /**
     * The toggle reads as a boolean, so an explicit '1' is ON — not just
     * "anything that isn't '0'". Guards against a truthiness slip that
     * would make the stored value meaningless.
     */
    public function testAnExplicitOneIsOn(): void
    {
        $GLOBALS['__bcc_pgv']['show_groups'] = [self::OWNER => '1'];

        self::assertCount(
            4,
            ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::VIEWER)
        );
    }

    // ── on-chain positions are signed-in only ───────────────────────

    /**
     * ⚠⚠ THE HEADLINE OF F-270. Holder and delegator membership discloses
     * an on-chain FINANCIAL position — that this person holds a
     * qualifying NFT on a named chain, that they delegate to a named
     * validator — and the rows carry the chain slug. Anonymous readers
     * never see them, even with the toggle ON.
     *
     * Asserted with the toggle at its permissive default, which is the
     * state in which a missing condition would leak.
     */
    public function testAnonymousNeverSeesHolderOrDelegatorGroups(): void
    {
        $out = ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::ANON);

        self::assertSame([self::G_HALL, self::G_PLAIN], self::ids($out));
    }

    /** The same groups ARE shown once the viewer is signed in. */
    public function testASignedInViewerSeesHolderAndDelegatorGroups(): void
    {
        $out = ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::VIEWER);

        self::assertContains(self::G_NFT, self::ids($out));
        self::assertContains(self::G_VALIDATOR, self::ids($out));
    }

    // ── secret groups (F-323) ───────────────────────────────────────

    /**
     * ⚠⚠ THE F-323 FIX. A secret group is one PeepSo hides from the
     * directory and from non-members everywhere else. Before this, the
     * user view-model's `halls` field published it to any profile
     * reader.
     */
    public function testASignedInNonMemberNeverSeesASecretGroup(): void
    {
        $out = ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::VIEWER);

        self::assertNotContains(self::G_SECRET, self::ids($out));
    }

    /** A fellow member of the secret group does see it. */
    public function testAFellowSecretMemberSeesIt(): void
    {
        $GLOBALS['__bcc_pgv']['memberships'] = [self::G_SECRET => true];

        $out = ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::VIEWER);

        self::assertContains(self::G_SECRET, self::ids($out));
    }

    /**
     * Anonymous is never a member, so the membership query must not even
     * be attempted for them — and the secret group is still dropped.
     */
    public function testAnonymousIsNeverAskedAboutMembership(): void
    {
        $out = ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::ANON);

        self::assertNotContains(self::G_SECRET, self::ids($out));
        self::assertSame([], $GLOBALS['__bcc_pgv']['membership_calls']);
    }

    /**
     * ⚠ FAIL-CLOSED. An empty membership map — whether the viewer really
     * is in none of them or the query failed — drops every secret group.
     * Hiding one the viewer could have seen is the harmless direction.
     */
    public function testAnEmptyMembershipAnswerDropsEverySecretGroup(): void
    {
        $contexts = [
            self::G_SECRET => self::ctx(self::G_SECRET, GroupType::User, PeepSoPrivacy::Secret),
            600            => self::ctx(600, GroupType::User, PeepSoPrivacy::Secret),
        ];

        self::assertSame(
            [],
            ProfileGroupVisibility::filter($contexts, self::OWNER, self::VIEWER)
        );
    }

    // ── the membership query itself ─────────────────────────────────

    /**
     * ⚠⚠ THE QUERY ASKS ABOUT THE VIEWER, NEVER THE OWNER. Passing the
     * owner's id would answer "is the owner in their own secret group",
     * which is always yes — so every secret group would be published to
     * everyone. The bug would be invisible to a result-only assertion
     * because the result would look generous rather than wrong.
     */
    public function testTheMembershipQueryAsksAboutTheViewer(): void
    {
        ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::VIEWER);

        $calls = $GLOBALS['__bcc_pgv']['membership_calls'];
        self::assertCount(1, $calls, 'one batched read, not one per group');
        self::assertSame(self::VIEWER, $calls[0]['viewer']);
    }

    /**
     * The in-memory drops run BEFORE the query, so it is asked only
     * about secret groups that actually survived them. With the toggle
     * OFF nothing but halls survives, so there is no query at all.
     */
    public function testTheQueryOnlySeesSurvivingSecretGroups(): void
    {
        $GLOBALS['__bcc_pgv']['show_groups'] = [self::OWNER => '0'];

        ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::VIEWER);

        self::assertSame(
            [],
            $GLOBALS['__bcc_pgv']['membership_calls'],
            'a secret group already dropped by the toggle must not be queried'
        );
    }

    /** Asked about exactly the secret ids, not every group on the profile. */
    public function testTheQueryIsScopedToSecretGroupIds(): void
    {
        ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::VIEWER);

        self::assertSame(
            [self::G_SECRET],
            $GLOBALS['__bcc_pgv']['membership_calls'][0]['ids']
        );
    }

    // ── degenerate inputs ───────────────────────────────────────────

    /** An empty profile is an empty list, never an error. */
    public function testNoGroupsIsAnEmptyList(): void
    {
        self::assertSame([], ProfileGroupVisibility::filter([], self::OWNER, self::VIEWER));
    }

    /**
     * A zero owner id cannot be privacy-classified, so it resolves to
     * nothing rather than to everything.
     */
    public function testAZeroOwnerYieldsNothing(): void
    {
        self::assertSame([], ProfileGroupVisibility::filter(self::allKinds(), 0, self::VIEWER));
    }

    /** Keys are preserved, so callers can intersect by group id. */
    public function testKeysArePreserved(): void
    {
        $out = ProfileGroupVisibility::filter(self::allKinds(), self::OWNER, self::VIEWER);

        self::assertArrayHasKey(self::G_HALL, $out);
        self::assertSame(self::G_HALL, $out[self::G_HALL]->groupId);
    }
}
