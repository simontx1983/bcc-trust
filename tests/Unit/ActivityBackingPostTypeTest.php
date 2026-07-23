<?php

declare(strict_types=1);

/**
 * Regression pin for the feed backing-post CPT slug.
 *
 * wp_posts.post_type is varchar(20) and WP 6.7+ validates field
 * lengths strictly — the fabricated `peepso-activity-status` slug
 * (22 chars) made EVERY review/watch_batch/page_claim backing-post
 * insert fail silently (db_insert_error → createBackingPost returned
 * 0 → no feed row was ever written for those kinds). The canonical
 * slug is PeepSo's real `peepso-post` (11 chars); module
 * discrimination lives in the META_MODULE post_meta, mirroring
 * PeepSoStatusWriter::createSelfBlogPost.
 */

namespace BCC\Trust\Core\Services\Feed {
    if (!function_exists(__NAMESPACE__ . '\\wp_insert_post')) {
        /**
         * @param array<string, mixed> $args
         * @return int|object
         */
        function wp_insert_post(array $args, bool $wpError = false)
        {
            unset($wpError);
            $GLOBALS['bcc_test_insert_post_args'][] = $args;
            return 777;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\is_wp_error')) {
        /** @param mixed $thing */
        function is_wp_error($thing): bool
        {
            unset($thing);
            return false;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\update_post_meta')) {
        /**
         * @param int|string   $postId
         * @param string       $key
         * @param string|int   $value
         */
        function update_post_meta($postId, string $key, $value): bool
        {
            $GLOBALS['bcc_test_post_meta_writes'][] = [$postId, $key, $value];
            return true;
        }
    }
}

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Core\Services\Feed\ActivityStreamWriter;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionMethod;

    final class ActivityBackingPostTypeTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['bcc_test_insert_post_args']  = [];
            $GLOBALS['bcc_test_post_meta_writes'] = [];
        }

        protected function tearDown(): void
        {
            unset(
                $GLOBALS['bcc_test_insert_post_args'],
                $GLOBALS['bcc_test_post_meta_writes']
            );
        }

        private function createBackingPost(string $moduleId): int
        {
            $writer = (new ReflectionClass(ActivityStreamWriter::class))
                ->newInstanceWithoutConstructor();
            $m = new ReflectionMethod(ActivityStreamWriter::class, 'createBackingPost');
            $m->setAccessible(true);
            /** @var int $out */
            $out = $m->invoke($writer, 141, $moduleId, 41);
            return $out;
        }

        public function testBackingPostUsesCanonicalPeepsoPostType(): void
        {
            $postId = $this->createBackingPost('review');

            self::assertSame(777, $postId);
            self::assertCount(1, $GLOBALS['bcc_test_insert_post_args']);
            $args = $GLOBALS['bcc_test_insert_post_args'][0];
            self::assertSame('peepso-post', $args['post_type']);
        }

        public function testPostTypeFitsTheVarchar20Column(): void
        {
            $this->createBackingPost('watch_batch');
            $type = (string) $GLOBALS['bcc_test_insert_post_args'][0]['post_type'];
            self::assertLessThanOrEqual(
                20,
                strlen($type),
                'wp_posts.post_type is varchar(20); anything longer fails WP 6.7+ strict validation and kills the feed row'
            );
        }

        public function testModuleDiscriminatorStillWrittenToMeta(): void
        {
            $this->createBackingPost('review');
            $keys = array_map(
                static fn(array $w): string => $w[1],
                $GLOBALS['bcc_test_post_meta_writes']
            );
            self::assertContains('_bcc_activity_module', $keys);
        }
    }
}
