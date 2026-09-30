#!/usr/bin/env bash
#
# Mutation controls for "endpoint redaction is deny-by-default, and description is
# not identity" (security issue #267, items 1, 2 and 4).
#
# Each control breaks ONE guarantee in executable code and runs the test NAMED for
# that guarantee. Verdicts:
#
#   killed        the named test failed with the guarantee broken
#   survived      everything stayed green with the guarantee broken
#   broken        the mutation changed no bytes (it tested nothing)
#
# ⚠⚠⚠ WHY THESE CONTROLS AND NOT OTHERS. The incident was not a missing test. It
#   was an allow-by-pattern redactor that passed every test written for the shapes
#   its author had enumerated, and leaked the first shape they had not. So every
#   control below reintroduces ALLOW-BY-PATTERN in some form — one credential shape
#   at a time — and the suite must notice each one individually. A suite that only
#   checked `?api-key=` would survive M1 and M2.
#
# ⚠⚠ M5 and M6 are the ones that matter most, because they are the mutations a
#   well-meaning developer would actually make: "compare the displays, they're
#   already there" and "log the endpoint, it's already redacted". Both are
#   type-correct, both read as tidying, and both are the defect.
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE (byte diff before/after).
# ⚠ RESTORE FROM A BYTE SNAPSHOT TAKEN ONCE, NEVER FROM GIT, and abort on a failed
#   restore — `git checkout --` once deleted six files mid-run in an earlier PR.
# ⚠⚠⚠ ANCHORS MUST COMPUTE THE FILE'S LINE ENDING, NEVER ASSUME IT. This tree
#   checks out CRLF on the dev host and LF on the CI runner, so a multi-line anchor
#   with a hardcoded \r\n passes locally and reports `broken` in CI — which is
#   exactly what M5b did on the first CI run of PR #269. Single-line anchors are
#   unaffected; anything spanning a line break uses implode($E, [...]).
#   `broken` failing the run is what surfaced it, and is not a pass.
#
# Usage: bash scripts/tests/endpoint-redaction-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d memory_limit=2G -d extension=mysqli}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

DESC="app/Domain/Onchain/Support/EndpointDescriptor.php"
READY="app/Domain/Onchain/Support/NftProviderReadiness.php"
FETCH="app/Domain/Onchain/Fetchers/SolanaFetcher.php"
STATUS="app/Domain/Onchain/Support/ProviderConfigStatus.php"
GUARD="scripts/endpoint-exposure-guard.php"

SNAPDIR="$(mktemp -d)"
for f in "$DESC" "$READY" "$FETCH" "$STATUS"; do
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

# mutate <file> <php-replacement-script> <named-test-filter> <label>
mutate () {
    local file="$1" replacement="$2" filter="$3" label="$4"
    local before after

    before="$($PHP -r 'echo md5_file($argv[1]);' "$file")"
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
    out="$($PHP "$PHPUNIT" --no-coverage --testsuite Unit --filter "$filter" 2>&1)"
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

# mutate_guard <file> <php-replacement-script> <label>
#
# For controls whose observer is the GUARD SCRIPT rather than a PHPUnit test: the
# mutation introduces a real leak into production code and the guard must FAIL.
mutate_guard () {
    local file="$1" replacement="$2" label="$3"
    local before after

    before="$($PHP -r 'echo md5_file($argv[1]);' "$file")"
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

    $PHP "$GUARD" >/dev/null 2>&1
    local rc=$?
    restore "$file"

    # ⚠ Exit 1 is the kill. Exit 2 is the guard failing to RUN, which is not a
    # detection and must never be counted as one.
    if [ $rc -eq 1 ]; then
        echo "  killed       $label  [endpoint-exposure-guard]"
        killed=$((killed + 1))
    elif [ $rc -eq 0 ]; then
        echo "  survived     $label  [endpoint-exposure-guard passed over a real leak]"
        survived=$((survived + 1))
    else
        echo "  broken       $label (guard exited $rc — it did not run)"
        broken=$((broken + 1))
    fi
}

echo "── endpoint redaction / identity mutation controls ───────────────────────"

# ── 1. PATH-KEY LEAKAGE ─────────────────────────────────────────────────────
#
# Restore allow-by-pattern: keep the path when it is not a shape we recognise as
# credential-bearing. This is the exact defect, expressed the way its author
# would have expressed it.
mutate "$DESC" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return \$parts[\x27scheme\x27] . \x27://\x27 . \$parts[\x27host\x27];";
$new = "        \$path = (string) (parse_url(\$raw, PHP_URL_PATH) ?? \x27\x27);\r\n        if (!preg_match(\x27~/v2/~\x27, \$path)) {\r\n            return \$parts[\x27scheme\x27] . \x27://\x27 . \$parts[\x27host\x27] . \$path;\r\n        }\r\n\r\n        return \$parts[\x27scheme\x27] . \x27://\x27 . \$parts[\x27host\x27];";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'EndpointDescriptorTest' 'M1 path retained unless it matches a known key shape'

# 1b. The SPECIFIC shape that leaked: `/nft/v3/<KEY>`, produced by
#     AlchemyEndpoint::nftBaseFromRpcUrl() rewriting a `/v2/<KEY>` path. An
#     allowlist covering `/v2/` alone lets this through, which is what happened.
mutate "$DESC" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return \$parts[\x27scheme\x27] . \x27://\x27 . \$parts[\x27host\x27];";
$new = "        \$path = (string) (parse_url(\$raw, PHP_URL_PATH) ?? \x27\x27);\r\n        if (str_starts_with(\$path, \x27/nft/\x27)) {\r\n            return \$parts[\x27scheme\x27] . \x27://\x27 . \$parts[\x27host\x27] . \$path;\r\n        }\r\n\r\n        return \$parts[\x27scheme\x27] . \x27://\x27 . \$parts[\x27host\x27];";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'EndpointDescriptorTest' 'M1b the /nft/v3/<KEY> rewrite shape leaks'

# ── 2. QUERY-KEY LEAKAGE ────────────────────────────────────────────────────
#
# Mask only the query parameters whose names are known. The predecessor's actual
# behaviour, and the reason a `?token=` would have leaked.
mutate "$DESC" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return \$parts[\x27scheme\x27] . \x27://\x27 . \$parts[\x27host\x27];";
$new = "        \$q = parse_url(\$raw, PHP_URL_QUERY);\r\n        if (is_string(\$q) && \$q !== \x27\x27 && !str_contains(\$q, \x27api-key\x27)) {\r\n            return \$parts[\x27scheme\x27] . \x27://\x27 . \$parts[\x27host\x27] . \x27?\x27 . \$q;\r\n        }\r\n\r\n        return \$parts[\x27scheme\x27] . \x27://\x27 . \$parts[\x27host\x27];";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'EndpointDescriptorTest' 'M2 query retained unless the parameter name is known'

# 2b. Userinfo survives. `https://user:SECRET@host/` — a credential in a component
#     nobody lists, and one `parse_url()` hands over without comment.
mutate "$DESC" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return \$parts[\x27scheme\x27] . \x27://\x27 . \$parts[\x27host\x27];";
$new = "        \$user = (string) (parse_url(\$raw, PHP_URL_USER) ?? \x27\x27);\r\n        \$pass = (string) (parse_url(\$raw, PHP_URL_PASS) ?? \x27\x27);\r\n        \$auth = \$user !== \x27\x27 ? \$user . (\$pass !== \x27\x27 ? \x27:\x27 . \$pass : \x27\x27) . \x27@\x27 : \x27\x27;\r\n\r\n        return \$parts[\x27scheme\x27] . \x27://\x27 . \$auth . \$parts[\x27host\x27];";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'EndpointDescriptorTest' 'M2b userinfo survives into the description'

# ── 3. THE MALFORMED-INPUT FALLBACK ─────────────────────────────────────────
#
# Echo the raw input when it cannot be parsed. Reads as helpful — "at least show
# the operator what is configured" — and a URL too broken to parse is still
# perfectly capable of holding a key.
mutate "$DESC" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            return self::UNRECOGNISED;";
$new = "            return \$raw;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'EndpointDescriptorTest' 'M3 unparseable input is echoed verbatim'

# ── 4. IDENTITY WEAKENED ────────────────────────────────────────────────────
#
# An unsalted digest. The stored option is readable by any administrator and lands
# in every backup; the URL space is small and templated, so an unsalted digest is
# grindable and is identical across every install.
mutate "$DESC" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return hash_hmac(\x27sha256\x27, self::normalise(\$parts, \$raw), self::identityKey());";
$new = "        return hash(\x27sha256\x27, self::normalise(\$parts, \$raw));";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'EndpointDescriptorTest' 'M4 identity becomes an unsalted public digest'

# 4b. Identity over the DESCRIPTION instead of the whole URL — the collapse that
#     makes every endpoint on one host identical.
mutate "$DESC" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return hash_hmac(\x27sha256\x27, self::normalise(\$parts, \$raw), self::identityKey());";
$new = "        return hash_hmac(\x27sha256\x27, \$parts[\x27scheme\x27] . \x27://\x27 . \$parts[\x27host\x27], self::identityKey());";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'DasMarkIdentityTest|NftProviderReadinessTest' 'M4b identity computed over scheme+host only'

# ── 5. ⚠⚠⚠ SAME-HOST IDENTITY COLLISION ─────────────────────────────────────
#
# Compare the DESCRIPTIONS. The single most likely regression: both values are on
# hand, the code is shorter, the types line up, and it silently makes one verdict
# per host — so a stale mark disables a rotated endpoint, and a path change on the
# same provider cannot clear it.
mutate "$READY" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return hash_equals(\$markedId, \$currentId);";
$new = "        return EndpointDescriptor::display(\$currentRpcUrl) === EndpointDescriptor::display(\$currentRpcUrl);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'DasMarkIdentityTest|NftProviderReadinessTest' 'M5 marks compared by description, not identity'

# 5b. Trust a mark with no identity by falling back to the legacy field. Reads as
#     back-compatibility and reinstates the permanent dead end: an unverifiable
#     mark disables a driver an operator has correctly configured.
mutate "$READY" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$head = "        \$markedId = isset(\$flag[\x27endpoint_id\x27]) ? trim((string) \$flag[\x27endpoint_id\x27]) : \x27\x27;";
$old = implode($E, [$head, "        if (\$markedId === \x27\x27) {", "            return false;", "        }"]);
$new = implode($E, [$head, "        if (\$markedId === \x27\x27) {", "            return isset(\$flag[\x27rpc_url\x27]);", "        }"]);
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'DasMarkIdentityTest|NftProviderReadinessTest' 'M5b a legacy mark with no identity is trusted'

# 5c. Export the comparison token beside the description. One extra key, no
#     behaviour change, and the persisted identity is now in admin HTML and in the
#     capability matrix JSON.
mutate "$READY" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            \x27code\x27             => isset(\$flag[\x27code\x27]) ? (int) \$flag[\x27code\x27] : 0,";
$new = "            \x27endpoint_id\x27      => isset(\$flag[\x27endpoint_id\x27]) ? (string) \$flag[\x27endpoint_id\x27] : \x27\x27,\r\n            \x27code\x27             => isset(\$flag[\x27code\x27]) ? (int) \$flag[\x27code\x27] : 0,";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'DasMarkIdentityTest' 'M5c the comparison identity is exported to callers'

# 5d. Re-conflate the two fields: store the identity where the description goes,
#     so what renders IS the comparison token.
mutate "$FETCH" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "                \x27endpoint_display\x27 => EndpointDescriptor::display(\$rpcUrl),";
$new = "                \x27endpoint_display\x27 => EndpointDescriptor::identity(\$rpcUrl),";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'DasMarkIdentityTest' 'M5d the identity is stored in the display field'

# ── 6. ⚠⚠⚠ LOGGING A DERIVED ENDPOINT ───────────────────────────────────────
#
# Persist the whole URL "for debugging". The mark used to be written from a
# pre-redacted value; passing the raw one and skipping the describer is a one-word
# change.
mutate "$FETCH" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "                \x27endpoint_display\x27 => EndpointDescriptor::display(\$rpcUrl),";
$new = "                \x27endpoint_display\x27 => \$rpcUrl,";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'DasMarkIdentityTest' 'M6 the raw endpoint is stored in the mark'

# 6b. The refusal reader trusts the stored display value instead of re-describing
#     it. Passes today — the writer is correct — and fails the moment the option is
#     restored from a backup, rolled back, or hand-edited.
mutate "$READY" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "                ? EndpointDescriptor::display((string) \$flag[\x27endpoint_display\x27])";
$new = "                ? (string) \$flag[\x27endpoint_display\x27]";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'DasMarkIdentityTest' 'M6b the stored display value is trusted, not re-described'

# ── 7. THE BOOLEAN-ONLY STATUS OBJECT STOPS BEING BOOLEAN-ONLY ──────────────
#
# Keep the endpoint on the object "in case a caller needs it". No accessor returns
# it, every existing assertion still passes, and it is fully visible in a
# `print_r()` pasted into a ticket.
# ⚠ EOL IS COMPUTED, NOT ASSUMED. This repo checks out CRLF, but a file created
#   in this branch and not yet round-tripped through git is still LF. Hardcoding
#   \r\n in a multi-line anchor made this control report `broken` — which is the
#   designed outcome for a drifted anchor, and is exactly why `broken` fails the
#   run instead of being tolerated.
mutate "$STATUS" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = implode($E, ["        private readonly bool \$resolverAvailable,", "    ) {"]);
$new = implode($E, ["        private readonly bool \$resolverAvailable,", "        private readonly string \$resolvedUrl = \x27\x27,", "    ) {"]);
if (substr_count($s, $old) !== 1) { exit(1); }
$s = str_replace($old, $new, $s);
$old2 = implode($E, ["            EndpointDescriptor::host(\$resolvedUrl),", "            \$resolverAvailable", "        );"]);
$new2 = implode($E, ["            EndpointDescriptor::host(\$resolvedUrl),", "            \$resolverAvailable,", "            (string) \$resolvedUrl", "        );"]);
if (substr_count($s, $old2) !== 1) { exit(1); }
file_put_contents($f, str_replace($old2, $new2, $s));
' 'ProviderConfigStatusTest' 'M7 the status object retains the complete endpoint'

# 7b. The hostname field carries the whole URL. `hostname()` is the one string a
#     diagnostic prints, so this is a direct leak through the safe-looking path.
mutate "$STATUS" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            EndpointDescriptor::host(\$resolvedUrl),";
$new = "            (string) \$resolvedUrl,";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'ProviderConfigStatusTest' 'M7b hostname carries the complete endpoint'

# 7c. The provider NAME is no longer filtered, so a caller passing an endpoint as
#     the name has it printed verbatim by the one class that promises otherwise.
mutate "$STATUS" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        \$clean = (string) preg_replace(\x27~[^a-z0-9._-]~\x27, \x27\x27, \$clean);";
$new = "        // unfiltered";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'ProviderConfigStatusTest' 'M7c the provider name is no longer filtered'

# ── 8. ⚠⚠ THE GUARD ITSELF MUST FIRE ────────────────────────────────────────
#
# Controls 1–7 are killed by PHPUnit. These are killed by the guard script, and
# without them a guard that silently matches nothing would look like a clean sheet
# — the false-green failure mode that has bitten this repo before.
mutate_guard "$FETCH" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "        update_option(";
$new = implode($E, [
    "        \\BCC\\Core\\Log\\Logger::error(\x27[bcc-trust] das endpoint\x27, [\x27rpc_url\x27 => \$rpcUrl]);",
    "        update_option(",
]);
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'M8 guard fires on an endpoint logged via a rpc_url context key'

mutate_guard "$READY" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return hash_equals(\$markedId, \$currentId);";
$new = "        error_log(EndpointDescriptor::identity(\$currentRpcUrl));\r\n\r\n        return hash_equals(\$markedId, \$currentId);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'M8b guard fires on the comparison identity reaching a log'

mutate_guard "$READY" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        \$provider = self::providerName(\$driverKey);";
$new = "        \$provider = self::providerName(\$driverKey);\r\n        echo esc_html(SolanaEndpoints::rpcEndpoint(\$chain));";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'M8c guard fires on a resolved endpoint echoed to a page'

echo "─────────────────────────────────────────────────────────────────────────"
echo "  killed: $killed   survived: $survived   broken: $broken"

rm -rf "$SNAPDIR"

# ⚠ `broken` fails the run. A control that changed no bytes tested nothing, and
# counting it as anything but a failure is how a drifted anchor turns into a
# silent gap in coverage.
if [ "$survived" -ne 0 ] || [ "$broken" -ne 0 ]; then
    exit 1
fi
exit 0
