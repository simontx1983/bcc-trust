#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# Regression harness for scripts/intent-guard.sh JSON prerequisites.
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
# The fix is `type -P` (PATH executables only) plus an optional-jq policy in
# which every jq expression the guard uses has a named php equivalent. The php
# side is a PARITY implementation, not a deferral: same stdout bytes, same exit
# status, same PASS/FAIL verdicts, DEFERRED: 0. This harness pins all of it. It
# needs NO WordPress: a `wp` shim on PATH replays captured payloads.
#
# Cases covered:
#   1   real jq installed              -> detection succeeds, clean run
#   2   jq absent                      -> ONE prerequisite notice, a COMPLETE
#                                         run, DEFERRED: 0, zero misleading
#                                         "non-JSON" failures
#   3   shell function `jq` + no binary -> detection NOT satisfied (the defect),
#                                         and the run is still complete
#   4/5/6a json_valid exit contract     -> jq -e semantics, both legs
#   6b  false predicates / malformed    -> never masked into a pass or defer
#   7   OPERATION PARITY                -> all eight operations, jq leg vs php
#                                         leg vs recorded expectations
#   8   shadowed jq at operation level  -> php leg answers correctly anyway
#   9   whole-run output parity         -> identical fixtures through both legs
#                                         produce identical reports
#   10  end-to-end with jq absent       -> DEFERRED: 0 and the right exit code
#
# ITERATION / /dev/fd (added with the here-string repair)
# ------------------------------------------------------
# The same deploy host has NO /dev/fd, so `done < <(producer)` could not open
# its redirect: the loop body ran ZERO times and the two probes built on it
# went quiet. The Trust Score probe then printed "PASS 0 page(s)" (its closing
# test was `bad == 0`, i.e. absence of failure, never presence of success), and
# the ServiceLocator probe — whose loop WAS the entire probe — emitted no
# verdict at all while the run still exited 0.
#
#   11  /dev/fd unavailable             -> no process substitution survives in
#                                         the guard, and the shipped here-string
#                                         construct still iterates with /dev/fd
#                                         genuinely absent
#   12  healthy samples                 -> 10/10 rows and 4/4 contracts PASS
#   13  zero iterations                 -> both probes FAIL loudly, exit 1
#   14  partial / excessive / duplicate -> counted against the requested total
#   15  malformed / failing / producer  -> never masked into a pass
#       failure
#   16  parent-shell state retention    -> ok / bad / iteration counters and the
#                                         PASS+FAIL tallies survive the loop
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

# Deliberately BROKEN world, used for the whole-run parity case: drift, thin
# coverage, sync lag, a trust-score mismatch and two overdue hooks. It drives
# every one of the eight operations down its failure path in both legs.
FX_READ_MODEL_DRIFT='{"status":"degraded","drift":{"divergent_pages":2,"samples":[{"page_id":41},{"page_id":77}]},"coverage":{"coverage_pct":88.5,"total_pages":40,"rm_rows":35,"gap_count":5},"freshness":{"lag_seconds":900,"dirty_queue_size":3}}'
FX_ROWS_TWO='[{"page_id":41,"trust_score":85.0,"positive_score":10.0,"negative_score":0.0,"onchain_bonus":5.0},{"page_id":77,"trust_score":70.0,"positive_score":6.0,"negative_score":0.0,"onchain_bonus":0.0}]'
FX_RESULTS_ONE_BAD='[{"page_id":41,"stored":85.0,"expected":85.0,"diff":0.0,"ok":true,"tolerance":0.5,"pos":10.0,"neg":0.0,"ob":5.0},{"page_id":77,"stored":70.0,"expected":72.25,"diff":2.25,"ok":false,"tolerance":0.5,"pos":6,"neg":0,"ob":0}]'
FX_CRON_EVENTS_OVERDUE='[{"hook":"bcc_zeta","next_run_relative":"3 hours ago"},{"hook":"bcc_alpha","next_run_relative":"2 minutes ago"},{"hook":"bcc_zeta","next_run_relative":"1 day ago"},{"hook":"bcc_later","next_run_relative":"in 5 minutes"}]'

# ── Sample generators for the iteration cases ────────────────────────────────
# The guard samples $SAMPLE (default 10) page_read_model rows, then asks
# PageScore::computeExpectedTotal for one result per row. These build both
# halves so a run can be given a sample that is complete, short, long,
# duplicated or malformed.

# mk_rows <n> — n page_read_model rows, page_id 101..100+n.
mk_rows() {
    local n="$1" i out=""
    for (( i = 1; i <= n; i++ )); do
        [[ -n "$out" ]] && out+=","
        out+="{\"page_id\":$((100+i)),\"trust_score\":85.0,\"positive_score\":10.0"
        out+=",\"negative_score\":0.0,\"onchain_bonus\":5.0}"
    done
    printf '[%s]' "$out"
}

# mk_result <page_id> <ok:true|false>
mk_result() {
    local pid="$1" okflag="$2" stored=85.0 expected=85.0 diff=0.0
    if [[ "$okflag" == "false" ]]; then stored=70.0; diff=15.0; fi
    printf '{"page_id":%s,"stored":%s,"expected":%s,"diff":%s,"ok":%s' \
        "$pid" "$stored" "$expected" "$diff" "$okflag"
    printf ',"tolerance":0.5,"pos":10.0,"neg":0.0,"ob":5.0}'
}

# mk_results <n> [bad-index] — n results, page_id 101..100+n. bad-index is
# 1-based; that row reports ok:false.
mk_results() {
    local n="$1" bad="${2:-0}" i out="" flag
    for (( i = 1; i <= n; i++ )); do
        flag=true; (( i == bad )) && flag=false
        [[ -n "$out" ]] && out+=","
        out+="$(mk_result "$((100+i))" "$flag")"
    done
    printf '[%s]' "$out"
}

FX_ROWS_TEN="$(mk_rows 10)"
FX_RESULTS_TEN="$(mk_results 10)"
FX_RESULTS_TEN_ONE_BAD="$(mk_results 10 3)"
FX_RESULTS_SEVEN="$(mk_results 7)"
FX_RESULTS_ELEVEN="$(mk_results 11)"
# Eleven results for a ten-row sample because page 101 was emitted twice.
FX_RESULTS_TEN_DUP="[$(mk_result 101 true),$(mk_results 10 | sed 's/^\[//;s/\]$//')]"
# A result row that is a bare string: json_get cannot read .ok out of it.
FX_RESULTS_MALFORMED="[$(mk_results 9 | sed 's/^\[//;s/\]$//'),\"not-a-result-row\"]"

# ── ServiceLocator payload variants ──────────────────────────────────────────
FX_LOCATOR_EMPTY='{}'
FX_LOCATOR_PARTIAL='{"TrustReadService":"real","ScoreReadService":"real","DisputeAdjudicator":"real"}'
FX_LOCATOR_EXTRA='{"TrustReadService":"real","ScoreReadService":"real","DisputeAdjudicator":"real","PageOwnerResolver":"real","ExtraService":"real"}'
# jq/php render a value containing a newline across two lines, so the second
# line arrives at the loop with no "=" in it at all.
FX_LOCATOR_SPLIT_LINE='{"TrustReadService":"real\nbogus-line","ScoreReadService":"real","DisputeAdjudicator":"real","PageOwnerResolver":"real"}'
FX_LOCATOR_EMPTY_LABEL='{"":"real","ScoreReadService":"real","DisputeAdjudicator":"real","PageOwnerResolver":"real"}'
# A JSON object cannot carry the same key twice, so the duplicate is produced
# the only way it can reach the loop: a value that spans two lines, the second
# of which is itself a well-formed "TrustReadService=real" entry.
FX_LOCATOR_DUP='{"TrustReadService":"real\nTrustReadService=real","ScoreReadService":"real","DisputeAdjudicator":"real","PageOwnerResolver":"real"}'
FX_LOCATOR_EMPTY_STATUS='{"TrustReadService":"","ScoreReadService":"real","DisputeAdjudicator":"real","PageOwnerResolver":"real"}'
FX_LOCATOR_BAD_STATUS='{"TrustReadService":"maybe","ScoreReadService":"real","DisputeAdjudicator":"real","PageOwnerResolver":"real"}'
# Valid JSON that has no keys at all: to_entries errors, so the producer fails
# even though json_valid is satisfied.
FX_LOCATOR_KEYLESS='"boom"'

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

# jq >= 1.7 re-prints an unmutated number using its SOURCE LITERAL (85.0 stays
# 85.0); jq 1.6 canonicalises it to a double (85). The php leg reproduces the
# 1.7+ behaviour, because that is what any jq installed today does and because
# it is the only rule that does not depend on php's serialize_precision. On a
# 1.6 host the literal-sensitive fixtures below skip their jq leg and still
# check the php leg against recorded values.
JQ_VERSION=""
JQ_KEEPS_LITERALS=0
if [[ -n "$REAL_JQ" ]]; then
    JQ_VERSION="$("$REAL_JQ" --version 2>/dev/null)"
    if [[ "$(printf '%s' '{"a":85.0}' | "$REAL_JQ" -r '.a' 2>/dev/null)" == "85.0" ]]; then
        JQ_KEEPS_LITERALS=1
    fi
fi

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

# run_guard_fx <path> <VAR=value>... -> sets GUARD_OUT / GUARD_RC
# Whole-run with an explicit fixture set, so each iteration scenario asserts a
# real verdict AND a real process exit status.
run_guard_fx() {
    local use_path="$1"; shift
    GUARD_OUT="$(env "$@" PATH="$use_path" bash "$GUARD" 2>&1)"
    GUARD_RC=$?
}

# probe_run <fn> <VAR=value>... -> stdout is the probe's output followed by the
# guard's PARENT-SHELL tallies. The guard is sourced as a library so a single
# probe function can be driven in isolation; PASSED/FAILED are globals the loop
# bodies mutate, so printing them AFTER the probe returns proves the loop did
# not run in a subshell.
probe_run() {
    local fn="$1"; shift
    # Stash the fixture assignments in an array FIRST: `set --` below wipes the
    # positional parameters (intent-guard.sh parses "$@" at load time and would
    # otherwise read a fixture as a CLI argument).
    local -a fixtures=("$@")
    (
        set --
        export BCC_INTENT_GUARD_LIB=1
        (( ${#fixtures[@]} > 0 )) && export "${fixtures[@]}"
        PATH="$PATH_NO_JQ"
        # shellcheck source=/dev/null
        source "$GUARD" >/dev/null 2>&1
        require_tools
        "$fn"
        printf 'TALLY PASSED=%d FAILED=%d SKIPPED=%d\n' "$PASSED" "$FAILED" "$SKIPPED"
    ) 2>&1
}

count_lines() { printf '%s\n' "$1" | grep -c -- "$2" 2>/dev/null || true; }

# deferred_count <guard output>
deferred_count() {
    printf '%s\n' "$1" | sed -n 's/.*DEFERRED: \([0-9][0-9]*\).*/\1/p' | tail -1
}

printf "${BOLD}intent-guard prerequisite + jq-parity regression harness${NC}\n"
printf "guard : %s\n" "$GUARD"
printf "jq    : %s %s\n" "${REAL_JQ:-<none on host>}" "$JQ_VERSION"
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
# Case 2 — jq absent: ONE notice, a COMPLETE run, nothing deferred
# ═════════════════════════════════════════════════════════════════════════════
case_banner 2 "jq absent -> one notice, COMPLETE run, DEFERRED: 0"
run_guard "$PATH_NO_JQ"
NOTICES="$(count_lines "$GUARD_OUT" "PREREQUISITE")"
assert_eq "exactly ONE prerequisite notice"            "1" "$NOTICES"
assert_eq "exit code is 0 (complete, not deferred)"    "0" "$GUARD_RC"
assert_eq "DEFERRED count is 0"                        "0" "$(deferred_count "$GUARD_OUT")"
assert_contains     "summary still reports FAIL: 0"                 "$GUARD_OUT" "FAIL: 0"
assert_not_contains "nothing is DEFERred"                           "$GUARD_OUT" "DEFER "
assert_not_contains "no bogus 'not valid JSON' failure"             "$GUARD_OUT" "not valid JSON"
assert_not_contains "no bogus 'returned non-JSON' failure"          "$GUARD_OUT" "returned non-JSON"
assert_not_contains "no bogus 'returned non-array' failure"         "$GUARD_OUT" "returned non-array"
assert_contains     "notice explains the php parity path"           "$GUARD_OUT" "falls back to php"
assert_contains     "notice states the run is complete"             "$GUARD_OUT" "This run is COMPLETE"
# Every probe that needed a jq filter must now report a real verdict.
assert_contains     "read-model drift evaluated"                    "$GUARD_OUT" "no read-model drift"
assert_contains     "coverage evaluated"                            "$GUARD_OUT" "coverage 100% >= 95%"
assert_contains     "sync lag evaluated"                            "$GUARD_OUT" "read-model sync healthy (lag=3s queue=0)"
assert_contains     "limiter health evaluated"                      "$GUARD_OUT" "limiter health: backend=trust_engine"
assert_contains     "ServiceLocator wiring evaluated"               "$GUARD_OUT" "TrustReadService → real implementation"
assert_contains     "cron snapshot evaluated"                       "$GUARD_OUT" "auto_resolve last succeeded"
assert_contains     "overdue-cron probe evaluated"                  "$GUARD_OUT" "no overdue cron events"
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

# 3b. Black-box: the guard must not believe jq is usable, and must still run.
run_guard_with_jq_function
NOTICES3="$(count_lines "$GUARD_OUT" "PREREQUISITE")"
assert_eq "exactly ONE prerequisite notice despite the function" "1" "$NOTICES3"
assert_eq "exit code is 0 (complete run through php)"           "0" "$GUARD_RC"
assert_eq "DEFERRED count is 0 despite the function"            "0" "$(deferred_count "$GUARD_OUT")"
assert_not_contains "function shadowing produced no bogus 'not valid JSON'"  "$GUARD_OUT" "not valid JSON"
assert_not_contains "function shadowing produced no bogus 'non-JSON'"        "$GUARD_OUT" "returned non-JSON"
assert_contains     "header reports jq as absent"                            "$GUARD_OUT" "<absent — php fallback>"
assert_contains     "probes still evaluated under the shadowing function"    "$GUARD_OUT" "TrustReadService → real implementation"

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

# payload|expected-exit|label. 0 = valid+truthy, 1 = valid but null/false,
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

FIXTURE_LOCATOR="$FX_LOCATOR_UNHEALTHY" run_guard "$PATH_NO_JQ"
assert_eq       "unhealthy ServiceLocator -> exit 1 with jq absent too" "1" "$GUARD_RC"
assert_contains "unhealthy predicate reported as FAIL (php leg)" "$GUARD_OUT" "TrustReadService → NullObject fallback"
assert_contains "still not deferred (php leg)"                   "$GUARD_OUT" "DEFERRED: 0"

FIXTURE_LOCATOR="$FX_LOCATOR_MALFORMED" run_guard "$PATH_NO_JQ"
assert_contains "malformed payload still FAILs with jq absent" "$GUARD_OUT" "ServiceLocator probe returned non-JSON"
assert_eq       "malformed payload -> exit 1 (failure wins over deferral)" "1" "$GUARD_RC"

# ═════════════════════════════════════════════════════════════════════════════
# Case 7 — OPERATION PARITY
#
# Every jq expression intent-guard.sh uses, fed identical fixtures through the
# real-jq leg and the php leg, asserted against RECORDED expectations and then
# against each other byte-for-byte. If the two legs ever disagree the guard's
# verdict depends on whether jq happens to be installed, which is the whole
# failure this PR exists to remove.
# ═════════════════════════════════════════════════════════════════════════════
case_banner 7 "operation parity: jq leg vs php leg vs recorded expectations"

# op_exec <PATH> <force-php:0|1> <op> <args...> — one helper call, in a
# subshell, with the guard sourced as a library. stdout is the caller's to
# redirect; the exit status is the helper's.
op_exec() {
    local use_path="$1" force_php="$2"; shift 2
    local op="$1"; shift
    local args=("$@")
    (
        # Clear positional parameters BEFORE sourcing: intent-guard.sh parses
        # "$@" at load time, and it would read the fixture as a CLI argument.
        set --
        export BCC_INTENT_GUARD_LIB=1
        PATH="$use_path"
        # shellcheck source=/dev/null
        source "$GUARD" >/dev/null 2>&1
        require_tools
        (( force_php == 1 )) && HAVE_JQ=0
        case "$op" in
            get)   json_get "${args[@]}" ;;
            arr)   json_is_array "${args[@]}" ;;
            len)   json_length "${args[@]}" ;;
            each)  json_each_compact "${args[@]}" ;;
            kv)    json_entries_kv "${args[@]}" ;;
            csv)   json_drift_sample_csv "${args[@]}" ;;
            over)  json_overdue_hooks "${args[@]}" ;;
            *)     echo "unknown op $op" >&2; exit 99 ;;
        esac
        exit $?
    ) 2>/dev/null
}

vis() { tr '\n' '~' < "$1"; }

PARITY_SKIPPED_LEGS=0

# _parity <literal-sensitive:0|1> <label> <exp-rc> <exp-stdout> <op> <args...>
_parity() {
    local lit="$1" label="$2" exp_rc="$3" exp_out="$4"; shift 4
    printf '%s' "$exp_out" > "$SANDBOX/exp.out"

    op_exec "$PATH_NO_JQ" 1 "$@" > "$SANDBOX/php.out"; local php_rc=$?
    if [[ "$php_rc" == "$exp_rc" ]] && cmp -s "$SANDBOX/exp.out" "$SANDBOX/php.out"; then
        ok "php  $label"
    else
        notok "php  $label" \
              "expected rc=$exp_rc out=[$(vis "$SANDBOX/exp.out")] got rc=$php_rc out=[$(vis "$SANDBOX/php.out")]"
    fi

    [[ -n "$REAL_JQ" ]] || return 0
    if (( lit == 1 && JQ_KEEPS_LITERALS == 0 )); then
        PARITY_SKIPPED_LEGS=$((PARITY_SKIPPED_LEGS+1))
        return 0
    fi

    op_exec "$PATH_WITH_JQ" 0 "$@" > "$SANDBOX/jq.out"; local jq_rc=$?
    if [[ "$jq_rc" == "$exp_rc" ]] && cmp -s "$SANDBOX/exp.out" "$SANDBOX/jq.out" \
       && [[ "$jq_rc" == "$php_rc" ]] && cmp -s "$SANDBOX/jq.out" "$SANDBOX/php.out"; then
        ok "jq   $label  (identical to php leg)"
    else
        notok "jq   $label" \
              "expected rc=$exp_rc out=[$(vis "$SANDBOX/exp.out")]; jq rc=$jq_rc out=[$(vis "$SANDBOX/jq.out")]; php rc=$php_rc out=[$(vis "$SANDBOX/php.out")]"
    fi
}
# parity      — version-independent fixture
# parity_lit  — output depends on jq's number-literal handling (jq >= 1.7)
parity()     { _parity 0 "$@"; }
parity_lit() { _parity 1 "$@"; }

# ── 1. json_get — scalar / nested extraction, with and without a default ─────
printf "  ${BOLD}op 1/8 json_get${NC}  — jq -r '.a' / '.a.b // 0' / '.[0].t // 0.5'\n"
parity 'get plain scalar'            0 $'1\n'      get '{"a":1}'          '.a'
parity 'get explicit null'           0 $'null\n'   get '{"a":null}'       '.a'
parity 'get missing key'             0 $'null\n'   get '{}'               '.a'
parity 'get nested'                  0 $'2\n'      get '{"a":{"b":2}}'    '.a.b'
parity 'get through null'            0 $'null\n'   get '{"a":null}'       '.a.b'
parity 'get index a scalar -> err'   5 ''          get '{"a":1}'          '.a.b'
parity 'get key on array -> err'     5 ''          get '[]'               '.a'
parity 'get .[0].t'                  0 $'0.5\n'    get '[{"t":0.5}]'      '.[0].t'
parity 'get .[0] out of range'       0 $'null\n'   get '[]'               '.[0]'
parity 'get index on object -> err'  5 ''          get '{}'               '.[0]'
parity 'get on null document'        0 $'null\n'   get 'null'             '.a'
parity 'get malformed json'          5 ''          get 'garbage'          '.a'
parity 'get empty input'             0 ''          get ''                 '.a'
parity 'get string is raw'           0 $'hi there\n' get '{"a":"hi there"}' '.a'
parity 'get boolean'                 0 $'true\n'   get '{"a":true}'       '.a'
parity 'get unicode + slash raw'     0 $'café/x\n' get '{"a":"café/x"}'   '.a'
parity 'get object pretty-prints'    0 $'{\n  "x": 1\n}\n'         get '{"a":{"x":1}}'  '.a'
parity 'get array pretty-prints'     0 $'[\n  1,\n  "z"\n]\n'      get '{"a":[1,"z"]}'  '.a'
# The `//` traps: it substitutes for null and false and for NOTHING else.
parity 'default: 0 is NOT replaced'  0 $'0\n'      get '{"a":0}'          '.a' 'X'
parity 'default: "" is NOT replaced' 0 $'\n'       get '{"a":""}'         '.a' 'X'
parity 'default: [] is NOT replaced' 0 $'[]\n'     get '{"a":[]}'         '.a' 'X'
parity 'default: {} is NOT replaced' 0 $'{}\n'     get '{"a":{}}'         '.a' 'X'
parity 'default: false IS replaced'  0 $'X\n'      get '{"a":false}'      '.a' 'X'
parity 'default: null IS replaced'   0 $'X\n'      get '{"a":null}'       '.a' 'X'
parity 'default: missing IS replaced' 0 $'X\n'     get '{}'               '.a' 'X'
parity 'default does not mask errors' 5 ''         get '{"a":1}'          '.a.b' 'X'
parity 'default on empty input'      0 ''          get ''                 '.a' 'X'
parity 'default on malformed'        5 ''          get 'garbage'          '.a' 'X'
# Real call-site shapes.
parity 'coverage_pct 0 stays 0'      0 $'0\n'      get '{"coverage":{"coverage_pct":0}}' '.coverage.coverage_pct' '0'
parity 'coverage_pct missing -> 0'   0 $'0\n'      get '{}'               '.coverage.coverage_pct' '0'
parity 'last_failure null -> unknown' 0 $'unknown\n' get '{"last_failure":null}' '.last_failure.message' 'unknown'
parity 'last_failure message'        0 $'boom\n'   get '{"last_failure":{"message":"boom"}}' '.last_failure.message' 'unknown'
parity_lit 'number literal 85.0 kept' 0 $'85.0\n'  get '{"a":85.0}'       '.a'
parity_lit 'exponent literal 1e2'    0 $'1E+2\n'   get '{"a":1e2}'        '.a'
parity_lit 'negative zero float'     0 $'-0.0\n'   get '{"a":-0.0}'       '.a'
parity_lit 'big integer keeps digits' 0 $'12345678901234567890\n' get '{"a":12345678901234567890}' '.a'

# ── 2. json_is_array — jq -e 'type == "array"' ───────────────────────────────
printf "  ${BOLD}op 2/8 json_is_array${NC}  — jq -e 'type == \"array\"'\n"
parity 'is_array []'          0 $'true\n'  arr '[]'
parity 'is_array [1,2]'       0 $'true\n'  arr '[1,2]'
parity 'is_array {} -> 1'     1 $'false\n' arr '{}'
parity 'is_array null -> 1'   1 $'false\n' arr 'null'
parity 'is_array false -> 1'  1 $'false\n' arr 'false'
parity 'is_array number -> 1' 1 $'false\n' arr '1'
parity 'is_array malformed'   5 ''         arr 'garbage'
parity 'is_array empty -> 4'  4 ''         arr ''

# ── 3. json_length — jq 'length' ─────────────────────────────────────────────
printf "  ${BOLD}op 3/8 json_length${NC}  — jq 'length'\n"
parity 'length of array'      0 $'2\n' len '[1,2]'
parity 'length of empty array' 0 $'0\n' len '[]'
parity 'length of object'     0 $'2\n' len '{"a":1,"b":2}'
parity 'length of {}'         0 $'0\n' len '{}'
parity 'length of null is 0'  0 $'0\n' len 'null'
parity 'length of string'     0 $'3\n' len '"abc"'
parity 'length counts codepoints' 0 $'4\n' len '"café"'
parity 'length of number is abs'  0 $'5.5\n' len '-5.5'
parity 'length of boolean -> err' 5 ''  len 'true'
parity 'length of malformed'      5 ''  len 'garbage'
parity 'length of empty input'    0 ''  len ''

# ── 4. json_each_compact — jq -c '.[]' ───────────────────────────────────────
printf "  ${BOLD}op 4/8 json_each_compact${NC}  — jq -c '.[]'\n"
parity 'each over array'      0 $'{"a":1}\n2\n' each '[{"a":1},2]'
parity 'each over object values' 0 $'1\n2\n'    each '{"a":1,"b":2}'
parity 'each over []'         0 ''              each '[]'
parity 'each over {}'         0 ''              each '{}'
parity 'each over null -> err' 5 ''             each 'null'
parity 'each over number -> err' 5 ''           each '1'
parity 'each malformed'       5 ''              each 'garbage'
parity 'each empty input'     0 ''              each ''
parity 'each keeps nesting compact' 0 $'{"a":[1,{"b":null}],"c":true}\n' each '[{"a":[1,{"b":null}],"c":true}]'
parity 'each leaves slash and unicode' 0 $'{"s":"a/b café"}\n' each '[{"s":"a/b café"}]'
EXP_ESCAPES='{"s":"he said \"hi\"","t":"back\\slash"}'
parity 'each preserves string escapes' 0 "$EXP_ESCAPES"$'\n' each '[{"s":"he said \"hi\"","t":"back\\slash"}]'
parity_lit 'each keeps number literals' 0 $'{"s":85.0,"t":0.5,"u":1.100}\n' each '[{"s":85.0,"t":0.5,"u":1.100}]'

# ── 5. json_entries_kv — jq -r 'to_entries[] | "\(.key)=\(.value)"' ──────────
printf "  ${BOLD}op 5/8 json_entries_kv${NC}  — jq -r 'to_entries[] | \"\\(.key)=\\(.value)\"'\n"
parity 'entries of the locator payload' 0 $'A=real\nB=null\n' kv '{"A":"real","B":"null"}'
parity 'entries keep insertion order'   0 $'z=1\na=2\nm=3\n'  kv '{"z":1,"a":2,"m":3}'
parity 'entries render mixed scalars'   0 $'a=1\nb=true\nc=null\n' kv '{"a":1,"b":true,"c":null}'
parity 'entries render containers compact' 0 $'a={"x":1}\nb=[1,2]\n' kv '{"a":{"x":1},"b":[1,2]}'
parity 'entries of an array use indexes' 0 $'0=10\n1=z\n' kv '[10,"z"]'
parity 'entries of {}'                  0 ''  kv '{}'
parity 'entries of null -> err'         5 ''  kv 'null'
parity 'entries of a string -> err'     5 ''  kv '"hi"'
parity 'entries malformed'              5 ''  kv 'garbage'
parity 'entries empty input'            0 ''  kv ''
parity 'entries leave slash + unicode'  0 $'k/1=a/b café\n' kv '{"k/1":"a/b café"}'
parity_lit 'entries keep number literals' 0 $'d=85.0\n' kv '{"d":85.0}'

# ── 6. json_drift_sample_csv ─────────────────────────────────────────────────
printf "  ${BOLD}op 6/8 json_drift_sample_csv${NC}  — '.drift.samples // [] | map(.page_id // .) | @csv'\n"
parity 'csv of page_id objects'   0 $'7,9\n' csv '{"drift":{"samples":[{"page_id":7},{"page_id":9}]}}'
parity 'csv doubles inner quotes' 0 $'"a""b"\n' csv '{"drift":{"samples":[{"page_id":"a\"b"}]}}'
parity 'csv quotes a comma'       0 $'12,"x,y",true\n' csv '{"drift":{"samples":[{"page_id":12},{"page_id":"x,y"},{"page_id":true}]}}'
parity 'csv null element'         0 $'\n' csv '{"drift":{"samples":[null]}}'
parity 'csv no drift key'         0 $'\n' csv '{}'
parity 'csv samples null -> []'   0 $'\n' csv '{"drift":{"samples":null}}'
parity 'csv samples false -> []'  0 $'\n' csv '{"drift":{"samples":false}}'
parity 'csv drift null -> []'     0 $'\n' csv '{"drift":null}'
parity 'csv empty samples'        0 $'\n' csv '{"drift":{"samples":[]}}'
parity 'csv page_id false -> element' 5 '' csv '{"drift":{"samples":[{"page_id":false}]}}'
parity 'csv page_id null -> element'  5 '' csv '{"drift":{"samples":[{"page_id":null}]}}'
parity 'csv object without page_id'   5 '' csv '{"drift":{"samples":[{"x":1}]}}'
parity 'csv numeric samples -> err'   5 '' csv '{"drift":{"samples":[1,2]}}'
parity 'csv string samples -> err'    5 '' csv '{"drift":{"samples":["s",3,true,null]}}'
parity 'csv nested array sample'      5 '' csv '{"drift":{"samples":[[1]]}}'
parity 'csv drift on an array -> err' 5 '' csv '[1,2]'
parity 'csv malformed'                5 '' csv 'garbage'
parity 'csv empty input'              0 '' csv ''

# ── 7. json_overdue_hooks ────────────────────────────────────────────────────
printf "  ${BOLD}op 7/8 json_overdue_hooks${NC}  — select(test(\"ago\")) | map(.hook) | unique\n"
parity 'overdue selects and dedupes' 0 $'a\nb\n' over \
  '[{"hook":"b","next_run_relative":"3 hours ago"},{"hook":"a","next_run_relative":"2 minutes ago"},{"hook":"b","next_run_relative":"1 day ago"},{"hook":"c","next_run_relative":"in 5 minutes"}]'
parity 'overdue unique SORTS (not input order)' 0 $'Beta\nZeta\nalpha\n' over \
  '[{"hook":"Zeta","next_run_relative":"ago"},{"hook":"alpha","next_run_relative":"ago"},{"hook":"Beta","next_run_relative":"ago"}]'
parity 'overdue jq total order across types' 0 $'null\n1\na\n' over \
  '[{"hook":1,"next_run_relative":"ago"},{"hook":"a","next_run_relative":"ago"},{"hook":null,"next_run_relative":"ago"}]'
parity 'overdue none due'      0 '' over '[{"hook":"a","next_run_relative":"in 5 minutes"}]'
parity 'overdue empty array'   0 '' over '[]'
parity 'overdue empty object'  0 '' over '{}'
parity 'overdue test() is a regex, not equality' 0 $'a\n' over \
  '[{"hook":"a","next_run_relative":"agog"},{"hook":"b","next_run_relative":"a g o"}]'
parity 'overdue null hook survives unique' 0 $'null\na\n' over \
  '[{"hook":null,"next_run_relative":"1 day ago"},{"hook":"a","next_run_relative":"1 day ago"}]'
parity 'overdue missing field -> err'  5 '' over '[{"hook":"a"}]'
parity 'overdue non-string field -> err' 5 '' over '[{"hook":"a","next_run_relative":5}]'
parity 'overdue scalar element -> err' 5 '' over '[1]'
parity 'overdue malformed'             5 '' over 'garbage'
parity 'overdue empty input'           0 '' over ''

# op 8/8 is json_valid, covered by cases 4/5/6a above.
printf "  ${BOLD}op 8/8 json_valid${NC}  — jq -e .  (covered by case 4/5/6a)\n"

if (( PARITY_SKIPPED_LEGS > 0 )); then
    printf "    ${CYAN}note${NC} %d jq leg(s) skipped: host jq (%s) canonicalises number\n" \
        "$PARITY_SKIPPED_LEGS" "$JQ_VERSION"
    printf "         literals instead of preserving them (pre-1.7). The php leg was still\n"
    printf "         checked against recorded jq >= 1.7 output.\n"
elif [[ -z "$REAL_JQ" ]]; then
    printf "    ${CYAN}note${NC} no jq binary on this host: every jq leg was skipped and only the\n"
    printf "         php leg ran, checked against recorded jq output.\n"
fi

# ═════════════════════════════════════════════════════════════════════════════
# Case 8 — a shadowing `jq` shell function with no jq binary must not be
# mistaken for jq at the OPERATION level either: the helpers have to route to
# php and still produce jq's answer.
# ═════════════════════════════════════════════════════════════════════════════
case_banner 8 "shadowed jq function at operation level -> php answers correctly"

# shadowed_op <op> <args...> — like op_exec but with a `jq` function exported
# into the environment and no jq binary reachable. HAVE_JQ is NOT forced.
shadowed_op() {
    (
        jq() { echo "SHADOW-FUNCTION-RAN"; return 0; }
        export -f jq
        op_exec "$PATH_NO_JQ" 0 "$@"
    )
}
SH_OUT="$(shadowed_op get '{"coverage":{"coverage_pct":0}}' '.coverage.coverage_pct' '0')"; SH_RC=$?
assert_eq "shadowed json_get returns jq's answer" "0" "$SH_OUT"
assert_eq "shadowed json_get exit status"         "0" "$SH_RC"
SH_OUT="$(shadowed_op arr '{}')"; SH_RC=$?
assert_eq "shadowed json_is_array stdout"         "false" "$SH_OUT"
assert_eq "shadowed json_is_array exit 1"         "1"     "$SH_RC"
SH_OUT="$(shadowed_op kv "$FX_LOCATOR_HEALTHY")"
assert_eq "shadowed json_entries_kv stdout" \
    "TrustReadService=real
ScoreReadService=real
DisputeAdjudicator=real
PageOwnerResolver=real" "$SH_OUT"
SH_OUT="$(shadowed_op over "$FX_CRON_EVENTS_OVERDUE")"
assert_eq "shadowed json_overdue_hooks stdout" "bcc_alpha
bcc_zeta" "$SH_OUT"
assert_not_contains "the shadowing function never ran" "$SH_OUT" "SHADOW-FUNCTION-RAN"

# ═════════════════════════════════════════════════════════════════════════════
# Case 9 — whole-run parity. Identical fixtures, both legs, and the reports
# must match line for line apart from the two lines that are ALLOWED to differ
# (which engine was used). This is the end-to-end statement of the property:
# the verdict does not depend on whether jq is installed.
# ═════════════════════════════════════════════════════════════════════════════
case_banner 9 "whole-run parity on a deliberately broken world"

# Everything below drives a failure path: drift, low coverage, sync lag, a
# trust-score mismatch, a NullObject binding and two overdue hooks.
BROKEN_ENV=(
    "FIXTURE_READ_MODEL=$FX_READ_MODEL_DRIFT"
    "FIXTURE_ROWS=$FX_ROWS_TWO"
    "FIXTURE_RESULTS=$FX_RESULTS_ONE_BAD"
    "FIXTURE_LOCATOR=$FX_LOCATOR_UNHEALTHY"
    "FIXTURE_CRON_EVENTS=$FX_CRON_EVENTS_OVERDUE"
)

# Lines that legitimately differ between the legs: the header line naming the
# JSON engine, the prerequisite notice and its bullets, and blank lines.
strip_engine_lines() {
    grep -v -e '^  jq          :' -e '^PREREQUISITE' -e '^ \{14,\}' -e '^$'
}

env "${BROKEN_ENV[@]}" PATH="$PATH_NO_JQ" bash "$GUARD" > "$SANDBOX/run-php.out" 2>&1
RUN_PHP_RC=$?
assert_eq "broken world with jq absent -> exit 1" "1" "$RUN_PHP_RC"
PHP_RUN_OUT="$(cat "$SANDBOX/run-php.out")"
assert_contains "drift reported (php leg)"     "$PHP_RUN_OUT" "read-model drift: 2 page(s) diverge"
assert_contains "drift samples via @csv"       "$PHP_RUN_OUT" "sample page_ids: 41,77"
assert_contains "coverage reported"            "$PHP_RUN_OUT" "read-model coverage below 95%"
assert_contains "coverage actual value"        "$PHP_RUN_OUT" "actual:   88.5% (35 of 40 pages covered)"
assert_contains "sync lag reported"            "$PHP_RUN_OUT" "sync lag exceeds budget"
assert_contains "row sample counted"           "$PHP_RUN_OUT" "sampling 2 page(s)"
assert_contains "trust score mismatch found"   "$PHP_RUN_OUT" "trust_score mismatch page_id=77"
assert_contains "mismatch carries the tolerance" "$PHP_RUN_OUT" "(tolerance ±0.5)"
assert_contains "NullObject binding reported"  "$PHP_RUN_OUT" "TrustReadService → NullObject fallback"
assert_contains "overdue hooks reported"       "$PHP_RUN_OUT" "hooks: bcc_alpha,bcc_zeta"
assert_contains "nothing deferred"             "$PHP_RUN_OUT" "DEFERRED: 0"

if [[ -z "$REAL_JQ" ]]; then
    printf "    ${CYAN}skip${NC} no jq binary; cannot diff the two legs on this host\n"
else
    env "${BROKEN_ENV[@]}" PATH="$PATH_WITH_JQ" bash "$GUARD" > "$SANDBOX/run-jq.out" 2>&1
    RUN_JQ_RC=$?
    assert_eq "broken world with jq present -> exit 1" "1" "$RUN_JQ_RC"
    assert_eq "both legs exit the same"                "$RUN_JQ_RC" "$RUN_PHP_RC"
    strip_engine_lines < "$SANDBOX/run-jq.out"  > "$SANDBOX/run-jq.cmp"
    strip_engine_lines < "$SANDBOX/run-php.out" > "$SANDBOX/run-php.cmp"
    if cmp -s "$SANDBOX/run-jq.cmp" "$SANDBOX/run-php.cmp"; then
        ok "whole-run reports are byte-identical across the two legs"
    else
        notok "whole-run reports are byte-identical across the two legs" \
              "$(diff "$SANDBOX/run-jq.cmp" "$SANDBOX/run-php.cmp" | head -20 | tr '\n' '~')"
    fi
fi

# ═════════════════════════════════════════════════════════════════════════════
# Case 10 — the shipping claim: a complete guard run with jq absent, showing
# DEFERRED: 0 and the correct exit code, on the healthy fixture set.
# ═════════════════════════════════════════════════════════════════════════════
case_banner 10 "end-to-end with jq absent: complete verdict, DEFERRED: 0"
run_guard "$PATH_NO_JQ"
assert_eq       "exit code 0"                       "0" "$GUARD_RC"
assert_eq       "DEFERRED: 0"                       "0" "$(deferred_count "$GUARD_OUT")"
assert_contains "summary line present"              "$GUARD_OUT" "DEFERRED: 0"
assert_not_contains "no deferral section printed"   "$GUARD_OUT" "Deferred (NOT evaluated"
PASS_COUNT="$(printf '%s\n' "$GUARD_OUT" | sed -n 's/.*PASS: \([0-9][0-9]*\).*/\1/p' | tail -1)"
if [[ -n "$PASS_COUNT" && "$PASS_COUNT" -ge 9 ]]; then
    ok "probes actually passed with jq absent (PASS=$PASS_COUNT)"
else
    notok "probes actually passed with jq absent" "PASS was [$PASS_COUNT], expected >= 9"
fi
if [[ -n "$REAL_JQ" ]]; then
    run_guard "$PATH_WITH_JQ"
    JQ_PASS_COUNT="$(printf '%s\n' "$GUARD_OUT" | sed -n 's/.*PASS: \([0-9][0-9]*\).*/\1/p' | tail -1)"
    assert_eq "same PASS count with and without jq" "$JQ_PASS_COUNT" "$PASS_COUNT"
fi

# ═════════════════════════════════════════════════════════════════════════════
# Case 11 — /dev/fd unavailable
#
# The deploy host has no /dev/fd, so `done < <(producer)` cannot open its
# redirect and the loop body runs ZERO times. Two halves:
#
#   11a/11b  STATIC — no process substitution may survive on an executable line
#            of the guard, and both probe loops must read from a here-string.
#            This half fails the moment `< <(` is reintroduced.
#   11c      DYNAMIC — with /dev/fd GENUINELY absent, process substitution
#            iterates 0 times while the shipped here-string construct iterates
#            fully and keeps its counter in the parent shell.
#
# 11c needs a private mount namespace to hide /proc (which is what /dev/fd is a
# symlink to). Where that cannot be established — no unshare, not Linux,
# unprivileged user namespaces disabled — it reports INCONCLUSIVE and skips
# rather than pretending. It never asserts unless the control has first proved
# the mask is real.
# ═════════════════════════════════════════════════════════════════════════════
case_banner 11 "/dev/fd unavailable -> the guard must not depend on it"

# Comment lines are allowed to name the construct (they warn against it); an
# executable line is not.
PROCSUB_CODE_LINES="$(grep -n '< <(' "$GUARD" | grep -v '^[0-9]*:[[:space:]]*#' || true)"
if [[ -z "$PROCSUB_CODE_LINES" ]]; then
    ok "no process substitution on any executable line of intent-guard.sh"
else
    notok "no process substitution on any executable line of intent-guard.sh" \
          "$(printf '%s' "$PROCSUB_CODE_LINES" | tr '\n' '~')"
fi

HS_LOOPS="$(grep -c '^[[:space:]]*done <<< ' "$GUARD" || true)"
assert_eq "both probe loops read from a here-string" "2" "$HS_LOOPS"
assert_contains "trust-score loop feeds from the captured producer output" \
    "$(sed -n '/check_trust_scores()/,/^}/p' "$GUARD")" 'done <<< "$entries"'
assert_contains "ServiceLocator loop feeds from the captured producer output" \
    "$(sed -n '/check_service_locator()/,/^}/p' "$GUARD")" 'done <<< "$entries"'

# 11c — the genuine host condition.
cat > "$SANDBOX/devfd-inner.sh" <<'DEVFD'
#!/usr/bin/env bash
# Runs INSIDE the masked mount namespace. Prints exactly one DEVFD_RESULT line.
set -u
if [[ -e /dev/fd ]]; then echo "DEVFD_RESULT=INCONCLUSIVE mask-ineffective"; exit 0; fi

# Control: the construct that was there before. Must iterate zero times.
n=0
while IFS= read -r _l; do n=$((n+1)); done < <(printf 'a\nb\nc\n') 2>/dev/null

# Shipped: the construct the guard now uses, including the empty-line skip.
data="$(printf 'a\nb\nc\n')"
m=0
while IFS= read -r l; do
    [[ -n "$l" ]] || continue
    m=$((m+1))
done <<< "$data"

if (( n == 0 && m == 3 )); then
    echo "DEVFD_RESULT=PROVEN procsub=$n herestring=$m"
else
    echo "DEVFD_RESULT=BROKEN procsub=$n herestring=$m"
fi
DEVFD

DEVFD_OUT=""
if [[ "$(uname -s)" == "Linux" ]] && type -P unshare >/dev/null 2>&1; then
    mkdir -p "$SANDBOX/devfd-empty"
    DEVFD_OUT="$(unshare -r -m bash -c "
        mount --make-rprivate / 2>/dev/null
        mount --bind '$SANDBOX/devfd-empty' /proc 2>/dev/null || exit 0
        exec bash '$SANDBOX/devfd-inner.sh'
    " 2>/dev/null || true)"
fi

case "$DEVFD_OUT" in
    *"DEVFD_RESULT=PROVEN"*)
        ok "with /dev/fd genuinely absent: process substitution iterates 0 times"
        ok "with /dev/fd genuinely absent: the here-string form iterates 3 times"
        ok "and the counter survived in the parent shell (${DEVFD_OUT#*herestring=})"
        ;;
    *"DEVFD_RESULT=BROKEN"*)
        notok "here-string iteration survives an absent /dev/fd" "$DEVFD_OUT"
        ;;
    *)
        printf "    ${CYAN}skip${NC} cannot hide /dev/fd on this host (needs Linux + a usable\n"
        printf "         mount namespace); the static half of case 11 still holds, and the\n"
        printf "         iteration behaviour is pinned by cases 12-16. Reported: [%s]\n" \
               "${DEVFD_OUT:-<no run>}"
        ;;
esac

# ═════════════════════════════════════════════════════════════════════════════
# Case 12 — the healthy samples must PASS, and say so with real numbers
# ═════════════════════════════════════════════════════════════════════════════
case_banner 12 "healthy 10-page sample and healthy four-service map -> PASS"

run_guard_fx "$PATH_NO_JQ" \
    "FIXTURE_ROWS=$FX_ROWS_TEN" "FIXTURE_RESULTS=$FX_RESULTS_TEN" \
    "FIXTURE_LOCATOR=$FX_LOCATOR_HEALTHY"
assert_eq       "healthy world -> exit 0"                "0" "$GUARD_RC"
assert_contains "all ten sampled pages verified"         "$GUARD_OUT" "10 page(s) pass canonical-formula invariant"
assert_contains "sample size announced"                  "$GUARD_OUT" "sampling 10 page(s)"
assert_contains "contract 1 of 4 has a verdict"          "$GUARD_OUT" "TrustReadService → real implementation"
assert_contains "contract 2 of 4 has a verdict"          "$GUARD_OUT" "ScoreReadService → real implementation"
assert_contains "contract 3 of 4 has a verdict"          "$GUARD_OUT" "DisputeAdjudicator → real implementation"
assert_contains "contract 4 of 4 has a verdict"          "$GUARD_OUT" "PageOwnerResolver → real implementation"
assert_contains "healthy world reports FAIL: 0"          "$GUARD_OUT" "FAIL: 0"
HEALTHY_PASS="$(printf '%s\n' "$GUARD_OUT" | sed -n 's/.*PASS: \([0-9][0-9]*\).*/\1/p' | tail -1)"
assert_eq       "healthy world yields 12 PASS verdicts"  "12" "$HEALTHY_PASS"

if [[ -n "$REAL_JQ" ]]; then
    run_guard_fx "$PATH_WITH_JQ" \
        "FIXTURE_ROWS=$FX_ROWS_TEN" "FIXTURE_RESULTS=$FX_RESULTS_TEN" \
        "FIXTURE_LOCATOR=$FX_LOCATOR_HEALTHY"
    assert_eq "healthy world -> exit 0 on the jq leg too" "0" "$GUARD_RC"
    assert_eq "same 12 PASS verdicts on the jq leg" "12" \
        "$(printf '%s\n' "$GUARD_OUT" | sed -n 's/.*PASS: \([0-9][0-9]*\).*/\1/p' | tail -1)"
fi

# ═════════════════════════════════════════════════════════════════════════════
# Case 13 — ZERO iterations. This is exactly what the deploy host produced.
# Before the repair the Trust Score probe printed "PASS 0 page(s)" and the
# ServiceLocator probe printed nothing at all, and the run exited 0.
# ═════════════════════════════════════════════════════════════════════════════
case_banner 13 "zero iterations -> loud FAIL and a nonzero exit, never silence"

run_guard_fx "$PATH_NO_JQ" \
    "FIXTURE_ROWS=$FX_ROWS_TEN" "FIXTURE_RESULTS=[]" \
    "FIXTURE_LOCATOR=$FX_LOCATOR_HEALTHY"
assert_eq       "empty result set -> exit 1"                  "1" "$GUARD_RC"
assert_contains "empty result set FAILs explicitly"           "$GUARD_OUT" "trust-score sample incompletely evaluated"
assert_contains "the failure names the requested count"       "$GUARD_OUT" "requested: 10 row(s)"
assert_contains "the failure names the iterated count"        "$GUARD_OUT" "iterated:  0 row(s)"
assert_not_contains "and never claims a pass"                 "$GUARD_OUT" "pass canonical-formula invariant"
assert_not_contains "specifically not the vacuous 'PASS 0'"   "$GUARD_OUT" "0 page(s) pass"

run_guard_fx "$PATH_NO_JQ" \
    "FIXTURE_LOCATOR=$FX_LOCATOR_EMPTY"
assert_eq       "empty ServiceLocator map -> exit 1"          "1" "$GUARD_RC"
assert_contains "empty map FAILs explicitly"                  "$GUARD_OUT" "ServiceLocator probe returned no contract entries"
assert_contains "and still names every expected contract (1)" "$GUARD_OUT" "TrustReadService → no verdict returned"
assert_contains "and still names every expected contract (2)" "$GUARD_OUT" "ScoreReadService → no verdict returned"
assert_contains "and still names every expected contract (3)" "$GUARD_OUT" "DisputeAdjudicator → no verdict returned"
assert_contains "and still names every expected contract (4)" "$GUARD_OUT" "PageOwnerResolver → no verdict returned"
assert_not_contains "silence is impossible now"               "$GUARD_OUT" "FAIL: 0"

# ═════════════════════════════════════════════════════════════════════════════
# Case 14 — partial, excessive and duplicated work
# ═════════════════════════════════════════════════════════════════════════════
case_banner 14 "partial / excessive / duplicate iteration -> counted, not trusted"

# 14a — 7 of 10 rows.
run_guard_fx "$PATH_NO_JQ" \
    "FIXTURE_ROWS=$FX_ROWS_TEN" "FIXTURE_RESULTS=$FX_RESULTS_SEVEN" \
    "FIXTURE_LOCATOR=$FX_LOCATOR_HEALTHY"
assert_eq       "7 of 10 rows -> exit 1"                "1" "$GUARD_RC"
assert_contains "7 of 10 rows FAILs"                    "$GUARD_OUT" "trust-score sample incompletely evaluated"
assert_contains "and names both numbers"                "$GUARD_OUT" "iterated:  7 row(s)"
assert_not_contains "no pass on a short sample"         "$GUARD_OUT" "pass canonical-formula invariant"

# 14b — 11 of 10 rows.
run_guard_fx "$PATH_NO_JQ" \
    "FIXTURE_ROWS=$FX_ROWS_TEN" "FIXTURE_RESULTS=$FX_RESULTS_ELEVEN" \
    "FIXTURE_LOCATOR=$FX_LOCATOR_HEALTHY"
assert_eq       "11 of 10 rows -> exit 1"               "1" "$GUARD_RC"
assert_contains "more rows than requested also FAILs"   "$GUARD_OUT" "iterated:  11 row(s)"
assert_not_contains "no pass on an oversized sample"    "$GUARD_OUT" "pass canonical-formula invariant"

# 14c — the same page twice (11 entries for a 10-row sample).
run_guard_fx "$PATH_NO_JQ" \
    "FIXTURE_ROWS=$FX_ROWS_TEN" "FIXTURE_RESULTS=$FX_RESULTS_TEN_DUP" \
    "FIXTURE_LOCATOR=$FX_LOCATOR_HEALTHY"
assert_eq       "a duplicated page -> exit 1"           "1" "$GUARD_RC"
assert_contains "a duplicated page is counted"          "$GUARD_OUT" "iterated:  11 row(s)"

# 14d — 3 of 4 contracts.
run_guard_fx "$PATH_NO_JQ" "FIXTURE_LOCATOR=$FX_LOCATOR_PARTIAL"
assert_eq       "3 of 4 contracts -> exit 1"            "1" "$GUARD_RC"
assert_contains "the missing contract is named"         "$GUARD_OUT" "PageOwnerResolver → no verdict returned"
assert_contains "the present ones still pass"           "$GUARD_OUT" "TrustReadService → real implementation"

# 14e — a fifth, unexpected contract.
run_guard_fx "$PATH_NO_JQ" "FIXTURE_LOCATOR=$FX_LOCATOR_EXTRA"
assert_eq       "a 5th contract -> exit 1"              "1" "$GUARD_RC"
assert_contains "the stranger is named"                 "$GUARD_OUT" "ServiceLocator probe returned an unexpected contract"
assert_contains "and the expected set is printed"       "$GUARD_OUT" "contract: ExtraService=real"
assert_contains "the four expected ones still pass"     "$GUARD_OUT" "PageOwnerResolver → real implementation"

# 14f — the same contract twice.
run_guard_fx "$PATH_NO_JQ" "FIXTURE_LOCATOR=$FX_LOCATOR_DUP"
assert_eq       "a repeated contract -> exit 1"         "1" "$GUARD_RC"
assert_contains "the repeat is named"                   "$GUARD_OUT" "ServiceLocator probe returned TrustReadService more than once"

# ═════════════════════════════════════════════════════════════════════════════
# Case 15 — malformed entries, one failing entry, and producer failure
# ═════════════════════════════════════════════════════════════════════════════
case_banner 15 "malformed / failing / producer failure -> never masked"

# 15a — an unparseable result row.
run_guard_fx "$PATH_NO_JQ" \
    "FIXTURE_ROWS=$FX_ROWS_TEN" "FIXTURE_RESULTS=$FX_RESULTS_MALFORMED" \
    "FIXTURE_LOCATOR=$FX_LOCATOR_HEALTHY"
assert_eq       "an unparseable row -> exit 1"          "1" "$GUARD_RC"
assert_contains "an unparseable row is reported"        "$GUARD_OUT" "trust_score mismatch"
assert_not_contains "and never passes"                  "$GUARD_OUT" "pass canonical-formula invariant"

# 15b — one genuinely mismatched page out of ten.
run_guard_fx "$PATH_NO_JQ" \
    "FIXTURE_ROWS=$FX_ROWS_TEN" "FIXTURE_RESULTS=$FX_RESULTS_TEN_ONE_BAD" \
    "FIXTURE_LOCATOR=$FX_LOCATOR_HEALTHY"
assert_eq       "one bad page -> exit 1"                "1" "$GUARD_RC"
assert_contains "the bad page is named"                 "$GUARD_OUT" "trust_score mismatch page_id=103"
assert_not_contains "nine good pages do not earn a pass" "$GUARD_OUT" "pass canonical-formula invariant"
assert_not_contains "and no second, redundant failure"   "$GUARD_OUT" "verified fewer rows than it sampled"

# 15c — the trust-score producer itself failed.
run_guard_fx "$PATH_NO_JQ" \
    "FIXTURE_ROWS=$FX_ROWS_TEN" "FIXTURE_RESULTS=PHP Fatal error: boom" \
    "FIXTURE_LOCATOR=$FX_LOCATOR_HEALTHY"
assert_eq       "a failed producer -> exit 1"           "1" "$GUARD_RC"
assert_contains "a failed producer is reported"         "$GUARD_OUT" "canonical formula call failed"
assert_not_contains "and never passes"                  "$GUARD_OUT" "pass canonical-formula invariant"

# 15d — the ServiceLocator producer returned valid JSON with no keys.
run_guard_fx "$PATH_NO_JQ" "FIXTURE_LOCATOR=$FX_LOCATOR_KEYLESS"
assert_eq       "a keyless payload -> exit 1"           "1" "$GUARD_RC"
assert_contains "a keyless payload is reported"         "$GUARD_OUT" "could not be read as contract entries"
assert_not_contains "and yields no locator pass"        "$GUARD_OUT" "→ real implementation"

# 15e — the ServiceLocator producer returned no JSON at all (pre-existing guard,
# re-asserted here for its exit status).
run_guard_fx "$PATH_NO_JQ" "FIXTURE_LOCATOR=$FX_LOCATOR_MALFORMED"
assert_eq       "a non-JSON payload -> exit 1"          "1" "$GUARD_RC"
assert_contains "a non-JSON payload is reported"        "$GUARD_OUT" "ServiceLocator probe returned non-JSON"

# 15f — one NullObject binding: the pre-existing semantics and wording.
run_guard_fx "$PATH_NO_JQ" "FIXTURE_LOCATOR=$FX_LOCATOR_UNHEALTHY"
assert_eq       "a null binding -> exit 1"              "1" "$GUARD_RC"
assert_contains "wording unchanged"                     "$GUARD_OUT" "TrustReadService → NullObject fallback"
assert_contains "detail line unchanged"                 "$GUARD_OUT" "contract is wired to a null implementation"
assert_contains "the other three still pass"            "$GUARD_OUT" "ScoreReadService → real implementation"

# 15g — malformed entry shapes, driven through the probe directly.
MAL_OUT="$(probe_run check_service_locator "FIXTURE_LOCATOR=$FX_LOCATOR_SPLIT_LINE")"
assert_contains "a line with no '=' is reported"        "$MAL_OUT" "ServiceLocator probe emitted a malformed entry"
assert_contains "and the offending text is shown"       "$MAL_OUT" "entry:    bogus-line"
MAL_OUT="$(probe_run check_service_locator "FIXTURE_LOCATOR=$FX_LOCATOR_EMPTY_LABEL")"
assert_contains "an empty contract name is reported"    "$MAL_OUT" "emitted an entry with no contract name"
MAL_OUT="$(probe_run check_service_locator "FIXTURE_LOCATOR=$FX_LOCATOR_EMPTY_STATUS")"
assert_contains "an empty status is reported"           "$MAL_OUT" "emitted an entry with no status"
MAL_OUT="$(probe_run check_service_locator "FIXTURE_LOCATOR=$FX_LOCATOR_BAD_STATUS")"
assert_contains "a status that is neither real nor null" "$MAL_OUT" "unrecognised ServiceLocator status"
assert_contains "and the bad status is shown"            "$MAL_OUT" "status:   maybe"

# The pre-existing skip/short-circuit behaviour must be untouched.
run_guard_fx "$PATH_NO_JQ" "FIXTURE_ROWS=ERR no_repo" "FIXTURE_LOCATOR=$FX_LOCATOR_HEALTHY"
assert_eq       "ERR no_repo still SKIPs, exit 0"       "0" "$GUARD_RC"
assert_contains "ERR no_repo wording unchanged"         "$GUARD_OUT" "PageReadModelRepository unavailable"
run_guard_fx "$PATH_NO_JQ" "FIXTURE_ROWS=[]" "FIXTURE_LOCATOR=$FX_LOCATOR_HEALTHY"
assert_eq       "zero sampled rows still SKIPs, exit 0" "0" "$GUARD_RC"
assert_contains "zero-row wording unchanged"            "$GUARD_OUT" "no rows in page_read_model"

# ═════════════════════════════════════════════════════════════════════════════
# Case 16 — parent-shell state retention
#
# The reason process substitution was reached for originally: a pipe would run
# the loop body in a subshell and every counter would be discarded. A
# here-string keeps parent scope, and these assertions prove it — the tallies
# are read AFTER the probe returns.
# ═════════════════════════════════════════════════════════════════════════════
case_banner 16 "counters mutated inside the loop survive in the parent shell"

TALLY_OUT="$(probe_run check_trust_scores \
    "FIXTURE_ROWS=$FX_ROWS_TEN" "FIXTURE_RESULTS=$FX_RESULTS_TEN")"
assert_contains "ok counter survived (printed after the loop)" "$TALLY_OUT" "10 page(s) pass canonical-formula invariant"
assert_contains "PASSED survived the loop"                     "$TALLY_OUT" "TALLY PASSED=1 FAILED=0"

TALLY_OUT="$(probe_run check_trust_scores \
    "FIXTURE_ROWS=$FX_ROWS_TEN" "FIXTURE_RESULTS=$FX_RESULTS_TEN_ONE_BAD")"
assert_contains "bad counter survived: no pass was printed"    "$TALLY_OUT" "trust_score mismatch page_id=103"
assert_contains "FAILED mutated inside the loop survived"      "$TALLY_OUT" "TALLY PASSED=0 FAILED=1"

TALLY_OUT="$(probe_run check_trust_scores \
    "FIXTURE_ROWS=$FX_ROWS_TEN" "FIXTURE_RESULTS=$FX_RESULTS_SEVEN")"
assert_contains "iteration counter survived the loop"          "$TALLY_OUT" "iterated:  7 row(s)"
assert_contains "and drove exactly one failure"                "$TALLY_OUT" "TALLY PASSED=0 FAILED=1"

TALLY_OUT="$(probe_run check_service_locator "FIXTURE_LOCATOR=$FX_LOCATOR_HEALTHY")"
assert_contains "four locator verdicts reached the parent"     "$TALLY_OUT" "TALLY PASSED=4 FAILED=0"

TALLY_OUT="$(probe_run check_service_locator "FIXTURE_LOCATOR=$FX_LOCATOR_DUP")"
assert_contains "a duplicate detected inside the loop counted" "$TALLY_OUT" "TALLY PASSED=4 FAILED=1"

TALLY_OUT="$(probe_run check_service_locator "FIXTURE_LOCATOR=$FX_LOCATOR_EMPTY")"
assert_contains "zero entries produce five failures, not zero" "$TALLY_OUT" "TALLY PASSED=0 FAILED=5"

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
