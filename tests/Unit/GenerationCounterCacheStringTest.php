<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\BlogChainTagRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
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
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GenerationCounterCacheStringTest extends TestCase
{
    private const HIDDEN_GROUP = 'bcc_trust:hidden_activities';
    private const BLOG_GROUP   = 'bcc_trust:blog_chain_tags';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/object-cache-stubs.php';
        \BccTestPersistentCache::reset();
    }

    // ── HiddenActivityRepository ─────────────────────────────────────

    public function testHiddenGenerationSurvivesStringRoundTrip(): void
    {
        wp_cache_set('gen', 5, self::HIDDEN_GROUP);
        // Stub returns '5' (string) — the cross-process view.
        self::assertSame('5', wp_cache_get('gen', self::HIDDEN_GROUP));
        self::assertSame(5, HiddenActivityRepository::getGeneration());
    }

    public function testHiddenGenerationIsNotResetByCrossProcessReads(): void
    {
        wp_cache_set('gen', 7, self::HIDDEN_GROUP);
        // The old is_int guard reset the counter to 0 on the first
        // read; a second read must still see 7 and the store must
        // still hold 7.
        self::assertSame(7, HiddenActivityRepository::getGeneration());
        self::assertSame(7, HiddenActivityRepository::getGeneration());
        self::assertSame(7, (int) \BccTestPersistentCache::raw('gen', self::HIDDEN_GROUP));
    }

    public function testHiddenBustAdvancesGenerationAcrossProcesses(): void
    {
        wp_cache_set('gen', 3, self::HIDDEN_GROUP);

        $bust = new ReflectionMethod(HiddenActivityRepository::class, 'bustCache');
        $bust->setAccessible(true);
        $bust->invoke(null);

        // The bust must survive a subsequent cross-process read — this
        // is the exact sequence that failed live (hide → feed request).
        self::assertSame(4, HiddenActivityRepository::getGeneration());

        $keyFn = new ReflectionMethod(HiddenActivityRepository::class, 'cacheGenerationKey');
        $keyFn->setAccessible(true);
        self::assertSame('all_hidden_ids:4', $keyFn->invoke(null));
    }

    public function testHiddenColdCacheInitializesToZero(): void
    {
        self::assertSame(0, HiddenActivityRepository::getGeneration());
        self::assertSame(0, (int) \BccTestPersistentCache::raw('gen', self::HIDDEN_GROUP));
    }

    // ── BlogChainTagRepository ───────────────────────────────────────

    public function testBlogChainTagCacheKeySurvivesStringRoundTrip(): void
    {
        wp_cache_set('gen', 2, self::BLOG_GROUP);

        $keyFn = new ReflectionMethod(BlogChainTagRepository::class, 'cacheKey');
        $keyFn->setAccessible(true);
        self::assertSame('post:9:2', $keyFn->invoke(null, 'post:9'));

        $bust = new ReflectionMethod(BlogChainTagRepository::class, 'bustCache');
        $bust->setAccessible(true);
        $bust->invoke(null);

        self::assertSame('post:9:3', $keyFn->invoke(null, 'post:9'));
    }

    public function testBlogChainTagColdCacheKeyIsGenerationZero(): void
    {
        $keyFn = new ReflectionMethod(BlogChainTagRepository::class, 'cacheKey');
        $keyFn->setAccessible(true);
        self::assertSame('post:1:0', $keyFn->invoke(null, 'post:1'));
    }
}
