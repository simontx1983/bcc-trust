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
 *
 * ── S4: THE SCANNER SUMMARY IS NO LONGER READ ───────────────────────────
 * This list used to carry a third entry,
 * `CosmwasmDiscoveryHealthSnapshot::buildSummary()` — four bounded aggregates,
 * fetched once per render for the Cosmos family. It fed `cw_chains`, which fed
 * the CosmWasm / CW-721 Discovery section. That section is withdrawn, so the
 * read is gone with it and this builder no longer touches the scanner at all.
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
     * ── S4: `cw_chains` AND `supports_enumeration_engine` ARE GONE ──────
     * Both existed solely to drive the CosmWasm / CW-721 Discovery section on
     * the NFT Discovery page. That section is withdrawn, so the snapshot no
     * longer fetches the scanner's health summary at all — which also removes
     * the second of the two `CosmwasmDiscoveryHealthSnapshot::buildSummary()`
     * calls that ran on every page load and whose result was then discarded.
     *
     * @return array{
     *     family: string,
     *     label: string,
     *     chains: list<array<string, mixed>>
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

            // ⚠ THE ONE SHARED ANSWER to "does this chain validate a submitted
            // contract?". Carried on the row so the Add Collection copy, the
            // capability editor and the intake service all read the SAME
            // predicate. The page used to re-derive it from a hardcoded
            // `$family === 'cosmos'`, which is how it kept telling EVM and
            // Solana operators their submissions were accepted unvalidated
            // after PR E started validating them.
            //
            // Per CHAIN, not per family: DECISION 7 approves only Ethereum and
            // Base on EVM, so two chains in one family legitimately differ.
            $matrix['manual_intake'] = NftChainCapability::canTakeManualIntake($chain);

            // Presentation-only facts the matrix has no business carrying.
            $matrix['is_active']   = (int) ($chain->is_active ?? 0) === 1;
            $matrix['is_testnet']  = (int) ($chain->is_testnet ?? 0) === 1;
            $matrix['has_rpc_url'] = trim((string) ($chain->rpc_url ?? '')) !== '';
            $matrix['has_rest_url'] = trim((string) ($chain->rest_url ?? '')) !== '';

            $rows[] = $matrix;
        }

        // ⚠ `manual_intake` ON EACH ROW IS UNAFFECTED by S4. It is the shared
        // answer to "does this chain validate a submitted contract?", read by
        // the Add Collection copy, the capability editor and the intake
        // service alike, and it is per CHAIN rather than per family. Removing
        // the enumeration keys below does not touch it: enumeration is about
        // whether a chain can be WALKED, which is a different question from
        // whether a submitted contract is checked.
        return [
            'family' => $family,
            'label'  => self::familyLabel($family),
            'chains' => $rows,
        ];
    }
}
