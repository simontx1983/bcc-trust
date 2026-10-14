#!/usr/bin/env bash
#
# Mutation controls for the announcement capability surface
# (Validator Announcements A2.1; api-contract-v1.md §4.32.11).
#
# Each control breaks ONE guarantee in executable code and runs the test NAMED
# for that guarantee. Verdicts:
#
#   killed        the named test failed with the guarantee broken
#   survived      everything stayed green with the guarantee broken
#   broken        the mutation changed no bytes (it tested nothing)
#
# ⚠⚠⚠ THE THREE MUTATIONS THAT MATTER MOST ARE M2, M3 AND M10, AND NONE OF THEM
#   LOOKS LIKE A BUG.
#
#   M2 makes `can_manage_comments` deny on an archived row — which reads as
#   consistency ("archived means read-only, so deny everything"). §4.32.7 says
#   the opposite: an archived discussion stays visible and read-only WITH
#   operator moderation intact. The mutation produces a closed discussion that
#   nobody can clean up, and every other archived-row assertion still passes.
#
#   M3 checks "is it published" before "is it archived" in the pin ladder —
#   which reads as cheapest-check-first. An archived row then reports
#   `announcement_not_published`, which is false (it WAS published) and is the
#   one reason that tells the client "publish it and try again". The capability
#   is still denied, so no test of the OUTCOME notices.
#
#   M10 adds a public producer for the public list. Nothing is wired to it, so
#   nothing changes at runtime — but it is the first half of the change that
#   puts owner capabilities and private states onto a cacheable public
#   response, and the structural guarantee is that the producer does not exist.
#
# ⚠ M11 exists because the OBVIOUS test of the §11 delegation cannot catch its
#   own failure mode: a re-copied literal returns an identical array, so
#   behavioural equality passes. Only the source assertion kills it.
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE (md5 before/after).
# ⚠ RESTORE FROM A BYTE SNAPSHOT TAKEN ONCE, NEVER FROM GIT, and abort on a
#   failed restore.
# ⚠ ANCHORS COMPUTE THE FILE'S LINE ENDING. This repo checks out CRLF, but a
#   file created in this branch and not yet round-tripped through git is LF. A
#   \n-only anchor matches nothing and is reported `broken`.
#
# Usage: bash scripts/tests/announcement-capability-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d memory_limit=2G}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

CAPS="app/Domain/Core/Announcements/AnnouncementCapabilityResolver.php"
PERM="app/Domain/Core/Support/Permission.php"
CARD="app/Domain/Core/Services/CardViewService.php"
HIDE="app/Domain/Core/Services/AutoHideService.php"
POLICY="app/Domain/Core/Announcements/AnnouncementModerationPolicy.php"

SNAPDIR="$(mktemp -d)"
for f in "$CAPS" "$PERM" "$CARD" "$HIDE" "$POLICY"; do
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

echo "── Announcement capability surface mutation controls ──────────────────────"

# ── M1. FEATURE-OFF EMITS A DENIED BLOCK INSTEAD OF OMITTING IT ─────────────
#
# Reads as more informative: "tell the client it cannot create". §4.32.11 says
# presence of the block IS the gate, so an emitted-but-denied block makes the
# client mount the surface, open the tab and the bar, and issue requests that
# are all going to 404.
mutate "$CAPS" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "        if (!\$featureEnabled) {" . $E . "            return null;" . $E . "        }";
$new = "        if (false) {" . $E . "            return null;" . $E . "        }";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testFeatureOffOmitsTheOwnerBlockEntirely' 'M1 feature-off emits a denied block instead of null'

# ── M2. ⚠ ARCHIVAL CLOSES MODERATION TOO — reads as consistency ─────────────
#
# Route can_manage_comments through the same ladder as edit and archive. Every
# other archived assertion still passes; what breaks is the ability to clean up
# a discussion that can no longer be edited.
mutate "$CAPS" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "self::manageComments(\$isOperator, \$featureEnabled)";
$new = "self::ownerAction(\$isOperator, \$archived, \$featureEnabled)";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testArchivalClosesEditPinAndCommentButLeavesModerationOpen' 'M2 archival closes operator moderation'

# ── M3. ⚠ THE PIN RUNGS ARE REORDERED — reads as cheapest-check-first ───────
#
# An archived row now reports announcement_not_published. Still denied, so the
# outcome is unchanged; the reason is false, and it is the one reason that
# implies a retry could succeed.
mutate "$CAPS" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "        if (\$archived) {" . $E
     . "            return Permission::deny(self::REASON_ARCHIVED);" . $E
     . "        }" . $E
     . "        if (!\$published) {" . $E
     . "            return Permission::deny(self::REASON_NOT_PUBLISHED);" . $E
     . "        }";
$new = "        if (!\$published) {" . $E
     . "            return Permission::deny(self::REASON_NOT_PUBLISHED);" . $E
     . "        }" . $E
     . "        if (\$archived) {" . $E
     . "            return Permission::deny(self::REASON_ARCHIVED);" . $E
     . "        }";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testArchivedBeatsNotPublishedWhenBothWouldApply' 'M3 pin reports not-published for an archived row'

# ── M4. OWNERSHIP BECOMES A COMMENT-GATE RUNG ───────────────────────────────
#
# Reads as symmetry with the other four capabilities. It puts the generic
# ownership reason — which is OUTSIDE §4.32.11's closed inventory — on the
# comment composer of every ordinary member, who would see a control disabled
# with no copy, because the frontend closes on an unrecognised reason.
# ⚠ ANCHORED ON THE ARCHIVED RUNG, not on the not-published one. The
# original needle matched `if (!$published) { ... REASON_UNAVAILABLE; }`,
# which was unique until §4.32.16 added can_report with the same opening
# rung -- at which point the needle matched TWICE, the mutation refused to
# apply, and the control reported `broken`. A control that tests nothing is
# worse than no control, because the run still looks like it passed.
# `if ($archived)` is the comment gate's next rung and report() has none.
mutate "$CAPS" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "            return Permission::deny(self::REASON_UNAVAILABLE);" . $E
     . "        }" . $E
     . "        if (\$archived) {" . $E
     . "            return Permission::deny(self::REASON_ARCHIVED);";
$new = "            return Permission::deny(self::REASON_NOT_CLAIMER);" . $E
     . "        }" . $E
     . "        if (\$archived) {" . $E
     . "            return Permission::deny(self::REASON_ARCHIVED);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheCommentGateNeverReportsAnOwnershipReason|testEveryCommentReasonEmittedIsInTheClosedInventory' 'M4 the comment gate reports an ownership reason'

# ── M5. A MALFORMED FLAG IS CAST INSTEAD OF REFUSED ─────────────────────────
#
# Reads as tolerant input handling. A DB tinyint read back as the string "0", a
# JSON-decoded null, an absent key — each now resolves to a value rather than
# to the restrictive default. `is_archived` absent then reads as NOT archived.
mutate "$CAPS" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "        if (!is_bool(\$value)) {" . $E . "            return \$absent;" . $E . "        }";
$new = "        if (!is_bool(\$value)) {" . $E . "            return (bool) \$value;" . $E . "        }";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAMalformedArchivedFlagReadsAsArchived|testAPartialViewerArrayFailsClosed|testAnAbsentCommentsEnabledFlagClosesTheDiscussion' 'M5 a malformed flag is cast rather than refused'

# ── M6. THE WRITE VERB IS ACCEPTED AS THE READ STATE ───────────────────────
#
# §4.32.13's two vocabularies share the key `status` and deliberately do NOT
# share values: `publish` is a verb, `published` is a state. `publish` is also
# the raw WordPress `post_status`. So "normalise publish to published" reads
# like harmless tolerance in three different ways at once — and it is the one
# edit that makes a row satisfy the published check without §4.32.1's
# authorization marker ever having been verified.
mutate "$CAPS" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$q = chr(39);
$old = "        \$status = \$row[" . $q . "status" . $q . "] ?? null;";
$new = $old . $E
     . "        if (\$status === " . $q . "publish" . $q . ") { \$status = self::STATUS_PUBLISHED; }";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAnUnrecognisedStatusIsNotPublished' 'M6 the write verb publish is accepted as the published state'

# ── ⚠ RECORDED NON-CONTROL: the status whitelist is DEPTH, not load-bearing ─
#
# An earlier M6 replaced the whitelist with `return $status;` — trusting the
# stored value unvalidated — and it SURVIVED. That result is correct and worth
# keeping written down rather than quietly replacing: the only consumer of
# `status()` compares with `=== self::STATUS_PUBLISHED`, so an unrecognised
# value fails that comparison whether or not it was first collapsed to
# `draft`. The whitelist buys nothing TODAY.
#
# It is kept anyway, because the second consumer — an owner-list response that
# serializes the state, or a transition check that switches on it — is the one
# that would start trusting the raw value, and by then the whitelist's absence
# would be invisible. M6 above attacks the mapping instead, which is the part
# that is load-bearing now.

# ── M7. ANY TRUTHY DECISION IS AN OPERATOR ─────────────────────────────────
#
# Reads as defensive loosening. It is the §4.32.2 hole: a caller handing in
# `[decision => true]` — or any array built from post_author, a mirror claim
# row, PeepSo membership or a read model — is now the operator.
mutate "$CAPS" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return (\$decision[\"decision\"] ?? null) === ValidatorPageOperatorAuthority::DECISION_OPERATOR;";
$old = str_replace(chr(34), chr(39), $old);
$new = "        return !empty(\$decision[" . chr(39) . "decision" . chr(39) . "]);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testOnlyTheAuthoritysDecisionShapeGrants' 'M7 any truthy decision reads as operator'

# ── M8. THE CAPABILITY READ BECOMES LOOSE ──────────────────────────────────
#
# `== true` instead of `=== true`. Every consumer of a capability map that
# arrived from a cache, a filter or a decoded payload now grants on the string
# "false", which is truthy in PHP. §4.32.11 requires absence AND malformation
# to fail closed.
mutate "$PERM" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return (\$entry[" . chr(39) . "allowed" . chr(39) . "] ?? null) === true;";
$new = "        return (\$entry[" . chr(39) . "allowed" . chr(39) . "] ?? null) == true;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testIsAllowedFailsClosedOnAnythingButLiteralTrue' 'M8 the capability read becomes a loose comparison'

# ── M9. THE WIRE REASON BECOMES A SECOND LITERAL ───────────────────────────
#
# Reads as removing a pointless indirection — the value is identical today.
# Two literals is exactly how the authority's private reasons drift back onto
# the wire: the next edit to the authority's PUBLIC_REASON leaves this copy
# behind, and §4.32.2's "leaks neither operator identity nor claim count" is
# silently no longer true.
mutate "$CAPS" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "    public const REASON_NOT_CLAIMER = ValidatorPageOperatorAuthority::PUBLIC_REASON;";
$new = "    public const REASON_NOT_CLAIMER = " . chr(39) . "not_claimer" . chr(39) . ";";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheWireReasonIsReExportedFromTheAuthorityNotRedeclared' 'M9 the generic wire reason becomes a second literal'

# ── M10. ⚠ A PUBLIC-LIST PRODUCER APPEARS ──────────────────────────────────
#
# Nothing is wired to it, so nothing changes at runtime. That is precisely why
# it has to fail here: the structural guarantee behind "the public list carries
# no capabilities, for any viewer including the operator" is that there is no
# producer to call. Once one exists, a serializer can reach it, and the failure
# mode is a private state on a cacheable public response.
mutate "$CAPS" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "    // ── internals ";
$new = "    /** @return array<string, Entry> */" . $E
     . "    public static function forPublicList(array \$decision, bool \$featureEnabled): array" . $E
     . "    {" . $E
     . "        return \$featureEnabled && self::isOperator(\$decision)" . $E
     . "            ? [" . chr(39) . "can_create" . chr(39) . " => Permission::allow()]" . $E
     . "            : [];" . $E
     . "    }" . $E
     . $E
     . "    // ── internals ";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testThereIsNoProducerAPublicResponseCouldCall' 'M10 a public-list capability producer appears'

# ── M11. THE §11 DELEGATION BECOMES A FIFTEENTH COPY ───────────────────────
#
# ⚠ The mutated method returns a BYTE-IDENTICAL array, so every behavioural
# assertion passes. This control exists to prove the suite does not rely on
# them: only the source assertion distinguishes a forwarder from a duplicate,
# and without it the registry's "canonical producer" would quietly become one
# of two implementations again.
mutate "$CARD" '
$f = $argv[1]; $s = file_get_contents($f);
$q = chr(39);
$old = "        return PermissionShape::allow();";
$new = "        return [" . $q . "allowed" . $q . " => true, " . $q . "unlock_hint" . $q . " => null, " . $q . "reason_code" . $q . " => null];";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testCardViewServiceDelegatesToTheSharedPermissionShape' 'M11 the canonical producer re-implements the shape'

# ── M12. A WORDPRESS READ APPEARS IN THE PURE RESOLVER ─────────────────────
#
# Planted in a branch that never executes, so no behaviour changes and no other
# test can see it. The source-invariant group is what must catch it — and this
# control also proves that group's comment-stripping works in the right
# direction: the docblocks DISCUSS every forbidden token in order to forbid it,
# so a naive whole-file grep would already be failing.
mutate "$CAPS" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$q = chr(39);
$old = "        \$value = \$source[\$key] ?? null;";
$new = "        if (\$key === " . $q . "__never" . $q . ") { current_user_can(" . $q . "x" . $q . "); }" . $E
     . "        \$value = \$source[\$key] ?? null;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheResolverNamesNoForbiddenAuthorizationInput' 'M12 a WordPress capability read appears in the pure resolver'

# ── M13. ⚠⚠ REPORTING CLOSES ON AN ARCHIVED ROW — reads as consistency ─────
#
# The single most likely wrong edit in the whole reportability change: copy
# the comment gate, which DOES close on archival. §4.32.6 keeps an archived
# announcement publicly readable at its permalink, so it stays challengeable
# -- and `announcement_archived` is absent from CAN_REPORT_REASONS precisely
# so this cannot be added without the inventory test also failing.
mutate "$CAPS" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "            " . chr(39) . "can_report" . chr(39) . "          => self::report(\$row, \$viewer, \$published, \$featureEnabled),";
$new = "            " . chr(39) . "can_report" . chr(39) . "          => self::comment(\$row, \$viewer, \$archived, \$published, \$featureEnabled),";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheReportGateDivergesFromTheCommentGateInExactlyTwoWays|testEveryReportReasonEmittedIsInTheClosedInventory' 'M13 reporting closes on an archived row'

# ── M14. ⚠⚠ THE AUTO-HIDE EXCLUSION IS COMPUTED AND IGNORED ───────────────
#
# The decision is still made and still logged -- only the `return` goes. So
# every value test of the policy still passes, the log still says the hide
# was withheld, and the announcement is hidden anyway. This is why the
# policy suite reads the CALLER'S SOURCE instead of trusting that calling
# the policy means honouring it.
mutate "$HIDE" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "            }" . $E . "            return;" . $E . "        }";
$new = "            }" . $E . "        }";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAutoHideServiceConsultsThePolicyBeforeItsOnlyWrite' 'M14 the auto-hide exclusion is computed but not honoured'

# ── M15. AN UNREADABLE MODULE BECOMES HIDEABLE ────────────────────────────
#
# Reads as not punishing ordinary content for a transient read failure, and
# it is the trade-off §4.32.16 decided the other way: an activity nobody can
# identify is not one to hide, and auto-hiding an announcement is the one
# outcome the decision forbids.
mutate "$POLICY" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "        if (\$module === " . chr(39) . chr(39) . ") {" . $E
     . "            return self::AUTO_HIDE_BLOCKED_UNRESOLVED;" . $E
     . "        }";
$new = "        if (\$module === " . chr(39) . chr(39) . ") {" . $E
     . "            return self::AUTO_HIDE_ALLOWED;" . $E
     . "        }";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testAnUnreadableModuleBlocksTheAutomaticHide' 'M15 an unreadable module becomes hideable'

# ── M16. THE MODULE CONSTANT BECOMES AN INT ───────────────────────────────
#
# ⚠ Fails in the SAFE-LOOKING direction. `act_module_id` comes back from
# MySQL as a string, so an int constant makes the strict comparison never
# match: the exclusion never fires, every ordinary-content test still
# passes, and nothing anywhere looks wrong.
mutate "$POLICY" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "    public const ANNOUNCEMENT_MODULE_ID = " . chr(39) . "205" . chr(39) . ";";
$new = "    public const ANNOUNCEMENT_MODULE_ID = 205;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheModuleConstantIsAStringNotAnInt|testAnAnnouncementActivityIsNeverAutoHidden' 'M16 the module constant becomes an int'
echo "──────────────────────────────────────────────────────────────────────────"
echo "killed=$killed survived=$survived broken=$broken"
rm -rf "$SNAPDIR"

# A survivor is a test that does not test what it claims; a broken control is a
# control that tested nothing. Both fail the run.
[ "$survived" -eq 0 ] && [ "$broken" -eq 0 ] || exit 1
