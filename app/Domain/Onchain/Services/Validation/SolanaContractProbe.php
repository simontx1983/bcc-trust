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
 * ── THREE BOUNDED DAS CALLS, BOTH DEVIATIONS APPROVED IN REVIEW ─────────
 * The plan budgeted one. Three are approved, because this path runs ONLY for a
 * single administrator-submitted collection — never from cron, page render,
 * enumeration or any fan-out — and each answers a question the others cannot:
 *
 *   1. `getAssetsByGroup`  is this a VERIFIED collection group?
 *   2. `searchAssets`      does ANY compressed member exist? (DECISION 8)
 *   3. `getAsset`          the COLLECTION's own name and image
 *
 * **Why the name needs its own call.** `showCollectionMetadata` is listed by
 * Helius but has **no documented response shape**, and the Metaplex DAS
 * specification states it is "accepted by the API; reserved for future use on
 * this method". The sampled member's own `content.metadata.name` is the
 * MEMBER's name ("Mad Lads #8420") — using it would recreate the mint-as-name
 * defect in a new form.
 *
 * **Why the cNFT exclusion needs its own call.** `getAssetsByGroup` returns
 * MEMBERS, so a member's `compression.compressed` proves only that member's
 * type, and collections may legitimately mix compressed and uncompressed
 * members. One sample therefore cannot decide the collection, and an earlier
 * version of this class said so while validating mixed collections anyway.
 * `searchAssets` documents both filters needed to ask the real question —
 * `grouping: ["collection", <addr>]` and `compressed: true` — so the question
 * is now asked about the COLLECTION.
 *
 * ── STRICT v1 cNFT POLICY, FULLY ENFORCED ───────────────────────────────
 *   any compressed member exists      → UNSUPPORTED, persist nothing
 *   mixed compressed/uncompressed     → UNSUPPORTED (it contains one)
 *   failed / malformed / capped /
 *     ambiguous / undocumented answer → UNAVAILABLE, persist nothing
 *   decisive zero compressed          → may continue
 *
 * ⚠ Only a DECISIVE ZERO continues. Provider uncertainty is never a zero: an
 * expired key reading as "no compressed members" is precisely the failure this
 * ordering exists to prevent.
 *
 * ── ⚠⚠⚠ NO SUPPLY IS COLLECTED ON SOLANA (v1, settled) ──────────────────
 * `total_supply` is NOT_APPLICABLE for this family. Nothing here reads it,
 * writes it, or infers it.
 *
 * **What that means, precisely:** BCC does not reliably collect Solana
 * collection supply through this intake path. It says NOTHING about the
 * on-chain collection, which of course has a size — only that we have no
 * bounded, documented way to learn it here. It is not zero and not absent.
 *
 * **What was tried, and why it was withdrawn.** An earlier version inferred
 * supply from this method's `total`, guarded conservatively: accept only when
 * the response echoed the requested `limit: 1` AND `total > limit`, reasoning
 * that a page-bounded count can never exceed its own page limit. That guard was
 * CORRECT against the information available — Helius's parameter table describes
 * `total` as "the total number of Solana NFTs found in this collection or
 * group". Live measurement against Helius disproved the SOURCE, not the guard:
 *
 *     requested limit 1  → echoed limit 1  → total 1
 *     requested limit 5  → echoed limit 5  → total 5
 *     requested limit 20 → echoed limit 20 → total 20
 *
 * `total` tracks the requested limit exactly: it is the RETURNED PAGE COUNT, so
 * it could never have been collection-wide. The vendor's prose is wrong and its
 * own response example (`"total": 1`) was right. `showGrandTotal: true` returned
 * an EMPTY result object with no `grand_total` field, so that is not a route
 * either.
 *
 * The heuristic and its exact-limit guard were therefore REMOVED rather than
 * left dormant: the guard only ever existed to protect a number this method
 * cannot supply, and leaving it would invite a future reader to revive it.
 * Re-deriving supply here needs a NEW documented provider capability, not a
 * cleverer reading of this response.
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

        // ── 2a. Is the SAMPLED member compressed? A cheap early negative. ─
        // Decisive when true — one compressed member is all DECISION 8 needs —
        // and it saves the existence call on the clearest case.
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

        // ── 2b. Does ANY compressed member exist? (the authoritative test) ─
        // ⚠⚠⚠ AN UNCOMPRESSED SAMPLE PROVES NOTHING ABOUT THE COLLECTION.
        // This is the query that actually enforces DECISION 8.
        $compressedExists = $this->compressedMembersExist($mint, $budget);
        if ($compressedExists === null) {
            // Could not ask, or could not understand the answer. NEVER a zero.
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_PROBE_ANSWERED,
                $this->evidenceFor($this->lastCompressedKind),
            ]);
        }
        if ($compressedExists === true) {
            return ContractValidationVerdict::unsupported('compressed-nft', [
                ContractValidationVerdict::EV_PROBE_ANSWERED,
                ContractValidationVerdict::EV_STANDARD_DEFERRED,
            ]);
        }

        // ── 3. The collection's OWN name and image (third call) ──────────
        $metadata = $this->collectCollectionMetadata($mint, $budget);
        if ($metadata === null) {
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_PROBE_ANSWERED,
                $this->evidenceFor($this->lastMetadataKind),
            ]);
        }

        // ⚠⚠⚠ NO SUPPLY IS DERIVED HERE, and `$result` is deliberately not read
        // again. `getAssetsByGroup.total` was measured live against Helius and
        // is the RETURNED PAGE COUNT — see the class docblock. `total_supply` is
        // NOT_APPLICABLE on Solana, which `IntakeMetadata::forFamily('solana')`
        // already established before the first call was made.
        return ContractValidationVerdict::valid('SPL-Metaplex', $metadata, [
            ContractValidationVerdict::EV_PROBE_ANSWERED,
            ContractValidationVerdict::EV_INTERFACE_CONFIRMED,
        ]);
    }

    /**
     * TRUE / FALSE only when the provider gave a documented answer; null when
     * it did not.
     *
     * ⚠ The three-way return is the point. A bool would force "we could not
     * ask" to collapse into one of the answers, and the safe-looking collapse
     * (false ⇒ "no compressed members") is the one that persists a cNFT
     * collection whenever Helius is having a bad minute.
     */
    private function compressedMembersExist(string $mint, ProviderRequestBudget $budget): ?bool
    {
        if (!$budget->canSpend(1)) {
            $this->lastCompressedKind = 'budget_exhausted';
            return null;
        }

        $r = $this->fetcher->compressedMembersExistResult($mint);
        $budget->spend(1);

        if (!$r['ok'] || !is_array($r['result'])) {
            $this->lastCompressedKind = is_string($r['kind'] ?? null) ? $r['kind'] : 'malformed';
            return null;
        }

        $items = $r['result']['items'] ?? null;
        if (!is_array($items)) {
            $this->lastCompressedKind = 'malformed';
            return null;
        }

        // ONE matching item establishes existence. No count is needed, which is
        // why `showGrandTotal` is not requested.
        return $items !== [];
    }

    /** Why the compressed-existence query gave no answer. */
    private string $lastCompressedKind = 'none';

    /** Why the metadata read failed, for the UNAVAILABLE evidence token. */
    private string $lastMetadataKind = 'none';

    /**
     * The COLLECTION's own name and image, from `getAsset` on the collection
     * mint — not from a member.
     *
     * ⚠ This call supplies `name` and `image_url` ONLY. DAS exposes no
     * collection-level symbol or description.
     *
     * ⚠⚠ AND DELIBERATELY NOT `supply`. The item's `supply` object describes
     * EDITION PRINTS of that one NFT (`print_current_supply` /
     * `print_max_supply`) — not how many items the collection contains.
     *
     * ⚠⚠⚠ NO SUPPLY IS COLLECTED ON SOLANA AT ALL. `total_supply` is
     * NOT_APPLICABLE for this family; see the class docblock for the live
     * measurement that disproved the only candidate source.
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
