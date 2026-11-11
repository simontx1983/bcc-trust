<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * STRUCTURAL INVARIANT: the CosmWasm chain-wide scanner is GONE — not
 * frozen, not disabled, not behind a flag — and the bounded,
 * operator-initiated capabilities that lived beside it are all still here.
 *
 * ── WHY THIS FILE EXISTS AT ALL ─────────────────────────────────────────
 * S8 deleted 26 classes. The behavioural suites that covered them went with
 * them, which is correct: a test for deleted code is either deleted or
 * vacuous. What no surviving behavioural test can do is fail when somebody
 * re-adds one of those classes next year, or quietly reaches a retained
 * primitive from a new chain-wide loop. That is this file's only job.
 *
 * It also carries the ONE assertion rescued from
 * `ScannerBackgroundEntryPointsAreFrozenTest`, which S8 deleted along with
 * the other freeze-behaviour suites:
 * {@see testTheExecutorHookIsStillRegisteredSoAPendingActionIsRefusedNotUnhandled}.
 * That hook must stay bound until an operator confirms the Action Scheduler
 * queue is drained, so the assertion has to outlive its original home.
 *
 * ── HONESTLY LABELLED: THIS IS A SOURCE-LEVEL TEST ──────────────────────
 * It tokenises files and looks for references. It cannot prove behaviour and
 * does not try to. Section 3 exists because removal tests alone cannot catch
 * OVER-deletion: a tree with the retained features ripped out would pass
 * every assertion in sections 1 and 2.
 *
 * ── COMMENTS ARE STRIPPED FIRST ─────────────────────────────────────────
 * With `token_get_all()`, not a regex. Retained files legitimately NAME the
 * removed classes in prose — several explain precisely why they no longer
 * use them — and a grep cannot tell a docblock from a reference.
 *
 * ── THE VACUOUS-PASS TRAP ───────────────────────────────────────────────
 * A sweep over an empty tree finds no violations and reports success. Every
 * sweep below asserts its denominator first.
 */
#[CoversNothing]
final class ScannerRemovedInventoryTest extends TestCase
{
    /**
     * The 26 classes S8 deleted.
     *
     * ⚠ `DiscoveryRunExecutor` is deliberately NOT in this list. It survives
     * as the registered refusal for its one-shot hook — see section 1.
     *
     * @var list<string>
     */
    private const REMOVED_CLASSES = [
        'CosmosEndpointAuthorization',
        'CosmwasmCodeFamilyRepository',
        'CosmwasmContractRepository',
        'CosmwasmDiscoveryGate',
        'CosmwasmDiscoveryHealthSnapshot',
        'CosmwasmDiscoveryService',
        'CosmwasmDiscoveryWorker',
        'CosmwasmEnumerationFailure',
        'CosmwasmEvidenceNarrator',
        'CosmwasmOneShotDiscoveryCommand',
        'CosmwasmPassReport',
        'CosmwasmPassStopReason',
        'CosmwasmScanEligibility',
        'DiscoveryJobKind',
        'DiscoveryReadiness',
        'DiscoveryRunError',
        'DiscoveryRunMaintenance',
        'DiscoveryRunRepository',
        'DiscoveryRunService',
        'DiscoveryRunStatusReader',
        'DiscoveryScanMode',
        'DiscoveryScanProgress',
        'DiscoveryScanSession',
        'DiscoverySessionTotals',
        'ScannerFreeze',
    ];

    /**
     * Wire primitives that SURVIVE because targeted validation, the piece
     * endpoint and holdings use them. Each takes one submitted contract and
     * is never chain-wide.
     *
     * @var list<string>
     */
    private const RETAINED_FETCHER_PRIMITIVES = [
        'probeCw721',
        'chainHasNoWasmFor',
        'numTokensCountFor',
        'fetchContractInfo',
        'testCw721ContractInfo',
        'fetchTokenMetadata',
        'cw721OwnerOf',
    ];

    /**
     * Methods S8 removed from `CosmosFetcher` because only the chain-wide
     * scanner drove them.
     *
     * @var list<string>
     */
    private const REMOVED_FETCHER_PRIMITIVES = [
        'listCodeFamilies',
        'listContractsForCodeId',
        'fetchContractCodeId',
        'parseCodeInfosPage',
        'parseContractsPage',
        'parseNextKey',
        'parseWasmCodeId',
        'wasmContractPath',
    ];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Comment-stripped source of every production PHP file, by repo path.
     *
     * Covers `app/`, `includes/` and the plugin bootstrap, because a
     * registration or a migration can reference a class just as easily as a
     * service can.
     *
     * @return array<string, string>
     */
    private static function sources(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $root = str_replace('\\', '/', self::repoRoot());
        $out  = [];

        foreach (['app', 'includes'] as $dir) {
            $base = self::repoRoot() . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
            foreach ($it as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');
                $out[$path] = self::stripComments((string) file_get_contents($file->getPathname()));
            }
        }

        foreach (['bcc-trust.php', 'uninstall.php'] as $single) {
            $abs = self::repoRoot() . '/' . $single;
            if (is_file($abs)) {
                $out[$single] = self::stripComments((string) file_get_contents($abs));
            }
        }

        ksort($out);

        return $cache = $out;
    }

    private static function stripComments(string $src): string
    {
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        return $code;
    }

    /** @return list<string> files whose CODE (not comments) contains $needle */
    private static function filesContaining(string $needle): array
    {
        $hits = [];
        foreach (self::sources() as $path => $code) {
            if (str_contains($code, $needle)) {
                $hits[] = $path;
            }
        }

        return $hits;
    }

    /** Comment-stripped body of one method, found by a balanced token walk. */
    private static function methodBody(string $path, string $method): string
    {
        $tokens = token_get_all(self::sources()[$path] ?? '');
        $n      = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while ($j < $n && !(is_array($tokens[$j]) && $tokens[$j][0] === T_STRING)) {
                $j++;
            }
            if ($j >= $n || $tokens[$j][1] !== $method) {
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

    // ═══════════════════════════════════════════════════════════════════
    //  0. THE DENOMINATOR
    // ═══════════════════════════════════════════════════════════════════

    public function testTheScanRootIsPopulated(): void
    {
        $sources = self::sources();

        self::assertGreaterThan(
            400,
            count($sources),
            'anti-vacuity: the production tree must actually have been scanned'
        );
        self::assertArrayHasKey('bcc-trust.php', $sources, 'the plugin bootstrap must be in the sweep');
        self::assertArrayHasKey(
            'app/Domain/Onchain/Fetchers/CosmosFetcher.php',
            $sources,
            'the retained fetcher must be in the sweep'
        );
        self::assertNotSame(
            '',
            $sources['app/Domain/Onchain/Fetchers/CosmosFetcher.php'],
            'denominator: a scanned file must have readable code'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. THE RESCUED ASSERTION — the hook stays bound
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Rescued from `ScannerBackgroundEntryPointsAreFrozenTest`.
     *
     * `bcc_discovery_run_execute` is a ONE-SHOT hook. Deleting what created
     * work does not retract work already queued, and an unregistered
     * callback on a scheduled event is drift a health check has to explain.
     */
    public function testTheExecutorHookIsStillRegisteredSoAPendingActionIsRefusedNotUnhandled(): void
    {
        $source = (string) file_get_contents(self::repoRoot() . '/bcc-trust.php');

        self::assertNotSame('', $source, 'denominator: the bootstrap must be readable');
        self::assertStringContainsString(
            'DiscoveryRunExecutor::HOOK',
            $source,
            'the async hook keeps a handler so a queued action is refused rather than erroring'
        );
        self::assertStringContainsString(
            'DiscoveryRunExecutor::handleQueuedAction((int) $runId)',
            $source,
            'the hook routes to the refusing handler'
        );
    }

    /**
     * The refusal is now UNCONDITIONAL, which is stronger than the flag it
     * replaced: a flag is a thing that can be flipped, and S8 removed the
     * implementation it would have flipped back on.
     */
    public function testTheExecutorRefusesWithoutConsultingAnyFlagAndTouchesNoRun(): void
    {
        $body = self::methodBody('app/Domain/Onchain/Workers/DiscoveryRunExecutor.php', 'handleQueuedAction');

        self::assertNotSame('', $body, 'denominator: handleQueuedAction() must be extracted');
        self::assertStringContainsString("'retired'", $body, 'anti-vacuity: this really is the refusal');

        foreach (['ScannerFreeze', 'frozen(', 'execute(', 'claim(', 'DiscoveryRunRepository', 'markFailed'] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $body,
                'the refusal must not consult a flag, claim a run, or reach an implementation'
            );
        }
    }

    /** There is no implementation left behind the refusal. */
    public function testTheExecutorNoLongerCarriesAnImplementation(): void
    {
        $path = 'app/Domain/Onchain/Workers/DiscoveryRunExecutor.php';
        $code = self::sources()[$path] ?? '';

        self::assertNotSame('', $code, 'denominator: the executor must be scanned');
        self::assertStringContainsString('handleQueuedAction', $code, 'anti-vacuity: the refusal survives');
        self::assertSame(
            '',
            self::methodBody($path, 'execute'),
            'execute() must be gone — the freeze used to guard it, now there is nothing to guard'
        );
        self::assertSame(
            '',
            self::methodBody($path, 'continueSession'),
            'the session continuation must be gone with it'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. GONE UNDER ANY SPELLING
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: string}> */
    public static function removedClasses(): array
    {
        $out = [];
        foreach (self::REMOVED_CLASSES as $class) {
            $out[$class] = [$class];
        }

        return $out;
    }

    #[DataProvider('removedClasses')]
    public function testNoProductionCodeReferencesARemovedClass(string $class): void
    {
        self::assertSame(
            [],
            self::filesContaining($class),
            $class . ' is referenced by production CODE (not a comment) but S8 deleted it'
        );
    }

    #[DataProvider('removedClasses')]
    public function testTheRemovedClassFileDoesNotExist(string $class): void
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::repoRoot() . '/app'));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getBasename() === $class . '.php') {
                $found[] = $file->getPathname();
            }
        }

        self::assertSame([], $found, $class . '.php is back on disk');
    }

    /** @return array<string, array{0: string}> */
    public static function removedFetcherPrimitives(): array
    {
        $out = [];
        foreach (self::REMOVED_FETCHER_PRIMITIVES as $m) {
            $out[$m] = [$m];
        }

        return $out;
    }

    #[DataProvider('removedFetcherPrimitives')]
    public function testTheChainWideWirePrimitivesAreGone(string $method): void
    {
        self::assertSame(
            '',
            self::methodBody('app/Domain/Onchain/Fetchers/CosmosFetcher.php', $method),
            $method . '() is back on CosmosFetcher — it only ever served chain-wide enumeration'
        );
    }

    /** The supervised one-shot CLI command is not registered under any name. */
    public function testTheRetiredCliCommandIsNotRegistered(): void
    {
        $bootstrap = self::sources()['bcc-trust.php'] ?? '';

        self::assertNotSame('', $bootstrap, 'denominator: the bootstrap must be scanned');
        self::assertStringContainsString(
            'add_command',
            $bootstrap,
            'anti-vacuity: WP-CLI registrations really are in this file'
        );
        self::assertStringNotContainsString('bcc-trust cosmwasm', $bootstrap);
        self::assertStringNotContainsString('CosmwasmOneShotDiscoveryCommand', $bootstrap);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. WHAT MUST STILL BE HERE
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: string}> */
    public static function retainedFetcherPrimitives(): array
    {
        $out = [];
        foreach (self::RETAINED_FETCHER_PRIMITIVES as $m) {
            $out[$m] = [$m];
        }

        return $out;
    }

    #[DataProvider('retainedFetcherPrimitives')]
    public function testTheBoundedPerContractPrimitivesSurvive(string $method): void
    {
        self::assertNotSame(
            '',
            self::methodBody('app/Domain/Onchain/Fetchers/CosmosFetcher.php', $method),
            $method . '() is gone, but targeted validation / the piece endpoint / holdings need it'
        );
    }

    /** Targeted validation still reaches its bounded Cosmos probe. */
    public function testTargetedValidationStillReachesItsProbe(): void
    {
        $probe = self::sources()['app/Domain/Onchain/Services/Validation/CosmosContractProbe.php'] ?? '';

        self::assertNotSame('', $probe, 'denominator: the Cosmos contract probe must exist');
        foreach (['probeCw721', 'chainHasNoWasmFor', 'numTokensCountFor', 'fetchContractInfo'] as $needle) {
            self::assertStringContainsString($needle, $probe, 'targeted validation lost ' . $needle);
        }
        self::assertStringContainsString(
            'CosmwasmClassifier',
            $probe,
            'the probe still classifies its evidence through the retained classifier'
        );
    }

    /** The capability control plane and manual intake are untouched. */
    public function testTheCapabilityControlPlaneSurvives(): void
    {
        $page = self::sources()['app/Domain/Onchain/Admin/NftDiscoveryPage.php'] ?? '';

        self::assertNotSame('', $page, 'denominator: the capability page must exist');
        foreach ([
            'ACTION_CAP_PRODUCT_ENABLE',
            'ACTION_CAP_MANUAL_ENABLE',
            'ACTION_CAP_DRIVER_INHERIT',
            'ACTION_CAP_STALE_REMOVE',
            'NftDiscoveryControlPlaneSnapshot',
            'ManualCollectionIntakeService',
        ] as $needle) {
            self::assertStringContainsString($needle, $page, 'the capability control plane lost ' . $needle);
        }
    }

    /** The audited endpoint switch still proves its destination live. */
    public function testTheEndpointSwitchStillVerifiesLive(): void
    {
        $transition = self::sources()['app/Domain/Onchain/Services/CosmosEndpointTransition.php'] ?? '';

        self::assertNotSame('', $transition, 'denominator: the endpoint switch must exist');
        self::assertStringContainsString(
            'CosmosEndpointVerifier',
            $transition,
            'the switch must still prove the target before moving the row to it'
        );
        self::assertStringNotContainsString(
            'CosmosEndpointAuthorization',
            $transition,
            'the authorization record retired in S2/S8 and must not come back'
        );
    }

    /**
     * `ProviderRequestBudget` survives; only its stage reserve went.
     *
     * The reserve existed so the incremental pass's four stages could not
     * let the first spend everything. Nothing else ever set it, so with that
     * pass deleted it was permanently zero.
     */
    public function testTheBudgetSurvivesWithoutItsStageReserve(): void
    {
        $path   = 'app/Domain/Onchain/Support/ProviderRequestBudget.php';
        $budget = self::sources()[$path] ?? '';

        self::assertNotSame('', $budget, 'denominator: the budget must exist');
        foreach (['canSpend', 'available', 'exhausted', 'timedOut', 'spend'] as $kept) {
            self::assertStringContainsString($kept, $budget, 'the budget lost ' . $kept . '()');
        }
        self::assertSame(
            '',
            self::methodBody($path, 'reserve'),
            'reserve() is back but nothing can set it'
        );

        $readers = self::filesContaining('ProviderRequestBudget');
        self::assertNotSame([], $readers, 'anti-vacuity: the budget must have readers');
        foreach ([
            'app/Domain/Onchain/Services/HoldingsService.php',
            'app/Domain/Onchain/Services/ContractValidator.php',
            'app/Domain/Onchain/Services/NftGroupGateService.php',
        ] as $required) {
            self::assertContains($required, $readers, $required . ' must still meter its provider work');
        }
    }
}
