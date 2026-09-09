#!/usr/bin/env bash
#
# PR 7.7 mutation controls — contract-list enumeration telemetry.
#
# Each control breaks ONE guarantee in executable code and asserts the suite
# goes red. A control that leaves the suite green is a SURVIVOR: the
# guarantee is unasserted, and the "passing" test that was supposed to cover
# it proves nothing.
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE. A replace that silently matches
# nothing produces a green run that looks like a killed mutant but tested an
# unmodified tree — and these files have been LF/CRLF-mixed before, which is
# exactly how a `\n` anchor no-ops in silence. Each control diffs the file
# before and after and reports BROKEN if the bytes did not move. BROKEN is
# never counted as killed.
#
# ⚠ RESTORE FROM A BYTE SNAPSHOT, NEVER FROM GIT. `git checkout -- <file>`
# restores from HEAD and once deleted six source files mid-run while printing
# cheerful "killed" lines for guarantees whose code was no longer there.
#
# ⚠ PHP `$vars` INSIDE A MUTATION ARGUMENT MUST BE WRITTEN `\$name`. The
# argument is a double-quoted BASH string and, under `set -u`, an unescaped
# `$page` aborts the run AFTER earlier controls have printed "killed".
#
# Usage: bash scripts/tests/pr77-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHPUNIT="vendor/bin/phpunit"
[ -x "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

KILLED=0
SURVIVED=0
BROKEN=0
SKIPPED=0
declare -a SURVIVOR_NAMES=()
declare -a BROKEN_NAMES=()
declare -a SKIPPED_NAMES=()

SNAPDIR="$(mktemp -d)"
trap 'rm -rf "$SNAPDIR"' EXIT

snapshot () { mkdir -p "$SNAPDIR/$(dirname "$1")"; cp "$1" "$SNAPDIR/$1"; }
restore  () { cp "$SNAPDIR/$1" "$1"; }

apply () {
  python - "$1" <<PY
import io, sys
p = sys.argv[1]
s = io.open(p, encoding='utf-8').read()
${2}
io.open(p, 'w', encoding='utf-8', newline='').write(s)
PY
}

# control <name> <file> <filter> <python-mutation>
control () {
  local name="$1" file="$2" filter="$3" mutation="$4"
  local before after

  snapshot "$file"
  before="$(md5sum "$file" | cut -d' ' -f1)"
  apply "$file" "$mutation"
  after="$(md5sum "$file" | cut -d' ' -f1)"

  if [ "$before" = "$after" ]; then
    echo "  BROKEN   $name — the mutation changed NO bytes; it tested nothing"
    BROKEN=$((BROKEN+1)); BROKEN_NAMES+=("$name")
    restore "$file"
    return
  fi

  if "$PHPUNIT" --no-coverage --filter "$filter" >/dev/null 2>&1; then
    echo "  SURVIVED $name — the suite stayed green with the guarantee broken"
    SURVIVED=$((SURVIVED+1)); SURVIVOR_NAMES+=("$name")
  else
    echo "  killed   $name"
    KILLED=$((KILLED+1))
  fi
  restore "$file"
}

# Same contract, against the REAL-MySQL suite.
#
# ⚠ REPORTED AS SKIPPED, NEVER AS KILLED, when no DB is configured. PR 7.6
# had a "survivor" that was a harness artefact: a DB-backed control ran under
# the UNIT config, so its only covering test never loaded.
control_integration () {
  local name="$1" file="$2" filter="$3" mutation="$4"
  local before after

  if [ -z "${BCC_INT_PHP:-}" ]; then
    echo "  SKIPPED  $name — no BCC_INT_PHP; the integration suite did not run"
    SKIPPED=$((SKIPPED+1)); SKIPPED_NAMES+=("$name")
    return
  fi

  snapshot "$file"
  before="$(md5sum "$file" | cut -d' ' -f1)"
  apply "$file" "$mutation"
  after="$(md5sum "$file" | cut -d' ' -f1)"

  if [ "$before" = "$after" ]; then
    echo "  BROKEN   $name — the mutation changed NO bytes; it tested nothing"
    BROKEN=$((BROKEN+1)); BROKEN_NAMES+=("$name")
    restore "$file"
    return
  fi

  if "$BCC_INT_PHP" vendor/phpunit/phpunit/phpunit -c phpunit-integration.xml.dist \
       --no-coverage --filter "$filter" >/dev/null 2>&1; then
    echo "  SURVIVED $name — the suite stayed green with the guarantee broken"
    SURVIVED=$((SURVIVED+1)); SURVIVOR_NAMES+=("$name")
  else
    echo "  killed   $name"
    KILLED=$((KILLED+1))
  fi
  restore "$file"
}

SERVICE="app/Domain/Onchain/Services/CosmwasmDiscoveryService.php"
FAILURE="app/Domain/Onchain/ValueObjects/CosmwasmEnumerationFailure.php"
CHECKPOINT="app/Domain/Onchain/Repositories/ChainCheckpointRepository.php"

UNIT="ContractListTelemetryTest|ContractListTelemetryWiringTest"

echo "PR 7.7 mutation controls"
echo "────────────────────────────────────────────────────────"

# ── 1. THE HEADLINE: the telemetry call is disabled ─────────────────────
# This is the defect PR 7.7 exists to fix, re-introduced. If it survives,
# nothing in the suite actually observes the recording happening.
control "contract-list telemetry call disabled" "$SERVICE" "$UNIT" \
  "s = s.replace(\"        if (!CosmwasmEnumerationFailure::isProviderFault(\$errorKind, \$httpCode)) {\n            return \$page;\", \"        if (true) {\n            return \$page;\")"

# ── 2. …and the same defect via a deleted call ──────────────────────────
control "recordCwEnumerationFailure() removed from the seam" "$SERVICE" "$UNIT" \
  "s = s.replace(\"        ChainCheckpointRepository::recordCwEnumerationFailure(\n            \$chainId,\n            CosmwasmEnumerationFailure::fromResult(\$errorKind, \$httpCode)\n        );\", \"        \$noop = \$errorKind . \$httpCode;\")"

# ── 3. Recovery never clears ────────────────────────────────────────────
control "successful listing no longer retires the token" "$SERVICE" "$UNIT" \
  "s = s.replace(\"            ChainCheckpointRepository::clearCwEnumerationFailure(\$chainId);\", \"            \$chainId = \$chainId;\")"

# ── 4. The failure path ALSO clears — the column goes NULL again ────────
control "failure path clears as well as records" "$SERVICE" "$UNIT" \
  "s = s.replace(\"        ChainCheckpointRepository::recordCwEnumerationFailure(\n            \$chainId,\", \"        ChainCheckpointRepository::clearCwEnumerationFailure(\$chainId);\n        ChainCheckpointRepository::recordCwEnumerationFailure(\n            \$chainId,\")"

# ── 5. A second breaker charge smuggled into the seam ───────────────────
# Retry accounting is MEASURED by this PR, never adjusted.
control "seam charges the breaker a second time" "$SERVICE" "$UNIT" \
  "s = s.replace(\"        ChainCheckpointRepository::recordCwEnumerationFailure(\", \"        \\\\BCC\\\\Trust\\\\Onchain\\\\Support\\\\OnchainCircuitBreaker::recordFailure(\$chainId);\n        ChainCheckpointRepository::recordCwEnumerationFailure(\")"

# ── 6-9. Each call site individually routed back around the seam ────────
control "classification sample bypasses the seam" "$SERVICE" "$UNIT" \
  "s = s.replace(\"\$page = self::contractsPage(\$chainId, \$fetcher, \$codeId, null, false);\", \"\$page = \$fetcher->listContractsForCodeId(\$codeId, null, false);\")"

control "forward walk bypasses the seam" "$SERVICE" "$UNIT" \
  "s = s.replace(\"\$page = self::contractsPage(\$chainId, \$fetcher, \$codeId, \$cursor, false);\", \"\$page = \$fetcher->listContractsForCodeId(\$codeId, \$cursor, false);\")"

control "reverse tail bypasses the seam" "$SERVICE" "$UNIT" \
  "s = s.replace(\"\$response = self::contractsPage(\$chainId, \$fetcher, \$codeId, \$pageKey, \$pageKey === null);\", \"\$response = \$fetcher->listContractsForCodeId(\$codeId, \$pageKey, \$pageKey === null);\")"

# ── 10-13. The gate, narrowed one outcome at a time ─────────────────────
# Each of these leaves a breaker-charging failure unrecorded — the exact
# invariant ContractListTelemetryTest measures against the real transport.
control "gate no longer treats 5xx as a fault" "$FAILURE" "$UNIT" \
  "s = s.replace(\"        if (\$httpCode === 429 || \$httpCode >= 500) {\", \"        if (\$httpCode === 429) {\")"

control "gate no longer treats 429 as a fault" "$FAILURE" "$UNIT" \
  "s = s.replace(\"        if (\$httpCode === 429 || \$httpCode >= 500) {\", \"        if (\$httpCode >= 500) {\")"

control "gate no longer treats a wire failure as a fault" "$FAILURE" "$UNIT" \
  "s = s.replace(\"        if (\$httpCode === 0 && \$errorKind === CosmwasmClassifier::KIND_TRANSPORT) {\n            return true;\n        }\", \"        if (false) {\n            return true;\n        }\")"

control "gate no longer treats an unreadable 200 as a fault" "$FAILURE" "$UNIT" \
  "s = s.replace(\"        return \$httpCode === 200 && \$errorKind === CosmwasmClassifier::KIND_MALFORMED;\", \"        return false;\")"

# ── 14-15. The gate, widened — a supported answer mislabelled ───────────
control "gate calls every failure a provider fault" "$FAILURE" "$UNIT" \
  "s = s.replace(\"        if (\$httpCode === 429 || \$httpCode >= 500) {\n            return true;\n        }\", \"        return true;\n        if (false) {\n            return true;\n        }\")"

control "gate mislabels a non-429 4xx" "$FAILURE" "$UNIT" \
  "s = s.replace(\"        return \$httpCode === 200 && \$errorKind === CosmwasmClassifier::KIND_MALFORMED;\", \"        return \$httpCode >= 400 || (\$httpCode === 200 && \$errorKind === CosmwasmClassifier::KIND_MALFORMED);\")"

# ── 16. The 501 exclusion I nearly shipped ──────────────────────────────
# A 501 is a 5xx: ApiRetry charges for it four times. Excluding it here
# would leave a breaker-charging failure with cw_last_error NULL.
control "501 excluded from the gate" "$FAILURE" "$UNIT" \
  "s = s.replace(\"        if (\$httpCode === 429 || \$httpCode >= 500) {\", \"        if (\$httpCode === 501) {\n            return false;\n        }\n        if (\$httpCode === 429 || \$httpCode >= 500) {\")"

# ── 17. The column stops refusing prose ─────────────────────────────────
control_integration "cw_last_error write stops validating the token" "$CHECKPOINT" \
  "MixedEvidencePersistenceIntegrationTest" \
  "s = s.replace(\"        if (\$chainId <= 0 || !CosmwasmEnumerationFailure::isValid(\$code)) {\", \"        if (\$chainId <= 0) {\")"

# ── 18. The clear stops being one column ────────────────────────────────
control_integration "clear also moves the discovery state machine" "$CHECKPOINT" \
  "MixedEvidencePersistenceIntegrationTest" \
  "s = s.replace(\"            ['cw_last_error' => null],\n            ['chain_id' => \$chainId],\n            ['%s'],\", \"            ['cw_last_error' => null, 'cw_discovery_state' => self::CW_STATE_IDLE],\n            ['chain_id' => \$chainId],\n            ['%s', '%s'],\")"

# ── 19. The clear stops refusing an invalid chain id ────────────────────
control_integration "clear accepts a non-positive chain id" "$CHECKPOINT" \
  "MixedEvidencePersistenceIntegrationTest" \
  "s = s.replace(\"    public static function clearCwEnumerationFailure(int \$chainId): bool\n    {\n        if (\$chainId <= 0) {\n            return false;\n        }\", \"    public static function clearCwEnumerationFailure(int \$chainId): bool\n    {\n        if (\$chainId < -999999) {\n            return false;\n        }\")"

echo "────────────────────────────────────────────────────────"
echo "killed=$KILLED survived=$SURVIVED broken=$BROKEN skipped=$SKIPPED"

# ⚠ ASSERT THE TREE IS BYTE-IDENTICAL. A restore that silently failed would
# leave a mutation in the working copy and the next run would test it.
DIRTY="$(git diff --name-only -- "$SERVICE" "$FAILURE" "$CHECKPOINT")"
if [ -n "$DIRTY" ]; then
  echo "FATAL: files not restored to their pre-run bytes:"
  echo "$DIRTY"
  exit 2
fi
echo "all mutated files restored byte-identical"

if [ "$SURVIVED" -ne 0 ]; then
  echo "SURVIVORS:"; printf '  - %s\n' "${SURVIVOR_NAMES[@]}"; exit 1
fi
if [ "$BROKEN" -ne 0 ]; then
  echo "BROKEN:"; printf '  - %s\n' "${BROKEN_NAMES[@]}"; exit 1
fi
if [ "$SKIPPED" -ne 0 ]; then
  echo "SKIPPED (not proof of anything — rerun with BCC_INT_PHP set):"
  printf '  - %s\n' "${SKIPPED_NAMES[@]}"; exit 1
fi
echo "ALL CONTROLS KILLED"
