<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Services {
    // The WP user API the sync calls, scoped to its namespace: a tiny user
    // table the test edits (wp_update_user stand-in) and empty user meta,
    // which is the production truth for the dead `bcc_trust_*` meta keys.
    if (!function_exists(__NAMESPACE__ . '\\get_userdata')) {
        function get_userdata(int $id): object|false
        {
            return $GLOBALS['__sync_users'][$id] ?? false;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_users')) {
        /** @param array<string, mixed> $args @return list<object> */
        function get_users(array $args): array
        {
            $all = array_values($GLOBALS['__sync_users']);
            return array_slice($all, (int) ($args['offset'] ?? 0), (int) ($args['number'] ?? 500));
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $id, string $key, bool $single = false)
        {
            return $key === 'bcc_trust_last_ip' ? '203.0.113.7' : '';
        }
    }
}

namespace BCC\Trust\Core\Support {
    if (!function_exists(__NAMESPACE__ . '\\get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $id, string $key, bool $single = false)
        {
            return '';
        }
    }
}

namespace BCC\Trust\Tests\Integration {

    use BCC\Trust\Core\Database\TableRegistry;
    use BCC\Trust\Core\Repositories\UserInfoRepository;
    use BCC\Trust\Core\Services\UserSyncService;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * HIGH: a user sync never overwrites trust state.
     *
     * UserSyncService::syncSingleUser() used to write its whole payload on an
     * EXISTING row, and its trust-owned values came from user meta nothing
     * writes. So every login, profile update (including the email-change
     * confirmation) and admin "sync users" / initial-sync run set
     * is_suspended = 0 (a suspended member could un-suspend themselves; a
     * repair un-suspended everyone) and zeroed fraud_score, automation_score,
     * votes_cast, endorsements_given, trust_rank and the device fingerprint.
     *
     * Real UserSyncService + real UserInfoRepository on the real user_info
     * table; only the WP user API is a stand-in.
     */
    #[Group('integration')]
    #[Group('mariadb')]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class UserSyncPreservesTrustStateIntegrationTest extends TestCase
    {
        private const X = 991201;
        private const Y = 991202;

        private UserSyncService $sync;

        protected function setUp(): void
        {
            $this->cleanup();
            $GLOBALS['__sync_users'] = [
                self::X => $this->user(self::X, 'x@example.test'),
                self::Y => $this->user(self::Y, 'y@example.test'),
            ];
            $this->sync = new UserSyncService(new UserInfoRepository());

            // Row created through the sync's own INSERT path.
            self::assertSame(1, $this->sync->sync(self::X));

            // The trust engine then records real trust state on it.
            global $wpdb;
            self::assertNotFalse($wpdb->update(TableRegistry::userInfo(), [
                'is_suspended'       => 1,
                'fraud_score'        => 72,
                'trust_rank'         => 0.42,
                'risk_level'         => 'high',
                'votes_cast'         => 9,
                'endorsements_given' => 3,
                'device_fingerprint' => 'fp-abc123',
                'automation_score'   => 55,
            ], ['user_id' => self::X]), $wpdb->last_error);
        }

        protected function tearDown(): void
        {
            $this->cleanup();
        }

        public function testAnEmailChangeSyncKeepsTheSuspensionAndTheFraudState(): void
        {
            // wp_update_user(['user_email' => ...]) → profile_update → sync($id).
            $GLOBALS['__sync_users'][self::X]->user_email = 'x-new@example.test';
            $this->sync->sync(self::X);

            $row = $this->row(self::X);
            self::assertSame('x-new@example.test', (string) $row->user_email, 'control: the sync DID run and update identity');
            $this->assertTrustStateKept($row);
        }

        public function testTheFullUserSyncNeverUnsuspendsAnyone(): void
        {
            // Admin Repair "sync users" / bcc_trust_initial_user_sync: every user.
            $GLOBALS['__sync_users'][self::X]->display_name = 'X Renamed';
            self::assertGreaterThanOrEqual(2, $this->sync->sync());

            $this->assertTrustStateKept($this->row(self::X));
            self::assertSame(0, (int) $this->row(self::Y)->is_suspended, 'a NEW row still gets the default');
        }

        private function assertTrustStateKept(object $row): void
        {
            self::assertSame(1, (int) $row->is_suspended, 'a sync must never un-suspend');
            self::assertSame(72, (int) $row->fraud_score);
            self::assertEqualsWithDelta(0.42, (float) $row->trust_rank, 0.0001);
            self::assertSame('high', (string) $row->risk_level);
            self::assertSame(9, (int) $row->votes_cast);
            self::assertSame(3, (int) $row->endorsements_given);
            self::assertSame('fp-abc123', (string) $row->device_fingerprint);
            self::assertSame(55, (int) $row->automation_score);
            self::assertSame('203.0.113.7', (string) $row->last_ip_address, 'sync-owned columns still update');
        }

        private function user(int $id, string $email): object
        {
            return (object) [
                'ID'              => $id,
                'user_login'      => 'sync' . $id,
                'user_email'      => $email,
                'display_name'    => 'Sync ' . $id,
                'user_registered' => '2026-01-01 00:00:00',
                'user_status'     => 0,
            ];
        }

        private function row(int $id): object
        {
            global $wpdb;
            $r = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM `' . TableRegistry::userInfo() . '` WHERE user_id = %d',
                $id
            ));
            self::assertIsObject($r, 'user_info row for ' . $id);
            return $r;
        }

        private function cleanup(): void
        {
            global $wpdb;
            $wpdb->query('DELETE FROM `' . TableRegistry::userInfo() . '` WHERE user_id IN (' . self::X . ',' . self::Y . ')');
        }
    }
}
