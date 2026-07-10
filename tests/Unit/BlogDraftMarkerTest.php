<?php

declare(strict_types=1);

// ── Global WP shims + recorder ──────────────────────────────────────────────
namespace {

    if (!class_exists('BccPostsMetaRecorder', false)) {
        /** Captures every update_post_meta() call createBlog makes. */
        final class BccPostsMetaRecorder
        {
            /** @var list<array{0:int,1:string,2:mixed}> */
            public static array $calls = [];

            public static function reset(): void
            {
                self::$calls = [];
            }

            /** @return list<array{0:int,1:string,2:mixed}> */
            public static function forKey(string $key): array
            {
                return array_values(array_filter(self::$calls, static fn ($c) => $c[1] === $key));
            }
        }
    }

    if (!function_exists('update_post_meta')) {
        /**
         * @param mixed $metaValue
         * @param mixed $prev
         */
        function update_post_meta(int $postId, string $metaKey, $metaValue, $prev = ''): bool
        {
            \BccPostsMetaRecorder::$calls[] = [$postId, $metaKey, $metaValue];
            return true;
        }
    }

    if (!function_exists('wp_cache_incr')) {
        /** @return int|false */
        function wp_cache_incr(string $key, int $offset = 1, string $group = '')
        {
            return $offset;
        }
    }

    if (!function_exists('do_action')) {
        /** @param mixed ...$args */
        function do_action(string $hook, ...$args): void
        {
            // no-op: the test asserts createBlog's OWN marker write, not the
            // downstream handleBlogPostCreated side effect.
        }
    }

    if (!defined('BCC_TRUST_RATE_LIMIT_STATUS_POST')) {
        define('BCC_TRUST_RATE_LIMIT_STATUS_POST', 5);
    }
    if (!defined('BCC_TRUST_RATE_WINDOW_STATUS_POST')) {
        define('BCC_TRUST_RATE_WINDOW_STATUS_POST', 60);
    }
}

// ── Collaborator stubs at their production FQNs (guarded — first wins) ───────
namespace BCC\Core\DB {
    if (!class_exists(__NAMESPACE__ . '\\DB', false)) {
        final class DB
        {
            public static function table(string $name): string
            {
                return 'wp_bcc_' . $name;
            }
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(__NAMESPACE__ . '\\Throttle', false)) {
        final class Throttle
        {
            public static function allow(string $key, int $limit, int $window): bool
            {
                return true; // never rate-limit in the marker test
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string,mixed> $c */
            public static function info(string $m, array $c = []): void
            {
            }

            /** @param array<string,mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
            }

            /** @param array<string,mixed> $c */
            public static function error(string $m, array $c = []): void
            {
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(__NAMESPACE__ . '\\PeepSoStatusWriter', false)) {
        final class PeepSoStatusWriter
        {
            /** The post id every createBlog persistence call resolves to here. */
            public const FAKE_POST_ID = 555;

            public static function createSelfBlogPost(
                int $authorId,
                string $excerpt,
                string $fullText,
                ?string $title,
                string $status
            ): int {
                return self::FAKE_POST_ID;
            }
        }
    }
}

// ── The test ────────────────────────────────────────────────────────────────
namespace BCC\Trust\Core\Services\Tests {

    use BCC\Core\PeepSo\PeepSoStatusWriter;
    use BCC\Trust\Core\Repositories\BlogChainTagRepository;
    use BCC\Trust\Core\Services\PostsService;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;

    /**
     * Pins the §D6 blog discriminator write.
     *
     * Regression guard for the draft-orphan deadlock: `_bcc_activity_module`
     * must be stamped at CREATION for BOTH draft and publish, not only when a
     * post is published. If it reverts to publish-only, a draft carries no
     * marker and getBlogForEdit / updateBlog / BlogStatusTransitionHandler all
     * treat it as `bcc_not_found` — it can never be loaded, edited, or
     * published. This suite turns red the moment the write moves back inside
     * the publish branch.
     *
     * PostsService is built without its constructor (its vote/feature deps are
     * untouched on the blog path); only the chain-tag repo is reflected in.
     */
    #[CoversClass(PostsService::class)]
    final class BlogDraftMarkerTest extends TestCase
    {
        private const MODULE_META = '_bcc_activity_module';

        // ≥ BLOG_EXCERPT_MIN_LENGTH (80) and ≤ 500.
        private const VALID_EXCERPT =
            'This is a sufficiently long blog excerpt written purely to clear the eighty-character minimum-length gate.';
        private const VALID_BODY = 'The blog body text — non-empty and well under the cap.';

        protected function setUp(): void
        {
            global $wpdb;
            // BlogChainTagRepository::replace([]) issues one $wpdb->delete; the
            // insert loop is empty for a no-chains post.
            $wpdb = new class {
                public string $prefix = 'wp_';

                /**
                 * @param array<string,mixed> $where
                 * @param array<int,string>   $formats
                 */
                public function delete(string $table, array $where = [], array $formats = []): int
                {
                    return 1;
                }
            };
            \BccPostsMetaRecorder::reset();
        }

        protected function tearDown(): void
        {
            global $wpdb;
            $wpdb = null;
        }

        private function service(): PostsService
        {
            // Skip the constructor: vote/feature deps are never touched on the
            // blog-create path (only $this->chainTagRepo() is), so we set that
            // one readonly property and leave the rest uninitialized.
            $ref = new \ReflectionClass(PostsService::class);
            /** @var PostsService $svc */
            $svc = $ref->newInstanceWithoutConstructor();

            $repo = (new \ReflectionClass(BlogChainTagRepository::class))->newInstanceWithoutConstructor();
            $prop = $ref->getProperty('blogChainTagRepository');
            $prop->setAccessible(true);
            $prop->setValue($svc, $repo);

            return $svc;
        }

        /**
         * @return array<string,mixed>
         */
        private function createBlogWithStatus(string $status): array
        {
            return $this->service()->createBlog(
                7,                     // authorId
                self::VALID_EXCERPT,
                self::VALID_BODY,
                null,                  // title
                null,                  // category
                [],                    // tags
                [],                    // chainIds
                null,                  // disclosure
                null,                  // coverImageId
                $status,
                []                     // sources
            );
        }

        public function testDraftIsStampedWithBlogModuleMarkerAtCreation(): void
        {
            $result = $this->createBlogWithStatus('draft');

            self::assertTrue($result['ok'] ?? false, 'draft create should succeed');

            $markerWrites = \BccPostsMetaRecorder::forKey(self::MODULE_META);
            self::assertCount(1, $markerWrites, 'a draft must be stamped with the blog module marker');
            self::assertSame('blog', $markerWrites[0][2]);
            self::assertSame(PeepSoStatusWriter::FAKE_POST_ID, $markerWrites[0][0]);
        }

        public function testPublishIsAlsoStampedAtCreation(): void
        {
            // do_action is a no-op here, so this asserts createBlog itself
            // writes the marker — not the downstream publish handler.
            $result = $this->createBlogWithStatus('publish');

            self::assertTrue($result['ok'] ?? false, 'publish create should succeed');

            $markerWrites = \BccPostsMetaRecorder::forKey(self::MODULE_META);
            self::assertCount(1, $markerWrites, 'publish path also stamps the marker at creation');
            self::assertSame('blog', $markerWrites[0][2]);
        }
    }
}
