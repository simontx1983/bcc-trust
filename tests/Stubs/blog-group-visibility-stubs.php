<?php

/**
 * Stubs for the profile Blog tab's group-visibility gate.
 *
 * What these pin is NOT whether a row is filtered — that is SQL, and it is
 * pinned in bcc-core's own suite. What they pin is WHICH GATE INPUTS this
 * caller hands the repository, because that is the whole of bcc-trust's
 * responsibility here and the place a leak would originate:
 *
 *   - null, never [], when the non-open set cannot be proven;
 *   - the author-scoped mode only for the signed-in owner of the profile;
 *   - the viewer taken from the SESSION, never from the argument.
 *
 * Every class below is also stubbed by other Stubs/ files, so any suite
 * using these MUST run in a separate process. The guards are
 * `class_exists(..., false)` so a stub already declared in this process
 * wins and nothing is redeclared.
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists('BCC\Core\Repositories\PeepSoActivityRepository', false)) {
        final class PeepSoActivityRepository
        {
            public const GROUP_GATE_VIEWER_ACCESS = 'viewer_access';
            public const GROUP_GATE_AUTHOR_SCOPED = 'author_scoped';
            public const RESTRICTED_GROUP_IDS_MAX = 500;

            /**
             * Every getActivities() call, in order, with just the args this
             * gate is about.
             *
             * @var list<array<string, mixed>>
             */
            public static array $calls = [];

            /** Rows to hand back. @var list<object> */
            public static array $rows = [];

            public static function reset(): void
            {
                self::$calls = [];
                self::$rows  = [];
            }

            /**
             * @param list<int>|null $authorIds
             * @param list<string>|null $moduleIds
             * @param list<int>|null $excludedAuthorIds
             * @param list<int>|null $excludedActIds
             * @param list<int>|null $excludedGroupIds
             * @param list<string>|null $groupVisibilityIn
             * @param list<int>|null $restrictedGroupIds
             * @param list<int>|null $viewerMemberGroupIds
             * @return list<object>
             */
            public static function getActivities(
                ?array $authorIds = null,
                ?array $moduleIds = null,
                ?string $cursorTime = null,
                ?int $cursorActId = null,
                int $limit = 20,
                ?array $excludedAuthorIds = null,
                ?array $excludedActIds = null,
                ?int $onlyForGroupId = null,
                ?array $excludedGroupIds = null,
                ?array $groupVisibilityIn = null,
                ?string $hashtag = null,
                ?string $hallFeed = null,
                ?int $onlyForPageId = null,
                ?array $restrictedGroupIds = null,
                ?string $groupGateMode = null,
                ?array $viewerMemberGroupIds = null,
                int $viewerId = 0
            ): array {
                self::$calls[] = [
                    'authorIds'            => $authorIds,
                    'moduleIds'            => $moduleIds,
                    'limit'                => $limit,
                    'onlyForGroupId'       => $onlyForGroupId,
                    'excludedGroupIds'     => $excludedGroupIds,
                    'restrictedGroupIds'   => $restrictedGroupIds,
                    'groupGateMode'        => $groupGateMode,
                    'viewerMemberGroupIds' => $viewerMemberGroupIds,
                    'viewerId'             => $viewerId,
                ];

                return self::$rows;
            }
        }
    }

    if (!class_exists('BCC\Core\Repositories\PeepSoGroupRepository', false)) {
        final class PeepSoGroupRepository
        {
            /**
             * What the STRICT lookup answers. null models "cannot prove" —
             * a failed or truncated query.
             *
             * @var list<int>|null
             */
            public static ?array $strictNonOpen = [];

            /** @var list<int> */
            public static array $memberships = [];

            public static int $strictCalls = 0;

            public static function reset(): void
            {
                self::$strictNonOpen = [];
                self::$memberships   = [];
                self::$strictCalls   = 0;
            }

            /** @return list<int>|null */
            public static function getNonOpenGroupIdsStrict(int $limit = 500): ?array
            {
                ++self::$strictCalls;

                return self::$strictNonOpen;
            }

            /** @return list<int> */
            public static function getUserMemberGroupIds(int $userId, int $limit = 200): array
            {
                return self::$memberships;
            }

            /**
             * The LENIENT lookup. Deliberately returns a DIFFERENT value
             * from the strict one, so a caller that reaches for this by
             * mistake is caught rather than accidentally correct.
             *
             * @return list<int>
             */
            public static function getNonOpenGroupIds(int $limit = 500): array
            {
                return [999999];
            }
        }
    }

    if (!class_exists('BCC\Trust\Core\Repositories\HiddenActivityRepository', false)) {
        // Declared in this namespace block for brevity; the real class
        // lives in BCC\Trust\Core\Repositories and is final, so it cannot
        // be subclassed in a test.
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!class_exists('BCC\Trust\Core\Repositories\HiddenActivityRepository', false)) {
        final class HiddenActivityRepository
        {
            /** @var list<int> */
            public static array $hidden = [];

            /** @return list<int> */
            public function getAllHiddenIds(): array
            {
                return self::$hidden;
            }
        }
    }
}

namespace BCC\Core\Feed {
    if (!class_exists('BCC\Core\Feed\FeedItemNormalizer', false)) {
        final class FeedItemNormalizer
        {
            /** @param mixed ...$args */
            public static function normalize(...$args): array
            {
                return ['id' => 'feed_stub'];
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists('BCC\Core\Log\Logger', false)) {
        final class Logger
        {
            /** @var list<array{string, array<string, mixed>}> */
            public static array $warnings = [];

            public static function reset(): void
            {
                self::$warnings = [];
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                self::$warnings[] = [$message, $context];
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function debug(string $message, array $context = []): void
            {
            }
        }
    }
}

namespace {
    // The session viewer. This is the value BlogService must trust, and
    // the tests move it independently of the argument it is passed.
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return isset($GLOBALS['bcc_test_session_user']) ? (int) $GLOBALS['bcc_test_session_user'] : 0;
        }
    }

    // hydrateAuthor() runs even for an empty page; false takes its early
    // exit, so no further user plumbing is needed.
    if (!function_exists('get_userdata')) {
        function get_userdata(int $userId)
        {
            return false;
        }
    }
}
