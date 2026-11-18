<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\REST\MyProfilePrefsEndpoint;
use BCC\Trust\Core\Services\MentorListingService;
use BCC\Trust\Core\Support\ProfileAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-341 PR 3 — `hidden_hall_ids` on /me/profile-prefs.
 *
 * The READ half (ProfileGroupVisibility rule 4) is pinned by
 * ProfileGroupVisibilityTest. This covers the WRITE half and the
 * round trip, because the two halves can disagree in ways neither
 * suite would notice on its own:
 *
 *   - the write stores what the reader can actually read back
 *     (an int list under ProfileAccess::META_HIDDEN_HALL_IDS);
 *   - ⚠⚠ a refused write leaves the EXISTING list intact. This is the
 *     one failure mode that silently un-hides: if an over-cap PATCH
 *     cleared or truncated the stored list, the member would be told
 *     "saved" while Halls they had hidden came back.
 *
 * The real endpoint runs; only WordPress, PeepSo and the mentor service
 * are doubled (tests/Stubs/profile-prefs-*.php), and every write they
 * receive is recorded in `__bcc_prefs_writes`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HiddenHallIdsPrefsTest extends TestCase
{
    private const USER_ID = 77;

    private const BAD_IDS_MESSAGE = '`hidden_hall_ids` must contain positive integer group ids.';

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-prefs-stubs.php';

        $GLOBALS['__bcc_prefs_user']        = self::USER_ID;
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

    /** @param array<string, mixed> $body */
    private static function patch(array $body): \WP_REST_Response
    {
        return (new MyProfilePrefsEndpoint())->patch(new \WP_REST_Request($body));
    }

    /** What is stored under the hide-list key right now, untouched. */
    private static function stored(): mixed
    {
        return $GLOBALS['__bcc_prefs_meta'][self::USER_ID][ProfileAccess::META_HIDDEN_HALL_IDS] ?? null;
    }

    private static function seedStored(mixed $value): void
    {
        $GLOBALS['__bcc_prefs_meta'][self::USER_ID][ProfileAccess::META_HIDDEN_HALL_IDS] = $value;
    }

    // ── the write ────────────────────────────────────────────────────

    public function testAListOfHallIdsIsStoredAsInts(): void
    {
        $response = self::patch(['hidden_hall_ids' => [12, 7]]);

        self::assertSame(200, $response->get_status());
        self::assertSame(
            [['meta', ProfileAccess::META_HIDDEN_HALL_IDS, [12, 7]]],
            $GLOBALS['__bcc_prefs_writes']
        );
        // Anti-vacuity: the value the gate's own reader gets back, not
        // merely the value the stub was handed. A write under a different
        // key, or in a shape hiddenHallIds() rejects, would satisfy the
        // assertion above and still hide nothing.
        self::assertSame([12, 7], ProfileAccess::hiddenHallIds(self::USER_ID));
    }

    public function testNumericStringIdsAreAcceptedAndStoredAsInts(): void
    {
        // A form-encoded PATCH sends every id as a string. Storing them as
        // strings would still READ back (the reader accepts both), but the
        // stored shape would then depend on the client's encoding.
        $response = self::patch(['hidden_hall_ids' => ['12', '7']]);

        self::assertSame(200, $response->get_status());
        self::assertSame([12, 7], self::stored());
        self::assertSame([12, 7], ProfileAccess::hiddenHallIds(self::USER_ID));
    }

    public function testDuplicateIdsAreStoredOnce(): void
    {
        // Deduped on the way in so a GET round-trips what was stored. A
        // settings form GETs and then PATCHes; a list that came back
        // shorter than it went in would read as data loss.
        $response = self::patch(['hidden_hall_ids' => [9, 9, '9', 4]]);

        self::assertSame(200, $response->get_status());
        self::assertSame([9, 4], self::stored());
    }

    public function testAnEmptyListClearsTheHideList(): void
    {
        self::seedStored([12, 7]);

        $response = self::patch(['hidden_hall_ids' => []]);

        // ⚠ [] is NOT "no preferences provided" (422). It is the only way
        // a member un-hides their last Hall, so it must reach the write.
        self::assertSame(200, $response->get_status());
        self::assertSame([], self::stored());
        self::assertSame([], ProfileAccess::hiddenHallIds(self::USER_ID));
    }

    public function testAnAbsentFieldLeavesTheStoredListAlone(): void
    {
        self::seedStored([12]);

        $response = self::patch(['hide_online' => true]);

        self::assertSame(200, $response->get_status());
        self::assertSame([12], self::stored(), 'a PATCH that omits the field must not touch it');
        self::assertSame(
            [['meta', 'peepso_hide_online_status', '1']],
            $GLOBALS['__bcc_prefs_writes'],
            'and must not write the hide-list key at all'
        );
    }

    public function testResendingTheSameListIsNotAFailure(): void
    {
        // ⚠ `update_user_meta()` returns FALSE for an unchanged value as well
        // as for a failure — the hazard this endpoint's `checkedWrite()`
        // docblock exists to warn about. The signal is `$wpdb->last_error`,
        // never the return.
        //
        // The suite already pins that for a SCALAR switch (`hide_online`).
        // This pins it for an ARRAY, where the stub's unchanged-check is an
        // element-wise `===` rather than a string comparison. Without it,
        // "simplifying" checkedWrite() to read the return value would make a
        // member who re-sends their own unchanged hide list — which the
        // v1.108 contract explicitly invites, every write being idempotent —
        // get a 500.
        self::seedStored([12, 7]);

        $response = self::patch(['hidden_hall_ids' => [12, 7]]);

        self::assertSame(200, $response->get_status());
        self::assertSame([12, 7], self::stored(), 'and the list is unharmed');
    }

    public function testTheFieldAloneIsEnoughForARequest(): void
    {
        // Without `$hiddenHalls === null` in the "nothing provided" guard,
        // a PATCH carrying only this field would be refused as empty.
        self::assertSame(200, self::patch(['hidden_hall_ids' => [3]])->get_status());
    }

    // ── the ceiling ──────────────────────────────────────────────────

    public function testExactlyTheCapIsAccepted(): void
    {
        // Boundary control. Without it an off-by-one (>= instead of >)
        // passes every other test here.
        $ids = range(1, ProfileAccess::HIDDEN_HALL_IDS_MAX);

        $response = self::patch(['hidden_hall_ids' => $ids]);

        self::assertSame(200, $response->get_status());
        self::assertCount(ProfileAccess::HIDDEN_HALL_IDS_MAX, (array) self::stored());
    }

    public function testOverTheCapIsRefusedAndTheStoredListSurvivesWhole(): void
    {
        // ⚠⚠ THE un-hide hazard. For a DENY list, truncating is the unsafe
        // direction: the entries past the ceiling would be the ones that
        // stopped being hidden, and the member would be told it saved.
        self::seedStored([12, 7]);
        $ids = range(1, ProfileAccess::HIDDEN_HALL_IDS_MAX + 1);

        $response = self::patch(['hidden_hall_ids' => $ids]);

        self::assertSame(422, $response->get_status());
        self::assertSame('bcc_invalid_request', $response->get_data()['error']['code']);
        self::assertSame(
            '`hidden_hall_ids` accepts at most ' . ProfileAccess::HIDDEN_HALL_IDS_MAX . ' ids.',
            $response->get_data()['error']['message']
        );
        self::assertSame([], $GLOBALS['__bcc_prefs_writes'], 'a refused PATCH writes nothing');
        self::assertSame([12, 7], self::stored(), 'and leaves every hidden Hall hidden');
    }

    public function testTheCapCountsRawEntriesNotDeduplicatedOnes(): void
    {
        // Pins the ratified rule rather than leaving it to drift: the
        // ceiling is checked BEFORE dedupe, so an over-length body of
        // duplicates is refused even though it names few ids. Cheap, and
        // it bounds the work done on a hostile body.
        $ids = array_fill(0, ProfileAccess::HIDDEN_HALL_IDS_MAX + 1, 5);

        self::assertSame(422, self::patch(['hidden_hall_ids' => $ids])->get_status());
        self::assertSame([], $GLOBALS['__bcc_prefs_writes']);
    }

    // ── malformed input ──────────────────────────────────────────────

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function malformedBodies(): iterable
    {
        yield 'a bare int' => [7, '`hidden_hall_ids` must be an array of group ids.'];
        yield 'a string' => ['7', '`hidden_hall_ids` must be an array of group ids.'];
        yield 'an object/assoc array' => [
            ['a' => 7],
            '`hidden_hall_ids` must be an array of group ids.',
        ];
        yield 'a sparse list' => [
            [0 => 7, 2 => 9],
            '`hidden_hall_ids` must be an array of group ids.',
        ];
        yield 'a non-numeric entry' => [['abc'], self::BAD_IDS_MESSAGE];
        yield 'an empty-string entry' => [[''], self::BAD_IDS_MESSAGE];
        yield 'a float entry' => [[1.5], self::BAD_IDS_MESSAGE];
        yield 'a bool entry' => [[true], self::BAD_IDS_MESSAGE];
        yield 'a null entry' => [[null], self::BAD_IDS_MESSAGE];
        yield 'a nested array entry' => [[[7]], self::BAD_IDS_MESSAGE];
        yield 'a zero entry' => [[0], self::BAD_IDS_MESSAGE];
        yield 'a negative entry' => [[-5], self::BAD_IDS_MESSAGE];
        yield 'a negative numeric string' => [['-5'], self::BAD_IDS_MESSAGE];
        yield 'a valid id followed by a bad one' => [[12, 'abc'], self::BAD_IDS_MESSAGE];
    }

    /** @param mixed $value */
    #[DataProvider('malformedBodies')]
    public function testAMalformedFieldIsRefusedAndWritesNothing($value, string $message): void
    {
        // ⚠ The WRITE path REFUSES junk; the READER skips it. A client
        // sending junk has a bug and should hear about it, while a corrupt
        // stored row must not blank a member's profile.
        self::seedStored([12]);

        $response = self::patch(['hidden_hall_ids' => $value]);

        self::assertSame(422, $response->get_status());
        self::assertSame($message, $response->get_data()['error']['message']);
        self::assertSame([], $GLOBALS['__bcc_prefs_writes']);
        self::assertSame([12], self::stored(), 'the existing hide list is untouched');
    }

    public function testAMalformedFieldRefusesTheWholeRequestIncludingValidFields(): void
    {
        // F-97 atomicity, for the new field: it is validated in Phase 1,
        // so a valid field ahead of it must not be written either.
        $response = self::patch([
            'hide_online'     => true,
            'hidden_hall_ids' => ['nope'],
        ]);

        self::assertSame(422, $response->get_status());
        self::assertSame([], $GLOBALS['__bcc_prefs_writes'], 'no partial save');
    }

    // ── the read ─────────────────────────────────────────────────────

    public function testTheGetResponseCarriesTheStoredList(): void
    {
        self::seedStored([12, 7]);

        $data = (new MyProfilePrefsEndpoint())->get(new \WP_REST_Request([]))->get_data()['data'];

        self::assertSame([12, 7], $data['hidden_hall_ids']);
    }

    public function testTheGetResponseIsAnEmptyListWhenNothingIsHidden(): void
    {
        // Absent meta means NOTHING hidden, not everything — the
        // absent-means-permissive rule the rest of F-341 follows.
        $data = (new MyProfilePrefsEndpoint())->get(new \WP_REST_Request([]))->get_data()['data'];

        self::assertSame([], $data['hidden_hall_ids']);
    }

    public function testACorruptStoredValueReadsAsNothingHidden(): void
    {
        // A scalar where a list belongs must not fatal the profile, and
        // must not be read as "hide everything".
        self::seedStored('garbage');

        $data = (new MyProfilePrefsEndpoint())->get(new \WP_REST_Request([]))->get_data()['data'];

        self::assertSame([], $data['hidden_hall_ids']);
    }

    public function testAStoredListWithJunkEntriesReadsPastTheJunk(): void
    {
        // The reader's skip half, from the endpoint's own GET: the valid
        // ids survive a row that picked up junk, rather than the whole
        // list being discarded.
        self::seedStored([12, 'abc', 0, '7', -3]);

        $data = (new MyProfilePrefsEndpoint())->get(new \WP_REST_Request([]))->get_data()['data'];

        self::assertSame([12, 7], $data['hidden_hall_ids']);
    }

    public function testTheWrittenListRoundTripsThroughTheGet(): void
    {
        // The whole point of PR 3's wire half: what a PATCH stores is what
        // the next GET reports, so a settings form that reads-then-writes
        // cannot silently loosen the member's choice.
        self::assertSame(200, self::patch(['hidden_hall_ids' => ['9', 9, 4]])->get_status());

        $data = (new MyProfilePrefsEndpoint())->get(new \WP_REST_Request([]))->get_data()['data'];

        self::assertSame([9, 4], $data['hidden_hall_ids']);
    }
}
