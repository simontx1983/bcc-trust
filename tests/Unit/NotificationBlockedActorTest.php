<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\NotificationRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-296 — a blocked member's old bell rows stay hidden, and the block read
 * fails CLOSED.
 *
 * Blocking someone left their earlier notifications in the bell list,
 * counted toward the unread badge, and sent in the weekly digest. All three
 * reads now exclude `not_from_user_id` on the other side of a block (either
 * direction) in SQL, before LIMIT. The block ids are read through
 * GuardsReadFailures::runRead: a failed read THROWS (bell/badge → 503, the
 * digest skips the user) instead of reading as "no blocks". Pinned on the
 * statements the REAL repository prepares.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NotificationBlockedActorTest extends TestCase
{
    /**
     * @var object{
     *   prefix: string, last_error: string, last_result: mixed,
     *   blocked: list<int>, blockers: list<int>, failOn: ?string,
     *   prepared: list<array{0: string, 1: list<mixed>}>, mainRuns: int
     * }
     */
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/notification-block-stubs.php';
        if (!defined('BCC_NOTIFICATION_MODULE_ID')) {
            define('BCC_NOTIFICATION_MODULE_ID', 9001);
        }
        $this->wpdb = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            /** @var mixed */
            public $last_result = [];
            /** @var list<int> */
            public array $blocked = [];
            /** @var list<int> */
            public array $blockers = [];
            /** 'blocked' | 'blockers' | null — which block read fails */
            public ?string $failOn = null;
            /** @var list<array{0: string, 1: list<mixed>}> */
            public array $prepared = [];
            public int $mainRuns = 0;

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                $this->prepared[] = [$sql, $args];
                return $sql;
            }

            /** @return int|false */
            public function query(string $sql)
            {
                $which = str_contains($sql, 'WHERE blk_user_id') ? 'blocked' : 'blockers';
                if ($this->failOn === $which) {
                    $this->last_error = 'MySQL server has gone away';
                    return false;
                }
                $this->last_error  = '';
                $this->last_result = array_map(static fn (int $id): object => (object) ['uid' => (string) $id], $this->{$which});
                return count($this->last_result);
            }

            /** @return list<object> */
            public function get_results(string $sql): array
            {
                $this->mainRuns++;
                return [];
            }

            public function get_var(string $sql): string
            {
                $this->mainRuns++;
                return '0';
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function reads(): array
    {
        return [
            'bell list, first page' => ['first'],
            'bell list, next page'  => ['next'],
            'unread badge'          => ['count'],
            'weekly digest'         => ['digest'],
        ];
    }

    private static function read(string $which): void
    {
        $r = new NotificationRepository();
        match ($which) {
            'first'  => $r->findForUser(7, 20, 0),
            'next'   => $r->findForUser(7, 20, 500),
            'count'  => $r->countUnreadForUser(7),
            'digest' => $r->findUnreadSince(7, '2026-11-05 00:00:00', 25),
        };
    }

    /** @return array{0: string, 1: list<mixed>} the notification statement (after the two block reads) */
    private function mainStatement(): array
    {
        self::assertCount(3, $this->wpdb->prepared, 'two block reads, then the notification read');
        return $this->wpdb->prepared[2];
    }

    #[DataProvider('reads')]
    public function testBlockedMembersInEitherDirectionAreExcludedBeforeLimit(string $which): void
    {
        $this->wpdb->blocked  = [41, 42];
        $this->wpdb->blockers = [42, 43, 0];

        self::read($which);

        [$sql, $args] = $this->mainStatement();
        self::assertStringContainsString(' AND `not_from_user_id` NOT IN (%d,%d,%d)', $sql, 'deduped, user 0 (system rows) never excluded');
        if (str_contains($sql, 'LIMIT')) {
            self::assertLessThan(strpos($sql, 'LIMIT'), strpos($sql, 'NOT IN'), 'in SQL before LIMIT: no short pages');
        }
        $typeCount = substr_count((string) substr($sql, 0, (int) strpos($sql, 'NOT IN')), '%s');
        $offset    = substr_count((string) substr($sql, 0, (int) strpos($sql, '`not_type`')), '%d');
        self::assertSame([41, 42, 43], array_slice($args, $offset + $typeCount, 3));
        self::assertSame(substr_count($sql, '%d') + substr_count($sql, '%s'), count($args), 'one argument per placeholder');

        // Both directions are bounded reads.
        self::assertStringContainsString('WHERE blk_user_id = %d LIMIT %d', $this->wpdb->prepared[0][0]);
        self::assertStringContainsString('WHERE blk_blocked_id = %d LIMIT %d', $this->wpdb->prepared[1][0]);
        self::assertSame([7, 500], $this->wpdb->prepared[0][1]);
        self::assertSame([7, 500], $this->wpdb->prepared[1][1]);
    }

    #[DataProvider('reads')]
    public function testNoBlocksLeavesTheStatementAsItWas(string $which): void
    {
        self::read($which);

        [$sql, $args] = $this->mainStatement();
        self::assertStringNotContainsString('`not_from_user_id` NOT IN', $sql);
        self::assertSame(substr_count($sql, '%d') + substr_count($sql, '%s'), count($args));
    }

    /** @return array<string, array{string, string}> */
    public static function failures(): array
    {
        $out = [];
        foreach (['first', 'next', 'count', 'digest'] as $read) {
            foreach (['blocked', 'blockers'] as $direction) {
                $out["{$read}, {$direction} read fails"] = [$read, $direction];
            }
        }
        return $out;
    }

    #[DataProvider('failures')]
    public function testAFailedBlockReadThrowsAndNeverReadsAsNoBlocks(string $which, string $direction): void
    {
        $this->wpdb->blocked  = [41];
        $this->wpdb->blockers = [43];
        $this->wpdb->failOn   = $direction;

        try {
            self::read($which);
            self::fail('a failed block read must not read as "no blocks"');
        } catch (RepositoryReadFailure) {
        }

        self::assertSame(0, $this->wpdb->mainRuns, 'the notification read never runs unfiltered');
    }

    public function testTheBellAndBadgeAnswer503AndTheDigestSkipsTheUser(): void
    {
        $root  = dirname(__DIR__, 2);
        $flat  = static fn (string $f): string => (string) preg_replace('/\s+/', ' ', (string) file_get_contents($root . '/' . $f));
        $bell  = $flat('app/Domain/Core/REST/NotificationsEndpoint.php');
        $badge = $flat('app/Domain/Core/REST/MeBadgesEndpoint.php');
        $mail  = $flat('app/Domain/Core/Services/DigestService.php');

        self::assertSame(2, substr_count($bell, 'catch (\BCC\Trust\Onchain\Repositories\RepositoryReadFailure) {'), 'list and unread count');
        self::assertStringContainsString("ApiResponse::error('bcc_unavailable', 'Notifications are temporarily unavailable.', 503)", $bell);
        self::assertStringContainsString("catch (\BCC\Trust\Onchain\Repositories\RepositoryReadFailure) { // F-296", $badge);
        self::assertStringContainsString("ApiResponse::error('bcc_unavailable', 'Badges are temporarily unavailable.', 503)", $badge);
        // The digest's per-user catch: a throw counts the user as failed and
        // mails nothing; the next run retries.
        self::assertStringContainsString('$result = $this->dispatchToUser($userId, $since, $settings[\'max_items\']); } catch (\Throwable $e) {', $mail);
        self::assertStringContainsString('$result = self::RESULT_FAILED;', $mail);
    }
}
