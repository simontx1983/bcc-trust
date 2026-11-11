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
 * F-97: PATCH /me/profile-prefs is all-or-nothing.
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
        $GLOBALS['__bcc_prefs_writes'] = [];
        $GLOBALS['__bcc_prefs_meta']   = [];

        $mentor = new class extends MentorListingService {
            public function __construct()
            {
            }

            public function setOptIn(int $userId, bool $optIn): void
            {
                $GLOBALS['__bcc_prefs_writes'][] = ['mentor', 'opt_in', $optIn];
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
            '`default_post_audience` must be one of: public, members, private.',
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
}
