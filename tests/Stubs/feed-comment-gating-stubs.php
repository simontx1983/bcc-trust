<?php
/**
 * Fixture-backed stubs for FeedCommentCountGatingTest.
 *
 * Subprocess-only (RunTestsInSeparateProcesses). Defines the two
 * collaborators hydrateCommentCounts touches at their FQNs BEFORE the
 * autoloader can resolve the real ones:
 *   - BCC\Core\Repositories\PeepSoGroupRepository (bcc-core, static) —
 *     records every getMembershipStatus call and answers from a
 *     (viewer,group) fixture map.
 *   - BCC\Trust\Core\Repositories\CommentRepository — countsByParentPostIds
 *     from a fixture map.
 *
 * CommentService is NOT stubbed — the real class supplies
 * READ_ALLOWED_STATUSES (a pure const, no DB on load).
 *
 * Fixture shape ($GLOBALS['__bcc_gate_fixture']):
 *   status_map  array<string, string|null>  "viewer:group" => status
 *   counts      array<int, int>              external_id => comment count
 *   calls       list<array{int,int}>         [viewerId, groupId] per getMembershipStatus
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        class PeepSoGroupRepository
        {
            public static function getMembershipStatus(int $userId, int $groupId): ?string
            {
                $GLOBALS['__bcc_gate_fixture']['calls'][] = [$userId, $groupId];
                $key = $userId . ':' . $groupId;
                return $GLOBALS['__bcc_gate_fixture']['status_map'][$key] ?? null;
            }
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(CommentRepository::class, false)) {
        class CommentRepository
        {
            /**
             * @param list<int> $parentPostIds
             * @return array<int, int>
             */
            public function countsByParentPostIds(array $parentPostIds): array
            {
                $out = [];
                foreach ($parentPostIds as $pid) {
                    if (isset($GLOBALS['__bcc_gate_fixture']['counts'][$pid])) {
                        $out[$pid] = $GLOBALS['__bcc_gate_fixture']['counts'][$pid];
                    }
                }
                return $out;
            }
        }
    }
}
