<?php
/**
 * Stubs for ValidatorPageOperatorAuthorityTest (subprocess-only).
 *
 * The authority under test is a pure decision ladder over three
 * collaborators: the claim resolver, `get_post()` and the validator
 * lookup. Doubling all three lets every rung be reached in isolation
 * with no database — which is the point, because the rung that matters
 * most (AMBIGUOUS denies) is unreachable in an integration fixture once
 * the verified-operator uniqueness constraint is in place.
 *
 * ⚠ The repository doubles keep their REAL fully-qualified names. The
 * authority resolves them by FQCN, so a `*Double` name would simply let
 * the autoloader pull in the production classes and reach the real
 * `$wpdb`. Declaring them here first is what keeps the unit isolated,
 * and it is why these tests MUST run with
 * #[RunTestsInSeparateProcesses] + #[PreserveGlobalState(false)].
 *
 * Fixtures:
 *   $GLOBALS['__bcc_a0_resolution']      array returned by the resolver
 *   $GLOBALS['__bcc_a0_resolve_calls']   int, incremented per resolve
 *   $GLOBALS['__bcc_a0_posts']           array<int, object|null> page posts
 *   $GLOBALS['__bcc_a0_validator_rows']  array<int, object|null> validator legs
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories {

    class ClaimRepository
    {
        public const OPERATOR_UNCLAIMED = 'UNCLAIMED';
        public const OPERATOR_RESOLVED  = 'RESOLVED';
        public const OPERATOR_AMBIGUOUS = 'AMBIGUOUS';

        /**
         * @return array{state: string, user_id: int|null}
         */
        public static function resolveVerifiedOperatorForPage(int $pageId): array
        {
            $GLOBALS['__bcc_a0_resolve_calls'] = ($GLOBALS['__bcc_a0_resolve_calls'] ?? 0) + 1;

            /** @var array{state: string, user_id: int|null} $r */
            $r = $GLOBALS['__bcc_a0_resolution']
                ?? ['state' => self::OPERATOR_UNCLAIMED, 'user_id' => null];
            return $r;
        }
    }

    class ValidatorRepository
    {
        public static function findFirstByPageId(int $pageId): ?object
        {
            $rows = $GLOBALS['__bcc_a0_validator_rows'] ?? [];
            return $rows[$pageId] ?? null;
        }
    }
}

namespace {

    if (!class_exists('WP_Post')) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_type = '';
            public string $post_status = '';

            /**
             * Declared, not dynamic, because one test deliberately SETS it
             * to the wrong user to prove authorship plays no part in the
             * decision. A dynamic property there is a PHP 8.2 deprecation,
             * and the real WP_Post declares this field anyway.
             */
            public int $post_author = 0;
        }
    }

    if (!function_exists('get_post')) {
        function get_post($pageId = null)
        {
            $posts = $GLOBALS['__bcc_a0_posts'] ?? [];
            return $posts[(int) $pageId] ?? null;
        }
    }
}
