#!/usr/bin/env bash
#
# PR 5b mutation controls.
#
# ── WHY THIS EXISTS ──────────────────────────────────────────────────────
# A green test suite proves the tests pass. It does not prove they would
# have FAILED had the fix been absent — and for a change like this one,
# that is the only property worth having. Several assertions here ("no
# provider call was made", "it rolled back") pass trivially if the code
# never reaches the branch at all.
#
# There is no mutation-testing framework in this repo (Infection appears
# only as transitive lock metadata), so this follows the house idiom
# instead: CanonicalIdentifierDriftGuardTest already carries "positive
# controls: prove the guard can actually fail", and PR 5a proved
# arch-guardrails with a planted violation.
#
# Each control below REVERTS one specific fix, runs the test that is
# supposed to catch it, and asserts that test FAILS. A control that does
# not kill its mutant is reported as a survivor — meaning the test is not
# actually testing what its name claims.
#
# ── SAFETY ───────────────────────────────────────────────────────────────
# Every mutation is applied to a working-tree copy and reverted in an EXIT
# trap, so an interrupted run cannot leave a planted defect behind. The
# script refuses to start on a dirty tree, because it cannot otherwise
# tell its own edits from yours.
#
# Usage:  bash scripts/tests/pr5b-mutation-controls.sh
# Exit:   0 = every mutant killed; 1 = a survivor; 2 = refused to run.

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 2
ROOT="$(pwd)"

PHP="${PHP_BIN:-php}"
if ! command -v "$PHP" >/dev/null 2>&1; then
    echo "FATAL: php not found (set PHP_BIN)" >&2
    exit 2
fi

# Extra interpreter flags, e.g. loading mysqli for the integration controls
# on a dev box whose CLI php.ini does not enable it:
#   PHP_FLAGS="-d extension_dir=C:/php/ext -d extension=mysqli"
# Deliberately word-split (not quoted as one argument) because it carries
# multiple flags.
# shellcheck disable=SC2206
PHP_ARGS=(${PHP_FLAGS:-})

# An anchor test: prove the harness can run tests at all before we start
# concluding anything from failures. Without this, a broken PHPUnit would
# look like "every mutant killed".
if ! "$PHP" "${PHP_ARGS[@]}" vendor/bin/phpunit --no-coverage --filter NftCollectionIdentityMatchesTest >/dev/null 2>&1; then
    echo "FATAL: the anchor test does not pass on a clean tree — fix that first." >&2
    echo "       (Concluding 'mutant killed' from a harness that always fails is worthless.)" >&2
    exit 2
fi

if [ -n "$(git status --porcelain -- app tests 2>/dev/null)" ]; then
    echo "FATAL: working tree has uncommitted changes under app/ or tests/." >&2
    echo "       This script edits those files; it will not risk reverting your work." >&2
    exit 2
fi

TOUCHED=()

restore() {
    for f in "${TOUCHED[@]:-}"; do
        [ -n "$f" ] && git checkout -- "$f" 2>/dev/null
    done
}
trap restore EXIT INT TERM

PASS=0
FAIL=0

# plant <file> <search> <replace>
#
# Line endings are normalised to LF on BOTH sides before matching. The repo
# has `text=auto`, so a Windows checkout writes CRLF while the anchors in
# this script are LF — a literal match would silently fail to plant. That
# is reported as ERROR rather than counted as "killed", because a control
# that never ran proves nothing.
plant() {
    local file="$1" search="$2" replace="$3"
    TOUCHED+=("$file")
    "$PHP" "${PHP_ARGS[@]}" -r '
        $f = $argv[1];
        $s = str_replace("\r\n", "\n", $argv[2]);
        $r = str_replace("\r\n", "\n", $argv[3]);
        $c = str_replace("\r\n", "\n", (string) file_get_contents($f));
        if (substr_count($c, $s) !== 1) {
            fwrite(STDERR, "anchor not found exactly once in {$f}\n");
            exit(3);
        }
        file_put_contents($f, str_replace($s, $r, $c));
    ' "$file" "$search" "$replace"
}

# control <label> <test filter> <integration?> <file> <search> <replace>
control() {
    local label="$1" filter="$2" suite="$3" file="$4" search="$5" replace="$6"

    printf '  %-34s ' "$label"

    if ! plant "$file" "$search" "$replace"; then
        printf 'ERROR (could not plant)\n'
        FAIL=$((FAIL + 1))
        return
    fi

    local rc=0
    if [ "$suite" = "integration" ]; then
        "$PHP" "${PHP_ARGS[@]}" vendor/bin/phpunit -c phpunit-integration.xml.dist --no-coverage --filter "$filter" >/dev/null 2>&1 || rc=$?
    else
        "$PHP" "${PHP_ARGS[@]}" vendor/bin/phpunit --no-coverage --filter "$filter" >/dev/null 2>&1 || rc=$?
    fi

    git checkout -- "$file" 2>/dev/null

    if [ "$rc" -ne 0 ]; then
        printf 'killed\n'
        PASS=$((PASS + 1))
    else
        printf 'SURVIVED  <-- %s does not actually test this\n' "$filter"
        FAIL=$((FAIL + 1))
    fi
}

echo "PR 5b mutation controls — each plants a defect and requires a test to catch it"
echo

# 1. Solana case comparison. Restore the fold that was the whole defect.
control 'solana case comparison' \
    'NftCollectionIdentityMatchesTest' 'unit' \
    'app/Domain/Onchain/Support/NftCollectionIdentifier.php' \
    'return $identity->canonical() === $canonicalTarget;' \
    'return strtolower($identity->canonical()) === strtolower($canonicalTarget);'

# 2. Fail-closed branch. Restore the ORIGINAL defect exactly: drop the
#    refusal and fold the raw contract, so an alias is compared against DAS
#    collection mints and the walk returns a confident, meaningless zero.
#
#    Note the anchor covers the guard AND the $target assignment. Removing
#    only the guard would make $identity->canonical() throw on a refusal,
#    and the control would "pass" for the wrong reason.
control 'unresolved fail-closed branch'     'SolanaGateFailClosedIntegrationTest' 'integration'     'app/Domain/Onchain/Fetchers/SolanaFetcher.php'     "        if (!\$identity->isAccepted()) {
            \BCC\Core\Log\Logger::warning(
                '[Solana Fetcher] count_holdings refused a non-canonical collection identifier; '
                . 'returning UNKNOWN rather than a meaningless zero',
                ['reason' => \$identity->reason()]
            );

            return null;
        }

        \$target  = \$identity->canonical();"     '        $target  = strtolower($contract);'

# 3. Checked-audit rollback. Ignore a failed audit entirely and let the
#    writes commit with no honest record of what changed.
#
#    The anchor spans the guard AND the row verification. Removing only the
#    guard leaves verifyAuditRow() to throw on the missing row, so the
#    mapping would still roll back and the mutant would survive while
#    looking dead — the exact false-negative this control exists to avoid.
control 'checked-audit rollback'     'SolanaGateIdentityRepairRollbackIntegrationTest' 'integration'     'app/Domain/Onchain/Repair/SolanaGateIdentityRepairService.php'     "        if (\$auditId === null || \$auditId <= 0) {
            // Metadata could not be encoded, or the insert failed. Either
            // way there is no honest record of this repair, so it does not
            // happen.
            throw new \RuntimeException('checked audit write failed; rolling back the repair');
        }

        // ── Verify the audit row actually says what we meant ────────────
        \$this->verifyAuditRow(\$auditId, \$meta);"     '        // mutant: audit failure ignored'

# 4. Postcondition rollback. Stop asserting the legacy alias survived —
#    isolated by the direct verifyPostconditions() tests, because in a
#    healthy run this check changes no observable outcome.
control 'postcondition rollback' \
    'SolanaGateIdentityRepairIntegrationTest|SolanaGateIdentityRepairRollbackIntegrationTest' 'integration' \
    'app/Domain/Onchain/Repair/SolanaGateIdentityRepairService.php' \
    "            throw new \RuntimeException('postcondition: contract_address was modified');" \
    '            $expectedContractAddress = (string) $row->contract_address;'

# 5. Administrator id. Accept a leading zero and, critically, `0` itself —
#    WP-CLI's default user, and the ambient identity --user-id exists to
#    replace.
control 'administrator-id rejection' \
    'SolanaGateIdentityRepairCommandTest' 'unit' \
    'app/Domain/Onchain/CLI/SolanaGateIdentityRepairCommand.php' \
    "return preg_match('/^[1-9][0-9]{0,9}\$/', \$raw) === 1;" \
    "return preg_match('/^[0-9]+\$/', \$raw) === 1;"

# 6. Manifest eight-row limit. Add a ninth mapping.
control 'manifest eight-row limit' \
    'SolanaGateIdentityManifestTest' 'unit' \
    'app/Domain/Onchain/Repair/SolanaGateIdentityManifest.php' \
    "100 => [6509, 'bozosgroup',     '8Db41NmU1i3gSPq6AZWK1tsndJPPTLRP22LDGAz8CHxD']," \
    "100 => [6509, 'bozosgroup',     '8Db41NmU1i3gSPq6AZWK1tsndJPPTLRP22LDGAz8CHxD'],
        101 => [6510, 'ninth_row',      'So11111111111111111111111111111111111111112'],"

echo
echo "killed: ${PASS}  survived: ${FAIL}"

if [ "$FAIL" -ne 0 ]; then
    echo "FAIL — a planted defect went undetected." >&2
    exit 1
fi

echo "OK — every planted defect was caught."
exit 0
