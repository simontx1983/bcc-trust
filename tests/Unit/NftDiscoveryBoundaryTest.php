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
    /** The files this PR added or took ownership of. */
    private const OWNED_FILES = [
        'app/Domain/Onchain/Admin/NftDiscoveryPage.php',
        'app/Domain/Onchain/Services/NftDiscoveryControlPlaneSnapshot.php',
        'app/Domain/Onchain/Support/CosmwasmPassStopReason.php',
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
    #[DataProvider('ownedFiles')]
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
    #[DataProvider('ownedFiles')]
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

    #[DataProvider('ownedFiles')]
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
    #[DataProvider('ownedFiles')]
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
            ],
            $callers,
            'only the admin page starts a backfill; the worker declares it'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  READ-ONLY ABOUT CAPABILITY
    // ═══════════════════════════════════════════════════════════════════

    /**
     * This PR explains capability and cannot change it.
     *
     * No writer for either chain column, no writer for the override table,
     * and no generation bump — because a bump is only ever needed after a
     * write, so its presence would mean one had appeared.
     */
    #[DataProvider('ownedFiles')]
    public function testNoOwnedFileWritesACapabilityValue(string $file): void
    {
        $code = self::code($file);

        foreach ([
            'setBccSupportsNftCollections',
            'setManualCollectionDiscoveryEnabled',
            'bumpChainGeneration',
            'ChainNftCapabilityRepository::',
        ] as $writer) {
            self::assertStringNotContainsString(
                $writer,
                $code,
                "{$file} must not be able to grant or seed a capability"
            );
        }
    }

    /**
     * Nor does it name either column, which is what keeps the capability
     * model the single reader of both.
     */
    #[DataProvider('ownedFiles')]
    public function testNoOwnedFileNamesACapabilityColumn(string $file): void
    {
        $code = self::code($file);

        self::assertStringNotContainsString('bcc_supports_nft_collections', $code);
        self::assertStringNotContainsString('manual_collection_discovery_enabled', $code);
    }

    /** §1: no `$wpdb` outside a repository. */
    #[DataProvider('ownedFiles')]
    public function testNoOwnedFileTouchesTheDatabaseDirectly(string $file): void
    {
        $code = self::code($file);

        self::assertStringNotContainsString('$wpdb', $code);
        self::assertStringNotContainsString('global $wpdb', $code);
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
