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
# golden master). Re-capture whenever the host or the seeded DB changes — the
# fixtures are host-pinned (avatar/cover URLs embed the capture origin).

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

py() { python "$@" 2>/dev/null || python3 "$@" 2>/dev/null; }

# Extract `.data` from a response file, canonicalised (sorted keys). Empty on failure.
extract_data() {
    py - "$1" <<'PY'
import json, sys
try:
    print(json.dumps(json.load(open(sys.argv[1])).get('data'), sort_keys=True, indent=2))
except Exception:
    pass
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
    pyfix="$(cygpath -m "$fixture" 2>/dev/null || echo "$fixture")"
    pytmp="$(cygpath -m "$tmp" 2>/dev/null || echo "$tmp")"
    py - "$pyfix" "$pytmp" "$name" <<'PY'
import json, sys, difflib
name = sys.argv[3]
a = open(sys.argv[1]).read().strip()
try:
    b = json.dumps(json.load(open(sys.argv[2])).get('data'), sort_keys=True, indent=2)
except Exception as e:
    print(f"[golden] {name}: could not parse live response: {e}"); sys.exit(1)
if a == b:
    print(f"[golden] {name}: MATCH"); sys.exit(0)
print(f"[golden] {name}: DRIFT -- live differs from the golden master:")
for ln in list(difflib.unified_diff(a.splitlines(), b.splitlines(), lineterm=''))[:40]:
    if ln[:3] in ('+++', '---'):
        continue
    if ln[:1] in '+-':
        print("  " + ln[:160])
sys.exit(1)
PY
    [ $? -ne 0 ] && fail=1
    rm -f "$tmp"
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
