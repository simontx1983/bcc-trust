<?php
/**
 * Delegation-gate configuration for a validator/delegator community.
 *
 * Source: post meta on the peepso-group post. One row per delegator
 * community. Read via ValidatorGroupRepository; do not read post meta
 * directly. Sibling of {@see GatedGroupConfig} (the NFT holder gate).
 *
 * @package BCC\Trust\Onchain\ValueObjects
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidatorGatedGroupConfig {

    /**
     * @param int    $groupId         WP post ID of the gated peepso-group.
     * @param int    $chainId         Numeric chain id (matches wp_bcc_wallet_links.chain_id).
     * @param int    $validatorId     FK into wp_bcc_onchain_validators.id.
     * @param string $operatorAddress Lower-cased valoper address (canonical form).
     * @param float  $minStake        Minimum delegated stake in DISPLAY units
     *                                (post-decimals), default 1.0 — the
     *                                dust-attack gate.
     */
    public function __construct(
        public readonly int    $groupId,
        public readonly int    $chainId,
        public readonly int    $validatorId,
        public readonly string $operatorAddress,
        public readonly float  $minStake,
    ) {}
}
