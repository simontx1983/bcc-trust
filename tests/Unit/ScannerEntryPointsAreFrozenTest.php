<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Trust\Onchain\Admin\DiscoveryRunStatusEndpoint;
    use BCC\Trust\Onchain\Admin\DiscoveryScanActions;
    use BCC\Trust\Onchain\Admin\NftDiscoveryPage;
    use BCC\Trust\Onchain\Admin\Views\CosmwasmScannerPanel;
    use BCC\Trust\Onchain\Admin\Views\DiscoveryScanPanel;
    use BCC\Trust\Onchain\Support\ScannerFreeze;
    use PHPUnit\Framework\Attributes\CoversNothing;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../Stubs/onchain-admin-action-stubs.php';

    /**
     * Full-chain discovery is FROZEN: no entry point registers, no control renders.
     *
     * ── WHAT IS ASSERTED, AND WHY BOTH HALVES MATTER ────────────────────
     * A freeze is only worth the name if it is provable in both directions:
     *
     *   1. Nothing that can START, CONTINUE, RETRY, CANCEL or INSPECT a run
     *      is reachable — the three admin-post scan routes, the run-status
     *      AJAX action, the four CosmWasm run controls, their buttons and
     *      nonces, and the one-shot CLI command.
     *   2. Everything that is NOT a full-chain run still works — manual Add
     *      Collection and its nonce, the capability controls, chain
     *      discovery enable/disable (configuration, which starts nothing),
     *      and the legacy-URL redirect. Asserting only absence would pass
     *      just as well if the whole page had been deleted.
     *
     * The implementation is deliberately still here: services, workers, the
     * CLI class, the panels and their markup are untouched and still tested.
     * What this PR removes is the way to reach them, so the freeze is a
     * one-line revert rather than a restoration from history.
     */
    #[CoversNothing]
    #[Group('unit')]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class ScannerEntryPointsAreFrozenTest extends TestCase
    {
        /** Hooks that must NOT exist while the scanner is frozen. */
        private const FROZEN_HOOKS = [
            'admin_post_bcc_discovery_scan_request',
            'admin_post_bcc_discovery_scan_retry',
            'admin_post_bcc_discovery_scan_cancel',
            'wp_ajax_bcc_discovery_run_status',
            'admin_post_bcc_chain_cw_pause',
            'admin_post_bcc_chain_cw_resume',
            'admin_post_bcc_chain_cw_backfill',
            'admin_post_bcc_chain_cw_retry',
        ];

        /** Hooks that must SURVIVE the freeze — none of them starts a run. */
        private const KEPT_HOOKS = [
            'admin_post_bcc_nftd_add_collection',
            'admin_post_bcc_nft_cap_product_enable',
            'admin_post_bcc_nft_cap_manual_enable',
            'admin_post_bcc_nft_cap_driver_inherit',
            'admin_post_bcc_nft_cap_stale_remove',
            'admin_post_bcc_chain_cw_discovery_enable',
            'admin_post_bcc_chain_cw_discovery_disable',
        ];

        /** @return list<string> */
        private function registerEverything(): array
        {
            $GLOBALS['bcc_test_registered_actions'] = [];
            DiscoveryScanActions::register();
            DiscoveryRunStatusEndpoint::register();
            NftDiscoveryPage::register_actions();

            return array_values(array_map('strval', $GLOBALS['bcc_test_registered_actions'] ?? []));
        }

        public function testTheFreezeIsOn(): void
        {
            self::assertTrue(ScannerFreeze::frozen(), 'the scanner surface is frozen in this build');
        }

        public function testNoScannerEntryPointCallbackIsRegistered(): void
        {
            $registered = $this->registerEverything();

            foreach (self::FROZEN_HOOKS as $hook) {
                self::assertNotContains($hook, $registered, "$hook must not be registered while the scanner is frozen");
            }
        }

        /** Without this, the absence above would also pass if registration simply never ran. */
        public function testTheControlsThatAreNotRunsStillRegister(): void
        {
            $registered = $this->registerEverything();

            self::assertNotSame([], $registered, 'registration must actually have happened');
            foreach (self::KEPT_HOOKS as $hook) {
                self::assertContains($hook, $registered, "$hook is not a run control and must survive the freeze");
            }
        }

        public function testTheFrozenEntryPointInventoryMatchesWhatIsAsserted(): void
        {
            $declared = array_values(array_filter(
                ScannerFreeze::FROZEN_ENTRY_POINTS,
                static fn(string $e): bool => !str_starts_with($e, 'cli:')
            ));
            sort($declared);
            $asserted = self::FROZEN_HOOKS;
            sort($asserted);

            self::assertSame($asserted, $declared, 'the documented inventory and the guard must not drift apart');
        }

        public function testNoScannerControlOrContinuationMarkupRenders(): void
        {
            $chain = (object) ['id' => 8, 'slug' => 'cosmos', 'name' => 'Cosmos Hub', 'chain_type' => 'cosmos'];

            ob_start();
            DiscoveryScanPanel::render($chain, true, '');
            CosmwasmScannerPanel::render(['status' => 'healthy', 'discovery_enabled' => true]);
            NftDiscoveryPage::render_cw_operation_control(NftDiscoveryPage::ACTION_CW_PAUSE, 8, 'cosmos');
            NftDiscoveryPage::render_cw_operation_control(NftDiscoveryPage::ACTION_CW_RESUME, 8, 'cosmos');
            NftDiscoveryPage::render_cw_operation_control(NftDiscoveryPage::ACTION_CW_BACKFILL, 8, 'cosmos');
            NftDiscoveryPage::render_cw_operation_control(NftDiscoveryPage::ACTION_CW_RETRY, 8, 'cosmos');
            $markup = (string) ob_get_clean();

            self::assertSame('', $markup, 'no scanner control, continuation link or nonce may render');
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
            // And it is not quietly rendering a scanner control instead.
            foreach (['bcc_chain_cw_pause', 'bcc_chain_cw_backfill', 'bcc_discovery_scan_request'] as $frozen) {
                self::assertStringNotContainsString($frozen, $markup);
            }
        }

        /**
         * The CLI command is registered only when the freeze is off, and the
         * neighbouring commands prove the check is specific rather than a
         * blanket guard around the whole block.
         */
        public function testTheScannerCliCommandIsNotRegistered(): void
        {
            $source = (string) file_get_contents(__DIR__ . '/../../bcc-trust.php');
            self::assertNotSame('', $source);

            $pos = strpos($source, "'bcc-trust cosmwasm'");
            self::assertIsInt($pos, 'the one-shot command registration must still be in the file, guarded');

            $before = substr($source, max(0, $pos - 600), min(600, $pos));
            self::assertStringContainsString('ScannerFreeze::frozen()', $before, 'the scanner CLI registration is behind the freeze');

            // Non-vacuity: a command that is NOT frozen must not be behind the guard.
            $pushPos = strpos($source, "'bcc-trust push'");
            self::assertIsInt($pushPos);
            $beforePush = substr($source, max(0, $pushPos - 600), min(600, $pushPos));
            self::assertStringNotContainsString('ScannerFreeze::frozen()', $beforePush, 'unrelated CLI commands are untouched');
        }

        /** Freeze, not delete: the next PR removes the implementation, this one must not. */
        public function testTheScannerImplementationIsPreserved(): void
        {
            $root = __DIR__ . '/../../';
            foreach ([
                'app/Domain/Onchain/CLI/CosmwasmOneShotDiscoveryCommand.php',
                'app/Domain/Onchain/Services/CosmwasmDiscoveryService.php',
                'app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php',
                'app/Domain/Onchain/Workers/DiscoveryRunExecutor.php',
                'app/Domain/Onchain/Admin/DiscoveryScanActions.php',
                'app/Domain/Onchain/Admin/DiscoveryRunStatusEndpoint.php',
                'app/Domain/Onchain/Admin/Views/DiscoveryScanPanel.php',
                'app/Domain/Onchain/Admin/Views/CosmwasmScannerPanel.php',
            ] as $file) {
                self::assertFileExists($root . $file, 'the implementation must be frozen, not deleted');
            }

            // The handlers behind the frozen routes are still there to be re-registered.
            self::assertTrue(method_exists(DiscoveryScanActions::class, 'handle'));
            self::assertTrue(method_exists(DiscoveryRunStatusEndpoint::class, 'handle'));
            foreach (['handle_cw_pause', 'handle_cw_resume', 'handle_cw_backfill', 'handle_cw_retry'] as $m) {
                self::assertTrue(method_exists(NftDiscoveryPage::class, $m), "$m must survive the freeze");
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
