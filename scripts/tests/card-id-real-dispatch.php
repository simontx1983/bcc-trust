<?php
/**
 * card-id-real-dispatch.php — exercise `GET /bcc/v1/cards/:type/:id` through
 * REAL WordPress code, against an isolated backend with no database.
 *
 * WHY THIS EXISTS. The C1 fix (percent-encoded validator slugs) turns on
 * behaviours only WordPress itself can tell you about, and that the unit suite
 * cannot reach because it runs with no core:
 *
 *   1. whether the REST route regex is matched against the DECODED or the
 *      UNDECODED request path — the whole shape of the fix depends on it;
 *   2. what `sanitize_text_field` does to a percent-encoded octet (it strips
 *      it, which is why `:id` no longer uses it);
 *   3. what `post_name` value `get_page_by_path()` actually searches for,
 *      given its internal `rawurlencode(urldecode())` + `%2F`/`%20` rewrites
 *      and `sanitize_title_for_query()` per segment — and therefore whether
 *      a candidate the resolver accepted is the single segment core will look
 *      for, or something core splits or rewrites into a DIFFERENT identifier.
 *
 * WHAT IS REAL AND WHAT IS NOT — stated so no reader over-reads the result:
 *   REAL: wp-includes/{class-wp-error,plugin,compat,utf8,formatting,load,
 *         functions,option,default-filters}.php, class-wp-list-util.php,
 *         class-wp-matchesmapregex.php and the whole REST stack, plus
 *         bcc-trust's own CardsEndpoint::ID_PATTERN,
 *         CardViewService::canonicalSlugFor() and
 *         CardViewService::pathSegmentsFor().
 *   SHIMMED: `__`, `_x`, `get_locale` (l10n.php needs a database; `get_locale`
 *         returns 'en_US', which is what production runs and the only value
 *         `remove_accents()` branches on here).
 *   NOT EXERCISED: the SQL in `get_page_by_path()`. There is no database, so
 *         the script reports the post_name core WOULD query for and stops
 *         there. It proves addressability and segment identity, not the query.
 *         The decoy section below is what covers "and it must not reach a
 *         different page".
 *
 * Verified identical on WordPress 7.0.2 and 7.1.4 (production's version).
 *
 * USAGE
 *   php scripts/tests/card-id-real-dispatch.php /path/to/wordpress
 *   php scripts/tests/card-id-real-dispatch.php /path/to/wordpress --print-table
 *
 * `--print-table` emits the real-core `sanitize_title()` and
 * `sanitize_title_for_query()` values that
 * tests/Unit/CardIdAddressabilityTest.php keeps as fixtures, so the fixtures
 * are re-derived rather than retyped.
 *
 * Set BCC_SLUG_CORPUS to a TSV whose SECOND column is a `post_name` to check
 * every live slug, in both its stored and its frontend spelling.
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

foreach ([
    '/class-wp-error.php', '/plugin.php', '/compat.php', '/utf8.php', '/formatting.php',
    '/load.php', '/functions.php', '/option.php', '/class-wp-list-util.php',
    '/class-wp-matchesmapregex.php', '/rest-api.php', '/class-wp-http-response.php',
    '/rest-api/class-wp-rest-response.php', '/rest-api/class-wp-rest-request.php',
    '/rest-api/class-wp-rest-server.php',
] as $coreFile) {
    require_once ABSPATH . WPINC . $coreFile;
}

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
$ID_PATTERN  = (string) (new ReflectionClass(CardsEndpoint::class))->getConstant('ID_PATTERN');
$OLD_PATTERN = '[A-Za-z0-9][A-Za-z0-9_-]{0,99}';

$resolveMethod = new ReflectionMethod(CardViewService::class, 'canonicalSlugFor');
$resolveMethod->setAccessible(true);
/** @var callable(string):?string $canonical */
$canonical   = static fn (string $id): ?string => $resolveMethod->invoke(null, $id);
$addressable = static fn (string $id): bool => $canonical($id) !== null;

$segmentsMethod = new ReflectionMethod(CardViewService::class, 'pathSegmentsFor');
$segmentsMethod->setAccessible(true);
/** @var callable(string):array<int,string> $prodSegments */
$prodSegments = static fn (string $path): array => $segmentsMethod->invoke(null, $path);

// ── harness ─────────────────────────────────────────────────────────────────
$pass  = 0;
$fail  = 0;
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
 * An INDEPENDENT transcription of get_page_by_path()'s normalization prefix
 * (wp-includes/post.php), kept here as the oracle the production replay is
 * checked against. Byte-identical in WordPress 7.0.2 and 7.1.4.
 *
 * @return list<string>
 */
function oracleSegments(string $path): array
{
    $path = rawurlencode(urldecode($path));
    $path = str_replace('%2F', '/', $path);
    $path = str_replace('%20', ' ', $path);

    return array_map('sanitize_title_for_query', explode('/', trim($path, '/')));
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
 * Replicates the rewrite stage of WP::parse_request() for the REST rule
 *   add_rewrite_rule('^wp-json/(.*)?', 'index.php?rest_route=/$matches[1]', 'top')
 * so we can see what route string the REST server is handed for a REQUEST_URI.
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

/** The frontend spelling of a stored slug: every `%` escaped exactly once. */
function asFrontendSends(string $storedSlug): string
{
    return str_replace('%', '%25', $storedSlug);
}

// ── corpus ──────────────────────────────────────────────────────────────────
$STORED   = 'bonynode%f0%9f%92%9a-cosmos-zsyh4e';
$FRONTEND = asFrontendSends($STORED);
// `𝐥𝐞𝐬𝐧𝐢𝐤 | 𝐔𝐓𝐒𝐀` (page 4079): a real 135-character stored slug whose frontend
// spelling is 215 — the regression case for bounding the SLUG, not the URL.
$LONG = '%f0%9d%90%a5%f0%9d%90%9e%f0%9d%90%ac%f0%9d%90%a7%f0%9d%90%a2%f0%9d%90%a4'
    . '-%f0%9d%90%94%f0%9d%90%93%f0%9d%90%92%f0%9d%90%80-jackal-um9q4p';

echo "WordPress {$wp_version} at " . ABSPATH . "\n";
echo 'shimmed (not real core): ' . (empty($shimmed) ? 'none' : implode(', ', $shimmed)) . "\n";
echo "route pattern under test: {$ID_PATTERN}\n\n";

// ── 1. which path does the REST layer see? ──────────────────────────────────
echo "== 1. the request-decoding path ==\n";
$uri   = '/wp-json/bcc/v1/cards/validator/' . $FRONTEND;
$route = restRouteForRequestUri($uri);
printf("  REQUEST_URI  %s\n  rest_route   %s\n", $uri, var_export($route, true));
ok(
    'the rewrite hands the REST server the UNDECODED path',
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

// ── 3. the production replay agrees with an independent transcription ───────
echo "\n== 3. pathSegmentsFor() is a faithful replay of core ==\n";
foreach ([$STORED, $FRONTEND, $LONG, asFrontendSends($LONG), 'ztake-org-cosmos-54at8c',
          'bonynode%2fadmin-cosmos-zsyh4e', 'bonynode%20cosmos', 'bonynode%25'] as $probe) {
    ok('replay === oracle for ' . $probe, oracleSegments($probe), $prodSegments($probe));
}
ok(
    'the %2f candidate really does split into two segments (the premise)',
    ['bonynode', 'admin-cosmos-zsyh4e'],
    $prodSegments('bonynode%2fadmin-cosmos-zsyh4e')
);
ok(
    'the %20 candidate really is rewritten by core (the other premise)',
    ['bonynode-cosmos'],
    $prodSegments('bonynode%20cosmos')
);

// ── 4. route admission, old pattern vs new, through a real server ───────────
echo "\n== 4. real WP_REST_Server dispatch ==\n";
$oldServer = buildServer($OLD_PATTERN, 'sanitize_text_field');
$newServer = buildServer($ID_PATTERN, [BoundedIntParam::class, 'keepScalar']);

$dispatchCases = [
    ['stored slug (lower hex)',       $STORED,                                 'rest_no_route', 'ok'],
    ['frontend spelling',             $FRONTEND,                               'rest_no_route', 'ok'],
    ['leading emoji, stored',         '%f0%9f%90%a0stake-fish-cosmos-2lcnj0',  'rest_no_route', 'ok'],
    ['135-char stored slug',          $LONG,                                   'rest_no_route', 'ok'],
    ['its 215-char frontend spelling', asFrontendSends($LONG),                 'rest_no_route', 'ok'],
    ['plain ASCII slug',              'ztake-org-cosmos-54at8c',               'ok',            'ok'],
    ['numeric id',                    '3692',                                  'ok',            'ok'],
    ['raw unencoded UTF-8',           "bonynode\u{1F49A}-cosmos-zsyh4e",       'rest_no_route', 'rest_no_route'],
    ['raw path separator',            'bonynode/admin',                        'rest_no_route', 'rest_no_route'],
];
printf("  %-32s %-16s %-16s\n", 'id', 'OLD pattern', 'NEW pattern');
foreach ($dispatchCases as [$label, $id, $expectOld, $expectNew]) {
    $o = dispatch($oldServer, '/bcc/v1/cards/validator/' . $id);
    $n = dispatch($newServer, '/bcc/v1/cards/validator/' . $id);
    printf("  %-32s %-16s %-16s\n", $label, $o['code'], $n['code']);
    ok("OLD pattern: {$label}", $expectOld, $o['code']);
    ok("NEW pattern: {$label}", $expectNew, $n['code']);
}
$n = dispatch($newServer, '/bcc/v1/cards/validator/' . $STORED);
ok('the handler receives the octets intact', $STORED, $n['id']);
$o = dispatch($oldServer, '/bcc/v1/cards/validator/ztake-org-cosmos-54at8c');
ok('the old config still passes an ASCII slug through', 'ztake-org-cosmos-54at8c', $o['id']);

// ── 5. the resolver, against real sanitize_title ────────────────────────────
echo "\n== 5. canonicalSlugFor against real core ==\n";

// The structural fact the `%25` acceptances rest on: sanitize_title only ever
// percent-encodes bytes >= 0x80, so no post_name it produces can contain the
// ASCII escape `%25`.
ok('sanitize_title encodes a non-ASCII byte as an octet', 'bonynode%f0%9f%92%9a', sanitize_title("bonynode\u{1F49A}"));
ok('sanitize_title DROPS a literal ASCII percent rather than escaping it', 'bonynode-100', sanitize_title('bonynode 100%'));
ok('so a %25 octet is never minted', false, str_contains(sanitize_title('100% bonynode'), '%25'));

// Each row is [id, the post_name it must resolve to or null, why]. The expected
// value is stated, never read back from the run — a table filled in from the
// output asserts only that the code does what it does.
$guardCases = [
    // ── spelling 1: the id IS post_name, octets intact
    [$STORED,   'bonynode%f0%9f%92%9a-cosmos-zsyh4e', 'stored spelling, zero decodes'],
    ['bonynode%F0%9F%92%9A-cosmos-zsyh4e', 'bonynode%f0%9f%92%9a-cosmos-zsyh4e', 'stored spelling, upper hex'],
    ['BonyNode%F0%9F%92%9A-cosmos-zsyh4e', 'bonynode%f0%9f%92%9a-cosmos-zsyh4e', 'mixed-case ASCII around the octets'],
    ['%f0%9f%90%a0stake-fish-cosmos-2lcnj0', '%f0%9f%90%a0stake-fish-cosmos-2lcnj0', 'leading emoji'],
    ['chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k', 'chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k', 'three-byte sequence'],
    [$LONG, $LONG, '135-char stored slug'],

    // ── spelling 2: post_name escaped ONCE — what this frontend sends
    [$FRONTEND, 'bonynode%f0%9f%92%9a-cosmos-zsyh4e', 'FRONTEND spelling (one decode)'],
    ['%25F0%259F%2590%25A0stake-fish-cosmos-2lcnj0', '%f0%9f%90%a0stake-fish-cosmos-2lcnj0', 'frontend, leading emoji'],
    [asFrontendSends($LONG), $LONG, '⭐ 215-char FRONTEND spelling of the 135-char slug'],

    // ── percent-free ids are returned untouched (today's behaviour, by construction)
    ['ztake-org-cosmos-54at8c', 'ztake-org-cosmos-54at8c', 'plain ASCII slug'],
    ['bro_n_bro-100-slash-protected-cosmos-rx52jg', 'bro_n_bro-100-slash-protected-cosmos-rx52jg', 'underscores'],
    ['ZTAKE-ORG-COSMOS-54AT8C', 'ZTAKE-ORG-COSMOS-54AT8C', '⭐ uppercase ASCII, passed through as today'],
    ['bonynode--cosmos', 'bonynode--cosmos', 'doubled dashes: percent-free, so unchanged'],
    [str_repeat('a', 200), str_repeat('a', 200), '200 chars, the column width'],

    // ── single-decode aliases: ordinary percent-encoding transparency
    ['%7Atake-org-cosmos-54at8c', 'ztake-org-cosmos-54at8c', 'ALIAS: %7A decodes to z'],
    ['bonynode%2dcosmos', 'bonynode-cosmos', 'ALIAS: %2d decodes to -'],

    // ── accepted but provably INERT: `%25` cannot occur in a post_name
    ['bonynode%2525f0%25259f%252592%25259a-cosmos-zsyh4e', 'bonynode%25f0%259f%2592%259a-cosmos-zsyh4e', 'triple-encoded: canonical but unmatchable'],
    ['bonynode%2525', 'bonynode%25', 'double-escaped percent: canonical but unmatchable'],

    // ── ⭐ STRUCTURAL refusals: canonical as SPELLING, re-interpreted by core
    ['bonynode%252Fadmin-cosmos-zsyh4e', null, '⭐ DOUBLE-encoded separator, upper hex'],
    ['bonynode%252fadmin-cosmos-zsyh4e', null, '⭐ DOUBLE-encoded separator, lower hex'],
    ['bonynode%2Fadmin-cosmos-zsyh4e',   null, 'single-encoded separator, upper hex'],
    ['bonynode%2fadmin-cosmos-zsyh4e',   null, 'single-encoded separator, lower hex'],
    ['bonynode%2520cosmos',              null, '⭐ DOUBLE-encoded space, lower hex'],
    ['bonynode%2520Cosmos',              null, '⭐ DOUBLE-encoded space, upper hex'],
    ['bonynode%20cosmos',                null, 'single-encoded space'],

    // ── other refusals
    ['bonynode%zz-cosmos-zsyh4e',        null, 'malformed octet'],
    ['bonynode%',                        null, 'bare trailing percent'],
    ["bonynode\u{1F49A}-cosmos-zsyh4e",  null, 'raw unencoded UTF-8 (by design)'],
    ['bonynode/admin',                   null, 'raw path separator'],
    ["bonynode\0admin",                  null, 'NUL byte'],
    ['bonynode cosmos',                  null, 'whitespace'],
    ['-bonynode-cosmos',                 null, 'leading dash'],
    [str_repeat('a', 201),               null, '201 chars, past the column width'],
    [str_repeat('a', 601),               null, '601 chars, past the raw cheapness bound'],
    ['',                                 null, 'empty'],
];
$accepted = 0;
$refused  = 0;
foreach ($guardCases as [$id, $expected, $label]) {
    $got = $canonical($id);
    $got === null ? $refused++ : $accepted++;
    ok("resolver: {$label}", $expected, $got);
}
printf("  accepted %d, refused %d\n", $accepted, $refused);
ok('the resolver discriminates (both outcomes occur)', true, $accepted > 0 && $refused > 0);

// ⭐ THE BINDING INVARIANT: whatever is accepted must be exactly what core will
// search for — one segment, equal to the candidate. Anything core would split
// or rewrite is a different identifier than the caller named.
echo "\n== 6. every accepted candidate is the single segment core will search for ==\n";
$checkedInvariant = 0;
foreach ($guardCases as [$id, $expected]) {
    if ($expected === null) {
        continue;
    }
    // Percent-free ids are returned untouched and deliberately skip the
    // structural check (core cannot split or rewrite this charset, and running
    // it would refuse an uppercase ASCII slug that works today).
    if (!str_contains($id, '%')) {
        ok("percent-free stays one segment: {$expected}", 1, count($prodSegments($expected)));
        $checkedInvariant++;
        continue;
    }
    ok("core searches exactly [{$expected}]", [$expected], $prodSegments($expected));
    $checkedInvariant++;
}
printf("  candidates checked: %d\n", $checkedInvariant);
ok('the invariant was actually exercised', true, $checkedInvariant > 10);

// ── 7. the decoy: a refused id must not reach the pages its segments name ───
echo "\n== 7. decoy lookup ==\n";
// A stand-in for wp_posts. Every entry is a page the hostile ids must never
// resolve to. `bonynode` + `admin-cosmos-zsyh4e` are what core would have
// searched for; `bonynode-cosmos` is what the %20 rewrite lands on.
$decoyTable = [
    'bonynode'                     => 9001,
    'admin-cosmos-zsyh4e'          => 9002,
    'bonynode-admin-cosmos-zsyh4e' => 9003,
    'bonynode-cosmos'              => 9004,
    // and one real page, so the lookup is shown to work at all
    'bonynode%f0%9f%92%9a-cosmos-zsyh4e' => 4471,
];
/** A get_page_by_path() stand-in: resolve only when every segment matches. */
$lookup = static function (?string $candidate) use ($decoyTable, $prodSegments): ?int {
    if ($candidate === null) {
        return null;
    }
    $parts = $prodSegments($candidate);
    if (count($parts) !== 1) {
        // core would walk ancestry; a flat table resolves nothing, but report
        // the hit it WOULD have looked for so an over-accepting resolver shows
        return isset($decoyTable[$parts[0]]) ? $decoyTable[$parts[0]] : null;
    }

    return $decoyTable[$parts[0]] ?? null;
};

ok('the decoy lookup works at all (positive control)', 4471, $lookup($canonical($STORED)));
ok('and for the frontend spelling', 4471, $lookup($canonical($FRONTEND)));

foreach ([
    'bonynode%252Fadmin-cosmos-zsyh4e' => 'double-encoded separator',
    'bonynode%252fadmin-cosmos-zsyh4e' => 'double-encoded separator, lower hex',
    'bonynode%2Fadmin-cosmos-zsyh4e'   => 'single-encoded separator',
    'bonynode%2520cosmos'              => 'double-encoded space',
    'bonynode%2520Cosmos'              => 'double-encoded space, upper hex',
    'bonynode%20cosmos'                => 'single-encoded space',
] as $hostile => $why) {
    ok("route ADMITS the hostile id ({$why})", 1, preg_match('/\A' . $ID_PATTERN . '\z/D', $hostile));
    ok("resolver REFUSES it ({$why})", null, $canonical($hostile));
    ok("and no decoy page is reached ({$why})", null, $lookup($canonical($hostile)));
}

// ── 8. the live slug corpus, when supplied ──────────────────────────────────
$corpusFile = getenv('BCC_SLUG_CORPUS');
if (is_string($corpusFile) && $corpusFile !== '' && is_file($corpusFile)) {
    echo "\n== 8. live slug corpus: {$corpusFile} ==\n";
    $rows      = 0;
    $missStored = [];
    $missFront  = [];
    $wrong      = [];
    foreach ((array) file($corpusFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $cols = explode("\t", (string) $line);
        $slug = $cols[1] ?? '';
        if ($slug === '' || $slug === 'post_name') {
            continue;
        }
        $rows++;

        $a = $canonical($slug);
        $b = $canonical(asFrontendSends($slug));
        if ($a === null) {
            $missStored[] = $slug;
        } elseif ($a !== $slug) {
            $wrong[] = "stored {$slug} -> {$a}";
        }
        if ($b === null) {
            $missFront[] = $slug;
        } elseif ($b !== $slug) {
            $wrong[] = "frontend {$slug} -> {$b}";
        }
    }
    printf(
        "  %d slugs x2 spellings\n    unreachable via stored  : %d\n    unreachable via frontend: %d\n    resolved to WRONG slug  : %d\n",
        $rows,
        count($missStored),
        count($missFront),
        count($wrong)
    );
    foreach (array_slice($missStored, 0, 5) as $s) { echo "      S: {$s}\n"; }
    foreach (array_slice($missFront, 0, 5) as $s) { echo "      F: {$s}\n"; }
    foreach (array_slice($wrong, 0, 5) as $s) { echo "      W: {$s}\n"; }
    ok('every live slug is addressable in its stored spelling', 0, count($missStored));
    ok('every live slug is addressable in its FRONTEND spelling', 0, count($missFront));
    ok('no live slug resolves to a different slug', 0, count($wrong));
    ok('the corpus was actually read', true, $rows > 0);
} else {
    echo "\n== 8. live slug corpus: SKIPPED (set BCC_SLUG_CORPUS to a TSV whose 2nd column is post_name) ==\n";
}

// ── the fixture tables the unit suite keeps ─────────────────────────────────
if ($printTable) {
    echo "\n== real-core values for tests/Unit/CardIdAddressabilityTest.php ==\n";
    $titleArgs = [];
    $queryArgs = [];
    foreach ($guardCases as [$id]) {
        if (!is_string($id) || $id === '' || !str_contains($id, '%') || strlen($id) > 600) {
            continue;
        }
        $lower = strtolower($id);
        $a     = rawurldecode($lower);
        $titleArgs[$a] = sanitize_title($a);
        $b = strtolower(rawurldecode($id));
        $titleArgs[$b] = sanitize_title($b);
        $cand = $canonical($id);
        if ($cand !== null) {
            foreach (oracleSegments($cand) as $part) {
                $queryArgs[$part] = sanitize_title_for_query($part);
            }
        }
    }
    $titleArgs['__bcc_probe__'] = sanitize_title('__bcc_probe__');
    foreach (['sanitize_title' => $titleArgs, 'sanitize_title_for_query' => $queryArgs] as $label => $rows) {
        ksort($rows);
        echo "\n// ---- {$label} (" . count($rows) . " entries) ----\n";
        foreach ($rows as $k => $v) {
            printf("        %-50s => %s,\n", var_export($k, true), var_export($v, true));
        }
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
