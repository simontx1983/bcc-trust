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
            // The recorder, which is itself only reached from the actions
            // classified under `CosmosEndpointAuthorization::authorize`.
            'app/Domain/Onchain/Support/CosmosEndpointAuthorization.php',
        ],
        'CosmosEndpointAuthorization::authorize' => [
            // admin_post: enable discovery for a chain, and the inline
            // backfill — the only control on that page that spends provider
            // budget.
            'app/Domain/Onchain/Admin/NftDiscoveryPage.php',
            // admin_post / supervised WP-CLI: request or retry a scan.
            'app/Domain/Onchain/Services/DiscoveryRunService.php',
        ],

        // ── The recorded check. NO NETWORK. Callable from anywhere. ─────
        //
        // This list is long ON PURPOSE: the whole design is that checking is
        // cheap enough for renders, snapshots and per-chunk worker loops.
        // It is still enumerated, so that a new reader is a decision.
        'CosmosEndpointAuthorization::isAuthorized' => [
            'app/Domain/Onchain/Support/DiscoveryReadiness.php',
            'app/Domain/Onchain/Services/CosmwasmDiscoveryHealthSnapshot.php',
            'app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php',
        ],

        // ── The write. One recorder, one withdrawal. ────────────────────
        'CosmosEndpointAuthorization::record' => [
            'app/Domain/Onchain/Support/CosmosEndpointAuthorization.php',
            // The switch records the proof it just made rather than proving
            // the same endpoint twice.
            'app/Domain/Onchain/Services/CosmosEndpointTransition.php',
        ],
        'CosmosEndpointAuthorization::forget' => [
            // Opting a chain out withdraws the authorization with it.
            'app/Domain/Onchain/Admin/NftDiscoveryPage.php',
        ],
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
        'CosmosEndpointAuthorization::authorize',
        'CosmosEndpointAuthorization::isAuthorized',
        'CosmosEndpointAuthorization::record',
        'CosmosEndpointAuthorization::forget',
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

        self::assertArrayHasKey(
            'app/Domain/Onchain/Support/CosmosEndpointAuthorization.php',
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
            'the scanner panel view'  => ['app/Domain/Onchain/Admin/Views/DiscoveryScanPanel.php'],
            'the chains page'         => ['app/Domain/Onchain/Admin/ChainsPage.php'],
            'the verify page'         => ['app/Domain/Onchain/Admin/VerifyCollectionsPage.php'],
            'the health snapshot'     => ['app/Domain/Onchain/Services/CosmwasmDiscoveryHealthSnapshot.php'],
            'the maintenance sweep'   => ['app/Domain/Onchain/Workers/DiscoveryRunMaintenance.php'],
            'the run executor'        => ['app/Domain/Onchain/Workers/DiscoveryRunExecutor.php'],
            'the discovery worker'    => ['app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php'],
            'the readiness composer'  => ['app/Domain/Onchain/Support/DiscoveryReadiness.php'],
        ];
    }

    #[DataProvider('surfacesThatMustNotProbe')]
    public function testARenderOrCronSurfaceNeverReachesTheLiveVerifier(string $path): void
    {
        $sources = self::sources();
        self::assertArrayHasKey($path, $sources, 'the file named by this rule must exist');

        $code = $sources[$path];

        foreach ([
            'CosmosEndpointVerifier::verify(',
            'CosmosEndpointAuthorization::authorize(',
        ] as $liveCall) {
            self::assertStringNotContainsString(
                $liveCall,
                $code,
                $path . ' must not make a live endpoint request: it renders, or it runs on a timer, '
                . 'or it runs once per worker chunk. Read the recorded proof instead '
                . '(CosmosEndpointAuthorization::isAuthorized).'
            );
        }
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
}
