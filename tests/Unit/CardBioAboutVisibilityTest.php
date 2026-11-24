<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Core\Support\ProfileSectionVisibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A member card's bio follows that member's About visibility (F-341).
 *
 * ## The gap this closes
 *
 * The profile honours the switch: `UserViewService` returns `bio` as a
 * documented `null` for a viewer who may not see the About section. The
 * CARD did not — `CardViewService` held no reference to
 * `ProfileSectionVisibility` at all — so the same text was served on every
 * card in every list while the member's own profile withheld it.
 *
 * ## What each level of this file can and cannot show
 *
 * ⚠ These tests drive `resolveMemberBio()` directly, by reflection. That
 * is the house pattern for this class — see
 * `MemberOpenDisputePermissionTest`, whose docblock gives the reason:
 * `CardViewService` has an eleven-dependency constructor, so a full
 * `getMemberCardByUserId()` build is not reachable in a unit test.
 *
 * ⇒ So these assertions prove the GATE'S BEHAVIOUR — including that the
 * real `ProfileSectionVisibility`, `ProfileAccess` and `PrivacySettings`
 * are consulted, since the stubs keep all three real and shim only
 * `get_user_meta`. They do NOT prove the WIRING: that the one call site
 * passes `$viewerId` through is covered by review of the diff, exactly as
 * `buildCard()`'s media lines were. Saying so beats implying otherwise.
 *
 * ⭐ The permissive-default case below is the anti-vacuity control. A gate
 * that hid every bio would satisfy every other assertion here.
 */
#[Group('unit')]
#[CoversClass(CardViewService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CardBioAboutVisibilityTest extends TestCase
{
    private const OWNER   = 501;
    private const VIEWER  = 902;
    private const BIO     = 'Delegator turned operator. Ask me about sentry nodes.';

    /** The raw meta key F-341 stores the About switch under. */
    private const SHOW_ABOUT = 'bcc_show_about_on_profile';

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/profile-section-visibility-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/card-bio-about-gate-stubs.php';

        $GLOBALS['__bcc_psv'] = ['is_admin' => false, 'meta' => []];
    }

    /**
     * ⭐ ANTI-VACUITY CONTROL: an untouched setting means SHOWN.
     *
     * `showFlag()` treats an absent row as `true`, which is the "member
     * never opened the setting" case — the overwhelming majority. If this
     * failed, the change would be hiding everyone's bio.
     */
    public function testAnUntouchedSettingStillShowsTheBio(): void
    {
        self::assertSame(self::BIO, $this->bioFor(self::VIEWER));
    }

    public function testExplicitlyShownStillShowsTheBio(): void
    {
        $this->givenAboutSetting('1');

        self::assertSame(self::BIO, $this->bioFor(self::VIEWER));
    }

    /** The defect: About hidden, and a stranger used to get the bio anyway. */
    public function testAHiddenAboutSectionWithholdsTheBioFromOthers(): void
    {
        $this->givenAboutSetting('0');

        self::assertSame('', $this->bioFor(self::VIEWER));
    }

    /**
     * And the owner still sees their own, so the settings surface can show
     * "you've hidden this" without lying to itself — `hides()` returns
     * false for a self-view, and this pins that we inherit it rather than
     * re-deciding it here.
     */
    public function testTheOwnerStillSeesTheirOwnHiddenBio(): void
    {
        $this->givenAboutSetting('0');

        self::assertSame(self::BIO, $this->bioFor(self::OWNER));
    }

    /** An admin keeps the §F-02 exemption the rest of profile privacy uses. */
    public function testAnAdminKeepsTheVisibilityExemption(): void
    {
        $this->givenAboutSetting('0');
        $GLOBALS['__bcc_psv']['is_admin'] = true;

        self::assertSame(self::BIO, $this->bioFor(self::VIEWER));
    }

    /**
     * ⚠ A signed-out viewer is `0`, and must be treated as "someone else",
     * not as the owner. `hides()` only exempts a self-view when
     * `$viewerId > 0`, and this is the case that would break if that
     * guard were dropped.
     */
    public function testASignedOutViewerIsWithheldToo(): void
    {
        $this->givenAboutSetting('0');

        self::assertSame('', $this->bioFor(0));
    }

    /** With About shown, an empty bio is still "" — no placeholder. */
    public function testNoBioIsStillEmptyWhenAboutIsVisible(): void
    {
        self::assertSame('', $this->bioFor(self::VIEWER, ''));
    }

    /**
     * ⚠ And the gate does not depend on this test's own constant: it is
     * the SAME accessor the profile calls. If the two ever diverged, this
     * would still pass while the surfaces disagreed — so it asserts
     * against `ProfileSectionVisibility` itself, not a copy of its rule.
     */
    public function testTheGateAgreesWithTheAccessorTheProfileUses(): void
    {
        $this->givenAboutSetting('0');

        self::assertTrue(
            ProfileSectionVisibility::hides(
                ProfileSectionVisibility::SECTION_ABOUT,
                self::OWNER,
                self::VIEWER
            ),
            'premise: the accessor itself must consider About hidden here'
        );
        self::assertSame('', $this->bioFor(self::VIEWER));
    }

    private function givenAboutSetting(string $value): void
    {
        $GLOBALS['__bcc_psv']['meta'][self::OWNER][self::SHOW_ABOUT] = $value;
    }

    private function bioFor(int $viewerId, ?string $bio = null): string
    {
        $user = new \WP_User(self::OWNER, $bio ?? self::BIO);

        $resolve = new ReflectionMethod(CardViewService::class, 'resolveMemberBio');

        return (string) $resolve->invoke(null, $user, $viewerId);
    }
}
