#!/usr/bin/env bash
#
# PR 7.3 mutation controls — operator-authorized durable scan sessions.
#
# Each control plants a specific defect and REQUIRES the suite to go red. A
# control that "passes" (suite still green with the defect planted) is a
# FAILURE of the control: the test describes the code instead of constraining
# it.
#
# ── ⚠ A MUTATOR THAT CHANGES NOTHING IS *BROKEN*, NEVER "KILLED" ────────
# `mutate.py` requires the needle to be present and UNIQUE and re-reads the
# file to prove the bytes changed. Anything else reports BROKEN here.
#
# ── WHAT THESE AIM AT ───────────────────────────────────────────────────
# The one rule PR 7.3 must not break — automatic scan CREATION is forbidden,
# bounded CONTINUATION of an authorized run is permitted — and every way a
# session could become unbounded, dishonest, or uncancellable.
#
# Usage:
#   BCC_TEST_DB_HOST=127.0.0.1 BCC_TEST_DB_PORT=13474 \
#   BCC_TEST_DB_USER=root BCC_TEST_DB_PASS=root BCC_TEST_DB_NAME=bcc_pr73 \
#   bash scripts/tests/pr73-mutation-controls.sh

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 2

PHP="${PHP_BIN:-php}"
PY="${PYTHON_BIN:-python}"
MUTATE="scripts/tests/mutate.py"

UNIT_FILTER='DiscoveryScanSessionTest|DiscoverySessionExecutorTest|CosmwasmBudgetFairnessTest|CosmwasmOneShotCliTest'
INT_FILTER='DiscoveryUnresolvedFamiliesIntegrationTest|DiscoverySessionLedgerIntegrationTest|DiscoverySessionPanelIntegrationTest|DiscoveryScanProgressIntegrationTest|DiscoveryScanPanelControlIntegrationTest'

SESSION='app/Domain/Onchain/Services/DiscoveryScanSession.php'
EXECUTOR='app/Domain/Onchain/Workers/DiscoveryRunExecutor.php'
REPO='app/Domain/Onchain/Repositories/DiscoveryRunRepository.php'
PROGRESS='app/Domain/Onchain/Services/DiscoveryScanProgress.php'
FAMILIES='app/Domain/Onchain/Repositories/CosmwasmCodeFamilyRepository.php'
WORKER='app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php'
PANEL='app/Domain/Onchain/Admin/Views/DiscoveryScanPanel.php'
MAINT='app/Domain/Onchain/Workers/DiscoveryRunMaintenance.php'

FILES=("$SESSION" "$EXECUTOR" "$REPO" "$PROGRESS" "$FAMILIES" "$WORKER" "$PANEL" "$MAINT")

WORK="$(mktemp -d)"
KILLED=0
SURVIVED=0
BROKEN=0
declare -a SURVIVORS=()
declare -a BROKENS=()

backup()  { for f in "${FILES[@]}"; do cp "$f" "$f.mutbak"; done; }
restore() { for f in "${FILES[@]}"; do [ -f "$f.mutbak" ] && mv -f "$f.mutbak" "$f"; done; return 0; }
cleanup() { restore; rm -rf "$WORK"; }
trap cleanup EXIT

run_unit() { "$PHP" -d extension=mysqli -d extension=gmp vendor/bin/phpunit \
                --filter "$UNIT_FILTER" --no-coverage >/dev/null 2>&1; }
run_int()  { "$PHP" -d extension=mysqli -d extension=gmp vendor/bin/phpunit \
                -c phpunit-integration.xml.dist --filter "$INT_FILTER" --no-coverage >/dev/null 2>&1; }

echo "── preflight ──────────────────────────────────────────────────────"
if [ ! -f "$MUTATE" ]; then
  echo "ABORT: $MUTATE is missing; every control would report BROKEN."
  exit 2
fi
echo "  ok   mutator present"

if ! run_unit; then
  echo "ABORT: the unit anchor does not pass on unmutated code."
  exit 2
fi
echo "  ok   unit harness green before mutating"

if ! run_int; then
  echo "ABORT: the integration anchor does not pass on unmutated code."
  echo "       An unreachable database makes every kill a FALSE kill."
  exit 2
fi
echo "  ok   integration harness green before mutating"
echo

control() {
  local label="$1" harness="$2" file="$3" needle="$4" repl="$5"
  backup
  if ! "$PY" "$MUTATE" "$file" "$needle" "$repl" 2>"$WORK/err"; then
    restore
    BROKEN=$((BROKEN + 1))
    BROKENS+=("$label — $(head -1 "$WORK/err")")
    printf '  BROKEN    %s\n' "$label"
    printf '            %s\n' "$(head -1 "$WORK/err")"
    return
  fi
  local unit_red=0 int_red=0
  if [ "$harness" = unit ] || [ "$harness" = both ]; then run_unit || unit_red=1; fi
  if [ "$harness" = int ]  || [ "$harness" = both ]; then run_int  || int_red=1;  fi
  restore
  if [ "$unit_red" -eq 1 ] || [ "$int_red" -eq 1 ]; then
    KILLED=$((KILLED + 1)); printf '  killed    %s\n' "$label"
  else
    SURVIVED=$((SURVIVED + 1)); SURVIVORS+=("$label"); printf '  SURVIVED  %s\n' "$label"
  fi
}

frag() { local p; p="$(mktemp "$WORK/frag.XXXXXX")"; cat > "$p"; printf '%s' "$p"; }

echo "── controls ───────────────────────────────────────────────────────"

# ── ⚠ (1) A CHUNK CREATES A NEW RUN ─────────────────────────────────────
#
# THE ONE THING PR 7.3 MUST NEVER DO. Replacing the same-run release with a
# fresh insert turns bounded continuation into an automatic scanner that
# names an administrator who authorised one run, not twenty-five.
N=$(frag <<'EOF'
        $released = DiscoveryRunRepository::releaseForNextChunk($runId, $token, $counts, $delay);
EOF
)
R=$(frag <<'EOF'
        $released = DiscoveryRunRepository::insertQueued(
            (string) $run->job_kind,
            (string) $run->scan_mode,
            $chainId,
            (int) $run->requested_by
        ) !== null;
EOF
)
control "a chunk creates a NEW run row instead of continuing" both "$EXECUTOR" "$N" "$R"

# ── ⚠ (2) THE SESSION CEILING REMOVED ───────────────────────────────────
N=$(frag <<'EOF'
        if ((int) ($ctx['chunks_used'] ?? 0) >= self::MAX_CHUNKS) {
            return $stop(self::STOP_CHUNK_CEILING);
        }
EOF
)
R=$(frag <<'EOF'
EOF
)
control "the chunk ceiling is removed entirely" both "$SESSION" "$N" "$R"

# The off-by-one that authorises one chunk more than the ceiling says.
N=$(frag <<'EOF'
            'chunks_used'   => max(0, (int) ($fresh->chunks_used ?? 0)) + 1,
EOF
)
R=$(frag <<'EOF'
            'chunks_used'   => max(0, (int) ($fresh->chunks_used ?? 0)),
EOF
)
control "the ceiling ignores the chunk in hand (off by one)" unit "$EXECUTOR" "$N" "$R"

# ── ⚠ (3) THE REQUEST AND AGE CEILINGS ──────────────────────────────────
N=$(frag <<'EOF'
        if ((int) ($ctx['requests_used'] ?? 0) >= self::MAX_REQUESTS) {
            return $stop(self::STOP_REQUEST_CEILING);
        }
EOF
)
R=$(frag <<'EOF'
EOF
)
control "the cumulative request ceiling is removed" unit "$SESSION" "$N" "$R"

N=$(frag <<'EOF'
        if ((int) ($ctx['age_seconds'] ?? 0) >= self::MAX_AGE_SECONDS) {
            return $stop(self::STOP_AGE_CEILING);
        }
EOF
)
R=$(frag <<'EOF'
EOF
)
control "the wall-clock ceiling is removed" unit "$SESSION" "$N" "$R"

# ── ⚠ (4) CANCELLATION BYPASSED ─────────────────────────────────────────
#
# An administrator pressing Stop must end the session. Checking it AFTER the
# ceilings would still work by luck; not checking it at all must not.
N=$(frag <<'EOF'
        if (($ctx['cancelled'] ?? false) === true) {
            return $stop(self::STOP_NOT_READY);
        }
EOF
)
R=$(frag <<'EOF'
EOF
)
control "cancellation no longer stops a session" both "$SESSION" "$N" "$R"

# ── ⚠ (5) READINESS IGNORED BETWEEN CHUNKS ──────────────────────────────
N=$(frag <<'EOF'
        if (($ctx['ready'] ?? false) !== true) {
            return $stop(self::STOP_NOT_READY);
        }
EOF
)
R=$(frag <<'EOF'
EOF
)
control "a chain that lost support keeps getting chunks" unit "$SESSION" "$N" "$R"

# The recheck itself, removed at the executor.
N=$(frag <<'EOF'
        $readiness = DiscoveryReadiness::forExecution($chainId, (string) $run->scan_mode);
EOF
)
R=$(frag <<'EOF'
        $readiness = ['eligible' => true];
EOF
)
control "the between-chunk readiness recheck is skipped" unit "$EXECUTOR" "$N" "$R"

# ── ⚠ (6) DELAYED WORK BECOMES COMPLETION ───────────────────────────────
#
# The busy-loop AND the honesty defect in one line: delayed work reported as
# a finished scan.
N=$(frag <<'EOF'
        return $stop(
            (int) ($ctx['delayed'] ?? 0) > 0 ? self::STOP_DELAYED_WORK : self::STOP_COMPLETED
        );
EOF
)
R=$(frag <<'EOF'
        return $stop(self::STOP_COMPLETED);
EOF
)
control "delayed work is reported as completion" unit "$SESSION" "$N" "$R"

# And the count that keeps them apart.
N=$(frag <<'EOF'
                AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
                AND (classified_at IS NULL OR classifier_version < %d)",
            $chainId,
            CosmwasmClassifier::NOT_CW721,
            CosmwasmClassifier::CONFIRMED,
            CosmwasmClassifier::PROBABLE,
            CosmwasmClassifier::MAX_RETRIES,
            gmdate('Y-m-d H:i:s'),
            $classifierVersion
EOF
)
R=$(frag <<'EOF'
                AND (classified_at IS NULL OR classifier_version < %d)",
            $chainId,
            CosmwasmClassifier::NOT_CW721,
            CosmwasmClassifier::CONFIRMED,
            CosmwasmClassifier::PROBABLE,
            CosmwasmClassifier::MAX_RETRIES,
            $classifierVersion
EOF
)
control "eligible-now stops honouring next_attempt_at (busy loop)" int "$FAMILIES" "$N" "$R"

# ── ⚠ (7) PROVIDER FAILURE TREATED AS ORDINARY ──────────────────────────
N=$(frag <<'EOF'
        if ((int) ($ctx['error_chunks'] ?? 0) >= self::MAX_ERROR_CHUNKS) {
            return $stop(self::STOP_PROVIDER_ERRORS);
        }
EOF
)
R=$(frag <<'EOF'
EOF
)
control "a failing provider no longer stops the session" unit "$SESSION" "$N" "$R"

# ── ⚠ (8) CUMULATIVE COUNTS DROPPED ─────────────────────────────────────
#
# Back to overwriting. A 25-chunk session would then report the last chunk's
# 48 requests as the whole session's cost.
N=$(frag <<'EOF'
                    requests_used = requests_used + %d,
                    pages_fetched = pages_fetched + %d,
                    families_seen = families_seen + %d,
                    contracts_seen = contracts_seen + %d,
                    collections_emitted = collections_emitted + %d,
                    collections_denied = collections_denied + %d,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d AND lease_token = %s AND status = %s",
            DiscoveryRunStatus::QUEUED,
EOF
)
R=$(frag <<'EOF'
                    requests_used = %d,
                    pages_fetched = %d,
                    families_seen = %d,
                    contracts_seen = %d,
                    collections_emitted = %d,
                    collections_denied = %d,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d AND lease_token = %s AND status = %s",
            DiscoveryRunStatus::QUEUED,
EOF
)
control "cumulative counts overwrite instead of accumulating" int "$REPO" "$N" "$R"

# ── ⚠ (9) DUPLICATE DELIVERY PROCESSES A CHUNK TWICE ────────────────────
#
# Action Scheduler is at-least-once. The claim's compare-and-swap is the
# whole defence; widening it lets two deliveries both run the chunk.
N=$(frag <<'EOF'
              WHERE id = %d
                AND status = %s
                AND active_marker IS NOT NULL
EOF
)
R=$(frag <<'EOF'
              WHERE id = %d
                AND status IS NOT NULL
                AND %s IS NOT NULL
                AND active_marker IS NOT NULL
EOF
)
control "the claim stops being a compare-and-swap" int "$REPO" "$N" "$R"

# ── ⚠ (10) THE SESSION LOSES THE ACTIVE SLOT ────────────────────────────
#
# Clearing `active_marker` between chunks would let a SECOND session start
# for the same chain beside the running one — `uq_active` is the only thing
# preventing it.
N=$(frag <<'EOF'
                SET status = %s,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    heartbeat_at = UTC_TIMESTAMP(),
                    attempt_count = 0,
EOF
)
R=$(frag <<'EOF'
                SET status = %s,
                    active_marker = NULL,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    heartbeat_at = UTC_TIMESTAMP(),
                    attempt_count = 0,
EOF
)
control "a chunk release drops the active-run slot" int "$REPO" "$N" "$R"

# ── ⚠ (11) MAINTENANCE INVENTS A RUN ────────────────────────────────────
#
# The sweep may re-dispatch an administrator's run. It may never create one.
N=$(frag <<'EOF'
        foreach (DiscoveryRunRepository::findDispatchable(self::DISPATCH_LIMIT) as $run) {
EOF
)
R=$(frag <<'EOF'
        DiscoveryRunRepository::insertQueued('cosmwasm_discovery', 'incremental', 1, 1);
        foreach (DiscoveryRunRepository::findDispatchable(self::DISPATCH_LIMIT) as $run) {
EOF
)
control "maintenance creates a run of its own" both "$MAINT" "$N" "$R"

# ── ⚠ (12) SCAN COMPLETION WHILE WORK REMAINS ───────────────────────────
N=$(frag <<'EOF'
        if ($enumerationComplete === self::YES && $remaining === 0 && $exhausted === 0) {
            $scanComplete = self::YES;
        }
EOF
)
R=$(frag <<'EOF'
        if ($enumerationComplete === self::YES) {
            $scanComplete = self::YES;
        }
EOF
)
control "enumeration alone declares the scan complete" both "$PROGRESS" "$N" "$R"

# ── ⚠ (13) THE AMBIGUOUS `Finished` RESTORED ────────────────────────────
N=$(frag <<'EOF'
            return $sessionStop ? 'Session finished' : 'Pass finished';
EOF
)
R=$(frag <<'EOF'
            return 'Finished';
EOF
)
control "the ambiguous standalone 'Finished' comes back" int "$PANEL" "$N" "$R"

# And the chain-scoped zero.
N=$(frag <<'EOF'
                'This pass completed successfully. It did not confirm a new NFT collection.',
EOF
)
R=$(frag <<'EOF'
                'Checked successfully — nothing new was found. That is a normal result, not an error.',
EOF
)
control "the unscoped 'nothing new was found' wording returns" int "$PANEL" "$N" "$R"

# ── ⚠ (14) POLLING SCHEDULES WORK ───────────────────────────────────────
#
# Rendering the panel must never advance a session.
N=$(frag <<'EOF'
        self::renderSession($current);
EOF
)
R=$(frag <<'EOF'
        self::renderSession($current);
        \BCC\Core\Cron\AsyncDispatcher::scheduleSingle(time(), 'bcc_discovery_run_execute', [1], 'bcc-discovery');
EOF
)
control "rendering the panel schedules a continuation" int "$PANEL" "$N" "$R"

# ── ⚠ (15) BACKLOG-FIRST DISABLED ───────────────────────────────────────
N=$(frag <<'EOF'
        if ($backlog !== null && $backlog > 0) {
EOF
)
R=$(frag <<'EOF'
        if (false) {
EOF
)
control "the code tail runs while a backlog exists" unit "$WORKER" "$N" "$R"

# ── ⚠ (16) THE CLI HOSTS A SESSION ──────────────────────────────────────
N=$(frag <<'EOF'
            $session = $allowContinuation
                ? self::continueSession($run, $runId, $chainId, $token, $counts, $report)
                : ['continued' => false, 'reason' => ''];
EOF
)
R=$(frag <<'EOF'
            $session = self::continueSession($run, $runId, $chainId, $token, $counts, $report);
EOF
)
control "the supervised CLI starts a multi-chunk session" unit "$EXECUTOR" "$N" "$R"

# ── ⚠ (17) THE RELEASE IS NOT CONFIRMED BEFORE SCHEDULING ───────────────
#
# Scheduling a chunk for a row we did not successfully write would queue work
# against a run whose state is unknown.
N=$(frag <<'EOF'
        if (!$released) {
EOF
)
R=$(frag <<'EOF'
        if (false) {
EOF
)
control "a continuation is scheduled without confirming the release" unit "$EXECUTOR" "$N" "$R"

# ── ⚠ (18) AN UNRESOLVED FAMILY DECLARES THE SCAN COMPLETE ──────────────
#
# THE EXHAUSTED-RESULT GATE. `remaining` excludes `retry_count >= MAX_RETRIES`,
# so with only exhausted families left it reads 0 — and without the third
# condition the panel says "Scan complete. All N contract families were
# checked. No supported NFT collections were confirmed" over a family nobody
# ever resolved. Measured on real MySQL before the fix.
N=$(frag <<'EOF'
        if ($enumerationComplete === self::YES && $remaining === 0 && $exhausted === 0) {
EOF
)
R=$(frag <<'EOF'
        if ($enumerationComplete === self::YES && $remaining === 0) {
EOF
)
control "an unresolved family still declares the scan complete" both "$PROGRESS" "$N" "$R"

# ── ⚠ (19) THE SESSION-FINISHED SENTENCE REMOVED ────────────────────────
#
# Falling through to "N families still need review" would invite a Continue
# that can claim nothing.
N=$(frag <<'EOF'
        if ($exhausted > 0 && $eligible === 0 && $delayed === 0) {
EOF
)
R=$(frag <<'EOF'
        if (false) {
EOF
)
control "the unresolved sentence is removed" both "$PROGRESS" "$N" "$R"

# ── ⚠ (20) EXHAUSTED COLLAPSED INTO THE NEGATIVE VERDICT ────────────────
#
# Counting `not_cw721` as exhausted (or the reverse) makes "we could not find
# out" and "this is not an NFT collection" the same number. The schema stores
# them almost identically; only these two counts keep them apart.
N=$(frag <<'EOF'
              WHERE chain_id = %d
                AND classification NOT IN (%s, %s, %s)
                AND retry_count >= %d",
EOF
)
R=$(frag <<'EOF'
              WHERE chain_id = %d
                AND classification IN (%s, %s, %s)
                AND retry_count >= %d",
EOF
)
control "exhausted is collapsed into the terminal negatives" int "$FAMILIES" "$N" "$R"

echo
echo "── result ─────────────────────────────────────────────────────────"
printf '  planted  %d\n' "$((KILLED + SURVIVED + BROKEN))"
printf '  killed   %d\n' "$KILLED"
printf '  survived %d\n' "$SURVIVED"
printf '  broken   %d\n' "$BROKEN"

if [ "${#SURVIVORS[@]}" -gt 0 ]; then
  echo
  echo "  SURVIVORS — the suite does not constrain these:"
  for s in "${SURVIVORS[@]}"; do printf '    - %s\n' "$s"; done
fi
if [ "${#BROKENS[@]}" -gt 0 ]; then
  echo
  echo "  BROKEN — the mutator could not plant these; they proved NOTHING:"
  for b in "${BROKENS[@]}"; do printf '    - %s\n' "$b"; done
fi

[ "$SURVIVED" -eq 0 ] && [ "$BROKEN" -eq 0 ] && exit 0
exit 1
