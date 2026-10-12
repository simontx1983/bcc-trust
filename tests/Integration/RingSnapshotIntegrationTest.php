<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Support\RingSnapshot;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The ring snapshot has to survive the request that wrote it.
 *
 * ── WHAT WAS WRONG ─────────────────────────────────────────────────────
 * One writer, `wp_cache_set('ring_results', ...)` and nothing else. wp_cache_*
 * is per-request unless an object-cache.php drop-in is installed, and staging
 * has none — so the daily job computed a graph pass measured at up to 91.9 s
 * and then threw it away. The admin tab could never show a ring, and
 * FraudDetector recomputed the graph on the request path instead.
 *
 * ── WHY A TRANSIENT IS ENOUGH, AND WHY THAT IS A MEASUREMENT ───────────
 * The open question was payload size. Measured in an isolated MariaDB
 * 11.8.9 container at the worst case inside the detector's own 10,000-edge
 * limit (a 100-node vouch clique): 4,851 clusters, 2.51 MiB serialized,
 * write 81.8 ms, read 12.6 ms, and — the decisive fact — the wp_options row
 * is NOT autoloaded. An autoloaded row that size would be read on every
 * request on the site; a non-autoloaded one is read when someone asks.
 *
 * ── THE SHAPE HALF IS NOT SEPARABLE ────────────────────────────────────
 * getSuspiciousClusters() merges two detectors that emit different keys, and
 * both consumers were written against only one of them. Persisting the
 * snapshot ACTIVATES that. Section 3 pins it.
 */
#[Group('integration')]
#[Group('mariadb')]
final class RingSnapshotIntegrationTest extends TestCase
{
    private const TRANSIENT = 'bcc_trust_ring_snapshot';

    protected function setUp(): void
    {
        $this->clearStores();
    }

    protected function tearDown(): void
    {
        $this->clearStores();
        unset(
            $GLOBALS['__bcc_test_ext_object_cache'],
            $GLOBALS['__bcc_test_object_cache_write_fails']
        );
    }

    private function clearStores(): void
    {
        delete_transient(self::TRANSIENT);
        wp_cache_delete(RingSnapshot::CACHE_KEY, RingSnapshot::CACHE_GROUP);
    }

    /** Pretend the host has a persistent object cache (see the bootstrap). */
    private function withExternalObjectCache(): void
    {
        $GLOBALS['__bcc_test_ext_object_cache'] = true;
        self::assertTrue(wp_using_ext_object_cache(), 'the hook must actually flip the branch');
    }

    /** @return list<array<string, mixed>> one ring of each producer shape */
    private function mixedProducerOutput(): array
    {
        return [
            // detectVoteRings(): users, size, strength, detected_at — no 'type'
            ['users' => [1, 2, 3], 'size' => 3, 'strength' => 0.91, 'detected_at' => '2026-10-11 00:00:00'],
            // detectEndorsementRings(): users, size, type, detected_at — no 'strength'
            ['users' => [7, 8, 9], 'size' => 3, 'type' => 'endorsement_ring', 'detected_at' => '2026-10-11 00:00:00'],
        ];
    }

    // ── 0. ⚠⚠ ABSENT IS A FACT, NOT AN EMPTY LIST
    // ═══════════════════════════════════════════════════════════════════

    public function testNoSnapshotIsReportedAsUnavailable(): void
    {
        $read = RingSnapshot::read();

        self::assertFalse($read['available'], '"no vote rings detected" must not be printable from this');
        self::assertSame([], $read['rings']);
        self::assertNull($read['computed_at']);
        self::assertNull($read['age_seconds']);
    }

    public function testAStoredEmptySnapshotIsAVAILABLEAndEmpty(): void
    {
        // ⚠ THE CASE THE WHOLE DISTINCTION EXISTS FOR. A run that found no
        // rings is a MEASUREMENT, and the tab is entitled to say "no rings
        // detected" about it. An absent snapshot is not.
        RingSnapshot::store([]);

        $read = RingSnapshot::read();

        self::assertTrue($read['available'], 'a run that found nothing still ran');
        self::assertSame([], $read['rings']);
        self::assertIsInt($read['computed_at']);
    }

    public function testAStoredSnapshotIsReadBackAfterTheWritingRequest(): void
    {
        // ⚠ The point of the change, stated once. The object cache is NOT
        // used here (no external cache), so only a durable store can make
        // this pass — which is precisely what the old code lacked.
        self::assertFalse(wp_using_ext_object_cache(), 'precondition: no external object cache');

        RingSnapshot::store($this->mixedProducerOutput());

        // Drop the per-request cache so nothing can answer from memory.
        wp_cache_delete(RingSnapshot::CACHE_KEY, RingSnapshot::CACHE_GROUP);

        $read = RingSnapshot::read();

        self::assertTrue($read['available'], 'the writer and the reader must meet across requests');
        self::assertCount(2, $read['rings']);
    }

    // ── 1. computed_at AND AGE
    // ═══════════════════════════════════════════════════════════════════

    public function testComputedAtIsStoredWithThePayload(): void
    {
        $before = time();
        RingSnapshot::store([]);
        $after = time();

        $read = RingSnapshot::read();

        self::assertGreaterThanOrEqual($before, $read['computed_at']);
        self::assertLessThanOrEqual($after, $read['computed_at']);
    }

    public function testAgeIsDerivedFromTheStoredTimestampNotFromTheStore(): void
    {
        // ⚠ Freshness is a property of the PAYLOAD, not of the store: the
        // object cache and the transient can expire at different moments, and
        // a reader that inferred age from which store answered would report
        // two different ages for the same snapshot.
        $payload = ['rings' => [], 'computed_at' => time() - 7200];
        set_transient(self::TRANSIENT, $payload, DAY_IN_SECONDS);

        $read = RingSnapshot::read();

        self::assertTrue($read['available']);
        self::assertGreaterThanOrEqual(7200, $read['age_seconds']);
        self::assertLessThan(7260, $read['age_seconds'], 'and not wildly more');
    }

    public function testTheTtlIsUnchangedAtOneDay(): void
    {
        // ⚠ DELIBERATE, and worth a test because the proposal originally
        // argued for THREE HOURS — on the belief that the producer ran
        // hourly. It runs DAILY (`bcc_trust_daily_graph_update`), so a
        // 3-hour TTL would have left the tab saying "no snapshot" for 21 of
        // every 24 hours. Shortening the TTL needs its own decision, and
        // this pins what it currently is.
        //
        // ⚠ Read from the HARNESS's transient store, not from
        // `_transient_timeout_*` in wp_options: the integration bootstrap
        // implements set_transient() as an in-memory array and writes no
        // option row at all. A first version of this case asserted the
        // option and failed — not because the TTL was wrong, but because the
        // harness cannot express that fact. Asserting what the harness
        // actually knows is the difference between a real check and a check
        // that happens to be red.
        $before = time();
        RingSnapshot::store([]);

        $entry = $GLOBALS['__bcc_test_transients'][self::TRANSIENT] ?? null;
        self::assertIsArray($entry, 'precondition: the harness must have recorded the write');

        $expiry = (int) $entry[1];
        self::assertGreaterThanOrEqual($before + DAY_IN_SECONDS, $expiry);
        self::assertLessThanOrEqual(time() + DAY_IN_SECONDS, $expiry);
    }

    // ⚠⚠ WHAT THIS SUITE CANNOT ASSERT, AND WHERE THE EVIDENCE IS INSTEAD.
    //
    // Whether the stored row is AUTOLOADED is the single fact that decided a
    // transient was an acceptable store, because the measured worst-case
    // payload is 2.51 MiB and an autoloaded option that size would be read on
    // every request on the site. It is a property of WordPress's own
    // transient implementation, and this harness replaces that implementation
    // with an in-memory array — so there is no row here to inspect and no
    // honest way to assert it.
    //
    // Measured instead against a REAL WordPress + MariaDB 11.8.9 install in
    // an isolated container, at five payload sizes from 6 B to 2.89 MB:
    // `autoload = off` in every case, round-tripping byte-identically, write
    // 81.8 ms / read 12.6 ms at the largest. option_value is LONGTEXT and
    // max_allowed_packet is 16 MiB, so 2.89 MB fits with 5.7x headroom; the
    // site's entire autoloaded set is 44.8 KiB across 139 rows and this does
    // not join it.
    //
    // A skipped test would have recorded the same thing while pretending to
    // be a check, so this is a comment rather than a case.

    // ── 2. ⚠⚠ THE SAME BEHAVIOUR WITH AND WITHOUT AN OBJECT CACHE
    // ═══════════════════════════════════════════════════════════════════

    public function testWithoutAnObjectCacheTheTransientIsTheStore(): void
    {
        self::assertFalse(wp_using_ext_object_cache());
        RingSnapshot::store($this->mixedProducerOutput());

        self::assertNotFalse(get_transient(self::TRANSIENT), 'the durable copy must exist');
        self::assertTrue(RingSnapshot::read()['available']);
    }

    public function testWithAnObjectCacheBOTHStoresAreWritten(): void
    {
        // ⚠ The transient is written ALWAYS, not only as a failure fallback.
        // A snapshot that survives only while the backend is healthy is the
        // bug this class removes, and the write happens once a day.
        $this->withExternalObjectCache();

        RingSnapshot::store($this->mixedProducerOutput());

        self::assertNotFalse(
            wp_cache_get(RingSnapshot::CACHE_KEY, RingSnapshot::CACHE_GROUP),
            'the object cache should hold it'
        );
        self::assertNotFalse(
            get_transient(self::TRANSIENT),
            'and so should the durable copy'
        );
    }

    public function testWithAnObjectCacheAStaleTransientDoesNotWinOverALiveSnapshot(): void
    {
        // ⚠ Read order is load-bearing. Seeded with DISAGREEING values,
        // because two equal payloads cannot tell the two orders apart.
        $this->withExternalObjectCache();
        set_transient(self::TRANSIENT, ['rings' => [], 'computed_at' => time() - 99999], DAY_IN_SECONDS);
        wp_cache_set(
            RingSnapshot::CACHE_KEY,
            ['rings' => $this->mixedProducerOutput(), 'computed_at' => time()],
            RingSnapshot::CACHE_GROUP,
            DAY_IN_SECONDS
        );

        $read = RingSnapshot::read();

        self::assertCount(2, $read['rings'], 'the object cache wins');
        self::assertLessThan(60, $read['age_seconds']);
    }

    public function testWithAnObjectCacheAMissFallsThroughToTheTransient(): void
    {
        $this->withExternalObjectCache();
        RingSnapshot::store($this->mixedProducerOutput());
        wp_cache_delete(RingSnapshot::CACHE_KEY, RingSnapshot::CACHE_GROUP);

        $read = RingSnapshot::read();

        self::assertTrue($read['available'], 'an eviction must not read as "no snapshot"');
        self::assertCount(2, $read['rings']);
    }

    public function testACacheWriteFailureStillLeavesAReadableSnapshot(): void
    {
        // ⚠ THE CACHE-WRITE FAILURE CASE. With the backend refusing writes,
        // the durable copy is the only thing that can carry the snapshot —
        // and because the transient is written unconditionally rather than
        // as a fallback, there is nothing to get wrong here.
        $this->withExternalObjectCache();
        $GLOBALS['__bcc_test_object_cache_write_fails'] = true;

        RingSnapshot::store($this->mixedProducerOutput());

        $read = RingSnapshot::read();

        self::assertTrue($read['available'], 'a refused cache write must not lose the snapshot');
        self::assertCount(2, $read['rings']);
    }

    public function testALegacyBareListIsNotMistakenForAPayload(): void
    {
        // ⚠ The old key held a BARE LIST of rings. A cache left warm across
        // a deploy, or a reverted build, would otherwise be read as a payload
        // and dereferenced for a computed_at that is not there. "Not
        // available" is both the honest answer and the safe one.
        $this->withExternalObjectCache();
        wp_cache_set(
            RingSnapshot::CACHE_KEY,
            $this->mixedProducerOutput(),     // no wrapper, no computed_at
            RingSnapshot::CACHE_GROUP,
            DAY_IN_SECONDS
        );

        $read = RingSnapshot::read();

        self::assertFalse($read['available'], 'a pre-wrapper value is not a payload');
        self::assertSame([], $read['rings']);
    }

    // ── 3. ⚠⚠ ONE SHAPE, WHICHEVER DETECTOR PRODUCED THE RING
    // ═══════════════════════════════════════════════════════════════════
    //
    // These are not cosmetic. FraudDetector read $ring['strength'] unguarded
    // and the admin tab read $ring['members']; the producers emit 'strength'
    // only for vote rings and 'users' for both. Persisting the snapshot
    // activates both, so the normalisation ships with the storage change.

    public function testEveryRingCarriesTheSameKeys(): void
    {
        RingSnapshot::store($this->mixedProducerOutput());

        foreach (RingSnapshot::read()['rings'] as $ring) {
            foreach (['users', 'size', 'kind', 'strength', 'detected_at'] as $key) {
                self::assertArrayHasKey($key, $ring, "every ring must carry {$key}");
            }
        }
    }

    public function testAnEndorsementRingCarriesANullStrengthRatherThanNoKey(): void
    {
        // ⚠ THE WARNING THIS REMOVES: "Undefined array key \"strength\"",
        // measured in a container by caching both shapes and running the
        // detector's loop against a member of the endorsement ring. It also
        // printed "(strength: 0.00)" — a fabricated measurement from a
        // detector that computes none. null says "not computed"; 0.0 would
        // claim a measured weakness.
        RingSnapshot::store($this->mixedProducerOutput());
        $rings = RingSnapshot::read()['rings'];

        $endorsement = array_values(array_filter(
            $rings,
            fn(array $r): bool => $r['kind'] === RingSnapshot::KIND_ENDORSEMENT
        ));

        self::assertCount(1, $endorsement, 'precondition: the fixture has one endorsement ring');
        self::assertArrayHasKey('strength', $endorsement[0]);
        self::assertNull($endorsement[0]['strength']);
    }

    public function testAVoteRingKeepsItsStrength(): void
    {
        // ⚠ Anti-over-reach: normalising must not flatten a real measurement
        // to null along with the absent one.
        RingSnapshot::store($this->mixedProducerOutput());

        $vote = array_values(array_filter(
            RingSnapshot::read()['rings'],
            fn(array $r): bool => $r['kind'] === RingSnapshot::KIND_VOTE
        ));

        self::assertCount(1, $vote);
        self::assertSame(0.91, $vote[0]['strength']);
    }

    public function testTheKindDistinguishesTheTwoDetectors(): void
    {
        RingSnapshot::store($this->mixedProducerOutput());

        $kinds = array_column(RingSnapshot::read()['rings'], 'kind');

        self::assertContains(RingSnapshot::KIND_VOTE, $kinds);
        self::assertContains(RingSnapshot::KIND_ENDORSEMENT, $kinds);
    }

    public function testUsersSurviveAsAFlatListOfIntegers(): void
    {
        // ⚠ The admin tab builds the hidden `user_ids` field of two
        // DESTRUCTIVE forms from this list. A shape change here is a safety
        // change, not a display change.
        RingSnapshot::store([
            ['users' => ['5', 6, '7'], 'size' => 3, 'strength' => 0.5, 'detected_at' => null],
        ]);

        $ring = RingSnapshot::read()['rings'][0];

        self::assertSame([5, 6, 7], $ring['users'], 'ints, in order, no gaps');
        self::assertSame(3, $ring['size']);
    }

    public function testARingWithoutUsersIsDroppedRatherThanStoredMalformed(): void
    {
        // Nothing in production emits this, which is the reason to pin it:
        // a malformed ring reaching the tab would reach two destructive
        // forms with it.
        RingSnapshot::store([
            ['size' => 3, 'strength' => 0.5],
            ['users' => [1, 2, 3], 'size' => 3, 'strength' => 0.7, 'detected_at' => null],
        ]);

        $rings = RingSnapshot::read()['rings'];

        self::assertCount(1, $rings, 'the ring with no members is not storable');
        self::assertSame([1, 2, 3], $rings[0]['users']);
    }

    public function testSizeFallsBackToTheMemberCount(): void
    {
        RingSnapshot::store([
            ['users' => [1, 2, 3, 4], 'strength' => 0.5],
        ]);

        self::assertSame(4, RingSnapshot::read()['rings'][0]['size']);
    }
}
