<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Support\NftCollectionIdentifier;
use BCC\Trust\Onchain\Support\NftCollectionIdentity;
use PHPUnit\Framework\TestCase;

/**
 * The canonical-identity rules, per chain family.
 *
 * These are the rules the database column encodes. If one of them changes,
 * `canonical_identifier` means something different than it did, and every
 * stored row is retroactively reinterpreted — so they are pinned here
 * rather than left to be inferred from call sites.
 */
final class NftCollectionIdentifierTest extends TestCase
{
    // Two REAL Solana mints (well-known Metaplex collection addresses),
    // used only for their shape.
    private const SOL_A = 'J1S9H3QjnRtBbbuD4HjPV6RpRhwuk4zKbxsnCHuTgh9w';
    private const EVM_CHECKSUMMED = '0x6e60bCdF52078A250932CF9FeC174c5F67348845';
    private const COSMOS_BECH32   = 'cosmos1qypqxpq9qcrsszg2pvxq6rs0zqg3yyc5lzv7xu';

    // ── Solana: exact case, never folded ────────────────────────────────

    /**
     * The headline requirement. Two valid mints differing ONLY by letter
     * case are two different collections, and the service must say so.
     */
    public function testTwoSolanaMintsDifferingOnlyByCaseAreDistinctIdentities(): void
    {
        $upper = self::SOL_A;                       // ...Tgh9w
        $lower = 'J1S9H3QjnRtBbbuD4HjPV6RpRhwuk4zKbxsnCHuTGH9W';

        self::assertNotSame($upper, $lower, 'precondition: the two inputs differ only by case');
        self::assertSame(strtolower($upper), strtolower($lower), 'precondition: they differ ONLY by case');

        $a = NftCollectionIdentifier::canonicalize('solana', $upper);
        $b = NftCollectionIdentifier::canonicalize('solana', $lower);

        self::assertTrue($a->isAccepted());
        self::assertTrue($b->isAccepted());
        self::assertNotSame(
            $a->canonical(),
            $b->canonical(),
            'case-folding Solana would merge two distinct mints into one collection'
        );
    }

    public function testSolanaCanonicalIsByteIdenticalToTheInput(): void
    {
        $identity = NftCollectionIdentifier::canonicalize('solana', self::SOL_A);

        self::assertTrue($identity->isAccepted());
        self::assertSame(self::SOL_A, $identity->canonical());
    }

    /**
     * The 99 legacy rows are Magic Eden symbols. None may ever become an
     * identity — that is the defect PR 5a exists to stop repeating.
     */
    public function testMarketplaceSymbolsAreRefusedAsSolanaIdentity(): void
    {
        // Real shapes drawn from the live `source = 'toplist'` rows.
        foreach (['mad_lads', 'okay_bears', 'smb_gen3', 'froganas', 'theheist', 'aurory'] as $symbol) {
            $identity = NftCollectionIdentifier::canonicalize('solana', $symbol);

            self::assertFalse(
                $identity->isAccepted(),
                "'{$symbol}' is a marketplace alias, not a mint, and must never be an identity"
            );
            self::assertSame(NftCollectionIdentity::REASON_NOT_BASE58_MINT, $identity->reason());
        }
    }

    /** Base58 excludes 0, O, I and l — a "mint" containing them is not one. */
    public function testSolanaRejectsNonBase58Characters(): void
    {
        $bad = '0OIl' . substr(self::SOL_A, 4);

        self::assertSame(strlen(self::SOL_A), strlen($bad), 'precondition: length is still plausible');
        self::assertFalse(NftCollectionIdentifier::canonicalize('solana', $bad)->isAccepted());
    }

    public function testSolanaRejectsLengthsOutsideThirtyTwoToFortyFour(): void
    {
        self::assertFalse(NftCollectionIdentifier::canonicalize('solana', str_repeat('a', 31))->isAccepted());
        self::assertTrue(NftCollectionIdentifier::canonicalize('solana', str_repeat('a', 32))->isAccepted());
        self::assertTrue(NftCollectionIdentifier::canonicalize('solana', str_repeat('a', 44))->isAccepted());
        self::assertFalse(NftCollectionIdentifier::canonicalize('solana', str_repeat('a', 45))->isAccepted());
    }

    // ── EVM: case variants converge ─────────────────────────────────────

    public function testEvmCaseVariantsProduceOneCanonicalIdentity(): void
    {
        $checksummed = self::EVM_CHECKSUMMED;
        $lower       = strtolower($checksummed);
        $upper       = '0x' . strtoupper(substr($checksummed, 2));

        $a = NftCollectionIdentifier::canonicalize('evm', $checksummed);
        $b = NftCollectionIdentifier::canonicalize('evm', $lower);
        $c = NftCollectionIdentifier::canonicalize('evm', $upper);

        self::assertTrue($a->isAccepted());
        self::assertSame($lower, $a->canonical(), 'EIP-55 case is a checksum, not identity');
        self::assertSame($a->canonical(), $b->canonical());
        self::assertSame($a->canonical(), $c->canonical());
    }

    public function testEvmRejectsMalformedAddresses(): void
    {
        foreach ([
            '6e60bCdF52078A250932CF9FeC174c5F67348845',   // no 0x
            '0x6e60bCdF52078A250932CF9FeC174c5F673488',   // too short
            '0x6e60bCdF52078A250932CF9FeC174c5F6734884567', // too long
            '0xZZ60bCdF52078A250932CF9FeC174c5F67348845', // non-hex
        ] as $bad) {
            $identity = NftCollectionIdentifier::canonicalize('evm', $bad);
            self::assertFalse($identity->isAccepted(), "'{$bad}' must be refused");
            self::assertSame(NftCollectionIdentity::REASON_BAD_EVM_SHAPE, $identity->reason());
        }
    }

    // ── Cosmos: case variants converge, checksum enforced ───────────────

    public function testCosmosCaseVariantsProduceOneCanonicalIdentity(): void
    {
        $lower = self::COSMOS_BECH32;
        $upper = strtoupper($lower);

        $a = NftCollectionIdentifier::canonicalize('cosmos', $lower);
        $b = NftCollectionIdentifier::canonicalize('cosmos', $upper);

        self::assertTrue($a->isAccepted(), 'precondition: the fixture is valid bech32');
        self::assertTrue($b->isAccepted(), 'bech32 permits an all-uppercase encoding of the same address');
        self::assertSame($a->canonical(), $b->canonical());
        self::assertSame($lower, $a->canonical());
    }

    /**
     * Not a regex — the real checksum. A single flipped character in an
     * otherwise perfectly-shaped bech32 string must be refused.
     */
    public function testCosmosEnforcesTheBech32Checksum(): void
    {
        $valid = self::COSMOS_BECH32;
        // Flip the last data character to a different charset member.
        $broken = substr($valid, 0, -1) . ($valid[strlen($valid) - 1] === 'u' ? 'a' : 'u');

        self::assertTrue(NftCollectionIdentifier::canonicalize('cosmos', $valid)->isAccepted());

        $identity = NftCollectionIdentifier::canonicalize('cosmos', $broken);
        self::assertFalse($identity->isAccepted(), 'a broken checksum must not become an identity');
        self::assertSame(NftCollectionIdentity::REASON_BAD_BECH32, $identity->reason());
    }

    /** bech32 forbids mixed case outright; Bech32::decode() enforces it. */
    public function testCosmosRejectsMixedCase(): void
    {
        $mixed = 'Cosmos1qypqxpq9qcrsszg2pvxq6rs0zqg3yyc5lzv7xu';

        self::assertFalse(NftCollectionIdentifier::canonicalize('cosmos', $mixed)->isAccepted());
    }

    // ── Family is an input, never a guess ───────────────────────────────

    /**
     * The same string canonicalises differently depending on the family it
     * is declared to belong to. That is only possible because the family is
     * supplied, not sniffed — and it is what makes cross-chain identity work.
     */
    public function testTheSameStringIsInterpretedByTheDeclaredFamilyNotItsShape(): void
    {
        $looksLikeBothEvmAndBase58 = self::EVM_CHECKSUMMED;

        $asEvm = NftCollectionIdentifier::canonicalize('evm', $looksLikeBothEvmAndBase58);
        self::assertTrue($asEvm->isAccepted());
        self::assertSame(strtolower($looksLikeBothEvmAndBase58), $asEvm->canonical());

        // Declared solana, the very same bytes are refused: `0` is not in
        // the base58 alphabet. Nothing here inspected the string to decide.
        $asSolana = NftCollectionIdentifier::canonicalize('solana', $looksLikeBothEvmAndBase58);
        self::assertFalse($asSolana->isAccepted());
    }

    // ── Fail closed ─────────────────────────────────────────────────────

    public function testUnsupportedFamiliesAreRefused(): void
    {
        // All four are live in wp_bcc_chains. `utxo` additionally has no
        // fetcher driver and no address validator anywhere in the codebase.
        foreach (['near', 'thorchain', 'polkadot', 'utxo', '', 'EVM', 'unknown'] as $family) {
            $identity = NftCollectionIdentifier::canonicalize($family, self::SOL_A);

            self::assertFalse($identity->isAccepted(), "family '{$family}' must be refused, not guessed");
            self::assertSame(NftCollectionIdentity::REASON_UNSUPPORTED_FAMILY, $identity->reason());
            self::assertFalse(NftCollectionIdentifier::supportsFamily($family));
        }
    }

    public function testSupportedFamiliesAreExactlyTheThreeWithRules(): void
    {
        self::assertTrue(NftCollectionIdentifier::supportsFamily('evm'));
        self::assertTrue(NftCollectionIdentifier::supportsFamily('cosmos'));
        self::assertTrue(NftCollectionIdentifier::supportsFamily('solana'));
    }

    public function testEmptyAndWhitespaceOnlyAreRefused(): void
    {
        foreach (['', '   ', "\t", "\n"] as $blank) {
            $identity = NftCollectionIdentifier::canonicalize('evm', $blank);
            self::assertFalse($identity->isAccepted());
            self::assertSame(NftCollectionIdentity::REASON_EMPTY, $identity->reason());
        }
    }

    /** Truncating an identifier yields a DIFFERENT collection, so refuse. */
    public function testOverlongInputIsRefusedRatherThanTruncated(): void
    {
        $identity = NftCollectionIdentifier::canonicalize('solana', str_repeat('a', 129));

        self::assertFalse($identity->isAccepted());
        self::assertSame(NftCollectionIdentity::REASON_TOO_LONG, $identity->reason());
    }

    public function testSurroundingWhitespaceIsTrimmedNotRejected(): void
    {
        $identity = NftCollectionIdentifier::canonicalize('solana', '  ' . self::SOL_A . "\n");

        self::assertTrue($identity->isAccepted());
        self::assertSame(self::SOL_A, $identity->canonical());
    }

    // ── The result type refuses to be misused ───────────────────────────

    /**
     * A caller that skips `isAccepted()` must crash, not write a null.
     * This is what stops a refusal from silently manufacturing another
     * NULL-canonical legacy row.
     */
    public function testReadingTheCanonicalOfARefusalThrows(): void
    {
        $identity = NftCollectionIdentifier::canonicalize('solana', 'mad_lads');

        self::assertFalse($identity->isAccepted());

        $this->expectException(\LogicException::class);
        $identity->canonical();
    }
}
