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
            /**
             * ⚠⚠ THE FULL SIGNATURE MATTERS, and a short one is worse than
             * useless here. PHP silently DISCARDS extra positional
             * arguments to a user-defined method, so while this took only
             * `$actId` the §F-02 audience read — `getById($actId, null,
             * $friends, true, $viewer)` — was accepted and ignored. The
             * gate was inert on this harness and every refusal test would
             * have passed for the wrong reason: exactly the bug a stubbed
             * collaborator hides.
             *
             * ⚠ The §F-02 behaviour below is MODELLED, not real. bcc-core
             * owns the predicate (the STRICTER of the post's `act_access`
             * and its author's `usr_profile_acc`, over ranges) and tests it
             * against rendered SQL in ActivityPerPostVisibilityTest. What
             * is reproduced here is only its observable contract: with
             * enforcement on, an act this viewer may not see does not come
             * back. That is all a bcc-trust test can honestly assert.
             *
             * @param list<int>|null $allowedModuleIds
             * @param list<int>|null $viewerFriendIds
             */
            public static function getById(
                int $actId,
                ?array $allowedModuleIds = null,
                ?array $viewerFriendIds = null,
                bool $enforcePostVisibility = false,
                int $viewerId = 0
            ): ?object {
                \BCC\Trust\Tests\Stubs\CommentDeleteFakes::$getByIdCalls[] = [
                    'act_id'  => $actId,
                    'enforce' => $enforcePostVisibility,
                    'viewer'  => $viewerId,
                    'friends' => $viewerFriendIds,
                    'modules' => $allowedModuleIds,
                ];

                $row = \BCC\Trust\Tests\Stubs\CommentDeleteFakes::$activities[$actId] ?? null;
                if ($row === null) {
                    return null;
                }

                if ($enforcePostVisibility
                    && in_array(
                        $actId,
                        \BCC\Trust\Tests\Stubs\CommentDeleteFakes::$f02Hidden,
                        true
                    )
                ) {
                    return null;
                }

                return $row;
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

        /**
         * Act ids the §F-02 predicate refuses when enforcement is ON — i.e.
         * posts whose audience excludes the viewer.
         *
         * @var list<int>
         */
        public static array $f02Hidden = [];

        /**
         * Every PeepSoActivityRepository::getById() call, in order, so a
         * test can assert the audience read actually carried
         * `enforce = true` for the RIGHT viewer — not merely that a
         * refusal happened, which a broken gate could also produce.
         *
         * @var list<array{act_id: int, enforce: bool, viewer: int, friends: list<int>|null, modules: list<int>|null}>
         */
        public static array $getByIdCalls = [];

        /**
         * Permissions::is_admin(), for the §F-02 exemption. Fixture-driven
         * rather than a hard-coded false, because "an admin is NOT
         * filtered" is a control the audience gate needs: without it, a
         * gate that refused everybody would satisfy every refusal
         * assertion in the suite.
         */
        public static bool $isAdmin = false;

        /**
         * Parent post ids the comment LIST read was reached for. A gate
         * that refuses leaves this empty, so a test can assert the thread
         * was never even queried rather than only that the response had
         * no items.
         *
         * @var list<int>
         */
        public static array $listReads = [];

        public static function reset(): void
        {
            self::$actions      = [];
            self::$writerCalls  = [];
            self::$writerResult = true;
            self::$activities   = [];
            self::$postMeta     = [];
            self::$memberships  = [];
            self::$f02Hidden    = [];
            self::$getByIdCalls = [];
            self::$isAdmin      = false;
            self::$listReads    = [];
        }
    }
}

namespace BCC\Core\Permissions {
    // createComment()'s suspension gate (bcc-core is not autoloadable in
    // the unit suite). Not suspended: the F-265 cases test what follows it.
    if (!class_exists(Permissions::class, false)) {
        final class Permissions
        {
            public static function is_not_suspended(int $userId, bool $allowAdminBypass = true): bool
            {
                return true;
            }

            /**
             * ⚠ REQUIRED since the §F-02 audience gate reached these
             * paths: ProfileAccess::viewerIsExemptFromVisibility() guards
             * on class_exists() and then calls this. The class EXISTS here
             * (that is the point of the stub), so a missing method is a
             * FATAL — "Call to undefined method …::is_admin()" — rather
             * than a failed assertion, which reads like a broken test
             * instead of an incomplete stub.
             *
             * false = an ordinary viewer, which is what these suites are
             * about; the admin exemption has its own coverage.
             */
            public static function is_admin(): bool
            {
                return \BCC\Trust\Tests\Stubs\CommentDeleteFakes::$isAdmin;
            }
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

            /**
             * The list read, for the paths that get PAST the gate.
             *
             * ⚠ Returns an EMPTY page on purpose. With no rows,
             * CommentService never reaches the badge, vouch or handle
             * resolvers (it short-circuits on an empty author set), so an
             * "allowed" assertion needs no further doubles. The page's
             * CONTENTS are not what the audience gate is about — whether
             * the read happens at all is.
             *
             * @return list<object>
             */
            public function listByParentPostId(
                int $parentPostId,
                string $sort,
                ?string $cursorKey,
                ?int $cursorActId,
                int $limit
            ): array {
                \BCC\Trust\Tests\Stubs\CommentDeleteFakes::$listReads[] = $parentPostId;

                return [];
            }
        }
    }
}

namespace BCC\Core\Feed {
    // F-345: FeedItemGate reads bcc-core's public-feed allowlist, and
    // bcc-core is not autoloadable in this suite. This double carries the
    // allowlist's shape; FeedItemGateTest pins the gate against the REAL
    // bcc-core FeedItemNormalizer.
    if (!class_exists(__NAMESPACE__ . '\FeedItemNormalizer', false)) {
        final class FeedItemNormalizer
        {
            /** @return list<int> */
            public static function publicFeedModuleIds(): array
            {
                return [1, 4, 200, 201, 202, 203, 204, 205];
            }
        }
    }
}
