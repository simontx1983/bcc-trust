#!/usr/bin/env bash
#
# PR 7.5 mutation controls — honest classification wording, provider-safe pacing.
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
# Two live findings from the 2026-09-07 Cosmos Hub session (run 5):
#
#   1. the panel added 12 confirmed + 1 probable and said "13 confirmed";
#   2. 772 requests at ~3/s opened the provider's circuit breaker.
#
# …plus the boundaries neither correction may erode: a probable family must
# never masquerade as confirmed, and a breaker refusal must never be retried
# or turned into a negative verdict.
#
# Usage:
#   BCC_TEST_DB_HOST=127.0.0.1 BCC_TEST_DB_PORT=13475 \
#   BCC_TEST_DB_USER=root BCC_TEST_DB_PASS=root BCC_TEST_DB_NAME=bcc_pr75 \
#   bash scripts/tests/pr75-mutation-controls.sh

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 2

PHP="${PHP_BIN:-php}"
PY="${PYTHON_BIN:-python}"
MUTATE="scripts/tests/mutate.py"

UNIT_FILTER='ClassificationSemanticsTest|ProbableClassificationBoundaryTest|DiscoveryPacingTest|DiscoveryScanProgressTest|DiscoverySessionSummaryWordingTest|CosmwasmCliPreflightAccuracyTest|BreakerStopsProviderWorkTest|DiscoveryScanSessionTest'
INT_FILTER='ClassificationCountsIntegrationTest|DiscoveryScanProgressIntegrationTest|DiscoverySessionPanelIntegrationTest|DiscoveryUnresolvedFamiliesIntegrationTest|DiscoveryScanPanelControlIntegrationTest'

PROGRESS='app/Domain/Onchain/Services/DiscoveryScanProgress.php'
FAMILIES='app/Domain/Onchain/Repositories/CosmwasmCodeFamilyRepository.php'
PANEL='app/Domain/Onchain/Admin/Views/DiscoveryScanPanel.php'
SESSION='app/Domain/Onchain/Services/DiscoveryScanSession.php'
GATE='app/Domain/Onchain/Support/CosmwasmDiscoveryGate.php'
WORKER='app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php'
CLASSIFIER='app/Domain/Onchain/Services/CosmwasmClassifier.php'
EXECUTOR='app/Domain/Onchain/Workers/DiscoveryRunExecutor.php'

FILES=("$PROGRESS" "$FAMILIES" "$PANEL" "$SESSION" "$GATE" "$WORKER" "$CLASSIFIER" "$EXECUTOR")

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

# ── ⚠ (1) PROBABLE FOLDED BACK INTO CONFIRMED ───────────────────────────
#
# The original defect, re-planted at the SQL. This is exactly what
# `countCollectionFamiliesOrThrow()` did, and it is what printed "13".
N=$(frag <<'EOF'
            "SELECT COUNT(*) FROM {$table} WHERE chain_id = %d AND classification = %s",
            $chainId,
            $classification
EOF
)
R=$(frag <<'EOF'
            "SELECT COUNT(*) FROM {$table} WHERE chain_id = %d AND classification IN (%s, %s)",
            $chainId,
            $classification,
            $classification === CosmwasmClassifier::CONFIRMED
                ? CosmwasmClassifier::PROBABLE
                : CosmwasmClassifier::CONFIRMED
EOF
)
control "the repository folds probable back into confirmed" both "$FAMILIES" "$N" "$R"

# The same collapse one layer up: the read model adding the two counts.
N=$(frag <<'EOF'
            'confirmed_families'   => $confirmed,
EOF
)
R=$(frag <<'EOF'
            'confirmed_families'   => $confirmed + $probable,
EOF
)
control "the read model adds probable into the confirmed count" both "$PROGRESS" "$N" "$R"

# ── ⚠ (2) THE PROBABLE COUNT HIDDEN ─────────────────────────────────────
#
# Silence is the other way to mislead: an operator who is never told about a
# candidate cannot review it, and the chain looks fully settled.
N=$(frag <<'EOF'
    private static function probableClause(int $probable): string
    {
        if ($probable <= 0) {
            return '';
        }
EOF
)
R=$(frag <<'EOF'
    private static function probableClause(int $probable): string
    {
        if ($probable >= 0) {
            return '';
        }
EOF
)
control "the probable clause is suppressed entirely" both "$PROGRESS" "$N" "$R"

# …and the panel's own probable chip.
N=$(frag <<'EOF'
            $probable = (int) ($progress['probable_families'] ?? 0);
            if ($probable > 0) {
EOF
)
R=$(frag <<'EOF'
            $probable = (int) ($progress['probable_families'] ?? 0);
            if (false) {
EOF
)
control "the panel hides its probable chip" int "$PANEL" "$N" "$R"

# ── ⚠ (3) A PROBABLE-ONLY CHAIN CLAIMS ZERO COLLECTIONS ─────────────────
#
# The final zero requires zero confirmed AND zero probable. Dropping the
# probable half lets a chain holding a live candidate announce there is
# nothing here.
N=$(frag <<'EOF'
            if ($confirmed > 0 || $probable > 0) {
                // ⚠ SCANNER-complete, which is not the same as SETTLED.
EOF
)
R=$(frag <<'EOF'
            if ($confirmed > 0) {
                // ⚠ SCANNER-complete, which is not the same as SETTLED.
EOF
)
control "a probable-only complete chain may claim the final zero" both "$PROGRESS" "$N" "$R"

# ── ⚠ (4)(5)(6) THE PACING NUMBERS REVERTED ─────────────────────────────
#
# Each is a promise to a provider whose breaker already opened once.
N=$(frag <<'EOF'
    public const DEFAULT_REQUEST_BUDGET = 25;
EOF
)
R=$(frag <<'EOF'
    public const DEFAULT_REQUEST_BUDGET = 50;
EOF
)
control "the default chunk budget reverts to 50" unit "$GATE" "$N" "$R"

N=$(frag <<'EOF'
    public const MAX_REQUESTS = 625;
EOF
)
R=$(frag <<'EOF'
    public const MAX_REQUESTS = 1250;
EOF
)
control "the session request ceiling reverts to 1250" unit "$SESSION" "$N" "$R"

N=$(frag <<'EOF'
    public const CHUNK_DELAY_SECONDS = 60;
EOF
)
R=$(frag <<'EOF'
    public const CHUNK_DELAY_SECONDS = 15;
EOF
)
control "the continuation delay reverts to 15 seconds" unit "$SESSION" "$N" "$R"

# …and the clamp that stops an override walking past the session ceiling.
N=$(frag <<'EOF'
        return min(CosmwasmDiscoveryGate::requestBudget(), $remaining);
EOF
)
R=$(frag <<'EOF'
        return CosmwasmDiscoveryGate::requestBudget();
EOF
)
control "the chunk allowance ignores what the session has left" unit "$SESSION" "$N" "$R"

# ── ⚠ (7) THE CIRCUIT BREAKER BYPASSED ──────────────────────────────────
#
# The breaker is the provider's own protection and it stays authoritative.
# Removing the check is how a stopped session becomes a hammering one.
N=$(frag <<'EOF'
        if (OnchainCircuitBreaker::isOpen($chainId)) {
EOF
)
R=$(frag <<'EOF'
        if (false && OnchainCircuitBreaker::isOpen($chainId)) {
EOF
)
control "the circuit-breaker stop is bypassed" both "$WORKER" "$N" "$R"

# ── ⚠ (8) A REFUSED SESSION RETRIED AUTOMATICALLY ───────────────────────
#
# "Not ready" must end the session, not loop it. A breaker-stopped run that
# re-queues itself is precisely the hammering the breaker exists to stop.
N=$(frag <<'EOF'
        if (($ctx['ready'] ?? false) !== true) {
EOF
)
R=$(frag <<'EOF'
        if (false) {
EOF
)
control "a not-ready session continues instead of stopping" unit "$SESSION" "$N" "$R"

# ── ⚠ (9) AN UNRESOLVED FAILURE BECOMES A NEGATIVE VERDICT ──────────────
#
# "We could not reach it" is not "this is not an NFT collection". A breaker
# trip must never settle a family it prevented us from examining.
N=$(frag <<'EOF'
        return $classification === self::NOT_CW721;
EOF
)
R=$(frag <<'EOF'
        return $classification === self::NOT_CW721 || $classification === self::UNREACHABLE;
EOF
)
control "an unreachable family is treated as a terminal negative" unit "$CLASSIFIER" "$N" "$R"

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
