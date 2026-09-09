<?php
/**
 * Stubs for HallOwnershipRepairServiceTest.
 *
 * Loaded ONLY inside a #[RunTestsInSeparateProcesses] subprocess.
 *
 * ── WHY THE LEDGER IS REAL STATE AND THE WRITER IS NOT A MOCK ───────────
 * The behaviour under test is a GUARD SET and a POSTCONDITION. A fake that
 * merely records "repointOwnerRow was called" could not tell a repair that
 * verified the ledger from one that assumed its own write worked — and the
 * mutation controls plant exactly that defect. So the fake repository keeps
 * a real in-memory row table, the write mutates it, and the postcondition
 * reads it back. A test can also make the write silently no-op or corrupt a
 * neighbouring member row, which is what proves the checks are load-bearing.
 *
 * WP functions are declared in the SERVICE's namespace: PHP resolves an
 * unqualified call to the current namespace first and falls back to global.
 */

declare(strict_types=1);

namespace {

    if (!class_exists('HallRepairTestState', false)) {
        final class HallRepairTestState
        {
            /** groupId => list of ['gm_id'=>int,'gm_user_id'=>int,'gm_user_status'=>string] */
            public static array $rows = [];

            /** groupId => ['meta_key' => list<string>] */
            public static array $meta = [];

            /** groupId => ['post_type'=>string,'post_status'=>string,'post_author'=>int] */
            public static array $posts = [];

            /** chainId => object|null */
            public static array $chains = [];

            /** user ids that exist in wp_users */
            public static array $users = [1, 2, 4, 49];

            /** user ids with a countable peepso_users role */
            public static array $countable = [1, 2, 4, 49];

            /** administrators in ID order, as get_users would return */
            public static array $admins = [1, 2, 4];

            /** ids that hold manage_options; null = all admins do */
            public static ?array $capable = null;

            /** userId => suspension meta value */
            public static array $suspended = [];

            /** postmeta scalar store: "groupId:key" => value */
            public static array $postMeta = [];

            /** Make the guarded UPDATE report success but change nothing. */
            public static bool $writeSilentlyNoOps = false;

            /** Same, but scoped to ONE group — so a multi-Hall run can have
             *  one Hall succeed and another fail. */
            public static ?int $writeSilentlyNoOpsForGroup = null;

            /** Make the guarded UPDATE also clobber another member's row. */
            public static bool $writeCorruptsNeighbour = false;

            /** Force the artifact directory to look unwritable. */
            public static bool $artifactDirUnwritable = false;

            /**
             * Override the affected-row count the guarded UPDATE reports,
             * WITHOUT changing what it actually does. Isolates the
             * "affected !== 1" check from the postconditions that would
             * otherwise also catch a bad write.
             */
            public static ?int $writeReportsAffected = null;

            /** Write lands, but on the wrong user id. Isolates the owner re-read. */
            public static int $writeLandsOnUser = 0;

            /**
             * Write ADDS a correct owner row and demotes the orphan to
             * 'member' instead of moving it. Owner re-read passes; only the
             * "no orphan remains" check can fire.
             */
            public static bool $writeLeavesOrphanBehind = false;

            /** Mutate the ledger AFTER planning, before the locked re-check. */
            public static $mutateBeforeApply = null;

            /**
             * Fires on the Nth unlocked read. The backup reads each group
             * twice — once to build the payload, once to verify it against
             * live rows — so mutating on the second read makes the saved
             * snapshot stale, which is exactly what the guard must catch.
             */
            public static ?int $mutateOnReadNumber = null;
            public static $mutateOnReadFn = null;
            public static int $readCount = 0;

            public static int $nextGmId = 9000;

            public static function reset(): void
            {
                self::$rows = []; self::$meta = []; self::$posts = []; self::$chains = [];
                self::$users = [1, 2, 4, 49]; self::$countable = [1, 2, 4, 49];
                self::$admins = [1, 2, 4]; self::$capable = null; self::$suspended = [];
                self::$postMeta = [];
                self::$writeSilentlyNoOps = false;
                self::$writeSilentlyNoOpsForGroup = null;
                self::$writeCorruptsNeighbour = false;
                self::$artifactDirUnwritable = false;
                self::$writeReportsAffected = null;
                self::$writeLandsOnUser = 0;
                self::$writeLeavesOrphanBehind = false;
                self::$mutateBeforeApply = null;
                self::$mutateOnReadNumber = null;
                self::$mutateOnReadFn = null;
                self::$readCount = 0;
                self::$nextGmId = 9000;
            }

            /** Seed a healthy, broken-owner Hall: one (user 0, member_owner) row. */
            public static function seedBrokenHall(int $groupId, int $chainId, array $realMembers = []): void
            {
                self::$posts[$groupId] = ['post_type' => 'peepso-group', 'post_status' => 'publish', 'post_author' => 1];
                self::$meta[$groupId] = [
                    '_bcc_group_kind'            => ['hall'],
                    '_bcc_chain_tag'             => [(string) $chainId],
                    'peepso_group_privacy'       => ['0'],
                    'peepso_group_members_count' => ['0'],
                ];
                self::$chains[$chainId] = (object) ['id' => $chainId, 'slug' => 'chain' . $chainId, 'name' => 'Chain ' . $chainId];
                self::$rows[$groupId] = [
                    ['gm_id' => self::$nextGmId++, 'gm_user_id' => 0, 'gm_user_status' => 'member_owner'],
                ];
                foreach ($realMembers as $uid) {
                    self::$rows[$groupId][] = ['gm_id' => self::$nextGmId++, 'gm_user_id' => $uid, 'gm_user_status' => 'member'];
                }
                self::$postMeta[$groupId . ':peepso_group_members_count'] = '0';
            }

            /** @return list<string> "user:status" for every row, in gm_id order */
            public static function snapshot(int $groupId): array
            {
                $rows = self::$rows[$groupId] ?? [];
                usort($rows, static fn(array $a, array $b): int => $a['gm_id'] <=> $b['gm_id']);
                return array_map(static fn(array $r): string => $r['gm_user_id'] . ':' . $r['gm_user_status'], $rows);
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            public static array $lines = [];
            public static function info(string $m, array $c = []): void    { self::$lines[] = ['info', $m]; }
            public static function warning(string $m, array $c = []): void { self::$lines[] = ['warning', $m]; }
            public static function error(string $m, array $c = []): void   { self::$lines[] = ['error', $m]; }
            public static function reset(): void { self::$lines = []; }
        }
    }
}

namespace BCC\Trust\Core\Security {

    if (!class_exists(AuditLogger::class, false)) {
        final class AuditLogger
        {
            public static array $rows = [];
            public static bool $failChecked = false;
            /** Corrupt the stored meta so verifyAuditRow() has something to catch. */
            public static bool $corruptStoredMeta = false;

            public static function logChecked(string $a, ?int $t = null, array $m = [], ?string $tt = null, ?int $u = null): ?int
            {
                if (self::$failChecked) { return null; }
                self::$rows[] = ['action' => $a, 'targetId' => $t, 'meta' => $m, 'targetType' => $tt, 'userId' => $u];
                return count(self::$rows);
            }

            /** @return list<string> */
            public static function actions(): array
            {
                return array_map(static fn(array $r): string => (string) $r['action'], self::$rows);
            }

            public static function reset(): void
            {
                self::$rows = []; self::$failChecked = false; self::$corruptStoredMeta = false;
            }
        }
    }

    if (!class_exists(TransactionManager::class, false)) {
        /**
         * A transaction fake that actually rolls back.
         *
         * On a throw it REWINDS the fake ledger and the audit rows to the
         * mark taken before the callback. Without that, a test asserting
         * "a failed audit rolls the repair back" would pass against a fake
         * that never rolled anything back.
         */
        final class TransactionManager
        {
            public static int $runs = 0;
            public static int $rollbacks = 0;
            private static bool $inRun = false;

            public static function run(callable $cb, ?int $retry = null)
            {
                self::$runs++;
                $rowMark   = \HallRepairTestState::$rows;
                $metaMark  = \HallRepairTestState::$postMeta;
                $auditMark = count(AuditLogger::$rows);

                self::$inRun = true;
                try {
                    $result = $cb();
                } catch (\Throwable $e) {
                    self::$rollbacks++;
                    \HallRepairTestState::$rows     = $rowMark;
                    \HallRepairTestState::$postMeta = $metaMark;
                    AuditLogger::$rows = array_slice(AuditLogger::$rows, 0, $auditMark);
                    self::$inRun = false;
                    throw $e;
                }
                self::$inRun = false;

                if ($result === false) {
                    self::$rollbacks++;
                    \HallRepairTestState::$rows     = $rowMark;
                    \HallRepairTestState::$postMeta = $metaMark;
                    AuditLogger::$rows = array_slice(AuditLogger::$rows, 0, $auditMark);
                    throw new \Exception('Transaction callback returned false');
                }

                return $result;
            }

            public static function isInRunTransaction(): bool { return self::$inRun; }

            public static function reset(): void
            {
                self::$runs = 0; self::$rollbacks = 0; self::$inRun = false;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(HallRepository::class, false)) {
        final class HallRepository
        {
            public const META_KIND             = '_bcc_group_kind';
            public const META_CHAIN_TAG        = '_bcc_chain_tag';
            public const META_OWNER_INCOMPLETE = '_bcc_hall_owner_incomplete';
            public const KIND_HALL             = 'hall';

            /** @return list<int> */
            public static function listAllHallIds(int $limit = 500): array
            {
                $ids = array_keys(\HallRepairTestState::$rows);
                sort($ids);
                return array_map('intval', $ids);
            }
        }
    }

    if (!class_exists(GatedGroupRepository::class, false)) {
        final class GatedGroupRepository
        {
            public const META_KIND = '_bcc_group_kind';
        }
    }

    if (!class_exists(ChainRepository::class, false)) {
        final class ChainRepository
        {
            public static function getById(int $id): ?object
            {
                return \HallRepairTestState::$chains[$id] ?? null;
            }
        }
    }

    if (!class_exists(HallOwnershipRepairRepository::class, false)) {
        final class HallOwnershipRepairRepository
        {
            public const OWNER_STATUS   = 'member_owner';
            public const ORPHAN_USER_ID = 0;

            /** @return list<object> */
            public static function lockMembershipRows(int $groupId): array
            {
                if (!\BCC\Trust\Core\Security\TransactionManager::isInRunTransaction()) {
                    throw new \RuntimeException('lockMembershipRows outside a transaction');
                }
                if (\HallRepairTestState::$mutateBeforeApply !== null) {
                    $fn = \HallRepairTestState::$mutateBeforeApply;
                    \HallRepairTestState::$mutateBeforeApply = null;   // once
                    $fn($groupId);
                }
                return self::readMembershipRows($groupId);
            }

            /** @return list<object> */
            public static function readMembershipRows(int $groupId): array
            {
                \HallRepairTestState::$readCount++;
                if (\HallRepairTestState::$mutateOnReadNumber === \HallRepairTestState::$readCount
                    && \HallRepairTestState::$mutateOnReadFn !== null) {
                    $fn = \HallRepairTestState::$mutateOnReadFn;
                    $fn($groupId);
                }

                $rows = \HallRepairTestState::$rows[$groupId] ?? [];
                usort($rows, static fn(array $a, array $b): int => $a['gm_id'] <=> $b['gm_id']);
                return array_map(
                    static fn(array $r): object => (object) [
                        'gm_id'          => (string) $r['gm_id'],
                        'gm_group_id'    => (string) $groupId,
                        'gm_user_id'     => (string) $r['gm_user_id'],
                        'gm_user_status' => $r['gm_user_status'],
                    ],
                    $rows
                );
            }

            public static function repointOwnerRow(int $rowId, int $groupId, int $from, int $to, string $status): int
            {
                if (\HallRepairTestState::$writeSilentlyNoOps
                    || \HallRepairTestState::$writeSilentlyNoOpsForGroup === $groupId) {
                    return 1;   // claims success, changes nothing
                }

                if (\HallRepairTestState::$writeLeavesOrphanBehind) {
                    // A correct owner row appears, but the orphan is merely
                    // demoted rather than moved.
                    foreach (\HallRepairTestState::$rows[$groupId] as $i => $r) {
                        if ($r['gm_id'] === $rowId) {
                            \HallRepairTestState::$rows[$groupId][$i]['gm_user_status'] = 'member';
                        }
                    }
                    \HallRepairTestState::$rows[$groupId][] = [
                        'gm_id' => \HallRepairTestState::$nextGmId++,
                        'gm_user_id' => $to, 'gm_user_status' => 'member_owner',
                    ];
                    return 1;
                }

                $landsOn = \HallRepairTestState::$writeLandsOnUser !== 0
                    ? \HallRepairTestState::$writeLandsOnUser
                    : $to;

                $hit = 0;
                foreach (\HallRepairTestState::$rows[$groupId] ?? [] as $i => $r) {
                    if ($r['gm_id'] === $rowId && $r['gm_user_id'] === $from && $r['gm_user_status'] === $status) {
                        \HallRepairTestState::$rows[$groupId][$i]['gm_user_id'] = $landsOn;
                        $hit++;
                    }
                }

                if ($hit > 0 && \HallRepairTestState::$writeCorruptsNeighbour) {
                    foreach (\HallRepairTestState::$rows[$groupId] as $i => $r) {
                        if ($r['gm_user_status'] === 'member') {
                            \HallRepairTestState::$rows[$groupId][$i]['gm_user_id'] = 99999;
                            break;
                        }
                    }
                }

                if (\HallRepairTestState::$writeReportsAffected !== null) {
                    return \HallRepairTestState::$writeReportsAffected;
                }

                return $hit;
            }

            public static function computePeepSoMemberCount(int $groupId): int
            {
                $n = 0;
                foreach (\HallRepairTestState::$rows[$groupId] ?? [] as $r) {
                    if (str_starts_with($r['gm_user_status'], 'member')
                        && in_array($r['gm_user_id'], \HallRepairTestState::$countable, true)) {
                        $n++;
                    }
                }
                return $n;
            }

            public static function userExists(int $userId): bool
            {
                return in_array($userId, \HallRepairTestState::$users, true);
            }

            public static function isPeepSoCountableUser(int $userId): bool
            {
                return in_array($userId, \HallRepairTestState::$countable, true);
            }

            public static function lockPost(int $postId): ?object
            {
                if (!\BCC\Trust\Core\Security\TransactionManager::isInRunTransaction()) {
                    throw new \RuntimeException('lockPost outside a transaction');
                }
                $p = \HallRepairTestState::$posts[$postId] ?? null;
                return $p === null ? null : (object) [
                    'ID' => (string) $postId, 'post_type' => $p['post_type'],
                    'post_status' => $p['post_status'], 'post_author' => (string) $p['post_author'],
                ];
            }

            /** @return array<string, list<string>> */
            public static function readMarkerMeta(int $postId): array
            {
                return \HallRepairTestState::$meta[$postId] ?? [];
            }

            public static function readAuditRow(int $auditId): ?object
            {
                $row = \BCC\Trust\Core\Security\AuditLogger::$rows[$auditId - 1] ?? null;
                if ($row === null) { return null; }
                $meta = $row['meta'];
                if (\BCC\Trust\Core\Security\AuditLogger::$corruptStoredMeta) {
                    $meta['after'] = -1;
                }
                return (object) [
                    'id' => (string) $auditId, 'action' => $row['action'],
                    'user_id' => (string) $row['userId'], 'target_type' => (string) $row['targetType'],
                    'target_id' => (string) $row['targetId'],
                    'meta' => json_encode($meta),
                ];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repair {

    if (!function_exists(__NAMESPACE__ . '\\get_users')) {
        function get_users(array $args = []): array { return \HallRepairTestState::$admins; }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_userdata')) {
        function get_userdata(int $id) {
            return in_array($id, \HallRepairTestState::$users, true) ? (object) ['ID' => $id] : false;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\user_can')) {
        function user_can($user, string $cap): bool {
            return \HallRepairTestState::$capable === null
                || in_array((int) $user, \HallRepairTestState::$capable, true);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_user_meta')) {
        function get_user_meta(int $id, string $key, bool $single = false) {
            return \HallRepairTestState::$suspended[$id] ?? '';
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_post_meta')) {
        function get_post_meta(int $id, string $key, bool $single = false) {
            return \HallRepairTestState::$postMeta[$id . ':' . $key] ?? '';
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\update_post_meta')) {
        function update_post_meta(int $id, string $key, $value): bool {
            \HallRepairTestState::$postMeta[$id . ':' . $key] = (string) $value;
            return true;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\wp_cache_delete')) {
        function wp_cache_delete($k, string $g = ''): bool { return true; }
    }
    if (!function_exists(__NAMESPACE__ . '\\clean_post_cache')) {
        function clean_post_cache($p): void {}
    }
    if (!function_exists(__NAMESPACE__ . '\\is_dir')) {
        function is_dir(string $p): bool { return !\HallRepairTestState::$artifactDirUnwritable && \is_dir($p); }
    }
    if (!function_exists(__NAMESPACE__ . '\\is_writable')) {
        function is_writable(string $p): bool { return !\HallRepairTestState::$artifactDirUnwritable && \is_writable($p); }
    }
}
