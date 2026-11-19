<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\FeedItemReportVisibility;
use BCC\Trust\Tests\Stubs\PublicOnlyFakes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-346 — "only PUBLIC posts may be reposted or quoted" (user decision
 * 2026-11-18, X's protected-post rule).
 *
 * ⭐ THIS is the suite that tests the RULE. `RepostEndpointTest` doubles the
 * predicate, so it can only show that the route asks the question, in the
 * right order, and honours the answer — it says nothing about whether the
 * answer is right. Both halves are needed and neither is sufficient.
 *
 * The real `GroupSyndicationGate` and the real rung snapping run here; only
 * post meta, the non-open group list and PeepSo's profile reader are doubled.
 *
 * ⚠ The EXPECTED column is written from the decision ("visible to every
 * member"), not derived from the implementation: 10 and 0 are everyone, 20 is
 * every signed-in member, 30 is friends-only, 40 is nobody — so 10 and 20
 * pass and 30 and 40 do not, and a group post passes only when it is
 * `public_all` AND still syndicated.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RepostPublicOnlyGateTest extends TestCase
{
    private const AUTHOR = 42;
    private const ACT    = 700;
    private const POST   = 5700;
    private const GROUP  = 900;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/public-only-gate-stubs.php';
        // The REAL syndication gate, so this suite fails if its rule moves.
        if (!\class_exists('BCC\\Core\\Feed\\GroupSyndicationGate', false)) {
            require_once dirname(__DIR__, 2) . '/../bcc-core/src/Feed/GroupSyndicationGate.php';
        }
        PublicOnlyFakes::reset();
    }

    /** A getById()-shaped row. */
    private static function row(int $actAccess): object
    {
        return (object) [
            'act_id'          => self::ACT,
            'act_user_id'     => self::AUTHOR,
            'act_external_id' => self::POST,
            'act_access'      => $actAccess,
            'act_status'      => 'publish',
        ];
    }

    // ── §F-02: the effective level is the STRICTER of two values ─────

    /** @return list<array{0: int, 1: int|string|null, 2: bool, 3: string}> */
    public static function ladderProvider(): array
    {
        return [
            // post access, raw author rung, expected, why
            [10, 10, true,  'public post, public author'],
            [0,  10, true,  'act_access 0 normalises to public, as the SQL does'],
            [20, 10, true,  'members_only is the DEFAULT audience and every member sees it'],
            [10, 20, true,  'a members-only author does not narrow below "every member"'],
            [20, 20, true,  'both at the members rung'],
            [30, 10, false, 'friends-only post excludes members'],
            [40, 10, false, 'only-me post'],
            [10, 30, false, 'a FRIENDS-only author narrows a public post — the GREATEST rule'],
            [10, 40, false, 'an only-me author hides even a public post'],
            [20, 30, false, 'stricter of the two wins'],
        ];
    }

    #[DataProvider('ladderProvider')]
    public function testTheEffectiveLevelDecides(int $actAccess, int|string|null $authorAcc, bool $expected, string $why): void
    {
        PublicOnlyFakes::$profileAcc[self::AUTHOR] = $authorAcc;

        self::assertSame(
            $expected,
            FeedItemReportVisibility::isPublicToEveryMember(self::row($actAccess)),
            $why
        );
    }

    /**
     * ⭐ STRAY COLUMN VALUES — asserted at the SEAM THAT SNAPS THEM, not
     * through the predicate.
     *
     * `usr_profile_acc` is a TINYINT nobody validates (PeepSo writes it
     * straight from request input), so off-rung values exist, and
     * `canonicalProfileLevelFor()` sends them to the nearest STRICTER rung.
     *
     * ⚠⚠ THIS IS TESTED HERE RATHER THAN THROUGH
     * `isPublicToEveryMember()` BECAUSE IT CANNOT BE TESTED THROUGH IT.
     * Snapping moves 11-20 to 20 and 21-30 to 30, so it never crosses the
     * `<= 20` boundary that predicate tests: `raw <= 20` and `snapped <= 20`
     * agree on every input. A mutation reading the rung RAW therefore
     * SURVIVES the predicate tests — measured, and equivalent rather than
     * uncovered. The snapping is still worth asserting, because it is what
     * keeps a FUTURE comparison (an `=== 10`, or `is_accessible_static()`,
     * which has no `case 15`) safe from an unvalidated column.
     */
    public function testOffRungAuthorLevelsSnapToTheNearestStricterRung(): void
    {
        foreach ([0 => 10, 9 => 10, 10 => 10, 11 => 20, 15 => 20, 20 => 20,
                  21 => 30, 25 => 30, 30 => 30, 31 => 40, 127 => 40, -5 => 10] as $raw => $expected) {
            PublicOnlyFakes::$profileAcc[self::AUTHOR] = $raw;

            self::assertSame(
                $expected,
                \BCC\Trust\Core\Support\ProfileAccess::canonicalProfileLevelFor(self::AUTHOR),
                "raw {$raw} must snap to {$expected}"
            );
        }
    }

    /**
     * And the boundary the predicate itself cares about: an off-rung value
     * either side of the members rung still lands on the right answer.
     */
    public function testTheMembersBoundaryHoldsForOffRungAuthorLevels(): void
    {
        PublicOnlyFakes::$profileAcc[self::AUTHOR] = 15;
        self::assertTrue(FeedItemReportVisibility::isPublicToEveryMember(self::row(10)), '15 is within "every member"');

        PublicOnlyFakes::$profileAcc[self::AUTHOR] = 21;
        self::assertFalse(FeedItemReportVisibility::isPublicToEveryMember(self::row(10)), '21 is past it');
    }

    // ── fail-closed ──────────────────────────────────────────────────

    /**
     * ⚠ An author rung that cannot be READ is not a level. A post whose
     * reach cannot be established must not be republished — answering 40
     * instead would be indistinguishable from a deliberate "only me", and
     * answering 10 would be a fail-open.
     */
    public function testAnUnreadableAuthorRungRefuses(): void
    {
        PublicOnlyFakes::$peepSoUserLoaded = false;
        self::assertFalse(FeedItemReportVisibility::isPublicToEveryMember(self::row(10)), 'PeepSo absent');

        PublicOnlyFakes::$peepSoUserLoaded         = true;
        PublicOnlyFakes::$profileAcc[self::AUTHOR] = 'not-a-number';
        self::assertFalse(FeedItemReportVisibility::isPublicToEveryMember(self::row(10)), 'non-numeric column');
    }

    public function testARowWithoutAPostOrAuthorRefuses(): void
    {
        self::assertFalse(FeedItemReportVisibility::isPublicToEveryMember(
            (object) ['act_id' => self::ACT, 'act_user_id' => self::AUTHOR, 'act_external_id' => 0, 'act_access' => 10]
        ), 'no backing post');

        self::assertFalse(FeedItemReportVisibility::isPublicToEveryMember(
            (object) ['act_id' => self::ACT, 'act_user_id' => 0, 'act_external_id' => self::POST, 'act_access' => 10]
        ), 'no author');
    }

    // ── the GROUP half ───────────────────────────────────────────────

    /**
     * A group-tagged post is public only when it is explicitly `public_all`.
     * `members_only` and `public_group` are the group's own audiences — a
     * closed or secret room's post is exactly what this refuses.
     */
    public function testAGroupPostIsPublicOnlyWhenMarkedPublicAll(): void
    {
        PublicOnlyFakes::$postMeta[self::POST] = ['peepso_group_id' => [self::GROUP]];

        foreach (['', 'members_only', 'public_group'] as $visibility) {
            PublicOnlyFakes::$postMeta[self::POST]['_bcc_post_visibility'] = $visibility;
            self::assertFalse(
                FeedItemReportVisibility::isPublicToEveryMember(self::row(10)),
                "group post marked '{$visibility}' is not public"
            );
        }

        PublicOnlyFakes::$postMeta[self::POST]['_bcc_post_visibility'] = 'public_all';
        self::assertTrue(
            FeedItemReportVisibility::isPublicToEveryMember(self::row(10)),
            'public_all in an open group is public'
        );
    }

    /**
     * ⚠⚠ `public_all` IS NOT A FREE PASS. The syndication gate still has to
     * allow it: a post made public_all while its group was OPEN stops being
     * syndicated if that group later goes closed or secret. This is the half
     * that a "just check the meta" implementation would miss.
     */
    public function testPublicAllStillHasToPassTheSyndicationGate(): void
    {
        PublicOnlyFakes::$postMeta[self::POST] = [
            'peepso_group_id'      => [self::GROUP],
            '_bcc_post_visibility' => 'public_all',
        ];

        // The group is non-open NOW, and the post was not recorded as made
        // while it was non-open.
        PublicOnlyFakes::$nonOpenGroups = [self::GROUP];

        self::assertFalse(
            FeedItemReportVisibility::isPublicToEveryMember(self::row(10)),
            'a public_all post in a group that has since closed is no longer public'
        );
    }

    /**
     * ⚠ FAIL-CLOSED on an unprovable non-open list: null means the gate
     * cannot tell which groups are non-open, and an unreadable deny-list
     * must not widen what counts as public.
     */
    public function testAnUnprovableNonOpenListRefusesAGroupPost(): void
    {
        PublicOnlyFakes::$postMeta[self::POST] = [
            'peepso_group_id'      => [self::GROUP],
            '_bcc_post_visibility' => 'public_all',
        ];
        PublicOnlyFakes::$nonOpenGroups = null;

        self::assertFalse(FeedItemReportVisibility::isPublicToEveryMember(self::row(10)));
    }

    /**
     * ⭐ The two halves are ANDed, not ORed: a perfectly syndicated
     * `public_all` group post still fails when its own audience excludes
     * members. Without this, a group post could bypass the §F-02 half.
     */
    public function testAPublicAllGroupPostStillObeysItsOwnAudience(): void
    {
        PublicOnlyFakes::$postMeta[self::POST] = [
            'peepso_group_id'      => [self::GROUP],
            '_bcc_post_visibility' => 'public_all',
        ];

        self::assertFalse(
            FeedItemReportVisibility::isPublicToEveryMember(self::row(30)),
            'friends-only wins over public_all'
        );
    }

    /** A non-group post never consults the group half at all. */
    public function testANonGroupPostNeedsNoGroupMeta(): void
    {
        PublicOnlyFakes::$nonOpenGroups = null; // would refuse a group post

        self::assertTrue(
            FeedItemReportVisibility::isPublicToEveryMember(self::row(10)),
            'an ungrouped public post is unaffected by the group list'
        );
    }
}
