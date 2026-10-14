<?php
/**
 * The six sibling bounded routes in `UsersEndpoint` — REAL WordPress REST
 * dispatch, one route at a time, each against ITS OWN documented cap.
 *
 * ── WHAT THIS MEASURES ──────────────────────────────────────────────────
 * Each route declared `minimum`/`maximum` beside a `sanitize_callback`, and
 * WordPress enforces a schema only for an argument that declares no
 * sanitizer — so none of the caps bound, and each handler clamped the lower
 * bound only. `absint` compounded it by rewriting invalid input into valid
 * input (`absint('-5') === 5`).
 *
 *   /users/:handle/blog          limit     max 50
 *   /users/:handle/activity      limit     max 50
 *   /users/:handle/endorsements  limit     max 50
 *   /users/:handle/reviews       per_page  max 50   (shared handler)
 *   /users/:handle/disputes      per_page  max 50   (shared handler)
 *   /users/:handle/shift-log     weeks     max 104  ← its own number
 *
 * ⚠ `weeks` is 104, not 50. A shared number would silently halve a
 * documented two-year window, so every assertion below uses the cap the
 * contract documents for that route.
 *
 * ⚠ IT CANNOT RUN IN CI — the jobs provide no WordPress core. The
 * CI-executable half is `tests/Unit/UsersEndpointSiblingBoundsTest.php`
 * (ordering, per-route caps, absint absence) plus the already-merged
 * `BoundedIntParamTest`.
 *
 * ── HOW TO RUN ──────────────────────────────────────────────────────────
 *   docker compose exec cli wp eval-file \
 *     wp-content/plugins/bcc-trust/scripts/tests/users-sibling-bounds-real-dispatch.php
 *
 * Read-only: GETs only, writes nothing, seeds nothing. Expected `fail=0`.
 */

function show($v): string
{
    return (is_scalar($v) || $v === null) ? var_export($v, true) : json_encode($v);
}

function ck(string $label, $expected, $actual): void
{
    $ok = $expected === $actual;
    if ($ok) { $GLOBALS['P'] = ($GLOBALS['P'] ?? 0) + 1; }
    else      { $GLOBALS['F'] = ($GLOBALS['F'] ?? 0) + 1; }
    printf("%-4s %-62s exp=%-14s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, show($expected), show($actual));
}

/** @return array{status:int, code:?string, queries:int, optionQueries:int, domainQueries:int, maxLimit:int} */
function hit(string $route, array $params): array
{
    $seen = array();
    $spy  = static function ($sql) use (&$seen) { $seen[] = $sql; return $sql; };
    add_filter('query', $spy);

    $req = new WP_REST_Request('GET', $route);
    foreach ($params as $k => $v) { $req->set_param($k, $v); }
    $res = rest_get_server()->dispatch($req);

    remove_filter('query', $spy);

    $data = $res->get_data();
    $maxLimit = 0;
    foreach ($seen as $sql) {
        if (preg_match_all('/LIMIT\s+(?:\d+\s*,\s*)?(\d+)/i', $sql, $m)) {
            foreach ($m[1] as $n) { $maxLimit = max($maxLimit, (int) $n); }
        }
    }

    // ⚠ Classified, not just counted. A raw count conflates the rate
    // limiter's own `wp_options` traffic with work on a domain table, and
    // the two mean completely different things for this fix.
    $optionQueries = 0;
    $domainQueries = 0;
    foreach ($seen as $sql) {
        if (preg_match('/\bwp_options\b/i', $sql)) {
            $optionQueries++;
            continue;
        }
        if (preg_match('/\bwp_(bcc_|users|usermeta|posts|postmeta|comments|peepso)/i', $sql)) {
            $domainQueries++;
        }
    }

    return array(
        'status'        => $res->get_status(),
        'code'          => is_array($data) ? ($data['code'] ?? ($data['error']['code'] ?? null)) : null,
        'queries'       => count($seen),
        'optionQueries' => $optionQueries,
        'domainQueries' => $domainQueries,
        'maxLimit'      => $maxLimit,
    );
}

// ── find a handle that resolves, so the valid cases reach real work ─────
$handle = null;
foreach (get_users(array('number' => 20, 'fields' => array('ID', 'user_nicename'))) as $u) {
    if (\BCC\Trust\Core\Support\UserSlugResolver::resolve((string) $u->user_nicename) > 0) {
        $handle = (string) $u->user_nicename;
        break;
    }
}
echo "resolvable handle: " . var_export($handle, true) . "\n";
if ($handle === null) {
    echo "\n⚠ NO RESOLVABLE HANDLE on this install. The refusal cases below still\n";
    echo "  mean something — they must refuse BEFORE the handle lookup, so they\n";
    echo "  cannot depend on one — but the 'valid request is preserved' cases\n";
    echo "  cannot run, and that limitation is reported rather than hidden.\n";
}

/** @var array<string, array{0:string,1:string,2:int}> route template, param, cap */
$routes = array(
    'blog'         => array('/bcc/v1/users/%s/blog', 'limit', 50),
    'activity'     => array('/bcc/v1/users/%s/activity', 'limit', 50),
    'endorsements' => array('/bcc/v1/users/%s/endorsements', 'limit', 50),
    'reviews'      => array('/bcc/v1/users/%s/reviews', 'per_page', 50),
    'disputes'     => array('/bcc/v1/users/%s/disputes', 'per_page', 50),
    'shift-log'    => array('/bcc/v1/users/%s/shift-log', 'weeks', 104),
);

echo "\n=== 1. EACH ROUTE REFUSES BEYOND ITS OWN CAP ===\n";
foreach ($routes as $name => $spec) {
    list($tpl, $param, $cap) = $spec;
    $route = sprintf($tpl, $handle ?? 'nobody-at-all');

    // one over its own cap
    $r = hit($route, array($param => (string) ($cap + 1)));
    ck("$name: $param=" . ($cap + 1) . ' -> 400', 400, $r['status']);
    ck("$name: $param=" . ($cap + 1) . ' -> bcc_invalid_request', 'bcc_invalid_request', $r['code']);

    // absurd
    $r = hit($route, array($param => '100000'));
    ck("$name: $param=100000 -> 400", 400, $r['status']);

    // ⚠ at its own cap it must be ACCEPTED, not refused — this is the
    // assertion that catches a borrowed number. shift-log at 104 would fail
    // if someone enforced 50 here.
    if ($handle !== null) {
        $r = hit($route, array($param => (string) $cap));
        ck("$name: $param=$cap (its own cap) is ACCEPTED", true, $r['status'] !== 400);
    }
}

echo "\n=== 2. ⚠ THE BORROWED-NUMBER TRAP: shift-log's cap is 104, not 50 ===\n";
if ($handle !== null) {
    $r = hit(sprintf('/bcc/v1/users/%s/shift-log', $handle), array('weeks' => '104'));
    ck('weeks=104 accepted (would fail if 50 were enforced)', true, $r['status'] !== 400);
    $r = hit(sprintf('/bcc/v1/users/%s/shift-log', $handle), array('weeks' => '51'));
    ck('weeks=51 accepted (inside 104)', true, $r['status'] !== 400);
}
$r = hit(sprintf('/bcc/v1/users/%s/shift-log', $handle ?? 'nobody-at-all'), array('weeks' => '105'));
ck('weeks=105 refused (just over 104)', 400, $r['status']);

echo "\n=== 3. NEGATIVE / FRACTIONAL / MALFORMED, PER ROUTE ===\n";
foreach ($routes as $name => $spec) {
    list($tpl, $param, $cap) = $spec;
    $route = sprintf($tpl, $handle ?? 'nobody-at-all');
    foreach (array('-1', '-5', '2.7', 'abc', '', '0', '1e3', ' 20', '10000000000') as $bad) {
        $r = hit($route, array($param => $bad));
        ck("$name: $param=" . var_export($bad, true) . ' -> 400', 400, $r['status']);
    }
}

echo "\n=== 4. ⚠⚠ A REFUSED REQUEST REACHES NO DATABASE OR PREFETCH WORK ===\n";
// Before this change each of these resolved the handle (a DB read) before
// even looking at the bound, then ran an unbounded query.
foreach ($routes as $name => $spec) {
    list($tpl, $param, $cap) = $spec;
    $route = sprintf($tpl, $handle ?? 'nobody-at-all');
    $r = hit($route, array($param => '100000'));

    if ($name === 'endorsements') {
        // ⚠ The one honest exception, by design: the throttle stays ahead of
        // the bound check. MEASURED — it is four `wp_options` operations,
        // not one, so an earlier version of this check asserted `<= 1` and
        // failed:
        //   SELECT bcc_throttle_degraded_until
        //   INSERT _transient_bcc_rl_…        (the counter write)
        //   SELECT _transient_bcc_rl_…
        //   SELECT bcc_throttle_last_success_ts
        // What matters is that NONE of them touches a domain table, so the
        // claim is made against the thing being protected rather than
        // against a raw count that measures the rate limiter.
        ck("$name: refusal touches NO domain table", 0, $r['domainQueries']);
        ck("$name: refusal touches only wp_options (the throttle)", true, $r['queries'] === $r['optionQueries']);
    } else {
        ck("$name: refusal issues ZERO queries", 0, $r['queries']);
    }
    ck("$name: refusal asks for no LIMIT at all", 0, $r['maxLimit']);
}

echo "\n=== 5. CONTROL — an ACCEPTED request still does the work ===\n";
if ($handle !== null) {
    foreach ($routes as $name => $spec) {
        list($tpl, $param, $cap) = $spec;
        // ⚠⚠ FLUSH FIRST. An earlier version of this control asserted
        // `queries > 0` without flushing and FAILED on `disputes` — not
        // because the route did no work, but because every read it needed
        // was already in the object cache from the ~150 dispatches above
        // it. A control that depends on execution order is not a control.
        wp_cache_flush();
        $r = hit(sprintf($tpl, $handle), array($param => '5'));
        ck("CONTROL $name: $param=5 issues queries", true, $r['queries'] > 0);
        ck("CONTROL $name: $param=5 touches a domain table", true, $r['domainQueries'] > 0);
    }
} else {
    echo "  SKIPPED — no resolvable handle, so there is no accepted path to control against.\n";
    echo "  ⚠ Without this control the zeroes in section 4 are weaker evidence: they\n";
    echo "    would also be zero if the spy were broken.\n";
}

echo "\n=== 6. mention-search UNCHANGED — it already clamped both ends ===\n";
$ms = hit('/bcc/v1/users/mention-search', array('q' => 'ab', 'limit' => '100000'));
ck('mention-search still CLAMPS rather than refusing', true, $ms['status'] !== 400);
ck('mention-search never asks for more than its own cap', true, $ms['maxLimit'] <= 8);

printf("\n==== SIBLING BOUNDS REAL DISPATCH: pass=%d fail=%d ====\n", $GLOBALS['P'] ?? 0, $GLOBALS['F'] ?? 0);
