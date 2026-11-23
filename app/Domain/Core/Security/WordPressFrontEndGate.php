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
 *      no themed page is rendered for them. A signed-in admin still gets
 *      the themed page.
 */
final class WordPressFrontEndGate
{
    /** Before redirect_canonical (10), so a feed URL is one hop. */
    public const REDIRECT_PRIORITY = 1;

    /** The X-Redirect-By header, so a curl shows who answered. */
    public const REDIRECT_BY = 'BCC';

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'sendFeedsToTheApp'], self::REDIRECT_PRIORITY);
        add_action('template_redirect', [self::class, 'refuseAuthorArchives'], self::REDIRECT_PRIORITY);
        add_action('template_redirect', [self::class, 'bareNotFoundWhenSignedOut'], self::REDIRECT_PRIORITY);
        add_filter('the_generator', [self::class, 'noGenerator'], PHP_INT_MAX, 2);
        add_filter('wp_sitemaps_enabled', [self::class, 'noSitemaps'], PHP_INT_MAX);

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

    /** A bare 404: no-cache headers, no body, no handler after it. */
    private static function bareNotFound(): never
    {
        nocache_headers();
        status_header(404);
        exit;
    }
}
