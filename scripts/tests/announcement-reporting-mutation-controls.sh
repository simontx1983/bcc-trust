#!/usr/bin/env bash
#
# Mutation controls for reporting an announcement (§4.32.4 / §4.32.11).
#
# ── WHY THIS SCRIPT EXISTS AND THE SUITES ALONE ARE NOT ENOUGH ──────────────
# Fourteen of the new cases FAIL on an unfixed tree, so they prove the change
# does something. Several do NOT, and cannot: they are over-reach guards —
# "a draft still answers the same envelope as a missing row", "the author id
# never reaches the wire", "the feed arm survives", "ordinary content stays
# auto-hideable". On a tree with no announcement target kind at all there is
# nothing for them to catch, and a guard that cannot fail on either side of
# the change is evidence of nothing until something breaks it.
#
# So each control below breaks ONE property and runs the case named for it.
#
#   killed        the named case failed with the property broken
#   survived      everything stayed green with the property broken
#   broken        the mutation changed no bytes (it tested nothing)
#
# ⚠⚠ M3, M4 AND M11 ARE THE THREE THAT MATTER, and none reads as a bug.
#
#   M3 decides reportability from `post_status === 'publish'` instead of the
#   §4.32.1 readability predicate. That reads like a simplification and is
#   even FASTER — one field instead of a join. What it actually does is make a
#   `publish` row whose publication-authorization marker is absent, future or
#   author-mismatched reportable: a row that is NOT publicly readable, so the
#   detail route 404s it while the report route accepts it. Two surfaces, two
#   answers, about the same row.
#
#   M4 takes the page id from the request instead of `post_parent`. That reads
#   like ordinary parameter plumbing — every other route takes a page id. Here
#   it hands the browser a say in which page a notice belongs to, and the page
#   is what the readability predicate is evaluated against. The whole design
#   of this fix is that the client sends ONE id and the server derives the
#   rest.
#
#   M11 adds the author id to the view-model. That reads like a convenience
#   the client might want, and it is the exact disclosure §4.32.2 forbids:
#   every public notice would name its operator, on a response whose public
#   variant is explicitly required to be byte-identical for every viewer.
#
# ⚠ NO CONTROL EXISTS FOR THE `$authorId > 0` GUARD in the self-report rung,
#   and that is stated rather than papered over: a zero author can only
#   collide with a zero viewer, an anonymous viewer is already denied
#   `auth_required` one rung earlier, and so a loose comparison changes only
#   which reason an unreportable row carries. The type-coercion provider
#   (`unreadableAuthorIds`) is the guard for that property; M9 is the part of
#   it that IS observable.
#
# ⚠ Unit suites only — no database, no WordPress. The behavioural half lives
#   in scripts/tests/announcement-reporting.php, which needs a real install;
#   M3's and M11's runtime consequences are measured there too.
# ⚠ Not CI-wired, like every mutation-control script here except
#   endpoint-redaction and alchemy-credential.
# ⚠ EVERY MUTATION PROVES IT CHANGED BYTES (md5 before/after). A sed-based
#   first draft of these controls silently failed to match and reported a
#   cheerful green — a broken control is worse than no control.
# ⚠ RESTORE FROM BYTE SNAPSHOTS TAKEN ONCE, NEVER FROM GIT, and abort on a
#   failed restore: a mutated file never restored poisons every later control.
#
# Usage: bash scripts/tests/announcement-reporting-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d memory_limit=2G}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed (run the isolated runner, not the worktree)"; exit 2; }

CONFIG="phpunit.xml.dist"
[ -f "$CONFIG" ] || { echo "FATAL: $CONFIG missing"; exit 2; }

SVC="app/Domain/Core/Services/ContentReportService.php"
CAP="app/Domain/Core/Announcements/AnnouncementCapabilityResolver.php"
VIEW="app/Domain/Core/Services/AnnouncementViewService.php"
EP="app/Domain/Core/REST/MyReportsEndpoint.php"
HIDE="app/Domain/Core/Services/AutoHideService.php"

FILES=("$SVC" "$CAP" "$VIEW" "$EP" "$HIDE")

SNAPDIR="$(mktemp -d)"
for f in "${FILES[@]}"; do
    [ -f "$f" ] || { echo "FATAL: $f missing"; exit 2; }
    cp "$f" "$SNAPDIR/$(echo "$f" | tr '/' '_')" || { echo "FATAL: snapshot failed for $f"; exit 2; }
done

restore_all () {
    for f in "${FILES[@]}"; do
        local snap="$SNAPDIR/$(echo "$f" | tr '/' '_')"
        cp "$snap" "$f" || { echo "FATAL: RESTORE FAILED for $f — ABORTING"; exit 3; }
        if ! cmp -s "$snap" "$f"; then
            echo "FATAL: $f differs from its snapshot after restore — ABORTING"
            exit 3
        fi
    done
}

# The whole reporting surface, in one filter — a mutation may legitimately
# break a case in a sibling suite, and hiding that would be the point missed.
ALL_FILTER='AnnouncementReportTargetTest|AnnouncementCapabilityResolverTest|AnnouncementViewServiceTest'

# A positive control FIRST: if the suites are not green unmutated, every
# "killed" below is meaningless because the case was already failing.
echo "── Baseline (unmutated) ──────────────────────────────────────────────────"
if ! $PHP "$PHPUNIT" -c "$CONFIG" --no-coverage --filter "$ALL_FILTER" >/dev/null 2>&1; then
    echo "FATAL: the suites are NOT green before any mutation — fix that first."
    echo "       (A red baseline makes every kill below unattributable.)"
    exit 2
fi
echo "  green — kills below are attributable to their mutation"
echo

killed=0; survived=0; broken=0

# mutate <file> <php-replacement-script> <phpunit-filter> <label>
#
# ⚠ The replacement runs as `php -r "$script" <file>` and MUST exit non-zero
#   unless it replaced its needle exactly once. Anchors are written with the
#   file's own line ending computed at run time: this repo checks out CRLF,
#   but a file freshly created on a branch can still be LF, and a \n-only
#   anchor matches nothing and would be reported `broken` rather than
#   `survived`.
mutate () {
    local file="$1" replacement="$2" filter="$3" label="$4"
    local before after out rc

    before="$($PHP -r 'echo md5_file($argv[1]);' "$file")"
    if ! $PHP -r "$replacement" "$file"; then
        echo "  broken       $label (mutation script failed — the control tested nothing)"
        broken=$((broken + 1)); restore_all; return
    fi
    after="$($PHP -r 'echo md5_file($argv[1]);' "$file")"

    if [ "$before" = "$after" ]; then
        echo "  broken       $label (no bytes changed — the control tested nothing)"
        broken=$((broken + 1)); restore_all; return
    fi

    out="$($PHP "$PHPUNIT" -c "$CONFIG" --no-coverage --filter "$filter" 2>&1)"
    rc=$?
    restore_all

    if [ $rc -ne 0 ]; then
        echo "  killed       $label  [$filter]"
        killed=$((killed + 1))
    else
        echo "  survived     $label  [$filter]"
        echo "$out" | tail -3 | sed 's/^/                 /'
        survived=$((survived + 1))
    fi
}

echo "── the accepted kinds ────────────────────────────────────────────────────"

# ── M1. The route stops accepting the kind — the pre-fix state exactly ──────
mutate "$SVC" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "public const TARGET_KINDS = [self::KIND_FEED_ITEM, self::KIND_ANNOUNCEMENT];";
$new = "public const TARGET_KINDS = [self::KIND_FEED_ITEM];";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheAcceptedTargetKindsAreExactlyFeedItemAndAnnouncement' \
  'M1 announcement is not an accepted target kind'

echo "── server-side resolution ────────────────────────────────────────────────"

# ── M2. The announcement arm resolves a FEED author ─────────────────────────
#
# The natural wrong fix: reuse the resolver that already exists. It looks up
# `peepso_activities` BY THE ANNOUNCEMENT ID, so it answers about whatever
# activity happens to carry that number — or 0, which disables the
# self-report check entirely.
mutate "$SVC" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "\$resolved = \$this->resolveAnnouncementTarget(\$targetId);";
$new = "\$resolved = [\x27author_id\x27 => self::resolveFeedItemAuthor(\$targetId)];";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheAuthorResolutionIsDispatchedPerKind' \
  'M2 the announcement arm resolves a feed activity author'

# ── M3. ⚠ REPORTABILITY BECOMES A STATUS CHECK — reads as a simplification ──
mutate "$SVC" '
$f = $argv[1]; $s = file_get_contents($f);
$nl  = strpos($s, "\r\n") === false ? "\n" : "\r\n";
$old = str_replace("\n", $nl, "            \$row = (new AnnouncementRepository())->findPublic(\n                (int) \$post->post_parent,\n                \$announcementId\n            );");
$new = "            \$row = \$post->post_status === \x27publish\x27 ? \$post : null;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testReportabilityIsDecidedByThePublicReadabilityPredicateNotByStatus' \
  'M3 reportability decided by post_status, not the readability predicate'

# ── M4. ⚠ THE PAGE COMES FROM THE REQUEST — reads as ordinary plumbing ──────
mutate "$SVC" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "                (int) \$post->post_parent,";
$new = "                (int) (\$_POST[\x27page_id\x27] ?? 0),";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheAnnouncementResolverTakesOnlyTheNoticeIdAndDerivesTheRest' \
  'M4 the page id is taken from the request'

# ── M5. An unreadable database answers "not found" ──────────────────────────
#
# Reads as consistency — one error shape for one route. It makes a provider
# outage indistinguishable from deleted content, so a reader is told the
# notice does not exist at the moment the database cannot say.
mutate "$SVC" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            return [\x27error\x27 => \x27bcc_unavailable\x27, \x27message\x27 => \x27Could not file the report. Try again.\x27];";
$new = "            return [\x27error\x27 => \x27bcc_not_found\x27, \x27message\x27 => \x27Not found.\x27];";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheResolverAnswersNotFoundTwiceAndUnavailableOnce' \
  'M5 a failed read answers not-found instead of unavailable'

echo "── the wire status ──────────────────────────────────────────────────────"

# ── M6. The 404 mapping is removed, so not-found becomes 400 ────────────────
#
# A 400 tells a prober the id was well-formed but refused, which is exactly
# the distinction §4.32.1 spends three predicates avoiding.
mutate "$EP" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            \x27bcc_not_found\x27       => 404,";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, "", $s));
' 'testTheEndpointMapsEveryServiceErrorCodeToItsContractStatus' \
  'M6 bcc_not_found falls through to 400'

echo "── the auto-hide exclusion ──────────────────────────────────────────────"

# ── M7. The target-kind gate is removed from the auto-hide handler ──────────
#
# ⚠ This is the one §4.32.16 exists for: an announcement projects exactly one
# feed activity, and without the gate a new target kind reaches the
# distinct-reporter counter — three reporters would take down an official
# notice with no operator and no administrator in the loop.
mutate "$HIDE" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (\$targetKind !== \x27feed_item\x27 || \$targetId <= 0) {";
$new = "        if (\$targetId <= 0) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAnAnnouncementReportNeverReachesTheAutoHideCounter' \
  'M7 any target kind reaches the auto-hide counter'

echo "── can_report consistency ───────────────────────────────────────────────"

# ── M8. The self-report rung is disabled ────────────────────────────────────
#
# Restores the dead control: `can_report` allowed on every row of the owner
# surface, whose reads bind post_author to the resolved operator, while the
# route answers 400.
mutate "$CAP" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (\$authorId > 0 && \$authorId === \$viewerId) {";
$new = "        if (false) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheAuthorCannotReportTheirOwnNoticeAndAStrangerStillCan' \
  'M8 the self-report rung never fires'

# ── M9. The id reader stops validating, and casts instead ───────────────────
#
# `(int) " 42"` is 42, so an untrimmed stored value matches user 42 and the
# author of one row silently becomes the author of another. The `===`
# comparison is only as strict as what produced its operands.
mutate "$CAP" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (!is_int(\$value) && !(is_string(\$value) && \$value !== \x27\x27 && ctype_digit(\$value))) {";
$new = "        if (!is_int(\$value) && !is_string(\$value)) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAnUnreadableAuthorLeavesTheNoticeReportable' \
  'M9 the id reader casts instead of validating'

echo "── the view-service wiring, both directions ─────────────────────────────"

# ── M10. The author is never passed to the gate ────────────────────────────
mutate "$VIEW" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "                \x27author_user_id\x27   => self::str(\$row, \x27post_author\x27),";
$new = "                \x27author_user_id\x27   => 0,";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheDetailViewPassesTheAuthorToTheReportGateAndNeverToTheWire' \
  'M10 the author never reaches the report gate'

# ── M11. ⚠ THE AUTHOR IS ALSO PUT ON THE WIRE — reads as a convenience ──────
mutate "$VIEW" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        \$model[\x27status\x27] = \$status;";
$new = "        \$model[\x27status\x27] = \$status;\n        \$model[\x27author_user_id\x27] = (int) self::str(\$row, \x27post_author\x27);";
if (substr_count($s, $old) !== 1) { exit(1); }
$nl = strpos($s, "\r\n") === false ? "\n" : "\r\n";
file_put_contents($f, str_replace($old, str_replace("\n", $nl, $new), $s));
' 'testTheDetailViewPassesTheAuthorToTheReportGateAndNeverToTheWire' \
  'M11 the author id is disclosed on the view-model'

echo "─────────────────────────────────────────────────────────────────────────"
echo "  killed: $killed   survived: $survived   broken: $broken"
rm -rf "$SNAPDIR"
[ "$survived" -eq 0 ] && [ "$broken" -eq 0 ] && [ "$killed" -gt 0 ]
