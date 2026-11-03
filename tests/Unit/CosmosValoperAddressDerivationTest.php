<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Support\Bech32;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: resolving a validator operator address never produces a
 * string the chain cannot decode, and never overwrites the chain's own
 * spelling of an address it already gave us.
 *
 * ── WHY THIS SUITE EXISTS ───────────────────────────────────────────────
 * `ensureValoperPrefix()` used to swap the bech32 HRP with `str_replace()`
 * and keep the original checksum. A bech32 checksum is computed over the HRP,
 * so the result does not validate. Against Crypto.org Chain -- whose operator
 * HRP is `crocncl`, not `crovaloper` -- that turned 101 perfectly good stored
 * addresses into malformed ones and produced 1,241 production
 * `LCD 500 ... decoding bech32 failed: invalid checksum` errors in ten days,
 * 81% of all production error volume. Each was retried four times and each
 * charged the chain's circuit breaker, which then skipped that chain's
 * indexing 61 times, which is why its validator pages were never created.
 *
 * ⚠ THE SAME DEFECT WAS SILENTLY BREAKING EVERY CHAIN, not just Crypto.org.
 * The account-address path (WalletSeedService asking "is this wallet a
 * validator operator?") also kept the stale checksum, so it produced an
 * invalid `cosmosvaloper1…` too. That failure was invisible because an empty
 * fetch result simply means "not a validator". `testAStandardChainAccount…`
 * below is the assertion that would have caught it: it demands the exact
 * operator address the chain really publishes, which only a recomputed
 * checksum can produce.
 *
 * ⚠⚠ EVERY ADDRESS HERE IS REAL, read from each chain's own LCD validator
 * list, and every derived form (account spelling, the old broken output, the
 * conventional spelling) was COMPUTED with Support\Bech32 rather than typed.
 * A hand-typed bech32 fixture is a guess with a checksum on it.
 *
 * @see \BCC\Trust\Onchain\Support\Bech32
 */
#[CoversClass(CosmosFetcher::class)]
final class CosmosValoperAddressDerivationTest extends TestCase
{
    /** Crypto.org Chain: account HRP `cro`, operator HRP `crocncl`. */
    private const CRO_OPERATOR_A = 'crocncl1qpjtgawphgvq5j9cf7maltldzuusuj8ufrm2yc';
    private const CRO_OPERATOR_B = 'crocncl1qz7k6tlc37u02yw95pp2rx2dgw0uraxaxne0v8';
    private const CRO_ACCOUNT_A  = 'cro1qpjtgawphgvq5j9cf7maltldzuusuj8u2wcrxy';

    /** What the old str_replace() produced for CRO_OPERATOR_A: invalid. */
    private const CRO_OLD_BROKEN_A = 'crovaloper1qpjtgawphgvq5j9cf7maltldzuusuj8ufrm2yc';

    /** The conventional (valid, but NOT Crypto.org's) spelling of the same bytes. */
    private const CRO_CONVENTIONAL_A = 'crovaloper1qpjtgawphgvq5j9cf7maltldzuusuj8us8zfht';

    /** Cosmos Hub: conventional chain, operator HRP `cosmosvaloper`. */
    private const HUB_OPERATOR  = 'cosmosvaloper1qphf0ferqcch0jca9hlqfm3x0eds3dpkcvpafp';
    private const HUB_ACCOUNT   = 'cosmos1qphf0ferqcch0jca9hlqfm3x0eds3dpkac4g9j';
    private const HUB_OPERATOR2 = 'cosmosvaloper1qr6sk28w4r6kqsg0737wzgu05505t4glezetwn';
    private const HUB_ACCOUNT2  = 'cosmos1qr6sk28w4r6kqsg0737wzgu05505t4glukd7zq';

    /**
     * Invoke the private resolver on a real CosmosFetcher.
     *
     * The constructor is bypassed deliberately: it builds an endpoint
     * fingerprint and a refusal reason, and this method reads exactly one
     * property. Bypassing it keeps the test pure -- no network, no
     * WordPress, no endpoint policy -- while still exercising the real
     * method and the real Support\Bech32.
     */
    private function resolve(string $address, ?string $bech32Prefix): string
    {
        $rc       = new \ReflectionClass(CosmosFetcher::class);
        $fetcher  = $rc->newInstanceWithoutConstructor();

        $chain = new \stdClass();
        if ($bech32Prefix !== null) {
            $chain->bech32_prefix = $bech32Prefix;
        }

        $chainProp = $rc->getProperty('chain');
        $chainProp->setAccessible(true);
        $chainProp->setValue($fetcher, $chain);

        $method = $rc->getMethod('ensureValoperPrefix');
        $method->setAccessible(true);

        return (string) $method->invoke($fetcher, $address);
    }

    // ── 1. The Crypto.org regression ────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function cryptoOrgOperatorAddresses(): array
    {
        return [
            'first validator'  => [self::CRO_OPERATOR_A],
            'second validator' => [self::CRO_OPERATOR_B],
        ];
    }

    #[DataProvider('cryptoOrgOperatorAddresses')]
    public function testACryptoOrgOperatorAddressIsReturnedUnchanged(string $operator): void
    {
        $this->assertSame(
            $operator,
            $this->resolve($operator, 'cro'),
            'A crocncl operator address came from the chain itself. Rewriting it is the bug.'
        );
    }

    /**
     * ⚠ THE PLANTED POSITIVE. Without this, a regression that reinstated
     * string substitution could still satisfy "returns a string".
     */
    public function testTheOldSubstitutionProducedAnAddressTheChainCannotDecode(): void
    {
        $this->assertNull(
            Bech32::decode(self::CRO_OLD_BROKEN_A),
            'crovaloper + the crocncl checksum must NOT validate; if it did, '
            . 'there would have been no bug and this suite would be meaningless.'
        );

        $this->assertNotSame(
            self::CRO_OLD_BROKEN_A,
            $this->resolve(self::CRO_OPERATOR_A, 'cro'),
            'The resolver must no longer emit the malformed address.'
        );
    }

    // ── 2. Standard chains are unchanged ────────────────────────────────

    /** @return array<string, array{0: string, 1: string}> */
    public static function conventionalOperatorAddresses(): array
    {
        return [
            'cosmos hub'    => [self::HUB_OPERATOR, 'cosmos'],
            'cosmos hub #2' => [self::HUB_OPERATOR2, 'cosmos'],
        ];
    }

    #[DataProvider('conventionalOperatorAddresses')]
    public function testAStandardChainOperatorAddressIsReturnedUnchanged(
        string $operator,
        string $prefix
    ): void {
        $this->assertSame($operator, $this->resolve($operator, $prefix));
    }

    /**
     * ⭐ THE STRONGEST ASSERTION IN THE SUITE. It demands the exact operator
     * address the chain publishes, derived from the account address. Only a
     * recomputed checksum can satisfy it -- the old code produced
     * `cosmosvaloper1…ac4g9j` here, where the real address ends `cvpafp`.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function accountToOperatorPairs(): array
    {
        return [
            'hub validator'    => [self::HUB_ACCOUNT, 'cosmos', self::HUB_OPERATOR],
            'hub validator #2' => [self::HUB_ACCOUNT2, 'cosmos', self::HUB_OPERATOR2],
        ];
    }

    #[DataProvider('accountToOperatorPairs')]
    public function testAStandardChainAccountAddressBecomesTheRealOperatorAddress(
        string $account,
        string $prefix,
        string $expectedOperator
    ): void {
        $this->assertSame($expectedOperator, $this->resolve($account, $prefix));
    }

    public function testTheConversionRecomputesTheChecksumRatherThanReusingIt(): void
    {
        $naive = \str_replace('cosmos1', 'cosmosvaloper1', self::HUB_ACCOUNT);

        $this->assertNull(
            Bech32::decode($naive),
            'Sanity: reusing the account checksum under the valoper HRP is invalid.'
        );
        $this->assertNotSame($naive, $this->resolve(self::HUB_ACCOUNT, 'cosmos'));
        $this->assertNotNull(Bech32::decode($this->resolve(self::HUB_ACCOUNT, 'cosmos')));
    }

    // ── 3. Nothing it returns is ever malformed ─────────────────────────

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function everyInput(): array
    {
        return [
            'cro operator'       => [self::CRO_OPERATOR_A, 'cro'],
            'cro operator #2'    => [self::CRO_OPERATOR_B, 'cro'],
            'cro account'        => [self::CRO_ACCOUNT_A, 'cro'],
            'hub operator'       => [self::HUB_OPERATOR, 'cosmos'],
            'hub account'        => [self::HUB_ACCOUNT, 'cosmos'],
        ];
    }

    #[DataProvider('everyInput')]
    public function testEveryValidInputYieldsADecodableAddress(string $address, ?string $prefix): void
    {
        $out = $this->resolve($address, $prefix);

        $this->assertNotNull(
            Bech32::decode($out),
            "Resolver returned a string that fails checksum verification: {$out}"
        );
    }

    // ── 4. Fail-safe behaviour ──────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function nonBech32Inputs(): array
    {
        return [
            'empty'              => [''],
            'no separator'       => ['notanaddress'],
            'separator only'     => ['1'],
            'bad checksum'       => ['cosmos1qphf0ferqcch0jca9hlqfm3x0eds3dpkzzzzzz'],
            'invalid charset'    => ['cosmos1bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
            'too short for sum'  => ['cosmos1qp'],
            'near-protocol id'   => ['00aboo203.pool.near'],
            'solana base58'      => ['11AMA4mnNbsrPQeuoNN7uiZVJZtqEzQHHHQQQQQQQQQ'],
        ];
    }

    #[DataProvider('nonBech32Inputs')]
    public function testAnUndecodableAddressIsPassedThroughRatherThanRewritten(string $address): void
    {
        $this->assertSame(
            $address,
            $this->resolve($address, 'cosmos'),
            'Manufacturing an address out of unparseable input is worse than '
            . 'letting the provider reject what the caller actually sent.'
        );
    }

    /** @return array<string, array{0: ?string}> */
    public static function unusablePrefixes(): array
    {
        return [
            'absent'        => [null],
            'empty string'  => [''],
            'uppercase'     => ['COSMOS'],
            'digits'        => ['cosmos2'],
            'hyphenated'    => ['crypto-org'],
            'underscored'   => ['crypto_org'],
        ];
    }

    #[DataProvider('unusablePrefixes')]
    public function testAnUnusableBech32PrefixLeavesTheAddressAlone(?string $prefix): void
    {
        $this->assertSame(
            self::HUB_ACCOUNT,
            $this->resolve(self::HUB_ACCOUNT, $prefix)
        );
    }

    public function testMixedCaseIsRefusedRatherThanNormalised(): void
    {
        $mixed = 'Cosmos1qphf0ferqcch0jca9hlqfm3x0eds3dpkac4g9j';

        $this->assertSame(
            $mixed,
            $this->resolve($mixed, 'cosmos'),
            'BIP-173 forbids mixed case; Bech32::decode refuses it, so the '
            . 'resolver must pass it through untouched.'
        );
    }

    // ── 5. The limitation, pinned so it stays visible ───────────────────

    /**
     * ⚠ KNOWN LIMITATION, asserted deliberately so it cannot drift unnoticed.
     *
     * For a chain whose operator HRP does not follow `<prefix>valoper`,
     * converting an ACCOUNT address still yields the conventional spelling,
     * which that chain will not recognise. This is pinned, NOT endorsed: the
     * operator HRP is not derivable from anything we store, and the Cosmos
     * chain-registry publishes no `bech32_config` for Crypto.org either.
     *
     * What the fix does buy here is the difference between failing cleanly
     * and failing loudly: the output below is checksum-VALID, so the chain
     * answers one 404 instead of a 500 retried four times with a breaker
     * charge. Closing the gap properly needs a per-chain operator HRP column,
     * which is a schema change and out of scope. If that ever lands, this
     * test should be changed on purpose.
     */
    public function testTheNonConventionalAccountPathIsPinnedAsAKnownLimitation(): void
    {
        $out = $this->resolve(self::CRO_ACCOUNT_A, 'cro');

        $this->assertSame(self::CRO_CONVENTIONAL_A, $out);

        $this->assertNotNull(
            Bech32::decode($out),
            'Even in the limitation case the output must be well-formed, so the '
            . 'chain can answer a clean 404 instead of a bech32 decode error.'
        );
        $this->assertNotSame(
            self::CRO_OPERATOR_A,
            $out,
            'Documenting the gap: this is NOT the address Crypto.org uses.'
        );
    }

    // ── 6. Source guard against a substitution regression ───────────────

    public function testTheResolverReEncodesThroughBech32AndDoesNotSubstitute(): void
    {
        $file = \dirname(__DIR__, 2) . '/app/Domain/Onchain/Fetchers/CosmosFetcher.php';
        $src  = (string) \file_get_contents($file);

        $start = \strpos($src, 'private function ensureValoperPrefix(');
        $this->assertNotFalse($start, 'ensureValoperPrefix() not found in the source.');

        // Bounded span: from the signature to the next method declaration.
        $next = \strpos($src, "\n    private function ", $start + 10);
        $body = $next === false ? \substr($src, $start) : \substr($src, $start, $next - $start);

        $this->assertStringContainsString(
            'Bech32::encode(',
            $body,
            'The operator address must be RE-ENCODED, not pasted together.'
        );
        $this->assertStringContainsString('Bech32::decode(', $body);
        $this->assertStringNotContainsString(
            'str_replace(',
            $body,
            'String substitution of a bech32 HRP invalidates the checksum. '
            . 'This is the exact regression that cost 1,241 production errors.'
        );
    }
}
