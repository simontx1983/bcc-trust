<?php
/**
 * F-341 — the profile SECTION switches, as a matrix.
 *
 * The interesting thing about this accessor is not any single rule but that
 * it reconciles THREE storage conventions behind one question, so the tests
 * are organised by convention:
 *
 *   1. four NEW `bcc_show_*_on_profile` flags, show semantics, absent = ON;
 *   2. HALLS, which delegates to F-270's `bcc_show_groups_on_profile`
 *      rather than re-reading it;
 *   3. REVIEWS / DISPUTES, inverted reads of the §K2 `*_hidden` flags,
 *      which remain the only storage.
 *
 * ⭐ The controls come first on purpose. An accessor that returned "hidden"
 * for everything would satisfy most of the per-flag assertions below, so
 * the owner and admin cases are what make the rest mean anything.
 *
 * ⚠ `ProfileAccess` and `PrivacySettings` are the REAL classes here — see
 * the stub header for why stubbing them would make this vacuous.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\ProfileSectionVisibility as PSV;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProfileSectionVisibilityTest extends TestCase
{
    private const OWNER  = 31;
    private const VIEWER = 62;

    /** Every switch in its HIDDEN state, across all three conventions. */
    private const ALL_OFF = [
        'bcc_show_about_on_profile'         => '0',
        'bcc_show_verifications_on_profile' => '0',
        'bcc_show_operator_on_profile'      => '0',
        'bcc_show_blogs_on_profile'         => '0',
        'bcc_show_groups_on_profile'        => '0',
        'bcc_privacy_reviews_hidden'        => '1',
        'bcc_privacy_disputes_hidden'       => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // ⛔ Not alongside any other stub that declares Permissions.
        require_once __DIR__ . '/../Stubs/profile-section-visibility-stubs.php';
        $GLOBALS['__bcc_psv'] = ['is_admin' => false, 'meta' => []];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_psv']);
        parent::tearDown();
    }

    /** @param array<string, mixed> $meta */
    private function storeFor(int $userId, array $meta): void
    {
        $GLOBALS['__bcc_psv']['meta'][$userId] = $meta;
    }

    // ── controls ────────────────────────────────────────────────────

    /**
     * ⭐ CONTROL. The owner sees every section, asserted with EVERY switch
     * off — the state that hides the most. Without this, an accessor that
     * hid unconditionally would pass nearly everything else here.
     */
    public function testTheOwnerSeesEverySection(): void
    {
        $this->storeFor(self::OWNER, self::ALL_OFF);

        foreach (PSV::SECTIONS as $section) {
            self::assertTrue(
                PSV::shows($section, self::OWNER, self::OWNER),
                $section . ' must be visible to its owner'
            );
        }
    }

    /** ⭐ CONTROL. Admins keep the §F-02 exemption, on every section. */
    public function testAnAdminSeesEverySection(): void
    {
        $GLOBALS['__bcc_psv']['is_admin'] = true;
        $this->storeFor(self::OWNER, self::ALL_OFF);

        foreach (PSV::SECTIONS as $section) {
            self::assertTrue(PSV::shows($section, self::OWNER, self::VIEWER), $section);
        }
    }

    /**
     * ⭐ CONTROL, the other way. With nothing stored, a STRANGER sees
     * everything — the permissive default that keeps F-341 from changing
     * any existing profile until its owner opts out.
     */
    public function testAStrangerSeesEverySectionByDefault(): void
    {
        foreach (PSV::SECTIONS as $section) {
            self::assertTrue(
                PSV::shows($section, self::OWNER, self::VIEWER),
                $section . ' must default to shown'
            );
        }
    }

    // ── convention 1: the four new show_* flags ─────────────────────

    /** @return array<string, array{0: string, 1: string}> */
    public static function newFlagSections(): array
    {
        return [
            'about'         => [PSV::SECTION_ABOUT, 'bcc_show_about_on_profile'],
            'verifications' => [PSV::SECTION_VERIFICATIONS, 'bcc_show_verifications_on_profile'],
            'operator'      => [PSV::SECTION_OPERATOR, 'bcc_show_operator_on_profile'],
            'blogs'         => [PSV::SECTION_BLOGS, 'bcc_show_blogs_on_profile'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('newFlagSections')]
    public function testANewFlagHidesOnlyItsOwnSection(string $section, string $metaKey): void
    {
        $this->storeFor(self::OWNER, [$metaKey => '0']);

        self::assertTrue(PSV::hides($section, self::OWNER, self::VIEWER), 'its own section hides');

        foreach (PSV::SECTIONS as $other) {
            if ($other === $section) {
                continue;
            }
            self::assertFalse(
                PSV::hides($other, self::OWNER, self::VIEWER),
                $other . ' must be unaffected by ' . $metaKey
            );
        }
    }

    /**
     * ⚠ ABSENT MEANS ON, and so does an explicit '1'. The default is the
     * permissive one here — the opposite of the §K2 block — so a missing
     * row must never read as hidden. That would blank the profile of every
     * account that has never opened the setting.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('newFlagSections')]
    public function testOnlyAFalseyValueHides(string $section, string $metaKey): void
    {
        // NOTE on `false`: it belongs in THIS group, not the hiding one.
        // `(string) false` is '', which is byte-identical to what
        // WordPress returns for an ABSENT row — so a literal boolean false
        // is indistinguishable from "never set" and must take the same
        // permissive default. It is also not reachable in practice:
        // update_user_meta($id, $key, false) stores '', and the only
        // writer stores '1'/'0'.
        foreach (['', '1', 1, 'true', 'yes', false] as $stored) {
            $this->storeFor(self::OWNER, [$metaKey => $stored]);
            self::assertTrue(
                PSV::shows($section, self::OWNER, self::VIEWER),
                var_export($stored, true) . ' must not hide ' . $section
            );
        }

        // ⚠ 'anything' is in the HIDING group, not the showing one:
        // filter_var without NULL_ON_FAILURE answers false for an
        // unrecognised string. That is F-270's behaviour too, and Halls
        // delegates there, so the five show-switches agree on corrupt data.
        // Unreachable in practice — the only writer stores '1'/'0'.
        foreach (['0', 0, 'false', 'anything'] as $stored) {
            $this->storeFor(self::OWNER, [$metaKey => $stored]);
            self::assertTrue(
                PSV::hides($section, self::OWNER, self::VIEWER),
                var_export($stored, true) . ' must hide ' . $section
            );
        }
    }

    // ── convention 2: Halls delegates to F-270 ──────────────────────

    /**
     * ⚠⚠ HALLS READS F-270'S KEY, not a new one. The switch shipped with
     * F-270 as `bcc_show_groups_on_profile`, and this class delegates to
     * `ProfileAccess::showsGroupsOnProfile()` rather than re-reading it —
     * two readers of one flag is how defaults drift apart.
     *
     * So a `bcc_show_halls_on_profile` row must do NOTHING: if this test
     * ever fails, someone has quietly introduced the second key.
     */
    public function testHallsReadsTheF270FlagAndNotANewOne(): void
    {
        $this->storeFor(self::OWNER, ['bcc_show_groups_on_profile' => '0']);
        self::assertTrue(PSV::hides(PSV::SECTION_HALLS, self::OWNER, self::VIEWER));

        $this->storeFor(self::OWNER, ['bcc_show_halls_on_profile' => '0']);
        self::assertFalse(
            PSV::hides(PSV::SECTION_HALLS, self::OWNER, self::VIEWER),
            'a bcc_show_halls_on_profile row must have no effect — the key is show_groups'
        );
    }

    // ── convention 3: reviews / disputes, inverted over §K2 ─────────

    /**
     * ⚠⚠ INVERTED, and over the EXISTING storage. `reviews_hidden = true`
     * means the Reviews section is hidden; there is no second flag. A new
     * `bcc_show_reviews_on_profile` row must do nothing, or §K2 and F-341
     * could disagree about the same section — which is the two-controls
     * failure this design exists to avoid.
     */
    public function testReviewsAndDisputesInvertTheK2Flags(): void
    {
        $this->storeFor(self::OWNER, ['bcc_privacy_reviews_hidden' => '1']);
        self::assertTrue(PSV::hides(PSV::SECTION_REVIEWS, self::OWNER, self::VIEWER));
        self::assertFalse(PSV::hides(PSV::SECTION_DISPUTES, self::OWNER, self::VIEWER));

        $this->storeFor(self::OWNER, ['bcc_privacy_disputes_hidden' => '1']);
        self::assertTrue(PSV::hides(PSV::SECTION_DISPUTES, self::OWNER, self::VIEWER));
        self::assertFalse(PSV::hides(PSV::SECTION_REVIEWS, self::OWNER, self::VIEWER));
    }

    public function testANewShowFlagCannotOverrideTheK2Storage(): void
    {
        $this->storeFor(self::OWNER, [
            'bcc_privacy_reviews_hidden'   => '1',
            'bcc_show_reviews_on_profile'  => '1',
        ]);

        self::assertTrue(
            PSV::hides(PSV::SECTION_REVIEWS, self::OWNER, self::VIEWER),
            '§K2 is the only storage; a stray show_* row must not unhide it'
        );
    }

    /** §K2 defaults RESTRICTIVE-when-true, so absent means shown. */
    public function testAnAbsentK2FlagShowsTheSection(): void
    {
        self::assertFalse(PSV::hides(PSV::SECTION_REVIEWS, self::OWNER, self::VIEWER));
        self::assertFalse(PSV::hides(PSV::SECTION_DISPUTES, self::OWNER, self::VIEWER));
    }

    // ── the payload block, and degenerate inputs ────────────────────

    /**
     * The `sections` block carries EVERY section, always, so a client can
     * distinguish hidden from empty. A map missing a key would be read as
     * "section absent" and is the shape bug this block exists to prevent.
     */
    public function testTheVisibilityMapCarriesEverySection(): void
    {
        $this->storeFor(self::OWNER, self::ALL_OFF);

        $map = PSV::visibilityMap(self::OWNER, self::VIEWER);

        self::assertSame(PSV::SECTIONS, array_keys($map));
        foreach ($map as $section => $visible) {
            self::assertFalse($visible, $section . ' is off in this fixture');
        }
    }

    public function testTheVisibilityMapIsAllTrueForTheOwner(): void
    {
        $this->storeFor(self::OWNER, self::ALL_OFF);

        self::assertSame(
            array_fill_keys(PSV::SECTIONS, true),
            PSV::visibilityMap(self::OWNER, self::OWNER)
        );
    }

    /**
     * ⚠ An UNKNOWN section name is VISIBLE, not hidden and not fatal. A
     * typo at a call site must not blank a section silently, and must not
     * take a profile down either; isKnownSection() is the deliberate check.
     */
    public function testAnUnknownSectionIsVisibleAndKnownSectionsAreListed(): void
    {
        $this->storeFor(self::OWNER, self::ALL_OFF);

        self::assertFalse(PSV::hides('wallets', self::OWNER, self::VIEWER));
        self::assertFalse(PSV::isKnownSection('wallets'));
        self::assertTrue(PSV::isKnownSection(PSV::SECTION_ABOUT));
    }

    /** A zero owner cannot be classified, so nothing is hidden on it. */
    public function testAZeroOwnerHidesNothing(): void
    {
        foreach (PSV::SECTIONS as $section) {
            self::assertFalse(PSV::hides($section, 0, self::VIEWER), $section);
        }
    }

    /**
     * An ANONYMOUS viewer is not the owner, so the switches apply to them
     * exactly as to a signed-in stranger. Worth pinning because the
     * owner check is `$viewerId > 0 && $viewerId === $ownerId`, and a
     * sloppier `==` would make viewer 0 match an owner id of 0.
     */
    public function testAnAnonymousViewerIsNotTheOwner(): void
    {
        $this->storeFor(self::OWNER, ['bcc_show_about_on_profile' => '0']);

        self::assertTrue(PSV::hides(PSV::SECTION_ABOUT, self::OWNER, 0));
    }
}
