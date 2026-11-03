<?php

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use BCC\Trust\Onchain\Services\CollectionService;
use BCC\Trust\Onchain\Services\ValidatorPageMinter;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\Support\ProviderOutcomeReceipt;

/**
 * Chain Refresh Cron
 *
 * Separate cron jobs per data type, each with its own interval.
 *
 * Jobs:
 *  - bcc_refresh_validators   (every 1 hour)
 *  - bcc_index_validators     (every 4 hours)
 *  - bcc_refresh_collections  (every 4 hours)
 *
 * COLLECTION DISCOVERY IS NOT HERE. `bcc_index_collections` used to sweep
 * every active EVM/Solana/Cosmos chain for "top collections" on a 4-hour
 * cadence; it is retired. Chain-wide NFT collection discovery is now
 * operator-initiated, one named chain at a time. What survives on this
 * class is validator indexing and refresh, plus the WALLET-LINKED half of
 * the collection refresh — a wallet whose owner verified it is not a
 * chain-wide scan.
 */
class ChainRefreshService
{
    const BATCH_SIZE = 50;

    /**
     * Register cron hooks.
     */
    public static function init(): void
    {
        add_filter('cron_schedules', [__CLASS__, 'add_cron_intervals']);

        add_action('bcc_refresh_validators',  [__CLASS__, 'refresh_validators']);
        add_action('bcc_refresh_collections', [__CLASS__, 'refresh_collections']);
        add_action('bcc_index_validators',   [__CLASS__, 'index_validators']);

        // Schedule crons directly — init() is already called during plugins_loaded,
        // so hooking plugins_loaded again would never fire.
        // wp_next_scheduled() prevents double-scheduling on every request.
        self::schedule_crons();
    }

    /**
     * Register custom cron intervals.
     *
     * Idempotent: only adds `every_4_hours` when no other plugin has
     * already defined it. Callers at plugin-load time, activation, and
     * init all converge on the same schedule table without clobbering.
     */
    /** @param array<string, array{interval: int, display: string}> $schedules
     *  @return array<string, array{interval: int, display: string}>
     */
    public static function add_cron_intervals(array $schedules): array
    {
        if (!isset($schedules['every_4_hours'])) {
            $schedules['every_4_hours'] = [
                'interval' => 4 * HOUR_IN_SECONDS,
                'display'  => 'Every 4 Hours',
            ];
        }
        return $schedules;
    }

    /**
     * Schedule recurring cron jobs.
     */
    public static function schedule_crons(): void
    {
        $jobs = [
            'bcc_refresh_validators'  => 'hourly',
            'bcc_refresh_collections' => 'every_4_hours',
            'bcc_index_validators'    => 'every_4_hours',
        ];

        foreach ($jobs as $hook => $interval) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time(), $interval, $hook);
            }
        }
    }

    /**
     * Clear all cron jobs on deactivation.
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('bcc_refresh_validators');
        wp_clear_scheduled_hook('bcc_refresh_collections');
        wp_clear_scheduled_hook('bcc_index_validators');
    }

    // ── Locking ──────────────────────────────────────────────────────────────

    private const LOCK_GROUP = 'bcc_cron';

    /**
     * Acquire a MySQL advisory lock for a cron job.
     * Advisory locks are cross-request and don't require persistent object cache.
     */
    /**
     * One run's TOTAL scan budget for validator placeholder minting,
     * shared across every healthy chain.
     *
     * ⚠ ValidatorPageMinter::MAX_PER_INVOCATION is clamped PER CALL, so
     * dispatching per chain without a shared budget would permit 500 x N
     * pages in one run. This is the run-wide ceiling.
     */
    private const MINT_SCAN_BUDGET = 500;

    /**
     * Dead-validator marking is OFF unless this option is explicitly on.
     *
     * ⚠ A SECOND, INDEPENDENT GATE. $hasPartialFetch already defers dead
     * marking on a degraded run, but that gate was also the only thing
     * holding back a GLOBAL UPDATE that would flip 51 production rows the
     * moment detection was corrected. Fixing a measurement must not be the
     * trigger for a destructive write, so the two are now separable: this
     * switch stays off until someone turns it on deliberately.
     *
     * ⚠ Closed truthy allowlist, NOT a (bool) cast - the house pattern from
     * AnnouncementFeature. Casting the strings off and false both yield TRUE,
     * so a cast would arm a destructive sweep on a value meaning the opposite.
     */
    private const DEAD_MARKING_OPTION = 'bcc_onchain_dead_marking_enabled';

    /** @var list<bool|int|string> */
    private const DEAD_MARKING_TRUTHY = [true, 1, '1'];

    /**
     * How long a clean-fetch baseline authorizes MINTING.
     *
     * ⚠⚠ THIS BOUND DOES NOT TOUCH DETECTION, and an earlier
     * version of it did. Gating the relative test on freshness read as the
     * conservative direction and was the exact opposite: see baselineTotal().
     * Past this age a chain still indexes and is still measured against its
     * last clean total; it simply may not create pages until a fresh clean
     * fetch re-establishes the baseline.
     * ⚠ A LITERAL, not 7 * DAY_IN_SECONDS. A class-constant initialiser
     * is evaluated on first ACCESS, and an unqualified constant resolves in
     * this NAMESPACE before falling back to global - so the WP constant form
     * loads fine and then fatals the moment detection reads it, anywhere WP
     * is not fully booted. 604800 = 7 days.
     */
    private const BASELINE_MAX_AGE = 604800;

    /**
     * Is this fetch partial? The whole detection rule, in one testable place.
     *
     * Rule 1, the relative test: a drop past 30% from the last CLEAN total.
     *
     * ⛔⛔⛔ AGE IS NOT A PARAMETER, AND MUST NEVER BECOME ONE. An
     * earlier version gated this on baseline freshness. That reads as the
     * conservative direction and is the opposite of it: for most chain shapes
     * this is the ONLY applicable rule - floor A needs more than 200 stored
     * rows AND fewer than 50 returned, floor B needs a first index - so
     * switching it off means "clean". A chain truncated for seven days was
     * then declared healthy and its truncated count written back as the new
     * baseline: truncation normalising itself on a timer. Age governs MINTING
     * only; see baselineAge().
     *
     * ⚠ A MISSING baseline must not set partial by itself. The baseline
     * is only written on a clean run, so a rule that read no-baseline as
     * partial could never let one be written - a permanent deadlock, and the
     * exact shape of the bug being fixed. With no baseline the relative test
     * is simply not applied and the floors decide alone.
     *
     * ⚠ INTEGER ARITHMETIC, not (int) ($baseline * 0.7). 180 * 0.7 is
     * 125.99999999999998 in IEEE754, so the cast truncated to 125 and the
     * cutoff sat one BELOW the intended 70% at some baselines. Cross-
     * multiplying is exact at every value.
     *
     * Rules 2 and 3, the absolute floors: always applied, need no baseline,
     * and they are what catch a catastrophic index and the slow erosion the
     * run-to-run comparison cannot see.
     */
    private static function isPartialFetch(
        int $returnedCount,
        int $knownCount,
        int $baseline
    ): bool {
        $relativeDrop = $baseline > 0
            && ($returnedCount * 10) < ($baseline * 7);

        $absoluteFloor = ($knownCount > 200 && $returnedCount < 50)
            || ($knownCount === 0 && $returnedCount > 0 && $returnedCount < 30);

        return $relativeDrop || $absoluteFloor;
    }

    /**
     * The comparable total from a chain's prior stats entry, and NOTHING
     * about its age.
     *
     * ⛔⛔⛔ DETECTION USES THIS WHATEVER ITS AGE. An earlier
     * version gated the relative test on freshness. That reads as the
     * conservative direction and is the opposite of it: for most chain shapes
     * the relative test is the ONLY applicable rule, because floor A needs
     * more than 200 stored rows AND fewer than 50 returned, and floor B needs
     * a first index. Ignoring the relative test therefore means "clean" - so a
     * chain truncated for a week was declared healthy and its truncated count
     * was written back as the new baseline. Age may withhold MINTING; it may
     * never relax DETECTION.
     *
     * @param array<string, mixed> $prevEntry
     */
    private static function baselineTotal(array $prevEntry): int
    {
        $raw = $prevEntry['last_good_total'] ?? null;
        if (!is_int($raw) && !is_string($raw)) {
            return 0;
        }

        $total = (int) $raw;

        return $total > 0 ? $total : 0;
    }

    /**
     * Age of the baseline in seconds, or NULL when it cannot be trusted.
     *
     * ⚠⚠⚠ strtotime() FAILS OPEN ON A BARE TIMEZONE. Measured on
     * PHP 8.2.30: strtotime(' UTC') returns NOW. The previous spelling was
     * strtotime((string) $raw . ' UTC'), so an empty string - or false, which
     * (string) renders empty - produced a baseline that was PERMANENTLY fresh
     * on every run, bypassing the staleness refusal completely.
     *
     * So parse strictly against the one format current_time('mysql', true)
     * emits, require an exact round-trip (createFromFormat tolerates trailing
     * junk and rolls impossible dates over), and refuse a FUTURE stamp - a
     * negative age satisfies "<= BASELINE_MAX_AGE" and would read as fresh.
     *
     * @param array<string, mixed> $prevEntry
     */
    private static function baselineAge(array $prevEntry, int $now): ?int
    {
        $raw = $prevEntry['last_good_at'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $at = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $raw,
            new \DateTimeZone('UTC')
        );
        if ($at === false || $at->format('Y-m-d H:i:s') !== $raw) {
            return null;
        }

        $age = $now - $at->getTimestamp();

        return $age >= 0 ? $age : null;
    }

    private static function deadMarkingEnabled(): bool
    {
        $raw = get_option(self::DEAD_MARKING_OPTION, false);
        foreach (self::DEAD_MARKING_TRUTHY as $on) {
            if ($raw === $on) {
                return true;
            }
        }

        return false;
    }

    private static function acquireLock(string $job): bool
    {
        $acquired = \BCC\Core\DB\AdvisoryLock::acquire(self::LOCK_GROUP . ':' . $job, 0);

        if (!$acquired) {
            \BCC\Core\Log\Logger::info('[Onchain] Skipping ' . $job . ' — previous run still locked.');
            return false;
        }

        return true;
    }

    private static function releaseLock(string $job): void
    {
        \BCC\Core\DB\AdvisoryLock::release(self::LOCK_GROUP . ':' . $job);
    }

    /**
     * Compose one chain's stats entry, baseline persistence included.
     *
     * ⛔⛔ EXTRACTED SO THE WRITE PATH CAN BE EXECUTED, NOT ONLY READ.
     * An independent review found that carry-forward and clean-only advance -
     * the only two statements in this change that mutate persistent state -
     * were pinned by STRING PRESENCE alone, and named two mutants that pass
     * the whole suite: emptying the carry-forward bodies, and moving them
     * BELOW the advance. The first restores the truncation ratchet this
     * change exists to remove; the second freezes the baseline at its
     * first-ever value, so every chain's minting is withheld once that stamp
     * ages past BASELINE_MAX_AGE. Both are now killed behaviourally by
     * PartialFetchBaselineTest's across-runs cases, which call THIS method.
     *
     * ⚠⚠ THE ORDER OF THE TWO BLOCKS IS LOAD-BEARING, not stylistic.
     * Carry forward FIRST, so a run that came back partial keeps the last
     * clean reference; advance ONLY on a clean fetch, so the newer value
     * wins. Reversed, the carried value overwrites the fresh one and the
     * baseline never moves again.
     *
     * ⚠ Nothing here is new behaviour. This is the same sequence that ran
     * inline in index_validators(), moved verbatim so a test can drive it.
     *
     * @param  array{total: int, new: int, updated: int, unchanged: int, refreshed: int} $stats
     * @param  array<string, mixed> $prevEntry this chain's previous entry, or [].
     * @return array<string, mixed>
     */
    private static function composeStatsEntry(
        array $stats,
        array $prevEntry,
        string $chainName,
        int $returnedCount,
        bool $isPartialFetch
    ): array {
        $entry = array_merge($stats, [
            'chain'     => $chainName,
            'timestamp' => current_time('mysql', true),
            'partial'   => $isPartialFetch,
        ]);

        // ⚠ CARRY THE BASELINE FORWARD EXPLICITLY. array_merge above is
        // built from $stats, which has no last_good_* keys, so without
        // this the baseline would be DROPPED on every run and the
        // relative test would never have anything to compare against.
        if (isset($prevEntry['last_good_total'])) {
            $entry['last_good_total'] = (int) $prevEntry['last_good_total'];
        }
        if (isset($prevEntry['last_good_at'])) {
            $entry['last_good_at'] = (string) $prevEntry['last_good_at'];
        }

        // ⛔ THE BASELINE ADVANCES ONLY ON A CLEAN FETCH. This is the
        // whole point: `total` above is written on every non-empty
        // fetch including a partial one, so it cannot serve as the
        // reference. last_good_total is the count from a fetch that
        // passed detection, which is what truncation must be measured
        // against.
        if (!$isPartialFetch) {
            $entry['last_good_total'] = $returnedCount;
            $entry['last_good_at']    = current_time('mysql', true);
        }

        return $entry;
    }

    // ── Validator Indexing (bulk — all validators per chain) ────────────────

    /**
     * Fetch and store ALL active validators for every Cosmos chain.
     *
     * This is the discovery/seeding path — populates the validators table
     * with the full active set so the discovery UI and claim system have
     * data to display. Runs every 4 hours.
     *
     * The per-validator refresh cron (refresh_validators) handles expensive
     * enrichment (uptime, governance, delegations) on expired rows.
     */
    public static function index_validators(): void
    {
        if (!self::acquireLock('index_validators')) {
            return;
        }

        try {
            // Index all chain types that support validators.
            $chains = array_merge(
                ChainRepository::getActive('cosmos'),
                ChainRepository::getActive('thorchain'),
                ChainRepository::getActive('solana'),
                ChainRepository::getActive('polkadot'),
                ChainRepository::getActive('near')
            );

            $hasPartialFetch = false;

            // ⚠ PER-CHAIN, DELIBERATELY SEPARATE FROM $hasPartialFetch.
            // $hasPartialFetch is run-wide and stays that way, because
            // markDeadValidators() is a GLOBAL UPDATE with no chain_id
            // predicate — one truncated chain can make another chain's live
            // validators look absent, so that gate must see the whole run.
            // Page creation is the opposite shape: it acts on PRESENCE, so a
            // truncated fetch elsewhere can only mint a page LATER, never
            // wrongly. Hence a per-chain list for minting only.
            $healthyChainFetches = [];

            // ⚠ ONE SNAPSHOT, TAKEN BEFORE ANY CHAIN IN THIS RUN IS WRITTEN.
            // The baseline each chain is judged against must be the value from
            // a PREVIOUS run. Re-reading the option inside the loop would let a
            // chain compare itself against an entry this same run had already
            // rewritten.
            $priorStats = get_option('bcc_onchain_indexer_stats', []);
            if (!is_array($priorStats)) {
                $priorStats = [];
            }

            foreach ($chains as $chain) {
                $chainId = (int) $chain->id;

                // Skip chains whose circuit breaker is open (consistently failing).
                //
                // ⚠ NON-MUTATING. The driver and capability gates below can
                // `continue` without contacting anything, so this must not
                // consume the half-open recovery probe — see issue #264.
                if (OnchainCircuitBreaker::isResting($chainId)) {
                    \BCC\Core\Log\Logger::info('[Onchain] Skipping index for ' . $chain->name . ' — circuit breaker open');
                    continue;
                }

                // ⚠ ONE RECEIPT PER CHAIN, CONSTRUCTED INSIDE THE LOOP AND
                // ABOVE THE try. Inside the loop so the next chain cannot
                // inherit this one's outcome; above the try so the catch
                // below can always read it, including when the throw happened
                // before the fetch.
                $indexOutcome = new ProviderOutcomeReceipt();

                try {
                    if (!FetcherFactory::has_driver($chain->chain_type)) {
                        continue;
                    }

                    $fetcher = FetcherFactory::make_for_chain($chain);

                    if (!$fetcher->supports_feature('validator')) {
                        continue;
                    }

                    $validators = $fetcher->fetch_all_validators($indexOutcome);

                    if (!empty($validators)) {
                        $returnedCount = count($validators);

                        // ⚠⚠ THE RELATIVE TEST NO LONGER USES THE STORED ROW COUNT.
                        //
                        // It used COUNT(*) over every row ever stored for the chain.
                        // A validator that LEAVES the bonded set keeps its row and
                        // keeps status = active, so that count only grows while the
                        // fetch stays flat - and the chain reports partial for ever.
                        // Measured on production 2026-11-02: cosmos stored 317
                        // active and the chain returned 180; osmosis 93 against 30.
                        // Five chains were permanently partial, and 52 Skipped
                        // markDeadValidators log lines record the deadlock - the
                        // sweep that would have reconciled those rows was itself
                        // blocked by them.
                        //
                        // ⛔ AND indexer_stats[slug][total] CANNOT BE THE BASELINE.
                        // It is written below on EVERY non-empty fetch, partial or
                        // not, so comparing against it would let one truncated run
                        // become the new normal - a ratchet in which truncation
                        // normalises itself. Cosmos shows it: partial=true with
                        // total=180, which compared against itself reads healthy.
                        //
                        // So the relative test compares against last_good_total,
                        // written below ONLY on a clean fetch.
                        $chainCounts = ValidatorRepository::getCountsByChain();
                        $knownCount  = isset($chainCounts[$chainId]) ? (int) $chainCounts[$chainId]->cnt : 0;

                        $prevEntry   = isset($priorStats[$chain->slug]) && is_array($priorStats[$chain->slug])
                            ? $priorStats[$chain->slug]
                            : [];
                        $baseline      = self::baselineTotal($prevEntry);
                        $baselineAge   = self::baselineAge($prevEntry, time());
                        $baselineFresh = $baselineAge !== null
                            && $baselineAge <= self::BASELINE_MAX_AGE;

                        // ⚠⚠ TWO DIFFERENT QUESTIONS, HELD APART ON PURPOSE.
                        // Can this baseline DETECT a drop is answered by its
                        // EXISTENCE alone. Can it AUTHORIZE new pages is answered by
                        // its existence AND its age. Conflating them was the defect:
                        // one flag did both jobs, so an aged baseline silently
                        // stopped detecting.
                        $mintBaselineVerified = $baseline > 0 && $baselineFresh;

                        // ⚠ A MISSING baseline must not set partial BY ITSELF.
                        // The baseline is only written on a clean run, so a rule that
                        // read no-baseline as partial could never let one be written -
                        // a permanent deadlock, and the exact shape of the bug being
                        // fixed. With no baseline the relative test is simply NOT
                        // APPLIED and the chain must clear the absolute floors alone.
                        //
                        // ⛔⛔ AGE IS NOT CONSULTED HERE, AND MUST NOT BE. An
                        // earlier version had $baselineUsable on this very line, and
                        // it re-created the ratchet this change exists to remove.
                        // Worked through with cosmos real numbers: baseline 318, the
                        // chain truncates to 180, correctly flagged partial, and
                        // last_good_at is never refreshed because the write below is
                        // gated on a clean verdict. Seven days later the age bound
                        // expires, the relative test switches OFF, and no floor
                        // applies - floor A needs fewer than 50 returned, floor B
                        // needs a first index - so the chain is declared CLEAN and 180
                        // is written back as the new baseline. Detection is anchored
                        // to the truncated value for good, the partial flag clears,
                        // and the evidence is destroyed. A sustained truncation was
                        // forgiven on a timer.
                        //
                        // A week-old baseline is less PRECISE; it is no less
                        // indicative of an 80% drop. So age withholds MINTING and
                        // never forgives a drop. ⚠ The cost is real and accepted:
                        // a genuine SUDDEN decline past the threshold keeps a chain
                        // flagged until an operator clears its last_good_total. That
                        // is the visible, non-destructive direction - minting pauses,
                        // nothing is written - and is preferred to silent self-
                        // forgiveness. A GRADUAL genuine decline needs no
                        // intervention: each step under 30% passes, so the baseline
                        // walks down with the chain.
                        // ⚠ The predicate lives in isPartialFetch() so the suite can
                        // EXECUTE it. It used to be inline here and the tests drove a
                        // local copy, which meant a mutation to this logic could not
                        // fail any behavioural test - only a string match on the
                        // source. That is how the age gate went unnoticed.
                        $isPartialFetch = self::isPartialFetch(
                            $returnedCount,
                            $knownCount,
                            $baseline
                        );

                        if ($isPartialFetch) {
                            // ⚠ REPORT THE NUMBER THE RULE ACTUALLY COMPARED.
                            // This logged $knownCount as "expected ~N", which is no
                            // longer the comparison basis - the relative test compares
                            // against the BASELINE. A chain returning 100 with 300
                            // stored and a baseline of 500 was reported as "expected
                            // ~300" while the rule had compared against 500. Clearing
                            // last_good_total is the operator's only remedy for a
                            // sustained flag, so the log has to name it. Both numbers
                            // appear: the baseline drives the relative test, the
                            // stored count drives floor A.
                            //
                            // ⚠⚠ AND THE SKIP IS RUN-WIDE, NOT PER-CHAIN.
                            // $hasPartialFetch is a single run-wide flag and
                            // markDeadValidators() has no chain_id, so ONE partial
                            // chain withholds the sweep for EVERY chain. The old
                            // wording sent an operator looking for a per-chain skip
                            // that does not exist.
                            \BCC\Core\Log\Logger::warning(sprintf(
                                '[Onchain] Partial fetch detected for %s: got %d against baseline %s (stored %d). Dead-validator marking is skipped for the WHOLE RUN.',
                                $chain->name,
                                $returnedCount,
                                $baseline > 0 ? (string) $baseline : 'absent',
                                $knownCount
                            ));
                            $hasPartialFetch = true;
                        }

                        $stats = ValidatorRepository::bulkUpsert($validators, 4 * HOUR_IN_SECONDS);

                        // Persist per-chain stats for the admin dashboard.
                        $allStats = get_option('bcc_onchain_indexer_stats', []);
                        if (!is_array($allStats)) {
                            $allStats = [];
                        }
                        // ⚠ Baseline persistence lives in composeStatsEntry() so it can
                        // be EXECUTED by a test rather than only matched as text.
                        $entry = self::composeStatsEntry(
                            $stats,
                            $prevEntry,
                            $chain->name,
                            $returnedCount,
                            $isPartialFetch
                        );

                        $allStats[$chain->slug] = $entry;
                        update_option('bcc_onchain_indexer_stats', $allStats, false);

                        \BCC\Core\Log\Logger::info(sprintf(
                            '[Onchain] Indexed %s: %d total, %d new, %d updated, %d unchanged, %d refreshed%s',
                            $chain->name, $stats['total'], $stats['new'], $stats['updated'],
                            $stats['unchanged'], $stats['refreshed'],
                            $isPartialFetch ? ' (PARTIAL)' : ''
                        ));

                        if (!$isPartialFetch) {
                            OnchainCircuitBreaker::recordSuccess($chainId);

                            // ⚠⚠ PAGE CREATION ALSO REQUIRES A VERIFIED BASELINE.
                            //
                            // A clean verdict is NOT on its own evidence that this
                            // fetch was complete. Where the baseline is missing or
                            // stale the relative test did not run, and the absolute
                            // floors are narrow and CONDITIONAL - floor A only applies
                            // when more than 200 rows are stored, floor B only on a
                            // first index. Measured 2026-11-02: jackal returned 24 of
                            // 53 stored, juno 25 of 44, osmosis 30 of 96, and NEITHER
                            // FLOOR APPLIES TO ANY OF THEM. They are not verified
                            // healthy; they are simply uncovered by any rule.
                            //
                            // The floors cannot just be tightened: kujira legitimately
                            // returns 8 validators and dungeon 19, so an unconditional
                            // floor at 30 or 50 would permanently flag real chains.
                            //
                            // So minting requires the count to be REPEATABLE: a
                            // baseline from an earlier fetch that itself passed
                            // detection, and a current fetch that agrees with it.
                            //
                            // ⚠⚠ REPEATABLE IS NOT COMPLETE, and the difference
                            // matters. Two fetches that agree are two observations of
                            // the SAME thing, which may be the same truncation - a
                            // pagination page-size cap or a fixed first page satisfies
                            // the comparison trivially, and nothing here can tell that
                            // apart from a real count. What the baseline does buy is
                            // narrower and still worth having: a count that has never
                            // been corroborated by a second fetch cannot create pages,
                            // and detection is anchored to a count that once passed
                            // rather than to a stored total that only grows. A
                            // truncated first fetch can still become a baseline; it
                            // just cannot mint pages on the strength of being its own
                            // reference.
                            //
                            // ⛔ Indexing is deliberately NOT withheld: the baseline is
                            // only written on a clean run, so blocking the index would
                            // stop any baseline ever forming - the deadlock this whole
                            // change exists to remove.
                            if (!$mintBaselineVerified) {
                                \BCC\Core\Log\Logger::info(sprintf(
                                    '[Onchain] %s indexed and clean, but page creation is withheld: no verified baseline yet (got %d, baseline %s). A later run will mint once a baseline is established.',
                                    $chain->name,
                                    $returnedCount,
                                    $baseline <= 0
                                        ? 'absent'
                                        : ($baselineAge === null ? 'unreadable' : 'stale')
                                ));
                            }

                            // ⚠ THE ONLY PLACE A CHAIN IS DECLARED HEALTHY, and it is
                            // the site that already proves it this run. Anything that
                            // `continue`s earlier (breaker resting, no driver, no
                            // validator feature), returned empty, or threw never
                            // reaches here — so it is absent from the list and FAILS
                            // CLOSED by construction rather than by a check.
                            //
                            // ⛔ Never source this from bcc_onchain_indexer_stats:
                            // that option PERSISTS ACROSS RUNS and is months stale for
                            // several chains, so it would report a chain last indexed
                            // in June as healthy.
                            // ⚠ AN EXACT ALLOWLIST, NOT JUST THE CHAIN ID. A healthy
                            // chain proves the FETCH was good; it does not prove any
                            // particular stored row is still in the set. Eligibility
                            // reads `wallet_link_id IS NULL` + no page meta and never
                            // consults currency, and `status` cannot stand in for it:
                            // measured 2026-11-02, 149 of 693 staging candidates were
                            // absent from their chain's latest complete fetch and all
                            // 149 still read 'active', 56 unseen for over 90 days.
                            //
                            // `fetched_at` cannot answer it either — bulkUpsert only
                            // refreshes it when a row is new, changed, or already >6h
                            // stale, so a row present in this fetch may carry a stamp
                            // 6h old and be indistinguishable from one that was absent.
                            //
                            // So the allowlist is built from the addresses THIS fetch
                            // actually returned. An empty list means mint none.
                            $fetchedAddresses = [];
                            foreach ($validators as $fetched) {
                                $address = is_array($fetched)
                                    ? (string) ($fetched['operator_address'] ?? '')
                                    : (string) ($fetched->operator_address ?? '');
                                if ($address !== '') {
                                    $fetchedAddresses[] = $address;
                                }
                            }

                            if ($mintBaselineVerified) {
                                $healthyChainFetches[$chainId] = ValidatorRepository::idsForOperatorAddresses(
                                    $chainId,
                                    $fetchedAddresses
                                );
                            }
                        }
                    } else {
                        // Empty result from an active chain is suspicious
                        $hasPartialFetch = true;

                        // ⚠ EMPTY IS TWO DIFFERENT FACTS, AND ONLY ONE OF THEM
                        // IS THIS VERDICT'S TO CHARGE. If the index came back
                        // empty because the provider failed, ApiRetry already
                        // charged that request once and this would be the same
                        // failure counted twice. If the provider ANSWERED and
                        // the answer was an empty validator set — a 200 with no
                        // rows, a cached-empty set, a driver that never called
                        // out — nothing was charged, the answer is unusable, and
                        // this verdict is correctly the operation's one charge.
                        if ($indexOutcome->domainMayCharge()) {
                            OnchainCircuitBreaker::recordFailure($chainId);
                        }
                        \BCC\Core\Log\Logger::warning('[Onchain] Validator index returned empty for ' . $chain->name);
                    }
                } catch (\Exception $e) {
                    $hasPartialFetch = true;

                    // ⚠ THIS CATCH SPANS MORE THAN ONE PROVIDER REQUEST — the
                    // whole index for this chain, plus the bulk upsert and the
                    // option writes after it. Most throws reaching here are
                    // therefore NOT provider faults at all, and the ones that
                    // are have already been charged once by ApiRetry. The
                    // receipt keeps this verdict from becoming that failure's
                    // second charge; a throw after a healthy request, or before
                    // any request, still charges exactly once.
                    if ($indexOutcome->domainMayCharge()) {
                        OnchainCircuitBreaker::recordFailure($chainId);
                    }
                    \BCC\Core\Log\Logger::error('[Onchain] Validator index failed for ' . $chain->name . ': ' . $e->getMessage());
                }
            }

            // After indexing, clean up validators that the indexer hasn't seen
            // in 30+ days and have exhausted retry attempts — they're gone.
            // Skip if ANY chain had a partial/failed fetch to prevent marking
            // validators dead due to transient RPC issues.
            // ⚠ TWO GATES, AND BOTH MUST PASS. The run-wide partial flag is
            // unchanged. The switch is new and defaults OFF, so correcting
            // partial-fetch detection cannot by itself start marking validators
            // inactive: before this, a chain that stopped reporting partial would
            // immediately expose a GLOBAL UPDATE with no chain_id predicate, and
            // 51 production rows were in range.
            //
            // ℹ Turning the switch on is a separate, deliberate decision and
            // needs its own review: the sweep requires enrichment_attempts >= 10,
            // so it does NOT reconcile validators that simply left the bonded set
            // (those sit at 0 attempts). It is not the tool for that problem.
            if (!$hasPartialFetch && self::deadMarkingEnabled()) {
                EnrichmentScheduler::markDeadValidators();
            } elseif ($hasPartialFetch) {
                \BCC\Core\Log\Logger::info('[Onchain] Skipped markDeadValidators — partial fetch detected in this run.');
            } else {
                \BCC\Core\Log\Logger::info('[Onchain] Skipped markDeadValidators — run was clean but the dead-marking switch is off.');
            }

            // Mint placeholder peepso-page posts for any newly-indexed
            // validators that don't have one yet, so they surface in
            // /directory?kind=validator with the WANTED claim CTA.
            // Idempotent — re-running is a no-op once the placeholders exist.
            //
            // ⚠ SCOPED TO CHAINS PROVEN HEALTHY IN **THIS** RUN, not gated on
            // $hasPartialFetch. Previously one unhealthy chain blocked page
            // creation for all fourteen, which is why 228 validators across 9
            // chains had no page while only 5 chains were actually degraded.
            // A truncated fetch cannot invent a validator, so minting for a
            // validator that demonstrably EXISTS is safe regardless of another
            // chain's health; the worst case is that a page appears a run late.
            //
            // ⛔ markDeadValidators() above is UNCHANGED and still gated on the
            // run-wide $hasPartialFetch. It acts on ABSENCE and its UPDATE has
            // no chain_id predicate, so it must keep seeing the whole run.
            if ($healthyChainFetches !== []) {
                try {
                    $mintResult = self::mintForHealthyChains($healthyChainFetches, self::MINT_SCAN_BUDGET);
                    if ($mintResult['minted'] > 0 || $mintResult['errors'] > 0) {
                        \BCC\Core\Log\Logger::info(sprintf(
                            '[Onchain] Validator placeholder pages — chains=%d, scanned=%d, minted=%d, errors=%d',
                            $mintResult['chains'],
                            $mintResult['scanned'],
                            $mintResult['minted'],
                            $mintResult['errors']
                        ));
                    }
                } catch (\Throwable $e) {
                    \BCC\Core\Log\Logger::error('[Onchain] ValidatorPageMinter::mintMissing failed: ' . $e->getMessage());
                }
            }
        } finally {
            self::releaseLock('index_validators');
        }
    }

    // ── Validator Refresh (scheduler-driven) ─────────────────────────────────

    /**
     * Enrich validators via the EnrichmentScheduler.
     *
     * The scheduler handles: priority ordering, API budget control,
     * retry/backoff, staggered scheduling, and Redis-based overlap prevention.
     * This method is just the cron entry point.
     */
    /**
     * Mint placeholder pages for the given chains under ONE shared budget.
     *
     * ⚠ The budget is decremented by SCANNED, not minted. `scanned` is what
     * bounds the work: a chain whose rows all already have pages reports
     * minted=0 while still scanning, so decrementing by `minted` would let it
     * burn unbounded scans and starve the chains after it.
     *
     * ⚠ Each chain is passed its OWN id. Never null — null means every chain,
     * which is exactly the all-or-nothing behaviour this replaces.
     *
     * $minter exists so the budget arithmetic is testable without a database;
     * production always uses the default.
     *
     * ⚠ Keyed by chain id, VALUE is that chain's exact allowlist of validator
     * ids from this run's fetch. An empty allowlist is forwarded as `[]`, which
     * mintMissing() treats as MINT NONE — never as 'no filter'.
     *
     * @param array<int, list<int>> $chainFetches
     * @param (callable(int, int, list<int>): array{scanned:int,minted:int,skipped:int,errors:int})|null $minter
     * @return array{chains:int,scanned:int,minted:int,skipped:int,errors:int}
     */
    private static function mintForHealthyChains(
        array $chainFetches,
        int $budget,
        ?callable $minter = null
    ): array {
        // ⚠ Re-keyed to a true list of ints before it crosses the boundary.
        // mintMissing() distinguishes [] from null, so the shape matters: a
        // gappy or string-keyed array would still be non-empty and would skip
        // the fail-closed branch while composing a malformed IN list.
        $minter ??= static function (int $chainId, int $max, array $allowlist): array {
            /** @var list<int> $ids */
            $ids = array_values(array_map(static fn ($id): int => (int) $id, $allowlist));

            return ValidatorPageMinter::mintMissing($chainId, false, $max, $ids);
        };

        $totals = ['chains' => 0, 'scanned' => 0, 'minted' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($chainFetches as $chainId => $allowlist) {
            if ($budget <= 0) {
                break;
            }

            $result = $minter((int) $chainId, $budget, array_values((array) $allowlist));

            $totals['chains']++;
            $totals['scanned'] += (int) ($result['scanned'] ?? 0);
            $totals['minted']  += (int) ($result['minted'] ?? 0);
            $totals['skipped'] += (int) ($result['skipped'] ?? 0);
            $totals['errors']  += (int) ($result['errors'] ?? 0);

            // ⚠ A minter that reports scanning nothing must still not loop
            // forever across chains; the foreach bounds that. But guard the
            // budget against a negative drift so `<= 0` stays meaningful.
            $budget -= max(0, (int) ($result['scanned'] ?? 0));
        }

        return $totals;
    }

    public static function refresh_validators(): void
    {
        $stats = EnrichmentScheduler::run();

        // Persist enrichment stats for the admin dashboard.
        update_option('bcc_onchain_enrichment_stats', array_merge($stats, [
            'timestamp' => current_time('mysql', true),
        ]), false);
    }

    // ── Collection Refresh (WALLET-LINKED ONLY) ─────────────────────────────

    /**
     * Refresh the collections a VERIFIED WALLET holds.
     *
     * This is wallet-driven, not chain-wide: every row it touches is
     * anchored to a `wallet_link_id`, so the work is bounded by how many
     * wallets members have linked rather than by how large a chain is.
     * {@see CollectionRepository::getExpiredWalletLinked()} enforces that at the query,
     * so a chain-level row cannot reach this loop at all.
     *
     * The chain-level branch that used to live here called
     * {@see CollectionRepository::backoffRow()} on rows with no wallet,
     * purely to push them out of the expired queue until the next
     * `bcc_index_collections` sweep re-fetched them. That sweep is retired,
     * so there is nothing to defer to and nothing to defer — the rows are
     * excluded upstream instead of being re-dated on every cycle.
     */
    public static function refresh_collections(): void
    {
        if (!self::acquireLock('refresh_collections')) {
            return;
        }

        try {
            $expired = CollectionRepository::getExpiredWalletLinked(self::BATCH_SIZE);

            if (empty($expired)) {
                return;
            }

            foreach ($expired as $row) {
                try {
                    $chain = ChainRepository::getById((int) $row->chain_id);
                    if (!$chain || !FetcherFactory::has_driver($chain->chain_type)) {
                        continue;
                    }

                    $fetcher = FetcherFactory::make_for_chain($chain);

                    if (!$fetcher->supports_feature('collection')) {
                        // The chain has no wallet-discovery driver — since
                        // PR 7.12 that includes every Cosmos chain, whose
                        // only driver was the Stargaze marketplace.
                        //
                        // ⚠ BACK THE ROW OFF, don't just `continue`. The
                        // batch is selected by `expires_at < NOW()` ordered
                        // oldest-first with LIMIT 50 and is chain-agnostic,
                        // so a bare skip leaves these rows permanently at
                        // the head of the queue and they crowd out the EVM
                        // and Solana rows this cron exists to refresh.
                        // Backing off moves the watermark WITHOUT claiming a
                        // refresh happened — `markHoldingsRefreshed()` is
                        // deliberately not called here.
                        CollectionRepository::backoffRow((int) $row->id);
                        continue;
                    }

                    // Resolve the wallet address. getExpiredWalletLinked()
                    // guarantees wallet_link_id is set; a missing wallet row
                    // is a dangling reference, not a chain-level collection.
                    $wallet = WalletRepository::getById((int) $row->wallet_link_id);
                    if (!$wallet) {
                        continue;
                    }

                    $collections = $fetcher->fetch_collections($wallet->wallet_address, (int) $row->chain_id);

                    if (!empty($collections)) {
                        // #212: never discard the result. A failure here is a
                        // genuine write loss, not a transient — it must not
                        // stay invisible the way the old bare INSERT did.
                        $persisted = CollectionPersistBatch::persist(
                            $collections,
                            (int) $row->wallet_link_id,
                            4 * HOUR_IN_SECONDS
                        );
                        if (!CollectionPersistBatch::allPersisted($persisted)) {
                            \BCC\Core\Log\Logger::warning(sprintf(
                                '[Onchain] %d of %d collections failed to persist for wallet_link_id %d on chain %d',
                                $persisted['failed'],
                                $persisted['total'],
                                (int) $row->wallet_link_id,
                                (int) $row->chain_id
                            ));
                        }

                        if ((int) $wallet->post_id > 0) {
                            CollectionService::invalidate((int) $wallet->post_id);
                        }
                    } else {
                        // Empty response after retries — backoff to prevent tight re-fetch
                        // loop on wallets with deleted collections or failing chain APIs.
                        CollectionRepository::backoffRow((int) $row->id);
                    }
                } catch (\Exception $e) {
                    OnchainCircuitBreaker::recordFailure((int) $row->chain_id);
                    \BCC\Core\Log\Logger::error('[Onchain] Collection ' . $row->contract_address . ' refresh failed: ' . $e->getMessage());
                    CollectionRepository::backoffRow((int) $row->id);
                }
            }
        } finally {
            self::releaseLock('refresh_collections');
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

}
