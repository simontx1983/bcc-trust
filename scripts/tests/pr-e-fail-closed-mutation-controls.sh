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
COL_REPO="app/Domain/Onchain/Repositories/CollectionRepository.php"

echo "PR E fail-closed mutation controls"
echo "=================================="

# ── 1. EVM metadata failure changed back to VALID ───────────────────────
mutate "evm-metadata-failure-becomes-valid" "$EVM_PROBE" \
  '            $metadata = $this->collectMetadata($contract, $budget);
            if ($metadata === null) {' \
  '            $metadata = $this->collectMetadata($contract, $budget) ?? IntakeMetadata::unknown();
            if (false) {' \
  'EvmContractProbeTest|PrEFailClosedBoundariesTest'

# ── 2. EVM launch allowlist removed ─────────────────────────────────────
mutate "evm-launch-allowlist-removed" "$VALIDATOR" \
  "if (\$family === 'evm' && !NftLaunchChains::isLaunchChain(\$chain)) {" \
  'if (false) {' \
  'PrEFailClosedBoundariesTest'

# ── 3. Solana generic RPC error changed to INVALID (not_found) ──────────
mutate "solana-generic-error-becomes-not-found" "$SOL_FETCH" \
  "        \$code = \$error['code'] ?? null;
        if (!is_int(\$code) || \$code !== self::DAS_ERROR_SERVER) {
            return 'transport';
        }" \
  "        return 'not_found';" \
  'SolanaContractProbeTest'

# ── 4. Unverified Solana self-reference accepted ────────────────────────
mutate "solana-self-reference-bypass-restored" "$SOL_PROBE" \
  "            if ((\$g['verified'] ?? false) === true) {
                return true;
            }" \
  "            if ((\$g['verified'] ?? false) === true) {
                return true;
            }
            if (\$value !== '') {
                return true;
            }" \
  'SolanaContractProbeTest'

# ── 5. Malformed ERC-165 result treated as false ────────────────────────
mutate "erc165-malformed-treated-as-false" "$EVM_PROBE" \
  '        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            return ['"'"'supported'"'"' => false, '"'"'kind'"'"' => '"'"'malformed'"'"'];
        }' \
  '        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            return ['"'"'supported'"'"' => false, '"'"'kind'"'"' => '"'"'none'"'"'];
        }' \
  'EvmContractProbeTest'

# ── 6. Audit failure allowed to commit the approval ─────────────────────
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

# ── 7. Cosmos PROBABLE changed back to VALID ────────────────────────────
mutate "cosmos-probable-becomes-valid" "$COS_PROBE" \
  '        if ($class !== CosmwasmClassifier::CONFIRMED) {' \
  '        if (false) {' \
  'CosmosNoWasmModuleIsNeverNotAnNftTest'

# ── 8. Invalid metadata_state accepted at the repository ────────────────
mutate "metadata-state-vocabulary-not-enforced" "$COL_REPO" \
  '$stateProvided = is_string($stateRaw) && in_array($stateRaw, self::METADATA_STATES, true);' \
  '$stateProvided = is_string($stateRaw);' \
  'integration:CollectionMetadataStateIntegrationTest'

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
