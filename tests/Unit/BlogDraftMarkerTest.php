<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\BlogChainTagRepository;
use BCC\Trust\Core\Services\PostsService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Audit HIGH #1 regression — blog draft lifecycle.
 *
 * A blog post created as `status=draft` must get the
 * `_bcc_activity_module='blog'` marker AT CREATE TIME. Both the edit-read
 * gate (BlogService::getBlogForEdit) and the update gate
 * (PostsService::updateBlog) require that marker; before the fix it was
 * written only on publish (via ActivityStreamWriter::handleBlogPostCreated),
 * so a never-published draft was orphaned — unreadable for editing and
 * unpublishable through the API. Feed surfacing must stay publish-only: it
 * is driven by the peepso_activities row (the `bcc_blog_post_created`
 * event), not by the marker, so a marked draft must NOT dispatch that event.
 *
 * ## Isolation
 * Runs in its own subprocess; setUp() pulls in tests/Stubs/blog-create-stubs.php
 * which fakes the WP functions + the three bcc-core statics createBlog reaches
 * (Throttle, PeepSoStatusWriter, DB) + a no-op $wpdb, so createBlog runs to
 * completion without WordPress, PeepSo, bcc-core, or a real DB.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BlogDraftMarkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/blog-create-stubs.php';
        $GLOBALS['__bcc_blog_meta_writes'] = [];
        $GLOBALS['__bcc_blog_actions']     = [];
    }

    /**
     * createBlog uses none of VoteService/VoteRepository/FeatureAccessService,
     * so skip the constructor and inject only the chain-tag repo (also built
     * without its $wpdb-touching constructor; replace([]) is a bounded no-op
     * against the faked $wpdb).
     */
    private function service(): PostsService
    {
        $svc  = (new ReflectionClass(PostsService::class))->newInstanceWithoutConstructor();
        $repo = (new ReflectionClass(BlogChainTagRepository::class))->newInstanceWithoutConstructor();

        $prop = (new ReflectionClass(PostsService::class))->getProperty('blogChainTagRepository');
        $prop->setAccessible(true);
        $prop->setValue($svc, $repo);

        return $svc;
    }

    /** Positional createBlog args with every optional empty. */
    private function args(string $status): array
    {
        // 90-char excerpt clears BLOG_EXCERPT_MIN_LENGTH (80); non-empty body.
        return [
            7,                        // authorId
            str_repeat('a', 90),      // excerpt
            'Body text goes here.',   // fullText
            'Title',                  // title
            null,                     // category
            [],                       // tags
            [],                       // chainIds
            null,                     // disclosure
            null,                     // coverImageId
            $status,                  // status
            [],                       // sources
        ];
    }

    /** @return list<array{0:int,1:string,2:mixed}> */
    private function metaWrites(): array
    {
        return $GLOBALS['__bcc_blog_meta_writes'] ?? [];
    }

    /** @return list<string> */
    private function actionHooks(): array
    {
        return array_map(
            static fn(array $entry): string => $entry[0],
            $GLOBALS['__bcc_blog_actions'] ?? []
        );
    }

    public function testDraftWritesActivityModuleMarkerAtCreateTime(): void
    {
        $result = $this->service()->createBlog(...$this->args('draft'));

        self::assertTrue($result['ok'] ?? false, 'createBlog(draft) should succeed');

        $markerWrites = array_values(array_filter(
            $this->metaWrites(),
            static fn(array $w): bool => $w[1] === '_bcc_activity_module'
        ));
        self::assertCount(1, $markerWrites, 'a draft must get the marker at create time');
        self::assertSame([123, '_bcc_activity_module', 'blog'], $markerWrites[0]);
    }

    public function testDraftDoesNotSurfaceInFeed(): void
    {
        $this->service()->createBlog(...$this->args('draft'));

        self::assertNotContains(
            'bcc_blog_post_created',
            $this->actionHooks(),
            'a draft must NOT dispatch the feed-surfacing event'
        );
    }

    public function testPublishWritesMarkerAndSurfaces(): void
    {
        $this->service()->createBlog(...$this->args('publish'));

        $markerWrites = array_filter(
            $this->metaWrites(),
            static fn(array $w): bool => $w[1] === '_bcc_activity_module'
        );
        self::assertNotEmpty($markerWrites, 'publish must also write the marker');
        self::assertContains(
            'bcc_blog_post_created',
            $this->actionHooks(),
            'publish must dispatch the feed-surfacing event'
        );
    }
}
