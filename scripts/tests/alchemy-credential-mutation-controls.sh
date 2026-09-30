#!/usr/bin/env bash
#
# Mutation controls for "one secure Alchemy credential source" (security issue
# #267, item 3).
#
# Each control breaks ONE guarantee in executable code and runs the test NAMED for
# that guarantee. Verdicts:
#
#   killed        the named test failed with the guarantee broken
#   survived      everything stayed green with the guarantee broken
#   broken        the mutation changed no bytes (it tested nothing)
#
# ⚠⚠⚠ THE TWO MUTATIONS THAT MATTER MOST ARE M3 AND M5, and neither looks like a
#   bug. M3 maps all five seeded Alchemy networks instead of the two launch
#   chains — which reads as completeness and silently launches Polygon, Arbitrum
#   and Optimism, because `driverSupportsChain()` gates the Alchemy drivers on
#   `chain_type === 'evm'` alone. M5 routes `eth_call` through the Alchemy
#   resolver — which reads as consistency and removes ERC-721 gating from
#   Avalanche and BSC.
#
#   A refactor that did either would have been reviewed as a security improvement.
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE (byte diff before/after).
# ⚠ RESTORE FROM A BYTE SNAPSHOT TAKEN ONCE, NEVER FROM GIT, and abort on a failed
#   restore — `git checkout --` once deleted six files mid-run in an earlier PR.
# ⚠ ANCHORS COMPUTE THE FILE'S LINE ENDING. This repo checks out CRLF, but a file
#   created in this branch and not yet round-tripped through git is LF. A \n-only
#   anchor matches nothing and is reported `broken`, which fails the run.
#
# Usage: bash scripts/tests/alchemy-credential-mutation-controls.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d memory_limit=2G -d extension=mysqli}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

CRED="app/Domain/Onchain/Support/AlchemyCredential.php"
FETCH="app/Domain/Onchain/Fetchers/EvmFetcher.php"
READY="app/Domain/Onchain/Support/NftProviderReadiness.php"
WORKER="app/Domain/Onchain/Workers/NftEthIndexerWorker.php"
GUARD="scripts/endpoint-exposure-guard.php"
# ⚠ M6d mutates the STUB file to prove the sentinel is actually wired, so the stub
# must be snapshotted and restored like any other target. A mutated file that is
# never restored silently poisons every later control in the run.
STUBS="tests/Stubs/alchemy-credential-stubs.php"

SNAPDIR="$(mktemp -d)"
for f in "$CRED" "$FETCH" "$READY" "$WORKER" "$STUBS"; do
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

# For controls whose observer is the guard script: the mutation introduces a real
# leak and the guard must FAIL.
mutate_guard () {
    local file="$1" replacement="$2" label="$3"
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

    $PHP "$GUARD" >/dev/null 2>&1
    local rc=$?
    restore "$file"

    # ⚠ Exit 1 is the kill. Exit 2 is the guard failing to RUN, which is not a
    # detection and must never be counted as one.
    if [ $rc -eq 1 ]; then
        echo "  killed       $label  [endpoint-exposure-guard]"
        killed=$((killed + 1))
    elif [ $rc -eq 0 ]; then
        echo "  survived     $label  [guard passed over a real leak]"
        survived=$((survived + 1))
    else
        echo "  broken       $label (guard exited $rc — it did not run)"
        broken=$((broken + 1))
    fi
}

echo "── Alchemy credential-source mutation controls ───────────────────────────"

# ── 1. THE CREDENTIAL COMES BACK FROM THE DATABASE ──────────────────────────
#
# Drop the constant entirely and trust the row. The defect being removed,
# restored as the shortest possible implementation.
mutate "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = implode($E, ["        \$network = self::networkFor(\$chain);", "        \$key     = self::key();"]);
$new = implode($E, ["        \$network = self::networkFor(\$chain);", "        \$key     = \$row !== \x27\x27 ? \$row : self::key();"]);
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest' 'M1 the row is used as the credential again'

# 1b. ⚠ `defined()` READ AS CONFIGURED — the empty-constant trap. A stripped
#     staging config, a secret that failed to inject, or a half-finished
#     provisioning step gives `define('BCC_ALCHEMY_API_KEY', '')`, which is
#     `defined()` and useless; read as configured it flips a chain to ready and
#     hands an operator a job that cannot make one successful call.
#
#     ⚠⚠ BOTH defences are mutated together, and that is the point. The explicit
#     `$key === ''` return and the `+` in the shape pattern are REDUNDANT — each
#     alone rejects an empty key. Mutating either on its own survives, not because
#     the guarantee is untested but because the other defence still holds it. The
#     guarantee under test is "an empty constant is not configured", so the
#     control has to remove every way the code currently enforces it.
mutate "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = implode($E, ["        if (\$key === \x27\x27) {", "            return null;", "        }"]);
$new = "        // empty no longer rejected";
if (substr_count($s, $old) !== 1) { exit(1); }
$s = str_replace($old, $new, $s);
$old2 = "preg_match(\x27~^[A-Za-z0-9_-]+\$~\x27, \$key)";
$new2 = "preg_match(\x27~^[A-Za-z0-9_-]*\$~\x27, \$key)";
if (substr_count($s, $old2) !== 1) { exit(1); }
file_put_contents($f, str_replace($old2, $new2, $s));
' 'AlchemyCredentialTest' 'M1b an empty constant reads as configured (both defences removed)'

# 1c. Stop trimming. A secret injected with a trailing newline is non-empty,
#     reads as configured, and carries whitespace into an outbound URL.
mutate "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        \$key = trim((string) constant(\x27BCC_ALCHEMY_API_KEY\x27));";
$new = "        \$key = (string) constant(\x27BCC_ALCHEMY_API_KEY\x27);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest' 'M1c the credential is no longer trimmed'

# ── 2. ⚠⚠⚠ THE CREDENTIAL IS SENT TO A DB-CONTROLLED HOST ───────────────────
#
# Build the endpoint from the ROW's host instead of the closed map — the
# "flexible" design rejected in the class docblock. Anything that can write one
# column redirects the secret to a host of its choosing, and the request carrying
# it succeeds in leaking it before anything notices.
mutate "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return \x27https://\x27 . \$network . self::HOST_SUFFIX . \x27/v2/\x27 . rawurlencode(\$key);";
$new = "        \$host = (string) (parse_url(\$row, PHP_URL_HOST) ?? \x27\x27);\r\n        if (\$host !== \x27\x27) {\r\n            return \x27https://\x27 . \$host . \x27/v2/\x27 . rawurlencode(\$key);\r\n        }\r\n\r\n        return \x27https://\x27 . \$network . self::HOST_SUFFIX . \x27/v2/\x27 . rawurlencode(\$key);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest::testACredentialIsNeverSentToANonAlchemyHost' 'M2 the endpoint host comes from the chain row'

# 2b. ⚠⚠ DROP THE KEY-SHAPE VALIDATION.
#
#     This control started life as "stop URL-encoding the key" and SURVIVED — and
#     the survival was informative rather than a gap. Every fixture used an
#     alphanumeric key, so `rawurlencode()` was a no-op on all of them.
#
#     Adding a key-shape check made that mutation EQUIVALENT: with the key
#     constrained to `[A-Za-z0-9_-]+`, the encoding provably cannot alter it, and
#     no test can distinguish its presence. A control that cannot be killed is a
#     permanent false failure, so it was re-scoped rather than kept or deleted.
#
#     What IS killable is the check itself. Without it, `rpcUrlFor()` returns a URL
#     for a malformed key while `nftBaseFor()` rejects that same URL — two methods
#     on one class disagreeing about whether a chain is usable, which is the exact
#     failure `AlchemyEndpoint` was extracted to end.
#
#     ⚠ `rawurlencode()` stays in the code as defence in depth for exactly the
#     case this mutation creates. It is deliberately un-pinned by a control,
#     because with the validation present nothing can observe it.
mutate "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        return preg_match(\x27~^[A-Za-z0-9_-]+\$~\x27, \$key) === 1 ? \$key : null;";
$new = "        return \$key;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest' 'M2b the key-shape validation is dropped'

# ── 3. ⚠⚠⚠ THE MAP GROWS TO EVERY SEEDED NETWORK ────────────────────────────
#
# Reads as completeness. Silently launches three chains DECISION 7 excludes,
# because nothing else gates them.
mutate "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = implode($E, ["        \x270x1\x27    => \x27eth-mainnet\x27,", "        \x270x2105\x27 => \x27base-mainnet\x27,"]);
$new = implode($E, [
    "        \x270x1\x27    => \x27eth-mainnet\x27,",
    "        \x270x89\x27   => \x27polygon-mainnet\x27,",
    "        \x270xa4b1\x27 => \x27arb-mainnet\x27,",
    "        \x270xa\x27    => \x27opt-mainnet\x27,",
    "        \x270x2105\x27 => \x27base-mainnet\x27,",
]);
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest::testASeededButUnlaunchedChainStaysUnresolvable' 'M3 the network map covers every seeded Alchemy chain'

# 3b. Key on slug instead of chain_id_hex. Slug is operator data and renameable;
#     DECISION 7 keys on chain_id_hex for exactly that reason.
mutate "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        \$hex = strtolower(trim((string) (\$chain->chain_id_hex ?? \x27\x27)));";
$new = "        \$hex = strtolower(trim((string) (\$chain->slug ?? \x27\x27)));";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest' 'M3b the map is keyed on slug rather than chain_id_hex'

# 3c. Drop the chain_type check, so a Solana row with a colliding hex resolves an
#     EVM endpoint.
mutate "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = implode($E, ["        if ((string) (\$chain->chain_type ?? \x27\x27) !== \x27evm\x27) {", "            return null;", "        }"]);
$new = "        // chain_type no longer checked";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest::testANonEvmChainIsNeverSupported' 'M3c chain_type is no longer required to be evm'

# 3d. Case-sensitive hex lookup. `0X2105` becomes a different chain.
mutate "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        \$hex = strtolower(trim((string) (\$chain->chain_id_hex ?? \x27\x27)));";
$new = "        \$hex = (string) (\$chain->chain_id_hex ?? \x27\x27);";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest::testChainIdHexIsMatchedCaseInsensitivelyAndTrimmed' 'M3d chain_id_hex is matched case-sensitively'

# ── 4. READINESS AND THE FETCHER DISAGREE AGAIN ─────────────────────────────
#
# Re-derive readiness from the row instead of asking the resolver. A panel that
# says configured while the fetcher returns [] is worse than no panel.
mutate "$READY" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS => AlchemyCredential::rpcUrlFor(\$chain) !== null,";
$new = "NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS => \$rpcUrl !== \x27\x27,";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest' 'M4 readiness re-derived from the row'

# ── 5. ⚠⚠⚠ eth_call LOSES ITS PUBLIC-RPC CHAINS ─────────────────────────────
#
# Route standard JSON-RPC through the Alchemy resolver. Reads as consistency;
# removes ERC-721 ownership gating from Avalanche and BSC.
mutate "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = implode($E, ["        \$row = trim((string) (\$chain->rpc_url ?? \x27\x27));", "", "        // ⚠ A keyless Alchemy TEMPLATE is not a usable public node — it is a"]);
$new = implode($E, ["        return null;", "        \$row = trim((string) (\$chain->rpc_url ?? \x27\x27));", "", "        // ⚠ A keyless Alchemy TEMPLATE is not a usable public node — it is a"]);
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest::testEthCallResolvesAPublicNodeWithNoCredential' 'M5 standard JSON-RPC requires an Alchemy credential'

# 5b. The reverse: accept a keyless Alchemy template as a public node, firing a
#     guaranteed 401 that reads as a node failure rather than a config problem.
mutate "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "        if (\$row === \x27\x27 || str_ends_with(\$row, \x27/v2/\x27)) {";
$new = "        if (\$row === \x27\x27) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest::testAKeylessTemplateIsNotTreatedAsAPublicNode' 'M5b a keyless template is treated as a public node'

# 5c. ⚠⚠ STOP PREFERRING ALCHEMY over the public fallback.
#
#     This replaced an earlier M7 ("the head block comes from the chain row"),
#     which became EQUIVALENT once the row fallback was restored: for a
#     non-Alchemy fixture host, `jsonRpcUrlFor()` returns the row anyway, so
#     reading the row directly is indistinguishable.
#
#     The guarantee that survives is the PREFERENCE. The head poll and the
#     transfer walk must resolve the SAME endpoint, or they can disagree across a
#     reorg and a checkpoint advances past a range the transfer endpoint never
#     served. Preferring Alchemy is how they are kept identical, and a chain with
#     a keyless row plus the constant is where the two orders differ: row-first
#     yields the rejected template, Alchemy-first yields a working endpoint.
mutate "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = implode($E, ["        \$alchemy = self::rpcUrlFor(\$chain);", "        if (\$alchemy !== null) {", "            return \$alchemy;", "        }"]);
$new = "        \$alchemy = null;";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest::testAlchemyIsPreferredOverThePublicFallbackWhenAvailable' 'M5c Alchemy is no longer preferred over the public fallback'

# ── 6. ⚠⚠ A BLOCKED REQUEST FIRES ANYWAY ────────────────────────────────────
#
# Fall back to the raw row when nothing resolves, so a blocked Alchemy-only
# request reaches the provider with a useless endpoint and spends quota.
mutate "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = implode($E, ["        if (\$network === null || \$key === null) {", "            return null;", "        }"]);
$new = implode($E, ["        if (\$network === null || \$key === null) {", "            return \$row !== \x27\x27 ? \$row : null;", "        }"]);
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest' 'M6 an unresolvable chain falls back to the raw row'

# 6c. ⚠⚠⚠ MOVE THE TRANSPORT CALL ABOVE THE CREDENTIAL GATE.
#
#     The control the whole sentinel exists for. `ethCallResult()` POSTs BEFORE it
#     checks whether an endpoint resolved, which is the shape of "returns the right
#     value but fired the request anyway" — quota spent, and a credentialed URL in
#     the provider's logs.
#
#     ⚠ This must be killed by the ARMED SENTINEL throwing TransportAttempted at
#     `SafeHttpClient::prepareArgs`, NOT by a class-not-found further down the
#     stack. The named test asserts the sentinel is untouched, so the kill names
#     the actual defect.
mutate "$FETCH" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = implode($E, [
    "        \$rpcUrl = \$this->jsonRpcUrl();",
    "        if (\$rpcUrl === null) {",
    "            return [\x27ok\x27 => false, \x27result\x27 => null, \x27kind\x27 => \x27credentials_missing\x27];",
    "        }",
]);
$new = implode($E, [
    "        \$rpcUrl = \$this->jsonRpcUrl();",
    "        ApiRetry::post((string) \$rpcUrl, [\x27body\x27 => \x27{}\x27], [\x27label\x27 => \x27premature\x27]);",
    "        if (\$rpcUrl === null) {",
    "            return [\x27ok\x27 => false, \x27result\x27 => null, \x27kind\x27 => \x27credentials_missing\x27];",
    "        }",
]);
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest::testABlockedRequestMakesNoProviderCall' 'M6c the transport is entered before the credential gate'

# 6d. The sentinel must be WIRED. Disarm it and the tripwire proof fails — without
#     this, a sentinel that silently stopped intercepting would look like a clean
#     sheet, which is the false-green failure this repo has shipped before.
mutate "$STUBS" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "                if (TransportSentinel::\$active) {";
$new = "                if (false) {";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest::testTheArmedSentinelThrowsTheMomentTheTransportIsEntered' 'M6d the outer sentinel stops intercepting'

# 6b. A missing credential reported as a VERDICT rather than as UNAVAILABLE.
#     This is the one that writes a permanent false negative against a contract.
mutate "$FETCH" '
$f = $argv[1]; $s = file_get_contents($f);
$old = "            return [\x27ok\x27 => false, \x27data\x27 => null, \x27kind\x27 => \x27credentials_missing\x27];";
$new = "            return [\x27ok\x27 => true, \x27data\x27 => [], \x27kind\x27 => \x27ok\x27];";
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'AlchemyCredentialTest::testABlockedRequestMakesNoProviderCall' 'M6b a missing credential reported as a successful empty read'

# ── 7. THE WORKER STOPS GUARDING ITS ENDPOINT AT ALL ────────────────────────
#
# Accept an empty endpoint and POST to it. The old code had two explicit refusals
# here; collapsing them is how a misconfigured chain turns into a transport error
# that reads as a provider fault.
#
# ⚠ This replaced a control that read the row directly, which became EQUIVALENT:
# `jsonRpcUrlFor()` returns the row for a non-Alchemy host, so for the existing
# fixtures the two are the same value. What is still observable is the REFUSAL.
mutate "$WORKER" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = implode($E, ["        \$rpcUrl = AlchemyCredential::jsonRpcUrlFor(\$chain);", "        if (\$rpcUrl === null) {"]);
$new = implode($E, ["        \$rpcUrl = (string) AlchemyCredential::jsonRpcUrlFor(\$chain);", "        if (false) {"]);
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'NftEthIndexerWorkerTest|BreakerChargePerLogicalRequestTest' 'M7 the worker no longer refuses a missing endpoint'

# ── 8. ⚠⚠ THE GUARD MUST FIRE ON THE NEW CREDENTIAL PATHS ───────────────────
#
# Without these, a guard that silently stopped matching the resolver would look
# like a clean sheet — the false-green failure this repo has shipped before.
mutate_guard "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "        return \x27https://\x27 . \$network . self::HOST_SUFFIX . \x27/v2/\x27 . rawurlencode(\$key);";
$new = implode($E, [
    "        \\BCC\\Core\\Log\\Logger::error(\x27[bcc-trust] resolved\x27, [\x27rpc_url\x27 => \$row]);",
    "        return \x27https://\x27 . \$network . self::HOST_SUFFIX . \x27/v2/\x27 . rawurlencode(\$key);",
]);
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'M8 guard fires on the row logged from the resolver'

mutate_guard "$CRED" '
$f = $argv[1]; $s = file_get_contents($f);
$E = substr_count($s, "\r\n") > 0 ? "\r\n" : "\n";
$old = "        \$rpc = self::rpcUrlFor(\$chain);";
$new = implode($E, ["        \$rpc = self::rpcUrlFor(\$chain);", "        error_log(\x27nft base for \x27 . constant(\x27BCC_ALCHEMY_API_KEY\x27));"]);
if (substr_count($s, $old) !== 1) { exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
' 'M8b guard fires on the credential constant reaching a log'

echo "─────────────────────────────────────────────────────────────────────────"
echo "  killed: $killed   survived: $survived   broken: $broken"

rm -rf "$SNAPDIR"

# ⚠ `broken` fails the run. A control that changed no bytes tested nothing.
if [ "$survived" -ne 0 ] || [ "$broken" -ne 0 ]; then
    exit 1
fi
exit 0
