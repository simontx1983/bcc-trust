<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Security\WordPressFrontEndGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Security review, 2026-11-19 (private tracker): WordPressFrontEndGate
 * sends every feed to the app with a 301, empties the generator, unlinks
 * the feeds from the head, and (F-509) keeps core's XML sitemaps off, gives
 * every author request the same bare 404, and gives a signed-out visitor a
 * bare 404 instead of a themed one.
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

    public function testItHooksTheRedirectTheGeneratorAndTheSitemapFilters(): void
    {
        WordPressFrontEndGate::register();

        self::assertSame(
            [
                ['template_redirect', [WordPressFrontEndGate::class, 'sendFeedsToTheApp'], 1, 1],
                ['template_redirect', [WordPressFrontEndGate::class, 'refuseAuthorArchives'], 1, 1],
                ['template_redirect', [WordPressFrontEndGate::class, 'bareNotFoundWhenSignedOut'], 1, 1],
                ['the_generator', [WordPressFrontEndGate::class, 'noGenerator'], PHP_INT_MAX, 2],
                ['wp_sitemaps_enabled', [WordPressFrontEndGate::class, 'noSitemaps'], PHP_INT_MAX, 1],
                ['404_template', [WordPressFrontEndGate::class, 'bareNotFoundTemplate'], PHP_INT_MAX, 1],
                ['wp', [WordPressFrontEndGate::class, 'sendFrontEndToTheApp'], -1000, 1],
            ],
            $GLOBALS['__bcc_sig']['hooks']
        );
    }

    /**
     * F-509: core's sitemaps stay off, as the last word: whatever core or an
     * earlier filter answered (true, a truthy string), the result is false.
     */
    public function testTheSitemapsAreOffWhateverCameBefore(): void
    {
        WordPressFrontEndGate::register();

        $filters = [
            [static fn($on): bool => true, 10],            // core's default
            [static fn($on): string => 'yes', 99],         // a later plugin
        ];
        foreach ($GLOBALS['__bcc_sig']['hooks'] as [$hook, $callback, $priority]) {
            if ($hook === 'wp_sitemaps_enabled') {
                $filters[] = [$callback, $priority];
            }
        }
        usort($filters, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
        $value = true;
        foreach ($filters as [$callback]) {
            $value = $callback($value);
        }

        self::assertFalse($value);
        self::assertFalse(WordPressFrontEndGate::noSitemaps(true));
    }

    public function testItUnhooksEveryAdvertisementAtCoresPriorities(): void
    {
        WordPressFrontEndGate::register();

        // remove_action() only removes at the priority core added it with
        // (default-filters.php), so these numbers are load-bearing.
        self::assertSame(
            [
                ['wp_head', 'wp_generator', 10],
                ['wp_head', 'feed_links', 2],
                ['wp_head', 'feed_links_extra', 3],
                ['wp_head', 'rest_output_link_wp_head', 10],
                ['template_redirect', 'rest_output_link_header', 11],
                ['wp_head', 'rsd_link', 10],
                ['wp_head', 'wp_shortlink_wp_head', 10],
                ['template_redirect', 'wp_shortlink_header', 11],
                ['wp_head', 'wp_oembed_add_discovery_links', 4],
                ['wp_head', 'wp_oembed_add_discovery_links', 10],
            ],
            $GLOBALS['__bcc_sig']['removed']
        );
    }

    /**
     * What core hooks on `wp_head` and `template_redirect`
     * (default-filters.php, 7.0.2), as [hook, callback, priority].
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private static function coresHeadAndRedirectHooks(): array
    {
        return [
            ['wp_head', 'rest_output_link_wp_head', 10], ['template_redirect', 'rest_output_link_header', 11],
            ['wp_head', '_wp_render_title_tag', 1], ['wp_head', 'wp_enqueue_scripts', 1],
            ['wp_head', 'wp_resource_hints', 2], ['wp_head', 'wp_preload_resources', 1],
            ['wp_head', 'feed_links', 2], ['wp_head', 'feed_links_extra', 3], ['wp_head', 'rsd_link', 10],
            ['wp_head', 'locale_stylesheet', 10], ['wp_head', 'wp_robots', 1],
            ['wp_head', 'print_emoji_detection_script', 7], ['wp_head', 'wp_print_styles', 8],
            ['wp_head', 'wp_print_head_scripts', 9], ['wp_head', 'wp_generator', 10],
            ['wp_head', 'rel_canonical', 10], ['wp_head', 'wp_shortlink_wp_head', 10],
            ['wp_head', 'wp_custom_css_cb', 101], ['wp_head', 'wp_site_icon', 99],
            ['template_redirect', 'wp_shortlink_header', 11], ['template_redirect', 'wp_old_slug_redirect', 10],
            ['wp_head', 'wp_post_preview_js', 1], ['template_redirect', 'redirect_canonical', 10],
            ['template_redirect', 'wp_redirect_admin_locations', 1000], ['template_redirect', '_wp_admin_bar_init', 0],
            ['wp_head', 'wp_oembed_add_discovery_links', 4], ['wp_head', 'wp_oembed_add_discovery_links', 10],
            ['wp_head', 'wp_oembed_add_host_js', 10],
        ];
    }

    /**
     * remove_action() as core does it: only an exact hook, callback and
     * priority comes off. So each advertisement must be gone from core's
     * own table, and nothing else may be.
     */
    public function testEachAdvertisementIsGoneFromCoresHooksAndNothingElse(): void
    {
        WordPressFrontEndGate::register();

        $left = array_values(array_filter(
            self::coresHeadAndRedirectHooks(),
            static fn(array $h): bool => !in_array($h, $GLOBALS['__bcc_sig']['removed'], true)
        ));
        $leftNames = array_column($left, 1);

        foreach (['wp_generator', 'feed_links', 'feed_links_extra', 'rest_output_link_wp_head', 'rest_output_link_header', 'rsd_link', 'wp_shortlink_wp_head', 'wp_shortlink_header', 'wp_oembed_add_discovery_links'] as $advert) {
            self::assertNotContains($advert, $leftNames, $advert . ' is gone (at every priority core hooks it)');
        }
        self::assertCount(count(self::coresHeadAndRedirectHooks()) - 10, $left, 'and nothing else came off');
        foreach (['wp_enqueue_scripts', 'rel_canonical', 'wp_robots', 'redirect_canonical'] as $kept) {
            self::assertContains($kept, $leftNames, $kept . ' stays');
        }
    }

    public function testItIsRegisteredAtBoot(): void
    {
        $boot = (string) file_get_contents(__DIR__ . '/../../bcc-trust.php');

        self::assertStringContainsString("\\BCC\\Trust\\Core\\Security\\WordPressFrontEndGate::register();", $boot);
    }

    // ── F-509: author archives answer a bare 404 ─────────────────────────

    /**
     * One author request, as the gate's hooks answer it: what ran, the
     * status, the no-cache headers, and every byte printed.
     *
     * @param array<string, string> $queryVars
     * @return array{trace: list<string>, status: mixed, nocache: mixed, body: string}
     */
    private static function answer(array $queryVars, bool $exists, bool $loggedIn): array
    {
        $GLOBALS['__bcc_sig'] = ['admins' => [1], 'current' => 0, 'ajax' => false, 'hooks' => [], 'removed' => [], 'feed' => false];
        WordPressFrontEndGate::register();
        $GLOBALS['__bcc_sig']['query_vars'] = $queryVars;
        // WP::handle_404() flips a MISSING author to is_404 and clears is_author.
        $GLOBALS['__bcc_sig']['author']    = $exists;
        $GLOBALS['__bcc_sig']['404']       = !$exists;
        $GLOBALS['__bcc_sig']['logged_in'] = $loggedIn;

        ob_start();
        $trace = self::serve(false);
        $body  = (string) ob_get_clean();

        return [
            'trace'   => $trace,
            'status'  => $GLOBALS['__bcc_sig']['status'] ?? null,
            'nocache' => $GLOBALS['__bcc_sig']['nocache'] ?? null,
            'body'    => $body,
        ];
    }

    /** @return iterable<string, array{0: array<string, string>, 1: bool}> */
    public static function authorRequests(): iterable
    {
        yield 'by id, signed out'       => [['author' => '7'], false];
        yield 'by nicename, signed out' => [['author_name' => 'alice'], false];
        yield 'by id, signed in'        => [['author' => '7'], true];
        yield 'by nicename, signed in'  => [['author_name' => 'alice'], true];
    }

    /**
     * An author that exists and one that does not get byte-identical answers:
     * the same bare 404, halted at priority 1 before redirect_canonical.
     *
     * @param array<string, string> $queryVars
     */
    #[DataProvider('authorRequests')]
    public function testEveryAuthorRequestGetsTheSameBare404(array $queryVars, bool $loggedIn): void
    {
        $existing = self::answer($queryVars, true, $loggedIn);
        $missing  = self::answer($queryVars, false, $loggedIn);

        self::assertSame(['trace' => ['redirected'], 'status' => 404, 'nocache' => true, 'body' => ''], $existing);
        self::assertSame($existing, $missing, 'an existing and a missing author answer alike');
    }

    public function testAnythingButAnAuthorRequestIsLeftToTheRest(): void
    {
        $GLOBALS['__bcc_sig']['author'] = true; // is_author() alone decides nothing

        WordPressFrontEndGate::refuseAuthorArchives();

        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
    }

    // ── F-509: a signed-out 404 is bare ───────────────────────────────────

    public function testASignedOutVisitorsNotFoundIsABare404(): void
    {
        WordPressFrontEndGate::register();
        $GLOBALS['__bcc_sig']['404'] = true;

        ob_start();
        $trace = self::serve(false);
        $body  = (string) ob_get_clean();

        self::assertSame(['redirected'], $trace, 'no themed page is rendered');
        self::assertSame(404, $GLOBALS['__bcc_sig']['status'] ?? null);
        self::assertTrue($GLOBALS['__bcc_sig']['nocache'] ?? false);
        self::assertSame('', $body);
    }

    public function testASignedInVisitorStillGetsTheThemedNotFound(): void
    {
        WordPressFrontEndGate::register();
        $GLOBALS['__bcc_sig']['404']       = true;
        $GLOBALS['__bcc_sig']['logged_in'] = true;

        self::assertSame(['redirect_canonical', 'page served'], self::serve(false));
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
    }

    public function testASignedOutPageThatExistsIsLeftAlone(): void
    {
        WordPressFrontEndGate::register();

        self::assertSame(['redirect_canonical', 'page served'], self::serve(false));
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
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

    // ── No themed 404 for a signed-out visitor, whoever asks for it ───────

    /**
     * A request served the way core orders it (wp-includes/template-loader.php
     * and default-filters.php, 7.0.2):
     *   1. `template_redirect` callbacks by priority: the gate's at 1, then
     *      redirect_canonical and core's sitemap handler at 10. With sitemaps
     *      off, the sitemap handler marks a sitemap request a 404 there.
     *   2. The template loader: a 404 asks get_404_template(), which runs the
     *      `404_template` filters, and the theme renders what it returns.
     *
     * @return list<string>
     */
    private static function serveThroughTheLoader(bool $sitemapRequest): array
    {
        $trace     = [];
        $callbacks = [
            [static function () use (&$trace): void { $trace[] = 'redirect_canonical'; }, 10],
            [static function () use (&$trace, $sitemapRequest): void {
                if ($sitemapRequest) {
                    $GLOBALS['__bcc_sig']['404'] = true; // WP_Sitemaps::render_sitemaps(), sitemaps off
                    $trace[] = 'sitemap handler: 404';
                }
            }, 10],
        ];
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
            if ((bool) ($GLOBALS['__bcc_sig']['404'] ?? false)) {
                $trace[] = 'themed 404: ' . self::the404Template();
                return $trace;
            }
        } catch (\SignInGateHalt) {
            $trace[] = 'bare';
            return $trace;
        }
        $trace[] = 'page served';

        return $trace;
    }

    /** get_404_template(): the theme's template through the recorded `404_template` filters, by priority. */
    private static function the404Template(): string
    {
        $filters = [[static fn($t) => $t, 10]]; // a theme or plugin filter that changes nothing
        foreach ($GLOBALS['__bcc_sig']['hooks'] as [$hook, $callback, $priority]) {
            if ($hook === '404_template') {
                $filters[] = [$callback, $priority];
            }
        }
        usort($filters, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
        $template = '/themes/twentytwentyfive/templates/404.html';
        foreach ($filters as [$callback]) {
            $template = $callback($template);
        }

        return (string) $template;
    }

    /**
     * /wp-sitemap.xml signed out: core marks it a 404 at template_redirect
     * 10, AFTER the gate's check at 1 (which therefore sees no 404). The
     * loader then asks for the 404 template, and that answer is bare.
     */
    public function testASitemapRequestThatCoreMarks404LaterIsStillBare(): void
    {
        WordPressFrontEndGate::register();

        ob_start();
        $trace = self::serveThroughTheLoader(true);
        $body  = (string) ob_get_clean();

        self::assertSame(['redirect_canonical', 'sitemap handler: 404', 'bare'], $trace, 'the check at 1 saw no 404; the template asked last did');
        self::assertSame(404, $GLOBALS['__bcc_sig']['status'] ?? null);
        self::assertTrue($GLOBALS['__bcc_sig']['nocache'] ?? false);
        self::assertSame('', $body);
        self::assertLessThan(10, WordPressFrontEndGate::REDIRECT_PRIORITY, "pinned: the first check runs before core's handlers at 10, so a later 404 needs the second");
    }

    public function testASignedInVisitorStillGetsTheThemedSitemap404(): void
    {
        WordPressFrontEndGate::register();
        $GLOBALS['__bcc_sig']['logged_in'] = true;

        self::assertSame(
            ['redirect_canonical', 'sitemap handler: 404', 'themed 404: /themes/twentytwentyfive/templates/404.html'],
            self::serveThroughTheLoader(true)
        );
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
    }

    /**
     * A plugin that renders the 404 page itself, before `template_redirect`
     * (it calls get_404_template() and includes the result): signed out,
     * that is bare too.
     */
    public function testAPluginRenderingThe404PageItselfGetsTheBareAnswer(): void
    {
        WordPressFrontEndGate::register();

        try {
            self::the404Template();
            self::fail('the theme template must not come back for a signed-out visitor');
        } catch (\SignInGateHalt) {
            // bare
        }
        self::assertSame(404, $GLOBALS['__bcc_sig']['status'] ?? null);
        self::assertTrue($GLOBALS['__bcc_sig']['nocache'] ?? false);
    }

    public function testTheTemplateFilterHandsASignedInVisitorTheTemplateUnchanged(): void
    {
        $GLOBALS['__bcc_sig']['logged_in'] = true;

        self::assertSame('/x/404.php', WordPressFrontEndGate::bareNotFoundTemplate('/x/404.php'));
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
    }

    public function testAnExistingPageNeverReachesTheTemplateFilter(): void
    {
        WordPressFrontEndGate::register();

        self::assertSame(['redirect_canonical', 'page served'], self::serveThroughTheLoader(false));
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
    }

    // ── cms has no public front end: non-admins go to the app ────────────

    /** One front-end request at this host by this user (0 = signed out). */
    private static function frontEndRequest(string $host = 'cms.bcc-origin.test', int $user = 0, bool $robots = false): void
    {
        $GLOBALS['__bcc_sig']['current'] = $user;
        $GLOBALS['__bcc_sig']['robots']  = $robots;
        $_SERVER['HTTP_HOST']            = $host;
    }

    /** @return iterable<string, array{0: int}> */
    public static function nonAdmins(): iterable
    {
        yield 'signed out'       => [0];
        yield 'a signed-in member' => [7];
    }

    /**
     * Whatever the request is (a page, a post, an archive, a feed, a 404: the
     * rule never asks), a non-admin gets no-cache headers and a 302 to the
     * app's front page.
     */
    #[DataProvider('nonAdmins')]
    public function testANonAdminsFrontEndRequestGoesToTheApp(int $user): void
    {
        self::frontEndRequest('cms.bcc-origin.test', $user);

        try {
            WordPressFrontEndGate::sendFrontEndToTheApp();
            self::fail('redirected');
        } catch (\SignInGateHalt) {
        }

        self::assertSame(['https://cms.example.test/', 302], $GLOBALS['__bcc_sig']['redirect']);
        self::assertSame('BCC', $GLOBALS['__bcc_sig']['redirect_by']);
        self::assertTrue($GLOBALS['__bcc_sig']['nocache'] ?? false, 'no-cache headers on the redirect');
    }

    public function testAnAdminKeepsTheFrontEnd(): void
    {
        self::frontEndRequest('cms.bcc-origin.test', 1); // 'admins' => [1]

        WordPressFrontEndGate::sendFrontEndToTheApp();

        self::assertArrayNotHasKey('redirect', $GLOBALS['__bcc_sig']);
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
    }

    public function testRobotsTxtIsStillServed(): void
    {
        self::frontEndRequest('cms.bcc-origin.test', 0, true);

        WordPressFrontEndGate::sendFrontEndToTheApp();

        self::assertArrayNotHasKey('redirect', $GLOBALS['__bcc_sig']);
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function peepsoCronTriggers(): iterable
    {
        foreach (WordPressFrontEndGate::PEEPSO_CRON_TRIGGERS as $trigger) {
            yield $trigger => [$trigger];
        }
    }

    /** PeepSo's external-cron triggers still reach PeepSo, as before the rule. */
    #[DataProvider('peepsoCronTriggers')]
    public function testAPeepSoCronTriggerIsLeftToPeepSo(string $trigger): void
    {
        self::frontEndRequest();
        $_GET = [$trigger => '1'];

        WordPressFrontEndGate::sendFrontEndToTheApp();

        self::assertArrayNotHasKey('redirect', $GLOBALS['__bcc_sig']);
        self::assertArrayNotHasKey('status', $GLOBALS['__bcc_sig']);
    }

    /** Exactly PeepSo's four live triggers (PeepSo::check_query, peepso.php 9.0.5.5). */
    public function testTheTriggersArePeepSosFour(): void
    {
        self::assertSame(
            ['peepso_process_mailqueue', 'peepso_process_maintenance', 'peepso_process_ranking', 'peepso_process_remove_inactive_users'],
            WordPressFrontEndGate::PEEPSO_CRON_TRIGGERS
        );
    }

    /** Any other GET parameter, a lookalike included, is redirected like any request. */
    public function testAnotherParameterIsStillRedirected(): void
    {
        self::frontEndRequest();
        $_GET = ['peepso_process' => '1', 'logout' => '1'];

        try {
            WordPressFrontEndGate::sendFrontEndToTheApp();
            self::fail('redirected');
        } catch (\SignInGateHalt) {
        }

        self::assertSame(302, $GLOBALS['__bcc_sig']['redirect'][1] ?? null);
    }

    /** @return iterable<string, array{0: string}> */
    public static function thisSameHost(): iterable
    {
        yield 'the same host'              => ['cms.example.test'];
        yield 'any case'                   => ['CMS.Example.Test'];
        yield 'with a port (review NIT)'   => ['cms.example.test:8443'];
        yield 'with a port, any case'      => ['CMS.EXAMPLE.TEST:80'];
    }

    /** Home on this same host (ports aside) would loop: a bare 403 instead. */
    #[DataProvider('thisSameHost')]
    public function testHomeOnThisHostAnswersABare403(string $host): void
    {
        self::frontEndRequest($host);

        try {
            WordPressFrontEndGate::sendFrontEndToTheApp();
            self::fail('refused');
        } catch (\SignInGateHalt) {
        }

        self::assertSame(403, $GLOBALS['__bcc_sig']['status'] ?? null);
        self::assertTrue($GLOBALS['__bcc_sig']['nocache'] ?? false);
        self::assertArrayNotHasKey('redirect', $GLOBALS['__bcc_sig']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function requestHosts(): iterable
    {
        yield 'a host'                 => ['cms.example.test', 'cms.example.test'];
        yield 'a host and a port'      => ['cms.example.test:8443', 'cms.example.test'];
        yield 'upper case'             => ['CMS.Example.Test:80', 'cms.example.test'];
        yield 'an IPv6 literal'        => ['[::1]', '[::1]'];
        yield 'an IPv6 literal, port'  => ['[::1]:8080', '[::1]'];
        yield 'empty'                  => ['', ''];
    }

    #[DataProvider('requestHosts')]
    public function testTheRequestHostDropsAnyPort(string $httpHost, string $expected): void
    {
        $_SERVER['HTTP_HOST'] = $httpHost;
        $read = new \ReflectionMethod(WordPressFrontEndGate::class, 'requestHost');
        $read->setAccessible(true);

        self::assertSame($expected, $read->invoke(null));
    }

    /** A request whose host differs only in being another host still goes to the app. */
    public function testAnotherHostThatMerelyContainsHomeIsNotTheSameHost(): void
    {
        self::frontEndRequest('cms.example.test.evil.test');

        try {
            WordPressFrontEndGate::sendFrontEndToTheApp();
            self::fail('redirected');
        } catch (\SignInGateHalt) {
        }

        self::assertSame(302, $GLOBALS['__bcc_sig']['redirect'][1] ?? null);
    }
}
