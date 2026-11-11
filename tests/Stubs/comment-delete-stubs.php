<?php
/**
 * Stubs for CommentDeleteEvidenceEventTest (subprocess-only).
 *
 * Fakes, per the house "fake-at-FQN, guarded" convention:
 *   - `do_action` in the CommentService namespace (capture-only), so the
 *     test can assert the exact `bcc_comment_deleted` arg tuple.
 *   - bcc-core's static PeepSoCommentWriter at its FQN (configurable
 *     success/failure, records the wp_post id it was asked to trash).
 *   - The final CommentRepository at its FQN — a registry-backed
 *     getCommentMeta so the test seeds the CommentMetaRow shape without
 *     a DB. Safe to define at FQN because the consuming test is
 *     #[RunTestsInSeparateProcesses]: the real class is never loaded in
 *     that subprocess (same isolation contract as
 *     feed-comment-gating-stubs.php).
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services {
    if (!function_exists(__NAMESPACE__ . '\\do_action')) {
        /** Capture-only action fake (namespace-first resolution). */
        function do_action(string $hook, mixed ...$args): void
        {
            \BCC\Trust\Tests\Stubs\CommentDeleteFakes::$actions[] = [$hook, $args];
        }
    }

    // F-47: CommentService::gateForParent() reads the parent's group tag.
    if (!function_exists(__NAMESPACE__ . '\\get_post_meta')) {
        /** @return mixed */
        function get_post_meta(int $postId, string $key = '', bool $single = false)
        {
            return \BCC\Trust\Tests\Stubs\CommentDeleteFakes::$postMeta[$postId][$key] ?? '';
        }
    }
}

namespace BCC\Core\Repositories {
    // F-47: the parent activity a comment route resolves from its feed_id.
    if (!class_exists(PeepSoActivityRepository::class, false)) {
        final class PeepSoActivityRepository
        {
            public static function getById(int $actId): ?object
            {
                return \BCC\Trust\Tests\Stubs\CommentDeleteFakes::$activities[$actId] ?? null;
            }
        }
    }

    // F-47: gateForParent()'s membership read. No row → not a member.
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        final class PeepSoGroupRepository
        {
            public static function getMembershipStatus(int $userId, int $groupId): ?string
            {
                return \BCC\Trust\Tests\Stubs\CommentDeleteFakes::$memberships[$userId][$groupId] ?? null;
            }
        }
    }
}

namespace BCC\Trust\Tests\Stubs {
    final class CommentDeleteFakes
    {
        /** @var list<array{0: string, 1: array<int, mixed>}> */
        public static array $actions = [];

        /** @var list<int> wp_post ids PeepSoCommentWriter::deleteComment received */
        public static array $writerCalls = [];

        public static bool $writerResult = true;

        /** @var array<int, object> act_id => PeepSoActivityRepository::getById() row */
        public static array $activities = [];

        /** @var array<int, array<string, mixed>> post_id => meta key => value */
        public static array $postMeta = [];

        /** @var array<int, array<int, string>> user_id => group_id => gm_user_status */
        public static array $memberships = [];

        public static function reset(): void
        {
            self::$actions      = [];
            self::$writerCalls  = [];
            self::$writerResult = true;
            self::$activities   = [];
            self::$postMeta     = [];
            self::$memberships  = [];
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoCommentWriter::class, false)) {
        final class PeepSoCommentWriter
        {
            public static function deleteComment(int $commentPostId): bool
            {
                \BCC\Trust\Tests\Stubs\CommentDeleteFakes::$writerCalls[] = $commentPostId;
                return \BCC\Trust\Tests\Stubs\CommentDeleteFakes::$writerResult;
            }
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(CommentRepository::class, false)) {
        class CommentRepository
        {
            // Mirrors the real constants: CommentService::normalizeSort()
            // reads them on every list call (F-47 drives listByFeedId).
            public const SORT_NEW      = 'new';
            public const SORT_TOP      = 'top';
            public const SORT_RELEVANT = 'relevant';

            /** @var array<int, object> act_id => CommentMetaRow-shaped object */
            public array $metaByActId = [];

            public function getCommentMeta(int $commentActId): ?object
            {
                return $this->metaByActId[$commentActId] ?? null;
            }
        }
    }
}
