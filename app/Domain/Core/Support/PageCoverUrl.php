<?php
/**
 * PageCoverUrl — a peepso-page's cover image, on an origin WordPress
 * actually serves.
 *
 * ⭐ ONE reader for every surface that emits a page cover: the §3.2 Card
 * (`CardViewService`) and the page-discovery card
 * (`PageDiscoveryService`). Two copies of this would be two chances to get
 * the rebase below wrong, and discovery's copy already had the problem.
 *
 * ── WHY THE STORED VALUE CANNOT BE EMITTED AS-IS ─────────────────────────
 *
 * ⚠⚠ `page_cover_photo` holds a FULL URL, built from `content_url()` AT
 * UPLOAD TIME. On a site whose WordPress origin has since moved — which is
 * what the headless cutover did — a row written before the move still
 * carries the OLD host, and that host may not serve `/wp-content` at all.
 * The public front door does not: it is a Next.js app.
 *
 * ⇒ So the stored host is discarded and the value is rebased BY PATH onto
 * `content_url()`: if the URL's path sits under the content directory's
 * path, the same path is re-emitted on the current content origin.
 * Anything else is `null`.
 *
 * That rule is deliberately host-blind, and it is safe BECAUSE it is: the
 * emitted origin is always ours, so a foreign host in the stored value can
 * never become the origin a viewer's browser is sent to. A foreign host
 * carrying a content-shaped path is therefore rebased onto our origin,
 * where at worst it 404s — a broken image on our own domain, not a request
 * to someone else's. A foreign host whose path is NOT content-shaped is
 * refused outright.
 *
 * ⚠ The query string and fragment are dropped. Nothing writes them here,
 * and carrying them would let a stored value append arbitrary query
 * parameters to a URL on our own origin.
 *
 * ⚠ A RELATIVE value is refused rather than rebased. PeepSo always stores
 * an absolute URL, so a relative one means something else wrote the row,
 * and this is not the place to start guessing what it meant.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class PageCoverUrl
{
    /** The upload path's own meta key, read by every caller. */
    public const META_KEY = 'page_cover_photo';

    /**
     * A page's cover URL on the current content origin, or null.
     *
     * ⚠⚠ THE META, NOT `PeepSoPage::get_cover_url()`. That accessor starts
     * from `get_cover_default()` and overwrites it only when this same meta
     * is non-empty (peepso-pages page.php:722-735), so it can never report
     * "no cover": every page would receive PeepSo's default image and no
     * caller could tell a real cover from a placeholder. This meta is the
     * only signal that separates them — and it is what the accessor itself
     * reads.
     *
     * Fail-safe throughout: anything unusable reads as "no cover", never as
     * an error.
     */
    public static function forPage(int $postId): ?string
    {
        if ($postId <= 0) {
            return null;
        }

        $raw = get_post_meta($postId, self::META_KEY, true);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return self::onContentOrigin(esc_url_raw($raw));
    }

    /**
     * Rebase one absolute URL onto `content_url()` by PATH, or null.
     *
     * Public so the rule itself is testable without a meta fixture, and so
     * a future caller holding a URL rather than a post id can reuse it.
     */
    public static function onContentOrigin(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        // Absolute only — see the class docblock on relative values.
        if (($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '') {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            return null;
        }

        $base     = (string) content_url();
        $basePath = parse_url($base, PHP_URL_PATH);
        $basePath = is_string($basePath) ? rtrim($basePath, '/') : '';

        // ⚠ No content PATH to match against (content served from the
        // domain root) means every path would qualify, so this refuses
        // instead. Fail-closed: WordPress puts content under a directory in
        // every normal install, and guessing is worse than no cover.
        if ($basePath === '') {
            return null;
        }

        if (!str_starts_with($path, $basePath . '/')) {
            return null;
        }

        return rtrim($base, '/') . substr($path, strlen($basePath));
    }
}
