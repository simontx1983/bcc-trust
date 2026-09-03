<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE ONE ANSWER TO "MAY AN ADMINISTRATOR START A DISCOVERY SCAN ON THIS
 * CHAIN RIGHT NOW, AND IF NOT, WHY NOT?"
 *
 * ── THE DEFECT THIS CLOSES (PR 7.1) ─────────────────────────────────────
 * PR 7 asked that question in three places that each knew a different
 * subset of the rules:
 *
 *   - the PANEL rendered a scan control for every active Cosmos chain,
 *     consulting nothing but `cosmwasm_nft_discovery_enabled`;
 *   - the REQUEST service checked the per-chain opt-in and the canary
 *     allowlist, but not the environment master switches;
 *   - the EXECUTOR checked the master switches — and only when it was
 *     already holding a lease on a queued run.
 *
 * So an operator could be shown a control for a validator-only chain,
 * press it, have the request ACCEPTED, and get back a run that terminated
 * without contacting a provider at all. The ledger recorded that honestly
 * (`failed` / `chain_not_ready` / `chain_refused_to_prepare` — PR 7A's
 * status split was already careful never to call it a success), but
 * `chain_refused_to_prepare` is the SAME stop reason a paused chain, an
 * open circuit breaker and a missing driver produce. An operator reading
 * "did not finish, chain refused to prepare" against a chain they had just
 * enabled had no way to learn that a global switch was off — and the
 * cheapest wrong conclusion available was "this chain has no NFTs".
 *
 * ⚠ The failure mode is NOT that a disabled engine reported success. It is
 * that a refusal nobody could attribute is indistinguishable from a
 * finding. Both are fixed the same way: refuse EARLIER, and refuse with a
 * code that names the actual blocker.
 *
 * ── WHY THIS IS A COMPOSER, NOT A NEW RULEBOOK ──────────────────────────
 * Every rule already had exactly one owner, and none of them is
 * reimplemented here:
 *
 *   NFT product support   {@see NftChainCapability::bccNftSupportState()}
 *                         over `wp_bcc_chains.bcc_supports_nft_collections`
 *   environment switches  {@see CosmwasmDiscoveryGate}
 *   scan mode             {@see DiscoveryScanMode::forCheckpoint()}
 *   per-chain opt-in,
 *   pause, measured-
 *   unsupported, canary   {@see CosmwasmScanEligibility::verdict()}
 *   active run            {@see DiscoveryRunRepository::findActive()}
 *
 * This class owns ONE thing the others cannot: the ORDER they are asked in
 * and what the combination means. Adding a seventh copy of "is this chain
 * opted in" is exactly the drift CosmwasmScanEligibility's own header
 * documents happening twice in a fortnight.
 *
 * ── THE ORDER IS THE SECURITY PROPERTY ──────────────────────────────────
 * Product support is asked FIRST, before the opt-in and before the canary
 * allowlist. That is what makes the guarantee "no capability row and no
 * allowlist entry can make an unsupported chain scannable" true by
 * construction rather than by review: those inputs are never reached.
 *
 * ── FAIL-CLOSED, EVERYWHERE ─────────────────────────────────────────────
 * `bccNftSupportState()` returns null when the column is absent (a
 * pre-migration install, or a projection built before the ALTER). null is
 * NOT false-with-extra-steps — it means nobody could answer — and both
 * answer the same way here: not supported. An unreadable support column
 * costing a refused scan is the cheap direction; the other direction walks
 * a chain the product never approved.
 *
 * @see \BCC\Trust\Onchain\Services\DiscoveryRunService::chainIsScannable()
 * @see \BCC\Trust\Onchain\Workers\DiscoveryRunExecutor::execute()
 * @see \BCC\Trust\Onchain\Admin\VerifyCollectionsPage
 */
final class DiscoveryReadiness
{
    /**
     * PURE. The whole decision, given every fact, in the one order that
     * makes the guarantees above hold.
     *
     * Deliberately takes primitives rather than rows: the panel works over
     * chain rows it already fetched, the request service reads by id, and
     * the executor has a frozen scan mode off the run. All three can
     * supply these seven facts; none of them could share a query.
     *
     * @param string|null $chainType       `wp_bcc_chains.chain_type`
     * @param bool|null   $nftSupported    NftChainCapability::bccNftSupportState() — null = unreadable
     * @param bool        $discoveryEnabled CosmwasmDiscoveryGate::discoveryEnabled()
     * @param bool        $backfillEnabled  CosmwasmDiscoveryGate::backfillEnabled()
     * @param string      $scanMode         the mode the SERVER selected
     * @param string      $chainVerdict     CosmwasmScanEligibility::verdict()
     * @param bool        $activeRunExists  an un-terminated run already holds this chain
     *
     * @return string a bounded reason code; CosmwasmScanEligibility::ELIGIBLE means yes
     */
    public static function evaluate(
        ?string $chainType,
        ?bool $nftSupported,
        bool $discoveryEnabled,
        bool $backfillEnabled,
        string $scanMode,
        string $chainVerdict,
        bool $activeRunExists
    ): string {
        // (1) Only Cosmos has a CW-721 enumeration driver today.
        if ($chainType !== 'cosmos') {
            return DiscoveryRunError::CHAIN_UNSUPPORTED;
        }

        // (2) PRODUCT SUPPORT — asked before anything an operator can set.
        //
        // ⚠ This is the check that makes a validator-only chain unscannable
        // no matter what else is true. Jackal has a healthy CosmWasm REST
        // endpoint: it answers /cosmwasm/wasm/v1/code with a 200 and a real
        // code list. A reachable wasm module is NOT a product decision, and
        // treating "the endpoint replied" as "we support NFTs here" is how
        // a validator-only chain acquired a Scan button in PR 7.
        if ($nftSupported !== true) {
            return DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED;
        }

        // (3) + (4) THE ENVIRONMENT SWITCHES — GATED BY MODE, ON PURPOSE.
        //
        // ⚠ READ THIS BEFORE ADDING AN UNCONDITIONAL `!$discoveryEnabled`
        // CHECK. An earlier draft of this class had one, and it broke the
        // supervised operator path — the failure being in the direction
        // that blocks legitimate work, which is the one nobody notices in
        // review because the tests that catch it look like "the CLI is
        // broken" rather than "the rule is wrong".
        //
        // The two switches do NOT mean "discovery is allowed":
        //
        //   BCC_COSMWASM_DISCOVERY_ENABLED  arms the SCHEDULED engine.
        //       The supervised one-shot WP-CLI discovery command documents
        //       bypassing exactly this one constant, because a supervised
        //       run on an environment that has not armed it is the case
        //       that command exists for. A supervised, administrator-
        //       initiated pass is not the thing that switch withholds.
        //       (Named only in prose: a test forbids application code from
        //       referencing that command's class, so a web bootstrap can
        //       never reach it.)
        //
        //   BCC_COSMWASM_BACKFILL_ENABLED   arms the HISTORICAL walk.
        //       `CosmwasmDiscoveryWorker::runBackfillForChain()` is the ONLY
        //       pass that consults it, on its first line.
        //       `runSupervisedSingleChainPass()` — the incremental pass —
        //       consults neither, and `runChainPass()` below it consults
        //       neither.
        //
        // So readiness gates by MODE, which is the only way the request
        // gate and the executor can agree. Gating incremental on a switch
        // the executor never reads would refuse work that would in fact
        // have run; not gating historical would accept work that cannot.
        if ($scanMode === DiscoveryScanMode::HISTORICAL) {
            // backfillEnabled() is false whenever discoveryEnabled() is,
            // so ask the broader one first to name the actual blocker
            // rather than reporting the symptom.
            if (!$discoveryEnabled) {
                return DiscoveryRunError::DISCOVERY_GLOBALLY_DISABLED;
            }

            if (!$backfillEnabled) {
                return DiscoveryRunError::HISTORICAL_BACKFILL_DISABLED;
            }
        }

        // (5) The four per-chain rules, from their existing owner.
        //
        // Its vocabulary is already bounded and already operator-safe, so
        // the verdicts are RETURNED AS-IS rather than re-spelled. Minting
        // `chain_not_allowlisted` alongside the existing
        // `allowlist_excluded` would create two names for one fact — the
        // precise duplication §11 exists to stop.
        if ($chainVerdict !== CosmwasmScanEligibility::ELIGIBLE) {
            return match ($chainVerdict) {
                CosmwasmScanEligibility::UNSUPPORTED        => DiscoveryRunError::CHAIN_UNSUPPORTED,
                CosmwasmScanEligibility::NOT_OPTED_IN       => CosmwasmScanEligibility::NOT_OPTED_IN,
                CosmwasmScanEligibility::PAUSED             => CosmwasmScanEligibility::PAUSED,
                CosmwasmScanEligibility::ALLOWLIST_EXCLUDED => CosmwasmScanEligibility::ALLOWLIST_EXCLUDED,
                // UNKNOWN, or a verdict from a newer build. Both mean
                // "nobody could answer", which is a NO.
                default                                     => DiscoveryRunError::DISCOVERY_DISABLED,
            };
        }

        // (6) One active run per chain is the ledger's own invariant
        //     (`uq_active`). Asking here turns a unique-key collision into
        //     an operator sentence instead of a caught exception.
        if ($activeRunExists) {
            return DiscoveryRunError::ALREADY_ACTIVE;
        }

        return CosmwasmScanEligibility::ELIGIBLE;
    }

    /** PURE. Exactly one reason code means yes. */
    public static function isEligible(string $reason): bool
    {
        return $reason === CosmwasmScanEligibility::ELIGIBLE;
    }

    /**
     * The REQUEST path: resolve every fact by id and report the mode the
     * server picked.
     *
     * ── WHY THE ACTIVE-RUN CHECK IS NOT DONE HERE ───────────────────────
     * {@see \BCC\Trust\Onchain\Services\DiscoveryRunService::createRun()}
     * already owns that invariant, and owns it BETTER: the `uq_active`
     * unique index decides atomically, the insert path returns the winning
     * `active_run_id` so the operator can act on it, and a bounded retry
     * recovers the real race where the active run terminates between the
     * check and the insert.
     *
     * A pre-read here would be a SECOND authority for one rule, and a
     * weaker one — classic time-of-check/time-of-use. It also silently
     * broke both behaviours: the refusal lost `active_run_id`, and the
     * race-recovery retry became unreachable because this check fired
     * first. Two existing tests caught exactly that.
     *
     * ⚠ So `already_active` is still part of {@see evaluate()} — the panel
     * genuinely needs to say "a scan is already running for this chain" —
     * but the REQUEST path lets the index answer it.
     *
     * @param string|null $forcedScanMode a mode pinned by a supervised CLI
     *                                    caller; the browser can never
     *                                    supply one
     *
     * @return array{reason: string, eligible: bool, scan_mode: string}
     */
    public static function forRequest(int $chainId, ?string $forcedScanMode = null): array
    {
        return self::resolve($chainId, $forcedScanMode, false);
    }

    /**
     * The EXECUTOR path: re-ask immediately before provider work, using the
     * mode FROZEN onto the run row at request time.
     *
     * ── WHY THE ACTIVE-RUN CHECK IS EXCLUDED HERE ───────────────────────
     * The run being executed IS the active run. Including the check would
     * make every execution refuse itself with `already_active`, which is
     * the kind of self-blocking guard that only shows up in production.
     *
     * ── AND WHY THE FROZEN MODE IS USED ─────────────────────────────────
     * A checkpoint that completed between queue and pickup would otherwise
     * flip the mode mid-flight, so the run would be re-judged against a
     * switch it was never queued under.
     *
     * @return array{reason: string, eligible: bool, scan_mode: string}
     */
    public static function forExecution(int $chainId, string $frozenScanMode): array
    {
        return self::resolve($chainId, $frozenScanMode, false);
    }

    /**
     * The PANEL path: decide over rows the caller ALREADY fetched.
     *
     * The panel's discipline is a fixed number of bounded reads and no
     * per-row lookup, so it must not be made to call {@see forRequest()} in
     * a loop. It supplies the same facts from what it has and gets the same
     * verdict from the same lines.
     *
     * @param object      $chain      a ChainRow
     * @param object|null $checkpoint that chain's checkpoint row, or null
     * @param bool        $activeRun  whether an un-terminated run holds it
     *
     * @return array{reason: string, eligible: bool, scan_mode: string}
     */
    public static function forDisplay(object $chain, ?object $checkpoint, bool $activeRun = false): array
    {
        $chainId  = (int) ($chain->id ?? 0);
        $scanMode = DiscoveryScanMode::forCheckpoint($checkpoint);

        $reason = self::evaluate(
            isset($chain->chain_type) ? (string) $chain->chain_type : null,
            NftChainCapability::bccNftSupportState($chain),
            CosmwasmDiscoveryGate::discoveryEnabled(),
            CosmwasmDiscoveryGate::backfillEnabled(),
            $scanMode,
            CosmwasmScanEligibility::verdict(
                $chainId,
                $checkpoint !== null ? (string) ($checkpoint->cw_discovery_state ?? '') : null,
                self::optInState($chain),
                CosmwasmDiscoveryGate::chainAllowlist()
            ),
            $activeRun
        );

        return [
            'reason'    => $reason,
            'eligible'  => self::isEligible($reason),
            'scan_mode' => $scanMode,
        ];
    }

    /**
     * The PANEL path, over the summary the page ALREADY built.
     *
     * ── WHY NOT forDisplay() IN A LOOP ──────────────────────────────────
     * {@see \BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot::buildSummary()}
     * already reads every checkpoint in one bounded call and hands back,
     * per chain, the verdict and the backfill timestamp this decision
     * needs. Re-reading each chain's checkpoint to rebuild an object would
     * put a per-row query back on a page whose whole discipline is a fixed
     * number of reads — and a test in CosmwasmScannerPanelOwnershipTest
     * pins that by forbidding the repository import in the page.
     *
     * So the facts come from the summary row; only the two things the
     * summary does not carry — the chain's product-support flag and the
     * environment switches — are read here, and neither is a query.
     *
     * @param object               $chain      a ChainRow
     * @param array<string, mixed> $summaryRow one entry of $summary['chains']
     * @param bool                 $activeRun  an un-terminated run holds it
     *
     * @return array{reason: string, eligible: bool, scan_mode: string}
     */
    public static function forSummaryRow(object $chain, array $summaryRow, bool $activeRun = false): array
    {
        $completedAt = isset($summaryRow['backfill_completed_at']) && is_string($summaryRow['backfill_completed_at'])
            ? $summaryRow['backfill_completed_at']
            : null;

        $scanMode = DiscoveryScanMode::forCompletedAt($completedAt);

        // ⚠ An absent or non-string verdict is NOT treated as eligible.
        // `evaluate()` maps any unrecognised verdict to a refusal, so a
        // summary built by an older build fails closed.
        $verdict = isset($summaryRow['eligibility']) && is_string($summaryRow['eligibility'])
            ? $summaryRow['eligibility']
            : '';

        $reason = self::evaluate(
            isset($chain->chain_type) ? (string) $chain->chain_type : null,
            NftChainCapability::bccNftSupportState($chain),
            CosmwasmDiscoveryGate::discoveryEnabled(),
            CosmwasmDiscoveryGate::backfillEnabled(),
            $scanMode,
            $verdict,
            $activeRun
        );

        return [
            'reason'    => $reason,
            'eligible'  => self::isEligible($reason),
            'scan_mode' => $scanMode,
        ];
    }

    /**
     * PURE. Does this chain row belong in the NFT scan surface at all?
     *
     * Separate from {@see evaluate()} because the two questions have
     * different answers and different UI consequences: an unsupported chain
     * gets NO control (there is nothing an operator can do about it from
     * this screen), whereas a supported chain with the engine switched off
     * gets a DISABLED control that explains itself.
     *
     * ⚠ Showing a disabled button for a validator-only chain would be a
     * standing invitation to file a ticket asking why it cannot be enabled.
     */
    public static function isNftDiscoverySurface(object $chain): bool
    {
        if ((string) ($chain->chain_type ?? '') !== 'cosmos') {
            return false;
        }

        return NftChainCapability::bccNftSupportState($chain) === true;
    }

    /**
     * @return array{reason: string, eligible: bool, scan_mode: string}
     */
    private static function resolve(int $chainId, ?string $forcedScanMode, bool $checkActiveRun): array
    {
        $unknown = static fn(): array => [
            'reason'    => DiscoveryRunError::CHAIN_UNKNOWN,
            'eligible'  => false,
            'scan_mode' => DiscoveryScanMode::HISTORICAL,
        ];

        if ($chainId <= 0) {
            return $unknown();
        }

        $chain = ChainRepository::getById($chainId);
        if ($chain === null || (int) ($chain->is_active ?? 0) !== 1) {
            return $unknown();
        }

        $checkpoint = ChainCheckpointRepository::get($chainId);

        $scanMode = $forcedScanMode !== null && DiscoveryScanMode::isValid($forcedScanMode)
            ? $forcedScanMode
            : DiscoveryScanMode::forCheckpoint($checkpoint);

        $activeRun = false;
        if ($checkActiveRun) {
            $activeRun = DiscoveryRunRepository::findActive(
                DiscoveryJobKind::COSMWASM_DISCOVERY,
                $chainId
            ) !== null;
        }

        $reason = self::evaluate(
            isset($chain->chain_type) ? (string) $chain->chain_type : null,
            NftChainCapability::bccNftSupportState($chain),
            CosmwasmDiscoveryGate::discoveryEnabled(),
            CosmwasmDiscoveryGate::backfillEnabled(),
            $scanMode,
            CosmwasmScanEligibility::verdict(
                $chainId,
                $checkpoint !== null ? (string) ($checkpoint->cw_discovery_state ?? '') : null,
                self::optInState($chain),
                CosmwasmDiscoveryGate::chainAllowlist()
            ),
            $activeRun
        );

        return [
            'reason'    => $reason,
            'eligible'  => self::isEligible($reason),
            'scan_mode' => $scanMode,
        ];
    }

    /**
     * The per-chain opt-in, as the tri-state CosmwasmScanEligibility wants.
     *
     * null (the projection carries no such property) is passed through
     * rather than folded to false, because that class distinguishes
     * "an operator said no" from "this install cannot answer".
     */
    private static function optInState(object $chain): ?bool
    {
        $vars = get_object_vars($chain);
        if (!array_key_exists('cosmwasm_nft_discovery_enabled', $vars)) {
            return null;
        }

        return (int) $vars['cosmwasm_nft_discovery_enabled'] === 1;
    }
}
