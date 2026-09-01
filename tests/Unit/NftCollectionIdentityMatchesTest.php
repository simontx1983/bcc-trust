<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Support\NftCollectionIdentifier;
use PHPUnit\Framework\TestCase;

/**
 * `NftCollectionIdentifier::matches()` is the single place allowed to
 * decide that two identifiers name the same collection.
 *
 * The tests that matter here are the ones that would have PASSED under the
 * old `strtolower()` comparison and must now FAIL — and the EVM ones,
 * which must still behave exactly as before. "Make it case-sensitive" is
 * the wrong fix; only a per-family rule is correct.
 */
final class NftCollectionIdentityMatchesTest extends TestCase
{
    /** A real 32-byte Solana key (wrapped SOL). */
    private const SOL_MINT = 'So11111111111111111111111111111111111111112';

    /** From the repair manifest — mixed case on purpose. */
    private const SOL_MIXED = '8Db41NmU1i3gSPq6AZWK1tsndJPPTLRP22LDGAz8CHxD';

    private const EVM_LOWER = '0xbc4ca0eda7647a8ab7c2061c2e118a18a936f13d';
    private const EVM_MIXED = '0xBC4CA0EdA7647A8aB7C2061c2E118A18a936f13D';

    /** Valid bech32 with a real checksum. */
    private const COSMOS_ADDR = 'cosmos1qypqxpq9qcrsszg2pvxq6rs0zqg3yyc5lzv7xu';

    // ── Solana: the defect ──────────────────────────────────────────────

    public function testSolanaMatchesItselfExactly(): void
    {
        self::assertTrue(NftCollectionIdentifier::matches('solana', self::SOL_MIXED, self::SOL_MIXED));
    }

    /**
     * THE regression. Lower-casing a base58 mint yields a different key,
     * and the old comparison treated the two as equal — which is how a
     * stored alias could be "compared" against a real mint and produce a
     * confident, wrong zero.
     */
    public function testSolanaDoesNotMatchACaseFoldedCopyOfItself(): void
    {
        $folded = strtolower(self::SOL_MIXED);

        self::assertNotSame(self::SOL_MIXED, $folded, 'fixture must actually contain upper-case characters');
        self::assertFalse(
            NftCollectionIdentifier::matches('solana', self::SOL_MIXED, $folded),
            'a case-folded mint is a DIFFERENT key and must not match'
        );
    }

    /**
     * Two valid 32-byte keys differing only by case stay distinct.
     * Constructed rather than hand-picked so the property is exercised
     * rather than asserted about one lucky pair.
     */
    public function testTwoValidKeysDifferingOnlyByCaseStayDistinct(): void
    {
        $a = self::SOL_MIXED;
        $b = self::flipOneLetterCase($a);

        // Both must genuinely be valid keys, or the test proves nothing.
        self::assertTrue(NftCollectionIdentifier::canonicalize('solana', $a)->isAccepted());
        self::assertTrue(NftCollectionIdentifier::canonicalize('solana', $b)->isAccepted());
        self::assertNotSame($a, $b);

        self::assertFalse(NftCollectionIdentifier::matches('solana', $a, $b));
        self::assertFalse(NftCollectionIdentifier::matches('solana', $b, $a));
    }

    /**
     * A marketplace alias is not an identity, so it matches nothing —
     * including itself. This is the property that turns the old silent
     * "count 0" into an explicit refusal upstream.
     */
    public function testAnAliasMatchesNothingAtAll(): void
    {
        self::assertFalse(NftCollectionIdentifier::matches('solana', self::SOL_MINT, 'bozosgroup'));
        self::assertFalse(NftCollectionIdentifier::matches('solana', 'bozosgroup', 'bozosgroup'));
    }

    /**
     * A refused candidate is NOT a match, and must never fall back to a
     * case-insensitive compare — that fallback is the drift this method
     * exists to remove.
     */
    public function testARefusedCandidateIsNotAMatch(): void
    {
        foreach (['', '   ', 'not base58 !!!', str_repeat('a', 44), str_repeat('a', 32)] as $candidate) {
            self::assertFalse(
                NftCollectionIdentifier::matches('solana', self::SOL_MINT, $candidate),
                "candidate '{$candidate}' must not match"
            );
        }
    }

    /**
     * Fails CLOSED when the documented contract is broken: a non-canonical
     * target can never equal a canonicalised candidate, so the answer is
     * "no match" rather than an accidental true.
     */
    public function testANonCanonicalTargetNeverMatches(): void
    {
        self::assertFalse(
            NftCollectionIdentifier::matches('solana', strtolower(self::SOL_MIXED), self::SOL_MIXED)
        );
        self::assertFalse(NftCollectionIdentifier::matches('solana', '', self::SOL_MIXED));
    }

    // ── EVM: must be unchanged ──────────────────────────────────────────

    /**
     * EIP-55 case is a checksum, not identity. Making everything
     * case-sensitive would split one contract into two rows — the opposite
     * bug, and just as real.
     */
    public function testEvmStillMatchesAcrossCase(): void
    {
        self::assertTrue(NftCollectionIdentifier::matches('evm', self::EVM_LOWER, self::EVM_MIXED));
        self::assertTrue(NftCollectionIdentifier::matches('evm', self::EVM_LOWER, self::EVM_LOWER));
        // Body fully upper-cased, `0x` prefix intact.
        self::assertTrue(
            NftCollectionIdentifier::matches(
                'evm',
                self::EVM_LOWER,
                '0x' . strtoupper(substr(self::EVM_LOWER, 2))
            )
        );
    }

    /**
     * Documents an inherited PR 5a property rather than a PR 5b decision:
     * the EVM pattern requires a literal lower-case `0x` prefix, so `0X…`
     * is REFUSED even though the address is otherwise identical.
     *
     * Pinned because it is surprising. A future reader who "fixes" the
     * prefix to be case-insensitive should do it deliberately, with this
     * test as the place that says so — not discover it by accident when an
     * upstream provider starts emitting `0X`.
     */
    public function testEvmRefusesAnUpperCaseZeroXPrefix(): void
    {
        $upperPrefix = '0X' . substr(self::EVM_LOWER, 2);

        self::assertFalse(
            NftCollectionIdentifier::canonicalize('evm', $upperPrefix)->isAccepted()
        );
        self::assertFalse(
            NftCollectionIdentifier::matches('evm', self::EVM_LOWER, $upperPrefix)
        );
    }

    public function testEvmDoesNotMatchADifferentContract(): void
    {
        $other = '0x' . str_repeat('a', 40);
        self::assertFalse(NftCollectionIdentifier::matches('evm', self::EVM_LOWER, $other));
    }

    // ── Cosmos: must be unchanged ───────────────────────────────────────

    public function testCosmosMatchesItsOwnCanonicalForm(): void
    {
        $identity = NftCollectionIdentifier::canonicalize('cosmos', self::COSMOS_ADDR);
        self::assertTrue($identity->isAccepted(), 'fixture must be a valid bech32 address');

        self::assertTrue(
            NftCollectionIdentifier::matches('cosmos', $identity->canonical(), self::COSMOS_ADDR)
        );
    }

    /**
     * Bech32 forbids mixed case outright, so a mixed-case candidate is
     * refused — not folded. Same visible outcome as before for well-formed
     * data, which is what keeps the 20 Cosmos gates unchanged.
     */
    public function testCosmosRefusesMixedCaseRatherThanFoldingIt(): void
    {
        $identity = NftCollectionIdentifier::canonicalize('cosmos', self::COSMOS_ADDR);
        self::assertTrue($identity->isAccepted());

        $mixed = ucfirst(self::COSMOS_ADDR);
        self::assertNotSame(self::COSMOS_ADDR, $mixed);

        self::assertFalse(NftCollectionIdentifier::matches('cosmos', $identity->canonical(), $mixed));
    }

    // ── Unknown families fail closed ────────────────────────────────────

    public function testAnUnsupportedFamilyNeverMatches(): void
    {
        foreach (['near', 'thorchain', 'polkadot', 'utxo', '', 'SOLANA'] as $family) {
            self::assertFalse(
                NftCollectionIdentifier::matches($family, self::SOL_MINT, self::SOL_MINT),
                "family '{$family}' must not resolve to a match"
            );
        }
    }

    /**
     * Flip the case of the first alphabetic character that stays inside the
     * base58 alphabet when flipped (base58 omits 0/O/I/l).
     */
    private static function flipOneLetterCase(string $value): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $char    = $value[$i];
            $flipped = ctype_upper($char) ? strtolower($char) : strtoupper($char);

            if ($flipped === $char || strpos($alphabet, $flipped) === false) {
                continue;
            }

            $candidate = substr_replace($value, $flipped, $i, 1);

            // Only useful if the result is still a real 32-byte key.
            if (NftCollectionIdentifier::canonicalize('solana', $candidate)->isAccepted()) {
                return $candidate;
            }
        }

        self::fail('could not construct a case-variant that is still a valid 32-byte key');
    }
}
