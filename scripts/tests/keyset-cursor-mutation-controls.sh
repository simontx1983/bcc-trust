#!/usr/bin/env bash
#
# Mutation controls for the keyset cursor codec
# (Validator Announcements A2.3; api-contract-v1.md §1.5 / §4.32.12).
#
# Each control breaks ONE guarantee in executable code and runs the test NAMED
# for that guarantee. Verdicts:
#
#   killed        the named test failed with the guarantee broken
#   survived      everything stayed green with the guarantee broken
#   broken        the mutation changed no bytes (it tested nothing)
#
# ⚠⚠⚠ THE TWO MUTATIONS THAT MATTER MOST ARE M2 AND M5, AND BOTH ARE EDITS
#   SOMEBODY WOULD MAKE ON PURPOSE.
#
#   M2 adds a "missing surface means legacy" fallback. That is EXACTLY the
#   accommodation CommentService carries (`$data['k'] ?? $data['t']`), and it
#   is why BlogService's documented "surfaces don't accidentally cross-decode
#   each other" is not true today: a blog cursor satisfies it and decodes as a
#   comment cursor. The fallback reads as compatibility and silently restores
#   the defect this class exists to remove.
#
#   M5 makes the id a cast instead of a validated integer. `(int) '4471abc'`
#   is 4471, so a tampered or truncated cursor yields a PLAUSIBLE page rather
#   than an obvious reset — the reader loses or repeats rows and nothing in
#   the response says so. The failure is invisible by construction, which is
#   why it needs a control rather than a review.
#
# ⚠ M7 exists because the obvious signature test cannot catch its own failure
#   mode: giving `$id` a default is a one-character change that reintroduces
#   unstable paging and breaks no VALUE assertion, because every existing
#   caller still passes it.
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE (md5 before/after).
# ⚠ RESTORE FROM A BYTE SNAPSHOT TAKEN ONCE, NEVER FROM GIT, and abort on a
#   failed restore.
# ⚠ ANCHORS COMPUTE THE FILE'S LINE ENDING. This repo checks out CRLF, but a
#   file created in this branch and not yet round-tripped through git is LF. A
#   \n-only anchor matches nothing and is reported `broken`.
#
# Usage: bash scripts/tests/keyset-cursor-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d memory_limit=2G}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

CUR="app/Domain/Core/Support/KeysetCursor.php"

SNAPDIR="$(mktemp -d)"
cp "$CUR" "$SNAPDIR/$(basename "$CUR").orig" || { echo "FATAL: snapshot failed"; exit 2; }

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

echo "── Keyset cursor mutation controls ───────────────────────────────────────"

# ── M1. THE SURFACE CHECK IS DROPPED ────────────────────────────────────────
#
# Every surface then decodes every other surface's cursors. The active and
# archived lists order by DIFFERENT columns (published_at vs archived_at), so
# applying one to the other skips or repeats rows silently.
mutate "$CUR" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if ((\$data[" . chr(39) . "s" . chr(39) . "] ?? null) !== \$expectedSurface) {";
$new = "        if (false) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testACursorFromOneSurfaceIsRefusedByEveryOther|testTheLegacyCrossDecodingPayloadIsRefused' 'M1 the surface check is dropped'

# ── M2. ⚠⚠ "A MISSING SURFACE MEANS LEGACY" — reads as compatibility ───────
#
# The exact shape of CommentService's `?? $data['t']` fallback, and the exact
# reason BlogService's documented isolation is untrue today. A surface-less
# `{"t":…,"id":…}` cursor would decode again.
mutate "$CUR" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if ((\$data[" . chr(39) . "s" . chr(39) . "] ?? null) !== \$expectedSurface) {";
$new = "        if (isset(\$data[" . chr(39) . "s" . chr(39) . "]) && \$data[" . chr(39) . "s" . chr(39) . "] !== \$expectedSurface) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAValidVersionWithNoSurfaceIsStillRefused|testACursorFromOneSurfaceIsRefusedByEveryOther' 'M2 a missing surface is treated as legacy-compatible'

# ── M3. THE VERSION CHECK BECOMES LOOSE ────────────────────────────────────
#
# `==` instead of `===`. `"1"` and `true` then both satisfy version 1, so a
# hand-built or re-serialized payload is read as a format this class knows.
mutate "$CUR" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if ((\$data[" . chr(39) . "v" . chr(39) . "] ?? null) !== self::VERSION) {";
$new = "        if ((\$data[" . chr(39) . "v" . chr(39) . "] ?? null) != self::VERSION) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAnUnknownVersionIsRefused' 'M3 the version check becomes a loose comparison'

# ── M4. base64 DECODING BECOMES NON-STRICT ─────────────────────────────────
#
# Without the strict flag, base64_decode silently ignores invalid characters
# instead of returning false — so a corrupted cursor decodes to SOMETHING,
# which then either fails JSON parsing (harmless) or, worse, parses.
mutate "$CUR" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "base64_decode(strtr(\$cursor, " . chr(39) . "-_" . chr(39) . ", " . chr(39) . "+/" . chr(39) . "), true)";
$new = "base64_decode(strtr(\$cursor, " . chr(39) . "-_" . chr(39) . ", " . chr(39) . "+/" . chr(39) . "))";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testACursorWithJunkInsertedIsRefusedRatherThanSilentlyRepaired' 'M4 base64 decoding becomes non-strict'

# ── M5. ⚠⚠ THE ID BECOMES A CAST — the invisible failure ───────────────────
#
# `(int) '4471abc'` is 4471. A tampered or truncated cursor yields a PLAUSIBLE
# page rather than a reset, so the reader silently loses or repeats rows and
# nothing in the response indicates it.
mutate "$CUR" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "        \$rawId = \$data[" . chr(39) . "id" . chr(39) . "] ?? null;";
$new = $old . $E . "        return [" . chr(39) . "key" . chr(39) . " => \$key, " . chr(39) . "id" . chr(39) . " => (int) \$rawId];";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testANonIntegralIdIsRefusedRatherThanCoerced' 'M5 the id becomes a cast instead of a validated integer'

# ── M6. AN EMPTY KEY IS ACCEPTED ───────────────────────────────────────────
#
# An empty sort key cannot order anything, so the page boundary becomes
# whatever the id comparison alone decides — a different, wrong page.
mutate "$CUR" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (!is_string(\$key) || \$key === " . chr(39) . chr(39) . ") {";
$new = "        if (!is_string(\$key)) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testANonStringOrEmptyKeyIsRefused' 'M6 an empty sort key is accepted'

# ── M7. ⚠ THE UNIQUE TIEBREAK BECOMES OPTIONAL ─────────────────────────────
#
# One character. Every existing caller still passes the id, so no VALUE
# assertion changes — but a caller can now mint a cursor carrying only a
# timestamp, and §4.32.12 says that is not a total order: two announcements
# published in the same second page unstably, and pinning moves a row to the
# front mid-scroll.
mutate "$CUR" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "    public static function encode(string \$surface, string \$key, int \$id): string";
$new = "    public static function encode(string \$surface, string \$key, int \$id = 0): string";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheUniqueTiebreakCannotBeOmitted' 'M7 the unique tiebreak becomes optional'

# ── M8. THE LENGTH CAP IS DROPPED ──────────────────────────────────────────
#
# Unbounded base64 + JSON decoding of an attacker-supplied string on a public
# read path. Not a correctness bug, which is why it needs its own control.
mutate "$CUR" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (strlen(\$cursor) > self::MAX_ENCODED_LENGTH) {";
$new = "        if (false) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheLengthCapIsCheckedBeforeAnyDecodingWork' 'M8 the encoded-length cap is dropped'

# ── M9. MINTING STOPS REFUSING WHAT DECODING REJECTS ───────────────────────
#
# encode() would then emit a cursor decode() refuses: the caller returns it as
# next_cursor, the next request silently restarts the list, and the reader
# loses their place with no error anywhere.
mutate "$CUR" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (\$id <= 0) {" . PHP_EOL;
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "        if (\$id <= 0) {" . $E . "            throw new \\InvalidArgumentException(" . chr(39) . "Cursor id must be a positive row id." . chr(39) . ");";
$new = "        if (false) {" . $E . "            throw new \\InvalidArgumentException(" . chr(39) . "Cursor id must be a positive row id." . chr(39) . ");";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testEncodeRefusesWhatDecodeWouldReject' 'M9 minting stops refusing a non-positive id'

# ── M10. THE ENCODING STOPS BEING URL-SAFE ─────────────────────────────────
#
# Plain base64 puts `+`, `/` and `=` in a query-string value, where they are
# re-read as a space, a path separator and a parameter break. The cursor still
# round-trips in a test that never puts it in a URL, which is exactly why the
# URL-safety assertion is separate from the round-trip one.
mutate "$CUR" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return rtrim(strtr(base64_encode(\$payload), " . chr(39) . "+/" . chr(39) . ", " . chr(39) . "-_" . chr(39) . "), " . chr(39) . "=" . chr(39) . ");";
$new = "        return base64_encode(\$payload);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheEncodingIsUrlSafe' 'M10 the encoding stops being URL-safe'

echo "──────────────────────────────────────────────────────────────────────────"
echo "killed=$killed survived=$survived broken=$broken"
rm -rf "$SNAPDIR"

# A survivor is a test that does not test what it claims; a broken control is a
# control that tested nothing. Both fail the run.
[ "$survived" -eq 0 ] && [ "$broken" -eq 0 ] || exit 1
