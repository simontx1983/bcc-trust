#!/usr/bin/env bash
#
# PR E — repeatable mutation controls for the fail-closed boundaries.
#
# Each mutation reintroduces exactly one reviewed defect. A mutation that the
# suite does NOT kill means the test protecting that boundary is decorative.
#
# ── THE THREE OUTCOMES, AND WHY "BROKEN" IS NOT "PASS" ──────────────────
#   KILLED   the patch applied AND the named test failed  → the boundary holds
#   SURVIVED the patch applied AND the suite stayed green → REAL REGRESSION
#   BROKEN   the patch did not apply                      → COUNTS AS FAILURE
#
# A mutation whose anchor has drifted silently stops testing anything, and a
# script that reported that as "skip" would go green while protecting nothing.
# BROKEN is therefore a failure here, not a note.
#
# Every file is restored from its pre-mutation bytes and verified byte-identical
# by checksum afterwards — a mutation run that corrupts the tree is worse than
# no mutation run at all.
#
# ── WHY THESE TWENTY-NINE ──────────────────────────────────────────────
# THIRTEEN of them reintroduce defects that were actually written and actually
# caught in review, not hypotheticals.
#
# From rounds 2–3:
#   S1  the Solana probe asking getAsset — a question about ONE asset — and
#       reading the answer as proof about a whole collection
#   S2  gating on `grouping[].verified`, a property that DOES NOT EXIST in the
#       DAS specification and was invented by an earlier draft of this code
#   S3  reading the generic `-32000` server error as "Asset Not Found", which
#       turns a provider outage into a permanent negative about an address
#   S4  honouring `-32004` from a method that does not document it
#   S5  letting a compressed member through, silently breaking DECISION 8
#   S6  reading a MISSING `compression` object as "not compressed"
#   A1  gating manual intake on ENUMERATION, which no EVM or Solana driver
#       claims — the grant was refused on exactly the chains it exists for
#   A2  printing "checked against the chain" for chains that are not checked
#
# From round 5 (numbers 27–28 below):
#   T1  `total > limit` as the whole supply guard, which proves only that
#       `limit` is an integer below `total` — `limit: 500`, `0` and `-1` all
#       satisfied it, so any page size the provider substituted was accepted
#
# From round 4 (numbers 19–26 and 29 below):
#   R1  deciding compression from ONE SAMPLED MEMBER, so a mixed collection
#       whose first sample happened to be uncompressed validated and persisted
#   R2  folding "could not ask" into "no compressed members" — the collapse
#       that makes an expired API key read as a clean collection
#   R3  the RENDERER still reading the enumeration answer while the WRITER
#       granted on canTakeManualIntake(), leaving the permission reachable
#       only by forging a POST
#   R4  the description review forms not existing at all, which made
#       DECISION 17's "the admin review interface is the reader" false
#   R5  a review nonce bound to the route but not the collection id
#
# The rest pin boundaries that were built correctly and must stay that way.

set -uo pipefail
cd "$(dirname "$0")/../.." || exit 1

PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: $PHPUNIT missing (composer install first)"; exit 2; }

KILLED=0; SURVIVED=0; BROKEN=0
FAILED_NAMES=()

BACKUP_DIR="$(mktemp -d)"
trap 'rm -rf "$BACKUP_DIR"' EXIT

# snapshot <file>  — keep the original bytes and their checksum
snapshot() {
  local f="$1" key
  key="$(echo "$f" | md5sum | cut -d' ' -f1)"
  cp "$f" "$BACKUP_DIR/$key.bak"
  md5sum "$f" | cut -d' ' -f1 > "$BACKUP_DIR/$key.sum"
}

# restore <file> — put the original bytes back and PROVE they match
restore() {
  local f="$1" key before after
  key="$(echo "$f" | md5sum | cut -d' ' -f1)"
  cp "$BACKUP_DIR/$key.bak" "$f"
  before="$(cat "$BACKUP_DIR/$key.sum")"
  after="$(md5sum "$f" | cut -d' ' -f1)"
  if [ "$before" != "$after" ]; then
    echo "FATAL: $f was not restored byte-identically ($before != $after)"
    exit 3
  fi
}

# mutate <name> <file> <php-literal-search> <php-literal-replace> <test-filter>
mutate() {
  local name="$1" file="$2" search="$3" replace="$4" filter="$5"

  if [ ! -f "$file" ]; then
    echo "  BROKEN   $name  (file missing: $file)"
    BROKEN=$((BROKEN + 1)); FAILED_NAMES+=("$name [file missing]"); return
  fi

  snapshot "$file"

  # Literal, non-regex replacement of the FIRST occurrence, via PHP so the
  # search string needs no shell or sed escaping.
  #
  # ⚠ LINE ENDINGS. This repo is checked out CRLF while these anchors are
  # written with LF, so a naive strpos() misses every multi-line anchor and
  # the whole script reports BROKEN. Both the needle and the replacement are
  # therefore tried in the file's own ending, and the replacement is emitted
  # to match — otherwise a mutation would leave mixed endings behind and the
  # byte-identical restore check would be the only thing that noticed.
  SEARCH="$search" REPLACE="$replace" php -r '
    $f = $argv[1];
    $s = file_get_contents($f);
    $a = getenv("SEARCH"); $b = getenv("REPLACE");

    $i = strpos($s, $a);
    if ($i === false) {
      $aCrlf = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $a));
      $i = strpos($s, $aCrlf);
      if ($i === false) { exit(9); }
      $a = $aCrlf;
      $b = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $b));
    }

    file_put_contents($f, substr_replace($s, $b, $i, strlen($a)));
    exit(0);
  ' "$file"
  local applied=$?

  if [ $applied -ne 0 ]; then
    echo "  BROKEN   $name  (anchor not found — the mutation tested nothing)"
    BROKEN=$((BROKEN + 1)); FAILED_NAMES+=("$name [anchor drifted]")
    restore "$file"; return
  fi

  if php -l "$file" > /dev/null 2>&1; then
    local rc
    if [[ "$filter" == integration:* ]]; then
      # ⚠ Some boundaries can only be proved against a REAL database — the
      # unit-suite repository is a double that records its arguments and never
      # issues SQL, so a vocabulary check there would pass no matter what the
      # repository does. Those mutations run the integration suite instead.
      if [ -z "${BCC_TEST_DB_PORT:-}" ]; then
        echo "  BROKEN   $name  (needs a database: set BCC_TEST_DB_PORT etc.)"
        BROKEN=$((BROKEN + 1)); FAILED_NAMES+=("$name [no database]")
        restore "$file"; return
      fi
      php -d extension=mysqli "$PHPUNIT" -c phpunit-integration.xml.dist \
        --no-coverage --filter "${filter#integration:}" > /dev/null 2>&1
      rc=$?
    else
      php "$PHPUNIT" --no-coverage --filter "$filter" > /dev/null 2>&1
      rc=$?
    fi
    if [ $rc -ne 0 ]; then
      echo "  KILLED   $name"
      KILLED=$((KILLED + 1))
    else
      echo "  SURVIVED $name  <-- REGRESSION: the boundary is unprotected"
      SURVIVED=$((SURVIVED + 1)); FAILED_NAMES+=("$name [survived]")
    fi
  else
    echo "  BROKEN   $name  (mutated file does not parse)"
    BROKEN=$((BROKEN + 1)); FAILED_NAMES+=("$name [parse error]")
  fi

  restore "$file"
}

EVM_PROBE="app/Domain/Onchain/Services/Validation/EvmContractProbe.php"
SOL_PROBE="app/Domain/Onchain/Services/Validation/SolanaContractProbe.php"
SOL_FETCH="app/Domain/Onchain/Fetchers/SolanaFetcher.php"
COS_PROBE="app/Domain/Onchain/Services/Validation/CosmosContractProbe.php"
VALIDATOR="app/Domain/Onchain/Services/ContractValidator.php"
VC_PAGE="app/Domain/Onchain/Admin/VerifyCollectionsPage.php"
DISC_PAGE="app/Domain/Onchain/Admin/NftDiscoveryPage.php"
CAP_PANEL="app/Domain/Onchain/Admin/Views/NftCapabilityEditorPanel.php"
CAP_EDITOR="app/Domain/Onchain/Services/NftCapabilityEditor.php"
CAPABILITY="app/Domain/Onchain/Support/NftChainCapability.php"
INTAKE_META="app/Domain/Onchain/ValueObjects/IntakeMetadata.php"
COL_REPO="app/Domain/Onchain/Repositories/CollectionRepository.php"

echo "PR E fail-closed mutation controls"
echo "=================================="

# ══ EVM ════════════════════════════════════════════════════════════════

# ── 1. EVM metadata failure changed back to VALID ───────────────────────
mutate "evm-metadata-failure-becomes-valid" "$EVM_PROBE" \
  '            $metadata = $this->collectMetadata($contract, $budget);
            if ($metadata === null) {' \
  '            $metadata = $this->collectMetadata($contract, $budget) ?? IntakeMetadata::unknown();
            if (false) {' \
  'EvmContractProbeTest|PrEFailClosedBoundariesTest'

# ── 2. EVM launch allowlist removed at the validator ───────────────────
mutate "evm-launch-allowlist-removed" "$VALIDATOR" \
  "if (\$family === 'evm' && !NftLaunchChains::isLaunchChain(\$chain)) {" \
  'if (false) {' \
  'PrEFailClosedBoundariesTest'

# ── 3. Malformed ERC-165 result treated as false ────────────────────────
mutate "erc165-malformed-treated-as-false" "$EVM_PROBE" \
  '        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            return ['"'"'supported'"'"' => false, '"'"'kind'"'"' => '"'"'malformed'"'"'];
        }' \
  '        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            return ['"'"'supported'"'"' => false, '"'"'kind'"'"' => '"'"'none'"'"'];
        }' \
  'EvmContractProbeTest'

# ══ SOLANA — the model that had to be rebuilt on the documented API ═════

# ── 4. The wrong DAS method entirely ───────────────────────────────────
# getAsset answers "what is this ONE asset?". getAssetsByGroup answers "who
# belongs to this collection, and is the grouping certified?". Substituting
# the first for the second is how a plain NFT mint got read as a collection.
mutate "solana-getasset-instead-of-group-method" "$SOL_PROBE" \
  '$group = $this->fetcher->assetsByGroupResult($mint);' \
  '$group = $this->fetcher->assetResult($mint);' \
  'SolanaContractProbeTest'

# ── 5. The invented `grouping[].verified` property required again ───────
# ⚠ THIS PROPERTY DOES NOT EXIST. A DAS `grouping[]` entry carries exactly
# `group_key` and `group_value`. Gating on a third field means every real
# Helius response fails the gate, so genuine collections are refused — and
# the code looks stricter while being strictly wrong.
mutate "solana-invented-verified-property-required" "$SOL_PROBE" \
  '        // ── 2a. Is the SAMPLED member compressed? A cheap early negative. ─' \
  '        $grouping = is_array($sample['"'"'grouping'"'"'] ?? null) ? $sample['"'"'grouping'"'"'] : [];
        $verified = false;
        foreach ($grouping as $g) {
            if (is_array($g) && ($g['"'"'verified'"'"'] ?? false) === true) {
                $verified = true;
            }
        }
        if (!$verified) {
            return ContractValidationVerdict::invalid([
                ContractValidationVerdict::EV_GROUPING_UNVERIFIED,
            ]);
        }

        // ── 2. Sampled member compressed? (partial DECISION 8 — see class doc)' \
  'SolanaContractProbeTest'

# ── 6. The generic -32000 server error read as a decided negative ───────
mutate "solana-generic-server-error-becomes-not-found" "$SOL_FETCH" \
  '        if ($code === self::DAS_NOT_FOUND) {' \
  '        if ($code === self::DAS_NOT_FOUND || $code === -32000) {' \
  'SolanaContractProbeTest'

# ── 7. not-found honoured from a method that does not document it ──────
mutate "solana-not-found-accepted-from-any-method" "$SOL_FETCH" \
  '            return match ($method) {
                self::METHOD_GET_ASSET, self::METHOD_ASSETS_BY_GROUP => '"'"'not_found'"'"',
                default => '"'"'transport'"'"',
            };' \
  "            return 'not_found';" \
  'SolanaContractProbeTest'

# ── 8. A compressed member accepted (DECISION 8 breached) ──────────────
mutate "solana-compressed-member-accepted" "$SOL_PROBE" \
  "        if ((\$compression['compressed'] ?? null) === true) {" \
  '        if (false) {' \
  'SolanaContractProbeTest'

# ── 9. Compression UNCERTAINTY treated as "not compressed" ─────────────
# A missing `compression` object is not evidence of an uncompressed asset;
# it means the response is not the shape the documentation describes.
mutate "solana-compression-absence-treated-as-supported" "$SOL_PROBE" \
  "        if (!array_key_exists('compressed', \$compression)) {" \
  '        if (false) {' \
  'SolanaContractProbeTest'

# ══ COSMOS ═════════════════════════════════════════════════════════════

# ── 10. Cosmos PROBABLE changed back to VALID ──────────────────────────
mutate "cosmos-probable-becomes-valid" "$COS_PROBE" \
  '        if ($class !== CosmwasmClassifier::CONFIRMED) {' \
  '        if (false) {' \
  'CosmosNoWasmModuleIsNeverNotAnNftTest'

# ══ THE ADMIN PATH — a boundary is worthless if unreachable or misdescribed

# ── 11. Manual intake re-coupled to ENUMERATION ────────────────────────
# No EVM or Solana driver claims enumeration, so this predicate refuses the
# grant on exactly the three chains manual intake was built for.
mutate "manual-intake-recoupled-to-enumeration" "$CAP_EDITOR" \
  'if (!NftChainCapability::canTakeManualIntake($chain)) {' \
  'if (!NftChainCapability::hasOperatorStartableOperation($chain)) {' \
  'NftCapabilityEditorFlagTest'

# ── 12. Every chain rendered as validated ──────────────────────────────
# The over-claim: an operator told the address was "checked against the
# chain" when nothing checked it.
mutate "chains-rendered-as-validated-without-checking" "$DISC_PAGE" \
  "            if ((\$row['manual_intake'] ?? null) === true) {" \
  '            if (true) {' \
  'ManualIntakeValidationDisclosureTest'

# ── 13. An UNREADABLE capability read as validated ─────────────────────
mutate "unreadable-capability-read-as-validated" "$DISC_PAGE" \
  "            if ((\$row['manual_intake'] ?? null) === true) {" \
  "            if ((\$row['manual_intake'] ?? null) !== false) {" \
  'ManualIntakeValidationDisclosureTest'

# ── 14. DECISION 7 launch scope dropped from the capability itself ─────
mutate "launch-scope-dropped-from-intake-capability" "$CAPABILITY" \
  "        if (strtolower((string) (\$chain->chain_type ?? '')) === 'evm') {
            return NftLaunchChains::isLaunchChain(\$chain);
        }" \
  '        // launch narrowing removed' \
  'ManualIntakeValidationDisclosureTest|NftCapabilityEditorFlagTest'

# ══ METADATA STATE ═════════════════════════════════════════════════════

# ── 15. A successful EVM/Solana read left permanently `partial` ────────
# Counting fields the family never fetches as outstanding makes `complete`
# unreachable for two of the three families — and an operator investigating
# a `partial` row would find no missing field to explain it.
mutate "successful-family-read-stays-partial" "$INTAKE_META" \
  '            if ($this->fields[$f]['"'"'state'"'"'] === self::NOT_APPLICABLE) {
                continue; // never attempted, never a shortfall
            }
            $applicable++;' \
  '            $applicable++;' \
  'IntakeMetadataFamilyStateTest'

# ── 16. The family declaration never applied ───────────────────────────
mutate "family-fields-not-marked-inapplicable" "$INTAKE_META" \
  "                \$m->fields[\$f] = ['value' => null, 'state' => self::NOT_APPLICABLE];" \
  "                \$m->fields[\$f] = ['value' => null, 'state' => self::UNKNOWN];" \
  'IntakeMetadataFamilyStateTest'

# ══ THE REPOSITORY — the last gate before a row exists ═════════════════

# ── 17. An invalid metadata_state silently writing a row ───────────────
# ⚠ REQUIRES A DATABASE. The unit double records arguments and issues no
# SQL, so it would report this boundary intact no matter what the repository
# did. Proving "no row was created" needs a real table.
mutate "metadata-state-vocabulary-not-enforced" "$COL_REPO" \
  '            if (!is_string($stateRaw) || !in_array($stateRaw, self::METADATA_STATES, true)) {' \
  '            if (false) {' \
  'integration:CollectionMetadataStateIntegrationTest'

# ══ AUDIT ══════════════════════════════════════════════════════════════

# ── 18. Audit failure allowed to commit the approval ───────────────────
mutate "description-audit-failure-commits" "$VC_PAGE" \
  "                if (\$auditId === null) {
                    throw new \\RuntimeException(
                        'checked audit write failed; rolling back the description state change'
                    );
                }" \
  '                if ($auditId === null) {
                    return true;
                }' \
  'PrEFailClosedBoundariesTest'

# ══ ROUND 4 ════════════════════════════════════════════════════════════
#
# Seven more, one per defect the round-4 review found or forbade. Five of the
# seven restore something that was ACTUALLY in the tree at head c8ab9434.

# ── 19. The cNFT existence query skipped entirely ──────────────────────
# ⚠⚠⚠ This is the round-3 behaviour: decide compression from ONE sampled
# member. A mixed collection whose first sample is uncompressed then validates
# and is persisted, which is the DECISION 8 breach the review required closing.
mutate "cnft-existence-query-skipped" "$SOL_PROBE" \
  '$compressedExists = $this->compressedMembersExist($mint, $budget);' \
  '$compressedExists = false;' \
  'SolanaContractProbeTest'

# ── 20. A compressed match treated as acceptable ───────────────────────
mutate "compressed-match-treated-as-acceptable" "$SOL_PROBE" \
  'if ($compressedExists === true) {' \
  'if (false) {' \
  'SolanaContractProbeTest'

# ── 21. Provider uncertainty read as zero compressed ───────────────────
# The dangerous collapse: `null` (could not ask / could not understand) folded
# into the safe-looking answer. An expired Helius key would then read as "this
# collection has no compressed members".
mutate "compressed-uncertainty-read-as-zero" "$SOL_PROBE" \
  '        if ($compressedExists === null) {' \
  '        if (false) {' \
  'SolanaContractProbeTest'

# ── 22. The Solana budget cut back to two ──────────────────────────────
# Makes the third call unaffordable, so the exclusion silently stops running.
mutate "solana-budget-reduced-to-two" "$VALIDATOR" \
  'public const BUDGET_SOLANA = 3;' \
  'public const BUDGET_SOLANA = 2;' \
  'PrEFailClosedBoundariesTest|SolanaContractProbeTest'

# ── 23. The edition-print `supply` object written as collection supply ──
# `supply.print_current_supply` counts EDITION PRINTS of ONE master-edition
# NFT. It is a different number answering a different question, and it is the
# nearest plausible thing to reach for now that the group total is gone.
mutate "edition-print-supply-used-as-collection-supply" "$SOL_PROBE" \
  '        return $metadata
            ->withAnswered('"'"'name'"'"', is_string($name) && trim($name) !== '"'"''"'"' ? $name : null)' \
  '        $sup = is_array($r['"'"'result'"'"']['"'"'supply'"'"'] ?? null) ? $r['"'"'result'"'"']['"'"'supply'"'"'] : [];
        $pc = $sup['"'"'print_current_supply'"'"'] ?? null;
        if (is_int($pc)) { $metadata = $metadata->withAnswered('"'"'total_supply'"'"', $pc); }
        return $metadata
            ->withAnswered('"'"'name'"'"', is_string($name) && trim($name) !== '"'"''"'"' ? $name : null)' \
  'SolanaSupplyNotApplicableTest'

# ── 24. The renderer back on the enumeration answer ────────────────────
# ⚠⚠⚠ BLOCKER 1 EXACTLY. The writer grants on canTakeManualIntake(); the panel
# read `operator_startable`. Ethereum, Base and Solana became grantable only by
# forging a POST. `operator_startable` no longer exists on the row, so this
# also proves the projection was genuinely renamed rather than duplicated.
mutate "renderer-back-on-the-enumeration-answer" "$CAP_PANEL" \
  "\$grantable = (\$chain['manual_intake'] ?? null) === true;" \
  "\$grantable = (\$chain['operator_startable'] ?? false) === true;" \
  'NftCapabilityEditorRenderTest'

# ── 25. The description review nonce unbound from the collection id ────
# The handler verifies `\$route . '_' . \$collectionId`. A nonce bound to the
# route alone makes the per-row binding decorative: one row's token would
# decide another row's text.
mutate "description-nonce-unbound-from-the-id" "$VC_PAGE" \
  "                    <?php wp_nonce_field(\$route . '_' . \$rowId); ?>" \
  "                    <?php wp_nonce_field(\$route); ?>" \
  'ChainDescriptionReviewInterfaceTest'

# ── 26. The description review forms removed ───────────────────────────
# The state PR E actually shipped in: handlers with no reachable caller, so
# DECISION 17's "the admin review interface is the reader" was false.
mutate "description-review-forms-removed" "$VC_PAGE" \
  '            if (!is_object($row) || !self::descriptionAwaitsReview($row)) {' \
  '            if (true) {' \
  'ChainDescriptionReviewInterfaceTest'

# ── 27. `total_supply` made APPLICABLE again on Solana ─────────────────
# ⚠⚠⚠ THE FIELD CAN NEVER RESOLVE. Live measurement proved the group
# response's `total` is the returned PAGE COUNT, and no other bounded source
# exists — so counting supply as an outstanding field makes `complete`
# permanently unreachable and every Solana row permanently `partial`.
mutate "solana-supply-made-applicable-again" "$INTAKE_META" \
  "        'solana' => ['name', 'image_url']," \
  "        'solana' => ['name', 'image_url', 'total_supply']," \
  'SolanaSupplyNotApplicableTest'

# ── 28. Supply re-derived from the group response ──────────────────────
# The regression this PR exists to make impossible: writing the returned page
# count into `total_supply`. Any collection would get a supply equal to the
# page size — a confident number nobody measured.
mutate "solana-supply-rederived-from-group-total" "$SOL_PROBE" \
  '        return ContractValidationVerdict::valid('"'"'SPL-Metaplex'"'"', $metadata, [' \
  '        $t = $result['"'"'total'"'"'] ?? null;
        if (is_int($t)) { $metadata = $metadata->withAnswered('"'"'total_supply'"'"', $t); }
        return ContractValidationVerdict::valid('"'"'SPL-Metaplex'"'"', $metadata, [' \
  'SolanaSupplyNotApplicableTest'

# ── 29. A decided description offered a repeat transition ──────────────
# `approved` / `rejected` are terminal at the repository, so a control there is
# a button that can never work and a notice the page implied would not appear.
mutate "decided-description-offers-a-repeat-transition" "$VC_PAGE" \
  '            <?php if (self::descriptionAwaitsReview($row)): ?>' \
  '            <?php if (true): ?>' \
  'ChainDescriptionReviewInterfaceTest'

echo
echo "=================================="
echo "killed=$KILLED survived=$SURVIVED broken=$BROKEN"

if [ ${#FAILED_NAMES[@]} -gt 0 ]; then
  echo
  echo "NOT KILLED:"
  for n in "${FAILED_NAMES[@]}"; do echo "  - $n"; done
fi

# ⚠ BROKEN counts as failure: a mutation that never applied proved nothing.
if [ "$SURVIVED" -gt 0 ] || [ "$BROKEN" -gt 0 ]; then
  echo
  echo "FAIL: every mutation must be KILLED."
  exit 1
fi

echo "PASS: all $KILLED mutations killed, 0 survived, 0 broken."
exit 0
