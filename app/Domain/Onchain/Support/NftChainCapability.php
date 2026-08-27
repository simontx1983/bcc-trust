<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
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
 * The one fact they share — the MEASURED `cw_discovery_state = 'unsupported'`
 * — is read from {@see ChainCheckpointRepository} by both. It is not
 * re-derived here, because a chain's wasm module answering HTTP 501 is a
 * measurement, and measurements get exactly one home.
 *
 * ── AUTHORED, MEASURED, DERIVED — KEPT APART ────────────────────────────
 * Collapsing these into one hand-maintained column would let an operator
 * assert a capability a chain does not have, and the measured 501 would have
 * nowhere to live:
 *
 *   AUTHORED  `wp_bcc_chains.bcc_supports_nft_collections`
 *             — BCC's PRODUCT decision. Never a claim about the blockchain.
 *   AUTHORED  `wp_bcc_chains.manual_collection_discovery_enabled`
 *             — permission to start one. NO CRON READS IT; after the
 *               automatic-discovery retirement there is no cron left that
 *               could.
 *   MEASURED  `wp_bcc_chain_checkpoints.cw_discovery_state`
 *             — observed, never authored.
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
    /**
     * The only `chain_type` for which `cw_discovery_state` carries meaning.
     * See {@see measuredUnsupported()}.
     */
    private const COSMOS_CHAIN_TYPE = 'cosmos';

    // ── THE VERDICTS ────────────────────────────────────────────────────

    /** Nothing is blocking it: an administrator may start a discovery. */
    public const SCANNABLE = 'scannable';

    /**
     * MEASURED CAPABILITY: the chain's wasm module answered with a 501.
     * No operator action can make a wasm module appear, which is why this
     * is named before every reason an operator CAN change.
     *
     * ── COSMOS ONLY ─────────────────────────────────────────────────────
     * The evidence behind this verdict — `cw_discovery_state` — is a
     * statement about a **CosmWasm** module, and it lives on
     * `wp_bcc_chain_checkpoints`, a table shared with the EVM indexer. A
     * non-Cosmos chain can therefore carry a `cw_*` value that means
     * nothing there: stale, defaulted, or written by an unrelated code path.
     * Reading it on an EVM or Solana row would report "this chain has no
     * wasm module" as though it explained why an Ethereum scan is refused —
     * true, irrelevant, and it would mask the real reason
     * ({@see NO_ENUMERATION_DRIVER}).
     *
     * {@see verdict()} therefore takes an already-scoped BOOLEAN, and
     * {@see forChain()} is the only place that decides a chain is Cosmos.
     */
    public const CHAIN_UNSUPPORTED = 'chain_unsupported';

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
     *   1. CHAIN_UNSUPPORTED       nothing anyone does can change it
     *   2. UNKNOWN                 we cannot read the overrides, or a
     *                              permission column is absent — either way
     *                              nobody can say anything yet
     *   3. NO_BCC_SUPPORT          a product decision, not a technical one
     *   4. NO_ENUMERATION_DRIVER   the code cannot, on any configuration
     *   5. MANUAL_DISABLED         a permission, one click away
     *   6. PROVIDER_UNAVAILABLE    configuration, and possibly spend
     *   7. SCANNABLE
     *
     * (4) precedes (5) deliberately: telling an operator to flip a
     * permission on a chain nothing can enumerate sends them to a switch
     * that will not help. (5) precedes (6) for the same reason in reverse —
     * a permission the operator controls outright is worth naming before
     * work that may require provisioning a paid network.
     *
     * ── EVERY UNSURE BRANCH RETURNS A REFUSAL ───────────────────────────
     * A null opt-in (column absent from the projection) is a NO, not a
     * skipped filter. An unresolvable chain is a NO. An empty driver list is
     * a NO. There is no branch that falls through to "no restriction" — that
     * fall-through is the fail-OPEN shape this codebase already shipped once.
     *
     * @param bool $measuredUnsupported  ALREADY SCOPED to Cosmos by the caller —
     *                                   see {@see CHAIN_UNSUPPORTED}. `false` covers
     *                                   both "measured and fine" and "never measured";
     *                                   an unmeasured chain is NOT refused, because the
     *                                   first pass is what CREATES the measurement and
     *                                   refusing it would be a permanent deadlock
     *                                   dressed up as caution.
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
     * @return string one of the seven verdict constants on this class
     */
    public static function verdict(
        bool $measuredUnsupported,
        bool $overridesAvailable,
        ?bool $bccSupportsNft,
        ?bool $manualEnabled,
        array $enumerationDrivers,
        array $readyEnumerationDrivers
    ): string {
        if ($measuredUnsupported) {
            return self::CHAIN_UNSUPPORTED;
        }
        // An unreadable override store is named right after the one fact no
        // operator can change, and BEFORE every reason derived from the
        // driver list — because when the overrides are unknown, that list is
        // exactly what we cannot trust. Reporting NO_ENUMERATION_DRIVER or
        // SCANNABLE from registry defaults here would silently restore a
        // driver an operator had disabled.
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

    /** MEASURED: the chain's wasm module answered 501. Cosmos only. */
    public const OP_CHAIN_UNSUPPORTED = 'op_chain_unsupported';

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
    public const REASON_MEASURED_NO_WASM          = 'measured_no_wasm_module';
    public const REASON_PRODUCT_SUPPORT_DISABLED  = 'product_support_disabled';
    public const REASON_NO_REGISTERED_DRIVER      = 'no_registered_driver';
    public const REASON_ALL_DRIVERS_DISABLED      = 'all_drivers_disabled';
    public const REASON_MANUAL_PERMISSION_DISABLED = 'manual_permission_disabled';
    public const REASON_NO_READY_DRIVER           = 'no_ready_driver';
    public const REASON_READY                     = 'ready';

    /**
     * The operations an ADMINISTRATOR can start, and therefore the only ones
     * the manual permission gates.
     *
     * `manual_collection_discovery_enabled` is permission to START a
     * discovery. Applying it to `metadata` or `ownership` — which run as a
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
     * called exactly once here and nowhere else in the codebase — the
     * override store has one reader, and this method and {@see forChain()}
     * are both downstream of it.
     *
     * ── THE LADDER, AND HOW IT DIFFERS FROM verdict() ───────────────────
     *
     *   1. overrides unavailable         OP_UNKNOWN
     *   2. a capability column is absent OP_UNKNOWN
     *   3. measured no wasm module       OP_CHAIN_UNSUPPORTED
     *   4. product support off           OP_NO_BCC_SUPPORT
     *   5. no registered driver          OP_NO_DRIVER
     *   6. every driver overridden off   OP_DISABLED
     *   7. manual permission off         OP_MANUAL_DISABLED  (started ops only)
     *   8. no ready driver               OP_PROVIDER_UNAVAILABLE
     *   9.                               OP_READY
     *
     * (1) and (2) come BEFORE (3), and that is the one deliberate departure
     * from {@see verdict()}, which names the measured refusal first.
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
     *     bcc_supports: bool|null,
     *     manual_enabled: bool|null,
     *     measured_unsupported: bool,
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
     *         endpoint_refusals: array<string, array{rpc_url: string, code: int, message: string, detected_at: int}>
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

        $measuredUnsupported = self::measuredUnsupported($chain);
        $bccSupports         = self::bccNftSupportState($chain);
        $manualEnabled       = self::manualDiscoveryState($chain);

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

            [$status, $reason] = self::operationStatus(
                $available,
                $overrides->reason(),
                $measuredUnsupported,
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
            'bcc_supports'         => $bccSupports,
            'manual_enabled'       => $manualEnabled,
            'measured_unsupported' => $measuredUnsupported,
            'verdict'              => self::verdict(
                $measuredUnsupported,
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
        bool $measuredUnsupported,
        ?bool $bccSupportsNft,
        ?bool $manualEnabled,
        bool $operatorStarted,
        array $registered,
        array $drivers,
        array $ready
    ): array {
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
        // Read for EVERY operation, not only the operator-started ones. An
        // install that cannot store the permission cannot describe itself,
        // and reporting the other five as confidently ready while the
        // permission column is missing would be a half-answer.
        if ($manualEnabled === null) {
            return [self::OP_UNKNOWN, self::REASON_MANUAL_COLUMN_ABSENT];
        }
        if ($measuredUnsupported) {
            return [self::OP_CHAIN_UNSUPPORTED, self::REASON_MEASURED_NO_WASM];
        }
        if ($bccSupportsNft === false) {
            return [self::OP_NO_BCC_SUPPORT, self::REASON_PRODUCT_SUPPORT_DISABLED];
        }
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
            self::measuredUnsupported($chain),
            $overrides->isAvailable(),
            self::bccNftSupportState($chain),
            self::manualDiscoveryState($chain),
            $enumeration,
            $ready
        );
    }

    /**
     * Is this chain MEASURED as lacking a CosmWasm module?
     *
     * ── THE COSMOS SCOPE LIVES HERE, AND ONLY HERE ──────────────────────
     * `cw_discovery_state` is evidence about a CosmWasm module, but it sits
     * on `wp_bcc_chain_checkpoints`, a row shared with the EVM indexer's own
     * `state` column. A non-Cosmos chain can carry a `cw_*` value that means
     * nothing there — stale, defaulted, or written by an unrelated path.
     *
     * Consulting it on an EVM or Solana row would answer "this chain has no
     * wasm module": true, irrelevant, and actively misleading, because it
     * would MASK the real reason an EVM scan is refused
     * ({@see NO_ENUMERATION_DRIVER} — no provider sells chain-wide EVM
     * enumeration) behind one that sounds like a chain defect.
     *
     * So the measurement is only consulted when the chain is actually
     * Cosmos, and {@see verdict()} receives a boolean it can trust.
     *
     * @param object $chain a `ChainRow`-shaped projection
     */
    private static function measuredUnsupported(object $chain): bool
    {
        if ((string) ($chain->chain_type ?? '') !== self::COSMOS_CHAIN_TYPE) {
            return false;
        }

        $chainId = (int) ($chain->id ?? 0);
        if ($chainId <= 0) {
            return false;
        }

        $checkpoint = ChainCheckpointRepository::get($chainId);
        if ($checkpoint === null || !isset($checkpoint->cw_discovery_state)) {
            return false;
        }

        return (string) $checkpoint->cw_discovery_state === ChainCheckpointRepository::CW_STATE_UNSUPPORTED;
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
