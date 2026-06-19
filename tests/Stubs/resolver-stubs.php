<?php

/**
 * Resolver test stubs.
 *
 * Loaded ONLY from inside a @runInSeparateProcess subprocess (see
 * DisputeResolverTest) so the main PHPUnit process — where
 * ComputeVerdictTest runs against the REAL DisputeRepository — never
 * sees these stub definitions.
 *
 * All classes are defined at the correct FQNs with class_exists()
 * guards so PHP's autoloader leaves them alone on first reference.
 */

declare(strict_types=1);

// ── Core\Log\Logger ────────────────────────────────────────────────────────

namespace BCC\Core\Log {

    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string,mixed> $c */
            public static function error(string $m, array $c = []): void {}
            /** @param array<string,mixed> $c */
            public static function info(string $m, array $c = []): void {}
            /** @param array<string,mixed> $c */
            public static function warning(string $m, array $c = []): void {}
            /** @param array<string,mixed> $c */
            public static function audit(string $m, array $c = []): void {}
        }
    }
}

// ── Core\Contracts\DisputeAdjudicationInterface ────────────────────────────

namespace BCC\Core\Contracts {

    if (!interface_exists(__NAMESPACE__ . '\\DisputeAdjudicationInterface', false)) {
        interface DisputeAdjudicationInterface
        {
            public function acceptVoteDispute(
                int $disputeId,
                int $voteId,
                int $pageId,
                int $voterId,
                int $resolvedBy
            ): bool;

            public function rejectVoteDispute(
                int $disputeId,
                int $voteId,
                int $pageId,
                int $reporterId,
                int $resolvedBy,
                bool $quorumMet
            ): bool;
        }
    }
}

// ── Core\ServiceLocator ────────────────────────────────────────────────────

namespace BCC\Core {

    use BCC\Core\Contracts\DisputeAdjudicationInterface;

    if (!class_exists(__NAMESPACE__ . '\\ServiceLocator', false)) {
        final class ServiceLocator
        {
            /** @var array<string,bool> */
            public static array $hasReal = [];
            public static ?DisputeAdjudicationInterface $adjudicator = null;

            public static function hasRealService(string $iface): bool
            {
                return self::$hasReal[$iface] ?? true;
            }

            public static function resolveDisputeAdjudication(): ?DisputeAdjudicationInterface
            {
                return self::$adjudicator;
            }

            public static function reset(): void
            {
                self::$hasReal     = [];
                self::$adjudicator = null;
            }
        }
    }
}

// ── Disputes\Repositories\DisputeRepository ────────────────────────────────

namespace BCC\Trust\Disputes\Repositories {

    if (!class_exists(__NAMESPACE__ . '\\DisputeRepository', false)) {
        final class DisputeRepository
        {
            /** @var list<array<int,mixed>> */
            public static array $calls = [];

            /** @var array{success:bool,affected_rows:int,db_error:?string,race:bool} */
            public static array $beginResult = [
                'success'       => true,
                'affected_rows' => 1,
                'db_error'      => null,
                'race'          => false,
            ];

            public static bool $quorumMet    = true;
            public static bool $setAdjResult = true;
            public static string $adjStatus  = 'completed';
            public static bool $commitResult = true;

            /**
             * @return array{success:bool,affected_rows:int,db_error:?string,race:bool}
             */
            public static function beginResolveTransaction(int $disputeId, string $outcome): array
            {
                self::$calls[] = ['beginResolveTransaction', $disputeId, $outcome];
                return self::$beginResult;
            }

            public static function commitTransaction(): bool
            {
                self::$calls[] = ['commitTransaction'];
                return self::$commitResult;
            }

            public static function rollbackTransaction(): void
            {
                self::$calls[] = ['rollbackTransaction'];
            }

            public static function invalidateDispute(int $disputeId): void
            {
                self::$calls[] = ['invalidateDispute', $disputeId];
            }

            public static function wasQuorumMetForDispute(int $disputeId): bool
            {
                self::$calls[] = ['wasQuorumMetForDispute', $disputeId];
                return self::$quorumMet;
            }

            public static function setAdjudicationStatus(int $disputeId, string $status): bool
            {
                self::$calls[] = ['setAdjudicationStatus', $disputeId, $status];
                return self::$setAdjResult;
            }

            public static function getAdjudicationStatus(int $disputeId): ?string
            {
                self::$calls[] = ['getAdjudicationStatus', $disputeId];
                return self::$adjStatus;
            }

            public static function reset(): void
            {
                self::$calls        = [];
                self::$beginResult  = [
                    'success'       => true,
                    'affected_rows' => 1,
                    'db_error'      => null,
                    'race'          => false,
                ];
                self::$quorumMet    = true;
                self::$setAdjResult = true;
                self::$adjStatus    = 'completed';
                self::$commitResult = true;
            }
        }
    }

    // §D5 outcome-match backfill collaborator. Added to DisputeResolver::handle()
    // after this test was written; the real repo reaches TableRegistry + $wpdb,
    // which a pure unit test must not touch — so stub it like the rest.
    if (!class_exists(__NAMESPACE__ . '\\DisputeParticipationRepository', false)) {
        final class DisputeParticipationRepository
        {
            /** @var list<array{0:int,1:string}> */
            public static array $calls = [];
            public static int $backfillResult = 0;

            public function backfillOutcomeMatch(int $disputeId, string $finalDecision): int
            {
                self::$calls[] = [$disputeId, $finalDecision];
                return self::$backfillResult;
            }

            public static function reset(): void
            {
                self::$calls          = [];
                self::$backfillResult = 0;
            }
        }
    }
}

// ── Disputes\Services\DisputeNotificationService ───────────────────────────

namespace BCC\Trust\Disputes\Services {

    if (!class_exists(__NAMESPACE__ . '\\DisputeNotificationService', false)) {
        final class DisputeNotificationService
        {
            /** @var list<array{0:string,1:array<int,mixed>}> */
            public static array $calls = [];
            public static bool $result = true;

            /** @param array<int,mixed> $args */
            public static function enqueueAsync(string $hook, array $args): bool
            {
                self::$calls[] = [$hook, $args];
                return self::$result;
            }

            public static function reset(): void
            {
                self::$calls  = [];
                self::$result = true;
            }
        }
    }
}

// ── Finally, load the REAL resolver — all collaborators are now stubbed ────

namespace {
    require_once __DIR__ . '/../../app/Domain/Disputes/Services/DisputeResolver.php';
}
