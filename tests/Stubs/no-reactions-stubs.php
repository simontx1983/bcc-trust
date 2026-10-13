<?php
/**
 * Stubs for NoReactionsGateTest (subprocess-only).
 *
 * ⚠ bcc-core IS NOT AUTOLOADABLE IN THE UNIT SUITE — `class_exists()` on
 * `BCC\Core\Feed\ReactionGrammarMap` is false there, because bcc-trust's
 * autoloader does not reach the sibling plugin. So these doubles are not a
 * convenience; without them the gate cannot be unit-tested at all. They
 * keep their REAL fully-qualified names for the same reason the repository
 * doubles elsewhere do: the gate resolves them by FQCN.
 *
 * ⚠⚠ THE GRAMMAR DOUBLE IS FAITHFUL ON PURPOSE, including the
 * `announcement => none` entry that bcc-core #57 adds. A double that simply
 * returned `none` for everything would make every assertion below vacuous —
 * the gate would "work" against a double that cannot distinguish kinds. The
 * map here is a copy of the production map, and
 * NoReactionsGateTest::testTheWireLiteralMatchesTheProductionConstant
 * reconciles it with the real class as soon as that class is reachable.
 *
 * Fixtures:
 *   $GLOBALS['__bcc_nr_module_by_act']  array<int, string|null> act → module
 *   $GLOBALS['__bcc_nr_module_lookups'] int, incremented per lookup
 */

declare(strict_types=1);

namespace BCC\Core\Feed {

    class FeedItemNormalizer
    {
        /** Faithful copy of the production numeric map, plus 205. */
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

        /** Faithful copy of the production post_kind → grammar map. */
        private const POST_KIND_TO_GRAMMAR = [
            'review'         => self::GRAMMAR_TRUST,
            'dispute_signed' => self::GRAMMAR_TRUST,
            'page_claim'     => self::GRAMMAR_TRUST,
            'project_drop'   => self::GRAMMAR_TRUST,
            'nft_drop'       => self::GRAMMAR_TRUST,
            'signal'         => self::GRAMMAR_TRUST,
            'status'         => self::GRAMMAR_SOCIAL,
            'watch_batch'    => self::GRAMMAR_SOCIAL,
            'pull_batch'     => self::GRAMMAR_SOCIAL,
            'blog_excerpt'   => self::GRAMMAR_SOCIAL,
            'announcement'   => self::GRAMMAR_NONE,
        ];

        public static function grammarFor(string $postKind): string
        {
            return self::POST_KIND_TO_GRAMMAR[$postKind] ?? self::GRAMMAR_SOCIAL;
        }

        /**
         * ⚠ FAITHFUL TO PRODUCTION: returns an OBJECT for an empty dict, so a
         * test that encodes the result sees `{}` and not `[]`. A double that
         * returned the array unchanged would make the wire-shape assertions
         * pass against a shape production does not emit.
         *
         * @param  array<string, int> $counts
         * @return array<string, int>|\stdClass
         */
        public static function countsForWire(array $counts)
        {
            return $counts === [] ? new \stdClass() : $counts;
        }

        public static function grammarForModule(string $moduleId): string
        {
            $postKind = FeedItemNormalizer::MODULE_TO_KIND[$moduleId] ?? 'status';
            return self::grammarFor($postKind);
        }
    }
}

namespace BCC\Core\Repositories {

    class PeepSoActivityRepository
    {
        public static function getModuleIdByActId(int $actId): ?string
        {
            $GLOBALS['__bcc_nr_module_lookups'] = ($GLOBALS['__bcc_nr_module_lookups'] ?? 0) + 1;

            $map = $GLOBALS['__bcc_nr_module_by_act'] ?? [];
            return $map[$actId] ?? null;
        }
    }
}

namespace {

    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            /** @var mixed */
            public $data;
            public int $status;

            /** @param mixed $data */
            public function __construct($data = null, int $status = 200)
            {
                $this->data   = $data;
                $this->status = $status;
            }

            /** @return mixed */
            public function get_data()
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }
        }
    }
}
