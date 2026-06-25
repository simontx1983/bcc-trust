#!/usr/bin/env bash
#
# Golden-master characterization for GET /users/:handle  (Phase 10a net).
#
# UserViewService::getUser/getSummary + MemberProfileComposer + CardViewService
# are 1,300-line god-services coupled to 8+ PeepSo repositories/models + WP user
# + avatar APIs — too heavy to characterize by unit-mocking that surface. Instead
# we pin the CURRENT /users/:handle view-model against REAL data + real PeepSo as
# a committed golden master, so a behavior-preserving refactor (e.g. the Phase 7
# query dedup) can be proven to leave the response byte-identical.
#
# The response is byte-stable for a given (user, anon viewer, DB state) — no
# per-request volatility (absolute timestamps only; no request_id in _meta).
#
# Usage:
#   BCC_BASE_URL=http://host scripts/verify-profile-golden.sh [handle]
# Defaults: handle=phillipcosmos, BASE=http://blue-collar-crypto-custom.local
# Exit: 0 = matches golden master · 1 = DRIFT (behavior changed) · 2 = setup error.
#
# NOTE: host-pinned — avatar/cover URLs embed the origin where the fixture was
# captured, so run it against that same site (re-capture the fixture if the host
# or the seeded user changes).

set -u
HANDLE="${1:-phillipcosmos}"
BASE="${BCC_BASE_URL:-http://blue-collar-crypto-custom.local}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURE="$DIR/tests/golden/profile-${HANDLE}.json"

[ -f "$FIXTURE" ] || { echo "[golden] no fixture for handle '$HANDLE': $FIXTURE" >&2; exit 2; }

TMP="$(mktemp 2>/dev/null || echo "$DIR/tests/golden/.live-${HANDLE}.tmp.json")"
curl -sk "$BASE/wp-json/bcc/v1/users/${HANDLE}" -o "$TMP" 2>/dev/null
[ -s "$TMP" ] || { echo "[golden] empty/failed response from $BASE" >&2; rm -f "$TMP"; exit 2; }

# Windows python needs C:/ paths; cygpath is a no-op-ish fallback elsewhere.
PYFIX="$(cygpath -m "$FIXTURE" 2>/dev/null || echo "$FIXTURE")"
PYTMP="$(cygpath -m "$TMP" 2>/dev/null || echo "$TMP")"

python - "$PYFIX" "$PYTMP" "$HANDLE" <<'PY'
import json, sys, difflib
fixture = json.load(open(sys.argv[1]))
resp    = json.load(open(sys.argv[2])).get('data')
handle  = sys.argv[3]
a = json.dumps(fixture, sort_keys=True, indent=2)
b = json.dumps(resp,    sort_keys=True, indent=2)
if a == b:
    print(f"[golden] MATCH -- /users/{handle} is byte-identical to the golden master.")
    sys.exit(0)
print(f"[golden] DRIFT -- /users/{handle} differs from the golden master:")
for line in list(difflib.unified_diff(a.splitlines(), b.splitlines(), lineterm=''))[:60]:
    if line[:3] in ('+++', '---'):
        continue
    if line[:1] in '+-':
        print("  " + line[:160])
sys.exit(1)
PY
rc=$?
rm -f "$TMP"
exit $rc
