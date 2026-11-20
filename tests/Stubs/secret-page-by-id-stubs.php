<?php
/**
 * SecretPageByIdTest: the world around VoteEligibilityChecker::check(), so a
 * review (or vote) can be driven up to and past its page gate.
 *
 * ⚠ Required from a test's setUp(), i.e. inside its separate process, BEFORE
 * the real Plugin autoloads: the Plugin declared here must win.
 *
 *  - Plugin::rankStateRepository(): every voter is an Apprentice or higher,
 *    so assertAuthenticated() lets them through.
 *  - Plugin::pageOwnerResolver(): a page's owner is its post_author (from
 *    $GLOBALS['__bcc_spl']['posts']); each lookup is recorded in $ownerCalls.
 *  - wp_cache_get()/wp_cache_set(): an in-memory object cache, so a test can
 *    seed the voter's cached eligibility verdict. That verdict is the first
 *    thing check() reads AFTER the page checks, which is how a test sees
 *    that a voter got past the page gate.
 */

declare(strict_types=1);

namespace {
    // TableRegistry's existence cache TTL (PeepSoQueryRepository::pageExists).
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    if (!function_exists('wp_cache_get')) {
        function wp_cache_get(string $key, string $group = '', bool $force = false, &$found = null)
        {
            $found = isset($GLOBALS['__bcc_cache'][$group][$key]);

            return $found ? $GLOBALS['__bcc_cache'][$group][$key] : false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            $GLOBALS['__bcc_cache'][$group][$key] = $value;

            return true;
        }
    }
}

namespace BCC\Trust\Core {
    if (!class_exists(__NAMESPACE__ . '\\Plugin', false)) {
        final class Plugin
        {
            /** @var list<int> page ids the owner resolver was asked about */
            public static array $ownerCalls = [];

            private static ?self $instance = null;

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            public function rankStateRepository(): object
            {
                return new class {
                    public function getForUser(int $userId): object
                    {
                        return (object) ['user_id' => $userId];
                    }
                };
            }

            public function pageOwnerResolver(): object
            {
                return new class {
                    public function getPageOwner(int $pageId): int
                    {
                        Plugin::$ownerCalls[] = $pageId;
                        $post = $GLOBALS['__bcc_spl']['posts'][$pageId] ?? null;

                        return $post instanceof \WP_Post ? (int) $post->post_author : 0;
                    }
                };
            }
        }
    }
}
