<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Rank\Repositories\RankStateRepository;
use BCC\Trust\Rank\Repositories\TierDaysRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-13: the keyset reads behind the two daily rank sweeps, against real
 * MySQL / MariaDB.
 *
 * Both reads page by ascending id after a cursor; the sweeps now persist that
 * cursor across runs. They used get_col(), which returns [] on a DB error —
 * and an empty page reads as "no more users", ending the pass. They now read
 * through runRead() and throw instead.
 *
 * The suite uses ids ≥ 920000 and deletes only those.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(RankStateRepository::class)]
#[CoversClass(TierDaysRepository::class)]
final class RankSweepKeysetIntegrationTest extends TestCase
{
    private const BASE = 920000;

    /** Inserted out of order on purpose. */
    private const IDS = [50, 10, 30, 20, 40];

    protected function setUp(): void
    {
        $this->cleanUp();
        global $wpdb;
        foreach (self::IDS as $n) {
            $id = self::BASE + $n;
            $ok = $wpdb->insert($wpdb->users, ['ID' => $id, 'user_login' => 'f13u' . $id, 'user_email' => 'f13u' . $id . '@example.invalid']);
            self::assertNotFalse($ok, 'user fixture: ' . $wpdb->last_error);
            $ok = $wpdb->insert(TableRegistry::rankState(), [
                'user_id'               => $id,
                'rank_slug'             => 'apprentice',
                'apprentice_awarded_at' => gmdate('Y-m-d H:i:s'),
                'updated_at'            => gmdate('Y-m-d H:i:s'),
            ]);
            self::assertNotFalse($ok, 'rank_state fixture: ' . $wpdb->last_error);
        }
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->clearFaultInjection();
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->users} WHERE ID >= %d AND ID < %d", self::BASE, self::BASE + 1000));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . TableRegistry::rankState() . ' WHERE user_id >= %d AND user_id < %d', self::BASE, self::BASE + 1000));
    }

    /** @return list<int> */
    private static function ids(int ...$n): array
    {
        return array_map(static fn (int $x): int => self::BASE + $x, $n);
    }

    /** @return iterable<string, array{0: callable(int, int): list<int>, 1: string}> */
    private function readers(): iterable
    {
        $state = new RankStateRepository();
        $days  = new TierDaysRepository();
        yield 'rank_state' => [static fn (int $c, int $l): array => $state->listUserIdsAfter($c, $l), TableRegistry::rankState()];
        global $wpdb;
        yield 'users' => [static fn (int $c, int $l): array => $days->listUserIdsAfter($c, $l), $wpdb->users];
    }

    public function testBothReadsAreAscendingAndStrictlyAfterTheCursor(): void
    {
        foreach ($this->readers() as $name => [$read]) {
            self::assertSame(self::ids(10, 20, 30, 40, 50), $read(self::BASE, 100), $name . ': ascending');
            self::assertSame(self::ids(40, 50), $read(self::BASE + 30, 100), $name . ': the cursor id itself is not repeated');
            self::assertSame(self::ids(20, 30), $read(self::BASE + 10, 2), $name . ': the limit bounds the page');
            self::assertSame([], $read(self::BASE + 50, 100), $name . ': past the last id is an empty page, not an error');
        }
    }

    public function testPagingVisitsEveryIdExactlyOnce(): void
    {
        foreach ($this->readers() as $name => [$read]) {
            $seen   = [];
            $cursor = self::BASE;
            for ($i = 0; $i < 10; $i++) {
                $page = $read($cursor, 2);
                if ($page === []) {
                    break;
                }
                array_push($seen, ...$page);
                $cursor = end($page);
            }
            self::assertSame(self::ids(10, 20, 30, 40, 50), $seen, $name);
        }
    }

    public function testAFailedReadThrowsRatherThanReadingAsNoMoreUsers(): void
    {
        global $wpdb;
        foreach ($this->readers() as $name => [$read, $table]) {
            $wpdb->failQueriesMatching = '/' . preg_quote($table, '/') . '/';
            try {
                $read(self::BASE, 100);
                self::fail($name . ': a failed read must throw');
            } catch (RepositoryReadFailure) {
                // expected
            } finally {
                $wpdb->clearFaultInjection();
            }
        }
    }
}
