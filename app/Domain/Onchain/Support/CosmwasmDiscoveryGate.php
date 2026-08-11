<?php

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fail-closed configuration gate for CosmWasm CW-721 discovery.
 *
 * ── THE RULE ────────────────────────────────────────────────────────────
 * A MISSING CONFIGURATION CONSTANT MUST NEVER MEAN "ENABLED".
 *
 * Every gate here answers FALSE when its constant is undefined. There is
 * no environment sniffing — no WP_ENVIRONMENT_TYPE, no host check, no
 * "looks like staging" heuristic. An environment either opts in
 * explicitly in wp-config.php or it does no scheduled discovery at all.
 *
 * ── THE TWO GATES ───────────────────────────────────────────────────────
 *   BCC_COSMWASM_DISCOVERY_ENABLED
 *       Governs ALL scheduled discovery: the daily new-code-id pass, the
 *       daily new-contract pass, the weekly retry pass, the monthly
 *       migration/metadata pass, AND the historical backfill.
 *
 *   BCC_COSMWASM_BACKFILL_ENABLED
 *       Governs the HISTORICAL BACKFILL specifically, and only in
 *       addition to the gate above. The backfill is the expensive,
 *       long-running one (juno has 5,149 code families — measured), so
 *       it stays behind its own switch that an operator turns on
 *       deliberately, watches, and can turn off again.
 *
 * The backfill gate is AND-ed with the discovery gate, not OR-ed: turning
 * discovery off stops everything, including a backfill in progress.
 *
 * ── WHAT THIS REPLACES ──────────────────────────────────────────────────
 * The retired `BCC_CW721_DISCOVERY_ENABLED` was fail-OPEN — its
 * implementation literally read `if (!defined(...)) return true;`, so
 * every environment ran discovery unless someone remembered to switch it
 * off. It is gone, along with the sampling path it guarded. There is now
 * exactly ONE gate family, and it defaults to off.
 *
 * CONSEQUENCE, AND IT IS INTENDED: an environment that does not define
 * BCC_COSMWASM_DISCOVERY_ENABLED does no discovery. Staging must define
 * it or discovery stops there.
 *
 * ── NO IMPLICIT TRIGGERS ────────────────────────────────────────────────
 * There is no activation-time backfill, no migration-triggered backfill
 * and no request-triggered discovery. Work happens only on the cron hooks
 * this gate guards (and on an explicit operator "run now"), which is what
 * keeps a deploy from kicking off tens of thousands of LCD requests.
 */
final class CosmwasmDiscoveryGate
{
    /**
     * Master switch for ALL scheduled discovery. FALSE when undefined.
     */
    public static function discoveryEnabled(): bool
    {
        if (!defined('BCC_COSMWASM_DISCOVERY_ENABLED')) {
            return false;
        }

        return (bool) constant('BCC_COSMWASM_DISCOVERY_ENABLED');
    }

    /**
     * Switch for the historical backfill specifically. FALSE when
     * undefined, and FALSE whenever discovery as a whole is off.
     */
    public static function backfillEnabled(): bool
    {
        if (!self::discoveryEnabled()) {
            return false;
        }
        if (!defined('BCC_COSMWASM_BACKFILL_ENABLED')) {
            return false;
        }

        return (bool) constant('BCC_COSMWASM_BACKFILL_ENABLED');
    }

    // ── Budgets ─────────────────────────────────────────────────────────

    /**
     * Wall-clock ceiling for ONE worker invocation, in seconds.
     *
     * Copied from {@see \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::MAX_RUNTIME_SECONDS}
     * for the same reason: Hostinger Business shared caps PHP
     * `max_execution_time` at 30s, and 20s leaves headroom for teardown.
     *
     * THIS DEADLINE WINS OVER THE REQUEST BUDGET. A tick that has spent
     * its wall clock stops immediately even with requests left, because
     * being killed mid-write is the failure mode that costs progress.
     */
    public const MAX_RUNTIME_SECONDS = 20;

    /** Requests per invocation when the override constant is undefined. */
    public const DEFAULT_REQUEST_BUDGET = 50;

    /** Hard ceiling on the override, so a typo cannot uncap the worker. */
    private const MAX_REQUEST_BUDGET = 500;

    /**
     * Request/page budget per worker invocation.
     *
     * Overridable via BCC_COSMWASM_REQUEST_BUDGET. Unlike the enable
     * gates this one has a safe default (an absent budget means the
     * documented 50, not "unlimited") — the fail-closed rule is about
     * whether work happens at all, and a budget constant cannot switch
     * work on.
     */
    public static function requestBudget(): int
    {
        if (defined('BCC_COSMWASM_REQUEST_BUDGET')) {
            $budget = (int) constant('BCC_COSMWASM_REQUEST_BUDGET');
            if ($budget > 0 && $budget <= self::MAX_REQUEST_BUDGET) {
                return $budget;
            }
        }

        return self::DEFAULT_REQUEST_BUDGET;
    }

    /**
     * Contracts sampled per code family when classifying it.
     *
     * Three is enough to survive a couple of dead instantiations without
     * turning family classification into a per-contract sweep. It is what
     * makes "a non-NFT family settles WITHOUT scanning all its contracts"
     * true: a 21-contract bindings family (Jackal code 4 — measured)
     * settles after at most three probed samples.
     */
    public const FAMILY_SAMPLE_SIZE = 3;

    /** Code-listing page size for `/cosmwasm/wasm/v1/code`. */
    public const CODE_PAGE_SIZE = 100;

    /**
     * Pages the incremental REVERSE tail walk may read per chain per pass.
     *
     * The walk is newest-first and stops the moment it meets the stored
     * watermark, so the typical daily cost is ONE request per chain. The
     * cap only bites after an outage long enough to bury the watermark
     * more than 500 code ids deep — and that case does NOT silently
     * truncate: it hands the catch-up to the historical backfill (see
     * {@see \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::requestCwBackfillRestart()}).
     */
    public const CODE_TAIL_MAX_PAGES = 5;

    /**
     * Pages the incremental REVERSE contract tail walk may read per family.
     *
     * Same shape as CODE_TAIL_MAX_PAGES: newest-first, stop at the first
     * already-inventoried address.
     */
    public const CONTRACT_TAIL_MAX_PAGES = 3;

    /**
     * Minimum elapsed time before the "monthly" pass runs again.
     *
     * wp-cron HAS NO MONTHLY INTERVAL and we do not invent one. The pass
     * rides the DAILY hook and this durable elapsed guard (read from
     * `wp_bcc_chain_checkpoints.cw_metadata_refreshed_at`) is what makes
     * it monthly.
     */
    public const METADATA_REFRESH_MIN_ELAPSED = 30 * 86400;

    /**
     * Operator priority/recovery hint: `BCC_CW721_CODE_IDS`.
     *
     * ── ITS MEANING CHANGED. IT IS NO LONGER AN ALLOWLIST. ──────────────
     * It used to BOUND discovery: only the code IDs it named (or the ones
     * sampled from already-curated collections) were ever enumerated,
     * which is precisely the closed loop this feature exists to break —
     * a chain with zero curated collections discovered nothing, forever.
     *
     * Its PERMITTED roles now, and only these:
     *   1. PRIORITY HINT — the named code IDs are classified and
     *      enumerated FIRST, ahead of the ordinary walk order.
     *   2. RECOVERY OVERRIDE — an operator can push a specific family
     *      back to the front after a bad classification or an outage.
     *   3. MANUALLY-CONFIRMED FAMILY HINT — "I checked this one myself,
     *      look at it early." It still gets classified normally; it does
     *      NOT skip probing and it does NOT imply confirmed_cw721.
     *   4. FALLBACK for a chain whose LCD cannot list codes at all
     *      (`/cosmwasm/wasm/v1/code` unavailable while
     *      `/code/{id}/contracts` works).
     *
     * WHAT IT MAY NEVER DO AGAIN: restrict which code IDs discovery is
     * allowed to see. An empty or absent constant means "no priority
     * hints", never "discover nothing".
     *
     * Format is unchanged for compatibility: a JSON object mapping chain
     * slug → list of code IDs, e.g. `{"cosmos":[434,467]}`.
     *
     * @return list<int> ascending, deduplicated, positive
     */
    public static function priorityCodeIds(string $slug): array
    {
        if ($slug === '' || !defined('BCC_CW721_CODE_IDS')) {
            return [];
        }

        /** @var mixed $raw */
        $raw = constant('BCC_CW721_CODE_IDS');
        $map = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($map)) {
            return [];
        }

        $entry = $map[$slug] ?? null;
        if (!is_array($entry)) {
            return [];
        }

        $ids = [];
        foreach ($entry as $value) {
            if (!is_int($value) && !is_string($value) && !is_float($value)) {
                continue;
            }
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        // sort() on an int-keyed map reindexes to a list.
        sort($ids);

        return $ids;
    }
}
