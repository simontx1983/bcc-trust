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

UNIT_FILTER='DiscoveryReadinessTest|DiscoveryExecutorFrozenModeTest|DiscoveryRunReadinessGateTest|DiscoveryExecutorReadinessRecheckTest|EnrichmentCronRetiredTest|AutomaticNftDiscoveryRetiredTest|DiscoveryRunServiceTest'
INT_FILTER='DiscoveryReadinessIntegrationTest'

READY='app/Domain/Onchain/Support/DiscoveryReadiness.php'
EXEC='app/Domain/Onchain/Workers/DiscoveryRunExecutor.php'
SERVICE='app/Domain/Onchain/Services/DiscoveryRunService.php'
PAGE='app/Domain/Onchain/Admin/VerifyCollectionsPage.php'
HOOKS='includes/cron-hooks.php'
UNSCHED='includes/database/unschedule-automatic-nft-discovery.php'
RUNNER='includes/database/migration-runner.php'
ENRICH='app/Domain/Onchain/Services/NftEnrichmentService.php'
MODE='app/Domain/Onchain/ValueObjects/DiscoveryScanMode.php'

FILES=("$READY" "$EXEC" "$SERVICE" "$PAGE" "$HOOKS" "$UNSCHED" "$RUNNER" "$ENRICH" "$MODE")

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

if ! run_int; then
  echo "ABORT: the integration anchor does not pass on unmutated code."
  echo "       Check the throwaway MySQL is up and BCC_TEST_DB_* are set —"
  echo "       an unreachable database makes every kill a FALSE kill."
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

# ── ⚠ (1) PRODUCT SUPPORT IS THE FIRST QUESTION ─────────────────────────

N=$(frag <<'EOF'
        if ($nftSupported !== true) {
            return DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED;
        }
EOF
)
R=$(frag <<'EOF'
        if (false) {
            return DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED;
        }
EOF
)
control "product support stops being checked at all" both "$READY" "$N" "$R"

# The classic truthiness bug: '0' from MySQL is falsy, but a loose check
# written as `!$nftSupported` treats the ABSENT column (null) the same as
# false — which is right — while `== false` on a '0' string is a different
# trap. This plants the one that ACCEPTS an unreadable column.
N=$(frag <<'EOF'
        if ($nftSupported !== true) {
EOF
)
R=$(frag <<'EOF'
        if ($nftSupported === false) {
EOF
)
control "an unreadable support column is treated as supported" both "$READY" "$N" "$R"

# ── ⚠ (2) THE ORDER IS THE SECURITY PROPERTY ────────────────────────────
#
# Moving support AFTER the per-chain verdict is the mutation that makes a
# capability row and an allowlist entry able to rescue a validator-only
# chain. The behaviour is identical on every supported chain, so only a
# test that pins the ORDER can catch it.
N=$(frag <<'EOF'
        // (2) PRODUCT SUPPORT — asked before anything an operator can set.
EOF
)
R=$(frag <<'EOF'
        if ($chainVerdict === CosmwasmScanEligibility::ELIGIBLE && $nftSupported !== true) { return CosmwasmScanEligibility::ELIGIBLE; }
        // (2) PRODUCT SUPPORT — asked before anything an operator can set.
EOF
)
control "an eligible verdict overrides missing product support" both "$READY" "$N" "$R"

# ── ⚠ (3) THE MODE-GATED ENVIRONMENT SWITCHES ───────────────────────────

N=$(frag <<'EOF'
            if (!$discoveryEnabled) {
                return DiscoveryRunError::DISCOVERY_GLOBALLY_DISABLED;
            }
EOF
)
R=$(frag <<'EOF'
            if (false) {
                return DiscoveryRunError::DISCOVERY_GLOBALLY_DISABLED;
            }
EOF
)
control "the global master switch stops blocking a historical run" unit "$READY" "$N" "$R"

N=$(frag <<'EOF'
            if (!$backfillEnabled) {
                return DiscoveryRunError::HISTORICAL_BACKFILL_DISABLED;
            }
EOF
)
R=$(frag <<'EOF'
            if (false) {
                return DiscoveryRunError::HISTORICAL_BACKFILL_DISABLED;
            }
EOF
)
control "the backfill switch stops blocking a historical run" unit "$READY" "$N" "$R"

# The inverse: gating INCREMENTAL on the switches too. This is the mutation
# that would refuse the supervised operator path — a failure in the
# blocks-legitimate-work direction, which is the one review misses.
N=$(frag <<'EOF'
        if ($scanMode === DiscoveryScanMode::HISTORICAL) {
EOF
)
R=$(frag <<'EOF'
        if (true) {
EOF
)
control "incremental is wrongly gated on the environment switches" unit "$READY" "$N" "$R"

# ── ⚠ (4) THE PER-CHAIN VERDICT FAILS OPEN ──────────────────────────────

N=$(frag <<'EOF'
                default                                     => DiscoveryRunError::DISCOVERY_DISABLED,
EOF
)
R=$(frag <<'EOF'
                default                                     => CosmwasmScanEligibility::ELIGIBLE,
EOF
)
control "an unknown verdict is treated as eligible" unit "$READY" "$N" "$R"

N=$(frag <<'EOF'
        return $reason === CosmwasmScanEligibility::ELIGIBLE;
EOF
)
R=$(frag <<'EOF'
        return $reason !== DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED;
EOF
)
control "isEligible becomes a negation instead of an identity test" both "$READY" "$N" "$R"

# ── ⚠ (5) THE EXECUTOR STOPS RE-ASKING ──────────────────────────────────

N=$(frag <<'EOF'
        if (!$readiness['eligible']) {
EOF
)
R=$(frag <<'EOF'
        if (false) {
EOF
)
control "the executor stops acting on its readiness recheck" unit "$EXEC" "$N" "$R"

# Re-judging against a freshly derived mode instead of the frozen one is the
# subtle version: a checkpoint completing between queue and pickup would
# switch the run to incremental and skip the backfill gate it was approved
# under.
N=$(frag <<'EOF'
        $readiness = DiscoveryReadiness::forExecution($chainId, $scanMode);
EOF
)
R=$(frag <<'EOF'
        $readiness = DiscoveryReadiness::forExecution($chainId, DiscoveryScanMode::INCREMENTAL);
EOF
)
control "the executor re-judges against a mode the run was not approved for" unit "$EXEC" "$N" "$R"

# ── ⚠ (6) THE REQUEST GATE STOPS REFUSING ───────────────────────────────

N=$(frag <<'EOF'
        $readiness = $this->chainReadiness($chainId, $forceScanMode);
        if (!$readiness['eligible']) {
            return $this->refuse($readiness['reason']);
        }
EOF
)
R=$(frag <<'EOF'
        $readiness = $this->chainReadiness($chainId, $forceScanMode);
        if (false) {
            return $this->refuse($readiness['reason']);
        }
EOF
)
control "the request gate accepts a run it cannot execute" unit "$SERVICE" "$N" "$R"

# ── ⚠ (7) THE PANEL SHOWS UNSUPPORTED CHAINS AGAIN ──────────────────────

N=$(frag <<'EOF'
                static fn(object $c): bool => DiscoveryReadiness::isNftDiscoverySurface($c)
EOF
)
R=$(frag <<'EOF'
                static fn(object $c): bool => true
EOF
)
control "validator-only chains regain a scan panel" unit "$PAGE" "$N" "$R"

N=$(frag <<'EOF'
        return NftChainCapability::bccNftSupportState($chain) === true;
EOF
)
R=$(frag <<'EOF'
        return NftChainCapability::bccNftSupportState($chain) !== false;
EOF
)
control "the scan surface admits an unreadable support column" both "$READY" "$N" "$R"

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

# ── ⚠ (9) THE SCAN-MODE RULE ────────────────────────────────────────────

N=$(frag <<'EOF'
        if (!is_string($completedAt) || trim($completedAt) === '') {
            return self::HISTORICAL;
        }
EOF
)
R=$(frag <<'EOF'
        if (false) {
            return self::HISTORICAL;
        }
EOF
)
control "a never-walked chain resolves to incremental" both "$MODE" "$N" "$R"

N=$(frag <<'EOF'
        if (str_starts_with($completedAt, '0000-00-00')) {
            return self::HISTORICAL;
        }
EOF
)
R=$(frag <<'EOF'
        if (false) {
            return self::HISTORICAL;
        }
EOF
)
control "the MySQL zero-date is read as a real completion" unit "$MODE" "$N" "$R"

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
