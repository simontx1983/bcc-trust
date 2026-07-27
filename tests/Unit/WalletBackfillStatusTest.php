<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The refactored wallet placeholder-email backfill's STATUS contract — it now
 * returns a migration status instead of self-completing. Proves the runner
 * can trust that status: empty salt / collisions / batch-cap → INCOMPLETE;
 * a clean drain → COMPLETE; genuine emails are never selected; a re-run over
 * already-migrated rows mutates nothing.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WalletBackfillStatusTest extends TestCase
{
    private const DOMAIN = '@noreply.bcc.local';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/wallet-backfill-stubs.php';
        require_once __DIR__ . '/../../includes/database/backfill-wallet-placeholder-emails.php';
        $GLOBALS['__bcc_salt']            = 'unit-salt';
        $GLOBALS['__bcc_get_users_pages'] = [];
        $GLOBALS['__bcc_get_users_args']  = [];
        $GLOBALS['__bcc_email_exists']    = null;
        $GLOBALS['__bcc_updates']         = [];
        $GLOBALS['__bcc_update_return']   = 1;
        $GLOBALS['__bcc_clean_cache']     = [];
    }

    /** Old-form (md5-ish) placeholder for a user — guaranteed != the hmac form. */
    private function md5Placeholder(int $id): string
    {
        return 'wallet-' . substr(md5('legacy' . $id), 0, 16) . self::DOMAIN;
    }

    /** @return \WP_User */
    private function user(int $id, string $email): \WP_User
    {
        return new \WP_User($id, $email);
    }

    // 9 — empty salt leaves the migration incomplete, touching nothing.
    public function testEmptySaltReturnsIncompleteAndWritesNothing(): void
    {
        $GLOBALS['__bcc_salt'] = '';
        $GLOBALS['__bcc_get_users_pages'] = [0 => [$this->user(135, $this->md5Placeholder(135))]];

        $status = bcc_trust_backfill_wallet_placeholder_emails();

        self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, $status);
        self::assertSame([], $GLOBALS['__bcc_updates'], 'no rows written on empty salt');
    }

    // Clean drain → COMPLETE, and each rewrite differs from the old value.
    public function testCleanRunRewritesAndReportsComplete(): void
    {
        $old135 = $this->md5Placeholder(135);
        $old137 = $this->md5Placeholder(137);
        $GLOBALS['__bcc_get_users_pages'] = [
            0   => [$this->user(135, $old135), $this->user(137, $old137)],
            200 => [], // drained
        ];

        $status = bcc_trust_backfill_wallet_placeholder_emails();

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, $status);
        self::assertCount(2, $GLOBALS['__bcc_updates']);
        // Each target email is the salted helper output, on the placeholder
        // domain, and different from the old value.
        foreach ([[135, $old135], [137, $old137]] as [$id, $old]) {
            $expected = bcc_trust_wallet_placeholder_email_for_user($id, 'noreply.bcc.local', 'unit-salt');
            $row = array_values(array_filter(
                $GLOBALS['__bcc_updates'],
                static fn(array $u): bool => (int) $u['where']['ID'] === $id
            ))[0];
            self::assertSame($expected, $row['data']['user_email']);
            self::assertNotSame($old, $row['data']['user_email']);
            self::assertStringEndsWith(self::DOMAIN, $row['data']['user_email']);
        }
    }

    // 10 — genuine emails are never selected: the query is scoped to the
    // placeholder domain, so real inboxes and unrelated users are excluded.
    public function testOnlyPlaceholderDomainRowsAreSelected(): void
    {
        $GLOBALS['__bcc_get_users_pages'] = [0 => [], ];
        bcc_trust_backfill_wallet_placeholder_emails();

        $args = $GLOBALS['__bcc_get_users_args'][0] ?? [];
        self::assertSame('*' . self::DOMAIN, $args['search']);
        self::assertSame(['user_email'], $args['search_columns']);
    }

    // A uniqueness collision leaves the migration incomplete and skips the row.
    public function testCollisionLeavesIncompleteAndSkipsTheRow(): void
    {
        $GLOBALS['__bcc_get_users_pages'] = [
            0   => [$this->user(135, $this->md5Placeholder(135))],
            200 => [],
        ];
        // Target email already owned by a different user → collision.
        $GLOBALS['__bcc_email_exists'] = static fn(string $e): int => 999;

        $status = bcc_trust_backfill_wallet_placeholder_emails();

        self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, $status);
        self::assertSame([], $GLOBALS['__bcc_updates'], 'collided row is not written');
    }

    // 11 — a second execution over already-migrated rows mutates nothing.
    public function testAlreadyMigratedRowsAreNoOpAndComplete(): void
    {
        // Both rows already carry their salted (hmac) email → equality-skip.
        $e135 = bcc_trust_wallet_placeholder_email_for_user(135, 'noreply.bcc.local', 'unit-salt');
        $e137 = bcc_trust_wallet_placeholder_email_for_user(137, 'noreply.bcc.local', 'unit-salt');
        $GLOBALS['__bcc_get_users_pages'] = [
            0   => [$this->user(135, $e135), $this->user(137, $e137)],
            200 => [],
        ];

        $status = bcc_trust_backfill_wallet_placeholder_emails();

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, $status);
        self::assertSame([], $GLOBALS['__bcc_updates'], 'idempotent re-run changes zero rows');
    }

    // Batch cap reached with full batches throughout → INCOMPLETE (the bug
    // fix: it must NOT falsely report complete with rows unprocessed).
    public function testBatchCapReachedReturnsIncomplete(): void
    {
        // Every offset returns a FULL 200-batch (already-migrated, so 0
        // rewrites) → the loop never sees a short batch → never drains.
        $full = [];
        for ($n = 0; $n < 200; $n++) {
            $id = 500 + $n;
            $full[] = $this->user($id, bcc_trust_wallet_placeholder_email_for_user($id, 'noreply.bcc.local', 'unit-salt'));
        }
        $pages = [];
        for ($offset = 0; $offset <= 50 * 200; $offset += 200) {
            $pages[$offset] = $full;
        }
        $GLOBALS['__bcc_get_users_pages'] = $pages;

        $status = bcc_trust_backfill_wallet_placeholder_emails();

        self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, $status, 'cap hit must not report complete');
    }
}
