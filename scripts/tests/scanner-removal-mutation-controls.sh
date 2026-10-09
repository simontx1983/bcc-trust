#!/usr/bin/env bash
#
# Mutation controls for ownership budgets and the retired discovery executor.
#
# Each control breaks ONE guarantee in executable code and runs the test NAMED for
# that guarantee. Verdicts:
#
#   killed        the named test failed with the guarantee broken
#   wrong-reason  the run went red, but not in the named test — NOT counted as killed
#   survived      everything stayed green with the guarantee broken
#   broken        the mutation changed no bytes (it tested nothing)
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE (byte diff before/after).
# ⚠ RESTORE FROM A BYTE SNAPSHOT TAKEN ONCE, NEVER FROM GIT, and abort on a failed
#   restore — `git checkout --` once deleted six files mid-run in an earlier PR.
#
# Usage: bash scripts/tests/scanner-removal-mutation-controls.sh
#
# ── S4: TWO MUTATION TARGETS NO LONGER EXIST ──────────────────────────────
#
# SCAN_ACTIONS (Admin/DiscoveryScanActions.php) is DELETED, and the
# `if (!ScannerFreeze::frozen())` block M7 mutated in NftDiscoveryPage.php is
# gone with the six CosmWasm routes. Controls M1 and M7 were removed then.
#
# They could not simply be left: each `mutate` call asserts its anchor text
# occurs exactly once and exits 1 otherwise, which this harness counts as
# `broken` — and `broken` fails the run just as a surviving mutation does. A
# control whose target has been deleted tests nothing and must not look like
# it passed.
#
# ── S8: THREE MORE WENT, ONE WAS REPLACED, AND THE FILE WAS RENAMED ───────
#
# This script was `scanner-freeze-mutation-controls.sh`. There is no freeze to
# control any more — S8 deleted `ScannerFreeze` along with the scanner — so the
# name was a claim the file could no longer make.
#
#   M2 REMOVED. It re-coupled `ProviderRequestBudget` to
#      `CosmwasmDiscoveryGate::requestBudget()` and named
#      `ProviderRequestBudgetIsNeutralTest`. The gate, the test and the
#      coupling it guarded against are all deleted. Its planted positive
#      planted a string that has stopped existing.
#
#   M5 REMOVED. It mutated `DiscoveryRunMaintenance`, which is a deleted file.
#
#   M6 REPLACED, NOT DELETED — see M6 below. It used to strip
#      `if (ScannerFreeze::frozen())` out of `handleQueuedAction()`. That block
#      is gone, but the guarantee it protected is MORE important now, not less:
#      the executor hook is still bound, so a queued action still fires into
#      that method, and its refusal is the only thing standing between a
#      surviving queued action and an error. The replacement breaks the
#      refusal in the way that can actually regress — by making it
#      conditional again.
#
#   M3 and M4 KEPT VERBATIM. They mutate `HoldingsService`, which is retained
#      production code serving ownership, group gates and revocation. Nothing
#      about S8 touches them.
#
# ── ⚠ AND THE ANCHORS ARE EOL-AGNOSTIC ────────────────────────────────────
#
# Running the retained controls for the first time (isolated container, PHP
# 8.2, gmp+mysqli, the COMMITTED tree) showed M2, M4, M5 and M6 reporting
# `broken` — "the control tested nothing". Their anchor strings hard-coded
# "\r\n", so they only matched a Windows working copy; against the committed
# LF bytes `substr_count($s, $old)` was 0 and each mutation exited 1. Only M3
# worked, because its anchor is a single line with no newline in it.
#
# These controls had therefore never run anywhere that uses the committed
# bytes — which is every Linux checkout, and would be CI if this script were
# ever wired in. Each anchor now derives its newline from the file it is about
# to mutate, so the same control works from a CRLF checkout and an LF one.
#
# ⚠ This script is still NOT CI-wired. It must be run deliberately, and it
# must be run against the committed bytes — `git -c core.autocrlf=false
# archive` on a Windows clone, or any Linux checkout. A plain `git archive`
# honours autocrlf and hands you CRLF, which is what hid this.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d extension=mysqli -d memory_limit=2G}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

HOLDINGS="app/Domain/Onchain/Services/HoldingsService.php"
EXECUTOR="app/Domain/Onchain/Workers/DiscoveryRunExecutor.php"

SNAPDIR="$(mktemp -d)"
for f in "$HOLDINGS" "$EXECUTOR"; do
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

killed=0; survived=0; wrong=0; broken=0

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

echo "── scanner-removal mutation controls ────────────────────────────────────"

# 3. Change ONE ownership budget: the test named for that surface must notice.
mutate "$HOLDINGS" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "self::SURFACE_LISTING   => [40, 8],";
$new = "self::SURFACE_LISTING   => [39, 8],";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'NftVerificationSurfaceBudgetTest::testTheListingIsBoundedByItsBudget' 'M3 the listing budget becomes 39'

# 4. Turn an exhausted budget into a decision against the member: fail-safe must notice.
mutate "$HOLDINGS" '
$f = $argv[1]; $s = file_get_contents($f);
$nl = strpos($s, "\r\n") !== false ? "\r\n" : "\n";
$old = "        if (\$reason !== null) {" . $nl . "            return EligibilityVerdict::unknownBecause(\$min, \$best, \$reason);" . $nl . "        }";
$new = "        if (\$reason !== null) {" . $nl . "            if (\$reason === EligibilityVerdict::REASON_BUDGET_EXHAUSTED) {" . $nl . "                return EligibilityVerdict::ineligible(\$min, \$best ?? 0);" . $nl . "            }" . $nl . "            return EligibilityVerdict::unknownBecause(\$min, \$best, \$reason);" . $nl . "        }";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'NftRevocationFailSafeTest::testJoinStopsAtItsBudgetAndFailsClosed' 'M4 budget exhaustion becomes INELIGIBLE'

# 6. Make the executor refusal CONDITIONAL again.
#
# The hook stays bound until an operator confirms the Action Scheduler queue is
# drained, so a queued action still fires into handleQueuedAction(). S8 made its
# refusal unconditional precisely so there is no flag to flip; this control
# reintroduces a flag and expects the inventory test to say so.
mutate "$EXECUTOR" '
$f = $argv[1]; $s = file_get_contents($f);
$nl = strpos($s, "\r\n") !== false ? "\r\n" : "\n";
$old = "        return [\x27status\x27 => \x27retired\x27, \x27run_id\x27 => \$runId];";
$new = "        if (\\BCC\\Trust\\Onchain\\Support\\ScannerFreeze::frozen()) {" . $nl
     . "            return [\x27status\x27 => \x27retired\x27, \x27run_id\x27 => \$runId];" . $nl
     . "        }" . $nl . $nl
     . "        return [\x27status\x27 => \x27ran\x27, \x27run_id\x27 => \$runId];";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'ScannerRemovedInventoryTest' 'M6 the executor refusal becomes conditional on a flag again'

echo "killed=$killed survived=$survived wrong_reason=$wrong broken=$broken"

# Every file must be byte-identical to its pre-run snapshot.
clean=1
for f in "$HOLDINGS" "$EXECUTOR"; do
    if ! cmp -s "$SNAPDIR/$(basename "$f").orig" "$f"; then
        echo "FATAL: $f is NOT byte-identical to its snapshot"
        clean=0
    fi
done
[ $clean -eq 1 ] && echo "all mutated files byte-identical to their pre-run snapshot"
rm -rf "$SNAPDIR"

[ $survived -eq 0 ] && [ $broken -eq 0 ] && [ $clean -eq 1 ] && exit 0
exit 1
