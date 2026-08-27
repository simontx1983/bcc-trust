<?php

/**
 * Stubs for the VC-B3b scanner operations: Pause, Resume, Backfill, Retry.
 *
 * ── LOAD ORDER IS LOad-BEARING ──────────────────────────────────────────
 * Everything here is declared BEFORE the require at the bottom. The
 * chains-cw-discovery stubs carry a narrower CosmwasmDiscoveryWorker (it
 * knows `discoveryOptInState` and counts passes, but cannot see the budget
 * it was handed), and whichever file declares a class first wins the
 * `class_exists` guard in the other. The richer fake has to be this one, so
 * it goes first — the same trap the VC-A stub family fell into once.
 *
 * ── WHAT THE FAKES ARE FOR ──────────────────────────────────────────────
 * Two claims in this batch cannot be checked by looking at output:
 *
 *   1. The handler constructs ONE CosmwasmTickBudget(20, 8) and hands it
 *      over. PR #200 owns the reserve sequence inside the worker, so this
 *      handler must never call reserve() or available() — a second opinion
 *      about the budget at the boundary is how two copies drift apart.
 *
 *   2. A refused operation writes NOTHING. Not a repository row, not a
 *      provider request, not an audit row.
 *
 * So the budget records its constructor arguments and every method call,
 * the worker records the budget it received, and every repository counts
 * its writes.
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support {

    if (!class_exists(CosmwasmTickBudget::class, false)) {
        /**
         * Faked at its production FQN so the handler's `new` lands here.
         *
         * `$reserveCalls` and `$availableCalls` exist to be asserted ZERO:
         * this is the only place that can prove the admin boundary did not
         * reach into the sequence PR #200 owns.
         */
        final class CosmwasmTickBudget
        {
            /** @var list<array{requests: int, seconds: int}> */
            public static array $constructions = [];

            public static int $reserveCalls   = 0;
            public static int $availableCalls = 0;

            public function __construct(public int $requests = 0, public int $seconds = 0)
            {
                self::$constructions[] = ['requests' => $requests, 'seconds' => $seconds];
            }

            public function reserve(int $n = 1): bool
            {
                self::$reserveCalls++;

                return true;
            }

            public function available(): int
            {
                self::$availableCalls++;

                return $this->requests;
            }

            // ── READ-ONLY ACCESSORS, COUNTED SEPARATELY ─────────────────
            //
            // The run report reads how much of the budget was spent and
            // whether the clock or the request ceiling ended the pass.
            // Those are OBSERVATIONS, not participation in the reserve
            // sequence the worker owns, so they get their own counter —
            // `$reserveCalls` and `$availableCalls` must stay assertable
            // as zero, and folding these into them would destroy the one
            // check that proves the boundary kept its hands off.
            public static int $readCalls = 0;

            /** What the fake reports as consumed. Tests set it directly. */
            public static int $spent    = 0;
            public static bool $timedOut = false;

            public function spent(): int
            {
                self::$readCalls++;

                return self::$spent;
            }

            public function remaining(): int
            {
                self::$readCalls++;

                return max(0, $this->requests - self::$spent);
            }

            public function timedOut(): bool
            {
                self::$readCalls++;

                return self::$timedOut;
            }

            public static function reset(): void
            {
                self::$constructions  = [];
                self::$reserveCalls   = 0;
                self::$availableCalls = 0;
                self::$readCalls      = 0;
                self::$spent          = 0;
                self::$timedOut       = false;
            }
        }
    }

    if (!class_exists(CosmwasmDiscoveryGate::class, false)) {
        /** The two environment constants, switchable per test. */
        final class CosmwasmDiscoveryGate
        {
            public const DEFAULT_REQUEST_BUDGET = 50;

            public static bool $discovery = true;
            public static bool $backfill  = true;

            public static function discoveryEnabled(): bool
            {
                return self::$discovery;
            }

            public static function backfillEnabled(): bool
            {
                return self::$backfill;
            }

            public static function reset(): void
            {
                self::$discovery = true;
                self::$backfill  = true;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(ChainCheckpointRepository::class, false)) {
        /**
         * The scanner's durable per-chain progress.
         *
         * `$pauseCalls` / `$resumeCalls` make "at most one write" a count.
         * `$readBackState` forces the read-back to disagree so the
         * unconfirmed branch is reachable without a real race.
         */
        final class ChainCheckpointRepository
        {
            public const CW_STATE_IDLE        = 'idle';
            public const CW_STATE_BACKFILLING = 'backfilling';
            public const CW_STATE_BACKFILLED  = 'backfilled';
            public const CW_STATE_UNSUPPORTED = 'unsupported';
            public const CW_STATE_PAUSED      = 'paused';

            /** @var array<int, object> */
            public static array $rows = [];

            public static int $pauseCalls  = 0;
            public static int $resumeCalls = 0;

            public static bool $pauseResult  = true;
            public static bool $resumeResult = true;

            public static ?\Throwable $pauseThrows = null;

            /** Force the post-write read to report something else. */
            public static ?string $readBackState = null;
            public static bool $readBackNull     = false;

            public static function get(int $chainId): ?object
            {
                if (self::$readBackNull && (self::$pauseCalls > 0 || self::$resumeCalls > 0)) {
                    return null;
                }

                return self::$rows[$chainId] ?? null;
            }

            public static function pauseCwDiscovery(int $chainId): bool
            {
                self::$pauseCalls++;

                if (self::$pauseThrows !== null) {
                    throw self::$pauseThrows;
                }

                if (!self::$pauseResult) {
                    return false;
                }

                if (isset(self::$rows[$chainId])) {
                    self::$rows[$chainId]->cw_discovery_state = self::$readBackState ?? self::CW_STATE_PAUSED;
                }

                return true;
            }

            public static function resumeCwDiscovery(int $chainId): bool
            {
                self::$resumeCalls++;

                if (!self::$resumeResult) {
                    return false;
                }

                if (isset(self::$rows[$chainId])) {
                    self::$rows[$chainId]->cw_discovery_state =
                        self::$readBackState ?? self::cwResumeState(self::$rows[$chainId]);
                }

                return true;
            }

            /**
             * Faithful to production: a completed backfill resumes to
             * `backfilled`, work in flight to `backfilling`, and only an
             * untouched chain to `idle`. Resuming a drained chain to idle
             * would make the worker re-walk its whole code listing, which
             * is why the handler must not reimplement this.
             */
            public static function cwResumeState(object $row): string
            {
                if (!empty($row->cw_backfill_completed_at)) {
                    return self::CW_STATE_BACKFILLED;
                }

                if (!empty($row->cw_code_cursor) || !empty($row->cw_code_watermark)) {
                    return self::CW_STATE_BACKFILLING;
                }

                return self::CW_STATE_IDLE;
            }

            /** @param array<string, mixed> $overrides */
            public static function seed(int $chainId, string $state = self::CW_STATE_IDLE, array $overrides = []): void
            {
                self::$rows[$chainId] = (object) array_merge([
                    'chain_id'                 => $chainId,
                    'cw_discovery_state'       => $state,
                    'cw_code_cursor'           => null,
                    'cw_code_watermark'        => null,
                    'cw_backfill_completed_at' => null,
                    'cw_last_error'            => null,
                ], $overrides);
            }

            public static function reset(): void
            {
                self::$rows          = [];
                self::$pauseCalls    = 0;
                self::$resumeCalls   = 0;
                self::$pauseResult   = true;
                self::$resumeResult  = true;
                self::$pauseThrows   = null;
                self::$readBackState = null;
                self::$readBackNull  = false;
            }
        }
    }

    if (!class_exists(CosmwasmCodeFamilyRepository::class, false)) {
        /** Retry target #1. Records the limit so the 100 bound is checkable. */
        final class CosmwasmCodeFamilyRepository
        {
            /** @var list<array{chain_id: int, limit: int}> */
            public static array $retryCalls = [];

            public static int $retryResult = 0;

            public static function forceRetryUnresolved(int $chainId, int $limit): int
            {
                self::$retryCalls[] = ['chain_id' => $chainId, 'limit' => $limit];

                return self::$retryResult;
            }

            public static function reset(): void
            {
                self::$retryCalls  = [];
                self::$retryResult = 0;
            }
        }
    }

    if (!class_exists(CosmwasmContractRepository::class, false)) {
        /** Retry target #2 — a SEPARATE limit, not a shared budget. */
        final class CosmwasmContractRepository
        {
            /** @var list<array{chain_id: int, limit: int}> */
            public static array $retryCalls = [];

            public static int $retryResult = 0;

            public static function forceRetryUnresolved(int $chainId, int $limit): int
            {
                self::$retryCalls[] = ['chain_id' => $chainId, 'limit' => $limit];

                return self::$retryResult;
            }

            public static function reset(): void
            {
                self::$retryCalls  = [];
                self::$retryResult = 0;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Workers {

    use BCC\Trust\Onchain\Support\CosmwasmTickBudget;

    if (!class_exists(CosmwasmDiscoveryWorker::class, false)) {
        /**
         * Richer than the chains-cw-discovery fake: it keeps the budget it
         * was handed, so "the handler passed the object it built" is an
         * identity check rather than an inference from a call count.
         */
        final class CosmwasmDiscoveryWorker
        {
            // The pass outcomes, mirroring production. The handler maps
            // these straight onto its result codes, so a fake that did not
            // carry them would let the mapping go untested.
            public const PASS_RAN     = 'ran';
            public const PASS_LOCKED  = 'locked';
            public const PASS_SKIPPED = 'skipped';
            public const PASS_FAILED  = 'failed';

            public static int $passes = 0;

            /** @var list<?CosmwasmTickBudget> */
            public static array $budgets = [];

            public static ?\Throwable $throws = null;

            /** What the next pass reports. Default: it ran. */
            public static string $outcome = self::PASS_RAN;

            /** @var list<?object> the report objects the handler passed in */
            public static array $reports = [];

            /**
             * Lets a test act on the report the way a real pass would.
             *
             * Production records a provider abort ON THE REPORT and still
             * returns PASS_RAN — that combination is what makes a slice
             * with budget to spare nevertheless partial. Without a hook the
             * fake could not reproduce it, and the honesty rule that
             * depends on it would go untested.
             *
             * @var null|callable(?object): void
             */
            public static $onRun = null;

            public static function runBackfillForChain(
                int $chainId,
                ?CosmwasmTickBudget $budget = null,
                ?object $report = null
            ): string {
                self::$passes++;
                self::$budgets[] = $budget;
                self::$reports[] = $report;

                if (self::$onRun !== null) {
                    (self::$onRun)($report);
                }

                if (self::$throws !== null) {
                    throw self::$throws;
                }

                return self::$outcome;
            }

            /** Production reads the opt-in with `=== '1'` and nothing else. */
            public static function discoveryOptInState(object $chain): ?bool
            {
                if (!property_exists($chain, 'cosmwasm_nft_discovery_enabled')) {
                    return null;
                }

                $raw = $chain->cosmwasm_nft_discovery_enabled;

                return $raw === null ? null : ((string) $raw === '1');
            }

            public static function reset(): void
            {
                self::$passes  = 0;
                self::$budgets = [];
                self::$throws  = null;
                self::$outcome = self::PASS_RAN;
                self::$reports = [];
                self::$onRun   = null;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {

    /**
     * The chain aggregate the run report brackets its pass with.
     *
     * Production reads this BEFORE and AFTER the slice so the report can
     * show a real delta — and reads the same aggregate the engine table
     * prints, so the two cannot disagree about what a chain holds.
     *
     * `$summaries` is a queue: push a before and an after and the handler
     * consumes them in order. `$calls` exists so a test can prove the
     * handler read it exactly twice and not once per row.
     */
    if (!class_exists(CosmwasmDiscoveryService::class, false)) {
        final class CosmwasmDiscoveryService
        {
            /** @var list<array<string, mixed>> */
            public static array $summaries = [];

            public static int $calls = 0;

            /** @return array<string, mixed> */
            public static function chainSummary(int $chainId): array
            {
                self::$calls++;

                return array_shift(self::$summaries) ?? [
                    'state'           => 'idle',
                    'families_total'  => 0,
                    'contracts_total' => 0,
                ];
            }

            public static function reset(): void
            {
                self::$summaries = [];
                self::$calls     = 0;
            }
        }
    }
}

namespace {

    // The run report is parked in an operator-scoped transient between the
    // POST and the redirect landing, because the PRG allowlist forbids
    // provider data in the query string. An in-memory store is enough to
    // prove the round trip, and `$store` is inspectable so a test can
    // assert what was — and was not — put in it.
    if (!class_exists('BccNftDiscoveryTransientStore', false)) {
        final class BccNftDiscoveryTransientStore
        {
            /** @var array<string, mixed> */
            public static array $store = [];

            public static function reset(): void
            {
                self::$store = [];
            }
        }
    }

    if (!function_exists('set_transient')) {
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            \BccNftDiscoveryTransientStore::$store[$key] = $value;

            return true;
        }
    }

    if (!function_exists('get_transient')) {
        function get_transient(string $key)
        {
            return \BccNftDiscoveryTransientStore::$store[$key] ?? false;
        }
    }

    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            unset(\BccNftDiscoveryTransientStore::$store[$key]);

            return true;
        }
    }

    // LAST. Everything above is declared first so the richer fakes win the
    // class_exists guards in the file this pulls in — which in turn loads
    // the Batch 1 admin-action shims (wp_die, wp_safe_redirect,
    // check_admin_referer, Logger, AuditLogger, BccAdminTestState) last of
    // all, for exactly the same reason.
    require_once __DIR__ . '/chains-cw-discovery-stubs.php';
}
