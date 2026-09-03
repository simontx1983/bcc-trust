<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\NftDiscoveryPage;
use BCC\Trust\Onchain\Services\NftDiscoveryControlPlaneSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * WHAT THE NFT DISCOVERY CONTROL PLANE MAY NOT GROW INTO.
 *
 * ── WHY THESE ARE SOURCE ASSERTIONS ─────────────────────────────────────
 * Every claim here is a NEGATIVE — "there is no cron", "nothing reaches
 * discovery from the relay", "no capability is written". A negative about
 * code that does not exist cannot be proven by exercising behaviour: you
 * can only run the paths that ARE there. So these read the shipped files,
 * the same discipline {@see NftCapabilityScaffoldBoundaryTest} and
 * {@see AutomaticNftDiscoveryRetiredTest} already use.
 *
 * Comments are stripped before matching. A prohibition explained in prose
 * must not register as a violation of itself — this file's own subjects
 * discuss `wp_schedule_event` at length precisely because they must never
 * call it.
 */
#[CoversClass(NftDiscoveryPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftDiscoveryBoundaryTest extends TestCase
{
    /**
     * The SURFACE files — the ones that may never write a capability value
     * themselves, whatever else they grow.
     */
    private const OWNED_FILES = [
        'app/Domain/Onchain/Admin/NftDiscoveryPage.php',
        'app/Domain/Onchain/Admin/Views/NftCapabilityEditorPanel.php',
        'app/Domain/Onchain/Services/NftDiscoveryControlPlaneSnapshot.php',
        'app/Domain/Onchain/Support/CosmwasmPassStopReason.php',
    ];

    /**
     * The one file that IS allowed to call a capability writer.
     *
     * Held apart from OWNED_FILES so the "must not write" assertion stays
     * exact rather than being loosened to accommodate it — the general
     * hygiene rules (no cron, no REST, no `$wpdb`, no reaching for the
     * relay) still apply to it, and it appears in those providers.
     */
    private const EDITOR_FILES = [
        'app/Domain/Onchain/Services/NftCapabilityEditor.php',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        return (string) file_get_contents(self::root() . '/' . $relative);
    }

    /** Source with every comment and docblock removed. */
    private static function code(string $relative): string
    {
        $out = '';
        foreach (token_get_all(self::read($relative)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /** @return array<string, array{0: string}> */
    public static function ownedFiles(): array
    {
        $out = [];
        foreach (self::OWNED_FILES as $file) {
            $out[basename($file)] = [$file];
        }

        return $out;
    }

    /**
     * Surfaces AND the editor service — everything this feature owns.
     *
     * @return array<string, array{0: string}>
     */
    public static function allFiles(): array
    {
        $out = [];
        foreach ([...self::OWNED_FILES, ...self::EDITOR_FILES] as $file) {
            $out[basename($file)] = [$file];
        }

        return $out;
    }

    /**
     * Every tracked PHP file under app/ and includes/.
     *
     * @return list<string> absolute paths
     */
    private static function productionPhpFiles(): array
    {
        $files = [];
        foreach (['app', 'includes'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  NO CRON. NOT NOW, NOT BY ACCIDENT.
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Automatic collection discovery was retired deliberately, and the
     * scheduling was DELETED rather than emptied — an empty `register()` is
     * one edit away from scheduling again, and re-arming looked harmless.
     *
     * This page is the surface that replaced it. If anything is ever going
     * to quietly put a scan back on a timer, it is here.
     */
    #[DataProvider('allFiles')]
    public function testNoOwnedFileSchedulesAnything(string $file): void
    {
        $code = self::code($file);

        foreach ([
            'wp_schedule_event',
            'wp_schedule_single_event',
            'wp_next_scheduled',
            'wp_clear_scheduled_hook',
            'wp_unschedule_event',
        ] as $scheduler) {
            self::assertStringNotContainsString(
                $scheduler,
                $code,
                "{$file} must not touch the scheduler"
            );
        }
    }

    /**
     * And it names none of the retired hooks — not even to clear one.
     *
     * Raw source, comments included: a hook name in a docblock here is one
     * copy-paste from a hook name in an `add_action`.
     */
    #[DataProvider('allFiles')]
    public function testNoOwnedFileMentionsARetiredDiscoveryHook(string $file): void
    {
        $raw = self::read($file);

        foreach ([
            'bcc_index_collections',
            'bcc_cosmwasm_backfill_tick',
            'bcc_cosmwasm_daily_discovery',
            'bcc_cosmwasm_weekly_retry',
            'bcc_cosmwasm_metadata_refresh',
        ] as $hook) {
            self::assertStringNotContainsString($hook, $raw, "{$file} names a retired hook");
        }
    }

    /**
     * The worker still has no `register()`, and this page does not call one.
     */
    public function testThePageNeverAsksTheWorkerToRegisterItself(): void
    {
        self::assertStringNotContainsString(
            'CosmwasmDiscoveryWorker::register',
            self::code('app/Domain/Onchain/Admin/NftDiscoveryPage.php')
        );
        self::assertFalse(
            method_exists(\BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker::class, 'register'),
            'the worker owns no cron hooks and must not grow a registrar'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ONE WAY IN: admin-post. NO REST, NO AJAX, NO RELAY.
    // ═══════════════════════════════════════════════════════════════════

    #[DataProvider('allFiles')]
    public function testNoOwnedFileRegistersARestOrAjaxRoute(string $file): void
    {
        $code = self::code($file);

        self::assertStringNotContainsString('register_rest_route', $code);
        self::assertStringNotContainsString('wp_ajax_', $code);
        self::assertStringNotContainsString('check_ajax_referer', $code);
    }

    /**
     * Every `add_action` on this page is an `admin_post_` route or the
     * legacy-URL redirect. Nothing else.
     */
    public function testEveryHookThePageRegistersIsAnAdminPostRouteOrTheRedirect(): void
    {
        $code = self::code('app/Domain/Onchain/Admin/NftDiscoveryPage.php');

        self::assertSame(
            1,
            preg_match_all("/add_action\(\s*'admin_init'/", $code),
            'exactly one non-admin_post hook: the compatibility redirect'
        );

        preg_match_all('/add_action\(\s*([^,]+),/', $code, $m);
        foreach ($m[1] as $hook) {
            $hook = trim($hook);
            $isAdminPost = str_contains($hook, "'admin_post_'");
            $isRedirect  = $hook === "'admin_init'";

            self::assertTrue(
                $isAdminPost || $isRedirect,
                "unexpected hook registration: {$hook}"
            );
        }
    }

    /**
     * ⚠️ THE RELAY IS A HOLDINGS RELAY, AND IT STAYS ONE.
     *
     * `IndexerTickEndpoint` is a machine-to-machine cron relay: Vercel Cron
     * POSTs it every minute behind `BCC_INTERNAL_CRON_SECRET`, and it drives
     * the EVM NFT *holdings* indexer and the feed warm.
     *
     * It has never touched collection discovery, and it must not start:
     * hanging discovery off it would re-create exactly the unattended
     * minute-cadence path that retiring automatic discovery removed — only
     * this time behind a shared secret instead of WP-Cron, which is harder
     * to notice, not safer.
     *
     * (An earlier brief for this work assumed a "discovery relay
     * accelerator" existed. It does not. This test is what that finding
     * became.)
     */
    public function testTheHoldingsRelayCannotReachDiscovery(): void
    {
        $relay = self::code('app/Domain/Onchain/REST/IndexerTickEndpoint.php');

        foreach ([
            'CosmwasmDiscoveryWorker',
            'CosmwasmDiscoveryService',
            'runBackfillForChain',
            'runSupervisedSingleChainPass',
            'NftDiscoveryPage',
            'NftChainCapability',
        ] as $discovery) {
            self::assertStringNotContainsString(
                $discovery,
                $relay,
                "the holdings relay must not reach {$discovery}"
            );
        }
    }

    /** And the discovery surface does not reach back for it. */
    #[DataProvider('allFiles')]
    public function testNoOwnedFileReachesForTheRelay(string $file): void
    {
        $code = self::code($file);

        self::assertStringNotContainsString('IndexerTickEndpoint', $code);
        self::assertStringNotContainsString('BCC_INTERNAL_CRON_SECRET', $code);
        self::assertStringNotContainsString('X-Bcc-Internal', $code);
    }

    /**
     * The backfill worker has exactly one production caller, and it is the
     * admin page.
     */
    public function testTheRunnerHasExactlyOneProductionCaller(): void
    {
        $callers = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root() . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $rel  = substr($path, strlen(str_replace('\\', '/', self::root())) + 1);

            if (str_contains(self::code($rel), 'runBackfillForChain(')) {
                $callers[] = $rel;
            }
        }

        sort($callers);

        self::assertSame(
            [
                'app/Domain/Onchain/Admin/NftDiscoveryPage.php',
                'app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php',
                'app/Domain/Onchain/Workers/DiscoveryRunExecutor.php',
            ],
            $callers,
            'the admin page and the ledger executor start a backfill; the worker declares it'
        );

        // ── WHY THE EXECUTOR IS ALLOWED HERE, AND STILL BOUNDED ─────────
        // PR 7A added it deliberately: a HISTORICAL run is a backfill, and
        // the executor is what performs one. The bound did not move, it
        // relocated — the executor reaches this method only for a run whose
        // `scan_mode` is historical, and a run only exists because a NAMED
        // administrator asked for it through DiscoveryRunService.
        //
        // The supervised CLI is deliberately NOT on this list: it pins
        // INCREMENTAL precisely so a backfill stays unreachable from a
        // terminal, which CosmwasmOneShotCliTest enforces separately.
        self::assertStringNotContainsString(
            'runBackfillForChain(',
            self::code('app/Domain/Onchain/CLI/CosmwasmOneShotDiscoveryCommand.php'),
            'the supervised CLI must never reach a backfill'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  READ-ONLY ABOUT CAPABILITY
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The ADMIN SURFACE explains capability; it still cannot change it
     * itself.
     *
     * ── WHAT THIS ASSERTION BECAME, AND WHY IT DID NOT JUST GO AWAY ─────
     * In PR 3 it said "no capability writer exists anywhere in these files"
     * — true then, and the whole basis on which that PR was reviewed as
     * inert. PR 4 adds a writer, so the literal claim had to change. What it
     * must NOT become is nothing.
     *
     * So it is now the stronger, permanent version: the writer exists, and
     * it is not HERE. The page and its views own authorization, request
     * shape, nonces, rendering and the PRG; the repositories own the SQL;
     * and exactly one service in between owns the rules. A repository call
     * appearing in a renderer is the first step of that separation
     * collapsing, and it is usually written as a convenience.
     */
    #[DataProvider('ownedFiles')]
    public function testNoOwnedFileWritesACapabilityValueItself(string $file): void
    {
        $code = self::code($file);

        foreach ([
            'enableNftProductSupport',
            'disableNftProductSupport',
            'grantManualCollectionDiscovery',
            'withdrawManualCollectionDiscovery',
            'upsertOverride',
            'deleteOverride',
            'bumpChainGeneration',
            'ChainNftCapabilityRepository::',
        ] as $writer) {
            self::assertStringNotContainsString(
                $writer,
                $code,
                "{$file} must reach capability writes through NftCapabilityEditor, never directly"
            );
        }
    }

    /**
     * Nor does any of them name either column, which is what keeps the
     * capability model the single reader of both.
     *
     * The editor surface displays both values — it reads them from the
     * matrix array the model built, under neutral keys (`bcc_supports`,
     * `manual_enabled`). A COLUMN name in a renderer means somebody went to
     * the row directly.
     */
    #[DataProvider('ownedFiles')]
    public function testNoOwnedFileNamesACapabilityColumn(string $file): void
    {
        $code = self::code($file);

        self::assertStringNotContainsString('bcc_supports_nft_collections', $code);
        self::assertStringNotContainsString('manual_collection_discovery_enabled', $code);
    }

    /** §1: no `$wpdb` outside a repository — page, views and service alike. */
    #[DataProvider('allFiles')]
    public function testNoOwnedFileTouchesTheDatabaseDirectly(string $file): void
    {
        $code = self::code($file);

        self::assertStringNotContainsString('$wpdb', $code);
        self::assertStringNotContainsString('global $wpdb', $code);
        self::assertDoesNotMatchRegularExpression('/\bUPDATE\s+\{/i', $code);
        self::assertDoesNotMatchRegularExpression('/\bINSERT\s+INTO\b/i', $code);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b/i', $code);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE CAPABILITY EDITOR'S OWN BOUNDARY
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Every capability route is admin-post. There is no REST, AJAX or CLI
     * way in — and this is asserted over the whole tree, not the owned list.
     *
     * A REST writer would be the natural thing to add the first time
     * somebody wants the editor on the Next.js `/admin/*` surface. It would
     * also be a capability grant reachable by a bearer token rather than by
     * a logged-in administrator holding a route-scoped nonce, which is a
     * different security story entirely and one this PR did not review.
     */
    public function testTheCapabilityRoutesExistOnlyAsAdminPost(): void
    {
        $routes = [
            NftDiscoveryPage::ACTION_CAP_PRODUCT_ENABLE,
            NftDiscoveryPage::ACTION_CAP_PRODUCT_DISABLE,
            NftDiscoveryPage::ACTION_CAP_MANUAL_ENABLE,
            NftDiscoveryPage::ACTION_CAP_MANUAL_DISABLE,
            NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE,
            NftDiscoveryPage::ACTION_CAP_DRIVER_ENABLE,
            NftDiscoveryPage::ACTION_CAP_DRIVER_INHERIT,
            NftDiscoveryPage::ACTION_CAP_STALE_REMOVE,
        ];

        $page = self::code('app/Domain/Onchain/Admin/NftDiscoveryPage.php');

        foreach ($routes as $route) {
            self::assertStringContainsString(
                "admin_post_' . self::ACTION_CAP_",
                $page,
                'capability routes are registered on admin_post only'
            );
            self::assertStringStartsWith('bcc_nft_cap_', $route);
        }

        // And nothing anywhere registers them as anything else.
        foreach (self::productionPhpFiles() as $path) {
            $rel  = str_replace('\\', '/', substr($path, strlen(self::root()) + 1));
            $code = self::code($rel);

            if (!str_contains($code, 'bcc_nft_cap_')) {
                continue;
            }

            self::assertStringNotContainsString('register_rest_route', $code, $rel);
            self::assertStringNotContainsString('wp_ajax_', $code, $rel);
            self::assertStringNotContainsString('WP_CLI::add_command', $code, $rel);
        }
    }

    /**
     * The capability PRG is NARROWER than the scanner PRG beside it.
     *
     * Three keys, and deliberately not the fourth: the scanner routes carry
     * `bcc_ref` because they report on WORK that ran and whose failure may
     * be a provider's; a capability edit reports on a configuration change
     * whose failures are ours, and the correlation id lives in the file log
     * beside the durable audit row instead. Fewer things in the URL, fewer
     * things to reason about.
     *
     * The assertion that matters most is what is ABSENT: no chain id under
     * any name, no operation, no driver, no priority, no submitted value.
     */
    public function testTheCapabilityRedirectCarriesNoTarget(): void
    {
        self::assertSame(
            ['page', 'family', 'bcc_nftcap'],
            NftDiscoveryPage::CAPABILITY_REDIRECT_KEYS
        );

        foreach (['chain', 'chain_id', 'operation', 'driver_key', 'priority', 'bcc_ref'] as $forbidden) {
            self::assertNotContains(
                $forbidden,
                NftDiscoveryPage::CAPABILITY_REDIRECT_KEYS,
                'the capability landing must not carry ' . $forbidden
            );
        }
    }

    /**
     * Saving configuration cannot start work.
     *
     * The editor's forms and the backfill route are on the same page, so the
     * cheapest possible mistake is a form whose `action` names the wrong
     * one. Nothing in the editor's view may mention the backfill route, the
     * worker, or the discovery service at all.
     */
    public function testTheEditorNeverSubmitsToTheBackfillRoute(): void
    {
        $panel = self::code('app/Domain/Onchain/Admin/Views/NftCapabilityEditorPanel.php');

        foreach ([
            'ACTION_CW_BACKFILL',
            'bcc_chain_cw_backfill',
            'CosmwasmDiscoveryWorker',
            'CosmwasmDiscoveryService',
            'runBackfillForChain',
        ] as $work) {
            self::assertStringNotContainsString(
                $work,
                $panel,
                'saving capability configuration must never be able to start a discovery'
            );
        }
    }

    /**
     * No bulk, family-wide or automatic control exists.
     *
     * Capability is granted one decision at a time. A "select all" over a
     * matrix this wide is a single click that could put every chain into
     * product scope, and the audit trail would record it as one action.
     */
    public function testNoBulkOrAutomaticCapabilityControlExists(): void
    {
        $panel = self::code('app/Domain/Onchain/Admin/Views/NftCapabilityEditorPanel.php');
        $page  = self::code('app/Domain/Onchain/Admin/NftDiscoveryPage.php');

        foreach ([$panel, $page] as $code) {
            foreach ([
                'enable_all',
                'enableAll',
                'bulk_',
                'select_all',
                'apply_to_family',
                'wp_schedule_event',
                'wp_schedule_single_event',
            ] as $bulk) {
                self::assertStringNotContainsString($bulk, $code);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE MOVE CHANGED NO ROUTE STRING
    // ═══════════════════════════════════════════════════════════════════

    /**
     * A form rendered before this PR still posts to a route that exists.
     *
     * The six routes moved class; their STRINGS did not. Changing one would
     * break every open tab, every bookmark, and — quietly — the audit
     * vocabulary, which is what "which chain was this?" is answered from.
     */
    public function testEveryMovedRouteKeptItsExactString(): void
    {
        self::assertSame('bcc_chain_cw_pause', NftDiscoveryPage::ACTION_CW_PAUSE);
        self::assertSame('bcc_chain_cw_resume', NftDiscoveryPage::ACTION_CW_RESUME);
        self::assertSame('bcc_chain_cw_backfill', NftDiscoveryPage::ACTION_CW_BACKFILL);
        self::assertSame('bcc_chain_cw_retry', NftDiscoveryPage::ACTION_CW_RETRY);
        self::assertSame('bcc_chain_cw_discovery_enable', NftDiscoveryPage::ACTION_CW_DISCOVERY_ENABLE);
        self::assertSame('bcc_chain_cw_discovery_disable', NftDiscoveryPage::ACTION_CW_DISCOVERY_DISABLE);
    }

    /** And the nonce is still scoped to route AND chain. */
    public function testTheNonceIsStillScopedToRouteAndChain(): void
    {
        $code = self::code('app/Domain/Onchain/Admin/NftDiscoveryPage.php');

        self::assertStringContainsString("requireNonce(\$route . '_' . \$chainId)", $code);
        self::assertStringContainsString("wp_nonce_field(\$route . '_' . \$chainId)", $code);
    }

    /** The Chains page no longer answers any of them. */
    public function testTheChainsPageNoLongerRegistersTheMovedRoutes(): void
    {
        $code = self::code('app/Domain/Onchain/Admin/ChainsPage.php');

        foreach ([
            'bcc_chain_cw_pause',
            'bcc_chain_cw_resume',
            'bcc_chain_cw_backfill',
            'bcc_chain_cw_retry',
            'bcc_chain_cw_discovery_enable',
            'bcc_chain_cw_discovery_disable',
        ] as $route) {
            self::assertStringNotContainsString($route, $code);
        }
    }

    /** Exactly one class registers them, so they cannot be double-bound. */
    public function testExactlyOneClassRegistersTheMovedRoutes(): void
    {
        $registrars = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root() . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $rel  = substr($path, strlen(str_replace('\\', '/', self::root())) + 1);
            $code = self::code($rel);

            if (str_contains($code, "admin_post_' . self::ACTION_CW_BACKFILL")) {
                $registrars[] = $rel;
            }
        }

        self::assertSame(['app/Domain/Onchain/Admin/NftDiscoveryPage.php'], $registrars);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  FAMILY NAVIGATION IS NOT A SECOND CHAIN REGISTRY
    // ═══════════════════════════════════════════════════════════════════

    public function testTheFamilyListIsAFilterNotACatalogueOfChains(): void
    {
        self::assertSame(['cosmos', 'evm', 'solana'], NftDiscoveryControlPlaneSnapshot::FAMILIES);

        $code = self::code('app/Domain/Onchain/Services/NftDiscoveryControlPlaneSnapshot.php');

        // Chains come from the chains table, never from a hardcoded list.
        self::assertStringContainsString('ChainRepository::getAll()', $code);

        foreach ([
            'ethereum', 'polygon', 'arbitrum', 'optimism', 'base', 'avalanche',
            'bsc', 'injective', 'kujira', 'dungeon', 'osmosis', 'juno',
        ] as $slug) {
            self::assertStringNotContainsString(
                "'" . $slug . "'",
                $code,
                'naming a chain slug here would be a second, silently incomplete registry'
            );
        }
    }

    #[DataProvider('unknownFamilies')]
    public function testAnUnknownFamilyIsRefusedNotGuessed(string $family): void
    {
        self::assertFalse(NftDiscoveryControlPlaneSnapshot::isFamily($family));
    }

    /** @return array<string, array{0: string}> */
    public static function unknownFamilies(): array
    {
        return [
            'empty'          => [''],
            'thorchain'      => ['thorchain'],
            'polkadot'       => ['polkadot'],
            'near'           => ['near'],
            'case mismatch'  => ['Cosmos'],
            'sql-looking'    => ["cosmos' OR 1=1"],
            'path traversal' => ['../../etc/passwd'],
        ];
    }
}
