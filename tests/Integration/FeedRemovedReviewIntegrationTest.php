<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\UserMiniRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\Feed\FeedHydrationPipeline;
use BCC\Trust\Core\Services\Feed\ReviewBodyHydrator;
use BCC\Trust\Core\Services\MemberSelfPageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-88: a review whose vote is no longer active must not appear in any feed.
 *
 * ReviewBodyHydrator used VoteRepository::findManyByIds(), which had no
 * status filter, so a retracted (0), dispute-removed (2) or
 * moderation-removed (3) review kept rendering — with its text — in every
 * feed it had been projected into, forever (the activity row is never
 * withdrawn). The fix is read-time: findManyActiveByIds() returns active
 * votes only, and FeedHydrationPipeline drops a review item whose vote
 * resolved to no active row. Being read-time it covers every removal path
 * (including the bulk ones that fire no per-vote hook) and reverses itself
 * when a vote is restored or re-cast.
 *
 * Real MySQL for votes + postmeta (the activity → sidecar link) + users;
 * the real ReviewBodyHydrator; the real pipeline body stage. Review targets
 * are member self-pages so the page lookup needs no wp_posts fixture.
 *
 * ── Why separate processes
 * The pipeline's body stage calls _prime_post_caches(), which the shared
 * bootstrap does not define; it is declared here, per child process only.
 */
#[Group('integration')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(FeedHydrationPipeline::class)]
#[CoversClass(ReviewBodyHydrator::class)]
final class FeedRemovedReviewIntegrationTest extends TestCase
{
    private const REVIEWER = 9801;
    private const TARGET   = 9811;

    // votes.status values (VoteRepository::STATUS_* once bcc-trust #428 lands).
    private const RETRACTED          = 0;
    private const ACTIVE             = 1;
    private const DISPUTE_REMOVED    = 2;
    private const MODERATION_REMOVED = 3;

    protected function setUp(): void
    {
        if (!function_exists('_prime_post_caches')) {
            eval('function _prime_post_caches(array $ids, bool $updateTermCache = true, bool $updateMetaCache = true): void {}');
        }

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_votes`');
        $wpdb->query("DELETE FROM `{$wpdb->postmeta}` WHERE meta_key = '_bcc_activity_sidecar_id'");
    }

    /** One review: a vote row + the activity wp_post's sidecar meta. Returns the external_id. */
    private function review(int $externalId, int $status, int $categoryId): int
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
            'voter_user_id' => self::REVIEWER,
            'page_id'       => MemberSelfPageService::ID_BASE + self::TARGET,
            'category_id'   => $categoryId,
            'vote_type'     => 1,
            'weight'        => 1.0,
            'vested_weight' => 1.0,
            'explanation'   => "review text {$externalId}",
            'status'        => $status,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);

        update_post_meta($externalId, '_bcc_activity_sidecar_id', (string) $wpdb->insert_id);
        return $externalId;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function hydrateBodies(array $items): array
    {
        $ref      = new \ReflectionClass(FeedHydrationPipeline::class);
        $pipeline = $ref->newInstanceWithoutConstructor();

        // The body stage needs every per-kind loader present; only the
        // review loader receives input here, the rest return [] on empty.
        $blank = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $deps  = [
            'gifRepo'                => $blank(\BCC\Trust\Core\Repositories\GifRepository::class),
            'mentionOverlay'         => $blank(\BCC\Trust\Core\Services\Mentions\MentionOverlayService::class),
            'watchBatchBodyHydrator' => $blank(\BCC\Trust\Core\Services\Feed\WatchBatchBodyHydrator::class),
            'pageClaimBodyHydrator'  => $blank(\BCC\Trust\Core\Services\Feed\PageClaimBodyHydrator::class),
            'photoBodyHydrator'      => $blank(\BCC\Trust\Core\Services\Feed\PhotoBodyHydrator::class),
            'gifBodyHydrator'        => $blank(\BCC\Trust\Core\Services\Feed\GifBodyHydrator::class),
            'reviewBodyHydrator'     => new ReviewBodyHydrator(new VoteRepository(), new UserMiniRepository()),
        ];
        foreach ($deps as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($pipeline, $value);
        }

        $m = $ref->getMethod('hydrateBodies');
        $m->setAccessible(true);
        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke($pipeline, $items);
        return $out;
    }

    /** @return array<string, mixed> */
    private static function item(int $externalId): array
    {
        return ['post_kind' => 'review', 'external_id' => $externalId, 'body' => []];
    }

    public function testOnlyReviewsWithAnActiveVoteSurvive(): void
    {
        $active     = $this->review(980001, self::ACTIVE, 0);
        $retracted  = $this->review(980002, self::RETRACTED, 1);
        $disputed   = $this->review(980003, self::DISPUTE_REMOVED, 2);
        $moderated  = $this->review(980004, self::MODERATION_REMOVED, 3);

        $out = $this->hydrateBodies([
            self::item($active),
            self::item($retracted),
            self::item($disputed),
            self::item($moderated),
        ]);

        self::assertCount(1, $out, 'removed reviews must be dropped, not rendered');
        self::assertSame($active, $out[0]['external_id']);
        self::assertSame("review text {$active}", $out[0]['body']['text'], 'control: the active review is hydrated');
    }

    public function testTheRepositoryReturnsActiveVotesOnly(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $this->review(980011, self::ACTIVE, 0);
        $activeId = (int) $wpdb->insert_id;
        $this->review(980012, self::RETRACTED, 1);
        $removedId = (int) $wpdb->insert_id;

        $rows = (new VoteRepository())->findManyActiveByIds([$activeId, $removedId]);

        self::assertSame([$activeId], array_keys($rows));
    }

    public function testAReviewWithNoSidecarLinkIsLeftAsBefore(): void
    {
        // Out of scope for F-88: an activity whose sidecar meta is missing
        // never resolved to a vote at all. Its handling is unchanged.
        $out = $this->hydrateBodies([self::item(980099)]);

        self::assertCount(1, $out);
        self::assertSame([], $out[0]['body']);
    }
}
