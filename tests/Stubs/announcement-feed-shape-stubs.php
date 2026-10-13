<?php
/**
 * Stubs for AnnouncementFeedReactionShapeTest (subprocess-only).
 *
 * Drives `FeedHydrationPipeline::hydrateReactions()` — the ONLY reachable
 * production path that serializes an announcement's `reactions` block — so
 * the wire shape can be asserted behaviourally rather than inferred from
 * the boundary helper alone.
 *
 * ⚠ bcc-core is not autoloadable in the unit suite, so `ReactionGrammarMap`
 * is doubled at its real FQCN. The double is FAITHFUL, including
 * `announcement => none` and a `countsForWire()` that returns an OBJECT for
 * an empty dict — a double that returned the array unchanged would make
 * every `{}` assertion pass against a shape production does not emit.
 *
 * ⚠ The repository doubles return DELIBERATELY HOSTILE data for the
 * announcement act: real reaction counts and real stoke heat. Nothing can
 * write those today, which is exactly why the fixture supplies them — the
 * guarantee has to hold against stored records the write path refuses, not
 * only against the empty state it produces.
 *
 * Fixtures:
 *   $GLOBALS['__bcc_afs_counts']   array<int, array<int,int>>  act → typeId → count
 *   $GLOBALS['__bcc_afs_viewer']   array<int, int>             act → viewer typeId
 *   $GLOBALS['__bcc_afs_heat']     array<int, int>             act → heat stage
 *   $GLOBALS['__bcc_afs_stoken']   array<int, int>             act → stoke count
 *   $GLOBALS['__bcc_afs_stoked']   array<int, true>            act → viewer stoked
 */

declare(strict_types=1);

namespace BCC\Core\Feed {

    class FeedItemNormalizer
    {
        public const MODULE_TO_KIND = [
            '1'   => 'status',
            '4'   => 'photo',
            '200' => 'watch_batch',
            '201' => 'page_claim',
            '202' => 'review',
            '203' => 'dispute_signed',
            '204' => 'blog_excerpt',
            '205' => 'announcement',
        ];
    }

    class ReactionGrammarMap
    {
        public const GRAMMAR_TRUST  = 'trust';
        public const GRAMMAR_SOCIAL = 'social';
        public const GRAMMAR_TRIBAL = 'tribal';
        public const GRAMMAR_NONE   = 'none';

        /** @var list<string> */
        public const TRUST_KINDS = ['solid'];

        /** @var list<string> */
        public const SOCIAL_KINDS = ['like', 'love', 'haha', 'wow', 'fire'];

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

        public static function grammarForModule(string $moduleId): string
        {
            return self::grammarFor(FeedItemNormalizer::MODULE_TO_KIND[$moduleId] ?? 'status');
        }

        /** @return list<string> */
        public static function kindsFor(string $grammar): array
        {
            return match ($grammar) {
                self::GRAMMAR_TRUST  => self::TRUST_KINDS,
                self::GRAMMAR_SOCIAL => self::SOCIAL_KINDS,
                default              => [],
            };
        }

        public static function kindBelongsToGrammar(string $kind, string $grammar): bool
        {
            return in_array($kind, self::kindsFor($grammar), true);
        }

        /** @return array<string, int> */
        public static function emptyCountsFor(string $grammar): array
        {
            $counts = [];
            foreach (self::kindsFor($grammar) as $kind) {
                $counts[$kind] = 0;
            }
            return $counts;
        }

        /**
         * @param  array<string, int> $counts
         * @return array<string, int>|\stdClass
         */
        public static function countsForWire(array $counts)
        {
            return $counts === [] ? new \stdClass() : $counts;
        }
    }
}

namespace BCC\Trust\Core\Support {

    /** type_id → kind. Ids chosen to be obviously synthetic. */
    class ReactionGrammarRegistry
    {
        /** @return array<int, string> */
        public static function idToKindMap(): array
        {
            return [
                91 => 'solid',
                92 => 'like',
                93 => 'love',
                94 => 'haha',
                95 => 'wow',
                96 => 'fire',
            ];
        }
    }
}

namespace BCC\Trust\Core\Repositories {

    class PeepSoReactionRepository
    {
        /**
         * @param  list<int> $actIds
         * @return array<int, array<int, int>>
         */
        public function countsByActIds(array $actIds): array
        {
            return $GLOBALS['__bcc_afs_counts'] ?? [];
        }

        /**
         * @param  list<int> $actIds
         * @return array<int, int>
         */
        public function viewerReactionsByActIds(int $viewerId, array $actIds): array
        {
            return $GLOBALS['__bcc_afs_viewer'] ?? [];
        }
    }

    class StokeRepository
    {
        /**
         * @param  list<int> $actIds
         * @return array<int, int>
         */
        public function heatByActIds(array $actIds): array
        {
            return $GLOBALS['__bcc_afs_heat'] ?? [];
        }

        /**
         * @param  list<int> $actIds
         * @return array<int, int>
         */
        public function countsByActIds(array $actIds): array
        {
            return $GLOBALS['__bcc_afs_stoken'] ?? [];
        }

        /**
         * @param  list<int> $actIds
         * @return array<int, true>
         */
        public function viewerStokedActIds(int $viewerId, array $actIds): array
        {
            return $GLOBALS['__bcc_afs_stoked'] ?? [];
        }
    }
}
