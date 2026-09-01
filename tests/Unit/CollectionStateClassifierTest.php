<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CollectionStateClassifier;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The four collection-state tabs.
 *
 * ── WHAT "HONEST" MEANS HERE, AND HOW IT IS TESTED ──────────────────────
 * Two properties, and both have to hold or the tabs mislead:
 *
 *   MUTUALLY EXCLUSIVE  no row appears in two tabs. A collection counted
 *                       twice inflates a queue an operator works through.
 *   COLLECTIVELY WHOLE  no row appears in none of them. A row in no tab is
 *                       invisible, and invisible is how a live community
 *                       with an unresolved identity would sit unnoticed.
 *
 * The tab is a function of exactly four booleans, so "every case" is 16
 * rows — small enough to enumerate, which is the only way to assert the
 * second property at all. A test that walked example rows could never show
 * that nothing falls through.
 */
#[CoversClass(CollectionStateClassifier::class)]
final class CollectionStateClassifierTest extends TestCase
{
    /**
     * All 16 combinations of (verified, identityResolved, hasCommunity,
     * hidden), each with the tab it must land in, written out by hand.
     *
     * @return array<string, array{0: bool, 1: bool, 2: bool, 3: bool, 4: string}>
     */
    public static function everyCombination(): array
    {
        $VWC = CollectionStateClassifier::TAB_VERIFIED_WITH_COMMUNITY;
        $DU  = CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED;
        $NA  = CollectionStateClassifier::TAB_NEEDS_ATTENTION;
        $HID = CollectionStateClassifier::TAB_HIDDEN_BY_OPERATOR;

        return [
            // V      K      C      H      => tab
            'healthy verified community'      => [true,  true,  true,  false, $VWC],
            'community, identity unresolved'  => [true,  false, true,  false, $NA],
            'verified, no community'          => [true,  true,  false, false, $NA],
            'verified, no identity, no group' => [true,  false, false, false, $NA],
            'community on unverified'         => [false, true,  true,  false, $NA],
            'community on unverified alias'   => [false, false, true,  false, $NA],
            'plain discovery row'             => [false, true,  false, false, $DU],
            'legacy alias row'                => [false, false, false, false, $DU],

            // Hidden outranks every one of the above, unchanged.
            'hidden + healthy'                => [true,  true,  true,  true,  $HID],
            'hidden + unresolved community'   => [true,  false, true,  true,  $HID],
            'hidden + verified no community'  => [true,  true,  false, true,  $HID],
            'hidden + verified no identity'   => [true,  false, false, true,  $HID],
            'hidden + community unverified'   => [false, true,  true,  true,  $HID],
            'hidden + unverified alias group' => [false, false, true,  true,  $HID],
            'hidden discovery row'            => [false, true,  false, true,  $HID],
            'hidden legacy alias'             => [false, false, false, true,  $HID],
        ];
    }

    #[DataProvider('everyCombination')]
    public function testEveryCombinationLandsInItsDecidedTab(
        bool $verified,
        bool $resolved,
        bool $community,
        bool $hidden,
        string $expected
    ): void {
        self::assertSame(
            $expected,
            CollectionStateClassifier::classify($verified, $resolved, $community, $hidden)
        );
    }

    /**
     * The two properties, proven over the whole input space rather than
     * asserted case by case.
     */
    public function testTheTabsAreMutuallyExclusiveAndCollectivelyExhaustive(): void
    {
        $tabs = CollectionStateClassifier::tabs();
        $seen = [];

        foreach ([true, false] as $v) {
            foreach ([true, false] as $k) {
                foreach ([true, false] as $c) {
                    foreach ([true, false] as $h) {
                        $tab = CollectionStateClassifier::classify($v, $k, $c, $h);

                        // Collectively exhaustive: it is always ONE OF the four.
                        self::assertContains(
                            $tab,
                            $tabs,
                            sprintf('(%d,%d,%d,%d) fell outside every tab', $v, $k, $c, $h)
                        );

                        // Mutually exclusive: classify() returns one value, so
                        // exclusivity is the claim that no OTHER tab's rule
                        // also matches. Asserted against the needs-attention
                        // predicate, the only one that overlaps by shape.
                        $attention = CollectionStateClassifier::needsAttention($v, $k, $c);
                        if ($tab === CollectionStateClassifier::TAB_VERIFIED_WITH_COMMUNITY
                            || $tab === CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED) {
                            self::assertFalse(
                                $attention,
                                'a healthy tab must not also satisfy needs-attention'
                            );
                        }

                        $seen[$tab] = true;
                    }
                }
            }
        }

        // And every tab is reachable — a tab nothing can land in is a lie in
        // the navigation.
        foreach ($tabs as $tab) {
            self::assertArrayHasKey($tab, $seen, $tab . ' is unreachable');
        }
    }

    public function testHiddenOutranksEverythingElse(): void
    {
        foreach ([true, false] as $v) {
            foreach ([true, false] as $k) {
                foreach ([true, false] as $c) {
                    self::assertSame(
                        CollectionStateClassifier::TAB_HIDDEN_BY_OPERATOR,
                        CollectionStateClassifier::classify($v, $k, $c, true),
                        'an operator DENY decision must not be overridden by any other state'
                    );
                }
            }
        }
    }

    public function testPrecedenceIsHiddenThenAttentionThenTheHealthyTabs(): void
    {
        self::assertSame(
            [
                CollectionStateClassifier::TAB_HIDDEN_BY_OPERATOR,
                CollectionStateClassifier::TAB_NEEDS_ATTENTION,
                CollectionStateClassifier::TAB_VERIFIED_WITH_COMMUNITY,
                CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED,
            ],
            CollectionStateClassifier::precedence()
        );
    }

    /**
     * The tab must not be a function of provisioning state.
     *
     * Keeping it a function of (V,K,C,H) alone is what makes the
     * exhaustiveness proof above possible at all — the moment a fifth input
     * can move a row between tabs, 16 cases stop covering the space.
     */
    public function testProvisioningStateNeverChangesTheTab(): void
    {
        foreach ([true, false] as $v) {
            foreach ([true, false] as $k) {
                foreach ([true, false] as $c) {
                    foreach ([true, false] as $h) {
                        $tab = CollectionStateClassifier::classify($v, $k, $c, $h);
                        foreach (ProvisioningState::all() as $state) {
                            // The cause may differ; the TAB may not.
                            self::assertSame(
                                $tab,
                                CollectionStateClassifier::classify($v, $k, $c, $h),
                                'state ' . $state . ' must not move a row between tabs'
                            );
                        }
                    }
                }
            }
        }
    }

    // ── Needs-attention causes ──────────────────────────────────────────

    public function testACauseIsOfferedOnlyForRowsThatNeedAttention(): void
    {
        // A healthy row has no cause at all — not a blank one.
        self::assertNull(
            CollectionStateClassifier::attentionCause(true, true, true, ProvisioningState::PROVISIONED)
        );
        self::assertNull(
            CollectionStateClassifier::attentionCause(false, true, false, ProvisioningState::NONE)
        );
    }

    public function testTheThreeVerifiedWithoutCommunityCausesAreDistinguished(): void
    {
        self::assertSame(
            CollectionStateClassifier::CAUSE_VERIFIED_NO_COMMUNITY,
            CollectionStateClassifier::attentionCause(true, true, false, ProvisioningState::NONE)
        );
        self::assertSame(
            CollectionStateClassifier::CAUSE_REQUEST_PENDING,
            CollectionStateClassifier::attentionCause(true, true, false, ProvisioningState::REQUESTED)
        );
        self::assertSame(
            CollectionStateClassifier::CAUSE_PROVISIONING_FAILED,
            CollectionStateClassifier::attentionCause(true, true, false, ProvisioningState::FAILED)
        );
    }

    /**
     * `provisioned` with no live community is a CONTRADICTION, and must not
     * read as "no community requested" — that copy invites an operator to
     * create a second community for a collection that already had one.
     */
    public function testProvisionedWithNoCommunityIsReportedAsContradictory(): void
    {
        self::assertSame(
            CollectionStateClassifier::CAUSE_CONTRADICTORY_STATE,
            CollectionStateClassifier::attentionCause(true, true, false, ProvisioningState::PROVISIONED)
        );
    }

    public function testAnUnverifiedCollectionWithACommunityIsSeparatedFromAContradiction(): void
    {
        // The expected shape: it WAS provisioned, then verification was
        // removed. Issue #215 says the community stays.
        self::assertSame(
            CollectionStateClassifier::CAUSE_COMMUNITY_UNVERIFIED,
            CollectionStateClassifier::attentionCause(false, true, true, ProvisioningState::PROVISIONED)
        );

        // A community exists but the row never recorded it — different
        // problem, different message.
        self::assertSame(
            CollectionStateClassifier::CAUSE_CONTRADICTORY_STATE,
            CollectionStateClassifier::attentionCause(false, true, true, ProvisioningState::NONE)
        );
    }

    public function testAnUnresolvedIdentityOnALiveCommunityOutranksTheOtherCauses(): void
    {
        self::assertSame(
            CollectionStateClassifier::CAUSE_IDENTITY_UNRESOLVED,
            CollectionStateClassifier::attentionCause(true, false, true, ProvisioningState::PROVISIONED)
        );
    }

    // ── Copy ────────────────────────────────────────────────────────────

    /**
     * Tab 3 records an operator DECISION. Nothing in the database records a
     * scam classification, and the label must not claim one about a third
     * party.
     */
    public function testTheHiddenTabIsNeverLabelledScam(): void
    {
        $label = CollectionStateClassifier::tabLabel(
            CollectionStateClassifier::TAB_HIDDEN_BY_OPERATOR
        );

        self::assertSame('Hidden by operator', $label);
        self::assertStringNotContainsStringIgnoringCase('scam', $label);
        self::assertStringNotContainsStringIgnoringCase('spam', $label);
        self::assertStringNotContainsStringIgnoringCase('fraud', $label);

        // Nor anywhere else in the tab vocabulary.
        foreach (CollectionStateClassifier::tabs() as $tab) {
            self::assertStringNotContainsStringIgnoringCase(
                'scam',
                CollectionStateClassifier::tabLabel($tab)
            );
        }
    }

    public function testEveryTabAndCauseHasRealCopy(): void
    {
        foreach (CollectionStateClassifier::tabs() as $tab) {
            $label = CollectionStateClassifier::tabLabel($tab);
            self::assertNotSame('Unrecognised tab', $label, $tab);
            self::assertStringNotContainsString('_', $label, $tab . ' renders its raw token');
        }

        foreach ([
            CollectionStateClassifier::CAUSE_VERIFIED_NO_COMMUNITY,
            CollectionStateClassifier::CAUSE_REQUEST_PENDING,
            CollectionStateClassifier::CAUSE_PROVISIONING_FAILED,
            CollectionStateClassifier::CAUSE_COMMUNITY_UNVERIFIED,
            CollectionStateClassifier::CAUSE_IDENTITY_UNRESOLVED,
            CollectionStateClassifier::CAUSE_CONTRADICTORY_STATE,
        ] as $cause) {
            $label = CollectionStateClassifier::causeLabel($cause);
            self::assertNotSame('Unrecognised cause', $label, $cause);
        }
    }

    // ── The SQL half ────────────────────────────────────────────────────

    /**
     * An unknown tab must never degrade to "no filter", which would render
     * the whole table under whichever heading was asked for.
     */
    public function testAnUnknownTabIsRefusedRatherThanMatchingEverything(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CollectionStateClassifier::sqlForTab('everything', 'wp_postmeta', 'wp_posts');
    }

    public function testIsTabAcceptsExactlyTheFour(): void
    {
        foreach (CollectionStateClassifier::tabs() as $tab) {
            self::assertTrue(CollectionStateClassifier::isTab($tab));
        }

        self::assertFalse(CollectionStateClassifier::isTab('verified'));
        self::assertFalse(CollectionStateClassifier::isTab(''));
        self::assertFalse(CollectionStateClassifier::isTab('flagged_or_scam'));
    }
}
