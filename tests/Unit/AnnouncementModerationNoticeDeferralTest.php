<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    $GLOBALS['__f307'] = ['posts' => [], 'meta' => [], 'cron' => [], 'logs' => []];

    if (!class_exists('WP_Post')) {
        final class WP_Post
        {
            public int $ID = 0;
            public string $post_type = '';
            public int $post_author = 0;
        }
    }
    if (!function_exists('get_post')) {
        function get_post($id = null)
        {
            return $GLOBALS['__f307']['posts'][(int) $id] ?? null;
        }
    }
    if (!function_exists('get_post_meta')) {
        function get_post_meta(int $id, string $key = '', bool $single = false)
        {
            return $GLOBALS['__f307']['meta'][$id][$key] ?? '';
        }
    }
    if (!function_exists('update_post_meta')) {
        function update_post_meta(int $id, string $key, $value): bool
        {
            $GLOBALS['__f307']['meta'][$id][$key] = (string) $value;
            return true;
        }
    }
    if (!function_exists('wp_next_scheduled')) {
        /** @param list<mixed> $args */
        function wp_next_scheduled(string $hook, array $args = [])
        {
            foreach ($GLOBALS['__f307']['cron'] as $e) {
                if ($e['hook'] === $hook && $e['args'] === $args) {
                    return $e['at'];
                }
            }
            return false;
        }
    }
    if (!function_exists('wp_schedule_single_event')) {
        /** @param list<mixed> $args */
        function wp_schedule_single_event(int $at, string $hook, array $args = []): bool
        {
            $GLOBALS['__f307']['cron'][] = ['at' => $at, 'hook' => $hook, 'args' => $args];
            return true;
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                $GLOBALS['__f307']['logs'][] = $message;
            }
        }
    }
}

namespace BCC\Trust\Tests\Unit {

    use BCC\Trust\Core\Announcements\AnnouncementPostType;
    use BCC\Trust\Core\Announcements\AnnouncementVisibility as Vis;
    use BCC\Trust\Core\Services\AnnouncementModerationService as Svc;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-307 review: the author's takedown / restore bell is deferred past the
     * 30 s undo window and sent only if the RE-READ state still warrants it.
     * An admin's misclick + undo tells the author nothing; a real decision
     * tells them exactly once; a flip-flop inside the window tells them only
     * the final state.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class AnnouncementModerationNoticeDeferralTest extends TestCase
    {
        private const ID = 4242;

        private Svc $svc;

        /** @var list<array{0: int, 1: bool}> bells actually sent */
        private array $sent = [];

        protected function setUp(): void
        {
            $GLOBALS['__f307'] = ['posts' => [], 'meta' => [], 'cron' => [], 'logs' => []];
            $post = new \WP_Post();
            $post->ID = self::ID;
            $post->post_type = AnnouncementPostType::POST_TYPE;
            $post->post_author = 7;
            $GLOBALS['__f307']['posts'][self::ID] = $post;
            // Only isHidden()/meta are exercised; the repos are not touched.
            $this->svc  = (new \ReflectionClass(Svc::class))->newInstanceWithoutConstructor();
            $this->sent = [];
        }

        private function setHidden(bool $hidden): void
        {
            $GLOBALS['__f307']['meta'][self::ID][Vis::META_MODERATION_HIDDEN_AT] = $hidden ? '2026-11-12 00:00:00' : '';
        }

        /** Fire every queued bell event once, as cron would after the delay. */
        private function runCron(): void
        {
            $events = $GLOBALS['__f307']['cron'];
            $GLOBALS['__f307']['cron'] = [];
            foreach ($events as $e) {
                self::assertSame(Svc::NOTICE_HOOK, $e['hook']);
                $this->svc->deliverOutcomeNotice((int) $e['args'][0], (bool) $e['args'][1], function (int $id, bool $h): void {
                    $this->sent[] = [$id, $h];
                });
            }
        }

        public function testTheBellIsQueuedPastTheUndoWindowNotSentNow(): void
        {
            $before = time();
            Svc::scheduleOutcomeNotice(self::ID, true);

            self::assertCount(1, $GLOBALS['__f307']['cron']);
            self::assertGreaterThan(30, $GLOBALS['__f307']['cron'][0]['at'] - $before, 'after the 30 s undo window');
            self::assertSame([self::ID, 1], $GLOBALS['__f307']['cron'][0]['args']);
            self::assertSame([], $this->sent);
        }

        public function testAnUndoInsideTheWindowSendsNoBellAtAll(): void
        {
            // Misclick Hide, then Undo (restore) inside the window.
            $this->setHidden(true);
            Svc::scheduleOutcomeNotice(self::ID, true);
            $this->setHidden(false);
            Svc::scheduleOutcomeNotice(self::ID, false);

            $this->runCron();

            self::assertSame([], $this->sent, 'the author was never told it went down, so is told nothing');
        }

        public function testANoUndoTakedownSendsExactlyOneBell(): void
        {
            $this->setHidden(true);
            Svc::scheduleOutcomeNotice(self::ID, true);

            $this->runCron();
            self::assertSame([[self::ID, true]], $this->sent);

            // A duplicate delivery (late or repeated cron) sends nothing more.
            Svc::scheduleOutcomeNotice(self::ID, true);
            $this->runCron();
            self::assertSame([[self::ID, true]], $this->sent);
        }

        public function testHideRestoreReHideInsideTheWindowSendsOnlyTheFinalStatesBell(): void
        {
            $this->setHidden(true);
            Svc::scheduleOutcomeNotice(self::ID, true);
            $this->setHidden(false);
            Svc::scheduleOutcomeNotice(self::ID, false);
            $this->setHidden(true);
            Svc::scheduleOutcomeNotice(self::ID, true);

            self::assertCount(2, $GLOBALS['__f307']['cron'], 'one deduped event per (notice, action)');

            $this->runCron();
            self::assertSame([[self::ID, true]], $this->sent);
        }

        public function testALaterRealRestoreIsTold(): void
        {
            $this->setHidden(true);
            Svc::scheduleOutcomeNotice(self::ID, true);
            $this->runCron();

            $this->setHidden(false);
            Svc::scheduleOutcomeNotice(self::ID, false);
            $this->runCron();

            self::assertSame([[self::ID, true], [self::ID, false]], $this->sent);
        }

        public function testAnUnreadableNoticeGetsNoBellAndIsLogged(): void
        {
            unset($GLOBALS['__f307']['posts'][self::ID]);
            Svc::scheduleOutcomeNotice(self::ID, true);

            $this->runCron();

            self::assertSame([], $this->sent);
            self::assertNotEmpty($GLOBALS['__f307']['logs'], 'the skip is logged');
        }
    }
}
