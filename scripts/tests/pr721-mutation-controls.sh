#!/usr/bin/env bash
#
# PR 7.2.1 mutation controls — the panel's control must tell the truth about
# whether a scan is running.
#
# Each control plants a specific defect and REQUIRES the suite to go red. A
# control that "passes" (suite still green with the defect planted) is a
# FAILURE of the control: the test describes the code instead of constraining
# it.
#
# ── ⚠ CONTROL (1) IS THE SHIPPED DEFECT ITSELF ──────────────────────────
# It restores `$hasActive = $current !== null` — the exact line that was on
# staging. If it does not go red, this whole PR is untested, because that is
# precisely the state a live administrator saw:
#
#     [Scan On-Chain for Easy Discovery]  A scan is already running for
#                                         this chain.
#
# beside a run that had finished, with `Continue scan` unreachable.
#
# ── ⚠ WHY THESE ARE ALL `int` AND NONE ARE `unit` ───────────────────────
# There is no unit harness for this. The label function was ALREADY unit
# tested and ALREADY returned the right string — the defect was that nothing
# rendered it. Only asserting on the view's real output constrains that, so
# every control here must be killed by an integration render.
#
# Usage:
#   BCC_TEST_DB_HOST=127.0.0.1 BCC_TEST_DB_PORT=13473 \
#   BCC_TEST_DB_USER=root BCC_TEST_DB_PASS=root BCC_TEST_DB_NAME=bcc_pr721 \
#   bash scripts/tests/pr721-mutation-controls.sh

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 2

PHP="${PHP_BIN:-php}"
PY="${PYTHON_BIN:-python}"
MUTATE="scripts/tests/mutate.py"

INT_FILTER='DiscoveryScanPanelControlIntegrationTest'

PANEL='app/Domain/Onchain/Admin/Views/DiscoveryScanPanel.php'
READER='app/Domain/Onchain/Services/DiscoveryRunStatusReader.php'
STATUS='app/Domain/Onchain/ValueObjects/DiscoveryRunStatus.php'

FILES=("$PANEL" "$READER" "$STATUS")

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

run_int() { "$PHP" -d extension=mysqli -d extension=gmp vendor/bin/phpunit \
              -c phpunit-integration.xml.dist --filter "$INT_FILTER" --no-coverage >/dev/null 2>&1; }

echo "── preflight ──────────────────────────────────────────────────────"
if [ ! -f "$MUTATE" ]; then
  echo "ABORT: $MUTATE is missing; every control would report BROKEN."
  exit 2
fi
echo "  ok   mutator present"

if ! run_int; then
  echo "ABORT: the integration anchor does not pass on unmutated code."
  echo "       An unreachable database makes every kill a FALSE kill."
  exit 2
fi
echo "  ok   integration harness green before mutating"
echo

control() {
  local label="$1" file="$2" needle="$3" repl="$4"
  backup
  if ! "$PY" "$MUTATE" "$file" "$needle" "$repl" 2>"$WORK/err"; then
    restore
    BROKEN=$((BROKEN + 1))
    BROKENS+=("$label — $(head -1 "$WORK/err")")
    printf '  BROKEN    %s\n' "$label"
    printf '            %s\n' "$(head -1 "$WORK/err")"
    return
  fi
  local red=0
  run_int || red=1
  restore
  if [ "$red" -eq 1 ]; then
    KILLED=$((KILLED + 1)); printf '  killed    %s\n' "$label"
  else
    SURVIVED=$((SURVIVED + 1)); SURVIVORS+=("$label"); printf '  SURVIVED  %s\n' "$label"
  fi
}

frag() { local p; p="$(mktemp "$WORK/frag.XXXXXX")"; cat > "$p"; printf '%s' "$p"; }

echo "── controls ───────────────────────────────────────────────────────"

# ── ⚠ (1) THE SHIPPED DEFECT, RESTORED VERBATIM ─────────────────────────
N=$(frag <<'EOF'
        $hasActive = $current !== null
            && !DiscoveryRunStatus::isTerminal((string) ($current['status'] ?? ''));
EOF
)
R=$(frag <<'EOF'
        $hasActive = $current !== null;
EOF
)
control "the presence of a run row means 'running' (the shipped defect)" "$PANEL" "$N" "$R"

# ── ⚠ (2) THE POLARITY INVERTED ─────────────────────────────────────────
#
# Trading one wrong answer for the opposite one: a RUNNING run would offer a
# second scan beside a live pass. `uq_active` would refuse it at the
# database, so the operator's only feedback would be a bounded error.
N=$(frag <<'EOF'
        $hasActive = $current !== null
            && !DiscoveryRunStatus::isTerminal((string) ($current['status'] ?? ''));
EOF
)
R=$(frag <<'EOF'
        $hasActive = $current !== null
            && DiscoveryRunStatus::isTerminal((string) ($current['status'] ?? ''));
EOF
)
control "liveness inverted — terminal reads as running" "$PANEL" "$N" "$R"

# ── ⚠ (3) LIVENESS FORCED OFF ENTIRELY ──────────────────────────────────
N=$(frag <<'EOF'
        $hasActive = $current !== null
            && !DiscoveryRunStatus::isTerminal((string) ($current['status'] ?? ''));
EOF
)
R=$(frag <<'EOF'
        $hasActive = false;
EOF
)
control "liveness hardcoded false — a live run offers another scan" "$PANEL" "$N" "$R"

# ── ⚠ (4) `retry_allowed` REUSED AS LIVENESS ────────────────────────────
#
# The plausible-looking shortcut, and the reason the fix does NOT take it:
# the reader computes `retry_allowed` as `isTerminal() && !== CANCELLED`, so
# a withdrawn run would keep the button disabled forever under "already
# running". Only the cancelled-run test can see this.
N=$(frag <<'EOF'
        $hasActive = $current !== null
            && !DiscoveryRunStatus::isTerminal((string) ($current['status'] ?? ''));
EOF
)
R=$(frag <<'EOF'
        $hasActive = $current !== null
            && ($current['retry_allowed'] ?? false) !== true;
EOF
)
control "retry_allowed reused as liveness — cancelled stays stuck" "$PANEL" "$N" "$R"

# ── ⚠ (5) AN UNKNOWN STATUS READ AS TERMINAL ────────────────────────────
#
# `isTerminal()` is written as membership in the terminal LIST precisely so
# a token from a newer build reads as NON-terminal. Rewriting it as "not
# queued and not running" reverses that and would open a second run beside a
# possibly-live one. Only the unrecognised-status test can see this — every
# other case sits on a status both spellings agree about.
N=$(frag <<'EOF'
        return in_array($status, self::terminal(), true);
EOF
)
R=$(frag <<'EOF'
        return !in_array($status, [self::QUEUED, self::RUNNING], true);
EOF
)
control "unknown status reads as terminal (fail-open)" "$STATUS" "$N" "$R"

# ── ⚠ (6) THE READER STOPS FALLING BACK ─────────────────────────────────
#
# Not a defect in the fix, but the assumption it rests on. If the reader
# stopped falling back to the latest run, a finished pass would show nothing
# at all — the "your scan vanished" state its own comment warns about — and
# the progress line would lose its context.
N=$(frag <<'EOF'
        $current = DiscoveryRunRepository::findActive($jobKind, $chainId)
            ?? DiscoveryRunRepository::findLatest($jobKind, $chainId);
EOF
)
R=$(frag <<'EOF'
        $current = DiscoveryRunRepository::findActive($jobKind, $chainId);
EOF
)
control "the reader stops falling back to the latest run" "$READER" "$N" "$R"

# ── ⚠ (7) ELIGIBILITY CHECKED AFTER LIVENESS ────────────────────────────
#
# Order is a security property here: an unsupported chain whose last run went
# terminal would get an actionable request form. The service and executor
# would still refuse it, but the panel would be inviting the attempt.
N=$(frag <<'EOF'
        if (!$scannable) {
EOF
)
R=$(frag <<'EOF'
        if (!$scannable && $hasActive) {
EOF
)
control "eligibility refusal weakened to require a live run" "$PANEL" "$N" "$R"

# ── ⚠ (8) THE LABEL BYPASSED ────────────────────────────────────────────
#
# Back to a fixed string. The whole point of PR 7.2's label is that it is
# DERIVED, so this must not be silently acceptable.
N=$(frag <<'EOF'
            esc_html(DiscoveryScanProgress::actionLabel($progress))
EOF
)
R=$(frag <<'EOF'
            esc_html__('Scan On-Chain for Easy Discovery', 'bcc-trust')
EOF
)
control "the derived label replaced by a fixed string" "$PANEL" "$N" "$R"

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
