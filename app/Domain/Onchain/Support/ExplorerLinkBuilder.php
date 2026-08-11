<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Block-explorer address link builder.
 *
 * The chains registry (`wp_bcc_chains.explorer_url`) stores an explorer
 * BASE, e.g. `https://etherscan.io` or `https://www.mintscan.io/cosmos`.
 * Every BCC surface that wants "look this contract up on the explorer"
 * appends the same `/address/{addr}` path, which is the form all the
 * configured explorers (Etherscan-family, Mintscan) accept for both EOAs
 * and contracts.
 *
 * WHY THIS EXISTS AS A HELPER: the expression was previously written
 * inline in {@see \BCC\Trust\Onchain\Core\REST\CreatorGalleryEndpoint},
 * and the CosmWasm scanner admin panel needed the same link. Two copies
 * of a URL convention drift the moment one chain needs a different path;
 * one helper is the §11 answer.
 *
 * DISTINCT FROM {@see MarketplaceLinkBuilder}: that one interpolates a
 * per-chain MARKETPLACE template carrying `{contract}`/`{token_id}`
 * placeholders and points at a listing page for ONE PIECE. This one
 * points at the chain's own block explorer for a CONTRACT and needs no
 * template. They are different destinations answering different
 * questions, so neither wraps the other.
 */
final class ExplorerLinkBuilder
{
    /**
     * Explorer URL for a contract/account address.
     *
     * Returns null when the chain has no configured explorer or the
     * address is empty — the caller renders nothing rather than a
     * half-formed link.
     */
    public static function addressUrl(?string $explorerBase, string $address): ?string
    {
        $base    = is_string($explorerBase) ? trim($explorerBase) : '';
        $address = trim($address);
        if ($base === '' || $address === '') {
            return null;
        }

        return rtrim($base, '/') . '/address/' . $address;
    }
}
