<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\NftChainCapability;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE FINISHED ROWS THE NFT DISCOVERY PAGE PRINTS.
 *
 * ── WHY A BUILDER SITS BETWEEN THE MODEL AND THE PAGE ───────────────────
 * The same reason {@see CosmwasmDiscoveryHealthSnapshot} exists, and the
 * same failure it was built to stop. When a renderer resolves its own
 * facts, the page and the thing it describes become two definitions of one
 * answer, written to agree, free to drift. That has happened twice in this
 * codebase already — see {@see \BCC\Trust\Onchain\Support\CosmwasmScanEligibility}
 * for the record of it.
 *
 * So this class does ALL the resolving, and
 * {@see \BCC\Trust\Onchain\Admin\NftDiscoveryPage} does none. Feed the page
 * rows carrying distinctive values and they come out unchanged; wire a
 * capability model that throws and the page never reaches it.
 *
 * ── ONE READ PER AUTHORITY, PER RENDER ──────────────────────────────────
 *   • `ChainRepository::getAll()`   ONE bounded read (LIMIT 200), all chains
 *   • `NftChainCapability::operationMatrix()`  ONE override read per chain
 *   • `CosmwasmDiscoveryHealthSnapshot::buildSummary()`  ONCE, Cosmos only
 *
 * The CosmWasm summary is fetched once for the whole page rather than once
 * per chain, and the CW engine section and the capability matrix are both
 * fed from it. Two reads would be two chances to disagree.
 *
 * ── IT IS READ-ONLY, AND THAT IS LOAD-BEARING ───────────────────────────
 * Nothing here writes, schedules, enables, seeds or busts a cache. Looking
 * at the control plane must never change it. In particular this class holds
 * no writer for `bcc_supports_nft_collections`, for
 * `manual_collection_discovery_enabled`, or for a driver override row —
 * PR 3 explains those values and cannot change them.
 */
final class NftDiscoveryControlPlaneSnapshot
{
    /**
     * The chain families the NFT capability model covers.
     *
     * ── NOT A SECOND CHAIN REGISTRY ─────────────────────────────────────
     * This is a FILTER over `wp_bcc_chains.chain_type`, not a catalogue of
     * chains. Every chain shown by this page is a row that already exists;
     * this list only decides which families get a tab. A chain added to the
     * chains table appears under its family with no edit here, which is the
     * whole point — an admin page that carried its own chain list would be
     * a duplicate registry, and would silently omit any chain somebody
     * forgot to add to it.
     *
     * The families the seed carries but the NFT model does not cover
     * (`thorchain`, `polkadot`, `near`) are absent deliberately: no NFT
     * driver in {@see \BCC\Trust\Onchain\Support\NftDriverRegistry} serves
     * them, so a tab would offer six rows of "no driver" and imply the
     * omission was a configuration gap.
     *
     * @var list<string>
     */
    public const FAMILIES = ['cosmos', 'evm', 'solana'];

    public const FAMILY_COSMOS = 'cosmos';
    public const FAMILY_EVM    = 'evm';
    public const FAMILY_SOLANA = 'solana';

    /** The family shown when no valid one is asked for. */
    public const DEFAULT_FAMILY = self::FAMILY_COSMOS;

    /** @var array<string, string> */
    private const FAMILY_LABELS = [
        self::FAMILY_COSMOS => 'Cosmos',
        self::FAMILY_EVM    => 'EVM',
        self::FAMILY_SOLANA => 'Solana',
    ];

    /** PURE. Is this a family this page knows how to show? */
    public static function isFamily(string $family): bool
    {
        return in_array($family, self::FAMILIES, true);
    }

    /** PURE. The tab label for a family, or the raw value if unknown. */
    public static function familyLabel(string $family): string
    {
        return self::FAMILY_LABELS[$family] ?? $family;
    }

    /**
     * Every chain of one family, with its full operation matrix.
     *
     * `getAll()` rather than `getActive()` on purpose: this is an
     * infrastructure page, and a deactivated chain that still carries
     * capability state is exactly the thing an operator comes here to see.
     * The row says whether it is active.
     *
     * ── AN EMPTY RESULT IS NOT A CLAIM ──────────────────────────────────
     * `ChainRepository::getAll()` does not distinguish a database failure
     * from a genuinely empty table — it returns `[]` for both. So the caller
     * is told how many chains were read, and renders "no chains of this
     * family are registered" rather than anything about capability.
     *
     * @return array{
     *     family: string,
     *     label: string,
     *     chains: list<array<string, mixed>>,
     *     cw_chains: list<array<string, mixed>>,
     *     supports_enumeration_engine: bool
     * }
     */
    public static function buildForFamily(string $family): array
    {
        if (!self::isFamily($family)) {
            $family = self::DEFAULT_FAMILY;
        }

        $rows = [];
        foreach (ChainRepository::getAll() as $chain) {
            if ((string) ($chain->chain_type ?? '') !== $family) {
                continue;
            }

            $matrix = NftChainCapability::operationMatrix($chain);

            // Presentation-only facts the matrix has no business carrying.
            $matrix['is_active']   = (int) ($chain->is_active ?? 0) === 1;
            $matrix['is_testnet']  = (int) ($chain->is_testnet ?? 0) === 1;
            $matrix['has_rpc_url'] = trim((string) ($chain->rpc_url ?? '')) !== '';
            $matrix['has_rest_url'] = trim((string) ($chain->rest_url ?? '')) !== '';

            $rows[] = $matrix;
        }

        // The CosmWasm engine section, from the SAME authority the old
        // Chains sub-tab used and the scanner panel still uses. Fetched
        // exactly once, and only for the family that has an engine.
        $cwChains = [];
        if ($family === self::FAMILY_COSMOS) {
            $summary  = CosmwasmDiscoveryHealthSnapshot::buildSummary();
            $cwChains = is_array($summary['chains'] ?? null) ? $summary['chains'] : [];
            $cwChains = self::annotateWithEnumerationStatus($cwChains, $rows);
        }

        return [
            'family'                      => $family,
            'label'                       => self::familyLabel($family),
            'chains'                      => $rows,
            'cw_chains'                   => $cwChains,
            // Whether ANY engine in this build can enumerate a chain of this
            // family. False for EVM and Solana permanently — see
            // NftDriverRegistry, which registers exactly one enumeration
            // driver and it is Cosmos-only.
            'supports_enumeration_engine' => $family === self::FAMILY_COSMOS,
        ];
    }

    /**
     * Carry each chain's ENUMERATION status onto its CosmWasm engine row.
     *
     * ── WHY THE JOIN HAPPENS HERE ───────────────────────────────────────
     * The engine section offers the one provider-consuming control on the
     * page, and that control may only be offered when the capability model
     * says the enumeration operation is ready. The two facts arrive from
     * two different authorities — the health snapshot and the capability
     * matrix — and something has to put them on one row.
     *
     * Doing it in the renderer would mean the renderer deciding which
     * chain's status applies to which row, which is a verdict, which is
     * exactly what the renderer is not allowed to do. So the join is done
     * here, by chain id, and the page prints what it is handed.
     *
     * ── AN UNMATCHED ROW FAILS CLOSED ───────────────────────────────────
     * A CosmWasm row whose chain id is not in the matrix set (a chain read
     * by one authority and not the other, or an id of 0) is annotated
     * `OP_UNKNOWN`. It is NOT dropped — hiding a chain an operator can see
     * elsewhere is its own kind of lie — and it is not defaulted to ready.
     *
     * @param list<array<string, mixed>> $cwChains
     * @param list<array<string, mixed>> $matrices
     * @return list<array<string, mixed>>
     */
    private static function annotateWithEnumerationStatus(array $cwChains, array $matrices): array
    {
        $statusByChain = [];
        foreach ($matrices as $matrix) {
            $chainId = (int) ($matrix['chain_id'] ?? 0);
            if ($chainId <= 0) {
                continue;
            }

            $operations = is_array($matrix['operations'] ?? null) ? $matrix['operations'] : [];
            $enumeration = is_array($operations['enumeration'] ?? null) ? $operations['enumeration'] : [];

            $statusByChain[$chainId] = [
                'status' => is_string($enumeration['status'] ?? null)
                    ? (string) $enumeration['status']
                    : NftChainCapability::OP_UNKNOWN,
                'reason' => is_string($enumeration['reason'] ?? null) ? (string) $enumeration['reason'] : '',
            ];
        }

        $out = [];
        foreach ($cwChains as $row) {
            $chainId = (int) ($row['chain_id'] ?? 0);
            $found   = $statusByChain[$chainId] ?? null;

            $row['enumeration_status'] = $found['status'] ?? NftChainCapability::OP_UNKNOWN;
            $row['enumeration_reason'] = $found['reason'] ?? '';

            $out[] = $row;
        }

        return $out;
    }
}
