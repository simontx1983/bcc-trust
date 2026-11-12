<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\NotificationRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-296 — a blocked member's old bell rows stay hidden.
 *
 * Blocking someone left their earlier notifications in the bell list,
 * counted toward the unread badge, and sent in the weekly digest. All three
 * reads now exclude `not_from_user_id` on the other side of a block (either
 * direction) in SQL, before LIMIT. Pinned on the statements the REAL
 * repository prepares.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NotificationBlockedActorTest extends TestCase
{
    /** @var object{prefix: string, prepared: list<array{0: string, 1: list<mixed>}>} */
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/notification-block-stubs.php';
        $GLOBALS['__bcc_nb'] = ['blocked' => [], 'blockers' => []];
        $this->wpdb = new class {
            public string $prefix = 'wp_';
            /** @var list<array{0: string, 1: list<mixed>}> */
            public array $prepared = [];

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                $this->prepared[] = [$sql, $args];
                return $sql;
            }

            /** @return list<object> */
            public function get_results(string $sql): array
            {
                return [];
            }

            public function get_var(string $sql): string
            {
                return '0';
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
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

    #[DataProvider('reads')]
    public function testBlockedMembersInEitherDirectionAreExcludedBeforeLimit(string $which): void
    {
        $GLOBALS['__bcc_nb'] = ['blocked' => [41, 42], 'blockers' => [42, 43, 0]];

        self::read($which);

        self::assertCount(1, $this->wpdb->prepared);
        [$sql, $args] = $this->wpdb->prepared[0];
        self::assertStringContainsString(' AND `not_from_user_id` NOT IN (%d,%d,%d)', $sql, 'deduped, user 0 (system rows) never excluded');
        if (str_contains($sql, 'LIMIT')) {
            self::assertLessThan(strpos($sql, 'LIMIT'), strpos($sql, 'NOT IN'), 'in SQL before LIMIT: no short pages');
        }
        // The ids land right after the type list, in placeholder order.
        $typeCount = substr_count((string) substr($sql, 0, (int) strpos($sql, 'NOT IN')), '%s');
        $offset    = substr_count((string) substr($sql, 0, (int) strpos($sql, '`not_type`')), '%d');
        self::assertSame([41, 42, 43], array_slice($args, $offset + $typeCount, 3));
        self::assertSame(substr_count($sql, '%d') + substr_count($sql, '%s'), count($args), 'one argument per placeholder');
    }

    #[DataProvider('reads')]
    public function testNoBlocksLeavesTheStatementAsItWas(string $which): void
    {
        self::read($which);

        [$sql, $args] = $this->wpdb->prepared[0];
        self::assertStringNotContainsString('`not_from_user_id` NOT IN', $sql);
        self::assertSame(substr_count($sql, '%d') + substr_count($sql, '%s'), count($args));
    }
}
