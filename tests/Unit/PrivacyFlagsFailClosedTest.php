<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Repositories\UserMetaRepository;
use BCC\Trust\Core\Support\PrivacySettings;
use BCC\Trust\Core\Support\PublicIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * An unreadable §K2 privacy flag reads as HIDDEN, on every reader
 * (flag(), readProfile(), readAll()) and on the real-name mask that rides
 * on it. Flags come from bcc-core UserMetaRepository's three-way answer.
 * Rationale: M3 (private tracker).
 *
 * This suite runs against the unit bootstrap's repository double
 * (tests/Stubs/user-meta-repository-double.php), with `$unreadable` making a
 * member's read fail. The real repository's probed read, stale rows,
 * strictest-row rule and memo are pinned on real MySQL by
 * PrivacyFlagsReadIntegrationTest.
 *
 * Separate processes: a global get_user_meta() shim and the double's
 * static state.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(PrivacySettings::class)]
final class PrivacyFlagsFailClosedTest extends TestCase
{
    private const MEMBER     = 4101;
    private const UNREADABLE = 4102;
    private const VIEWER     = 4103;

    protected function setUp(): void
    {
        if (!function_exists('get_user_meta')) {
            eval('function get_user_meta(int $id, string $key = "", bool $single = false) { return $GLOBALS["__bcc_pf"][$id][$key] ?? ""; }');
        }
        $GLOBALS['__bcc_pf'] = [
            self::MEMBER => [
                'bcc_privacy_reviews_hidden'   => '1',
                'bcc_privacy_real_name_hidden' => '0',
            ],
        ];
        UserMetaRepository::$unreadable = [self::UNREADABLE => true];
        UserMetaRepository::$calls      = [];
    }

    public function testAReadableMemberGetsTheirStoredSettings(): void
    {
        self::assertTrue(PrivacySettings::flag(self::MEMBER, 'reviews_hidden'));
        self::assertFalse(PrivacySettings::flag(self::MEMBER, 'real_name_hidden'));
        self::assertFalse(PrivacySettings::flag(self::MEMBER, 'email_hidden'), 'no row is public');
        self::assertFalse(PrivacySettings::flagOrUnknown(self::MEMBER, 'disputes_hidden'));
    }

    public function testAnUnreadableFlagReadsAsHidden(): void
    {
        foreach (PrivacySettings::ALL_KEYS as $key) {
            self::assertNull(PrivacySettings::flagOrUnknown(self::UNREADABLE, $key), $key);
            self::assertTrue(PrivacySettings::flag(self::UNREADABLE, $key), $key . ' fails closed');
        }
    }

    public function testAnUnreadableMembersProfileFlagsAreAllHidden(): void
    {
        self::assertSame(
            array_fill_keys(PrivacySettings::PROFILE_KEYS, true),
            PrivacySettings::readProfile(self::UNREADABLE)
        );
        self::assertSame(
            array_fill_keys(PrivacySettings::ALL_KEYS, true),
            PrivacySettings::readAll(self::UNREADABLE)
        );
    }

    public function testAReadableProfileIsUnchanged(): void
    {
        $profile = PrivacySettings::readProfile(self::MEMBER);

        self::assertTrue($profile['reviews_hidden']);
        self::assertFalse($profile['real_name_hidden']);
        self::assertFalse($profile['watching_hidden']);
        self::assertSame(PrivacySettings::PROFILE_KEYS, array_keys($profile), 'the canonical key order');
    }

    public function testNoMemberHasNothingHiddenAndNeedsNoRead(): void
    {
        self::assertFalse(PrivacySettings::flag(0, 'reviews_hidden'));
        self::assertSame(array_fill_keys(PrivacySettings::PROFILE_KEYS, false), PrivacySettings::readProfile(0));
        self::assertSame([], UserMetaRepository::$calls);
    }

    /** M4 rides on the same read: an unreadable member's real name is masked. */
    public function testAnUnreadableMembersRealNameIsMasked(): void
    {
        self::assertSame(
            '@alice',
            PublicIdentity::visibleName(self::UNREADABLE, 'Alice Real', 'u_alice', 'alice', self::VIEWER)
        );
        self::assertSame(
            'Alice Real',
            PublicIdentity::visibleName(self::UNREADABLE, 'Alice Real', 'u_alice', 'alice', self::UNREADABLE),
            'the member themselves still sees it, with no read at all'
        );
        self::assertSame(
            'Real Bob',
            PublicIdentity::visibleName(self::MEMBER, 'Real Bob', 'u_bob', 'bob', self::VIEWER),
            'control: a readable member who did not hide it'
        );
    }

    public function testOneReadCoversEveryFlagOfAMember(): void
    {
        PrivacySettings::readProfile(self::MEMBER);

        self::assertCount(1, UserMetaRepository::$calls);
        self::assertSame([self::MEMBER], UserMetaRepository::$calls[0]['users']);
        self::assertCount(count(PrivacySettings::ALL_KEYS), UserMetaRepository::$calls[0]['keys']);
        self::assertContains(PrivacySettings::HIDE_FROM_LISTING_META, UserMetaRepository::$calls[0]['keys'], 'the discovery_optout alias');
    }

    public function testPrimeReadsAListInOneBatch(): void
    {
        PrivacySettings::prime([self::MEMBER, self::VIEWER, self::MEMBER, 0]);

        self::assertCount(1, UserMetaRepository::$calls);
        self::assertSame([self::MEMBER, self::VIEWER], UserMetaRepository::$calls[0]['users'], 'deduplicated, no id 0');
    }
    // -- strictest(): the boolean-truthy vocabulary -----------------------

    /**
     * RE-POINTED COVERAGE, not new coverage. This table used to live in
     * UserViewFlagsTest, exercising PrivacySettings::strictest() INDIRECTLY
     * through UserViewService::metaFlag() and the three moderation flags that
     * read usermeta. F-582 removed those flags and metaFlag with them - and
     * strictest() is live code with two callers in this class, so deleting
     * those tests would have dropped the only unit coverage of this parser
     * while nothing went red.
     *
     * The rule: wp_usermeta stores strings, so a `(bool)` cast on "false",
     * "no", "off" or "0" is TRUE and would surface phantom truthy values.
     * FILTER_VALIDATE_BOOLEAN is what makes those four read as false.
     *
     * ANY row that reads as on wins: WordPress permits duplicate rows, and a
     * '0' row beside a '1' row must not mask the '1'.
     *
     * @param list<mixed> $rows
     */
    #[DataProvider('truthinessVocabulary')]
    public function testStrictestReadsTheBooleanTruthyVocabulary(array $rows, bool $expected): void
    {
        self::assertSame($expected, PrivacySettings::strictest($rows));
    }

    /** @return iterable<string, array{list<mixed>, bool}> */
    public static function truthinessVocabulary(): iterable
    {
        yield 'no rows at all'          => [[], false];
        yield 'string one'              => [['1'], true];
        yield 'string true'             => [['true'], true];
        yield 'string yes'              => [['yes'], true];
        yield 'string on'               => [['on'], true];
        yield 'real true'               => [[true], true];
        yield 'string zero'             => [['0'], false];
        yield 'string false'            => [['false'], false];
        yield 'string no'               => [['no'], false];
        yield 'string off'              => [['off'], false];
        yield 'empty string'            => [[''], false];
        yield 'null'                    => [[null], false];
        yield 'an on row beside an off' => [['0', '1'], true];
        yield 'only off rows'           => [['0', 'false', 'off'], false];
    }
}
