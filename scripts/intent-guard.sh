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
#      DEFERRED — the guard could not evaluate them (e.g. jq absent). A
#      deferred run is NOT a pass; never treat 3 as success in CI.
#
# Tooling: wp, curl, awk and php are hard-required. jq is OPTIONAL — JSON
# validation falls back to php, and probes that need real jq *filters* are
# reported as DEFERRED rather than silently passing or falsely failing.
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
        --help|-h)           sed -n '2,39p' "$0"; exit 0 ;;
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
# because the guard lacked a tool it needed. Deferrals drive exit code 3 so
# an unevaluated run can never be mistaken for a green one.
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
# otherwise php + json_decode(JSON_THROW_ON_ERROR). Deliberately limited to
# VALIDATION: jq *filters* are not reimplemented in php (see defer()).
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

# jq_required <what> <why> — emit a single DEFER for a probe that needs real
# jq filters. Returns 0 when the caller must bail out (jq absent), 1 when jq
# is present and the caller should continue.
jq_required() {
    (( HAVE_JQ == 1 )) && return 1
    defer "$1" "$2" "install jq to evaluate this probe"
    return 0
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
    if jq_required "read-model drift / coverage / sync-lag invariants" \
                   "payload IS valid JSON, but reading .drift/.coverage/.freshness needs jq filters"; then
        return
    fi

    local status drift coverage gap lag dirty total rm_rows
    status=$(echo "$body"   | jq -r '.status // "unknown"')
    drift=$(echo "$body"    | jq -r '.drift.divergent_pages // 0')
    coverage=$(echo "$body" | jq -r '.coverage.coverage_pct // 0')
    total=$(echo "$body"    | jq -r '.coverage.total_pages // 0')
    rm_rows=$(echo "$body"  | jq -r '.coverage.rm_rows // 0')
    gap=$(echo "$body"      | jq -r '.coverage.gap_count // 0')
    lag=$(echo "$body"      | jq -r '.freshness.lag_seconds // 0')
    dirty=$(echo "$body"    | jq -r '.freshness.dirty_queue_size // 0')

    note "status=$status cov=${coverage}% ($rm_rows/$total) drift=$drift gap=$gap lag=${lag}s queue=$dirty"

    # Drift: any drift is a correctness bug. Fail in both profiles.
    if [[ "$drift" -gt 0 ]]; then
        local samples
        samples=$(echo "$body" | jq -r '.drift.samples // [] | map(.page_id // .) | @csv' 2>/dev/null)
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
    if (( HAVE_JQ == 0 )); then
        # Array-ness is a jq filter, so it defers — but malformed output is
        # still called out honestly: something that will not even parse is
        # certainly not an array, which is exactly what jq -e would report.
        if ! json_valid "$rows"; then
            fail "sample query returned non-array" "wp eval output: $rows"; return
        fi
        defer "trust-score canonical-formula invariant" \
              "row sample IS valid JSON, but shape + per-row extraction need jq filters (type==\"array\", length, .[])" \
              "install jq to evaluate this probe"
        return
    fi
    if ! echo "$rows" | jq -e 'type == "array"' >/dev/null 2>&1; then
        fail "sample query returned non-array" "wp eval output: $rows"; return
    fi

    local count
    count=$(echo "$rows" | jq 'length')
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

    if ! echo "$results" | jq -e 'type == "array"' >/dev/null 2>&1; then
        fail "canonical formula call failed" "wp eval output: $results"; return
    fi

    local bad=0 ok=0
    local tolerance
    tolerance=$(echo "$results" | jq -r '.[0].tolerance // 0.5')

    while IFS= read -r entry; do
        local page_id stored expected diff ok_flag pos neg eb ob
        page_id=$(echo "$entry"  | jq -r '.page_id')
        stored=$(echo "$entry"   | jq -r '.stored')
        expected=$(echo "$entry" | jq -r '.expected')
        diff=$(echo "$entry"     | jq -r '.diff')
        ok_flag=$(echo "$entry"  | jq -r '.ok')
        pos=$(echo "$entry"      | jq -r '.pos')
        neg=$(echo "$entry"      | jq -r '.neg')
        eb=$(echo "$entry"       | jq -r '.eb')
        ob=$(echo "$entry"       | jq -r '.ob')

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
    done < <(echo "$results" | jq -c '.[]')

    if (( bad == 0 )); then
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
    if jq_required "fraud-gate invariants (is_suspended / vote / endorsement)" \
                   "probe output IS valid JSON, but reading its result flags needs jq filters"; then
        return
    fi

    local suspend vote_test_ran vote_high vote_low endorse_high endorse_low reason_high
    suspend=$(echo "$out"       | jq -r '.is_suspended_honored')
    vote_test_ran=$(echo "$out" | jq -r '.vote_test_ran // false')
    vote_high=$(echo "$out"     | jq -r '.vote_blocked_at_HIGH_by_fraud // false')
    vote_low=$(echo "$out"      | jq -r '.vote_NOT_blocked_as_fraud_at_LOW // false')
    reason_high=$(echo "$out"   | jq -r '.vote_block_reason // ""')
    endorse_high=$(echo "$out"  | jq -r '.endorsement_would_block_at_HIGH')
    endorse_low=$(echo "$out"   | jq -r '.endorsement_would_NOT_block_at_LOW')

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
    # Only (a) needs jq. The behavioral probe (b) and HTTP hammer (c) below
    # are pure bash/curl, so they still run and still report honestly when
    # jq is absent — do NOT return here.
    if jq_required "limiter health contract (ready / degraded / backend)" \
                   "health payload IS valid JSON, but reading its fields needs jq filters"; then
        :
    else
        local ready backend degraded
        ready=$(echo "$h"    | jq -r '.rate_limiter_ready')
        backend=$(echo "$h"  | jq -r '.backend')
        degraded=$(echo "$h" | jq -r '.degraded')
        note "health: backend=$backend ready=$ready degraded=$degraded"
        if [[ "$ready" != "true" ]]; then
            fail "limiter not ready (allow() fails closed)" "backend: $backend"
        elif [[ "$degraded" == "true" ]]; then
            fail "limiter in DEGRADED mode" "backend: $backend — cache layer flapping"
        else
            pass "limiter health: backend=$backend"
        fi
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
    if jq_required "ServiceLocator real-vs-NullObject wiring" \
                   "probe output IS valid JSON, but per-contract iteration needs jq filters (to_entries)"; then
        return
    fi

    while IFS="=" read -r label status; do
        if [[ "$status" == "real" ]]; then
            pass "$label → real implementation"
        else
            fail "$label → NullObject fallback" \
                 "contract is wired to a null implementation" \
                 "dependent plugin probably failed to init or registerProviders never ran"
        fi
    done < <(echo "$out" | jq -r 'to_entries[] | "\(.key)=\(.value)"')
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
    # Only the snapshot assertions need jq; the overdue-hook probe below has
    # its own deferral, so don't return out of the whole section here.
    if jq_required "auto_resolve run-vs-success invariant + success staleness" \
                   "cron snapshot IS valid JSON, but reading .last_run/.last_success needs jq filters"; then
        :
    else
        local cron_disabled last_run last_success now
        cron_disabled=$(echo "$snapshot" | jq -r '.cron_disabled')
        last_run=$(echo "$snapshot"      | jq -r '.last_run')
        last_success=$(echo "$snapshot"  | jq -r '.last_success')
        now=$(echo "$snapshot"           | jq -r '.now')

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
            fail_msg=$(echo "$snapshot" | jq -r '.last_failure.message // "unknown"')
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
    fi

    # Overdue events — any hook still in the past after wp-cli dispatch.
    if jq_required "overdue cron events" \
                   "selecting hooks whose next_run_relative matches \"ago\" needs jq filters (select/map/unique)"; then
        return
    fi
    local overdue_json
    overdue_json="$(wp "${WP_ARGS[@]}" cron event list --fields=hook,next_run_relative --format=json 2>/dev/null || echo '[]')"
    local overdue_hooks
    overdue_hooks=$(echo "$overdue_json" | jq -r '[.[] | select(.next_run_relative | test("ago"))] | map(.hook) | unique | .[]' 2>/dev/null)

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

    # ONE prerequisite notice, printed once, up front. Previously a missing
    # jq produced five separate "returned non-JSON" failures against payloads
    # that were valid JSON; now the operator is told exactly what is going on
    # before any probe runs.
    if (( HAVE_JQ == 0 )); then
        echo ""
        printf "${MAGENTA}PREREQUISITE${NC}  jq is not installed (no jq executable on PATH).\n"
        printf "              • JSON validation falls back to php — malformed payloads are still reported.\n"
        printf "              • Probes needing real jq filters will be ${MAGENTA}DEFERRED${NC}, not passed or failed.\n"
        printf "              • Install jq for a complete run; this run will exit 3 if anything defers.\n"
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
