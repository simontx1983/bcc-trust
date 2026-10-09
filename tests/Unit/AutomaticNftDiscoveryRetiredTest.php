<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Trust\Onchain\Admin\ChainSweepActions;
    use BCC\Trust\Onchain\Services\ChainRefreshService;

    use BCC\Trust\Tests\Support\CronHealState;
    use PHPUnit\Framework\Attributes\CoversNothing;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * Automatic, unattended NFT collection discovery is retired.
     *
     * ── WHAT WAS RETIRED, AND WHY IT NEEDED MORE THAN A DELETION ────────
     * Five recurring hooks each walked chains on their own cadence:
     * `bcc_index_collections` swept every active EVM/Solana/Cosmos chain
     * for "top collections" every four hours, and the four `bcc_cosmwasm_*`
     * hooks walked every opted-in Cosmos chain for CW-721 code families.
     *
     * Deleting the `wp_schedule_event()` calls only stops FUTURE installs
     * from creating those events. Two things had to happen as well, and
     * both are asserted here:
     *
     *   1. `CosmwasmDiscoveryWorker::register()` self-healed four of them
     *      from `plugins_loaded` — on EVERY REQUEST. A hook cleared by hand
     *      came back on the next page load, so the schedule could not be
     *      retired by clearing it. The method is GONE, not emptied.
     *   2. Installs that already scheduled the events still carry them in
     *      `wp_options.cron`. A scheduled hook with no handler fires into
     *      nothing forever, and a drift detector cannot tell that state
     *      apart from a hook that has genuinely gone missing. The cleanup
     *      migration removes them, and proves it did.
     *
     * ── WHAT IS DELIBERATELY UNTOUCHED ──────────────────────────────────
     * Validator indexing and refresh, the EVM/Solana holdings indexer,
     * metadata enrichment for rows that already exist, community
     * provisioning, and wallet-linked collection refresh. None of those is
     * a chain-wide collection scanner, and each is asserted still present
     * rather than assumed.
     */
    #[CoversNothing]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class AutomaticNftDiscoveryRetiredTest extends TestCase
    {
        /** @var list<string> */
        private const RETIRED = [
            'bcc_index_collections',
            'bcc_cosmwasm_backfill_tick',
            'bcc_cosmwasm_daily_discovery',
            'bcc_cosmwasm_weekly_retry',
            'bcc_cosmwasm_metadata_refresh',
            // PR 7.1. Not a discovery sweep, but retired under the same
            // rule and by the same migration: a five-minute loop that
            // selected every active chain and called that chain's metadata
            // provider with no administrator-created run.
            'bcc_nft_enrichment_tick',
        ];

        protected function setUp(): void
        {
            parent::setUp();
            require_once __DIR__ . '/../Stubs/discovery-retirement-stubs.php';

            CronHealState::reset();
            \BccRetirementState::reset();
        }

        protected function tearDown(): void
        {
            CronHealState::$active = false;
            parent::tearDown();
        }

        // ── (1) initialization cannot recreate a retired hook ───────────

        /**
         * Plugin initialization is what used to re-arm the schedule, so it
         * is driven REPEATEDLY here rather than once: `register()` ran on
         * `plugins_loaded`, and one request was enough to undo a manual
         * clear. If anything on the init path still schedules, ten passes
         * will surface it.
         */
        public function testPluginInitializationCannotRecreateARetiredHook(): void
        {
            for ($i = 0; $i < 10; $i++) {
                ChainRefreshService::init();
            }

            foreach (self::RETIRED as $hook) {
                self::assertFalse(
                    \BCC\Trust\Onchain\Services\wp_next_scheduled($hook),
                    "{$hook} must not be scheduled by plugin initialization"
                );
            }
        }

        /**
         * And the self-healing entry point itself is gone.
         *
         * ⚠ S8 CHANGED WHAT THIS ASSERTS, BECAUSE THE OLD FORM WENT VACUOUS.
         * It was `assertFalse(method_exists(CosmwasmDiscoveryWorker::class,
         * 'register'))`, chosen so that an empty `register()` would still
         * fail. S8 deleted the whole class, so `method_exists` now returns
         * false because the CLASS is missing, not because the method is —
         * it would keep passing even if someone reintroduced the worker with
         * a scheduling `register()` under a different name.
         *
         * So it now asserts the stronger fact directly: the class does not
         * exist at all. The bootstrap checks below are unchanged and still
         * carry their original weight.
         */
        public function testTheSelfHealingRegistrationIsGone(): void
        {
            self::assertFalse(
                class_exists(\BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker::class),
                'the discovery worker was deleted in S8 and must not come back'
            );

            $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php');
            self::assertStringNotContainsString('CosmwasmDiscoveryWorker::register()', $bootstrap);

            foreach (self::RETIRED as $hook) {
                self::assertStringNotContainsString(
                    "wp_schedule_event(time(), 'every_4_hours', '{$hook}')",
                    $bootstrap,
                    "{$hook} must not be scheduled from the bootstrap"
                );
            }
        }

        /**
         * ChainRefreshService still schedules its VALIDATOR jobs — proof
         * the previous assertion is not passing because initialization
         * schedules nothing at all.
         */
        public function testValidatorSchedulesRemainPresentAndUnchanged(): void
        {
            ChainRefreshService::init();

            foreach ([
                'bcc_refresh_validators'  => 'hourly',
                'bcc_index_validators'    => 'every_4_hours',
                'bcc_refresh_collections' => 'every_4_hours',
            ] as $hook => $interval) {
                self::assertNotFalse(
                    \BCC\Trust\Onchain\Services\wp_next_scheduled($hook),
                    "{$hook} must still be scheduled"
                );
                self::assertSame(
                    $interval,
                    CronHealState::$scheduled[CronHealState::eventKey($hook)]['interval'],
                    "{$hook} must keep its interval"
                );
            }
        }

        /**
         * `bcc_refresh_collections` SURVIVES, and that is deliberate.
         *
         * It refreshes the collections a VERIFIED WALLET holds — bounded by
         * how many wallets members have linked, not by how large a chain
         * is. Only its chain-level branch was retired, and the repository
         * now excludes wallet-less rows at the query rather than
         * re-dating them on every cycle.
         */
        public function testWalletLinkedCollectionRefreshRemainsRegistered(): void
        {
            ChainRefreshService::init();

            self::assertNotFalse(\BCC\Trust\Onchain\Services\wp_next_scheduled('bcc_refresh_collections'));

            $src = (string) file_get_contents(
                dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/ChainRefreshService.php'
            );
            self::assertStringContainsString('getExpiredWalletLinked(', $src, 'the sweep must be wallet-scoped');
            self::assertStringNotContainsString('index_collections', str_replace(
                'bcc_index_collections',
                '',
                preg_replace('~^\s*//.*$~m', '', $src) ?? $src
            ), 'the chain-wide indexer must be gone from the service');
        }

        // ── (2)(3)(10) the cleanup migration ────────────────────────────

        /**
         * Pre-existing events are cleared, and the SCOPE of that clearing
         * is pinned rather than assumed.
         *
         * `wp_clear_scheduled_hook($hook)` addresses the EMPTY-ARGUMENT
         * identity, because WordPress keys events by hook AND serialized
         * arguments. An argument-bearing event for the same hook survives
         * it, and the postcondition — which asks `wp_next_scheduled($hook)`
         * — does not see that survivor either.
         *
         * That is correct for these five: every scheduler that created
         * them called `wp_schedule_event($ts, $interval, $hook)` with no
         * fourth argument, so no argument-bearing variant has ever
         * existed. The variant is seeded here anyway so the test states
         * what the migration actually guarantees instead of implying it
         * sweeps every identity of a hook.
         */
        public function testTheCleanupClearsPreExistingEvents(): void
        {
            foreach (self::RETIRED as $hook) {
                \BccRetirementState::schedule($hook, [], 'daily');
            }
            \BccRetirementState::schedule('bcc_cosmwasm_daily_discovery', [7], 'daily');

            self::assertSame(
                BCC_TRUST_MIGRATION_COMPLETE,
                bcc_trust_unschedule_automatic_nft_discovery()
            );

            // Every no-argument identity — the ones production actually has.
            foreach (self::RETIRED as $hook) {
                self::assertFalse(\BccRetirementState::next($hook, []), "{$hook} must be cleared");
            }

            // And the documented limit: an argument-bearing variant is out
            // of reach of both the clear and the postcondition.
            self::assertNotFalse(
                \BccRetirementState::next('bcc_cosmwasm_daily_discovery', [7]),
                'wp_clear_scheduled_hook($hook) does not reach an argument-bearing identity'
            );
        }

        /** The ordinary production case: no-argument events, all cleared. */
        public function testTheCleanupCompletesOnceEveryRetiredHookIsGone(): void
        {
            foreach (self::RETIRED as $hook) {
                \BccRetirementState::schedule($hook, [], 'daily');
            }

            self::assertSame(
                BCC_TRUST_MIGRATION_COMPLETE,
                bcc_trust_unschedule_automatic_nft_discovery()
            );

            foreach (self::RETIRED as $hook) {
                self::assertFalse(\BccRetirementState::next($hook, []));
            }
        }

        /** Running it twice is harmless — and still reports COMPLETE. */
        public function testRunningTheCleanupTwiceIsHarmless(): void
        {
            foreach (self::RETIRED as $hook) {
                \BccRetirementState::schedule($hook, [], 'daily');
            }

            self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_unschedule_automatic_nft_discovery());
            $afterFirst = \BccRetirementState::$events;

            self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_unschedule_automatic_nft_discovery());
            self::assertSame($afterFirst, \BccRetirementState::$events, 'the second run changed nothing');
        }

        /**
         * FAIL CLOSED. A hook still scheduled after the clear leaves the
         * migration INCOMPLETE so it runs again — it never marks itself
         * done on the strength of having merely CALLED the clear.
         */
        public function testTheCleanupFailsClosedWhenAHookSurvives(): void
        {
            \BccRetirementState::schedule('bcc_index_collections', [], 'every_4_hours');
            \BccRetirementState::$refuseToClear = ['bcc_index_collections'];

            self::assertSame(
                BCC_TRUST_MIGRATION_INCOMPLETE,
                bcc_trust_unschedule_automatic_nft_discovery()
            );
        }

        /** Unrelated cron events are not touched. */
        public function testUnrelatedCronEventsSurviveTheCleanup(): void
        {
            foreach (self::RETIRED as $hook) {
                \BccRetirementState::schedule($hook, [], 'daily');
            }
            foreach ([
                'bcc_refresh_validators',
                'bcc_index_validators',
                'bcc_refresh_collections',
                'bcc_nft_eth_indexer_tick',
                                'bcc_gated_group_provision',
                'bcc_trust_daily_cleanup',
            ] as $keep) {
                \BccRetirementState::schedule($keep, [], 'hourly');
            }

            self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_unschedule_automatic_nft_discovery());

            foreach ([
                'bcc_refresh_validators',
                'bcc_index_validators',
                'bcc_refresh_collections',
                'bcc_nft_eth_indexer_tick',
                                'bcc_gated_group_provision',
                'bcc_trust_daily_cleanup',
            ] as $keep) {
                self::assertNotFalse(\BccRetirementState::next($keep, []), "{$keep} must survive");
            }
        }

        /** The migration writes nothing but cron state. */
        public function testTheCleanupTouchesNoDomainRepository(): void
        {
            $src = (string) file_get_contents(
                dirname(__DIR__, 2) . '/includes/database/unschedule-automatic-nft-discovery.php'
            );

            foreach ([
                'CollectionRepository',
                'GatedGroupRepository',
                'setVerified',
                'is_verified',
                '$wpdb',
                'provision',
                'wp_insert_post',
                'wp_delete_post',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $src,
                    "the cleanup must not reference {$forbidden}"
                );
            }
        }

        // ── (4)(5)(6)(7) preserved subsystems ───────────────────────────

        /**
         * The hooks that must keep firing are still bound in the bootstrap.
         *
         * Asserted against the bootstrap SOURCE because binding happens at
         * file scope: loading bcc-trust.php in-process to observe
         * `add_action` would drag in all of WordPress.
         */
        public function testPreservedSubsystemsAreStillWired(): void
        {
            $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php');

            foreach ([
                'NftEthIndexerWorker::register()'   => 'EVM/Solana holdings indexing',
                'NftEnrichmentService::register()'  => 'metadata enrichment for known rows',
                'bcc_gated_group_provision'         => 'community provisioning',
                'bcc_helius_dedupe_sweep'           => 'Helius processing',
                'ValidatorMsgQueueWorker::register' => 'validator messaging',
            ] as $needle => $what) {
                self::assertStringContainsString($needle, $bootstrap, "{$what} must remain wired");
            }
        }

        /**
         * The supervised WP-CLI pass is GONE — this case used to assert the
         * opposite, and S8 inverted it rather than deleting it.
         *
         * ⚠ IT ASSERTED A FACT THAT S8 MADE FALSE. When the four automatic
         * hooks were retired in 2026-08, the supervised one-shot command was
         * deliberately kept as the single operator-initiated way to run a
         * full-chain pass, and this case existed to stop the retirement from
         * taking it with them. S4 withdrew its registration and S8 deleted
         * the command and the worker behind it, so the original claim is now
         * simply untrue.
         *
         * Inverted rather than removed because the direction of travel is
         * the point: a reader of this file should be able to see that the
         * escape hatch the 2026-08 retirement preserved was itself closed
         * later, deliberately, and is not supposed to reappear.
         */
        public function testTheOneShotCliPathIsGoneToo(): void
        {
            self::assertFalse(
                class_exists(\BCC\Trust\Onchain\CLI\CosmwasmOneShotDiscoveryCommand::class),
                'the one-shot discovery command was deleted in S8'
            );
            self::assertFalse(
                class_exists(\BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker::class),
                'and so was the worker it drove'
            );

            // ⚠ THE "NOT REGISTERED UNDER ITS OLD NAME" CHECK LIVES IN
            // `ScannerRemovedInventoryTest::testTheRetiredCliCommandIsNotRegistered`,
            // NOT HERE, AND THAT IS NOT A STYLE CHOICE.
            //
            // Asserting it on the raw bytes of bcc-trust.php fails on the
            // RETIREMENT COMMENT that records the removal — the prose names the
            // subcommand in order to say it is gone. The inventory test strips
            // comments with `token_get_all()` before looking, so it can tell a
            // docblock from a registration; a raw `file_get_contents` cannot.
            //
            // This case keeps the part that needs no source reading at all.
        }

        // ── (8) the admin no longer offers collection discovery ─────────

        public function testRunAllNoLongerContainsACollectionsStep(): void
        {
            self::assertSame(['validators', 'enrichment'], ChainSweepActions::stepsForSlug('all'));
            self::assertSame([], ChainSweepActions::stepsForSlug('collections'));
            self::assertFalse(
                defined(ChainSweepActions::class . '::ACTION_COLLECTIONS'),
                'the collections sweep route must not exist'
            );

            // Assert on EXECUTABLE content: the class docblock legitimately
            // explains what was removed and why, and a substring match
            // against the whole file would forbid that explanation.
            $src  = (string) file_get_contents(
                dirname(__DIR__, 2) . '/app/Domain/Onchain/Admin/ChainSweepActions.php'
            );
            $code = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
            $code = preg_replace('~^\s*//.*$~m', '', $code) ?? $code;

            self::assertStringNotContainsString('index_collections', $code);
            self::assertStringNotContainsString("'collections'", $code);
        }

        /** And the Chains page offers no chain-wide collection refresh. */
        public function testTheChainsPageOffersNoCollectionRefresh(): void
        {
            $src = (string) file_get_contents(
                dirname(__DIR__, 2) . '/app/Domain/Onchain/Admin/ChainsPage.php'
            );

            foreach ([
                'ajax_collection_refresh',
                'bcc_collection_refresh',
                'render_collections_tab',
                'Refresh All Collections',
                'fetch_top_collections',
            ] as $gone) {
                self::assertStringNotContainsString($gone, $src, "{$gone} must be gone from the Chains page");
            }
        }
    }
}
