<?php
/**
 * F-283 — §K2 count privacy: the rule, and that every surface applies it.
 *
 * Two halves, deliberately in one file because the bug was the gap between
 * them: the rule was CORRECT in `UserViewService::resolveCounts()` and
 * simply absent from the member card and from `/followers`'s
 * `pagination.total`. A suite that tested only the rule would have passed
 * throughout.
 *
 * `CountPrivacy` is pure — no WordPress, no `$wpdb` — so the first half
 * needs no stubs at all. The second half is a static token walk, as
 * `ProfileGateWiringTest` does it: {@see GateOrder::code()} strips comments
 * first, so a docblock `{@see CountPrivacy}` cannot satisfy it.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\CountPrivacy;
use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\TestCase;

final class CountPrivacyTest extends TestCase
{
    /** Every count the block carries, all non-zero, so a zero is a decision. */
    private const COUNTS = [
        'followers'          => 11,
        'following'          => 22,
        'watching_size'      => 22,
        'reviews_written'    => 33,
        'reviews_received'   => 44,
        'disputes_signed'    => 55,
        'solids_given'       => 66,
        'solids_received'    => 88,
        'blog_posts_written' => 77,
    ];

    private const ALL_HIDDEN = [
        'follower_count_hidden' => true,
        'watching_hidden'       => true,
        'reviews_hidden'        => true,
        'disputes_hidden'       => true,
    ];

    // ── the rule ────────────────────────────────────────────────────

    /**
     * ⭐ CONTROL. The owner sees everything, asserted with every flag ON
     * — the state that hides the most. Without this a method that zeroed
     * unconditionally would satisfy most of the file.
     */
    public function testTheOwnerSeesEveryCount(): void
    {
        self::assertSame(
            self::COUNTS,
            CountPrivacy::applyToCounts(self::COUNTS, self::ALL_HIDDEN, true)
        );
    }

    /** ⭐ CONTROL. With nothing hidden, nothing changes for a stranger. */
    public function testAStrangerSeesEveryCountWhenNothingIsHidden(): void
    {
        self::assertSame(
            self::COUNTS,
            CountPrivacy::applyToCounts(self::COUNTS, [], false)
        );
    }

    public function testEachFlagZeroesOnlyItsOwnCounts(): void
    {
        $out = CountPrivacy::applyToCounts(
            self::COUNTS,
            ['follower_count_hidden' => true],
            false
        );
        self::assertSame(0, $out['followers']);
        self::assertSame(22, $out['following'], 'the other side of the graph is a different flag');
        self::assertSame(33, $out['reviews_written']);

        $out = CountPrivacy::applyToCounts(self::COUNTS, ['reviews_hidden' => true], false);
        self::assertSame(0, $out['reviews_written']);
        self::assertSame(11, $out['followers']);

        $out = CountPrivacy::applyToCounts(self::COUNTS, ['disputes_hidden' => true], false);
        self::assertSame(0, $out['disputes_signed']);
        self::assertSame(33, $out['reviews_written']);
    }

    /**
     * §C2 single-graph rule: the watchlist IS the follow set, so
     * `watching_hidden` has to zero BOTH `watching_size` and `following`.
     * Zeroing one would leave the same number readable under the other
     * key — the shape of leak this whole ticket is about.
     */
    public function testWatchingHiddenZeroesBothSidesOfTheSameNumber(): void
    {
        $out = CountPrivacy::applyToCounts(self::COUNTS, ['watching_hidden' => true], false);

        self::assertSame(0, $out['watching_size']);
        self::assertSame(0, $out['following']);
    }

    /**
     * ⚠ `reviews_received` is NOT governed (product decision 2026-07-22:
     * reviews written ABOUT a member are public; `reviews_hidden` covers
     * only the ones they wrote). Pinned so a future "tidy-up" cannot
     * quietly reverse it.
     */
    public function testReviewsReceivedIsNeverHidden(): void
    {
        $out = CountPrivacy::applyToCounts(self::COUNTS, self::ALL_HIDDEN, false);

        self::assertSame(44, $out['reviews_received']);
    }

    /** Counts outside the four rules are untouched. */
    public function testUngovernedCountsSurvive(): void
    {
        $out = CountPrivacy::applyToCounts(self::COUNTS, self::ALL_HIDDEN, false);

        self::assertSame(66, $out['solids_given']);
        self::assertSame(77, $out['blog_posts_written']);
    }

    /**
     * ⚠ ABSENT OR NON-TRUE MEANS VISIBLE. The block is read from
     * user_meta, so a missing key must not read as "hidden" — that would
     * blank the counts of every member who never opened the setting.
     * `'1'`, `1` and `'true'` are deliberately NOT hidden: the writer
     * stores a real bool, and accepting look-alikes here would make the
     * rule disagree with the one surface that stores it.
     */
    public function testOnlyABooleanTrueHides(): void
    {
        foreach ([null, false, 0, '', '0', '1', 1, 'true', 'yes'] as $value) {
            self::assertFalse(
                CountPrivacy::hides(['follower_count_hidden' => $value], CountPrivacy::FLAG_FOLLOWERS, false),
                sprintf('%s must not hide', var_export($value, true))
            );
        }
        self::assertTrue(
            CountPrivacy::hides(['follower_count_hidden' => true], CountPrivacy::FLAG_FOLLOWERS, false)
        );
        self::assertFalse(
            CountPrivacy::hides([], CountPrivacy::FLAG_FOLLOWERS, false),
            'an absent flag is visible'
        );
    }

    /**
     * ⚠⚠ `hides()` MUST honour the owner ON ITS OWN. A mutation that made
     * it ignore `$isSelf` SURVIVED the first version of this suite,
     * because `applyToCounts()` has its own owner short-circuit that
     * masked it — and `hides()` is what the member card, the card
     * summary and `/followers` call DIRECTLY. Broken there, a member's
     * own counts would read as 0 to themselves on three surfaces while
     * the profile block stayed correct.
     */
    public function testHidesHonoursTheOwnerWithoutApplyToCounts(): void
    {
        foreach ([
            CountPrivacy::FLAG_FOLLOWERS,
            CountPrivacy::FLAG_WATCHING,
            CountPrivacy::FLAG_REVIEWS,
            CountPrivacy::FLAG_DISPUTES,
        ] as $flag) {
            self::assertFalse(
                CountPrivacy::hides(self::ALL_HIDDEN, $flag, true),
                $flag . ' must never hide a count from its owner'
            );
            self::assertTrue(
                CountPrivacy::hides(self::ALL_HIDDEN, $flag, false),
                $flag . ' must hide from a non-owner'
            );
        }
    }

    // ── the card renders what it is handed (BEHAVIOUR) ──────────────

    /**
     * ⚠⚠ THIS IS THE TEST THAT WOULD HAVE CAUGHT THE DEAD CODE.
     *
     * The first revision had `buildMemberStats()` decide privacy itself
     * from `$summary['privacy']` / `$summary['is_self']` — keys
     * `getSummary()` DOES NOT RETURN. It therefore always received `[]`
     * and `false`, narrowed nothing, and `reviews_written` kept leaking on
     * every member card. Every source-text wiring assertion passed,
     * because the text was all present and correct.
     *
     * The fix was structural: the card now renders counts that arrive
     * ALREADY NARROWED and holds no privacy logic, so it cannot forget to
     * apply it. This test pins that contract — the tiles equal the
     * arguments — so reintroducing an independent read (a second source,
     * a second chance to forget) breaks it.
     *
     * Reflection, because the method is private and the class's
     * constructor pulls in half the container; it needs no state, which
     * is itself the property under test.
     */
    public function testTheMemberStatTilesEqualTheCountsHandedIn(): void
    {
        $ref = new \ReflectionMethod(
            \BCC\Trust\Core\Services\CardViewService::class,
            'buildMemberStats'
        );
        $ref->setAccessible(true);

        /** @var list<array{key: string, raw: int}> $tiles */
        $tiles = $ref->invoke(null, 7, 11, 22, 33);

        $raw = [];
        foreach ($tiles as $tile) {
            $raw[$tile['key']] = $tile['raw'];
        }

        self::assertSame(7, $raw['trust']);
        self::assertSame(11, $raw['watchers'], 'watchers must be the count handed in');
        self::assertSame(22, $raw['watching'], 'watching must be the count handed in');
        self::assertSame(
            33,
            $raw['reviews_written'],
            'reviews_written must be the NARROWED count handed in, never re-read'
        );
    }

    /**
     * ⚠ And a zero stays zero. If the card ever re-acquired its own
     * source, a hidden count arriving as 0 would be "helpfully" replaced
     * by the real number — which is precisely the leak.
     */
    public function testAZeroedCountIsRenderedAsZero(): void
    {
        $ref = new \ReflectionMethod(
            \BCC\Trust\Core\Services\CardViewService::class,
            'buildMemberStats'
        );
        $ref->setAccessible(true);

        /** @var list<array{key: string, raw: int, value: string}> $tiles */
        $tiles = $ref->invoke(null, 7, 0, 0, 0);

        foreach ($tiles as $tile) {
            if ($tile['key'] === 'trust') {
                continue;
            }
            self::assertSame(0, $tile['raw'], $tile['key'] . ' must stay 0');
            self::assertSame('0', $tile['value'], $tile['key'] . ' must render "0"');
        }
    }

    /**
     * ⭐ THE STRUCTURAL INVARIANT behind both tests above: the card must
     * hold NO count source and NO privacy decision of its own.
     */
    public function testTheCardHoldsNoCountSourceOfItsOwn(): void
    {
        $body = GateOrder::body(
            GateOrder::code('app/Domain/Core/Services/CardViewService.php'),
            'buildMemberStats'
        );

        self::assertStringNotContainsString('countByVoter', $body, 'no second source');
        self::assertStringNotContainsString('prefetched', $body, 'no prefetch read');
        self::assertStringNotContainsString('CountPrivacy', $body, 'no privacy decision here');
    }

    // ── every surface is wired to it ────────────────────────────────

    /**
     * ⚠⚠ THE HALF THAT WOULD HAVE CAUGHT F-283. The rule above was always
     * right; these four surfaces are where it was missing.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function wiredSurfaces(): array
    {
        return [
            // The profile counts block — the one place that was correct.
            'profile counts' => ['app/Domain/Core/Services/UserViewService.php', 'resolveCounts'],
            // The card's two follow-graph tiles, emitted raw before F-283.
            'card summary'   => ['app/Domain/Core/Services/UserViewService.php', 'getSummary'],
            // pagination.total on /followers and /following.
            'follows total'  => ['app/Domain/Core/Services/UserFollowsService.php', 'totalFor'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wiredSurfaces')]
    public function testTheSurfaceConsultsCountPrivacy(string $file, string $method): void
    {
        $body = GateOrder::body(GateOrder::code($file), $method);

        self::assertStringContainsString(
            'CountPrivacy::',
            $body,
            sprintf('%s() must apply §K2 count privacy, not re-derive or skip it', $method)
        );
    }

    /**
     * ⚠⚠ WHICH RULES, not merely "some rule". A mutation that deleted one
     * surface's narrowing SURVIVED an earlier version of this file,
     * because the surface's OTHER calls still mentioned `CountPrivacy::`
     * and the assertion only looked for the class name.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function surfaceRules(): array
    {
        return [
            // ⚠ FOUR rules, not two. The engagement block is copied
            // wholesale onto every member card as
            // `member_dossier.engagement`, so reviews/disputes have to be
            // narrowed HERE or the dossier contradicts the stat tiles on
            // the same card (review finding).
            'card summary applies all four rules' => [
                'app/Domain/Core/Services/UserViewService.php',
                'getSummary',
                ['FLAG_FOLLOWERS', 'FLAG_WATCHING', 'FLAG_REVIEWS', 'FLAG_DISPUTES'],
            ],
            'follows total applies the follower rule' => [
                'app/Domain/Core/Services/UserFollowsService.php',
                'totalFor',
                ['FLAG_FOLLOWERS'],
            ],
        ];
    }

    /**
     * @param list<string> $flags
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaceRules')]
    public function testTheSurfaceAppliesEachRuleItOwes(string $file, string $method, array $flags): void
    {
        $body = GateOrder::body(GateOrder::code($file), $method);

        foreach ($flags as $flag) {
            self::assertStringContainsString(
                'CountPrivacy::' . $flag,
                $body,
                sprintf('%s() must apply %s', $method, $flag)
            );
        }
    }

    /**
     * ⭐ THE SINGLE-OWNER INVARIANT. `resolveCounts()` must not go back to
     * deciding for itself beside the shared call — four inline `if`s there
     * were the whole reason the other three surfaces drifted.
     */
    public function testResolveCountsNoLongerReadsTheFlagsItself(): void
    {
        $body = GateOrder::body(
            GateOrder::code('app/Domain/Core/Services/UserViewService.php'),
            'resolveCounts'
        );

        foreach (['follower_count_hidden', 'watching_hidden', 'reviews_hidden', 'disputes_hidden'] as $flag) {
            self::assertStringNotContainsString(
                "'" . $flag . "'",
                $body,
                'the flag names belong to CountPrivacy now'
            );
        }
    }
}
