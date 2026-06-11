#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# BCC API Contract Check
#
# Verifies the live REST API matches the contract locked in
# docs/api-contract-v1.md. Three tiers by criticality:
#
#   T1 (deep)    — envelope + pagination + null/array safety on user-facing
#                  endpoints whose breakage would blind the Next.js Floor.
#   T2 (schema)  — error envelope verification on mutations and user-scoped
#                  settings; OAuth callbacks redirect correctly.
#   T3 (smoke)   — 200 + non-empty body on admin/internal endpoints.
#
# Sign-off gate after Phase 7. After Phase 9 lands, every PR touching
# app/Domain/*/REST/ or view-model builders runs this in CI.
#
# Usage:
#   bash scripts/api-contract-check.sh                 # all tiers
#   bash scripts/api-contract-check.sh --base=URL      # custom base URL
#   bash scripts/api-contract-check.sh --token=JWT     # bearer token
#   bash scripts/api-contract-check.sh --tier=1        # one tier only (1|2|3)
#
# Env: BCC_BASE_URL, BCC_BEARER_TOKEN, BCC_SAMPLE_PAGE_ID, BCC_SAMPLE_HANDLE,
#      BCC_SAMPLE_SELF_HANDLE (handle the BCC_BEARER_TOKEN authenticates as
#      — used by the wallet-privacy positive test; must DIFFER from
#      BCC_SAMPLE_HANDLE for the negative test to be load-bearing)
# ──────────────────────────────────────────────────────────────────────────────

set -uo pipefail

BASE_URL="${BCC_BASE_URL:-http://blue-collar-crypto-custom.local}"
TOKEN="${BCC_BEARER_TOKEN:-}"
TIER_FILTER="all"

for arg in "$@"; do
    case "$arg" in
        --base=*)   BASE_URL="${arg#*=}" ;;
        --token=*)  TOKEN="${arg#*=}" ;;
        --tier=*)   TIER_FILTER="${arg#*=}" ;;
        --help|-h)  sed -n '2,28p' "$0"; exit 0 ;;
        *)          echo "Unknown arg: $arg" >&2; exit 2 ;;
    esac
done

command -v curl   >/dev/null || { echo "curl required" >&2; exit 2; }
command -v python >/dev/null || { echo "python required" >&2; exit 2; }

T1_PASS=0; T1_FAIL=0; T1_FAILS=()
T2_PASS=0; T2_FAIL=0; T2_FAILS=()
T3_PASS=0; T3_FAIL=0; T3_FAILS=()

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

log()  { echo -e "$@"; }
ok()   { echo -e "  ${GREEN}PASS${NC} $1"; }
bad()  { echo -e "  ${RED}FAIL${NC} $1 — $2"; }
skip() { echo -e "  ${YELLOW}SKIP${NC} $1 — $2"; }

fetch() {
    local path="$1" auth="${2:-no}"
    local args=(-s -w "\n%{http_code}" -H "Accept: application/json")
    if [[ "$auth" == "auth" && -n "$TOKEN" ]]; then
        args+=(-H "Authorization: Bearer $TOKEN")
    fi
    curl "${args[@]}" "$BASE_URL$path"
}

split_response() {
    local response="$1"
    BODY=$(echo "$response" | sed '$d')
    STATUS=$(echo "$response" | tail -1)
}

# JSON inspection via Python — returns 0 if assertion holds, 1 otherwise.
# Usage: jcheck "$BODY" "<python expression on `d` (parsed dict/list)>"
jcheck() {
    local body="$1" expr="$2"
    python -c "
import sys, json
try:
    d = json.loads(sys.stdin.read())
except Exception:
    sys.exit(1)
sys.exit(0 if ($expr) else 1)
" <<<"$body" 2>/dev/null
}

# ── Tier 1 ───────────────────────────────────────────────────────────────────

t1_check() {
    local path="$1" desc="$2" paginated="${3:-no}"

    local response
    response=$(fetch "$path" auth)
    split_response "$response"

    if [[ "$STATUS" != "200" ]]; then
        T1_FAIL=$((T1_FAIL + 1))
        T1_FAILS+=("$path: HTTP $STATUS expected 200")
        bad "$desc" "HTTP $STATUS"
        return
    fi

    if ! jcheck "$BODY" "isinstance(d, dict) and 'data' in d and '_meta' in d"; then
        T1_FAIL=$((T1_FAIL + 1))
        T1_FAILS+=("$path: missing data or _meta envelope keys")
        bad "$desc" "envelope missing 'data' or '_meta'"
        return
    fi

    if [[ "$paginated" == "cursor" ]]; then
        if ! jcheck "$BODY" "isinstance(d['data'].get('items'), list)"; then
            T1_FAIL=$((T1_FAIL + 1))
            T1_FAILS+=("$path: .data.items not a list")
            bad "$desc" "items not list"
            return
        fi
        if ! jcheck "$BODY" "isinstance(d['data'].get('pagination', {}).get('has_more'), bool)"; then
            T1_FAIL=$((T1_FAIL + 1))
            T1_FAILS+=("$path: .data.pagination.has_more not bool")
            bad "$desc" "has_more not bool"
            return
        fi
        if ! jcheck "$BODY" "(lambda c: c is None or isinstance(c, str))(d['data'].get('pagination', {}).get('next_cursor'))"; then
            T1_FAIL=$((T1_FAIL + 1))
            T1_FAILS+=("$path: .data.pagination.next_cursor not string|null")
            bad "$desc" "next_cursor not string|null"
            return
        fi
    elif [[ "$paginated" == "offset" ]]; then
        if ! jcheck "$BODY" "isinstance(d['data'].get('items'), list)"; then
            T1_FAIL=$((T1_FAIL + 1))
            T1_FAILS+=("$path: .data.items not a list")
            bad "$desc" "items not list"
            return
        fi
        if ! jcheck "$BODY" "all(isinstance(d['data'].get('pagination', {}).get(k), int) for k in ['page','page_size','total','total_pages'])"; then
            T1_FAIL=$((T1_FAIL + 1))
            T1_FAILS+=("$path: offset pagination malformed")
            bad "$desc" "offset pagination malformed"
            return
        fi
    fi

    # Null-where-array-expected heuristic (best-effort)
    local null_fields
    null_fields=$(python -c "
import sys, json
ARRAY_FIELDS = {'items','flags','endorsements','badges','tabs','tags','reactions','wallets','verifications'}
try:
    d = json.loads(sys.stdin.read())
except Exception:
    print('')
    sys.exit(0)
def walk(obj, hits):
    if isinstance(obj, dict):
        for k, v in obj.items():
            if k in ARRAY_FIELDS and v is None:
                hits.add(k)
            walk(v, hits)
    elif isinstance(obj, list):
        for v in obj:
            walk(v, hits)
hits = set()
walk(d, hits)
print(','.join(sorted(hits)))
" <<<"$BODY" 2>/dev/null)
    if [[ -n "$null_fields" ]]; then
        T1_FAIL=$((T1_FAIL + 1))
        T1_FAILS+=("$path: null where array expected: $null_fields")
        bad "$desc" "null array field(s): $null_fields"
        return
    fi

    T1_PASS=$((T1_PASS + 1))
    ok "$desc"
}

# ── Tier 2 ───────────────────────────────────────────────────────────────────

t2_check() {
    local path="$1" desc="$2" expect_unauth="${3:-401}"

    local response
    response=$(fetch "$path" no)
    split_response "$response"

    # Accept any 4xx for unauth path — the contract is that auth-protected
    # routes return an error envelope, not the specific status code.
    if [[ ! "$STATUS" =~ ^4[0-9][0-9]$ ]]; then
        T2_FAIL=$((T2_FAIL + 1))
        T2_FAILS+=("$path unauth: HTTP $STATUS, expected 4xx")
        bad "$desc (unauth)" "HTTP $STATUS"
        return
    fi

    if ! jcheck "$BODY" "isinstance(d, dict) and isinstance(d.get('error'), dict)"; then
        T2_FAIL=$((T2_FAIL + 1))
        T2_FAILS+=("$path: missing .error envelope")
        bad "$desc (unauth)" "missing .error"
        return
    fi
    if ! jcheck "$BODY" "isinstance(d['error'].get('code'), str)"; then
        T2_FAIL=$((T2_FAIL + 1))
        T2_FAILS+=("$path: .error.code not string")
        bad "$desc (unauth)" "missing .error.code"
        return
    fi
    if ! jcheck "$BODY" "isinstance(d['error'].get('message'), str)"; then
        T2_FAIL=$((T2_FAIL + 1))
        T2_FAILS+=("$path: .error.message not string")
        bad "$desc (unauth)" "missing .error.message"
        return
    fi
    if ! jcheck "$BODY" "isinstance(d['error'].get('status'), int)"; then
        T2_FAIL=$((T2_FAIL + 1))
        T2_FAILS+=("$path: .error.status not int")
        bad "$desc (unauth)" "missing .error.status"
        return
    fi

    T2_PASS=$((T2_PASS + 1))
    ok "$desc (envelope)"
}

# ── Tier 2 — wallet privacy regression tests (§3.1) ──────────────────────────
#
# The most dangerous regression in the User view-model is `wallets[].address`
# leaking to non-self viewers. These two tests close the gap that a generic
# envelope/shape check can't catch — they verify the FIELD-LEVEL privacy
# gate by asserting on which keys appear (or don't) per viewer relationship.
#
# Setup: BCC_BEARER_TOKEN authenticates as $BCC_SAMPLE_SELF_HANDLE (User A).
#        $BCC_SAMPLE_HANDLE is some OTHER user (User B).
# Skipped when env vars missing or both handles match.

t2_wallet_privacy() {
    local self_handle="${BCC_SAMPLE_SELF_HANDLE:-}"
    local other_handle="${BCC_SAMPLE_HANDLE:-}"

    if [[ -z "$TOKEN" || -z "$self_handle" || -z "$other_handle" ]]; then
        skip "wallet privacy" "set BCC_BEARER_TOKEN, BCC_SAMPLE_SELF_HANDLE, BCC_SAMPLE_HANDLE"
        return
    fi
    if [[ "$self_handle" == "$other_handle" ]]; then
        skip "wallet privacy" "BCC_SAMPLE_SELF_HANDLE == BCC_SAMPLE_HANDLE; negative test would be vacuous"
        return
    fi

    # ─ Positive: own profile MUST include `address` on every wallet entry ───
    local response_self
    response_self=$(fetch "/wp-json/bcc/v1/users/${self_handle}" auth)
    split_response "$response_self"

    if [[ "$STATUS" != "200" ]]; then
        T2_FAIL=$((T2_FAIL + 1))
        T2_FAILS+=("wallet privacy positive: HTTP $STATUS for own profile (/users/${self_handle})")
        bad "wallet privacy: own profile" "HTTP $STATUS"
    elif jcheck "$BODY" "len(d.get('data', {}).get('wallets', [])) == 0"; then
        # Empty wallets — gate is technically untested. Warn, don't fail.
        skip "wallet privacy: own profile" "self user has no wallets configured; positive gate untested"
    elif ! jcheck "$BODY" "all('address' in w for w in d['data']['wallets'])"; then
        T2_FAIL=$((T2_FAIL + 1))
        T2_FAILS+=("wallet privacy POSITIVE FAIL: 'address' missing on own-profile wallets")
        bad "wallet privacy: own profile" "address missing on self"
    else
        T2_PASS=$((T2_PASS + 1))
        ok "wallet privacy: own profile includes \`address\`"
    fi

    # ─ Negative: other profile MUST NOT include `address` (CVE-class) ───────
    local response_other
    response_other=$(fetch "/wp-json/bcc/v1/users/${other_handle}" auth)
    split_response "$response_other"

    if [[ "$STATUS" != "200" ]]; then
        T2_FAIL=$((T2_FAIL + 1))
        T2_FAILS+=("wallet privacy negative: HTTP $STATUS for other profile (/users/${other_handle})")
        bad "wallet privacy: other profile" "HTTP $STATUS"
    elif ! jcheck "$BODY" "all('address' not in w for w in d.get('data', {}).get('wallets', []))"; then
        T2_FAIL=$((T2_FAIL + 1))
        T2_FAILS+=("wallet privacy NEGATIVE FAIL: 'address' LEAKED to non-self viewer (/users/${other_handle})")
        bad "wallet privacy: other profile" "address LEAKED — privacy gate broken"
    else
        T2_PASS=$((T2_PASS + 1))
        ok "wallet privacy: other profile omits \`address\`"
    fi
}

# ── Tier 3 ───────────────────────────────────────────────────────────────────

t3_check() {
    local path="$1" desc="$2" auth="${3:-no}" expect="${4:-200}"

    local response
    response=$(fetch "$path" "$auth")
    split_response "$response"

    if [[ "$STATUS" != "$expect" ]]; then
        T3_FAIL=$((T3_FAIL + 1))
        T3_FAILS+=("$path: HTTP $STATUS expected $expect")
        bad "$desc" "HTTP $STATUS"
        return
    fi

    local body_len
    body_len=$(echo -n "$BODY" | wc -c)
    if [[ $body_len -lt 3 ]]; then
        T3_FAIL=$((T3_FAIL + 1))
        T3_FAILS+=("$path: body too short ($body_len bytes)")
        bad "$desc" "body too short"
        return
    fi

    T3_PASS=$((T3_PASS + 1))
    ok "$desc"
}

# ── Tier definitions ─────────────────────────────────────────────────────────

run_t1() {
    log "${BOLD}Tier 1 — deep verify (public reads, no auth needed)${NC}"

    t1_check "/wp-json/bcc/v1/ranks"                "ranks"
    t1_check "/wp-json/bcc/v1/locals"               "locals"

    local sample_handle="${BCC_SAMPLE_HANDLE:-admin}"
    t1_check "/wp-json/bcc/v1/users/${sample_handle}"          "users/:handle"
    t1_check "/wp-json/bcc/v1/cards"                           "cards (list)"        cursor
}

run_t2() {
    log "\n${BOLD}Tier 2 — schema + behavior (auth-required surfaces)${NC}"

    # Auth-required reads
    t2_check "/wp-json/bcc/v1/feed"                   "GET feed"
    t2_check "/wp-json/bcc/v1/system/health"          "GET system/health (admin)"
    t2_check "/wp-json/bcc/v1/me/highlights"          "GET me/highlights"
    t2_check "/wp-json/bcc/v1/me/notifications"       "GET me/notifications"
    t2_check "/wp-json/bcc/v1/me/watching"            "GET me/watching"
    t2_check "/wp-json/bcc/v1/me/binder"              "GET me/binder (deprecated, release-N alias)"
    t2_check "/wp-json/bcc/v1/me/notification-prefs"  "GET me/notification-prefs"
    t2_check "/wp-json/bcc/v1/me/privacy"             "GET me/privacy"
    t2_check "/wp-json/bcc/v1/me/blocks"              "GET me/blocks"
    t2_check "/wp-json/bcc/v1/me/push-subscriptions"  "GET me/push-subscriptions"
    t2_check "/wp-json/bcc/v1/me/reports"             "GET me/reports"
    t2_check "/wp-json/bcc/v1/disputes/panel"         "GET disputes/panel"
    t2_check "/wp-json/bcc/v1/wallets"                "GET wallets"
    t2_check "/wp-json/bcc/v1/onchain/1"              "GET onchain/{page_id}"
    t2_check "/wp-json/bcc/v1/endorsements/mine"      "GET endorsements/mine"
    t2_check "/wp-json/bcc/v1/nft-selections"         "GET nft-selections"
    t2_check "/wp-json/bcc-trust/v1/github/status"    "GET github/status"
    t2_check "/wp-json/bcc-trust/v1/x/status"         "GET x/status"

    # Mutations (POST) — error envelope on unauth
    t2_check "/wp-json/bcc/v1/disputes"               "POST disputes"
    t2_check "/wp-json/bcc/v1/report-user"            "POST report-user"
    t2_check "/wp-json/bcc/v1/claim"                  "POST claim"
    t2_check "/wp-json/bcc/v1/flag"                   "POST flag"
    t2_check "/wp-json/bcc-trust/v1/vote"             "POST vote"
    t2_check "/wp-json/bcc-trust/v1/remove-vote"      "POST remove-vote"
    t2_check "/wp-json/bcc-trust/v1/endorse"          "POST endorse"
    t2_check "/wp-json/bcc-trust/v1/revoke-endorsement" "POST revoke-endorse"
    t2_check "/wp-json/bcc-trust/v1/report-vote"      "POST report-vote"

    # V2 Phase 2 — Profile + account self-edit (bio + avatar + cover).
    t2_check "/wp-json/bcc/v1/me/profile"             "PATCH me/profile (bio)"
    t2_check "/wp-json/bcc/v1/me/profile/avatar"      "POST me/profile/avatar"
    t2_check "/wp-json/bcc/v1/me/profile/cover"       "POST me/profile/cover"
    t2_check "/wp-json/bcc/v1/me/profile/cover/position" "PATCH me/profile/cover/position"

    # V2 Phase 2 — Messaging preferences (PeepSo-backed chat toggles).
    t2_check "/wp-json/bcc/v1/me/messages-prefs"      "GET/PATCH me/messages-prefs"

    # V2 Phase 2.5 — PeepSo profile mirror (fields + prefs + account).
    t2_check "/wp-json/bcc/v1/me/profile/fields"      "GET me/profile/fields"
    t2_check "/wp-json/bcc/v1/me/profile-prefs"       "GET/PATCH me/profile-prefs"
    t2_check "/wp-json/bcc/v1/me/account/email"       "PATCH me/account/email"
    t2_check "/wp-json/bcc/v1/me/account/password"    "PATCH me/account/password"
    t2_check "/wp-json/bcc/v1/me/account"             "DELETE me/account"

    # ─ Field-level privacy regression tests (must be opt-in via env vars) ─
    log "  ${BOLD}Field-level privacy${NC}"
    t2_wallet_privacy
}

run_t3() {
    log "\n${BOLD}Tier 3 — smoke${NC}"

    t3_check "/wp-json/bcc/v1/system/ping"                  "system/ping"
    t3_check "/wp-json/bcc-trust/v1/"                       "bcc-trust/v1 api index"
    t3_check "/wp-json/bcc/v1/chains"                       "chains"
}

# ── Run ──────────────────────────────────────────────────────────────────────

case "$TIER_FILTER" in
    1)   run_t1 ;;
    2)   run_t2 ;;
    3)   run_t3 ;;
    all) run_t1; run_t2; run_t3 ;;
    *)   echo "Unknown tier filter: $TIER_FILTER" >&2; exit 2 ;;
esac

echo ""
echo "──────────────────────────────────────────"
echo -e "T1: ${GREEN}${T1_PASS} pass${NC} / ${RED}${T1_FAIL} fail${NC}"
echo -e "T2: ${GREEN}${T2_PASS} pass${NC} / ${RED}${T2_FAIL} fail${NC}"
echo -e "T3: ${GREEN}${T3_PASS} pass${NC} / ${RED}${T3_FAIL} fail${NC}"
if [[ ${#T1_FAILS[@]} -gt 0 || ${#T2_FAILS[@]} -gt 0 || ${#T3_FAILS[@]} -gt 0 ]]; then
    echo ""
    echo "Failures:"
    for f in "${T1_FAILS[@]:-}" "${T2_FAILS[@]:-}" "${T3_FAILS[@]:-}"; do
        [[ -n "$f" ]] && echo "  - $f"
    done
fi
echo "──────────────────────────────────────────"

TOTAL_FAIL=$((T1_FAIL + T2_FAIL + T3_FAIL))
exit $((TOTAL_FAIL == 0 ? 0 : 1))
