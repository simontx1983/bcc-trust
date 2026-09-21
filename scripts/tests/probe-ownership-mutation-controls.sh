#!/usr/bin/env bash
#
# Mutation controls for "half-open recovery-probe ownership" (issue #264).
#
# Each control breaks ONE guarantee in executable code and runs the test NAMED for
# that guarantee. Verdicts:
#
#   killed        the named test failed with the guarantee broken
#   survived      everything stayed green with the guarantee broken
#   broken        the mutation changed no bytes (it tested nothing)
#
# ⚠ THE LOCK MUTANTS ARE KILLED AGAINST A REAL DATABASE. `GET_LOCK` is reentrant
#   per session and needs matching releases; no reasonable test double reproduces
#   that, which is exactly why the leak survived until now. Those controls run the
#   integration suite and need BCC_TEST_DB_* pointing at a disposable server.
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE (byte diff before/after).
# ⚠ RESTORE FROM A BYTE SNAPSHOT TAKEN ONCE, NEVER FROM GIT, and abort on a failed
#   restore — `git checkout --` once deleted six files mid-run in an earlier PR.
#
# Usage: BCC_TEST_DB_PORT=33801 bash scripts/tests/probe-ownership-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d extension=mysqli -d memory_limit=2G}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

BREAKER="app/Domain/Onchain/Support/OnchainCircuitBreaker.php"
RETRY="app/Domain/Onchain/Support/ApiRetry.php"
WORKER="app/Domain/Onchain/Workers/NftEthIndexerWorker.php"
REFRESH="app/Domain/Onchain/Services/ChainRefreshService.php"
SCHED="app/Domain/Onchain/Services/EnrichmentScheduler.php"
CWWORKER="app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php"

SNAPDIR="$(mktemp -d)"
for f in "$BREAKER" "$RETRY" "$WORKER" "$REFRESH" "$SCHED" "$CWWORKER"; do
    cp "$f" "$SNAPDIR/$(basename "$f").orig" || { echo "FATAL: snapshot failed for $f"; exit 2; }
done

restore () {
    local f="$1"
    cp "$SNAPDIR/$(basename "$f").orig" "$f" || { echo "FATAL: RESTORE FAILED for $f — ABORTING"; exit 3; }
    if ! cmp -s "$SNAPDIR/$(basename "$f").orig" "$f"; then
        echo "FATAL: $f differs from its snapshot after restore — ABORTING"
        exit 3
    fi
}

killed=0; survived=0; broken=0

# mutate <file> <php-replacement> <suite: unit|integration> <named-test-filter> <label>
mutate () {
    local file="$1" replacement="$2" suite="$3" filter="$4" label="$5"
    local before after cfg

    if [ "$suite" = "integration" ]; then cfg="phpunit-integration.xml.dist"; else cfg="phpunit.xml.dist"; fi

    before="$($PHP -r 'echo md5_file($argv[1]);' "$file")"
    if ! $PHP -r "$replacement" "$file"; then
        echo "  broken       $label (mutation script failed — the control tested nothing)"
        broken=$((broken + 1)); restore "$file"; return
    fi
    after="$($PHP -r 'echo md5_file($argv[1]);' "$file")"
    if [ "$before" = "$after" ]; then
        echo "  broken       $label (no bytes changed — the control tested nothing)"
        broken=$((broken + 1)); restore "$file"; return
    fi

    local out rc
    out="$($PHP "$PHPUNIT" -c "$cfg" --no-coverage --filter "$filter" 2>&1)"
    rc=$?
    restore "$file"

    if [ $rc -ne 0 ]; then
        echo "  killed       $label  [$filter]"
        killed=$((killed + 1))
    else
        echo "  survived     $label  [$filter]"
        echo "$out" | tail -3 | sed 's/^/                 /'
        survived=$((survived + 1))
    fi
}

echo "── half-open probe ownership mutation controls (issue #264) ──────────────"

# ── 1. restore a MUTATING outer preflight, one caller at a time ─────────────
#    Each is the original defect: claim the probe before knowing whether this
#    worker will contact a provider at all.
for spec in \
  "$WORKER|M1a EVM indexer preflight claims the probe again" \
  "$REFRESH|M1b validator-index preflight claims the probe again" \
  "$SCHED|M1c enrichment scheduler preflight claims the probe again" \
  "$CWWORKER|M1d frozen cosmwasm preflight claims the probe again" ; do
    f="${spec%%|*}"; label="${spec#*|}"
    mutate "$f" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "OnchainCircuitBreaker::isResting(";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, "OnchainCircuitBreaker::isOpen(", $s));
' unit 'HalfOpenProbeOwnershipTest' "$label"
done

# ── 2. make the "non-mutating" reader claim after all ───────────────────────
#    The subtlest regression: the call sites still read correctly, but the
#    reader grew a side effect. Killed against a REAL lock.
mutate "$BREAKER" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return self::phaseFor(self::getState(\$chainId), time()) === self::PHASE_OPEN;";
$new = "        return self::isOpen(\$chainId);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' integration 'HalfOpenProbeOwnershipIntegrationTest::testTheNonMutatingCheckNeverClaimsTheProbe' \
  'M2 isResting() acquires the probe lock'

# ── 2b. the same mutation, caught structurally without a database ───────────
mutate "$BREAKER" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return self::phaseFor(self::getState(\$chainId), time()) === self::PHASE_OPEN;";
$new = "        \BCC\Core\DB\AdvisoryLock::acquire(self::PROBE_LOCK_PREFIX . \$chainId, 0);\r\n        return self::phaseFor(self::getState(\$chainId), time()) === self::PHASE_OPEN;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' unit 'HalfOpenProbeOwnershipTest::testIsRestingContainsNoLockCall' \
  'M2b isResting() mentions AdvisoryLock'

# ── 3. strand every probe: releasing becomes a no-op ────────────────────────
mutate "$BREAKER" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        \BCC\Core\DB\AdvisoryLock::release(self::PROBE_LOCK_PREFIX . \$chainId);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, "        // stranded", $s));
' integration 'HalfOpenProbeOwnershipIntegrationTest::testAnotherWorkerCannotTakeAnActiveProbe' \
  'M3 releaseProbe() never releases'

# ── 4. strand on the transport path: ApiRetry stops releasing in finally ────
mutate "$RETRY" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            if (\$chainId > 0) {\r\n                OnchainCircuitBreaker::releaseProbe(\$chainId);\r\n            }\r\n        }\r\n    }\r\n\r\n    /**\r\n     * Convenience: wp_remote_get with retry + SSRF hardening.";
$new = "        }\r\n    }\r\n\r\n    /**\r\n     * Convenience: wp_remote_get with retry + SSRF hardening.";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' unit 'BreakerRetryAccountingTest::testTheProbeIsReleasedExactlyOncePerRequest' \
  'M4 ApiRetry::request() strands the probe'

# ── 5. charge the loser of the probe race ──────────────────────────────────
mutate "$RETRY" '
$f = $argv[1]; $s = file_get_contents($f);
// Two call sites record a blocked request (request() and getBatchSameHost());
// mutate ONLY the first, which is request().
$old = "            \$receipt?->recordBlockedByOpenBreaker();";
if (substr_count($s, $old) !== 2) { exit(1); }
$at = strpos($s, $old);
$inject = "\r\n            self::settleFailure(\$chainId, null, \$requestClass, \$endpointFp, \$receipt);";
file_put_contents($f, substr_replace($s, $old . $inject, $at, strlen($old)));
' unit 'BreakerChargePerLogicalRequestTest::testLosingTheProbeRaceChargesNothing' \
  'M5 a refused request is charged as a provider failure'

echo "─────────────────────────────────────────────────────────────────────────"
printf '  killed=%d  survived=%d  broken=%d\n' "$killed" "$survived" "$broken"
rm -rf "$SNAPDIR"

if [ "$survived" -ne 0 ] || [ "$broken" -ne 0 ]; then
    echo "  FAIL: a control survived or never ran."
    exit 1
fi
echo "  PASS: every control was killed by the test named for its guarantee."
exit 0
