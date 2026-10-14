#!/usr/bin/env bash
#
# Mutation controls for the announcement READ path
# (Validator Announcements A2.3a/b; api-contract-v1.md §4.32.1, §4.32.11,
# §4.32.12).
#
# Each control breaks ONE guarantee in executable code and runs the test NAMED
# for that guarantee. Verdicts:
#
#   killed        the named test failed with the guarantee broken
#   survived      everything stayed green with the guarantee broken
#   broken        the mutation changed no bytes (it tested nothing)
#
# ⚠ These controls exercise the UNIT suite only, so they run anywhere. The
#   SQL-bearing guarantees (the three visibility predicates, keyset paging,
#   the owner-list binding, fail-closed reads) are covered by
#   AnnouncementRepositoryIntegrationTest, which needs a real engine — see
#   M8/M9 for the two that can be reached statically.
#
# ⚠⚠ THE THREE THAT MATTER MOST ARE M1, M2 AND M6, AND NONE LOOKS LIKE A BUG.
#
#   M1 lets the public row carry capabilities. It is one line, it reads as
#   "the owner should see their controls", and it puts a private state on a
#   response that §4.32.11 makes CACHEABLE for every viewer.
#
#   M2 gives the public producer a viewer parameter. Nothing changes at
#   runtime — but it is the first half of every leak this design exists to
#   prevent, because once the signature can express a viewer, a later edit
#   can branch on it.
#
#   M6 mints a cursor from `count($rows) === $limit` instead of the
#   repository's over-fetch. That is CommentService's live defect: an
#   exact-boundary page hands the reader a "next page" that is empty.
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE (md5 before/after).
# ⚠ RESTORE FROM A BYTE SNAPSHOT TAKEN ONCE, NEVER FROM GIT, and abort on a
#   failed restore.
# ⚠ ANCHORS COMPUTE THE FILE'S LINE ENDING. This repo checks out CRLF, but a
#   file created in this branch and not yet round-tripped through git is LF. A
#   \n-only anchor matches nothing and is reported `broken`.
#
# Usage: bash scripts/tests/announcement-read-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d memory_limit=2G}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

VIEW="app/Domain/Core/Services/AnnouncementViewService.php"
REPO="app/Domain/Core/Repositories/AnnouncementRepository.php"

SNAPDIR="$(mktemp -d)"
for f in "$VIEW" "$REPO"; do
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

echo "── Announcement read-path mutation controls ───────────────────────────────"

# ── M1. ⚠⚠ THE PUBLIC ROW GAINS CAPABILITIES ───────────────────────────────
#
# One line, and it reads like a kindness to the operator. §4.32.11: the
# public list is published public data only, for EVERY viewer including the
# operator — and it is viewer-independent precisely so it can be cached.
mutate "$VIEW" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$q = chr(39);
$old = "            " . $q . "links" . $q . "            => [" . $q . "self" . $q . " => self::permalink(\$validatorSlug, \$id)],";
$new = $old . $E . "            " . $q . "capabilities" . $q . "     => [" . $q . "can_edit" . $q . " => [" . $q . "allowed" . $q . " => true]],";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testNoPublicRowCarriesCapabilitiesStatusOrBody|testThePublicRowIsExactlyTheContractFieldSet' 'M1 the public row carries capabilities'

# ── M2. ⚠⚠ THE PUBLIC PRODUCER LEARNS A VIEWER ─────────────────────────────
#
# Nothing changes at runtime — which is exactly why it needs a control. Once
# the signature can express a viewer, a later edit can branch on it, and the
# structural guarantee is gone with no test to notice.
mutate "$VIEW" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "    public static function publicPage(" . PHP_EOL;
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "    public static function publicPage(" . $E . "        array \$rows,";
$new = "    public static function publicPage(" . $E . "        array \$decision = [], " . $E . "        array \$rows,";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testThePublicMethodInventoryIsPinned|testThePublicPageIsByteIdenticalForAnOperatorAndAnAnonymousReader' 'M2 the public producer gains a viewer parameter'

# ── M3. A PUBLIC PRODUCER IS ADDED ─────────────────────────────────────────
#
# Nothing is wired to it, so nothing changes — but the §4.32.11 guarantee is
# that the ONLY public producers are the two that cannot see a viewer.
mutate "$VIEW" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "    // ── shapers (zero queries) ";
$new = "    /** @return array<string, mixed> */" . $E
     . "    public static function publicRowForViewer(object \$row, array \$decision): array" . $E
     . "    {" . $E
     . "        return self::publicRow(\$row, [], " . chr(39) . "x" . chr(39) . ");" . $E
     . "    }" . $E
     . $E
     . "    // ── shapers (zero queries) ";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testThePublicMethodInventoryIsPinned' 'M3 a viewer-aware public producer is added'

# ── M4. CAPABILITIES ARE FILTERED INSTEAD OF PASSED THROUGH ────────────────
#
# Reads as trimming an unused key. It makes this service a SECOND place where
# a capability is decided, so the reportability slice's can_report would
# silently vanish from the owner list.
mutate "$VIEW" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$q = chr(39);
$old = "        \$model[" . $q . "capabilities" . $q . "] = AnnouncementCapabilityResolver::forRow(";
$new = "        \$model[" . $q . "capabilities" . $q . "] = array_slice(AnnouncementCapabilityResolver::forRow(";
if (substr_count($s, $old) !== 1) { exit(1); }
$s = str_replace($old, $new, $s);
$old2 = "            \$featureEnabled" . $E . "        );";
$new2 = "            \$featureEnabled" . $E . "        ), 0, 2, true);";
if (substr_count($s, $old2) !== 1) { exit(1); }
file_put_contents($f, str_replace($old2, $new2, $s));
' 'testCapabilitiesArePassedThroughFromTheResolverVerbatim' 'M4 capabilities are filtered rather than passed through'

# ── M5. `updated_at` ECHOES `published_at` ─────────────────────────────────
#
# §4.32.3 surfaces it only when it differs materially. A duplicate makes the
# client compare timestamps to decide whether to render "edited" — business
# logic the frontend is explicitly not supposed to carry.
mutate "$VIEW" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$q = chr(39);
$old = "        if (\$model[" . $q . "updated_at" . $q . "] === \$model[" . $q . "published_at" . $q . "]) {";
$new = "        if (false) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testUpdatedAtIsSuppressedOnTheOwnerRowWhenItEqualsPublishedAt' 'M5 updated_at echoes published_at'

# ── M6. ⚠⚠ A CURSOR IS MINTED WITHOUT has_more ─────────────────────────────
#
# CommentService's live defect: inferring "there is more" from a full-looking
# page. The reader is handed a next_cursor for an empty page.
mutate "$VIEW" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (!\$hasMore || \$rows === []) {";
$new = "        if (\$rows === []) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAFinalPageMintsNoCursor|testTheRotatorMintsNoCursorEvenWithAFullCap' 'M6 a cursor is minted without consulting has_more'

# ── M7. THE CURSOR KEY IGNORES THE SURFACE ─────────────────────────────────
#
# Every surface then keys on the active list's composite. The archived list
# orders by a DIFFERENT column, so its next page starts in the wrong place —
# and the rows between are silently skipped.
mutate "$VIEW" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (\$surface === KeysetCursor::SURFACE_ANNOUNCEMENTS_ARCHIVED) {";
$new = "        if (false) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheCursorKeyMatchesTheSurfacesOrderBy' 'M7 the cursor key ignores the surface'

# ── M8. `is_archived` IS DERIVED FROM THE RAW META, NOT THE NORMALISED DATE ─
#
# Reads as removing a redundant round trip — "why normalise twice?" — and it
# is how a row with a ZERO DATE (`0000-00-00 00:00:00`) becomes archived.
# Archival is ONE-WAY, so a row wrongly marked archived is stranded in the
# Archived section permanently.
#
# ⚠ RECORDED NON-CONTROL: the first version of M8 removed the empty-string
# guard inside `archivedAtRaw()` and SURVIVED — correctly. The empty case is
# guarded THREE independent ways: that trim-and-null, `iso()`'s explicit
# empty/zero-date check, and `strtotime()` failing on both. No single-line
# mutation can flip it, so no control can kill it. That is defence in depth
# rather than a gap, and it is written down instead of being replaced
# silently. The ZERO-DATE path through `is_archived` is the part that IS
# singly mutable, so the control attacks that.
mutate "$VIEW" '
$f = $argv[1]; $s = file_get_contents($f);
$q = chr(39);
$old = "            " . $q . "is_archived" . $q . "      => self::archivedAt(\$row) !== null,";
$new = "            " . $q . "is_archived" . $q . "      => self::archivedAtRaw(\$row) !== null,";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAnEmptyOrBlankArchivedAtIsNotArchived' 'M8 is_archived derives from the raw meta, not the normalised date'

# ── M9. `is_pinned` BECOMES TRUTHY ─────────────────────────────────────────
#
# Reads as tolerant input handling. The string "false" then reads as pinned,
# and a row jumps to the top of a validator's public page.
mutate "$VIEW" '
$f = $argv[1]; $s = file_get_contents($f);
$q = chr(39);
$old = "        return \$v === " . $q . "1" . $q . ";";
$new = "        return (bool) \$v;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testIsPinnedReadsTheColumnsNumericTruthOnly' 'M9 is_pinned becomes a truthy cast'

# ── M10. COMMENTS DEFAULT CLOSED ───────────────────────────────────────────
#
# §4.32.7 makes discussion the default and `comments_enabled: false` an
# explicit operator choice. Defaulting closed silently disables every
# announcement's discussion.
mutate "$VIEW" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "        if (\$v === null) {" . $E . "            return true;" . $E . "        }";
$new = "        if (\$v === null) {" . $E . "            return false;" . $E . "        }";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testCommentsEnabledDefaultsOpenAndClosesOnlyOnAnExplicitOff' 'M10 comments default to closed'

# ── M11. `blocked` IS SYNTHESISED WITHOUT THE RECONCILER'S MARKER ──────────
#
# Reads as completing the §4.32.13 vocabulary. Nothing distinguishes a
# blocked row from a draft until reconciler arm C writes a marker, so this
# mislabels every ordinary draft as reconciler-flagged.
mutate "$VIEW" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return AnnouncementCapabilityResolver::STATUS_DRAFT;";
$new = "        return AnnouncementCapabilityResolver::STATUS_BLOCKED;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheReadStatusMapsFromPostStatusAndNeverYieldsBlocked' 'M11 blocked is synthesised without a marker'

# ── M12. THE REPOSITORY GROWS AN OFFSET ────────────────────────────────────
#
# The recorded creator-gallery defect: offset with no unique tiebreak,
# 313/313 rows tied, and dedupe CANNOT recover a skipped row. Reached
# statically because the integration suite needs a real engine.
mutate "$REPO" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "                 LIMIT %d";
$new = "                 LIMIT %d OFFSET 0";
if (substr_count($s, $old) < 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheRepositoryEmitsNoOffsetClause' 'M12 the repository grows an OFFSET clause'

echo "──────────────────────────────────────────────────────────────────────────"
echo "killed=$killed survived=$survived broken=$broken"
rm -rf "$SNAPDIR"

# A survivor is a test that does not test what it claims; a broken control is a
# control that tested nothing. Both fail the run.
[ "$survived" -eq 0 ] && [ "$broken" -eq 0 ] || exit 1
