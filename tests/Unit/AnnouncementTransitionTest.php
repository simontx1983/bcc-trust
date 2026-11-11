<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Announcements\AnnouncementTransition as Verb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The write vocabulary, as policy. No WordPress, no database.
 *
 * ⚠ This suite exists separately from the service's because the verb mapping is
 * the one piece of A3 that can be proved without a running WordPress, and the
 * read side already paid for conflating "testable" with "needs core": the
 * endpoint suite had to model WordPress's own schema-enforcement rule by hand
 * because real dispatch was unavailable in CI. Policy that needs nothing is
 * kept needing nothing.
 */
#[CoversClass(Verb::class)]
final class AnnouncementTransitionTest extends TestCase
{
    public function testTheVerbSetIsExactlyThree(): void
    {
        self::assertSame(['draft', 'publish', 'schedule'], Verb::verbs());
    }

    #[DataProvider('verbToStatus')]
    public function testEachVerbMapsToItsStoredStatus(string $verb, string $status): void
    {
        self::assertSame($status, Verb::statusFor($verb));
    }

    /** @return iterable<string, array{string, string}> */
    public static function verbToStatus(): iterable
    {
        yield 'draft stays draft'          => ['draft', 'draft'];
        yield 'publish becomes publish'    => ['publish', 'publish'];
        // ⚠⚠ THE MAPPING THAT IS NOT OBVIOUS, and the whole reason this case
        // exists. `schedule` MUST store `future`. A row stored `publish` with a
        // future date is invisible to the public (predicate 2) AND invisible to
        // WordPress's scheduler, which only promotes rows whose status is
        // `future` — so it would be stuck private forever, with no error.
        yield 'schedule becomes FUTURE'    => ['schedule', 'future'];
    }

    #[DataProvider('notVerbs')]
    public function testANonVerbIsRejectedRatherThanMapped(string $notAVerb): void
    {
        self::assertFalse(Verb::isVerb($notAVerb));

        $this->expectException(\InvalidArgumentException::class);
        Verb::statusFor($notAVerb);
    }

    /** @return iterable<string, array{string}> */
    public static function notVerbs(): iterable
    {
        // ⚠⚠ `blocked` is the important one. §4.32.13 makes it a READ state
        // produced by reconciler arm C, which does not exist. Mapping it onto
        // `draft` to make it "work" would make a refusal look like a successful
        // write, and would be the second time this feature invented a state it
        // could not produce.
        yield 'blocked — a read state, not a verb' => ['blocked'];
        yield 'published — the read state'         => ['published'];
        yield 'future — the storage value'         => ['future'];
        yield 'empty'                              => [''];
        yield 'case variant'                       => ['Publish'];
        yield 'whitespace padded'                  => [' publish '];
        yield 'trash'                              => ['trash'];
        yield 'private'                            => ['private'];
    }

    /**
     * ⚠⚠ The §6.1 decision this slice owns, pinned: `schedule` authorizes
     * publication just as `publish` does, so the marker is written at schedule
     * time. Safe because a scheduled row is stored `future`, which fails
     * visibility predicate 1 — the marker cannot leak it early, because
     * predicate 1 is what is keeping it private, not predicate 3.
     */
    /**
     * ⚠⚠ REVERSED ON MEASURED EVIDENCE, and the old expectation is recorded
     * here rather than quietly swapped.
     *
     * This case used to assert that `schedule` authorizes publication, because
     * the marker was written at schedule time. The interval probe measured what
     * that cost: an OVERDUE scheduled row (status `future`, due instant passed,
     * cron not yet run) has a PAST date, so predicate 2 stops refusing and a
     * non-cron publish satisfies all three predicates — the row was measurably
     * readable through the public list, rotator and detail in the interval
     * between core's status write and arm C's backstop.
     *
     * `schedule` now writes no marker at all; `AnnouncementPublicationGuard`
     * writes it at promotion after re-checking authority. A not-yet-public row
     * therefore never carries the marker, and predicate 3 refuses throughout.
     */
    public function testOnlyPublishAuthorizesPublication(): void
    {
        self::assertTrue(Verb::authorizesPublication('publish'));
        self::assertFalse(
            Verb::authorizesPublication('schedule'),
            'the marker is written at PROMOTION, not at schedule time — interval probe cases E and G'
        );
        self::assertFalse(Verb::authorizesPublication('draft'));
    }

    public function testOnlyScheduleNeedsACallerSuppliedInstant(): void
    {
        self::assertTrue(Verb::requiresInstant('schedule'));
        // §4.32.5 makes server time authoritative, so a client timestamp on
        // draft or publish is ignored entirely rather than honoured.
        self::assertFalse(Verb::requiresInstant('publish'));
        self::assertFalse(Verb::requiresInstant('draft'));
    }

    public function testAStrictlyFutureCanonicalInstantIsAccepted(): void
    {
        self::assertSame(
            '2030-01-02 03:04:05',
            Verb::normalizeInstant('2030-01-02 03:04:05', '2026-10-14 00:00:00')
        );
    }

    #[DataProvider('rejectedInstants')]
    public function testAnUnacceptableInstantIsRefusedNotClamped(string $raw, string $why): void
    {
        self::assertNull(Verb::normalizeInstant($raw, '2026-10-14 12:00:00'), $why);
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedInstants(): iterable
    {
        yield 'the past' => ['2020-01-01 00:00:00', 'a past instant must be refused'];
        // ⚠ EQUAL to now is refused too: "strictly future" is the rule, and an
        // instant equal to now would publish immediately while claiming to be
        // scheduled.
        yield 'exactly now' => ['2026-10-14 12:00:00', 'equal to now is not strictly future'];
        yield 'empty' => ['', 'empty is not an instant'];
        // ⚠⚠ A clamp is the failure mode this refuses to repeat. `absint`
        // rewrote out-of-range request bounds into in-range ones and served
        // them; clamping a past instant to "now" would turn schedule into
        // publish silently, which is the same class of defect.
        yield 'date only'            => ['2030-01-02', 'not the canonical Y-m-d H:i:s form'];
        yield 'ISO 8601 with T'      => ['2030-01-02T03:04:05', 'not the canonical form'];
        yield 'ISO 8601 with Z'      => ['2030-01-02T03:04:05Z', 'not the canonical form'];
        yield 'relative'             => ['+1 day', 'strtotime-style input is not a contract input'];
        yield 'natural language'     => ['next tuesday', 'strtotime-style input is not a contract input'];
        // ⚠ The coercion case: createFromFormat would happily roll Feb 31 into
        // March 3 and report success. A round-trip comparison catches it.
        yield 'impossible date rolls over' => ['2030-02-31 00:00:00', 'Feb 31 must not be coerced into March'];
        yield 'hour out of range'          => ['2030-01-02 25:00:00', '25:00 must not be coerced'];
        yield 'trailing junk'              => ['2030-01-02 03:04:05x', 'trailing characters must not be ignored'];
    }

    /**
     * ⚠ Surrounding whitespace IS trimmed and accepted, and that is not a
     * contradiction of the no-clamping rule above. The rule forbids rewriting a
     * value into a semantically DIFFERENT one — a past instant into now, Feb 31
     * into Mar 3, 25:00 into the next day. `" 2030-01-02 03:04:05"` denotes
     * exactly one instant and trimming it changes nothing about which.
     *
     * This case exists because the first version of this suite asserted the
     * opposite and failed: I had written a test for behaviour the
     * implementation never claimed, which is worth a case rather than a silent
     * edit.
     */
    public function testSurroundingWhitespaceIsTrimmedRatherThanRefused(): void
    {
        self::assertSame(
            '2030-01-02 03:04:05',
            Verb::normalizeInstant("  2030-01-02 03:04:05\t", '2026-10-14 12:00:00')
        );
        // ...but trimming does not rescue an otherwise unacceptable value.
        self::assertNull(Verb::normalizeInstant('  2020-01-02 03:04:05  ', '2026-10-14 12:00:00'));
    }

    /**
     * ⚠ The comparison is against a caller-supplied now, not the wall clock, so
     * this rule is testable without freezing time. A suite that reads the real
     * clock would pass today and fail on the day the hardcoded date arrives.
     */
    public function testTheFutureTestUsesTheSuppliedNowNotTheWallClock(): void
    {
        $instant = '2026-10-15 00:00:00';

        self::assertSame($instant, Verb::normalizeInstant($instant, '2026-10-14 00:00:00'));
        self::assertNull(Verb::normalizeInstant($instant, '2026-10-16 00:00:00'));
    }
}
