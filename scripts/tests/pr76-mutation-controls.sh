#!/usr/bin/env bash
#
# PR 7.6 mutation controls.
#
# Each control breaks ONE guarantee in executable code and asserts the suite
# goes red. A control that leaves the suite green is a SURVIVOR: the
# guarantee is unasserted, and the "passing" test that was supposed to cover
# it proves nothing.
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE. A sed that silently matches
# nothing produces a green run that looks like a killed mutant but tested an
# unmodified tree. Each control diffs the file before and after and reports
# BROKEN if the bytes did not move. BROKEN is never counted as killed.
#
# ⚠ MUTATIONS ARE APPLIED TO COMMENT-STRIPPED-EQUIVALENT TARGETS ONLY.
# Every pattern below targets a line of real code; none of them can be
# satisfied by matching a docblock, because a docblock edit changes bytes
# without changing behaviour and would produce a false BROKEN-vs-killed
# reading.
#
# Usage: bash scripts/tests/pr76-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2
ROOT="$(pwd)"

PHPUNIT="vendor/bin/phpunit"
[ -x "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

KILLED=0
SURVIVED=0
BROKEN=0
declare -a SURVIVOR_NAMES=()
declare -a BROKEN_NAMES=()

restore () { git checkout -- "$1"; }

# control <name> <file> <filter> <python-mutation>
control () {
  local name="$1" file="$2" filter="$3" mutation="$4"
  local before after

  before="$(md5sum "$file" | cut -d' ' -f1)"
  python - "$file" <<PY
import io, sys
p = sys.argv[1]
s = io.open(p, encoding='utf-8').read()
${mutation}
io.open(p, 'w', encoding='utf-8', newline='').write(s)
PY
  after="$(md5sum "$file" | cut -d' ' -f1)"

  if [ "$before" = "$after" ]; then
    echo "  BROKEN   $name — the mutation changed NO bytes; it tested nothing"
    BROKEN=$((BROKEN+1)); BROKEN_NAMES+=("$name")
    restore "$file"
    return
  fi

  if "$PHPUNIT" --filter "$filter" >/dev/null 2>&1; then
    echo "  SURVIVED $name — the suite stayed green with the guarantee broken"
    SURVIVED=$((SURVIVED+1)); SURVIVOR_NAMES+=("$name")
  else
    echo "  killed   $name"
    KILLED=$((KILLED+1))
  fi
  restore "$file"
}

CLASSIFIER="app/Domain/Onchain/Services/CosmwasmClassifier.php"
BREAKER="app/Domain/Onchain/Support/OnchainCircuitBreaker.php"
WORKER="app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php"
STOPREASON="app/Domain/Onchain/Support/CosmwasmPassStopReason.php"
RETRY="app/Domain/Onchain/Support/ApiRetry.php"
CHECKPOINT="app/Domain/Onchain/Repositories/ChainCheckpointRepository.php"
FETCHER="app/Domain/Onchain/Fetchers/CosmosFetcher.php"

echo "PR 7.6 mutation controls"
echo "────────────────────────────────────────────────────────"

# ── 1. Classification: ambiguity blamed on the node again ───────────────
control "ambiguous evidence restored to node_unreachable" "$CLASSIFIER" \
  "MixedEvidenceClassificationTest|MixedEvidencePersistenceIntegrationTest" \
  "s = s.replace(\"\$ambiguousOnly = !\$nodeCaused && self::anyDecisiveRefusal(\$outcomes);\", \"\$ambiguousOnly = false;\")"

# ── 2. Classification: mixed evidence promoted to a terminal negative ───
control "mixed evidence converted to not_cw721" "$CLASSIFIER" \
  "MixedEvidenceClassificationTest" \
  "s = s.replace(\"                \$ambiguousOnly\n                    ? self::REASON_MIXED_EVIDENCE_AMBIGUOUS\n                    : self::REASON_NODE_UNREACHABLE,\", \"                self::REASON_NODE_UNREACHABLE,\")"

# ── 3. Classification: 4xx/malformed treated as a node fault ────────────
control "http_4xx and malformed become node-caused faults" "$CLASSIFIER" \
  "MixedEvidenceClassificationTest" \
  "s = s.replace(\"return \$kind === self::KIND_NODE_ERROR || \$kind === self::KIND_TRANSPORT;\", \"return self::isTransientFault(\$kind);\")"

# ── 4. Fetcher: a recognised contract rejection blamed on the provider ──
control "recognised unknown-variant 500 treated as a provider failure" "$FETCHER" \
  "CosmwasmSmartQueryRetryTest|ApiRetryApplicationErrorTest" \
  "s = s.replace(\"        if (\$smartQuery) {\", \"        if (false) {\")"

# ── 5. Telemetry: the enumeration failure code is dropped ───────────────
control "enumeration telemetry dropped on the tail path" "$WORKER" \
  "EnumerationTelemetryTest|MixedEvidencePersistenceIntegrationTest" \
  "s = s.replace(\"            ChainCheckpointRepository::recordCwEnumerationFailure(\", \"            false && ChainCheckpointRepository::recordCwEnumerationFailure(\")"

# ── 6. Telemetry: a raw provider body is persisted instead of a token ───
control "raw provider prose persisted into cw_last_error" "$CHECKPOINT" \
  "MixedEvidencePersistenceIntegrationTest|EnumerationTelemetryTest" \
  "s = s.replace(\"        if (\$chainId <= 0 || !CosmwasmEnumerationFailure::isValid(\$code)) {\n            return false;\n        }\", \"        if (\$chainId <= 0) {\n            return false;\n        }\")"

# ── 7. Breaker: the threshold is raised out of reach ────────────────────
control "failure threshold 5 becomes 50" "$BREAKER" \
  "BreakerLifecycleTest" \
  "s = s.replace(\"const FAILURE_THRESHOLD = 5;\", \"const FAILURE_THRESHOLD = 50;\")"

# ── 8. Breaker: a success no longer clears the durable counter ──────────
control "success stops resetting the failure counter" "$BREAKER" \
  "BreakerLifecycleTest" \
  "s = s.replace(\"        OnchainCircuitBreakerRepository::deleteCounter(self::counterOptionName(\$chainId));\n\n        // Release the probe lock\", \"        // deleteCounter removed by mutation\n\n        // Release the probe lock\")"

# ── 9. Breaker: a stale counter is trusted again ────────────────────────
control "stale counter reopens the breaker instantly" "$BREAKER" \
  "BreakerLifecycleTest" \
  "s = s.replace(\"        \$priorState = self::getState(\$chainId);\n        if (\$priorState === null) {\n            OnchainCircuitBreakerRepository::deleteCounter(\$counterOption);\n        }\", \"        \$priorState = self::getState(\$chainId);\")"

# ── 10. Breaker: the half-open probe lock is bypassed ───────────────────
control "half-open admits every caller (lock bypassed)" "$BREAKER" \
  "BreakerLifecycleTest" \
  "s = s.replace(\"if (\\\\BCC\\\\Core\\\\DB\\\\AdvisoryLock::acquire(self::PROBE_LOCK_PREFIX . \$chainId, 0)) {\", \"if (true) {\")"

# ── 11. Operator truth: the circuit reason collapses back ───────────────
control "circuit-open collapses back to chain_refused_to_prepare" "$STOPREASON" \
  "CircuitOpenReasonTest|CosmwasmOneShotCliTest" \
  "s = s.replace(\"            return self::PROVIDER_CIRCUIT_OPEN;\", \"            return self::CHAIN_REFUSED_TO_PREPARE;\")"

# ── 12. Worker: the breaker refusal stops being distinguishable ─────────
control "prepareChain stops reporting the circuit refusal" "$WORKER" \
  "CircuitOpenReasonTest|BreakerStopsProviderWorkTest" \
  "s = s.replace(\"            \$refusal = self::PASS_CIRCUIT_OPEN;\", \"            \$refusal = null;\")"

# ── 13. Retry accounting: the multiplier changes ────────────────────────
control "retry accounting changed (max retries 3 -> 0)" "$RETRY" \
  "BreakerRetryAccountingTest" \
  "s = s.replace(\"const DEFAULT_MAX_RETRIES   = 3;\", \"const DEFAULT_MAX_RETRIES   = 0;\")"

echo "────────────────────────────────────────────────────────"
echo "killed=${KILLED}  survived=${SURVIVED}  broken=${BROKEN}"
if [ "${#SURVIVOR_NAMES[@]}" -gt 0 ]; then
  echo "SURVIVORS (unasserted guarantees):"
  printf '  - %s\n' "${SURVIVOR_NAMES[@]}"
fi
if [ "${#BROKEN_NAMES[@]}" -gt 0 ]; then
  echo "BROKEN (mutation changed no code — NOT counted as killed):"
  printf '  - %s\n' "${BROKEN_NAMES[@]}"
fi

# Fail on anything that is not a clean kill.
[ "$SURVIVED" -eq 0 ] && [ "$BROKEN" -eq 0 ]
