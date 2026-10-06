<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Trust\Onchain\Admin\NftDiscoveryPage;
    use BCC\Trust\Onchain\Support\ScannerFreeze;
    use PHPUnit\Framework\Attributes\CoversNothing;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../Stubs/onchain-admin-action-stubs.php';

    /**
     * The scanner's OPERATOR SURFACE is gone, and what is not a run still works.
     *
     * ── WHAT CHANGED AT S4, AND WHY THESE ASSERTIONS CHANGED WITH IT ────
     * This suite used to prove a FREEZE: eleven entry points existed, were
     * registered only behind `!ScannerFreeze::frozen()`, and therefore never
     * registered at all. S4 deleted them, so the same facts now need different
     * assertions:
     *
     *   • `testNoScannerEntryPointCallbackIsRegistered` still asserts the ten
     *     route strings are unregistered — but it is now a REGRESSION guard
     *     against someone re-adding them, not evidence that a guard works.
     *   • `testTheWithdrawnSurfaceIsDeleted` is new and is the positive half:
     *     the four surface files and the withdrawn members are ABSENT. Without
     *     it, "no route registers" would pass just as well if S4 had only
     *     commented out a call.
     *   • The CLI case INVERTED. It used to require the registration to still
     *     be present and guarded; it now requires it to be gone.
     *   • `testTheScannerImplementationIsPreserved` shrank from eight files to
     *     four. S4 withdraws the SURFACE; the services, workers, executor and
     *     one-shot CLI class are retained and still consulted via `frozen()`.
     *   • The old inventory-parity case was deleted. It compared the
     *     non-`cli:`/`cron:`/`async:` half of `FROZEN_ENTRY_POINTS` against the
     *     hook list here; after S4 both halves are empty of hooks, so it
     *     asserted `[] === []`. `testTheInventoryIsNowOnlyTheBackgroundPair`
     *     replaces it with something that can fail.
     *
     * ⚠ The freeze itself is NOT retired. Two background entry points remain
     * frozen and the retained implementation still depends on `frozen()`
     * returning true. That is asserted here and in
     * ScannerBackgroundEntryPointsAreFrozenTest.
     */
    #[CoversNothing]
    #[Group('unit')]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class ScannerEntryPointsAreFrozenTest extends TestCase
    {
        /**
         * Route strings that must never be registered again.
         *
         * All ten are DELETED as of S4 — the constants that named them, the
         * handlers behind them and the two classes that registered three of
         * them are gone. They are still enumerated by their literal strings
         * precisely because the constants no longer exist: a literal cannot
         * be refactored away, so re-introducing any of these routes under its
         * old name fails here.
         */
        private const WITHDRAWN_ROUTES = [
            'admin_post_bcc_discovery_scan_request',
            'admin_post_bcc_discovery_scan_retry',
            'admin_post_bcc_discovery_scan_cancel',
            'wp_ajax_bcc_discovery_run_status',
            'admin_post_bcc_chain_cw_pause',
            'admin_post_bcc_chain_cw_resume',
            'admin_post_bcc_chain_cw_backfill',
            'admin_post_bcc_chain_cw_retry',
            'admin_post_bcc_chain_cw_discovery_enable',
            'admin_post_bcc_chain_cw_discovery_disable',
        ];

        /** Hooks that must SURVIVE — none of them starts a run. */
        private const KEPT_HOOKS = [
            'admin_post_bcc_nftd_add_collection',
            'admin_post_bcc_nft_cap_product_enable',
            'admin_post_bcc_nft_cap_manual_enable',
            'admin_post_bcc_nft_cap_driver_inherit',
            'admin_post_bcc_nft_cap_stale_remove',
        ];

        /**
         * Files the surface withdrawal deleted outright.
         *
         * @var list<string>
         */
        private const DELETED_FILES = [
            'app/Domain/Onchain/Admin/DiscoveryScanActions.php',
            'app/Domain/Onchain/Admin/DiscoveryRunStatusEndpoint.php',
            'app/Domain/Onchain/Admin/Views/DiscoveryScanPanel.php',
            'app/Domain/Onchain/Admin/Views/CosmwasmScannerPanel.php',
        ];

        /**
         * Members of the retained page that the withdrawal removed.
         *
         * @var list<string>
         */
        private const DELETED_PAGE_MEMBERS = [
            'handle_cw_pause',
            'handle_cw_resume',
            'handle_cw_backfill',
            'handle_cw_retry',
            'handle_cw_discovery_enable',
            'handle_cw_discovery_disable',
            'render_cw_discovery_section',
            'render_cw_operation_control',
            'render_cw_discovery_button',
            'render_run_report',
        ];

        /**
         * Register everything this page still registers.
         *
         * ⚠ It no longer calls `DiscoveryScanActions::register()` or
         * `DiscoveryRunStatusEndpoint::register()` — those classes are deleted,
         * so naming them here would be a fatal error rather than a test.
         *
         * @return list<string>
         */
        private function registerEverything(): array
        {
            $GLOBALS['bcc_test_registered_actions'] = [];
            NftDiscoveryPage::register_actions();

            return array_values(array_map('strval', $GLOBALS['bcc_test_registered_actions'] ?? []));
        }

        public function testTheFreezeIsOn(): void
        {
            self::assertTrue(
                ScannerFreeze::frozen(),
                'the retained scanner implementation still depends on this being true'
            );
        }

        public function testNoScannerEntryPointCallbackIsRegistered(): void
        {
            $registered = $this->registerEverything();

            foreach (self::WITHDRAWN_ROUTES as $route) {
                self::assertNotContains(
                    $route,
                    $registered,
                    "$route was withdrawn by S4 and must not be registered again"
                );
            }
        }

        /** Without this, the absence above would also pass if registration simply never ran. */
        public function testTheControlsThatAreNotRunsStillRegister(): void
        {
            $registered = $this->registerEverything();

            self::assertNotSame([], $registered, 'registration must actually have happened');
            foreach (self::KEPT_HOOKS as $hook) {
                self::assertContains($hook, $registered, "$hook is not a run control and must survive");
            }
        }

        /**
         * THE POSITIVE HALF: the surface is absent because it was deleted.
         *
         * `assertNotContains` above cannot distinguish "the route was removed"
         * from "registration was commented out". This can.
         */
        public function testTheWithdrawnSurfaceIsDeleted(): void
        {
            $root = __DIR__ . '/../../';

            foreach (self::DELETED_FILES as $file) {
                self::assertFileDoesNotExist(
                    $root . $file,
                    "$file is part of the withdrawn surface and must not come back"
                );
            }

            foreach (self::DELETED_PAGE_MEMBERS as $member) {
                self::assertFalse(
                    method_exists(NftDiscoveryPage::class, $member),
                    "NftDiscoveryPage::$member() was withdrawn by S4"
                );
            }

            // Anti-vacuity: the page itself still exists and still has the
            // members the capability and manual-intake surfaces need, so the
            // assertions above are about specific removals rather than a class
            // that failed to load.
            foreach (['register_actions', 'render_page', 'handle_add_collection', 'handle_cap_product_enable'] as $kept) {
                self::assertTrue(
                    method_exists(NftDiscoveryPage::class, $kept),
                    "NftDiscoveryPage::$kept() is retained and must still exist"
                );
            }
        }

        /**
         * The freeze inventory is now exactly the two background entry points.
         *
         * Replaces the old parity case, which compared the hook half of
         * `FROZEN_ENTRY_POINTS` against the list in this file. Both are empty
         * of hooks after S4, so that comparison could no longer fail.
         */
        public function testTheInventoryIsNowOnlyTheBackgroundPair(): void
        {
            $declared = ScannerFreeze::FROZEN_ENTRY_POINTS;
            sort($declared);

            self::assertSame(
                ['async:bcc_discovery_run_execute', 'cron:bcc_discovery_run_maintenance'],
                $declared,
                'only the maintenance sweep and the async executor are still frozen'
            );

            // And none of the withdrawn routes is still described as frozen.
            foreach (self::WITHDRAWN_ROUTES as $route) {
                self::assertNotContains(
                    $route,
                    ScannerFreeze::FROZEN_ENTRY_POINTS,
                    "$route is deleted, so the freeze must not claim to cover it"
                );
            }
        }

        /** Manual Add Collection is not a full-chain run and must still render, nonce and all. */
        public function testManualAddCollectionStillRenders(): void
        {
            $method = new \ReflectionMethod(NftDiscoveryPage::class, 'render_add_collection');
            $method->setAccessible(true);

            ob_start();
            $method->invoke(null, 'cosmos', [[
                'chain_id' => 8,
                'slug'     => 'cosmos',
                'name'     => 'Cosmos Hub',
                'eligible' => true,
            ]]);
            $markup = (string) ob_get_clean();

            self::assertStringContainsString('Add a collection', $markup, 'the manual intake surface still renders');
            self::assertNotSame('', trim($markup));
            // And it is not quietly rendering a withdrawn scanner control instead.
            foreach (['bcc_chain_cw_pause', 'bcc_chain_cw_backfill', 'bcc_discovery_scan_request'] as $withdrawn) {
                self::assertStringNotContainsString($withdrawn, $markup);
            }
        }

        /**
         * ⚠ THIS CASE INVERTED AT S4.
         *
         * It used to require `'bcc-trust cosmwasm'` to still be present in
         * bcc-trust.php and sitting behind `ScannerFreeze::frozen()`. The
         * registration is deleted, so the requirement is now its absence —
         * with the neighbouring command proving the file was actually read and
         * the CLI block still exists.
         */
        public function testTheScannerCliCommandIsNotRegistered(): void
        {
            $source = (string) file_get_contents(__DIR__ . '/../../bcc-trust.php');
            self::assertNotSame('', $source);

            self::assertStringNotContainsString(
                "'bcc-trust cosmwasm'",
                $source,
                'the one-shot discovery command must no longer be registered'
            );
            self::assertStringNotContainsString(
                'CosmwasmOneShotDiscoveryCommand::class',
                $source,
                'nothing in the bootstrap may name the one-shot command any more'
            );

            // Non-vacuity: the CLI block is still there and still registers the
            // commands that are not scanner entry points.
            self::assertStringContainsString("'bcc-trust push'", $source);
            self::assertStringContainsString("'bcc-trust gate-identity'", $source);
        }

        /**
         * Withdraw the surface, not the implementation.
         *
         * Four files, down from eight: the two admin-route classes and the two
         * panel views are deleted (asserted absent above). These four are the
         * retained implementation, and they stay inert because `frozen()`
         * returns true — which is why the deletion of the surface did not make
         * the freeze redundant.
         */
        public function testTheScannerImplementationIsPreserved(): void
        {
            $root = __DIR__ . '/../../';
            foreach ([
                'app/Domain/Onchain/CLI/CosmwasmOneShotDiscoveryCommand.php',
                'app/Domain/Onchain/Services/CosmwasmDiscoveryService.php',
                'app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php',
                'app/Domain/Onchain/Workers/DiscoveryRunExecutor.php',
            ] as $file) {
                self::assertFileExists($root . $file, 'the implementation is retained, not deleted');
            }
        }

        /** A filter would be one more way to reach a run, which is the thing being removed. */
        public function testTheFreezeCannotBeReopenedByAFilter(): void
        {
            $source = (string) file_get_contents(__DIR__ . '/../../app/Domain/Onchain/Support/ScannerFreeze.php');

            $code = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= $token[1];
                    continue;
                }
                $code .= $token;
            }

            self::assertStringNotContainsString('apply_filters', $code, 'the freeze must not be filterable');
            self::assertStringNotContainsString('get_option', $code, 'the freeze must not be an option anyone can flip');
            // `defined('ABSPATH')` is the file's standard direct-access guard, so the assertion is
            // scoped to a BCC constant that could be used to re-open the scanner.
            self::assertStringNotContainsString('BCC_SCANNER', $code, 'the freeze must not be a constant override');
        }
    }
}
