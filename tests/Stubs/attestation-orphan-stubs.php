<?php

declare(strict_types=1);

/**
 * AttestationOrphanPurgeTest doubles: the Plugin's attestation repository,
 * score synthesis and lifecycle service, and the Logger, recording into
 * OrphanWorld. Load only in a separate process, before UserLifecycleService
 * or AttestationOrphansCommand are autoloaded.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    final class OrphanWorld
    {
        /** @var array<int, list<object{target_kind: string, target_id: int}>> attestor => active rows */
        public static array $active = [];
        /** @var list<int> users whose deleteForUser() CAST leg fails */
        public static array $deleteFails = [];
        /** @var list<int> users whose deleteForUser() TARGETED leg fails */
        public static array $targetedFails = [];
        /** @var list<int> */
        public static array $deleted = [];
        /** @var list<array{string, int}> */
        public static array $recomputed = [];
        /** @var list<array{string, array<string, mixed>}> */
        public static array $errors = [];
        public static bool $readFails = false;
        /** @var list<int> */
        public static array $orphans = [];
        /** @var array{cast: int, targeted: int, active: int} */
        public static array $rows = ['cast' => 0, 'targeted' => 0, 'active' => 0];
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void { \OrphanWorld::$errors[] = [$m, $c]; }
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
        }
    }
}

namespace BCC\Trust\Core {
    if (!class_exists(__NAMESPACE__ . '\\Plugin', false)) {
        final class Plugin
        {
            public static function instance(): self
            {
                return new self();
            }

            public function attestationRepository(): object
            {
                return new class {
                    /** @return list<object> */
                    public function listActiveByAttestor(int $userId): array
                    {
                        return \OrphanWorld::$active[$userId] ?? [];
                    }

                    /** @return array{cast: bool, targeted: bool} */
                    public function deleteForUser(int $userId): array
                    {
                        \OrphanWorld::$deleted[] = $userId;
                        return [
                            'cast'     => !in_array($userId, \OrphanWorld::$deleteFails, true),
                            'targeted' => !in_array($userId, \OrphanWorld::$targetedFails, true),
                        ];
                    }

                    /** @return list<int> */
                    public function findOrphanedUserIds(int $limit = 1000): array
                    {
                        if (\OrphanWorld::$readFails) {
                            throw new \BCC\Trust\Onchain\Repositories\RepositoryReadFailure('AttestationRepository', 'findOrphanedUserIds', 'gone away');
                        }
                        return \OrphanWorld::$orphans;
                    }

                    /** @return array{cast: int, targeted: int, active: int} */
                    public function countOrphanedRows(): array
                    {
                        return \OrphanWorld::$rows;
                    }
                };
            }

            public function attestationScoreSynthesis(): object
            {
                return new class {
                    public function recomputeFor(string $targetKind, int $targetId): void
                    {
                        \OrphanWorld::$recomputed[] = [$targetKind, $targetId];
                    }
                };
            }

            public function userLifecycleService(): \BCC\Trust\Core\Services\UserLifecycleService
            {
                return (new \ReflectionClass(\BCC\Trust\Core\Services\UserLifecycleService::class))->newInstanceWithoutConstructor();
            }
        }
    }
}
