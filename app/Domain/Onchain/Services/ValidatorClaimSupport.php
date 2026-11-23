<?php
/**
 * ValidatorClaimSupport — can a validator page be claimed at all, and if
 * not, why (F-515).
 *
 * The ONE answer for both readers of it:
 *   - the card: `claim_target.claimable` / `unclaimable_reason_code` /
 *     `unclaimable_hint`, and `permissions.can_claim`'s `not_claimable`
 *     ({@see \BCC\Trust\Core\Services\CardViewService});
 *   - the write: POST /pages/:id/claim refuses an unclaimable validator up
 *     front with the same code and hint ({@see ClaimService::claim()}), so
 *     the card and the write can never disagree.
 *
 * A claim proves that one of the member's linked wallets IS the validator's
 * operator ({@see ClaimService::matchValidator()}). A chain is claimable here
 * only when that proof can be made from the data BCC stores:
 *   - `cosmos`: the operator's account and valoper share the same 20 bytes
 *     under different bech32 prefixes;
 *   - `polkadot`: the stored operator address IS the stash account.
 *
 * ⛔ LOCKSTEP WITH ClaimService::matchValidator(). Add a chain type to
 * CLAIMABLE_CHAIN_TYPES only once matchValidator() can prove it;
 * ValidatorClaimSupportTest fails for a claimable type it cannot prove.
 *
 * The hint names the chain by its display name (`chains.name`), never by
 * its slug.
 *
 * @package BCC\Trust\Onchain\Services
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type ClaimSupport array{claimable: bool, reason_code: string|null, hint: string|null}
 */
final class ValidatorClaimSupport
{
    /** Chain types whose validator operator authority matchValidator() can prove. */
    public const CLAIMABLE_CHAIN_TYPES = ['cosmos', 'polkadot'];

    /**
     * Chains of a claimable type whose STORED operator address is not one an
     * operator's wallet can match. THORChain stores the node address, not
     * the node operator's address.
     */
    public const UNPROVABLE_SLUGS = ['thorchain'];

    /** The validator has no operator address on record, so there is nothing to prove against. */
    public const REASON_OPERATOR_ADDRESS_MISSING = 'operator_address_missing';

    /** Solana: proving a validator needs its vote account's withdraw authority, which BCC does not record. */
    public const REASON_SOLANA_UNSUPPORTED = 'solana_unsupported';

    /** EVM chains have no validator pages. */
    public const REASON_EVM_NO_VALIDATOR_PAGES = 'evm_no_validator_pages';

    /** Any other chain, or a claimable type whose stored address cannot be matched. */
    public const REASON_OPERATOR_UNPROVABLE = 'operator_unprovable';

    /**
     * @phpstan-return ClaimSupport
     */
    public static function assess(string $chainType, string $chainSlug, string $chainName, string $operatorAddress): array
    {
        $type = strtolower(trim($chainType));
        $name = trim($chainName);

        if ($type === 'solana') {
            return self::unclaimable(
                self::REASON_SOLANA_UNSUPPORTED,
                self::contactBcc($name !== '' ? $name : 'Solana')
            );
        }
        if ($type === 'evm') {
            return self::unclaimable(
                self::REASON_EVM_NO_VALIDATOR_PAGES,
                ($name !== '' ? $name : 'This chain') . " doesn't have validator pages, so there's nothing to claim here."
            );
        }
        if (!in_array($type, self::CLAIMABLE_CHAIN_TYPES, true)
            || in_array(strtolower(trim($chainSlug)), self::UNPROVABLE_SLUGS, true)
        ) {
            return self::unclaimable(
                self::REASON_OPERATOR_UNPROVABLE,
                self::contactBcc($name !== '' ? $name : 'this chain')
            );
        }
        if (trim($operatorAddress) === '') {
            return self::unclaimable(
                self::REASON_OPERATOR_ADDRESS_MISSING,
                "This validator has no operator address on record yet, so there's nothing to prove "
                . 'ownership against. Check back after the next chain refresh.'
            );
        }

        return ['claimable' => true, 'reason_code' => null, 'hint' => null];
    }

    /**
     * Where wallet claiming cannot prove an operator, BCC staff can verify
     * them by hand (the admin validator-linking screen), so the hint says
     * what to do next rather than ending at "not available". No timing is
     * promised.
     */
    private static function contactBcc(string $chain): string
    {
        return "Wallet claiming isn't available on {$chain} yet. Contact BCC and we'll verify you as this validator's operator.";
    }

    /**
     * @phpstan-return ClaimSupport
     */
    private static function unclaimable(string $code, string $hint): array
    {
        return ['claimable' => false, 'reason_code' => $code, 'hint' => $hint];
    }
}
