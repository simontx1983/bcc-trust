#!/usr/bin/env bash
#
# PR 7.1 mutation controls — NFT scan eligibility and the false-zero path.
#
# Each control plants a specific defect and REQUIRES the suite to go red. A
# control that "passes" (suite still green with the defect planted) is a
# FAILURE of the control: the test describes the code instead of constraining
# it.
#
# ── ⚠ A MUTATOR THAT CHANGES NOTHING IS *BROKEN*, NEVER "KILLED" ────────
# PR 7's first runner used `perl -0777 -pi -e "s/\Q$needle\E/$repl/"`. The
# SHELL expanded `$key` and friends inside the double quotes, so some
# invocations died loudly and others EXITED 0 HAVING CHANGED NOTHING — and
# every one of those was counted as a SURVIVING mutant. 22 of 23 "survived"
# that way, manufacturing "your tests do not constrain this" out of a broken
# tool. `mutate.py` requires the needle to be present and UNIQUE and re-reads
# the file to prove the bytes changed; anything else is BROKEN here.
#
# ── WHAT THESE CONTROLS AIM AT ──────────────────────────────────────────
# The three things it would be worst to get wrong in this PR:
#   1. product support stops being the FIRST question, so a capability row
#      or an allowlist entry can make a validator-only chain scannable;
#   2. the executor stops re-asking, so a config change after queueing lets
#      a run reach a provider it should not, or produce an unattributable
#      refusal that reads like "this chain has no NFTs";
#   3. the retired enrichment loop comes back.
#
# Usage:
#   BCC_TEST_DB_HOST=127.0.0.1 BCC_TEST_DB_PORT=13471 \
#   BCC_TEST_DB_USER=root BCC_TEST_DB_PASS=root BCC_TEST_DB_NAME=bcc_test_pr71 \
#   PHP_BIN=C:/php/php.exe bash scripts/tests/pr71-mutation-controls.sh

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 2

PHP="${PHP_BIN:-php}"
PY="${PYTHON_BIN:-python}"
MUTATE="scripts/tests/mutate.py"

UNIT_FILTER='EnrichmentCronRetiredTest|AutomaticNftDiscoveryRetiredTest'

HOOKS='includes/cron-hooks.php'
UNSCHED='includes/database/unschedule-automatic-nft-discovery.php'
RUNNER='includes/database/migration-runner.php'
ENRICH='app/Domain/Onchain/Services/NftEnrichmentService.php'

FILES=("$HOOKS" "$UNSCHED" "$RUNNER" "$ENRICH")

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

# ── PREFLIGHT ───────────────────────────────────────────────────────────
echo "── preflight ──────────────────────────────────────────────────────"

if [ ! -f "$MUTATE" ]; then
  echo "ABORT: $MUTATE is missing; every control would report BROKEN."
  exit 2
fi
echo "  ok   mutator present"

if ! run_unit; then
  echo "ABORT: the unit anchor does not pass on unmutated code."
  echo "       Every 'killed' below would be meaningless."
  exit 2
fi
echo "  ok   unit harness green before mutating"
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
  if [ "$harness" = unit ]; then run_unit || unit_red=1; fi

  restore

  if [ "$unit_red" -eq 1 ] || [ "$int_red" -eq 1 ]; then
    KILLED=$((KILLED + 1))
    printf '  killed    %s\n' "$label"
  else
    SURVIVED=$((SURVIVED + 1))
    SURVIVORS+=("$label")
    printf '  SURVIVED  %s\n' "$label"
  fi
}

frag() { local p; p="$(mktemp "$WORK/frag.XXXXXX")"; cat > "$p"; printf '%s' "$p"; }

echo "── controls ───────────────────────────────────────────────────────"

# ── CONTROLS (1)-(7) AND (9) REMOVED, WITH THEIR TARGETS (2026-11-12) ──
#
# They mutated DiscoveryReadiness, DiscoveryRunService, DiscoveryScanMode,
# the executor's readiness recheck and the Verify Collections scan panel. All
# of that was deleted in the scanner retirement (S1-S8), together with every
# test these controls named (DiscoveryReadinessTest, DiscoveryRunServiceTest,
# DiscoveryReadinessIntegrationTest, ...). scanner-removal-mutation-
# controls.sh and ScannerRemovedInventoryTest guard that the surface stays
# gone. What remains is (8): the retirement itself must stay retired.

# ── ⚠ (8) THE ENRICHMENT LOOP COMES BACK ────────────────────────────────

N=$(frag <<'EOF'
        'bcc_nft_enrichment_tick',
EOF
)
R=$(frag <<'EOF'
EOF
)
control "the enrichment hook leaves the cleanup-only list" unit "$HOOKS" "$N" "$R"

N=$(frag <<'EOF'
            'bcc_nft_enrichment_tick',
EOF
)
R=$(frag <<'EOF'
EOF
)
control "the enrichment hook leaves the shared retired list" unit "$UNSCHED" "$N" "$R"

# ⚠ THE ONE THAT WOULD SHIP SILENTLY. Dropping the v2 registry entry leaves
# the hook in every list and the code correct — and clears it on NEW
# installs only, because v1's done_option is already set on every install
# that ran it. The live five-minute event would keep running forever on
# exactly the machines that have one.
N=$(frag <<'EOF'
                'id'          => 'unschedule_automatic_nft_discovery_v2',
EOF
)
R=$(frag <<'EOF'
                'id'          => 'unschedule_automatic_nft_discovery_v1',
EOF
)
control "the second migration entry loses its distinct id" unit "$RUNNER" "$N" "$R"

N=$(frag <<'EOF'
                'done_option' => 'bcc_trust_nft_enrichment_tick_unscheduled',
EOF
)
R=$(frag <<'EOF'
                'done_option' => 'bcc_trust_automatic_nft_discovery_unscheduled',
EOF
)
control "the v2 migration reuses v1's done_option and never runs" unit "$RUNNER" "$N" "$R"

# And the schedule itself coming back.
N=$(frag <<'EOF'
    public static function register(): void
    {
EOF
)
R=$(frag <<'EOF'
    public static function register(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 90, self::CRON_INTERVAL, self::CRON_HOOK);
        }
EOF
)
control "the enrichment self-heal is restored" unit "$ENRICH" "$N" "$R"

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
