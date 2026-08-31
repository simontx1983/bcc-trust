<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Support\Base58;
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
    /**
     * Two genuine 32-byte public keys differing ONLY in the final
     * character's case. Both are proven to decode to exactly 32 bytes by
     * {@see testTheCaseDistinctFixturesAreGenuineThirtyTwoByteKeys} BEFORE
     * the uniqueness test below relies on them — otherwise that test would
     * be asserting on two strings that merely look like keys.
     */
    private const SOL_A = '7cmUkdkC4Z5fBWk42hqnvjPftNNWwBy9GKe6FcyFVwH9';
    private const SOL_B = '7cmUkdkC4Z5fBWk42hqnvjPftNNWwBy9GKe6FcyFVwh9';

    /** Real Solana program/mint ids — every one is a 32-byte key. */
    private const REAL_KEYS = [
        'System Program'      => '11111111111111111111111111111111',
        'Token Program'       => 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA',
        'Assoc Token Program' => 'ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL',
        'Memo Program'        => 'MemoSq4gqABAXKb96qnH8TysNcWxMyWCqXgDLGmfcHr',
        'USDC mint'           => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
        'Wrapped SOL mint'    => 'So11111111111111111111111111111111111111112',
        'Metaplex Token Meta' => 'metaqbxxUerdq28cj1RbAWkYQm3ybzjb6a8bt518x1s',
    ];

    /** Base58-shaped, right length band, but decodes to 31 / 33 bytes. */
    private const DECODES_TO_31 = 'mhuioF2uqSeGwJustsK8U4xo6Pbqc4m9TFR86wKeaY';
    private const DECODES_TO_33 = 'V144wP39My4EhyEkJqXwXby9HqEFZ8ZHbpf1DBcZoyrW';

    private const EVM_CHECKSUMMED = '0x6e60bCdF52078A250932CF9FeC174c5F67348845';
    private const COSMOS_BECH32   = 'cosmos1qypqxpq9qcrsszg2pvxq6rs0zqg3yyc5lzv7xu';

    // ── Solana: a real 32-byte key, not a shape ─────────────────────────

    /** Known-good anchor: if these fail, the decoder itself is wrong. */
    public function testRealSolanaPublicKeysAreAccepted(): void
    {
        foreach (self::REAL_KEYS as $label => $key) {
            self::assertSame(
                32,
                Base58::decodedLength($key),
                "{$label} must decode to 32 bytes"
            );
            self::assertTrue(
                NftCollectionIdentifier::canonicalize('solana', $key)->isAccepted(),
                "{$label} is a real Solana address and must be accepted"
            );
        }
    }

    /**
     * The System Program id is 32 '1' characters — every byte is zero. It
     * is the case that a naive decoder gets wrong by stripping leading
     * zeros, so it pins the leading-'1' handling specifically.
     */
    public function testTheAllZeroKeyDecodesToThirtyTwoZeroBytes(): void
    {
        $decoded = Base58::decode('11111111111111111111111111111111');

        self::assertSame(32, strlen((string) $decoded));
        self::assertSame(str_repeat("\x00", 32), $decoded);
    }

    /**
     * Guards the fixtures the uniqueness test depends on. Without this,
     * that test could pass on two strings that are not keys at all.
     */
    public function testTheCaseDistinctFixturesAreGenuineThirtyTwoByteKeys(): void
    {
        self::assertSame(32, Base58::decodedLength(self::SOL_A), 'fixture A must be a real 32-byte key');
        self::assertSame(32, Base58::decodedLength(self::SOL_B), 'fixture B must be a real 32-byte key');

        self::assertNotSame(self::SOL_A, self::SOL_B, 'the fixtures must differ');
        self::assertSame(
            strtolower(self::SOL_A),
            strtolower(self::SOL_B),
            'the fixtures must differ ONLY by case'
        );
        self::assertNotSame(
            Base58::decode(self::SOL_A),
            Base58::decode(self::SOL_B),
            'they must be genuinely different keys, not one key spelled twice'
        );
    }

    /**
     * The headline requirement. Two valid mints differing ONLY by letter
     * case are two different collections, and the service must say so.
     */
    public function testTwoSolanaMintsDifferingOnlyByCaseAreDistinctIdentities(): void
    {
        $a = NftCollectionIdentifier::canonicalize('solana', self::SOL_A);
        $b = NftCollectionIdentifier::canonicalize('solana', self::SOL_B);

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
        self::assertSame(self::SOL_A, $identity->canonical(), 'the ENCODED text is the identity');
    }

    /**
     * The defect this rule replaced: a base58-SHAPED string in the right
     * length band is not a public key. Both of these satisfy
     * `/^[1-9A-HJ-NP-Za-km-z]{32,44}$/` and neither is 32 bytes.
     */
    public function testBase58ShapedValuesThatDoNotDecodeToThirtyTwoBytesAreRejected(): void
    {
        self::assertSame(31, Base58::decodedLength(self::DECODES_TO_31));
        self::assertSame(33, Base58::decodedLength(self::DECODES_TO_33));

        foreach ([self::DECODES_TO_31, self::DECODES_TO_33] as $notAKey) {
            self::assertMatchesRegularExpression(
                '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/',
                $notAKey,
                'precondition: the OLD shape-only rule would have accepted this'
            );

            $identity = NftCollectionIdentifier::canonicalize('solana', $notAKey);
            self::assertFalse($identity->isAccepted(), 'only a 32-byte decode is a Solana key');
            self::assertSame(NftCollectionIdentity::REASON_NOT_BASE58_MINT, $identity->reason());
        }
    }

    /**
     * Pins the exact values the removed length-only tests used to assert
     * were valid. They are not keys, and now they are refused.
     */
    public function testRepeatedCharacterStringsAreNotKeys(): void
    {
        self::assertSame(24, Base58::decodedLength(str_repeat('a', 32)));
        self::assertSame(33, Base58::decodedLength(str_repeat('a', 44)));

        self::assertFalse(NftCollectionIdentifier::canonicalize('solana', str_repeat('a', 32))->isAccepted());
        self::assertFalse(NftCollectionIdentifier::canonicalize('solana', str_repeat('a', 44))->isAccepted());
    }

    /**
     * Pre-PR-5a Solana rows hold Magic Eden symbols. None may ever become an
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
        self::assertNull(Base58::decode($bad), 'the decoder must reject the alphabet violation outright');
        self::assertFalse(NftCollectionIdentifier::canonicalize('solana', $bad)->isAccepted());
    }

    /**
     * Length is a pre-filter, not the rule. Anything outside 32-44 cannot
     * be a 32-byte key, so it is refused before the decoder runs — but
     * passing the band proves nothing on its own (see the 31/33-byte test).
     */
    public function testSolanaRejectsLengthsOutsideThePreFilterBand(): void
    {
        self::assertFalse(NftCollectionIdentifier::canonicalize('solana', str_repeat('a', 31))->isAccepted());
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
