<?php

/**
 * Post-lookup stubs for the claim-bonus integration suite.
 *
 * `BonusService::recomputeAndApply()` reaches `get_post()` twice over, and
 * neither call is optional:
 *
 *   - `SignalRepository::get_for_page()` -> `PeepSo::get_page_owner()` ->
 *     `NullPageOwnerResolver::getPageOwner()` -> `get_post()`
 *   - the `getPageForOwner()` fallback -> `get_posts()`
 *
 * The shared integration bootstrap defines neither, and the Null resolver is
 * NOT a no-op — it really queries WordPress. Both are declared here behind
 * `function_exists` guards, per the house stub convention, so a sibling
 * suite that already declared its own keeps it.
 *
 * ⚠ `post_author = 0` is the point of these stubs, not an oversight. A
 * system-minted placeholder page is unowned until `bcc_page_claimed`
 * transfers authorship, which happens AFTER the bonus listener has already
 * run — so 0 is exactly what the production code sees at bonus time.
 *
 * @package BCC\Trust\Tests\Stubs
 */

declare(strict_types=1);

if (!isset($GLOBALS['__bcc_posts']) || !is_array($GLOBALS['__bcc_posts'])) {
    $GLOBALS['__bcc_posts'] = [];
}

// Defined in bcc-trust.php:37, which the integration harness does not load.
// Mirrored here rather than invented: the cap is 20, so the 5.0 and 10.0 this
// suite asserts both sit under it and the cap is never the thing under test.
if (!defined('BCC_ONCHAIN_MAX_TOTAL_BONUS')) {
    define('BCC_ONCHAIN_MAX_TOTAL_BONUS', 20);
}

if (!function_exists('get_post')) {
    function get_post($id = null)
    {
        $key = is_object($id) ? (int) ($id->ID ?? 0) : (int) $id;

        return $GLOBALS['__bcc_posts'][$key] ?? null;
    }
}

if (!function_exists('get_posts')) {
    /**
     * Minimal `author` + `post_type` + `fields=ids` query over the fixture
     * registry — enough for `NullPageOwnerResolver::getPageForOwner()`.
     *
     * @param  array<string, mixed> $args
     * @return list<int>
     */
    function get_posts(array $args = []): array
    {
        $author = (int) ($args['author'] ?? 0);
        $type   = (string) ($args['post_type'] ?? 'post');
        $out    = [];

        foreach ($GLOBALS['__bcc_posts'] as $id => $post) {
            if ((int) ($post->post_author ?? 0) !== $author) {
                continue;
            }
            if ((string) ($post->post_type ?? '') !== $type) {
                continue;
            }
            $out[] = (int) $id;
        }

        return $out;
    }
}
