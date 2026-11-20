<?php

declare(strict_types=1);

/**
 * FeedItemGateTest doubles: the activity module lookup and the REST response.
 * The feed allowlist itself is bcc-core's REAL FeedItemNormalizer (the test
 * loads it from the sibling checkout). Load only in a separate process.
 */

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\PeepSoActivityRepository', false)) {
        class PeepSoActivityRepository
        {
            public static function getModuleIdByActId(int $actId): ?string
            {
                $map = $GLOBALS['__bcc_fig_module_by_act'] ?? [];
                return $map[$actId] ?? null;
            }

            /**
             * Only the announcement arm reads the row (for its notice id),
             * so the row is built from the module map.
             *
             * @param mixed ...$rest
             */
            public static function getById(int $actId, ...$rest): ?object
            {
                $module = $GLOBALS['__bcc_fig_module_by_act'][$actId] ?? null;

                return $module === null
                    ? null
                    : (object) ['act_id' => $actId, 'act_module_id' => $module, 'act_external_id' => $actId + 1, 'act_user_id' => (int) ($GLOBALS['__bcc_fig_author'] ?? 0)];
            }
        }
    }
}

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_fig_viewer'] ?? 0);
        }
    }

    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            /** @param mixed $data */
            public function __construct(public $data = null, public int $status = 200)
            {
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
}

/*
 * The comment-repository seam FeedItemGate::isCommentAct() asks, reached
 * through the container when no repository is handed in.
 *
 * ⚠ Both guards pass `false` to class_exists: the default AUTOLOADS the real
 * class and then skips the double, which would silently run production code.
 */
namespace BCC\Trust\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\CommentRepository', false)) {
        class CommentRepository
        {
            /** @var array<int, object> act_id => a CommentMetaRow-shaped row */
            public array $metaByActId = [];

            public function getCommentMeta(int $commentActId): ?object
            {
                $seeded = $GLOBALS['__bcc_fig_comment_acts'] ?? [];
                if (in_array($commentActId, (array) $seeded, true)) {
                    return (object) ['act_id' => $commentActId, 'parent_post_id' => 4242, 'parent_act_id' => 77];
                }

                return $this->metaByActId[$commentActId] ?? null;
            }
        }
    }
}

namespace BCC\Trust\Core {
    if (!class_exists(__NAMESPACE__ . '\\Plugin', false)) {
        final class Plugin
        {
            private static ?self $instance = null;

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            public function commentRepository(): Repositories\CommentRepository
            {
                return new Repositories\CommentRepository();
            }
        }
    }
}
