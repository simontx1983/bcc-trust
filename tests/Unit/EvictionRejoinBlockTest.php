<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Onchain\Services\NftGroupGateService;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * H7 follow-up: a moderator eviction's rejoin block reports whether it
     * was recorded, retries when it wasn't, and leaves an audit trail; and
     * every opt-out map change runs under a per-user advisory lock.
     *
     * Same world as OptOutMapReadFailClosedTest (tests/Stubs/optout-map-stubs.php):
     * the REAL NftGroupGateService, bcc-core's REAL UserMetaRepository and the
     * REAL UserMetaSwapRepository over the $wpdb double, plus recorders for
     * AdvisoryLock, AuditLogger and wp_schedule_single_event.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class EvictionRejoinBlockTest extends TestCase
    {
        private const USER  = 701;
        private const GROUP = 44;
        private const KEY   = 'bcc_gated_groups_optout';

        private \TokenStateWpdb $db;

        protected function setUp(): void
        {
            require_once dirname(__DIR__) . '/Stubs/optout-map-stubs.php';
            $this->db = new \TokenStateWpdb();
            $GLOBALS['wpdb'] = $this->db;
            $GLOBALS['__bcc_optout_audit']     = [];
            $GLOBALS['__bcc_optout_scheduled'] = [];
            $GLOBALS['__bcc_optout_log']       = [];
            \BCC\Core\DB\AdvisoryLock::$calls         = [];
            \BCC\Core\DB\AdvisoryLock::$heldElsewhere = [];
        }

        /** @return list<string> */
        private static function auditActions(): array
        {
            return array_map(static fn(array $row): string => $row['action'], $GLOBALS['__bcc_optout_audit']);
        }

        // ── recordPermanentOptOut reports the outcome ──────────────────────

        public function testRecordPermanentOptOutIsTrueWhenRecorded(): void
        {
            self::assertTrue((new NftGroupGateService())->recordPermanentOptOut(self::USER, self::GROUP));
            self::assertSame([self::GROUP => 0], \OptOutStore::map(self::USER));
        }

        public function testRecordPermanentOptOutIsFalseWhenTheMapCannotBeRead(): void
        {
            $this->db->mode = 'error';

            self::assertFalse((new NftGroupGateService())->recordPermanentOptOut(self::USER, self::GROUP));
        }

        // ── the eviction's rejoin block ────────────────────────────────────

        public function testAnEvictionRecordedAtOnceLeavesNoAuditAndNoRetry(): void
        {
            self::assertTrue((new NftGroupGateService())->recordEvictionRejoinBlock(self::USER, self::GROUP));

            self::assertSame([self::GROUP => 0], \OptOutStore::map(self::USER));
            self::assertSame([], $GLOBALS['__bcc_optout_audit']);
            self::assertSame([], $GLOBALS['__bcc_optout_scheduled']);
        }

        public function testAnEvictionThatCannotBeRecordedIsAuditedAndRetried(): void
        {
            $this->db->mode = 'error';
            $before = time();

            self::assertFalse((new NftGroupGateService())->recordEvictionRejoinBlock(self::USER, self::GROUP));

            self::assertSame([[
                'action'      => 'group_rejoin_block_failed',
                'target_id'   => self::GROUP,
                'meta'        => ['attempt' => 0, 'final' => false],
                'target_type' => 'group',
                'user_id'     => self::USER,
            ]], $GLOBALS['__bcc_optout_audit']);

            self::assertCount(1, $GLOBALS['__bcc_optout_scheduled']);
            $retry = $GLOBALS['__bcc_optout_scheduled'][0];
            self::assertSame(NftGroupGateService::REJOIN_BLOCK_RETRY_HOOK, $retry['hook']);
            self::assertSame([self::USER, self::GROUP, 1], $retry['args']);
            self::assertGreaterThanOrEqual($before + NftGroupGateService::REJOIN_BLOCK_RETRY_DELAY, $retry['at']);
        }

        public function testARetryThatRecordsItIsAuditedAsRecorded(): void
        {
            self::assertTrue((new NftGroupGateService())->recordEvictionRejoinBlock(self::USER, self::GROUP, 2));

            self::assertSame([self::GROUP => 0], \OptOutStore::map(self::USER));
            self::assertSame(['group_rejoin_block_recorded'], self::auditActions());
            self::assertSame(['attempt' => 2], $GLOBALS['__bcc_optout_audit'][0]['meta']);
            self::assertSame([], $GLOBALS['__bcc_optout_scheduled']);
        }

        public function testAFailingRetrySchedulesTheNextAttempt(): void
        {
            $this->db->mode = 'error';

            (new NftGroupGateService())->recordEvictionRejoinBlock(self::USER, self::GROUP, 2);

            self::assertSame(['attempt' => 2, 'final' => false], $GLOBALS['__bcc_optout_audit'][0]['meta']);
            self::assertSame([self::USER, self::GROUP, 3], $GLOBALS['__bcc_optout_scheduled'][0]['args'] ?? null);
        }

        public function testTheLastAttemptStopsRetryingAndIsAuditedAsFinal(): void
        {
            $this->db->mode = 'error';

            self::assertFalse((new NftGroupGateService())->recordEvictionRejoinBlock(
                self::USER,
                self::GROUP,
                NftGroupGateService::REJOIN_BLOCK_RETRY_ATTEMPTS
            ));

            self::assertSame(['attempt' => NftGroupGateService::REJOIN_BLOCK_RETRY_ATTEMPTS, 'final' => true], $GLOBALS['__bcc_optout_audit'][0]['meta']);
            self::assertSame([], $GLOBALS['__bcc_optout_scheduled'], 'no retry after the last attempt');
        }

        // ── wiring (bcc-trust.php cannot be loaded in a unit process) ─────

        /**
         * A source pin, not a behaviour test: the moderator-eviction listener
         * goes through the retrying entry point, and the retry hook is
         * registered with all three arguments.
         */
        public function testTheEvictionListenerAndTheRetryHookAreWired(): void
        {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php');
            $src = (string) preg_replace('/\s+/', ' ', $src);

            $start    = (int) strpos($src, "add_action('peepso_action_group_user_delete'");
            $listener = substr($src, $start, (int) strpos($src, '}, 10, 2);', $start) - $start);
            self::assertStringContainsString('->recordEvictionRejoinBlock($userId, $groupId);', $listener);
            self::assertStringNotContainsString('->recordPermanentOptOut(', $listener);

            self::assertStringContainsString(
                '\BCC\Trust\Onchain\Services\NftGroupGateService::REJOIN_BLOCK_RETRY_HOOK, static function ($userId, $groupId, $attempt): void { \BCC\Trust\Onchain\OnchainPlugin::instance() ->nftGroupGateService() ->recordEvictionRejoinBlock((int) $userId, (int) $groupId, (int) $attempt); }, 10, 3 );',
                $src
            );
        }

        // ── the per-user lock ──────────────────────────────────────────────

        public function testAChangeRunsUnderThePerUserLock(): void
        {
            (new NftGroupGateService())->recordOptOut(self::USER, self::GROUP);

            self::assertSame(
                ['acquire:bcc_optout_map_' . self::USER, 'release:bcc_optout_map_' . self::USER],
                \BCC\Core\DB\AdvisoryLock::$calls
            );
        }

        public function testTheLockIsReleasedWhenTheChangeGivesUp(): void
        {
            $this->db->mode = 'error';

            (new NftGroupGateService())->recordOptOut(self::USER, self::GROUP);

            self::assertSame(
                ['acquire:bcc_optout_map_' . self::USER, 'release:bcc_optout_map_' . self::USER],
                \BCC\Core\DB\AdvisoryLock::$calls
            );
        }

        /** A lock it cannot have is not a refusal: the swap still guards the write. */
        public function testAChangeWithoutTheLockStillRunsAndIsLogged(): void
        {
            \BCC\Core\DB\AdvisoryLock::$heldElsewhere = ['bcc_optout_map_' . self::USER];

            (new NftGroupGateService())->recordPermanentOptOut(self::USER, self::GROUP);

            self::assertSame([self::GROUP => 0], \OptOutStore::map(self::USER));
            self::assertSame(['acquire:bcc_optout_map_' . self::USER], \BCC\Core\DB\AdvisoryLock::$calls, 'nothing to release');
        }
    }
}
