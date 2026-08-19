<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\CLI;

use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryService;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\CosmwasmPassReport;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `wp bcc-trust cosmwasm run` — ONE supervised CosmWasm discovery pass,
 * for ONE chain, watched by a human.
 *
 * ── WHY THIS COMMAND EXISTS ─────────────────────────────────────────────
 * Before it there was no way to run exactly one pass and watch it.
 * Defining `BCC_COSMWASM_DISCOVERY_ENABLED` ARMS THREE ALREADY-SCHEDULED
 * CRON HOOKS — `bcc_cosmwasm_daily_discovery`, `bcc_cosmwasm_weekly_retry`
 * and `bcc_cosmwasm_metadata_refresh` — which then fire unsupervised on
 * their own cadence. Unscheduling them does not hold either:
 * {@see CosmwasmDiscoveryWorker::register()} runs on `plugins_loaded`,
 * i.e. on EVERY REQUEST, and reschedules anything missing. So the choice
 * used to be "arm cron and hope" or "write a second discovery
 * implementation to watch" — and a second implementation is how an
 * operator ends up carefully observing something that is not what cron
 * runs. This command is the missing control: the production pass, once,
 * on demand, with the constant bypassed and everything else tightened.
 *
 * ── THE EXACT CALL PATH IT INVOKES ──────────────────────────────────────
 * Emission is INSEPARABLE from the canonical pass — `emitCollections()`
 * is the last thing `classifyAndEnumerate()` does — so this command does
 * cause collection rows to be written when the pass classifies a CW-721.
 * That is not a side door; it is the pass. The whole path, in order:
 *
 *   CosmwasmOneShotDiscoveryCommand::run()
 *     └─ CosmwasmDiscoveryWorker::runSupervisedSingleChainPass()
 *         └─ CosmwasmDiscoveryWorker::runChainPass()
 *             ├─ AdvisoryLock::acquire('bcc_cosmwasm_chain_<id>', 0)
 *             ├─ ChainCheckpointRepository::ensureExists()
 *             ├─ CosmwasmDiscoveryWorker::prepareChain()
 *             │    (pause / unsupported / circuit breaker / fetcher)
 *             ├─ CosmwasmDiscoveryWorker::dailyChainStep()   ← THE PASS
 *             │    ├─ CosmwasmCodeFamilyRepository::requeueForClassifierVersion()
 *             │    ├─ CosmwasmContractRepository::requeueForClassifierVersion()
 *             │    ├─ (a) CosmwasmDiscoveryService::ingestNewCodeFamilies()
 *             │    ├─ (b) CosmwasmDiscoveryService::enumerateFamilyTail() ×N
 *             │    └─ (c)+(d) CosmwasmDiscoveryWorker::classifyAndEnumerate()
 *             │          ├─ CosmwasmDiscoveryService::classifyFamily()   ×N
 *             │          ├─ CosmwasmDiscoveryService::enumerateFamilyPage() ×N
 *             │          ├─ CosmwasmDiscoveryService::classifyContract() ×N
 *             │          └─ CosmwasmDiscoveryService::emitCollections()  ← EMIT
 *             └─ finally: ChainCheckpointRepository::touchCwDiscovery()
 *                         + AdvisoryLock::release()
 *
 * The circuit breaker is NOT touched at the end of the pass. It is driven
 * per response inside {@see \BCC\Trust\Onchain\Support\ApiRetry::request()}
 * from real transport evidence, because "the pass returned" and "the chain
 * is reachable" are different claims — see the comment in
 * {@see CosmwasmDiscoveryWorker::runChainPass()}.
 *
 * `runBackfillTick()`, `runBackfillForChain()`, `runWeeklyRetry()` and
 * `runMetadataRefresh()` are NOT on that path and are not named anywhere
 * in this file. A backfill cannot be reached from here.
 *
 * ── WHAT IT BYPASSES: EXACTLY ONE THING, AND ONLY WHILE IT IS OFF ───────
 * `BCC_COSMWASM_DISCOVERY_ENABLED`, and nothing else — and the bypass is
 * one-directional. The command runs the pass while that constant is OFF
 * (which is the whole point) and REFUSES while it is ON, because an armed
 * constant means the three scheduled hooks are live and a pass can fire
 * either side of this one. The advisory lock only rules out SIMULTANEOUS
 * execution; it cannot rule out a tick a second before or a second after,
 * so the "one supervised pass" guarantee is void. See the comment at the
 * check itself.
 *
 * Still enforced, unchanged, by the same production code the scheduled
 * passes use:
 *   - the per-chain operator opt-in (`cosmwasm_nft_discovery_enabled`);
 *   - `is_active = 1` and `chain_type = 'cosmos'`;
 *   - the measured `unsupported` state and the operator `paused` state;
 *   - `BCC_COSMWASM_CHAIN_ALLOWLIST`;
 *   - the request budget and the wall-clock deadline
 *     ({@see CosmwasmTickBudget});
 *   - the per-chain `bcc_cosmwasm_chain_<id>` advisory lock;
 *   - the fail-closed repository read guards (a checkpoint read that did
 *     not run yields no eligible chain, so this command refuses).
 *
 * ── AND ONE PLACE IT IS DELIBERATELY STRICTER THAN CRON ─────────────────
 * READ THIS BEFORE "FIXING" IT.
 *
 * For the SCHEDULED passes, an UNDEFINED `BCC_COSMWASM_CHAIN_ALLOWLIST`
 * means "no canary restriction" — see
 * {@see CosmwasmDiscoveryGate::chainAllowlist()}, which returns null for
 * undefined and `[]` for defined-but-unusable. This command instead
 * FAILS CLOSED on undefined: it requires an allowlist that EXISTS and
 * that NAMES the requested chain.
 *
 * That asymmetry is intentional and is not a bug to be normalised away. A
 * scheduled pass has already been authorised twice over (a constant in
 * wp-config plus a per-chain opt-in an operator clicked); a human typing
 * a chain id at a terminal, with the master gate bypassed, has not. The
 * allowlist is the second independent statement of "yes, this chain, on
 * this environment". This command only ever ADDS requirements; it must
 * never relax one, because the moment it relaxes one it stops being a
 * safe way to observe what cron would do.
 *
 * ── THE BUDGETS, AND WHAT THEY DO AND DO NOT BOUND ──────────────────────
 * Both ceilings are read from {@see CosmwasmDiscoveryGate}. There is no
 * CLI-specific budget and no second budget system: a supervised run is
 * bounded exactly as a scheduled one is, which is the entire point of
 * running it. An earlier draft of this file described a temporary
 * 25-request canary limit (`BCC_COSMWASM_REQUEST_BUDGET=25`). That limit
 * was NOT adopted — the canonical ceiling is what runs.
 *
 *   REQUESTS — {@see CosmwasmDiscoveryGate::DEFAULT_REQUEST_BUDGET}: 50
 *   LOGICAL requests per invocation (`BCC_COSMWASM_REQUEST_BUDGET`
 *   overrides it within 1..500). For this command per-invocation and
 *   per-chain are the same number, because it builds one
 *   {@see CosmwasmTickBudget} for exactly one chain. The scheduled loop
 *   builds ONE budget and SHARES it across every eligible chain.
 *
 *   HTTP RETRIES ARE ATTEMPTS INSIDE A LOGICAL REQUEST AND ARE NOT
 *   CHARGED SEPARATELY. One logical request is one `spend()`. Beneath it
 *   {@see ApiRetry} may make up to 1 + `ApiRetry::DEFAULT_MAX_RETRIES`
 *   = 4 HTTP attempts, but only on 5xx and network errors. A 429 returns
 *   immediately without retrying or sleeping; a non-429 4xx is never
 *   retried.
 *
 *   WALL CLOCK — {@see CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS}: 20
 *   seconds, and IT IS COOPERATIVE, NOT A HARD PROCESS TIMEOUT. It is
 *   tested before each spend and at the top of each loop; WORK ALREADY IN
 *   FLIGHT IS ALLOWED TO FINISH and nothing interrupts it. Under WP-CLI
 *   there is no `max_execution_time` backstop either — the CLI SAPI is
 *   unlimited, and the 30s shared-host cap that motivated the 20s figure
 *   applies to web and cron requests, not to this one.
 *
 *   SO THE RUNTIME BOUND IS ~88 SECONDS, NOT 20. One logical request that
 *   times out on all four attempts costs 4 x 15s
 *   (`CosmosFetcher::$timeout`) plus ApiRetry's capped backoff of
 *   2s + 3s + 3s = 68s. If the deadline test passes an instant before 20s
 *   and that is the request which runs, the pass ends at ~88s. That is
 *   the bounded worst case, not the expectation.
 *
 * ── HOW MANY HTTP ATTEMPTS CAN ACTUALLY HAPPEN ──────────────────────────
 * NOT 50 x 4 = 200. That product is arithmetic, not a reachable state,
 * and quoting it overstates the load on the node by roughly 4x.
 *
 * Exhausting four attempts on all fifty logical requests would require
 * 50 x 8s = 400 SECONDS of mandatory backoff sleep, while new spends stop
 * at 20s. At most THREE fully-retried requests can even BEGIN, since each
 * costs 8s in sleep alone (t=0, t=8, t=16).
 *
 * Attempts are maximised not by full retries but by SINGLE-retry
 * requests: 2 attempts per 2s of sleep beats 3-per-5s and 4-per-8s. Ten
 * of those start before the deadline (t=0,2,..,18), giving
 * 10 x 2 + 40 x 1 = 60 ATTEMPTS. That is the ceiling, and it already
 * assumes every HTTP attempt itself takes zero time — which is
 * impossible.
 *
 * In practice a failing attempt consumes its 15s timeout, so a single
 * retrying request ends the pass by itself. Expect ~50 attempts for 50
 * logical requests, and ~50-55 in the worst realistic case.
 *
 * ── WHAT A FIRST DUNGEON PASS IS EXPECTED TO DO ─────────────────────────
 * ZERO EMITTED COLLECTIONS IS EXPECTED, and is a healthy first pass.
 * ZERO CONTRACTS IS **NOT** the expected result — though it stays
 * explainable by which families the pass actually reached.
 *
 * INVENTORY IS CAPPED AT ONE CODE PAGE ON A FRESH CHAIN. Dungeon Chain
 * (id 17) has roughly 179 code families, and a first pass inventories AT
 * MOST THE NEWEST {@see CosmwasmDiscoveryGate::CODE_PAGE_SIZE} = 100 of
 * them — not all ~179. The reverse walk RETURNS AFTER ITS FIRST PAGE when
 * the watermark is 0; see
 * {@see CosmwasmDiscoveryService::ingestNewCodeFamilies()}, "Nothing
 * inventoried yet: one page is enough ... The HISTORICAL BACKFILL owns
 * the rest of the history". {@see CosmwasmDiscoveryGate::CODE_TAIL_MAX_PAGES}
 * therefore never engages on the first pass.
 *
 * THE OLDER ~79 FAMILIES STAY UNTOUCHED, and stay untouched afterwards:
 * the watermark advances to the newest id, so later incremental passes
 * stop at their first page too. Only the historical backfill reaches
 * them, and it is disabled — this command refuses to run while
 * `BCC_COSMWASM_BACKFILL_ENABLED` is armed, and cannot invoke the
 * backfill in any case. IF DUNGEON'S NFT FAMILIES ARE AMONG THE OLDER
 * ONES, THIS CANARY FINDS NOTHING WHILE WORKING PERFECTLY.
 *
 * CLASSIFICATION REACHES ~5-25 FAMILIES under the canonical budget,
 * depending on what each costs. A family costs 1 request for its
 * contracts page plus up to
 * {@see CosmwasmDiscoveryGate::FAMILY_SAMPLE_SIZE} = 3 sampled contracts,
 * and EACH SAMPLE IS 2 OR 3 REQUESTS, not one:
 * {@see \BCC\Trust\Onchain\Fetchers\CosmosFetcher::probeCw721()} issues
 * `num_tokens`, then `contract_info`, then
 * `get_collection_info_and_extension` ONLY if `contract_info` failed. So
 * a family costs 1 request when its contract page is empty, 3 when the
 * first sample confirms, and up to 10 in the worst case.
 * {@see CosmwasmDiscoveryWorker::FAMILIES_PER_PASS} caps the count at 25
 * regardless. Measured across chains, roughly 50% of code families have
 * no contracts at all and only about 5% are CW-721.
 *
 * CONTRACT ROWS ARE BULK-INSERTED AND ARE NOT REQUEST-BOUNDED. One
 * contracts page returns up to `CosmosFetcher::CW721_PAGE_SIZE` = 100
 * addresses and ALL of them are inventoried for that single request.
 * Conservative worst case for a 50-request pass: ~1,700 contract rows.
 * That is expected; bulk inserts at that size are not a concern.
 *
 * So the expected first-pass shape is `code_families.inserted` up to 100,
 * `code_families.classified` between 5 and 25, `contracts.inserted`
 * plausibly in the hundreds, and `collections_emitted: 0`. Judge the run
 * by `errors`, `stop_reason` and whether the watermark moved — not by the
 * collection count. Re-run to spend another budget on the queue.
 *
 * AND JUDGE ITS SCOPE HONESTLY: this canary validates the NORMAL
 * INCREMENTAL DISCOVERY PATH. It does not, and cannot, prove complete
 * historical NFT coverage of the chain.
 *
 * ── DRY BY DEFAULT ──────────────────────────────────────────────────────
 * Without `--confirm=<slug>-<id>` the command validates everything,
 * prints the plan, and makes ZERO network requests and ZERO writes.
 *
 * ── EXIT CODES ──────────────────────────────────────────────────────────
 *   0  the pass ran to a natural stop (or a dry run validated cleanly).
 *      INCLUDING a run that discovered nothing.
 *   2  INVALID INVOCATION — a missing/malformed `--chain`, a missing
 *      `--once`, an unknown flag, a `--confirm` token that is not this
 *      chain's, or an attempt to run outside WP-CLI.
 *   3  ELIGIBILITY REFUSED — unknown chain, not opted in, not active,
 *      not cosmos, paused, measured unsupported, absent or non-matching
 *      allowlist, a fail-closed repository read, the historical backfill
 *      being armed, or the scheduled discovery gate being armed on this
 *      environment.
 *   4  LOCK CONTENDED — a cron tick or another operator holds
 *      `bcc_cosmwasm_chain_<id>`. Nothing was run and nothing was written.
 *   5  BUDGET EXHAUSTED — the pass ran and was CUT SHORT by its request
 *      budget or its wall-clock deadline. Progress is durable; work
 *      remains; re-run. This is a "there is more to do" signal, not an
 *      error.
 *   6  EXECUTION FAILED — the pass threw, or the chain refused to prepare
 *      after the lock was taken (breaker open, no usable fetcher).
 *   1  is left to WP-CLI's own generic failure.
 *
 * ## EXAMPLES
 *
 *     # Dry run: validate + print the plan. No network, no writes.
 *     wp bcc-trust cosmwasm run --chain=17 --once
 *
 *     # Execute one supervised pass against Dungeon.
 *     wp bcc-trust cosmwasm run --chain=17 --once --confirm=dungeon-17
 *
 * @package BCC\Trust\Onchain\CLI
 *
 * @phpstan-import-type ChainRow from ChainRepository
 *
 * @phpstan-type PreflightSummary array{
 *     state: string,
 *     max_code_id: int,
 *     last_discovery_at: string|null,
 *     last_error: string|null,
 *     families_total: int,
 *     families_pending: int,
 *     contracts_total: int
 * }
 * @phpstan-type Invocation array{ok: true, chain_id: int, confirm: string|null}|array{ok: false, error: string}
 */
final class CosmwasmOneShotDiscoveryCommand
{
    /** A clean run, including one that discovered nothing. */
    public const EXIT_OK = 0;

    /** Missing/malformed arguments, an unknown flag, or a non-CLI caller. */
    public const EXIT_INVALID_ARGS = 2;

    /** Every authorization condition other than the bypassed master gate. */
    public const EXIT_NOT_ELIGIBLE = 3;

    /** A peer holds the per-chain advisory lock. */
    public const EXIT_LOCK_CONTENDED = 4;

    /** The pass ran but was cut short by the request budget or the clock. */
    public const EXIT_BUDGET_EXHAUSTED = 5;

    /** The pass threw, or the chain refused to prepare inside the lock. */
    public const EXIT_EXECUTION_FAILED = 6;

    // ── Mirrors used ONLY to print honest runtime arithmetic ────────────
    //
    // NONE OF THESE BOUND ANYTHING. Every enforced budget is still read
    // live from CosmwasmDiscoveryGate at the point of printing. These four
    // exist because the preflight has to be able to say "20 seconds is
    // cooperative and the real bound is ~88s", and their real sources are
    // not reachable constants from here: `CosmosFetcher::$timeout` is a
    // private instance property, ApiRetry's backoff is computed rather
    // than declared, `ApiRetry::DEFAULT_MAX_RETRIES` is absent from the
    // transport test double that stands in at ApiRetry's production FQN,
    // and `CosmwasmDiscoveryWorker::FAMILIES_PER_PASS` is private.
    //
    // A MIRROR THAT DRIFTS IS WORSE THAN A LITERAL, so all four are pinned
    // to their real sources by CosmwasmCliPreflightAccuracyTest — by
    // reflection where the symbol is reachable and by reading the defining
    // source where it is not. Change any source and that test fails.

    /** Mirror of {@see \BCC\Trust\Onchain\Fetchers\CosmosFetcher::$timeout}. */
    private const HTTP_ATTEMPT_TIMEOUT_SECONDS = 15;

    /** Mirror of 1 + `ApiRetry::DEFAULT_MAX_RETRIES`. */
    private const HTTP_ATTEMPTS_PER_LOGICAL_REQUEST = 4;

    /** ApiRetry's capped backoff across a fully-retried call: 2s + 3s + 3s. */
    private const RETRY_BACKOFF_SECONDS = 8;

    /** Mirror of `CosmwasmDiscoveryWorker::FAMILIES_PER_PASS` (private there). */
    private const FAMILIES_PER_PASS_MIRROR = 25;

    /**
     * The ONLY flags this command accepts.
     *
     * Anything else is refused rather than ignored: `--confrim=dungeon-17`
     * silently becoming a dry run, or `--chains=17` silently becoming a
     * missing `--chain`, are exactly the typos a confirmation token exists
     * to catch.
     *
     * @var list<string>
     */
    private const ALLOWED_FLAGS = ['chain', 'once', 'confirm'];

    /**
     * Run ONE supervised incremental CosmWasm discovery pass.
     *
     * ## OPTIONS
     *
     * --chain=<id>
     * : REQUIRED. The numeric `wp_bcc_chains.id` to scan. Exactly one.
     *   There is no "all chains", no list, no wildcard and no default.
     *
     * [--once]
     * : REQUIRED. An explicit acknowledgement that this runs the pass
     *   exactly once and then stops. Must be the bare flag.
     *
     *   THE BRACKETS ARE WP-CLI GRAMMAR, NOT POLICY — DO NOT "TIDY" THE
     *   REQUIREMENT AWAY BECAUSE OF THEM. WP-CLI's synopsis grammar has no
     *   form for a MANDATORY bare flag: {@see \WP_CLI\SynopsisParser::parse()}
     *   retypes any non-optional `flag` token to `unknown`, the declaration
     *   is then discarded, and `--once` comes back out of
     *   {@see \WP_CLI\SynopsisValidator::unknown_assoc()} as an unrecognised
     *   parameter. Declaring it `--once` therefore made EVERY invocation die
     *   at WP-CLI's argument layer ("unknown --once parameter") before this
     *   method was ever entered. `[--once]` only tells the PARSER the flag
     *   may be absent; it is this command that decides absence is an error,
     *   and it still refuses with EXIT_INVALID_ARGS (2) — see
     *   {@see self::parseInvocation()}. Pinned by
     *   CosmwasmCliSynopsisTest, which fails if the brackets come off.
     *
     * [--confirm=<token>]
     * : The chain-specific execution token, `<slug>-<id>` (Dungeon Chain
     *   id 17 is `dungeon-17`). WITHOUT IT THE COMMAND IS A DRY RUN:
     *   it validates, prints the plan, and makes zero network requests
     *   and zero writes. A token that does not match this chain is an
     *   error, not a dry run — that is the copy-paste-from-another-chain
     *   case, and it must be loud.
     *
     * ## EXAMPLES
     *
     *     wp bcc-trust cosmwasm run --chain=17 --once
     *     wp bcc-trust cosmwasm run --chain=17 --once --confirm=dungeon-17
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $startedAt = self::utcNow();
        $startedTs = microtime(true);

        // ── 0. WP-CLI ONLY ──────────────────────────────────────────────
        //
        // Registration is already inside `if (defined('WP_CLI') && WP_CLI)`
        // in bcc-trust.php, so a web request never sees the command. This
        // second check is not redundant: it means that even if some future
        // code called this method directly from a controller, a hook or an
        // AJAX handler, it would refuse rather than run a chain walk on a
        // web request.
        if (!defined('WP_CLI') || !constant('WP_CLI')) {
            self::fail(
                'bcc-trust cosmwasm run is a WP-CLI command and cannot be invoked from a web request.',
                self::EXIT_INVALID_ARGS
            );
        }

        // ── 1. ARGUMENTS ────────────────────────────────────────────────
        $parsed = self::parseInvocation($args, $assoc);
        if ($parsed['ok'] === false) {
            self::fail($parsed['error'], self::EXIT_INVALID_ARGS);
        }

        $chainId = $parsed['chain_id'];

        // ── 2. THE CHAIN ROW ────────────────────────────────────────────
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            self::fail(
                sprintf('Chain %d is not in wp_bcc_chains. Nothing to scan.', $chainId),
                self::EXIT_NOT_ELIGIBLE
            );
        }
        $slug = $chain->slug;

        // ── 3. PREFLIGHT, BEFORE ANY NETWORK REQUEST ────────────────────
        $allowlist     = CosmwasmDiscoveryGate::chainAllowlist();
        $summaryBefore = CosmwasmDiscoveryService::chainSummary($chainId);
        self::printPreflight($chainId, $chain, $allowlist, $summaryBefore);

        // ── 4. AUTHORIZATION ────────────────────────────────────────────
        //
        // The allowlist check is FIRST because it is the one rule this
        // command adds on top of the scheduled policy, and an operator who
        // has not set it should be told THAT rather than a generic
        // "ineligible".
        if ($allowlist === null) {
            self::fail(
                'BCC_COSMWASM_CHAIN_ALLOWLIST is not defined. A supervised run REQUIRES an explicit '
                . 'allowlist naming the chain — undefined means "no restriction" for the scheduled '
                . 'passes, but it means "refuse" here. This command only ever adds requirements.',
                self::EXIT_NOT_ELIGIBLE
            );
        }
        if (!in_array($chainId, $allowlist, true)) {
            self::fail(
                sprintf(
                    'Chain %d is not named in BCC_COSMWASM_CHAIN_ALLOWLIST (currently: %s).',
                    $chainId,
                    $allowlist === [] ? 'defined but naming no usable chain id' : implode(',', $allowlist)
                ),
                self::EXIT_NOT_ELIGIBLE
            );
        }

        // ── THE TWO SCHEDULING GATES: ARMED MEANS REFUSE ────────────────
        //
        // Both of the next two checks run BEFORE the first network request
        // and before the first write, and both refuse with
        // EXIT_NOT_ELIGIBLE.
        //
        // BACKFILL IS CHECKED FIRST, AND THE ORDER IS LOAD-BEARING.
        // `backfillEnabled()` is AND-ed with `discoveryEnabled()` — see
        // {@see CosmwasmDiscoveryGate::backfillEnabled()}, which returns
        // false whenever discovery as a whole is off — so an armed backfill
        // ALWAYS implies an armed discovery gate. Checking discovery first
        // would therefore make this branch unreachable and take its
        // message with it. The backfill is the more specific and more
        // expensive condition, so it gets to name itself.
        //
        // The historical backfill is the expensive, long-running pass. A
        // supervised one-shot exists to watch ONE bounded incremental pass,
        // so it refuses to run on an environment where the backfill is
        // armed and could be interleaving on the same chain and the same
        // lock. Refusing is also what makes "this command cannot backfill"
        // provable rather than merely true-by-inspection.
        if (CosmwasmDiscoveryGate::backfillEnabled()) {
            self::fail(
                'BCC_COSMWASM_BACKFILL_ENABLED is on. A supervised one-shot refuses to run alongside '
                . 'the historical backfill — turn the backfill off before observing a single pass.',
                self::EXIT_NOT_ELIGIBLE
            );
        }

        // AND NOW THE INVERSION. READ IT TWICE; IT IS BACKWARDS FROM WHAT
        // THE REST OF THIS FILE PRIMES YOU TO EXPECT.
        //
        // BCC_COSMWASM_DISCOVERY_ENABLED is the one constant this command
        // BYPASSES. It bypasses it while it is OFF — that is the entire
        // point of the command — and it REFUSES while it is ON. Those are
        // not in tension; they are the same rule stated at both ends:
        //
        //   OFF → the three scheduled hooks are inert. Nothing else can be
        //         running this pass, so the pass this operator is watching
        //         is the ONLY pass, and "one supervised pass" is literally
        //         true. Run it.
        //   ON  → the scheduled hooks are LIVE and fire on their own
        //         cadence. There is then nothing for a supervised run to
        //         add, and something for it to destroy: the one-shot
        //         guarantee.
        //
        // AND THE LOCK IS NOT A SUBSTITUTE FOR THIS CHECK. The per-chain
        // `bcc_cosmwasm_chain_<id>` advisory lock is honest about exactly
        // one thing — SIMULTANEOUS execution. It says nothing whatsoever
        // about a scheduled tick that fires a second BEFORE this process
        // acquires the lock, or a second AFTER it releases. Both of those
        // are ordinary cron behaviour when the gate is armed, and both are
        // invisible in this command's output. The operator would then read
        // one summary and attribute to it the writes, the request spend,
        // the watermark movement and the state transitions of an
        // unwatched pass that ran either side of it. That is a worse
        // failure than a crash, because it is a wrong conclusion that
        // looks like a measurement.
        //
        // So: to observe one pass, turn the gate off. If the gate is on,
        // the scheduled pass is already running — read its telemetry.
        if (CosmwasmDiscoveryGate::discoveryEnabled()) {
            self::fail(
                'BCC_COSMWASM_DISCOVERY_ENABLED is on, so the scheduled discovery hooks are LIVE on this '
                . 'environment. A supervised one-shot refuses: the per-chain advisory lock prevents a cron '
                . 'tick and this process running SIMULTANEOUSLY, but not a scheduled pass immediately before '
                . 'or after this one — so the summary you are about to read would silently include work this '
                . 'command did not do. Turn the gate off to observe a single pass; leave it on and read the '
                . 'scheduled run\'s telemetry instead.',
                self::EXIT_NOT_ELIGIBLE
            );
        }

        // THE PRODUCTION ELIGIBILITY SET. Membership, not a re-derivation:
        // opt-in, is_active, chain_type, paused, unsupported, allowlist and
        // the fail-closed reads are all decided by the same selector the
        // scheduled passes use.
        if (!CosmwasmDiscoveryWorker::isChainScannable($chainId)) {
            self::fail(
                sprintf(
                    'Chain %d (%s) is not in the scanner\'s eligible set. The preflight above shows why: '
                    . 'check the opt-in flag, the checkpoint state (paused / unsupported), is_active and '
                    . 'chain_type. A fail-closed checkpoint read also lands here.',
                    $chainId,
                    $slug
                ),
                self::EXIT_NOT_ELIGIBLE
            );
        }

        // ── 5. CONFIRMATION / DRY RUN ───────────────────────────────────
        $expectedToken = self::confirmationToken($slug, $chainId);
        $given         = $parsed['confirm'];

        if ($given === null) {
            \WP_CLI::log('');
            \WP_CLI::log('DRY RUN — no network request was made and nothing was written.');
            \WP_CLI::log(sprintf(
                'To execute this pass: wp bcc-trust cosmwasm run --chain=%d --once --confirm=%s',
                $chainId,
                $expectedToken
            ));
            \WP_CLI::success('Plan validated. Chain ' . $chainId . ' is eligible for one supervised pass.');

            return;
        }
        if (!hash_equals($expectedToken, $given)) {
            self::fail(
                sprintf(
                    'Confirmation token does not match chain %d. Expected --confirm=%s. '
                    . 'A token minted for a different chain is refused, not downgraded to a dry run.',
                    $chainId,
                    $expectedToken
                ),
                self::EXIT_INVALID_ARGS
            );
        }

        // ── 6. EXECUTE — EXACTLY ONE PASS ───────────────────────────────
        $operator = self::operatorFingerprint();
        self::audit('cosmwasm_cli_pass_started', $chainId, $operator, $startedAt, null);

        $budget = new CosmwasmTickBudget();
        $report = new CosmwasmPassReport();

        \WP_CLI::log('');
        \WP_CLI::log('Running ONE incremental discovery pass…');

        $outcome = CosmwasmDiscoveryWorker::runSupervisedSingleChainPass($chainId, $budget, $report);

        $elapsed      = microtime(true) - $startedTs;
        $summaryAfter = CosmwasmDiscoveryService::chainSummary($chainId);
        $stopReason   = self::stopReason($outcome, $budget);
        $exitCode     = self::exitCodeFor($outcome, $stopReason);

        self::printSummary(self::buildSummary(
            $chainId,
            $slug,
            $outcome,
            $stopReason,
            $exitCode,
            $elapsed,
            $budget,
            $report,
            $summaryBefore,
            $summaryAfter
        ));

        self::audit('cosmwasm_cli_pass_' . $outcome, $chainId, $operator, $startedAt, self::utcNow());

        \BCC\Core\Log\Logger::info('[CosmwasmOneShotDiscoveryCommand] supervised pass finished', [
            'chain_id'   => $chainId,
            'slug'       => $slug,
            'outcome'    => $outcome,
            'stop'       => $stopReason,
            'exit_code'  => $exitCode,
            'requests'   => $budget->spent(),
            'started_at' => $startedAt,
            'ended_at'   => self::utcNow(),
            'operator'   => $operator,
        ]);

        if ($exitCode !== self::EXIT_OK) {
            \WP_CLI::warning(sprintf('Pass finished with exit code %d (%s).', $exitCode, $stopReason));
            \WP_CLI::halt($exitCode);
        }

        \WP_CLI::success(sprintf(
            'One pass complete on chain %d (%s). Zero discovered collections is not a failure — '
            . 'read code_families.inserted and the watermark.',
            $chainId,
            $slug
        ));
    }

    // ── Argument validation ─────────────────────────────────────────────

    /**
     * PURE. Shape-check the invocation and hand back the parsed values.
     *
     * Every branch refuses. There is no coercion, no "did you mean", and
     * no default: `--chain` and `--once` are both explicit and both
     * required, and anything that is not an unambiguous single positive
     * chain id is an error.
     *
     * Returns the parsed values rather than leaving the caller to re-read
     * `$assoc` — a caller that re-reads has to re-narrow the type, and
     * "the validator said it was fine" is exactly the reasoning that ends
     * in a suppressed type annotation.
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     *
     * @phpstan-return Invocation
     */
    private static function parseInvocation(array $args, array $assoc): array
    {
        if ($args !== []) {
            return ['ok' => false, 'error' => 'This command takes no positional arguments. Use --chain=<id> --once.'];
        }

        $unknown = array_values(array_diff(array_keys($assoc), self::ALLOWED_FLAGS));
        if ($unknown !== []) {
            $names = array_map(static fn(int|string $k): string => '--' . (string) $k, $unknown);

            return [
                'ok'    => false,
                'error' => 'Unknown flag(s): ' . implode(', ', $names)
                    . '. Accepted: --chain=<id>, --once, --confirm=<token>.',
            ];
        }

        if (!array_key_exists('once', $assoc)) {
            return [
                'ok'    => false,
                'error' => '--once is required. This command runs the pass exactly once and there is no other mode.',
            ];
        }
        if ($assoc['once'] !== true) {
            return [
                'ok'    => false,
                'error' => '--once must be the bare flag (not --once=<value>, not --no-once).',
            ];
        }

        if (!array_key_exists('chain', $assoc)) {
            return [
                'ok'    => false,
                'error' => '--chain=<id> is required. There is no default and no "all chains" mode.',
            ];
        }
        $rawChain = $assoc['chain'];
        if (!is_string($rawChain)) {
            return ['ok' => false, 'error' => '--chain requires a value, e.g. --chain=17.'];
        }
        // Strict: no leading zeros, no sign, no decimal point, no
        // separators, no surrounding whitespace, no "all", no "*". A cast
        // would happily turn "17abc", " 17" and "17.9" into 17, and a
        // silent coercion is how the wrong chain gets walked.
        if (preg_match('/^[1-9][0-9]{0,8}$/', $rawChain) !== 1) {
            return [
                'ok'    => false,
                'error' => sprintf(
                    '--chain must be a single positive integer chain id (got "%s"). '
                    . 'No lists, no wildcards, no "all", no zero, no negatives.',
                    $rawChain
                ),
            ];
        }

        $confirm = null;
        if (array_key_exists('confirm', $assoc)) {
            $rawConfirm = $assoc['confirm'];
            if (!is_string($rawConfirm)) {
                return ['ok' => false, 'error' => '--confirm requires a value, e.g. --confirm=dungeon-17.'];
            }
            $confirm = $rawConfirm;
        }

        return ['ok' => true, 'chain_id' => (int) $rawChain, 'confirm' => $confirm];
    }

    /**
     * The chain-specific execution token: `<slug>-<id>`.
     *
     * Chain-specific on purpose. A single global token would mean a
     * command line copy-pasted from a runbook for one chain executes
     * against whichever chain the operator edited `--chain` to, and the
     * confirmation would have confirmed nothing.
     */
    private static function confirmationToken(string $slug, int $chainId): string
    {
        $slug = strtolower(trim($slug));
        $slug = (string) preg_replace('/[^a-z0-9_-]+/', '', $slug);

        return ($slug === '' ? 'chain' : $slug) . '-' . $chainId;
    }

    // ── Preflight ───────────────────────────────────────────────────────

    /**
     * Everything an operator needs in order to decide NOT to press enter.
     *
     * Printed BEFORE the first network request and before any write, on
     * both the dry-run and the executing path, so the two are identical up
     * to this point.
     *
     * @param ChainRow $chain
     * @param list<int>|null $allowlist
     * @phpstan-param PreflightSummary $summary
     */
    private static function printPreflight(
        int $chainId,
        object $chain,
        ?array $allowlist,
        array $summary
    ): void {
        $optIn = CosmwasmDiscoveryWorker::discoveryOptInState($chain);

        \WP_CLI::log('── PREFLIGHT ───────────────────────────────────────────────');
        \WP_CLI::log(sprintf('  environment       : %s', self::environmentType()));
        \WP_CLI::log(sprintf('  site url          : %s', self::siteUrl()));
        \WP_CLI::log(sprintf('  database          : %s (fingerprint %s)', self::databaseName(), self::databaseFingerprint()));
        \WP_CLI::log(sprintf(
            '  chain             : id=%d slug=%s type=%s active=%s',
            $chainId,
            $chain->slug,
            $chain->chain_type,
            $chain->is_active
        ));
        \WP_CLI::log(sprintf('  operator opt-in   : %s', $optIn === null ? 'UNKNOWN (column absent — migration has not run)' : ($optIn ? 'yes' : 'NO')));
        \WP_CLI::log(sprintf(
            '  allowlist         : %s',
            $allowlist === null
                ? 'UNDEFINED (scheduled passes read this as "no restriction"; THIS COMMAND REFUSES)'
                : ($allowlist === []
                    ? 'defined but names no usable chain id'
                    : implode(',', $allowlist) . (in_array($chainId, $allowlist, true) ? ' (chain present)' : ' (CHAIN ABSENT)'))
        ));
        \WP_CLI::log(sprintf(
            '  discovery gate    : BCC_COSMWASM_DISCOVERY_ENABLED=%s  ← the ONE gate this command bypasses, '
            . 'and only while it is off; ON means cron is live and this command REFUSES',
            self::constantState('BCC_COSMWASM_DISCOVERY_ENABLED')
        ));
        \WP_CLI::log(sprintf(
            '  backfill gate     : BCC_COSMWASM_BACKFILL_ENABLED=%s  ← must be off',
            self::constantState('BCC_COSMWASM_BACKFILL_ENABLED')
        ));
        \WP_CLI::log(sprintf(
            '  checkpoint        : state=%s max_code_id=%d last_discovery_at=%s last_error=%s',
            $summary['state'],
            $summary['max_code_id'],
            $summary['last_discovery_at'] ?? 'never',
            $summary['last_error'] ?? 'none'
        ));
        \WP_CLI::log(sprintf(
            '  inventory         : families=%d (pending %d) contracts=%d',
            $summary['families_total'],
            $summary['families_pending'],
            $summary['contracts_total']
        ));
        // Every number here is read from the production gate. Restating a
        // budget as a literal is how a printed preflight starts describing
        // a run that is not the run about to happen.
        $attempts  = self::HTTP_ATTEMPTS_PER_LOGICAL_REQUEST;
        $worstCall = ($attempts * self::HTTP_ATTEMPT_TIMEOUT_SECONDS) + self::RETRY_BACKOFF_SECONDS;

        \WP_CLI::log(sprintf(
            '  request budget    : %d LOGICAL requests — the canonical scanner ceiling, not a canary-only limit',
            CosmwasmDiscoveryGate::requestBudget()
        ));
        \WP_CLI::log(sprintf(
            '                      HTTP retries are attempts INSIDE a logical request (up to %d, on 5xx/network'
            . ' only) and are NOT charged against that count',
            $attempts
        ));
        \WP_CLI::log(sprintf(
            '  runtime deadline  : %ds — COOPERATIVE, not a hard process timeout. Work already in flight is'
            . ' allowed to finish.',
            CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS
        ));
        \WP_CLI::log(sprintf(
            '                      one retrying request can extend the run to ~%ds (%d x %ds timeout + %ds'
            . ' backoff, begun just under the %ds mark)',
            CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS + $worstCall,
            $attempts,
            self::HTTP_ATTEMPT_TIMEOUT_SECONDS,
            self::RETRY_BACKOFF_SECONDS,
            CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS
        ));
        \WP_CLI::log(sprintf(
            '  page budgets      : %d code pages max, %d contract pages max, %d samples/family',
            CosmwasmDiscoveryGate::CODE_TAIL_MAX_PAGES,
            CosmwasmDiscoveryGate::CONTRACT_TAIL_MAX_PAGES,
            CosmwasmDiscoveryGate::FAMILY_SAMPLE_SIZE
        ));
        self::printExpectations($summary);
        \WP_CLI::log('  pass              : CosmwasmDiscoveryWorker::dailyChainStep() — the DAILY incremental pass');
        \WP_CLI::log('                      (a) ingestNewCodeFamilies  (b) enumerateFamilyTail');
        \WP_CLI::log('                      (c) classifyFamily/classifyContract  (d) emitCollections');
        \WP_CLI::log('  NOT invoked       : backfill, weekly retry, metadata refresh');
        \WP_CLI::log('────────────────────────────────────────────────────────────');
    }

    /**
     * What this pass will and will not achieve, in the operator's terms.
     *
     * ── WHY THIS IS PRINTED AT ALL ──────────────────────────────────────
     * A safety command that leaves an operator with the wrong expectation
     * is not safe, it is merely quiet. The specific wrong expectations
     * this block exists to prevent: that a first pass inventories the
     * WHOLE chain, that "0 collections" means the scanner is broken, that
     * "0 contracts" is equally fine, and that finding nothing proves the
     * chain has no NFTs.
     *
     * ── IT BRANCHES ON THE WATERMARK, AND MUST ──────────────────────────
     * The one-page cap is a property of a FRESH chain, not of the walk. On
     * `cw_max_code_id = 0` {@see CosmwasmDiscoveryService::ingestNewCodeFamilies()}
     * returns after its first page and hands the rest of history to the
     * backfill. On a resumed chain the walk is an ordinary reverse tail
     * that may read up to {@see CosmwasmDiscoveryGate::CODE_TAIL_MAX_PAGES}
     * pages. Printing the fresh-chain text on a resumed chain would be the
     * same class of lie in the other direction.
     *
     * @phpstan-param PreflightSummary $summary
     */
    private static function printExpectations(array $summary): void
    {
        \WP_CLI::log('  expectations      :');

        if ($summary['max_code_id'] === 0) {
            \WP_CLI::log(sprintf(
                '    inventory       : AT MOST the newest %d code families — NOT the whole chain. The reverse'
                . ' walk returns after ONE page while the watermark is 0.',
                CosmwasmDiscoveryGate::CODE_PAGE_SIZE
            ));
            \WP_CLI::log(
                '    older families  : anything below that first page stays UNTOUCHED, now and on later'
                . ' incremental passes. Only the historical backfill reaches them, and it is disabled.'
            );
        } else {
            \WP_CLI::log(sprintf(
                '    inventory       : an incremental reverse tail from watermark %d, up to %d pages of %d.',
                $summary['max_code_id'],
                CosmwasmDiscoveryGate::CODE_TAIL_MAX_PAGES,
                CosmwasmDiscoveryGate::CODE_PAGE_SIZE
            ));
            \WP_CLI::log(
                '    older families  : anything below the watermark is owned by the historical backfill,'
                . ' which is disabled.'
            );
        }

        \WP_CLI::log(sprintf(
            '    classification  : roughly 5-25 families, capped at %d — 1 request per family page plus 2-3'
            . ' per sampled contract, so a family costs 1 to 10.',
            self::FAMILIES_PER_PASS_MIRROR
        ));
        \WP_CLI::log(
            '    contract rows   : bulk-inserted and NOT request-bounded (one page carries up to 100'
            . ' addresses). Conservative worst case ~1,700 rows.'
        );
        \WP_CLI::log(
            '    collections     : 0 is EXPECTED and is a healthy first pass.'
        );
        \WP_CLI::log(
            '    contracts       : 0 is NOT the expected result, though it stays explainable by which'
            . ' families the pass reached.'
        );
        \WP_CLI::log(
            '    scope           : this validates the NORMAL INCREMENTAL DISCOVERY PATH. It does not prove'
            . ' complete historical NFT coverage of the chain.'
        );
    }

    // ── Summary ─────────────────────────────────────────────────────────

    /**
     * The machine-readable final summary.
     *
     * One JSON object on its own lines so `wp ... | tail -n +N | jq` and a
     * human reading the scrollback both work. Deltas are computed from two
     * bounded {@see CosmwasmDiscoveryService::chainSummary()} reads — the
     * same aggregate the admin panel uses — rather than from a second
     * counting path that could disagree with the panel.
     *
     * ── "INSERTED" AND "CLASSIFIED" ARE THE TWO WAYS A ROW MOVES ────────
     * `inserted` is the before/after delta in the durable inventory: rows
     * that did not exist and now do. `classified` is the UPDATE count —
     * the only field a discovery pass ever changes on an existing family
     * or contract row is its classification, so "how many rows were
     * updated" and "how many were classified" are the same number, and it
     * is counted where it happens rather than inferred from a diff. A
     * pass that inserts 179 and classifies 5 has, correctly, done almost
     * all of its work as inventory.
     *
     * @phpstan-param PreflightSummary $before
     * @phpstan-param PreflightSummary $after
     *
     * @return array<string, mixed>
     */
    private static function buildSummary(
        int $chainId,
        string $slug,
        string $outcome,
        string $stopReason,
        int $exitCode,
        float $elapsed,
        CosmwasmTickBudget $budget,
        CosmwasmPassReport $report,
        array $before,
        array $after
    ): array {
        $familiesBefore  = $before['families_total'];
        $familiesAfter   = $after['families_total'];
        $contractsBefore = $before['contracts_total'];
        $contractsAfter  = $after['contracts_total'];

        return [
            'command'        => 'bcc-trust cosmwasm run',
            'chain_id'       => $chainId,
            'chain_slug'     => $slug,
            'pass'           => 'daily_incremental',
            'outcome'        => $outcome,
            'stop_reason'    => $stopReason,
            'exit_code'      => $exitCode,
            'elapsed_seconds' => round($elapsed, 3),
            'requests' => [
                'used'      => $budget->spent(),
                'budget'    => CosmwasmDiscoveryGate::requestBudget(),
                'remaining' => $budget->remaining(),
            ],
            'pages_fetched' => [
                'code'      => $report->codePagesFetched,
                'contracts' => $report->contractPagesFetched,
                'total'     => $report->pagesFetched(),
            ],
            'code_families' => [
                'before'     => $familiesBefore,
                'after'      => $familiesAfter,
                'inserted'   => max(0, $familiesAfter - $familiesBefore),
                'classified' => $report->familiesClassified,
                'pending'    => $after['families_pending'],
            ],
            'contracts' => [
                'before'     => $contractsBefore,
                'after'      => $contractsAfter,
                'inserted'   => max(0, $contractsAfter - $contractsBefore),
                'classified' => $report->contractsClassified,
            ],
            'collections' => [
                'emitted' => $report->collectionsEmitted,
                'denied'  => $report->collectionsDenied,
            ],
            'watermark' => [
                'before' => $before['max_code_id'],
                'after'  => $after['max_code_id'],
                'moved'  => $after['max_code_id'] - $before['max_code_id'],
            ],
            'checkpoint_state' => $after['state'],
            'errors'           => $report->errors,
        ];
    }

    /**
     * Emit the summary.
     *
     * One JSON object on its own lines, between two rules, so a human
     * reading the scrollback and `wp ... | sed -n '/^{/,/^}/p' | jq` both
     * work.
     *
     * @param array<string, mixed> $payload
     */
    private static function printSummary(array $payload): void
    {
        \WP_CLI::log('');
        \WP_CLI::log('── SUMMARY (JSON) ──────────────────────────────────────────');
        \WP_CLI::log((string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        \WP_CLI::log('────────────────────────────────────────────────────────────');
    }

    // ── Outcome mapping ─────────────────────────────────────────────────

    /**
     * Why the pass stopped, in one machine-readable token.
     *
     * The wall clock is checked before the request budget for the same
     * reason {@see CosmwasmTickBudget::exhausted()} does: a tick with
     * requests left but no time left stopped because of the clock, and
     * saying otherwise would send an operator to raise the wrong ceiling.
     */
    private static function stopReason(string $outcome, CosmwasmTickBudget $budget): string
    {
        if ($outcome === CosmwasmDiscoveryWorker::PASS_LOCKED) {
            return 'lock_contended';
        }
        if ($outcome === CosmwasmDiscoveryWorker::PASS_SKIPPED) {
            return 'chain_refused_to_prepare';
        }
        if ($outcome === CosmwasmDiscoveryWorker::PASS_FAILED) {
            return 'execution_failed';
        }
        if ($budget->timedOut()) {
            return 'runtime_deadline_reached';
        }
        if ($budget->remaining() <= 0) {
            return 'request_budget_exhausted';
        }

        return 'pass_completed';
    }

    private static function exitCodeFor(string $outcome, string $stopReason): int
    {
        if ($outcome === CosmwasmDiscoveryWorker::PASS_LOCKED) {
            return self::EXIT_LOCK_CONTENDED;
        }
        if ($outcome === CosmwasmDiscoveryWorker::PASS_FAILED || $outcome === CosmwasmDiscoveryWorker::PASS_SKIPPED) {
            return self::EXIT_EXECUTION_FAILED;
        }
        if ($stopReason === 'runtime_deadline_reached' || $stopReason === 'request_budget_exhausted') {
            return self::EXIT_BUDGET_EXHAUSTED;
        }

        return self::EXIT_OK;
    }

    // ── Audit ───────────────────────────────────────────────────────────

    /**
     * A durable audit row for a supervised, privileged, one-shot run.
     *
     * TWO rows per execution — `_started` and `_<outcome>` — because the
     * audit table stores an action, a target and a timestamp, and NOT the
     * metadata array. Encoding the outcome into the action name is
     * therefore what makes the outcome durable; the richer structured
     * context (operator fingerprint, elapsed, budgets) goes to the
     * application log, which is where multi-field context belongs.
     *
     * NEVER a secret: no tokens, no credentials, no database password, no
     * confirmation string. The operator fingerprint is a short, sanitised
     * OS/WP-CLI username and nothing else.
     *
     * A dry run writes NO audit row, because a dry run writes NOTHING.
     */
    private static function audit(string $action, int $chainId, string $operator, string $startedAt, ?string $endedAt): void
    {
        AuditLogger::log(
            $action,
            $chainId,
            [
                'operator'   => $operator,
                'started_at' => $startedAt,
                'ended_at'   => $endedAt,
            ],
            'chain'
        );
    }

    /**
     * WHO ran it, "where safely available".
     *
     * WP-CLI usually runs as user 0 unless `--user` was passed, so the WP
     * id alone answers "nobody" on almost every real invocation. The OS
     * username is the useful half. It is read from the environment, capped
     * and stripped to a safe character set — it lands in a log line, and an
     * environment variable is attacker-controllable in principle.
     */
    private static function operatorFingerprint(): string
    {
        $osUser = getenv('USER');
        if (!is_string($osUser) || $osUser === '') {
            $osUser = getenv('USERNAME');
        }
        $osUser = is_string($osUser) ? $osUser : '';
        $osUser = (string) preg_replace('/[^A-Za-z0-9._-]+/', '', $osUser);
        $osUser = $osUser === '' ? 'unknown' : substr($osUser, 0, 32);

        $wpUser = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

        return sprintf('os:%s wp:%d', $osUser, $wpUser);
    }

    // ── Environment description helpers ─────────────────────────────────

    private static function environmentType(): string
    {
        if (function_exists('wp_get_environment_type')) {
            return (string) wp_get_environment_type();
        }

        return 'unknown';
    }

    private static function siteUrl(): string
    {
        if (function_exists('home_url')) {
            return (string) home_url();
        }

        return 'unknown';
    }

    private static function databaseName(): string
    {
        return defined('DB_NAME') ? (string) constant('DB_NAME') : 'unknown';
    }

    /**
     * A short, non-reversible fingerprint of WHICH database this is.
     *
     * Host + name only. Never the user and never the password — the point
     * is "am I pointed at staging or at production", not credentials.
     */
    private static function databaseFingerprint(): string
    {
        $host = defined('DB_HOST') ? (string) constant('DB_HOST') : '';

        return substr(hash('sha256', $host . '|' . self::databaseName()), 0, 12);
    }

    private static function constantState(string $name): string
    {
        if (!defined($name)) {
            return 'undefined';
        }

        return constant($name) ? 'true' : 'false';
    }

    private static function utcNow(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Print a refusal and halt with a specific, documented exit code. */
    private static function fail(string $message, int $code): never
    {
        \WP_CLI::error($message, false);
        \WP_CLI::halt($code);
    }
}
