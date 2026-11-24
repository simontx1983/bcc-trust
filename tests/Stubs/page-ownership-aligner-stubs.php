<?php

declare(strict_types=1);

/**
 * PageOwnershipAlignerTest: recording fakes for the aligner's collaborators.
 * Load only in a separate process, from setUp(), BEFORE the aligner is
 * autoloaded. State lives in AlignWorld.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    final class AlignWorld
    {
        public static bool $lockFree = true;
        /** @var array{state: string, user_id: int|null}|null null = the read fails */
        public static ?array $desired = ['state' => 'UNCLAIMED', 'user_id' => null];
        /** @var bool|null null = the read fails */
        public static ?bool $holds = false;
        public static bool $releaseSkips = false;
        /** @var bool|null did the author own the page through a now-revoked claim; null = the read fails */
        public static ?bool $hadRevoked = false;
        /** @var list<int> user ids whose account is gone */
        public static array $deletedUsers = [];
        public static bool $existenceReadFails = false;
        /** @var list<string> advisory lock names held by someone else */
        public static array $busyLocks = [];
        public static bool $swapSkips = false;
        /** @var array<int, int> page => post_author */
        public static array $author = [];
        /** @var array<string, mixed> */
        public static array $options = [];
        /** @var list<string> */
        public static array $calls = [];
        /** @var list<array<string, mixed>> */
        public static array $logRows = [];
        /** @var list<string> */
        public static array $metrics = [];
        /** @var list<array{string, array<int, mixed>}> */
        public static array $scheduled = [];

        public static function reset(): void
        {
            self::$lockFree     = true;
            self::$desired      = ['state' => 'UNCLAIMED', 'user_id' => null];
            self::$holds        = false;
            self::$releaseSkips = false;
            self::$hadRevoked   = false;
            self::$deletedUsers = [];
            self::$existenceReadFails = false;
            self::$busyLocks    = [];
            self::$swapSkips    = false;
            self::$author       = [];
            self::$options      = [];
            self::$calls        = [];
            self::$logRows      = [];
            self::$metrics      = [];
            self::$scheduled    = [];
        }
    }

    function clean_post_cache(int $postId): void
    {
    }

    function get_userdata(int $userId): object|false
    {
        return in_array($userId, \AlignWorld::$deletedUsers, true) ? false : (object) ['ID' => $userId];
    }

    function get_post_field(string $field, int $postId): int
    {
        return \AlignWorld::$author[$postId] ?? 0;
    }

    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        foreach (\AlignWorld::$scheduled as [$h, $a]) {
            if ($h === $hook && $a === $args) {
                return time() + 60;
            }
        }
        return false;
    }

    function wp_schedule_single_event(int $ts, string $hook, array $args = []): bool
    {
        \AlignWorld::$scheduled[] = [$hook, $args];
        return true;
    }

    function get_option(string $key, mixed $default = false): mixed
    {
        return \AlignWorld::$options[$key] ?? $default;
    }

    function update_option(string $key, mixed $value, mixed $autoload = null): bool
    {
        \AlignWorld::$options[$key] = $value;
        return true;
    }
}

namespace BCC\Core\DB {
    final class AdvisoryLock
    {
        public static function acquire(string $key, int $timeout = 0): bool
        {
            return \AlignWorld::$lockFree && !in_array($key, \AlignWorld::$busyLocks, true);
        }

        public static function release(string $key): void
        {
        }
    }
}

namespace BCC\Core\Observability {
    final class DegradationMetrics
    {
        public static function record(string $subsystem, string $event = 'activation'): void
        {
            \AlignWorld::$metrics[] = $subsystem . '/' . $event;
        }
    }
}

namespace BCC\Core\Log {
    final class Logger
    {
        /** @param array<string, mixed> $c */
        public static function warning(string $m, array $c = []): void {}
        /** @param array<string, mixed> $c */
        public static function error(string $m, array $c = []): void {}
        /** @param array<string, mixed> $c */
        public static function info(string $m, array $c = []): void {}
    }
}

namespace BCC\Trust\Onchain\Repositories {
    final class ClaimRepository
    {
        public const OPERATOR_UNCLAIMED = 'UNCLAIMED';
        public const OPERATOR_RESOLVED  = 'RESOLVED';
        public const OPERATOR_AMBIGUOUS = 'AMBIGUOUS';

        /** @return array{state: string, user_id: int|null} */
        public static function resolveVerifiedOperatorForPageStrict(int $pageId): array
        {
            if (\AlignWorld::$desired === null) {
                throw new RepositoryReadFailure(self::class, __FUNCTION__, 'read failed');
            }
            return \AlignWorld::$desired;
        }

        public static function accountExistsStrict(int $userId): bool
        {
            if (\AlignWorld::$existenceReadFails) {
                throw new RepositoryReadFailure(self::class, __FUNCTION__, 'read failed');
            }
            return !in_array($userId, \AlignWorld::$deletedUsers, true);
        }

        public static function userHadRevokedOwnerRowOnPage(int $userId, int $pageId): bool
        {
            if (\AlignWorld::$hadRevoked === null) {
                throw new RepositoryReadFailure(self::class, __FUNCTION__, 'read failed');
            }
            return \AlignWorld::$hadRevoked;
        }

        public static function userHoldsPageThroughAnyClaim(int $userId, int $pageId): bool
        {
            if (\AlignWorld::$holds === null) {
                throw new RepositoryReadFailure(self::class, __FUNCTION__, 'read failed');
            }
            return \AlignWorld::$holds;
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    final class BonusService
    {
        public static function clearForUnownedPage(int $pageId): void
        {
            \AlignWorld::$calls[] = 'bonus_clear:' . $pageId;
        }
    }

    final class RelationshipLogWriter
    {
        /** @param array<string, mixed> $event */
        public static function appendStandalone(array $event): bool
        {
            \AlignWorld::$logRows[] = $event;
            return true;
        }
    }
}

namespace BCC\Trust\Core {
    final class Plugin
    {
        public static function instance(): self
        {
            return new self();
        }

        public function peepSoIntegration(): object
        {
            return new class {
                public function onPageClaimed(int $userId, int $pageId): void
                {
                    \AlignWorld::$calls[] = "claimed:{$pageId}:{$userId}";
                    if ((\AlignWorld::$author[$pageId] ?? 0) === 0) {
                        \AlignWorld::$author[$pageId] = $userId; // only ever from 0
                    }
                }

                public function swapClaimedPageOwner(int $pageId, int $fromUserId, int $toUserId): bool
                {
                    \AlignWorld::$calls[] = "swap:{$pageId}:{$fromUserId}:{$toUserId}";
                    if (\AlignWorld::$swapSkips || (\AlignWorld::$author[$pageId] ?? 0) !== $fromUserId) {
                        return false; // a ban, or the author changed: left in place
                    }
                    \AlignWorld::$author[$pageId] = $toUserId;
                    return true;
                }

                public function releaseClaimedPage(int $pageId, int $userId): bool
                {
                    \AlignWorld::$calls[] = "release:{$pageId}:{$userId}";
                    if (\AlignWorld::$releaseSkips) {
                        return false; // e.g. a banned owner: left in place
                    }
                    \AlignWorld::$author[$pageId] = 0;
                    return true;
                }
            };
        }
    }
}
