<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Onchain\Services\NftGroupGateService;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * #409 / F-158: the opt-out map must be read from user meta, the one
     * shared store, never from a per-instance memo.
     *
     * The F-158 join gate refuses only a PERMANENT opt-out (a moderator
     * eviction) and lets a voluntary one through. A gate instance that memoized
     * a member's map while they had only LEFT, and that is then asked again
     * after a moderator evicted them through another instance, would read
     * "left" and let an evicted member rejoin. This is exactly how
     * DelegatorGroupIntegrationTest::testTheListingSaysWhichKindOfOptOutEachIs
     * failed: the test wrote the eviction through its own instance, while the
     * listing read through OnchainPlugin's memoized one.
     *
     * In production every caller shares OnchainPlugin's single instance, which
     * updated its own memo on write, so the invariant held there. A second
     * instance anywhere would silently break it, and WordPress already caches
     * user meta, so the memo bought nothing.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class OptOutMapCoherenceTest extends TestCase
    {
        private const USER_ID  = 701;
        private const GROUP_ID = 4400;

        protected function setUp(): void
        {
            require_once dirname(__DIR__) . '/Stubs/optout-map-stubs.php';

            $GLOBALS['__bcc_optout_meta']  = [];
            $GLOBALS['__bcc_optout_reads'] = 0;
        }

        public function testAnEvictionWrittenByOneInstanceIsSeenByAnotherThatAlreadyReadTheMap(): void
        {
            $reader = new NftGroupGateService();
            $writer = new NftGroupGateService();

            $writer->recordOptOut(self::USER_ID, self::GROUP_ID);
            self::assertFalse(
                $reader->isPermanentOptOut(self::USER_ID, self::GROUP_ID),
                'a self-leave is not permanent, so Join stays offered'
            );

            $writer->recordPermanentOptOut(self::USER_ID, self::GROUP_ID);
            self::assertTrue(
                $reader->isPermanentOptOut(self::USER_ID, self::GROUP_ID),
                'a moderator eviction must be seen by every instance, or the evicted member could rejoin'
            );
        }

        public function testAClearedOptOutIsSeenByAnotherInstance(): void
        {
            $reader = new NftGroupGateService();
            $writer = new NftGroupGateService();

            $writer->recordPermanentOptOut(self::USER_ID, self::GROUP_ID);
            self::assertTrue($reader->isPermanentOptOut(self::USER_ID, self::GROUP_ID));

            $writer->clearOptOut(self::USER_ID, self::GROUP_ID);
            self::assertFalse($reader->isPermanentOptOut(self::USER_ID, self::GROUP_ID));
        }

        public function testEveryCheckReadsTheStoreRatherThanAMemo(): void
        {
            $gate = new NftGroupGateService();
            $gate->recordOptOut(self::USER_ID, self::GROUP_ID);
            $GLOBALS['__bcc_optout_reads'] = 0;

            $gate->isPermanentOptOut(self::USER_ID, self::GROUP_ID);
            $gate->isPermanentOptOut(self::USER_ID, self::GROUP_ID);

            self::assertSame(2, $GLOBALS['__bcc_optout_reads']);
        }
    }
}
