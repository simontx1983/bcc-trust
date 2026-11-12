<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\SuspensionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-146: archiving old closed suspensions is idempotent.
 *
 * archiveOldClosed() appended " [ARCHIVED]" with no guard, so every admin
 * cleanup run added another marker to every old closed suspension (the
 * notes grew forever), counted every old row every time, and never marked
 * a NULL note. Real UPDATE on the real suspensions table.
 */
#[Group('integration')]
#[Group('mariadb')]
final class SuspensionArchiveIdempotentIntegrationTest extends TestCase
{
    private const USERS = [991301, 991302, 991303, 991304, 991305];

    protected function setUp(): void
    {
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testArchivingMarksEachOldClosedSuspensionExactlyOnce(): void
    {
        $old    = gmdate('Y-m-d H:i:s', time() - 200 * 86400);
        $recent = gmdate('Y-m-d H:i:s', time() - 2 * 86400);

        $plain   = $this->insert(991301, 'spam ring', $old);
        $bloated = $this->insert(991302, 'appeal denied [ARCHIVED] [ARCHIVED] [ARCHIVED]', $old);
        $null    = $this->insert(991303, null, $old);
        $fresh   = $this->insert(991304, 'recent', $recent);
        $active  = $this->insert(991305, 'still active', null);

        $repo = new SuspensionRepository();
        self::assertSame(3, $repo->archiveOldClosed(90), 'the plain, the bloated and the NULL note');

        self::assertSame('spam ring [ARCHIVED]', $this->notes($plain));
        self::assertSame('appeal denied [ARCHIVED]', $this->notes($bloated), 'old-bug bloat normalised to one marker, text kept');
        self::assertSame(' [ARCHIVED]', $this->notes($null), 'a NULL note is marked too');
        self::assertSame('recent', $this->notes($fresh), 'closed too recently: untouched');
        self::assertSame('still active', $this->notes($active), 'active: untouched');

        // ⚠ The whole point: running it again changes and counts nothing.
        self::assertSame(0, $repo->archiveOldClosed(90));
        self::assertSame('spam ring [ARCHIVED]', $this->notes($plain));
        self::assertSame('appeal denied [ARCHIVED]', $this->notes($bloated));
    }

    /**
     * The "already archived?" guard is what lets a bounded run make
     * progress. Without it, every run's LIMIT batch would be spent
     * re-touching the same already-archived rows and a row past them would
     * never be reached.
     */
    public function testAnUnarchivedRowBehindAFullBatchOfArchivedOnesIsStillReached(): void
    {
        global $wpdb;
        $old   = gmdate('Y-m-d H:i:s', time() - 200 * 86400);
        $table = TableRegistry::suspensions();

        $values = [];
        for ($i = 0; $i < 1000; $i++) {
            $values[] = $wpdb->prepare('(%d, 1, %s, %s, %s, %s)', 991301, 'manual_suspension', 'done [ARCHIVED]', $old, $old);
        }
        self::assertNotFalse($wpdb->query(
            "INSERT INTO `{$table}` (user_id, suspended_by, reason, notes, suspended_at, unsuspended_at) VALUES " . implode(',', $values)
        ), $wpdb->last_error);
        $behind = $this->insert(991302, 'behind the batch', $old);

        self::assertSame(1, (new SuspensionRepository())->archiveOldClosed(90));
        self::assertSame('behind the batch [ARCHIVED]', $this->notes($behind));
    }

    private function insert(int $userId, ?string $notes, ?string $unsuspendedAt): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(TableRegistry::suspensions(), [
            'user_id'        => $userId,
            'suspended_by'   => 1,
            'reason'         => 'manual_suspension',
            'notes'          => $notes,
            'suspended_at'   => gmdate('Y-m-d H:i:s', time() - 300 * 86400),
            'unsuspended_at' => $unsuspendedAt,
        ]), $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    private function notes(int $id): ?string
    {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare('SELECT notes FROM `' . TableRegistry::suspensions() . '` WHERE id = %d', $id));
        return $v === null ? null : (string) $v;
    }

    private function cleanup(): void
    {
        global $wpdb;
        $wpdb->query('DELETE FROM `' . TableRegistry::suspensions() . '` WHERE user_id IN (' . implode(',', self::USERS) . ')');
    }
}
