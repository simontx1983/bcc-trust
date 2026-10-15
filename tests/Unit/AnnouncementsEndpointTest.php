<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Announcements\AnnouncementFeature;
use BCC\Trust\Core\REST\AnnouncementsEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The announcement READ routes — api-contract-v1.md §4.32.4, §4.32.10.
 *
 * ── ⚠⚠ WHAT THIS CANNOT DO, STATED UP FRONT ─────────────────────────────
 * **WordPress core is not loadable in this suite, so there is no real
 * `WP_REST_Server` to dispatch through.** Nothing here proves WordPress's
 * own dispatcher behaves as expected.
 *
 * What it does instead is drive the **real route declarations**:
 * {@see self::dispatch()} reads the captured `args` schema for the route
 * under test and applies that schema — `enum`, `minimum`, `maximum`,
 * `default`, `sanitize_callback` — before invoking the real callback. So
 * an `enum` that does not list `blocked`, or a `maximum` of 50, is tested
 * as declared rather than as remembered. It is a simulation driven by the
 * declaration, not WordPress's dispatcher, and the two differ: WP returns
 * a `WP_Error` (400) for a schema violation, which this models as a
 * `rest_invalid_param` refusal.
 *
 * ── ⚠⚠⚠ THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. FEATURE-OFF LEAKS ANYTHING. §4.32.10: every route 404s while the
 *     switch is off. Not 403, not an empty list, and not a body that
 *     admits the feature exists.
 *  2. THE THREE REFUSALS BECOME DISTINGUISHABLE. feature-off and
 *     non-public-row must be byte-identical 404s; ownership denial must be
 *     one unvarying 403 + `not_claimer` regardless of cause.
 *  3. AN OWNERSHIP DENIAL NAMES ITS CAUSE. A distinguishable
 *     `claim_ambiguous` maps the contested validators; `claim_absent` maps
 *     the unclaimed ones.
 *  4. `?status=blocked` IS SILENTLY HONOURED. It has no reconciler marker,
 *     so it would return ORDINARY DRAFTS — a wrong answer that looks
 *     right. It must be refused by the schema.
 *  5. A READ FAILURE PRESENTS AS AN EMPTY LIST. An empty announcement bar
 *     is entirely normal, so that would hide the fault behind a plausible
 *     page.
 *  6. THE PUBLIC LIST VARIES BY VIEWER, or is marked privately cacheable.
 *  7. THE ENDPOINT RESOLVES OWNERSHIP ITSELF. §4.32.2 forbids five
 *     inputs; §7 asserts the source names none of them.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(AnnouncementsEndpoint::class)]
#[CoversClass(AnnouncementFeature::class)]
final class AnnouncementsEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/announcement-endpoint-stubs.php';

        $GLOBALS['__bcc_routes']       = [];
        $GLOBALS['__bcc_options']      = [];
        $GLOBALS['__bcc_current_user'] = 0;
        $GLOBALS['__bcc_posts']        = [];

        AnnouncementsEndpoint::register();
    }

    /** @return list<array{namespace: string, route: string, args: array<string, mixed>}> */
    private function routes(): array
    {
        return $GLOBALS['__bcc_routes'] ?? [];
    }

    /** @return array{namespace: string, route: string, args: array<string, mixed>}|null */
    private function route(string $needle): ?array
    {
        foreach ($this->routes() as $r) {
            if ($r['route'] === $needle) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Apply a route's OWN declared `args` schema the way WordPress does,
     * then invoke its callback.
     *
     * ⚠⚠ THE RULE MODELLED HERE IS WORDPRESS'S, AND GETTING IT WRONG ONCE
     * PRODUCED A FALSE GREEN.
     *
     * `WP_REST_Request::sanitize_params()` defaults an argument to
     * `rest_parse_request_arg` — the function that enforces a schema —
     * **only when the argument declares no `sanitize_callback`**. An
     * argument that declares one therefore opts OUT of schema enforcement
     * unless it ALSO declares a `validate_callback`.
     *
     * An earlier version of this helper applied `enum`, `minimum` and
     * `maximum` unconditionally. That made it STRICTER than WordPress: it
     * reported green on `?limit=51` while real WordPress 6.7.2 answered
     * 200 with 50 rows, because `limit` carried `absint` and no validator.
     * Modelling the real rule is what makes the bound assertions mean
     * something.
     *
     * Real `WP_REST_Server` dispatch is covered separately by
     * `scripts/tests/announcement-real-dispatch.php`, which runs against an
     * actual WordPress install; this harness cannot, because WordPress core
     * is not loadable here.
     *
     * @param array<string, mixed> $params
     * @return \WP_REST_Response|array{error: string, param: string}
     */
    private function dispatch(string $routePath, array $params)
    {
        $route = $this->route($routePath);
        self::assertNotNull($route, 'route not registered: ' . $routePath);

        $argSchema = is_array($route['args']['args'] ?? null) ? $route['args']['args'] : [];

        foreach ($argSchema as $name => $spec) {
            if (!array_key_exists($name, $params)) {
                if (($spec['required'] ?? false) === true) {
                    return ['error' => 'rest_missing_callback_param', 'param' => (string) $name];
                }
                if (array_key_exists('default', $spec)) {
                    $params[$name] = $spec['default'];
                }
                continue;
            }

            $value = $params[$name];

            // WordPress enforces the schema when the arg declares no
            // sanitizer (so `rest_parse_request_arg` is defaulted in), or
            // when it explicitly declares a validator.
            $schemaEnforced = !isset($spec['sanitize_callback'])
                || isset($spec['validate_callback']);

            if ($schemaEnforced) {
                if (isset($spec['enum']) && is_array($spec['enum']) && !in_array($value, $spec['enum'], true)) {
                    return ['error' => 'rest_invalid_param', 'param' => (string) $name];
                }
                if (($spec['type'] ?? null) === 'integer' && !is_int($value)) {
                    return ['error' => 'rest_invalid_param', 'param' => (string) $name];
                }
                if (isset($spec['minimum']) && is_numeric($value) && $value < $spec['minimum']) {
                    return ['error' => 'rest_invalid_param', 'param' => (string) $name];
                }
                if (isset($spec['maximum']) && is_numeric($value) && $value > $spec['maximum']) {
                    return ['error' => 'rest_invalid_param', 'param' => (string) $name];
                }
            }

            if (isset($spec['sanitize_callback']) && is_callable($spec['sanitize_callback'])) {
                $params[$name] = ($spec['sanitize_callback'])($value);
            }
        }

        $callback = $route['args']['callback'] ?? null;
        self::assertIsCallable($callback, 'route has no callable callback: ' . $routePath);

        return $callback(new \WP_REST_Request($params));
    }

    private const PUBLIC_LIST = '/validators/(?P<pageId>\d+)/announcements';
    private const ROTATOR     = '/validators/(?P<pageId>\d+)/announcements/rotator';
    private const DETAIL      = '/validators/(?P<pageId>\d+)/announcements/(?P<id>\d+)';
    private const OWNER_LIST  = '/me/validators/(?P<pageId>\d+)/announcements';

    // ── 1. Registration: exactly four read routes, and no writes ─────────

    public function testExactlyTheFourAcceptedReadRoutesAreRegistered(): void
    {
        $paths = array_map(static fn (array $r): string => $r['route'], $this->routes());
        sort($paths);

        $expected = [self::DETAIL, self::ROTATOR, self::PUBLIC_LIST, self::OWNER_LIST];
        sort($expected);

        self::assertSame($expected, $paths);

        foreach ($this->routes() as $r) {
            self::assertSame('bcc/v1', $r['namespace']);
        }
    }

    /**
     * ⚠⚠ No write route. §4.32.1's publication-authorization MARKER has no
     * writer yet (A3) and §4.32.13's `blocked` needs reconciler arm C —
     * registering a write now would mean inventing where the marker comes
     * from and then depending on the invention.
     *
     * ⚠ It would also make the route visible to `contract-parity-guard` in
     * both directions while §4.32.4 still has it in table form, which is a
     * guard failure.
     */
    public function testNoWriteRouteIsRegistered(): void
    {
        foreach ($this->routes() as $r) {
            $methods = (string) ($r['args']['methods'] ?? '');
            self::assertSame('GET', $methods, $r['route'] . ' must be read-only');
        }
    }

    /**
     * ⚠ The rotator takes NO cursor and NO limit — absent from the schema,
     * not merely ignored, so a caller cannot page it (§4.32.4 caps it at 5
     * and §4.32.12 makes its pagination inert).
     */
    public function testTheRotatorDeclaresNoPagingParameters(): void
    {
        $rotator = $this->route(self::ROTATOR);
        self::assertNotNull($rotator);

        $args = $rotator['args']['args'] ?? [];
        self::assertSame(['pageId'], array_keys($args));
    }

    /**
     * §4.32.4: default 20, max 50 — declared, not just clamped in code.
     *
     * ⚠⚠ The `validate_callback` assertion is the one with teeth, and it
     * exists because the bound did NOT bind without it. WordPress only
     * defaults an argument into schema enforcement when the argument
     * declares no `sanitize_callback`; `limit` declares `absint`, so
     * without an explicit validator `minimum`/`maximum` are decorative and
     * real WordPress 6.7.2 answers `?limit=51` with 200 and 50 rows.
     *
     * Measured, not reasoned: see `scripts/tests/announcement-real-dispatch.php`.
     */
    public function testTheListRoutesDeclareTheContractLimits(): void
    {
        foreach ([self::PUBLIC_LIST, self::OWNER_LIST] as $path) {
            $args = $this->route($path)['args']['args'] ?? [];
            self::assertSame(20, $args['limit']['default'] ?? null, $path);
            self::assertSame(50, $args['limit']['maximum'] ?? null, $path);
            self::assertSame(1, $args['limit']['minimum'] ?? null, $path);
            self::assertSame(
                'rest_validate_request_arg',
                $args['limit']['validate_callback'] ?? null,
                $path . ': without this the declared bounds do not bind'
            );
        }
    }

    /**
     * ⚠ The bound is refused, not clamped — and this pins which of the two
     * it is, because both are defensible and only one is what ships.
     *
     * `clampLimit()` survives as defence in depth: it is the only guard
     * left if someone later removes the validator, and the mutation
     * controls check exactly that.
     */
    #[DataProvider('outOfRangeLimits')]
    public function testAnOutOfRangeLimitIsRefusedRatherThanClamped(int $limit): void
    {
        $GLOBALS['__bcc_options'] = [AnnouncementFeature::OPTION => '1'];

        $result = $this->dispatch(self::PUBLIC_LIST, ['pageId' => 4471, 'limit' => $limit]);

        self::assertIsArray($result, 'limit=' . $limit . ' must be refused');
        self::assertSame('rest_invalid_param', $result['error']);
        self::assertSame('limit', $result['param']);
    }

    /** @return iterable<string, array{int}> */
    public static function outOfRangeLimits(): iterable
    {
        yield 'one over the maximum' => [51];
        yield 'far over'             => [100000];
        yield 'zero'                 => [0];
        yield 'negative'             => [-5];
    }

    /**
     * ⚠⚠ MUST-NOT-HAPPEN #4 — the enum is the refusal. `blocked` and
     * `published` are both absent, for different reasons: `blocked` has no
     * reconciler marker so it would return ordinary drafts, and
     * `published` is served by the public list.
     */
    /**
     * ⚠⚠ `blocked` IS NOW ACCEPTED — this case previously asserted it was not,
     * and the old reason is recorded because it was correct at the time.
     *
     * It read: *"no reconciler marker exists (§4.32.13)"*. That was true: with
     * nothing to distinguish a blocked row from an ordinary draft, accepting
     * the parameter would have returned ordinary drafts — a wrong page that
     * looks right. Reconciler arm C now stamps
     * `_bcc_announcement_blocked_at`, so the filter is real and the parameter
     * means what it says.
     *
     * `published` is accepted too, and for a reason that had to be corrected
     * rather than merely extended: it was refused because "the public list
     * serves it", which is true of the ROWS and false of the CAPABILITIES.
     * §4.32.11 forbids the public list from carrying `capabilities` for any
     * viewer, so an operator had no way to obtain management capabilities for
     * a SET of published rows — only one at a time through detail.
     */
    public function testTheOwnerStatusEnumAcceptsEveryWriteState(): void
    {
        $args = $this->route(self::OWNER_LIST)['args']['args'] ?? [];
        $enum = $args['status']['enum'] ?? null;

        self::assertSame(['draft', 'scheduled', 'blocked', 'published'], $enum);

        // ⚠ The PUBLIC list must not have gained a status parameter in the
        // process. §4.32.11's guarantee is that it carries no status, no body
        // and no capabilities for anybody; a status filter there would be the
        // first step toward serving unpublished rows from a cacheable surface.
        $publicArgs = $this->route(self::PUBLIC_LIST)['args']['args'] ?? [];
        self::assertArrayNotHasKey('status', $publicArgs);

        // ⚠ And the enum must BIND: beside a sanitizer it would be decorative,
        // which is the /members defect. An explicit validator is required.
        self::assertSame(
            'rest_validate_request_arg',
            $args['status']['validate_callback'] ?? null,
            'an enum without an explicit validator does not bind'
        );
    }

    public function testThePublicStateEnumIsExactlyActiveAndArchived(): void
    {
        $args = $this->route(self::PUBLIC_LIST)['args']['args'] ?? [];
        self::assertSame(['active', 'archived'], $args['state']['enum'] ?? null);
        self::assertSame('active', $args['state']['default'] ?? null);
    }

    // ── 2. Feature off — MUST-NOT-HAPPEN #1 and #2 ───────────────────────

    /**
     * ⚠⚠⚠ Every route, 404, with a body that does not mention
     * announcements. The switch being off must not disclose that the
     * feature exists.
     */
    #[DataProvider('allFourRoutes')]
    public function testEveryRouteIs404WhileTheFeatureIsOff(string $path, array $params): void
    {
        $GLOBALS['__bcc_options'] = [];   // default OFF

        $response = $this->dispatch($path, $params);

        self::assertInstanceOf(\WP_REST_Response::class, $response, $path);
        self::assertSame(404, $response->get_status(), $path);

        $body = $response->get_data();
        self::assertSame('bcc_not_found', $body['error']['code'] ?? null, $path);
        self::assertStringNotContainsStringIgnoringCase(
            'announcement',
            (string) ($body['error']['message'] ?? ''),
            $path . ' must not name the feature'
        );
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function allFourRoutes(): iterable
    {
        yield 'public list' => [self::PUBLIC_LIST, ['pageId' => 4471]];
        yield 'rotator'     => [self::ROTATOR, ['pageId' => 4471]];
        yield 'detail'      => [self::DETAIL, ['pageId' => 4471, 'id' => 4480]];
        yield 'owner list'  => [self::OWNER_LIST, ['pageId' => 4471]];
    }

    /**
     * ⚠⚠ MUST-NOT-HAPPEN #2 — the feature-off 404 and the owner-list
     * ownership refusal must NOT be confusable, and feature-off must win.
     * An operator asking for their own drafts while the feature is off
     * gets 404, not 403: a 403 would confirm both that the feature exists
     * and that they manage the page.
     */
    public function testFeatureOffBeatsOwnershipEvenForTheOperator(): void
    {
        $GLOBALS['__bcc_options']      = [];
        $GLOBALS['__bcc_current_user'] = 7;

        $response = $this->dispatch(self::OWNER_LIST, ['pageId' => 4471]);

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        self::assertSame(404, $response->get_status());
        self::assertSame('bcc_not_found', $response->get_data()['error']['code'] ?? null);
    }

    /** The two 404 producers must emit identical bodies (§4.32.4). */
    public function testTheFeatureOffAndNotFoundBodiesAreIdentical(): void
    {
        $GLOBALS['__bcc_options'] = [];
        $featureOff = $this->dispatch(self::PUBLIC_LIST, ['pageId' => 4471]);

        // Feature ON, but an impossible page id — the other 404 path.
        $GLOBALS['__bcc_options'] = [AnnouncementFeature::OPTION => '1'];
        $badPage = $this->dispatch(self::PUBLIC_LIST, ['pageId' => 0]);

        self::assertInstanceOf(\WP_REST_Response::class, $featureOff);
        self::assertInstanceOf(\WP_REST_Response::class, $badPage);
        self::assertSame($featureOff->get_status(), $badPage->get_status());
        self::assertSame($featureOff->get_data(), $badPage->get_data());
    }

    // ── 3. The feature switch itself ─────────────────────────────────────

    /**
     * ⚠ Default OFF, and a CLOSED allowlist rather than a bool cast.
     * WordPress persists booleans as `'1'`/`'0'`, so those four values
     * cover everything the platform produces — and `'off'`/`'false'` must
     * NOT enable the feature, which a `(bool)` cast would.
     */
    #[DataProvider('switchValues')]
    public function testTheSwitchAcceptsOnlyWordPressOwnTruthyValues($stored, bool $expected): void
    {
        if ($stored === '__absent__') {
            $GLOBALS['__bcc_options'] = [];
        } else {
            $GLOBALS['__bcc_options'] = [AnnouncementFeature::OPTION => $stored];
        }

        self::assertSame($expected, AnnouncementFeature::isEnabled(), var_export($stored, true));
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function switchValues(): iterable
    {
        yield 'absent'        => ['__absent__', false];
        yield 'bool true'     => [true, true];
        yield 'int 1'         => [1, true];
        yield 'string 1'      => ['1', true];
        yield 'bool false'    => [false, false];
        yield 'string 0'      => ['0', false];
        yield 'empty'         => ['', false];
        yield 'string off'    => ['off', false];
        yield 'string false'  => ['false', false];
        yield 'string yes'    => ['yes', false];
        yield 'string on'     => ['on', false];
        yield 'int 2'         => [2, false];
        yield 'array'         => [[1], false];
        yield 'null'          => [null, false];
    }

    // ── 4. Malformed input — schema refusals ─────────────────────────────

    /**
     * Driven through each route's OWN declared schema, so these are the
     * refusals WordPress would produce from the registration as written.
     *
     * @param array<string, mixed> $params
     */
    #[DataProvider('malformedParams')]
    public function testMalformedParametersAreRefusedByTheDeclaredSchema(
        string $path,
        array $params,
        string $badParam
    ): void {
        $GLOBALS['__bcc_options'] = [AnnouncementFeature::OPTION => '1'];

        $result = $this->dispatch($path, $params);

        self::assertIsArray($result, 'expected a schema refusal for ' . $badParam);
        self::assertSame('rest_invalid_param', $result['error']);
        self::assertSame($badParam, $result['param']);
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function malformedParams(): iterable
    {
        yield 'unknown state'       => [self::PUBLIC_LIST, ['pageId' => 1, 'state' => 'everything'], 'state'];
        yield 'state casing'        => [self::PUBLIC_LIST, ['pageId' => 1, 'state' => 'ACTIVE'], 'state'];
        // limit bounds live in their own case (testAnOutOfRangeLimitIsRefusedRatherThanClamped)
        yield 'owner limit over'    => [self::OWNER_LIST, ['pageId' => 1, 'limit' => 51], 'limit'];
        // ⚠⚠ `status blocked` AND `status published` were both here as expected
        // REFUSALS and both are gone, each once its filter could mean what it
        // says: arm C stamps a marker that separates a blocked row from an
        // ordinary draft, and the owner list is the only surface that may
        // carry capabilities for a SET of published rows (§4.32.11 forbids the
        // public list from carrying them for anybody). See
        // testTheOwnerStatusEnumAcceptsEveryWriteState.
        //
        // ⚠ The rows below are what keeps the enum BINDING. Deleting a refusal
        // row without leaving a real invalid value behind would retire the
        // only check that the enum rejects anything at all.
        yield 'status nonsense'     => [self::OWNER_LIST, ['pageId' => 1, 'status' => 'whatever'], 'status'];
        yield 'status near-miss'    => [self::OWNER_LIST, ['pageId' => 1, 'status' => 'publish'], 'status'];
        yield 'status empty'        => [self::OWNER_LIST, ['pageId' => 1, 'status' => ''], 'status'];
    }

    /**
     * ⚠ `0` is the only impossible page id a handler ever sees, and it is a
     * 404 before any query.
     *
     * An earlier version of this case also fed `-1`, `'abc'` and `''` to
     * the handler and expected 404. That was fiction: the route pattern is
     * `(?P<pageId>\d+)`, so none of them matches a route at all —
     * WordPress answers `rest_no_route` without entering this plugin.
     * `absint` then turning `-1` into `1` meant the fictional case drove a
     * real repository read and blew up on a null `$wpdb`, which is how the
     * fiction was caught. `'0'` DOES match `\d+`, so the handler guard is
     * the real defence for exactly one value.
     *
     * The unroutable classes are asserted against the pattern below, and
     * confirmed end to end by the real-dispatch script.
     */
    public function testPageIdZeroIs404BeforeAnyQuery(): void
    {
        $GLOBALS['__bcc_options'] = [AnnouncementFeature::OPTION => '1'];

        $response = $this->dispatch(self::PUBLIC_LIST, ['pageId' => 0]);

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        self::assertSame(404, $response->get_status());
        self::assertSame('bcc_not_found', $response->get_data()['error']['code'] ?? null);
    }

    /**
     * Every id segment in every route is digits-only, so a negative,
     * fractional or alphabetic id cannot reach a handler.
     */
    #[DataProvider('unroutableIds')]
    public function testTheRoutePatternsExcludeNonDigitIds(string $candidate): void
    {
        foreach ($this->routes() as $r) {
            $pattern = '#^' . str_replace('/', '\/', $r['route']) . '$#';
            $probe   = preg_replace('/\(\?P<\w+>\\\\d\+\)/', $candidate, $r['route']);
            self::assertSame(
                0,
                preg_match($pattern, (string) $probe),
                $candidate . ' must not match ' . $r['route']
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unroutableIds(): iterable
    {
        yield 'negative'   => ['-1'];
        yield 'alphabetic' => ['abc'];
        yield 'fractional' => ['1.5'];
        yield 'empty'      => [''];
        yield 'spaced'     => [' 1'];
    }

    // ── 5. Source-level guarantees — MUST-NOT-HAPPEN #6 and #7 ───────────

    /**
     * ⚠⚠⚠ MUST-NOT-HAPPEN #7. §4.32.2 forbids `post_author`, mirror claim
     * rows, page metadata, PeepSo membership and read models as
     * authorization inputs — and `current_user_can` is not the operator
     * check either. The endpoint must name none of them in executable
     * code.
     */
    #[DataProvider('forbiddenAuthorizationInputs')]
    public function testTheEndpointNamesNoForbiddenAuthorizationInput(string $token, string $why): void
    {
        $code = self::executableSource('app/Domain/Core/REST/AnnouncementsEndpoint.php');

        self::assertStringNotContainsString($token, $code, $why);
    }

    /** @return iterable<string, array{string, string}> */
    public static function forbiddenAuthorizationInputs(): iterable
    {
        yield 'current_user_can' => ['current_user_can', 'a WP capability is not the operator check'];
        yield 'claim repository' => ['ClaimRepository', 'mirror claim rows are not authority'];
        yield 'peepso'           => ['PeepSo', 'PeepSo membership is not authority'];
        yield 'read model'       => ['ReadModel', 'a denormalized read model is not authority'];
        yield 'wpdb'             => ['wpdb', 'the endpoint touches no database directly (§1)'];
        yield 'post_author'      => ['post_author', 'the claim flow MOVES page authorship'];
    }

    /**
     * ⚠⚠ MUST-NOT-HAPPEN #6, and the shape of it CHANGED once the three
     * named hazards were worked through, so this case documents a retreat
     * rather than a win.
     *
     * An earlier revision shipped `public, max-age=60` on the two public
     * reads, reasoning from §4.32.11's "safely cacheable and its cache key
     * needs no viewer component." That sentence is about CACHE KEYS. It is
     * not a freshness guarantee, and §1.6's endpoint-kind TTL table has no
     * announcements row — so the TTL was an invention.
     *
     * What it would have cost:
     *   - the feature switch would not take effect for up to a minute,
     *     which is the wrong direction to be approximate in for a surface
     *     whose safe state is off;
     *   - archive and pin would lag for everyone INCLUDING the operator,
     *     because a viewer-independent response is one shared cache entry;
     *   - nothing exists to purge it with — no generation counter, no
     *     surrogate key, no hook on archive, pin or the switch.
     *
     * ⚠ Claim revocation is NOT one of those costs, and the difference is
     * what the `Vary` assertions encode: the public reads carry no
     * viewer-dependent field, so revocation cannot change them, while
     * detail and the owner list carry `capabilities` and therefore must
     * vary on the credential.
     */
    public function testCacheHeadersMatchViewerDependence(): void
    {
        $code = self::executableSource('app/Domain/Core/REST/AnnouncementsEndpoint.php');

        self::assertSame(
            0,
            substr_count($code, 'max-age'),
            'no announcement read may carry a TTL the contract does not grant'
        );
        self::assertSame(
            2,
            substr_count($code, "'Cache-Control', 'no-store'"),
            'the two public reads are no-store'
        );
        self::assertSame(
            2,
            substr_count($code, "'Cache-Control', 'private, no-store'"),
            'detail and the owner list are private and uncacheable'
        );
        self::assertSame(
            2,
            substr_count($code, "'Vary', 'Authorization, Cookie'"),
            'exactly the two viewer-dependent reads vary on the credential'
        );
    }

    /**
     * ⚠⚠⚠ The feature check must be the FIRST statement of every handler —
     * before ownership is resolved and before any repository exists.
     *
     * Order is the whole guarantee here. A feature-off request that first
     * resolved ownership would tell an operator "you manage this page" for
     * a feature that is supposed to be absent, and a feature-off request
     * that first read data would do work on a withdrawn surface. The
     * real-dispatch script proves the consequence in SQL — zero
     * announcement queries and zero claim-resolution queries while off,
     * with a positive control showing both fire when on — and this pins
     * the ordering that produces it.
     */
    #[DataProvider('handlerNames')]
    public function testTheFeatureCheckPrecedesAuthorityAndAnyRepository(string $handler): void
    {
        $code = self::executableSource('app/Domain/Core/REST/AnnouncementsEndpoint.php');

        $start = strpos($code, 'function ' . $handler . '(');
        self::assertIsInt($start, 'handler not found: ' . $handler);

        $body  = substr($code, $start);
        $gate  = strpos($body, 'AnnouncementFeature::isEnabled()');
        self::assertIsInt($gate, $handler . ' does not consult the switch at all');

        foreach (['Authority::decide', 'new AnnouncementRepository'] as $later) {
            $at = strpos($body, $later);
            if ($at === false) {
                continue;   // not every handler resolves ownership
            }
            self::assertGreaterThan(
                $gate,
                $at,
                $handler . ': ' . $later . ' must come AFTER the feature check'
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function handlerNames(): iterable
    {
        yield 'publicList' => ['publicList'];
        yield 'rotator'    => ['rotator'];
        yield 'detail'     => ['detail'];
        yield 'ownerList'  => ['ownerList'];
    }

    /**
     * The ownership denial is produced in exactly ONE place and takes its
     * reason from the authority's constant, never a literal — two literals
     * is how a private reason reaches the wire.
     */
    public function testTheOwnershipDenialIsSingleSourcedFromTheAuthority(): void
    {
        $code = self::executableSource('app/Domain/Core/REST/AnnouncementsEndpoint.php');

        self::assertSame(1, substr_count($code, "'bcc_forbidden'"), 'one denial producer');
        self::assertStringContainsString('Authority::PUBLIC_REASON', $code);
        foreach (['claim_ambiguous', 'claim_absent', 'not_a_validator_page'] as $private) {
            self::assertStringNotContainsString(
                $private,
                $code,
                'the private reason ' . $private . ' must never be serialised'
            );
        }
    }

    /**
     * ⚠ MUST-NOT-HAPPEN #5 — a read failure is 503, and the handler must
     * never return an empty page on error. Four `catch (RepositoryException)`
     * arms, one per read, each returning the same 503.
     */
    public function testEveryReadCatchesARepositoryFailureAndReturns503(): void
    {
        $code = self::executableSource('app/Domain/Core/REST/AnnouncementsEndpoint.php');

        self::assertSame(
            4,
            substr_count($code, 'catch (RepositoryException)'),
            'one guarded read per route'
        );
        self::assertSame(4, substr_count($code, 'self::unavailable()'));
        self::assertSame(1, substr_count($code, "'bcc_unavailable'"), 'one 503 producer');
    }

    /**
     * ⚠⚠ THE BEHAVIOURAL HALF of the 503 guarantee, added because the
     * source-shape test above SURVIVED a mutation.
     *
     * Inserting `return ApiResponse::ok(['items' => []]);` as the first
     * statement of a `catch (RepositoryException)` block leaves every count
     * that test makes intact — the catch is still there, `self::unavailable()`
     * is still in the file, there is still one `'bcc_unavailable'` — while
     * making the 503 unreachable. Counting the pieces is not reaching the
     * answer.
     *
     * This drives the REAL repository against a `$wpdb` whose reads set
     * `last_error`, which is exactly what `runPage()` and `findOne()` inspect.
     * So the throw is the production throw.
     *
     * ⚠ The owner list is absent from this provider on purpose, not by
     * oversight: reaching its read requires `Authority::decide()` to return
     * `operator`, which needs a real validator page and a real verified
     * claim — three tables this harness does not have. Its 503 arm is covered
     * end to end by `scripts/tests/announcement-real-dispatch.php`, which
     * seeds the claim chain and then breaks the meta table underneath it.
     */
    #[DataProvider('readFailureRoutes')]
    public function testAReadFailureIs503AndNeverAnEmptyPage(string $path, array $params): void
    {
        $GLOBALS['__bcc_options'] = [AnnouncementFeature::OPTION => '1'];
        $GLOBALS['wpdb']          = new \BccFailingWpdb();

        try {
            $response = $this->dispatch($path, $params);

            self::assertInstanceOf(\WP_REST_Response::class, $response, $path);
            self::assertSame(503, $response->get_status(), $path . ' must be 503, not an empty page');

            $body = $response->get_data();
            self::assertSame('bcc_unavailable', $body['error']['code'] ?? null, $path);

            // ⚠ The specific wrong answer this guards against: a 200 whose
            // body is a plausible empty bar.
            self::assertArrayNotHasKey('data', $body, $path . ' must carry no payload');
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function readFailureRoutes(): iterable
    {
        yield 'public list' => [self::PUBLIC_LIST, ['pageId' => 4471]];
        yield 'archived list' => [self::PUBLIC_LIST, ['pageId' => 4471, 'state' => 'archived']];
        yield 'rotator'     => [self::ROTATOR, ['pageId' => 4471]];
        yield 'detail'      => [self::DETAIL, ['pageId' => 4471, 'id' => 4480]];
    }

    /** Comment-stripped source, so docblocks discussing a token do not trip the check. */
    private static function executableSource(string $relative): string
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        self::assertIsString($src);

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        return $code;
    }

    /**
     * ⚠⚠ A1's guards must NOT consult this switch. §4.32.10: turning the
     * feature off must never *open* the comment-bypass surface, so a
     * switch reference inside the post type would make the safest-looking
     * configuration the least safe one.
     */
    public function testTheCptAndItsGuardsDoNotConsultTheSwitch(): void
    {
        $code = self::executableSource('app/Domain/Core/Announcements/AnnouncementPostType.php');

        self::assertStringNotContainsString(AnnouncementFeature::OPTION, $code);
        self::assertStringNotContainsString('AnnouncementFeature', $code);
    }
}
