<?php

declare(strict_types=1);

/**
 * F-345 ReviewReplyServiceTest: a driven world for ReviewReplyService.
 * Load only in a separate process, BEFORE the service is autoloaded, so these
 * declarations win over the real (final) collaborators.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    final class ReplyWorld
    {
        /** @var array<int, array{voter: int, page: int}> active, not-hidden reviews by vote id */
        public static array $reviews = [];
        /** @var array<int, array{vote_id: int, post_id: int, act_id: int, author_id: int, body: string, posted_at: string, edited_at: string|null, hidden: bool}> */
        public static array $replies = [];
        /** @var array<int, int> page id => PeepSo/post owner (0 = an unowned placeholder) */
        public static array $owners = [];
        /** @var array<int, int> validator page id => verified operator (0 = ambiguous / unclaimed) */
        public static array $validatorOperators = [];
        /** @var array<int, true> */
        public static array $suspended = [];
        public static bool $readFails = false;
        public static bool $throttled = false;
        public static bool $writeFails = false;
        /** @var list<array{string, array<int, mixed>}> */
        public static array $actions = [];
        /** @var list<array<string, mixed>> */
        public static array $published = [];
        /** @var list<array{int, int}> */
        public static array $deleted = [];
        /** @var array<int, array<string, mixed>> */
        public static array $postMeta = [];
        /** @var array<int, string> */
        public static array $postContent = [];

        public static function reset(): void
        {
            foreach (['reviews', 'replies', 'owners', 'validatorOperators', 'suspended', 'actions', 'published', 'deleted', 'postMeta', 'postContent'] as $p) {
                self::${$p} = [];
            }
            self::$readFails  = false;
            self::$throttled  = false;
            self::$writeFails = false;
        }

        public static function maybeFail(): void
        {
            if (self::$readFails) {
                throw new \BCC\Trust\Onchain\Repositories\RepositoryReadFailure('Stub', 'read', 'injected');
            }
        }
    }

    if (!function_exists('do_action')) {
        function do_action(string $hook, ...$args): void
        {
            ReplyWorld::$actions[] = [$hook, $args];
        }
    }
    if (!function_exists('sanitize_textarea_field')) {
        function sanitize_textarea_field(string $s): string
        {
            return trim(strip_tags($s));
        }
    }
    if (!function_exists('is_wp_error')) {
        /** @param mixed $thing */
        function is_wp_error($thing): bool
        {
            return false;
        }
    }
    if (!function_exists('wp_update_post')) {
        /** @param array<string, mixed> $post */
        function wp_update_post(array $post, bool $wpError = false): int
        {
            ReplyWorld::$postContent[(int) $post['ID']] = (string) $post['post_content'];
            foreach (ReplyWorld::$replies as $voteId => $r) {
                if ($r['post_id'] === (int) $post['ID']) {
                    ReplyWorld::$replies[$voteId]['body'] = (string) $post['post_content'];
                }
            }
            return (int) $post['ID'];
        }
    }
    if (!function_exists('update_post_meta')) {
        /** @param mixed $value */
        function update_post_meta(int $postId, string $key, $value): bool
        {
            ReplyWorld::$postMeta[$postId][$key] = $value;
            foreach (ReplyWorld::$replies as $voteId => $r) {
                if ($r['post_id'] === $postId && $key === '_bcc_review_reply_edited_at') {
                    ReplyWorld::$replies[$voteId]['edited_at'] = (string) $value;
                }
            }
            return true;
        }
    }
}

namespace BCC\Core\Permissions {
    if (!class_exists(__NAMESPACE__ . '\\Permissions', false)) {
        final class Permissions
        {
            public static function is_not_suspended(?int $userId, bool $allowAdminBypass = true): bool
            {
                return !isset(\ReplyWorld::$suspended[(int) $userId]);
            }
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(__NAMESPACE__ . '\\Throttle', false)) {
        final class Throttle
        {
            public static function allow(string $action, int $limit = 10, int $window = 60, ?string $key = null): bool
            {
                return !\ReplyWorld::$throttled;
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\VoteRepository', false)) {
        class VoteRepository
        {
            public function findReviewForReply(int $voteId): ?object
            {
                \ReplyWorld::maybeFail();
                $r = \ReplyWorld::$reviews[$voteId] ?? null;
                return $r === null ? null : (object) ['id' => $voteId, 'voter_user_id' => $r['voter'], 'page_id' => $r['page']];
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\ReviewReplyRepository', false)) {
        final class ReviewReplyRepository
        {
            public const MODULE_NAME    = 'review_reply';
            public const META_EDITED_AT = '_bcc_review_reply_edited_at';

            /** @param list<int> $voteIds @return array<int, array<string, mixed>> */
            public function findByVoteIds(array $voteIds): array
            {
                \ReplyWorld::maybeFail();
                return array_intersect_key(\ReplyWorld::$replies, array_flip($voteIds));
            }

            /** @return array<string, mixed>|null */
            public function findForVote(int $voteId): ?array
            {
                \ReplyWorld::maybeFail();
                return \ReplyWorld::$replies[$voteId] ?? null;
            }
        }
    }
}

namespace BCC\Trust\Core\Services\Feed {
    if (!class_exists(__NAMESPACE__ . '\\ActivityStreamWriter', false)) {
        final class ActivityStreamWriter
        {
            /** @return array{post_id: int, act_id: int}|null */
            public function publishReviewReply(int $replierId, int $voteId, string $body): ?array
            {
                if (\ReplyWorld::$writeFails) {
                    return null;
                }
                $postId = 5000 + $voteId;
                $actId  = 9000 + $voteId;
                \ReplyWorld::$published[] = ['replier' => $replierId, 'vote' => $voteId, 'body' => $body];
                \ReplyWorld::$replies[$voteId] = [
                    'vote_id' => $voteId, 'post_id' => $postId, 'act_id' => $actId, 'author_id' => $replierId,
                    'body' => $body, 'posted_at' => '2026-11-12 10:00:00', 'edited_at' => null, 'hidden' => false,
                ];
                return ['post_id' => $postId, 'act_id' => $actId];
            }

            public function deleteReviewReply(int $postId, int $actId): bool
            {
                \ReplyWorld::$deleted[] = [$postId, $actId];
                return true;
            }
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!class_exists(__NAMESPACE__ . '\\PageOwnerResolver', false)) {
        final class PageOwnerResolver
        {
            public function getPageOwner(int $pageId): int
            {
                return \ReplyWorld::$owners[$pageId] ?? 0;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!class_exists(__NAMESPACE__ . '\\ValidatorPageOperatorAuthority', false)) {
        final class ValidatorPageOperatorAuthority
        {
            public static function isValidatorPage(int $pageId): bool
            {
                return array_key_exists($pageId, \ReplyWorld::$validatorOperators);
            }

            public static function isOperator(int $pageId, int $viewerId): bool
            {
                $op = \ReplyWorld::$validatorOperators[$pageId] ?? 0;
                return $op > 0 && $op === $viewerId;
            }
        }
    }
}
