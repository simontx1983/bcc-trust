<?php

declare(strict_types=1);

/**
 * F-246 / F-300 DmThreadReadAndReplyTest: a driven DM world for
 * MessagesService's thread/inbox reads and the can_reply gate. Load only
 * in a separate process, BEFORE MessagesService is autoloaded, so these
 * declarations win over bcc-core and PeepSo.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    final class DmWorld
    {
        /** A DM read fails (the `...OrUnknown` reads answer null). */
        public static bool $readFails = false;
        /** @var list<int> unfiltered participants of the conversation */
        public static array $participants = [];
        /** @var array<int, true> users the VIEWER-FILTERED participant list drops (PeepSo hides a blocked pair) */
        public static array $hiddenFromViewer = [];
        /** @var array<int, bool> userId => chat_enabled */
        public static array $chatEnabled = [];
        /** @var array<int, true> users with chat_friends_only on */
        public static array $friendsOnly = [];
        /** @var array<int, true> suspended users */
        public static array $suspended = [];
        /** true / false / null (unreadable) for the viewer↔peer pair */
        public static ?bool $blocked = false;
        /** @var list<array{int, int}> */
        public static array $viewedCalls = [];
        /** @var list<?int> the viewerId argument of every getParticipantUserIds call */
        public static array $participantReads = [];

        public static function reset(): void
        {
            self::$readFails        = false;
            self::$participants     = [];
            self::$hiddenFromViewer = [];
            self::$chatEnabled      = [];
            self::$friendsOnly      = [];
            self::$suspended        = [];
            self::$blocked          = false;
            self::$viewedCalls      = [];
            self::$participantReads = [];
        }
    }

    if (!class_exists('PeepSoChatModel', false)) {
        final class PeepSoChatModel
        {
            public static function chat_enabled(int $userId): bool
            {
                return DmWorld::$chatEnabled[$userId] ?? true;
            }

            public static function chat_friends_only(int $userId): bool
            {
                return isset(DmWorld::$friendsOnly[$userId]);
            }
        }
    }

    if (!function_exists('cache_users')) {
        /** @param list<int> $ids */
        function cache_users(array $ids): void {}
    }

    if (!function_exists('get_userdata')) {
        /** @return object|false */
        function get_userdata(int $userId)
        {
            return $userId > 0 ? (object) ['ID' => $userId] : false;
        }
    }
}

namespace BCC\Core\Permissions {
    if (!class_exists(__NAMESPACE__ . '\\Permissions', false)) {
        final class Permissions
        {
            public static function is_not_suspended(int $userId, bool $default = false): bool
            {
                return !isset(\DmWorld::$suspended[$userId]);
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\PeepSoBlockRepository', false)) {
        final class PeepSoBlockRepository
        {
            public static function isMutuallyBlockedOrUnknown(int $userA, int $userB): ?bool
            {
                return \DmWorld::$blocked;
            }

            /** @return array<int, true>|null */
            public static function getMutuallyBlockedSetOrUnknown(int $userId, array $otherIds): ?array
            {
                return \DmWorld::$blocked === null ? null : [];
            }

            /** @return array<int, true>|null */
            public static function getBlockedSetOrUnknown(int $blockerId, array $candidateIds): ?array
            {
                return [];
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\PeepSoMessageRepository', false)) {
        final class PeepSoMessageRepository
        {
            public static function findRootConversationId(int $msgId): int
            {
                return $msgId;
            }

            public static function userIsParticipant(int $userId, int $rootMsgId): bool
            {
                return true;
            }

            /** @return list<int> */
            public static function getParticipantUserIds(int $rootMsgId, ?int $viewerId = null): array
            {
                \DmWorld::$participantReads[] = $viewerId;
                if ($viewerId === null) {
                    return \DmWorld::$participants;
                }
                return array_values(array_filter(
                    \DmWorld::$participants,
                    static fn (int $u): bool => !isset(\DmWorld::$hiddenFromViewer[$u])
                ));
            }

            /** @return list<array<string, mixed>>|null */
            public static function findConversationsForUserOrUnknown(int $userId, int $limit, int $offset): ?array
            {
                return \DmWorld::$readFails ? null : [];
            }

            /** @return list<array<string, mixed>>|null */
            public static function findMessagesInConversationOrUnknown(int $rootMsgId, int $userId, int $limit, int $offset): ?array
            {
                return \DmWorld::$readFails ? null : [];
            }

            public static function countConversationsForUser(int $userId): int
            {
                return 0;
            }

            /** The send path's rate-limit probe: never throttled here. */
            public static function countRecentByAuthor(int $authorId, int $windowSeconds): int
            {
                return 0;
            }

            public static function markConversationAsViewed(int $userId, int $rootMsgId): void
            {
                \DmWorld::$viewedCalls[] = [$userId, $rootMsgId];
            }
        }
    }
}
