<?php
/**
 * card-id-real-dispatch.php — exercise `GET /bcc/v1/cards/:type/:id` through
 * REAL WordPress code, against an isolated backend with no database.
 *
 * WHY THIS EXISTS. The C1 fix (percent-encoded validator slugs) turns on three
 * behaviours that only WordPress itself can tell you about, and that the unit
 * suite cannot reach because it runs with no core:
 *
 *   1. whether the REST route regex is matched against the DECODED or the
 *      UNDECODED request path — the whole shape of the fix depends on it;
 *   2. what `sanitize_text_field` does to a percent-encoded octet (it strips
 *      it, which is why `:id` no longer uses it);
 *   3. what `post_name` value `get_page_by_path()` actually searches for,
 *      given its internal `rawurlencode(urldecode())` +
 *      `sanitize_title_for_query()` per segment.
 *
 * WHAT IS REAL AND WHAT IS NOT — stated so no reader over-reads the result:
 *   REAL: wp-includes/{class-wp-error,plugin,compat,utf8,formatting,load,
 *         functions,option,default-filters}.php, wp-includes/class-wp-list-util.php,
 *         wp-includes/class-wp-matchesmapregex.php, and the whole REST stack
 *         (class-wp-rest-{request,server,response}.php, rest-api.php), plus
 *         bcc-trust's own CardsEndpoint::ID_PATTERN and
 *         CardViewService::slugIsAddressable().
 *   SHIMMED: `__`, `_x`, `get_locale` (l10n.php needs a database; `get_locale`
 *         returns 'en_US', which is what production runs and is the only value
 *         `remove_accents()` branches on here).
 *   NOT EXERCISED: the SQL in `get_page_by_path()`. There is no database, so
 *         the script reports the post_name core WOULD query for and stops
 *         there. It therefore proves addressability, not resolution.
 *
 * USAGE
 *   php scripts/tests/card-id-real-dispatch.php /path/to/wordpress
 *   php scripts/tests/card-id-real-dispatch.php /path/to/wordpress --print-table
 *
 * `--print-table` emits the real-core `sanitize_title()` values that
 * tests/Unit/CardIdAddressabilityTest.php keeps as fixtures, so the fixtures
 * are re-derived rather than retyped.
 *
 * @package BCC\Trust\Scripts\Tests
 * @since 2026-10-30 (C1 — percent-encoded validator slugs)
 */

declare(strict_types=1);

$wpRoot = $argv[1] ?? getenv('BCC_WP_ROOT');
if (!is_string($wpRoot) || $wpRoot === '' || !is_file(rtrim($wpRoot, '/\\') . '/wp-includes/version.php')) {
    fwrite(STDERR, "usage: php " . basename(__FILE__) . " /path/to/wordpress [--print-table]\n");
    fwrite(STDERR, "  (the path must contain wp-includes/version.php)\n");
    exit(2);
}
$printTable = in_array('--print-table', $argv, true);

define('ABSPATH', rtrim(str_replace('\\', '/', $wpRoot), '/') . '/');
define('WPINC', 'wp-includes');

$GLOBALS['wp_filter']         = [];
$GLOBALS['wp_actions']        = [];
$GLOBALS['wp_current_filter'] = [];

require_once ABSPATH . WPINC . '/class-wp-error.php';
require_once ABSPATH . WPINC . '/plugin.php';
require_once ABSPATH . WPINC . '/compat.php';
require_once ABSPATH . WPINC . '/utf8.php';
require_once ABSPATH . WPINC . '/formatting.php';
require_once ABSPATH . WPINC . '/load.php';
require_once ABSPATH . WPINC . '/functions.php';
require_once ABSPATH . WPINC . '/option.php';
require_once ABSPATH . WPINC . '/class-wp-list-util.php';
require_once ABSPATH . WPINC . '/class-wp-matchesmapregex.php';
require_once ABSPATH . WPINC . '/rest-api.php';
require_once ABSPATH . WPINC . '/class-wp-http-response.php';
require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-response.php';
require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-request.php';
require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-server.php';

// `is_utf8_charset()` reads the blog_charset option; short-circuit the read so
// no database is touched. Production is utf8mb4, so 'UTF-8' is faithful.
add_filter('pre_option_blog_charset', static fn () => 'UTF-8');

$shimmed = [];
foreach ([
    '__'         => static fn ($t, $d = 'default') => $t,
    '_x'         => static fn ($t, $c, $d = 'default') => $t,
    'get_locale' => static fn () => 'en_US',
] as $fn => $impl) {
    if (!function_exists($fn)) {
        $GLOBALS['__bcc_shim'][$fn] = $impl;
        eval('function ' . $fn . '(...$a) { return ($GLOBALS["__bcc_shim"]["' . $fn . '"])(...$a); }');
        $shimmed[] = $fn;
    }
}

// Core's own filter registrations. WITHOUT THIS, `sanitize_title()` returns its
// input unchanged (the percent-encoding lives in `sanitize_title_with_dashes`,
// which core attaches here) and every assertion below silently measures
// nothing. Loaded last so the shims above are already in place.
require_once ABSPATH . WPINC . '/default-filters.php';

require ABSPATH . WPINC . '/version.php';

// ── bcc-trust under test ────────────────────────────────────────────────────
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use BCC\Trust\Core\REST\CardsEndpoint;
use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Core\Support\BoundedIntParam;

/** @var string $ID_PATTERN the production route pattern, read not retyped */
$ID_PATTERN = (string) (new ReflectionClass(CardsEndpoint::class))->getConstant('ID_PATTERN');
$OLD_PATTERN = '[A-Za-z0-9][A-Za-z0-9_-]{0,99}';

$guard = new ReflectionMethod(CardViewService::class, 'canonicalSlugFor');
$guard->setAccessible(true);
/** @var callable(string):?string $canonical */
$canonical   = static fn (string $id): ?string => $guard->invoke(null, $id);
$addressable = static fn (string $id): bool => $canonical($id) !== null;

// ── harness ─────────────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
$lines = 0;

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function ok(string $what, $expected, $actual): void
{
    global $pass, $fail, $lines;
    $lines++;
    if ($expected === $actual) {
        $pass++;
        return;
    }
    $fail++;
    echo 'FAIL ' . $what . "\n  expected: " . var_export($expected, true)
        . "\n  actual:   " . var_export($actual, true) . "\n";
}

/**
 * Build a real WP_REST_Server carrying the cards route, with the production
 * `id` sanitize_callback and a callback that reports what reached the handler.
 */
function buildServer(string $idPattern, callable $sanitizeId): WP_REST_Server
{
    $server = new WP_REST_Server();
    $server->register_route(
        'bcc/v1',
        '/bcc/v1/cards/(?P<type>validator|project|creator|member)/(?P<id>' . $idPattern . ')',
        [[
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => static fn (WP_REST_Request $r) => new WP_REST_Response(
                ['id' => $r->get_param('id')],
                200
            ),
            'permission_callback' => static fn () => true,
            'args'                => [
                'type' => [
                    'required'          => true,
                    'type'              => 'string',
                    'enum'              => ['validator', 'project', 'creator', 'member'],
                    'sanitize_callback' => 'sanitize_key',
                ],
                'id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => $sanitizeId,
                ],
            ],
        ]]
    );

    return $server;
}

/**
 * @return array{status:int, code:string, id:?string}
 */
function dispatch(WP_REST_Server $server, string $route): array
{
    $response = $server->dispatch(new WP_REST_Request('GET', $route));
    $data     = $response->get_data();
    if ($response->get_status() !== 200) {
        return [
            'status' => $response->get_status(),
            'code'   => is_array($data) ? (string) ($data['code'] ?? '?') : '?',
            'id'     => null,
        ];
    }

    return ['status' => 200, 'code' => 'ok', 'id' => is_array($data) ? (string) $data['id'] : null];
}

/**
 * Replicate the pure prefix of get_page_by_path() verbatim (wp-includes/post.php)
 * — the part that decides which post_name values are queried.
 *
 * @return list<string>
 */
function pageByPathParts(string $path): array
{
    $path = rawurlencode(urldecode($path));
    $path = str_replace('%2F', '/', $path);
    $path = str_replace('%20', ' ', $path);

    return array_map('sanitize_title_for_query', explode('/', trim($path, '/')));
}

/**
 * Replicate the rewrite stage of WP::parse_request() for the REST rule
 *   add_rewrite_rule('^wp-json/(.*)?', 'index.php?rest_route=/$matches[1]', 'top')
 * so we can see what route string the REST server is handed for a given
 * REQUEST_URI.
 */
function restRouteForRequestUri(string $requestUri): ?string
{
    $match = '^wp-json/(.*)?';
    $req   = trim((string) parse_url($requestUri, PHP_URL_PATH), '/');

    if (!preg_match("#^$match#", $req, $m) && !preg_match("#^$match#", urldecode($req), $m)) {
        return null;
    }

    $query = addslashes(WP_MatchesMapRegex::apply('index.php?rest_route=/$matches[1]', $m));
    $query = (string) preg_replace('!^.+\?!', '', $query);
    parse_str($query, $vars);

    return isset($vars['rest_route']) ? stripslashes((string) $vars['rest_route']) : null;
}

// ── corpus ──────────────────────────────────────────────────────────────────
$STORED  = 'bonynode%f0%9f%92%9a-cosmos-zsyh4e';
$FRONTEND = 'bonynode%F0%9F%92%9A-cosmos-zsyh4e';

echo "WordPress {$wp_version} at " . ABSPATH . "\n";
echo 'shimmed (not real core): ' . (empty($shimmed) ? 'none' : implode(', ', $shimmed)) . "\n";
echo "route pattern under test: {$ID_PATTERN}\n\n";

// ── 1. which path does the REST layer see? ──────────────────────────────────
echo "== 1. the request-decoding path ==\n";
$uri = '/wp-json/bcc/v1/cards/validator/' . $FRONTEND;
$route = restRouteForRequestUri($uri);
printf("  REQUEST_URI  %s\n  rest_route   %s\n", $uri, var_export($route, true));
ok(
    'the rewrite hands the REST server the UNDECODED path (WP_MatchesMapRegex re-urlencodes, parse_str decodes once)',
    '/bcc/v1/cards/validator/' . $FRONTEND,
    $route
);
ok(
    'an ASCII path is unaffected',
    '/bcc/v1/cards/validator/ztake-org-cosmos-54at8c',
    restRouteForRequestUri('/wp-json/bcc/v1/cards/validator/ztake-org-cosmos-54at8c')
);

// ── 2. sanitize_text_field destroys octets; keepScalar does not ─────────────
echo "\n== 2. the sanitize_callback ==\n";
printf("  sanitize_text_field(%s)\n    = %s\n", $STORED, sanitize_text_field($STORED));
ok('sanitize_text_field STRIPS percent octets', 'bonynode-cosmos-zsyh4e', sanitize_text_field($STORED));
ok('keepScalar preserves them', $STORED, BoundedIntParam::keepScalar($STORED));

// ── 3. route admission, old pattern vs new, through a real server ───────────
echo "\n== 3. real WP_REST_Server dispatch ==\n";
$oldServer = buildServer($OLD_PATTERN, 'sanitize_text_field');
$newServer = buildServer($ID_PATTERN, [BoundedIntParam::class, 'keepScalar']);

$dispatchCases = [
    // label                         route id                  old           new
    ['stored slug (lower hex)',      $STORED,                  'rest_no_route', 'ok'],
    ['frontend spelling (UPPER hex)', $FRONTEND,               'rest_no_route', 'ok'],
    ['leading emoji, stored',        '%f0%9f%90%a0stake-fish-cosmos-2lcnj0', 'rest_no_route', 'ok'],
    ['plain ASCII slug',             'ztake-org-cosmos-54at8c', 'ok',          'ok'],
    ['numeric id',                   '3692',                    'ok',          'ok'],
    ['raw unencoded UTF-8',          "bonynode\u{1F49A}-cosmos-zsyh4e", 'rest_no_route', 'rest_no_route'],
    ['raw path separator',           'bonynode/admin',          'rest_no_route', 'rest_no_route'],
];
printf("  %-32s %-16s %-16s\n", 'id', 'OLD pattern', 'NEW pattern');
foreach ($dispatchCases as [$label, $id, $expectOld, $expectNew]) {
    $o = dispatch($oldServer, '/bcc/v1/cards/validator/' . $id);
    $n = dispatch($newServer, '/bcc/v1/cards/validator/' . $id);
    printf("  %-32s %-16s %-16s\n", $label, $o['code'], $n['code']);
    ok("OLD pattern: {$label}", $expectOld, $o['code']);
    ok("NEW pattern: {$label}", $expectNew, $n['code']);
}

// The handler must receive the caller's octets intact under the new config,
// and mangled under the old one. This is the planted positive for section 2.
$n = dispatch($newServer, '/bcc/v1/cards/validator/' . $STORED);
ok('the handler receives the octets intact', $STORED, $n['id']);
$o = dispatch($oldServer, '/bcc/v1/cards/validator/ztake-org-cosmos-54at8c');
ok('the old config still passes an ASCII slug through', 'ztake-org-cosmos-54at8c', $o['id']);

// ── 4. the guard, against real sanitize_title ───────────────────────────────
echo "\n== 4. CardViewService::canonicalSlugFor against real sanitize_title ==\n";

// The structural fact the `%25` acceptances rest on: sanitize_title only ever
// percent-encodes bytes >= 0x80, so no post_name it produces can contain the
// ASCII escape `%25`. Demonstrated on core, both directions.
ok('sanitize_title encodes a non-ASCII byte as an octet', 'bonynode%f0%9f%92%9a', sanitize_title("bonynode\u{1F49A}"));
ok('sanitize_title DROPS a literal ASCII percent rather than escaping it', 'bonynode-100', sanitize_title('bonynode 100%'));
ok('so a %25 octet is never minted', false, str_contains(sanitize_title('100% bonynode'), '%25'));
// Each row is [id, the post_name it must resolve to or null, why]. The
// expected value is stated, never read back from the run — a table filled in
// from the output asserts only that the code does what it does.
$guardCases = [
    // ── spelling 1: the id IS post_name, octets intact (direct API caller)
    [$STORED,   'bonynode%f0%9f%92%9a-cosmos-zsyh4e', 'stored spelling, zero decodes'],
    [$FRONTEND, 'bonynode%f0%9f%92%9a-cosmos-zsyh4e', 'stored spelling, upper hex'],
    ['BonyNode%F0%9F%92%9A-cosmos-zsyh4e', 'bonynode%f0%9f%92%9a-cosmos-zsyh4e', 'mixed-case ASCII around the octets'],
    ['%f0%9f%90%a0stake-fish-cosmos-2lcnj0', '%f0%9f%90%a0stake-fish-cosmos-2lcnj0', 'leading emoji'],
    ['chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k', 'chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k', 'three-byte sequence'],

    // ── spelling 2: post_name escaped ONCE — what this frontend sends
    ['bonynode%25F0%259F%2592%259A-cosmos-zsyh4e', 'bonynode%f0%9f%92%9a-cosmos-zsyh4e', 'FRONTEND spelling (one decode)'],
    ['bonynode%25f0%259f%2592%259a-cosmos-zsyh4e', 'bonynode%f0%9f%92%9a-cosmos-zsyh4e', 'frontend spelling, lower hex'],
    ['%25F0%259F%2590%25A0stake-fish-cosmos-2lcnj0', '%f0%9f%90%a0stake-fish-cosmos-2lcnj0', 'frontend spelling, leading emoji'],

    // ── percent-free ids are returned untouched (today's behaviour, by construction)
    ['ztake-org-cosmos-54at8c',                     'ztake-org-cosmos-54at8c', 'plain ASCII slug'],
    ['bro_n_bro-100-slash-protected-cosmos-rx52jg', 'bro_n_bro-100-slash-protected-cosmos-rx52jg', 'underscores preserved'],
    ['ZTAKE-ORG-COSMOS-54AT8C',                     'ZTAKE-ORG-COSMOS-54AT8C', 'uppercase ASCII, passed through as today'],
    ['bonynode--cosmos',                            'bonynode--cosmos', 'doubled dashes: percent-free, so unchanged'],
    [str_repeat('a', 200),                          str_repeat('a', 200), '200 chars, the column width'],

    // ── single-decode aliases. Accepted: one decode is ordinary URL semantics
    //    and names the page it spells. Noted, not celebrated.
    ['%7Atake-org-cosmos-54at8c', 'ztake-org-cosmos-54at8c', 'ALIAS: %7A decodes to z'],
    ['bonynode%2dcosmos',         'bonynode-cosmos',         'ALIAS: %2d decodes to -'],

    // ── accepted but provably INERT. One decode of a triple-encoded id lands
    //    on `%25…`, which IS sanitize_title-canonical (core protects and
    //    restores existing %XX octets). It names exactly what the caller
    //    spelled, and it can never match a row: sanitize_title only
    //    percent-encodes bytes >= 0x80, so `%25` (ASCII 0x25) cannot occur in
    //    any post_name. Asserted below rather than assumed.
    ['bonynode%2525f0%25259f%252592%25259a-cosmos-zsyh4e', 'bonynode%25f0%259f%2592%259a-cosmos-zsyh4e', 'triple-encoded: canonical but unmatchable'],
    ['bonynode%2525', 'bonynode%25', 'double-escaped percent: canonical but unmatchable'],

    // ── refused
    ['bonynode%2fadmin-cosmos-zsyh4e', null, 'encoded path separator: would become two segments'],
    ['bonynode%2Fadmin-cosmos-zsyh4e', null, 'encoded path separator, upper hex'],
    ['bonynode%20cosmos',              null, 'encoded space: sanitize_title would ALTER it to a dash'],
    ['bonynode%zz-cosmos-zsyh4e',      null, 'malformed octet'],
    ['bonynode%',                      null, 'bare trailing percent'],
    ["bonynode\u{1F49A}-cosmos-zsyh4e", null, 'raw unencoded UTF-8 (by design)'],
    ['bonynode/admin',                 null, 'raw path separator'],
    ["bonynode\0admin",                null, 'NUL byte'],
    ['bonynode cosmos',                null, 'whitespace'],
    ['-bonynode-cosmos',               null, 'leading dash'],
    [str_repeat('a', 201),             null, '201 chars, past the column width'],
    ['',                               null, 'empty'],
];
$accepted = 0;
$refused  = 0;
foreach ($guardCases as [$id, $expected, $label]) {
    $got = $canonical($id);
    $got === null ? $refused++ : $accepted++;
    ok("guard: {$label}", $expected, $got);
}
printf("  accepted %d, refused %d\n", $accepted, $refused);
ok('the guard discriminates (both outcomes occur)', true, $accepted > 0 && $refused > 0);

// Everything accepted must be a slug core will look up as ONE segment, and
// must itself be sanitize_title-canonical — otherwise the lookup is a guess.
foreach ($guardCases as [$id, $expected]) {
    if ($expected === null) {
        continue;
    }
    ok("accepted slug is one path segment: {$expected}", 1, count(pageByPathParts($expected)));
}

// ── 5. every hazard must be route-ADMITTED, so the guard is load-bearing ────
echo "\n== 5. the guard is load-bearing, not decorative ==\n";
$hazards = [
    'bonynode%2fadmin-cosmos-zsyh4e',
    'bonynode%2Fadmin-cosmos-zsyh4e',
    'bonynode%20cosmos',
    'bonynode%zz-cosmos-zsyh4e',
    'bonynode%',
];
foreach ($hazards as $id) {
    ok("route ADMITS the hazard {$id}", 1, preg_match('/\A' . $ID_PATTERN . '\z/D', $id));
    ok("guard refuses the hazard {$id}", false, $addressable($id));
}

// ── 6. what post_name would core have queried for? ──────────────────────────
echo "\n== 6. get_page_by_path() target post_name ==\n";
foreach ([$STORED, $FRONTEND, 'BonyNode%F0%9F%92%9A-cosmos-zsyh4e', 'ztake-org-cosmos-54at8c'] as $id) {
    $parts = pageByPathParts($id);
    printf("  %-38s -> %s\n", $id, implode(' || ', $parts));
    ok("addressable ids resolve to ONE segment: {$id}", 1, count($parts));
}
ok(
    'all three accepted spellings target the SAME stored post_name',
    [[$STORED], [$STORED], [$STORED]],
    [
        pageByPathParts($STORED),
        pageByPathParts($FRONTEND),
        pageByPathParts('BonyNode%F0%9F%92%9A-cosmos-zsyh4e'),
    ]
);
// and the separator hazard really does split, which is why it is refused
ok('the %2f hazard would have become TWO segments', 2, count(pageByPathParts('bonynode%2fadmin-cosmos-zsyh4e')));

// ── 7. the live slug corpus, when supplied ──────────────────────────────────
$corpusFile = getenv('BCC_SLUG_CORPUS');
if (is_string($corpusFile) && $corpusFile !== '' && is_file($corpusFile)) {
    echo "\n== 7. live slug corpus: {$corpusFile} ==\n";
    $n = 0;
    $notAddressable = [];
    foreach ((array) file($corpusFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $cols = explode("\t", (string) $line);
        $slug = $cols[1] ?? '';
        if ($slug === '' || $slug === 'post_name') {
            continue;
        }
        $n++;
        if (!$addressable($slug)) {
            $notAddressable[] = $slug;
        }
    }
    printf("  %d slugs; NOT addressable: %d\n", $n, count($notAddressable));
    foreach (array_slice($notAddressable, 0, 10) as $s) {
        echo "    {$s}\n";
    }
    ok('every live stored slug is addressable', 0, count($notAddressable));
    ok('the corpus was actually read', true, $n > 0);
} else {
    echo "\n== 7. live slug corpus: SKIPPED (set BCC_SLUG_CORPUS to a TSV whose 2nd column is post_name) ==\n";
}

// ── the fixture table the unit suite keeps ──────────────────────────────────
if ($printTable) {
    echo "\n== real-core sanitize_title() values for tests/Unit/CardIdAddressabilityTest.php ==\n";
    $keys = [];
    foreach ($guardCases as [$id]) {
        if (is_string($id) && $id !== '' && str_contains($id, '%') && strlen($id) <= 200) {
            $keys[] = rawurldecode($id);
        }
    }
    $keys[] = '__bcc_probe__';
    foreach (array_values(array_unique($keys)) as $k) {
        printf("  %-46s => %s\n", var_export($k, true), var_export(sanitize_title($k), true));
    }
}

// ── verdict ─────────────────────────────────────────────────────────────────
echo "\n";
printf("assertions: %d   pass: %d   fail: %d\n", $lines, $pass, $fail);
if ($lines === 0) {
    fwrite(STDERR, "REFUSED: no assertions ran — an empty run reports zero failures and means nothing.\n");
    exit(3);
}
exit($fail === 0 ? 0 : 1);
