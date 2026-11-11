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

namespace BCC\Core\Feed {

    /**
     * Grammar double — REQUIRED, not optional, since hydrateCommentCounts()
     * began asking NoReactionsGate whether the kind takes reactions. bcc-core
     * is not autoloadable in the unit suite, so without this the method dies
     * on a class-not-found before a single assertion runs.
     *
     * ⚠ Faithful to the production map, including `announcement => none`. A
     * double that answered `none` for everything would turn the comment-count
     * cases below green while silently withholding the count from every kind.
     */
    class ReactionGrammarMap
    {
        public const GRAMMAR_SOCIAL = 'social';
        public const GRAMMAR_TRUST  = 'trust';
        public const GRAMMAR_NONE   = 'none';

        private const POST_KIND_TO_GRAMMAR = [
            'review'         => self::GRAMMAR_TRUST,
            'dispute_signed' => self::GRAMMAR_TRUST,
            'page_claim'     => self::GRAMMAR_TRUST,
            'status'         => self::GRAMMAR_SOCIAL,
            'watch_batch'    => self::GRAMMAR_SOCIAL,
            'blog_excerpt'   => self::GRAMMAR_SOCIAL,
            'announcement'   => self::GRAMMAR_NONE,
        ];

        public static function grammarFor(string $postKind): string
        {
            return self::POST_KIND_TO_GRAMMAR[$postKind] ?? self::GRAMMAR_SOCIAL;
        }

        /** @param array<string, int> $counts @return array<string, int>|\stdClass */
        public static function countsForWire(array $counts)
        {
            return $counts === [] ? new \stdClass() : $counts;
        }
    }
}

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
