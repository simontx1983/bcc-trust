<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories {
    // Namespace-scoped so they bind ONLY for UserInfoRepository (which calls
    // both unqualified) and cannot collide with a global shim another
    // integration suite installs. Same isolation as AuditLoggerMetaWriteIntegrationTest.
    if (!function_exists(__NAMESPACE__ . '\\do_action')) {
        function do_action(string $hook, ...$args): void
        {
            $GLOBALS['bcc_itest_suspend_actions'][] = $hook;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\wp_next_scheduled')) {
        /** @param list<mixed> $args */
        function wp_next_scheduled(string $hook, array $args = []): bool
        {
            return false;
        }
    }
}

namespace BCC\Trust\Tests\Integration {

    use BCC\Trust\Core\Database\TableRegistry;
    use BCC\Trust\Core\Exceptions\RepositoryException;
    use BCC\Trust\Core\Repositories\UserInfoRepository;
    use PHPUnit\Framework\TestCase;

    /**
     * F-112: UserInfoRepository::suspendUser() against REAL MySQL.
     *
     * ── ⚠ THE THINGS THAT MUST NOT HAPPEN ───────────────────────────────
     *  1. A SILENT NO-OP. `$wpdb->update()` matching zero rows is not an
     *     error, so suspending a user with no user_info row reported success
     *     while is_suspended, the flag every enforcement path reads, never
     *     landed. It must THROW, so the caller's transaction rolls back.
     *  2. FRAUD EVIDENCE OVERWRITTEN. fraud_triggers used to be replaced
     *     wholesale with {suspension_reason, suspended_at}, erasing whatever
     *     the fraud pipeline had recorded. It must MERGE.
     */
    final class SuspendUserRowIntegrationTest extends TestCase
    {
        private const UID_PRESENT = 990101;
        private const UID_ABSENT  = 990102;
        private const UID_NULL    = 990103;

        private function table(): string
        {
            return TableRegistry::userInfo();
        }

        private function insertRow(int $uid, ?string $triggers): void
        {
            $GLOBALS['wpdb']->insert($this->table(), [
                'user_id'        => $uid,
                'user_login'     => 'itsusp' . $uid,
                'user_email'     => 'itsusp' . $uid . '@example.test',
                'display_name'   => 'itsusp' . $uid,
                'is_suspended'   => 0,
                'fraud_triggers' => $triggers,
            ]);
        }

        private function row(int $uid): ?object
        {
            $wpdb = $GLOBALS['wpdb'];
            $r = $wpdb->get_row($wpdb->prepare(
                'SELECT is_suspended, fraud_triggers FROM `' . $this->table() . '` WHERE user_id = %d',
                $uid
            ));
            return $r ?: null;
        }

        protected function setUp(): void
        {
            $this->tearDown();
            $GLOBALS['bcc_itest_suspend_actions'] = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['wpdb']->query(
                'DELETE FROM `' . $this->table() . '` WHERE user_id IN ('
                . self::UID_PRESENT . ',' . self::UID_ABSENT . ',' . self::UID_NULL . ')'
            );
        }

        public function testSuspendingAUserWithNoRowThrowsAndWritesNothing(): void
        {
            $repo = new UserInfoRepository();

            try {
                $repo->suspendUser(self::UID_ABSENT, 'manual_suspension');
                self::fail('a missing user_info row must not be a silent success');
            } catch (RepositoryException $e) {
                self::assertStringContainsString('no user_info row', $e->getMessage());
            }

            self::assertNull($this->row(self::UID_ABSENT), 'nothing may be created by the repository itself');
            self::assertSame([], $GLOBALS['bcc_itest_suspend_actions'], 'no suspension-changed event for a failed suspend');
        }

        public function testExistingFraudTriggersAreMergedNotOverwritten(): void
        {
            $this->insertRow(self::UID_PRESENT, (string) json_encode(['ring_member' => true, 'velocity_spike' => 3]));

            (new UserInfoRepository())->suspendUser(self::UID_PRESENT, 'vote_ring');

            $row = $this->row(self::UID_PRESENT);
            self::assertNotNull($row);
            self::assertSame(1, (int) $row->is_suspended);

            $triggers = json_decode((string) $row->fraud_triggers, true);
            self::assertIsArray($triggers);
            self::assertTrue($triggers['ring_member'] ?? null, 'pre-existing evidence must survive');
            self::assertSame(3, $triggers['velocity_spike'] ?? null);
            self::assertSame('vote_ring', $triggers['suspension_reason'] ?? null);
            self::assertArrayHasKey('suspended_at', $triggers);
            self::assertContains('bcc_user_suspension_changed', $GLOBALS['bcc_itest_suspend_actions']);
        }

        public function testANullTriggersColumnStillSuspends(): void
        {
            // A row whose fraud_triggers is NULL is NOT a missing row.
            $this->insertRow(self::UID_NULL, null);

            (new UserInfoRepository())->suspendUser(self::UID_NULL, 'manual_suspension');

            $row = $this->row(self::UID_NULL);
            self::assertNotNull($row);
            self::assertSame(1, (int) $row->is_suspended);
            self::assertSame('manual_suspension', json_decode((string) $row->fraud_triggers, true)['suspension_reason'] ?? null);
        }
    }
}
