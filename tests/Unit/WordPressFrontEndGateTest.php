<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Security\WordPressFrontEndGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Security review, 2026-11-19: the cms host served signed-out RSS (the five
 * newest posts in full, with their authors' names; the comments feed) and a
 * generator tag naming the WordPress version. WordPressFrontEndGate sends
 * every feed to the app with a 301, empties the generator and unlinks the
 * feeds from the head.
 *
 * Runs the real gate over the sign-in gate's WordPress doubles plus
 * tests/Stubs/wp-front-end-stubs.php (is_feed(), remove_action()).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WordPressFrontEndGateTest extends TestCase
{
    private const APP = 'https://app.example.test/';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/envelope-meta-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        require_once __DIR__ . '/../Stubs/wp-signin-gate-stubs.php';
        require_once __DIR__ . '/../Stubs/wp-front-end-stubs.php';
        $GLOBALS['__bcc_sig'] = ['admins' => [1], 'current' => 0, 'ajax' => false, 'hooks' => [], 'removed' => [], 'feed' => false];
    }

    // ── Wiring ────────────────────────────────────────────────────────────

    public function testItHooksTheRedirectAndTheGeneratorFilter(): void
    {
        WordPressFrontEndGate::register();

        self::assertSame(
            [
                ['template_redirect', [WordPressFrontEndGate::class, 'sendFeedsToTheApp'], 1, 1],
                ['the_generator', [WordPressFrontEndGate::class, 'noGenerator'], PHP_INT_MAX, 2],
            ],
            $GLOBALS['__bcc_sig']['hooks']
        );
    }

    public function testItUnhooksTheGeneratorAndTheFeedLinksAtCoresPriorities(): void
    {
        WordPressFrontEndGate::register();

        // remove_action() only removes at the priority core added it with
        // (default-filters.php), so these numbers are load-bearing.
        self::assertSame(
            [
                ['wp_head', 'wp_generator', 10],
                ['wp_head', 'feed_links', 2],
                ['wp_head', 'feed_links_extra', 3],
            ],
            $GLOBALS['__bcc_sig']['removed']
        );
    }

    public function testItIsRegisteredAtBoot(): void
    {
        $boot = (string) file_get_contents(__DIR__ . '/../../bcc-trust.php');

        self::assertStringContainsString("\\BCC\\Trust\\Core\\Security\\WordPressFrontEndGate::register();", $boot);
    }

    // ── Feeds go to the app ───────────────────────────────────────────────

    /**
     * template-loader.php (7.0.2), cut down to what decides a front-end
     * answer: every `template_redirect` callback by priority, core's own
     * redirect_canonical (default-filters.php, so registered before any
     * plugin) at 10; then the HEAD exit; then a feed is served by
     * do_feed(), anything else by the theme.
     *
     * @return list<string> what happened, in order
     */
    private static function serve(bool $feed, string $method = 'GET'): array
    {
        $GLOBALS['__bcc_sig']['feed'] = $feed;
        $trace = [];

        $callbacks = [[static function () use (&$trace): void { $trace[] = 'redirect_canonical'; }, 10]];
        foreach ($GLOBALS['__bcc_sig']['hooks'] as [$hook, $callback, $priority]) {
            if ($hook === 'template_redirect') {
                $callbacks[] = [$callback, $priority];
            }
        }
        usort($callbacks, static fn(array $a, array $b): int => $a[1] <=> $b[1]);

        try {
            foreach ($callbacks as [$callback]) {
                $callback();
            }
        } catch (\SignInGateHalt) {
            $trace[] = 'redirected';
            return $trace;
        }

        if ($method === 'HEAD') {
            $trace[] = 'HEAD exit';
            return $trace;
        }
        $trace[] = $feed ? 'feed served' : 'page served';

        return $trace;
    }

    /** @return iterable<string, array{0: string}> */
    public static function methods(): iterable
    {
        yield 'GET'  => ['GET'];
        yield 'HEAD' => ['HEAD'];
    }

    #[DataProvider('methods')]
    public function testAFeedIs301edToTheAppBeforeAnythingElseAnswers(string $method): void
    {
        WordPressFrontEndGate::register();

        self::assertSame(['redirected'], self::serve(true, $method), 'one hop, before redirect_canonical, the HEAD exit and do_feed()');
        self::assertSame([self::APP, 301], $GLOBALS['__bcc_sig']['redirect']);
        self::assertSame('BCC', $GLOBALS['__bcc_sig']['redirect_by']);
    }

    #[DataProvider('methods')]
    public function testAnythingElseIsLeftAlone(string $method): void
    {
        WordPressFrontEndGate::register();

        self::assertSame(['redirect_canonical', $method === 'HEAD' ? 'HEAD exit' : 'page served'], self::serve(false, $method));
        self::assertArrayNotHasKey('redirect', $GLOBALS['__bcc_sig']);
    }

    // ── No generator, in any format ───────────────────────────────────────

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function generators(): iterable
    {
        // get_the_generator()'s output for each type (general-template.php).
        yield 'html'    => ['<meta name="generator" content="WordPress 7.1.4">', 'html'];
        yield 'xhtml'   => ['<meta name="generator" content="WordPress 7.1.4" />', 'xhtml'];
        yield 'atom'    => ['<generator uri="https://wordpress.org/" version="7.1.4">WordPress</generator>', 'atom'];
        yield 'rss2'    => ['<generator>https://wordpress.org/?v=7.1.4</generator>', 'rss2'];
        yield 'rdf'     => ['<admin:generatorAgent rdf:resource="https://wordpress.org/?v=7.1.4" />', 'rdf'];
        yield 'comment' => ['<!-- generator="WordPress/7.1.4" -->', 'comment'];
        yield 'export'  => ['<!-- generator="WordPress/7.1.4" created="2026-11-19 00:00" -->', 'export'];
    }

    #[DataProvider('generators')]
    public function testTheGeneratorSaysNothing(string $generator, string $type): void
    {
        WordPressFrontEndGate::register();

        // the_generator's recorded filters, by priority, over core's value
        // and a LATER filter's rewrite of it (99), which the gate must still
        // follow: it is the last word.
        $filters = [[static fn(string $g): string => $g . '<!-- theme -->', 99]];
        foreach ($GLOBALS['__bcc_sig']['hooks'] as [$hook, $callback, $priority]) {
            if ($hook === 'the_generator') {
                $filters[] = [$callback, $priority];
            }
        }
        usort($filters, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
        $value = $generator;
        foreach ($filters as [$callback]) {
            $value = $callback($value, $type);
        }

        self::assertSame('', $value);
    }
}
