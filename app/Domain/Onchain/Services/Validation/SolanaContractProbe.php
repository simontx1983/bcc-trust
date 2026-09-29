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
 * Validate ONE submitted Solana collection mint against the documented DAS API.
 *
 * ── ⚠⚠⚠ THE MODEL THIS REPLACES WAS NOT THE DOCUMENTED CONTRACT ─────────
 * An earlier version called `getAsset` on the submitted mint and required
 * `grouping[].verified === true`. **No such field exists.** A DAS grouping entry
 * contains exactly `group_key` and `group_value`. The invented flag meant the
 * "verified grouping required" rule was enforced against nothing.
 *
 * How DAS actually expresses verification: `options.showUnverifiedCollections`
 * defaults to **false**, and an unverified grouping is then **omitted** from
 * results rather than returned marked false. So the real question —
 * *is this address in use as a certified collection?* — is asked with
 * `getAssetsByGroup`, which is the documented method for taking a collection
 * address as `groupValue` and finding its members.
 *
 *   non-empty, well-formed `items`  → the address IS a verified collection group
 *   documented no-assets response   → decided negative
 *   anything else                   → UNAVAILABLE
 *
 * ── ⛔ TWO KNOWN GAPS, REPORTED RATHER THAN PAPERED OVER ────────────────
 *
 * **1. This costs TWO DAS calls, not the one the plan budgeted.**
 * `showCollectionMetadata` is listed by Helius but has **no documented
 * response shape**, and the Metaplex DAS specification states it is "accepted
 * by the API; reserved for future use on this method". There is therefore no
 * documented place to read the collection's name and image from the group
 * response. The sampled member's own `content.metadata.name` is the MEMBER's
 * name ("Mad Lads #8420") — using it would recreate the mint-as-name defect in
 * a new form. So the collection's own name/image come from a second call,
 * `getAsset` on the submitted mint. **That deviates from the approved one-call
 * Solana budget and needs sign-off.**
 *
 * **2. Compressed-member exclusion (DECISION 8) is only PARTIALLY enforced.**
 * `getAssetsByGroup` items each carry the documented `compression.compressed`
 * boolean, so the sampled member's type is known and a compressed sample is
 * refused here. But nothing in `getAssetsByGroup` aggregates or filters by
 * compression — `total` is a count, not a type breakdown — and a collection may
 * legitimately mix compressed and uncompressed members. **One sample cannot
 * prove the whole collection's type, so this code does NOT fully enforce
 * DECISION 8 and does not claim to.** A documented route exists and costs
 * another call: `searchAssets` accepts `grouping: ["collection", <addr>]`
 * together with a `compressed` filter and `showGrandTotal`, which would answer
 * "does this collection contain ANY compressed member?". Adopting it is a
 * product decision about the Solana call budget.
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

        // ── 1. Is the submitted address a verified collection group? ─────
        $group = $this->fetcher->assetsByGroupResult($mint);
        $budget->spend(1);

        if (!$group['ok']) {
            // `not_found` here is the documented "No assets found for the
            // specified group" — an answer about the address.
            if ($group['kind'] === 'not_found') {
                return ContractValidationVerdict::invalid([
                    ContractValidationVerdict::EV_GROUPING_UNVERIFIED,
                ]);
            }

            return ContractValidationVerdict::unavailable([
                $this->evidenceFor($group['kind']),
            ]);
        }

        $result = is_array($group['result']) ? $group['result'] : [];
        $items  = is_array($result['items'] ?? null) ? $result['items'] : [];

        if ($items === []) {
            // Well-formed, and empty. With showUnverifiedCollections false,
            // that means no VERIFIED membership exists for this address.
            return ContractValidationVerdict::invalid([
                ContractValidationVerdict::EV_PROBE_ANSWERED,
                ContractValidationVerdict::EV_GROUPING_UNVERIFIED,
            ]);
        }

        $sample = is_array($items[0] ?? null) ? $items[0] : null;
        if ($sample === null) {
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_MALFORMED_RESPONSE,
            ]);
        }

        // ── 2. Sampled member compressed? (partial DECISION 8 — see class doc)
        $compression = is_array($sample['compression'] ?? null) ? $sample['compression'] : [];
        if (($compression['compressed'] ?? null) === true) {
            return ContractValidationVerdict::unsupported('compressed-nft', [
                ContractValidationVerdict::EV_PROBE_ANSWERED,
                ContractValidationVerdict::EV_STANDARD_DEFERRED,
            ]);
        }

        // ⚠ A missing `compression` object is not proof of anything. The field
        // is documented, so its absence means the shape is not what we expect.
        if (!array_key_exists('compressed', $compression)) {
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_MALFORMED_RESPONSE,
            ]);
        }

        // ── 3. The collection's OWN name and image (second call) ─────────
        $metadata = $this->collectCollectionMetadata($mint, $budget);
        if ($metadata === null) {
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_PROBE_ANSWERED,
                $this->evidenceFor($this->lastMetadataKind),
            ]);
        }

        return ContractValidationVerdict::valid('SPL-Metaplex', $metadata, [
            ContractValidationVerdict::EV_PROBE_ANSWERED,
            ContractValidationVerdict::EV_INTERFACE_CONFIRMED,
        ]);
    }

    /** Why the metadata read failed, for the UNAVAILABLE evidence token. */
    private string $lastMetadataKind = 'none';

    /**
     * The COLLECTION's own name and image, from `getAsset` on the collection
     * mint — not from a member.
     *
     * ⚠ Only `name` and `image_url` are applicable on Solana. DAS exposes no
     * collection-level symbol or description, and its `supply` object describes
     * EDITION PRINTS of that one NFT (`print_current_supply` / `print_max_supply`)
     * — not how many items the collection contains. Storing an edition print
     * count as the collection's item count would be a different number from the
     * one an operator expects to see, so `total_supply` is NOT_APPLICABLE here.
     * If a membership count is wanted later, the documented source is the group
     * result's own `total`, which is a separate decision.
     */
    private function collectCollectionMetadata(string $mint, ProviderRequestBudget $budget): ?IntakeMetadata
    {
        $metadata = IntakeMetadata::forFamily('solana');

        if (!$budget->canSpend(1)) {
            $this->lastMetadataKind = 'budget_exhausted';
            return null;
        }

        $r = $this->fetcher->assetResult($mint);
        $budget->spend(1);

        if (!$r['ok'] || !is_array($r['result'])) {
            $this->lastMetadataKind = is_string($r['kind'] ?? null) ? $r['kind'] : 'malformed';
            return null;
        }

        $content = is_array($r['result']['content'] ?? null) ? $r['result']['content'] : [];
        $meta    = is_array($content['metadata'] ?? null) ? $content['metadata'] : [];
        $links   = is_array($content['links'] ?? null) ? $content['links'] : [];

        $name  = $meta['name'] ?? null;
        $image = $links['image'] ?? null;

        return $metadata
            ->withAnswered('name', is_string($name) && trim($name) !== '' ? $name : null)
            ->withAnswered('image_url', is_string($image) && trim($image) !== '' ? $image : null);
    }

    private function evidenceFor(string $kind): string
    {
        return match ($kind) {
            'credentials_missing' => ContractValidationVerdict::EV_CREDENTIALS_MISSING,
            'rate_limited'        => ContractValidationVerdict::EV_PROVIDER_RATE_LIMITED,
            'transport'           => ContractValidationVerdict::EV_PROVIDER_TIMEOUT,
            'malformed'           => ContractValidationVerdict::EV_MALFORMED_RESPONSE,
            'budget_exhausted'    => ContractValidationVerdict::EV_BUDGET_EXHAUSTED,
            default               => ContractValidationVerdict::EV_PROVIDER_ERROR,
        };
    }
}
