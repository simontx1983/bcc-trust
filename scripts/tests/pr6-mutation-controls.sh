#!/usr/bin/env bash
#
# PR 6 mutation controls.
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
if ! "$PHP" "${PHP_ARGS[@]}" vendor/bin/phpunit --no-coverage --filter ProvisioningStateModelTest >/dev/null 2>&1; then
    echo "FATAL: the anchor test does not pass on a clean tree — fix that first." >&2
    echo "       (Concluding 'mutant killed' from a harness that always fails is worthless.)" >&2
    exit 2
fi

# `includes/` joined the list when the Blocker 5 controls landed: they plant
# defects in the schema migration and the version-stamp gate, and `restore()`
# reverts with `git checkout --`, which would silently wipe uncommitted work
# there. It would also fail outright on an UNTRACKED file, leaving a planted
# defect behind — the one outcome this script must never produce.
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

echo "PR 6 mutation controls — each plants a defect and requires a test to catch it"
echo

# 1. The verification guard on Request community.
#    Without it an unverified collection can record intent, and the daily
#    sweep then has a queue entry for something nobody vouched for.
control 'request: verification guard' \
    'CommunityRequestServiceTest' 'unit' \
    'app/Domain/Onchain/Services/CommunityRequestService.php' \
    "        if ((int) \$row->is_verified !== 1) {
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_NOT_VERIFIED];
        }" \
    '        // mutant: verification no longer required to record intent'

# 2. The INTENT GATE — the single point that makes "verified alone never
#    provisions" true for every caller at once.
#
#    Note this plants the gate itself and not the queue query. Swapping
#    listRequested() for listVerified() in processRequested() does NOT
#    reproduce the defect, because this gate would still refuse every row —
#    which is precisely why the gate lives in the service rather than in the
#    SELECT. Planting the weaker mutation would produce a survivor and teach
#    us nothing.
control 'provision: recorded-intent gate' \
    'GatedGroupProvisioningIntentTest' 'unit' \
    'app/Domain/Onchain/Services/GatedGroupProvisioningService.php' \
    "        if ((string) \$row->provisioning_state !== ProvisioningState::REQUESTED) {" \
    '        if ((int) $row->is_verified !== 1) {'

# 3. Swallow a failed checked audit. The state change then commits with no
#    honest record that a person made it.
control 'request: checked-audit rollback' \
    'CommunityRequestServiceTest' 'unit' \
    'app/Domain/Onchain/Services/CommunityRequestService.php' \
    "                if (\$auditId === null) {
                    // A request nobody can prove was made is not a request.
                    throw new \RuntimeException('checked audit write failed; rolling back the request');
                }" \
    '                // mutant: audit failure ignored'

# 4. Skip the postcondition re-read. `writeGateConfig()` returning true is
#    not the same as the gate being readable afterwards.
control 'provision: postcondition re-read' \
    'GatedGroupProvisioningIntentTest' 'unit' \
    'app/Domain/Onchain/Services/GatedGroupProvisioningService.php' \
    "                \$config = GatedGroupRepository::getGateConfig(\$groupId);
                if (\$config === null
                    || \$config->collectionId !== \$collectionId
                    || \$config->chainId !== \$chainId
                    || strcmp(\$config->contractAddress, \$canonical) !== 0
                ) {
                    throw new \RuntimeException('gate config did not survive its postcondition re-read');
                }" \
    '                // mutant: postcondition not re-read'

# 5. Remove compensation. The rolled-back transaction leaves the PeepSo
#    group standing, invisible to findGroupForCollection(), and the next run
#    creates a duplicate.
control 'provision: compensation' \
    'GatedGroupProvisioningIntentTest' 'unit' \
    'app/Domain/Onchain/Services/GatedGroupProvisioningService.php' \
    "            \$this->compensate(\$groupId, \$collectionId, \$ownerId, \$chainId);" \
    '            // mutant: no compensation — the group is left behind'

# 6. Accept a chain whose family does not match the tab the nonce was minted
#    on. A Solana-tab nonce could then drive an add against a Cosmos chain.
control 'add: chain/family mismatch' \
    'ManualCollectionIntakeServiceTest' 'unit' \
    'app/Domain/Onchain/Services/ManualCollectionIntakeService.php' \
    "        if (\$chainFamily !== \$family) {
            return \$this->refuse(self::REFUSED_FAMILY_MISMATCH, \$chainId, \$operatorId, \$family);
        }" \
    '        // mutant: submitted chain need not belong to the family'

# 7. Fold the canonical identity to lowercase on the way to the writer. For
#    Solana that produces a DIFFERENT 32-byte key — the original PR 5a/5b
#    defect, reintroduced at the intake boundary.
control 'add: solana case preservation' \
    'ManualCollectionIntakeServiceTest' 'unit' \
    'app/Domain/Onchain/Services/ManualCollectionIntakeService.php' \
    "        \$canonical = \$identity->canonical();" \
    '        $canonical = strtolower($identity->canonical());'

# 8. Remove the POST enforcement from a mutation route, making it reachable
#    by GET with a nonce in the query string — i.e. from an <img> tag.
control 'route: POST enforcement' \
    'VerifyCollectionsHandlerSecurityTest' 'unit' \
    'app/Domain/Onchain/Admin/VerifyCollectionsPage.php' \
    "        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        // Shape first: the nonce action is derived from the id, so it must be
        // read before the nonce can be checked. Nothing touches the database
        // until the nonce has proven the request authentic.
        \$collectionId = self::requireCollectionIdShape();" \
    "        AdminActionSupport::requireCapability();

        \$collectionId = self::requireCollectionIdShape();"

# 9. Cap the set of gate relationships the tab predicate considers.
#
#    This is the failure mode a "load the first N gated ids, then classify"
#    implementation has: every row beyond the cap is silently misclassified,
#    the counts stop matching the pages, and nothing errors. The cap is 200
#    rather than 500 only so the fixture can stay small — the defect class,
#    and the test that must catch it, are identical either way.
control 'tabs: bounded-subset misclassification' \
    'CollectionStateTabsIntegrationTest' 'integration' \
    'app/Domain/Onchain/Services/CollectionStateClassifier.php' \
    "             WHERE pm_coll.meta_key = '\" . GatedGroupRepository::META_COLLECTION . \"'
               AND pm_coll.meta_value = c.id" \
    "             WHERE pm_coll.meta_key = '\" . GatedGroupRepository::META_COLLECTION . \"'
               AND pm_coll.meta_value = c.id
               AND pm_coll.post_id IN (
                   SELECT * FROM (
                       SELECT post_id FROM {\$postmetaTable}
                        WHERE meta_key = '\" . GatedGroupRepository::META_COLLECTION . \"'
                        ORDER BY post_id LIMIT 200
                   ) capped
               )"

# ── Blocker 6: a failed read is not an empty queue ───────────────────────

# 10. The original defect verbatim. `get_results()` hands back an empty
#     array for a FAILED query exactly as it does for a genuine no-rows
#     result, so collapsing the two makes a sweep whose SELECT never ran
#     report a clean zero.
control 'queue: read failure vs empty' \
    'ProvisioningReadFailureTest' 'unit' \
    'app/Domain/Onchain/Repositories/CollectionRepository.php' \
    "        if (\$readFailed) {
            \BCC\Core\Log\Logger::error(
                '[bcc-trust] the provisioning queue could not be read; reporting UNAVAILABLE, not empty',
                ['after_id' => \$afterId, 'db_error' => \$wpdb->last_error]
            );

            return ['rows' => [], 'available' => false];
        }" \
    '        // mutant: a failed read is reported as an empty queue'

# 11. The single-row twin. A fault reported as `available` lets a caller
#     conclude "no such collection" from a query that never executed.
control 'row: read failure vs not-found' \
    'ProvisioningReadFailureTest' 'unit' \
    'app/Domain/Onchain/Repositories/CollectionRepository.php' \
    "            return ['row' => null, 'available' => false];" \
    "            return ['row' => null, 'available' => true];"

# 12. And the service boundary: even with an honest repository, a caller
#     that folds `unavailable` back into `skipped` re-creates the defect —
#     the sweep walks past every row a flaky database declined to return.
control 'provision: unavailable is not skipped' \
    'ProvisioningReadFailureTest' 'unit' \
    'app/Domain/Onchain/Services/GatedGroupProvisioningService.php' \
    "        if (!\$read['available']) {
            return [
                'status'           => 'unavailable'," \
    "        if (false) {
            return [
                'status'           => 'skipped',"

# ── Blocker 5: an unfinished migration must not be stamped ───────────────

# 13. The defect itself: stamp unconditionally. The version is a content
#     hash of files that have not changed, so once it is stamped nothing
#     will ever bump it again and the migration is never retried.
control 'schema: completion gates the stamp' \
    'SchemaCompletionGateIntegrationTest' 'integration' \
    'includes/database/schema-completion.php' \
    '        if (!$migrationsComplete) {' \
    '        if (false) {'

# 14. A migration step that bails must SAY so. Returning true from the
#     unverifiable-probe branch is the subtler half of the same bug: the
#     gate above stays intact and is simply told the wrong answer.
control 'schema: unverified probe reports false' \
    'SchemaCompletionGateIntegrationTest' 'integration' \
    'includes/database/schema-collections.php' \
    "                    '[schema-collections-provisioning] could not determine whether a column exists; treating as UNVERIFIED, not absent',
                    ['table' => \$table, 'column' => \$column]
                );
                return false;" \
    "                    '[schema-collections-provisioning] could not determine whether a column exists; treating as UNVERIFIED, not absent',
                    ['table' => \$table, 'column' => \$column]
                );
                return true;"

# 15. The write-verification re-read. `update_option()` returning true is
#     not proof `get_option()` will serve the new value — a stale object
#     cache makes exactly that combination, and without the re-read the
#     installer runs on every request forever with nothing reporting it.
control 'schema: stamp write is verified' \
    'SchemaCompletionGateIntegrationTest' 'integration' \
    'includes/database/schema-completion.php' \
    "        \$reread = get_option('bcc_trust_schema_version', '');" \
    '        $reread = $computed;'

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
