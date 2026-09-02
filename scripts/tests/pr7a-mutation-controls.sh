#!/usr/bin/env bash
#
# PR 7A mutation controls.
#
# ── WHY THIS EXISTS ──────────────────────────────────────────────────────
# A green suite proves the tests pass. It does not prove they would have
# FAILED without the fix, and PR 6 is full of assertions that pass trivially
# if the branch is never reached: "no community was created", "it rolled
# back", "the tab is disjoint". PR 5b found two of its own tests could not
# fail — one had restated the very rule it guarded — and only planted
# defects found that.
#
# There is still no mutation-testing framework in this repo, so this follows
# the house idiom established by scripts/tests/pr5b-mutation-controls.sh:
# revert one specific fix, run the test that is supposed to catch it, and
# require that test to FAIL. A control that does not kill its mutant is a
# SURVIVOR — the test is not testing what its name claims.
#
# ── SAFETY ───────────────────────────────────────────────────────────────
# Every mutation is applied to the working tree and reverted in an EXIT
# trap, so an interrupted run cannot leave a planted defect behind. The
# script refuses to start on a dirty tree, because it cannot otherwise tell
# its own edits from yours.
#
# Usage:  bash scripts/tests/pr6-mutation-controls.sh
# Exit:   0 = every mutant killed; 1 = a survivor; 2 = refused to run.

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 2

PHP="${PHP_BIN:-php}"
if ! command -v "$PHP" >/dev/null 2>&1; then
    echo "FATAL: php not found (set PHP_BIN)" >&2
    exit 2
fi

# Extra interpreter flags, e.g. loading mysqli for the integration controls
# on a dev box whose CLI php.ini does not enable it:
#   PHP_FLAGS="-d extension_dir=C:/php/ext -d extension=php_mysqli.dll"
# Deliberately word-split (not quoted as one argument) because it carries
# multiple flags.
# shellcheck disable=SC2206
PHP_ARGS=(${PHP_FLAGS:-})

# ── Anchor: prove the harness can run tests AT ALL ───────────────────────
# Without this a broken PHPUnit — a missing extension, a fatal in a stub —
# would report every mutant as killed, because every run would "fail".
if ! "$PHP" "${PHP_ARGS[@]}" vendor/bin/phpunit --no-coverage --filter DiscoveryRunStateModelTest >/dev/null 2>&1; then
    echo "FATAL: the anchor test does not pass on a clean tree — fix that first." >&2
    echo "       (Concluding 'mutant killed' from a harness that always fails is worthless.)" >&2
    exit 2
fi

# `includes/` joined the list when the Blocker 5 controls landed: they plant
# defects in the schema migration and the version-stamp gate, and `restore()`
# reverts with `git checkout --`, which would silently wipe uncommitted work
# there. It would also fail outright on an UNTRACKED file, leaving a planted
# defect behind — the one outcome this script must never produce.
# ── Anchor 2: prove the INTEGRATION harness can run too ──────────────────
# Added after a stopped local MySQL was found to turn every integration
# control into a FALSE KILL: the bootstrap exits non-zero before running a
# single test, the runner sees rc != 0, and reports "killed" for a mutant it
# never planted meaningfully. That is the same "a guard that scanned nothing
# is not a guard" failure the unit anchor above already guards against, and
# it is worse here because it reads as a pass.
if ! "$PHP" "${PHP_ARGS[@]}" vendor/bin/phpunit -c phpunit-integration.xml.dist \
        --no-coverage --filter DiscoveryRunLedgerIntegrationTest >/dev/null 2>&1; then
    echo "FATAL: the integration anchor does not pass on a clean tree." >&2
    echo "       Start the local database (BCC_TEST_DB_PORT, default 10005) and retry." >&2
    echo "       Refusing to run: every integration control would report a false kill." >&2
    exit 2
fi

if [ -n "$(git status --porcelain -- app tests includes 2>/dev/null)" ]; then
    echo "FATAL: working tree has uncommitted changes under app/, tests/ or includes/." >&2
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
# Line endings are normalised to LF on BOTH sides before matching: the repo
# has `text=auto`, so a Windows checkout writes CRLF while the anchors below
# are LF, and a literal match would silently fail to plant. A failed plant
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

# control <label> <test filter> <unit|integration> <file> <search> <replace>
control() {
    local label="$1" filter="$2" suite="$3" file="$4" search="$5" replace="$6"

    printf '  %-38s ' "$label"

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

echo "PR 7A mutation controls — each plants a defect and requires a test to catch it"
echo


# 1. The active-run guarantee. Without uq_active two administrators can start
#    overlapping scans on one chain, and a disabled button is not a bound.
control 'ledger: one active run per target' \
    'DiscoveryRunLedgerIntegrationTest' 'integration' \
    'includes/database/schema-discovery-runs.php' \
    "        UNIQUE KEY uq_active (job_kind, chain_id, active_marker)," \
    "        KEY uq_active (job_kind, chain_id, active_marker),"

# 2. Historical and incremental write the same cw_* checkpoint columns, so
#    they must exclude each other. Adding scan_mode to the key breaks that.
control 'ledger: modes cannot co-run' \
    'DiscoveryRunLedgerIntegrationTest' 'integration' \
    'includes/database/schema-discovery-runs.php' \
    "        UNIQUE KEY uq_active (job_kind, chain_id, active_marker)," \
    "        UNIQUE KEY uq_active (job_kind, chain_id, scan_mode, active_marker),"

# 3. A lease expiry is NOT an attempt — the claim already counted it. Bumping
#    it burns every attempt on a healthy run inside fifteen minutes.
control 'ledger: reap does not bump attempts' \
    'DiscoveryRunLedgerIntegrationTest' 'integration' \
    'app/Domain/Onchain/Repositories/DiscoveryRunRepository.php' \
    "                SET status = %s,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    next_retry_at = DATE_ADD(" \
    "                SET status = %s,
                    attempt_count = attempt_count + 1,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    next_retry_at = DATE_ADD("

# 4. Retention must spare an ACTIVE run, or a live scan is deleted mid-flight.
control 'ledger: prune spares active runs' \
    'DiscoveryRunLedgerIntegrationTest' 'integration' \
    'app/Domain/Onchain/Repositories/DiscoveryRunRepository.php' \
    "              WHERE active_marker IS NULL
                AND finished_at IS NOT NULL" \
    "              WHERE finished_at IS NOT NULL"

# 5. Retention must also keep the newest success and failure per target, or
#    the status read model's last_succeeded / last_failed go blank.
control 'ledger: prune keeps the newest terminal' \
    'DiscoveryRunLedgerIntegrationTest' 'integration' \
    'app/Domain/Onchain/Repositories/DiscoveryRunRepository.php' \
    "            if (\$latest !== null) {
                \$keepers[] = (int) \$latest->id;
            }" \
    "            unset(\$latest);"

# 6. An exhausted expired run must become terminal. Leaving it running
#    forever is a lie told by omission — nothing will ever claim it again.
control 'ledger: exhausted runs terminalize' \
    'DiscoveryRunLedgerIntegrationTest' 'integration' \
    'app/Domain/Onchain/Repositories/DiscoveryRunRepository.php' \
    "                AND attempt_count >= %d\",
            DiscoveryRunStatus::FAILED," \
    "                AND attempt_count >= 9999\",
            DiscoveryRunStatus::FAILED,"

# 7. THE MONEY RULE. BCC is never a price platform; the ledger must carry no
#    market column, and adding one must fail the build.
control 'ledger: no market column' \
    'DiscoveryRunLedgerIntegrationTest' 'integration' \
    'includes/database/schema-discovery-runs.php' \
    "        collections_denied INT UNSIGNED NOT NULL DEFAULT 0," \
    "        collections_denied INT UNSIGNED NOT NULL DEFAULT 0,
        floor_price DECIMAL(20,8) DEFAULT NULL,"

# 8. An unknown user is not an administrator.
#
#    ⚠ The `$operatorId <= 0` guard is NOT separately killable, and saying so
#    is more useful than pretending otherwise: WordPress returns false from
#    get_userdata(0) anyway, so a mutant weakening only that line changes no
#    behaviour. Belt-and-braces is right to keep and dishonest to claim a test
#    for, so this control targets the check that actually decides.
control 'service: the operator must exist' \
    'DiscoveryRunServiceTest' 'unit' \
    'app/Domain/Onchain/Services/DiscoveryRunService.php' \
    "        if (get_userdata(\$operatorId) === false) {
            return null;
        }" \
    "        if (false) {
            return null;
        }"

# 9. The capability must be checked on the NAMED user, or a context with no
#    current user satisfies an authorization check.
control 'service: capability on the named user' \
    'DiscoveryRunServiceTest' 'unit' \
    'app/Domain/Onchain/Services/DiscoveryRunService.php' \
    "        if (!user_can(\$operatorId, 'manage_options')) {
            return null;
        }" \
    "        if (false) {
            return null;
        }"

# 10. A queued run nobody can prove was authorized is what this design exists
#     to prevent.
control 'service: audit failure rolls back' \
    'DiscoveryRunServiceTest' 'unit' \
    'app/Domain/Onchain/Services/DiscoveryRunService.php' \
    "                    throw new \RuntimeException('checked audit write failed; rolling back the run request');" \
    "                    return ['ok' => true, 'audit_id' => 0];"

# 11. A refusal must create NO ROW. Queueing a run the executor is guaranteed
#     to refuse manufactures a failed run and tells the operator it was
#     accepted.
control 'service: disabled chain writes nothing' \
    'DiscoveryRunServiceTest' 'unit' \
    'app/Domain/Onchain/Services/DiscoveryRunService.php' \
    "        if (!CosmwasmScanEligibility::isScannable(\$verdict)) {
            return DiscoveryRunError::DISCOVERY_DISABLED;
        }" \
    "        if (false) {
            return DiscoveryRunError::DISCOVERY_DISABLED;
        }"

# 12. The bounded race must RE-READ. Breaking out returns a refusal for a run
#     that already finished, or none at all.
control 'service: race re-reads, never guesses' \
    'DiscoveryRunServiceTest' 'unit' \
    'app/Domain/Onchain/Services/DiscoveryRunService.php' \
    "            usleep(self::RACE_SLEEP_US);" \
    "            break;"

# 13. Only a pass that RAN is a success. Marking every outcome succeeded
#     records a scan that never started as a completed one.
control 'executor: locked is not success' \
    'CosmwasmOneShotCliTest' 'unit' \
    'app/Domain/Onchain/Workers/DiscoveryRunExecutor.php' \
    "        if (\$outcome === CosmwasmDiscoveryWorker::PASS_RAN) {" \
    "        if (true) {"

# 14. The CLI pins INCREMENTAL so a backfill stays unreachable from a
#     terminal. Removing the pin lets the server choose historical.
control 'cli: incremental is pinned' \
    'CosmwasmOneShotCliTest' 'unit' \
    'app/Domain/Onchain/CLI/CosmwasmOneShotDiscoveryCommand.php' \
    "            \\BCC\\Trust\\Onchain\\ValueObjects\\DiscoveryScanMode::INCREMENTAL" \
    "            null"

# 15. A shell action must never be attributed to an arbitrary administrator.
control 'cli: --user-id is required to execute' \
    'CosmwasmOneShotCliTest' 'unit' \
    'app/Domain/Onchain/CLI/CosmwasmOneShotDiscoveryCommand.php' \
    "        if (!is_int(\$userId) || \$userId <= 0) {" \
    "        if (false) {"

echo
echo "killed: $PASS   survivors/errors: $FAIL"

if [ "$FAIL" -ne 0 ]; then
    echo
    echo "A SURVIVOR means the named test does not actually exercise the fix."
    echo "An ERROR means the anchor did not match — the control never ran, which"
    echo "proves nothing either way. Both are failures."
    exit 1
fi

exit 0
