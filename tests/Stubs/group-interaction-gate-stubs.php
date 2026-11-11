<?php
/**
 * Stubs for GroupInteractionGateTest (subprocess-only).
 *
 * The gate touches four collaborators, each doubled at its REAL FQCN so the
 * production class resolves them unchanged:
 *   - BCC\Core\Repositories\PeepSoActivityRepository::getById (bcc-core is
 *     not autoloadable in the unit suite);
 *   - BCC\Trust\Core\Plugin, exposing commentRepository() + groupsService();
 *   - the comment repository's getCommentMeta();
 *   - get_post_meta() / get_post_type().
 *
 * ⚠ Faithful shapes, on purpose. getCommentMeta() answers ONLY for acts that
 * are published comments with a parent (as the real query's
 * `act_comment_object_id > 0 AND post_status = 'publish'` does), and a
 * comment's own wp_post carries NO `peepso_group_id`, as in production. A
 * double that put the group id on the comment post would make the bypass
 * case pass without the fix.
 *
 * Fixture ($GLOBALS['__bcc_gig']):
 *   acts        array<int, array{external_id:int}>       act_id → activity row
 *   comments    array<int, array{parent_post_id:int}>    act_id → published comment meta
 *   post_type   array<int, string>                        post_id → post_type
 *   group_of    array<int, int>                           post_id → peepso_group_id
 *   members     array<string, bool>                       "user:group" → isMember
 *   access_calls list<array{int,int}>                     [userId, groupId] per lookup
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {

    class PeepSoActivityRepository
    {
        public static function getById(int $actId, ?array $allowedModuleIds = null): ?object
        {
            $row = $GLOBALS['__bcc_gig']['acts'][$actId] ?? null;
            if ($row === null) {
                return null;
            }
            // Faithful to the real query: an allowlist is a SQL `IN (...)` on
            // act_module_id, and an empty list matches nothing.
            $module = (int) ($row['module'] ?? 1);
            if ($allowedModuleIds !== null && !in_array($module, $allowedModuleIds, true)) {
                return null;
            }
            $GLOBALS['__bcc_gig']['allowlists'][] = $allowedModuleIds;
            return (object) ['act_id' => $actId, 'act_external_id' => $row['external_id'], 'act_module_id' => $module];
        }
    }
}

namespace BCC\Trust\Core\Tests\Stubs {

    final class GigCommentRepository
    {
        public function getCommentMeta(int $commentActId): ?object
        {
            $c = $GLOBALS['__bcc_gig']['comments'][$commentActId] ?? null;
            if ($c === null) {
                return null;
            }
            return (object) ['act_id' => $commentActId, 'parent_post_id' => $c['parent_post_id']];
        }
    }

    final class GigGroupsService
    {
        /** @return array{isMember: bool}|null */
        public function resolveGroupAccess(int $userId, int $groupId): ?array
        {
            $GLOBALS['__bcc_gig']['access_calls'][] = [$userId, $groupId];
            $key = $userId . ':' . $groupId;
            if (!array_key_exists($key, $GLOBALS['__bcc_gig']['members'])) {
                return null;
            }
            return ['isMember' => $GLOBALS['__bcc_gig']['members'][$key]];
        }
    }
}

namespace BCC\Trust\Core {

    final class Plugin
    {
        private static ?self $instance = null;

        public static function instance(): self
        {
            return self::$instance ??= new self();
        }

        public function commentRepository(): Tests\Stubs\GigCommentRepository
        {
            return new Tests\Stubs\GigCommentRepository();
        }

        public function groupsService(): Tests\Stubs\GigGroupsService
        {
            return new Tests\Stubs\GigGroupsService();
        }
    }
}

namespace {

    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            /** @var mixed */
            public $data;
            public int $status;

            /** @param mixed $data */
            public function __construct($data = null, int $status = 200)
            {
                $this->data   = $data;
                $this->status = $status;
            }

            /** @return mixed */
            public function get_data()
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }
        }
    }

    if (!function_exists('get_post_meta')) {
        /** @return mixed */
        function get_post_meta($postId, $key = '', $single = false)
        {
            if ($key !== 'peepso_group_id') {
                return '';
            }
            $g = $GLOBALS['__bcc_gig']['group_of'][(int) $postId] ?? null;
            return $g === null ? '' : (string) $g;
        }
    }

    if (!function_exists('get_post_type')) {
        /** @return string|false */
        function get_post_type($post = null)
        {
            return $GLOBALS['__bcc_gig']['post_type'][(int) $post] ?? false;
        }
    }

    if (!function_exists('get_post_status')) {
        /** @return string|false */
        function get_post_status($post = null)
        {
            return $GLOBALS['__bcc_gig']['post_status'][(int) $post] ?? 'publish';
        }
    }
}
