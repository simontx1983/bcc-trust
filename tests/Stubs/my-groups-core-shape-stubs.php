<?php
/**
 * Stubs for MyGroupsCoreShapeTest (F-416). SUBPROCESS-ONLY: this declares
 * bcc-core's real `BCC\Core\Feed\ActivityFeedService` and
 * `BCC\Core\Repositories\PeepSoGroupRepository`, and a class declaration
 * cannot be undone. The consuming test is `#[RunTestsInSeparateProcesses]`.
 *
 * The SHAPE is chosen by `$GLOBALS['__bcc_mgcore']['f416']`, set BEFORE this
 * file is required:
 *   false  bcc-core as it was before F-416: no SCOPE_GROUPS, no
 *          MEMBER_GROUPS_CAP, no getActiveMemberGroupIdsOrFail();
 *   true   bcc-core with F-416. getActiveMemberGroupIdsOrFail() records each
 *          call in `asked` and returns `groups` (default []).
 *
 * Only what "My Groups" touches before its first feed read exists here.
 */

declare(strict_types=1);

namespace BCC\Core\Feed {
    if (empty($GLOBALS['__bcc_mgcore']['f416'])) {
        final class ActivityFeedService
        {
            public const SCOPE_FOR_YOU   = 'for_you';
            public const SCOPE_FOLLOWING = 'following';
            public const SCOPE_SIGNALS   = 'signals';
            public const SCOPE_GROUP     = 'group';
        }
    } else {
        final class ActivityFeedService
        {
            public const SCOPE_FOR_YOU     = 'for_you';
            public const SCOPE_FOLLOWING   = 'following';
            public const SCOPE_SIGNALS     = 'signals';
            public const SCOPE_GROUP       = 'group';
            public const SCOPE_GROUPS      = 'groups';
            public const MEMBER_GROUPS_CAP = 200;
        }
    }
}

namespace BCC\Core\Repositories {
    if (empty($GLOBALS['__bcc_mgcore']['f416'])) {
        final class PeepSoGroupRepository
        {
        }
    } else {
        final class PeepSoGroupRepository
        {
            /** @return list<int>|null */
            public static function getActiveMemberGroupIdsOrFail(int $userId, int $limit = 200): ?array
            {
                $GLOBALS['__bcc_mgcore']['asked'][] = [$userId, $limit];

                return $GLOBALS['__bcc_mgcore']['groups'] ?? [];
            }
        }
    }
}
