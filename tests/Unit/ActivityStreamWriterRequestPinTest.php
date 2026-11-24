<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\Feed\ActivityStreamWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * F-361 — the request must be NEUTRALISED while ActivityStreamWriter
 * writes a backing post.
 *
 * ── THE HOLE ─────────────────────────────────────────────────────────
 *
 * Three PeepSo listeners sit on the CORE `wp_insert_post` action and read
 * the current request to decide what to stamp on whichever post is being
 * written, with no authorization check of their own:
 *
 *   PeepSoMoods::save_mood       peepso/classes/moods.php:141   int('mood')
 *   PeepSoLocation::insert_post  peepso/classes/location.php:248 value('location')
 *   PeepSo3_Anon::save_anon_op   peepso-anon anon.php:113       int('anon_id')
 *
 * ⚠ Neither of the two in PeepSo core filters by post type.
 * `PeepSoLocation::insert_post` has no check at all, and
 * `PeepSoMoods::save_mood` only looks like it does — it uses the return
 * value of `apply_filters('peepso_moods_apply_to_post_types', array(…))`
 * as a BOOLEAN rather than comparing it against the post's type, and a
 * non-empty array is always true. So "it is only a backing post" is not
 * protection.
 *
 * These handlers run inside the MEMBER'S OWN REQUEST — publishing a
 * review, claiming a page, closing a watch batch — so `$_GET`/`$_POST`
 * still carry whatever that member sent.
 *
 * ── WHY THIS SUITE EXISTS SEPARATELY ─────────────────────────────────
 *
 * ⚠⚠ `ActivityReplayGuardTest` and `ActivityBackingPostTypeTest` both
 * pass with the pin and passed without it: their cases never put anything
 * in the superglobals, so the guard runs against an already-empty
 * request and proves nothing. A passing suite is not evidence a new
 * guard works. These cases set the request first.
 *
 * ⭐ And the observation has to happen DURING the write. The guard
 * restores the real request in a `finally`, so a test that inspects
 * `$_POST` after the call sees the original either way. The stubs
 * snapshot the superglobals from inside `wp_insert_post` /
 * `wp_update_post`, which is the only moment the question has an answer.
 *
 * The REAL `PeepSoAddPostGuard` runs here — see the loader stub for why a
 * double would make every assertion below vacuous.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ActivityStreamWriterRequestPinTest extends TestCase
{
    /** A request shaped the way an attacker would shape it. */
    private const HOSTILE = [
        'mood'         => '7',
        'location'     => 'somewhere else',
        'anon_id'      => '41',
        'group_id'     => '900',
        'page_id'      => '55',
        'module_id'    => '8',
        'post_as_page' => '1',
        'type'         => 'photo',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wp/');
        }

        $GLOBALS['__bcc_pin'] = [
            'writes'      => [],
            'next_post'   => 8001,
            'insert_fail' => false,
            'throw_on'    => null,
            'meta'        => [],
        ];

        require_once __DIR__ . '/../Stubs/activity-request-pin-stubs.php';
        require_once __DIR__ . '/../Stubs/peepso-add-post-guard-real.php';

        $_GET     = self::HOSTILE;
        $_POST    = self::HOSTILE;
        $_REQUEST = self::HOSTILE;
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = $_REQUEST = [];
        unset($GLOBALS['__bcc_pin']);
        parent::tearDown();
    }

    private function writer(): ActivityStreamWriter
    {
        return new ActivityStreamWriter(
            new \BCC\Trust\Core\Repositories\WatchBatchRepository(),
            new \BCC\Trust\Core\Repositories\PeepSoActivityWriter()
        );
    }

    private function createBackingPost(int $authorId, string $moduleId, int $sidecarId): int
    {
        $m = new ReflectionMethod(ActivityStreamWriter::class, 'createBackingPost');
        $m->setAccessible(true);

        /** @var int $out */
        $out = $m->invoke($this->writer(), $authorId, $moduleId, $sidecarId);

        return $out;
    }

    /** @return array{fn: string, args: array<string, mixed>, get: array<string, mixed>, post: array<string, mixed>, request: array<string, mixed>} */
    private function writeNamed(string $fn): array
    {
        /** @var list<array{fn: string, args: array<string, mixed>, get: array<string, mixed>, post: array<string, mixed>, request: array<string, mixed>}> $writes */
        $writes = $GLOBALS['__bcc_pin']['writes'];

        foreach ($writes as $write) {
            if ($write['fn'] === $fn) {
                return $write;
            }
        }

        self::fail("no {$fn} call was recorded — the fixture did not reach the write");
    }

    // ── the insert ───────────────────────────────────────────────────

    /**
     * ⭐ THE CORE PROPERTY. Every key those listeners read is at its
     * neutral value at the moment the post is written, and the query
     * string — which `PeepSoInput` consults FIRST — is empty.
     *
     * @param int|string $neutral
     */
    #[DataProvider('pinnedKeyProvider')]
    public function testThePinnedKeysAreNeutralDuringTheInsert(string $key, $neutral): void
    {
        $this->createBackingPost(41, 'review', 77);

        $write = $this->writeNamed('wp_insert_post');

        self::assertSame([], $write['get'], '$_GET must be empty: PeepSoInput reads it first');
        self::assertArrayHasKey($key, $write['post'], "{$key} must be PINNED, not merely unset");
        self::assertSame($neutral, $write['post'][$key], "{$key} must be neutral during the write");
    }

    /**
     * ⚠ PINNED, NOT UNSET — the distinction is the whole defence.
     * `PeepSoInput::int()` falls back to the RAW REQUEST BODY when a key is
     * absent from `$_GET` and `$_POST`, so unsetting `mood` or `anon_id`
     * would OPEN the raw-body leg rather than close it. Only a present key
     * at a neutral value shuts that path.
     *
     * @return list<array{0: string, 1: int|string}>
     */
    public static function pinnedKeyProvider(): array
    {
        return [
            // read on wp_insert_post itself — the F-361 trio
            ['mood',         0],
            ['location',     ''],
            ['anon_id',      0],
            // the routing keys, which decide who may READ the post
            ['group_id',     0],
            ['page_id',      0],
            ['module_id',    0],
            ['post_as_page', 0],
            ['type',         ''],
        ];
    }

    /** $_REQUEST is cleared too, for anything reading it directly. */
    public function testTheMergedRequestArrayIsClearedDuringTheInsert(): void
    {
        $this->createBackingPost(41, 'review', 77);

        self::assertSame([], $this->writeNamed('wp_insert_post')['request']);
    }

    /**
     * ⚠ ANTI-VACUITY. Without this the suite above could pass against a
     * writer that simply never wrote anything: the fixture has to prove the
     * hostile request really was present, and really did reach the insert.
     */
    public function testTheHostileRequestIsRestoredAfterwardsAndWasPresentBefore(): void
    {
        self::assertSame('7', $_POST['mood'], 'precondition: the request is hostile going in');

        $postId = $this->createBackingPost(41, 'review', 77);

        self::assertSame(8001, $postId, 'the write still happened');
        self::assertSame(self::HOSTILE, $_GET, '$_GET restored');
        self::assertSame(self::HOSTILE, $_POST, '$_POST restored');
        self::assertSame(self::HOSTILE, $_REQUEST, '$_REQUEST restored');
    }

    /**
     * ⚠ The restore is in a `finally`, so it survives a write that throws.
     * Without that, one failed insert would leave the rest of the request
     * running against an emptied `$_POST` — a far worse outcome than the
     * bug being fixed.
     */
    public function testTheRequestIsRestoredEvenWhenTheWriteThrows(): void
    {
        $GLOBALS['__bcc_pin']['throw_on'] = 'wp_insert_post';

        try {
            $this->createBackingPost(41, 'review', 77);
            self::fail('the stub was supposed to throw');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame(self::HOSTILE, $_POST, '$_POST restored through the finally');
        self::assertSame(self::HOSTILE, $_GET, '$_GET restored through the finally');
    }

    /**
     * The pin must not change what is written. Same arguments as before,
     * and the module/sidecar meta still lands.
     */
    public function testThePinDoesNotChangeThePostOrItsMeta(): void
    {
        $postId = $this->createBackingPost(41, 'page_claim', 77);

        self::assertSame(
            [
                'post_type'    => 'peepso-post',
                'post_status'  => 'publish',
                'post_author'  => 41,
                'post_title'   => 'page_claim-41-77',
                'post_content' => '',
            ],
            $this->writeNamed('wp_insert_post')['args']
        );

        self::assertSame(
            [
                ['post' => $postId, 'key' => '_bcc_activity_module',     'value' => 'page_claim'],
                ['post' => $postId, 'key' => '_bcc_activity_sidecar_id', 'value' => 77],
            ],
            $GLOBALS['__bcc_pin']['meta']
        );
    }

    // ── the UPDATE, which is the easy site to miss ───────────────────

    /**
     * ⭐ `wp_update_post()` IS NOT A QUIETER WRITE. It is a thin wrapper
     * that calls `wp_insert_post()` (wp-includes/post.php), which fires the
     * `wp_insert_post` action at :5204 on an update exactly as on an
     * insert — so the same three listeners run. `publishReviewReply()`
     * writes the reply body through it, straight from a member's own
     * request, which makes this the second exposed site in this file and
     * the one a "bare wp_insert_post" sweep walks past.
     */
    public function testTheReviewReplyBodyUpdateIsPinnedToo(): void
    {
        $result = $this->writer()->publishReviewReply(41, 77, 'my reply');

        self::assertIsArray($result, 'the reply must still be written');

        $update = $this->writeNamed('wp_update_post');

        self::assertSame('my reply', $update['args']['post_content'], 'the body still goes in');
        self::assertSame([], $update['get'], '$_GET empty during the update');
        self::assertSame(0, $update['post']['mood'], 'mood neutral during the update');
        self::assertSame('', $update['post']['location'], 'location neutral during the update');
        self::assertSame(0, $update['post']['anon_id'], 'anon_id neutral during the update');
    }

    /**
     * ⚠ BOTH writes in that one call are pinned, not just the first. The
     * insert and the update are separate `pinOnly()` calls, so a fix that
     * wrapped only one would leave the other open — and the request is
     * restored in between, which is why each needs its own.
     */
    public function testBothWritesInAReviewReplyArePinned(): void
    {
        $this->writer()->publishReviewReply(41, 77, 'my reply');

        /** @var list<array{fn: string, post: array<string, mixed>}> $writes */
        $writes = $GLOBALS['__bcc_pin']['writes'];

        self::assertSame(
            ['wp_insert_post', 'wp_update_post'],
            array_map(static fn(array $w): string => $w['fn'], $writes),
            'both writes, in order'
        );

        foreach ($writes as $write) {
            self::assertSame(0, $write['post']['mood'], $write['fn'] . ' must be pinned');
        }
    }

    /**
     * A failed insert still answers 0 and writes no meta — the pin wraps
     * the call, it does not swallow its result.
     */
    public function testAFailedInsertStillAnswersZero(): void
    {
        $GLOBALS['__bcc_pin']['insert_fail'] = true;

        self::assertSame(0, $this->createBackingPost(41, 'review', 77));
        self::assertSame([], $GLOBALS['__bcc_pin']['meta'], 'no meta for a post that was not created');
        self::assertSame(self::HOSTILE, $_POST, 'and the request is still restored');
    }

    /**
     * TWO pinned writes in this file, and only two.
     *
     * The replay-guard `get_posts()` lookup runs OUTSIDE the pin, and that
     * is correct: it reads, it does not fire `wp_insert_post`, and pinning a
     * read would buy nothing. Asserted so that widening the pin over a whole
     * method later is a deliberate choice rather than drift — and so that
     * DROPPING one of the two is caught even though the other still passes
     * every behavioural case above.
     *
     * ⚠⚠ COUNTED BY TOKENS, NOT BY SUBSTRING, and that is not fussiness:
     * the substring version of this test FAILED at 3, because the
     * explanatory comment in `createBackingPost()` names
     * `PeepSoAddPostGuard::pinOnly()` too. A raw text count cannot tell a
     * call from a mention of one, so documenting the guard would break the
     * guard's own test — and the obvious "fix", deleting the reference from
     * the comment, trades the explanation for the check. `token_get_all()`
     * hands comments back as single T_COMMENT / T_DOC_COMMENT tokens, so a
     * walk over them counts real call sites only.
     *
     * ⚠ This is a SOURCE-TEXT assertion and cannot see behaviour. It is
     * worth having only because every other test in this file is
     * behavioural; it is the cardinality check those cannot make.
     */
    public function testExactlyTwoWritesAreWrapped(): void
    {
        $file   = (string) (new ReflectionClass(ActivityStreamWriter::class))->getFileName();
        $tokens = token_get_all((string) file_get_contents($file));

        $calls = 0;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'PeepSoAddPostGuard') {
                continue;
            }

            // ClassName :: pinOnly — skipping whitespace between the parts.
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_DOUBLE_COLON) {
                continue;
            }

            $j++;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j < $count && is_array($tokens[$j]) && $tokens[$j][1] === 'pinOnly') {
                $calls++;
            }
        }

        self::assertSame(
            2,
            $calls,
            'exactly two writes are wrapped: createBackingPost()\'s insert and'
            . ' publishReviewReply()\'s body update'
        );
    }
}
