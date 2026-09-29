<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\ValueObjects\ContractValidationVerdict;
use BCC\Trust\Onchain\ValueObjects\IntakeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The four-state verdict vocabulary.
 *
 * ── WHAT THESE TESTS PROTECT ────────────────────────────────────────────
 * One property above all: **unavailable evidence never becomes a negative
 * authenticity verdict.** Every other rule here exists to keep that one
 * enforceable — if UNAVAILABLE could persist a row, or could report itself as
 * "decided", the distinction would be cosmetic.
 */
#[CoversClass(ContractValidationVerdict::class)]
final class ContractValidationVerdictTest extends TestCase
{
    // ── The central invariant ───────────────────────────────────────────

    public function testUnavailableIsNotADecisionAboutTheContract(): void
    {
        $v = ContractValidationVerdict::unavailable([
            ContractValidationVerdict::EV_PROVIDER_TIMEOUT,
        ]);

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertFalse($v->isDecided(), 'a provider failure decides nothing about the contract');
        self::assertFalse($v->isValid());
        self::assertFalse($v->mayPersist(), 'an unavailable read must write nothing');
        self::assertNull($v->standard());
        self::assertNull($v->metadata());
    }

    public function testInvalidIsADecisionAndIsStillNotPersistable(): void
    {
        $v = ContractValidationVerdict::invalid([
            ContractValidationVerdict::EV_INTERFACE_DENIED,
        ]);

        self::assertTrue($v->isDecided(), 'the contract answered; this IS a statement about it');
        self::assertFalse($v->isValid());
        // A decided negative still creates no row: BCC stores collections, not
        // rejections.
        self::assertFalse($v->mayPersist());
    }

    public function testUnsupportedIsDecidedButNeverValid(): void
    {
        $v = ContractValidationVerdict::unsupported('ERC-1155', [
            ContractValidationVerdict::EV_STANDARD_DEFERRED,
        ]);

        self::assertTrue($v->isDecided());
        self::assertFalse($v->isValid(), 'ERC-1155 must never be accepted as if it were ERC-721');
        self::assertFalse($v->mayPersist());
        // The standard is retained so the operator copy can say WHY.
        self::assertSame('ERC-1155', $v->standard());
    }

    public function testOnlyValidMayPersist(): void
    {
        $valid = ContractValidationVerdict::valid('ERC-721', IntakeMetadata::unknown());

        self::assertTrue($valid->isValid());
        self::assertTrue($valid->mayPersist());
        self::assertTrue($valid->isDecided());
        self::assertSame('ERC-721', $valid->standard());
        self::assertInstanceOf(IntakeMetadata::class, $valid->metadata());
    }

    /**
     * Every non-valid state must refuse to persist. Stated as a table so a
     * future fifth state cannot be added without deciding this question.
     */
    #[DataProvider('nonPersistableStates')]
    public function testNoNonValidStatePersists(ContractValidationVerdict $v): void
    {
        self::assertFalse($v->mayPersist());
    }

    /** @return array<string, array{0: ContractValidationVerdict}> */
    public static function nonPersistableStates(): array
    {
        return [
            'invalid'     => [ContractValidationVerdict::invalid()],
            'unsupported' => [ContractValidationVerdict::unsupported('ERC-1155')],
            'unavailable' => [ContractValidationVerdict::unavailable()],
        ];
    }

    // ── Evidence is bounded ─────────────────────────────────────────────

    public function testUnknownEvidenceTokensAreDropped(): void
    {
        $v = ContractValidationVerdict::unavailable([
            ContractValidationVerdict::EV_PROVIDER_TIMEOUT,
            'Connection refused by 203.0.113.9 with key sk_live_abcd',
            '<script>alert(1)</script>',
        ]);

        self::assertSame([ContractValidationVerdict::EV_PROVIDER_TIMEOUT], $v->evidence());
    }

    public function testEvidenceIsDeduplicated(): void
    {
        $v = ContractValidationVerdict::unavailable([
            ContractValidationVerdict::EV_PROVIDER_ERROR,
            ContractValidationVerdict::EV_PROVIDER_ERROR,
        ]);

        self::assertSame([ContractValidationVerdict::EV_PROVIDER_ERROR], $v->evidence());
    }

    public function testHasEvidenceAnswersMembership(): void
    {
        $v = ContractValidationVerdict::unavailable([
            ContractValidationVerdict::EV_CHAIN_HAS_NO_WASM,
        ]);

        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_CHAIN_HAS_NO_WASM));
        self::assertFalse($v->hasEvidence(ContractValidationVerdict::EV_INTERFACE_DENIED));
    }
}
