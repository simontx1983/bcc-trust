#!/usr/bin/env bash
#
# PR 7.2 mutation controls — truthful scan completeness.
#
# Each control plants a specific defect and REQUIRES the suite to go red. A
# control that "passes" (suite still green with the defect planted) is a
# FAILURE of the control: the test describes the code instead of constraining
# it.
#
# ── ⚠ A MUTATOR THAT CHANGES NOTHING IS *BROKEN*, NEVER "KILLED" ────────
# `mutate.py` requires the needle to be present and UNIQUE and re-reads the
# file to prove the bytes changed. Anything else reports BROKEN here. PR 7's
# first runner counted 22 no-op mutators as SURVIVORS and manufactured "your
# tests do not constrain this" out of a broken tool.
#
# ── WHAT THESE AIM AT ───────────────────────────────────────────────────
# Every way the Cosmos Hub canary's state could be made to read as finished:
#   - enumeration alone forcing scan-complete;
#   - remaining work forced to zero;
#   - a failed progress read treated as an empty queue;
#   - default unvisited rows treated as settled;
#   - the final-zero sentence escaping while work remains;
#   - `Continue scan` becoming a restart.
#
# Usage:
#   BCC_TEST_DB_HOST=127.0.0.1 BCC_TEST_DB_PORT=13472 \
#   BCC_TEST_DB_USER=root BCC_TEST_DB_PASS=root BCC_TEST_DB_NAME=bcc_test_pr72 \
#   bash scripts/tests/pr72-mutation-controls.sh

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 2

PHP="${PHP_BIN:-php}"
PY="${PYTHON_BIN:-python}"
MUTATE="scripts/tests/mutate.py"

UNIT_FILTER='DiscoveryScanProgressTest|RepositoryReadGuardCoverageTest'
INT_FILTER='DiscoveryScanProgressIntegrationTest|DiscoveryProgressFailClosedIntegrationTest'

PROGRESS='app/Domain/Onchain/Services/DiscoveryScanProgress.php'
REPO='app/Domain/Onchain/Repositories/CosmwasmCodeFamilyRepository.php'
PANEL='app/Domain/Onchain/Admin/Views/DiscoveryScanPanel.php'

FILES=("$PROGRESS" "$REPO" "$PANEL")

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

run_unit() { "$PHP" vendor/bin/phpunit --filter "$UNIT_FILTER" --no-coverage >/dev/null 2>&1; }
run_int()  { "$PHP" -d extension_dir=C:/php/ext -d extension=mysqli vendor/bin/phpunit \
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

# ── ⚠ (1) ENUMERATION ALONE FORCING COMPLETE ────────────────────────────
#
# THE CANARY'S EXACT DEFECT. Dropping the second condition makes
# `cw_backfill_completed_at` sufficient — which is the state the 2026-09-04
# run left behind with 732 families unexamined.
N=$(frag <<'EOF'
        if ($enumerationComplete === self::YES && $remaining === 0) {
EOF
)
R=$(frag <<'EOF'
        if ($enumerationComplete === self::YES) {
EOF
)
control "enumeration alone forces scan-complete" both "$PROGRESS" "$N" "$R"

# And the inverse: an empty queue while enumeration is still open.
N=$(frag <<'EOF'
        if ($enumerationComplete === self::YES && $remaining === 0) {
EOF
)
R=$(frag <<'EOF'
        if ($remaining === 0) {
EOF
)
control "an empty queue alone forces scan-complete" both "$PROGRESS" "$N" "$R"

# ── ⚠ (2) REMAINING WORK FORCED TO ZERO ─────────────────────────────────

N=$(frag <<'EOF'
            $remaining   = CosmwasmCodeFamilyRepository::countPendingClassificationOrThrow(
                $chainId,
                CosmwasmClassifier::VERSION
            );
EOF
)
R=$(frag <<'EOF'
            $remaining   = 0;
EOF
)
control "remaining work hardcoded to zero" both "$PROGRESS" "$N" "$R"

# ── ⚠ (3) A FAILED READ TREATED AS AN EMPTY QUEUE ───────────────────────

N=$(frag <<'EOF'
            return $unknown('progress_unavailable');
EOF
)
R=$(frag <<'EOF'
            $total = 0; $remaining = 0; $collections = 0;
EOF
)
control "a failed progress read becomes an empty queue" both "$PROGRESS" "$N" "$R"

# The fail-closed guard downgraded to the worker's fail-open one.
N=$(frag <<'EOF'
        $total = $wpdb->get_var(self::pendingClassificationCountSql($chainId, $classifierVersion));
        self::guardReadOrThrow(__FUNCTION__);
EOF
)
R=$(frag <<'EOF'
        $total = $wpdb->get_var(self::pendingClassificationCountSql($chainId, $classifierVersion));
        self::guardRead(__FUNCTION__);
EOF
)
control "the operator count stops failing closed" int "$REPO" "$N" "$R"

# ── ⚠ (4) DEFAULT UNVISITED ROWS TREATED AS SETTLED ─────────────────────
#
# Dropping `classified_at IS NULL` is precisely what turns 732 never-looked-at
# families into a finished scan.
N=$(frag <<'EOF'
                AND (classified_at IS NULL OR classifier_version < %d)",
            $chainId,
            CosmwasmClassifier::NOT_CW721,
            CosmwasmClassifier::CONFIRMED,
            CosmwasmClassifier::PROBABLE,
            CosmwasmClassifier::MAX_RETRIES,
            $classifierVersion
        );
    }
EOF
)
R=$(frag <<'EOF'
                AND (classifier_version < %d)",
            $chainId,
            CosmwasmClassifier::NOT_CW721,
            CosmwasmClassifier::CONFIRMED,
            CosmwasmClassifier::PROBABLE,
            CosmwasmClassifier::MAX_RETRIES,
            $classifierVersion
        );
    }
EOF
)
control "default unvisited rows are treated as settled" int "$REPO" "$N" "$R"

# The inconclusive default excluded outright.
N=$(frag <<'EOF'
                AND classification NOT IN (%s, %s, %s)
                AND retry_count < %d
                AND (classified_at IS NULL OR classifier_version < %d)",
EOF
)
R=$(frag <<'EOF'
                AND classification NOT IN (%s, %s, %s)
                AND classification <> 'inconclusive'
                AND retry_count < %d
                AND (classified_at IS NULL OR classifier_version < %d)",
EOF
)
control "inconclusive rows are dropped from the queue" int "$REPO" "$N" "$R"

# ── ⚠ (5) FINAL-ZERO WORDING ESCAPING WHILE WORK REMAINS ────────────────

N=$(frag <<'EOF'
        if (($progress['scan_complete'] ?? self::NO) === self::YES) {
EOF
)
R=$(frag <<'EOF'
        if (true) {
EOF
)
control "the final-zero sentence escapes while work remains" unit "$PROGRESS" "$N" "$R"

# The unavailable branch removed — a failed read would then fall through to
# a numeric sentence built from nulls.
N=$(frag <<'EOF'
        if (($progress['ok'] ?? false) !== true || ($progress['scan_complete'] ?? self::UNKNOWN) === self::UNKNOWN) {
EOF
)
R=$(frag <<'EOF'
        if (false) {
EOF
)
control "the unavailable sentence is bypassed" unit "$PROGRESS" "$N" "$R"

# ── ⚠ (6) CONTINUE SCAN BECOMING A RESTART ──────────────────────────────

N=$(frag <<'EOF'
            return __('Continue scan', 'bcc-trust');
EOF
)
R=$(frag <<'EOF'
            return __('Start over', 'bcc-trust');
EOF
)
control "Continue scan becomes Start over" unit "$PROGRESS" "$N" "$R"

N=$(frag <<'EOF'
        if (($progress['ok'] ?? false) === true && ($progress['more_work_available'] ?? self::UNKNOWN) === self::YES) {
            return __('Continue scan', 'bcc-trust');
        }
EOF
)
R=$(frag <<'EOF'
        if (false) {
            return __('Continue scan', 'bcc-trust');
        }
EOF
)
control "the Continue label never appears" unit "$PROGRESS" "$N" "$R"

# ── ⚠ (7) POLLING STOPS BEING FREE ──────────────────────────────────────
#
# A write on the read path is a write on every page load and every poll.
N=$(frag <<'EOF'
        $checkpoint = ChainCheckpointRepository::get($chainId);
EOF
)
R=$(frag <<'EOF'
        ChainCheckpointRepository::ensureExists($chainId);
        $checkpoint = ChainCheckpointRepository::get($chainId);
EOF
)
control "reading progress writes a checkpoint row" int "$PROGRESS" "$N" "$R"

echo
echo "── result ─────────────────────────────────────────────────────────"
printf '  killed   %d\n' "$KILLED"
printf '  survived %d\n' "$SURVIVED"
printf '  BROKEN   %d\n' "$BROKEN"
for s in "${SURVIVORS[@]:-}"; do [ -n "$s" ] && printf '  SURVIVOR  %s\n' "$s"; done
for b in "${BROKENS[@]:-}";   do [ -n "$b" ] && printf '  BROKEN    %s\n' "$b"; done

if [ "$SURVIVED" -gt 0 ] || [ "$BROKEN" -gt 0 ]; then
  echo
  echo "FAIL: a surviving mutant means the tests do not constrain that rule;"
  echo "      a BROKEN control means the mutation never applied and proves nothing."
  exit 1
fi

echo
echo "PASS: every planted defect was caught."
