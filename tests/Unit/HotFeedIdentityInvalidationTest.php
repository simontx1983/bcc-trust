<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\Feed\FeedRankingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-294 (item 13): an account deletion, a handle change, or "Hide my real
 * name" switched on/off dropped the cached anon hot-feed first page at once,
 * instead of leaving the old identity on a shared, publicly cacheable page
 * for up to HOT_CACHE_TTL.
 *
 * Scores and votes still never invalidate this cache (the audit-confirmed
 * F-277 decision); only these three identity/privacy events do.
 *
 * The events are wired through FeedRankingService::registerIdentityHooks(),
 * run here against a recording add_action(), and fired.
 */
#[CoversClass(FeedRankingService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HotFeedIdentityInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/blocked-sender-stubs.php';
        require_once __DIR__ . '/../Stubs/hot-feed-cache-stubs.php';
        if (!\function_exists('add_action')) {
            eval('function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool { $GLOBALS["__bcc_hooks"][$hook][] = $cb; return true; }');
        }
        $GLOBALS['__bcc_hooks'] = [];
        \BccHotFeedCacheStore::reset();
    }

    /** @return list<string> store ids of the seeded pages */
    private static function seedPages(): array
    {
        $r     = new ReflectionClass(FeedRankingService::class);
        $keyFn = $r->getMethod('hotFeedCacheKey');
        /** @var string $group */
        $group = $r->getConstant('HOT_CACHE_GROUP');
        $ids   = [];
        foreach ([1, 7, FeedRankingService::HOT_WARM_LIMIT, FeedRankingService::HOT_CACHE_MAX_LIMIT] as $limit) {
            /** @var string $key */
            $key = $keyFn->invoke(null, $limit);
            wp_cache_set($key, [
                'items'      => [['id' => 'feed_1', 'author' => ['kind' => 'user', 'id' => 70, 'handle' => 'old-handle', 'display_name' => 'Real Name']]],
                'pagination' => ['next_cursor' => null, 'has_more' => false],
            ], $group);
            $ids[] = \BccHotFeedCacheStore::id($key, $group);
        }
        return $ids;
    }

    private static function service(): FeedRankingService
    {
        return (new ReflectionClass(FeedRankingService::class))->newInstanceWithoutConstructor();
    }

    /** Register the hooks with a forget() that really drops the pages, and record why. */
    private static function wire(): void
    {
        $GLOBALS['__bcc_forgot'] = [];
        FeedRankingService::registerIdentityHooks(static function (string $why, int $userId): void {
            $GLOBALS['__bcc_forgot'][] = [$why, $userId];
            self::service()->forgetHotFeed();
        });
    }

    /** @param mixed ...$args */
    private static function fire(string $hook, ...$args): void
    {
        foreach ($GLOBALS['__bcc_hooks'][$hook] ?? [] as $cb) {
            $cb(...$args);
        }
    }

    public function testForgetHotFeedDropsEveryCachedPageSize(): void
    {
        $ids = self::seedPages();

        self::service()->forgetHotFeed();

        foreach ($ids as $id) {
            self::assertArrayNotHasKey($id, \BccHotFeedCacheStore::$store);
        }
    }

    /** @return iterable<string, array{string, list<mixed>, string}> */
    public static function identityEvents(): iterable
    {
        yield 'account deleted'   => ['deleted_user', [70], 'account_deleted'];
        yield 'handle changed'    => ['bcc_handle_changed', [70, 'old-handle', 'new-handle'], 'handle_changed'];
        yield 'real name hidden'  => ['bcc_privacy_settings_changed', [70, ['real_name_hidden']], 'real_name_hidden'];
        yield 'profile made private' => ['bcc_profile_visibility_changed', [70, 40], 'profile_visibility_changed'];
        yield 'suspended'            => ['bcc_user_suspension_changed', [70], 'suspension_changed'];
    }

    /** @param list<mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider('identityEvents')]
    public function testEachIdentityEventDropsThePages(string $hook, array $args, string $why): void
    {
        $ids = self::seedPages();
        self::wire();

        self::fire($hook, ...$args);

        self::assertSame([[$why, 70]], $GLOBALS['__bcc_forgot']);
        foreach ($ids as $id) {
            self::assertArrayNotHasKey($id, \BccHotFeedCacheStore::$store);
        }
    }

    public function testAnUnrelatedPrivacyWriteKeepsThePages(): void
    {
        $ids = self::seedPages();
        self::wire();

        self::fire('bcc_privacy_settings_changed', 70, ['reviews_hidden', 'email_hidden']);

        self::assertSame([], $GLOBALS['__bcc_forgot']);
        foreach ($ids as $id) {
            self::assertArrayHasKey($id, \BccHotFeedCacheStore::$store);
        }
    }
}
