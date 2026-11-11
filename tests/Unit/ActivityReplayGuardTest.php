<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * BEHAVIOURAL tests for the activity-post replay guard.
 *
 * Why this exists: `PagesEndpoint` now re-fires `bcc_page_claimed` when a
 * claim is retried against a page whose `post_author` is still 0, so the
 * ownership transfer that the first (failed) attempt never completed can
 * finish. That re-fire is only safe if every subscriber is replay-safe.
 * `ActivityStreamWriter` was NOT: `createBackingPost()` called
 * `wp_insert_post()` unconditionally, so a second delivery produced a second
 * backing post and a second `peepso_activities` row — one claim rendering as
 * two "claimed their page" items on the operator's wall.
 *
 * The guard identifies the event by STRUCTURED IDENTITY at both layers:
 *
 *   backing post — (post_author, _bcc_activity_module, _bcc_activity_sidecar_id),
 *                  where the sidecar is a real FK (claim id / vote id / batch id)
 *   activity row — (act_external_id, act_module_id) via findActIdForExternal()
 *
 * ⛔ The post_title is deliberately NOT the check, and
 * {@see testAPostWhoseTitleMatchesButWhoseSidecarDiffersIsNotReused} proves
 * it: a title is display text, nothing enforces its uniqueness, and two
 * genuinely different events must never collapse because their titles agreed.
 *
 * ⚠ Stubs are required inside setUp(), not at file scope: file-scope
 * declarations pollute the PHPUnit discovery parent, and this file declares
 * `wp_insert_post` at the same FQN as ActivityBackingPostTypeTest.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
final class ActivityReplayGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wp/');
        }

        $GLOBALS['__bcc_replay'] = [
            'posts'       => [],
            'next_post'   => 7001,
            'inserts'     => [],
            'acts'        => [],
            'next_act'    => 501,
            'act_inserts' => [],
            'act_lookups' => [],
            'claims'      => [],
            'logs'        => [],
        ];

        require_once __DIR__ . '/../Stubs/activity-replay-stubs.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_replay']);
        parent::tearDown();
    }

    /** Register a claim row so handlePageClaimed can resolve it. */
    private function seedClaim(int $userId, string $entityType, int $entityId, int $claimId): void
    {
        $GLOBALS['__bcc_replay']['claims'][$entityType . '|' . $entityId . '|' . $userId]
            = (object) ['id' => $claimId];
    }

    private function writer(): \BCC\Trust\Core\Services\Feed\ActivityStreamWriter
    {
        return new \BCC\Trust\Core\Services\Feed\ActivityStreamWriter(
            new \BCC\Trust\Core\Repositories\WatchBatchRepository(),
            new \BCC\Trust\Core\Repositories\PeepSoActivityWriter()
        );
    }

    /** Call the private backing-post helper directly. */
    private function createBackingPost(int $authorId, string $moduleId, int $sidecarId): int
    {
        $m = new ReflectionMethod(
            \BCC\Trust\Core\Services\Feed\ActivityStreamWriter::class,
            'createBackingPost'
        );
        $m->setAccessible(true);

        /** @var int $out */
        $out = $m->invoke($this->writer(), $authorId, $moduleId, $sidecarId);

        return $out;
    }

    // ---------------------------------------------------------------- retries

    public function testAFirstDeliveryWritesOnePostAndOneActivityRow(): void
    {
        $this->seedClaim(41, 'validator', 900, 77);

        $this->writer()->handlePageClaimed(41, 1234, 'validator', 900, 'operator');

        $this->assertCount(1, $GLOBALS['__bcc_replay']['inserts']);
        $this->assertCount(1, $GLOBALS['__bcc_replay']['act_inserts']);
        $this->assertSame(41, $GLOBALS['__bcc_replay']['act_inserts'][0]['actor']);
        $this->assertSame(41, $GLOBALS['__bcc_replay']['act_inserts'][0]['owner']);
        $this->assertSame('page_claim', $GLOBALS['__bcc_replay']['act_inserts'][0]['module']);
    }

    public function testARetriedClaimProducesExactlyOnePostAndOneActivityRow(): void
    {
        $this->seedClaim(41, 'validator', 900, 77);

        // Three deliveries of the same event — the retry path can fire the
        // hook again, and a cron/queue redelivery could fire it more.
        $this->writer()->handlePageClaimed(41, 1234, 'validator', 900, 'operator');
        $this->writer()->handlePageClaimed(41, 1234, 'validator', 900, 'operator');
        $this->writer()->handlePageClaimed(41, 1234, 'validator', 900, 'operator');

        $this->assertCount(
            1,
            $GLOBALS['__bcc_replay']['inserts'],
            'a replayed event must not mint a second backing post'
        );
        $this->assertCount(
            1,
            $GLOBALS['__bcc_replay']['act_inserts'],
            'a replayed event must not insert a second activity row'
        );
        $this->assertCount(1, $GLOBALS['__bcc_replay']['posts']);
    }

    public function testAReplayStillResolvesTheExistingActivityRowRatherThanFailing(): void
    {
        $this->seedClaim(41, 'validator', 900, 77);

        $this->writer()->handlePageClaimed(41, 1234, 'validator', 900, 'operator');
        $firstPostId = $GLOBALS['__bcc_replay']['act_inserts'][0]['post'];

        $GLOBALS['__bcc_replay']['logs'] = [];
        $this->writer()->handlePageClaimed(41, 1234, 'validator', 900, 'operator');

        // The replay must look the activity row up and succeed, not log a
        // failure: callers cannot tell a first delivery from a replay.
        $levels = array_column($GLOBALS['__bcc_replay']['logs'], 'level');
        $this->assertNotContains('error', $levels, 'a replay is not an error');
        $this->assertContains('info', $levels);

        // And it pointed at the SAME backing post.
        $lookups = $GLOBALS['__bcc_replay']['act_lookups'];
        $this->assertSame($firstPostId, end($lookups)['post']);
    }

    public function testAReplayReusesTheSamePostId(): void
    {
        $first  = $this->createBackingPost(41, 'page_claim', 77);
        $second = $this->createBackingPost(41, 'page_claim', 77);

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, $second, 'the same event resolves to the same backing post');
        $this->assertCount(1, $GLOBALS['__bcc_replay']['inserts']);
    }

    // -------------------------------------------------------- distinct events

    public function testADifferentClaimGetsItsOwnPostAndActivityRow(): void
    {
        $this->seedClaim(41, 'validator', 900, 77);
        $this->seedClaim(41, 'validator', 901, 78);

        $this->writer()->handlePageClaimed(41, 1234, 'validator', 900, 'operator');
        $this->writer()->handlePageClaimed(41, 1235, 'validator', 901, 'operator');

        $this->assertCount(
            2,
            $GLOBALS['__bcc_replay']['inserts'],
            'two different claims are two different events'
        );
        $this->assertCount(2, $GLOBALS['__bcc_replay']['act_inserts']);
    }

    public function testTheSameSidecarIdUnderADifferentModuleIsADifferentEvent(): void
    {
        // Claim 77 and vote 77 share an integer but are unrelated rows.
        $claim  = $this->createBackingPost(41, 'page_claim', 77);
        $review = $this->createBackingPost(41, 'review', 77);

        $this->assertNotSame($claim, $review, 'the module is part of the identity');
        $this->assertCount(2, $GLOBALS['__bcc_replay']['inserts']);
    }

    public function testTheSameEventForADifferentAuthorIsADifferentEvent(): void
    {
        $a = $this->createBackingPost(41, 'page_claim', 77);
        $b = $this->createBackingPost(42, 'page_claim', 77);

        $this->assertNotSame($a, $b, 'the author is part of the identity');
        $this->assertCount(2, $GLOBALS['__bcc_replay']['inserts']);
    }

    public function testEachDistinctEventKeepsItsOwnActivityRow(): void
    {
        $this->seedClaim(41, 'validator', 900, 77);
        $this->seedClaim(41, 'validator', 901, 78);

        // first, replay, second, replay of the second
        $this->writer()->handlePageClaimed(41, 1234, 'validator', 900, 'operator');
        $this->writer()->handlePageClaimed(41, 1234, 'validator', 900, 'operator');
        $this->writer()->handlePageClaimed(41, 1235, 'validator', 901, 'operator');
        $this->writer()->handlePageClaimed(41, 1235, 'validator', 901, 'operator');

        $this->assertCount(2, $GLOBALS['__bcc_replay']['inserts']);
        $this->assertCount(2, $GLOBALS['__bcc_replay']['act_inserts']);

        $posts = array_column($GLOBALS['__bcc_replay']['act_inserts'], 'post');
        $this->assertCount(2, array_unique($posts), 'each event points at its own backing post');
    }

    // ------------------------------------------------ the title is NOT the key

    public function testAPostWhoseTitleMatchesButWhoseSidecarDiffersIsNotReused(): void
    {
        // Seed a post carrying the EXACT title the next event will generate
        // ('page_claim-41-77') while its sidecar meta names a different claim.
        // Title matching alone would reuse it and silently attach the new
        // claim's activity to the wrong row.
        $GLOBALS['__bcc_replay']['posts'][6999] = [
            'author'  => 41,
            'module'  => 'page_claim',
            'sidecar' => '4242',
            'title'   => 'page_claim-41-77',
        ];

        $postId = $this->createBackingPost(41, 'page_claim', 77);

        $this->assertNotSame(6999, $postId, 'a title collision must not be read as the same event');
        $this->assertCount(1, $GLOBALS['__bcc_replay']['inserts']);
        $this->assertSame('page_claim-41-77', $GLOBALS['__bcc_replay']['inserts'][0]['title']);
    }

    public function testAPostWhoseMetaMatchesIsReusedEvenWhenItsTitleDiffers(): void
    {
        // The mirror of the case above: identity is the meta, so a row whose
        // title was never written (or was later edited) is still the same
        // event and must be reused.
        $GLOBALS['__bcc_replay']['posts'][6998] = [
            'author'  => 41,
            'module'  => 'page_claim',
            'sidecar' => '77',
            'title'   => 'something else entirely',
        ];

        $postId = $this->createBackingPost(41, 'page_claim', 77);

        $this->assertSame(6998, $postId);
        $this->assertSame([], $GLOBALS['__bcc_replay']['inserts'], 'nothing new was inserted');
    }

    // ---------------------------------------------------------------- controls

    public function testAnUnresolvableClaimWritesNothing(): void
    {
        // No seeded claim: the subscriber must warn and return.
        $this->writer()->handlePageClaimed(41, 1234, 'validator', 900, 'operator');

        $this->assertSame([], $GLOBALS['__bcc_replay']['inserts']);
        $this->assertSame([], $GLOBALS['__bcc_replay']['act_inserts']);
        $this->assertContains('warning', array_column($GLOBALS['__bcc_replay']['logs'], 'level'));
    }

    public function testTheGuardQueriesByBothMetaKeysAtOnce(): void
    {
        // Anti-vacuity: the stub's get_posts() refuses a lookup missing
        // either half of the identity, so if the production query ever lost
        // one key this would fall through and insert twice.
        $this->createBackingPost(41, 'page_claim', 77);
        $this->createBackingPost(41, 'page_claim', 77);

        $this->assertCount(
            1,
            $GLOBALS['__bcc_replay']['inserts'],
            'the lookup must carry module AND sidecar, or it matches nothing'
        );
    }
}
