#!/usr/bin/env bash
#
# Mutation controls for the announcement READ ROUTES and the feature switch
# (Validator Announcements A2.4; api-contract-v1.md §4.32.4, §4.32.10,
# §4.32.11, §4.32.13).
#
# Each control breaks ONE guarantee in executable code and runs the test NAMED
# for that guarantee. Verdicts:
#
#   killed        the named test failed with the guarantee broken
#   survived      everything stayed green with the guarantee broken
#   broken        the mutation changed no bytes (it tested nothing)
#
# ⚠⚠ THE THREE THAT MATTER MOST ARE M2, M5 AND M7, AND NONE LOOKS LIKE A BUG.
#
#   M2 moves the feature check BELOW the ownership resolution. Every response
#   stays the same shape; what changes is that a request to a withdrawn
#   surface now resolves a claim first. That is how "off" quietly starts
#   meaning "off, after we check who you are".
#
#   M5 deletes one line — `validate_callback` — from the limit argument. It
#   reads as redundant beside `minimum`/`maximum`. It is not: WordPress only
#   enforces a schema when the argument declares no `sanitize_callback`, so
#   without it `?limit=51` answers 200 with 50 rows on real WordPress 6.7.2
#   while every declared bound still looks right in review. This is the exact
#   defect real dispatch found and a unit harness had hidden.
#
#   M7 gives detail the public cache header. One word, and a shared cache can
#   then hand one viewer the `capabilities` resolved for another.
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE (md5 before/after).
# ⚠ RESTORE FROM A BYTE SNAPSHOT TAKEN ONCE, NEVER FROM GIT, and abort on a
#   failed restore.
# ⚠ ANCHORS COMPUTE THE FILE'S LINE ENDING. This repo checks out CRLF, but a
#   file created in this branch and not yet round-tripped through git is LF. A
#   \n-only anchor matches nothing and is reported `broken`.
#
# ⚠ These controls exercise the UNIT suite only, so they run anywhere. The
#   guarantees that need a live WordPress — real route matching, real schema
#   refusal, real header emission, and the SQL-level proof that a disabled
#   feature performs no authorization and no read — are covered by
#   scripts/tests/announcement-real-dispatch.php, which needs an install.
#
# Usage: bash scripts/tests/announcement-endpoint-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d memory_limit=2G}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

ENDPOINT="app/Domain/Core/REST/AnnouncementsEndpoint.php"
FEATURE="app/Domain/Core/Announcements/AnnouncementFeature.php"

SNAPDIR="$(mktemp -d)"
for f in "$ENDPOINT" "$FEATURE"; do
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

echo "── Announcement endpoint mutation controls ────────────────────────────────"

# ── M1. THE SWITCH BECOMES A BOOL CAST ─────────────────────────────────────
#
# `(bool) get_option(...)` is what almost everyone writes. It enables the
# feature for the string 'off' and for 'false', because both are truthy.
mutate "$FEATURE" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "        foreach (self::TRUTHY as \$on) {";
$new = "        return (bool) \$raw;" . $E . $E . "        foreach (self::TRUTHY as \$on) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheSwitchAcceptsOnlyWordPressOwnTruthyValues' 'M1 the switch becomes a bool cast'

# ── M2. ⚠⚠ THE FEATURE CHECK MOVES BELOW THE OWNERSHIP RESOLUTION ──────────
#
# The owner list still answers 404 while off, so the response is unchanged.
# What changes is that a withdrawn surface resolves a claim first — which is
# both work on a disabled feature and an ordering that a later edit can turn
# into a 403 that confirms the feature exists.
mutate "$ENDPOINT" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$q = chr(39);
$gate = "        if (!AnnouncementFeature::isEnabled()) {" . $E
      . "            return AnnouncementFeature::notFound();" . $E
      . "        }" . $E . $E
      . "        \$pageId = (int) \$request->get_param(" . $q . "pageId" . $q . ");" . $E
      . "        if (\$pageId <= 0) {" . $E
      . "            return self::notFound();" . $E
      . "        }" . $E . $E
      . "        \$viewerId = get_current_user_id();" . $E
      . "        \$decision = Authority::decide(\$pageId, \$viewerId);";
$moved = "        \$pageId = (int) \$request->get_param(" . $q . "pageId" . $q . ");" . $E
      . "        if (\$pageId <= 0) {" . $E
      . "            return self::notFound();" . $E
      . "        }" . $E . $E
      . "        \$viewerId = get_current_user_id();" . $E
      . "        \$decision = Authority::decide(\$pageId, \$viewerId);" . $E . $E
      . "        if (!AnnouncementFeature::isEnabled()) {" . $E
      . "            return AnnouncementFeature::notFound();" . $E
      . "        }";
if (substr_count($s, $gate) !== 1) { exit(1); }
file_put_contents($f, str_replace($gate, $moved, $s));
' 'testTheFeatureCheckPrecedesAuthorityAndAnyRepository' 'M2 the feature check moves below the authority call'

# ── M3. THE OWNER ENUM ACCEPTS `blocked` ───────────────────────────────────
#
# §4.32.13 names `blocked`, so accepting it reads like completeness. It has
# no marker and no post_status, so the request would return ORDINARY DRAFTS
# labelled as if the filter had been honoured.
mutate "$ENDPOINT" '
$f = $argv[1]; $s = file_get_contents($f);
$q = chr(39);
$old = "[" . $q . "draft" . $q . ", " . $q . "scheduled" . $q . "]";
$new = "[" . $q . "draft" . $q . ", " . $q . "scheduled" . $q . ", " . $q . "blocked" . $q . "]";
if (substr_count($s, $old) < 1) { exit(1); }
file_put_contents($f, preg_replace("/" . preg_quote($old, "/") . "/", $new, $s, 1));
' 'testTheOwnerStatusEnumRefusesBlockedAndPublished' 'M3 the owner enum accepts blocked'

# ── M4. THE MAXIMUM IS RAISED ──────────────────────────────────────────────
mutate "$ENDPOINT" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "    private const MAX_LIMIT     = 50;";
$new = "    private const MAX_LIMIT     = 500;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheListRoutesDeclareTheContractLimits|testTheLimitIsClampedToTheContractMaximum' 'M4 the declared maximum is raised to 500'

# ── M5. ⚠⚠ THE VALIDATOR IS REMOVED FROM THE LIMIT ARGUMENT ────────────────
#
# It reads as redundant next to `minimum`/`maximum`. It is the only reason
# they bind: WordPress defaults an argument into schema enforcement only when
# the argument declares no `sanitize_callback`, and this one declares
# `absint`. Measured on WordPress 6.7.2 without this line: `?limit=51`
# answers 200 with 50 rows.
mutate "$ENDPOINT" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$q = chr(39);
$old = "            " . $q . "validate_callback" . $q . " => " . $q . "rest_validate_request_arg" . $q . "," . $E;
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, "", $s));
' 'testTheListRoutesDeclareTheContractLimits' 'M5 the limit validator is deleted (bounds stop binding)'

# ── M6. A READ FAILURE BECOMES AN EMPTY PAGE ───────────────────────────────
#
# An empty announcement bar is entirely normal, so this is the mutation whose
# output looks most like a correct answer.
mutate "$ENDPOINT" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "        } catch (RepositoryException) {";
$new = "        } catch (RepositoryException) {" . $E
     . "            return ApiResponse::ok([" . chr(39) . "items" . chr(39) . " => []]);";
if (substr_count($s, $old) < 1) { exit(1); }
file_put_contents($f, preg_replace("/" . preg_quote($old, "/") . "/", $new, $s, 1));
' 'testAReadFailureIs503AndNeverAnEmptyPage' 'M6 a read failure returns an empty 200 page'

# ── M7. ⚠⚠ DETAIL BECOMES PUBLICLY CACHEABLE ───────────────────────────────
#
# Detail carries row `capabilities`, which are resolved per viewer. A shared
# cache can then serve one viewer the permissions computed for another.
mutate "$ENDPOINT" '
$f = $argv[1]; $s = file_get_contents($f);
$q = chr(39);
$old = "\$response->header(" . $q . "Cache-Control" . $q . ", " . $q . "private, no-store" . $q . ");";
$new = "\$response->header(" . $q . "Cache-Control" . $q . ", " . $q . "public, max-age=60" . $q . ");";
if (substr_count($s, $old) < 1) { exit(1); }
file_put_contents($f, preg_replace("/" . preg_quote($old, "/") . "/", $new, $s, 1));
' 'testCacheHeadersMatchViewerDependence' 'M7 detail becomes publicly cacheable'

# ── M8. THE VIEWER-DEPENDENT READS STOP VARYING ────────────────────────────
#
# `private` asks a shared cache not to store the response; `Vary` is what
# keeps a cache that stores it anyway correct across a claim revocation.
mutate "$ENDPOINT" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$q = chr(39);
$old = "        \$response->header(" . $q . "Vary" . $q . ", " . $q . "Authorization, Cookie" . $q . ");" . $E;
if (substr_count($s, $old) < 1) { exit(1); }
file_put_contents($f, preg_replace("/" . preg_quote($old, "/") . "/", "", $s, 1));
' 'testCacheHeadersMatchViewerDependence' 'M8 a viewer-dependent read stops varying'

# ── M9. THE ROTATOR BECOMES PAGEABLE ───────────────────────────────────────
#
# §4.32.4 caps it at five and §4.32.12 makes its pagination inert. A cursor
# argument is the first half of making it a list.
mutate "$ENDPOINT" '
$f = $argv[1]; $s = file_get_contents($f);
$q = chr(39);
$old = "                " . $q . "args" . $q . "                => [" . $q . "pageId" . $q . " => \$pageArg],";
$new = "                " . $q . "args" . $q . "                => [" . $q . "pageId" . $q . " => \$pageArg, " . $q . "cursor" . $q . " => [" . $q . "type" . $q . " => " . $q . "string" . $q . "]],";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheRotatorDeclaresNoPagingParameters' 'M9 the rotator gains a cursor parameter'

# ── M10. THE DENIAL NAMES ITS CAUSE ────────────────────────────────────────
#
# A distinguishable `claim_ambiguous` maps the contested validators for an
# unauthenticated prober; a distinguishable `claim_absent` maps the unclaimed
# ones. §4.32.2 requires one unvarying answer.
mutate "$ENDPOINT" '
$f = $argv[1]; $s = file_get_contents($f);
$q = chr(39);
$old = "[" . $q . "reason_code" . $q . " => Authority::PUBLIC_REASON]";
$new = "[" . $q . "reason_code" . $q . " => " . $q . "claim_ambiguous" . $q . "]";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testTheOwnershipDenialIsSingleSourcedFromTheAuthority' 'M10 the denial serialises a private reason'

# ── M11. THE FEATURE-OFF REFUSAL NAMES THE FEATURE ─────────────────────────
#
# A helpful message is the leak: §4.32.10 is that an outside reader cannot
# tell a disabled feature from a missing row.
mutate "$FEATURE" '
$f = $argv[1]; $s = file_get_contents($f);
$q = chr(39);
$old = "            " . $q . "Not found." . $q . ",";
$new = "            " . $q . "Announcements are not enabled for this site." . $q . ",";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'testEveryRouteIs404WhileTheFeatureIsOff|testTheFeatureOffAndNotFoundBodiesAreIdentical' 'M11 the feature-off body names the feature'

# ── M12. A READ ROUTE BECOMES WRITABLE ─────────────────────────────────────
#
# A2.4 is reads only: the publication-authorization marker has a reader and
# no writer, so a write here would have to invent where the marker comes
# from. A registered-but-undocumented route is also a CI failure under the
# 2026-07-24 parity ratchet.
#
# ⚠ The first version of this control tried to INJECT a whole `registerWrite()`
# method and came back `broken` — its needle carried four backslashes to
# survive two layers of quoting and matched nothing, so the control tested
# nothing while looking thorough. A one-token change to an existing
# registration tests the same guarantee and cannot miss.
mutate "$ENDPOINT" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "WP_REST_Server::READABLE";
$new = "WP_REST_Server::CREATABLE";
if (substr_count($s, $old) < 1) { exit(1); }
file_put_contents($f, preg_replace("/" . preg_quote($old, "/") . "/", $new, $s, 1));
' 'testNoWriteRouteIsRegistered' 'M12 a read route becomes writable'

# (superseded M12 body, kept out of the run)
: <<'SUPERSEDED'
mutate "$ENDPOINT" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$q = chr(39);
$old = "            " . $q . "/me/validators/(?P<pageId>\\\\d+)/announcements" . $q . ",";
if (substr_count($s, $old) !== 1) { exit(1); }
$new = $old . $E . "            // mutation control M12" . $E;
$anchor = "    // ── Public reads ";
if (substr_count($s, $anchor) !== 1) { exit(1); }
$inject = "    public static function registerWrite(): void" . $E . "    {" . $E
    . "        register_rest_route(self::ROUTE_NAMESPACE, " . $q . "/validators/(?P<pageId>\\\\d+)/announcements" . $q . ", [" . $E
    . "            " . $q . "methods" . $q . " => " . $q . "POST" . $q . "," . $E
    . "            " . $q . "callback" . $q . " => static fn () => null," . $E
    . "            " . $q . "permission_callback" . $q . " => " . $q . "__return_true" . $q . "," . $E
    . "        ]);" . $E . "    }" . $E . $E;
file_put_contents($f, str_replace($anchor, $inject . $anchor, $s));
' 'testNoWriteRouteIsRegistered|testExactlyTheFourAcceptedReadRoutesAreRegistered' 'M12 a write route is registered'
SUPERSEDED

echo "──────────────────────────────────────────────────────────────────────────"
echo "killed=$killed survived=$survived broken=$broken"

rm -rf "$SNAPDIR"

# A survivor means a guarantee has no test. A broken control means the control
# itself is wrong — both fail, because a control that tests nothing is worse
# than no control: it reports confidence it has not earned.
[ "$survived" -eq 0 ] && [ "$broken" -eq 0 ]
