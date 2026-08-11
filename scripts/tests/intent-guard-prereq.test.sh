#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# Regression harness for scripts/intent-guard.sh prerequisite detection.
#
# WHY THIS EXISTS
# ---------------
# intent-guard.sh defines a wrapper function named `jq` (to strip CRLF from
# Windows jq builds). Its require_tools() used to probe for tools with
# `command -v`, which resolves shell FUNCTIONS and ALIASES as well as PATH
# executables — so on a host with no jq binary at all, `command -v jq` matched
# the script's own wrapper and reported success. require_tools passed, then
# every `jq -e .` validation site failed, reporting "returned non-JSON" for
# payloads that were valid JSON. The guard was confidently wrong.
#
# The fix is `type -P` (PATH executables only) plus an optional-jq policy with
# a php JSON-validation fallback and DEFERRED verdicts. This harness pins all
# of it. It needs NO WordPress: a `wp` shim on PATH replays captured payloads.
#
# Cases covered:
#   1  real jq installed              -> detection succeeds, nothing deferred
#   2  jq absent                      -> exactly ONE prerequisite notice,
#                                        jq-dependent probes DEFERRED, zero
#                                        misleading "non-JSON" failures
#   3  shell function `jq` + no binary -> detection NOT satisfied (the defect)
#   4  valid JSON                     -> json_valid exit 0
#   5  malformed JSON                 -> json_valid exit non-zero
#   6  legitimately-false predicates  -> still false/failure, never masked into
#                                        a pass or a deferral (incl. `jq -e .`
#                                        on literal null / false)
#
# Usage:  bash scripts/tests/intent-guard-prereq.test.sh
# Exit:   0 = all cases green, 1 = at least one regression
# ──────────────────────────────────────────────────────────────────────────────

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="$(cd "$SCRIPT_DIR/.." && pwd)/intent-guard.sh"

if [[ ! -f "$GUARD" ]]; then
    echo "FATAL: cannot find intent-guard.sh at $GUARD" >&2
    exit 1
fi

if [[ -t 1 ]]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'
else
    RED=''; GREEN=''; CYAN=''; BOLD=''; NC=''
fi

TESTS_RUN=0
TESTS_FAILED=0

ok()   { TESTS_RUN=$((TESTS_RUN+1)); printf "    ${GREEN}ok${NC}   %s\n" "$1"; }
notok() {
    TESTS_RUN=$((TESTS_RUN+1)); TESTS_FAILED=$((TESTS_FAILED+1))
    printf "    ${RED}FAIL${NC} %s\n" "$1"
    [[ $# -gt 1 ]] && printf "         ${RED}└─${NC} %s\n" "$2"
}
case_banner() { printf "\n${CYAN}── Case %s ──${NC} %s\n" "$1" "$2"; }

# assert_eq <label> <expected> <actual>
assert_eq() {
    if [[ "$2" == "$3" ]]; then ok "$1"; else notok "$1" "expected [$2] got [$3]"; fi
}
# assert_contains <label> <haystack> <needle>
assert_contains() {
    if [[ "$2" == *"$3"* ]]; then ok "$1"; else notok "$1" "output did not contain: $3"; fi
}
# assert_not_contains <label> <haystack> <needle>
assert_not_contains() {
    if [[ "$2" != *"$3"* ]]; then ok "$1"; else notok "$1" "output unexpectedly contained: $3"; fi
}

# ── Fixtures ─────────────────────────────────────────────────────────────────
# The throttle-health, ServiceLocator and cron-snapshot payloads below are the
# REAL responses captured from installed staging at the time the guard was
# misreporting them as "non-JSON". They are fixtures for this harness only —
# intent-guard.sh does not hard-code any payload.
FX_THROTTLE_HEALTH='{"rate_limiter_ready":true,"backend":"trust_engine","degraded":false,"last_success_ts":1786446709}'
FX_LOCATOR_HEALTHY='{"TrustReadService":"real","ScoreReadService":"real","DisputeAdjudicator":"real","PageOwnerResolver":"real"}'
FX_CRON_SNAPSHOT='{"cron_disabled":true,"last_run":1786363294,"last_success":1786363294,"last_failure":null,"now":1786446945}'
# Synthesised (no staging capture available for this route). Deliberately healthy.
FX_READ_MODEL='{"status":"healthy","drift":{"divergent_pages":0,"samples":[]},"coverage":{"coverage_pct":100,"total_pages":42,"rm_rows":42,"gap_count":0},"freshness":{"lag_seconds":3,"dirty_queue_size":0}}'
FX_LOCATOR_UNHEALTHY='{"TrustReadService":"null","ScoreReadService":"real","DisputeAdjudicator":"real","PageOwnerResolver":"real"}'
FX_LOCATOR_MALFORMED='PHP Fatal error: something exploded'

# ── Sandbox: a PATH we fully control ─────────────────────────────────────────
SANDBOX="$(mktemp -d 2>/dev/null || mktemp -d -t bccguard)"
cleanup() { rm -rf "$SANDBOX"; }
trap cleanup EXIT

REAL_CURL="$(type -P curl || true)"
REAL_AWK="$(type -P awk || true)"
REAL_PHP="$(type -P php || true)"
REAL_JQ="$(type -P jq || true)"

for req in "$REAL_AWK" "$REAL_PHP"; do
    if [[ -z "$req" ]]; then
        echo "FATAL: this harness needs awk and php on PATH to run at all." >&2
        exit 1
    fi
done

mkdir -p "$SANDBOX/bin"

# Absolute-path shims so the required tools stay reachable even when we strip
# their directory out of PATH. /bin/sh is an absolute shebang, so these work
# no matter how hostile PATH becomes.
make_shim() {
    local name="$1" target="$2"
    { printf '#!/bin/sh\n'; printf 'exec "%s" "$@"\n' "$target"; } > "$SANDBOX/bin/$name"
    chmod +x "$SANDBOX/bin/$name"
}
[[ -n "$REAL_CURL" ]] && make_shim curl "$REAL_CURL"
make_shim awk "$REAL_AWK"
make_shim php "$REAL_PHP"
# curl is hard-required by the guard; stub it if the host has none.
if [[ -z "$REAL_CURL" ]]; then
    printf '#!/bin/sh\nexit 0\n' > "$SANDBOX/bin/curl"; chmod +x "$SANDBOX/bin/curl"
fi

# The `wp` shim. Replays fixtures based on markers in the eval'd PHP so the
# guard can complete a full run with no WordPress installed.
cat > "$SANDBOX/bin/wp" <<'SHIM'
#!/bin/sh
ARGS="$*"
case "$ARGS" in
    *"cron event list"*)  printf '%s' "${FIXTURE_CRON_EVENTS:-[]}"; exit 0 ;;
esac
case "$ARGS" in
    *health/read-model*)                     printf '%s' "$FIXTURE_READ_MODEL" ;;
    *pageReadModelRepository*)               printf '%s' "${FIXTURE_ROWS:-[]}" ;;
    *computeExpectedTotal*)                  printf '%s' "${FIXTURE_RESULTS:-[]}" ;;
    *"Throttle::health"*)                    printf '%s' "$FIXTURE_THROTTLE_HEALTH" ;;
    *"Throttle::allow"*)                     printf '%s' "${FIXTURE_THROTTLE_PROBE:-1,1,1,0}" ;;
    *hasRealService*)                        printf '%s' "$FIXTURE_LOCATOR" ;;
    *bcc_disputes_auto_resolve_last_run*)    printf '%s' "$FIXTURE_CRON_SNAPSHOT" ;;
    *DISABLE_WP_CRON*)                       printf 'yes' ;;
    *)                                       printf '{}' ;;
esac
exit 0
SHIM
chmod +x "$SANDBOX/bin/wp"

# PATH with every jq-containing directory removed. Prepending $SANDBOX/bin
# keeps wp/curl/awk/php reachable even if their directory was the one holding
# jq, so this is safe on hosts where jq lives in /usr/bin.
path_without_jq() {
    local out="" d
    local OLD_IFS="$IFS"; IFS=:
    for d in $PATH; do
        [[ -z "$d" ]] && continue
        [[ -x "$d/jq" || -x "$d/jq.exe" ]] && continue
        out="${out:+$out:}$d"
    done
    IFS="$OLD_IFS"
    printf '%s' "$out"
}

PATH_WITH_JQ="$SANDBOX/bin:$PATH"
PATH_NO_JQ="$SANDBOX/bin:$(path_without_jq)"

export FIXTURE_READ_MODEL="$FX_READ_MODEL"
export FIXTURE_THROTTLE_HEALTH="$FX_THROTTLE_HEALTH"
export FIXTURE_LOCATOR="$FX_LOCATOR_HEALTHY"
export FIXTURE_CRON_SNAPSHOT="$FX_CRON_SNAPSHOT"

# run_guard <path> -> sets GUARD_OUT / GUARD_RC
GUARD_OUT=""; GUARD_RC=0
run_guard() {
    local use_path="$1"; shift
    GUARD_OUT="$(PATH="$use_path" bash "$GUARD" "$@" 2>&1)"
    GUARD_RC=$?
}

# run_guard_with_jq_function — same, but a shell FUNCTION named jq is exported
# into the guard's environment while no jq binary exists. This is the exact
# shape of the original defect.
run_guard_with_jq_function() {
    GUARD_OUT="$(
        jq() { command jq "$@" | tr -d '\r'; return "${PIPESTATUS[0]}"; }
        export -f jq
        PATH="$PATH_NO_JQ" bash "$GUARD" 2>&1
    )"
    GUARD_RC=$?
}

count_lines() { printf '%s\n' "$1" | grep -c -- "$2" 2>/dev/null || true; }

# deferred_count <guard output>
deferred_count() {
    printf '%s\n' "$1" | sed -n 's/.*DEFERRED: \([0-9][0-9]*\).*/\1/p' | tail -1
}

printf "${BOLD}intent-guard prerequisite regression harness${NC}\n"
printf "guard : %s\n" "$GUARD"
printf "jq    : %s\n" "${REAL_JQ:-<none on host>}"
printf "php   : %s\n" "$REAL_PHP"

# ═════════════════════════════════════════════════════════════════════════════
# Case 1 — real jq installed: detection succeeds, nothing deferred
# ═════════════════════════════════════════════════════════════════════════════
case_banner 1 "real jq installed -> detection succeeds, no deferral"
if [[ -z "$REAL_JQ" ]]; then
    printf "    ${CYAN}skip${NC} no jq binary on this host; cannot exercise the jq-present path\n"
else
    run_guard "$PATH_WITH_JQ"
    assert_eq        "exit code is 0 (clean run)"              "0"  "$GUARD_RC"
    assert_not_contains "no prerequisite notice when jq exists" "$GUARD_OUT" "PREREQUISITE"
    assert_contains  "summary reports DEFERRED: 0"             "$GUARD_OUT" "DEFERRED: 0"
    assert_contains  "summary reports FAIL: 0"                 "$GUARD_OUT" "FAIL: 0"
    assert_contains  "jq path is echoed in the header"         "$GUARD_OUT" "jq          : $REAL_JQ"
    assert_contains  "read-model probe actually evaluated"     "$GUARD_OUT" "no read-model drift"
    assert_contains  "ServiceLocator probe actually evaluated" "$GUARD_OUT" "TrustReadService → real implementation"
fi

# ═════════════════════════════════════════════════════════════════════════════
# Case 2 — jq absent: ONE notice, probes deferred, no bogus "non-JSON" failures
# ═════════════════════════════════════════════════════════════════════════════
case_banner 2 "jq absent -> one notice, DEFERRED probes, zero bogus failures"
run_guard "$PATH_NO_JQ"
NOTICES="$(count_lines "$GUARD_OUT" "PREREQUISITE")"
assert_eq "exactly ONE prerequisite notice"            "1" "$NOTICES"
assert_eq "exit code is 3 (deferred, not success)"     "3" "$GUARD_RC"
DEF="$(deferred_count "$GUARD_OUT")"
if [[ -n "$DEF" && "$DEF" -ge 5 ]]; then
    ok "jq-dependent probes deferred (DEFERRED=$DEF)"
else
    notok "jq-dependent probes deferred" "DEFERRED was [$DEF], expected >= 5"
fi
assert_contains     "summary still reports FAIL: 0"                 "$GUARD_OUT" "FAIL: 0"
assert_not_contains "no bogus 'not valid JSON' failure"             "$GUARD_OUT" "not valid JSON"
assert_not_contains "no bogus 'returned non-JSON' failure"          "$GUARD_OUT" "returned non-JSON"
assert_not_contains "no bogus 'returned non-array' failure"         "$GUARD_OUT" "returned non-array"
assert_contains     "notice explains the php fallback"              "$GUARD_OUT" "falls back to php"
assert_contains     "read-model invariants deferred, not failed"    "$GUARD_OUT" "DEFER read-model drift / coverage / sync-lag invariants"
assert_contains     "ServiceLocator wiring deferred, not failed"    "$GUARD_OUT" "DEFER ServiceLocator real-vs-NullObject wiring"
assert_contains     "overdue-cron probe deferred, not failed"       "$GUARD_OUT" "DEFER overdue cron events"
# Probes that need no jq must STILL run — a deferral must not swallow them.
assert_contains     "non-jq limiter probe still evaluated"          "$GUARD_OUT" "limiter blocks 4th request"

# ═════════════════════════════════════════════════════════════════════════════
# Case 3 — THE DEFECT: shell function named jq while the binary is absent
# ═════════════════════════════════════════════════════════════════════════════
case_banner 3 "shell function 'jq' + no binary -> detection must NOT be satisfied"

# 3a. The primitive itself, proven in-process.
(
    PATH="$PATH_NO_JQ"
    jq() { command jq "$@"; }
    CMDV="$(command -v jq 2>/dev/null || true)"
    TYPEP="$(type -P jq 2>/dev/null || true)"
    [[ -n "$CMDV" ]] || { echo "PRIMITIVE_UNEXPECTED_CMDV_EMPTY"; exit 9; }
    [[ -z "$TYPEP" ]] || { echo "PRIMITIVE_UNEXPECTED_TYPEP:$TYPEP"; exit 9; }
    exit 0
) >/dev/null 2>&1
if [[ $? -eq 0 ]]; then
    ok "command -v jq matches the function; type -P jq does not (defect reproduced)"
else
    notok "command -v jq matches the function; type -P jq does not" \
          "could not reproduce the primitive difference on this host"
fi

# 3b. Black-box: the guard must not believe jq is usable.
run_guard_with_jq_function
NOTICES3="$(count_lines "$GUARD_OUT" "PREREQUISITE")"
assert_eq "exactly ONE prerequisite notice despite the function" "1" "$NOTICES3"
assert_eq "exit code is 3, not 1"                               "3" "$GUARD_RC"
DEF3="$(deferred_count "$GUARD_OUT")"
if [[ -n "$DEF3" && "$DEF3" -ge 5 ]]; then
    ok "probes deferred despite the shadowing function (DEFERRED=$DEF3)"
else
    notok "probes deferred despite the shadowing function" "DEFERRED was [$DEF3], expected >= 5"
fi
assert_not_contains "function shadowing produced no bogus 'not valid JSON'"  "$GUARD_OUT" "not valid JSON"
assert_not_contains "function shadowing produced no bogus 'non-JSON'"        "$GUARD_OUT" "returned non-JSON"
assert_contains     "header reports jq as absent"                            "$GUARD_OUT" "<absent — php fallback>"

# ═════════════════════════════════════════════════════════════════════════════
# Cases 4/5/6a — json_valid must reproduce `jq -e .` exit semantics exactly,
# and the jq-backed and php-backed implementations must not diverge.
# ═════════════════════════════════════════════════════════════════════════════
case_banner "4/5/6a" "json_valid exit-code contract (jq path vs php path)"

# json_valid_rc <PATH> <force-php:0|1> <payload>
json_valid_rc() {
    local use_path="$1" force_php="$2" payload="$3"
    (
        set --
        export BCC_INTENT_GUARD_LIB=1
        PATH="$use_path"
        # shellcheck source=/dev/null
        source "$GUARD" >/dev/null 2>&1
        require_tools
        (( force_php == 1 )) && HAVE_JQ=0
        json_valid "$payload"
        exit $?
    ) >/dev/null 2>&1
    printf '%s' "$?"
}

# payload<US>expected-exit. 0 = valid+truthy, 1 = valid but null/false,
# 4 = empty input, 5 = parse error. Mirrors jq -e exactly.
JSON_TABLE=(
    '{"a":1}|0|object'
    '{}|0|empty object'
    '[]|0|empty array'
    '[1,2]|0|array'
    'true|0|true'
    '0|0|zero is truthy in jq'
    '"x"|0|string'
    'null|1|literal null is FALSY (jq -e exits 1)'
    'false|1|literal false is FALSY (jq -e exits 1)'
    'garbage|5|parse error'
    '{"a":|5|truncated object'
    '|4|empty input'
)

for row in "${JSON_TABLE[@]}"; do
    payload="${row%%|*}"; rest="${row#*|}"
    expected="${rest%%|*}"; label="${rest#*|}"
    php_rc="$(json_valid_rc "$PATH_NO_JQ" 1 "$payload")"
    assert_eq "php fallback: $label" "$expected" "$php_rc"
    if [[ -n "$REAL_JQ" ]]; then
        jq_rc="$(json_valid_rc "$PATH_WITH_JQ" 0 "$payload")"
        assert_eq "jq path    : $label" "$expected" "$jq_rc"
        # Real jq is the oracle: the two implementations must agree.
        assert_eq "parity     : $label" "$jq_rc" "$php_rc"
    fi
done

# The three real staging payloads must validate cleanly under the php fallback.
i=0
for payload in "$FX_THROTTLE_HEALTH" "$FX_LOCATOR_HEALTHY" "$FX_CRON_SNAPSHOT"; do
    i=$((i+1))
    assert_eq "captured staging payload #$i is valid JSON (php path)" \
        "0" "$(json_valid_rc "$PATH_NO_JQ" 1 "$payload")"
done

# ═════════════════════════════════════════════════════════════════════════════
# Case 6b — a predicate that is legitimately false must still FAIL, and a
# genuinely malformed payload must still FAIL even with jq absent. Neither may
# be masked into a pass or a deferral.
# ═════════════════════════════════════════════════════════════════════════════
case_banner 6b "false predicates and malformed payloads are never masked"

if [[ -n "$REAL_JQ" ]]; then
    FIXTURE_LOCATOR="$FX_LOCATOR_UNHEALTHY" run_guard "$PATH_WITH_JQ"
    assert_eq       "unhealthy ServiceLocator -> exit 1"        "1" "$GUARD_RC"
    assert_contains "unhealthy predicate reported as FAIL"      "$GUARD_OUT" "TrustReadService → NullObject fallback"
    assert_contains "and NOT deferred"                          "$GUARD_OUT" "DEFERRED: 0"
else
    printf "    ${CYAN}skip${NC} no jq binary; cannot exercise the jq-present false-predicate path\n"
fi

FIXTURE_LOCATOR="$FX_LOCATOR_MALFORMED" run_guard "$PATH_NO_JQ"
assert_contains "malformed payload still FAILs with jq absent" "$GUARD_OUT" "ServiceLocator probe returned non-JSON"
assert_eq       "malformed payload -> exit 1 (failure wins over deferral)" "1" "$GUARD_RC"

# ═════════════════════════════════════════════════════════════════════════════
printf "\n──────────────────────────────────────────\n"
if (( TESTS_FAILED == 0 )); then
    printf "${GREEN}ALL GREEN${NC}  %d assertion(s)\n" "$TESTS_RUN"
    printf "──────────────────────────────────────────\n"
    exit 0
fi
printf "${RED}REGRESSION${NC}  %d of %d assertion(s) failed\n" "$TESTS_FAILED" "$TESTS_RUN"
printf "──────────────────────────────────────────\n"
exit 1
