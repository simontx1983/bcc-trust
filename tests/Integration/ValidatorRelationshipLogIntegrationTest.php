<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Repositories\ValidatorRelationshipLogRepository as Log;
use BCC\Trust\Onchain\Services\RelationshipLogVerifier;
use BCC\Trust\Onchain\Support\RelationshipLogAnchor;
use BCC\Trust\Onchain\Support\RelationshipLogHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The validator relationship log on a real engine: appends chain under the
 * named lock and commit or roll back with the caller's transaction; every
 * row read back reproduces its hash; and the verifier reports the FIRST row
 * that was tampered with, removed or forged, without repairing anything.
 *
 * Separate processes: the test defines BCC_ENCRYPTION_KEY, which must not
 * leak into other tests.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(Log::class)]
#[CoversClass(RelationshipLogVerifier::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatorRelationshipLogIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BCC_ENCRYPTION_KEY')) {
            define('BCC_ENCRYPTION_KEY', 'integration-test-key-not-a-secret');
        }
        $this->wipe();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->clearFaultInjection();
        $wpdb->query("SELECT RELEASE_LOCK('" . Log::LOCK_NAME . "')");
        $this->wipe();
        parent::tearDown();
    }

    private function wipe(): void
    {
        global $wpdb;
        $wpdb->query('DELETE FROM `' . Log::table() . '`');
        delete_option(RelationshipLogAnchor::OPTION);
    }

    /** @return array<string, mixed> */
    private static function event(string $action, int $pageId, ?string $note = null): array
    {
        return [
            'action'          => $action,
            'page_id'         => $pageId,
            'subject_user_id' => 7001,
            'actor_kind'      => 'staff',
            'actor_user_id'   => 7002,
            'payload'         => ['new' => ['user_id' => 7001, 'method' => 'staff'], 'prev' => null, 'reason' => null],
            'evidence_note'   => $note,
            'evidence_ref'    => $note === null ? null : 'https://example.org/proof',
        ];
    }

    /** @param list<array<string, mixed>> $events */
    private function appendCommitted(array $events): void
    {
        global $wpdb;
        self::assertSame('1', (string) $wpdb->get_var("SELECT GET_LOCK('" . Log::LOCK_NAME . "', 5)"));
        $wpdb->query('START TRANSACTION');
        foreach ($events as $event) {
            Log::append($event);
        }
        $wpdb->query('COMMIT');
        $wpdb->query("SELECT RELEASE_LOCK('" . Log::LOCK_NAME . "')");
    }

    public function testAppendsChainAndEveryRowReproducesItsHash(): void
    {
        $this->appendCommitted([
            self::event('owner_linked', 501, 'Confirmed on the operator website.'),
            self::event('owner_revoked', 501),
            self::event('team_invited', 502),
        ]);

        $rows = Log::rowsAfter(0, 10);
        self::assertSame([1, 2, 3], array_map(static fn (object $r): int => (int) $r->seq, $rows));
        self::assertSame(RelationshipLogHasher::GENESIS, $rows[0]->prev_hash);
        self::assertSame($rows[0]->row_hash, $rows[1]->prev_hash);
        self::assertSame($rows[1]->row_hash, $rows[2]->prev_hash);
        foreach ($rows as $row) {
            self::assertSame($row->row_hash, RelationshipLogHasher::rowHash(Log::hashInput($row), $row->prev_hash), 'seq ' . $row->seq);
        }
        self::assertSame(hash('sha256', 'Confirmed on the operator website.'), $rows[0]->evidence_note_sha256);
        self::assertSame(['seq' => 3, 'row_hash' => $rows[2]->row_hash], Log::head());

        self::assertSame(
            ['ok' => true, 'rows_checked' => 3, 'head_seq' => 3, 'first_bad_seq' => null, 'reason' => null],
            RelationshipLogVerifier::verify()
        );
    }

    public function testAnAppendWithoutTheLockIsRefusedAndWritesNothing(): void
    {
        try {
            Log::append(self::event('owner_linked', 501));
            self::fail('an append without the lock must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(Log::LOCK_NAME, $e->getMessage());
        }
        self::assertSame(['seq' => 0, 'row_hash' => RelationshipLogHasher::GENESIS], Log::head());
    }

    public function testTheLogRowRollsBackWithTheCallersTransaction(): void
    {
        global $wpdb;
        $this->appendCommitted([self::event('owner_linked', 501)]);
        $head = Log::head();

        $wpdb->get_var("SELECT GET_LOCK('" . Log::LOCK_NAME . "', 5)");
        $wpdb->query('START TRANSACTION');
        Log::append(self::event('owner_revoked', 501));
        $wpdb->query('ROLLBACK');
        $wpdb->query("SELECT RELEASE_LOCK('" . Log::LOCK_NAME . "')");

        self::assertSame($head, Log::head(), 'the rolled-back row is gone and the chain is unchanged');
        self::assertTrue(RelationshipLogVerifier::verify()['ok']);
    }

    public function testATamperedRowIsTheFirstBadSeq(): void
    {
        global $wpdb;
        $this->appendCommitted([self::event('owner_linked', 501), self::event('owner_revoked', 501), self::event('owner_linked', 502)]);

        $wpdb->query('UPDATE `' . Log::table() . "` SET action = 'owner_link_cancelled' WHERE seq = 2");

        $result = RelationshipLogVerifier::verify();
        self::assertFalse($result['ok']);
        self::assertSame(2, $result['first_bad_seq']);
        self::assertSame('row_hash_mismatch', $result['reason']);
        self::assertSame(1, $result['rows_checked']);
    }

    public function testARewrittenLinkIsCaught(): void
    {
        global $wpdb;
        $this->appendCommitted([self::event('owner_linked', 501), self::event('owner_revoked', 501), self::event('owner_linked', 502)]);

        $wpdb->query('UPDATE `' . Log::table() . "` SET prev_hash = '" . str_repeat('a', 64) . "' WHERE seq = 3");

        $result = RelationshipLogVerifier::verify();
        self::assertSame([3, 'prev_hash_mismatch'], [$result['first_bad_seq'], $result['reason']]);
    }

    public function testARemovedRowIsASeqGap(): void
    {
        global $wpdb;
        $this->appendCommitted([self::event('owner_linked', 501), self::event('owner_revoked', 501), self::event('owner_linked', 502)]);

        $wpdb->query('DELETE FROM `' . Log::table() . '` WHERE seq = 2');

        $result = RelationshipLogVerifier::verify();
        self::assertSame([3, 'seq_gap'], [$result['first_bad_seq'], $result['reason']]);
    }

    public function testEvidenceTextMustMatchItsDigestButMayBeCleared(): void
    {
        global $wpdb;
        $this->appendCommitted([self::event('owner_linked', 501, 'original note'), self::event('owner_linked', 502, 'second note')]);

        $wpdb->query('UPDATE `' . Log::table() . '` SET evidence_note = NULL WHERE seq = 1');
        self::assertTrue(RelationshipLogVerifier::verify()['ok'], 'a cleared text keeps its digest: still verifies');

        $wpdb->query('UPDATE `' . Log::table() . "` SET evidence_note = 'edited note' WHERE seq = 2");
        $result = RelationshipLogVerifier::verify();
        self::assertSame([2, 'evidence_mismatch'], [$result['first_bad_seq'], $result['reason']]);
    }

    public function testAFailedHeadReadRefusesTheAppend(): void
    {
        global $wpdb;
        $wpdb->get_var("SELECT GET_LOCK('" . Log::LOCK_NAME . "', 5)");
        $wpdb->failQueriesMatching = '/SELECT seq, row_hash FROM/';

        $this->expectException(RepositoryReadFailure::class);
        Log::append(self::event('owner_linked', 501));
    }

    public function testAFailedReadIsNotAHealthyChain(): void
    {
        global $wpdb;
        $this->appendCommitted([self::event('owner_linked', 501)]);
        $wpdb->failQueriesMatching = '/' . preg_quote(Log::table(), '/') . '/';

        $result = RelationshipLogVerifier::verify();
        self::assertFalse($result['ok']);
        self::assertSame('read_failed', $result['reason']);
    }

    /**
     * Rows deleted from the END, or a table emptied, still walk cleanly. The
     * anchor kept outside the table catches both.
     */
    public function testATruncatedTailIsCaughtByTheAnchor(): void
    {
        global $wpdb;
        $this->appendCommitted([self::event('owner_linked', 501), self::event('owner_revoked', 501), self::event('owner_linked', 502)]);
        $head = Log::head();
        RelationshipLogAnchor::advance($head['seq'], $head['row_hash']);
        self::assertTrue(RelationshipLogVerifier::verify()['ok'], 'control: intact and anchored');

        $wpdb->query('DELETE FROM `' . Log::table() . '` WHERE seq = 3');
        $result = RelationshipLogVerifier::verify();
        self::assertSame([false, 3, 'tail_missing'], [$result['ok'], $result['first_bad_seq'], $result['reason']]);

        $wpdb->query('DELETE FROM `' . Log::table() . '`'); // the table only: the anchor stays
        $result = RelationshipLogVerifier::verify();
        self::assertSame([false, 1, 'tail_missing'], [$result['ok'], $result['first_bad_seq'], $result['reason']], 'an emptied table is not a healthy chain');
    }

    /** A tail re-forged with the key chains cleanly but no longer matches the anchor. */
    public function testAReForgedTailIsCaughtByTheAnchor(): void
    {
        global $wpdb;
        $this->appendCommitted([self::event('owner_linked', 501), self::event('owner_revoked', 501)]);
        $head = Log::head();
        RelationshipLogAnchor::advance($head['seq'], $head['row_hash']);

        $wpdb->query('DELETE FROM `' . Log::table() . '` WHERE seq = 2');
        $this->appendCommitted([self::event('owner_link_cancelled', 501)]);

        $result = RelationshipLogVerifier::verify();
        self::assertSame([2, 'anchor_mismatch'], [$result['first_bad_seq'], $result['reason']]);
    }

    public function testTheAnchorNeverMovesBack(): void
    {
        RelationshipLogAnchor::advance(5, str_repeat('a', 64));
        RelationshipLogAnchor::advance(3, str_repeat('b', 64));
        RelationshipLogAnchor::advance(0, str_repeat('c', 64));

        self::assertSame(['seq' => 5, 'row_hash' => str_repeat('a', 64)], RelationshipLogAnchor::get());
    }

    public function testATamperedKeyIdIsAnUnreadableRowNotACrash(): void
    {
        global $wpdb;
        $this->appendCommitted([self::event('owner_linked', 501), self::event('owner_revoked', 501)]);

        $wpdb->query('UPDATE `' . Log::table() . '` SET key_id = 9 WHERE seq = 2');

        $result = RelationshipLogVerifier::verify();
        self::assertSame([2, 'unreadable_row'], [$result['first_bad_seq'], $result['reason']]);
    }

    public function testInvalidUtf8EvidenceIsRefused(): void
    {
        global $wpdb;
        $wpdb->get_var("SELECT GET_LOCK('" . Log::LOCK_NAME . "', 5)");

        $this->expectException(\InvalidArgumentException::class);
        Log::append(self::event('owner_linked', 501, "bad \xC3\x28 bytes"));
    }

    public function testHistoryIsNewestFirstFilteredAndPaged(): void
    {
        $this->appendCommitted([self::event('owner_linked', 501), self::event('owner_revoked', 501), self::event('owner_linked', 502)]);

        self::assertSame([3, 2, 1], array_map(static fn (object $r): int => (int) $r->seq, Log::history(null, null, null, 10)));
        self::assertSame([2, 1], array_map(static fn (object $r): int => (int) $r->seq, Log::history(501, null, null, 10)));
        self::assertSame([1], array_map(static fn (object $r): int => (int) $r->seq, Log::history(501, 7002, 2, 10)));
        self::assertSame([], Log::history(null, 999999, null, 10));
    }

    public function testAnEventThatDoesNotFitIsRefusedBeforeAnythingIsWritten(): void
    {
        global $wpdb;
        $wpdb->get_var("SELECT GET_LOCK('" . Log::LOCK_NAME . "', 5)");
        foreach ([
            ['action' => 'Owner Linked'],
            ['action' => str_repeat('a', 41)],
            ['actor_kind' => 'robot'],
            ['evidence_note' => str_repeat('x', 2001)],
            ['payload' => ['ratio' => 0.5]],
        ] as $override) {
            try {
                Log::append($override + self::event('owner_linked', 501));
                self::fail('must be refused: ' . json_encode($override));
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame(0, Log::head()['seq']);
    }
}
