<?php

declare(strict_types=1);

/**
 * OwnerPagesSyncTest doubles: the Plugin's resolver / score repository /
 * read-model repository, the durable dirty queue, get_posts and wp_cache_set,
 * all recording into OwnerPagesWorld. Load only in a separate process,
 * before PageReadModelSync / PageOwnerResolver are autoloaded.
 */

namespace {
    final class OwnerPagesWorld
    {
        public static int $bonusPage = 0;
        /** @var list<int> */
        public static array $ownedPages = [];
        /** @var list<int> */
        public static array $flagged = [];
        /** @var list<int> */
        public static array $synced = [];
        /** @var list<int> */
        public static array $enqueued = [];
        /** @var list<int> limits getOwnedPageIds() was called with */
        public static array $ownedLimits = [];
        /** @var list<array<string, mixed>> */
        public static array $getPostsArgs = [];
        public static int $scoresPageForOwner = 0;
    }

    if (!function_exists('wp_cache_set')) {
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            return true;
        }
    }

    if (!function_exists('get_posts')) {
        /** @param array<string, mixed> $args @return list<int> */
        function get_posts(array $args = []): array
        {
            OwnerPagesWorld::$getPostsArgs[] = $args;
            return [];
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\PageReadModelRepository', false)) {
        final class PageReadModelRepository
        {
            public static function enqueueDirty(int $pageId): void
            {
                \OwnerPagesWorld::$enqueued[] = $pageId;
            }

            public function syncPage(int $pageId): void
            {
                \OwnerPagesWorld::$synced[] = $pageId;
            }
        }
    }
}

namespace BCC\Trust\Core {
    if (!class_exists(__NAMESPACE__ . '\\Plugin', false)) {
        final class Plugin
        {
            public static function instance(): self
            {
                return new self();
            }

            public function pageOwnerResolver(): object
            {
                return new class {
                    public function getPageForOwner(int $userId): int
                    {
                        return \OwnerPagesWorld::$bonusPage;
                    }
                };
            }

            public function scoreRepository(): object
            {
                return new class {
                    public function flagForRecalculation(int $pageId): void
                    {
                        \OwnerPagesWorld::$flagged[] = $pageId;
                    }

                    /** @return list<int> */
                    public function getOwnedPageIds(int $ownerId, int $limit): array
                    {
                        \OwnerPagesWorld::$ownedLimits[] = $limit;
                        return \OwnerPagesWorld::$ownedPages;
                    }

                    public function getPageIdForOwner(int $userId): int
                    {
                        return \OwnerPagesWorld::$scoresPageForOwner;
                    }
                };
            }

            public function pageReadModelRepository(): \BCC\Trust\Core\Repositories\PageReadModelRepository
            {
                return new \BCC\Trust\Core\Repositories\PageReadModelRepository();
            }
        }
    }
}
