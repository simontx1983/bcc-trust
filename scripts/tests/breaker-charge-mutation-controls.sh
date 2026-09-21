#!/usr/bin/env bash
#
# Mutation controls for "circuit-breaker charging per logical provider request".
#
# Each control breaks ONE guarantee in executable code and runs the test NAMED for
# that guarantee. Verdicts:
#
#   killed        the named test failed with the guarantee broken
#   survived      everything stayed green with the guarantee broken
#   broken        the mutation changed no bytes (it tested nothing)
#
# ⚠ EVERY CONTROL IS KILLED BY A COUNTER OR STATE ASSERTION, NOT BY A LOG LINE OR A
#   MOCK CALL COUNT. The named tests below read `_bcc_cb_counter_<chain>` — the real
#   option the real breaker writes — after driving the real retry loop. A regression
#   to per-attempt charging still calls `recordFailure()` once per attempt, so a
#   harness that counted CALLS would stay green through M1.
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE (byte diff before/after).
# ⚠ RESTORE FROM A BYTE SNAPSHOT TAKEN ONCE, NEVER FROM GIT, and abort on a failed
#   restore — `git checkout --` once deleted six files mid-run in an earlier PR.
#
# Usage: bash scripts/tests/breaker-charge-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d memory_limit=2G}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

RETRY="app/Domain/Onchain/Support/ApiRetry.php"
RECEIPT="app/Domain/Onchain/Support/ProviderOutcomeReceipt.php"
WORKER="app/Domain/Onchain/Workers/NftEthIndexerWorker.php"

SNAPDIR="$(mktemp -d)"
for f in "$RETRY" "$RECEIPT" "$WORKER"; do
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

# mutate <file> <php-replacement-script> <named-test-filter> <label>
mutate () {
    local file="$1" replacement="$2" filter="$3" label="$4"
    local before after

    before="$($PHP -r 'echo md5_file($argv[1]);' "$file")"
    # A mutation script that fails has tested NOTHING. Count it as broken: silently
    # skipping it would let a control that never ran look like a clean sheet.
    if ! $PHP -r "$replacement" "$file"; then
        echo "  broken       $label (mutation script failed — the control tested nothing)"
        broken=$((broken + 1))
        restore "$file"
        return
    fi
    after="$($PHP -r 'echo md5_file($argv[1]);' "$file")"

    if [ "$before" = "$after" ]; then
        echo "  broken       $label (no bytes changed — the control tested nothing)"
        broken=$((broken + 1))
        restore "$file"
        return
    fi

    local out rc
    out="$($PHP "$PHPUNIT" --no-coverage --filter "$filter" 2>&1)"
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

echo "── breaker charge-per-logical-request mutation controls ──────────────────"

# 1. RESTORE PER-ATTEMPT CHARGING on the 5xx branch. The defect itself.
#    Killed by a real-counter delta, which is the only observation that can tell
#    "one charge after four attempts" from "four charges".
mutate "$RETRY" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "                    // ⚠ NOT CHARGED HERE. A retryable 5xx is local retry state";
$new = "                    self::settleFailure(\$chainId, ProviderFailureKind::HTTP_5XX, \$requestClass, \$endpointFp, \$receipt);\r\n                    // ⚠ NOT CHARGED HERE. A retryable 5xx is local retry state";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'BreakerChargePerLogicalRequestTest::testAnExhaustedFiveHundredHeadPollAlsoChargesExactlyOnce' 'M1 per-attempt charging restored (5xx)'

# 1b. Same, on the transport branch.
mutate "$RETRY" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            // ⚠ NOT CHARGED HERE — same reasoning as the 5xx branch above.";
$new = "            self::settleFailure(\$chainId, ProviderFailureKind::TRANSPORT, \$requestClass, \$endpointFp, \$receipt);\r\n            // ⚠ NOT CHARGED HERE — same reasoning as the 5xx branch above.";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'BreakerChargePerLogicalRequestTest::testAnExhaustedHeadPollPlusItsDomainVerdictChargesExactlyOnce' 'M1b per-attempt charging restored (transport)'

# 2. CHARGE BOTH the transport exhaustion AND its duplicate domain verdict —
#    the 4+1 shape that made one failing head poll open the breaker.
mutate "$WORKER" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            if (\$headResult[\x27outcome\x27]->domainMayCharge()) {\r\n                OnchainCircuitBreaker::recordFailure(\$chainId);\r\n            }";
$new = "            OnchainCircuitBreaker::recordFailure(\$chainId);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'BreakerChargePerLogicalRequestTest::testAnExhaustedHeadPollPlusItsDomainVerdictChargesExactlyOnce' 'M2 the domain verdict charges on top of transport'

# 2b. Same duplication on the pagination verdict.
mutate "$WORKER" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            if (\$failedPageOutcome === null || \$failedPageOutcome->domainMayCharge()) {\r\n                OnchainCircuitBreaker::recordFailure(\$chainId);\r\n            }";
$new = "            OnchainCircuitBreaker::recordFailure(\$chainId);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'BreakerChargePerLogicalRequestTest::testAFailingSecondPageChargesOnceForTheWholeTick' 'M2b the page verdict charges on top of transport'

# 3. SUPPRESS A GENUINE SEMANTIC FAILURE. The over-correction: treating a 200 with
#    an unusable payload as "already handled" and charging nothing at all.
mutate "$RECEIPT" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return \$this->last === self::NONE || \$this->last === self::SUCCESS;";
$new = "        return \$this->last === self::NONE;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'BreakerChargePerLogicalRequestTest::testATwoHundredCarryingAnRpcErrorCreditsThenChargesExactlyOnce' 'M3 a genuine semantic failure is suppressed'

# 4. LEAK THE RECEIPT BETWEEN OPERATIONS — make the head-poll receipt static, so
#    one tick's outcome decides the next tick's verdict.
#
#    ⚠ WHY NOT "HOIST IT ABOVE THE PAGING LOOP". That was the first mutant tried
#    here and it SURVIVED — correctly. The paging loop breaks on its first failed
#    page, so the failing page is always the last to write, and a hoisted receipt
#    holds exactly what a fresh one would. It is an equivalent mutant, not a gap:
#    the receipt is still constructed inside the loop, because a loop that did
#    NOT break on first failure would make that difference real. This mutant
#    targets the leak that IS observable today.
mutate "$WORKER" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        \$outcome = new ProviderOutcomeReceipt();\r\n        \$chain  = \$fetcher->get_chain();";
$new = "        static \$leaked = null;\r\n        \$outcome = \$leaked ??= new ProviderOutcomeReceipt();\r\n        \$chain  = \$fetcher->get_chain();";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'BreakerChargePerLogicalRequestTest::testATickThatNeverReachesTheWireStillChargesAfterAFailingTick' 'M4 the head-poll receipt leaks between ticks'

# 5. FAIL TO RELEASE THE RECOVERY PROBE. The finally-block guarantee.
mutate "$RETRY" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            if (\$chainId > 0) {\r\n                OnchainCircuitBreaker::releaseProbe(\$chainId);\r\n            }\r\n        }\r\n    }\r\n\r\n    /**\r\n     * Convenience: wp_remote_get with retry + SSRF hardening.";
$new = "        }\r\n    }\r\n\r\n    /**\r\n     * Convenience: wp_remote_get with retry + SSRF hardening.";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'BreakerRetryAccountingTest::testTheProbeIsReleasedExactlyOncePerRequest' 'M5 the probe lock is never released'

# 6. COLLAPSE TWO LOGICAL REQUESTS INTO ONE CHARGE — settle only when the receipt
#    has not already recorded a failure, i.e. let one request silence the next.
mutate "$RETRY" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        OnchainCircuitBreaker::recordFailure(\$chainId, \$kind, \$requestClass, \$endpointFp);\r\n        \$receipt?->recordTransportFailureCharge(\$kind);";
$new = "        if (\$receipt !== null && \$receipt->transportChargedFailure()) {\r\n            return;\r\n        }\r\n        OnchainCircuitBreaker::recordFailure(\$chainId, \$kind, \$requestClass, \$endpointFp);\r\n        \$receipt?->recordTransportFailureCharge(\$kind);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'BreakerChargePerLogicalRequestTest::testAReceiptSharedAcrossTwoRequestsStillChargesTwice' 'M6 two logical requests collapse into one charge'

# 7. TREAT EVENTUAL SUCCESS AS A FAILURE — charge the sequence even though a later
#    attempt succeeded.
mutate "$RETRY" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "                    self::settleSuccess(\$chainId, \$receipt);\r\n                    return \$lastResponse;";
$new = "                    if (\$attempt > 0) {\r\n                        self::settleFailure(\$chainId, ProviderFailureKind::TRANSPORT, \$requestClass, \$endpointFp, \$receipt);\r\n                    } else {\r\n                        self::settleSuccess(\$chainId, \$receipt);\r\n                    }\r\n                    return \$lastResponse;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'BreakerChargePerLogicalRequestTest::testAProbeThatSucceedsOnItsLastAttemptIsASuccess' 'M7 a retry that succeeds is charged as a failure'

echo "─────────────────────────────────────────────────────────────────────────"
printf '  killed=%d  survived=%d  broken=%d\n' "$killed" "$survived" "$broken"

rm -rf "$SNAPDIR"

# A clean sheet requires every control to have RUN and been killed.
if [ "$survived" -ne 0 ] || [ "$broken" -ne 0 ]; then
    echo "  FAIL: a control survived or never ran."
    exit 1
fi
echo "  PASS: every control was killed by the test named for its guarantee."
exit 0
