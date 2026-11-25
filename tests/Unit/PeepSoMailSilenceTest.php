<?php
/**
 * PeepSoMailSilence keeps PeepSo's email pipeline off, and touches nothing
 * else.
 *
 *  - The send loop: PeepSo's mail-queue cron is unscheduled on `init`.
 *  - The queue: `peepso_mailqueue_allowed`, which PeepSoMailQueue::add_message()
 *    asks before it queues anything (including its send-immediately path),
 *    answers false for every recipient, late enough that no earlier filter
 *    can turn it back into an allow.
 *  - The scope: those two hooks and nothing on wp_mail(), so BCC's own mail,
 *    which goes straight through wp_mail(), is unaffected.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Integration\PeepSoMailSilence;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoMailSilenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/peepso-mail-silence-stubs.php';
        $GLOBALS['__bcc_ms_hooks']   = [];
        $GLOBALS['__bcc_ms_cleared'] = [];
    }

    public function testRegisterAddsOnlyTheSendLoopClearAndTheQueueVeto(): void
    {
        PeepSoMailSilence::register();

        $hooks = array_map(
            static fn (array $h): array => [$h[0], $h[1], $h[3]],
            $GLOBALS['__bcc_ms_hooks']
        );

        self::assertSame(
            [
                ['action', 'init', 20],
                ['filter', 'peepso_mailqueue_allowed', 999],
            ],
            $hooks,
            'exactly the two PeepSo hooks: nothing on wp_mail() or its filters, so BCC mail is untouched'
        );
    }

    public function testTheQueueIsVetoedForEveryRecipient(): void
    {
        PeepSoMailSilence::register();

        // PeepSo's call shape: apply_filters('peepso_mailqueue_allowed', $user_id, TRUE).
        foreach ([7, 1, 999999] as $userId) {
            self::assertFalse(apply_filters('peepso_mailqueue_allowed', $userId, true), "recipient {$userId}");
        }
    }

    public function testAnEarlierAllowCannotOverrideTheVeto(): void
    {
        add_filter('peepso_mailqueue_allowed', static fn (): bool => true, 10, 2);
        PeepSoMailSilence::register();

        self::assertFalse(apply_filters('peepso_mailqueue_allowed', 7, true));
    }

    public function testTheSendLoopIsUnscheduled(): void
    {
        PeepSoMailSilence::unscheduleSendLoop();

        self::assertSame(['peepso_mailqueue_send_event'], $GLOBALS['__bcc_ms_cleared']);
    }

    /**
     * bcc-trust.php delegates to this class, and no longer clears the cron
     * itself: one owner for the silencing.
     */
    public function testThePluginRegistersItOnce(): void
    {
        $src = self::code(dirname(__DIR__, 2) . '/bcc-trust.php');

        self::assertSame(1, substr_count($src, 'PeepSoMailSilence::register()'));
        self::assertSame(0, substr_count($src, "'peepso_mailqueue_send_event'"), 'the cron clear lives in the class now');
    }

    /**
     * BCC's own mail: every bcc-trust file that sends through wp_mail() keeps
     * clear of PeepSo's queue, so vetoing that queue cannot reach it.
     */
    public function testBccMailNeverRoutesThroughPeepSosQueue(): void
    {
        $senders = [];
        $it      = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/app'));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = self::code($file->getPathname());
            if (!str_contains($code, 'wp_mail(')) {
                continue;
            }
            $senders[] = $file->getFilename();
            self::assertStringNotContainsString('PeepSoMailQueue', $code, $file->getFilename());
            self::assertStringNotContainsString('peepso_mailqueue', $code, $file->getFilename());
        }

        self::assertContains('AccountSecurityMailer.php', $senders, 'the scan found BCC\'s mailers');
    }

    /** Source without comments, so prose that names a construct is not a use of it. */
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
