<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\PostsService;
use BCC\Trust\Tests\Support\PostGroupFactsWpdb;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-520: a PUBLISHED blog's group is fixed, and PostsService::updateBlog()
 * reads that group through PostGroupFactsRepository, never get_post_meta(),
 * and only when the request sends `group_id`. A read that cannot be trusted
 * refuses the edit with the retryable 503 before anything is written.
 *
 * In this world get_post_meta() says the post has NO group, while the
 * database (PostGroupFactsWpdb::$dbRows) says it is in group 7: the edit
 * must follow the database.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BlogPublishedGroupFactsTest extends TestCase
{
    private const AUTHOR    = 7;
    private const POST      = 3201;
    private const LIVE      = 7;
    private const OTHER     = 9;

    private PostGroupFactsWpdb $wpdb;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/blog-ownership-stubs.php';

        $post              = new \WP_Post();
        $post->ID          = self::POST;
        $post->post_type   = 'peepso-post';
        $post->post_status = 'publish';
        $post->post_author = (string) self::AUTHOR;

        $GLOBALS['__bcc_blog_posts'] = [self::POST => $post];
        // get_post_meta(): a blog, and NO group.
        $GLOBALS['__bcc_blog_meta']  = [self::POST => ['_bcc_activity_module' => 'blog']];

        $this->wpdb = new PostGroupFactsWpdb(
            static fn (int $postId, string $key): mixed => $GLOBALS['__bcc_blog_meta'][$postId][$key] ?? ''
        );
        // The database: the post is in group 7.
        $this->wpdb->dbRows[self::POST] = [
            PostGroupFactsWpdb::row('peepso_group_id', (string) self::LIVE),
            PostGroupFactsWpdb::row('_bcc_post_visibility', 'members_only'),
        ];
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    /**
     * The update with `group_id` sent (or absent when null). Anything past
     * the group check reaches code this world cannot host, which throws:
     * that is "the group check let it through", reported as null.
     *
     * @return array<string, mixed>|null
     */
    private static function update(?int $groupId, string $status = 'publish'): ?array
    {
        $GLOBALS['__bcc_blog_posts'][self::POST]->post_status = $status;
        $service = (new ReflectionClass(PostsService::class))->newInstanceWithoutConstructor();
        try {
            /** @var array<string, mixed> $out */
            $out = $service->updateBlog(self::POST, self::AUTHOR, 'New title', null, null, null, null, null, null, null, null, null, $groupId, null);
            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    public function testEchoingTheDatabasesGroupIsANoOpNotARefusal(): void
    {
        $out = self::update(self::LIVE);

        self::assertNotSame('bcc_invalid_request', $out['error'] ?? null, 'the group the database holds is not a move');
        self::assertSame(1, $this->wpdb->factsReads);
    }

    public function testMovingAPublishedBlogIsRefusedWithTheDatabasesGroup(): void
    {
        $out = self::update(self::OTHER);

        self::assertNotNull($out);
        self::assertSame('bcc_invalid_request', $out['error']);
        self::assertSame('A published blog post cannot be moved to another group or out of its group.', $out['message']);
        self::assertSame(['group_id' => self::LIVE], $out['data']);
    }

    public function testTakingAPublishedBlogOutOfItsGroupIsRefused(): void
    {
        $out = self::update(0);

        self::assertNotNull($out, 'a published group blog cannot be taken out of its group');
        self::assertSame('bcc_invalid_request', $out['error']);
        self::assertSame(['group_id' => self::LIVE], $out['data']);
    }

    /** Unreadable: the retryable 503, before anything is written. */
    public function testAnUnreadableGroupRefusesTheEditRetryably(): void
    {
        $this->wpdb->failRead = true;

        self::assertSame(
            ['error' => 'bcc_unavailable', 'message' => "We couldn't check this post's group just now. Please try again in a moment."],
            self::update(self::LIVE)
        );
    }

    /** The group is read only where it decides something. */
    public function testTheGroupIsNotReadWhenItDecidesNothing(): void
    {
        self::update(null);
        self::assertSame(0, $this->wpdb->factsReads, 'no group_id sent');

        self::update(self::OTHER, 'draft');
        self::assertSame(0, $this->wpdb->factsReads, 'a draft re-targets its parked group, not its live one');
    }
}
