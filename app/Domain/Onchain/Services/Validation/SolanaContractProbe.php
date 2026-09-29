<?php

namespace BCC\Trust\Onchain\Services\Validation;

use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\ContractValidationVerdict;
use BCC\Trust\Onchain\ValueObjects\IntakeMetadata;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validate ONE submitted Solana collection mint through DAS `getAsset`.
 *
 * ── WHAT "VALID" MEANS HERE ─────────────────────────────────────────────
 * The submitted address must be a collection NFT: an asset whose own
 * `grouping` entry for `collection` is **verified**, or which is itself the
 * verified collection parent. An unverified grouping is refused — on Solana
 * anyone can claim membership of a collection by writing its address into
 * their metadata; only the `verified` flag, set by the collection authority,
 * makes that claim trustworthy. Accepting an unverified grouping would let
 * anyone mint an asset claiming to belong to a blue-chip collection and have
 * BCC gate a community on it.
 *
 * ── ⚠⚠ NEVER THE MINT-AS-NAME FIELD ────────────────────────────────────
 * {@see SolanaFetcher::fetchMetadataForMint()} returns a `collection_name`
 * key, and it holds the collection **mint address**, not a human-readable
 * name — it is `grouping[].group_value`, a base58 address. Storing it as
 * `collection_name` would fill the admin queue with rows named
 * `DRiP2Pn2K6fuMLKQmt5rZWyHiUZ6WK3GChEySUpHSS4x`. The name comes from
 * `content.metadata.name` or it stays UNKNOWN.
 *
 * ── COMPRESSED NFTs ARE OUT OF SCOPE (DECISION 8) ───────────────────────
 * A cNFT lives in a Merkle tree, not in a token account, so ownership cannot
 * be proven by the balance reads the holder-gating path uses. Detected and
 * refused as UNSUPPORTED — decided, and never silently accepted.
 *
 * ── BOUNDED COST ────────────────────────────────────────────────────────
 * Exactly one DAS call. Validation and metadata come from the same response.
 */
final class SolanaContractProbe
{
    public function __construct(private SolanaFetcher $fetcher)
    {
    }

    public function validate(string $mint, ProviderRequestBudget $budget): ContractValidationVerdict
    {
        if (!$budget->canSpend(1)) {
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_BUDGET_EXHAUSTED,
            ]);
        }

        $r = $this->fetcher->assetResult($mint);
        $budget->spend(1);

        if (!$r['ok']) {
            // `not_found` is the one DAS failure that IS an answer about the
            // address: nothing exists there. Everything else is about us.
            if ($r['kind'] === 'not_found') {
                return ContractValidationVerdict::invalid([
                    ContractValidationVerdict::EV_NO_CODE_AT_ADDRESS,
                ]);
            }

            return ContractValidationVerdict::unavailable([
                $this->evidenceFor($r['kind']),
            ]);
        }

        $asset = is_array($r['result']) ? $r['result'] : [];

        // ── Compressed? Decided, and out of scope for v1 ─────────────────
        $compression = is_array($asset['compression'] ?? null) ? $asset['compression'] : [];
        if (($compression['compressed'] ?? false) === true) {
            return ContractValidationVerdict::unsupported('compressed-nft', [
                ContractValidationVerdict::EV_PROBE_ANSWERED,
                ContractValidationVerdict::EV_STANDARD_DEFERRED,
            ]);
        }

        // ── Verified collection grouping? ───────────────────────────────
        if (!$this->hasVerifiedCollectionGrouping($asset, $mint)) {
            return ContractValidationVerdict::invalid([
                ContractValidationVerdict::EV_PROBE_ANSWERED,
                ContractValidationVerdict::EV_GROUPING_UNVERIFIED,
            ]);
        }

        return ContractValidationVerdict::valid(
            'SPL-Metaplex',
            $this->collectMetadata($asset),
            [
                ContractValidationVerdict::EV_PROBE_ANSWERED,
                ContractValidationVerdict::EV_INTERFACE_CONFIRMED,
            ]
        );
    }

    /**
     * Is this a collection whose membership claim is authority-signed?
     *
     * Two accepted shapes:
     *  1. The asset carries a `collection` grouping whose `verified` flag is
     *     true — the collection authority signed it.
     *  2. The asset IS the collection parent: its `group_value` is its own id,
     *     which Metaplex sized-collection parents report.
     *
     * ⚠ `verified` absent is NOT treated as true. Some DAS providers omit the
     * flag; absent means unproven, and unproven is refused.
     *
     * @param array<string, mixed> $asset
     */
    private function hasVerifiedCollectionGrouping(array $asset, string $mint): bool
    {
        $grouping = is_array($asset['grouping'] ?? null) ? $asset['grouping'] : [];

        foreach ($grouping as $g) {
            if (!is_array($g)) {
                continue;
            }
            if (($g['group_key'] ?? null) !== 'collection') {
                continue;
            }
            $value = $g['group_value'] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (($g['verified'] ?? false) === true) {
                return true;
            }
            // The collection parent pointing at itself.
            if ($value === $mint) {
                return true;
            }
        }

        return false;
    }

    /**
     * Name, image and supply from the SAME response — no second call.
     *
     * ⚠ `description` is not attempted: DAS returns an off-chain description
     * fetched from a URI BCC did not validate, so it is left UNKNOWN rather
     * than recorded as absent. `symbol` likewise is frequently the per-asset
     * symbol rather than the collection's, so it stays UNKNOWN.
     *
     * @param array<string, mixed> $asset
     */
    private function collectMetadata(array $asset): IntakeMetadata
    {
        $metadata = IntakeMetadata::unknown();

        $content  = is_array($asset['content'] ?? null) ? $asset['content'] : [];
        $meta     = is_array($content['metadata'] ?? null) ? $content['metadata'] : [];
        $links    = is_array($content['links'] ?? null) ? $content['links'] : [];

        // ⚠ content.metadata.name — the human-readable name. NOT
        // grouping[].group_value, which is the collection MINT ADDRESS.
        $name = $meta['name'] ?? null;
        $metadata = $metadata->withAnswered(
            'name',
            is_string($name) && trim($name) !== '' ? $name : null
        );

        $image = $links['image'] ?? null;
        $metadata = $metadata->withAnswered(
            'image_url',
            is_string($image) && trim($image) !== '' ? $image : null
        );

        // Supply only where the response genuinely carries it. A sized
        // collection reports `supply.print_current_supply`; most collection
        // parents do not report a total at all, and inventing one from the
        // asset count would be a different number than the operator expects.
        $supply = is_array($asset['supply'] ?? null) ? $asset['supply'] : null;
        if ($supply !== null) {
            $current = $supply['print_current_supply'] ?? null;
            $metadata = $metadata->withAnswered(
                'total_supply',
                is_int($current) ? $current : null
            );
        }

        return $metadata;
    }

    private function evidenceFor(string $kind): string
    {
        return match ($kind) {
            'credentials_missing' => ContractValidationVerdict::EV_CREDENTIALS_MISSING,
            'transport'           => ContractValidationVerdict::EV_PROVIDER_TIMEOUT,
            'malformed'           => ContractValidationVerdict::EV_MALFORMED_RESPONSE,
            default               => ContractValidationVerdict::EV_PROVIDER_ERROR,
        };
    }
}
