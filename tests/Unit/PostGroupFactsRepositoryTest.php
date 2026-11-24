<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\PostGroupFactsRepository;
use BCC\Trust\Tests\Support\PostGroupFactsWpdb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-461: a post's group facts, read so that a failed read is a refusal.
 *
 * ⚠⚠ Read straight from postmeta, through ProbesReads, never
 * get_post_meta(): a read that cannot be trusted is null, never "no group".
 * The gates read here.
 *
 * ⚠⚠ AND THE ROWS MUST BE THIS QUERY'S. wpdb::query() can bail BEFORE
 * flush() (a failed reconnect, an emptied statement), returning false with
 * no last_error while last_result still holds the PREVIOUS query's rows.
 * The repository reads through bcc-core's ProbesReads::trustedRows(), which
 * checks query()'s own return, and that case is pinned below.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(PostGroupFactsRepository::class)]
final class PostGroupFactsRepositoryTest extends TestCase
{
    private const POST = 6001;

    private PostGroupFactsWpdb $wpdb;

    /** @var array<int, array<string, mixed>> the world's get_post_meta() fixture */
    private static array $meta = [];

    protected function setUp(): void
    {
        self::$meta = [];
        $this->wpdb = new PostGroupFactsWpdb(
            static fn (int $postId, string $key): mixed => self::$meta[$postId][$key] ?? ''
        );
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    /** @param list<array{0: string, 1: string}> $rows */
    private function database(array $rows): void
    {
        $this->wpdb->dbRows[self::POST] = array_map(
            static fn (array $r): object => PostGroupFactsWpdb::row($r[0], $r[1]),
            $rows
        );
    }

    public function testTheFactsAreBuiltFromThePostsRows(): void
    {
        $this->database([
            ['peepso_group_id', '7'],
            ['_bcc_post_visibility', 'members_only'],
            ['peepso_group_id', '12'],
            ['_bcc_post_group_privacy_at_post', '1'],
            // ⚠ A LATER DUPLICATE, and the STRICTEST wins — not the first.
            // Here they coincide ('members_only' is both), so this case cannot
            // tell the two rules apart; the pair below is what does.
            ['_bcc_post_visibility', 'public_all'],
        ]);

        self::assertSame([
            'group_raw'       => '7',
            'group_ids'       => [7, 12],
            'visibility'      => 'members_only',
            'privacy_at_post' => '1',
        ], PostGroupFactsRepository::forPost(self::POST));
    }

    public function testAPostWithNoGroupMetaIsAnswerNotAFailure(): void
    {
        $this->database([]);

        self::assertSame([
            'group_raw'       => '',
            'group_ids'       => [],
            'visibility'      => '',
            'privacy_at_post' => '',
        ], PostGroupFactsRepository::forPost(self::POST), 'a genuine "no rows" is an answer, never null');
    }

    /** A stored '0' stays raw, so each gate keeps its own strict reading of it. */
    public function testAStoredZeroGroupIsKeptRawButIsNotAGroupId(): void
    {
        $this->database([['peepso_group_id', '0']]);

        $facts = PostGroupFactsRepository::forPost(self::POST);
        self::assertNotNull($facts);
        self::assertSame('0', $facts['group_raw']);
        self::assertSame([], $facts['group_ids']);
    }

    public function testOneBoundedStatementOverTheThreeKeys(): void
    {
        $this->database([]);

        PostGroupFactsRepository::forPost(self::POST);

        self::assertSame(1, $this->wpdb->factsReads);
        $sql = $this->wpdb->lastSql;
        self::assertStringContainsString('FROM wp_postmeta', $sql);
        self::assertStringContainsString('ORDER BY meta_id ASC', $sql, 'first value by meta_id, as get_post_meta(…, true)');
        self::assertStringContainsString('LIMIT %d', $sql);
        self::assertStringNotContainsString('*', $sql);
        self::assertSame(
            [self::POST, 'peepso_group_id', '_bcc_post_visibility', '_bcc_post_group_privacy_at_post', 101],
            $this->wpdb->lastArgs
        );
    }

    /**
     * ⚠⚠ The pre-flush bail: query() returns false with NO last_error, and
     * last_result (and so get_results() / get_row()) still hold the
     * PREVIOUS query's rows, here another post's "ungrouped" meta. It must
     * be a refusal, never those rows.
     */
    public function testAPreFlushBailIsARefusalNotThePreviousQuerysRows(): void
    {
        $this->wpdb->last_result     = [PostGroupFactsWpdb::row('_bcc_post_visibility', 'public_all')];
        $this->wpdb->bailBeforeFlush = true;

        self::assertNull(PostGroupFactsRepository::forPost(self::POST));
        self::assertSame(1, $this->wpdb->factsReads, 'the read was attempted');
    }

    public function testAFailedReadIsARefusal(): void
    {
        $this->wpdb->failRead = true;

        self::assertNull(PostGroupFactsRepository::forPost(self::POST));
    }

    /** More rows than the bounded read can prove complete is a refusal, not a guess. */
    public function testMoreRowsThanTheCapAreARefusal(): void
    {
        $rows = array_fill(0, 101, ['peepso_group_id', '7']);
        $this->database($rows);
        self::assertNull(PostGroupFactsRepository::forPost(self::POST));

        $this->database(array_slice($rows, 0, 100));
        self::assertNotNull(PostGroupFactsRepository::forPost(self::POST), 'exactly the cap is complete');
    }

    public function testANonPositivePostIdIsARefusalWithoutARead(): void
    {
        self::assertNull(PostGroupFactsRepository::forPost(0));
        self::assertNull(PostGroupFactsRepository::forPost(-3));
        self::assertSame(0, $this->wpdb->factsReads);
    }

    /** By default the double answers from the world's get_post_meta() fixture (what every gate test relies on). */
    public function testTheDoubleAnswersFromTheMetaFixtureByDefault(): void
    {
        self::$meta[self::POST] = ['peepso_group_id' => '9', '_bcc_post_visibility' => 'public_group'];

        $facts = PostGroupFactsRepository::forPost(self::POST);
        self::assertNotNull($facts);
        self::assertSame('9', $facts['group_raw']);
        self::assertSame('public_group', $facts['visibility']);
    }
    // ── duplicate rows fold STRICTEST-WINS (F-461 security LOW) ──────────

    /**
     * ⛔ THE BUG, in the order that exposes it. `$visibility ??= $value` kept
     * the FIRST row by meta_id, so a stray leading `public_all` published a
     * post every later row says is members-only.
     *
     * `public_all` is the PERMISSIVE value: it is the one that lets a
     * group-tagged post syndicate OUTSIDE its group.
     *
     * ⚠ This is the mirror of `testTheFactsAreBuiltFromThePostsRows`, which
     * has the same two values the other way round. That one passes under
     * EITHER rule; only this one separates them.
     */
    public function testAPermissiveVisibilityFirstDoesNotWin(): void
    {
        $this->database([
            ['peepso_group_id', '7'],
            ['_bcc_post_visibility', 'public_all'],
            ['_bcc_post_visibility', 'members_only'],
        ]);

        $facts = PostGroupFactsRepository::forPost(self::POST);

        self::assertNotSame(
            'public_all',
            $facts['visibility'] ?? null,
            'one disagreeing row withholds public_all, whatever the meta_id order'
        );
        self::assertSame('members_only', $facts['visibility'] ?? null);
    }

    /** And it IS public_all when every row says so — the fold is not a blanket refusal. */
    public function testVisibilityIsPublicAllOnlyWhenEveryRowSaysSo(): void
    {
        $this->database([
            ['peepso_group_id', '7'],
            ['_bcc_post_visibility', 'public_all'],
            ['_bcc_post_visibility', 'public_all'],
        ]);

        self::assertSame('public_all', PostGroupFactsRepository::forPost(self::POST)['visibility']);
    }

    /**
     * ⛔ The same bug on privacy-at-post, where THE PERMISSIVE ANSWER IS
     * "NON-OPEN" — which reads backwards until you follow it through.
     *
     * A post recorded as made while its group was ALREADY closed or secret is
     * a DELIBERATE syndication, so `GroupSyndicationGate::allows()` admits it
     * early and keeps it public after the group closes. A stray leading '1'
     * therefore kept a post public that every later row says was made while
     * the group was open.
     */
    public function testAPermissiveNonOpenPrivacyFirstDoesNotWin(): void
    {
        $this->database([
            ['peepso_group_id', '7'],
            ['_bcc_post_group_privacy_at_post', '1'],
            ['_bcc_post_group_privacy_at_post', '0'],
        ]);

        $facts = PostGroupFactsRepository::forPost(self::POST);

        self::assertNotContains(
            $facts['privacy_at_post'] ?? null,
            ['1', '2'],
            'one open row withholds the made-while-non-open record'
        );
        self::assertSame('0', $facts['privacy_at_post'] ?? null);
    }

    /** Non-open survives when every row agrees, including a mix of 1 and 2. */
    public function testPrivacyAtPostStaysNonOpenWhenEveryRowAgrees(): void
    {
        $this->database([
            ['peepso_group_id', '7'],
            ['_bcc_post_group_privacy_at_post', '1'],
            ['_bcc_post_group_privacy_at_post', '2'],
        ]);

        self::assertContains(
            PostGroupFactsRepository::forPost(self::POST)['privacy_at_post'],
            ['1', '2'],
            'closed and secret both mean non-open, so agreeing rows keep the record'
        );
    }

    /**
     * ⚠ A single row is unchanged by the fold. Worth pinning: duplicate rows
     * should not exist at all — `attachToGroup()` is the single writer — so
     * the ordinary one-row case is what runs in production, and a fold that
     * quietly altered it would be a regression nothing else would catch.
     */
    public function testTheOrdinarySingleRowCaseIsUntouched(): void
    {
        $this->database([
            ['peepso_group_id', '7'],
            ['_bcc_post_visibility', 'public_all'],
            ['_bcc_post_group_privacy_at_post', '1'],
        ]);

        self::assertSame([
            'group_raw'       => '7',
            'group_ids'       => [7],
            'visibility'      => 'public_all',
            'privacy_at_post' => '1',
        ], PostGroupFactsRepository::forPost(self::POST));
    }
}
