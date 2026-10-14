<?php
/**
 * `GET /bcc/v1/members` bounds — REAL WordPress REST dispatch.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────
 * The defect it covers was invisible to every harness we had. The route
 * declared `'per_page' => ['minimum' => 1, 'maximum' => 50, …]` and the
 * declaration is correct in isolation — `rest_validate_value_from_schema(51)`
 * returns `rest_out_of_bounds`. But the argument also declared
 * `'sanitize_callback' => 'absint'`, and
 * `WP_REST_Request::sanitize_params()` substitutes the schema-enforcing
 * `rest_parse_request_arg` ONLY for an argument that declares none. So the
 * bound never ran.
 *
 * Measured on WordPress 6.7.2 BEFORE the fix:
 *
 *   per_page=20      status=200  reported=20      max SQL LIMIT=500
 *   per_page=50      status=200  reported=50      max SQL LIMIT=500
 *   per_page=51      status=200  reported=51      max SQL LIMIT=500
 *   per_page=100000  status=200  reported=100000  max SQL LIMIT=100000
 *
 * A single query parameter set the `LIMIT` the database was given, on a
 * route whose `permission_callback` is `__return_true`, against a declared
 * and documented cap of 50.
 *
 * ⚠ IT CANNOT RUN IN CI. The CI jobs install the plugin tree and a database
 * but no WordPress core, so there is no `WP_REST_Server` there. The
 * CI-executable half of this fix is `tests/Unit/BoundedIntParamTest.php`
 * (the parser, no WordPress needed) and
 * `tests/Unit/MembersBoundsEnforcementTest.php` (the ordering).
 *
 * ── HOW TO RUN IT ───────────────────────────────────────────────────────
 *   docker compose up -d        # wordpress:6.7-php8.2 + mariadb + wp-cli,
 *                               # this plugin and bcc-core bind-mounted
 *   docker compose exec cli wp eval-file \
 *       wp-content/plugins/bcc-trust/scripts/tests/members-bounds-real-dispatch.php
 *
 * Expected: `pass=N fail=0`.
 *
 * ⚠ Read-only: it dispatches GETs and writes nothing. Safe against a dev
 * install. Still not for staging or production — it is a diagnostic, and a
 * deliberately large request is exactly what the fix exists to refuse.
 */

$PASS = 0;
$FAIL = 0;

function show($v): string
{
    return (is_scalar($v) || $v === null) ? var_export($v, true) : json_encode($v);
}

function ck(string $label, $expected, $actual): void
{
    $ok = $expected === $actual;
    if ($ok) { $GLOBALS['PASS'] = ($GLOBALS['PASS'] ?? 0) + 1; }
    else      { $GLOBALS['FAIL'] = ($GLOBALS['FAIL'] ?? 0) + 1; }
    printf("%-4s %-58s exp=%-18s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, show($expected), show($actual));
}

/** @return array{status:int, code:?string, reported:mixed, maxLimit:int, queries:int, memberWork:int} */
function probe($perPage = null, $page = null): array
{
    $seen = array();
    $spy  = static function ($sql) use (&$seen) { $seen[] = $sql; return $sql; };
    add_filter('query', $spy);

    $req = new WP_REST_Request('GET', '/bcc/v1/members');
    if ($perPage !== null) { $req->set_param('per_page', $perPage); }
    if ($page !== null)    { $req->set_param('page', $page); }
    $res  = rest_get_server()->dispatch($req);
    remove_filter('query', $spy);

    $data = $res->get_data();
    $code = is_array($data) ? ($data['code'] ?? ($data['error']['code'] ?? null)) : null;

    $maxLimit = 0;
    foreach ($seen as $sql) {
        if (preg_match_all('/LIMIT\s+(?:\d+\s*,\s*)?(\d+)/i', $sql, $m)) {
            foreach ($m[1] as $n) { $maxLimit = max($maxLimit, (int) $n); }
        }
    }

    // Queries that only a members read performs: the global type-count and
    // the prefetcher's batched maps all touch peepso_* or the user table.
    $memberWork = 0;
    foreach ($seen as $sql) {
        if (preg_match('/peepso_page_members|peepso_reactions|peepso_user_followers|wp_users/i', $sql)) {
            $memberWork++;
        }
    }

    return array(
        'status'     => $res->get_status(),
        'code'       => $code,
        'reported'   => $data['data']['pagination']['per_page'] ?? ($data['pagination']['per_page'] ?? null),
        'maxLimit'   => $maxLimit,
        'queries'    => count($seen),
        'memberWork' => $memberWork,
    );
}

echo "=== 1. THE SCHEMA IS CORRECT IN ISOLATION (it always was) ===\n";
$args = rest_get_server()->get_routes()['/bcc/v1/members'][0]['args'];
foreach (array(50, 51, 100000) as $v) {
    $r = rest_validate_value_from_schema($v, $args['per_page'], 'per_page');
    ck('schema rejects ' . $v, true, is_wp_error($r) === ($v > 50));
}
ck('per_page declares maximum 50', 50, $args['per_page']['maximum'] ?? null);
ck('per_page no longer sanitises with absint', false, ($args['per_page']['sanitize_callback'] ?? null) === 'absint');

echo "\n=== 2. VALUES INSIDE 1-50 ARE PRESERVED, NOT CLAMPED OR DEFAULTED ===\n";
foreach (array(1, 2, 20, 49, 50) as $v) {
    $r = probe((string) $v);
    ck("per_page=$v -> 200", 200, $r['status']);
    ck("per_page=$v preserved on the wire", $v, $r['reported']);
}
$absent = probe();
ck('per_page absent -> 200', 200, $absent['status']);
ck('per_page absent -> the documented default 20', 20, $absent['reported']);

echo "\n=== 3. OUT OF RANGE IS REFUSED (was 200 before the fix) ===\n";
foreach (array('51', '52', '100', '100000', '999999999') as $v) {
    $r = probe($v);
    ck("per_page=$v -> 400", 400, $r['status']);
    ck("per_page=$v -> bcc_invalid_request", 'bcc_invalid_request', $r['code']);
}
foreach (array('0') as $v) {
    $r = probe($v);
    ck("per_page=$v -> 400", 400, $r['status']);
}

echo "\n=== 4. NEGATIVE — absint turned every one of these POSITIVE ===\n";
foreach (array('-1', '-5', '-50', '-100000') as $v) {
    $r = probe($v);
    ck("per_page=$v -> 400 (absint made it " . abs((int) $v) . ')', 400, $r['status']);
    ck("per_page=$v -> bcc_invalid_request", 'bcc_invalid_request', $r['code']);
}

echo "\n=== 5. FRACTIONAL — absint truncated instead of refusing ===\n";
foreach (array('2.7', '20.0', '50.9', '51.1', '-2.7') as $v) {
    $r = probe($v);
    ck("per_page=$v -> 400", 400, $r['status']);
}

echo "\n=== 6. MALFORMED ===\n";
foreach (array('abc', '', '20abc', 'abc20', '1e3', '0x10', '+20', ' 20', '20 ', '1,000', '10000000000') as $v) {
    $r = probe($v);
    ck("per_page=" . var_export($v, true) . ' -> 400', 400, $r['status']);
}
$arr = probe(array('20'));
ck('per_page=["20"] (array) -> 400', 400, $arr['status']);

echo "\n=== 7. page IS BOUNDED BELOW, UNBOUNDED ABOVE (as documented) ===\n";
foreach (array('1', '2', '9999') as $v) {
    ck("page=$v -> 200", 200, probe(null, $v)['status']);
}
foreach (array('0', '-1', 'abc', '2.7', ' 1', '10000000000') as $v) {
    ck('page=' . var_export($v, true) . ' -> 400', 400, probe(null, $v)['status']);
}

echo "\n=== 8. ⚠⚠ A REFUSED REQUEST DOES NO REPOSITORY OR PREFETCH WORK ===\n";
// The point of refusing in the first statement. Before the fix,
// per_page=100000 reached the global type-count query AND the eleven
// batched prefetch maps, then issued LIMIT 100000.
foreach (array('100000', '-5', '2.7', 'abc', '0') as $v) {
    $r = probe($v);
    ck("per_page=$v issues ZERO queries", 0, $r['queries']);
    ck("per_page=$v does ZERO member work", 0, $r['memberWork']);
    ck("per_page=$v asks for no LIMIT at all", 0, $r['maxLimit']);
}

echo "\n=== 9. CONTROL — an ACCEPTED request still does the work ===\n";
// Without this, the zeroes above could mean the spy is broken.
$ok = probe('20');
ck('CONTROL per_page=20 issues queries', true, $ok['queries'] > 0);
ck('CONTROL per_page=20 does member work', true, $ok['memberWork'] > 0);
ck('CONTROL per_page=20 still answers 200', 200, $ok['status']);

echo "\n=== 10. THE ORIGINAL DEFECT CANNOT RECUR ===\n";
$was = probe('100000');
ck('per_page=100000 no longer reports 100000', true, $was['reported'] !== 100000);
ck('per_page=100000 no longer issues LIMIT 100000', true, $was['maxLimit'] < 100000);

printf("\n==== /members BOUNDS REAL DISPATCH: pass=%d fail=%d ====\n", $GLOBALS['PASS'] ?? 0, $GLOBALS['FAIL'] ?? 0);
