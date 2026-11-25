<?php
/**
 * F-270: "Show my groups on my profile" reads FAIL-CLOSED, and the
 * exemption belongs to the viewer being served.
 *
 * ⚠⚠ THE BUG THIS PINS. The switch was read with a bare
 * `get_user_meta()`, which answers `''` for "no row" AND for "the read
 * did not happen". Absent means ON for this setting — the one profile
 * switch whose default is permissive — so an unreadable row took the
 * permissive branch and PUBLISHED the groups of a member who had opted
 * out. The setting failed in the direction that disclosed.
 *
 * ⇒ It now reads through `ProfileAccess::showFlagOrUnknown()`, which
 * routes to bcc-core's `UserMetaRepository::valuesOrUnknown()` and can
 * answer null. That is the SAME reader F-341's four show-flags use: the
 * reader moved to `ProfileAccess` rather than being copied, so the five
 * switches cannot disagree about corrupt or unreadable data.
 *
 * ⚠ WHAT IS DELIBERATELY NOT STUBBED: `ProfileAccess`,
 * `ProfileSectionVisibility` and `PrivacySettings` are the REAL classes.
 * Stubbing a collaborator here would prove only that a stub returns what
 * it was told, and the whole question is which of three readers answers.
 *
 * ⭐ ANTI-VACUITY. Two controls carry this file: an untouched setting
 * still SHOWS (a gate that hid everyone would satisfy every fail-closed
 * assertion), and a readable `'0'` still HIDES (a gate that showed
 * everyone would satisfy the permissive ones). Both must hold, so
 * neither constant answer passes.
 *
 * @package BCC\Trust\Tests\Unit
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Repositories\UserMetaRepository;
use BCC\Trust\Core\Support\ProfileAccess;
use BCC\Trust\Core\Support\ProfileSectionVisibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[CoversClass(ProfileAccess::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProfileGroupsFlagFailClosedTest extends TestCase
{
    private const OWNER     = 611;
    private const VIEWER    = 977;
    private const ADMIN     = 1044;
    private const SHOW_META = 'bcc_show_groups_on_profile';

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/profile-section-visibility-stubs.php';

        $GLOBALS['__bcc_psv'] = ['is_admin' => false, 'admin_ids' => [], 'meta' => []];
        UserMetaRepository::$unreadable = [];
        UserMetaRepository::$rows       = [];
        UserMetaRepository::$reads      = [];
        UserMetaRepository::$calls      = [];
    }

    // ── anti-vacuity controls ──────────────────────────────────────────

    /**
     * ⭐ CONTROL. An untouched setting still SHOWS.
     *
     * This is the overwhelming majority of accounts, and it is the case a
     * fail-closed read must not swallow: absent means ON, and the read
     * DID run, so the answer is the member's default, not a refusal.
     */
    public function testAnUntouchedSettingStillShowsTheGroups(): void
    {
        self::assertTrue(ProfileAccess::showsGroupsOnProfile(self::OWNER, self::VIEWER));
    }

    /** ⭐ CONTROL. The toggle still works: a readable '0' HIDES. */
    public function testAReadableOffStillHides(): void
    {
        $this->store('0');

        self::assertFalse(ProfileAccess::showsGroupsOnProfile(self::OWNER, self::VIEWER));
    }

    /** ⭐ CONTROL. A readable '1' SHOWS, so "on" is not ignored either. */
    public function testAReadableOnShows(): void
    {
        $this->store('1');

        self::assertTrue(ProfileAccess::showsGroupsOnProfile(self::OWNER, self::VIEWER));
    }

    // ── the unreadable read ────────────────────────────────────────────

    /**
     * ⚠⚠ THE CASE THAT MATTERS. An unreadable row HIDES from a viewer the
     * setting applies to — it no longer takes the permissive default.
     */
    public function testAnUnreadableFlagHidesFromAStranger(): void
    {
        UserMetaRepository::$unreadable[self::OWNER] = true;

        self::assertFalse(ProfileAccess::showsGroupsOnProfile(self::OWNER, self::VIEWER));
    }

    /**
     * ⚠ And it hides from a SIGNED-OUT reader too, who is the most likely
     * one to be served a cached anonymous build.
     */
    public function testAnUnreadableFlagHidesFromAnonymous(): void
    {
        UserMetaRepository::$unreadable[self::OWNER] = true;

        self::assertFalse(ProfileAccess::showsGroupsOnProfile(self::OWNER, 0));
    }

    /**
     * The OWNER is answered before the read, so an unreadable row never
     * hides a member's own groups from them. They chose the setting and
     * must be able to see what it is doing.
     */
    public function testAnUnreadableFlagDoesNotHideFromTheOwner(): void
    {
        UserMetaRepository::$unreadable[self::OWNER] = true;

        self::assertTrue(ProfileAccess::showsGroupsOnProfile(self::OWNER, self::OWNER));
    }

    /** An exempt viewer keeps the §F-02 exemption through a failed read. */
    public function testAnUnreadableFlagDoesNotHideFromAnExemptViewer(): void
    {
        $GLOBALS['__bcc_psv']['admin_ids'] = [self::ADMIN];
        UserMetaRepository::$unreadable[self::OWNER] = true;

        self::assertTrue(ProfileAccess::showsGroupsOnProfile(self::OWNER, self::ADMIN));
    }

    /**
     * ⚠⚠ The exemption is the VIEWER'S, never the request user's.
     *
     * A card list can be composed for one member while another is signed
     * in, and a fan-out build has no current user at all. An admin
     * triggering such a render must not lend their exemption to the
     * viewer it is being built for — that direction DISCLOSES.
     */
    public function testTheRequestUsersExemptionDoesNotReachAnotherViewer(): void
    {
        // `is_admin` models the REQUEST's user; `admin_ids` the viewer.
        $GLOBALS['__bcc_psv']['is_admin']  = true;
        $GLOBALS['__bcc_psv']['admin_ids'] = [];
        $this->store('0');

        self::assertFalse(ProfileAccess::showsGroupsOnProfile(self::OWNER, self::VIEWER));
    }

    // ── several stored rows ────────────────────────────────────────────

    /**
     * Strictest row wins, in the direction that hides. WordPress allows
     * several rows per (user, key) and `add_user_meta()` can create them,
     * so the rule has to be defined rather than left to row order.
     *
     * @param list<string> $rows
     */
    #[DataProvider('rowSets')]
    public function testStrictestRowWins(array $rows, bool $expected, string $why): void
    {
        UserMetaRepository::$rows[self::OWNER][self::SHOW_META] = $rows;

        self::assertSame(
            $expected,
            ProfileAccess::showsGroupsOnProfile(self::OWNER, self::VIEWER),
            $why
        );
    }

    /** @return iterable<string, array{0: list<string>, 1: bool, 2: string}> */
    public static function rowSets(): iterable
    {
        yield 'on then off'   => [['1', '0'], false, 'an off row after an on row still hides'];
        yield 'off then on'   => [['0', '1'], false, 'an off row before an on row still hides'];
        yield 'both on'       => [['1', '1'], true, 'every row says show'];
        yield 'empty then on' => [['', '1'], true, 'an empty row is no preference, not an off vote'];
        yield 'empty only'    => [[''], true, 'an empty row is the absent-means-ON case'];
        yield 'whitespace'    => [['  '], true, 'whitespace is no preference, trimmed like PrivacySettings'];
        yield 'nonsense'      => [['maybe'], false, 'an unrecognised value hides, as the four F-341 flags do'];
    }

    // ── the ungated reader ─────────────────────────────────────────────

    /**
     * ⚠ The UNGATED reader answers null when the read did not happen, and
     * that is the whole point of it existing: the prefs form needs to
     * tell "stored off" from "could not tell", which the gate cannot
     * express because it answers true for the owner by construction.
     */
    public function testTheUngatedReaderSaysNullWhenTheReadFails(): void
    {
        UserMetaRepository::$unreadable[self::OWNER] = true;

        self::assertNull(ProfileAccess::showsGroupsOnProfileOrUnknown(self::OWNER));
    }

    /** And it reports the member's OWN choice, not the gate's answer. */
    public function testTheUngatedReaderReportsTheStoredChoiceNotTheGates(): void
    {
        $this->store('0');

        // The gate says "shown" to the owner; the stored choice is off.
        self::assertTrue(ProfileAccess::showsGroupsOnProfile(self::OWNER, self::OWNER));
        self::assertFalse(ProfileAccess::showsGroupsOnProfileOrUnknown(self::OWNER));
    }

    // ── the Halls section delegates here ───────────────────────────────

    /**
     * `ProfileSectionVisibility` does not re-read this flag; it delegates,
     * so the fail-closed read reaches the Halls SECTION too. Without this
     * the section and the group list could disagree about the same member.
     */
    public function testTheHallsSectionIsHiddenWhenTheFlagCannotBeRead(): void
    {
        UserMetaRepository::$unreadable[self::OWNER] = true;

        self::assertTrue(ProfileSectionVisibility::hides(
            ProfileSectionVisibility::SECTION_HALLS,
            self::OWNER,
            self::VIEWER
        ));
    }

    /** ⭐ CONTROL for the line above: an untouched setting shows it. */
    public function testTheHallsSectionIsShownByDefault(): void
    {
        self::assertFalse(ProfileSectionVisibility::hides(
            ProfileSectionVisibility::SECTION_HALLS,
            self::OWNER,
            self::VIEWER
        ));
    }

    // ── the prime covers this key ──────────────────────────────────────

    /**
     * ⚠ N+1 GUARD. `prime()` exists so a member LIST costs one read, and
     * it primed only F-341's four keys. The groups flag now goes through
     * the same memo, so leaving it out would mean a list that asks about
     * Halls reads once PER MEMBER — invisible, because the answers would
     * all be right.
     *
     * The key is asserted in the primed set rather than counting reads,
     * because `read()` takes every key in ONE statement (`MAX_KEYS` is
     * 10): the cost is the same whether four keys or five are asked for,
     * and what matters is that this one is among them.
     */
    public function testThePrimeIncludesTheGroupsFlag(): void
    {
        ProfileSectionVisibility::prime([self::OWNER, self::VIEWER]);

        self::assertNotSame([], UserMetaRepository::$calls, 'prime must issue a call');
        $keys = UserMetaRepository::$calls[0]['keys'];
        self::assertContains(self::SHOW_META, $keys, 'the groups flag must be primed with the rest');
        self::assertSame([self::OWNER, self::VIEWER], UserMetaRepository::$calls[0]['users']);
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function store(string $value): void
    {
        $GLOBALS['__bcc_psv']['meta'][self::OWNER][self::SHOW_META] = $value;
    }
}
