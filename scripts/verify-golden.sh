#!/usr/bin/env bash
#
# Golden-master characterization net for the read endpoints Phase 11 refactors
# (Phase 10). Supersedes the single-endpoint verify-profile-golden.sh.
#
# UserViewService / FeedRankingService / CardViewService and the Core->Onchain
# read cluster are 1,300+-line god-services coupled to 8+ PeepSo repositories,
# WP user/avatar APIs, and cross-domain static calls — too heavy to characterize
# by unit-mocking that surface. Instead we pin each endpoint's CURRENT response
# `data` against REAL data + real PeepSo as a committed golden master, so a
# behaviour-preserving refactor (the Phase-11 FeedHydrationPipeline / ChainRead
# interface / AuthEndpoint / Plugin.php splits) can be PROVEN to leave every
# pinned response byte-identical. A drift = the refactor changed behaviour.
#
# Each entry's `data` is byte-stable for a given (endpoint, anon viewer, DB
# state): absolute timestamps only, and the per-request request_id lives in
# `_meta` (excluded — we compare `data`). The manifest documents the coverage
# map (which split each fixture gates).
#
# Usage:
#   scripts/verify-golden.sh                 # verify every manifest entry (default)
#   scripts/verify-golden.sh --capture       # (re)capture every fixture from live
#   scripts/verify-golden.sh --capture NAME  # (re)capture one entry
#   scripts/verify-golden.sh NAME            # verify one entry
#   BCC_BASE_URL=http://host scripts/verify-golden.sh ...
# Default BASE = http://blue-collar-crypto-custom.local
# Exit: 0 = all match (or all captured) · 1 = DRIFT / unstable · 2 = setup error.
#
# --capture PROVES self-stability before writing: it fetches twice and refuses
# to write a fixture whose two fetches differ (a volatile endpoint can't be a
# golden master). Re-capture whenever the seeded DB changes.
#
# NORMALISATION (added with the #131 repair). Fixtures are no longer host-pinned
# and no longer self-rotting. Two things used to change with no code change
# behind them, so every fixture "drifted" for reasons nobody caused:
#   · the capture origin — avatar/cover URLs embedded scheme+host, so Local's
#     move from http to https broke all of them at once → now {{ORIGIN}}
#   · PeepSo's ?mt=<mtime> avatar cache-buster, which changed whenever an
#     avatar file was touched → now {{MT}}
# Everything else is still compared byte-for-byte. A side benefit: fixtures are
# now portable across hosts, which they could never be before.
#
# THIS SCRIPT CANNOT RUN IN CI — it needs a live WordPress and a seeded
# database. That is why it sat in no workflow and its fixtures rotted unnoticed.
# Its CI companion is scripts/golden-staleness-guard.php, which catches the
# rot class (retired routes, orphaned fixtures, fields no PHP emits) from the
# checked-in tree alone, and DOES run on every push. The two are complementary:
# the guard proves the fixtures still describe reality, this script proves the
# values still match it. Run this one before/after a behaviour-preserving
# refactor; the guard has your back the rest of the time.

set -u
BASE="${BCC_BASE_URL:-http://blue-collar-crypto-custom.local}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MANIFEST="$DIR/scripts/golden/manifest.txt"
GOLDEN_DIR="$DIR/tests/golden"

[ -f "$MANIFEST" ] || { echo "[golden] no manifest: $MANIFEST" >&2; exit 2; }

MODE="verify"
ONLY=""
for arg in "$@"; do
    case "$arg" in
        --capture) MODE="capture" ;;
        --*) echo "[golden] unknown flag: $arg" >&2; exit 2 ;;
        *) ONLY="$arg" ;;
    esac
done

# Run python, PRESERVING stderr. This used to be `python "$@" 2>/dev/null ||
# python3 "$@" 2>/dev/null`, which silently swallowed every interpreter error —
# so `cards` reported failure with ZERO output for months (its fixture contains
# U+1F420, and printing the diff raised UnicodeEncodeError on a Windows cp1252
# stdout). A checker you cannot distinguish from a crash is worse than no
# checker. Probe once for an interpreter, then let errors through.
PYBIN=""
for c in python3 python; do
    if command -v "$c" >/dev/null 2>&1 && "$c" -c '' >/dev/null 2>&1; then PYBIN="$c"; break; fi
done
[ -n "$PYBIN" ] || { echo "[golden] no python interpreter on PATH" >&2; exit 2; }
py() { "$PYBIN" "$@"; }

# Origin host of the target site, used to make fixtures host-portable.
HOST="$(printf '%s' "$BASE" | sed -E 's#^https?://##; s#/.*$##')"

# Extract `.data` from a response file, canonicalised (sorted keys) and
# NORMALISED. Prints nothing and returns non-zero on failure — the caller
# reports it; stderr carries the reason.
extract_data() {
    py - "$1" "$HOST" <<'PY'
import json, re, sys

# stdout must survive non-ASCII payload content (emoji in card names) on a
# Windows cp1252 console. Without this the whole script fails invisibly.
try:
    sys.stdout.reconfigure(encoding='utf-8')
except Exception:
    pass

path, host = sys.argv[1], sys.argv[2]
try:
    with open(path, encoding='utf-8') as fh:
        payload = json.load(fh)
except Exception as e:
    print(f"could not parse response: {e}", file=sys.stderr)
    sys.exit(1)

if not isinstance(payload, dict) or 'data' not in payload:
    print("response has no 'data' key", file=sys.stderr)
    sys.exit(1)

# ── Normalisation ────────────────────────────────────────────────────────
# Two classes of churn made these fixtures rot on their own, with no code
# change behind it. Neither is behaviour we want to pin:
#
#   1. ORIGIN. Avatar/cover URLs embed the capture host AND scheme. Local
#      moved http -> https and every fixture "drifted" at once. Pinning the
#      origin also made fixtures un-runnable anywhere else, which is why this
#      could never be pointed at staging.
#   2. CACHE-BUSTERS. PeepSo appends ?mt=<mtime> to avatar URLs. That integer
#      changes whenever an avatar file is touched, so a fixture re-rotted
#      immediately after every recapture.
#
# Everything else is still compared byte-for-byte — the point of the net.
#   3. EXTERNALLY-SOURCED VALUES. The fields below carry on-chain data that a
#      background indexer cron rewrites on its own schedule. Nothing in the
#      code under test produces them, so they cannot express a refactor
#      regression — but they DO make the net permanently red, which is how it
#      came to be ignored in the first place. Only their VALUES are replaced;
#      the field names are still pinned, so removing or renaming one still
#      drifts, and golden-staleness-guard.php still checks PHP emits them.
#      Cost, stated plainly: a refactor that changed the FORMATTING of these
#      two values (decimal precision, timestamp shape) would not be caught
#      here. Keep this list minimal and explicit for that reason.
VOLATILE_VALUE_FIELDS = {
    'last_fetched_at',  # validator/NFT indexer sweep timestamp
    'total_stake',      # live delegated stake, refreshed from chain
}

ORIGIN_RE = re.compile(r'https?://' + re.escape(host))
MT_RE     = re.compile(r'([?&]mt=)\d+')

def norm(node):
    if isinstance(node, str):
        return MT_RE.sub(r'\1{{MT}}', ORIGIN_RE.sub('{{ORIGIN}}', node))
    if isinstance(node, list):
        return [norm(v) for v in node]
    if isinstance(node, dict):
        return {
            k: ('{{VOLATILE}}' if k in VOLATILE_VALUE_FIELDS and v is not None else norm(v))
            for k, v in node.items()
        }
    return node

print(json.dumps(norm(payload['data']), sort_keys=True, indent=2, ensure_ascii=False))
PY
}

fail=0
checked=0

while IFS= read -r line || [ -n "$line" ]; do
    # skip comments / blanks
    case "$line" in ''|\#*) continue ;; esac
    name="$(printf '%s' "$line" | awk '{print $1}')"
    path="$(printf '%s' "$line" | awk '{print $2}')"
    [ -n "$name" ] && [ -n "$path" ] || continue
    [ -n "$ONLY" ] && [ "$ONLY" != "$name" ] && continue

    fixture="$GOLDEN_DIR/$name.json"
    url="$BASE/wp-json/bcc/v1${path}"
    tmp="$(mktemp 2>/dev/null || echo "$GOLDEN_DIR/.live-$name.tmp.json")"
    curl -sk "$url" -o "$tmp" 2>/dev/null
    if [ ! -s "$tmp" ]; then
        echo "[golden] $name: empty/failed response from $url" >&2
        rm -f "$tmp"; fail=1; continue
    fi
    live="$(extract_data "$tmp")"
    if [ -z "$live" ]; then
        echo "[golden] $name: response has no 'data' key ($url)" >&2
        rm -f "$tmp"; fail=1; continue
    fi
    checked=$((checked + 1))

    if [ "$MODE" = "capture" ]; then
        # Prove self-stability: a second fetch must match, else it's volatile.
        tmp2="$(mktemp 2>/dev/null || echo "$GOLDEN_DIR/.live2-$name.tmp.json")"
        curl -sk "$url" -o "$tmp2" 2>/dev/null
        live2="$(extract_data "$tmp2")"
        rm -f "$tmp2"
        if [ "$live" != "$live2" ]; then
            echo "[golden] $name: VOLATILE — two fetches differ; not writing fixture." >&2
            rm -f "$tmp"; fail=1; continue
        fi
        printf '%s\n' "$live" > "$fixture"
        echo "[golden] $name: captured ($(wc -c < "$fixture" | tr -d ' ') bytes) -> tests/golden/$name.json"
        rm -f "$tmp"; continue
    fi

    # verify mode
    if [ ! -f "$fixture" ]; then
        echo "[golden] $name: no fixture (run --capture $name): $fixture" >&2
        rm -f "$tmp"; fail=1; continue
    fi
    # Compare against the ALREADY-NORMALISED live extract computed above, so
    # verify and capture can never diverge on normalisation.
    livefile="$tmp.norm"
    printf '%s\n' "$live" > "$livefile"
    pyfix="$(cygpath -m "$fixture" 2>/dev/null || echo "$fixture")"
    pylive="$(cygpath -m "$livefile" 2>/dev/null || echo "$livefile")"
    py - "$pyfix" "$pylive" "$name" <<'PY'
import sys, difflib
try:
    sys.stdout.reconfigure(encoding='utf-8')
except Exception:
    pass
name = sys.argv[3]
a = open(sys.argv[1], encoding='utf-8').read().strip()
b = open(sys.argv[2], encoding='utf-8').read().strip()
if a == b:
    print(f"[golden] {name}: MATCH"); sys.exit(0)
diff = [l for l in difflib.unified_diff(a.splitlines(), b.splitlines(), lineterm='')
        if l[:1] in '+-' and l[:3] not in ('+++', '---')]
print(f"[golden] {name}: DRIFT -- live differs from the golden master "
      f"({len(diff)} changed line(s); '-' = fixture, '+' = live):")
for ln in diff[:40]:
    print("  " + ln[:160])
if len(diff) > 40:
    print(f"  ... {len(diff) - 40} more changed line(s) suppressed")
sys.exit(1)
PY
    [ $? -ne 0 ] && fail=1
    rm -f "$tmp" "$livefile"
done < "$MANIFEST"

if [ "$checked" -eq 0 ]; then
    echo "[golden] no entries matched${ONLY:+ name '$ONLY'}." >&2
    exit 2
fi
if [ "$MODE" = "capture" ]; then
    [ "$fail" -eq 0 ] && echo "[golden] captured $checked fixture(s)."
else
    [ "$fail" -eq 0 ] && echo "[golden] all $checked fixture(s) match."
fi
exit $fail
