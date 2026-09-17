#!/usr/bin/env bash
#
# PR 7.14 mutation controls — NFT membership revocation must fail safely.
#
# Each control breaks ONE guarantee in executable code and runs the suites
# that should notice. Verdicts:
#
#   killed        a test NAMED for that guarantee failed
#   wrong-reason  the run went red, but only in tests not named for it — this
#                 is NOT counted as killed
#   survived      everything stayed green with the guarantee broken
#   broken        the mutation changed no bytes (it tested nothing)
#   skipped       the suite for that control could not run here
#
# ⚠ EVERY MUTATION PROVES IT CHANGED CODE (byte diff before/after).
# ⚠ RESTORE FROM A BYTE SNAPSHOT TAKEN ONCE, NEVER FROM GIT, and abort on a
#   failed restore — `git checkout --` once deleted six files mid-run.
# ⚠ PHP `$vars` in a mutation are written `\$name` (bash double quotes).
#
# Usage:
#   bash scripts/tests/pr714-mutation-controls.sh            unit + integration
#   BCC_TEST_DB_PORT=… bash scripts/tests/pr714-mutation-controls.sh
#
# The integration half needs the five BCC_TEST_DB_* variables pointing at a
# THROWAWAY MySQL; without a reachable one those halves report `skipped`.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 2

PHP="${PHP:-php -d extension=gmp -d extension=intl -d extension=mysqli}"
PHPUNIT="vendor/bin/phpunit"
[ -f "$PHPUNIT" ] || { echo "FATAL: phpunit not installed"; exit 2; }

HOLDINGS="app/Domain/Onchain/Services/HoldingsService.php"
REVOKE="app/Domain/Onchain/Services/NftGroupRevokeService.php"
COUNT="app/Domain/Onchain/ValueObjects/HoldingsCount.php"
COSMOS="app/Domain/Onchain/Fetchers/CosmosFetcher.php"
SOLANA="app/Domain/Onchain/Fetchers/SolanaFetcher.php"
WALLETS="app/Domain/Onchain/Repositories/WalletRepository.php"
LISTING="app/Domain/Onchain/REST/HolderGroupsEndpoint.php"
FILES=("$HOLDINGS" "$REVOKE" "$COUNT" "$COSMOS" "$SOLANA" "$WALLETS" "$LISTING")

UNIT_CLASSES="NftRevocationFailSafeTest|HoldingsEvidenceIncompleteTest|NftVerificationSurfaceBudgetTest|HoldingsCompletenessTest|StargazeFanOutRemovedTest|CosmosFetcherListHoldingsTest|CosmosGalleryVerifiedOnlyTest|StoredHoldingsAccessIsolationTest"
INTEGRATION_CLASS="NftRevocationFailSafeIntegrationTest"

if [ -n "$(git status --porcelain -- "${FILES[@]}")" ]; then
  echo "FATAL: mutated files are dirty. Commit or restore them first —"
  echo "       a snapshot of an edited tree proves nothing."
  exit 2
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
SNAPDIR="$WORK/snap"

for f in "${FILES[@]}"; do
  mkdir -p "$SNAPDIR/$(dirname "$f")"
  cp "$f" "$SNAPDIR/$f" || { echo "FATAL: cannot snapshot $f"; exit 2; }
done

restore () { cp "$SNAPDIR/$1" "$1"; }

verify_clean () {
  if ! cmp -s "$SNAPDIR/$1" "$1"; then
    restore "$1"
    if ! cmp -s "$SNAPDIR/$1" "$1"; then
      echo "FATAL: $1 was NOT restored after '$2'. Aborting — later controls would test a corrupted tree."
      exit 2
    fi
  fi
}

apply () {
  python - "$1" <<PY || { echo "FATAL: mutation script errored"; exit 2; }
import io, sys
p = sys.argv[1]
s = io.open(p, encoding='utf-8', newline='').read()
nl = '\r\n' if '\r\n' in s else '\n'
s = s.replace('\r\n', '\n')
${2}
io.open(p, 'w', encoding='utf-8', newline='').write(s.replace('\n', nl))
PY
}

INTEGRATION_OK=1
if ! $PHP -r '$c=@mysqli_connect(getenv("BCC_TEST_DB_HOST")?:"127.0.0.1",getenv("BCC_TEST_DB_USER")?:"root",getenv("BCC_TEST_DB_PASS")?:"root","",(int)(getenv("BCC_TEST_DB_PORT")?:10005)); exit($c?0:1);' 2>/dev/null; then
  INTEGRATION_OK=0
fi

# Failed test names (Class::method) from a JUnit file, one per line.
failures () {
  python - "$1" <<'PY'
import sys, xml.etree.ElementTree as ET
try:
    root = ET.parse(sys.argv[1]).getroot()
except Exception:
    print("__NO_JUNIT__"); sys.exit(0)
for tc in root.iter('testcase'):
    if tc.find('failure') is not None or tc.find('error') is not None:
        cls = tc.get('class', '').rsplit('\\', 1)[-1]
        print(f"{cls}::{tc.get('name')}")
PY
}

declare -A TALLY=([killed]=0 [wrong_reason]=0 [survived]=0 [broken]=0 [skipped]=0)
declare -a REPORT=()

# run_suite <label> <config|''> <filter> <expected regex> → sets VERDICT, DETAIL
run_suite () {
  local label="$1" config="$2" filter="$3" expected="$4" junit="$WORK/junit.xml" failed hit other
  rm -f "$junit"
  if [ -n "$config" ]; then
    $PHP "$PHPUNIT" -c "$config" --no-coverage --filter "$filter" --log-junit "$junit" >/dev/null 2>&1
  else
    $PHP "$PHPUNIT" --no-coverage --filter "$filter" --log-junit "$junit" >/dev/null 2>&1
  fi
  failed="$(failures "$junit")"
  if [ "$failed" = "__NO_JUNIT__" ]; then
    VERDICT="wrong_reason"; DETAIL="$label: no JUnit (fatal before tests ran)"; return
  fi
  if [ -z "$failed" ]; then
    VERDICT="survived"; DETAIL="$label: all green"; return
  fi
  hit="$(printf '%s\n' "$failed" | grep -E "$expected" || true)"
  other="$(printf '%s\n' "$failed" | grep -vE "$expected" || true)"
  if [ -n "$hit" ]; then
    VERDICT="killed"
    DETAIL="$label: $(printf '%s' "$hit" | tr '\n' ' ')"
    [ -n "$other" ] && DETAIL="$DETAIL | also red: $(printf '%s' "$other" | tr '\n' ' ')"
  else
    VERDICT="wrong_reason"; DETAIL="$label: only unrelated red: $(printf '%s' "$other" | tr '\n' ' ')"
  fi
}

# control <id> <name> <file> <python mutation> <unit expected regex|-> <integration expected regex|->
control () {
  local id="$1" name="$2" file="$3" mutation="$4" unitExp="$5" intExp="$6" before after
  before="$(md5sum "$file" | cut -d' ' -f1)"
  apply "$file" "$mutation"
  after="$(md5sum "$file" | cut -d' ' -f1)"

  if [ "$before" = "$after" ]; then
    TALLY[broken]=$((TALLY[broken]+1))
    REPORT+=("#$id broken        $name — no bytes changed")
    echo "  #$id BROKEN $name"
    restore "$file"; verify_clean "$file" "$name"; return
  fi

  if [ "$unitExp" != "-" ]; then
    run_suite "unit" "" "$UNIT_CLASSES" "$unitExp"
    TALLY[$VERDICT]=$((TALLY[$VERDICT]+1))
    REPORT+=("#$id $(printf '%-12s' "$VERDICT") $name [$DETAIL]")
    echo "  #$id unit        $VERDICT  $name"
  fi

  if [ "$intExp" != "-" ]; then
    if [ "$INTEGRATION_OK" = 1 ]; then
      run_suite "integration" "phpunit-integration.xml.dist" "$INTEGRATION_CLASS" "$intExp"
    else
      VERDICT="skipped"; DETAIL="integration: no reachable test database"
    fi
    TALLY[$VERDICT]=$((TALLY[$VERDICT]+1))
    REPORT+=("#$id $(printf '%-12s' "$VERDICT") $name [$DETAIL]")
    echo "  #$id integration $VERDICT  $name"
  fi

  restore "$file"; verify_clean "$file" "$name"
}

echo "PR 7.14 mutation controls (integration database: $([ "$INTEGRATION_OK" = 1 ] && echo reachable || echo UNREACHABLE))"
echo "──────────────────────────────────────────────────────────────"

# ── Required 1–14 ────────────────────────────────────────────────────────

control 1 "UNKNOWN changed to NOT_OWNED" "$HOLDINGS" \
  "s = s.replace('            return EligibilityVerdict::unknownBecause(\$min, \$best, \$reason);', '            return EligibilityVerdict::ineligible(\$min, \$best ?? 0);')" \
  "testOneUnknownWalletBlocksIneligibleButOnePositiveWins|testAProviderThatCouldNotAnswerIsUnknown|testACappedCountBelowTheThresholdIsUnknown|testKeptMembersBetweenRemovalsAreNeitherSkippedNorRevisited" \
  "testKeptMembersBetweenRemovalsAreNeitherSkippedNorRevisited"

control 2 "Repository failure changed to empty success (evaluator reads the folding reader)" "$HOLDINGS" \
  "s = s.replace('WalletRepository::getForUserOrThrow(\$userId, null, true)', 'WalletRepository::getForUser(\$userId, null, true)')" \
  "testAWalletReadFailureIsUnknownNotIneligible|testJoinDuringAWalletReadFailureIsTemporarilyUnavailable|testTheSweepNeverRemovesAMemberOnAWalletReadFailure" \
  "testAFailedWalletQueryRemovesNobodyAndTheNextHealthyTickDoes"

control 2b "Repository failure changed to empty success (repository no longer throws)" "$WALLETS" \
  "s = s.replace(\"            self::guardReadOrThrow('getForUserOrThrow');\", \"            self::guardRead('getForUserOrThrow');\")" \
  - \
  "testAFailedWalletQueryRemovesNobodyAndTheNextHealthyTickDoes"

control 3 "Inactive chain changed to zero" "$HOLDINGS" \
  "s = s.replace('        if (is_string(\$context)) {\n            return EligibilityVerdict::unknownBecause(\$min, null, \$context);', '        if (\$context === EligibilityVerdict::REASON_CHAIN_UNAVAILABLE) {\n            return EligibilityVerdict::ineligible(\$min, 0);\n        }\n        if (is_string(\$context)) {\n            return EligibilityVerdict::unknownBecause(\$min, null, \$context);')" \
  "testAnInactiveChainIsUnknownNotIneligible|testAMissingChainIsUnknownNotIneligible|testJoinOnAnInactiveChainIsTemporarilyUnavailable|testTheSweepNeverRemovesAMemberOfAnInactiveChainsGroup|testAStanceOnAChainThatCannotBeVerifiedIsRetryableNotADenial" \
  -

control 4 "Incomplete changed to complete (atLeast is exact)" "$COUNT" \
  "s = s.replace('return new self(max(0, \$count), false);', 'return new self(max(0, \$count), true);')" \
  "testACappedCountBelowTheThresholdIsUnknown|testACappedCountBetweenOneAndTheThresholdIsUnknown|testAnIntegerFromADriverThatNeverVouchedForCompletenessCannotProveZero|testAnAbsentIndexRowIsNotProofOfNonOwnership" \
  -

control 5 "Missing tokens changed to an empty valid list" "$COSMOS" \
  "s = s.replace(\"        if (!array_key_exists('tokens', \$data) || !is_array(\$data['tokens']) || !array_is_list(\$data['tokens'])) {\n            return null;\", \"        if (!array_key_exists('tokens', \$data) || !is_array(\$data['tokens']) || !array_is_list(\$data['tokens'])) {\n            return [];\")" \
  "testACosmosEnvelopeWithoutTokensIsNotAnEmptyWallet|testACosmosTokensFieldThatIsNotAListIsUnknown" \
  -

control 6 "Solana page cap marked complete" "$SOLANA" \
  "s = s.replace('        \$cutOff  = true;', '        \$cutOff  = false;')" \
  "testASolanaCountThatStoppedAtItsPageCeilingIsNotAConfidentZero|testASolanaWalkStoppedAtItsPageCeilingIsALowerBound" \
  -

control 7 "Cosmos top-30 cap marked complete" "$COSMOS" \
  "s = s.replace('        \$complete  = !\$collectionsOmitted;', '        \$complete  = true;')" \
  "testACosmosWalkLimitedToTheTopThirtyCollectionsIsIncomplete" \
  -

control 8 "ERC-1155 absent index row treated as zero" "$HOLDINGS" \
  "s = s.replace('                return HoldingsCount::atLeast(0);', '                return HoldingsCount::exact(0);')" \
  "testAnAbsentIndexRowIsNotProofOfNonOwnership|testARelinkedWalletDoesNotInheritAConfidentZero" \
  -

control 9 "EVM unsupported list cached as complete empty" "$HOLDINGS" \
  "s = s.replace(\"            return ['items' => [], 'truncated' => false, 'complete' => false];\n        }\", \"            \$empty = ['items' => [], 'truncated' => false, 'complete' => true];\n            set_transient(\$cacheKey, \$empty, self::CACHE_TTL);\n            return \$empty;\n        }\")" \
  "testADriverWithoutAHoldingsListNeverCachesACompleteEmptyWalk" \
  -

control 10 "Revocation on UNKNOWN enabled" "$REVOKE" \
  "s = s.replace('                    if (\$verdict->isUnknown()) {', '                    if (\$verdict->isUnknown() && \$this->revokeMember(\$userId, \$groupId, \$config, \$verdict)) {\n                        \$stats[\\'revoked\\']++;\n                        continue;\n                    }\n                    if (\$verdict->isUnknown()) {')" \
  "testTheSweepNeverRemovesAMemberOnAWalletReadFailure|testTheSweepNeverRemovesAMemberOfAnInactiveChainsGroup|testKeptMembersBetweenRemovalsAreNeitherSkippedNorRevisited" \
  "testAFailedWalletQueryRemovesNobodyAndTheNextHealthyTickDoes|testKeptMembersBetweenRemovalsAreNeitherSkippedNorRevisited"

control 11 "Revocation guard removed (any decided verdict removes)" "$REVOKE" \
  "s = s.replace('                    if (\$verdict->isIneligible()\n                        && \$this->revokeMember(', '                    if (true\n                        && \$this->revokeMember(')" \
  "testTheSweepKeepsAProvenHolder|testKeptMembersBetweenRemovalsAreNeitherSkippedNorRevisited" \
  "testKeptMembersBetweenRemovalsAreNeitherSkippedNorRevisited"

control 12 "Complete-zero AND changed to OR" "$COUNT" \
  "s = s.replace('if (\$this->complete && \$this->count < \$min)', 'if (\$this->complete || \$this->count < \$min)')" \
  "testACappedCountBelowTheThresholdIsUnknown|testACappedCountBetweenOneAndTheThresholdIsUnknown|testAnAbsentIndexRowIsNotProofOfNonOwnership" \
  -

control 13 "Pagination offset bug restored" "$REVOKE" \
  "s = s.replace('                \$offset = \$position;', '                \$offset += count(\$members);')" \
  "testOneSweepProcessesEveryMemberExactlyOnceAcrossPages|testKeptMembersBetweenRemovalsAreNeitherSkippedNorRevisited" \
  "testEveryMemberIsEvaluatedAndRemovedExactlyOnceAcrossPagesAndTicks|testKeptMembersBetweenRemovalsAreNeitherSkippedNorRevisited"

control 14 "Existing-member preservation removed (owner short-circuit)" "$REVOKE" \
  "s = s.replace('if (\$userId <= 0 || \$this->isOwnerRole((string) \$member->role)) {', 'if (\$userId <= 0) {')" \
  "testTheSweepNeverRemovesAnOwnerEvenOnACompleteZero" \
  -

# ── Safeguard 1: bounded lifetime of positive evidence ──────────────────

control 15 "Cached-positive age check removed" "$HOLDINGS" \
  "s = s.replace('        \$age = time() - \$observedAt;\n\n        return \$age >= 0 && \$age <= self::POSITIVE_EVIDENCE_MAX_AGE;', '        \$age = time() - \$observedAt;\n\n        return \$age >= 0;')" \
  "testACachedPositiveOlderThanADayIsReVerified" \
  -

control 16 "served_from_cache guard removed" "$HOLDINGS" \
  "s = s.replace(\"if (!is_int(\$observedAt) || (\$cached['served_from_cache'] ?? null) !== false) {\", 'if (!is_int(\$observedAt)) {')" \
  "testACachedPositiveBuiltFromCachedPagesIsReVerified" \
  -

control 17 "ERC-1155 checkpoint freshness check removed" "$HOLDINGS" \
  "s = s.replace('            return self::transferIndexIsFresh(\$chainId)\n', '            return true\n')" \
  "testPositiveIndexEvidenceFromAStaleIndexerIsUnknown|testPositiveIndexEvidenceFromADisabledIndexerIsUnknown" \
  "testAFailedTransferIndexQueryIsUnknownAndAFreshPositiveCounts"

# ── Safeguard 2: bounded provider fan-out ───────────────────────────────

control 18 "Budget canSpend check removed" "$HOLDINGS" \
  "s = s.replace('        if (!\$budget->canSpend(\$cost)) {', '        if (false) {')" \
  "testTheSweepStopsAtItsBudgetAndResumesOnTheNextUnevaluatedMember|testJoinStopsAtItsBudgetAndFailsClosed|testEligibleGroupDiscoveryIsBudgeted|testOwnsAnyManyHonoursACallerSuppliedBudget|testTheListingIsBoundedByItsBudget|testTheProfileBadgeIsBoundedByItsBudget|testAStanceWriteIsBoundedByItsBudget" \
  "testATickThatRunsOutOfBudgetResumesOnTheFirstUnevaluatedMember"

control 19 "Budget exhaustion mapped to NOT_OWNED" "$HOLDINGS" \
  "s = s.replace('            return EligibilityVerdict::REASON_BUDGET_EXHAUSTED;\n        }\n        // Charged up front', '            return HoldingsCount::exact(0);\n        }\n        // Charged up front')" \
  "testJoinStopsAtItsBudgetAndFailsClosed|testAStanceWriteIsBoundedByItsBudget|testTheSweepStopsAtItsBudgetAndResumesOnTheNextUnevaluatedMember" \
  "testATickThatRunsOutOfBudgetResumesOnTheFirstUnevaluatedMember"

control 20 "Listing evaluates joined and opted-out groups again" "$LISTING" \
  "s = s.replace('            if (isset(\$memberships[\$cfg->groupId]) || \$gateService->isOptOutActive(\$userId, \$cfg->groupId)) {\n                continue;\n            }\n            \$identity', '            \$identity')" \
  "testTheListingNeverAsksAboutGroupsTheViewerAlreadyJoined|testTheListingNeverAsksAboutGroupsTheViewerOptedOutOf" \
  -

# ── Supporting guarantees ───────────────────────────────────────────────

control 21 "Cosmos evidence reads the page cache again" "$COSMOS" \
  "s = s.replace('\$this->cw721AllTokensForOwner(\$contract, \$wallet, false);', '\$this->cw721AllTokensForOwner(\$contract, \$wallet, true);')" \
  "testCosmosOwnershipEvidenceNeverReadsADayOldCachedEmptyPage" \
  -

control 22 "A cached complete zero answers the gate again" "$HOLDINGS" \
  "s = s.replace('            if (\$matches > 0 && self::cachedPositiveIsTrusted(\$cached)) {\n                return HoldingsCount::atLeast(\$matches);\n            }', '            if (\$matches > 0 && self::cachedPositiveIsTrusted(\$cached)) {\n                return HoldingsCount::atLeast(\$matches);\n            }\n            if (\$matches === 0 && empty(\$cached[\\'truncated\\']) && (\$cached[\\'complete\\'] ?? false) === true) {\n                return HoldingsCount::exact(0);\n            }')" \
  "testAnEmptyCachedListNeverStopsTheDirectBalanceOfCheck|testACompleteCachedListNoLongerAnswersAGateZeroFromCache" \
  -

control 23 "A member too expensive for a whole tick stalls the rotation" "$REVOKE" \
  "s = s.replace('if (\$verdict->isBudgetExhausted() && \$stats[\\'checked\\'] > 0) {', 'if (\$verdict->isBudgetExhausted()) {')" \
  "testAMemberTooExpensiveForAWholeTickCannotStallTheRotation" \
  -

# ── Follow-up: starvation and stored-evidence isolation ─────────────────

control 24 "Join continuation never stored" "$HOLDINGS" \
  "s = s.replace(\"                \$rotation[\$key] = ['offset' => \$resumeAt, 'at' => time()];\n                self::writeWalletRotation(\$userId, \$rotation);\", \"                \$rotation[\$key] = ['offset' => \$resumeAt, 'at' => time()];\")" \
  "testAQualifyingWalletPastTheJoinBudgetIsReachedByARetry|testRepeatedJoinAttemptsReadEveryWalletAndNeverDeny|testJoinContinuationAtCosmosCostReachesALateWallet|testAStanceRetryResumesWithTheWalletsTheBudgetDidNotReach" \
  -

control 25 "Join continuation start ignored" "$HOLDINGS" \
  "s = s.replace(\"        \$startAt  = \$rotation[\$key]['offset'] ?? 0;\", '        \$startAt  = 0;')" \
  "testAQualifyingWalletPastTheJoinBudgetIsReachedByARetry|testRepeatedJoinAttemptsReadEveryWalletAndNeverDeny|testJoinContinuationAtCosmosCostReachesALateWallet|testAStanceRetryResumesWithTheWalletsTheBudgetDidNotReach" \
  -

control 26 "Continuation measured from the first wallet instead of where the attempt started" "$HOLDINGS" \
  "s = s.replace('                \$resumeAt ??= (\$start + \$i) % \$count;', '                \$resumeAt ??= \$i % \$count;')" \
  "testRepeatedJoinAttemptsReadEveryWalletAndNeverDeny" \
  -

control 27 "Budget-stopped sweep rewinds to the start of its group" "$REVOKE" \
  "s = s.replace(\"\$this->writeCursor(['group_id' => \$groupId, 'offset' => \$position]);\", \"\$this->writeCursor(['group_id' => \$groupId, 'offset' => 0]);\")" \
  "testSuccessiveTicksReachEveryMemberBeforeRevisitingAny" \
  "testSuccessiveTicksReachEveryMemberBeforeRevisitingAny"

control 28 "Stored holdings rows accepted as ownership evidence" "$HOLDINGS" \
  "s = s.replace('        \$evidenceCapable = \$fetcher instanceof CountsHoldingsWithCompleteness;', '        foreach (NftHoldingsRepository::findVisibleForWallet(\$walletLinkId, \$chainId) as \$storedRow) {\n            if (strtolower((string) \$storedRow->contract_address) === strtolower(\$contract)) {\n                return HoldingsCount::atLeast(1);\n            }\n        }\n        \$evidenceCapable = \$fetcher instanceof CountsHoldingsWithCompleteness;')" \
  "testAStoredHoldingsRowNeverSatisfiesAJoin|testAStoredHoldingsRowNeverKeepsAMember|testAStoredHoldingsRowNeverMakesAGroupEligibleForAutoJoin|testStoredRowsAreReadOnlyByDisplayAndTestimonyCode" \
  -

control 29 "The sweep uses (and writes) join continuation" "$REVOKE" \
  "s = s.replace('                \$config->minBalance,\n                \$budget\n            );', '                \$config->minBalance,\n                \$budget,\n                true\n            );')" \
  "testTheSweepNeverWritesJoinContinuation|testTheSweepIgnoresAStoredJoinContinuation" \
  -

echo "──────────────────────────────────────────────────────────────"
for line in "${REPORT[@]}"; do echo "$line"; done
echo "──────────────────────────────────────────────────────────────"
echo "killed=${TALLY[killed]} wrong_reason=${TALLY[wrong_reason]} survived=${TALLY[survived]} broken=${TALLY[broken]} skipped=${TALLY[skipped]}"

for f in "${FILES[@]}"; do
  cmp -s "$SNAPDIR/$f" "$f" || { echo "FATAL: $f differs from its snapshot at the end of the run"; exit 2; }
done
echo "all mutated files byte-identical to their pre-run snapshot"

[ "${TALLY[survived]}" -eq 0 ] && [ "${TALLY[wrong_reason]}" -eq 0 ] && [ "${TALLY[broken]}" -eq 0 ]
