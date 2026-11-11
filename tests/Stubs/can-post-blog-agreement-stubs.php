<?php

/**
 * The one double the can_post_blog agreement test needs.
 *
 * `GroupsService::viewerCanAuthorInGroup()` resolves the viewer's status
 * through `PeepSoGroupRepository::getMembershipStatus()`. Stubbing just
 * that lets the test feed BOTH implementations the same status and compare
 * their verdicts, which is the point: the comparison is only meaningful if
 * the input is identical on both sides.
 *
 * Nothing else is doubled — in particular `PublicAllPolicy` is the real
 * constant, because the two sides sharing it is exactly what is under test.
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists('BCC\Core\Repositories\PeepSoGroupRepository', false)) {
        final class PeepSoGroupRepository
        {
            /** The status the next getMembershipStatus call reports. */
            public static ?string $statusToReturn = null;

            public static function getMembershipStatus(int $userId, int $groupId): ?string
            {
                return self::$statusToReturn;
            }

            /**
             * Present because UserGroupsEndpoint references it; unused by
             * this suite, which exercises the verdict helper directly.
             *
             * @param int[] $groupIds
             * @return array<int, object>
             */
            public static function findUserMemberships(int $userId, array $groupIds): array
            {
                return [];
            }
        }
    }
}
