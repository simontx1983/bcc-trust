<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }
    if (!defined('BCC_FRONTEND_ORIGIN')) {
        define('BCC_FRONTEND_ORIGIN', 'https://front.example, regex:^https://preview-.*$');
    }
    $GLOBALS['__f315_transients'] = [];
    if (!function_exists('get_transient')) {
        function get_transient(string $key)
        {
            return $GLOBALS['__f315_transients'][$key] ?? false;
        }
    }
    if (!function_exists('set_transient')) {
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            $GLOBALS['__f315_transients'][$key] = $value;
            return true;
        }
    }
}

namespace BCC\Trust\Tests\Unit {

    use BCC\Trust\Core\Admin\NotificationCenter;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-315 (L6): nothing in wp-admin said content reports were waiting — the
     * queue lives on the frontend and was reachable only from the avatar
     * menu. The Notification Center now carries a count with a link to it.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class NotificationCenterPendingReportsTest extends TestCase
    {
        protected function setUp(): void
        {
            if (!class_exists(\BCC\Core\Support\FrontendOriginConfig::class)) {
                require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/Support/FrontendOriginConfig.php');
            }
            $GLOBALS['__f315_transients'] = [];
        }

        public function testNoPendingReportsAddsNothing(): void
        {
            self::assertNull(NotificationCenter::checkPendingContentReports(static fn (): int => 0));
        }

        public function testPendingReportsAreCountedAndLinkedToTheQueue(): void
        {
            $item = NotificationCenter::checkPendingContentReports(static fn (): int => 3);

            self::assertNotNull($item);
            self::assertSame('moderation.pending_content_reports', $item['id']);
            self::assertSame('3 content reports are waiting in the moderation queue.', $item['message']);
            self::assertSame('https://front.example/admin/moderation', $item['link'] ?? null, 'the canonical frontend origin, not a regex entry');
        }

        public function testOneReportIsSingular(): void
        {
            $item = NotificationCenter::checkPendingContentReports(static fn (): int => 1);

            self::assertSame('1 content report is waiting in the moderation queue.', $item['message'] ?? null);
        }

        public function testTheCountIsCachedBecauseItRunsOnEveryAdminPage(): void
        {
            $calls = 0;
            $count = static function () use (&$calls): int {
                $calls++;
                return 2;
            };

            NotificationCenter::checkPendingContentReports($count);
            NotificationCenter::checkPendingContentReports($count);

            self::assertSame(1, $calls);
        }

        public function testAFailedCountShowsNoBannerRatherThanBreakingThePage(): void
        {
            self::assertNull(NotificationCenter::checkPendingContentReports(static function (): int {
                throw new \RuntimeException('db down');
            }));
        }
    }
}
