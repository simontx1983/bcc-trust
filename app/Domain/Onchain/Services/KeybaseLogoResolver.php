<?php
/**
 * KeybaseLogoResolver — Cosmos validator logo source.
 *
 * Cosmos SDK validators publish a `description.identity` that is a Keybase
 * 16-hex key suffix. Keybase's public lookup maps it to the operator's
 * profile picture:
 *
 *   GET https://keybase.io/_/api/1.0/user/lookup.json?key_suffix=<id>&fields=pictures
 *   → { status:{code:0}, them:[ { pictures:{ primary:{ url:"https://…" } } } ] }
 *
 * The call goes through ApiRetry (→ SafeHttpClient) so it inherits SSRF
 * hardening, retry, and circuit-breaking. We only RESOLVE the URL here;
 * the downloading + byte-validation happens in ValidatorLogoService.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Contracts\LogoResolver;
use BCC\Trust\Onchain\Support\ApiRetry;

if (!defined('ABSPATH')) {
    exit;
}

final class KeybaseLogoResolver implements LogoResolver
{
    private const ENDPOINT = 'https://keybase.io/_/api/1.0/user/lookup.json';

    public function resolveImageUrl(string $identity): ?string
    {
        // A Cosmos identity is a Keybase 16-hex key suffix. Anything else
        // (empty, a username, garbage some validators set) is not a Keybase
        // key-suffix lookup and is ignored here.
        if (preg_match('/^[0-9A-Fa-f]{16}$/', $identity) !== 1) {
            return null;
        }

        $url = self::ENDPOINT
            . '?key_suffix=' . rawurlencode($identity)
            . '&fields=pictures';

        $resp = ApiRetry::get(
            $url,
            ['timeout' => 5],
            ['label' => 'Keybase validator logo lookup', 'max_retries' => 1]
        );

        if (is_wp_error($resp)) {
            return null;
        }
        if ((int) wp_remote_retrieve_response_code($resp) !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($resp);
        if (!is_string($body) || $body === '') {
            return null;
        }

        /** @var mixed $data */
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        // `them` is a list of matched users (empty when the suffix matches
        // nobody). Take the first.
        $them = $data['them'] ?? null;
        $first = is_array($them) && isset($them[0]) && is_array($them[0]) ? $them[0] : null;
        if ($first === null) {
            return null;
        }

        $pictures = isset($first['pictures']) && is_array($first['pictures']) ? $first['pictures'] : null;
        $primary  = $pictures !== null && isset($pictures['primary']) && is_array($pictures['primary'])
            ? $pictures['primary']
            : null;
        $picUrl = $primary !== null && isset($primary['url']) && is_string($primary['url'])
            ? $primary['url']
            : '';

        // Only https URLs are worth handing to the downloader (SafeHttpClient
        // rejects others anyway, but fail fast and keep the column clean).
        if ($picUrl === '' || stripos($picUrl, 'https://') !== 0) {
            return null;
        }

        return $picUrl;
    }
}
