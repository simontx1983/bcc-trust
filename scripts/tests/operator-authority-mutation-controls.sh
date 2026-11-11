#!/usr/bin/env bash
#
# Mutation controls for the authoritative validator-page operator check
# (Validator Announcements A0; api-contract-v1.md §4.32.2).
#
# Each control breaks ONE guarantee in executable code and runs the test NAMED
# for that guarantee. Verdicts:
#
#   killed        the named test failed with the guarantee broken
#   survived      everything stayed green with the guarantee broken
#   broken        the mutation changed no bytes (it tested nothing)
#
# ⚠⚠⚠ THE TWO MUTATIONS THAT MATTER MOST ARE M3 AND M4, and NEITHER LOOKS LIKE
#   A BUG.
#
#   M3 moves the operator-id comparison above the ambiguity rung — which reads
#   as "check the cheap thing first". A contested page then denies with
#   `not_claimer` instead of `claim_ambiguous`, because AMBIGUOUS carries
#   `user_id === null` and so fails the comparison. The caller is still denied,
#   so no test of the OUTCOME notices; what is lost is the audit distinction
#   between "someone else owns this" and "this validator's ownership is
#   contested" — the second being an incident the claim-exclusivity constraint
#   is supposed to make impossible.
#
#   M4 returns the private reason on the wire — which reads as a better error
#   message. It hands an unauthenticated prober a map of which validator pages
#   are unclaimed and which are contested, the exact disclosure §4.32.2's
#   "leaks neither operator identity nor claim count" exists to prevent.
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE (md5 before/after).
# ⚠ RESTORE FROM A BYTE SNAPSHOT TAKEN ONCE, NEVER FROM GIT, and abort on a
#   failed restore.
# ⚠ ANCHORS COMPUTE THE FILE'S LINE ENDING. This repo checks out CRLF, but a
#   file created in this branch and not yet round-tripped through git is LF. A
#   \n-only anchor matches nothing and is reported `broken`.
#
# Usage: bash scripts/tests/operator-authority-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d memory_limit=2G}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

AUTH="app/Domain/Onchain/Services/ValidatorPageOperatorAuthority.php"
# ⚠ M7 mutates the STUB to prove the resolve-call recorder is actually wired, so
# the stub is snapshotted and restored like any other target. A mutated file
# never restored silently poisons every later control in the run.
STUBS="tests/Stubs/operator-authority-stubs.php"

SNAPDIR="$(mktemp -d)"
for f in "$AUTH" "$STUBS"; do
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

mutate () {
    local file="$1" replacement="$2" filter="$3" label="$4"
    local before after

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

echo "── Validator-page operator authority mutation controls ───────────────────"

# ── M1. THE CALLER'S OWN CLAIM IS NEVER CHECKED ─────────────────────────────
#
# Drop the viewer binding. This is the whole difference between "where does a
# message go" (what every existing caller of the resolver asks) and "may THIS
# PERSON act" (what an authorization check must ask). Without it, any signed-in
# member is the operator of every claimed validator page.
mutate "$AUTH" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "        if (\$operatorId !== \$viewerId) {";
$new = "        if (false) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAResolvedPageDeniesEveryViewerWhoIsNotThatOperator' 'M1 the acting viewer is no longer bound to the resolution'

# ── M2. A CONTESTED PAGE PICKS A WINNER ─────────────────────────────────────
#
# Treat AMBIGUOUS as a pass-through. The resolver returns user_id === null for
# AMBIGUOUS, so this alone does not grant — but it removes the rung, and the
# very next edit that supplied a fallback id would hand a contested validator
# to whichever rival the planner returned.
mutate "$AUTH" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (\$state === ClaimRepository::OPERATOR_AMBIGUOUS) {";
$new = "        if (false && \$state === ClaimRepository::OPERATOR_AMBIGUOUS) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAContestedPageDeniesTheRivalWhoWouldHaveWonOnRowOrder|testAmbiguityIsReportedAsAmbiguityRatherThanAsNotTheClaimer' 'M2 ambiguity stops being its own rung'

# ── M3. ⚠ THE RUNGS ARE REORDERED — reads as a cheap-check-first tidy-up ────
#
# Report ambiguity as ordinary non-ownership. The caller is still denied, so
# the OUTCOME is unchanged and only the reason moves. What is lost is the one
# signal that says this page needs an operator to look at it.
mutate "$AUTH" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            return self::denied(self::REASON_CLAIM_AMBIGUOUS);";
$new = "            return self::denied(self::REASON_NOT_CLAIMER);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAmbiguityIsReportedAsAmbiguityRatherThanAsNotTheClaimer' 'M3 ambiguity is reported as plain non-ownership'

# ── M4. ⚠ THE DENIAL REASON LEAKS THE CLAIM STATE — reads as a better error ─
#
# Pass the private reason to the wire. Four distinct internal causes become
# four distinct client-visible answers, and claim existence, ownership and
# contention stop being indistinguishable from outside.
mutate "$AUTH" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return \$decision[\x27decision\x27] === self::DECISION_OPERATOR ? null : self::PUBLIC_REASON;";
$new = "        return \$decision[\x27decision\x27] === self::DECISION_OPERATOR ? null : \$decision[\x27reason\x27];";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testEveryDenialPresentsTheSameReasonOnTheWire' 'M4 the private reason reaches the wire'

# ── M5. A NON-VALIDATOR PAGE IS ACCEPTED ────────────────────────────────────
#
# Drop the validator-leg requirement, keeping only the post-type check. The
# resolver would return UNCLAIMED for a collection-backed page, so the denial
# reason becomes `claim_absent` — "nobody has claimed this" — about a page that
# may well have a verified CREATOR. That is the live page-image gate's mistake
# (it accepts the collection leg) arriving here by omission.
mutate "$AUTH" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return \\BCC\\Trust\\Onchain\\Repositories\\ValidatorRepository::findFirstByPageId(\$pageId) !== null;";
$new = "        return true;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAPageWithNoValidatorLegIsOutOfScopeRatherThanUnclaimed' 'M5 any published page is treated as a validator page'

# ── M6. A HALF-RESOLUTION IS TRUSTED ────────────────────────────────────────
#
# Accept RESOLVED without a usable operator id. With user_id === 0 this falls
# through to the id comparison, and the 0 === 0 trap is one careless viewer
# default away from granting an anonymous caller operator rights.
mutate "$AUTH" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (!is_int(\$operatorId) || \$operatorId <= 0) {";
$new = "        if (false) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testResolvedWithoutAUsableOperatorIdDenies' 'M6 a RESOLVED state with no usable id is trusted'

# ── M7. ANTI-VACUITY: is the resolve-call recorder actually wired? ──────────
#
# The "anon costs no claim query" and "exactly once, nothing memoized"
# assertions are only worth anything if the double really counts. Stop counting
# and they must both fail. A control that proves the OBSERVER works, not the
# subject — without it, those two cases could be passing on a dead counter.
mutate "$STUBS" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            \$GLOBALS[\x27__bcc_a0_resolve_calls\x27] = (\$GLOBALS[\x27__bcc_a0_resolve_calls\x27] ?? 0) + 1;";
$new = "            // recorder disabled by mutation control M7";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testEachDecisionResolvesExactlyOnceAndNothingIsMemoized' 'M7 the resolve-call recorder is disabled (observer control)'

# ── M8. THE FORBIDDEN-INPUT SWEEP IS REAL ───────────────────────────────────
#
# Plant a forbidden authorization input in executable code. The source guard
# must catch it. Without this control the guard could be passing because its
# needle list never matches anything, rather than because the code is clean.
mutate "$AUTH" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        \$resolution = ClaimRepository::resolveVerifiedOperatorForPage(\$pageId);";
$new = "        \$resolution = ClaimRepository::resolveVerifiedOperatorForPage(\$pageId);\n        \$ignored = get_post_meta(\$pageId, \x27_claimed_by\x27, true);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheAuthorityNamesNoForbiddenAuthorizationInput' 'M8 a forbidden input is planted in executable code'

for f in "$AUTH" "$STUBS"; do restore "$f"; done
rm -rf "$SNAPDIR"

echo "─────────────────────────────────────────────────────────────────────────"
echo "killed=$killed survived=$survived broken=$broken"

# A survivor means a guarantee is unpinned; a broken control means it was never
# tested. Both fail the run.
[ "$survived" -eq 0 ] && [ "$broken" -eq 0 ] || exit 1
exit 0
