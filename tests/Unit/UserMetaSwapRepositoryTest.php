<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Core\Repositories\UserMetaRepository;
use BCC\Trust\Core\Repositories\UserMetaSwapRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * H7: UserMetaSwapRepository changes ONE user-meta value only if it is still
 * exactly what was read, over the $wpdb double that executes its three
 * guarded statements (tests/Stubs/token-state-wpdb-stubs.php).
 *
 * The statements themselves were run on MySQL 8 and MariaDB 11.8 with the
 * same expectations: an insert only when no row exists, an update or delete
 * only when the guard matches, NULL matched by the "empty or NULL" guard.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserMetaSwapRepositoryTest extends TestCase
{
    private const USER = 7;
    private const KEY  = 'bcc_gated_groups_optout';

    private \TokenStateWpdb $db;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/optout-map-stubs.php';
        $this->db = new \TokenStateWpdb();
        $GLOBALS['wpdb'] = $this->db;
        $GLOBALS['__bcc_optout_cache_deletes'] = [];
    }

    /** @return list<string|null> every stored value for the pair, oldest first */
    private function stored(): array
    {
        $out = [];
        foreach (\OptOutStore::rowsFor(self::USER, self::KEY) as $i) {
            $out[] = $this->db->rows[$i]['meta_value'];
        }
        return $out;
    }

    // ── no row read → INSERT … WHERE NOT EXISTS ─────────────────────────

    public function testWithNoRowItInserts(): void
    {
        self::assertTrue(UserMetaSwapRepository::swap(self::USER, self::KEY, null, '{"1":2}'));
        self::assertSame(['{"1":2}'], $this->stored());
    }

    public function testWithNoRowItDoesNotInsertBesideARowThatAppeared(): void
    {
        $this->db->put(self::USER, self::KEY, '{"9":9}');

        self::assertFalse(UserMetaSwapRepository::swap(self::USER, self::KEY, null, '{"1":2}'));
        self::assertSame(['{"9":9}'], $this->stored());
    }

    public function testNothingReadAndNothingToWriteIsNoQuery(): void
    {
        self::assertTrue(UserMetaSwapRepository::swap(self::USER, self::KEY, null, null));
        self::assertSame([], $this->db->queries);
    }

    // ── a value read → UPDATE / DELETE guarded by it ────────────────────

    public function testItUpdatesWhenTheValueIsStillAsRead(): void
    {
        $this->db->put(self::USER, self::KEY, '{"1":2}');

        self::assertTrue(UserMetaSwapRepository::swap(self::USER, self::KEY, '{"1":2}', '{"1":2,"3":4}'));
        self::assertSame(['{"1":2,"3":4}'], $this->stored());
        self::assertStringContainsString("meta_value <=> '{\\\"1\\\":2}'", (string) end($this->db->queries));
    }

    public function testItDoesNotUpdateAValueThatMovedOn(): void
    {
        $this->db->put(self::USER, self::KEY, '{"5":6}');

        self::assertFalse(UserMetaSwapRepository::swap(self::USER, self::KEY, '{"1":2}', '{"1":2,"3":4}'));
        self::assertSame(['{"5":6}'], $this->stored());
    }

    public function testItDeletesWhenTheValueIsStillAsRead(): void
    {
        $this->db->put(self::USER, self::KEY, '{"1":2}');

        self::assertTrue(UserMetaSwapRepository::swap(self::USER, self::KEY, '{"1":2}', null));
        self::assertSame([], $this->stored());
    }

    public function testItDoesNotDeleteAValueThatMovedOn(): void
    {
        $this->db->put(self::USER, self::KEY, '{"5":6}');

        self::assertFalse(UserMetaSwapRepository::swap(self::USER, self::KEY, '{"1":2}', null));
        self::assertSame(['{"5":6}'], $this->stored());
    }

    // ── '' may be NULL ───────────────────────────────────────────────────

    public function testAnEmptyReadMatchesAStoredNull(): void
    {
        $this->db->putNull(self::USER, self::KEY);

        self::assertTrue(UserMetaSwapRepository::swap(self::USER, self::KEY, '', '{"1":2}'));
        self::assertSame(['{"1":2}'], $this->stored());
        self::assertStringContainsString("(meta_value = '' OR meta_value IS NULL)", (string) end($this->db->queries));
    }

    public function testAnEmptyReadMatchesAStoredEmptyString(): void
    {
        $this->db->put(self::USER, self::KEY, '');

        self::assertTrue(UserMetaSwapRepository::swap(self::USER, self::KEY, '', null));
        self::assertSame([], $this->stored());
    }

    public function testAnEmptyReadIsStillGuarded(): void
    {
        $this->db->put(self::USER, self::KEY, '{"5":6}');

        self::assertFalse(UserMetaSwapRepository::swap(self::USER, self::KEY, '', '{"1":2}'));
        self::assertSame(['{"5":6}'], $this->stored(), 'an empty read is not "write unconditionally"');
    }

    public function testANonEmptyReadNeverMatchesANull(): void
    {
        $this->db->putNull(self::USER, self::KEY);

        self::assertFalse(UserMetaSwapRepository::swap(self::USER, self::KEY, '{"1":2}', '{"3":4}'));
        self::assertSame([null], $this->stored());
    }

    // ── caches ───────────────────────────────────────────────────────────

    public function testASwapThatLandsDropsTheMemoAndTheObjectCache(): void
    {
        $this->db->put(self::USER, self::KEY, '{"1":2}');
        UserMetaRepository::firstValuesOrUnknown([self::USER], [self::KEY]);

        self::assertTrue(UserMetaSwapRepository::swap(self::USER, self::KEY, '{"1":2}', '{"3":4}'));

        self::assertSame([[self::USER, 'user_meta']], $GLOBALS['__bcc_optout_cache_deletes']);
        self::assertSame(
            [self::USER => [self::KEY => '{"3":4}']],
            UserMetaRepository::firstValuesOrUnknown([self::USER], [self::KEY]),
            'the memo was dropped, so the next (memoised) read sees the new value'
        );
    }

    public function testASwapThatMissesTouchesNoCache(): void
    {
        $this->db->put(self::USER, self::KEY, '{"5":6}');

        UserMetaSwapRepository::swap(self::USER, self::KEY, '{"1":2}', '{"3":4}');

        self::assertSame([], $GLOBALS['__bcc_optout_cache_deletes']);
    }

    public function testAFailedWriteIsFalseAndTouchesNoCache(): void
    {
        $this->db->put(self::USER, self::KEY, '{"1":2}');
        $this->db->mode = 'error';

        self::assertFalse(UserMetaSwapRepository::swap(self::USER, self::KEY, '{"1":2}', '{"3":4}'));
        self::assertSame([], $GLOBALS['__bcc_optout_cache_deletes']);
    }

    public function testABadUserOrKeyIsRefusedWithoutAQuery(): void
    {
        self::assertFalse(UserMetaSwapRepository::swap(0, self::KEY, null, '{"1":2}'));
        self::assertFalse(UserMetaSwapRepository::swap(self::USER, '', null, '{"1":2}'));
        self::assertSame([], $this->db->queries);
    }
}
