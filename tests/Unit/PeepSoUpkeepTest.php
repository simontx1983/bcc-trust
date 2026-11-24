<?php
/**
 * F-581: PeepSoUpkeep runs only the PeepSo upkeep BCC depends on (trending
 * hashtags, expired mayfly rows, registration-avatar temp files), by calling
 * PeepSo's own methods, and nothing else of PeepSo's maintenance bundle.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Integration\PeepSoUpkeep;
use BCC\Trust\Core\Services\CronService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoUpkeepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/peepso-upkeep-stubs.php';
        $GLOBALS['__bcc_up'] = ['calls' => [], 'logs' => [], 'lock' => [], 'throw' => [], 'filters' => [], 'lock_free' => true];
    }

    private static function withPeepSo(): void
    {
        require_once __DIR__ . '/../Stubs/peepso-upkeep-peepso-stubs.php';
    }

    public function testRunsTheThreeStepsWithBoundedBatches(): void
    {
        self::withPeepSo();

        $out = (new PeepSoUpkeep())->run();

        self::assertSame('ok', $out['status']);
        self::assertSame(
            ['hashtag_posts' => 'done', 'hashtag_recounts' => 'done', 'mayfly' => 'done', 'avatar_temp' => 'done'],
            $out['steps']
        );
        self::assertSame(
            [
                ['hashtags_build_posts', [PeepSoUpkeep::HASHTAG_POSTS_PER_RUN]],
                ['hashtags_build_hashtags', [PeepSoUpkeep::HASHTAG_RECOUNTS_PER_RUN]],
                // No arguments: expired entries only, never clr(TRUE) (the whole store).
                ['mayfly_clr', []],
                ['purge_old', [PeepSoUpkeep::AVATAR_TTL_SECONDS]],
            ],
            $GLOBALS['__bcc_up']['calls']
        );
        self::assertSame([50, 20, 7200], [
            PeepSoUpkeep::HASHTAG_POSTS_PER_RUN,
            PeepSoUpkeep::HASHTAG_RECOUNTS_PER_RUN,
            PeepSoUpkeep::AVATAR_TTL_SECONDS,
        ]);
    }

    public function testTheAvatarTtlFollowsPeepSosFilterAndZeroSkips(): void
    {
        self::withPeepSo();

        $GLOBALS['__bcc_up']['filters']['peepso_registration_avatar_ttl'] = 3600;
        (new PeepSoUpkeep())->run();
        self::assertContains(['purge_old', [3600]], $GLOBALS['__bcc_up']['calls']);

        $GLOBALS['__bcc_up']['calls'] = [];
        $GLOBALS['__bcc_up']['filters']['peepso_registration_avatar_ttl'] = 0;
        $out = (new PeepSoUpkeep())->run();
        self::assertSame('skipped', $out['steps']['avatar_temp']);
        self::assertNotContains('purge_old', array_column($GLOBALS['__bcc_up']['calls'], 0));
    }

    public function testAFailingStepIsLoggedAndTheOthersStillRun(): void
    {
        self::withPeepSo();
        $GLOBALS['__bcc_up']['throw'] = ['hashtags_build_posts'];

        $out = (new PeepSoUpkeep())->run();

        self::assertSame(
            ['hashtag_posts' => 'failed', 'hashtag_recounts' => 'done', 'mayfly' => 'done', 'avatar_temp' => 'done'],
            $out['steps']
        );
        self::assertCount(1, $GLOBALS['__bcc_up']['logs']);
        self::assertStringContainsString('hashtag_posts', $GLOBALS['__bcc_up']['logs'][0]);
        self::assertSame(
            ['acquire:bcc_trust_peepso_upkeep', 'release:bcc_trust_peepso_upkeep'],
            $GLOBALS['__bcc_up']['lock'],
            'the lock is released after a failing step'
        );
    }

    public function testAnOverlappingRunSkipsAndTouchesNothing(): void
    {
        self::withPeepSo();
        $GLOBALS['__bcc_up']['lock_free'] = false;

        $out = (new PeepSoUpkeep())->run();

        self::assertSame(['status' => 'locked', 'steps' => []], $out);
        self::assertSame([], $GLOBALS['__bcc_up']['calls']);
        self::assertSame(['acquire:bcc_trust_peepso_upkeep'], $GLOBALS['__bcc_up']['lock'], 'a lock it never held is not released');
    }

    public function testWithoutPeepSoEveryStepIsSkipped(): void
    {
        $out = (new PeepSoUpkeep())->run();

        self::assertSame('ok', $out['status']);
        self::assertSame(
            ['hashtag_posts' => 'skipped', 'hashtag_recounts' => 'skipped', 'mayfly' => 'skipped', 'avatar_temp' => 'skipped'],
            $out['steps']
        );
        self::assertSame([], $GLOBALS['__bcc_up']['logs']);
    }

    /**
     * ⛔ Never anything else of PeepSo's maintenance bundle: not the
     * notification purge (BCC's notification centre is built on that table),
     * not the group/page post deletes, not the bundle's event or its public
     * trigger. Comment-stripped source, so the class's own warnings about
     * them are not counted.
     */
    public function testItNeverReachesTheRestOfTheBundle(): void
    {
        $code = self::code(dirname(__DIR__, 2) . '/app/Domain/Core/Integration/PeepSoUpkeep.php');

        foreach ([
            'deleteOldNotifications', 'peepso_notifications', 'deletePostsFor', 'deleteNotificationsFor',
            'peepso_maintenance_event', 'CRON_MAINTENANCE_EVENT', 'peepso_process_maintenance',
            'PeepSoMaintenance', 'PeepSo3_Maintenance', 'do_action(', 'wp_delete_post', '$wpdb',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $code, $forbidden);
        }

        // The only PeepSo calls are the four entry points.
        preg_match_all('~\\\\?(PeepSo\w*)(?:::get_instance\(\)->|::)(\w+)\(~', $code, $m, PREG_SET_ORDER);
        $calls = array_values(array_unique(array_map(
            static fn (array $hit): string => $hit[1] . '::' . $hit[2],
            $m
        )));
        sort($calls);
        self::assertSame(
            ['PeepSo3_Mayfly::clr', 'PeepSo::hashtags_build_hashtags', 'PeepSo::hashtags_build_posts', 'PeepSoRegisterAvatarAjax::purge_old'],
            $calls
        );
    }

    public function testTheJobIsOwnedEveryFiveMinutesAndWired(): void
    {
        self::assertSame('bcc_five_minutes', CronService::ownedJobs()[PeepSoUpkeep::CRON_HOOK] ?? null);

        $plugin = self::code(dirname(__DIR__, 2) . '/bcc-trust.php');
        self::assertSame(
            1,
            substr_count($plugin, 'add_action(\\BCC\\Trust\\Core\\Integration\\PeepSoUpkeep::CRON_HOOK, [\\BCC\\Trust\\Core\\Integration\\PeepSoUpkeep::class, \'runScheduled\']);')
        );
    }

    /** Source without comments. */
    private static function code(string $path): string
    {
        $src = file_get_contents($path);
        self::assertIsString($src, $path);

        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }
}
