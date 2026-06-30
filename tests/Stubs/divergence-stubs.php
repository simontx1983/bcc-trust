<?php

/**
 * DivergenceStateClassifier test stubs.
 *
 * Loaded ONLY from inside a #[RunTestsInSeparateProcesses] subprocess
 * (see DivergenceStateClassifierTest) so the main PHPUnit process never
 * sees these stub definitions — the real AttestationRepository /
 * DisputeRepository keep loading via PSR-4 everywhere else.
 *
 * The stubs are defined at the exact production FQNs with
 * class_exists() guards so PHP's autoloader leaves them alone on first
 * reference. They record the arguments classify() passes so the test
 * can assert the id-duality invariant: the dispute existence read keys
 * on the member's SELF-PAGE id (ID_BASE + user_id) while the
 * attestation counts key on the RAW user id.
 *
 * MemberSelfPageService is NOT stubbed — selfPageId() is a pure static
 * function with no DB, so the real class is used (it is what the
 * production translation calls anyway).
 */

declare(strict_types=1);

// ── Core\Repositories\AttestationRepository (stub) ──────────────────────────

namespace BCC\Trust\Core\Repositories {

    if (!class_exists(__NAMESPACE__ . '\\AttestationRepository', false)) {
        final class AttestationRepository
        {
            /** @var list<string> */
            public const TARGET_KINDS = [
                'user_profile',
                'validator_card',
                'project_card',
                'creator_card',
            ];

            /** @var list<array{kind: string, id: int}> Recorded count reads. */
            public static array $countByTargetCalls = [];
            /** @var list<array{kind: string, id: int}> Recorded revoked reads. */
            public static array $countRevokedCalls = [];

            /** @var int Active attestation total the next countByTarget returns. */
            public static int $activeTotal = 0;
            /** @var int Revoked attestation total the next countRevokedByTarget returns. */
            public static int $revokedTotal = 0;

            public static function reset(): void
            {
                self::$countByTargetCalls = [];
                self::$countRevokedCalls = [];
                self::$activeTotal = 0;
                self::$revokedTotal = 0;
            }

            /** @return array{vouch_count: int, stand_behind_count: int, total: int} */
            public function countByTarget(string $targetKind, int $targetId): array
            {
                self::$countByTargetCalls[] = ['kind' => $targetKind, 'id' => $targetId];
                return [
                    'vouch_count'        => self::$activeTotal,
                    'stand_behind_count' => 0,
                    'total'              => self::$activeTotal,
                ];
            }

            public function countRevokedByTarget(string $targetKind, int $targetId): int
            {
                self::$countRevokedCalls[] = ['kind' => $targetKind, 'id' => $targetId];
                return self::$revokedTotal;
            }
        }
    }
}

// ── Disputes\Repositories\DisputeRepository (stub) ──────────────────────────

namespace BCC\Trust\Disputes\Repositories {

    if (!class_exists(__NAMESPACE__ . '\\DisputeRepository', false)) {
        final class DisputeRepository
        {
            /** @var list<int> page_ids the existence read was called with. */
            public static array $hasActiveCalls = [];
            /** @var array<int, bool> page_id => has-active map the stub returns. */
            public static array $hasActiveMap = [];

            public static function reset(): void
            {
                self::$hasActiveCalls = [];
                self::$hasActiveMap = [];
            }

            public static function hasActiveDisputeForPage(int $pageId): bool
            {
                self::$hasActiveCalls[] = $pageId;
                return self::$hasActiveMap[$pageId] ?? false;
            }
        }
    }
}
