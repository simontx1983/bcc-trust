<?php
/**
 * F-270: the prefs switch reports what the profile GATE is enforcing.
 *
 * `show_groups_on_profile` on GET /me/profile-prefs was the SECOND reader
 * of `bcc_show_groups_on_profile` — `self::flag(…, true)`, its own
 * `get_user_meta()` with its own permissive default. Two readers of one
 * flag is how defaults drift apart, and it is the duplication F-283 was.
 * It now reads through `ProfileAccess::showsGroupsOnProfileOrUnknown()`,
 * the same reader the gate uses, exactly as `hide_from_search` and
 * `hidden_hall_ids` already do in this payload.
 *
 * ⚠ THE UNGATED READER, deliberately. The gate answers true for the owner
 * by construction — the owner must see their own groups — and the owner is
 * who is asking here, so a form reading the gate would show every member
 * their switch as ON whatever they had chosen. That is why
 * `showsGroupsOnProfileOrUnknown()` exists and why it is named to make
 * routing a viewer-facing decision through it look wrong.
 *
 * ⚠ AN UNREADABLE READ REPORTS OFF. The alternative is to report ON,
 * which would tell a member their groups are listed at the moment the
 * gate is withholding them — the form would contradict the profile. The
 * cost is named in the PR: a client that PUTs the whole payload back
 * could persist that off. It errs toward the member's privacy, it is
 * recoverable by toggling, and it matches what `hide_from_search` already
 * does on an unreadable read.
 *
 * @package BCC\Trust\Tests\Unit
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Repositories\UserMetaRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\REST\MyProfilePrefsEndpoint;
use BCC\Trust\Core\Services\MentorListingService;
use BCC\Trust\Core\Support\ProfileAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProfileGroupsPrefsReadsAsGateTest extends TestCase
{
    private const USER_ID   = 77;
    private const STRANGER  = 904;
    private const SHOW_META = 'bcc_show_groups_on_profile';

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-prefs-stubs.php';

        $GLOBALS['__bcc_prefs_user']         = self::USER_ID;
        $GLOBALS['__bcc_prefs_writes']       = [];
        $GLOBALS['__bcc_prefs_meta']         = [];
        $GLOBALS['__bcc_prefs_write_count']  = 0;
        $GLOBALS['__bcc_prefs_fail_on']      = 0;
        $GLOBALS['__bcc_prefs_logs']         = [];
        $GLOBALS['__bcc_prefs_options']      = [];
        $GLOBALS['__bcc_prefs_option_reads'] = [];
        UserMetaRepository::$unreadable      = [];
        UserMetaRepository::$rows            = [];

        $mentor = new class extends MentorListingService {
            public function __construct()
            {
            }

            /** @return array{opted_in: bool, listed: bool, reason: string|null} */
            public function listingFor(int $userId): array
            {
                return ['opted_in' => false, 'listed' => false, 'reason' => null];
            }
        };
        (new ReflectionClass(Plugin::class))
            ->getProperty('mentorListingService')
            ->setValue(Plugin::instance(), $mentor);
    }

    /** GET /me/profile-prefs `show_groups_on_profile`. */
    private function switchValue(): bool
    {
        $data = (new MyProfilePrefsEndpoint())->get(new \WP_REST_Request([]))->get_data()['data'];
        self::assertIsBool($data['show_groups_on_profile'], 'the field is always a bool');

        return $data['show_groups_on_profile'];
    }

    /**
     * Stored rows for the member, in umeta_id order. The FIRST row is also
     * what get_user_meta(…, true) answers — the read the switch used
     * before — so a divergence between the two shows up here.
     *
     * @param list<string> $values
     */
    private static function rows(array $values): void
    {
        UserMetaRepository::$rows[self::USER_ID][self::SHOW_META]     = $values;
        $GLOBALS['__bcc_prefs_meta'][self::USER_ID][self::SHOW_META] = $values[0];
    }

    // ── the stored choice ──────────────────────────────────────────────

    /** ⭐ CONTROL. An untouched setting reports ON: absent means shown. */
    public function testAnUntouchedSettingReportsOn(): void
    {
        self::assertTrue($this->switchValue());
    }

    /** ⭐ CONTROL. A stored off reports OFF, so the switch is not stuck on. */
    public function testAStoredOffReportsOff(): void
    {
        self::rows(['0']);

        self::assertFalse($this->switchValue());
    }

    public function testAStoredOnReportsOn(): void
    {
        self::rows(['1']);

        self::assertTrue($this->switchValue());
    }

    /**
     * ⚠ The switch now applies the STRICTEST row, as the gate does. The
     * first row is what the old reader saw, so this is a case where the
     * two answers differ: `['1', '0']` read as ON before and HIDES now.
     *
     * @param list<string> $rows
     */
    #[DataProvider('rowSets')]
    public function testTheSwitchFoldsRowsAsTheGateDoes(array $rows, bool $expected, string $why): void
    {
        self::rows($rows);

        self::assertSame($expected, $this->switchValue(), $why);
        self::assertSame(
            ProfileAccess::showsGroupsOnProfile(self::USER_ID, self::STRANGER),
            $this->switchValue(),
            'the switch and the gate must agree about a member who is not exempt'
        );
    }

    /** @return iterable<string, array{0: list<string>, 1: bool, 2: string}> */
    public static function rowSets(): iterable
    {
        yield 'on then off' => [['1', '0'], false, 'the old reader saw only the first row and said ON'];
        yield 'off then on' => [['0', '1'], false, 'any row that is not on hides'];
        yield 'both on'     => [['1', '1'], true, 'every row says show'];
    }

    // ── the unreadable read ────────────────────────────────────────────

    /**
     * ⚠⚠ An unreadable read reports OFF, matching what the gate is doing
     * to this member's profile at that moment. Before, it reported ON —
     * telling a member their groups were listed while the gate (then also
     * failing open) published them, and now telling them so while the
     * gate withholds them. Either way the form must not claim to know.
     */
    public function testAnUnreadableRowReportsOff(): void
    {
        UserMetaRepository::$unreadable[self::USER_ID] = true;

        self::assertFalse($this->switchValue());
    }

    /** The ungated reader says null, which is what the endpoint folds. */
    public function testTheUnderlyingReaderSaysNullRatherThanGuessing(): void
    {
        UserMetaRepository::$unreadable[self::USER_ID] = true;

        self::assertNull(ProfileAccess::showsGroupsOnProfileOrUnknown(self::USER_ID));
    }

    // ── the round trip ─────────────────────────────────────────────────

    /**
     * ⚠ A PATCH echo and a fresh GET must agree, or a client that writes
     * and re-reads sees the switch flip under it. The write path is
     * unchanged by F-270; this pins that the NEW reader still agrees with
     * it, which a reader with a different default would not.
     */
    public function testAPatchAndASubsequentGetAgree(): void
    {
        $off = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(['show_groups_on_profile' => false]))
            ->get_data()['data'];
        self::assertFalse($off['show_groups_on_profile'], 'the PATCH echo');
        self::assertFalse($this->switchValue(), 'and the GET that follows it');

        $on = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(['show_groups_on_profile' => true]))
            ->get_data()['data'];
        self::assertTrue($on['show_groups_on_profile'], 'the PATCH echo');
        self::assertTrue($this->switchValue(), 'and the GET that follows it');
    }
}
