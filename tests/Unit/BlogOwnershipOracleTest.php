<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\BlogService;
use BCC\Trust\Core\Services\PostsService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-47: loading or editing someone else's blog post must not confirm the id
 * exists.
 *
 * `GET /posts/:id` (BlogService::getBlogForEdit) and `PATCH /posts/:id`
 * (PostsService::updateBlog) are owner-only. They used to answer a non-author
 * with 403 "You can only edit your own blog posts." and an unknown id with
 * 404, so a 403 confirmed that the id was another member's blog post,
 * unpublished DRAFTS included. Both now answer a non-author with the exact
 * missing-id 404, and the author still loads their own post.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BlogOwnershipOracleTest extends TestCase
{
    private const AUTHOR   = 7;
    private const STRANGER = 8;
    private const DRAFT_ID = 3101;
    private const MISSING  = 3999;

    private const NOT_FOUND = ['error' => 'bcc_not_found', 'message' => 'Blog post not found.'];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/blog-ownership-stubs.php';

        $draft              = new \WP_Post();
        $draft->ID          = self::DRAFT_ID;
        $draft->post_type   = 'peepso-post';
        $draft->post_status = 'draft';
        $draft->post_author = (string) self::AUTHOR;

        $GLOBALS['__bcc_blog_posts'] = [self::DRAFT_ID => $draft];
        $GLOBALS['__bcc_blog_meta']  = [self::DRAFT_ID => ['_bcc_activity_module' => 'blog']];
    }

    private static function blogService(): BlogService
    {
        return (new ReflectionClass(BlogService::class))->newInstanceWithoutConstructor();
    }

    /** @return array<string, mixed> */
    private static function update(int $postId, int $authorId): array
    {
        $service = (new ReflectionClass(PostsService::class))->newInstanceWithoutConstructor();

        return $service->updateBlog($postId, $authorId, 'New title', null, null, null, null, null, null, null, null);
    }

    public function testLoadingSomeoneElsesDraftAnswersExactlyLikeAMissingId(): void
    {
        $missing = self::blogService()->getBlogForEdit(self::MISSING, self::STRANGER);
        $foreign = self::blogService()->getBlogForEdit(self::DRAFT_ID, self::STRANGER);

        self::assertSame(self::NOT_FOUND, $missing);
        self::assertSame($missing, $foreign, "another member's draft must be indistinguishable from a missing id");
    }

    public function testEditingSomeoneElsesDraftAnswersExactlyLikeAMissingId(): void
    {
        $missing = self::update(self::MISSING, self::STRANGER);
        $foreign = self::update(self::DRAFT_ID, self::STRANGER);

        self::assertSame(self::NOT_FOUND, $missing);
        self::assertSame($missing, $foreign);
    }

    /**
     * The gate still lets the author through: the author's own load passes
     * the ownership check and reaches hydration, which this harness does not
     * double. Any failure there is NOT the not-found answer.
     */
    public function testTheAuthorStillPassesTheOwnershipGate(): void
    {
        try {
            $result = self::blogService()->getBlogForEdit(self::DRAFT_ID, self::AUTHOR);
        } catch (\Throwable) {
            $result = null; // reached hydration, past the gate
        }

        self::assertNotSame(self::NOT_FOUND, $result);
    }
}
