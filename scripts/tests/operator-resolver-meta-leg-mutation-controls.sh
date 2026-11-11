#!/usr/bin/env bash
#
# Mutation controls for the post_meta leg of the verified-operator resolver
# (ClaimRepository::resolveVerifiedOperatorForPages).
#
# ── WHY THIS SCRIPT EXISTS AND THE SUITE ALONE IS NOT ENOUGH ────────────────
# Four of the seven cases this change adds FAIL on an unfixed tree, so they
# prove the fix does something. Three do NOT, and cannot: they are over-reach
# guards — "the wallet-link leg still wins", "the same role and status filters
# apply", "a collection claim is not reached" — and on a tree with no second
# leg at all there is nothing for them to catch. A guard that cannot fail on
# either side of the change is evidence of nothing until something breaks it.
#
# So each control below breaks ONE property of the NEW leg and runs the case
# named for it. Verdicts:
#
#   killed        the named case failed with the property broken
#   survived      everything stayed green with the property broken
#   broken        the mutation changed no bytes (it tested nothing)
#
# ⚠⚠ M1 AND M5 ARE THE TWO THAT MATTER, and neither reads as a bug.
#
#   M1 makes the fallback a UNION — run the second leg for every page rather
#   than only the ones the first missed. That reads like thoroughness. What it
#   actually does is merge a second validator's claimants into a page that
#   already resolves cleanly, turning a WORKING operator's page AMBIGUOUS and
#   locking them out. The failure mode of a fix that over-reaches is the same
#   lockout the fix was written to remove, just on different pages.
#
#   M5 adds `LIMIT 1` to the fallback, which reads like a bounded read and is
#   the house habit everywhere else. On a CONTESTED page it drops the second
#   claimant, so AMBIGUOUS silently becomes RESOLVED and one of two rivals is
#   handed sole authority over the page. This is why the fallback carries no
#   LIMIT and is bounded by the 100-id IN-list instead.
#
# ⚠ Needs a live MySQL/MariaDB: these are integration cases. Not CI-wired, like
#   every other mutation-control script here except endpoint-redaction and
#   alchemy-credential. Set BCC_TEST_DB_* exactly as CI does.
# ⚠ EVERY MUTATION PROVES IT CHANGED BYTES (md5 before/after).
# ⚠ RESTORE FROM A BYTE SNAPSHOT TAKEN ONCE, NEVER FROM GIT, and abort on a
#   failed restore — a mutated file never restored poisons every later control.
# ⚠ ANCHORS COMPUTE THE FILE'S LINE ENDING. This repo checks out CRLF; a file
#   created on a branch and not yet round-tripped through git is LF. A \n-only
#   anchor matches nothing and is reported `broken`, not `survived`.
#
# Usage: bash scripts/tests/operator-resolver-meta-leg-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d memory_limit=2G}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

CONFIG="phpunit-integration.xml.dist"
[ -f "$CONFIG" ] || { echo "FATAL: $CONFIG missing"; exit 2; }

REPO="app/Domain/Onchain/Repositories/ClaimRepository.php"

SNAPDIR="$(mktemp -d)"
cp "$REPO" "$SNAPDIR/orig" || { echo "FATAL: snapshot failed"; exit 2; }

restore () {
    cp "$SNAPDIR/orig" "$REPO" || { echo "FATAL: RESTORE FAILED — ABORTING"; exit 3; }
    if ! cmp -s "$SNAPDIR/orig" "$REPO"; then
        echo "FATAL: $REPO differs from its snapshot after restore — ABORTING"
        exit 3
    fi
}

# A positive control FIRST: if the suite is not green unmutated, every
# "killed" below is meaningless because the case was already failing.
echo "── Baseline (unmutated) ──────────────────────────────────────────────────"
if ! $PHP "$PHPUNIT" -c "$CONFIG" --no-coverage \
        --filter VerifiedOperatorResolverStatesIntegrationTest >/dev/null 2>&1; then
    echo "FATAL: the suite is NOT green before any mutation — fix that first."
    echo "       (A red baseline makes every kill below unattributable.)"
    exit 2
fi
echo "  green — kills below are attributable to their mutation"
echo

killed=0; survived=0; broken=0

mutate () {
    local replacement="$1" filter="$2" label="$3"
    local before after out rc

    before="$($PHP -r 'echo md5_file($argv[1]);' "$REPO")"
    if ! $PHP -r "$replacement" "$REPO"; then
        echo "  broken       $label (mutation script failed — the control tested nothing)"
        broken=$((broken + 1)); restore; return
    fi
    after="$($PHP -r 'echo md5_file($argv[1]);' "$REPO")"

    if [ "$before" = "$after" ]; then
        echo "  broken       $label (no bytes changed — the control tested nothing)"
        broken=$((broken + 1)); restore; return
    fi

    out="$($PHP "$PHPUNIT" -c "$CONFIG" --no-coverage --filter "$filter" 2>&1)"
    rc=$?
    restore

    if [ $rc -ne 0 ]; then
        echo "  killed       $label  [$filter]"
        killed=$((killed + 1))
    else
        echo "  survived     $label  [$filter]"
        echo "$out" | tail -3 | sed 's/^/                 /'
        survived=$((survived + 1))
    fi
}

echo "── post_meta leg mutation controls ───────────────────────────────────────"

# ── M1. ⚠ THE FALLBACK BECOMES A UNION — reads as thoroughness ──────────────
mutate '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            if (!isset(\$byPage[\$id])) {";
$new = "            if (true) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheWalletLinkLegStillWinsOverThePostMetaBinding' \
  'M1 the second leg runs for every page, not only the missed ones'

# ── M2. The operator role stops being required on the new leg ───────────────
mutate '
$f = $argv[1]; $s = file_get_contents($f);
$old = "                   AND cl.status = \x27verified\x27 AND cl.claim_role = \x27operator\x27";
$new = "                   AND cl.status = \x27verified\x27";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testThePostMetaLegAppliesTheSameRoleAndStatusFilters' \
  'M2 a creator claim satisfies the new leg'

# ── M3. Verified status stops being required on the new leg ─────────────────
mutate '
$f = $argv[1]; $s = file_get_contents($f);
$old = "                   AND cl.status = \x27verified\x27 AND cl.claim_role = \x27operator\x27";
$new = "                   AND cl.claim_role = \x27operator\x27";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testThePostMetaLegAppliesTheSameRoleAndStatusFilters' \
  'M3 an unverified claim satisfies the new leg'

# ── M4. The entity type stops being pinned to validator ────────────────────
#
# Without it the join is `cl.entity_id = v.id` alone, so a COLLECTION claim
# whose entity_id happens to equal a validator id is answered as that
# validator page's operator. The named case makes those ids collide on purpose.
mutate '
$f = $argv[1]; $s = file_get_contents($f);
$old = "JOIN {\$table} cl ON cl.entity_id = v.id AND cl.entity_type = \x27validator\x27";
$new = "JOIN {\$table} cl ON cl.entity_id = v.id";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testACollectionClaimIsNotReachedByTheValidatorMetaLeg' \
  'M4 a collection claim is reachable through the validator meta leg'

# ── M5. ⚠ A BOUNDED READ IS ADDED — reads as the house habit ────────────────
mutate '
$f = $argv[1]; $s = file_get_contents($f);
$old = "                 ORDER BY pm.post_id, cl.user_id\",";
$new = "                 ORDER BY pm.post_id, cl.user_id LIMIT 1\",";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTwoOperatorsReachedOnlyThroughPostMetaAreAmbiguous' \
  'M5 the fallback truncates, so a contested page resolves to one rival'

echo "─────────────────────────────────────────────────────────────────────────"
echo "  killed: $killed   survived: $survived   broken: $broken"
rm -rf "$SNAPDIR"
[ "$survived" -eq 0 ] && [ "$broken" -eq 0 ] && [ "$killed" -gt 0 ]
