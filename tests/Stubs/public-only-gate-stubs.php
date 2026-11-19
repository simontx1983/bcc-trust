<?php
/**
 * Doubles for RepostPublicOnlyGateTest — the F-346 "only public posts may be
 * reposted" predicate ({@see \BCC\Trust\Core\Services\FeedItemReportVisibility::isPublicToEveryMember()}).
 *
 * ⭐ WHAT IS REAL HERE, because that is the point of this file being small:
 * the predicate itself, `GroupSyndicationGate` and `ProfileAccess`'s rung
 * snapping all run for real. Only the I/O edges are doubled — post meta, the
 * non-open group list, and PeepSo's profile-accessibility reader.
 *
 * ⚠ Every guard passes `false` to `class_exists`. The default AUTOLOADS the
 * real class and then skips the double, so the suite would silently exercise
 * production collaborators it believed it had replaced.
 *
 * Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope: the `PeepSoGroupRepository` double replaces a REAL class name.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Stubs {
    final class PublicOnlyFakes
    {
        /** @var array<int, array<string, mixed>> post_id => meta key => value */
        public static array $postMeta = [];

        /** @var array<int, int|string|null> user_id => raw usr_profile_acc */
        public static array $profileAcc = [];

        /** @var list<int>|null the non-open group ids, or null for "cannot prove" */
        public static ?array $nonOpenGroups = [];

        public static bool $peepSoUserLoaded = true;

        public static function reset(): void
        {
            self::$postMeta         = [];
            self::$profileAcc       = [];
            self::$nonOpenGroups    = [];
            self::$peepSoUserLoaded = true;
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!function_exists(__NAMESPACE__ . '\\get_post_meta')) {
        /**
         * @return mixed the stored value, or [] / '' as WordPress would
         */
        function get_post_meta(int $postId, string $key, bool $single = false)
        {
            $all = \BCC\Trust\Tests\Stubs\PublicOnlyFakes::$postMeta[$postId] ?? [];
            if (!array_key_exists($key, $all)) {
                // WordPress: single asks for a scalar, multi for a list.
                return $single ? '' : [];
            }

            $value = $all[$key];

            // `peepso_group_id` is read in the MULTI-VALUE form by the
            // predicate, so a list must come back as a list.
            if (!$single) {
                return is_array($value) ? $value : [$value];
            }

            return is_array($value) ? ($value[0] ?? '') : $value;
        }
    }
}

namespace BCC\Trust\Core\Support {
    if (!function_exists(__NAMESPACE__ . '\\class_exists')) {
        /**
         * Lets a test say "PeepSo is not loaded" so the fail-closed leg of
         * ProfileAccess::canonicalProfileLevelFor() is reachable.
         *
         * @param string $class
         */
        function class_exists($class, bool $autoload = true): bool
        {
            if ($class === '\\PeepSoUser' || $class === 'PeepSoUser') {
                return \BCC\Trust\Tests\Stubs\PublicOnlyFakes::$peepSoUserLoaded;
            }

            return \class_exists($class, $autoload);
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\PeepSoGroupRepository', false)) {
        final class PeepSoGroupRepository
        {
            /**
             * ⚠ null means "could not prove a complete list", which the
             * syndication gate treats as the STRICT direction. The default
             * is `[]` (no group is non-open), the pre-F-23 shape.
             *
             * @return list<int>|null
             */
            public static function getNonOpenGroupIdsStrict(): ?array
            {
                return \BCC\Trust\Tests\Stubs\PublicOnlyFakes::$nonOpenGroups;
            }
        }
    }
}

namespace {
    if (!class_exists('PeepSoUser', false)) {
        class PeepSoUser
        {
            private function __construct(private int $userId)
            {
            }

            public static function get_instance(int $userId): self
            {
                return new self($userId);
            }

            /** @return mixed the RAW column value, unsnapped */
            public function get_profile_accessibility()
            {
                return \BCC\Trust\Tests\Stubs\PublicOnlyFakes::$profileAcc[$this->userId] ?? 10;
            }
        }
    }
}
