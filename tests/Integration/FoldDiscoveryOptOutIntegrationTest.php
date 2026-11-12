<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Support\PrivacySettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-257: fold_discovery_optout_v1 copies every discovery opt-out that is ON
 * into hide_from_search (PeepSo's `peepso_is_hide_profile_from_user_listing`)
 * so that flag can be the only one search checks. Pinned on the real engine,
 * because the selection is SQL (IN list over a case-insensitive collation,
 * NOT EXISTS, self-narrowing):
 *   - nobody hidden today becomes visible: every truthy opt-out is copied;
 *   - it only ever turns the flag ON, never off, and never touches a member
 *     whose flag is already on or whose opt-out is off;
 *   - the legacy rows are left as they were;
 *   - a re-run writes nothing; a failed write stops with INCOMPLETE.
 *
 * update_user_meta() is a DB-backed double over a real wp_usermeta, as in
 * LoginHandleRotationIntegrationTest. Separate processes keep it out of
 * sibling suites.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FoldDiscoveryOptOutIntegrationTest extends TestCase
{
    private const LEGACY = 'bcc_privacy_discovery_optout';
    private const HIDE   = 'peepso_is_hide_profile_from_user_listing';

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->clearFaultInjection();

        if (!\function_exists('update_user_meta')) {
            eval(<<<'PHP'
                function update_user_meta(int $userId, string $key, $value): bool
                {
                    global $wpdb;
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $userId, $key));
                    return (bool) $wpdb->insert($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => $key, 'meta_value' => (string) $value]);
                }
                PHP);
        }
        require_once dirname(__DIR__, 2) . '/includes/database/migration-runner.php';
        require_once dirname(__DIR__, 2) . '/includes/database/fold-discovery-optout.php';

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->usermeta}` (
                umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255) NULL DEFAULT NULL,
                meta_value LONGTEXT NULL,
                PRIMARY KEY (umeta_id),
                KEY user_id (user_id),
                KEY meta_key (meta_key(191))
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        self::assertSame('', $wpdb->last_error, 'wp_usermeta must be creatable');
        $wpdb->query("DELETE FROM `{$wpdb->usermeta}`");
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->clearFaultInjection();
        $wpdb->query("DELETE FROM `{$wpdb->usermeta}`");
        parent::tearDown();
    }

    private static function meta(int $userId, string $key, string $value): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => $key, 'meta_value' => $value]);
    }

    private static function value(int $userId, string $key): ?string
    {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id DESC LIMIT 1",
            $userId,
            $key
        ));
        return $v === null ? null : (string) $v;
    }

    public function testTheKeysAreTheOnesTheCodeUses(): void
    {
        self::assertSame(self::LEGACY, PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META);
        self::assertSame(self::HIDE, PrivacySettings::HIDE_FROM_LISTING_META);
    }

    public function testEveryOptOutThatIsOnBecomesHidden(): void
    {
        self::meta(1, self::LEGACY, '1');
        self::meta(2, self::LEGACY, 'TRUE');       // FILTER_VALIDATE_BOOLEAN reads it as on
        self::meta(3, self::LEGACY, 'yes');
        self::meta(4, self::LEGACY, '1');
        self::meta(4, self::HIDE, '0');             // hide explicitly off, opt-out on → on

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_fold_discovery_optout());

        foreach ([1, 2, 3, 4] as $id) {
            self::assertSame('1', self::value($id, self::HIDE), "member {$id} must stay hidden");
        }
    }

    public function testANonCanonicalOnHideValueIsNormalisedSoSearchHidesThem(): void
    {
        // Search lists anyone whose hide value is not exactly '1'. A
        // hand-edited 'true' plus an ON opt-out must end up '1', not be
        // skipped as "already on" and then listed once folded.
        self::meta(9, self::LEGACY, '1');
        self::meta(9, self::HIDE, 'true');

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_fold_discovery_optout());

        self::assertSame('1', self::value(9, self::HIDE));
    }

    public function testItNeverTurnsAnythingOffOrTouchesTheUninvolved(): void
    {
        self::meta(5, self::LEGACY, '0');           // opt-out off
        self::meta(6, self::HIDE, '1');             // hidden through profile-prefs only
        self::meta(7, self::LEGACY, '1');
        self::meta(7, self::HIDE, '1');             // both on already
        self::meta(8, self::HIDE, '0');             // visible, never opted out

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_fold_discovery_optout());

        self::assertNull(self::value(5, self::HIDE), 'an OFF opt-out writes nothing');
        self::assertSame('1', self::value(6, self::HIDE));
        self::assertSame('1', self::value(7, self::HIDE));
        self::assertSame('0', self::value(8, self::HIDE));
    }

    public function testTheLegacyRowsAreLeftAsTheyWere(): void
    {
        self::meta(1, self::LEGACY, '1');
        self::meta(5, self::LEGACY, '0');

        bcc_trust_fold_discovery_optout();

        self::assertSame('1', self::value(1, self::LEGACY));
        self::assertSame('0', self::value(5, self::LEGACY));
    }

    public function testARerunWritesNothing(): void
    {
        global $wpdb;
        self::meta(1, self::LEGACY, '1');
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_fold_discovery_optout());
        $rows = (string) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta}");

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_fold_discovery_optout());
        self::assertSame($rows, (string) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta}"));
    }

    public function testAFailedWriteStopsIncompleteAndTheRetryFinishes(): void
    {
        global $wpdb;
        self::meta(1, self::LEGACY, '1');

        $wpdb->failQueriesMatching = '/^\s*INSERT\b.*' . preg_quote(self::HIDE, '/') . '/is';
        self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, bcc_trust_fold_discovery_optout());
        $wpdb->clearFaultInjection();

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_fold_discovery_optout());
        self::assertSame('1', self::value(1, self::HIDE));
    }

    public function testMoreThanOneBatchIsFolded(): void
    {
        for ($id = 100; $id < 350; $id++) {     // > the 200-row batch
            self::meta($id, self::LEGACY, '1');
        }

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_fold_discovery_optout());

        global $wpdb;
        self::assertSame('250', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = '1'",
            self::HIDE
        )));
    }
}
