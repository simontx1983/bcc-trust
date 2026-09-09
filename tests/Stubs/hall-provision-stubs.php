<?php
/**
 * Stubs for HallProvisioningServiceTest.
 *
 * Loaded ONLY inside a #[RunTestsInSeparateProcesses] subprocess, so the main
 * process never sees these definitions and a test that needs `\PeepSoGroup`
 * ABSENT can simply not require this file.
 *
 * ── WHY THE WRITERS ARE FAKED AND THE SERVICE IS NOT ────────────────────
 * The behaviour under test is the ORDER and the POSTCONDITION CHECK: join →
 * transfer → leave, then ask the membership ledger what it actually says. A
 * fake that always succeeds could not distinguish "the service verified the
 * ledger" from "the service trusted three return values", which is precisely
 * the defect the postcondition guard exists to prevent. So every writer can
 * be made to fail independently, and the ledger is a separate piece of state
 * that a writer only changes when the test says it does.
 *
 * WP functions are declared in the SERVICE's namespace: PHP resolves an
 * unqualified call to the current namespace first and falls back to global,
 * so this intercepts without touching global scope.
 */

declare(strict_types=1);

namespace {

    if (!class_exists('HallTestState', false)) {
        /** Control surface for the Hall provisioning fakes. */
        final class HallTestState
        {
            /** The acting administrator. 0 = nobody signed in (cron shape). */
            public static int $currentUserId = 7;

            /** Ids returned by get_users(role=administrator, ID ASC, number=1). */
            public static array $admins = [3];

            /** Ids that resolve via get_userdata(). null = every id resolves. */
            public static ?array $knownUserIds = null;

            /** Ids holding manage_options. null = everyone does. */
            public static ?array $capableUserIds = null;

            /** Next id handed out by the PeepSoGroup constructor. */
            public static int $nextGroupId = 900;

            /** Make `new PeepSoGroup(null, $data)` throw. */
            public static bool $createThrows = false;

            /** Make `new PeepSoGroup(null, $data)` yield a 0-id group. */
            public static bool $createReturnsZero = false;

            /** Every `new PeepSoGroup(null, ...)` payload, in order. */
            public static array $created = [];

            /** Hard-deleted post ids. */
            public static array $deletedPosts = [];

            /** do_action() calls: [name, args]. */
            public static array $actions = [];

            public static function reset(): void
            {
                self::$currentUserId    = 7;
                self::$admins           = [3];
                self::$knownUserIds     = null;
                self::$capableUserIds   = null;
                self::$nextGroupId      = 900;
                self::$createThrows     = false;
                self::$createReturnsZero = false;
                self::$created          = [];
                self::$deletedPosts     = [];
                self::$actions          = [];
            }

            /** @return list<string> action names, in order */
            public static function actionNames(): array
            {
                return array_map(static fn(array $a): string => (string) $a[0], self::$actions);
            }
        }
    }

    // A test that needs the PeepSo-ABSENT branch defines this before
    // requiring the file: the WP-function fakes still load, so the
    // service's own guard is what stops it, not a missing stub.
    if (!defined('HALL_STUBS_NO_PEEPSO') && !class_exists('PeepSoGroup', false)) {
        class PeepSoGroup
        {
            private int $id = 0;

            /** @param array<string, mixed>|null $data */
            public function __construct($id = null, $data = null)
            {
                if ($id === null && is_array($data)) {
                    if (\HallTestState::$createThrows) {
                        throw new \RuntimeException('PeepSo exploded');
                    }
                    \HallTestState::$created[] = $data;
                    if (\HallTestState::$createReturnsZero) {
                        $this->id = 0;
                        return;
                    }
                    $this->id = \HallTestState::$nextGroupId++;

                    // PeepSo's create() joins and OWNS the current user,
                    // whatever owner_id says. Reproducing that is the whole
                    // reason the reconcile exists.
                    \FakePeepSoLedger::set(\HallTestState::$currentUserId, $this->id, 'member_owner');
                    \FakePeepSoLedger::$owners[$this->id] = \HallTestState::$currentUserId;
                    return;
                }

                $this->id = (int) $id;
            }

            public function get(string $key): int
            {
                if ($key === 'owner_id') {
                    return (int) (\FakePeepSoLedger::$owners[$this->id] ?? 0);
                }

                return $this->id;
            }
        }
    }

    if (!defined('HALL_STUBS_NO_PEEPSO') && !class_exists('PeepSoGroupUser', false)) {
        class PeepSoGroupUser
        {
            public function __construct(private int $groupId, private ?int $userId = null)
            {
                if ($this->userId === null) {
                    $this->userId = \HallTestState::$currentUserId;
                }
            }

            public function member_leave(): void
            {
                \FakePeepSoLedger::remove((int) $this->userId, $this->groupId);
            }
        }
    }

    if (!defined('HALL_STUBS_NO_PEEPSO') && !class_exists('PeepSo', false)) {
        class PeepSo
        {
            public static function get_option(string $key, $default = null)
            {
                return $default;
            }
        }
    }

    if (!class_exists('FakePeepSoLedger', false)) {
        /**
         * The membership ledger, as a plain map — the thing
         * `getMembershipStatus()` reads and the writers mutate.
         *
         * Separate from the writer fakes ON PURPOSE: a test can make a writer
         * return true while leaving the ledger untouched, which is exactly the
         * shape of the bug the postcondition re-read has to catch.
         */
        final class FakePeepSoLedger
        {
            /** "userId:groupId" => status */
            public static array $rows = [];

            /** groupId => owner user id (PeepSo's post_author pointer) */
            public static array $owners = [];

            public static function set(int $userId, int $groupId, string $status): void
            {
                self::$rows[$userId . ':' . $groupId] = $status;
            }

            public static function remove(int $userId, int $groupId): void
            {
                unset(self::$rows[$userId . ':' . $groupId]);
            }

            public static function status(int $userId, int $groupId): ?string
            {
                return self::$rows[$userId . ':' . $groupId] ?? null;
            }

            public static function reset(): void
            {
                self::$rows   = [];
                self::$owners = [];
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            public static array $lines = [];

            public static function info(string $m, array $c = []): void    { self::$lines[] = ['info', $m, $c]; }
            public static function warning(string $m, array $c = []): void { self::$lines[] = ['warning', $m, $c]; }
            public static function error(string $m, array $c = []): void   { self::$lines[] = ['error', $m, $c]; }

            public static function reset(): void { self::$lines = []; }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoGroupWriter::class, false)) {
        /**
         * Recording writer. Each step can be forced to fail independently, and
         * a forced failure does NOT touch the ledger — so the service cannot
         * pass by accident.
         */
        final class PeepSoGroupWriter
        {
            public static bool $joinResult     = true;
            public static bool $transferResult = true;
            public static bool $leaveResult    = true;

            /**
             * Return true from every writer while changing NOTHING.
             *
             * This is what a silently-dropped PeepSo write looks like, and it
             * is the only way to prove the service asks the ledger instead of
             * trusting three cooperative return values.
             */
            public static bool $silentSuccess = false;

            /** leave() alone reports success and removes nothing. */
            public static bool $leaveSilent = false;

            /** Ordered log of "join:u:g" / "transfer:g:from:to" / "leave:u:g". */
            public static array $calls = [];

            public static function join(int $userId, int $groupId): bool
            {
                self::$calls[] = "join:{$userId}:{$groupId}";
                if (!self::$joinResult) {
                    return false;
                }
                if (self::$silentSuccess) {
                    return true;
                }
                \FakePeepSoLedger::set($userId, $groupId, 'member');
                return true;
            }

            public static function transferOwnership(int $groupId, int $fromUserId, int $toUserId): bool
            {
                self::$calls[] = "transfer:{$groupId}:{$fromUserId}:{$toUserId}";
                if (!self::$transferResult) {
                    return false;
                }
                if (self::$silentSuccess) {
                    return true;
                }
                \FakePeepSoLedger::set($toUserId, $groupId, 'member_owner');
                // The real writer demotes the previous owner to member_manager,
                // which is what makes the subsequent leave() legal.
                \FakePeepSoLedger::set($fromUserId, $groupId, 'member_manager');
                \FakePeepSoLedger::$owners[$groupId] = $toUserId;
                return true;
            }

            public static function leave(int $userId, int $groupId): bool
            {
                self::$calls[] = "leave:{$userId}:{$groupId}";
                if (!self::$leaveResult) {
                    return false;
                }
                if (self::$silentSuccess || self::$leaveSilent) {
                    return true;
                }
                if (\FakePeepSoLedger::status($userId, $groupId) === 'member_owner') {
                    return false;
                }
                \FakePeepSoLedger::remove($userId, $groupId);
                return true;
            }

            public static function reset(): void
            {
                self::$joinResult     = true;
                self::$transferResult = true;
                self::$leaveResult    = true;
                self::$silentSuccess  = false;
                self::$leaveSilent    = false;
                self::$calls          = [];
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        final class PeepSoGroupRepository
        {
            public static function getMembershipStatus(int $userId, int $groupId): ?string
            {
                return \FakePeepSoLedger::status($userId, $groupId);
            }
        }
    }
}

namespace BCC\Trust\Core\Security {
    if (!class_exists(AuditLogger::class, false)) {
        final class AuditLogger
        {
            public static array $rows = [];

            /** Fail only the NAMED actions, so a fault can be aimed. */
            public static array $failCheckedActions = [];

            public static bool $failChecked = false;

            public static function logChecked(
                string $action,
                ?int $targetId = null,
                array $meta = [],
                ?string $targetType = null,
                ?int $userId = null
            ): ?int {
                if (self::$failChecked || in_array($action, self::$failCheckedActions, true)) {
                    return null;
                }

                self::$rows[] = [
                    'action'     => $action,
                    'targetId'   => $targetId,
                    'meta'       => $meta,
                    'targetType' => $targetType,
                    'userId'     => $userId,
                ];

                return count(self::$rows);
            }

            /** @return list<string> */
            public static function actions(): array
            {
                return array_map(static fn(array $r): string => (string) $r['action'], self::$rows);
            }

            public static function reset(): void
            {
                self::$rows               = [];
                self::$failChecked        = false;
                self::$failCheckedActions = [];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(ChainRepository::class, false)) {
        final class ChainRepository
        {
            /** chainId => row */
            public static array $chains = [];

            public static function seed(int $id, string $slug, string $name): void
            {
                self::$chains[$id] = (object) ['id' => $id, 'slug' => $slug, 'name' => $name];
            }

            public static function getById(int $id): ?object
            {
                return self::$chains[$id] ?? null;
            }

            public static function reset(): void
            {
                self::$chains = [];
            }
        }
    }

    if (!class_exists(HallRepository::class, false)) {
        final class HallRepository
        {
            public const META_KIND              = '_bcc_group_kind';
            public const META_CHAIN_TAG         = '_bcc_chain_tag';
            public const META_OWNER_INCOMPLETE  = '_bcc_hall_owner_incomplete';
            public const KIND_HALL              = 'hall';

            /** chainId => groupId */
            public static array $halls = [];

            /** groupId => true */
            public static array $incomplete = [];

            /** When false, writeHallMeta() records the call but does not link. */
            public static bool $writeMetaWorks = true;

            public static array $metaWrites = [];

            public static function findHallForChain(int $chainId): ?int
            {
                return self::$halls[$chainId] ?? null;
            }

            public static function writeHallMeta(int $groupId, int $chainId): void
            {
                self::$metaWrites[] = [$groupId, $chainId];
                if (self::$writeMetaWorks) {
                    self::$halls[$chainId] = $groupId;
                }
            }

            public static function markOwnerIncomplete(int $groupId): void
            {
                self::$incomplete[$groupId] = true;
            }

            public static function clearOwnerIncomplete(int $groupId): void
            {
                unset(self::$incomplete[$groupId]);
            }

            public static function isOwnerIncomplete(int $groupId): bool
            {
                return isset(self::$incomplete[$groupId]);
            }

            public static function reset(): void
            {
                self::$halls          = [];
                self::$incomplete     = [];
                self::$writeMetaWorks = true;
                self::$metaWrites     = [];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {

    if (!function_exists('BCC\\Trust\\Onchain\\Services\\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return \HallTestState::$currentUserId;
        }
    }

    if (!function_exists('BCC\\Trust\\Onchain\\Services\\get_users')) {
        /**
         * @param  array<string, mixed> $args
         * @return list<int>
         */
        function get_users(array $args = []): array
        {
            return \HallTestState::$admins;
        }
    }

    if (!function_exists('BCC\\Trust\\Onchain\\Services\\get_userdata')) {
        function get_userdata(int $userId)
        {
            if (\HallTestState::$knownUserIds === null) {
                return (object) ['ID' => $userId];
            }

            return in_array($userId, \HallTestState::$knownUserIds, true)
                ? (object) ['ID' => $userId]
                : false;
        }
    }

    if (!function_exists('BCC\\Trust\\Onchain\\Services\\user_can')) {
        function user_can($user, string $cap): bool
        {
            if (\HallTestState::$capableUserIds === null) {
                return true;
            }

            return in_array((int) $user, \HallTestState::$capableUserIds, true);
        }
    }

    if (!function_exists('BCC\\Trust\\Onchain\\Services\\delete_post_meta')) {
        function delete_post_meta(int $postId, string $key, $value = ''): bool
        {
            return true;
        }
    }

    if (!function_exists('BCC\\Trust\\Onchain\\Services\\wp_delete_post')) {
        function wp_delete_post(int $postId, bool $force = false)
        {
            \HallTestState::$deletedPosts[] = $postId;
            return (object) ['ID' => $postId];
        }
    }

    if (!function_exists('BCC\\Trust\\Onchain\\Services\\wp_cache_delete')) {
        function wp_cache_delete($key, string $group = ''): bool
        {
            return true;
        }
    }

    if (!function_exists('BCC\\Trust\\Onchain\\Services\\clean_post_cache')) {
        function clean_post_cache($post): void
        {
        }
    }

    if (!function_exists('BCC\\Trust\\Onchain\\Services\\do_action')) {
        function do_action(string $name, ...$args): void
        {
            \HallTestState::$actions[] = [$name, $args];
        }
    }
}
