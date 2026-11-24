<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\UserMetaRepository;
use BCC\Trust\Core\Repositories\UserSyncRepository;
use BCC\Trust\Core\Support\PrivacySettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The §K2 privacy flags are read from wp_usermeta through bcc-core's
 * guarded read (UserMetaRepository, ProbesReads), against real MySQL. A read
 * that cannot be trusted reads every flag as HIDDEN.
 * Rationale: M3 (private tracker).
 *
 * Pinned:
 *  - stored values, no row = public, the discovery_optout alias;
 *  - duplicate rows: the strictest wins;
 *  - a case-variant key is ignored, as get_user_meta() ignores it;
 *  - a failed read, loud and silent, reads as hidden. The silent one is
 *    wpdb's pre-flush() bail with a stale row that says "not hidden" for
 *    this very member and key, which a last_error-only check would trust;
 *  - only trusted answers are memoised; forget() drops a member's memo;
 *  - an oversized batch is refused, never truncated;
 *  - F-519: `discovery_optout` (= /me/profile-prefs `hide_from_search`)
 *    reads as the search SQL decides, for every row shape.
 *
 * Separate processes: the repository's memo is static.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(UserMetaRepository::class)]
#[CoversClass(PrivacySettings::class)]
final class PrivacyFlagsReadIntegrationTest extends TestCase
{
    private const HIDES       = 988101;
    private const NOTHING     = 988102;
    private const DUPLICATES  = 988103;
    private const CASE_VARIANT = 988104;
    private const OPTED_OUT   = 988105;
    private const SEARCH      = 988106;

    private const FAULT = '/FROM\s+`?\w*usermeta`?\s/';

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb->clearFaultInjection();
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
        $this->clean();

        self::meta(self::HIDES, 'bcc_privacy_reviews_hidden', '1');
        self::meta(self::HIDES, 'bcc_privacy_real_name_hidden', '0');
        self::meta(self::HIDES, 'nickname', 'unrelated');
        self::meta(self::DUPLICATES, 'bcc_privacy_reviews_hidden', '0');
        self::meta(self::DUPLICATES, 'bcc_privacy_reviews_hidden', '1');
        self::meta(self::CASE_VARIANT, 'BCC_PRIVACY_REVIEWS_HIDDEN', '1');
        self::meta(self::OPTED_OUT, PrivacySettings::HIDE_FROM_LISTING_META, '1');
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->clearFaultInjection();
        $this->clean();
    }

    private function clean(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->usermeta}` WHERE user_id BETWEEN %d AND %d",
            self::HIDES,
            self::SEARCH
        ));
    }

    private static function meta(int $userId, string $key, string $value): void
    {
        global $wpdb;
        self::assertNotFalse(
            $wpdb->insert($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => $key, 'meta_value' => $value]),
            (string) $wpdb->last_error
        );
    }

    public function testStoredSettingsAreReadAndNoRowIsPublic(): void
    {
        self::assertTrue(PrivacySettings::flag(self::HIDES, 'reviews_hidden'));
        self::assertFalse(PrivacySettings::flag(self::HIDES, 'real_name_hidden'));
        self::assertFalse(PrivacySettings::flag(self::HIDES, 'email_hidden'));
        self::assertSame(array_fill_keys(PrivacySettings::PROFILE_KEYS, false), PrivacySettings::readProfile(self::NOTHING));
        self::assertTrue(PrivacySettings::readAll(self::OPTED_OUT)['discovery_optout'], 'the alias reads PeepSo\'s key');
    }

    public function testTheStrictestDuplicateRowWins(): void
    {
        self::assertTrue(PrivacySettings::flag(self::DUPLICATES, 'reviews_hidden'), "rows '0' then '1': hidden");
    }

    public function testACaseVariantKeyIsIgnoredAsWordPressIgnoresIt(): void
    {
        self::assertFalse(PrivacySettings::flag(self::CASE_VARIANT, 'reviews_hidden'));
        self::assertNotNull(PrivacySettings::flagOrUnknown(self::CASE_VARIANT, 'reviews_hidden'), 'and it is not an unknown either');
    }

    /** @return iterable<string, array{string}> */
    public static function faults(): iterable
    {
        yield 'the read errors'               => ['failQueriesMatching'];
        yield 'the read bails before flush()' => ['failSilentlyMatching'];
    }

    #[DataProvider('faults')]
    public function testAnUnreadableFlagReadsAsHidden(string $fault): void
    {
        global $wpdb;

        // The PREVIOUS query's rows: a row that says "not hidden" for this
        // very member and key. A last_error-only check reads these back.
        $wpdb->get_results($wpdb->prepare(
            'SELECT %d AS user_id, %s AS meta_key, %s AS meta_value',
            self::HIDES,
            'bcc_privacy_reviews_hidden',
            '0'
        ));

        $wpdb->{$fault} = self::FAULT;
        try {
            $unknown = PrivacySettings::flagOrUnknown(self::HIDES, 'reviews_hidden');
            $flag    = PrivacySettings::flag(self::HIDES, 'reviews_hidden');
            $nothing = PrivacySettings::readProfile(self::NOTHING);
            $fired   = $wpdb->injectedFailures + $wpdb->silentFailures;
        } finally {
            $wpdb->clearFaultInjection();
        }

        self::assertGreaterThan(0, $fired, 'the fault must actually have fired');
        self::assertNull($unknown);
        self::assertTrue($flag);
        self::assertSame(
            array_fill_keys(PrivacySettings::PROFILE_KEYS, true),
            $nothing,
            'a member with nothing hidden reads as all hidden while unreadable'
        );

        // Not remembered: healthy again, the real answer comes back.
        self::assertSame(array_fill_keys(PrivacySettings::PROFILE_KEYS, false), PrivacySettings::readProfile(self::NOTHING));
    }

    public function testATrustedAnswerIsMemoisedAndForgetDropsIt(): void
    {
        global $wpdb;
        self::assertTrue(PrivacySettings::flag(self::HIDES, 'reviews_hidden'));

        $wpdb->failQueriesMatching = self::FAULT;
        try {
            $memoised = PrivacySettings::flag(self::HIDES, 'real_name_hidden');
            $fired    = $wpdb->injectedFailures;
        } finally {
            $wpdb->clearFaultInjection();
        }
        self::assertFalse($memoised, 'served from the memo');
        self::assertSame(0, $fired, 'no second read for the same member');

        $wpdb->update($wpdb->usermeta, ['meta_value' => '1'], ['user_id' => self::HIDES, 'meta_key' => 'bcc_privacy_real_name_hidden']);
        UserMetaRepository::forget(self::HIDES);
        self::assertTrue(PrivacySettings::flag(self::HIDES, 'real_name_hidden'), 'forget() makes the next read fresh');
    }

    public function testAnOversizedBatchIsRefusedNotTruncated(): void
    {
        global $wpdb;
        $before = $wpdb->queryCount;

        self::assertNull(UserMetaRepository::valuesOrUnknown(range(1, UserMetaRepository::BATCH_MAX + 1), ['bcc_privacy_reviews_hidden']));
        self::assertSame($before, $wpdb->queryCount, 'refused before any query');

        // prime() chunks, so a long list is still read in full.
        PrivacySettings::prime(array_merge(range(1, UserMetaRepository::BATCH_MAX), [self::HIDES]));
        $wpdb->failQueriesMatching = self::FAULT;
        try {
            $primed = PrivacySettings::flag(self::HIDES, 'reviews_hidden');
            $fired  = $wpdb->injectedFailures;
        } finally {
            $wpdb->clearFaultInjection();
        }
        self::assertTrue($primed);
        self::assertSame(0, $fired, 'the member past the first chunk was primed too');
    }

    /**
     * Literal keys: the provider runs before any test process loads a class.
     *
     * @return iterable<string, array{list<array{string, string}>, bool, bool}>
     */
    public static function searchOptOutShapes(): iterable
    {
        $hide   = 'peepso_is_hide_profile_from_user_listing';
        $legacy = 'bcc_privacy_discovery_optout';

        // rows (umeta_id order), fold done?, hidden from search?
        yield 'no row'                     => [[], false, false];
        yield "'0'"                        => [[[$hide, '0']], false, false];
        yield "'1'"                        => [[[$hide, '1']], false, true];
        yield "'0' then '1'"               => [[[$hide, '0'], [$hide, '1']], false, true];
        yield "'1' then '0'"               => [[[$hide, '1'], [$hide, '0']], false, true];
        yield "'TRUE'"                     => [[[$hide, 'TRUE']], false, true];
        yield "'yes'"                      => [[[$hide, 'yes']], false, true];
        yield 'legacy on, fold not done'   => [[[$hide, '0'], [$legacy, '1']], false, true];
        yield 'legacy on, fold done'       => [[[$hide, '0'], [$legacy, '1']], true, false];
        yield 'legacy only, fold not done' => [[[$legacy, 'on']], false, true];
        yield 'legacy off, fold not done'  => [[[$legacy, '0']], false, false];
        yield 'flag on, fold done'         => [[[$hide, '1'], [$legacy, '0']], true, true];
    }

    /**
     * F-519: the member's switch says what search does to them. The search
     * SQL is the shared predicate UserSyncRepository::listingOptOutSql(),
     * run here on the real engine; the switch is PrivacySettings::flag(),
     * which /me/profile-prefs `hide_from_search` reads, and /me/privacy's
     * readAll(). Each is checked against the expected answer too, so the
     * two cannot agree by both being wrong.
     *
     * @param list<array{string, string}> $rows
     */
    #[DataProvider('searchOptOutShapes')]
    public function testTheSwitchReadsAsTheSearchSqlDecides(array $rows, bool $folded, bool $hidden): void
    {
        global $wpdb;
        foreach ($rows as [$key, $value]) {
            self::meta(self::SEARCH, $key, $value);
        }
        if ($folded) {
            // What the migration runner stores on completion: time().
            $GLOBALS['__bcc_test_options'][PrivacySettings::FOLD_DONE_OPTION] = 1_700_000_000;
        }
        self::assertSame(!$folded, PrivacySettings::legacyOptOutStillRead());

        $clause = UserSyncRepository::listingOptOutSql('m.ID', PrivacySettings::legacyOptOutStillRead());
        $listed = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM (SELECT %d AS ID) m WHERE {$clause}", self::SEARCH));
        self::assertSame('', $wpdb->last_error);
        self::assertSame($hidden ? '0' : '1', (string) $listed, 'the search SQL');

        self::assertSame($hidden, PrivacySettings::flag(self::SEARCH, 'discovery_optout'), 'the switch');
        self::assertSame($hidden, PrivacySettings::readAll(self::SEARCH)['discovery_optout'], '/me/privacy');
    }
}
