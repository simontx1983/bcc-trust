<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\ValueObjects\IntakeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fully successful provider read must report COMPLETE for every family.
 *
 * ── THE DEFECT THIS EXISTS TO PREVENT ───────────────────────────────────
 * `complete` was defined as "every attempted field resolved" but computed over
 * all five universal fields. EVM deliberately fetches no description; Solana
 * deliberately fetches no description, symbol or supply. Those fields could
 * therefore never resolve, and a completely successful read on two of the three
 * families was **permanently `partial`** — a state that can never be reached
 * tells a reader nothing, and an operator chasing "why is this partial?" would
 * find no answer.
 *
 * NOT_APPLICABLE separates "there was never anything to read" from "we should
 * have read this and could not".
 */
#[CoversClass(IntakeMetadata::class)]
final class IntakeMetadataFamilyStateTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function familyApplicableFields(): array
    {
        return [
            'cosmos' => ['cosmos', ['name', 'symbol', 'description', 'image_url', 'total_supply']],
            'evm'    => ['evm', ['name', 'symbol', 'image_url', 'total_supply']],
            'solana' => ['solana', ['name', 'image_url']],
        ];
    }

    #[DataProvider('familyApplicableFields')]
    public function testEachFamilyDeclaresItsApplicableFields(string $family, array $expected): void
    {
        self::assertSame($expected, IntakeMetadata::applicableFieldsFor($family));
    }

    #[DataProvider('familyApplicableFields')]
    public function testUnsupplyableFieldsStartNotApplicable(string $family, array $applicable): void
    {
        $m = IntakeMetadata::forFamily($family);

        foreach (IntakeMetadata::FIELDS as $f) {
            if (in_array($f, $applicable, true)) {
                self::assertSame(IntakeMetadata::UNKNOWN, $m->stateOf($f), "{$family}/{$f} is applicable");
            } else {
                self::assertSame(
                    IntakeMetadata::NOT_APPLICABLE,
                    $m->stateOf($f),
                    "{$family} cannot supply {$f}, so it is not a shortfall"
                );
            }
        }
    }

    /**
     * ⚠⚠ THE HEADLINE CASE. Answer every applicable field and the state must be
     * COMPLETE — for all three families, not just Cosmos.
     */
    #[DataProvider('familyApplicableFields')]
    public function testAFullySuccessfulReadIsCompleteForEveryFamily(string $family, array $applicable): void
    {
        $m = IntakeMetadata::forFamily($family);
        foreach ($applicable as $f) {
            $m = $m->withAnswered($f, $f === 'total_supply' ? 1000 : "value-{$f}");
        }

        self::assertSame(
            IntakeMetadata::STATE_COMPLETE,
            $m->state(),
            "a successful {$family} read must not be permanently partial"
        );
        self::assertSame(count($applicable), $m->applicableCount());
        self::assertSame(count($applicable), $m->resolvedCount());
    }

    /** A read where every applicable field is genuinely ABSENT is still complete. */
    #[DataProvider('familyApplicableFields')]
    public function testEveryApplicableFieldAbsentIsStillComplete(string $family, array $applicable): void
    {
        $m = IntakeMetadata::forFamily($family);
        foreach ($applicable as $f) {
            $m = $m->withAnswered($f, null);
        }

        self::assertSame(IntakeMetadata::STATE_COMPLETE, $m->state());
        self::assertSame([], $m->writableFields());
    }

    /** One unread APPLICABLE field is partial — that is a real shortfall. */
    #[DataProvider('familyApplicableFields')]
    public function testOneUnreadApplicableFieldIsPartial(string $family, array $applicable): void
    {
        if (count($applicable) < 2) {
            self::markTestSkipped("{$family} has too few applicable fields for this case");
        }

        $m = IntakeMetadata::forFamily($family);
        foreach ($applicable as $i => $f) {
            if ($i === 0) {
                continue; // leave the first one unread
            }
            $m = $m->withAnswered($f, 'x');
        }

        self::assertSame(IntakeMetadata::STATE_PARTIAL, $m->state());
    }

    #[DataProvider('familyApplicableFields')]
    public function testNoApplicableFieldResolvedIsUnavailable(string $family, array $applicable): void
    {
        self::assertSame(IntakeMetadata::STATE_UNAVAILABLE, IntakeMetadata::forFamily($family)->state());
    }

    // ── NOT_APPLICABLE is never a value and cannot be overwritten ────────

    public function testANotApplicableFieldIsNeverWritable(): void
    {
        // Solana cannot supply a symbol; offering one must not store it.
        $m = IntakeMetadata::forFamily('solana')->withAnswered('symbol', 'MAD');

        self::assertSame(IntakeMetadata::NOT_APPLICABLE, $m->stateOf('symbol'));
        self::assertArrayNotHasKey('symbol', $m->writableFields());
        self::assertNull($m->valueOf('symbol'));
    }

    public function testANotApplicableFieldCannotBeDowngradedToUnknown(): void
    {
        $m = IntakeMetadata::forFamily('solana');
        self::assertSame(IntakeMetadata::NOT_APPLICABLE, $m->stateOf('description'));

        $m = $m->withUnknown('description');

        self::assertSame(
            IntakeMetadata::NOT_APPLICABLE,
            $m->stateOf('description'),
            'marking it unknown would hold a good read at partial forever'
        );
    }

    public function testIsApplicableReportsTheDistinction(): void
    {
        $m = IntakeMetadata::forFamily('evm');

        self::assertTrue($m->isApplicable('name'));
        self::assertFalse($m->isApplicable('description'), 'EVM does not fetch a description');
    }

    /** NOT_APPLICABLE counts as resolved, so it never blocks completion. */
    public function testNotApplicableCountsAsResolvedForCompletionPurposes(): void
    {
        $m = IntakeMetadata::forFamily('solana');

        self::assertTrue($m->isResolved('description'));
        self::assertFalse($m->isResolved('name'), 'name is applicable and still unread');
    }

    public function testAnUnknownFamilyTreatsEveryFieldAsApplicable(): void
    {
        // Fail closed: a family nobody declared might supply anything, so
        // nothing is written off as not applicable.
        $m = IntakeMetadata::forFamily('aptos');

        self::assertSame(IntakeMetadata::FIELDS, IntakeMetadata::applicableFieldsFor('aptos'));
        foreach (IntakeMetadata::FIELDS as $f) {
            self::assertSame(IntakeMetadata::UNKNOWN, $m->stateOf($f));
        }
    }
}
