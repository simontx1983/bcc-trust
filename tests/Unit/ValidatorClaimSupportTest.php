<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\ClaimService;
use BCC\Trust\Onchain\Services\ValidatorClaimSupport;
use BCC\Trust\Onchain\Support\Bech32;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-515: whether a validator page can be claimed at all, and why not. The
 * card (claim_target, can_claim) and the claim write read this one rule.
 */
#[CoversClass(ValidatorClaimSupport::class)]
final class ValidatorClaimSupportTest extends TestCase
{
    /** @return array<string, array{string, string, string, string, bool, string|null}> */
    public static function chains(): array
    {
        return [
            'a cosmos chain'             => ['cosmos', 'cosmoshub', 'Cosmos Hub', 'cosmosvaloper1abc', true, null],
            'a polkadot chain'           => ['polkadot', 'polkadot', 'Polkadot', '15oF4uVJwmo4TdGW7VfQxNLavjCXviqxT9S1MgbjMNHr6Sp5', true, null],
            'type case is ignored'       => ['Cosmos', 'osmosis', 'Osmosis', 'osmovaloper1abc', true, null],
            'THORChain'                  => ['cosmos', 'thorchain', 'THORChain', 'thor1node', false, 'operator_unprovable'],
            'Solana'                     => ['solana', 'solana', 'Solana', 'Vote111', false, 'solana_unsupported'],
            'an EVM chain'               => ['evm', 'ethereum', 'Ethereum', '0xabc', false, 'evm_no_validator_pages'],
            'an unknown family'          => ['near', 'near', 'NEAR Protocol', 'pool.near', false, 'operator_unprovable'],
            'an empty chain type'        => ['', 'mystery', 'Mystery', 'x1', false, 'operator_unprovable'],
            'no operator address'        => ['cosmos', 'cosmoshub', 'Cosmos Hub', '', false, 'operator_address_missing'],
            'a blank operator address'   => ['polkadot', 'polkadot', 'Polkadot', '   ', false, 'operator_address_missing'],
        ];
    }

    #[DataProvider('chains')]
    public function testTheRule(string $type, string $slug, string $name, string $operator, bool $claimable, ?string $code): void
    {
        $out = ValidatorClaimSupport::assess($type, $slug, $name, $operator);

        self::assertSame($claimable, $out['claimable']);
        self::assertSame($code, $out['reason_code']);
        if ($claimable) {
            self::assertNull($out['hint']);
        } else {
            self::assertIsString($out['hint']);
            self::assertNotSame('', $out['hint']);
        }
    }

    /** The family is decided before the address: an unclaimable chain says why, even with no operator on record. */
    public function testTheChainFamilyWinsOverAMissingAddress(): void
    {
        self::assertSame('solana_unsupported', ValidatorClaimSupport::assess('solana', 'solana', 'Solana', '')['reason_code']);
        self::assertSame('operator_unprovable', ValidatorClaimSupport::assess('cosmos', 'thorchain', 'THORChain', '')['reason_code']);
    }

    /** The hint names the chain by its display name, never by its slug. */
    public function testTheHintNamesTheChainNotItsSlug(): void
    {
        $hint = (string) ValidatorClaimSupport::assess('cosmos', 'thorchain', 'THORChain', 'thor1node')['hint'];
        self::assertStringContainsString('THORChain', $hint);
        self::assertStringNotContainsString('thorchain', $hint);

        $evm = (string) ValidatorClaimSupport::assess('evm', 'base-mainnet', 'Base', '0xabc')['hint'];
        self::assertStringStartsWith('Base ', $evm);
        self::assertStringNotContainsString('base-mainnet', $evm);
    }

    /**
     * Where wallet claiming can't prove an operator, BCC can verify them: the
     * hint says what to do next, and names the address to write to (F-551).
     * The copy is pinned word for word.
     */
    public function testTheUnprovableHintsSayWhatToDoNext(): void
    {
        foreach ([
            ['solana', 'solana', 'Solana', 'Vote111'],
            ['cosmos', 'thorchain', 'THORChain', 'thor1node'],
            ['near', 'near', 'NEAR Protocol', 'pool.near'],
            ['near', 'near', '', 'pool.near'],
        ] as [$type, $slug, $name, $operator]) {
            $chain = $name !== '' ? $name : 'this chain';
            self::assertSame(
                "Wallet claiming isn't available on {$chain} yet. Email phillip@bluecollarcrypto.io and we'll verify you as this validator's operator.",
                ValidatorClaimSupport::assess($type, $slug, $name, $operator)['hint']
            );
        }
    }

    /** The address lives in ONE constant: the hint reads it, and the source spells it once. */
    public function testTheContactAddressIsOneConstant(): void
    {
        self::assertSame('phillip@bluecollarcrypto.io', ValidatorClaimSupport::VERIFICATION_CONTACT_EMAIL);

        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/ValidatorClaimSupport.php');
        self::assertSame(1, substr_count($source, '@bluecollarcrypto.io'), 'the address is spelled once, in the constant');
    }

    public function testAMissingChainNameFallsBackToGenericWords(): void
    {
        self::assertStringContainsString('this chain', (string) ValidatorClaimSupport::assess('near', 'near', '', 'x')['hint']);
        self::assertStringStartsWith('This chain ', (string) ValidatorClaimSupport::assess('evm', 'ethereum', '', '0x1')['hint']);
    }

    // ── LOCKSTEP with ClaimService::matchValidator() ───────────────────

    /**
     * A wallet and the validator operator address that wallet must PROVE,
     * for each chain type. Add one here when a type becomes claimable.
     *
     * @return array{string, string}|null
     */
    private static function provingPair(string $type): ?array
    {
        if ($type === 'cosmos') {
            // A real 20-byte account/valoper pair: the same bytes, two HRPs.
            $bytes   = (string) hex2bin('0123456789abcdef0123456789abcdef01234567');
            $account = Bech32::encode('cosmos', $bytes);
            $valoper = Bech32::encode('cosmosvaloper', $bytes);
            self::assertIsString($account);
            self::assertIsString($valoper);
            return [$account, $valoper];
        }
        if ($type === 'polkadot') {
            // The stored operator address IS the stash account.
            $stash = '15oF4uVJwmo4TdGW7VfQxNLavjCXviqxT9S1MgbjMNHr6Sp5';
            return [$stash, $stash];
        }

        return null;
    }

    /**
     * ⛔ Fails when a chain type is claimable but matchValidator() cannot
     * prove an operator on it, so the card can never offer a claim the
     * write would refuse as "your wallet does not match".
     */
    public function testEveryClaimableChainTypeIsOneMatchValidatorCanProve(): void
    {
        self::assertNotSame([], ValidatorClaimSupport::CLAIMABLE_CHAIN_TYPES);

        foreach (ValidatorClaimSupport::CLAIMABLE_CHAIN_TYPES as $type) {
            $pair = self::provingPair($type);
            self::assertNotNull(
                $pair,
                "'{$type}' is claimable but no wallet/operator pair proves it here: "
                . 'make ClaimService::matchValidator() prove it, then add the pair'
            );
            [$wallet, $operator] = $pair;

            self::assertTrue(
                ClaimService::walletProvesOperator((object) ['wallet_address' => $wallet, 'chain_id' => 1], $operator, $type),
                "matchValidator() does not prove a '{$type}' operator"
            );
            self::assertFalse(
                ClaimService::walletProvesOperator((object) ['wallet_address' => $wallet . 'x', 'chain_id' => 1], $operator, $type),
                "control: another '{$type}' wallet must not prove it"
            );
            self::assertTrue(
                ValidatorClaimSupport::assess($type, 'some-chain', 'Some Chain', $operator)['claimable'],
                "'{$type}' with an operator on record is claimable"
            );
        }
    }
}
