<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Core\Repositories\UserMetaRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\REST\MyProfilePrefsEndpoint;
use BCC\Trust\Core\Services\MentorListingService;
use BCC\Trust\Core\Support\PrivacySettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-519: the member's own "hidden from search" switch says what search
 * does to them.
 *
 * Search leaves a member out when ANY row of the one flag reads as on, and,
 * until the F-257 fold is done, when any row of the legacy opt-out does
 * (UserSyncRepository::listingOptOutSql()). /me/profile-prefs
 * `hide_from_search` reads through PrivacySettings, the same reader as its
 * alias, /me/privacy `discovery_optout`, so the two always agree.
 *
 * The real endpoint runs; WordPress, PeepSo and the mentor service are
 * doubled (tests/Stubs/profile-prefs-*.php). Several rows for one key are
 * given through the UserMetaRepository double's `$rows`. The SQL side of
 * the same parity is pinned on the real engine by
 * PrivacyFlagsReadIntegrationTest.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HideFromSearchReadsAsSearchTest extends TestCase
{
    private const USER_ID = 77;

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

    /**
     * Stored rows for the member, in umeta_id order. The FIRST row is also
     * what get_user_meta(…, true) answers, the read the switch used before.
     *
     * @param list<string> $values
     */
    private static function rows(string $key, array $values): void
    {
        UserMetaRepository::$rows[self::USER_ID][$key] = $values;
        $GLOBALS['__bcc_prefs_meta'][self::USER_ID][$key] = $values[0];
    }

    private static function foldDone(): void
    {
        // What the migration runner stores on completion: time().
        $GLOBALS['__bcc_prefs_options'][PrivacySettings::FOLD_DONE_OPTION] = 1_700_000_000;
    }

    /** GET /me/profile-prefs `hide_from_search`, after checking /me/privacy agrees. */
    private function hideFromSearch(): bool
    {
        $data = (new MyProfilePrefsEndpoint())->get(new \WP_REST_Request([]))->get_data()['data'];
        self::assertIsBool($data['hide_from_search']);
        self::assertSame(
            PrivacySettings::readAll(self::USER_ID)['discovery_optout'],
            $data['hide_from_search'],
            'one setting, two fields: /me/privacy discovery_optout must say the same'
        );

        return $data['hide_from_search'];
    }

    public function testNoRowIsOff(): void
    {
        self::assertFalse($this->hideFromSearch());
    }

    public function testAnOnRowIsOn(): void
    {
        self::rows(PrivacySettings::HIDE_FROM_LISTING_META, ['1']);

        self::assertTrue($this->hideFromSearch());
    }

    /**
     * Search leaves out a member with an on row beside an off one, so the
     * switch is on, whatever the first row says.
     */
    public function testAnOnRowBesideAnOffRowIsOn(): void
    {
        self::rows(PrivacySettings::HIDE_FROM_LISTING_META, ['0', '1']);

        self::assertTrue($this->hideFromSearch(), "rows '0' then '1'");
    }

    /** The values PeepSo's own form can store raw, as search reads them. */
    public function testEveryValueSearchReadsAsOnIsOn(): void
    {
        foreach (['true', 'on', 'yes', 'TRUE', 'Yes', 'On'] as $value) {
            self::rows(PrivacySettings::HIDE_FROM_LISTING_META, [$value]);
            self::assertTrue($this->hideFromSearch(), $value);
        }
    }

    /** F-257 rollout: until the fold is done, search still reads the legacy opt-out. */
    public function testTheLegacyOptOutCountsUntilTheFoldIsDone(): void
    {
        self::rows(PrivacySettings::HIDE_FROM_LISTING_META, ['0']);
        self::rows(PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META, ['1']);

        self::assertTrue($this->hideFromSearch());
    }

    /**
     * Once folded, the legacy row no longer counts. The fold left the legacy
     * rows in place, so a member who has since switched this off holds
     * hide '0' beside a legacy '1', and search lists them.
     */
    public function testOnceFoldedTheLegacyRowNoLongerCounts(): void
    {
        self::rows(PrivacySettings::HIDE_FROM_LISTING_META, ['0']);
        self::rows(PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META, ['1']);
        self::foldDone();

        self::assertFalse($this->hideFromSearch());
    }

    public function testOnceFoldedTheOneFlagStillCounts(): void
    {
        self::rows(PrivacySettings::HIDE_FROM_LISTING_META, ['1']);
        self::foldDone();

        self::assertTrue($this->hideFromSearch());
    }

    /**
     * The fold-done option is read only when it could change the answer:
     * the one flag off and a legacy row on.
     */
    public function testTheFoldOptionIsReadOnlyForALegacyOnRow(): void
    {
        self::rows(PrivacySettings::HIDE_FROM_LISTING_META, ['0']);
        self::rows(PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META, ['0']);
        self::assertFalse($this->hideFromSearch());
        self::assertNotContains(PrivacySettings::FOLD_DONE_OPTION, $GLOBALS['__bcc_prefs_option_reads'], 'legacy off');

        self::rows(PrivacySettings::HIDE_FROM_LISTING_META, ['1']);
        self::rows(PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META, ['1']);
        self::assertTrue($this->hideFromSearch());
        self::assertNotContains(PrivacySettings::FOLD_DONE_OPTION, $GLOBALS['__bcc_prefs_option_reads'], 'the one flag on');

        self::rows(PrivacySettings::HIDE_FROM_LISTING_META, ['0']);
        self::assertTrue($this->hideFromSearch());
        self::assertContains(PrivacySettings::FOLD_DONE_OPTION, $GLOBALS['__bcc_prefs_option_reads'], 'control: it decides here');
    }

    /** An unreadable setting shows as on (hidden), as /me/privacy shows it. */
    public function testAnUnreadableSettingShowsAsOn(): void
    {
        UserMetaRepository::$unreadable[self::USER_ID] = true;

        self::assertTrue($this->hideFromSearch());
    }

    /** One read serves the switch, the legacy key included. */
    public function testTheSwitchReadsBothKeysInOneCall(): void
    {
        UserMetaRepository::$calls = [];
        PrivacySettings::flag(self::USER_ID, 'discovery_optout');

        self::assertCount(1, UserMetaRepository::$calls);
        self::assertContains(PrivacySettings::HIDE_FROM_LISTING_META, UserMetaRepository::$calls[0]['keys']);
        self::assertContains(PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META, UserMetaRepository::$calls[0]['keys']);
    }

    /** A PATCH echoes the setting as it now reads, both directions. */
    public function testAPatchEchoesWhatSearchNowDoes(): void
    {
        $on = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(['hide_from_search' => true]))
            ->get_data()['data'];
        self::assertTrue($on['hide_from_search']);

        $off = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(['hide_from_search' => false]))
            ->get_data()['data'];
        self::assertFalse($off['hide_from_search']);
    }

    /**
     * Rows match as search's SQL matches them: the same set, nothing
     * trimmed (UserSyncRepository::anyRowOn()). A padded value is not on
     * there, so it is not on here.
     */
    public function testAPaddedValueReadsAsSearchReadsIt(): void
    {
        foreach ([' 1', "1\n", ' yes', "\ttrue"] as $value) {
            self::rows(PrivacySettings::HIDE_FROM_LISTING_META, [$value]);
            self::assertFalse($this->hideFromSearch(), var_export($value, true));
        }

        self::rows(PrivacySettings::HIDE_FROM_LISTING_META, ['0']);
        self::rows(PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META, [' 1']);
        self::assertFalse($this->hideFromSearch(), 'a padded legacy value, before the fold');
    }

    /**
     * A member the legacy opt-out still hides switches the setting off on
     * /me/profile-prefs: the legacy key is written '0' too, so search lists
     * them and the switch stays off.
     */
    public function testSwitchingOffAlsoWritesTheLegacyOptOutOff(): void
    {
        $GLOBALS['__bcc_prefs_meta'][self::USER_ID][PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META] = '1';
        self::assertTrue($this->hideFromSearch(), 'control: the legacy row hides before the fold');

        $response = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(['hide_from_search' => false]));

        self::assertSame(200, $response->get_status());
        self::assertFalse($response->get_data()['data']['hide_from_search'], 'the PATCH echo');
        self::assertSame('0', $GLOBALS['__bcc_prefs_meta'][self::USER_ID][PrivacySettings::HIDE_FROM_LISTING_META]);
        self::assertSame('0', $GLOBALS['__bcc_prefs_meta'][self::USER_ID][PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META]);
        self::assertFalse($this->hideFromSearch(), 'the next read');
    }

    /** The same through /me/privacy, whose writer is PrivacySettings::writePartial(). */
    public function testSwitchingOffThroughPrivacyAlsoWritesTheLegacyOptOutOff(): void
    {
        $GLOBALS['__bcc_prefs_meta'][self::USER_ID][PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META] = '1';
        self::assertTrue(PrivacySettings::readAll(self::USER_ID)['discovery_optout'], 'control');

        PrivacySettings::writePartial(self::USER_ID, ['discovery_optout' => false]);

        self::assertSame('0', $GLOBALS['__bcc_prefs_meta'][self::USER_ID][PrivacySettings::HIDE_FROM_LISTING_META]);
        self::assertSame('0', $GLOBALS['__bcc_prefs_meta'][self::USER_ID][PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META]);
        self::assertFalse($this->hideFromSearch());
    }

    /** Switching it on writes the flag only: the flag alone hides. */
    public function testSwitchingOnLeavesTheLegacyOptOutAlone(): void
    {
        (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(['hide_from_search' => true]));
        PrivacySettings::writePartial(self::USER_ID, ['discovery_optout' => true]);

        $keys = array_map(static fn (array $write): string => (string) $write[1], $GLOBALS['__bcc_prefs_writes']);
        self::assertContains(PrivacySettings::HIDE_FROM_LISTING_META, $keys, 'control');
        self::assertNotContains(PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META, $keys);
        self::assertTrue($this->hideFromSearch());
    }

    /**
     * The legacy write fails at the database: the field is reported as not
     * saved, and the member is still hidden, never listed by half a write.
     */
    public function testAFailedLegacyWriteFailsTheFieldAndTheMemberStaysHidden(): void
    {
        $GLOBALS['__bcc_prefs_meta'][self::USER_ID][PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META] = '1';
        $GLOBALS['__bcc_prefs_fail_on'] = 2; // 1 = the flag '0', 2 = the legacy '0'

        $response = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(['hide_from_search' => false]));

        self::assertSame(500, $response->get_status());
        $error = $response->get_data()['error'];
        self::assertSame('hide_from_search', $error['data']['failed']);
        self::assertSame([], $error['data']['saved']);
        self::assertSame('1', $GLOBALS['__bcc_prefs_meta'][self::USER_ID][PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META]);
        $GLOBALS['wpdb']->last_error = '';
        self::assertTrue($this->hideFromSearch(), 'still hidden');
    }
}
