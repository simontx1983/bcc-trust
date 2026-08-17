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
 *             ├─ OnchainCircuitBreaker::recordSuccess()
 *             └─ finally: ChainCheckpointRepository::touchCwDiscovery()
 *                         + AdvisoryLock::release()
 *
 * `runBackfillTick()`, `runBackfillForChain()`, `runWeeklyRetry()` and
 * `runMetadataRefresh()` are NOT on that path and are not named anywhere
 * in this file. A backfill cannot be reached from here.
 *
 * ── WHAT IT BYPASSES: EXACTLY ONE THING ─────────────────────────────────
 * `BCC_COSMWASM_DISCOVERY_ENABLED`, and nothing else. Still enforced,
 * unchanged, by the same production code the scheduled passes use:
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
 * ── WHAT A FIRST DUNGEON PASS IS EXPECTED TO DO ─────────────────────────
 * ZERO DISCOVERED COLLECTIONS IS NOT A FAILURE, and the operator should
 * expect it on the first run.
 *
 * Dungeon Chain (id 17) has roughly **179 code families**. A first pass
 * on a fresh install is dominated by INVENTORY: the reverse code walk
 * ingests families into `wp_bcc_cosmwasm_code_families`, and only then
 * does classification start spending requests on them. With the
 * 25-request budget this canary is planned to run under
 * (`BCC_COSMWASM_REQUEST_BUDGET=25`), classification reaches only about
 * FIVE families — each one costs a metadata read plus up to
 * {@see CosmwasmDiscoveryGate::FAMILY_SAMPLE_SIZE} contract probes — out
 * of the ~179 inventoried. Measured across chains, roughly 50% of code
 * families have no contracts at all and only about 5% are CW-721.
 *
 * So the expected first-pass shape is: `code_families.inserted` in the
 * hundreds, `code_families.classified` around five, and
 * `collections_emitted: 0`. That is a healthy run. It means the inventory
 * landed and the queue is now primed. Judge the run by `errors`,
 * `stop_reason` and whether the watermark moved — not by the collection
 * count. Re-run to spend another budget on the queue.
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
 *      allowlist, a fail-closed repository read, or the historical
 *      backfill being armed on this environment.
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
     * --once
     * : REQUIRED. An explicit acknowledgement that this runs the pass
     *   exactly once and then stops. Must be the bare flag.
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
            '  discovery gate    : BCC_COSMWASM_DISCOVERY_ENABLED=%s  ← BYPASSED by this command, and only this',
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
        \WP_CLI::log(sprintf(
            '  budgets           : %d requests, %ds wall clock, %d code pages max, %d contract pages max, %d samples/family',
            CosmwasmDiscoveryGate::requestBudget(),
            CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS,
            CosmwasmDiscoveryGate::CODE_TAIL_MAX_PAGES,
            CosmwasmDiscoveryGate::CONTRACT_TAIL_MAX_PAGES,
            CosmwasmDiscoveryGate::FAMILY_SAMPLE_SIZE
        ));
        \WP_CLI::log('  pass              : CosmwasmDiscoveryWorker::dailyChainStep() — the DAILY incremental pass');
        \WP_CLI::log('                      (a) ingestNewCodeFamilies  (b) enumerateFamilyTail');
        \WP_CLI::log('                      (c) classifyFamily/classifyContract  (d) emitCollections');
        \WP_CLI::log('  NOT invoked       : backfill, weekly retry, metadata refresh');
        \WP_CLI::log('────────────────────────────────────────────────────────────');
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
