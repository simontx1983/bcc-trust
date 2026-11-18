<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\REST\MyProfilePrefsEndpoint;
use BCC\Trust\Core\Services\MentorListingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-97: PATCH /me/profile-prefs validates everything before any write.
 *
 * The handler used to validate and write field by field, so a bad value in a
 * LATER field returned 422 while every earlier field, profile_visibility
 * included, had already been saved. Each refusal below carries a VALID field
 * that the old code wrote before reaching the invalid one, and asserts that
 * nothing at all was written: no user meta, no PeepSo profile row, no mentor
 * opt-in.
 *
 * The real endpoint runs; only WordPress, PeepSo and the mentor service are
 * doubled (tests/Stubs/profile-prefs-*.php), and every write they receive is
 * recorded.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MyProfilePrefsEndpointAtomicityTest extends TestCase
{
    private const USER_ID = 77;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-prefs-stubs.php';

        $GLOBALS['__bcc_prefs_user']   = self::USER_ID;
        $GLOBALS['__bcc_prefs_writes']      = [];
        $GLOBALS['__bcc_prefs_meta']        = [];
        $GLOBALS['__bcc_prefs_write_count'] = 0;
        $GLOBALS['__bcc_prefs_fail_on']     = 0;
        $GLOBALS['__bcc_prefs_logs']        = [];

        $mentor = new class extends MentorListingService {
            public function __construct()
            {
            }

            public function setOptIn(int $userId, bool $optIn): void
            {
                \bcc_prefs_write(['mentor', 'opt_in', $optIn]);
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
     * Each body pairs valid fields with ONE invalid field that the handler
     * reaches after them, so the old code had already written the valid ones.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'bad default_post_audience after a valid profile_visibility and flags' => [
            ['profile_visibility' => 'private', 'hide_online' => true, 'default_post_audience' => 'everyone'],
            '`default_post_audience` must be one of: public, members, friends, private.',
        ];
        yield 'bad post_visibility after a valid profile_visibility' => [
            ['profile_visibility' => 'members', 'post_visibility' => 'public'],
            '`post_visibility` must be one of: members, private.',
        ];
        yield 'bad post_visibility alongside a mentor opt-in' => [
            ['mentor_opt_in' => true, 'hide_from_search' => true, 'post_visibility' => 'nobody'],
            '`post_visibility` must be one of: members, private.',
        ];
        yield 'bad profile_visibility alongside valid meta fields' => [
            ['profile_visibility' => 'everyone', 'post_visibility' => 'private', 'hide_birthday_year' => true],
            '`profile_visibility` must be one of: public, members, friends, private.',
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function testAnInvalidFieldRefusesTheWholeRequestAndWritesNothing(array $body, string $message): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-prefs-peepso-stub.php';

        $response = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request($body));

        self::assertSame(422, $response->get_status());
        self::assertSame('bcc_invalid_request', $response->get_data()['error']['code']);
        self::assertSame($message, $response->get_data()['error']['message']);
        self::assertSame([], $GLOBALS['__bcc_prefs_writes'], 'a refused PATCH must write nothing');
    }

    /**
     * PeepSo missing is the one failure a WRITE could hit. It is now checked
     * before anything is written, so the meta fields and the mentor opt-in in
     * the same request are not saved either.
     */
    public function testPeepSoUnavailableRefusesBeforeAnyOtherFieldIsWritten(): void
    {
        $response = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request([
            'profile_visibility' => 'private',
            'post_visibility'    => 'private',
            'hide_online'        => true,
            'mentor_opt_in'      => true,
        ]));

        self::assertSame(503, $response->get_status());
        self::assertSame('bcc_peepso_unavailable', $response->get_data()['error']['code']);
        self::assertSame([], $GLOBALS['__bcc_prefs_writes']);
    }

    public function testAnEmptyBodyIsRefusedAndWritesNothing(): void
    {
        $response = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request([]));

        self::assertSame(422, $response->get_status());
        self::assertSame('No profile preferences provided.', $response->get_data()['error']['message']);
        self::assertSame([], $GLOBALS['__bcc_prefs_writes']);
    }

    /** Without PeepSo, a request that does not touch profile_visibility still saves. */
    // ── §F-169: a stored 30 must round-trip as `friends` ────────────

    /**
     * ⚠⚠ THE SILENT LOOSENING THIS CLOSES. `peepso_last_used_post_privacy`
     * can hold 30 — PeepSo's own posting UI sets it — but 30 had no token
     * in the read map, so it was reported as `members`. A settings form
     * GETs the value and PATCHes it back, which wrote 20: the user's
     * default went from friends to members with no action and no notice.
     *
     * Nothing failed, which is why it survived. The test is a READ
     * assertion because the read is where the information was lost.
     */
    /** F-333: the owner's read says when the handle may next change. */
    public function testTheReadCarriesHandleNextChangeAt(): void
    {
        $data = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(['hide_online' => true]))
            ->get_data()['data'];
        self::assertNull($data['handle_next_change_at'], 'never renamed: can change now');

        $changed = time() - 3600;
        $GLOBALS['__bcc_prefs_meta'][self::USER_ID]['bcc_handle_last_changed'] = (string) $changed;
        $data = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(['hide_online' => false]))
            ->get_data()['data'];
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $changed + 604800), $data['handle_next_change_at']);
    }

    public function testAStored30IsReportedAsFriendsAndNotAsMembers(): void
    {
        $GLOBALS['__bcc_prefs_meta'][self::USER_ID]['peepso_last_used_post_privacy'] = '30';

        $data = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(['hide_online' => true]))
            ->get_data()['data'];

        self::assertSame('friends', $data['default_post_audience']);
        self::assertNotSame('members', $data['default_post_audience'], 'the loosening was members');
    }

    /** The other side of the trip: `friends` must write 30, not 20. */
    public function testPatchingFriendsWrites30(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-prefs-peepso-stub.php';

        $response = (new MyProfilePrefsEndpoint())->patch(
            new \WP_REST_Request(['default_post_audience' => 'friends'])
        );

        self::assertSame(200, $response->get_status());
        self::assertContains(
            ['meta', 'peepso_last_used_post_privacy', '30'],
            $GLOBALS['__bcc_prefs_writes']
        );
        self::assertSame('friends', $response->get_data()['data']['default_post_audience']);
    }

    /**
     * The whole point, asserted end to end: GET the reported value, PATCH
     * it straight back, and the stored int must not move. This is the
     * shape a settings form actually performs, and it is what silently
     * rewrote 30 to 20.
     */
    public function testAGetThenPatchRoundTripDoesNotChangeTheStoredValue(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-prefs-peepso-stub.php';

        $GLOBALS['__bcc_prefs_meta'][self::USER_ID]['peepso_last_used_post_privacy'] = '30';

        $reported = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(['hide_online' => true]))
            ->get_data()['data']['default_post_audience'];

        $GLOBALS['__bcc_prefs_writes'] = [];
        (new MyProfilePrefsEndpoint())->patch(
            new \WP_REST_Request(['default_post_audience' => $reported])
        );

        self::assertSame(
            '30',
            $GLOBALS['__bcc_prefs_meta'][self::USER_ID]['peepso_last_used_post_privacy'],
            'a round trip must preserve the stored level, not rewrite it'
        );
    }

    /**
     * Control: the three tokens that already worked must still map to the
     * same ints. Adding a fourth entry to a map is exactly the change that
     * can shift the others if someone reorders or mistypes it, and every
     * assertion above would still pass.
     */
    public function testTheThreeOriginalTokensStillMapToTheSameInts(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-prefs-peepso-stub.php';

        foreach (['public' => '10', 'members' => '20', 'private' => '40'] as $token => $expected) {
            $GLOBALS['__bcc_prefs_writes'] = [];
            (new MyProfilePrefsEndpoint())->patch(
                new \WP_REST_Request(['default_post_audience' => $token])
            );
            self::assertContains(
                ['meta', 'peepso_last_used_post_privacy', $expected],
                $GLOBALS['__bcc_prefs_writes'],
                "{$token} must still write {$expected}"
            );
        }
    }

    public function testFieldsThatDoNotNeedPeepSoStillSaveWithoutIt(): void
    {
        $response = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(['hide_online' => true]));

        self::assertSame(200, $response->get_status());
        self::assertSame([['meta', 'peepso_hide_online_status', '1']], $GLOBALS['__bcc_prefs_writes']);
    }

    public function testAFullyValidRequestWritesEveryField(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-prefs-peepso-stub.php';

        $response = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request([
            'profile_visibility'    => 'private',
            'post_visibility'       => 'private',
            'hide_birthday_year'    => true,
            'hide_online'           => false,
            'hide_from_search'      => 'true',
            'default_post_audience' => 'members',
            'mentor_opt_in'         => true,
        ]));

        self::assertSame(200, $response->get_status());
        $writes = $GLOBALS['__bcc_prefs_writes'];
        usort($writes, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        self::assertSame([
            ['mentor', 'opt_in', true],
            ['meta', 'peepso_hide_birthday_year', '1'],
            ['meta', 'peepso_hide_online_status', '0'],
            ['meta', 'peepso_is_hide_profile_from_user_listing', '1'],
            ['meta', 'peepso_last_used_post_privacy', '20'],
            ['meta', 'peepso_profile_post_acc', '40'],
            ['peepso', 'usr_profile_acc', 40],
        ], $writes);

        $data = $response->get_data()['data'];
        self::assertSame('private', $data['profile_visibility']);
        self::assertTrue($data['hide_birthday_year']);
        self::assertSame('members', $data['default_post_audience']);
    }

    // ── M1: a write that fails AFTER validation passed ────────────────

    /** @return array<string, mixed> */
    private static function allFields(): array
    {
        return [
            'profile_visibility' => 'private',
            'post_visibility'    => 'private',
            'hide_online'        => true,
            'mentor_opt_in'      => true,
        ];
    }

    /**
     * The 2nd write (post_visibility, after profile_visibility) fails at the
     * database. Phase 2 must stop there, answer 500, and say exactly which
     * fields persisted, never report the request as saved.
     */
    public function testASecondWriteFailureStopsAndReportsWhatWasSaved(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-prefs-peepso-stub.php';
        $GLOBALS['__bcc_prefs_fail_on'] = 2;

        $response = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(self::allFields()));

        self::assertSame(500, $response->get_status());
        $error = $response->get_data()['error'];
        self::assertSame('bcc_internal_error', $error['code']);
        self::assertSame(['profile_visibility'], $error['data']['saved']);
        self::assertSame('post_visibility', $error['data']['failed']);
        self::assertSame(
            [['peepso', 'usr_profile_acc', 40]],
            $GLOBALS['__bcc_prefs_writes'],
            'nothing after the failed write may run'
        );
        self::assertCount(1, $GLOBALS['__bcc_prefs_logs'], 'the failure is logged');
    }

    public function testAFailureOnTheFirstWriteReportsNothingSaved(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-prefs-peepso-stub.php';
        $GLOBALS['__bcc_prefs_fail_on'] = 1;

        $response = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(self::allFields()));

        self::assertSame(500, $response->get_status());
        self::assertSame([], $response->get_data()['error']['data']['saved']);
        self::assertSame('profile_visibility', $response->get_data()['error']['data']['failed']);
        self::assertSame([], $GLOBALS['__bcc_prefs_writes']);
    }

    public function testAMentorOptInFailureReportsEveryEarlierField(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-prefs-peepso-stub.php';
        $GLOBALS['__bcc_prefs_fail_on'] = 4; // peepso, post_visibility, hide_online, then mentor

        $response = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(self::allFields()));

        self::assertSame(500, $response->get_status());
        self::assertSame(
            ['profile_visibility', 'post_visibility', 'hide_online'],
            $response->get_data()['error']['data']['saved']
        );
        self::assertSame('mentor_opt_in', $response->get_data()['error']['data']['failed']);
    }

    /**
     * update_user_meta() returns false for an UNCHANGED value too. That must
     * not read as a failure: the signal is $wpdb->last_error, not the return.
     */
    public function testAnUnchangedValueIsNotAFailure(): void
    {
        $GLOBALS['__bcc_prefs_meta'][self::USER_ID]['peepso_hide_online_status'] = '1';

        $response = (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request(['hide_online' => true]));

        self::assertSame(200, $response->get_status());
    }
}
