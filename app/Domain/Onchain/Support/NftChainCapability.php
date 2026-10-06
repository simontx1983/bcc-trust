<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE ONE ANSWER TO "MAY AN ADMINISTRATOR START A CHAIN-WIDE NFT COLLECTION
 * DISCOVERY ON THIS CHAIN, AND IF NOT, WHY NOT?"
 *
 * ── WHY THIS CLASS EXISTS ───────────────────────────────────────────────
 * It is the cross-family sibling of {@see CosmwasmScanEligibility}, built on
 * the same discipline and for the same reason. That class's docblock records
 * what happens without it: "scannable" was defined twice — once by the
 * worker that acted on it, once by the panel that displayed it — they were
 * written to agree, and they drifted anyway, twice in one fortnight. A
 * dashboard that disagrees with the worker is worse than no dashboard,
 * because it is believed.
 *
 * So this is again exactly ONE definition, and the admin surface, the job
 * starter and the capability editor will all call it.
 *
 * ── HOW IT DIFFERS FROM CosmwasmScanEligibility ─────────────────────────
 * They answer different questions and both stay:
 *
 *   CosmwasmScanEligibility — "will the CosmWasm scanner walk this COSMOS
 *   chain?" Knows about `BCC_COSMWASM_CHAIN_ALLOWLIST`, the pause state and
 *   the per-chain CosmWasm opt-in. Still gates the supervised CLI.
 *
 *   NftChainCapability (here) — "may an administrator start an NFT
 *   discovery on ANY chain?" Knows about BCC product support, the manual
 *   permission, the driver registry and per-driver provider readiness.
 *
 * They now share NOTHING. `cw_discovery_state` used to be read by both; S5
 * removed this class's dependency on it entirely (see below), leaving the
 * stored measurement to the scanner's own eligibility check until that is
 * retired in turn.
 *
 * ── S5: THE STORED MEASUREMENT IS NO LONGER AN INPUT ────────────────────
 * This class used to read `wp_bcc_chain_checkpoints.cw_discovery_state` and
 * name a `CHAIN_UNSUPPORTED` verdict ahead of every operator-controlled
 * reason. That value is DURABLE AND TERMINAL by design: it has no TTL, no
 * re-measure path, `pauseCwDiscovery()` refuses to override it, and the
 * rotation excluded the chain permanently. A chain that gained a wasm module
 * could never clear the flag except by a hand-written repository call — so
 * the capability model's answer could outlive the fact it described.
 *
 * It is gone from here. A 501 is still never reported as "not an NFT": the
 * per-request evidence in {@see \BCC\Trust\Onchain\Services\Validation\CosmosContractProbe}
 * resolves it to UNAVAILABLE, which is a statement about the ATTEMPT rather
 * than about the contract, and that boundary is pinned by name in
 * `CosmosNoWasmModuleIsNeverNotAnNftTest`. Missing or stale scanner evidence
 * now yields NO VERDICT AT ALL rather than a stale one.
 *
 * ⚠ Deliberately NOT replaced with a "physically incapable but unmeasured"
 * verdict. This class's vocabulary has none, `UNKNOWN` already means "this
 * install cannot say" (reusing it would make that rung ambiguous and would
 * fail closed for every Cosmos chain on a fresh install), and
 * `PROVIDER_UNAVAILABLE` is reserved for configuration.
 *
 * ── AUTHORED AND DERIVED — KEPT APART ───────────────────────────────────
 * Collapsing these into one hand-maintained column would let an operator
 * assert a capability a chain does not have:
 *
 *   AUTHORED  `wp_bcc_chains.bcc_supports_nft_collections`
 *             — BCC's PRODUCT decision. Never a claim about the blockchain.
 *   AUTHORED  `wp_bcc_chains.manual_collection_discovery_enabled`
 *             — permission to start one. NO CRON READS IT; after the
 *               automatic-discovery retirement there is no cron left that
 *               could.
 *   DERIVED   {@see NftDriverRegistry}   — what the CODE can do.
 *   DERIVED   {@see NftProviderReadiness} — what the CONFIG allows, per
 *             driver, at read time. Never stored.
 *
 * ── EXACTLY ONE VERDICT MEANS YES ───────────────────────────────────────
 * `SCANNABLE`. Every other value — including the one that means "we could
 * not tell" — is a NO. {@see isScannable()} is an identity test against that
 * single value rather than a list of exclusions, so a verdict from a newer
 * build, a typo, an empty string, or a value a partially-populated row never
 * set is NOT scannable. That direction costs a refusal; the other direction
 * costs an operator concluding a chain is covered when it is not.
 */
final class NftChainCapability
{
    // ── THE VERDICTS ────────────────────────────────────────────────────

    /** Nothing is blocking it: an administrator may start a discovery. */
    public const SCANNABLE = 'scannable';

    /**
     * A column is absent from the projection — a pre-migration install, or
     * a stale pre-migration transient.
     *
     * A SEPARATE value from {@see NO_BCC_SUPPORT} because the two are
     * different facts: one says somebody decided no, the other says nobody
     * has been able to decide anything yet. Both are NOT scannable, and
     * telling somebody they declined something they were never offered
     * sends them looking for a switch that is not there.
     */
    public const UNKNOWN = 'unknown';

    /** PRODUCT DECISION: `bcc_supports_nft_collections = 0`. */
    public const NO_BCC_SUPPORT = 'no_bcc_support';

    /**
     * STRUCTURAL: no driver in this build can enumerate this chain.
     *
     * True for every EVM chain and for Solana, permanently, because no
     * provider offers chain-wide NFT contract enumeration on those families.
     * This is NOT a configuration gap and NOT the same as
     * {@see PROVIDER_UNAVAILABLE}: no amount of Alchemy or Helius
     * credentials can change it. Alchemy enumerates a WALLET's contracts,
     * which is a different question.
     */
    public const NO_ENUMERATION_DRIVER = 'no_enumeration_driver';

    /** OPERATOR PERMISSION: `manual_collection_discovery_enabled = 0`. */
    public const MANUAL_DISABLED = 'manual_disabled';

    /**
     * An enumerating driver EXISTS for this chain, but none of them is
     * currently configured — a missing LCD endpoint, an unkeyed RPC URL, an
     * absent Helius credential.
     *
     * Distinct from {@see NO_ENUMERATION_DRIVER} on purpose, and the
     * distinction is the load-bearing one in this class: "we cannot do this
     * at all" and "we could, once you finish configuring it" send an
     * operator to two completely different places. Fusing them would let a
     * chain look one API key away from something no provider sells.
     */
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /**
     * PURE. The verdict for one chain.
     *
     * ── ORDER IS EXPLANATION, NOT LOGIC ─────────────────────────────────
     * Every condition is an AND, so the SET of scannable chains is the same
     * whatever order they are asked in. The order chosen is the one that
     * produces the most useful SENTENCE when the answer is no:
     *
     *   1. UNKNOWN                 we cannot read the overrides, or a
     *                              permission column is absent — either way
     *                              nobody can say anything yet
     *   2. NO_BCC_SUPPORT          a product decision, not a technical one
     *   3. NO_ENUMERATION_DRIVER   the code cannot, on any configuration
     *   4. MANUAL_DISABLED         a permission, one click away
     *   5. PROVIDER_UNAVAILABLE    configuration, and possibly spend
     *   6. SCANNABLE
     *
     * S5 removed a rung above (1) — `CHAIN_UNSUPPORTED`, read from a stored
     * 501 measurement — and deliberately put nothing in its place.
     *
     * (3) precedes (4) deliberately: telling an operator to flip a
     * permission on a chain nothing can enumerate sends them to a switch
     * that will not help. (4) precedes (5) for the same reason in reverse —
     * a permission the operator controls outright is worth naming before
     * work that may require provisioning a paid network.
     *
     * ── EVERY UNSURE BRANCH RETURNS A REFUSAL ───────────────────────────
     * A null opt-in (column absent from the projection) is a NO, not a
     * skipped filter. An unresolvable chain is a NO. An empty driver list is
     * a NO. There is no branch that falls through to "no restriction" — that
     * fall-through is the fail-OPEN shape this codebase already shipped once.
     *
     * @param bool $overridesAvailable   did we actually establish what this chain's
     *                                   driver overrides are? `false` = the override
     *                                   store was missing, failed, malformed or
     *                                   truncated, so `$enumerationDrivers` cannot be
     *                                   trusted and the verdict must fail closed.
     * @param bool|null   $bccSupportsNft       null = the column is absent from the projection
     * @param bool|null   $manualEnabled        null = the column is absent from the projection
     * @param list<string> $enumerationDrivers  ordered, from {@see NftDriverRegistry}
     * @param list<string> $readyEnumerationDrivers subset of the above that
     *                                          {@see NftProviderReadiness} accepts
     * @return string one of the six verdict constants on this class
     */
    public static function verdict(
        bool $overridesAvailable,
        ?bool $bccSupportsNft,
        ?bool $manualEnabled,
        array $enumerationDrivers,
        array $readyEnumerationDrivers
    ): string {
        // An unreadable override store is named FIRST, and BEFORE every reason
        // derived from the driver list — because when the overrides are
        // unknown, that list is exactly what we cannot trust. Reporting
        // NO_ENUMERATION_DRIVER or SCANNABLE from registry defaults here would
        // silently restore a driver an operator had disabled.
        //
        // S5 removed a rung above this one that refused a chain on a STORED
        // 501 measurement. Nothing took its place on purpose: see the class
        // docblock for why no "incapable but unmeasured" verdict was invented.
        if (!$overridesAvailable) {
            return self::UNKNOWN;
        }
        if ($bccSupportsNft === null || $manualEnabled === null) {
            return self::UNKNOWN;
        }
        if ($bccSupportsNft === false) {
            return self::NO_BCC_SUPPORT;
        }
        if ($enumerationDrivers === []) {
            return self::NO_ENUMERATION_DRIVER;
        }
        if ($manualEnabled === false) {
            return self::MANUAL_DISABLED;
        }
        if ($readyEnumerationDrivers === []) {
            return self::PROVIDER_UNAVAILABLE;
        }

        return self::SCANNABLE;
    }

    /**
     * PURE. Is this verdict the one that permits starting a discovery?
     *
     * Identity test against ONE value, never a list of exclusions — see the
     * class docblock for why that direction is the safe one.
     */
    public static function isScannable(string $verdict): bool
    {
        return $verdict === self::SCANNABLE;
    }

    // ── THE PER-OPERATION STATUSES ──────────────────────────────────────
    //
    // {@see verdict()} answers ONE question — may a discovery be started —
    // and its seven values are unchanged and untouched by everything below.
    //
    // The admin control plane asks a WIDER question: for each of the six
    // operations in {@see NftDriverRegistry::operations()}, what can this
    // chain actually do, and if it cannot, why not? That needs its own
    // vocabulary, because "no enumeration driver" is the wrong sentence to
    // print against `metadata`.
    //
    // Deliberately a SEPARATE namespace of constants rather than a widened
    // verdict enum: `verdict()` must never return one of these, and no
    // exhaustive test over the verdict values has to change.

    /** Nothing blocks this operation on this chain. */
    public const OP_READY = 'op_ready';

    /**
     * We could not establish something we need, so no claim is made.
     *
     * Covers an unreadable/overflowed/malformed override store AND an
     * absent capability column. Both mean the same thing to an operator:
     * nobody can say anything about this chain yet.
     */
    public const OP_UNKNOWN = 'op_unknown';

    /** PRODUCT DECISION: `bcc_supports_nft_collections = 0`. */
    public const OP_NO_BCC_SUPPORT = 'op_no_bcc_support';

    /**
     * STRUCTURAL: no driver in this build performs this operation on this
     * chain family, on any configuration.
     *
     * The permanent answer for `enumeration` on every EVM chain and on
     * Solana. Kept apart from {@see OP_DISABLED} and
     * {@see OP_PROVIDER_UNAVAILABLE} because this is the only one of the
     * three that no operator action can change.
     */
    public const OP_NO_DRIVER = 'op_no_driver';

    /**
     * The registry offers driver(s) for this operation and an override row
     * has switched every one of them off.
     *
     * Distinct from {@see OP_NO_DRIVER} on purpose: this one is an operator
     * decision recorded in `wp_bcc_chain_nft_capabilities`, and it is
     * reversible by editing that row. Reporting it as "no driver" would
     * send somebody looking for a provider that is already there.
     */
    public const OP_DISABLED = 'op_disabled';

    /** OPERATOR PERMISSION: `manual_collection_discovery_enabled = 0`. */
    public const OP_MANUAL_DISABLED = 'op_manual_disabled';

    /** A driver exists and is enabled, but nothing is configured to run it. */
    public const OP_PROVIDER_UNAVAILABLE = 'op_provider_unavailable';

    // ── The sub-reasons, for the sentence under the status ──────────────

    public const REASON_OVERRIDES_UNAVAILABLE     = 'overrides_unavailable';
    public const REASON_PRODUCT_COLUMN_ABSENT     = 'product_support_column_absent';
    public const REASON_MANUAL_COLUMN_ABSENT      = 'manual_permission_column_absent';
    public const REASON_PRODUCT_SUPPORT_DISABLED  = 'product_support_disabled';
    public const REASON_NO_REGISTERED_DRIVER      = 'no_registered_driver';
    public const REASON_ALL_DRIVERS_DISABLED      = 'all_drivers_disabled';
    public const REASON_MANUAL_PERMISSION_DISABLED = 'manual_permission_disabled';
    public const REASON_NO_READY_DRIVER           = 'no_ready_driver';
    public const REASON_READY                     = 'ready';

    // ── The three override states, for the editor ───────────────────────
    //
    // Named constants rather than bare strings because the editor renders
    // them, the tests assert on them and a typo in any one place would show
    // an operator the wrong control for a driver that is switched off.

    /** No row exists — the code registry decides, priority included. */
    public const OVERRIDE_STATE_DEFAULT = 'default';

    /** A row with `enabled = 0` removes a registry default. */
    public const OVERRIDE_STATE_DISABLED = 'disabled';

    /** A row with `enabled = 1` restores or reorders a registry default. */
    public const OVERRIDE_STATE_ENABLED = 'enabled';

    // ── Why a stored row is inert ───────────────────────────────────────

    public const STALE_UNKNOWN_OPERATION      = 'unknown_operation';
    public const STALE_UNKNOWN_DRIVER         = 'unknown_driver';
    public const STALE_DRIVER_LACKS_OPERATION = 'driver_does_not_perform_operation';
    public const STALE_DRIVER_LACKS_CHAIN     = 'driver_does_not_serve_chain';

    /**
     * The operations an ADMINISTRATOR can start, and therefore the only ones
     * the manual permission gates.
     *
     * ⚠ `manual_collection_discovery_enabled` is permission for an
     * administrator to submit ONE contract through manual intake. Despite the
     * word "discovery" in its name it authorises nothing chain-wide: it starts
     * no enumeration, resumes no scan, and cannot unfreeze the retired
     * scanner. Its one consumer is `ManualCollectionIntakeService`, and no
     * cron reads it. Applying it to `metadata` or `ownership` — which run as a
     * consequence of other work, never because somebody pressed a button —
     * would report those as blocked by a switch that has nothing to do with
     * them.
     *
     * Exactly one entry today. It is a list rather than a comparison so
     * that adding a second operator-started operation is one line here and
     * nothing anywhere else.
     *
     * @var list<string>
     */
    private const OPERATOR_STARTED_OPERATIONS = [NftDriverRegistry::OP_ENUMERATION];

    // ⚠⚠⚠ `hasOperatorStartableOperation()` AND `operatorStartedOperations()`
    // WERE DELETED IN REVIEW ROUND 4, and must not come back as the manual
    // permission's gate.
    //
    // Both asked "can this chain be ENUMERATED?", and both were used to decide
    // whether an administrator may submit ONE contract. Those are different
    // questions with different answers: no EVM or Solana driver claims
    // enumeration, so the enumeration answer refused manual intake on exactly
    // the chains manual intake was built for. The editor stopped asking it in
    // round 3; the capability PANEL went on asking it, which left the
    // permission grantable only by forging a POST.
    //
    // `canTakeManualIntake()` below is the manual-intake question, and it is
    // the only one either the writer or the renderer asks.
    //
    // OPERATOR_STARTED_OPERATIONS itself stays: it still marks which per-
    // operation rows are operator-started in `operationMatrix()`, which is the
    // genuine enumeration concept and remains frozen and separate.

    /**
     * PURE. Can an administrator submit ONE contract on this chain through
     * manual intake?
     *
     * ── ⚠⚠⚠ WHY THIS IS NOT hasOperatorStartableOperation() ─────────────
     * That predicate asks whether the chain can be ENUMERATED, because
     * `OPERATOR_STARTED_OPERATIONS` is `[OP_ENUMERATION]` — and no EVM or
     * Solana driver in this build claims enumeration, by design. So while
     * `manual_collection_discovery_enabled` was gated on it, manual intake
     * could **never be enabled on Ethereum, Base or Solana**: the editor
     * refused every grant on exactly the chains PR E exists to serve.
     *
     * The column stopped meaning "may start a chain-wide discovery" and started
     * meaning "may submit one contract". This predicate asks that question
     * directly instead of pretending it is the enumeration one, which is also
     * why the enumeration constant above is left alone: two different questions
     * now have two different predicates.
     *
     * ── WHAT MAKES A CHAIN ELIGIBLE ─────────────────────────────────────
     *  1. Some driver can VALIDATE a single contract on it. Asked of the
     *     REGISTRY, never of `chain_type`, so the day a family gains a
     *     validator this answer moves on its own.
     *  2. For EVM, the chain is inside the approved launch scope
     *     (DECISION 7 — Ethereum and Base). The EVM validator works on any EVM
     *     RPC, so without this a capability grant on Polygon would look
     *     available and then refuse at intake.
     *
     * ⚠ Override-free, for the same reason as the predicate above: this asks
     * what the CODE can do. An operator who has switched a driver off has not
     * made the chain structurally incapable and must still be able to hold the
     * permission while they switch it back on.
     *
     * @param object $chain a `ChainRow`-shaped projection
     */
    public static function canTakeManualIntake(object $chain): bool
    {
        if (NftDriverRegistry::driversFor($chain, NftDriverRegistry::OP_VALIDATION, []) === []) {
            return false;
        }

        if (strtolower((string) ($chain->chain_type ?? '')) === 'evm') {
            return NftLaunchChains::isLaunchChain($chain);
        }

        return true;
    }

    /**
     * EVERY operation's status for one chain, from ONE override read.
     *
     * ── WHY THIS LIVES HERE AND NOT ON THE ADMIN PAGE ───────────────────
     * Same reason the rest of this class exists. The page needs the driver
     * list, the readiness map and a reason per operation; assembling those
     * in a renderer would be a second definition of "can this chain do X",
     * free to disagree with {@see verdict()} on the row directly above it.
     *
     * It is also why {@see ChainNftCapabilityRepository::getForChain()} is
     * called exactly ONCE per render here — the six rows an operator sees
     * are one read, so they cannot disagree with each other.
     *
     * ── THE EDITOR CALLS getForChain() TOO, AND THAT IS CORRECT ─────────
     * {@see \BCC\Trust\Onchain\Services\NftCapabilityEditor} is the second
     * caller in the codebase. It deliberately uses the SAME whole-chain read
     * rather than a narrower "fetch one triple" helper, because the narrow
     * read cannot answer the question a write has to ask first: is this
     * chain's override state readable, well-formed, and below the row
     * ceiling? A single-row lookup would happily return a clean row from a
     * set that is truncated or contains a malformed sibling — and would then
     * permit a write against a store this class would refuse to draw any
     * conclusion from. So the write path reads everything, twice: once to
     * validate and detect a no-op, once afterwards to verify.
     *
     * ── THE LADDER, AND HOW IT DIFFERS FROM verdict() ───────────────────
     *
     *   1. overrides unavailable         OP_UNKNOWN               GLOBAL
     *   2. product column absent         OP_UNKNOWN               GLOBAL
     *   3. manual column absent          OP_UNKNOWN               started ops
     *   4. product support off           OP_NO_BCC_SUPPORT        GLOBAL
     *   5. no registered driver          OP_NO_DRIVER
     *   6. every driver overridden off   OP_DISABLED
     *   7. manual permission off         OP_MANUAL_DISABLED       started ops
     *   8. no ready driver               OP_PROVIDER_UNAVAILABLE
     *   9.                               OP_READY
     *
     * S5 removed a rung between (3) and (4): a stored `cw_discovery_state`
     * measurement answering `OP_CHAIN_UNSUPPORTED`, scoped to enumeration
     * alone. Nothing replaced it — a 501 chain is refused per request, by
     * evidence gathered when the request is made.
     *
     * ── A START-ONLY PERMISSION REFUSES STARTING, AND NOTHING ELSE ──────
     * Rungs (3) and (7) are SCOPED to operator-started operations — a LIST,
     * not one name. `manual_collection_discovery_enabled` is permission to
     * submit ONE contract through manual intake; nothing else reads it, so
     * nothing else may be refused by it being false OR absent.
     *
     * (1), (2) and (4) stay global: an unreadable override store means
     * operator intent is unknown for every driver on the chain, and the
     * product decision is about the chain rather than any one operation.
     *
     * ── AND THE ORDERING THAT DIFFERS FROM verdict() ────────────────────
     * (1)–(3) come BEFORE (4), which is the deliberate departure from
     * {@see verdict()}, which names the measured refusal first.
     *
     * The reason is what each answer is FOR. `verdict()` produces a decision,
     * and for a decision the measured 501 is the most useful thing to say
     * first: nothing an operator does can change it. This produces a
     * DISPLAY, and a display that prints a confident "this chain has no wasm
     * module" while the capability store is unreadable has converted "we
     * could not read our own configuration" into a statement about the
     * blockchain. The measurement is still shown — as `evidence` — but it
     * may not upgrade an unreadable read into a confident verdict.
     *
     * (5) before (6) before (7) before (8) is the same escalation
     * `verdict()` documents: structural, then operator-recorded, then
     * permission, then configuration.
     *
     * ── IT DECIDES NOTHING AND WRITES NOTHING ───────────────────────────
     * One bounded read of the override table, one checkpoint read, and pure
     * composition over the registry and readiness. No write, no network
     * call, no cache bust, no capability is enabled by looking at it.
     *
     * @param object $chain a `ChainRow`-shaped projection
     * @return array{
     *     chain_id: int,
     *     slug: string,
     *     name: string,
     *     chain_type: string,
     *     overrides_available: bool,
     *     overrides_reason: string|null,
     *     stale_overrides: list<array{operation: string, driver_key: string, enabled: bool, priority: int, reason: string}>,
     *     manual_intake: bool,
     *     bcc_supports: bool|null,
     *     manual_enabled: bool|null,
     *     verdict: string,
     *     operations: array<string, array{
     *         operation: string,
     *         status: string,
     *         reason: string,
     *         operator_started: bool,
     *         registered: list<string>,
     *         drivers: list<string>,
     *         readiness: array<string, bool>,
     *         ready: list<string>,
     *         endpoint_refusals: array<string, array{endpoint_display: string, code: int, message: string, detected_at: int}>,
     *         editable: list<array{driver_key: string, state: string, priority: int, default_priority: int, ready: bool}>
     *     }>
     * }
     */
    public static function operationMatrix(object $chain): array
    {
        $chainId = (int) ($chain->id ?? 0);

        // ONE read. Every operation below is answered from it, so the six
        // rows an operator sees cannot disagree with each other about what
        // the operator configured.
        $overrides = ChainNftCapabilityRepository::getForChain($chainId);
        $available = $overrides->isAvailable();

        $bccSupports   = self::bccNftSupportState($chain);
        $manualEnabled = self::manualDiscoveryState($chain);

        $operations = [];
        foreach (NftDriverRegistry::operations() as $operation) {
            // The registry's answer with NO overrides applied. This is a
            // COMPARISON BASELINE and never an answer: it is what makes
            // "the code cannot do this at all" distinguishable from "you
            // switched the driver off". It is computed only when the
            // override read SUCCEEDED, so it can never stand in for an
            // override set we failed to read.
            $registered = $available
                ? NftDriverRegistry::driversFor($chain, $operation, [])
                : [];

            $drivers = $available
                ? NftDriverRegistry::driversFor($chain, $operation, $overrides->rows())
                : [];

            $readiness = NftProviderReadiness::readinessMap($chain, $drivers);
            $ready     = NftProviderReadiness::readyDrivers($chain, $drivers);

            $refusals = [];
            foreach ($drivers as $driverKey) {
                $refusal = NftProviderReadiness::endpointRefusal($chain, $driverKey);
                if ($refusal !== null) {
                    $refusals[$driverKey] = $refusal;
                }
            }

            $operatorStarted = in_array($operation, self::OPERATOR_STARTED_OPERATIONS, true);

            // S5 removed a second scope here. A `$measurementApplies` flag
            // narrowed the stored `cw_discovery_state` refusal to
            // `enumeration` alone, because applying evidence about walking a
            // wasm module to metadata, ownership and validation had reported
            // a chain as wholly incapable on the strength of one measurement
            // about one operation. Both the flag and the rung it guarded are
            // gone; `$operatorStarted` below is the only remaining scope, and
            // it answers a different question — permission to START.
            [$status, $reason] = self::operationStatus(
                $available,
                $overrides->reason(),
                $bccSupports,
                $manualEnabled,
                $operatorStarted,
                $registered,
                $drivers,
                $ready
            );

            $operations[$operation] = [
                'operation'         => $operation,
                'status'            => $status,
                'reason'            => $reason,
                'operator_started'  => $operatorStarted,
                'registered'        => $registered,
                'drivers'           => $drivers,
                'readiness'         => $readiness,
                'ready'             => $ready,
                'endpoint_refusals' => $refusals,
                // The EDITABLE view of the same facts: one entry per
                // registry-declared driver, saying which of the three states
                // it is in. Derived from `$registered` and the SAME override
                // read — never a second read and never a second opinion. Empty
                // when the override store could not be established, because
                // an editor cannot honestly offer to change a state it could
                // not determine.
                'editable'          => $available
                    ? self::editableDrivers($chain, $operation, $registered, $overrides->rows())
                    : [],
            ];
        }

        // The UNCHANGED enumeration verdict, composed from the same inputs
        // so the page and any future job starter cannot disagree.
        $enumeration = $operations[NftDriverRegistry::OP_ENUMERATION] ?? null;

        return [
            'chain_id'             => $chainId,
            'slug'                 => (string) ($chain->slug ?? ''),
            'name'                 => (string) ($chain->name ?? ($chain->slug ?? '')),
            'chain_type'           => (string) ($chain->chain_type ?? ''),
            'overrides_available'  => $available,
            'overrides_reason'     => $overrides->reason(),
            // Rows this build no longer recognises for this chain. They are
            // already INERT — driversFor() discards them — but they are not
            // invisible, and an operator who cannot see them cannot remove
            // them. Listed separately from `editable` precisely because they
            // are not editable: the only thing that may be done to one is an
            // exact-row removal.
            'stale_overrides'      => $available
                ? self::staleOverrides($chain, $overrides->rows())
                : [],
            // ⚠⚠⚠ THE ONE ANSWER THE WRITER AND THE RENDERER SHARE.
            //
            // This used to be `hasOperatorStartableOperation()` — "can this
            // chain be ENUMERATED?" — under the key `operator_startable`. The
            // capability editor was corrected to grant on
            // canTakeManualIntake(), but the PANEL still read the enumeration
            // answer, so Ethereum, Base and Solana were grantable by a
            // hand-built POST and ungrantable through the admin UI, under copy
            // blaming a missing enumeration driver. A permission reachable only
            // by forging a request is not a permission an operator has.
            //
            // Renderer and writer now consume this single field, so they cannot
            // disagree about what may be granted.
            'manual_intake'        => self::canTakeManualIntake($chain),
            'bcc_supports'         => $bccSupports,
            'manual_enabled'       => $manualEnabled,
            'verdict'              => self::verdict(
                $available,
                $bccSupports,
                $manualEnabled,
                is_array($enumeration) ? $enumeration['drivers'] : [],
                is_array($enumeration) ? $enumeration['ready'] : []
            ),
            'operations'           => $operations,
        ];
    }

    /**
     * PURE. The three-state editable view of one operation's drivers.
     *
     * ── THE THREE STATES, AND WHY "ABSENT" IS ONE OF THEM ───────────────
     *   default   NO ROW EXISTS. The registry decides, including its
     *             priority. This is what every chain has on a fresh install.
     *   disabled  a row with `enabled = 0` removes a registry default.
     *   enabled   a row with `enabled = 1` restores or REORDERS one.
     *
     * "Absent" is a genuine state and not a synonym for "enabled at the
     * default priority", which is why the editor offers a way back to it
     * (an exact-row DELETE) rather than writing `enabled = 1` at the
     * registry priority. Materialising a row for every default would fill
     * the table with rows that say nothing, and the day a registry priority
     * changes, every one of them would silently pin the old value.
     *
     * Only drivers the registry ALREADY OFFERS for this chain and operation
     * appear. That is what makes the editor incapable of inventing a
     * capability: there is nothing to press for a triple the code does not
     * have.
     *
     * @param list<string> $registered registry defaults for this (chain, operation)
     * @param list<array{operation: string, driver_key: string, enabled: bool, priority: int}> $rows
     * @return list<array{driver_key: string, state: string, priority: int, default_priority: int, ready: bool}>
     */
    private static function editableDrivers(
        object $chain,
        string $operation,
        array $registered,
        array $rows
    ): array {
        $byDriver = [];
        foreach ($rows as $row) {
            if (($row['operation'] ?? '') !== $operation) {
                continue;
            }
            $byDriver[(string) ($row['driver_key'] ?? '')] = $row;
        }

        $out = [];
        foreach ($registered as $driverKey) {
            $default = NftDriverRegistry::defaultPriority($driverKey) ?? 0;
            $row     = $byDriver[$driverKey] ?? null;

            if ($row === null) {
                $state    = self::OVERRIDE_STATE_DEFAULT;
                $priority = $default;
            } else {
                $state    = ($row['enabled'] ?? false) === true
                    ? self::OVERRIDE_STATE_ENABLED
                    : self::OVERRIDE_STATE_DISABLED;
                $priority = (int) ($row['priority'] ?? $default);
            }

            $out[] = [
                'driver_key'       => $driverKey,
                'state'            => $state,
                'priority'         => $priority,
                'default_priority' => $default,
                'ready'            => NftProviderReadiness::isReady($chain, $driverKey),
            ];
        }

        return $out;
    }

    /**
     * PURE. Override rows this build cannot honour for this chain.
     *
     * ── FOUR WAYS A ROW GOES STALE, AND NONE OF THEM IS CORRUPTION ──────
     * A row can name an operation this build no longer has, a driver it
     * never had, a driver that has since stopped performing that operation,
     * or a driver pointed at a chain it does not serve. Every one of those
     * is a NORMAL consequence of a build changing under a database that did
     * not — a downgrade, a restored backup, a driver retired between
     * releases. (`das`, retired when the single Solana DAS driver became
     * `das_rpc` and `das_helius`, is the standing example.)
     *
     * They are already harmless: {@see NftDriverRegistry::driversFor()}
     * discards each one at the read. Surfacing them is about a different
     * problem — a row nobody can see is a row nobody can clean up, and the
     * next person to run a `SELECT` against this table finds configuration
     * they cannot account for.
     *
     * ── LISTED, NEVER SILENTLY REPAIRED ─────────────────────────────────
     * Nothing here rewrites or deletes. A save on some unrelated driver must
     * not quietly take a stale row with it: the row records that somebody
     * once made a decision, and discarding it as a side effect of an
     * unrelated action destroys that record without anybody choosing to.
     * Removal is its own explicit, exact-row action.
     *
     * @param list<array{operation: string, driver_key: string, enabled: bool, priority: int}> $rows
     * @return list<array{operation: string, driver_key: string, enabled: bool, priority: int, reason: string}>
     */
    private static function staleOverrides(object $chain, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $operation = (string) ($row['operation'] ?? '');
            $driverKey = (string) ($row['driver_key'] ?? '');

            $reason = null;
            if (!NftDriverRegistry::isOperation($operation)) {
                $reason = self::STALE_UNKNOWN_OPERATION;
            } elseif (!NftDriverRegistry::isDriver($driverKey)) {
                $reason = self::STALE_UNKNOWN_DRIVER;
            } elseif (!NftDriverRegistry::driverPerformsOperation($driverKey, $operation)) {
                $reason = self::STALE_DRIVER_LACKS_OPERATION;
            } elseif (!NftDriverRegistry::driverSupportsChain($driverKey, $chain)) {
                $reason = self::STALE_DRIVER_LACKS_CHAIN;
            }

            if ($reason === null) {
                continue;               // A live triple; it belongs in `editable`.
            }

            $out[] = [
                'operation'  => $operation,
                'driver_key' => $driverKey,
                'enabled'    => (bool) ($row['enabled'] ?? false),
                'priority'   => (int) ($row['priority'] ?? 0),
                'reason'     => $reason,
            ];
        }

        return $out;
    }

    /**
     * PURE. One operation's status and sub-reason.
     *
     * Every unsure branch refuses. There is no fall-through to "no
     * restriction" — see {@see verdict()} for why that shape is the one
     * this codebase has already shipped once and will not ship again.
     *
     * @param list<string> $registered registry defaults, override-free
     * @param list<string> $drivers    effective, after overrides
     * @param list<string> $ready      subset of $drivers
     * @return array{0: string, 1: string} status, reason
     */
    private static function operationStatus(
        bool $overridesAvailable,
        ?string $overridesReason,
        ?bool $bccSupportsNft,
        ?bool $manualEnabled,
        bool $operatorStarted,
        array $registered,
        array $drivers,
        array $ready
    ): array {
        // ── GLOBAL: nothing can be said about this chain at all ─────────
        if (!$overridesAvailable) {
            return [
                self::OP_UNKNOWN,
                self::REASON_OVERRIDES_UNAVAILABLE
                    . ($overridesReason !== null && $overridesReason !== '' ? ':' . $overridesReason : ''),
            ];
        }
        if ($bccSupportsNft === null) {
            return [self::OP_UNKNOWN, self::REASON_PRODUCT_COLUMN_ABSENT];
        }

        // ── SCOPED: permission to START, so only what can be started ────
        //
        // An install whose projection cannot carry the permission cannot
        // say whether a discovery may be STARTED — and that is the whole of
        // what it cannot say. Metadata, ownership, validation, curated
        // feeds and wallet discovery do not consult this column and are not
        // refused by its absence; reporting them unknown would blame five
        // working operations on a switch none of them reads.
        if ($operatorStarted && $manualEnabled === null) {
            return [self::OP_UNKNOWN, self::REASON_MANUAL_COLUMN_ABSENT];
        }

        // ── S5: THE STORED-MEASUREMENT RUNG USED TO SIT HERE ────────────
        //
        // It read `cw_discovery_state = unsupported` and refused enumeration
        // (and only enumeration — the scoping flag existed because applying
        // it to all six operations had reported a chain as wholly incapable
        // on the strength of one measurement about one of them).
        //
        // Removed because the value is durable and terminal with no
        // re-measure path, so the refusal could outlive the fact. A contract
        // on such a chain is still refused per request, by evidence gathered
        // at the time of asking, and as UNAVAILABLE rather than as invalid.
        //
        // ── GLOBAL: a product decision about the whole chain ────────────
        if ($bccSupportsNft === false) {
            return [self::OP_NO_BCC_SUPPORT, self::REASON_PRODUCT_SUPPORT_DISABLED];
        }

        // ── PER OPERATION: what the code, the operator and the config say ──
        if ($registered === []) {
            return [self::OP_NO_DRIVER, self::REASON_NO_REGISTERED_DRIVER];
        }
        if ($drivers === []) {
            return [self::OP_DISABLED, self::REASON_ALL_DRIVERS_DISABLED];
        }
        if ($operatorStarted && $manualEnabled === false) {
            return [self::OP_MANUAL_DISABLED, self::REASON_MANUAL_PERMISSION_DISABLED];
        }
        if ($ready === []) {
            return [self::OP_PROVIDER_UNAVAILABLE, self::REASON_NO_READY_DRIVER];
        }

        return [self::OP_READY, self::REASON_READY];
    }

    /**
     * PURE. Is this operation status the one that permits acting?
     *
     * Identity test against ONE value, for the same reason
     * {@see isScannable()} is: a status from a newer build, a typo or an
     * empty string must read as NOT permitted.
     */
    public static function isOperationReady(string $status): bool
    {
        return $status === self::OP_READY;
    }

    /**
     * Composed entry point: resolve every input for one chain and return the
     * verdict.
     *
     * NOTHING IN PRODUCTION CALLS THIS YET — PR 2 is a scaffold. It exists
     * so the admin surface and the job starter that land later share this
     * resolution instead of each assembling their own, which is precisely
     * how the two-definitions drift starts.
     *
     * An unresolvable chain returns {@see UNKNOWN}: a chain we cannot read
     * is one we cannot make any claim about, and `UNKNOWN` is not scannable.
     */
    public static function forChainId(int $chainId): string
    {
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return self::UNKNOWN;
        }

        return self::forChain($chain);
    }

    /**
     * Composed entry point for an already-resolved chain row.
     *
     * Follows the sequence the verdict documents: ask the registry for
     * ordered ENUMERATION drivers FIRST, then evaluate readiness for exactly
     * those drivers — never a chain-wide readiness flag. That ordering is
     * what keeps `NO_ENUMERATION_DRIVER` and `PROVIDER_UNAVAILABLE`
     * distinguishable.
     *
     * @param object $chain a `ChainRow`-shaped projection
     */
    public static function forChain(object $chain): string
    {
        $chainId = (int) ($chain->id ?? 0);

        // Overrides FIRST. If we cannot establish them, the driver list is
        // untrustworthy and every conclusion drawn from it would be a guess
        // in the permissive direction.
        $overrides = ChainNftCapabilityRepository::getForChain($chainId);

        $enumeration = $overrides->isAvailable()
            ? NftDriverRegistry::driversFor($chain, NftDriverRegistry::OP_ENUMERATION, $overrides->rows())
            : [];
        $ready = NftProviderReadiness::readyDrivers($chain, $enumeration);

        return self::verdict(
            $overrides->isAvailable(),
            self::bccNftSupportState($chain),
            self::manualDiscoveryState($chain),
            $enumeration,
            $ready
        );
    }

    /**
     * ONE READER, THREE ANSWERS — yes, no, or "this install cannot say".
     *
     * Typed `object` rather than the `ChainRow` shape on purpose, because
     * the honest answer depends on something the shape cannot express:
     * whether the row was projected BEFORE or AFTER the migration that adds
     * the column. A pre-migration row simply has no such property, and
     * reading it would raise a PHP warning and evaluate to null — so the
     * PRESENCE check comes first and answers `null`.
     *
     * The third answer is kept rather than collapsed to `false` here, and
     * collapsed by whoever needs a boolean: {@see verdict()} turns it into
     * {@see UNKNOWN}. Mirrors
     * {@see \BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker::discoveryOptInState()},
     * which established this pattern for the CosmWasm opt-in column.
     *
     * @return bool|null null = the projection carries no such property
     */
    public static function bccNftSupportState(object $chain): ?bool
    {
        return self::tinyintState($chain, 'bcc_supports_nft_collections');
    }

    /**
     * The manual-discovery PERMISSION, read with the same three-answer
     * discipline as {@see bccNftSupportState()}.
     *
     * @return bool|null null = the projection carries no such property
     */
    public static function manualDiscoveryState(object $chain): ?bool
    {
        return self::tinyintState($chain, 'manual_collection_discovery_enabled');
    }

    /** @return bool|null null = the projection carries no such property */
    private static function tinyintState(object $chain, string $column): ?bool
    {
        $vars = get_object_vars($chain);
        if (!array_key_exists($column, $vars)) {
            return null;
        }

        return (int) $vars[$column] === 1;
    }
}
