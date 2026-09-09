#!/usr/bin/env bash
#
# PR 7.8 mutation controls — centralized breaker failure attribution.
#
# Each control breaks ONE guarantee in executable code and asserts the suite
# goes red. A control that leaves the suite green is a SURVIVOR: the
# guarantee is unasserted.
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE. A replace that matches nothing
# produces a green run that looks like a killed mutant but tested an
# unmodified tree. Each control diffs the file before and after and reports
# BROKEN if the bytes did not move. BROKEN is never counted as killed.
#
# ⚠ RESTORE FROM A BYTE SNAPSHOT, NEVER FROM GIT. `git checkout -- <file>`
# restores from HEAD and once deleted six source files mid-run while printing
# cheerful "killed" lines.
#
# ⚠ PHP `$vars` INSIDE A MUTATION ARGUMENT MUST BE WRITTEN `\$name` — the
# argument is a double-quoted BASH string and under `set -u` an unescaped
# `$kind` aborts the run AFTER earlier controls have printed "killed".
#
# Usage: bash scripts/tests/pr78-mutation-controls.sh
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

# ⚠⚠ SNAPSHOT ONCE, FOR THE WHOLE RUN — NEVER PER CONTROL.
#
# The first version of this script snapshotted inside control(). When one
# restore silently failed, the NEXT control snapshotted the already-mutated
# file, "restored" to that mutated state, and every control after it ran
# against a progressively corrupted tree. The run reported 17 killed, 1
# survived and 2 BROKEN — and all three of those verdicts were fiction,
# because two of the "BROKEN" mutations had in fact changed bytes and were
# still applied when the run ended.
#
# A pristine snapshot taken before any mutation cannot be poisoned by a
# failed restore, and verify_clean() below turns a failed restore into an
# immediate abort instead of a corrupted rest-of-run.
snapshot_all () {
  local f
  for f in "$@"; do
    mkdir -p "$SNAPDIR/$(dirname "$f")"
    cp "$f" "$SNAPDIR/$f" || { echo "FATAL: cannot snapshot $f"; exit 2; }
  done
}
restore () { cp "$SNAPDIR/$1" "$1"; }

# ⚠ A RESTORE THAT DID NOT RESTORE MUST STOP THE RUN, not be discovered at
# the end. Compares bytes against the pristine snapshot.
verify_clean () {
  local f="$1" name="$2"
  if ! cmp -s "$SNAPDIR/$f" "$f"; then
    # One retry, then abort — a transient copy failure is recoverable, a
    # persistent one must never be papered over.
    restore "$f"
    if ! cmp -s "$SNAPDIR/$f" "$f"; then
      echo "FATAL: $f was NOT restored after control '$name'."
      echo "       Every later control would have tested a corrupted tree."
      echo "       Recover with: git checkout -- $f"
      exit 2
    fi
    echo "  note     restore of $f needed a retry after '$name'"
  fi
}

apply () {
  python - "$1" <<PY || { echo "FATAL: mutation script errored"; exit 2; }
import io, sys
p = sys.argv[1]
s = io.open(p, encoding='utf-8').read()
${2}
io.open(p, 'w', encoding='utf-8', newline='').write(s)
PY
}

control () {
  local name="$1" file="$2" filter="$3" mutation="$4"
  local before after

  before="$(md5sum "$file" | cut -d' ' -f1)"
  apply "$file" "$mutation"
  after="$(md5sum "$file" | cut -d' ' -f1)"

  if [ "$before" = "$after" ]; then
    echo "  BROKEN   $name — the mutation changed NO bytes; it tested nothing"
    BROKEN=$((BROKEN+1)); BROKEN_NAMES+=("$name")
    restore "$file"; verify_clean "$file" "$name"
    return
  fi

  if "$PHPUNIT" --no-coverage --filter "$filter" >/dev/null 2>&1; then
    echo "  SURVIVED $name — the suite stayed green with the guarantee broken"
    SURVIVED=$((SURVIVED+1)); SURVIVOR_NAMES+=("$name")
  else
    echo "  killed   $name"
    KILLED=$((KILLED+1))
  fi
  restore "$file"; verify_clean "$file" "$name"
}

BREAKER="app/Domain/Onchain/Support/OnchainCircuitBreaker.php"
RETRY="app/Domain/Onchain/Support/ApiRetry.php"
KIND="app/Domain/Onchain/ValueObjects/ProviderFailureKind.php"
CLASSVO="app/Domain/Onchain/ValueObjects/ProviderRequestClass.php"
SETTINGS="app/Domain/Onchain/Admin/SettingsPage.php"

UNIT="BreakerAttributionTest|BreakerLifecycleTest|BreakerRetryAccountingTest|ApiRetryApplicationErrorTest"
ADMIN="BreakerAdminObservationTest"

# ⚠ THE TREE MUST BE CLEAN BEFORE THE FIRST MUTATION, or the snapshot itself
# captures someone else's edit and "restored byte-identical" means nothing.
if [ -n "$(git status --porcelain -- "$BREAKER" "$RETRY" "$KIND" "$CLASSVO" "$SETTINGS")" ]; then
  echo "FATAL: mutated files are already dirty. Commit or restore them first —"
  echo "       a snapshot of an edited tree cannot prove anything."
  exit 2
fi

snapshot_all "$BREAKER" "$RETRY" "$KIND" "$CLASSVO" "$SETTINGS"

echo "PR 7.8 mutation controls"
echo "────────────────────────────────────────────────────────"

# ── 1-3. Each ApiRetry charge site stops attributing ────────────────────
control "429 charge no longer attributed" "$RETRY" "$UNIT" \
  "s = s.replace(\"                            ProviderFailureKind::RATE_LIMITED,\n\", \"\")"

control "5xx charge no longer attributed" "$RETRY" "$UNIT" \
  "s = s.replace(\"                            ProviderFailureKind::HTTP_5XX,\n\", \"\")"

control "transport charge no longer attributed" "$RETRY" "$UNIT" \
  "s = s.replace(\"                    ProviderFailureKind::TRANSPORT,\n                    \$requestClass\n\", \"\")"

# ── 4. The kinds are swapped — attribution present but WRONG ────────────
control "429 mislabelled as http_5xx" "$RETRY" "$UNIT" \
  "s = s.replace(\"ProviderFailureKind::RATE_LIMITED,\", \"ProviderFailureKind::HTTP_5XX,\")"

control "transport mislabelled as rate_limited" "$RETRY" "$UNIT" \
  "s = s.replace(\"ProviderFailureKind::TRANSPORT,\n                    \$requestClass\", \"ProviderFailureKind::RATE_LIMITED,\n                    \$requestClass\")"

# ── 5. The request class is faked instead of derived ────────────────────
control "request class hard-coded instead of derived" "$RETRY" "$UNIT" \
  "s = s.replace(\"\$requestClass = ProviderRequestClass::fromOptions(\$options);\", \"\$requestClass = ProviderRequestClass::SMART_QUERY;\")"

control "smart query no longer detected from the opt-in" "$CLASSVO" "$UNIT" \
  "s = s.replace(\"        \$isSmartQuery = isset(\$options['application_error'])\n            && is_callable(\$options['application_error']);\", \"        \$isSmartQuery = false;\")"

# ── 6-7. THE STORE: attribution dropped or never persisted ──────────────
control "attribution never written to state" "$BREAKER" "$UNIT" \
  "s = s.replace(\"            'kind'          => \$kind ?? \$priorKind,\n            'request_class' => \$requestClass ?? \$priorClass,\n\", \"\")"

control "attribution dropped on read" "$BREAKER" "$UNIT" \
  "s = s.replace(\"            'kind'          => \$kind,\n            'request_class' => \$requestClass,\n\", \"\")"

# ── 8. Validation removed — prose could reach durable state ─────────────
control "recordFailure stops validating the kind" "$BREAKER" "$UNIT" \
  "s = s.replace(\"        if (\$kind !== null && !ProviderFailureKind::isValid(\$kind)) {\n            \$kind = null;\n        }\n\", \"\")"

control "getState stops re-validating the kind" "$BREAKER" "$UNIT" \
  "s = s.replace(\"        \$kind = isset(\$value['kind']) && is_string(\$value['kind'])\n            && ProviderFailureKind::isValid(\$value['kind'])\n                ? \$value['kind']\n                : null;\", \"        \$kind = \$value['kind'] ?? null;\")"

# ── 9. Recovery stops clearing the attribution ──────────────────────────
#
# ⚠ THE OBVIOUS MUTATION HERE IS AN EQUIVALENT MUTANT AND WAS REPLACED.
# Simply DELETING the two `null` lines from recordSuccess() cannot fail:
# setState() REPLACES the whole state array rather than merging it, so an
# absent `kind` key reads back as null exactly as an explicit null does.
# Measured: after that deletion a success still leaves attribution NULL.
#
# The real regression is the opposite shape — a recovered chain that keeps
# displaying the reason it was last broken — so the mutation carries the
# prior attribution FORWARD through the success instead.
control "success carries the stale attribution forward" "$BREAKER" "$UNIT" \
  "s = s.replace(\"                'kind'          => null,\n                'request_class' => null,\", \"                'kind'          => \$state['kind'] ?? null,\n                'request_class' => \$state['request_class'] ?? null,\")"

# ── 10-11. The vocabulary is widened or narrowed ────────────────────────
control "a fourth token added to the breaker vocabulary" "$KIND" "$UNIT" \
  "s = s.replace(\"            self::TRANSPORT,\n        ];\", \"            self::TRANSPORT,\n            'timeout',\n        ];\")"

control "isValid accepts anything" "$KIND" "$UNIT" \
  "s = s.replace(\"        return in_array(\$kind, self::all(), true);\", \"        return \$kind !== '';\")"

# ── 12. The mapper names a non-charging outcome ─────────────────────────
control "mapper names a non-charging 4xx" "$KIND" "$UNIT" \
  "s = s.replace(\"        if (\$httpStatus >= 500) {\n            return self::HTTP_5XX;\n        }\n\n        return null;\", \"        if (\$httpStatus >= 400) {\n            return self::HTTP_5XX;\n        }\n\n        return null;\")"

# ── 13. Accounting silently changed (must NOT happen in this PR) ────────
control "retry count changed under the attribution work" "$RETRY" "$UNIT" \
  "s = s.replace(\"    const DEFAULT_MAX_RETRIES   = 3;\", \"    const DEFAULT_MAX_RETRIES   = 1;\")"

control "429 made retryable — accounting drift" "$RETRY" "$UNIT" \
  "s = s.replace(\"                    // Do NOT sleep — return immediately and let the caller\", \"                    if (\$attempt < \$maxRetries) { \$attempt++; continue; }\n                    // Do NOT sleep — return immediately and let the caller\")"

# ── 14. The application-error escape hatch is removed ───────────────────
control "application errors start charging the breaker" "$RETRY" "$UNIT" \
  "s = s.replace(\"                    if (\$isApplicationError !== null\n                        && \$isApplicationError((string) wp_remote_retrieve_body(\$lastResponse), \$code)) {\", \"                    if (false) {\")"

# ── 15. The two vocabularies drift apart ────────────────────────────────
control "shared token renamed, drifting from the enumeration vocabulary" "$KIND" "$UNIT" \
  "s = s.replace(\"    public const HTTP_5XX = 'http_5xx';\", \"    public const HTTP_5XX = 'http5xx';\")"

# ── 16. The phase readers stop agreeing ─────────────────────────────────
control "getAllStatus recomputes its own phase again" "$BREAKER" "$UNIT" \
  "s = s.replace(\"            \$status = self::phaseHyphenated(self::phaseFor(\$state, \$now));\", \"            \$status = (\$now - \$openedAt) >= self::COOLDOWN_SECONDS ? 'half-open' : 'open';\")"

# ── 17-19. STATUS PAGES MUST NOT CONSUME THE HALF-OPEN PROBE ────────────
#
# `isOpen()` claims the probe lock in the half-open window. A status page
# that calls it steals the slot from the worker waiting for it — invisibly,
# because nobody suspects a dashboard of causing an outage.
control "admin page reverts to isOpen()" "$SETTINGS" "$ADMIN" \
  "s = s.replace(\"        \$status = \$chainIds === []\n            ? []\n            : \\\\BCC\\\\Trust\\\\Onchain\\\\Support\\\\OnchainCircuitBreaker::getAllStatus(\$chainIds);\", \"        \$status = [];\n        foreach (\$chainIds as \$__c) { \\\\BCC\\\\Trust\\\\Onchain\\\\Support\\\\OnchainCircuitBreaker::isOpen(\$__c); }\")"

control "admin page renders the raw token instead of a label" "$SETTINGS" "$ADMIN" \
  "s = s.replace(\"                        \$reasonLabel = \\\\BCC\\\\Trust\\\\Onchain\\\\ValueObjects\\\\ProviderFailureKind::label(\", \"                        \$reasonLabel = (string) (\")"

# The probe must admit exactly ONE claimant.
control "half-open admits every claimant (probe lock bypassed)" "$BREAKER" "$ADMIN|$UNIT" \
  "s = s.replace(\"        if (\\\\BCC\\\\Core\\\\DB\\\\AdvisoryLock::acquire(self::PROBE_LOCK_PREFIX . \$chainId, 0)) {\n            return false; // We own the probe → allow the request through.\n        }\", \"        if (true) {\n            return false;\n        }\")"

echo "────────────────────────────────────────────────────────"
echo "killed=$KILLED survived=$SURVIVED broken=$BROKEN skipped=$SKIPPED"

DIRTY="$(git diff --name-only -- "$BREAKER" "$RETRY" "$KIND" "$CLASSVO" "$SETTINGS")"
if [ -n "$DIRTY" ]; then
  echo "FATAL: files not restored to their pre-run bytes:"; echo "$DIRTY"; exit 2
fi
echo "all mutated files restored byte-identical"

if [ "$SURVIVED" -ne 0 ]; then echo "SURVIVORS:"; printf '  - %s\n' "${SURVIVOR_NAMES[@]}"; exit 1; fi
if [ "$BROKEN"   -ne 0 ]; then echo "BROKEN:";    printf '  - %s\n' "${BROKEN_NAMES[@]}";    exit 1; fi
echo "ALL CONTROLS KILLED"
