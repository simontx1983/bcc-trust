<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * STRUCTURAL INVARIANT: every endpoint-safety method has an INTENTIONAL
 * caller, every caller is classified, and a new unclassified one fails here.
 *
 * ⚠ HONESTLY LABELLED: THIS IS A SOURCE-LEVEL TEST. It reads files and
 * counts call sites. It cannot prove behaviour, and it is not trying to —
 * the behaviour is pinned by `CosmosEndpointEnforcementTest`,
 * `EndpointVerificationWorkflowTest`, `HoldingsCompletenessTest` and
 * `ManualOnlyDiscoveryTest`, all of which run the real code. What THOSE
 * cannot do is fail when somebody adds a NEW caller in a year, on a surface
 * nobody thought to write a behavioural test for. That is this file's only
 * job.
 *
 * ── THE DEFECT IT EXISTS FOR ────────────────────────────────────────────
 * `CosmosFetcher::hasEndpoint()` and
 * `CosmwasmScanEligibility::ENDPOINT_UNVERIFIED` both shipped with careful
 * docblocks describing protections that could not fire: the first had no
 * caller at all, the second had a parameter no caller passed. Both read, in
 * review and in the file, exactly like working safety code. Nothing failed,
 * because nothing was asked.
 *
 * So the rule is: a safety-relevant method is either CALLED from a place
 * this file names, or it does not exist. "Nobody calls it yet" is how the
 * last two got there.
 *
 * ── AND THE VACUOUS-PASS TRAP ───────────────────────────────────────────
 * A source scan whose root does not exist passes loudly having read nothing
 * — the exact failure mode of a guard script printing PASS over zero files.
 * Every sweep below asserts its own denominator first.
 */
#[CoversNothing]
final class EndpointSafetyCallerInventoryTest extends TestCase
{
    /**
     * Every method whose job is to refuse something, and the files allowed
     * to call it.
     *
     * ⚠ THE VALUE IS A CLASSIFICATION, NOT A COUNT. Adding a caller in a
     * listed file is free; adding one anywhere else fails by name and has to
     * be argued for. That asymmetry is deliberate — the risk is not "too many
     * calls", it is "a call from a surface that must not make one".
     *
     * @var array<string, list<string>>
     */
    private const AUTHORIZED_CALLERS = [
        // ── The live probe. NETWORK. Administrator actions only. ────────
        //
        // Each entry is an explicit, capability-checked, POST-only,
        // nonce-checked operator gesture, or the audited endpoint switch.
        // A render, a cron handler, a worker or a REST route appearing here
        // is the thing this inventory exists to catch.
        'CosmosEndpointVerifier::verify' => [
            // The one place the switch proves its destination before moving
            // the row to it.
            'app/Domain/Onchain/Services/CosmosEndpointTransition.php',
        ],
        // ⚠ S8 REMOVED THE RECORDER AND THE THREE ENTRIES BELOW IT.
        //
        // `CosmosEndpointVerifier::verify` used to have a second authorized
        // caller: `CosmosEndpointAuthorization.php`, which probed in order to
        // record the proof it had just made. S2 dropped that `record()` call
        // from the switch, and S8 deleted the class, so the switch's own
        // `CosmosEndpointTransition` is the ONLY caller left — which is what
        // keeps `verify()` satisfying MUST_BE_CALLED.
        //
        // Gone with the class: `authorize` (whose last caller was
        // `DiscoveryRunService`, also deleted), `isAuthorized` (whose three
        // callers were the readiness composer, the health snapshot and the
        // discovery worker, all deleted) and `record`.
        //
        // ⚠ THIS IS NOT "A SAFETY METHOD LOST ITS CALLER AND WE SHRUGGED".
        // The thing those methods granted and withdrew permission FOR — a
        // chain-wide scan refusing as `endpoint_unverified` — no longer
        // exists in any form. `CosmosEndpointVerifier` and
        // `CosmosEndpointPolicy` both survive, and their entries below are
        // unchanged: the verifier because the audited switch still proves its
        // destination live, the policy because `CosmosFetcher::refusalFor()`
        // and the wallet SSRF allowlist both read it.
    ];

    /**
     * Safety-relevant methods that must have at least one production caller.
     *
     * A method here with zero callers is `hasEndpoint()` again.
     *
     * @var list<string>
     */
    private const MUST_BE_CALLED = [
        'CosmosEndpointVerifier::verify',
        // ⚠ S8 REMOVED THE THREE `CosmosEndpointAuthorization` ENTRIES with
        // the class itself. `authorize()`, `isAuthorized()` and `record()`
        // are not unprotected now — they do not exist. `forget()` had
        // already gone callerless in S4. Every reader of the record it kept
        // (`bcc_cosmos_endpoint_authz_<id>`) was a scanner file, and all of
        // them are deleted, so the option is written by nothing and read by
        // nothing; the leftover rows are cleaned up with the S9 migration.
        //
        // What carries the proof forward instead: the audit row's
        // `endpoint_fp`, written by the switch, which is what a later reader
        // needs in order to know WHICH endpoint was verified.
        'CosmosEndpointPolicy::isApproved',
        'CosmosEndpointPolicy::normalize',
        'CosmosEndpointPolicy::fingerprint',
        'CosmosEndpointPolicy::isGoverned',
    ];

    private static function appRoot(): string
    {
        return dirname(__DIR__, 2) . '/app';
    }

    /**
     * Every production PHP file under `app/`, keyed by repo-relative path.
     *
     * @return array<string, string> path => contents
     */
    private static function sources(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $root  = self::appRoot();
        $files = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $rel  = 'app' . substr($path, strlen(str_replace('\\', '/', $root)));
            $files[$rel] = (string) file_get_contents($file->getPathname());
        }

        ksort($files);

        return $cache = $files;
    }

    /**
     * Files calling `Class::method(`, excluding the declaration itself.
     *
     * @return list<string>
     */
    private static function callersOf(string $qualified): array
    {
        [$class, $method] = explode('::', $qualified);

        $callers = [];
        foreach (self::sources() as $path => $code) {
            // The class's own file may call it internally; that is a
            // declaration detail, not a caller to classify — except where
            // the inventory deliberately lists it.
            if (!str_contains($code, $class . '::' . $method . '(')
                && !(str_contains($code, 'self::' . $method . '(') && str_ends_with($path, '/' . $class . '.php'))
            ) {
                continue;
            }
            $callers[] = $path;
        }

        return $callers;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  0. THE DENOMINATOR
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠ ASSERTED FIRST, AND ON PURPOSE. Everything below is a search; a
     * search over an empty tree finds no violations and reports success.
     */
    public function testTheScanRootExistsAndIsPopulated(): void
    {
        self::assertDirectoryExists(self::appRoot(), 'the scan root must exist');

        $sources = self::sources();
        self::assertGreaterThan(
            200,
            count($sources),
            'the sweep must actually be reading the plugin; a handful of files means the root moved'
        );

        // ⚠ WAS `CosmosEndpointAuthorization.php` UNTIL S8 DELETED IT. The
        // denominator has to name a file that still exists, or this guard
        // starts failing for the wrong reason. The verifier is the right
        // replacement: it is the subject of the first MUST_BE_CALLED entry.
        self::assertArrayHasKey(
            'app/Domain/Onchain/Support/CosmosEndpointVerifier.php',
            $sources,
            'the file under inventory must be among the files scanned'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. NO UNCLASSIFIED CALLER
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function inventory(): array
    {
        $out = [];
        foreach (self::AUTHORIZED_CALLERS as $method => $files) {
            $out[$method] = [$method, $files];
        }

        return $out;
    }

    /**
     * @param list<string> $authorized
     */
    #[DataProvider('inventory')]
    public function testEveryCallerIsClassified(string $method, array $authorized): void
    {
        $actual = self::callersOf($method);

        self::assertNotSame([], $actual, $method . ' has no caller at all — see MUST_BE_CALLED');

        $unclassified = array_values(array_diff($actual, $authorized));

        self::assertSame(
            [],
            $unclassified,
            $method . ' is called from a file this inventory does not classify: '
            . implode(', ', $unclassified)
            . ' — add it to AUTHORIZED_CALLERS with a reason, having first checked that the '
            . 'surface is allowed to do what that call does.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. NO PROTECTION WITHOUT A CALLER
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: string}> */
    public static function mustBeCalled(): array
    {
        $out = [];
        foreach (self::MUST_BE_CALLED as $method) {
            $out[$method] = [$method];
        }

        return $out;
    }

    /**
     * ⚠ `hasEndpoint()` IS WHY THIS EXISTS. It was a public method on the
     * fetcher whose entire purpose was to report whether an endpoint was
     * configured, it was never called, and its presence made the class look
     * like it checked. It is now deleted; this test is what stops the next
     * one being written.
     */
    #[DataProvider('mustBeCalled')]
    public function testASafetyMethodWithoutACallerIsADefect(string $method): void
    {
        self::assertNotSame(
            [],
            self::callersOf($method),
            $method . ' has no production caller. Either wire it into the path it is supposed to '
            . 'protect, or delete it — a method that looks like a protection and protects nothing '
            . 'is worse than its absence.'
        );
    }

    /** The deleted method stays deleted. */
    public function testHasEndpointIsGone(): void
    {
        foreach (self::sources() as $path => $code) {
            self::assertStringNotContainsString('function hasEndpoint', $code, $path);
            self::assertStringNotContainsString('hasEndpoint(', $code, $path);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. THE SURFACES THAT MUST NOT PROBE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The live verifier must not be reachable from a render, a cron handler
     * or a REST route.
     *
     * ⚠ THIS IS THE STRUCTURAL HALF of a claim whose behavioural half lives
     * in `EndpointVerificationWorkflowTest` (the recorded check makes no
     * request) and in the admin-render tests (rendering issues none). It is
     * here because those tests cover the surfaces that exist TODAY, and a
     * probe added to a new status page next year would pass all of them.
     *
     * @return array<string, array{0: string}>
     */
    public static function surfacesThatMustNotProbe(): array
    {
        return [
            // ⚠ `the scanner panel view` => Views/DiscoveryScanPanel.php was
            // removed by S4: the file is deleted, and this rule asserts the
            // file it names exists.
            //
            // ⭐ S4 ADDS THE NFT DISCOVERY PAGE, WHICH IS A NEW GUARANTEE.
            // It was deliberately ABSENT from this list before, because it was
            // allowed to probe: `prove_endpoint()` reached the live verifier
            // from `apply_cw_backfill()` and `apply_cw_discovery()`. Both
            // routes are withdrawn, so the page now probes nothing — and
            // listing it here turns that from "we deleted the caller" into
            // "a caller cannot come back without failing a test".
            'the nft discovery page'  => ['app/Domain/Onchain/Admin/NftDiscoveryPage.php'],
            'the chains page'         => ['app/Domain/Onchain/Admin/ChainsPage.php'],
            'the verify page'         => ['app/Domain/Onchain/Admin/VerifyCollectionsPage.php'],
            // ⚠ S8 REMOVED FOUR ENTRIES, AND NOT BECAUSE THE RULE RELAXED.
            // The health snapshot, the maintenance sweep, the discovery
            // worker and the readiness composer are DELETED FILES. This rule
            // asserts the file it names exists, so a stale entry would fail
            // as a missing denominator rather than guard anything.
            //
            // The run executor stays: its file still exists, reduced to the
            // registered refusal, and it must not grow a probe.
            'the run executor'        => ['app/Domain/Onchain/Workers/DiscoveryRunExecutor.php'],
        ];
    }

    #[DataProvider('surfacesThatMustNotProbe')]
    public function testARenderOrCronSurfaceNeverReachesTheLiveVerifier(string $path): void
    {
        $sources = self::sources();
        self::assertArrayHasKey($path, $sources, 'the file named by this rule must exist');

        $code = $sources[$path];

        // ⚠ THE SECOND NEEDLE WAS DROPPED IN S8, NOT RELAXED.
        // It was `CosmosEndpointAuthorization::authorize(`, and S8 deleted
        // that class — so asserting no file calls it is a test that passes
        // because the path cannot exist, which is exactly the kind of
        // assertion this suite is not allowed to keep. That the class is gone
        // everywhere is asserted directly by `ScannerRemovedInventoryTest`.
        //
        // One live call remains to guard, and it is the one that matters:
        // `verify()` issues the uncached outbound request.
        self::assertStringNotContainsString(
            'CosmosEndpointVerifier::verify(',
            $code,
            $path . ' must not make a live endpoint request: it renders, or it runs on a timer. '
            . 'The live proof belongs to the audited endpoint switch, which is an explicit, '
            . 'capability-checked, nonce-checked operator gesture.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  4. ONE POLICY AUTHORITY
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Files allowed to contain an approved host as a STRING LITERAL, and why.
     *
     * @var array<string, string>
     */
    private const HOST_LITERAL_EXEMPT = [
        // The policy itself: the one authority.
        'app/Domain/Onchain/ValueObjects/CosmosEndpointPolicy.php' =>
            'the authority',

        // ⚠ A DIFFERENT QUESTION, NOT A SECOND COPY. This is the
        // wallet-holdings SSRF host allowlist. It is chain-agnostic — it gets
        // a bare URL and cannot know which slug's path rules apply — and it
        // already UNIONS `CosmosEndpointPolicy::approvedHosts()` rather than
        // restating it, with its own header explaining that the legacy
        // entries additionally cover UNGOVERNED Cosmos chains the policy does
        // not describe. Deleting them would turn a hardening into an outage.
        // Pinned below: the union must remain, so this cannot quietly become
        // a standalone list again.
        'app/Domain/Core/Services/wallet/BlockchainQueryService.php' =>
            'the chain-agnostic holdings host allowlist, which unions the policy',
    ];

    /**
     * ⚠ NO SECOND ALLOWLIST. The approved endpoints are a DECISION, and the
     * decision exists in one file. A copy anywhere else is how an endpoint
     * gets approved in one place and refused in another — and the copy is
     * always the one that gets updated last.
     *
     * ⚠ COMMENTS ARE STRIPPED FIRST, with `token_get_all()` rather than a
     * regex. Half a dozen files legitimately NAME these hosts in prose —
     * describing a measured run, explaining a stored request class, recording
     * what production points at. A grep cannot tell prose from policy, and a
     * rule that forces engineers to stop writing down what they measured buys
     * nothing and costs the thing this codebase is actually good at.
     */
    public function testTheApprovedHostsAreADecisionInExactlyOnePlace(): void
    {
        $offenders = [];
        $scanned   = 0;

        foreach (self::sources() as $path => $code) {
            if (isset(self::HOST_LITERAL_EXEMPT[$path])) {
                continue;
            }

            $scanned++;

            foreach (token_get_all($code) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                // Only real string literals — never a comment or a docblock.
                if ($token[0] !== T_CONSTANT_ENCAPSED_STRING && $token[0] !== T_ENCAPSED_AND_WHITESPACE) {
                    continue;
                }
                foreach (['cosmos-api.polkachu.com', 'rest.cosmos.directory'] as $host) {
                    if (str_contains($token[1], $host)) {
                        $offenders[] = $path . ' (' . $host . ')';
                    }
                }
            }
        }

        self::assertGreaterThan(200, $scanned, 'the sweep must have read the tree');

        self::assertSame(
            [],
            array_values(array_unique($offenders)),
            'an approved endpoint host is a live string literal outside the policy: '
            . implode(', ', array_unique($offenders))
            . ' — decide it in CosmosEndpointPolicy and ask it from there.'
        );
    }

    /**
     * The one exempt file must still DERIVE from the policy rather than
     * merely coexisting with it.
     *
     * Without this, the exemption above would be a hole: a file could keep
     * its own three hosts, drop the union, and pass.
     */
    public function testTheHoldingsAllowlistStillUnionsThePolicy(): void
    {
        $path = 'app/Domain/Core/Services/wallet/BlockchainQueryService.php';
        $code = self::sources()[$path] ?? '';

        self::assertNotSame('', $code, $path . ' must exist');
        self::assertStringContainsString(
            'CosmosEndpointPolicy::approvedHosts()',
            $code,
            'the holdings allowlist must admit everything the policy approves, not a copy of it'
        );
    }

    /**
     * And the fetcher reaches its verdict THROUGH the policy rather than by
     * re-deriving one. Anti-vacuity for the rule above: a fetcher that named
     * no hosts because it had no opinion at all would also pass it.
     */
    public function testTheFetcherAsksThePolicy(): void
    {
        $code = self::sources()['app/Domain/Onchain/Fetchers/CosmosFetcher.php'] ?? '';

        self::assertNotSame('', $code);

        foreach (['CosmosEndpointPolicy::isGoverned(', 'CosmosEndpointPolicy::isApproved('] as $call) {
            self::assertStringContainsString($call, $code, 'the fetcher must consult the policy, not its own list');
        }
    }

    /**
     * ⚠ ASKING PERMISSION MUST NOT BE ABLE TO OPEN THE BREAKER.
     *
     * The verifier is a GATE, not work. Routed through {@see ApiRetry} it would
     * charge the circuit breaker on failure — so an operator checking whether a
     * replacement host answers could, by asking, push the chain into a state
     * where nothing may talk to it. Worse, the breaker it opened would be the
     * one keyed to the chain it was trying to repair.
     *
     * ⚠⚠ A GREP WOULD BE WRONG HERE. The verifier's own docblock explains the
     * decision and therefore CONTAINS the word `ApiRetry`; a text search would
     * report the violation it is checking for. This walks tokens, so comments
     * and docblocks are not code.
     */
    public function testTheLiveProofIsNotRoutedThroughTheRetryLayer(): void
    {
        $file = dirname(__DIR__, 2) . '/app/Domain/Onchain/Support/CosmosEndpointVerifier.php';
        $src  = (string) file_get_contents($file);

        self::assertStringContainsString(
            'ApiRetry',
            $src,
            'anti-vacuity: the docblock does mention it, so a grep WOULD have failed here'
        );

        self::assertSame(
            0,
            self::executableReferencesTo($src, 'ApiRetry'),
            'the verifier must not reach the retry layer in executable code'
        );

        // And the walk can see a real one: plant it as CODE, not as a comment.
        $planted = $src . '<?php ApiRetry::request(); ';
        self::assertGreaterThan(
            0,
            self::executableReferencesTo($planted, 'ApiRetry'),
            'anti-vacuity: the token walk detects an executable reference'
        );
    }

    /**
     * The live proof must not override `SafeHttpClient`'s redirect-zero default.
     *
     * ⚠ RESCUED IN S8 FROM `EndpointVerificationWorkflowTest`, which S8
     * deleted: 16 of its 18 cases were about the retired
     * `CosmosEndpointAuthorization` record and the scanner's
     * "is this endpoint proven before scanning" gate, and the two that were
     * not still drove through `DiscoveryRunService`, which is also deleted.
     *
     * This claim had NO other home. `CosmosEndpointPolicyTest` covers the URL
     * shape but is pure-function and never goes near the wire, and the only
     * behavioural driver left is an integration test. So it is pinned here
     * structurally rather than dropped — weaker than the behavioural original,
     * and deliberately not silently lost.
     *
     * Why it matters: the verifier resolves a host an operator typed and is
     * the one place that deliberately dials an endpoint that is not yet
     * trusted. Following a redirect there would let the approved host hand the
     * request to one that was never approved, and the method's own comment
     * ("with redirects disabled a redirect is simply not a 200") is written
     * on the assumption that it does not.
     */
    public function testTheLiveProofDoesNotFollowRedirects(): void
    {
        $src  = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Support/CosmosEndpointVerifier.php'
        );
        $body = self::methodBodyOf($src, 'verify');

        self::assertNotSame('', $body, 'denominator: verify() must be extracted');
        self::assertStringContainsString(
            'SafeHttpClient::get',
            $body,
            'anti-vacuity: this really is the method that makes the request'
        );
        self::assertStringNotContainsString(
            'redirection',
            $body,
            'the verifier must leave SafeHttpClient at its redirect-zero default: following a '
            . 'redirect would let an approved host hand the probe to one that was never approved'
        );
    }

    /** Occurrences of a class name in EXECUTABLE code, ignoring comments and strings. */
    private static function executableReferencesTo(string $src, string $name): int
    {
        $found = 0;
        foreach (token_get_all($src) as $token) {
            if (is_array($token) && $token[0] === T_STRING && $token[1] === $name) {
                $found++;
            }
        }

        return $found;
    }

    /**
     * One method's body, by a balanced TOKEN walk rather than a brace count.
     *
     * Comments are stripped first, so a docblock that mentions `redirection`
     * in prose cannot fail the caller above — and braces inside strings,
     * heredocs or interpolation cannot end the walk early.
     */
    private static function methodBodyOf(string $src, string $method): string
    {
        $stripped = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                continue;
            }
            $stripped .= is_array($t) ? $t[1] : $t;
        }

        $tokens = token_get_all($stripped);
        $n      = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $method) {
                continue;
            }
            while ($j < $n && $tokens[$j] !== '{') {
                $j++;
            }
            $depth = 0;
            $body  = '';
            for (; $j < $n; $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($text === '{') {
                    $depth++;
                }
                if ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $body;
                    }
                }
                $body .= $text;
            }
        }

        return '';
    }
}
