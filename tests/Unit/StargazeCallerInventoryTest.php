<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * STRUCTURAL INVARIANT: every path that can reach the Stargaze marketplace
 * API is CLASSIFIED, and no administrator render path is among them.
 *
 * ⚠ HONESTLY LABELLED: THIS IS A SOURCE-LEVEL TEST. It tokenises files and
 * looks for call sites. It cannot prove behaviour and does not try to — the
 * behaviour is pinned by `VerifyCollectionsRenderIsolationTest` (the real
 * page, 200 linked wallets, an HTTP spy) and `CollectionDemandServiceTest`.
 * What those cannot do is fail when somebody adds a NEW caller next year, on
 * a surface nobody wrote a behavioural test for. That is this file's only job.
 *
 * ── THE DEFECT IT EXISTS FOR ────────────────────────────────────────────
 * Opening Verify Collections sent every linked Cosmos Hub wallet address — up
 * to 200 per render — to `marketplace-api.cosmos.stargaze-apis.com`, an
 * endpoint Stargaze does not publish as a supported integration. It looked
 * like an innocent demand count. A render path is the worst place for an
 * outbound call: it runs on every page load, it needs no intent beyond
 * viewing, and nobody reviewing a template expects network traffic in it.
 *
 * ── COMMENTS ARE STRIPPED FIRST ─────────────────────────────────────────
 * With `token_get_all()`, not a regex. Several files legitimately NAME the
 * API in prose (the demand service explains why it no longer calls it), and
 * a grep cannot tell a docblock from a call.
 *
 * ── THE VACUOUS-PASS TRAP ───────────────────────────────────────────────
 * A scan over an empty tree finds no violations and reports success. Every
 * sweep asserts its denominator first.
 */
#[CoversNothing]
final class StargazeCallerInventoryTest extends TestCase
{
    /**
     * Every file allowed to call the Stargaze client, and what kind of
     * surface it is. None of these renders an administrator page.
     *
     * @var array<string, string>
     */
    private const STARGAZE_CALLERS = [
        // fetch_collections(): wallet-verify seed (WalletSeedService, async
        // after a wallet is verified), the 4-hourly bcc_refresh_collections
        // cron (ChainRefreshService) and the creator-gallery refresh job
        // (Plugin, a single cron event scheduled by a REST GET). Sends ONE
        // wallet address per call. Not an admin render.
        'app/Domain/Onchain/Fetchers/CosmosFetcher.php' => 'wallet discovery — async/cron',

        // panelForUser() / holdsPerPanelSources(): the signed-in member's OWN
        // linked Hub wallets, via /bcc/v1/me/collection-stances (GET panel,
        // POST stance). A member-facing REST surface, not an admin render;
        // documented as a separate follow-up, deliberately unchanged here.
        'app/Domain/Onchain/Services/CollectionStanceService.php' => 'member REST — own wallets',
    ];

    /**
     * Every file allowed to call `->fetch_collections(`, which reaches
     * Stargaze for the Cosmos Hub.
     *
     * @var list<string>
     */
    private const FETCH_COLLECTIONS_CALLERS = [
        'app/Domain/Core/Plugin.php',
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

    /** @return list<string> files whose code (not comments) contains $needle */
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

    // ═══════════════════════════════════════════════════════════════════
    //  0. THE DENOMINATOR
    // ═══════════════════════════════════════════════════════════════════

    public function testTheScanRootIsPopulatedAndContainsTheClient(): void
    {
        self::assertDirectoryExists(self::appRoot());
        $sources = self::sources();
        self::assertGreaterThan(200, count($sources), 'the sweep must be reading the plugin, not an empty tree');
        self::assertArrayHasKey('app/Domain/Onchain/Support/StargazeMarketplaceApi.php', $sources);
        self::assertArrayHasKey('app/Domain/Onchain/Admin/VerifyCollectionsPage.php', $sources);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. EVERY PATH TO STARGAZE IS CLASSIFIED
    // ═══════════════════════════════════════════════════════════════════

    public function testEveryStargazeCallerIsClassified(): void
    {
        $callers = array_values(array_diff(
            self::filesContaining('StargazeMarketplaceApi::'),
            ['app/Domain/Onchain/Support/StargazeMarketplaceApi.php']
        ));

        self::assertNotSame([], $callers, 'anti-vacuity: the classified callers must actually be found');
        self::assertSame(
            array_keys(self::STARGAZE_CALLERS),
            $callers,
            'a file calls the Stargaze client without being classified — decide what surface it is, '
            . 'and confirm it is not an administrator render path, before adding it here'
        );
    }

    public function testTheHostnameIsWrittenDownInExactlyOneFile(): void
    {
        self::assertSame(
            ['app/Domain/Onchain/Support/StargazeMarketplaceApi.php'],
            self::filesContaining('stargaze-apis.com'),
            'a second copy of the endpoint is a second, unclassified way to reach it'
        );
    }

    public function testEveryFetchCollectionsCallerIsClassified(): void
    {
        $callers = self::filesContaining('->fetch_collections(');

        self::assertNotSame([], $callers);
        self::assertSame(self::FETCH_COLLECTIONS_CALLERS, $callers);
    }

    public function testEveryStanceServiceCallerIsClassified(): void
    {
        self::assertSame(self::STANCE_SERVICE_CALLERS, self::filesContaining('CollectionStanceService::'));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. NO ADMINISTRATOR SURFACE CAN REACH IT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * No file under any `Admin/` directory may name the Stargaze client or
     * the two services that call it. POST handlers are included on purpose:
     * a live Stargaze refresh requires separate authorisation, published API
     * terms and a privacy review, none of which exists.
     */
    public function testNoAdminFileReachesStargaze(): void
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
     * The demand service is the thing the Verify page calls while rendering,
     * so it may contain no outbound transport, no wallet-address lookup and
     * no cache or option write at all.
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

    /**
     * And the Verify page's render method itself, token-bounded to the method
     * body so its POST handlers — which legitimately do other work — are not
     * swept in.
     */
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

    /** The wallet-address listing that fed the fan-out stays deleted. */
    public function testTheWalletAddressFanOutIsGone(): void
    {
        self::assertSame([], self::filesContaining('listAddressesForChain'));
    }

    /** Comment-stripped body of one method, found by token walk. */
    private static function methodBody(string $path, string $method): string
    {
        $code   = self::sources()[$path] ?? '';
        $tokens = token_get_all($code);
        $n      = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            // next T_STRING is the name
            $j = $i + 1;
            while ($j < $n && !(is_array($tokens[$j]) && $tokens[$j][0] === T_STRING)) {
                $j++;
            }
            if ($j >= $n || $tokens[$j][1] !== $method) {
                continue;
            }
            // walk to the opening brace, then balance
            while ($j < $n && $tokens[$j] !== '{') {
                $j++;
            }
            $depth = 0;
            $body  = '';
            for (; $j < $n; $j++) {
                $t = $tokens[$j];
                $s = is_array($t) ? $t[1] : $t;
                if ($t === '{' || (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES))) {
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                }
                $body .= $s;
                if ($depth === 0) {
                    return $body;
                }
            }
        }

        return '';
    }
}
