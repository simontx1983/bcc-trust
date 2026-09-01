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
 *   Cosmos   `cw721_lcd` is the ONLY driver registering `OP_VALIDATION`.
 *            A real CW-721 `contract_info` query runs, and a contract that
 *            does not answer is refused.
 *   EVM      `evm_rpc` registers `OP_OWNERSHIP` only; the registry says the
 *            `supportsInterface(0x80ac58cd|0xd9b67a26)` call is "explicitly
 *            still to build". Nothing proves this address is an NFT
 *            contract.
 *   Solana   the registry says verbatim: "VALIDATION is NOT claimed. Solana
 *            collection adds are 'trusted as entered' today."
 *
 * So EVM and Solana rows are ACCEPTED AS ENTERED, and both the operator copy
 * and the audit record say so. A canonical address is not a verified NFT
 * collection, and this class never implies otherwise.
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

    /** What was actually proven about the identifier. Recorded on the row's audit. */
    public const VALIDATION_CW721 = 'cw721_contract_info';
    public const VALIDATION_NONE  = 'none';

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

        // ── 7. Family-specific validation ───────────────────────────────
        $validation = self::VALIDATION_NONE;
        $name       = '';

        if ($chainFamily === 'cosmos') {
            // ONE bounded validation operation. It is NOT one HTTP request:
            // `testCw721ContractInfo()` tries `contract_info` and, only if
            // that yields nothing, falls back to
            // `get_collection_info_and_extension` for SG721-shaped contracts
            // — so up to TWO LCD queries, and no more. Saying "exactly one
            // request" would be a claim the method does not support.
            $info = null;
            try {
                $fetcher = FetcherFactory::make_for_chain($chain);
                if ($fetcher instanceof \BCC\Trust\Onchain\Fetchers\CosmosFetcher) {
                    $info = $fetcher->testCw721ContractInfo($canonical);
                }
            } catch (\Throwable $e) {
                Logger::warning('[bcc-trust] CW-721 validation threw during manual intake', [
                    'chain_id' => $chainId,
                    'error'    => $e->getMessage(),
                ]);
                $info = null;
            }

            if (!is_array($info)) {
                // ⚠ `null` here means EITHER "not a CW-721" OR "the LCD did
                // not answer". The fetcher collapses transport failure and a
                // shape mismatch into the same null, so this refusal says
                // "could not validate" — never "this is not an NFT
                // collection". Reporting a provider failure as a negative
                // result is the defect issue #225 describes, and it would be
                // worse here, where a human acts on the answer.
                return $this->refuse(self::REFUSED_NOT_CW721, $chainId, $operatorId, $family);
            }

            $validation = self::VALIDATION_CW721;
            $candidate  = isset($info['name']) && is_string($info['name']) ? trim($info['name']) : '';
            if ($candidate !== '') {
                $name = $candidate;
            }
        }

        // ── 8. Insert + checked audit, atomically ───────────────────────
        // If the audit cannot be written, the collection must not remain
        // inserted: an unattributable manual add is exactly the thing the
        // checked-audit contract exists to prevent.
        $collectionId = 0;

        try {
            /** @var int $collectionId */
            $collectionId = TransactionManager::run(function () use (
                $chainId, $canonical, $name, $chainFamily, $chain, $operatorId, $validation
            ) {
                $data = [
                    'chain_id'         => $chainId,
                    'contract_address' => $canonical,
                    'collection_name'  => $name !== '' ? $name : null,
                    'token_standard'   => $chainFamily === 'cosmos' ? 'CW-721' : null,
                ];

                // `addManual()` forces `is_verified = 0` and
                // `source = 'manual'` in its own INSERT; neither is passed in,
                // so no caller can talk it into landing a pre-verified row.
                // `provisioning_state` takes its column default, `'none'`.
                $rowId = CollectionRepository::addManual($data);
                if (!is_int($rowId) || $rowId <= 0) {
                    throw new \RuntimeException('collection insert failed');
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
                return 'The contract could not be confirmed as a CW-721. This may mean it is not one, or that the chain endpoint did not answer — it is not proof either way, and nothing was added.';
            case self::REFUSED_WRITE_FAILED:
            default:
                return 'The collection could not be added and nothing was written. See the bcc-trust error log.';
        }
    }
}
