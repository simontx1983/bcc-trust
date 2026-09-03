#!/usr/bin/env bash
#
# PR 7 mutation controls.
#
# Each control plants a specific defect and REQUIRES the suite to go red. A
# control that "passes" (suite still green with the defect planted) is a
# FAILURE of the control: the test describes the code instead of constraining
# it.
#
# The controls aim at what would be WORST to get wrong — a price surviving the
# parser, an unapproved description going public, a lookalike marketplace host
# accepted, a confirmed collection size blanked by a provider timeout, an
# authorization gate that does not gate.
#
# ── ⚠ TWO THINGS MUST BE PROVEN BEFORE ANY RESULT COUNTS ────────────────
#
#   1. PREFLIGHT, in BOTH harnesses. A runner that reports "all killed" having
#      executed nothing is the exact false green this file guards against, and
#      an unreachable database makes every kill a FALSE kill.
#
#   2. THE MUTATOR ACTUALLY APPLIED. The first version of this runner used
#      `perl -0777 -pi -e "s/\Q$needle\E/$repl/"`; the shell expanded `$key`
#      and friends inside the double quotes, so some invocations died loudly
#      and others exited 0 having changed NOTHING — and every one of those was
#      counted as a SURVIVING mutant. 22 of 23 controls "survived" that way.
#      `mutate.py` now takes literal text via argv, requires a unique match,
#      and re-reads the file to prove the bytes changed; anything else is
#      reported as BROKEN, never as a survivor.
#
# Usage:
#   BCC_TEST_DB_HOST=127.0.0.1 BCC_TEST_DB_PORT=13402 \
#   BCC_TEST_DB_USER=root BCC_TEST_DB_PASS=root BCC_TEST_DB_NAME=bcc_pr7_test \
#   PHP_BIN=C:/php/php.exe bash scripts/tests/pr7-mutation-controls.sh

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 2

PHP="${PHP_BIN:-php}"
PY="${PYTHON_BIN:-python}"
MUTATE="scripts/tests/mutate.py"

UNIT_FILTER='CollectionMetadataRulesTest|MarketplaceResearchUrlTest|DiscoveryScanAdminActionsTest'
INT_FILTER='CollectionCommunityMetadataMigrationTest|ChainDescriptionSeparationTest'

RULES='app/Domain/Onchain/ValueObjects/CollectionMetadataRules.php'
URL='app/Domain/Onchain/ValueObjects/MarketplaceResearchUrl.php'
REPO='app/Domain/Onchain/Repositories/CollectionRepository.php'
ACTIONS='app/Domain/Onchain/Admin/DiscoveryScanActions.php'
SCHEMA='includes/database/schema-collections.php'
STATE='app/Domain/Onchain/ValueObjects/ChainDescriptionState.php'

FILES=("$RULES" "$URL" "$REPO" "$ACTIONS" "$SCHEMA" "$STATE")

WORK="$(mktemp -d)"
KILLED=0
SURVIVED=0
BROKEN=0
declare -a SURVIVORS=()
declare -a BROKENS=()

backup()  { for f in "${FILES[@]}"; do cp "$f" "$f.mutbak"; done; }
restore() { for f in "${FILES[@]}"; do [ -f "$f.mutbak" ] && mv -f "$f.mutbak" "$f"; done; return 0; }
cleanup() { restore; rm -rf "$WORK"; }
trap cleanup EXIT

run_unit() { "$PHP" vendor/bin/phpunit --filter "$UNIT_FILTER" --no-coverage >/dev/null 2>&1; }
run_int()  { "$PHP" -d extension=mysqli vendor/bin/phpunit -c phpunit-integration.xml.dist \
                --filter "$INT_FILTER" --no-coverage >/dev/null 2>&1; }

# ── PREFLIGHT ───────────────────────────────────────────────────────────
echo "── preflight ──────────────────────────────────────────────────────"

if ! run_unit; then
  echo "ABORT: the unit anchor does not pass on unmutated code."
  echo "       Every 'killed' below would be meaningless."
  exit 2
fi
echo "  ok   unit harness green before mutating"

if ! run_int; then
  echo "ABORT: the integration anchor does not pass on unmutated code."
  echo "       Check the throwaway MySQL is up and BCC_TEST_DB_* are set —"
  echo "       an unreachable database makes every kill a FALSE kill."
  exit 2
fi
echo "  ok   integration harness green before mutating"

if [ ! -f "$MUTATE" ]; then
  echo "ABORT: $MUTATE is missing; every control would report BROKEN."
  exit 2
fi
echo "  ok   mutator present"
echo

# control <label> <unit|int|both> <file> <<'NEEDLE' ... then <<'REPL'
# Needle and replacement are read from here-docs on fd 3 and 4 so neither
# passes through shell interpolation.
control() {
  local label="$1" harness="$2" file="$3" needle="$4" repl="$5"

  backup

  if ! "$PY" "$MUTATE" "$file" "$needle" "$repl" 2>"$WORK/err"; then
    restore
    BROKEN=$((BROKEN + 1))
    BROKENS+=("$label — $(head -1 "$WORK/err")")
    printf '  BROKEN    %s\n' "$label"
    printf '            %s\n' "$(head -1 "$WORK/err")"
    return
  fi

  local unit_red=0 int_red=0
  if [ "$harness" = "unit" ] || [ "$harness" = "both" ]; then run_unit || unit_red=1; fi
  if [ "$harness" = "int" ]  || [ "$harness" = "both" ]; then run_int  || int_red=1;  fi
  restore

  local red=0
  case "$harness" in
    unit) red=$unit_red ;;
    int)  red=$int_red ;;
    both) { [ "$unit_red" = 1 ] || [ "$int_red" = 1 ]; } && red=1 ;;
  esac

  if [ "$red" = 1 ]; then
    KILLED=$((KILLED + 1)); printf '  KILLED    %s\n' "$label"
  else
    SURVIVED=$((SURVIVED + 1)); SURVIVORS+=("$label")
    printf '  SURVIVED  %s   <-- the tests do not constrain this\n' "$label"
  fi
}

# Write a here-doc to a file and echo its path.
frag() { local p; p="$(mktemp "$WORK/frag.XXXXXX")"; cat > "$p"; printf '%s' "$p"; }

echo "── controls ───────────────────────────────────────────────────────"

# ── ⚠ product safety: market data ───────────────────────────────────────

N=$(frag <<'EOF'
            if (isset($banned[(string) $key])) {
                continue;
            }
EOF
)
R=$(frag <<'EOF'
            if (false) {
                continue;
            }
EOF
)
control "market fields no longer stripped from a provider payload" unit "$RULES" "$N" "$R"

N=$(frag <<'EOF'
            'floor_price',
            'floorPrice',
EOF
)
R=$(frag <<'EOF'
            'not_a_real_market_field',
EOF
)
control "the prohibition list drops floor price" unit "$RULES" "$N" "$R"

# ── collection size ─────────────────────────────────────────────────────

N=$(frag <<'EOF'
            return ['value' => null, 'reason' => self::REASON_OVERFLOW];
        }

        return ['value' => (int) $trimmed, 'reason' => null];
EOF
)
R=$(frag <<'EOF'
            return ['value' => self::MAX_TOTAL_SUPPLY, 'reason' => null];
        }

        return ['value' => (int) $trimmed, 'reason' => null];
EOF
)
control "overflow is CLAMPED instead of refused" unit "$RULES" "$N" "$R"

N=$(frag <<'EOF'
        if (preg_match('/^\d+$/', $text) !== 1) {
EOF
)
R=$(frag <<'EOF'
        if (!is_numeric($text)) {
EOF
)
control "supply accepts anything numeric instead of digits only" unit "$RULES" "$N" "$R"

# ── NULL preservation ───────────────────────────────────────────────────

N=$(frag <<'EOF'
        if ($incoming === null) {
            return false;
        }
EOF
)
R=$(frag <<'EOF'
        if ($incoming === null) {
            return true;
        }
EOF
)
control "an absent incoming value overwrites a confirmed one" unit "$RULES" "$N" "$R"

N=$(frag <<'EOF'
        if (is_string($value) && trim($value) === '') {
            return null;
        }
EOF
)
R=$(frag <<'EOF'
        if (false) {
            return null;
        }
EOF
)
control "empty string is no longer treated as absence" unit "$RULES" "$N" "$R"

# ── ⚠ marketplace hostname ──────────────────────────────────────────────

N=$(frag <<'EOF'
        if (!in_array($host, $allowed, true)) {
            return self::refuse(self::HOST_NOT_ALLOWED);
        }
EOF
)
R=$(frag <<'EOF'
        $hit = false;
        foreach ($allowed as $a) {
            if (str_contains($host, $a)) {
                $hit = true;
            }
        }
        if (!$hit) {
            return self::refuse(self::HOST_NOT_ALLOWED);
        }
EOF
)
control "hostname matched by SUBSTRING instead of exact membership" unit "$URL" "$N" "$R"

N=$(frag <<'EOF'
        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::refuse(self::HAS_CREDENTIALS);
        }
EOF
)
R=$(frag <<'EOF'
        if (false) {
            return self::refuse(self::HAS_CREDENTIALS);
        }
EOF
)
control "credentials in the URL are accepted" unit "$URL" "$N" "$R"

N=$(frag <<'EOF'
                if (in_array(strtolower((string) $k), $strip, true)) {
                    continue;
                }
EOF
)
R=$(frag <<'EOF'
                if (false) {
                    continue;
                }
EOF
)
control "tracking parameters are no longer stripped" unit "$URL" "$N" "$R"

N=$(frag <<'EOF'
            self::FAMILY_EVM    => ['opensea.io'],
EOF
)
R=$(frag <<'EOF'
            self::FAMILY_EVM    => ['opensea.io', 'magiceden.io'],
EOF
)
control "a WITHHELD pairing is quietly granted (magic eden on evm)" unit "$URL" "$N" "$R"

N=$(frag <<'EOF'
            self::FAMILY_COSMOS => ['stargaze.zone'],
EOF
)
R=$(frag <<'EOF'
            self::FAMILY_COSMOS => ['stargaze.zone', 'www.stargaze.zone'],
EOF
)
control "the www. variant is admitted to the allowlist" unit "$URL" "$N" "$R"

N=$(frag <<'EOF'
        $hosts = self::approvedHostsForFamily($family);
EOF
)
R=$(frag <<'EOF'
        $hosts = self::allApprovedHosts();
EOF
)
control "validation uses the UNION instead of the per-chain policy" unit "$URL" "$N" "$R"

# ⚠ CONTROL REMOVED, WITH ITS TARGET.
#
# "a percent-encoded host is no longer refused" deleted a separate
# `str_contains($host, '%')` branch and SURVIVED — the proof it was REDUNDANT,
# because `%` is not in the `[a-z0-9.-]` charset rule that follows and was
# already refused there. The duplicate check was removed rather than the
# control softened; `testEncodedHostsAreRefused` still proves the behaviour,
# now through the single charset authority.



# ── ⚠ description approval boundary ─────────────────────────────────────

N=$(frag <<'EOF'
              WHERE id = %d AND chain_description_state = %s",
            $collectionId,
            ChainDescriptionState::APPROVED
EOF
)
R=$(frag <<'EOF'
              WHERE id = %d AND %s IS NOT NULL",
            $collectionId,
            ChainDescriptionState::APPROVED
EOF
)
control "an unapproved description is publicly readable" int "$REPO" "$N" "$R"

N=$(frag <<'EOF'
            ChainDescriptionState::PENDING,
            $sourceLabel,
EOF
)
R=$(frag <<'EOF'
            ChainDescriptionState::APPROVED,
            $sourceLabel,
EOF
)
control "changed text keeps a stale approval" int "$REPO" "$N" "$R"

N=$(frag <<'EOF'
        return in_array($to, self::allowedTransitionsFrom($from), true);
EOF
)
R=$(frag <<'EOF'
        return true;
EOF
)
control "an illegal state transition is allowed" int "$STATE" "$N" "$R"

N=$(frag <<'EOF'
        return $state === self::APPROVED;
EOF
)
R=$(frag <<'EOF'
        return $state !== self::PENDING;
EOF
)
control "publicly-visible becomes a NEGATED check" int "$STATE" "$N" "$R"

# ── ⚠ administrator authorization ───────────────────────────────────────

N=$(frag <<'EOF'
        AdminActionSupport::requirePost();
EOF
)
R=$(frag <<'EOF'
        /* mutant: method gate removed */
EOF
)
control "the POST-only gate is removed" unit "$ACTIONS" "$N" "$R"

N=$(frag <<'EOF'
        AdminActionSupport::requireCapability();
EOF
)
R=$(frag <<'EOF'
        /* mutant: capability gate removed */
EOF
)
control "the capability gate is removed" unit "$ACTIONS" "$N" "$R"

N=$(frag <<'EOF'
        return $action . '_' . $chainId;
EOF
)
R=$(frag <<'EOF'
        return $action;
EOF
)
control "the nonce is not chain-scoped" unit "$ACTIONS" "$N" "$R"

# ⚠ CONTROL REMOVED, WITH ITS TARGET.
#
# "user id 0 is accepted" deleted the handler's own `if ($operatorId <= 0)`
# branch and SURVIVED — which was the proof that the branch was REDUNDANT, not
# the proof it was safe to keep. `DiscoveryRunService::resolveOperator()`
# already refuses id 0, a nonexistent user and a non-administrator, and a
# second copy of that rule is a second place it can drift. The duplicate was
# removed rather than the control being softened; `testUserIdZeroIsRefused`
# still proves the behaviour, now through the single authority.


# ── migrations ──────────────────────────────────────────────────────────

N=$(frag <<'EOF'
    $result = $wpdb->query("UPDATE {$table} SET image_url = NULL WHERE image_url = ''");
EOF
)
R=$(frag <<'EOF'
    $result = $wpdb->query("UPDATE {$table} SET image_url = NULL WHERE image_url IS NULL");
EOF
)
control "empty-image normalization matches NULL instead of ''" int "$SCHEMA" "$N" "$R"

N=$(frag <<'EOF'
        $changed = $wpdb->query("UPDATE {$table} SET {$column} = NULL WHERE {$column} IS NOT NULL");
EOF
)
R=$(frag <<'EOF'
        $changed = $wpdb->query("UPDATE {$table} SET {$column} = NULL WHERE 1 = 0");
EOF
)
control "market retirement is scoped to nothing" int "$SCHEMA" "$N" "$R"

N=$(frag <<'EOF'
        if ($exists === null || $exists < 1) {
            return false;
        }
EOF
)
R=$(frag <<'EOF'
        if (false) {
            return false;
        }
EOF
)
control "the completion verifier always reports complete" int "$SCHEMA" "$N" "$R"

echo
echo "── result ─────────────────────────────────────────────────────────"
printf '  killed:   %d\n' "$KILLED"
printf '  survived: %d\n' "$SURVIVED"
printf '  broken:   %d\n' "$BROKEN"

if [ "$BROKEN" -gt 0 ]; then
  echo
  echo "  BROKEN controls (not results — the mutator never applied):"
  for b in "${BROKENS[@]}"; do printf '    - %s\n' "$b"; done
fi

if [ "$SURVIVED" -gt 0 ]; then
  echo
  echo "  survivors:"
  for s in "${SURVIVORS[@]}"; do printf '    - %s\n' "$s"; done
fi

if [ "$SURVIVED" -gt 0 ] || [ "$BROKEN" -gt 0 ]; then
  exit 1
fi

echo
echo "  All controls killed."
exit 0
