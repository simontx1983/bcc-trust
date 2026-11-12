<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\BlogChainTagRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Support\CacheGeneration;
use BCC\Trust\Disputes\Repositories\DisputeRepositorySupport;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression pins for the 2026-07-21 admin-audit P1: generation-counter
 * reads must tolerate NUMERIC STRINGS from the object cache.
 *
 * Persistent backends (Redis/Predis, memcached) serialize with
 * maybe_serialize(), so an integer generation written by one PHP
 * process reads back as "0"/"1"/... in every other process. The old
 * strict `is_int(wp_cache_get(...))` guard treated that as
 * "uninitialized" and RESET the counter to 0 on every cross-process
 * read — permanently undoing every bustCache() increment. Observed
 * live: an admin Hide left the post publicly visible until a manual
 * `wp cache flush`.
 *
 * tests/Stubs/object-cache-stubs.php simulates the cross-process view
 * (ints stringify on read). Each test runs in its own subprocess so
 * the global wp_cache_* shims never leak into the main process.
 */
#[CoversMethod(HiddenActivityRepository::class, 'getGeneration')]
#[CoversMethod(BlogChainTagRepository::class, 'cacheKey')]
#[CoversMethod(DisputeRepositorySupport::class, 'getGeneration')]
#[CoversMethod(CacheGeneration::class, 'get')]
#[CoversMethod(CacheGeneration::class, 'bump')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GenerationCounterCacheStringTest extends TestCase
{
    private const HIDDEN_GROUP = 'bcc_trust:hidden_activities';
    private const BLOG_GROUP   = 'bcc_trust:blog_chain_tags';

    /** A clock-seeded generation, as CacheGeneration::seed() writes. */
    private const CLOCK_GEN = 1_700_000_000_000_005;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/object-cache-stubs.php';
        \BccTestPersistentCache::reset();
    }

    // ── HiddenActivityRepository ─────────────────────────────────────

    public function testHiddenGenerationSurvivesStringRoundTrip(): void
    {
        wp_cache_set('gen', self::CLOCK_GEN, self::HIDDEN_GROUP);
        // Stub returns a numeric string — the cross-process view.
        self::assertSame((string) self::CLOCK_GEN, wp_cache_get('gen', self::HIDDEN_GROUP));
        self::assertSame(self::CLOCK_GEN, HiddenActivityRepository::getGeneration());
    }

    public function testHiddenGenerationIsNotResetByCrossProcessReads(): void
    {
        wp_cache_set('gen', self::CLOCK_GEN, self::HIDDEN_GROUP);
        // The old is_int guard reset the counter to 0 on the first
        // read; a second read must still see it and the store must
        // still hold it.
        self::assertSame(self::CLOCK_GEN, HiddenActivityRepository::getGeneration());
        self::assertSame(self::CLOCK_GEN, HiddenActivityRepository::getGeneration());
        self::assertSame(self::CLOCK_GEN, (int) \BccTestPersistentCache::raw('gen', self::HIDDEN_GROUP));
    }

    public function testHiddenBustAdvancesGenerationAcrossProcesses(): void
    {
        wp_cache_set('gen', self::CLOCK_GEN, self::HIDDEN_GROUP);

        $bust = new ReflectionMethod(HiddenActivityRepository::class, 'bustCache');
        $bust->setAccessible(true);
        $bust->invoke(null);

        // The bust must survive a subsequent cross-process read — this
        // is the exact sequence that failed live (hide → feed request).
        self::assertSame(self::CLOCK_GEN + 1, HiddenActivityRepository::getGeneration());

        $keyFn = new ReflectionMethod(HiddenActivityRepository::class, 'cacheGenerationKey');
        $keyFn->setAccessible(true);
        self::assertSame('all_hidden_ids:' . (self::CLOCK_GEN + 1), $keyFn->invoke(null));
    }

    /** A cold (or evicted) counter seeds from the clock, never 0. */
    public function testHiddenColdCacheSeedsFromClock(): void
    {
        $before = CacheGeneration::seed();
        $gen    = HiddenActivityRepository::getGeneration();

        self::assertGreaterThanOrEqual($before, $gen);
        self::assertSame($gen, (int) \BccTestPersistentCache::raw('gen', self::HIDDEN_GROUP));
    }

    /**
     * F-87 class: an eviction must not rewind the counter to a value an
     * earlier generation already used. The stub's incr creates a missing
     * key at 0+1, like Redis INCRBY in the production drop-in.
     */
    public function testHiddenBustAfterEvictionDoesNotRewind(): void
    {
        $bust = new ReflectionMethod(HiddenActivityRepository::class, 'bustCache');
        $bust->setAccessible(true);

        $first = HiddenActivityRepository::getGeneration();
        $bust->invoke(null);
        $bumped = HiddenActivityRepository::getGeneration();
        self::assertSame($first + 1, $bumped);

        wp_cache_delete('gen', self::HIDDEN_GROUP); // LRU eviction
        $bust->invoke(null);

        $after = HiddenActivityRepository::getGeneration();
        self::assertGreaterThan($bumped, $after);
        // The bump left a stable key: reads agree, so the cache can hit.
        self::assertSame($after, HiddenActivityRepository::getGeneration());
    }

    /** A pre-clock counter (deployed before this change) moves forward too. */
    public function testHiddenBustOfLegacySmallCounterMovesPastIt(): void
    {
        wp_cache_set('gen', 3, self::HIDDEN_GROUP);

        $bust = new ReflectionMethod(HiddenActivityRepository::class, 'bustCache');
        $bust->setAccessible(true);
        $bust->invoke(null);

        self::assertGreaterThan(1_000_000_000_000_000, HiddenActivityRepository::getGeneration());
    }

    // ── DisputeRepositorySupport (reporter_gen readers) ──────────────

    public function testDisputeGenerationSeedsFromClockAndSurvivesEvictionBump(): void
    {
        $key    = 'reporter_gen:42';
        $before = CacheGeneration::seed();
        $cold   = DisputeRepositorySupport::getGeneration($key);
        self::assertGreaterThanOrEqual($before, $cold);

        DisputeRepositorySupport::bumpGeneration($key);
        self::assertSame($cold + 1, DisputeRepositorySupport::getGeneration($key));

        wp_cache_delete($key, DisputeRepositorySupport::CACHE_GROUP); // eviction
        $reread = DisputeRepositorySupport::getGeneration($key);
        self::assertGreaterThan($cold + 1, $reread);
        self::assertSame($reread, DisputeRepositorySupport::getGeneration($key));
    }

    // ── BlogChainTagRepository ───────────────────────────────────────

    public function testBlogChainTagCacheKeySurvivesStringRoundTrip(): void
    {
        wp_cache_set('gen', self::CLOCK_GEN, self::BLOG_GROUP);

        $keyFn = new ReflectionMethod(BlogChainTagRepository::class, 'cacheKey');
        $keyFn->setAccessible(true);
        self::assertSame('post:9:' . self::CLOCK_GEN, $keyFn->invoke(null, 'post:9'));

        $bust = new ReflectionMethod(BlogChainTagRepository::class, 'bustCache');
        $bust->setAccessible(true);
        $bust->invoke(null);

        self::assertSame('post:9:' . (self::CLOCK_GEN + 1), $keyFn->invoke(null, 'post:9'));
    }

    public function testBlogChainTagColdCacheKeySeedsFromClock(): void
    {
        $keyFn = new ReflectionMethod(BlogChainTagRepository::class, 'cacheKey');
        $keyFn->setAccessible(true);

        $before = CacheGeneration::seed();
        $key    = (string) $keyFn->invoke(null, 'post:1');
        self::assertMatchesRegularExpression('/^post:1:\d{16,}$/', $key);
        self::assertGreaterThanOrEqual($before, (int) substr($key, strlen('post:1:')));
    }

    public function testBlogChainTagBustAfterEvictionDoesNotRewind(): void
    {
        $keyFn = new ReflectionMethod(BlogChainTagRepository::class, 'cacheKey');
        $keyFn->setAccessible(true);
        $bust = new ReflectionMethod(BlogChainTagRepository::class, 'bustCache');
        $bust->setAccessible(true);

        $bust->invoke(null);
        $before = (int) substr((string) $keyFn->invoke(null, 'p'), 2);

        wp_cache_delete('gen', self::BLOG_GROUP);
        $bust->invoke(null);

        $after = (string) $keyFn->invoke(null, 'p');
        self::assertGreaterThan($before, (int) substr($after, 2));
        self::assertSame($after, $keyFn->invoke(null, 'p'));
    }
}
