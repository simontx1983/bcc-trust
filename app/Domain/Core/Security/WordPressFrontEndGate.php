<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security;

use BCC\Trust\Core\Support\FrontendRedirect;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What WordPress's own front end on the cms host may still say to a
 * signed-out visitor.
 *
 * BCC is headless: the app has no RSS and reads no sitemap, and BCC's own
 * `/bcc/v1/feed/*` routes are REST, which core answers at `parse_request`,
 * before any of this runs. So WordPress's own feeds, sitemaps and
 * self-description stay off on cms. Rationale: security review 2026-11-19
 * (private tracker).
 *
 *   1. A feed request 301s to the app's front page.
 *      - `template_redirect` at priority 1: before `redirect_canonical`
 *        (10), so it is one hop; and before template-loader.php's HEAD
 *        exit and its `do_feed()`, so a HEAD request is redirected too.
 *      - Every feed core serves is an `is_feed()` query: posts, comments,
 *        a post's comments, author, category, tag, term, search, and the
 *        `?feed=` spellings of each, in every format.
 *   2. The generator is empty everywhere `the_generator` reaches. That is
 *      the head's meta tag and a feed's `<generator>` element alike.
 *      `wp_generator` is also unhooked from `wp_head`, so it leaves no
 *      empty line.
 *   3. Neither the head nor the headers advertise anything else:
 *      - the feeds (`feed_links`, `feed_links_extra`);
 *      - the REST API (`rest_output_link_wp_head`, and the
 *        `Link: <…/wp-json/>; rel="https://api.w.org/"` header from
 *        `rest_output_link_header` on `template_redirect`);
 *      - XML-RPC's RSD document (`rsd_link`);
 *      - the `?p=` shortlink (`wp_shortlink_wp_head`, and its
 *        `Link: rel=shortlink` header from `wp_shortlink_header`);
 *      - oEmbed discovery (`wp_oembed_add_discovery_links`, which core
 *        hooks twice: at 4, and at 10 for back-compat). The links point
 *        at `/wp-json/oembed/…`, which RestRouteAllowlist refuses to a
 *        signed-out caller anyway, and cms serves nothing to embed.
 *
 *   4. Core's XML sitemaps are off (`wp_sitemaps_enabled` → false, last
 *      word), so `/wp-sitemap.xml` and every `?sitemap=` answer 404. No
 *      SEO plugin provides them on cms any more (F-509), and nothing BCC
 *      runs needs them.
 *   5. An author archive (`?author=N`, `/author/{slug}/`) answers a bare 404
 *      at `template_redirect` priority 1, before `redirect_canonical` (10)
 *      runs, for every caller. It is decided on the REQUEST (its query
 *      vars), never on whether that author exists, so every author request
 *      gets the same answer. BCC's member pages live in the app; nothing
 *      links to these.
 *   6. Every other 404 a signed-out visitor would get is the same bare 404:
 *      no themed page is rendered for them. Any signed-in visitor still
 *      gets the themed page (the check is is_user_logged_in()). Checked
 *      twice:
 *      - at `template_redirect` priority 1, before `redirect_canonical`
 *        (10) can guess a permalink for it;
 *      - at `404_template`, the last word. Every path to the theme's 404
 *        page asks get_404_template() first: core's template loader,
 *        including a 404 set later during `template_redirect` (core's
 *        sitemap handler sets one at 10 while sitemaps are off), and any
 *        plugin that renders the 404 page itself.
 *   7. cms has no public front end. EVERY front-end request from a
 *      non-admin (signed out included) is sent to the app's front page with
 *      a 302: pages, posts, archives, the front page, search, feeds, 404s,
 *      whatever they are built from. Two things still reach core and
 *      PeepSo: robots.txt, and PeepSo's external-cron triggers
 *      (PEEPSO_CRON_TRIGGERS: PeepSo runs the job and exits, rendering no
 *      page), so a server cron that calls them keeps working.
 *      - It runs on `wp` at -1000, before PeepSo's own `wp` handler (-1,
 *        PeepSo::check_query) can act on the request, and before
 *        `template_redirect`, so for a non-admin it is the only answer.
 *        Items 1, 5 and 6 still apply to admins, and stay as defence in
 *        depth.
 *      - `wp` fires only for front-end main queries, so REST (served at
 *        `parse_request`), admin-ajax, wp-admin, wp-login.php, wp-cron.php
 *        and xmlrpc.php never reach it.
 *      - The app is on another host. If home ever pointed back at this
 *        host (ports aside), the answer is a bare 403 instead of a loop.
 */
final class WordPressFrontEndGate
{
    /** Before redirect_canonical (10), so a feed URL is one hop. */
    public const REDIRECT_PRIORITY = 1;

    /** The X-Redirect-By header, so a curl shows who answered. */
    public const REDIRECT_BY = 'BCC';

    /** `wp`, before PeepSo's own handler there (-1). */
    public const FRONT_END_PRIORITY = -1000;

    /**
     * PeepSo's external-cron triggers (PeepSo::check_query, `wp` at -1): a GET
     * parameter that runs one job and exits. Left to PeepSo, exactly as
     * before this rule existed.
     */
    public const PEEPSO_CRON_TRIGGERS = [
        'peepso_process_mailqueue',
        'peepso_process_maintenance',
        'peepso_process_ranking',
        'peepso_process_remove_inactive_users',
    ];

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'sendFeedsToTheApp'], self::REDIRECT_PRIORITY);
        add_action('template_redirect', [self::class, 'refuseAuthorArchives'], self::REDIRECT_PRIORITY);
        add_action('template_redirect', [self::class, 'bareNotFoundWhenSignedOut'], self::REDIRECT_PRIORITY);
        add_filter('the_generator', [self::class, 'noGenerator'], PHP_INT_MAX, 2);
        add_filter('wp_sitemaps_enabled', [self::class, 'noSitemaps'], PHP_INT_MAX);
        add_filter('404_template', [self::class, 'bareNotFoundTemplate'], PHP_INT_MAX);
        add_action('wp', [self::class, 'sendFrontEndToTheApp'], self::FRONT_END_PRIORITY);

        // Core hooks these in default-filters.php, which runs before any
        // plugin loads, so they are there to remove now.
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'feed_links', 2);
        remove_action('wp_head', 'feed_links_extra', 3);
        remove_action('wp_head', 'rest_output_link_wp_head', 10);
        remove_action('template_redirect', 'rest_output_link_header', 11);
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wp_shortlink_wp_head', 10);
        remove_action('template_redirect', 'wp_shortlink_header', 11);
        remove_action('wp_head', 'wp_oembed_add_discovery_links', 4);
        remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);
    }

    /**
     * `template_redirect`: a feed goes to the app's front page, for good.
     */
    public static function sendFeedsToTheApp(): void
    {
        if (!is_feed()) {
            return;
        }

        // ⚠ wp_redirect, NOT wp_safe_redirect: the same reason as
        // WordPressSignInGate::keepMembersOutOfAdmin(). The target comes from
        // BCC_FRONTEND_ORIGIN, never from the request, and wp_safe_redirect
        // only trusts the frontend's host once FrontendRedirect's
        // `allowed_redirect_hosts` filter is in place (rest_api_init, never
        // a front-end request). Until then it would send a feed to
        // wp-admin instead.
        wp_redirect(FrontendRedirect::defaultReturn('/'), 301, self::REDIRECT_BY);
        exit;
    }

    /**
     * `the_generator`: say nothing, in every format.
     *
     * @param mixed $generator
     * @param mixed $type
     */
    public static function noGenerator($generator = '', $type = ''): string
    {
        unset($generator, $type);

        return '';
    }

    /**
     * `wp_sitemaps_enabled`, last: core's XML sitemaps stay off, whatever an
     * earlier filter answered (F-509).
     *
     * @param mixed $enabled
     */
    public static function noSitemaps($enabled = true): bool
    {
        unset($enabled);

        return false;
    }

    /**
     * `template_redirect`, first (F-509): an author request answers a bare 404
     * and nothing else: no page is rendered. Decided on the request's query
     * vars, which keep their values whether or not the author exists, so
     * every author request gets this same answer.
     */
    public static function refuseAuthorArchives(): void
    {
        if ((string) get_query_var('author') === '' && (string) get_query_var('author_name') === '') {
            return;
        }

        self::bareNotFound();
    }

    /**
     * `template_redirect`, first (F-509): a signed-out visitor's 404 is a bare
     * 404, never a themed page.
     */
    public static function bareNotFoundWhenSignedOut(): void
    {
        if (!is_404() || is_user_logged_in()) {
            return;
        }

        self::bareNotFound();
    }

    /**
     * `404_template`, last: the theme's 404 page is never rendered for a
     * signed-out visitor, whoever asked for it. A signed-in visitor gets the
     * template unchanged.
     *
     * @param mixed $template
     * @return mixed
     */
    public static function bareNotFoundTemplate($template = '')
    {
        if (is_user_logged_in()) {
            return $template;
        }

        self::bareNotFound();
    }

    /**
     * `wp`, first: a non-admin's front-end request goes to the app's front
     * page (302, no-cache), or answers a bare 403 if home is this same host.
     * robots.txt is left to core, and PEEPSO_CRON_TRIGGERS to PeepSo.
     */
    public static function sendFrontEndToTheApp(): void
    {
        $userId = get_current_user_id();
        if (($userId > 0 && user_can($userId, WordPressSignInGate::CAPABILITY)) || is_robots()) {
            return;
        }
        foreach (self::PEEPSO_CRON_TRIGGERS as $trigger) {
            if (isset($_GET[$trigger])) {
                return;
            }
        }

        $target     = home_url('/');
        $targetHost = strtolower((string) parse_url($target, PHP_URL_HOST));
        if ($targetHost === '' || $targetHost === self::requestHost()) {
            nocache_headers();
            status_header(403);
            exit;
        }

        nocache_headers();
        wp_safe_redirect($target, 302, self::REDIRECT_BY);
        exit;
    }

    /**
     * The request's host, lower-cased, with any port removed, so it compares
     * with parse_url()'s host: `cms.example:8443` → `cms.example`,
     * `[::1]:8080` → `[::1]`.
     */
    private static function requestHost(): string
    {
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            return $end === false ? $host : substr($host, 0, $end + 1);
        }
        $colon = strpos($host, ':');

        return $colon === false ? $host : substr($host, 0, $colon);
    }

    /** A bare 404: no-cache headers, no body, no handler after it. */
    private static function bareNotFound(): never
    {
        nocache_headers();
        status_header(404);
        exit;
    }
}
