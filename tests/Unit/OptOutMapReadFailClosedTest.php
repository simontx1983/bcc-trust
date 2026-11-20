<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Onchain\Services\NftGroupGateService;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * H7: a change to the opt-out map never writes over a map it could not
     * read, and never overwrites another request's change.
     *
     * The map is ONE user-meta value holding every group's opt-out, so each
     * change is a read-modify-write, and get_user_meta() answers a failed
     * read as ''. Every change must start from a map that was really read.
     *
     * The world (tests/Stubs/optout-map-stubs.php) is one store behind both
     * the REAL trusted read and the meta functions, and its get_user_meta()
     * fails exactly when the database does, as WordPress's does.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class OptOutMapReadFailClosedTest extends TestCase
    {
        private const USER   = 701;
        private const KEY    = 'bcc_gated_groups_optout';
        private const EVICTED = 10;
        private const LEFT    = 20;
        private const NEW     = 30;

        private \TokenStateWpdb $db;

        protected function setUp(): void
        {
            require_once dirname(__DIR__) . '/Stubs/optout-map-stubs.php';
            $this->db = new \TokenStateWpdb();
            $GLOBALS['wpdb'] = $this->db;
            $GLOBALS['__bcc_optout_log']           = [];
            $GLOBALS['__bcc_optout_cache_deletes'] = [];
        }

        /** The member has a moderator eviction and a recent leave on record. */
        private function storedMap(): string
        {
            $raw = (string) json_encode([self::EVICTED => 0, self::LEFT => time() - 3600]);
            $this->db->put(self::USER, self::KEY, $raw);
            return $raw;
        }

        private function storedRaw(): ?string
        {
            $rows = \OptOutStore::rowsFor(self::USER, self::KEY);
            return $rows === [] ? null : $this->db->rows[$rows[0]]['meta_value'];
        }

        // ── A read that fails writes nothing ───────────────────────────────

        public function testALeaveDuringAFailedReadErasesNothing(): void
        {
            $before = $this->storedMap();
            $this->db->mode = 'error';

            (new NftGroupGateService())->recordOptOut(self::USER, self::NEW);

            self::assertSame($before, $this->storedRaw(), 'the eviction and the earlier leave are both still there');
            self::assertSame([], $this->db->writeVerbs(), 'nothing was written');
        }

        /**
         * ⚠⚠ The pre-flush trap: query() bails before flush(), last_result
         * still holds an earlier query's rows (here an EMPTY map for this
         * member), and WordPress's own read answers ''.
         */
        public function testALeaveDuringStaleRowsErasesNothing(): void
        {
            $before = $this->storedMap();
            $this->db->last_result = [(object) ['user_id' => (string) self::USER, 'meta_key' => self::KEY, 'meta_value' => '{}']];
            $this->db->mode = 'false_early';

            (new NftGroupGateService())->recordOptOut(self::USER, self::NEW);

            self::assertSame($before, $this->storedRaw());
            self::assertSame([], $this->db->writeVerbs());
        }

        public function testAnEvictionDuringAFailedReadIsNotWrittenOverTheMap(): void
        {
            $before = $this->storedMap();
            $this->db->mode = 'error';

            (new NftGroupGateService())->recordPermanentOptOut(self::USER, self::NEW);

            self::assertSame($before, $this->storedRaw());
            self::assertCount(1, $GLOBALS['__bcc_optout_log'], 'and it is logged, because this eviction did not stick');
            self::assertSame('record_permanent_opt_out', $GLOBALS['__bcc_optout_log'][0]['context']['operation'] ?? null);
            self::assertSame(3, $GLOBALS['__bcc_optout_log'][0]['context']['failed_reads'] ?? null, 'each attempt re-read');
        }

        public function testAClearDuringAFailedReadIsANoOp(): void
        {
            $before = $this->storedMap();
            $this->db->mode = 'error';

            (new NftGroupGateService())->clearOptOut(self::USER, self::LEFT);

            self::assertSame($before, $this->storedRaw());
            self::assertSame([], $this->db->writeVerbs());
        }

        /** A failed read is retried: a blip on the first read still records. */
        public function testAReadThatFailsOnceIsRetried(): void
        {
            $this->storedMap();
            $this->db->modes = [0 => 'error'];

            (new NftGroupGateService())->recordOptOut(self::USER, self::NEW);

            $map = \OptOutStore::map(self::USER);
            self::assertSame(0, $map[self::EVICTED] ?? null);
            self::assertArrayHasKey(self::LEFT, $map);
            self::assertGreaterThan(0, $map[self::NEW] ?? 0);
            self::assertSame([], $GLOBALS['__bcc_optout_log']);
        }

        // ── A change made in between is never overwritten ─────────────────

        /**
         * Another request records an eviction between this request's read
         * and its write. A plain update would save the map as THIS request
         * read it, losing that eviction.
         */
        public function testAnEvictionRecordedInBetweenIsKept(): void
        {
            $this->db->put(self::USER, self::KEY, (string) json_encode([self::LEFT => time() - 3600]));
            $this->db->beforeWrite = function (int $attempt): void {
                if ($attempt === 1) {
                    $rows = \OptOutStore::rowsFor(self::USER, self::KEY);
                    $this->db->rows[$rows[0]]['meta_value'] = (string) json_encode([self::LEFT => time() - 3600, self::EVICTED => 0]);
                }
            };

            (new NftGroupGateService())->recordOptOut(self::USER, self::NEW);

            $map = \OptOutStore::map(self::USER);
            self::assertSame(0, $map[self::EVICTED] ?? null, 'the other request\'s eviction survives');
            self::assertArrayHasKey(self::LEFT, $map);
            self::assertGreaterThan(0, $map[self::NEW] ?? 0, 'and this request\'s leave is recorded');
        }

        /** The first-ever opt-out is a unique add: a row added in between is merged, not duplicated. */
        public function testAFirstOptOutRacingAnotherFirstOptOutKeepsBoth(): void
        {
            $this->db->beforeWrite = function (int $attempt): void {
                if ($attempt === 1) {
                    $this->db->put(self::USER, self::KEY, (string) json_encode([self::EVICTED => 0]));
                }
            };

            (new NftGroupGateService())->recordOptOut(self::USER, self::NEW);

            self::assertCount(1, \OptOutStore::rowsFor(self::USER, self::KEY), 'one row, never a duplicate');
            $map = \OptOutStore::map(self::USER);
            self::assertSame(0, $map[self::EVICTED] ?? null);
            self::assertGreaterThan(0, $map[self::NEW] ?? 0);
        }

        /** Clearing the last opt-out deletes the row only if it is unchanged. */
        public function testClearingTheLastOptOutKeepsAnOptOutAddedInBetween(): void
        {
            $this->db->put(self::USER, self::KEY, (string) json_encode([self::LEFT => time() - 3600]));
            $this->db->beforeWrite = function (int $attempt): void {
                if ($attempt === 1) {
                    $rows = \OptOutStore::rowsFor(self::USER, self::KEY);
                    $this->db->rows[$rows[0]]['meta_value'] = (string) json_encode([self::LEFT => time() - 3600, self::EVICTED => 0]);
                }
            };

            (new NftGroupGateService())->clearOptOut(self::USER, self::LEFT);

            self::assertSame([self::EVICTED => 0], \OptOutStore::map(self::USER));
        }

        public function testClearingTheLastOptOutDeletesTheRow(): void
        {
            $this->db->put(self::USER, self::KEY, (string) json_encode([self::LEFT => time() - 3600]));

            (new NftGroupGateService())->clearOptOut(self::USER, self::LEFT);

            self::assertNull(\OptOutStore::map(self::USER));
        }

        /** A map that keeps changing underneath is given up on, and logged. */
        public function testAMapThatKeepsChangingIsGivenUpOnAfterThreeTries(): void
        {
            $this->db->put(self::USER, self::KEY, (string) json_encode([self::LEFT => 1]));
            $this->db->beforeWrite = function (int $attempt): void {
                $rows = \OptOutStore::rowsFor(self::USER, self::KEY);
                $this->db->rows[$rows[0]]['meta_value'] = (string) json_encode([self::LEFT => 1 + $attempt]);
            };

            (new NftGroupGateService())->recordOptOut(self::USER, self::NEW);

            self::assertSame(['update', 'update', 'update'], $this->db->writeVerbs());
            self::assertArrayNotHasKey(self::NEW, (array) \OptOutStore::map(self::USER));
            self::assertCount(1, $GLOBALS['__bcc_optout_log']);
            self::assertSame(0, $GLOBALS['__bcc_optout_log'][0]['context']['failed_reads'] ?? null);
            self::assertSame([], $GLOBALS['__bcc_optout_cache_deletes'], 'no swap landed, so no cache was dropped');
        }

        // ── RA2's compare-and-swap rules ───────────────────────────────────

        /**
         * WordPress can store SQL NULL; the read answers ''. `= ''` never
         * matches NULL, so the swap guards '' as "empty or NULL".
         */
        public function testANullStoredMapIsSwappedThroughTheMaybeNullGuard(): void
        {
            $this->db->putNull(self::USER, self::KEY);

            (new NftGroupGateService())->recordOptOut(self::USER, self::NEW);

            self::assertCount(1, \OptOutStore::rowsFor(self::USER, self::KEY), 'the NULL row was updated, not joined by a second');
            self::assertGreaterThan(0, \OptOutStore::map(self::USER)[self::NEW] ?? 0);
            self::assertStringContainsString('meta_value IS NULL', (string) end($this->db->queries));
        }

        /**
         * An empty stored value is still a GUARDED swap: WordPress treats an
         * empty previous value as "write unconditionally", this must not.
         */
        public function testAnEmptyStoredValueIsAGuardedSwapNotAnUnconditionalWrite(): void
        {
            $this->db->put(self::USER, self::KEY, '');
            $this->db->beforeWrite = function (int $attempt): void {
                if ($attempt === 1) {
                    \OptOutStore::overwrite(self::USER, (string) json_encode([self::EVICTED => 0]));
                }
            };

            (new NftGroupGateService())->recordOptOut(self::USER, self::NEW);

            $map = \OptOutStore::map(self::USER);
            self::assertSame(0, $map[self::EVICTED] ?? null, 'the change made in between survives');
            self::assertGreaterThan(0, $map[self::NEW] ?? 0);
            self::assertSame(['update', 'update'], $this->db->writeVerbs(), 'the first swap missed, the second landed');
        }

        /**
         * The read before a swap is FRESH. Here the request memo holds an
         * older map, and another request has since recorded an eviction. A
         * memoised read would swap against the old value forever (each
         * swap misses, nothing drops the memo) and record nothing.
         */
        public function testTheReadBeforeASwapIsFreshNotTheMemo(): void
        {
            $this->db->put(self::USER, self::KEY, (string) json_encode([self::LEFT => 1]));
            \BCC\Core\Repositories\UserMetaRepository::firstValuesOrUnknown([self::USER], [self::KEY]);
            \OptOutStore::overwrite(self::USER, (string) json_encode([self::LEFT => 1, self::EVICTED => 0]));

            (new NftGroupGateService())->recordOptOut(self::USER, self::NEW);

            $map = \OptOutStore::map(self::USER);
            self::assertSame(0, $map[self::EVICTED] ?? null, 'the other request\'s eviction survives');
            self::assertGreaterThan(0, $map[self::NEW] ?? 0, 'and this leave is recorded at the first try');
            self::assertSame(['update'], $this->db->writeVerbs());
        }

        /**
         * A raw write skips WordPress's hooks and cache, so a successful
         * swap drops UserMetaRepository's memo and the user_meta cache.
         */
        public function testASuccessfulSwapDropsTheMemoAndTheObjectCache(): void
        {
            $this->db->put(self::USER, self::KEY, (string) json_encode([self::LEFT => 1]));
            $before = \BCC\Core\Repositories\UserMetaRepository::firstValuesOrUnknown([self::USER], [self::KEY]);
            self::assertSame((string) json_encode([self::LEFT => 1]), $before[self::USER][self::KEY] ?? null, 'control: the memo holds the old map');

            (new NftGroupGateService())->recordOptOut(self::USER, self::NEW);

            self::assertContains([self::USER, 'user_meta'], $GLOBALS['__bcc_optout_cache_deletes']);
            $after = \BCC\Core\Repositories\UserMetaRepository::firstValuesOrUnknown([self::USER], [self::KEY]);
            self::assertArrayHasKey(self::NEW, (array) json_decode((string) ($after[self::USER][self::KEY] ?? ''), true), 'the memo was dropped');
        }

        // ── The join gate's read ───────────────────────────────────────────

        public function testThePermanentStateIsUnknownWhenTheMapCannotBeRead(): void
        {
            $this->storedMap();
            $gate = new NftGroupGateService();

            self::assertTrue($gate->permanentOptOutState(self::USER, self::EVICTED));
            self::assertFalse($gate->permanentOptOutState(self::USER, self::LEFT));

            $this->db->mode = 'error';
            self::assertNull($gate->permanentOptOutState(self::USER, self::EVICTED), 'unknown, never "not evicted"');
        }

        public function testThePermanentStateIsUnknownOnStaleRows(): void
        {
            $this->storedMap();
            $this->db->last_result = [(object) ['user_id' => (string) self::USER, 'meta_key' => self::KEY, 'meta_value' => '{}']];
            $this->db->mode = 'false_early';

            self::assertNull((new NftGroupGateService())->permanentOptOutState(self::USER, self::EVICTED));
        }

        public function testEveryJoinCheckReadsTheStore(): void
        {
            $this->storedMap();
            $gate = new NftGroupGateService();

            $gate->permanentOptOutState(self::USER, self::EVICTED);
            $gate->permanentOptOutState(self::USER, self::EVICTED);

            self::assertCount(2, $this->db->queries, 'no memo: an eviction by another instance is seen at once');
        }
    }
}
