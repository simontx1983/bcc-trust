<?php

declare(strict_types=1);

namespace {
    if (!function_exists('wp_next_scheduled')) {
        /** @param array<int, mixed> $args */
        function wp_next_scheduled(string $hook, array $args = [])
        {
            foreach ($GLOBALS['__bcc_sched'] ?? [] as $e) {
                if ($e[1] === $hook && $e[2] === $args) {
                    return $e[0];
                }
            }
            return false;
        }
    }
    if (!function_exists('wp_schedule_single_event')) {
        /** @param array<int, mixed> $args */
        function wp_schedule_single_event(int $ts, string $hook, array $args = []): bool
        {
            $GLOBALS['__bcc_sched'][] = [$ts, $hook, $args];
            return true;
        }
    }
}

namespace BCC\Trust\Tests\Unit {

    use BCC\Trust\Onchain\Services\NftGroupRevokeService;
    use BCC\Trust\Onchain\Services\NftHoldingLostListener;
    use BCC\Trust\Onchain\Services\NftHoldingsIndexer;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-278: a gated-group member who sells is re-checked within a minute.
     *
     *   - lostHoldings(): exactly the holdings a committed batch REMOVED —
     *     721 deletes and negative 1155 deltas; never a transfer-in.
     *   - the listener schedules ONE re-check per (member, holder group);
     *     a non-holder collection schedules nothing; a re-check that finds a
     *     sweep running retries a bounded number of times.
     */
    #[CoversClass(NftHoldingsIndexer::class)]
    #[CoversClass(NftHoldingLostListener::class)]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class NftHoldingLostTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['__bcc_sched'] = [];
            if (!class_exists('\\BCC\\Core\\Log\\Logger', false)) {
                eval('namespace BCC\\Core\\Log; final class Logger { public static function info(string $m, array $c = []): void {} public static function warning(string $m, array $c = []): void {} public static function error(string $m, array $c = []): void {} }');
            }
            NftHoldingLostListener::$userForWallet      = static fn (int $w): ?int => $w === 7 ? 501 : null;
            NftHoldingLostListener::$groupForCollection = static fn (int $c, string $k): ?int => $k === '0xgated' ? 9001 : null;
            NftHoldingLostListener::$recheck            = null;
        }

        /** @return array<string, mixed> */
        private static function plan(array $deletes, array $deltas): array
        {
            return ['upserts' => [], 'deletes' => $deletes, 'deltas' => $deltas, 'skipped' => 0, 'spam_filtered' => 0];
        }

        private static function del(int $link, string $contract): array
        {
            return ['wallet_link_id' => $link, 'contract' => $contract, 'token_id' => '1', 'block' => 1, 'chain_id' => 1, 'confirmed_at' => '2026-11-12 00:00:00', 'token_standard' => 'ERC-721'];
        }

        private static function delta(int $link, string $contract, int $delta): array
        {
            return ['wallet_link_id' => $link, 'chain_id' => 1, 'contract_address' => $contract, 'token_id' => '1', 'token_standard' => 'ERC-1155', 'delta' => $delta, 'metadata_status' => 1, 'last_seen_block' => 1, 'confirmed_at' => '2026-11-12 00:00:00'];
        }

        // ── lostHoldings ──────────────────────────────────────────────────

        public function testOnlyRemovedHoldingsAreReportedOncePerWalletAndContract(): void
        {
            $lost = NftHoldingsIndexer::lostHoldings(self::plan(
                [self::del(7, '0xgated'), self::del(7, '0xgated'), self::del(8, '0xother')],
                [self::delta(9, '0x1155', -2), self::delta(10, '0x1155', +3)]
            ));

            self::assertSame([
                ['wallet_link_id' => 7, 'chain_id' => 1, 'contract' => '0xgated'],
                ['wallet_link_id' => 8, 'chain_id' => 1, 'contract' => '0xother'],
                ['wallet_link_id' => 9, 'chain_id' => 1, 'contract' => '0x1155'],
            ], $lost, 'two token deletes on one contract are one loss; a 1155 increase is not a loss');
        }

        public function testATransferInReportsNothing(): void
        {
            self::assertSame([], NftHoldingsIndexer::lostHoldings(self::plan([], [self::delta(10, '0x1155', +1)])));
        }

        /**
         * ingest() persists through the DB, so pin its wiring: after the
         * committed batch, every lost holding invalidates the wallet's cached
         * holdings and fires bcc_nft_holding_lost.
         */
        public function testIngestAnnouncesEveryLostHoldingAfterTheCommit(): void
        {
            $m     = new \ReflectionMethod(NftHoldingsIndexer::class, 'ingest');
            $lines = file((string) $m->getFileName());
            self::assertIsArray($lines);
            $body  = implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));

            $commit = strpos($body, 'NftHoldingsRepository::ingestBatch(');
            $loop   = strpos($body, 'foreach (self::lostHoldings($plan) as $lost)');
            self::assertNotFalse($commit);
            self::assertNotFalse($loop);
            self::assertGreaterThan($commit, $loop, 'announced only after the batch is persisted');
            self::assertStringContainsString("HoldingsService::invalidateWallet(\$lost['wallet_link_id'])", $body);
            self::assertStringContainsString("do_action('bcc_nft_holding_lost', \$lost['wallet_link_id'], \$lost['chain_id'], \$lost['contract'])", $body);
        }

        // ── the listener ──────────────────────────────────────────────────

        public function testALostGatedHoldingSchedulesOneRecheckForThatMember(): void
        {
            NftHoldingLostListener::onHoldingLost(7, 1, '0xgated');
            NftHoldingLostListener::onHoldingLost(7, 1, '0xgated'); // the same loss reported twice

            self::assertCount(1, $GLOBALS['__bcc_sched'], 'deduplicated');
            [$ts, $hook, $args] = $GLOBALS['__bcc_sched'][0];
            self::assertSame(NftGroupRevokeService::RECHECK_HOOK, $hook);
            self::assertSame([501, 9001], $args);
            self::assertEqualsWithDelta(time() + NftHoldingLostListener::RECHECK_DELAY, $ts, 5);
        }

        public function testANonGatedCollectionOrUnknownWalletSchedulesNothing(): void
        {
            NftHoldingLostListener::onHoldingLost(7, 1, '0xnotgated');
            NftHoldingLostListener::onHoldingLost(99, 1, '0xgated');
            NftHoldingLostListener::onHoldingLost(0, 1, '0xgated');

            // A lookup that answers 0 (not null) must still schedule nothing.
            NftHoldingLostListener::$groupForCollection = static fn (int $c, string $k): ?int => $k === '0xgated' ? 9001 : 0;
            NftHoldingLostListener::$userForWallet      = static fn (int $w): ?int => $w === 7 ? 501 : 0;
            NftHoldingLostListener::onHoldingLost(7, 1, '0xnotgated');
            NftHoldingLostListener::onHoldingLost(99, 1, '0xgated');

            self::assertSame([], $GLOBALS['__bcc_sched']);
        }

        public function testALockedRecheckRetriesABoundedNumberOfTimes(): void
        {
            NftHoldingLostListener::$recheck = static fn (int $u, int $g): string => 'locked';

            NftHoldingLostListener::runRecheck(501, 9001, 0);
            NftHoldingLostListener::runRecheck(501, 9001, NftHoldingLostListener::MAX_LOCKED_RETRIES);

            self::assertCount(1, $GLOBALS['__bcc_sched'], 'one retry from attempt 0; none past the cap');
            self::assertSame([501, 9001, 1], $GLOBALS['__bcc_sched'][0][2]);
        }

        public function testAFinishedRecheckSchedulesNothingFurther(): void
        {
            foreach (['revoked', 'kept', 'unknown', 'owner', 'not_member'] as $outcome) {
                NftHoldingLostListener::$recheck = static fn (int $u, int $g): string => $outcome;
                NftHoldingLostListener::runRecheck(501, 9001, 0);
            }

            self::assertSame([], $GLOBALS['__bcc_sched']);
        }
    }
}
