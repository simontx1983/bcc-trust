<?php

declare(strict_types=1);

/**
 * Absolute media-library URLs that were stored with a host baked in.
 *
 * `bcc_onchain_validators.logo_url` is written from wp_get_attachment_url()
 * at sideload time, so it carries whatever host ran the download. A
 * staging-flavoured DB restore put 73 `stage.bluecollarcrypto.io` logo URLs
 * into production (2026-11-12 incident); every one 404'd and none self-healed.
 *
 * Two helpers, both pure given an upload dir:
 *   - rebase(): a SIBLING-host upload URL (same registrable domain — stage.,
 *     cms., apex) is re-based onto this site's upload baseurl at read time.
 *     It fixes host moves where the file exists here; it cannot conjure a
 *     missing file.
 *   - isCurrentAndPresent(): the stored URL is on THIS site's upload baseurl
 *     AND its file exists — what ValidatorLogoService now requires before it
 *     skips a re-download, so a wrong-host or missing logo heals itself.
 *
 * @package BCC\Trust\Onchain\Support
 */

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class LocalUploadUrl
{
    private const UPLOADS_PATH = '/wp-content/uploads/';

    /**
     * @param array{baseurl?: string, basedir?: string}|null $uploadDir defaults to wp_upload_dir()
     */
    public static function rebase(?string $url, ?array $uploadDir = null): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        // Only an upload URL can be re-based; anything else returns before
        // the upload dir is even looked up.
        $rel = self::relativeUploadPath($url);
        if ($rel === null) {
            return $url;
        }
        $baseUrl = rtrim((string) (($uploadDir ?? self::uploadDir())['baseurl'] ?? ''), '/');
        if ($baseUrl === '') {
            return $url;
        }

        $host     = strtolower((string) parse_url($url, PHP_URL_HOST));
        $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        if ($host === '' || $baseHost === '' || $host === $baseHost || !self::siblings($host, $baseHost)) {
            return $url;
        }

        return $baseUrl . '/' . $rel;
    }

    /**
     * @param array{baseurl?: string, basedir?: string}|null $uploadDir defaults to wp_upload_dir()
     */
    public static function isCurrentAndPresent(?string $url, ?array $uploadDir = null): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $dir     = $uploadDir ?? self::uploadDir();
        $baseUrl = rtrim((string) ($dir['baseurl'] ?? ''), '/');
        $baseDir = rtrim((string) ($dir['basedir'] ?? ''), '/\\');
        if ($baseUrl === '' || $baseDir === '' || !str_starts_with($url, $baseUrl . '/')) {
            return false;
        }

        $rel = substr($url, strlen($baseUrl) + 1);
        $rel = (string) strtok($rel, '?#');
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, '\\')) {
            return false;
        }

        return is_file($baseDir . '/' . rawurldecode($rel));
    }

    /** The path after /wp-content/uploads/, or null when the URL is not an upload. */
    private static function relativeUploadPath(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $at   = strpos($path, self::UPLOADS_PATH);
        if ($at !== 0) {
            return null;
        }
        $rel = substr($path, strlen(self::UPLOADS_PATH));
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }
        return $rel;
    }

    /** Same registrable domain (last two labels): stage./cms./apex are siblings. */
    private static function siblings(string $a, string $b): bool
    {
        $tail = static function (string $h): string {
            $labels = explode('.', $h);
            return implode('.', array_slice($labels, -2));
        };
        return substr_count($a, '.') >= 1 && substr_count($b, '.') >= 1 && $tail($a) === $tail($b);
    }

    /** @return array{baseurl?: string, basedir?: string} */
    private static function uploadDir(): array
    {
        if (!function_exists('wp_upload_dir')) {
            return []; // no WordPress: nothing to re-base against
        }
        $dir = wp_upload_dir(null, false);
        return is_array($dir) ? $dir : [];
    }
}
