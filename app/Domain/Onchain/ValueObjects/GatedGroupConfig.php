<?php
/**
 * NFT-gate configuration for a holder group.
 *
 * Source: post meta on the peepso-group post. One row per holder
 * group. Read via GatedGroupRepository; do not read post meta directly.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class GatedGroupConfig {

    /**
     * @param int      $groupId         WP post ID of the gated peepso-group.
     * @param int      $chainId         Numeric chain id (matches wp_bcc_wallet_links.chain_id).
     * @param string   $contractAddress Lower-cased contract address (canonical form).
     * @param int      $minBalance      Minimum holdings required, default 1.
     * @param int|null $collectionId    FK into wp_bcc_onchain_collections.id, null if unlinked.
     */
    public function __construct(
        public readonly int     $groupId,
        public readonly int     $chainId,
        public readonly string  $contractAddress,
        public readonly int     $minBalance,
        public readonly ?int    $collectionId,
    ) {}
}
