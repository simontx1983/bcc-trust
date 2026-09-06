#!/usr/bin/env bash
#
# PR 7.4 mutation controls — cumulative session totals and honest wording.
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
# The two defects the 2026-09-06 Cosmos Hub session exposed:
#
#   1. the terminal audit reported the LAST CHUNK as though it were the
#      session — 41 / 9 / 0 against a ledger row of 1136 / 371 / 2;
#   2. the panel said "No NFT collections were confirmed in this pass" beside
#      "Found 2 new collection(s)" and "5 NFT collection families confirmed".
#
# …plus the distinctions PR 7.4 must not let collapse: an emitted collection
# ROW is not a confirmed FAMILY, an unresolved family is not a negative
# verdict, and a failed read is not a zero.
#
# Usage:
#   BCC_TEST_DB_HOST=127.0.0.1 BCC_TEST_DB_PORT=13474 \
#   BCC_TEST_DB_USER=root BCC_TEST_DB_PASS=root BCC_TEST_DB_NAME=bcc_pr74 \
#   bash scripts/tests/pr74-mutation-controls.sh

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 2

PHP="${PHP_BIN:-php}"
PY="${PYTHON_BIN:-python}"
MUTATE="scripts/tests/mutate.py"

UNIT_FILTER='DiscoverySessionAuditTotalsTest|DiscoverySessionTotalsTest|DiscoverySessionSummaryWordingTest|DiscoveryScanProgressTest'
INT_FILTER='DiscoverySessionCumulativeAuditIntegrationTest|DiscoverySessionPanelIntegrationTest|DiscoveryUnresolvedFamiliesIntegrationTest|DiscoveryScanPanelControlIntegrationTest|DiscoveryProgressFailClosedIntegrationTest'

EXECUTOR='app/Domain/Onchain/Workers/DiscoveryRunExecutor.php'
TOTALS='app/Domain/Onchain/ValueObjects/DiscoverySessionTotals.php'
PROGRESS='app/Domain/Onchain/Services/DiscoveryScanProgress.php'
PANEL='app/Domain/Onchain/Admin/Views/DiscoveryScanPanel.php'
REPO='app/Domain/Onchain/Repositories/DiscoveryRunRepository.php'
SERVICE='app/Domain/Onchain/Services/DiscoveryRunService.php'

FILES=("$EXECUTOR" "$TOTALS" "$PROGRESS" "$PANEL" "$REPO" "$SERVICE")

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

# ── ⚠ (1) THE ORIGINAL DEFECT, RE-PLANTED ───────────────────────────────
#
# `$counts` is ONE CHUNK's telemetry. Handing it to the audit is exactly what
# wrote 41 / 9 / 0 into audit row #15 for a session that spent 1,136 requests.
N=$(frag <<'EOF'
            self::auditTerminal(
                $runId,
                $ran ? self::AUDIT_COMPLETED : self::AUDIT_FAILED,
                $totals->toAuditMeta()
            );
EOF
)
R=$(frag <<'EOF'
            self::auditTerminal(
                $runId,
                $ran ? self::AUDIT_COMPLETED : self::AUDIT_FAILED,
                $counts + ['run_uuid' => $totals->runUuid, 'chain_id' => $chainId, 'stop_reason' => $stopReason, 'status' => $totals->status, 'partial' => 0, 'audit_degraded' => 0, 'chunks_used' => $totals->chunksUsed]
            );
EOF
)
control "the terminal audit is handed the LAST CHUNK's counts" unit "$EXECUTOR" "$N" "$R"

# ── ⚠ (2) PREVIOUS CHUNKS DROPPED AT THE LEDGER ─────────────────────────
#
# `col = col + %d` is where a session's totals actually accumulate. Setting
# instead of adding leaves the row — and therefore the audit AND the panel —
# describing only the chunk that happened to be last.
N=$(frag <<'EOF'
                    next_retry_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
                    requests_used = requests_used + %d,
                    pages_fetched = pages_fetched + %d,
                    families_seen = families_seen + %d,
                    contracts_seen = contracts_seen + %d,
                    collections_emitted = collections_emitted + %d,
                    collections_denied = collections_denied + %d,
EOF
)
R=$(frag <<'EOF'
                    next_retry_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
                    requests_used = %d,
                    pages_fetched = %d,
                    families_seen = %d,
                    contracts_seen = %d,
                    collections_emitted = %d,
                    collections_denied = %d,
EOF
)
control "the chunk release overwrites instead of accumulating (previous chunks dropped)" int "$REPO" "$N" "$R"

# ── ⚠ (3) THE FINAL CHUNK COUNTED TWICE ─────────────────────────────────
#
# The row is incremented by the terminal write; a caller that also adds the
# delta by hand doubles the last chunk. Plausible-looking, and wrong in the
# direction that flatters the report.
N=$(frag <<'EOF'
        $totals = DiscoverySessionTotals::fromPersistedRow(
            DiscoveryRunRepository::findById($runId)
        );

        if ($totals === null) {
EOF
)
R=$(frag <<'EOF'
        $mutFresh = DiscoveryRunRepository::findById($runId);
        if ($mutFresh !== null) {
            foreach ($counts as $mutKey => $mutValue) {
                if (isset($mutFresh->{$mutKey})) {
                    $mutFresh->{$mutKey} = (int) $mutFresh->{$mutKey} + (int) $mutValue;
                }
            }
        }
        $totals = DiscoverySessionTotals::fromPersistedRow($mutFresh);

        if ($totals === null) {
EOF
)
control "the final chunk is added twice on top of the persisted row" unit "$EXECUTOR" "$N" "$R"

# ── ⚠ (4) CONFIRMED FAMILIES PASSED OFF AS SESSION OUTPUT ───────────────
#
# The live session confirmed FIVE families and stored TWO records. Reporting
# the chain's confirmed count as "added this session" reads perfectly and
# overstates what exists by 150%.
N=$(frag <<'EOF'
        $sessionEmitted = self::sessionEmitted($status);
EOF
)
R=$(frag <<'EOF'
        $sessionEmitted = (int) ($progress['collection_families'] ?? 0);
EOF
)
control "the chain's confirmed-family count is reported as session-emitted" int "$PANEL" "$N" "$R"

# The same substitution one layer down: the sentence itself preferring the
# chain total over what it was handed.
N=$(frag <<'EOF'
        if ($sessionEmitted === null) {
            return '';
        }
EOF
)
R=$(frag <<'EOF'
        if ($sessionEmitted === null) {
            return '';
        }
        $sessionEmitted = max($sessionEmitted, 5);
EOF
)
control "the added-records sentence inflates the count it was given" unit "$PROGRESS" "$N" "$R"

# ── ⚠ (5) THE CONTRADICTORY SENTENCE RESTORED ───────────────────────────
#
# Verbatim the line that shipped: true while discovery found nothing, and a
# flat contradiction the first time it worked.
N=$(frag <<'EOF'
        if ($found > 0) {
            return self::addedSentence($sessionEmitted) . sprintf(
                /* translators: %s: collection families confirmed overall */
                _n(
                    'Overall, %s NFT collection family is confirmed so far.',
                    'Overall, %s NFT collection families are confirmed so far.',
                    $found,
                    'bcc-trust'
                ),
                number_format_i18n($found)
            ) . ' ' . $tail;
        }
EOF
)
R=$(frag <<'EOF'
        if ($found > 0) {
            return sprintf(
                /* translators: 1: families checked, 2: total families, 3: families remaining */
                __('Pass completed. Checked %1$s of %2$s contract families. No NFT collections were confirmed in this pass. %3$s families still need review.', 'bcc-trust'),
                number_format_i18n($checked),
                number_format_i18n($total),
                number_format_i18n($remaining)
            );
        }
EOF
)
control "the contradictory 'none confirmed in this pass' sentence is restored" both "$PROGRESS" "$N" "$R"

# The panel's own zero line reverting to a claim about CONFIRMATION, which
# `collections_emitted === 0` cannot support.
N=$(frag <<'EOF'
                    ? __('This session completed successfully. It did not add a new collection record.', 'bcc-trust')
                    : __('This pass completed successfully. It did not add a new collection record.', 'bcc-trust')
EOF
)
R=$(frag <<'EOF'
                    ? __('This session completed successfully. It did not confirm a new NFT collection.', 'bcc-trust')
                    : __('This pass completed successfully. It did not confirm a new NFT collection.', 'bcc-trust')
EOF
)
control "the panel claims nothing was CONFIRMED from an emitted-row count" int "$PANEL" "$N" "$R"

# ── ⚠ (6) UNRESOLVED TREATED AS A FINAL NEGATIVE ────────────────────────
#
# A family unreachable six times is UNKNOWN, not "not an NFT". Dropping the
# third completion condition lets a chain full of unresolved families report
# a clean, final zero.
N=$(frag <<'EOF'
        if ($enumerationComplete === self::YES && $remaining === 0 && $exhausted === 0) {
EOF
)
R=$(frag <<'EOF'
        if ($enumerationComplete === self::YES && $remaining === 0) {
EOF
)
control "retry-exhausted families no longer block a clean-zero completion" both "$PROGRESS" "$N" "$R"

# …and the sentence half: the unresolved branch replaced by the final zero.
N=$(frag <<'EOF'
        if ($exhausted > 0 && $eligible === 0 && $delayed === 0) {
EOF
)
R=$(frag <<'EOF'
        if (false && $exhausted > 0 && $eligible === 0 && $delayed === 0) {
EOF
)
control "the unresolved sentence is skipped, leaving a negative-sounding report" both "$PROGRESS" "$N" "$R"

# ── ⚠ (7) A FAILED READ TURNED INTO A ZERO ──────────────────────────────
#
# "We could not tell" must never become "there is nothing". Removing the
# fail-closed guard makes a broken read render 0 of 0 with a session claim
# attached.
N=$(frag <<'EOF'
        if (($progress['ok'] ?? false) !== true || ($progress['scan_complete'] ?? self::UNKNOWN) === self::UNKNOWN) {
EOF
)
R=$(frag <<'EOF'
        if (false) {
EOF
)
control "a failed progress read falls through to completion wording" both "$PROGRESS" "$N" "$R"

# The audit half of the same rule: unconfirmable totals invented rather than
# degraded.
N=$(frag <<'EOF'
            DiscoveryRunRepository::markAuditDegraded($runId);
        } else {
EOF
)
R=$(frag <<'EOF'
            self::auditTerminal($runId, $ran ? self::AUDIT_COMPLETED : self::AUDIT_FAILED, $counts);
        } else {
EOF
)
control "unconfirmable totals are guessed from the chunk instead of degrading" unit "$EXECUTOR" "$N" "$R"

# ── ⚠ (8) THE PANEL ACQUIRES A SIDE EFFECT ──────────────────────────────
#
# Rendering is polled. A write or a schedule from a view turns every page load
# into an unauthorised advance of the scan — and the ledger hash alone cannot
# see the scheduling half, which is why both are planted.
N=$(frag <<'EOF'
        $sessionEmitted = self::sessionEmitted($status);
EOF
)
R=$(frag <<'EOF'
        $sessionEmitted = self::sessionEmitted($status);
        DiscoveryRunRepository::markAuditDegraded((int) ($status['current']['id'] ?? 0));
EOF
)
control "rendering the panel writes to the ledger" int "$PANEL" "$N" "$R"

N=$(frag <<'EOF'
        self::renderProgress($progress, $sessionEmitted);
EOF
)
R=$(frag <<'EOF'
        self::renderProgress($progress, $sessionEmitted);
        wp_schedule_single_event(
            time() + 60,
            \BCC\Trust\Onchain\Workers\DiscoveryRunExecutor::HOOK,
            [(int) ($status['current']['id'] ?? 0)]
        );
EOF
)
control "rendering the panel schedules another chunk" int "$PANEL" "$N" "$R"

# ── ⚠ (9) THE CANCELLATION AUDIT LOSES ITS SESSION ──────────────────────
#
# A withdrawal is a terminal outcome too. Reporting it without the totals
# makes a twenty-chunk session indistinguishable from one cancelled before it
# started.
N=$(frag <<'EOF'
                    $totals === null ? $meta : $meta + $totals->toAuditMeta(),
EOF
)
R=$(frag <<'EOF'
                    $meta,
EOF
)
control "a cancelled session's committed work is dropped from its audit" unit "$SERVICE" "$N" "$R"

# ── ⚠ (10) THE TOTALS STOP BEING A CONFIRMATION ─────────────────────────
#
# The type exists so an audit can only report what was PERSISTED. Reading the
# stop reason from anywhere but the row re-opens the second-source drift this
# PR closed.
N=$(frag <<'EOF'
            'stop_reason'         => $this->stopReason,
EOF
)
R=$(frag <<'EOF'
            'stop_reason'         => '',
EOF
)
control "the audit stops reporting the ledger's own stop reason" both "$TOTALS" "$N" "$R"

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
