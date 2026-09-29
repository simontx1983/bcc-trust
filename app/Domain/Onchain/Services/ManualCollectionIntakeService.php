<?php
/**
 * The ONE manual collection-intake path.
 *
 * ── WHAT IT REPLACES ────────────────────────────────────────────────────
 * Verify Collections carried two divergent Add forms:
 *
 *   `bcc_vc_add_collection`  every active chain, five metadata fields, and
 *                            NO on-chain validation at all outside Cosmos —
 *                            the row was inserted on operator trust with a
 *                            warning notice. Wrote `source = 'manual'`.
 *   `bcc_vc_add_cosmos`      cosmos-only, contract field only, always
 *                            CW-721-validated. Wrote through `bulkUpsert()`,
 *                            so the row did NOT get `source = 'manual'` and
 *                            the "Manual" badge never appeared for it.
 *
 * Two forms, two validation postures, two provenance labels, one table. This
 * is one form, chain-locked, with the family's real capability stated rather
 * than implied.
 *
 * ── WHAT EACH FAMILY CAN ACTUALLY PROVE ─────────────────────────────────
 * Taken from {@see \BCC\Trust\Onchain\Support\NftDriverRegistry}, which is
 * the build's own account of what exists:
 *
 *   Cosmos   `cw721_lcd`. A real CW-721 `contract_info` probe runs, and a
 *            contract that does not answer is refused.
 *   EVM      `evm_rpc`. ERC-165 `supportsInterface(0x80ac58cd)` proves the
 *            standard, then Alchemy supplies name/symbol/image/supply.
 *            ⚠ Only the approved launch chains are eligible — Ethereum and
 *            Base (DECISION 7) — enforced by
 *            {@see \BCC\Trust\Onchain\Support\NftLaunchChains}.
 *   Solana   `das_helius`. `getAssetsByGroup` with
 *            `showUnverifiedCollections: false` proves the address is in use
 *            as a certified collection group, then `getAsset` supplies the
 *            collection's own name and image.
 *
 * ── ⚠⚠ HISTORICAL: THE "ACCEPTED AS ENTERED" RULE IS GONE ───────────────
 * This docblock used to state that EVM and Solana rows were accepted with no
 * validation at all, quoting the registry's then-accurate notes that the EVM
 * `supportsInterface` call was "still to build" and that Solana adds were
 * "trusted as entered". **Both were true before PR E and are false now**: PR E
 * built both validators, so every family is checked against the chain before a
 * row is written, and a provider failure is refused as *could not confirm*
 * rather than accepted.
 *
 * A canonical address is still not a VERIFIED collection. Validation proves the
 * contract is an NFT contract; it never proves it is the official one for a
 * named collection. That stays an administrator's judgement on the Verify
 * screen, and this class never implies otherwise.
 *
 * @package BCC\Trust\Onchain\Services
 * @since PR 6 — collection administration and explicit provisioning
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftCollectionIdentifier;
use BCC\Trust\Onchain\ValueObjects\CollectionMetadataRules;
use BCC\Trust\Onchain\ValueObjects\ContractValidationVerdict;
use BCC\Trust\Onchain\ValueObjects\IntakeMetadata;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;

if (!defined('ABSPATH')) {
    exit;
}

final class ManualCollectionIntakeService
{
    public const AUDIT_ADDED   = 'admin_nftd_collection_added';
    public const AUDIT_REFUSED = 'admin_nftd_collection_add_refused';

    /** The only three families that may take a manual add. */
    public const FAMILIES = ['cosmos', 'evm', 'solana'];

    /** Bounded refusal reasons. Safe to render, safe to audit. */
    public const REFUSED_BAD_FAMILY        = 'family_not_allowed';
    public const REFUSED_CHAIN_NOT_FOUND   = 'chain_not_found';
    public const REFUSED_CHAIN_INACTIVE    = 'chain_inactive';
    public const REFUSED_FAMILY_MISMATCH   = 'chain_family_mismatch';
    public const REFUSED_NO_PRODUCT        = 'product_support_disabled';
    public const REFUSED_NO_MANUAL         = 'manual_discovery_disabled';
    public const REFUSED_BAD_IDENTIFIER    = 'identifier_invalid';
    public const REFUSED_DUPLICATE         = 'duplicate_canonical_identity';
    public const REFUSED_NOT_CW721         = 'cw721_validation_failed';
    public const REFUSED_WRITE_FAILED      = 'write_failed';

    /**
     * The contract answered, and its standard is one BCC has deliberately not
     * taken on yet — ERC-1155 (DECISION 9) or a compressed Solana NFT
     * (DECISION 8). ⚠ Distinct from {@see REFUSED_NOT_CW721}: this IS an NFT
     * contract, and telling an operator otherwise would be wrong.
     */
    public const REFUSED_UNSUPPORTED_STANDARD = 'standard_not_supported_yet';

    /**
     * ⚠⚠ THE FAIL-CLOSED REFUSAL. No answer was obtained — timeout, missing
     * API key, open breaker, exhausted budget, a chain with no wasm module.
     * It says NOTHING about the contract, and the operator copy must offer a
     * retry rather than a verdict.
     */
    public const REFUSED_UNAVAILABLE = 'could_not_validate';

    /** What was actually proven about the identifier. Recorded on the row's audit. */
    public const VALIDATION_CW721 = 'cw721_contract_info';
    public const VALIDATION_NONE  = 'none';

    /**
     * A targeted per-family validation actually ran and proved the standard.
     *
     * Replaces the old audit value for accepted rows: `cw721_contract_info`
     * named a Cosmos-only probe, and PR E proves EVM and Solana too.
     */
    public const VALIDATION_TARGETED = 'targeted_contract_validation';

    /**
     * Which provider claimed a description, recorded beside it so a reviewer
     * can see who said it. Bounded tokens — never a host or a URL.
     */
    private static function descriptionSourceFor(string $chainFamily): string
    {
        return match ($chainFamily) {
            'cosmos' => 'cw721_contract_info',
            'evm'    => 'alchemy_contract_metadata',
            'solana' => 'das_get_asset',
            default  => 'unknown',
        };
    }

    /**
     * Add one collection.
     *
     * @param string $family     from the request, allowlisted here
     * @param int    $chainId    from the request; re-resolved and re-checked
     * @param string $identifier the raw operator input
     * @param int    $operatorId the administrator performing the add
     * @return array{ok: bool, reason?: string, collection_id?: int, validation?: string, duplicate_of?: int}
     */
    public function add(string $family, int $chainId, string $identifier, int $operatorId): array
    {
        // ── 1. Family allowlist ─────────────────────────────────────────
        if (!in_array($family, self::FAMILIES, true)) {
            return $this->refuse(self::REFUSED_BAD_FAMILY, $chainId, $operatorId, $family);
        }

        // ── 2. Chain, resolved from the repository not the request ──────
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return $this->refuse(self::REFUSED_CHAIN_NOT_FOUND, $chainId, $operatorId, $family);
        }

        if ((int) ($chain->is_active ?? 0) !== 1) {
            return $this->refuse(self::REFUSED_CHAIN_INACTIVE, $chainId, $operatorId, $family);
        }

        // ── 3. The submitted chain must BELONG to the selected family ───
        // The nonce is bound to the chain and the family comes from the tab,
        // so a mismatch means the two halves of the request disagree. Trusting
        // either one over the other would let a nonce minted on the Solana tab
        // drive an add against a Cosmos chain.
        $chainFamily = (string) ($chain->chain_type ?? '');
        if ($chainFamily !== $family) {
            return $this->refuse(self::REFUSED_FAMILY_MISMATCH, $chainId, $operatorId, $family);
        }

        // ── 4. Product support, then permission ─────────────────────────
        // Two separate flags in a deliberate order: product support is BCC's
        // decision that this chain is in scope at all; manual discovery is
        // permission to start an operator-initiated intake on a chain already
        // in scope. Reporting "permission disabled" for a chain we do not
        // support would send an operator to enable the wrong thing.
        //
        // ⚠ Asked through NftChainCapability, never by naming the columns.
        // The capability model is the only thing outside ChainRepository and
        // the schema allowed to know those column names, and a boundary test
        // enforces it — so a second reader cannot drift from the model's
        // interpretation of them.
        //
        // Both accessors return `?bool`, where NULL means the column could
        // not be read. `!== true` is therefore the only correct test: an
        // unreadable capability store must fail CLOSED, not open.
        if (NftChainCapability::bccNftSupportState($chain) !== true) {
            return $this->refuse(self::REFUSED_NO_PRODUCT, $chainId, $operatorId, $family);
        }

        if (NftChainCapability::manualDiscoveryState($chain) !== true) {
            return $this->refuse(self::REFUSED_NO_MANUAL, $chainId, $operatorId, $family);
        }

        // ── 5. Identity, through the one chain-aware rule ───────────────
        // No `strtolower()` anywhere: EVM canonicalises to lowercase hex,
        // Cosmos to lowercase bech32 with a verified checksum, and Solana
        // stays BYTE-EXACT base58 that decodes to exactly 32 bytes.
        $identifier = trim($identifier);
        if ($identifier === '') {
            return $this->refuse(self::REFUSED_BAD_IDENTIFIER, $chainId, $operatorId, $family);
        }

        $identity = NftCollectionIdentifier::canonicalize($chainFamily, $identifier);
        if (!$identity->isAccepted()) {
            return $this->refuse(self::REFUSED_BAD_IDENTIFIER, $chainId, $operatorId, $family);
        }
        $canonical = $identity->canonical();

        // ── 6. Duplicate check on CANONICAL identity ────────────────────
        // `findByChainContract()` matches `canonical_identifier` exactly.
        // The legacy case-insensitive lookup
        // (`findLegacyByChainContractInsensitive`) is deliberately NOT used:
        // it exists to keep pre-PR-5a alias rows reachable, and resolving a
        // NEW collection through it would let an unresolved legacy alias
        // absorb a distinct, valid identity.
        $existing = CollectionRepository::findByChainContract($chainId, $canonical);
        if ($existing !== null) {
            $result = $this->refuse(self::REFUSED_DUPLICATE, $chainId, $operatorId, $family);
            $result['duplicate_of'] = (int) $existing->id;
            return $result;
        }

        // ── 7. Targeted validation, one address, family-dispatched ──────
        //
        // ⚠ THE REFUSAL REASON IS THE POINT. Before PR E this block was
        // Cosmos-only and collapsed every unhappy path into one refusal, so a
        // timed-out LCD and a contract that is genuinely not CW-721 produced
        // the same message. An administrator acts on that message. See
        // {@see ContractValidationVerdict} for the four-state vocabulary that
        // keeps "we could not ask" apart from "we asked and the answer is no".
        $verdict = (new ContractValidator())->validate($chain, $canonical);

        if (!$verdict->mayPersist()) {
            // Nothing is written for INVALID, UNSUPPORTED or UNAVAILABLE.
            // UNSUPPORTED and UNAVAILABLE can both change on a later attempt,
            // and a stored row would outlive the condition that produced it.
            $reason = match ($verdict->state()) {
                ContractValidationVerdict::INVALID     => self::REFUSED_NOT_CW721,
                ContractValidationVerdict::UNSUPPORTED => self::REFUSED_UNSUPPORTED_STANDARD,
                default                                => self::REFUSED_UNAVAILABLE,
            };

            $result = $this->refuse($reason, $chainId, $operatorId, $family);
            $result['evidence'] = $verdict->evidence();
            $result['standard'] = $verdict->standard();

            return $result;
        }

        $validation = self::VALIDATION_TARGETED;
        $metadata   = $verdict->metadata() ?? IntakeMetadata::unknown();
        $standard   = $verdict->standard();

        // Sanitize every field through the existing rules. ⚠ Only fields the
        // provider ANSWERED with a value reach the writer — an UNKNOWN field
        // writes nothing, so a failed read never overwrites a stored value
        // with null, and `metadata_state` carries the shortfall instead.
        $writable = $metadata->writableFields();
        $name     = CollectionMetadataRules::sanitizeName($writable['name'] ?? null) ?? '';
        $symbol   = CollectionMetadataRules::sanitizeSymbol($writable['symbol'] ?? null);
        $imageUrl = CollectionMetadataRules::sanitizeImageUrl($writable['image_url'] ?? null);
        $supply   = null;
        if (array_key_exists('total_supply', $writable)) {
            $supplyCheck = CollectionMetadataRules::validateTotalSupply($writable['total_supply']);
            $supply      = ($supplyCheck['ok'] ?? false) ? ($supplyCheck['value'] ?? null) : null;
        }
        $description   = CollectionMetadataRules::sanitizeDescription($writable['description'] ?? null);
        $metadataState = $metadata->state();

        // ── 8. Insert + checked audit, atomically ───────────────────────
        // If the audit cannot be written, the collection must not remain
        // inserted: an unattributable manual add is exactly the thing the
        // checked-audit contract exists to prevent.
        $collectionId = 0;

        try {
            /** @var int $collectionId */
            $collectionId = TransactionManager::run(function () use (
                $chainId, $canonical, $name, $chainFamily, $chain, $operatorId, $validation,
                $symbol, $imageUrl, $supply, $standard, $metadataState, $description
            ) {
                $data = [
                    'chain_id'          => $chainId,
                    'contract_address'  => $canonical,
                    'collection_name'   => $name !== '' ? $name : null,
                    // The standard the validator PROVED, not one inferred from
                    // the family. An EVM chain can carry 721 and 1155, and only
                    // the proven value may be stored.
                    'token_standard'    => $standard,
                    'collection_symbol' => $symbol,
                    'image_url'         => $imageUrl,
                    'total_supply'      => $supply,
                    // How far the metadata read got, and when. Computed by
                    // IntakeMetadata — never passed in by a caller, so nothing
                    // can describe a failed read as `complete`.
                    'metadata_state'      => $metadataState,
                    'metadata_checked_at' => current_time('mysql', true),
                ];

                // `addManual()` forces `is_verified = 0` and
                // `source = 'manual'` in its own INSERT; neither is passed in,
                // so no caller can talk it into landing a pre-verified row.
                // `provisioning_state` takes its column default, `'none'`.
                //
                // ⚠ METADATA RETRIEVAL NEVER VERIFIES AND NEVER PROVISIONS.
                // Reading a name and an image says nothing about whether this
                // is the official contract for that collection — that is an
                // administrator's judgement, made later on the Verify screen.
                $rowId = CollectionRepository::addManual($data);
                if (!is_int($rowId) || $rowId <= 0) {
                    throw new \RuntimeException('collection insert failed');
                }

                // ⚠ DESCRIPTION LANDS `pending` AND IS NEVER PUBLISHED HERE.
                // It is provider-authored text: bounded, sanitized, and
                // attributed to its source. An administrator can approve or
                // reject it on the review screen, but per DECISION 17 that is
                // a REVIEW OUTCOME only — during scanner retirement the text
                // gets no REST field, no view-model field and no public
                // surface. The admin review screen is its only reader.
                // It is NOT the Community Description and never reaches a
                // PeepSo group — that is PR G.
                if ($description !== null && $description !== '') {
                    CollectionRepository::importChainDescription(
                        $rowId,
                        $description,
                        self::descriptionSourceFor($chainFamily)
                    );
                }

                $auditId = AuditLogger::logChecked(
                    self::AUDIT_ADDED,
                    $rowId,
                    [
                        'collection_id'    => $rowId,
                        'chain_id'         => $chainId,
                        'chain_slug'       => (string) ($chain->slug ?? ''),
                        'chain_family'     => $chainFamily,
                        'operator_user_id' => $operatorId,
                        'validation'       => $validation,
                        'new_state'        => ProvisioningState::NONE,
                    ],
                    'collection',
                    $operatorId
                );

                if ($auditId === null) {
                    throw new \RuntimeException('checked audit write failed; rolling back the collection insert');
                }

                return $rowId;
            });
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] manual collection intake rolled back', [
                'chain_id' => $chainId,
                'error'    => $e->getMessage(),
            ]);
            return $this->refuse(self::REFUSED_WRITE_FAILED, $chainId, $operatorId, $family);
        }

        // The per-chain count changed, so the cached census is stale.
        // `addManual()` already busts it on its own success path; this is
        // belt-and-braces for the transactional wrapper, and is idempotent.
        //
        // NOTE it is busted HERE and not on a verification or provisioning
        // change: `getCountsByChain()` counts collection ROWS per chain and
        // reads neither `is_verified` nor `provisioning_state`, so those
        // writes cannot invalidate it.
        wp_cache_delete('collection_counts_by_chain', 'bcc_onchain');

        return [
            'ok'            => true,
            'collection_id' => $collectionId,
            'validation'    => $validation,
        ];
    }

    /**
     * Record a refusal and return it.
     *
     * The audit carries the bounded reason code and the chain, never the
     * operator's raw input: an unvalidated identifier echoed into a durable
     * row is a write primitive for whoever can reach the form.
     *
     * @return array{ok: bool, reason: string}
     */
    private function refuse(string $reason, int $chainId, int $operatorId, string $family): array
    {
        AuditLogger::log(
            self::AUDIT_REFUSED,
            null,
            [
                'chain_id'         => $chainId,
                'chain_family'     => $family,
                'operator_user_id' => $operatorId,
                'error_code'       => $reason,
            ],
            'chain',
            $operatorId
        );

        return ['ok' => false, 'reason' => $reason];
    }

    /**
     * Operator-facing copy for a refusal.
     *
     * Every branch names what to do next. An unrecognised reason gets a
     * generic sentence rather than being echoed back to the page.
     */
    public static function refusalMessage(string $reason): string
    {
        switch ($reason) {
            case self::REFUSED_BAD_FAMILY:
                return 'That chain family cannot take a manual collection.';
            case self::REFUSED_CHAIN_NOT_FOUND:
                return 'That chain no longer exists.';
            case self::REFUSED_CHAIN_INACTIVE:
                return 'That chain is not active.';
            case self::REFUSED_FAMILY_MISMATCH:
                return 'The selected chain does not belong to the chosen family. Nothing was added.';
            case self::REFUSED_NO_PRODUCT:
                return 'This chain is not enabled for NFT collections. Enable product support for it in the capability editor first.';
            case self::REFUSED_NO_MANUAL:
                return 'Manual collection discovery is not permitted on this chain. Grant it in the capability editor first.';
            case self::REFUSED_BAD_IDENTIFIER:
                return 'That identifier is not valid for this chain. Nothing was added.';
            case self::REFUSED_DUPLICATE:
                return 'A collection with that on-chain identity already exists on this chain.';
            case self::REFUSED_NOT_CW721:
                // ⚠ Now a DECIDED negative, and the copy may say so: the
                // contract answered, and its answers are not an NFT
                // collection's. The old hedge ("this may mean it is not one,
                // or that the endpoint did not answer") existed because the
                // Cosmos path could not tell those apart. It can now, and the
                // "could not reach" case has its own refusal below.
                return 'The contract answered, and it is not an NFT collection contract on this chain. Nothing was added.';
            case self::REFUSED_UNSUPPORTED_STANDARD:
                return 'This is an NFT contract, but BCC does not support its standard yet — ERC-1155 and compressed Solana NFTs are deferred until ownership can be proven safely. Nothing was added, and this is not a judgement about the collection.';
            case self::REFUSED_UNAVAILABLE:
                // ⚠⚠ NEVER PHRASED AS A VERDICT. Nothing was learned about
                // the contract, and an operator who reads this as "not an NFT"
                // will stop pursuing a collection that is perfectly fine.
                return 'BCC could not reach the chain to check this contract, so nothing could be confirmed either way and nothing was added. This is not a judgement about the collection — try again, and if it keeps happening check the chain endpoint and provider credentials.';
            case self::REFUSED_WRITE_FAILED:
            default:
                return 'The collection could not be added and nothing was written. See the bcc-trust error log.';
        }
    }
}
