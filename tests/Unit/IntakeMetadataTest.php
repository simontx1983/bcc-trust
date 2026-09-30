<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\ValueObjects\IntakeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * UNKNOWN must stay distinguishable from ABSENT.
 *
 * ── THE DEFECT THESE TESTS EXIST TO PREVENT ─────────────────────────────
 * A failed metadata read writes NULL into `image_url`. A collection that
 * genuinely has no image also reads NULL. Once stored, they are the same byte,
 * so the row looks complete and nothing ever retries it — a transient outage
 * becomes a permanent claim that the collection has no image.
 *
 * DECISION 13 answers it with explicit state rather than inference. These
 * tests pin the three field states, the rolled-up value, and the rule that
 * decides what may be written.
 */
#[CoversClass(IntakeMetadata::class)]
final class IntakeMetadataTest extends TestCase
{
    public function testEverythingStartsUnknown(): void
    {
        $m = IntakeMetadata::unknown();

        foreach (IntakeMetadata::FIELDS as $f) {
            self::assertSame(IntakeMetadata::UNKNOWN, $m->stateOf($f), "{$f} should start unknown");
            self::assertFalse($m->isResolved($f));
        }

        self::assertSame(IntakeMetadata::STATE_UNAVAILABLE, $m->state());
        self::assertSame(0, $m->resolvedCount());
        self::assertSame([], $m->writableFields(), 'nothing may be written before anything was asked');
    }

    // ── The distinction ─────────────────────────────────────────────────

    public function testAnsweredWithAValueIsKnown(): void
    {
        $m = IntakeMetadata::unknown()->withAnswered('name', 'Blue Collar Apes');

        self::assertSame(IntakeMetadata::KNOWN, $m->stateOf('name'));
        self::assertTrue($m->isKnown('name'));
        self::assertTrue($m->isResolved('name'));
        self::assertSame('Blue Collar Apes', $m->valueOf('name'));
    }

    public function testAnsweredWithNothingIsAbsentNotUnknown(): void
    {
        // The provider ANSWERED, and the collection has no image.
        $m = IntakeMetadata::unknown()->withAnswered('image_url', null);

        self::assertSame(IntakeMetadata::ABSENT, $m->stateOf('image_url'));
        self::assertTrue($m->isResolved('image_url'), 'absent is an answer');
        self::assertFalse($m->isKnown('image_url'));
    }

    public function testAnEmptyStringIsAbsentNotAValue(): void
    {
        $m = IntakeMetadata::unknown()->withAnswered('symbol', '   ');

        self::assertSame(IntakeMetadata::ABSENT, $m->stateOf('symbol'));
        self::assertNull($m->valueOf('symbol'));
    }

    public function testUnknownIsDistinctFromAbsent(): void
    {
        $absent  = IntakeMetadata::unknown()->withAnswered('image_url', null);
        $unknown = IntakeMetadata::unknown()->withUnknown('image_url');

        // Both have a null VALUE …
        self::assertNull($absent->valueOf('image_url'));
        self::assertNull($unknown->valueOf('image_url'));

        // … and that is exactly why the STATE has to carry the difference.
        self::assertNotSame($absent->stateOf('image_url'), $unknown->stateOf('image_url'));
        self::assertTrue($absent->isResolved('image_url'));
        self::assertFalse($unknown->isResolved('image_url'));
    }

    // ── What may be written ─────────────────────────────────────────────

    public function testOnlyKnownFieldsAreWritable(): void
    {
        $m = IntakeMetadata::unknown()
            ->withAnswered('name', 'Kujira Cats')
            ->withAnswered('image_url', null)   // absent
            ->withUnknown('symbol');            // unknown

        $writable = $m->writableFields();

        self::assertSame(['name' => 'Kujira Cats'], $writable);
        self::assertArrayNotHasKey('image_url', $writable, 'absent must not overwrite a stored value with null');
        self::assertArrayNotHasKey('symbol', $writable, 'unknown must never be written');
    }

    // ── Rolled-up state ─────────────────────────────────────────────────

    public function testStateIsUnavailableWhenNothingResolved(): void
    {
        self::assertSame(IntakeMetadata::STATE_UNAVAILABLE, IntakeMetadata::unknown()->state());
    }

    public function testStateIsPartialWhenSomeResolved(): void
    {
        $m = IntakeMetadata::unknown()->withAnswered('name', 'Partial Collection');

        self::assertSame(IntakeMetadata::STATE_PARTIAL, $m->state());
        self::assertSame(1, $m->resolvedCount());
    }

    public function testStateIsCompleteOnlyWhenEveryFieldResolved(): void
    {
        $m = IntakeMetadata::unknown();
        foreach (IntakeMetadata::FIELDS as $f) {
            $m = $m->withAnswered($f, $f === 'total_supply' ? 100 : "v-{$f}");
        }

        self::assertSame(IntakeMetadata::STATE_COMPLETE, $m->state());
        self::assertSame(count(IntakeMetadata::FIELDS), $m->resolvedCount());
    }

    public function testCompleteIncludesFieldsAnsweredAsAbsent(): void
    {
        // A collection that genuinely has no description and no image is
        // COMPLETE — we asked everything and got an answer every time.
        $m = IntakeMetadata::unknown();
        foreach (IntakeMetadata::FIELDS as $f) {
            $m = $m->withAnswered($f, null);
        }

        self::assertSame(IntakeMetadata::STATE_COMPLETE, $m->state());
        self::assertSame([], $m->writableFields());
    }

    public function testOneFailedFieldDowngradesCompleteToPartial(): void
    {
        $m = IntakeMetadata::unknown();
        foreach (IntakeMetadata::FIELDS as $f) {
            $m = $m->withAnswered($f, 'x');
        }
        self::assertSame(IntakeMetadata::STATE_COMPLETE, $m->state());

        $m = $m->withUnknown('image_url');
        self::assertSame(IntakeMetadata::STATE_PARTIAL, $m->state());
    }

    // ── Immutability ────────────────────────────────────────────────────

    public function testWithersDoNotMutateTheOriginal(): void
    {
        $base = IntakeMetadata::unknown();
        $next = $base->withAnswered('name', 'Something');

        self::assertSame(IntakeMetadata::UNKNOWN, $base->stateOf('name'));
        self::assertSame(IntakeMetadata::KNOWN, $next->stateOf('name'));
    }

    public function testUnrecognisedFieldsAreIgnored(): void
    {
        // Notably `floor_price` — a field this model must never carry.
        $m = IntakeMetadata::unknown()->withAnswered('floor_price', '12.5');

        self::assertSame([], $m->writableFields());
        self::assertSame(IntakeMetadata::STATE_UNAVAILABLE, $m->state());
    }

    public function testStateMapCoversEveryFieldForTheAdminScreen(): void
    {
        $map = IntakeMetadata::unknown()->withAnswered('name', 'X')->stateMap();

        self::assertSame(IntakeMetadata::FIELDS, array_keys($map));
        self::assertSame(IntakeMetadata::KNOWN, $map['name']);
        self::assertSame(IntakeMetadata::UNKNOWN, $map['total_supply']);
    }

    /** No price or marketplace field may exist in this model at all. */
    public function testNoPriceOrMarketplaceFieldIsTracked(): void
    {
        foreach (['floor_price', 'floor_currency', 'total_volume', 'listed_percentage', 'last_sale'] as $banned) {
            self::assertNotContains($banned, IntakeMetadata::FIELDS);
        }
    }
}
