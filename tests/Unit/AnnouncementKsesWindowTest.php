<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\AnnouncementWriteService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * #451 follow-up — AnnouncementWriteService::withPlainTextColumns() lifts
 * core's title/excerpt kses for the announcement's OWN column sanitisation
 * only. Each test's callable plays wp_insert_post(): columns are sanitised
 * (kses must be off), `wp_insert_post_data` fires, then actions run — where
 * a nested publish listener writing another post must see kses back on.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AnnouncementKsesWindowTest extends TestCase
{
    private const HOOKS = ['title_save_pre', 'excerpt_save_pre'];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/kses-window-hook-stubs.php';
        self::assertTrue($GLOBALS['__bcc_kw_stub'] ?? false);
        $GLOBALS['__bcc_kw_filters'] = [];
        foreach (self::HOOKS as $hook) {
            add_filter($hook, 'wp_filter_kses', 10);
        }
    }

    private static function withWindow(callable $write): mixed
    {
        $m = new \ReflectionMethod(AnnouncementWriteService::class, 'withPlainTextColumns');
        return $m->invoke(null, $write);
    }

    /** @return array<string, int|false> */
    private static function ksesState(): array
    {
        $out = [];
        foreach (self::HOOKS as $hook) {
            $out[$hook] = has_filter($hook, 'wp_filter_kses');
        }
        return $out;
    }

    public function testKsesIsOffForTheColumnsAndBackOnBeforeAnyActionFires(): void
    {
        $seen = [];
        $result = self::withWindow(static function () use (&$seen) {
            $seen['sanitize'] = self::ksesState();           // sanitize_post()
            apply_filters('wp_insert_post_data', ['post_title' => 'Q&A'], [], [], false);
            $seen['nested'] = self::ksesState();             // save_post / publish listener
            return 42;
        });

        self::assertSame(42, $result);
        self::assertSame(['title_save_pre' => false, 'excerpt_save_pre' => false], $seen['sanitize']);
        self::assertSame(['title_save_pre' => 10, 'excerpt_save_pre' => 10], $seen['nested']);
    }

    public function testTheDataFilterPassesTheRowThroughUnchanged(): void
    {
        $row = ['post_title' => 'Q&A', 'post_excerpt' => 'a < b'];
        $out = null;
        self::withWindow(static function () use ($row, &$out) {
            $out = apply_filters('wp_insert_post_data', $row, [], [], false);
            return 1;
        });

        self::assertSame($row, $out);
    }

    public function testKsesIsRestoredExactlyOnceAndTheDataFilterIsRemoved(): void
    {
        self::withWindow(static function () {
            apply_filters('wp_insert_post_data', [], [], [], false);
            apply_filters('wp_insert_post_data', [], [], [], false); // a nested insert
            return 1;
        });

        foreach (self::HOOKS as $hook) {
            self::assertCount(1, $GLOBALS['__bcc_kw_filters'][$hook][10] ?? [], $hook . ' re-added more than once');
        }
        self::assertSame([], $GLOBALS['__bcc_kw_filters']['wp_insert_post_data'] ?? []);
    }

    public function testAWriteThatReturnsEarlyStillRestoresKses(): void
    {
        // e.g. wp_insert_post() refusing empty content before wp_insert_post_data.
        self::withWindow(static fn () => 0);

        self::assertSame(['title_save_pre' => 10, 'excerpt_save_pre' => 10], self::ksesState());
        self::assertSame([], $GLOBALS['__bcc_kw_filters']['wp_insert_post_data'] ?? []);
    }

    public function testAThrowingWriteStillRestoresKses(): void
    {
        try {
            self::withWindow(static function () {
                throw new \RuntimeException('boom');
            });
            self::fail('exception swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(['title_save_pre' => 10, 'excerpt_save_pre' => 10], self::ksesState());
    }

    public function testNothingIsAddedWhenKsesWasNotRegistered(): void
    {
        // A site that already removed kses (e.g. unfiltered_html context).
        $GLOBALS['__bcc_kw_filters'] = [];
        self::withWindow(static fn () => 1);

        self::assertSame([], $GLOBALS['__bcc_kw_filters']);
    }
}
