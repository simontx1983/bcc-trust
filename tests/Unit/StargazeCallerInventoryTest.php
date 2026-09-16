<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * STRUCTURAL INVARIANT: there is NO path to the Stargaze marketplace API
 * left in this plugin, and none of the surfaces that used to reach it can
 * grow one back quietly.
 *
 * ⚠ HONESTLY LABELLED: THIS IS A SOURCE-LEVEL TEST. It tokenises files and
 * looks for call sites. It cannot prove behaviour and does not try to — the
 * behaviour is pinned by `StargazeFanOutRemovedTest` (the five former
 * paths, with transport spies and multiple wallets),
 * `CosmosFetcherDiscoveryTest` and `VerifyCollectionsRenderIsolationTest`.
 * What those cannot do is fail when somebody adds a NEW caller next year,
 * on a surface nobody wrote a behavioural test for. That is this file's
 * only job.
 *
 * ── WHAT WAS REMOVED, AND WHY THE GUARD CHANGED SHAPE ───────────────────
 * PR 7.11's ancestor of this file CLASSIFIED the callers, because five of
 * them were still expected to exist. PR 7.12 deleted the client itself, so
 * the correct assertion is no longer "every caller is classified" but
 * "there are none, and the class is gone".
 *
 * The five paths that used to send a member's Cosmos address — in a URL
 * path, with WordPress's site-identifying default user-agent — were: the
 * wallet-verify async seed, the four-hourly refresh cron, a job an
 * ANONYMOUS creator-gallery GET could schedule, the signed-in stance panel
 * GET, and the set-stance POST.
 *
 * ── COMMENTS ARE STRIPPED FIRST ─────────────────────────────────────────
 * With `token_get_all()`, not a regex. Files legitimately NAME the removed
 * API in prose (several explain why they no longer call it), and a grep
 * cannot tell a docblock from a call.
 *
 * ── THE VACUOUS-PASS TRAP ───────────────────────────────────────────────
 * A scan over an empty tree finds no violations and reports success. Every
 * sweep asserts its denominator first.
 */
#[CoversNothing]
final class StargazeCallerInventoryTest extends TestCase
{
    /**
     * Files still allowed to call `->fetch_collections(`.
     *
     * The method survives for EVM and Solana, which have real wallet
     * discovery drivers. `Plugin.php` is NOT here any more: its caller was
     * the gallery-refresh handler an anonymous GET could schedule, and
     * both the handler and the dispatch are gone.
     *
     * @var list<string>
     */
    private const FETCH_COLLECTIONS_CALLERS = [
        'app/Domain/Onchain/Services/ChainRefreshService.php',
        'app/Domain/Onchain/Services/WalletSeedService.php',
    ];

    /** @var list<string> */
    private const STANCE_SERVICE_CALLERS = [
        'app/Domain/Onchain/REST/CollectionStancesEndpoint.php',
    ];

    private static function appRoot(): string
    {
        return dirname(__DIR__, 2) . '/app';
    }

    /**
     * Comment-stripped source of every production PHP file, by repo path.
     *
     * @return array<string, string>
     */
    private static function sources(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $root = str_replace('\\', '/', self::appRoot());
        $out  = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::appRoot()));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = 'app' . substr(str_replace('\\', '/', $file->getPathname()), strlen($root));
            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $t) {
                if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                    continue;
                }
                $code .= is_array($t) ? $t[1] : $t;
            }
            $out[$path] = $code;
        }
        ksort($out);

        return $cache = $out;
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
        $code   = self::sources()[$path] ?? '';
        $tokens = token_get_all($code);
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
        self::assertDirectoryExists(self::appRoot());
        $sources = self::sources();
        self::assertGreaterThan(200, count($sources), 'the sweep must be reading the plugin, not an empty tree');

        // Files the later sweeps name — if a rename silently removed one,
        // the sweep over it would pass by scanning nothing.
        foreach ([
            'app/Domain/Onchain/Services/CollectionStanceService.php',
            'app/Domain/Onchain/Fetchers/CosmosFetcher.php',
            'app/Domain/Core/REST/CreatorGalleryEndpoint.php',
            'app/Domain/Core/Plugin.php',
        ] as $path) {
            self::assertArrayHasKey($path, $sources);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. THE CLIENT IS GONE — not merely uncalled
    // ═══════════════════════════════════════════════════════════════════

    public function testTheClientFileNoLongerExists(): void
    {
        self::assertArrayNotHasKey(
            'app/Domain/Onchain/Support/StargazeMarketplaceApi.php',
            self::sources(),
            'the marketplace client must be deleted, not left dormant for a future caller to find'
        );
    }

    public function testNoFileCallsTheClient(): void
    {
        self::assertSame([], self::filesContaining('StargazeMarketplaceApi'));
        self::assertSame([], self::filesContaining('profileCollections('));
    }

    /**
     * The hostname is gone from CODE everywhere. Prose may still explain
     * the removal — that is what the comment strip is for.
     */
    public function testTheHostnameAppearsInNoProductionCode(): void
    {
        self::assertSame([], self::filesContaining('stargaze-apis.com'));
        self::assertSame([], self::filesContaining('marketplace-api'));
    }

    /** The driver key went with it, under any spelling. */
    public function testTheDriverKeyIsGone(): void
    {
        self::assertSame([], self::filesContaining('DRIVER_STARGAZE_MARKETPLACE'));
        self::assertSame([], self::filesContaining("'stargaze_marketplace'"));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. THE FIVE FORMER PATHS CANNOT REACH A WALLET FAN-OUT
    // ═══════════════════════════════════════════════════════════════════

    public function testOnlyTheTwoIndexedChainCallersRemainForFetchCollections(): void
    {
        $callers = self::filesContaining('->fetch_collections(');

        self::assertNotSame([], $callers, 'anti-vacuity: the surviving callers must actually be found');
        self::assertSame(
            self::FETCH_COLLECTIONS_CALLERS,
            $callers,
            'a new fetch_collections caller appeared — it must not be a render, an anonymous request, '
            . 'or anything that could reach a chain without a documented discovery driver'
        );
    }

    /**
     * The Cosmos driver must not re-advertise wallet discovery. This is the
     * capability all three callers gate on, so re-adding it is the single
     * edit that would restore the fan-out everywhere at once.
     */
    public function testCosmosDoesNotAdvertiseWalletDiscovery(): void
    {
        $body = self::methodBody('app/Domain/Onchain/Fetchers/CosmosFetcher.php', 'supports_feature');
        self::assertNotSame('', $body, 'denominator: supports_feature() must be extracted');
        self::assertStringContainsString('holdings_count', $body, 'anti-vacuity: the feature list must be in there');
        self::assertStringNotContainsString("'collection'", $body);
    }

    /** And fetch_collections itself stays inert on Cosmos. */
    public function testCosmosFetchCollectionsHasNoTransport(): void
    {
        $body = self::methodBody('app/Domain/Onchain/Fetchers/CosmosFetcher.php', 'fetch_collections');
        self::assertNotSame('', $body, 'denominator: fetch_collections() must be extracted');

        foreach (['ApiRetry', 'wp_remote_', 'SafeHttpClient', 'curl_', 'Stargaze', 'lcdGet'] as $needle) {
            self::assertStringNotContainsString($needle, $body);
        }
    }

    /**
     * An anonymous GET may not schedule anything. The gallery endpoint's
     * permission callback is `__return_true`, so a dispatch here is a
     * deferred disclosure triggered by a stranger.
     */
    public function testTheAnonymousGalleryEndpointSchedulesNothing(): void
    {
        $code = self::sources()['app/Domain/Core/REST/CreatorGalleryEndpoint.php'] ?? '';
        self::assertNotSame('', $code, 'denominator: the gallery endpoint must be scanned');
        self::assertStringContainsString('__return_true', $code, 'anti-vacuity: this really is the anonymous route');

        foreach ([
            'wp_schedule_single_event', 'wp_schedule_event', 'REFRESH_HOOK',
            'bcc_onchain_holdings_refresh', '->fetch_collections(', 'ApiRetry', 'wp_remote_',
        ] as $needle) {
            self::assertStringNotContainsString($needle, $code, "the anonymous gallery route must not contain {$needle}");
        }
    }

    /** And nothing else registers a handler for the retired hook. */
    public function testTheRetiredRefreshHookHasNoHandler(): void
    {
        self::assertSame([], self::filesContaining('bcc_onchain_holdings_refresh'));
    }

    /**
     * The stance service is member-facing and signed-in, but it is still a
     * REST read: it may not carry outbound transport of its own. Its Cosmos
     * evidence comes from HoldingsService (a bounded LCD walk over verified
     * collections), which is a different thing from a marketplace client.
     *
     * @return array<string, array{0: string}>
     */
    public static function forbiddenInTheStanceService(): array
    {
        $out = [];
        foreach (['StargazeMarketplaceApi', 'profileCollections(', 'ApiRetry', 'wp_remote_', 'SafeHttpClient', 'curl_'] as $needle) {
            $out[$needle] = [$needle];
        }

        return $out;
    }

    #[DataProvider('forbiddenInTheStanceService')]
    public function testTheStanceServiceHasNoOutboundPath(string $needle): void
    {
        $code = self::sources()['app/Domain/Onchain/Services/CollectionStanceService.php'] ?? '';
        self::assertNotSame('', $code, 'the stance service must exist to be checked');
        self::assertStringNotContainsString($needle, $code);
    }

    public function testEveryStanceServiceCallerIsClassified(): void
    {
        self::assertSame(self::STANCE_SERVICE_CALLERS, self::filesContaining('CollectionStanceService::'));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. NO ADMINISTRATOR SURFACE REACHES ANY OF IT  (PR 7.11 / 7.12)
    // ═══════════════════════════════════════════════════════════════════

    public function testNoAdminFileReachesAWalletFanOut(): void
    {
        $adminFiles = array_values(array_filter(
            array_keys(self::sources()),
            static fn(string $p): bool => str_contains($p, '/Admin/')
        ));
        self::assertGreaterThan(10, count($adminFiles), 'denominator: the Admin/ trees must be scanned');

        $offenders = [];
        foreach ($adminFiles as $path) {
            $code = self::sources()[$path];
            foreach (['StargazeMarketplaceApi', 'CollectionStanceService', 'profileCollections(', '->fetch_collections(', 'panelForUser('] as $needle) {
                if (str_contains($code, $needle)) {
                    $offenders[] = "{$path} ({$needle})";
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * The demand service is what the Verify page calls while rendering, so
     * it may contain no outbound transport, no wallet-address lookup and no
     * cache or option write at all. (PR 7.11's invariant, still pinned.)
     *
     * @return array<string, array{0: string}>
     */
    public static function forbiddenInTheDemandService(): array
    {
        $out = [];
        foreach ([
            'StargazeMarketplaceApi', 'ApiRetry', 'wp_remote_', 'SafeHttpClient', 'curl_',
            'WalletRepository', 'wallet_address',
            'wp_cache_set(', 'wp_cache_add(', 'set_transient(', 'update_option(', 'add_option(',
        ] as $needle) {
            $out[$needle] = [$needle];
        }

        return $out;
    }

    #[DataProvider('forbiddenInTheDemandService')]
    public function testTheDemandServiceHasNoOutboundOrWritePath(string $needle): void
    {
        $code = self::sources()['app/Domain/Onchain/Services/CollectionDemandService.php'] ?? '';
        self::assertNotSame('', $code, 'the demand service must exist to be checked');
        self::assertStringNotContainsString($needle, $code);
    }

    public function testTheVerifyRenderMethodHasNoOutboundOrWritePath(): void
    {
        $body = self::methodBody('app/Domain/Onchain/Admin/VerifyCollectionsPage.php', 'render_page');
        self::assertGreaterThan(5000, strlen($body), 'denominator: the render method body must actually be extracted');

        foreach ([
            'ApiRetry', 'wp_remote_', 'SafeHttpClient', 'Stargaze', '->fetch_collections(',
            'wp_cache_set(', 'set_transient(', 'update_option(', 'add_option(',
        ] as $needle) {
            self::assertStringNotContainsString($needle, $body, "render_page() must not contain {$needle}");
        }
    }

    /** The wallet-address listing that fed the original fan-out stays deleted. */
    public function testTheWalletAddressFanOutIsGone(): void
    {
        self::assertSame([], self::filesContaining('listAddressesForChain'));
    }
}
