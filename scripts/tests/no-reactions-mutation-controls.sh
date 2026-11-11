#!/usr/bin/env bash
#
# Mutation controls for "an announcement takes no engagement"
# (api-contract-v1.md §4.32.15).
#
# Each control breaks ONE guarantee in executable code and runs the test NAMED for
# that guarantee. Verdicts:
#
#   killed        the named test failed with the guarantee broken
#   survived      everything stayed green with the guarantee broken
#   broken        the mutation changed no bytes (it tested nothing)
#
# ⚠⚠⚠ M2 IS THE ONE THAT MATTERS MOST, and it is the least obvious.
#   Removing the gate from the HELPFUL-MARK endpoint leaves Stoke correctly
#   closed, the reactions grammar correctly empty, and the feed payload
#   correctly stripped — so every read-side check still looks right. What stays
#   open is the only REPUTATION-BEARING path of the three: a helpful mark mints
#   rank evidence crediting the CONTENT AUTHOR, so an announcement could move
#   its own operator's standing through a hand-made POST while the UI showed no
#   control at all.
#
# ⚠ M6 is the second: it makes the hydrator advertise `heat_stage` again. The
#   write path still refuses, so nothing can actually be recorded — but the
#   server now claims a stoke surface it will 403, which is how a correct
#   client ends up rendering a button that always errors.
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE (md5 before/after).
# ⚠ RESTORE FROM A BYTE SNAPSHOT TAKEN ONCE, NEVER FROM GIT, and abort on a
#   failed restore.
# ⚠ ANCHORS COMPUTE THE FILE'S LINE ENDING. This repo checks out CRLF; a file
#   created in this branch and not yet round-tripped through git is LF. A
#   \n-only anchor matches nothing and is reported `broken`.
#
# Usage: bash scripts/tests/no-reactions-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d memory_limit=2G}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

GATE="app/Domain/Core/Support/NoReactionsGate.php"
STOKE="app/Domain/Core/REST/StokeEndpoint.php"
HELPFUL="app/Domain/Core/REST/HelpfulMarkEndpoint.php"
REACTIONS="app/Domain/Core/REST/ReactionsEndpoint.php"
HYDRATOR="app/Domain/Core/Services/Feed/FeedHydrationPipeline.php"
# ⚠ M8 mutates the STUB to prove the grammar double is load-bearing, so the stub
# is snapshotted and restored like any other target. A mutated file never
# restored silently poisons every later control in the run.
STUBS="tests/Stubs/no-reactions-stubs.php"

SNAPDIR="$(mktemp -d)"
for f in "$GATE" "$STOKE" "$HELPFUL" "$REACTIONS" "$HYDRATOR" "$STUBS"; do
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

# Drops the whole `NoReactionsGate::check()` guard from a handler by neutering
# its condition — the shape a careless "simplify the gates" refactor leaves.
drop_guard () {
    local file="$1" filter="$3" label="$4"
    export BCC_MUT_COUNT="$2"
    mutate "$file" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (\$noReactions !== null) {";
$new = "        if (false) {";
$want = (int) getenv("BCC_MUT_COUNT");
if (substr_count($s, $old) !== $want) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' "$filter" "$label"
}

echo "── No-engagement-on-announcements mutation controls ──────────────────────"

# ── M1. STOKE STAYS OPEN ────────────────────────────────────────────────────
drop_guard "$STOKE" 2 'NoReactionsGateTest' \
  'M1 the stoke endpoint stops consulting the gate'

# ── M2. ⚠⚠⚠ THE REPUTATION-BEARING PATH STAYS OPEN ─────────────────────────
#
# Looks like the least important of the three — it is the most. See the header.
drop_guard "$HELPFUL" 1 'NoReactionsGateTest' \
  'M2 the HELPFUL-MARK endpoint stops consulting the gate (rank evidence)'

# ── M3. REACTION REMOVAL STAYS OPEN ─────────────────────────────────────────
#
# The empty kind set closes set-reaction via kindBelongsToGrammar(). It does
# NOT close removal, which never consults a grammar — so this half of the
# toggle is only closed by the gate.
drop_guard "$REACTIONS" 2 'NoReactionsGateTest' \
  'M3 the reactions endpoint stops consulting the gate (removal is ungrammared)'

# ── M4. THE GATE NEVER REFUSES ──────────────────────────────────────────────
mutate "$GATE" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (!self::moduleTakesNoReactions(\$module)) {";
$new = "        if (true) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'NoReactionsGateTest' 'M4 the gate permits every module'

# ── M5. THE GATE REFUSES EVERYTHING ─────────────────────────────────────────
#
# The opposite failure, and the one a "fail closed everywhere" instinct
# produces: Stoke breaks for the entire feed while every announcement
# assertion still passes.
mutate "$GATE" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return ReactionGrammarMap::grammarForModule(\$moduleId) === self::GRAMMAR_NONE_WIRE;";
$new = "        return true;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'NoReactionsGateTest' 'M5 the gate refuses every module (ordinary posts break)'

# ── M6. ⚠ THE SERVER ADVERTISES A STOKE SURFACE IT WILL REFUSE ─────────────
mutate "$HYDRATOR" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            if (!NoReactionsGate::kindTakesNoReactions(\$postKind)) {";
$new = "            if (true) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'NoReactionsGateTest' 'M6 the hydrator advertises heat_stage on every kind'

# ── M7. THE WIRE LITERAL DRIFTS FROM bcc-core ───────────────────────────────
#
# The one duplication in the design, and the reason the reconciliation test
# exists. A typo here silently reopens everything: the comparison never
# matches, so no module is ever a no-reactions module.
mutate "$GATE" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "    public const GRAMMAR_NONE_WIRE = \x27none\x27;";
$new = "    public const GRAMMAR_NONE_WIRE = \x27non\x27;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'NoReactionsGateTest' 'M7 the wire literal drifts by one character'

# ── M8. ANTI-VACUITY: is the grammar double load-bearing? ───────────────────
#
# Every case runs against a doubled ReactionGrammarMap, because bcc-core is not
# autoloadable in the unit suite. If that double simply answered `none` for
# everything the suite would pass while proving nothing. Remove the
# announcement entry and the refusal cases must fail — which shows the double
# is discriminating by kind, exactly as production does.
mutate "$STUBS" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            \x27announcement\x27   => self::GRAMMAR_NONE,";
$new = "            // announcement entry removed by mutation control M8";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'NoReactionsGateTest' 'M8 the grammar double stops mapping announcement (observer control)'

for f in "$GATE" "$STOKE" "$HELPFUL" "$REACTIONS" "$HYDRATOR" "$STUBS"; do restore "$f"; done
rm -rf "$SNAPDIR"

echo "─────────────────────────────────────────────────────────────────────────"
echo "killed=$killed survived=$survived broken=$broken"

# A survivor means a guarantee is unpinned; a broken control means it was never
# tested. Both fail the run.
[ "$survived" -eq 0 ] && [ "$broken" -eq 0 ] || exit 1
exit 0
