<?php

declare(strict_types=1);

/**
 * Stubs for the PR 6 provisioning-intent tests.
 *
 * ── LOAD ORDER IS LOAD-BEARING ──────────────────────────────────────────
 * The fuller `GatedGroupRepository` below is declared BEFORE
 * verify-collections-stubs.php is required, because that file declares a
 * narrower one and every fake in this tree is guarded by `class_exists`.
 * Whichever is declared first wins, so the richer definition has to go
 * first or the gate-write and compensation paths would have nothing to
 * call.
 *
 * ── WHY THE PEEPSO CLASSES ARE IN A SEPARATE FILE ───────────────────────
 * A class cannot be un-declared. "PeepSo Groups is not installed" is
 * therefore only reachable by NOT declaring it — which works because these
 * tests run in separate processes, and the test that wants that case simply
 * skips the require.
 */

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(GatedGroupRepository::class, false)) {
        final class GatedGroupRepository
        {
            public const META_KIND       = '_bcc_group_kind';
            public const META_CHAIN_ID   = '_bcc_gate_chain_id';
            public const META_CONTRACT   = '_bcc_gate_contract_address';
            public const META_MIN_BAL    = '_bcc_gate_min_balance';
            public const META_COLLECTION = '_bcc_gate_collection_id';
            public const KIND_HOLDERS    = 'holders';

            /** @var array<string, int> `chainId|canonical` => group id */
            public static array $groups = [];

            /** @var array<int, array<string, mixed>> group id => gate config */
            public static array $configs = [];

            /** Forces writeGateConfig() to refuse, as a corrupt identity would. */
            public static bool $writeRefuses = false;

            /** Drops the config immediately after writing, to fail the re-read. */
            public static bool $postconditionFails = false;

            /**
             * @var int how many times the existence read ran
             *
             * ⚠ Counted so "the lock is taken BEFORE the existence check" is
             * assertable. A lock placed AFTER this read leaves the #368 TOCTOU
             * window exactly as wide while every outcome still looks correct,
             * so the outcome assertions alone cannot catch it.
             */
            public static int $lookups = 0;

            public static function findGroupForCollection(int $chainId, string $contract): ?int
            {
                self::$lookups++;

                return self::$groups[$chainId . '|' . $contract] ?? null;
            }

            public static function writeGateConfig(
                int $groupId,
                int $chainId,
                string $chainFamily,
                string $canonicalAddress,
                int $minBalance,
                int $collectionId
            ): bool {
                if (self::$writeRefuses) {
                    return false;
                }

                self::$groups[$chainId . '|' . $canonicalAddress] = $groupId;

                if (!self::$postconditionFails) {
                    self::$configs[$groupId] = [
                        'groupId'         => $groupId,
                        'chainId'         => $chainId,
                        'contractAddress' => $canonicalAddress,
                        'minBalance'      => max(1, $minBalance),
                        'collectionId'    => $collectionId,
                    ];
                }

                return true;
            }

            public static function getGateConfig(int $groupId): ?object
            {
                $c = self::$configs[$groupId] ?? null;

                return $c === null ? null : (object) $c;
            }

            /** @return list<string> */
            public static function compensationResidue(int $groupId): array
            {
                $residue = [];

                if (\BccPeepSoSpy::$postSurvivesCompensation) {
                    $residue[] = 'post_remains';
                }
                if (isset(self::$configs[$groupId])) {
                    $residue[] = 'gate_meta_remains';
                }

                return $residue;
            }

            public static function reset(): void
            {
                self::$groups = [];
                self::$configs = [];
                self::$writeRefuses = false;
                self::$postconditionFails = false;
                self::$lookups = 0;
            }
        }
    }
}

namespace {

    require_once __DIR__ . '/verify-collections-stubs.php';

    if (!class_exists('BccPeepSoSpy')) {
        /** Observable record of everything the PeepSo half of the flow did. */
        final class BccPeepSoSpy
        {
            public static int $created = 0;
            public static int $deleted = 0;
            public static int $memberLeaves = 0;
            public static int $metaDeletes = 0;
            public static int $lastGroupId = 0;
            public static int $lastOwnerId = 0;
            public static bool $imageDirRemoved = false;

            /** Makes `new PeepSoGroup(null, …)` yield a 0-id group. */
            public static bool $createReturnsZero = false;

            /**
             * Make the PeepSo constructor THROW rather than return a 0-id
             * group. The two are different failure shapes and only this one
             * exercises the `finally` that releases the advisory lock (#368).
             */
            public static bool $throwOnCreate = false;

            /** Set false by the ONE test that needs PeepSo to look absent. */
            public static bool $classAvailable = true;

            /** Makes the residue check report the post as still present. */
            public static bool $postSurvivesCompensation = false;

            public static function reset(): void
            {
                self::$created = 0;
                self::$deleted = 0;
                self::$memberLeaves = 0;
                self::$metaDeletes = 0;
                self::$lastGroupId = 0;
                self::$lastOwnerId = 0;
                self::$imageDirRemoved = false;
                self::$createReturnsZero = false;
                self::$throwOnCreate = false;
                self::$classAvailable = true;
                self::$postSurvivesCompensation = false;
            }
        }
    }

    if (!class_exists('WP_Filesystem_Direct')) {
        final class WP_Filesystem_Direct
        {
            /** @param array<mixed> $args */
            public function __construct(array $args = []) {}

            public function rmdir(string $path, bool $recursive = false): bool
            {
                \BccPeepSoSpy::$imageDirRemoved = true;
                return true;
            }
        }
    }

    if (!function_exists('wp_delete_post')) {
        /** @return object|false */
        function wp_delete_post(int $postId, bool $forceDelete = false)
        {
            \BccPeepSoSpy::$deleted++;
            return (object) ['ID' => $postId];
        }
    }

    if (!function_exists('delete_post_meta')) {
        /**
         * Deleting a gate meta key removes the GATE, because in production the
         * five meta rows ARE the gate — there is no separate table. A fake
         * that counted the call without dropping the gate would let a
         * compensation test pass while the collection still resolved to a
         * community that no longer exists.
         *
         * @param mixed $value
         */
        function delete_post_meta(int $postId, string $key, $value = ''): bool
        {
            \BccPeepSoSpy::$metaDeletes++;

            $repo = \BCC\Trust\Onchain\Repositories\GatedGroupRepository::class;
            unset($repo::$configs[$postId]);
            foreach ($repo::$groups as $mapKey => $groupId) {
                if ((int) $groupId === $postId) {
                    unset($repo::$groups[$mapKey]);
                }
            }

            return true;
        }
    }

    if (!function_exists('do_action')) {
        /** @param mixed ...$args */
        function do_action(string $hook, ...$args): void {}
    }
}

namespace BCC\Core\Observability {

    if (!class_exists(DegradationMetrics::class, false)) {
        /**
         * Records the subsystem/event pairs the provisioning path emits, so a
         * test can assert that PR 6 added NO new event — the taxonomy is
         * owned by bcc-core and a fourth `gated_group_provision` event would
         * need three repositories to change together.
         */
        final class DegradationMetrics
        {
            /** @var list<array{subsystem: string, event: string}> */
            public static array $recorded = [];

            public static function record(string $subsystem, string $event): void
            {
                self::$recorded[] = ['subsystem' => $subsystem, 'event' => $event];
            }

            public static function reset(): void
            {
                self::$recorded = [];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {

    // The compensation path stats the album directory before removing it.
    // Answering true is what makes the removal branch reachable at all.
    if (!function_exists(__NAMESPACE__ . '\\is_dir')) {
        function is_dir(string $path): bool
        {
            return $path !== '';
        }
    }
}

namespace BCC\Core\DB {

    if (!class_exists(AdvisoryLock::class, false)) {
        /**
         * A SPY over the MySQL advisory lock, with REAL mutual exclusion.
         *
         * ⚠ THIS STUB IS NOT OPTIONAL FOR THESE SUITES. Since #368,
         * `GatedGroupProvisioningService::provisionOne()` acquires a
         * per-collection lock and FAILS CLOSED when the primitive is absent —
         * so without this class every provision in these suites returns
         * `busy` and creates nothing. That is the correct production
         * behaviour (an unlockable create is the duplicate the lock exists to
         * stop), which is exactly why the fixture has to supply the lock
         * rather than the service being made lenient for tests.
         *
         * `acquire()` genuinely refuses a name already held, so contention can
         * be driven from one process. Every acquire and release is recorded in
         * order, so "the lock was released" is OBSERVED rather than inferred.
         *
         * ⚠ IT DOES NOT MODEL WAITING. Real `GET_LOCK(name, 5)` blocks for up
         * to five seconds before refusing, which is what usually turns a
         * double-submit into `exists` rather than `busy`. That is a property
         * of MySQL and of `LOCK_WAIT_SECONDS`; this fixture pins only the two
         * outcomes the service controls — refused-and-created-nothing, and
         * released-on-every-path.
         *
         * Shape deliberately identical to the one in `hall-provision-stubs.php`
         * so a reader comparing the two locks compares behaviour, not harness.
         */
        final class AdvisoryLock
        {
            /** Global override: false makes every acquisition fail. */
            public static bool $acquirable = true;

            /** @var array<string, bool> name => held */
            public static array $held = [];

            /** @var list<string> every acquire attempt, in order */
            public static array $acquired = [];

            /** @var list<string> every release call, in order */
            public static array $released = [];

            /**
             * @var list<int> the timeout each acquire asked for
             *
             * Recorded because a non-zero wait is load-bearing behaviour, not
             * a detail: it is what turns an ordinary double-submit into
             * `exists` instead of `busy`. A mutation to 0 would still pass
             * every outcome assertion.
             */
            public static array $timeouts = [];

            public static function reset(): void
            {
                self::$acquirable = true;
                self::$held       = [];
                self::$acquired   = [];
                self::$released   = [];
                self::$timeouts   = [];
            }

            public static function acquire(string $key, int $timeout = 0): bool
            {
                self::$acquired[] = $key;
                self::$timeouts[] = $timeout;

                if (!self::$acquirable || isset(self::$held[$key])) {
                    return false;
                }
                self::$held[$key] = true;

                return true;
            }

            public static function release(string $key): void
            {
                self::$released[] = $key;
                unset(self::$held[$key]);
            }
        }
    }
}
