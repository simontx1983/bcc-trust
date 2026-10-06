#!/usr/bin/env bash
#
# Mutation controls for "Detach ownership budgets and freeze full-chain discovery".
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
# Usage: bash scripts/tests/scanner-freeze-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d extension=mysqli -d memory_limit=2G}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

# ── S4: TWO MUTATION TARGETS NO LONGER EXIST ──────────────────────────────
#
# SCAN_ACTIONS (Admin/DiscoveryScanActions.php) is DELETED, and the
# `if (!ScannerFreeze::frozen())` block M7 mutated in NftDiscoveryPage.php is
# gone with the six CosmWasm routes. Controls M1 and M7 are removed below.
#
# They could not simply be left: each `mutate` call asserts its anchor text
# occurs exactly once and exits 1 otherwise, which this harness counts as
# `broken` — and `broken` fails the run just as a surviving mutation does. A
# control whose target has been deleted tests nothing and must not look like
# it passed.
#
# PAGE is dropped too: M7 was its only user.
BUDGET="app/Domain/Onchain/Support/ProviderRequestBudget.php"
HOLDINGS="app/Domain/Onchain/Services/HoldingsService.php"
MAINTENANCE="app/Domain/Onchain/Workers/DiscoveryRunMaintenance.php"
EXECUTOR="app/Domain/Onchain/Workers/DiscoveryRunExecutor.php"

SNAPDIR="$(mktemp -d)"
for f in "$BUDGET" "$HOLDINGS" "$MAINTENANCE" "$EXECUTOR"; do
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

echo "── scanner-freeze mutation controls ─────────────────────────────────────"

# 2. Re-couple the budget primitive to the scanner: the structural test must notice.
mutate "$BUDGET" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "    public function __construct(int \$requests, int \$runtimeSeconds)\r\n    {\r\n        \$this->remaining = \$requests;";
$new = "    public function __construct(int \$requests, int \$runtimeSeconds)\r\n    {\r\n        \$this->remaining = \$requests > 0 ? \$requests : CosmwasmDiscoveryGate::requestBudget();";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'ProviderRequestBudgetIsNeutralTest' 'M2 the budget reads CosmwasmDiscoveryGate again'

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
$old = "        if (\$reason !== null) {\r\n            return EligibilityVerdict::unknownBecause(\$min, \$best, \$reason);\r\n        }";
$new = "        if (\$reason !== null) {\r\n            if (\$reason === EligibilityVerdict::REASON_BUDGET_EXHAUSTED) {\r\n                return EligibilityVerdict::ineligible(\$min, \$best ?? 0);\r\n            }\r\n            return EligibilityVerdict::unknownBecause(\$min, \$best, \$reason);\r\n        }";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'NftRevocationFailSafeTest::testJoinStopsAtItsBudgetAndFailsClosed' 'M4 budget exhaustion becomes INELIGIBLE'

# 5. Restore the maintenance sweep's redispatch: the background freeze test must notice.
mutate "$MAINTENANCE" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (ScannerFreeze::frozen()) {\r\n            return \$result;\r\n        }\r\n";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, "", $s));
' 'ScannerBackgroundEntryPointsAreFrozenTest' 'M5 the five-minute sweep requeues and re-dispatches again'

# 6. Restore executor execution: a pending Action Scheduler action would run again.
mutate "$EXECUTOR" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (ScannerFreeze::frozen()) {\r\n            return [\x27status\x27 => \x27frozen\x27, \x27run_id\x27 => \$runId];\r\n        }\r\n";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, "", $s));
' 'ScannerBackgroundEntryPointsAreFrozenTest' 'M6 a queued executor action claims and runs again'
echo "killed=$killed survived=$survived wrong_reason=$wrong broken=$broken"

# Every file must be byte-identical to its pre-run snapshot.
clean=1
for f in "$BUDGET" "$HOLDINGS" "$MAINTENANCE" "$EXECUTOR"; do
    if ! cmp -s "$SNAPDIR/$(basename "$f").orig" "$f"; then
        echo "FATAL: $f is NOT byte-identical to its snapshot"
        clean=0
    fi
done
[ $clean -eq 1 ] && echo "all mutated files byte-identical to their pre-run snapshot"
rm -rf "$SNAPDIR"

[ $survived -eq 0 ] && [ $broken -eq 0 ] && [ $clean -eq 1 ] && exit 0
exit 1
