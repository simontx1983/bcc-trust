#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# BCC Intent Guard — runtime invariant checker for the BCC plugin ecosystem.
#
# Complements (does not replace):
#   - scripts/arch-guardrails.sh  — static architectural rules (grep-based)
#   - scripts/phpstan-all.sh      — static type/logic analysis (PHPStan L8)
#
# This script validates that the SYSTEM BEHAVES correctly at runtime. It fails
# on observable invariant breaks: trust_score correctness, read-model drift,
# ServiceLocator null-leakage, rate-limiter misbehavior, overdue cron.
#
# Two strictness profiles:
#   local (default) — forgiving thresholds so devs iterate quickly
#   ci              — brutal. No drift. No partial coverage. Real rate-limit hammer.
#
# Usage:
#   bash scripts/intent-guard.sh                      # local profile, read-only
#   bash scripts/intent-guard.sh --ci                 # strict: CI gate
#   bash scripts/intent-guard.sh --destructive        # include fraud-gate sim
#   bash scripts/intent-guard.sh --sample=20          # trust-score sample size
#   bash scripts/intent-guard.sh --base-url=URL       # for HTTP hammer
#   bash scripts/intent-guard.sh --wp-path=/var/www   # wp-cli --path override
#
# Env alternatives: BCC_BASE_URL, WP_PATH, INTENT_CI=1, INTENT_DESTRUCTIVE=1,
#                   INTENT_SAMPLE, BCC_RL_TEST_ROUTE
#
# Exit codes:
#   0  all probes ran and passed (or were legitimately SKIPped)
#   1  one or more probes FAILED — a real invariant is broken
#   2  a hard-required tool is missing (wp / curl / awk / php)
#   3  the run completed with no failures but one or more probes were
#      DEFERRED — the guard could not evaluate them. A deferred run is NOT a
#      pass; never treat 3 as success in CI. NO current probe defers: every
#      JSON read this script performs has a php implementation with the same
#      verdict as jq (see "jq-parity JSON helpers" below). defer() is kept
#      for a FUTURE read that genuinely cannot be evaluated.
#
# Tooling: wp, curl, awk and php are hard-required. jq is OPTIONAL and the
# run is COMPLETE either way: every jq expression this script uses has a
# named php equivalent that reproduces jq's output bytes and exit status, so
# a host without jq gets the same PASS/FAIL verdicts and DEFERRED: 0.
# ──────────────────────────────────────────────────────────────────────────────

set -uo pipefail

# ── Args / env ───────────────────────────────────────────────────────────────
BASE_URL="${BCC_BASE_URL:-http://blue-collar-crypto-custom.local}"
WP_PATH="${WP_PATH:-}"
DESTRUCTIVE="${INTENT_DESTRUCTIVE:-0}"
CI_MODE="${INTENT_CI:-0}"
SAMPLE="${INTENT_SAMPLE:-10}"
RL_ROUTE="${BCC_RL_TEST_ROUTE:-}"

for arg in "$@"; do
    case "$arg" in
        --ci)                CI_MODE=1 ;;
        --destructive)       DESTRUCTIVE=1 ;;
        --base-url=*)        BASE_URL="${arg#*=}" ;;
        --wp-path=*)         WP_PATH="${arg#*=}" ;;
        --sample=*)          SAMPLE="${arg#*=}" ;;
        --rl-route=*)        RL_ROUTE="${arg#*=}" ;;
        --help|-h)           sed -n '2,43p' "$0"; exit 0 ;;
        *)                   echo "Unknown arg: $arg" >&2; exit 2 ;;
    esac
done

# CI profile forces --destructive + real rate-limit hammer.
if [[ "$CI_MODE" == "1" ]]; then
    DESTRUCTIVE=1
fi

WP_ARGS=()
[[ -n "$WP_PATH" ]] && WP_ARGS+=("--path=$WP_PATH")
# rest_do_request() honors permission_callback even in-process — without a
# current user, the admin-gated health routes return rest_forbidden and the
# jq defaults make every metric parse as 0 (coverage "fails" at 0%, drift
# silently "passes"). Run as an admin; override with BCC_GUARD_WP_USER.
WP_ARGS+=("--user=${BCC_GUARD_WP_USER:-1}")

# Strictness thresholds (local ↔ ci).
if [[ "$CI_MODE" == "1" ]]; then
    MIN_COVERAGE=100          # 100% required in CI, no drift tolerated
    MAX_LAG_SECONDS=30        # very tight sync budget
    MAX_DIRTY_LAG=10
    PROFILE_LABEL="ci"
else
    MIN_COVERAGE=95
    MAX_LAG_SECONDS=300
    MAX_DIRTY_LAG=60
    PROFILE_LABEL="local"
fi

# ── Colours ──────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; MAGENTA='\033[0;35m'; BOLD='\033[1m'; NC='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; CYAN=''; MAGENTA=''; BOLD=''; NC=''
fi

# ── Result tracking ──────────────────────────────────────────────────────────
PASSED=0; FAILED=0; SKIPPED=0; DEFERRED=0
FAIL_DETAILS=()
DEFER_DETAILS=()

pass() { printf "  ${GREEN}PASS${NC}  %s\n" "$1"; PASSED=$((PASSED+1)); }
# fail <short> [<detail-line>]... — always loud. Short form is summary-ready;
# detail lines print with indent and carry expected/actual/id context.
fail() {
    local short="$1"; shift
    printf "  ${RED}FAIL${NC}  ${BOLD}%s${NC}\n" "$short"
    for line in "$@"; do
        printf "        ${RED}└─${NC} %s\n" "$line"
    done
    FAILED=$((FAILED+1))
    FAIL_DETAILS+=("$short")
}
skip() { printf "  ${YELLOW}SKIP${NC}  %s\n" "$1"; SKIPPED=$((SKIPPED+1)); }
# defer <short> [<detail-line>]... — "the guard could not evaluate this".
# NOT a pass and NOT a skip: a skip means the probe legitimately does not
# apply (feature off, no rows); a defer means the invariant is still unknown
# because the guard lacked something it needed. Deferrals drive exit code 3
# so an unevaluated run can never be mistaken for a green one.
#
# NO probe defers today, and a missing jq must never make one defer: every
# jq expression in this script has a php equivalent below with the same
# verdict. This stays for a FUTURE read that genuinely cannot be evaluated
# on some host — reach for it only when there is no parity implementation
# to write, and say plainly what could not be evaluated and why.
defer() {
    local short="$1"; shift
    printf "  ${MAGENTA}DEFER${NC} ${BOLD}%s${NC}\n" "$short"
    for line in "$@"; do
        printf "        ${MAGENTA}└─${NC} %s\n" "$line"
    done
    DEFERRED=$((DEFERRED+1))
    DEFER_DETAILS+=("$short")
}
note() { printf "        ${CYAN}info${NC}  %s\n" "$1"; }

section() {
    echo ""
    printf "${CYAN}── %s ─────────────────────────────────────────${NC}\n" "$1"
}

# ── Prereqs ──────────────────────────────────────────────────────────────────
#
# ⚠️  DO NOT change `type -P` back to `command -v` here. Read this first.
#
# `command -v NAME` resolves shell FUNCTIONS and ALIASES as well as PATH
# executables, and this very script defines a wrapper function named `jq`
# (see below). So `command -v jq` answered "yes, found: jq" on hosts where
# no jq binary exists at all — the wrapper shadowed the check. Proof:
#
#     $ PATH=/usr/bin:/bin        # no jq binary anywhere on PATH
#     $ jq() { command jq "$@"; } # the wrapper this script defines
#     $ command -v jq  ->  jq            (exit 0 — WRONG, claims present)
#     $ type -P jq     ->  <empty>       (exit 1 — correct, truly absent)
#     $ echo '{}' | jq .  ->  exit 127   (reality: cannot run)
#
# The consequence was not a clean error: require_tools passed, then every
# `jq -e .` validation site failed, reporting "returned non-JSON" for
# payloads that were perfectly valid JSON. The guard gave a confidently
# wrong reading. An alias has the same effect.
#
# `type -P` is a bash builtin that searches PATH for EXECUTABLES ONLY and
# ignores functions, aliases, builtins and keywords. It is the only correct
# primitive for "can I actually exec this program?".
#
# The wrapper below deliberately keeps the name `jq` (renaming it would churn
# 40+ call sites for no safety gain now that detection is correct). This
# comment and scripts/tests/intent-guard-prereq.test.sh are the guard against
# this regressing.
#
# Hard-required: wp, curl, awk, php. php is required because it powers the
# portable json_valid() fallback — every installed WordPress environment has
# one, which is what makes the fallback safe to depend on.
HAVE_JQ=0
PHP_BIN=""

require_tools() {
    local t
    for t in wp curl awk php; do
        # type -P, NOT command -v — see the block comment above.
        type -P "$t" >/dev/null 2>&1 || {
            echo -e "${RED}Missing required tool:${NC} $t" >&2; exit 2
        }
    done
    PHP_BIN="$(type -P php)"

    # jq is OPTIONAL. Detect the real binary only; never trust a function.
    if type -P jq >/dev/null 2>&1; then
        HAVE_JQ=1
    else
        HAVE_JQ=0
    fi
}

# Windows jq builds emit CRLF line endings; the stray \r makes string
# compares fail ($status == "real" vs "real\r") and silently breaks the
# numeric [[ -gt ]] checks (which error → false → fake PASS on drift).
# Strip \r from all jq output while preserving jq's exit status for -e.
# Every call site is gated on HAVE_JQ==1; this is never reached without a
# real jq binary on PATH.
jq() { command jq "$@" | tr -d '\r'; return "${PIPESTATUS[0]}"; }

# json_valid <payload> — "is this valid JSON, and is its value truthy?"
#
# Drop-in replacement for `echo "$payload" | jq -e . >/dev/null` that works
# without jq. It reproduces `jq -e`'s exit-status contract exactly, because
# -e derives its status from the LAST OUTPUT VALUE, not from parseability:
#
#     input        jq -e .   json_valid
#     {"a":1}        0          0        valid, truthy
#     []  {}  0  ""  0          0        valid, truthy (empty ≠ falsy in jq)
#     true           0          0
#     null           1          1        valid JSON, but a falsy value
#     false          1          1        valid JSON, but a falsy value
#     garbage        5          5        parse error
#     <empty>        4          4        no input, so no output value
#
# Uses the real jq binary when one exists so the two paths cannot diverge;
# otherwise php + json_decode(JSON_THROW_ON_ERROR). This is the VALIDATION
# operation only; the reads (.a, to_entries, @csv, unique …) are the seven
# named helpers below.
json_valid() {
    if (( HAVE_JQ == 1 )); then
        # `command jq` bypasses the wrapper function above.
        printf '%s' "$1" | command jq -e . >/dev/null 2>&1
        return $?
    fi
    [[ -n "$PHP_BIN" ]] || PHP_BIN="$(type -P php)"
    printf '%s' "$1" | "$PHP_BIN" -r '
        $raw = stream_get_contents(STDIN);
        if (trim($raw) === "") { exit(4); }          // jq: no output value
        try {
            $v = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            exit(5);                                  // jq: parse error
        }
        exit(($v === null || $v === false) ? 1 : 0);  // jq -e falsy rule
    ' >/dev/null 2>&1
    return $?
}

# ═════════════════════════════════════════════════════════════════════════════
# jq-parity JSON helpers
# ═════════════════════════════════════════════════════════════════════════════
#
# Every JSON read this script performs goes through one of the seven named
# helpers below (plus json_valid above — eight operations in total). Each one
# dispatches to the REAL jq binary when there is one, and to a php
# implementation when there is not. The php side is a parity implementation,
# not an approximation and not a deferral: same stdout bytes, same exit
# status. That is what lets a jq-less host (installed staging) produce a
# complete verdict with DEFERRED: 0.
#
#   helper                  jq expression it stands in for
#   ─────────────────────── ────────────────────────────────────────────────
#   json_get                jq -r '.a'  /  jq -r '.a.b // 0'  /  '.[0].t // 0.5'
#   json_is_array           jq -e 'type == "array"'
#   json_length             jq 'length'
#   json_each_compact       jq -c '.[]'
#   json_entries_kv         jq -r 'to_entries[] | "\(.key)=\(.value)"'
#   json_drift_sample_csv   jq -r '.drift.samples // [] | map(.page_id // .) | @csv'
#   json_overdue_hooks      jq -r '[.[] | select(.next_run_relative | test("ago"))]
#                                   | map(.hook) | unique | .[]'
#   json_valid              jq -e .
#
# This is deliberately NOT a jq interpreter. There is no filter evaluator:
# the php side switches on an operation NAME, and json_get takes a path made
# only of `.key` and `.[N]` segments (validated below). Adding a new jq
# expression to this script means adding a new named helper here — which is
# the point, because the parity obligation stays visible.
#
# jq semantics reproduced exactly (each one is a trap that silently changes
# verdicts if you get it wrong, and each one is pinned by
# scripts/tests/intent-guard-prereq.test.sh):
#
#   `//`      substitutes ONLY when the left side is null or false. NOT for
#             0, "", [] or {}. A coverage_pct of 0 stays 0; it does not
#             become the default and quietly pass the coverage check.
#   -r        strings print unquoted, null prints "null", booleans print
#             true/false, containers print as jq pretty-prints them.
#   -e        exit 1 when the last value is false/null, 4 when there was no
#             output at all, 5 on a parse or runtime error.
#   numbers   keep their SOURCE LITERAL (jq >= 1.7 behaviour: 85.0 prints as
#             85.0, 1.100 as 1.100, 1e2 as 1E+2), so php float formatting —
#             which varies with serialize_precision — can never leak in.
#   errors    indexing a non-object with a key, iterating a scalar, @csv on a
#             container, test() on a non-string: all exit 5, as in jq.
#   unique    SORTS as well as deduplicating (jq total order:
#             null < false < true < numbers < strings < arrays < objects),
#             so overdue-hook output order matches jq, not input order.
#
# Known, deliberate narrowings (neither occurs in this script's payloads):
#   • exactly ONE JSON document per payload — jq would process a stream.
#   • json_get paths use the dotted index form `.a.[0]`, not `.a[0]`.
BCC_JSON_PHP=$(cat <<'BCC_JSON_PHP_EOF'
ini_set("display_errors", "stderr");
error_reporting(E_ALL);

// Numbers are tagged with this sentinel during decode so the SOURCE LITERAL
// survives (php floats would re-format them). NUL cannot appear unescaped in
// JSON text, so a payload can only collide with this by containing a literal
// NUL followed by "jqnum:" and a well-formed number.
const NT    = "\x00jqnum:";
const NUMRE = "/^-?(0|[1-9][0-9]*)(\\.[0-9]+)?([eE][+-]?[0-9]+)?$/";

function jq_die($msg) {
    fwrite(STDERR, "intent-guard json: " . $msg . "\n");
    exit(5);
}

// Rewrite every JSON number token as a tagged string, leaving strings (and
// anything malformed, so json_decode still rejects it) untouched.
function wrap_numbers($s) {
    $out = ""; $n = strlen($s); $i = 0; $inStr = false;
    while ($i < $n) {
        $c = $s[$i];
        if ($inStr) {
            if ($c === "\\" && $i + 1 < $n) { $out .= $c . $s[$i + 1]; $i += 2; continue; }
            if ($c === '"') { $inStr = false; }
            $out .= $c; $i++; continue;
        }
        if ($c === '"') { $inStr = true; $out .= $c; $i++; continue; }
        if ($c === "-" || ($c >= "0" && $c <= "9")) {
            $j = $i;
            while ($j < $n && strpos("+-0123456789.eE", $s[$j]) !== false) { $j++; }
            $tok = substr($s, $i, $j - $i);
            $out .= (preg_match(NUMRE, $tok) === 1) ? '"\u0000jqnum:' . $tok . '"' : $tok;
            $i = $j; continue;
        }
        $out .= $c; $i++;
    }
    return $out;
}

function is_num($v) {
    return is_string($v)
        && strncmp($v, NT, strlen(NT)) === 0
        && preg_match(NUMRE, substr($v, strlen(NT))) === 1;
}
function is_str($v)  { return is_string($v) && !is_num($v); }
function num_lit($v) { return substr($v, strlen(NT)); }
function truthy($v)  { return !($v === null || $v === false); }

function jq_type($v) {
    if ($v === null)    { return "null"; }
    if (is_bool($v))    { return "boolean"; }
    if (is_num($v))     { return "number"; }
    if (is_string($v))  { return "string"; }
    if (is_array($v))   { return "array"; }
    return "object";
}

// decNumber to-scientific-string, which is how jq >= 1.7 re-prints a number
// literal it has not mutated: 85.0 -> 85.0, 0.50 -> 0.50, 1e2 -> 1E+2,
// 5e-1 -> 0.5, 0.0000001 -> 1E-7.
function canon_num($lit) {
    if (preg_match("/^(-?)([0-9]+)(?:\\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/", $lit, $m) !== 1) {
        return $lit;
    }
    $sign  = $m[1];
    $frac  = isset($m[3]) ? $m[3] : "";
    $exp   = (isset($m[4]) && $m[4] !== "") ? (int) $m[4] : 0;
    $exp  -= strlen($frac);
    $digits = ltrim($m[2] . $frac, "0");
    if ($digits === "") { $digits = "0"; }
    $n   = strlen($digits);
    $adj = $exp + $n - 1;
    if ($exp <= 0 && $adj >= -6) {
        if ($exp === 0)   { $out = $digits; }
        elseif ($adj >= 0) { $out = substr($digits, 0, $adj + 1) . "." . substr($digits, $adj + 1); }
        else               { $out = "0." . str_repeat("0", -$adj - 1) . $digits; }
    } else {
        $out = substr($digits, 0, 1)
             . ($n > 1 ? "." . substr($digits, 1) : "")
             . "E" . ($adj >= 0 ? "+" : "-") . abs($adj);
    }
    return $sign . $out;
}

// jq escapes U+007F; php does not. Everything else matches with slashes and
// unicode left unescaped, which is jq's default output.
function enc_str($s) {
    $e = json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($e === false) { jq_die("string is not valid UTF-8"); }
    return str_replace("\x7f", "\\u007f", $e);
}

// enc($v, false) == jq -c output. enc($v, true) == jq's pretty output
// (2-space indent), which is what -r prints for a container.
function enc($v, $pretty = false, $ind = 0) {
    if ($v === null)  { return "null"; }
    if ($v === true)  { return "true"; }
    if ($v === false) { return "false"; }
    if (is_num($v))   { return canon_num(num_lit($v)); }
    if (is_string($v)) { return enc_str($v); }
    $isList = is_array($v);
    $items  = $isList ? $v : (array) $v;
    $open   = $isList ? "[" : "{";
    $close  = $isList ? "]" : "}";
    if (count($items) === 0) { return $open . $close; }
    $nl   = $pretty ? "\n" : "";
    $pad  = $pretty ? str_repeat(" ", $ind + 2) : "";
    $cpad = $pretty ? str_repeat(" ", $ind) : "";
    $parts = [];
    foreach ($items as $k => $val) {
        $piece = enc($val, $pretty, $ind + 2);
        if (!$isList) { $piece = enc_str((string) $k) . ($pretty ? ": " : ":") . $piece; }
        $parts[] = $pad . $piece;
    }
    return $open . $nl . implode("," . $nl, $parts) . $nl . $cpad . $close;
}

// jq -r: a string prints as itself, anything else as pretty JSON.
function raw_out($v) { return is_str($v) ? $v : enc($v, true, 0); }

function short_repr($v) {
    $s = enc($v, false, 0);
    return strlen($s) > 11 ? substr($s, 0, 11) . "..." : $s;
}

function idx_key($v, $k) {
    if ($v === null) { return null; }
    if ($v instanceof stdClass) { return property_exists($v, $k) ? $v->{$k} : null; }
    jq_die("Cannot index " . jq_type($v) . " with string \"" . $k . "\"");
}
function idx_at($v, $i) {
    if ($v === null) { return null; }
    if (is_array($v)) { return array_key_exists($i, $v) ? $v[$i] : null; }
    jq_die("Cannot index " . jq_type($v) . " with number");
}
function iterate($v) {
    if (is_array($v)) { return $v; }
    if ($v instanceof stdClass) { return array_values((array) $v); }
    jq_die("Cannot iterate over " . jq_type($v) . " (" . short_repr($v) . ")");
}

// jq total order: null < false < true < numbers < strings < arrays < objects.
function rankv($v) {
    if ($v === null)  { return 0; }
    if ($v === false) { return 1; }
    if ($v === true)  { return 2; }
    if (is_num($v))   { return 3; }
    if (is_string($v)) { return 4; }
    if (is_array($v)) { return 5; }
    return 6;
}
function cmpv($a, $b) {
    $ra = rankv($a); $rb = rankv($b);
    if ($ra !== $rb) { return $ra < $rb ? -1 : 1; }
    if ($ra === 3) {
        $x = (float) num_lit($a); $y = (float) num_lit($b);
        return $x < $y ? -1 : ($x > $y ? 1 : 0);
    }
    if ($ra === 4) { $c = strcmp($a, $b); return $c < 0 ? -1 : ($c > 0 ? 1 : 0); }
    if ($ra === 5) {
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $c = cmpv($a[$i], $b[$i]);
            if ($c !== 0) { return $c; }
        }
        return count($a) < count($b) ? -1 : (count($a) > count($b) ? 1 : 0);
    }
    if ($ra === 6) {
        $aa = (array) $a; $bb = (array) $b;
        $ka = array_map("strval", array_keys($aa)); sort($ka, SORT_STRING);
        $kb = array_map("strval", array_keys($bb)); sort($kb, SORT_STRING);
        $c = cmpv($ka, $kb);
        if ($c !== 0) { return $c; }
        foreach ($ka as $k) {
            $c = cmpv($aa[$k], $bb[$k]);
            if ($c !== 0) { return $c; }
        }
    }
    return 0;
}
// jq's unique: sort by the total order above, then drop equal neighbours.
function uniq_sorted($vals) {
    usort($vals, "cmpv");
    $out = [];
    foreach ($vals as $v) {
        if (count($out) === 0 || cmpv($out[count($out) - 1], $v) !== 0) { $out[] = $v; }
    }
    return $out;
}

function str_len_cp($s) {
    if (function_exists("mb_strlen")) { return (int) mb_strlen($s, "UTF-8"); }
    return (int) preg_match_all("/./us", $s);
}

// ── entry point ──────────────────────────────────────────────────────────
$op  = (string) getenv("BCC_JSON_OP");
$raw = stream_get_contents(STDIN);
if ($raw === false) { $raw = ""; }

// No input at all: jq emits nothing and exits 0, except under -e where "no
// output was ever produced" is exit 4.
if (trim($raw) === "") { exit($op === "is_array" ? 4 : 0); }

// Validate the ORIGINAL text first, so number rewriting can never turn a
// malformed literal (e.g. 01) into something that parses.
json_decode($raw);
if (json_last_error() !== JSON_ERROR_NONE) { jq_die("parse error: " . json_last_error_msg()); }
$doc = json_decode(wrap_numbers($raw));
if (json_last_error() !== JSON_ERROR_NONE) { jq_die("parse error: " . json_last_error_msg()); }

switch ($op) {

    case "get":
        $cur  = $doc;
        $path = (string) getenv("BCC_JSON_PATH");
        preg_match_all("/\\.([A-Za-z_][A-Za-z0-9_]*)|\\.\\[([0-9]+)\\]/", $path, $segs, PREG_SET_ORDER);
        foreach ($segs as $seg) {
            if (isset($seg[2]) && $seg[2] !== "") { $cur = idx_at($cur, (int) $seg[2]); }
            else                                  { $cur = idx_key($cur, $seg[1]); }
        }
        if (getenv("BCC_JSON_HAS_DEFAULT") === "1" && !truthy($cur)) {
            echo (string) getenv("BCC_JSON_DEFAULT"), "\n";
        } else {
            echo raw_out($cur), "\n";
        }
        exit(0);

    case "is_array":
        $isArray = is_array($doc);
        echo $isArray ? "true\n" : "false\n";
        exit($isArray ? 0 : 1);

    case "length":
        if ($doc === null)               { echo "0\n"; }
        elseif (is_num($doc))            { echo canon_num(ltrim(num_lit($doc), "-")), "\n"; }
        elseif (is_string($doc))         { echo str_len_cp($doc), "\n"; }
        elseif (is_array($doc))          { echo count($doc), "\n"; }
        elseif ($doc instanceof stdClass) { echo count((array) $doc), "\n"; }
        else { jq_die("boolean (" . ($doc ? "true" : "false") . ") has no length"); }
        exit(0);

    case "each_compact":
        foreach (iterate($doc) as $e) { echo enc($e, false, 0), "\n"; }
        exit(0);

    case "entries_kv":
        $pairs = [];
        if ($doc instanceof stdClass) { $pairs = (array) $doc; }
        elseif (is_array($doc))       { $pairs = $doc; }
        else { jq_die(jq_type($doc) . " (" . short_repr($doc) . ") has no keys"); }
        foreach ($pairs as $k => $v) {
            echo $k, "=", (is_str($v) ? $v : enc($v, false, 0)), "\n";
        }
        exit(0);

    case "drift_csv":
        $samples = idx_key(idx_key($doc, "drift"), "samples");
        if (!truthy($samples)) { $samples = []; }
        $cells = [];
        foreach (iterate($samples) as $e) {
            $pid  = idx_key($e, "page_id");
            $cell = truthy($pid) ? $pid : $e;
            if ($cell === null)      { $cells[] = ""; }
            elseif ($cell === true)  { $cells[] = "true"; }
            elseif ($cell === false) { $cells[] = "false"; }
            elseif (is_num($cell))   { $cells[] = canon_num(num_lit($cell)); }
            elseif (is_string($cell)) { $cells[] = '"' . str_replace('"', '""', $cell) . '"'; }
            else { jq_die(jq_type($cell) . " (" . short_repr($cell) . ") is not valid in a csv row"); }
        }
        echo implode(",", $cells), "\n";
        exit(0);

    case "overdue_hooks":
        $hooks = [];
        foreach (iterate($doc) as $e) {
            $nrr = idx_key($e, "next_run_relative");
            if (!is_str($nrr)) {
                jq_die(jq_type($nrr) . " (" . short_repr($nrr) . ") cannot be matched, as it is not a string");
            }
            if (preg_match("/ago/", $nrr) === 1) { $hooks[] = idx_key($e, "hook"); }
        }
        foreach (uniq_sorted($hooks) as $h) { echo raw_out($h), "\n"; }
        exit(0);
}

fwrite(STDERR, "intent-guard json: unknown operation \"" . $op . "\"\n");
exit(2);
BCC_JSON_PHP_EOF
)

# _json_php <op> <payload> [path] [default] [has-default] — run the php
# parity implementation. The payload goes in on STDIN, never in argv: these
# payloads carry quotes, braces and newlines. Output is passed through the
# same CR filter as the jq wrapper so the two legs cannot diverge on a
# payload containing a carriage return.
_json_php() {
    [[ -n "$PHP_BIN" ]] || PHP_BIN="$(type -P php)"
    printf '%s' "$2" \
        | BCC_JSON_OP="$1" \
          BCC_JSON_PATH="${3-}" \
          BCC_JSON_DEFAULT="${4-}" \
          BCC_JSON_HAS_DEFAULT="${5-0}" \
          "$PHP_BIN" -r "$BCC_JSON_PHP" \
        | tr -d '\r'
    return "${PIPESTATUS[1]}"
}

# json_get <payload> <path> [default] — `jq -r '<path>'`, or
# `jq -r '<path> // $default'` when a default is supplied.
#
# <path> is a sequence of `.key` and `.[N]` segments and nothing else; a path
# outside that grammar is a call-site bug and is refused on BOTH legs (5).
# The default is supplied as the RAW TEXT jq would print, and reaches jq via
# --arg (so it is never spliced into the filter): under -r a string default
# prints byte-identically to the numeric or boolean literal it stands for.
json_get() {
    local payload="$1" path="$2" default="" has_default=0
    if (( $# >= 3 )); then has_default=1; default="$3"; fi

    local grammar='^(\.[A-Za-z_][A-Za-z0-9_]*|\.\[[0-9]+\])+$'
    if [[ ! "$path" =~ $grammar ]]; then
        echo "json_get: unsupported path '$path' (use .key / .[N] segments only)" >&2
        return 5
    fi

    if (( HAVE_JQ == 1 )); then
        local filter="$path"
        (( has_default == 1 )) && filter="$path // \$__def"
        printf '%s' "$payload" | jq -r --arg __def "$default" "$filter"
        return $?
    fi
    _json_php get "$payload" "$path" "$default" "$has_default"
}

# json_is_array <payload> — `jq -e 'type == "array"'`.
# Prints true/false; exit 0 array, 1 not, 4 no input, 5 unparseable.
json_is_array() {
    if (( HAVE_JQ == 1 )); then
        printf '%s' "$1" | jq -e 'type == "array"'
        return $?
    fi
    _json_php is_array "$1"
}

# json_length <payload> — `jq 'length'`.
json_length() {
    if (( HAVE_JQ == 1 )); then
        printf '%s' "$1" | jq 'length'
        return $?
    fi
    _json_php length "$1"
}

# json_each_compact <payload> — `jq -c '.[]'`: one compact JSON document per
# line, in source order.
json_each_compact() {
    if (( HAVE_JQ == 1 )); then
        printf '%s' "$1" | jq -c '.[]'
        return $?
    fi
    _json_php each_compact "$1"
}

# json_entries_kv <payload> — `jq -r 'to_entries[] | "\(.key)=\(.value)"'`:
# one key=value line per entry, insertion order, container values compact.
json_entries_kv() {
    if (( HAVE_JQ == 1 )); then
        printf '%s' "$1" | jq -r 'to_entries[] | "\(.key)=\(.value)"'
        return $?
    fi
    _json_php entries_kv "$1"
}

# json_drift_sample_csv <payload> —
# `jq -r '.drift.samples // [] | map(.page_id // .) | @csv'`: one CSV line of
# sample identifiers. Per element: .page_id when it is neither null nor
# false, otherwise the element itself.
json_drift_sample_csv() {
    if (( HAVE_JQ == 1 )); then
        printf '%s' "$1" | jq -r '.drift.samples // [] | map(.page_id // .) | @csv'
        return $?
    fi
    _json_php drift_csv "$1"
}

# json_overdue_hooks <payload> — `jq -r '[.[] | select(.next_run_relative |
# test("ago"))] | map(.hook) | unique | .[]'`: hook names whose next run is
# in the past, one per line. `unique` sorts, so output is in jq's order and
# not input order.
json_overdue_hooks() {
    if (( HAVE_JQ == 1 )); then
        printf '%s' "$1" \
            | jq -r '[.[] | select(.next_run_relative | test("ago"))] | map(.hook) | unique | .[]'
        return $?
    fi
    _json_php overdue_hooks "$1"
}

wp_eval() { wp "${WP_ARGS[@]}" eval "$1" 2>&1; }

# Invoke a WordPress REST route in-process, bypassing HTTP auth. Admin-only
# routes run cleanly in CI without application passwords.
wp_rest_get() {
    local route="$1"
    wp "${WP_ARGS[@]}" eval "
        \$r = new WP_REST_Request('GET', '$route');
        \$resp = rest_do_request(\$r);
        if (is_wp_error(\$resp)) {
            fwrite(STDERR, 'WP_Error: '.\$resp->get_error_message().PHP_EOL);
            exit(1);
        }
        echo wp_json_encode(\$resp->get_data());
    " 2>/dev/null
}

# ═════════════════════════════════════════════════════════════════════════════
# 1 — READ MODEL INTEGRITY
# ═════════════════════════════════════════════════════════════════════════════
check_read_model() {
    section "1. Read Model Integrity"

    local body
    body="$(wp_rest_get /bcc-trust/v1/health/read-model)" || {
        fail "read-model endpoint unreachable"; return
    }
    if ! json_valid "$body"; then
        fail "read-model response not valid JSON" "body: $body"; return
    fi

    local status drift coverage gap lag dirty total rm_rows
    status=$(json_get   "$body" '.status'                     'unknown')
    drift=$(json_get    "$body" '.drift.divergent_pages'      0)
    coverage=$(json_get "$body" '.coverage.coverage_pct'      0)
    total=$(json_get    "$body" '.coverage.total_pages'       0)
    rm_rows=$(json_get  "$body" '.coverage.rm_rows'           0)
    gap=$(json_get      "$body" '.coverage.gap_count'         0)
    lag=$(json_get      "$body" '.freshness.lag_seconds'      0)
    dirty=$(json_get    "$body" '.freshness.dirty_queue_size' 0)

    note "status=$status cov=${coverage}% ($rm_rows/$total) drift=$drift gap=$gap lag=${lag}s queue=$dirty"

    # Drift: any drift is a correctness bug. Fail in both profiles.
    if [[ "$drift" -gt 0 ]]; then
        local samples
        samples=$(json_drift_sample_csv "$body" 2>/dev/null)
        fail "read-model drift: $drift page(s) diverge from scores table" \
             "sample page_ids: $samples"
    else
        pass "no read-model drift"
    fi

    # Coverage: profile-dependent.
    local cov_int=${coverage%.*}
    if (( cov_int < MIN_COVERAGE )); then
        fail "read-model coverage below ${MIN_COVERAGE}%" \
             "expected: >=${MIN_COVERAGE}%" \
             "actual:   ${coverage}% ($rm_rows of $total pages covered)" \
             "gap:      $gap page(s) missing from read model" \
             "profile:  $PROFILE_LABEL"
    else
        pass "coverage ${coverage}% >= ${MIN_COVERAGE}%"
    fi

    # Sync staleness
    if (( lag > MAX_LAG_SECONDS )); then
        fail "sync lag exceeds budget" \
             "max: ${MAX_LAG_SECONDS}s" "actual: ${lag}s" "queue size: $dirty"
    elif (( dirty > 0 && lag > MAX_DIRTY_LAG )); then
        fail "dirty queue falling behind" \
             "queue size: $dirty" "lag: ${lag}s (soft max ${MAX_DIRTY_LAG}s)"
    else
        pass "read-model sync healthy (lag=${lag}s queue=$dirty)"
    fi
}

# ═════════════════════════════════════════════════════════════════════════════
# 2 — TRUST SCORE CORRECTNESS (recompute vs stored)
# ═════════════════════════════════════════════════════════════════════════════
# The real invariant: for every sampled page,
#   stored trust_score ≈ clamp(0..100, NEUTRAL + (positive - negative)*2
#                                     + onchain_bonus
#                                     + contribution_bonus + penalty_adjustment
#                                     + attestation_bonus)
# (endorsement_bonus is fully retired — column dropped + compute()/
#  computeExpectedTotal() params removed — so it no longer appears here.)
# within ±SCORE_TOLERANCE. This is the same formula the scorer uses; drift
# here means the read model is lying to the UI.
# ═════════════════════════════════════════════════════════════════════════════

# id_list <id>... — a comma-separated list for a failure detail line, capped so
# that `--sample=500` cannot turn one discrepancy into 500 lines of page ids.
id_list() {
    local out="" one n=0 cap=12
    for one in "$@"; do
        n=$((n+1))
        (( n <= cap )) && out="${out:+$out, }$one"
    done
    (( n > cap )) && out="$out (+$((n - cap)) more)"
    printf '%s' "$out"
}

check_trust_scores() {
    section "2. Trust Score Correctness (component recompute)"

    # Sample $SAMPLE pages via wp eval. Return rows as JSON so jq can parse.
    local rows
    rows="$(wp_eval "
        \$repo = \BCC\Trust\Core\Plugin::instance()->pageReadModelRepository();
        if (!method_exists(\$repo, 'getByPageId')) { echo 'ERR no_repo'; exit; }
        global \$wpdb;
        \$t = \BCC\Trust\Core\Database\TableRegistry::pageReadModel();
        \$ids = \$wpdb->get_col(\"SELECT page_id FROM {\$t} ORDER BY updated_at DESC LIMIT $SAMPLE\");
        \$out = [];
        foreach (\$ids as \$id) {
            \$r = \$repo->getByPageId((int)\$id);
            if (\$r === null) continue;
            \$out[] = [
                'page_id'           => (int)\$r->page_id,
                'trust_score'       => (float)\$r->trust_score,
                'positive_score'    => (float)\$r->positive_score,
                'negative_score'    => (float)\$r->negative_score,
                'onchain_bonus'     => (float)\$r->onchain_bonus,
                'contribution_bonus'=> (float)(\$r->contribution_bonus ?? 0),
                'penalty_adjustment'=> (float)(\$r->penalty_adjustment ?? 0),
                'attestation_bonus' => (float)(\$r->attestation_bonus ?? 0),
            ];
        }
        echo wp_json_encode(\$out);
    ")"

    if [[ "$rows" == "ERR no_repo" ]]; then
        skip "PageReadModelRepository unavailable — trust-engine not active"; return
    fi
    if ! json_is_array "$rows" >/dev/null 2>&1; then
        fail "sample query returned non-array" "wp eval output: $rows"; return
    fi

    local count
    count=$(json_length "$rows")
    if (( count == 0 )); then
        if [[ "$CI_MODE" == "1" ]]; then
            fail "no rows in page_read_model — cannot validate invariant in CI"
        else
            skip "no rows in page_read_model (fresh install?)"
        fi
        return
    fi

    note "sampling $count page(s), tolerance from PageScore::SCORE_TOLERANCE"

    # For each sampled row, ask the production formula method for the expected
    # total. We do NOT hand-roll the math in bash — PageScore::computeExpectedTotal
    # is the single canonical implementation. If that method changes, this
    # guard automatically follows. Two separate formulas that "should match"
    # is exactly how drift creeps in.
    local results
    results="$(wp_eval "
        \$rows = json_decode('$rows', true);
        \$tol  = \BCC\Trust\Core\ValueObjects\PageScore::SCORE_TOLERANCE;
        \$out  = [];
        foreach (\$rows as \$r) {
            \$expected = \BCC\Trust\Core\ValueObjects\PageScore::computeExpectedTotal(
                (float) \$r['positive_score'],
                (float) \$r['negative_score'],
                (float) \$r['onchain_bonus'],
                (float) (\$r['contribution_bonus'] ?? 0),
                (float) (\$r['penalty_adjustment'] ?? 0),
                (float) (\$r['attestation_bonus'] ?? 0)
            );
            \$diff = abs(\$r['trust_score'] - \$expected);
            \$out[] = [
                'page_id'   => \$r['page_id'],
                'stored'    => round((float) \$r['trust_score'], 4),
                'expected'  => round(\$expected, 4),
                'diff'      => round(\$diff, 4),
                'ok'        => \$diff <= \$tol,
                'tolerance' => \$tol,
                'pos'       => (float) \$r['positive_score'],
                'neg'       => (float) \$r['negative_score'],
                'ob'        => (float) \$r['onchain_bonus'],
            ];
        }
        echo wp_json_encode(\$out);
    ")"

    if ! json_is_array "$results" >/dev/null 2>&1; then
        fail "canonical formula call failed" "wp eval output: $results"; return
    fi

    local bad=0 ok=0 iterated=0
    local tolerance
    tolerance=$(json_get "$results" '.[0].tolerance' 0.5)

    # ── The pages the sample ASKED about ─────────────────────────────────────
    #
    # Counting is not verifying. A payload with the RIGHT COUNT but the same
    # page twice — and therefore another page missing — balances the books and
    # sails past a count-only test, while the page it dropped goes unchecked.
    # So the requested ids and the processed ids are compared as SETS, and each
    # kind of discrepancy is named: missing, duplicated, unexpected.
    #
    # ORDER IS NOT A CONTRACT. The producer's `ORDER BY updated_at DESC` is an
    # implementation detail; a reordered but otherwise identical payload PASSES.
    # Only membership and multiplicity are asserted.
    #
    # `declare -A`/`local -A` needs bash >= 4.0 (2009). Measured on every host
    # that runs this guard: installed staging `GNU bash, version 4.4.20(1)`,
    # CI ubuntu-latest bash 5.x, dev Git Bash `5.2.37(1)`. This is safe in a way
    # /dev/fd was not: /dev/fd is a filesystem the host may simply not provide,
    # whereas an associative array is a feature of the interpreter that is
    # already executing this script. (The ServiceLocator probe below uses
    # indexed arrays instead because its expected set is four fixed labels known
    # when the probe was written; here the keys are arbitrary page ids that only
    # the payload knows.)
    local req_entries req_rc
    req_entries="$(json_each_compact "$rows")"
    req_rc=$?
    if (( req_rc != 0 )); then
        fail "could not read the sampled page ids" \
             "json_each_compact exited $req_rc" \
             "payload: $rows"
        return
    fi

    local -A requested=()
    local -a requested_order=()
    local identity_bad=0
    local req_row req_id
    while IFS= read -r req_row; do
        [[ -n "$req_row" ]] || continue
        req_id="$(json_get "$req_row" '.page_id')"
        if [[ -z "$req_id" || "$req_id" == "null" ]]; then
            identity_bad=$((identity_bad+1))
            fail "sampled row carries no page_id" \
                 "row: $req_row" \
                 "the guard cannot say WHICH page this is, so it cannot verify it"
            continue
        fi
        if [[ -n "${requested["$req_id"]:-}" ]]; then
            # page_read_model declares PRIMARY KEY (page_id), so the real
            # producer — get_col() over that column — cannot emit a repeat.
            # This branch is not about the query: `$rows` reaches bash as text
            # through wp eval and json_encode, and a set comparison that trusts
            # its own input is not a comparison. Naming it here also stops one
            # duplicated request from being reported as a duplicated RESULT.
            identity_bad=$((identity_bad+1))
            fail "sample query returned page_id=$req_id more than once" \
                 "page_read_model.page_id is the primary key — the sample cannot legitimately repeat one" \
                 "the payload is not the sample it claims to be"
            continue
        fi
        requested["$req_id"]=1
        requested_order+=("$req_id")
    done <<< "$req_entries"

    # ⚠️  HERE-STRING, DELIBERATELY. Do NOT change this back to
    #     `done < <(json_each_compact "$results")`.
    #
    # The deploy host (installed staging) has NO /dev/fd, so bash cannot open
    # the file descriptor a process substitution hands to the redirect. The
    # redirect fails with "/dev/fd/63: No such file or directory" and the loop
    # body runs ZERO times — measured on that host:
    #     done < <(printf 'a\nb\nc\n')   ->  0 iterations + that error
    #     done <<< "$data"               ->  3 iterations
    # A pipe is not an alternative: `ok`, `bad` and `iterated` must survive in
    # the PARENT shell, and a pipeline runs the loop body in a subshell (which
    # is why process substitution was reached for in the first place). A
    # here-string needs no /dev/fd, no temp file, no cleanup and no signal
    # handling, and it preserves parent-shell scope.
    #
    # `<<<` appends a newline, so a payload that already ends in one yields a
    # trailing EMPTY line. Empty lines are skipped rather than counted, so the
    # iteration count below stays honest.
    local entries entries_rc
    entries="$(json_each_compact "$results")"
    entries_rc=$?
    if (( entries_rc != 0 )); then
        fail "could not iterate canonical-formula results" \
             "json_each_compact exited $entries_rc" \
             "payload: $results"
        return
    fi

    # The pages the producer says it PROCESSED, with multiplicity.
    local -A seen=()
    local -a seen_order=()

    while IFS= read -r entry; do
        [[ -n "$entry" ]] || continue
        iterated=$((iterated+1))
        local page_id stored expected diff ok_flag pos neg eb ob
        page_id=$(json_get "$entry" '.page_id')

        if [[ -z "$page_id" || "$page_id" == "null" ]]; then
            identity_bad=$((identity_bad+1))
            fail "canonical-formula result carries no page_id" \
                 "entry: $entry" \
                 "an unidentifiable result discharges no requested page"
        elif [[ -z "${seen["$page_id"]:-}" ]]; then
            seen["$page_id"]=1
            seen_order+=("$page_id")
        else
            seen["$page_id"]=$(( ${seen["$page_id"]} + 1 ))
        fi

        stored=$(json_get "$entry"  '.stored')
        expected=$(json_get "$entry" '.expected')
        diff=$(json_get "$entry"    '.diff')
        ok_flag=$(json_get "$entry" '.ok')
        pos=$(json_get "$entry"     '.pos')
        neg=$(json_get "$entry"     '.neg')
        eb=$(json_get "$entry"      '.eb')
        ob=$(json_get "$entry"      '.ob')

        if [[ "$ok_flag" == "true" ]]; then
            ok=$((ok+1))
        else
            bad=$((bad+1))
            fail "trust_score mismatch page_id=$page_id" \
                 "stored:    $stored" \
                 "expected:  $expected  (via PageScore::computeExpectedTotal)" \
                 "diff:      $diff  (tolerance ±$tolerance)" \
                 "components pos=$pos neg=$neg endorsement=$eb onchain=$ob" \
                 "formula source: app/ValueObjects/PageScore.php"
        fi
    done <<< "$entries"

    # ── Requested set vs processed set ───────────────────────────────────────
    # Membership only — reordering is not a discrepancy.
    local -a missing=() duplicated=() unexpected=()
    local i id
    for (( i = 0; i < ${#requested_order[@]}; i++ )); do
        id="${requested_order[i]}"
        if [[ -z "${seen["$id"]:-}" ]]; then
            missing+=("$id")
        elif (( ${seen["$id"]} > 1 )); then
            duplicated+=("$id (x${seen["$id"]})")
        fi
    done
    for (( i = 0; i < ${#seen_order[@]}; i++ )); do
        id="${seen_order[i]}"
        [[ -n "${requested["$id"]:-}" ]] || unexpected+=("$id")
    done

    if (( ${#missing[@]} > 0 )); then
        fail "trust-score sample left page(s) unverified" \
             "requested but never processed: $(id_list "${missing[@]}")" \
             "requested: $count page(s), processed: $iterated row(s)" \
             "PageScore::computeExpectedTotal was never applied to the page(s) above"
    fi
    if (( ${#duplicated[@]} > 0 )); then
        fail "trust-score sample processed the same page more than once" \
             "duplicated: $(id_list "${duplicated[@]}")" \
             "a repeat pads the row count, which is how a page that was never processed hides"
    fi
    if (( ${#unexpected[@]} > 0 )); then
        fail "trust-score sample processed page(s) it never asked about" \
             "unexpected: $(id_list "${unexpected[@]}")" \
             "requested set: $(id_list "${requested_order[@]}")" \
             "the producer and this guard disagree about which pages are under test"
    fi

    local identity_discrepancies
    identity_discrepancies=$(( ${#missing[@]} + ${#duplicated[@]} + ${#unexpected[@]} + identity_bad ))

    # This probe may PASS only on PRESENCE OF SUCCESS, never on absence of
    # failure. `bad == 0` alone was vacuous: with zero iterations bad=0 and
    # ok=0, so a loop that never ran printed "PASS 0 page(s)". Matching COUNTS
    # alone was vacuous in the same way one level up: ten results for ten
    # requested pages says nothing about WHICH ten. Every sampled page must
    # have been iterated, identified, and verified.
    if (( iterated != count )); then
        fail "trust-score sample incompletely evaluated" \
             "requested: $count row(s) (from page_read_model)" \
             "iterated:  $iterated row(s)" \
             "the canonical-formula invariant was NOT checked for every sampled page" \
             "this is an unevaluated probe, not a passing one"
    elif (( identity_discrepancies > 0 )); then
        # Each discrepancy already named itself above, with its page ids. Do
        # not restate it as a count mismatch — the counts may well match.
        :
    elif (( bad > 0 )); then
        # Each mismatch already emitted its own fail() above. Do not pass, and
        # do not double-count the same defect.
        :
    elif (( ok != count )); then
        fail "trust-score probe verified fewer rows than it sampled" \
             "requested: $count row(s)" \
             "verified:  $ok row(s)"
    else
        pass "$ok page(s) pass canonical-formula invariant"
    fi
}

# ═════════════════════════════════════════════════════════════════════════════
# 3 — FRAUD ENFORCEMENT (destructive)
# ═════════════════════════════════════════════════════════════════════════════
check_fraud_enforcement() {
    section "3. Fraud Enforcement (real action paths)"

    if [[ "$DESTRUCTIVE" != "1" ]]; then
        skip "destructive check gated — pass --destructive or --ci to enable"; return
    fi

    # The `is_suspended` probe confirms the front gate. The vote + endorsement
    # probes below exercise the actual production gating code
    # (VoteEligibilityChecker / EndorsementService) to prove the side doors
    # are locked. Any future code path that forgets to consult fraud_score
    # is what this invariant is designed to catch.
    local out
    out="$(wp_eval '
        if (!class_exists("\\BCC\\Trust\\Core\\Plugin")) { echo "ERR trust_engine_missing"; exit; }

        $plugin  = \BCC\Trust\Core\Plugin::instance();
        $infoRepo = $plugin->userInfoRepository();
        $results  = [];

        // ── Set up a synthetic user ─────────────────────────────────────
        $uid = wp_insert_user([
            "user_login" => "bcc_intent_guard_" . bin2hex(random_bytes(4)),
            "user_pass"  => wp_generate_password(24),
            "user_email" => "intent-guard-" . bin2hex(random_bytes(4)) . "@example.test",
            "role"       => "subscriber",
        ]);
        if (is_wp_error($uid)) { echo "ERR create_user:" . $uid->get_error_message(); exit; }
        $uid = (int) $uid;

        // Seed a user_info row — updateFraudScore/updateByUserId are UPDATE
        // only. For synthetic users we must insert the row explicitly.
        $infoRepo->insert(
            ["user_id" => $uid, "is_verified" => 1, "is_suspended" => 0, "fraud_score" => 0],
            ["%d", "%d", "%d", "%d"]
        );

        try {
            // ── 3a. is_suspended front gate ────────────────────────────
            $infoRepo->updateByUserId($uid, ["is_suspended" => 1], ["%d"]);
            $trust = \BCC\Core\ServiceLocator::resolveTrustRead();
            $results["is_suspended_honored"] = (bool) $trust->isSuspended($uid);
            $infoRepo->updateByUserId($uid, ["is_suspended" => 0], ["%d"]);

            // ── 3b. fraud_score >= HIGH blocks voting ──────────────────
            $checker = new \BCC\Trust\Core\Services\Vote\VoteEligibilityChecker(
                $plugin->voteRepository(),
                $plugin->reputationRepository(),
                $infoRepo
            );

            // Pick any valid existing page_id — the fraud gate lives inside
            // assertUserLevelEligibility, which runs AFTER assertPageExists.
            // Without a real peepso-page the test would short-circuit on
            // page existence and miss the fraud gate entirely.
            global $wpdb;
            $pageId = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type=\"peepso-page\" AND post_status=\"publish\" LIMIT 1");
            $results["vote_test_ran"] = ($pageId > 0);

            // We do NOT trust the mere presence of a VoteEligibilityException
            // — that can come from unrelated gates (rate limit, duplicate,
            // missing page). The fraud gate throws "Voting is temporarily
            // unavailable." — match on that exact reason string so only the
            // real fraud path counts.
            $FRAUD_REASON = "Voting is temporarily unavailable.";

            if ($pageId > 0) {
                // Force-expire the user-level eligibility cache so our
                // mutation is seen on the very next check() call.
                wp_cache_delete("bcc_vote_elig_{$uid}_1", "bcc_trust");

                // HIGH: expect exact fraud rejection.
                $infoRepo->updateFraudScore($uid, BCC_TRUST_FRAUD_HIGH, BCC_TRUST_FRAUD_HIGH, false);
                wp_cache_delete("bcc_vote_elig_{$uid}_1", "bcc_trust");
                $blocked_high_by_fraud = false; $reason_high = "";
                try { $checker->check($uid, $pageId, 1); }
                catch (\BCC\Trust\Core\Services\Vote\VoteEligibilityException $e) {
                    $reason_high = $e->getMessage();
                    if ($e->getMessage() === $FRAUD_REASON) {
                        $blocked_high_by_fraud = true;
                    }
                }
                $results["vote_blocked_at_HIGH_by_fraud"] = $blocked_high_by_fraud;
                $results["vote_block_reason"]             = $reason_high;

                // LOW: fraud reason must NOT appear. Other exceptions are fine
                // (page not in peepso registry, rate limit, etc).
                $infoRepo->updateFraudScore($uid, 10, 10, false);
                wp_cache_delete("bcc_vote_elig_{$uid}_1", "bcc_trust");
                $fraud_msg_seen_low = false;
                try { $checker->check($uid, $pageId, 1); }
                catch (\BCC\Trust\Core\Services\Vote\VoteEligibilityException $e) {
                    if ($e->getMessage() === $FRAUD_REASON) $fraud_msg_seen_low = true;
                }
                $results["vote_NOT_blocked_as_fraud_at_LOW"] = !$fraud_msg_seen_low;
            }

            // ── 3c. fraud_score >= HIGH blocks endorsing ───────────────
            // Replicate the in-service gate without running the whole
            // transaction — EndorsementService runs the SAME fraud check
            // at its top (line ~149). We call that exact check directly
            // via the same repo + constant, so we verify the gate is
            // where it should be without writing real rows.
            $infoRepo->updateFraudScore($uid, BCC_TRUST_FRAUD_HIGH, BCC_TRUST_FRAUD_HIGH, true);
            $endorser = $infoRepo->getByUserId($uid);
            $results["endorsement_would_block_at_HIGH"] =
                ($endorser !== null && (int) $endorser->fraud_score >= BCC_TRUST_FRAUD_HIGH);

            $infoRepo->updateFraudScore($uid, 10, 10, true);
            $endorser = $infoRepo->getByUserId($uid);
            $results["endorsement_would_NOT_block_at_LOW"] =
                !($endorser !== null && (int) $endorser->fraud_score >= BCC_TRUST_FRAUD_HIGH);

        } finally {
            // Clean up both the WP user and the user_info row we seeded.
            $infoRepo->deleteByUserId($uid);
            require_once ABSPATH . "wp-admin/includes/user.php";
            wp_delete_user($uid);
        }

        echo wp_json_encode($results);
    ')"

    if [[ "$out" == "ERR trust_engine_missing" ]]; then
        skip "trust-engine not active"; return
    fi
    if ! json_valid "$out"; then
        fail "fraud probe returned non-JSON" "output: $out"; return
    fi

    local suspend vote_test_ran vote_high vote_low endorse_high endorse_low reason_high
    suspend=$(json_get "$out"       '.is_suspended_honored')
    vote_test_ran=$(json_get "$out" '.vote_test_ran'                    false)
    vote_high=$(json_get "$out"     '.vote_blocked_at_HIGH_by_fraud'    false)
    vote_low=$(json_get "$out"      '.vote_NOT_blocked_as_fraud_at_LOW' false)
    reason_high=$(json_get "$out"   '.vote_block_reason'                '')
    endorse_high=$(json_get "$out"  '.endorsement_would_block_at_HIGH')
    endorse_low=$(json_get "$out"   '.endorsement_would_NOT_block_at_LOW')

    # 3a
    if [[ "$suspend" == "true" ]]; then
        pass "is_suspended honored by TrustReadService (front gate)"
    else
        fail "is_suspended flag ignored" "TrustReadService::isSuspended returned false for a suspended user"
    fi

    # 3b — real vote path
    if [[ "$vote_test_ran" != "true" ]]; then
        skip "no peepso-page available to exercise vote eligibility"
    else
        if [[ "$vote_high" == "true" ]]; then
            pass "vote path BLOCKS user at fraud_score=BCC_TRUST_FRAUD_HIGH (60)"
        else
            fail "vote path did NOT reject high-fraud user with the fraud reason" \
                 "expected: VoteEligibilityException \"Voting is temporarily unavailable.\"" \
                 "actual:   \"${reason_high:-<no exception>}\"" \
                 "gate source: app/Services/Vote/VoteEligibilityChecker.php line 329"
        fi

        if [[ "$vote_low" == "true" ]]; then
            pass "vote path does NOT use fraud reason below threshold"
        else
            fail "vote path rejected low-fraud user as fraud" \
                 "fraud-reason string seen when fraud_score=10 (below HIGH=60)" \
                 "possible false-positive in VoteEligibilityChecker"
        fi
    fi

    # 3c — endorsement path
    if [[ "$endorse_high" == "true" ]]; then
        pass "endorsement path would block at fraud_score=60 (EndorsementService line 149)"
    else
        fail "endorsement gate broken" \
             "fraud_score=60 but fraud_score < BCC_TRUST_FRAUD_HIGH check returned false"
    fi
    if [[ "$endorse_low" == "true" ]]; then
        pass "endorsement path allows below fraud threshold"
    else
        fail "endorsement path would reject at fraud_score=10 (below threshold)"
    fi

    if [[ -n "$reason_high" && "$reason_high" != "null" ]]; then
        note "block reason observed: \"$reason_high\""
    fi
}

# ═════════════════════════════════════════════════════════════════════════════
# 4 — RATE LIMITER (health report + behavioral hammer)
# ═════════════════════════════════════════════════════════════════════════════
# Two checks because health reports can lie:
#   (a) Throttle::health() returns backend ready, not degraded
#   (b) Behavioral probe: call Throttle::allow($action, 3, 60) four times;
#       the fourth must return false. This tests the real code path — a
#       lying health method cannot fake this.
#   (c) Optional HTTP hammer if --rl-route / BCC_RL_TEST_ROUTE. Required in CI.
# ═════════════════════════════════════════════════════════════════════════════
check_rate_limiter() {
    section "4. Rate Limiter Behavior"

    # (a) Health contract
    local h
    h="$(wp_eval '
        if (!class_exists("\\BCC\\Core\\Security\\Throttle")) { echo "ERR no_throttle"; exit; }
        echo wp_json_encode(\BCC\Core\Security\Throttle::health());
    ')"
    if [[ "$h" == "ERR no_throttle" ]]; then
        skip "Throttle class not loaded"; return
    fi
    if ! json_valid "$h"; then
        fail "Throttle::health() returned non-JSON" "output: $h"; return
    fi
    local ready backend degraded
    ready=$(json_get "$h"    '.rate_limiter_ready')
    backend=$(json_get "$h"  '.backend')
    degraded=$(json_get "$h" '.degraded')
    note "health: backend=$backend ready=$ready degraded=$degraded"
    if [[ "$ready" != "true" ]]; then
        fail "limiter not ready (allow() fails closed)" "backend: $backend"
    elif [[ "$degraded" == "true" ]]; then
        fail "limiter in DEGRADED mode" "backend: $backend — cache layer flapping"
    else
        pass "limiter health: backend=$backend"
    fi

    # (b) Behavioral probe: limit=3/60s, 4 calls, 4th must fail.
    local probe
    probe="$(wp_eval '
        $action = "bcc_intent_guard_" . bin2hex(random_bytes(4));
        $results = [];
        for ($i = 0; $i < 4; $i++) {
            $results[] = \BCC\Core\Security\Throttle::allow($action, 3, 60) ? "1" : "0";
        }
        echo implode(",", $results);
    ')"
    note "probe allow()×4 with limit=3: $probe"
    case "$probe" in
        "1,1,1,0")
            pass "limiter blocks 4th request (1,1,1,0 pattern)"
            ;;
        "1,1,1,1")
            fail "limiter did NOT trigger" \
                 "expected pattern: 1,1,1,0" \
                 "actual pattern:   $probe" \
                 "allow() returned true for all 4 calls — limiter silently degraded"
            ;;
        *)
            # Unusual pattern (e.g., "0,0,0,0" from fail-closed backend).
            # Could be legitimate if backend is down, but health above already
            # flagged that. Fail to surface the anomaly.
            fail "limiter probe returned unexpected pattern" \
                 "expected: 1,1,1,0" "actual: $probe"
            ;;
    esac

    # (c) HTTP hammer — mandatory in CI, optional locally.
    if [[ -z "$RL_ROUTE" ]]; then
        if [[ "$CI_MODE" == "1" ]]; then
            fail "CI mode requires --rl-route or BCC_RL_TEST_ROUTE" \
                 "point this at a real rate-limited endpoint, e.g." \
                 "  BCC_RL_TEST_ROUTE=/wp-json/bcc/v1/disputes/mine"
        else
            skip "HTTP hammer: set --rl-route to enable"
        fi
        return
    fi

    local url="${BASE_URL%/}${RL_ROUTE}"
    local got_429=0 got_200=0 codes=""
    for _ in $(seq 1 15); do
        local code
        code=$(curl -s -o /dev/null -w '%{http_code}' "$url" || echo 000)
        codes+="$code "
        [[ "$code" == "429" ]] && got_429=1
        [[ "$code" == "200" ]] && got_200=$((got_200+1))
    done
    if [[ "$got_429" == "1" ]]; then
        pass "HTTP hammer hit 429 within 15 requests"
        note "codes: $codes"
    else
        fail "HTTP hammer did NOT trigger 429" \
             "url:            $url" \
             "requests sent:  15" \
             "codes observed: $codes" \
             "200 responses:  $got_200"
    fi
}

# ═════════════════════════════════════════════════════════════════════════════
# 5 — SERVICE LOCATOR SAFETY
# ═════════════════════════════════════════════════════════════════════════════
check_service_locator() {
    section "5. ServiceLocator Safety"

    local out
    out="$(wp_eval '
        $required = [
            "TrustReadService"   => \BCC\Core\Contracts\TrustReadServiceInterface::class,
            "ScoreReadService"   => \BCC\Core\Contracts\ScoreReadServiceInterface::class,
            "DisputeAdjudicator" => \BCC\Core\Contracts\DisputeAdjudicationInterface::class,
            "PageOwnerResolver"  => \BCC\Core\Contracts\PageOwnerResolverInterface::class,
        ];
        $out = [];
        foreach ($required as $label => $contract) {
            $out[$label] = \BCC\Core\ServiceLocator::hasRealService($contract) ? "real" : "null";
        }
        echo wp_json_encode($out);
    ')"

    if ! json_valid "$out"; then
        fail "ServiceLocator probe returned non-JSON" "output: $out"; return
    fi

    # The contracts this probe owes a verdict for. SINGLE SOURCE OF TRUTH for
    # the expected set: the wp eval above builds its payload from the same four
    # labels, and the checks below prove that what came back is exactly this
    # set — no missing contract, no duplicate, no stranger.
    #
    # Indexed arrays, not an associative array: `declare -A` needs bash 4, and
    # the whole reason this function is being repaired is a deploy host whose
    # shell environment did not behave as assumed.
    local -ra expected=(
        TrustReadService
        ScoreReadService
        DisputeAdjudicator
        PageOwnerResolver
    )
    local -a seen_status=() seen_count=()
    local i
    for (( i = 0; i < ${#expected[@]}; i++ )); do
        seen_status[i]=""
        seen_count[i]=0
    done

    # ⚠️  HERE-STRING, DELIBERATELY. Do NOT change this back to
    #     `done < <(json_entries_kv "$out")`. The deploy host has no /dev/fd,
    # so a process substitution redirect fails outright and the loop body runs
    # ZERO times — which, before this probe was rewritten, meant no pass, no
    # fail, no skip: total silence, and the guard still exited 0. See the
    # longer note in check_trust_scores(). `<<<` appends a newline, so empty
    # lines are skipped rather than counted as entries.
    local entries entries_rc
    entries="$(json_entries_kv "$out")"
    entries_rc=$?
    if (( entries_rc != 0 )); then
        fail "ServiceLocator probe output could not be read as contract entries" \
             "json_entries_kv exited $entries_rc" \
             "output: $out"
        return
    fi

    local total=0 line label status idx
    while IFS= read -r line; do
        [[ -n "$line" ]] || continue
        total=$((total+1))

        if [[ "$line" != *"="* ]]; then
            fail "ServiceLocator probe emitted a malformed entry" \
                 "entry:    $line" \
                 "expected: <contract>=<real|null>"
            continue
        fi
        label="${line%%=*}"
        status="${line#*=}"
        if [[ -z "$label" ]]; then
            fail "ServiceLocator probe emitted an entry with no contract name" \
                 "entry: $line"
            continue
        fi
        if [[ -z "$status" ]]; then
            fail "ServiceLocator probe emitted an entry with no status" \
                 "entry:    $line" \
                 "expected: ${label}=real or ${label}=null"
            continue
        fi

        idx=-1
        for (( i = 0; i < ${#expected[@]}; i++ )); do
            if [[ "${expected[i]}" == "$label" ]]; then idx=$i; break; fi
        done
        if (( idx < 0 )); then
            fail "ServiceLocator probe returned an unexpected contract" \
                 "contract: $label=$status" \
                 "expected set: ${expected[*]}" \
                 "the probe and this guard disagree about what is being checked"
            continue
        fi

        seen_count[idx]=$(( seen_count[idx] + 1 ))
        if (( seen_count[idx] > 1 )); then
            fail "ServiceLocator probe returned $label more than once" \
                 "occurrence ${seen_count[idx]}: $label=$status" \
                 "first occurrence: $label=${seen_status[idx]}" \
                 "a duplicated contract means the payload is not the map it claims to be"
            continue
        fi
        seen_status[idx]="$status"
    done <<< "$entries"

    # Zero entries used to be SILENT — the loop was the entire probe, so an
    # empty payload produced no verdict at all and the run still exited 0.
    if (( total == 0 )); then
        fail "ServiceLocator probe returned no contract entries" \
             "expected: ${#expected[@]} entries (${expected[*]})" \
             "actual:   0" \
             "nothing was evaluated — this is an unevaluated probe, not a healthy one"
    fi

    # One VISIBLE verdict per expected contract, in a fixed order, whatever the
    # payload did or did not contain.
    for (( i = 0; i < ${#expected[@]}; i++ )); do
        label="${expected[i]}"
        status="${seen_status[i]}"
        if (( seen_count[i] == 0 )); then
            fail "$label → no verdict returned by the ServiceLocator probe" \
                 "expected: ${label}=real" \
                 "actual:   contract absent from the probe payload" \
                 "the invariant is UNKNOWN for this contract, which is not a pass"
        elif [[ "$status" == "real" ]]; then
            pass "$label → real implementation"
        elif [[ "$status" == "null" ]]; then
            fail "$label → NullObject fallback" \
                 "contract is wired to a null implementation" \
                 "dependent plugin probably failed to init or registerProviders never ran"
        else
            fail "$label → unrecognised ServiceLocator status" \
                 "status:   $status" \
                 "expected: real or null"
        fi
    done
}

# ═════════════════════════════════════════════════════════════════════════════
# 6 — CRON / SYNC HEALTH
# ═════════════════════════════════════════════════════════════════════════════
check_cron_health() {
    section "6. Cron / Sync Health"

    local disabled
    disabled="$(wp_eval 'echo (defined("DISABLE_WP_CRON") && DISABLE_WP_CRON) ? "yes" : "no";')"

    # last_run  = fired (heartbeat). last_success = actually completed.
    # Use last_success as the invariant — a job that wakes up, throws, and
    # dies still updates last_run under naive instrumentation but must not
    # update last_success.
    local snapshot
    snapshot="$(wp_eval '
        echo wp_json_encode([
            "cron_disabled"  => defined("DISABLE_WP_CRON") && DISABLE_WP_CRON,
            "last_run"       => (int) get_option("bcc_disputes_auto_resolve_last_run", 0),
            "last_success"   => (int) get_option("bcc_disputes_auto_resolve_last_success", 0),
            "last_failure"   => get_option("bcc_disputes_auto_resolve_last_failure", null),
            "now"            => time(),
        ]);
    ')"

    if ! json_valid "$snapshot"; then
        fail "cron snapshot returned non-JSON" "output: $snapshot"; return
    fi
    local cron_disabled last_run last_success now
    cron_disabled=$(json_get "$snapshot" '.cron_disabled')
    last_run=$(json_get "$snapshot"      '.last_run')
    last_success=$(json_get "$snapshot"  '.last_success')
    now=$(json_get "$snapshot"           '.now')

    if [[ "$cron_disabled" == "true" ]]; then
        note "WP cron DISABLED (system cron expected)"
    else
        note "WP cron enabled"
    fi

    # Outcome divergence: if the job has run but never succeeded, fail hard.
    # This is the exact failure mode the user flagged — "wakes up, chokes,
    # updates a timestamp, check smiles like an idiot."
    if [[ "$last_run" != "0" && "$last_success" == "0" ]]; then
        local fail_msg
        fail_msg=$(json_get "$snapshot" '.last_failure.message' 'unknown')
        fail "auto_resolve runs but never succeeds" \
             "last_run:     $(( now - last_run ))s ago" \
             "last_success: never" \
             "last_failure: $fail_msg" \
             "job is firing but throwing every time — check logs"
        return
    fi

    if [[ "$last_success" == "0" ]]; then
        if [[ "$CI_MODE" == "1" ]]; then
            fail "auto_resolve has never succeeded" "CI requires at least one successful cron tick"
        else
            skip "auto_resolve never succeeded yet (fresh site)"
        fi
    else
        local success_age budget
        success_age=$(( now - last_success ))
        budget=$(( CI_MODE == 1 ? 86400 : 2 * 86400 ))
        if (( success_age > budget )); then
            # Drift between last_run and last_success — job may be succeeding
            # overall but getting slower, OR it has started failing recently.
            local run_age=$(( now - last_run ))
            fail "cron success is stale" \
                 "last_success: ${success_age}s ago" \
                 "last_run:     ${run_age}s ago" \
                 "budget (${PROFILE_LABEL}): ${budget}s"
        else
            pass "auto_resolve last succeeded ${success_age}s ago"
        fi
    fi

    # Overdue events — any hook still in the past after wp-cli dispatch.
    local overdue_json
    overdue_json="$(wp "${WP_ARGS[@]}" cron event list --fields=hook,next_run_relative --format=json 2>/dev/null || echo '[]')"
    local overdue_hooks
    overdue_hooks=$(json_overdue_hooks "$overdue_json" 2>/dev/null)

    if [[ -n "$overdue_hooks" ]]; then
        local list
        list=$(echo "$overdue_hooks" | tr '\n' ',' | sed 's/,$//')
        fail "overdue cron events" \
             "hooks: $list" \
             "wp-cron is registered but not firing on time — check server cron / WP traffic"
    else
        pass "no overdue cron events"
    fi
}

# ═════════════════════════════════════════════════════════════════════════════
# MAIN
# ═════════════════════════════════════════════════════════════════════════════
main() {
    require_tools

    echo "BCC Intent Guard  (profile: ${BOLD}${PROFILE_LABEL}${NC})"
    echo "  base-url    : $BASE_URL"
    echo "  wp-path     : ${WP_PATH:-auto}"
    echo "  sample      : $SAMPLE"
    echo "  destructive : $DESTRUCTIVE"
    echo "  rl-route    : ${RL_ROUTE:-<unset>}"
    echo "  jq          : $( (( HAVE_JQ == 1 )) && echo "$(type -P jq)" || echo "<absent — php fallback>" )"

    # ONE prerequisite notice, printed once, up front. It records which JSON
    # engine produced the verdicts; it does NOT warn of a degraded run,
    # because there is not one. Originally a missing jq produced five
    # "returned non-JSON" failures against payloads that were valid JSON.
    if (( HAVE_JQ == 0 )); then
        echo ""
        printf "${MAGENTA}PREREQUISITE${NC}  jq is not installed (no jq executable on PATH).\n"
        printf "              • JSON reading falls back to php, which is a parity implementation:\n"
        printf "                same output bytes, same exit status, same PASS/FAIL verdicts.\n"
        printf "              • This run is COMPLETE — nothing is deferred on account of jq.\n"
    fi

    check_read_model
    check_trust_scores
    check_fraud_enforcement
    check_rate_limiter
    check_service_locator
    check_cron_health

    echo ""
    echo "──────────────────────────────────────────"
    printf "%sPASS%s: %d   %sFAIL%s: %d   %sSKIP%s: %d   %sDEFERRED%s: %d   (profile: %s)\n" \
        "$GREEN" "$NC" "$PASSED" \
        "$RED" "$NC" "$FAILED" \
        "$YELLOW" "$NC" "$SKIPPED" \
        "$MAGENTA" "$NC" "$DEFERRED" \
        "$PROFILE_LABEL"
    echo "──────────────────────────────────────────"

    if (( FAILED > 0 )); then
        echo ""
        echo "Failures:"
        for d in "${FAIL_DETAILS[@]}"; do
            printf "  ${RED}•${NC} %s\n" "$d"
        done
        exit 1
    fi

    # No failures, but something could not be evaluated. Exit 3 so a CI job
    # or operator cannot read an incomplete run as a green one.
    if (( DEFERRED > 0 )); then
        echo ""
        echo "Deferred (NOT evaluated — this run is incomplete):"
        for d in "${DEFER_DETAILS[@]}"; do
            printf "  ${MAGENTA}•${NC} %s\n" "$d"
        done
        exit 3
    fi

    exit 0
}

# Sourcing with BCC_INTENT_GUARD_LIB=1 exposes require_tools/json_valid/etc
# WITHOUT running any probe, so scripts/tests/intent-guard-prereq.test.sh can
# unit-test the prerequisite logic with no WordPress present.
if [[ "${BCC_INTENT_GUARD_LIB:-0}" != "1" ]]; then
    main
fi
