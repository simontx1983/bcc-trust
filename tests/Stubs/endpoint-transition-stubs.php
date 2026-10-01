<?php

declare(strict_types=1);

/**
 * Fakes for the audited Cosmos endpoint switch.
 *
 * ── WHAT IS REAL HERE, AND WHY ──────────────────────────────────────────
 * Only the collaborators that touch a database or a network are faked. The
 * policy, the deny-by-default descriptor, the review store, the circuit
 * breaker and the transition itself are the real classes, because every
 * property worth asserting lives in how they interact:
 *
 *   - the review store is real, so "a replacement review retires the older
 *     confirmation" is a property of the code and not of a fixture;
 *   - the breaker is real (see breaker-real-stubs.php), so "the breaker is not
 *     cleared when the endpoint cannot be confirmed" is observable;
 *   - the policy is real, so approval is exact rather than permissive.
 *
 * ⚠ `ChainRepository::updateRestUrl()` is faked, and its fake re-implements the
 * compare-and-swap IN PHP with `===`. That is deliberate: these tests pin the
 * ORDERING and the RESULT MODEL around the write. Whether the SQL itself is
 * byte-exact is a question only a database can answer, and
 * `CosmosEndpointSwitchCasIntegrationTest` answers it on both engines.
 *
 * @package BCC_Trust
 * @subpackage Tests
 */

namespace {

require_once __DIR__ . '/breaker-real-stubs.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!class_exists('BccTransitionWorld', false)) {
    final class BccTransitionWorld
    {
        /** @var array<int, object> */
        public static array $chains = [];

        /** @var list<string> every write, in order, for an ordering assertion */
        public static array $writes = [];

        /** @var list<array{action: string, target: int, meta: array<string, mixed>}> */
        public static array $audits = [];

        /** @var int how many times the chains cache was busted */
        public static int $cacheBusts = 0;

        public static bool $verifyOk = true;

        public static string $verifyReason = 'network_mismatch';

        /** Force the CAS result: null = simulate it honestly, else return this. */
        public static ?int $forceCasResult = null;

        /** Make the durable audit row fail, to exercise the follow-up report. */
        public static bool $auditOk = true;

        /** Make the post-write read-back unavailable (null row). */
        public static bool $readBackAvailable = true;

        /** Replace the row the read-back sees, to simulate a superseding write. */
        public static ?string $readBackRestUrl = null;

        /** Runs after the proof and before the CAS — the race seam. */
        public static mixed $afterVerify = null;

        public static function reset(): void
        {
            self::$chains            = [];
            self::$writes            = [];
            self::$audits            = [];
            self::$cacheBusts        = 0;
            self::$verifyOk          = true;
            self::$verifyReason      = 'network_mismatch';
            self::$forceCasResult    = null;
            self::$auditOk           = true;
            self::$readBackAvailable = true;
            self::$readBackRestUrl   = null;
            self::$afterVerify       = null;
            \BccBreakerStore::reset();
            \BccBreakerStore::$deleteTransientFails = false;
            \BccBreakerStore::$deleteCounterThrows = false;
        }

        public static function seedChain8(?string $restUrl, string $slug = 'cosmos', int $isActive = 1): void
        {
            self::$chains[8] = (object) [
                'id'        => '8',
                'slug'      => $slug,
                'rest_url'  => $restUrl,
                'rpc_url'   => 'https://rpc.cosmos.directory/cosmoshub',
                // The switch gates on this, so a fixture without it would be
                // refused as `chain_inactive` before anything interesting ran.
                'is_active' => $isActive,
            ];
        }
    }
}

}

namespace BCC\Trust\Onchain\Repositories {

if (!class_exists(ChainRepository::class, false)) {
    final class ChainRepository
    {
        public static function getById(int $id): ?object
        {
            if (!\BccTransitionWorld::$readBackAvailable && \BccTransitionWorld::$writes !== []) {
                // Only the POST-write read fails; the pre-write reads must
                // succeed or the test would be measuring the wrong refusal.
                return null;
            }

            $row = \BccTransitionWorld::$chains[$id] ?? null;
            if ($row === null) {
                return null;
            }

            if (\BccTransitionWorld::$readBackRestUrl !== null && \BccTransitionWorld::$writes !== []) {
                $clone = clone $row;
                $clone->rest_url = \BccTransitionWorld::$readBackRestUrl;

                return $clone;
            }

            return $row;
        }

        /**
         * The compare-and-swap, re-implemented with `===` so the ordering and
         * result model can be tested without a database.
         *
         * @param array{rest_url: string|null, slug: string, is_active: int} $expected
         */
        public static function updateRestUrl(int $chainId, string $restUrl, array $expected): int
        {
            if (\BccTransitionWorld::$forceCasResult !== null) {
                $forced = \BccTransitionWorld::$forceCasResult;
                if ($forced !== 0) {
                    self::clearCache();
                }
                if ($forced > 0) {
                    \BccTransitionWorld::$writes[] = 'chain.rest_url=' . $restUrl;
                }

                return $forced;
            }

            $row = \BccTransitionWorld::$chains[$chainId] ?? null;
            if ($row === null) {
                return 0;
            }

            $storedRest = $row->rest_url ?? null;
            $matches = $storedRest === ($expected['rest_url'] ?? null)
                && (string) ($row->slug ?? '') === (string) $expected['slug']
                && (int) ($row->is_active ?? 0) === (int) $expected['is_active'];

            if (!$matches) {
                return 0;
            }

            $row->rest_url = $restUrl;
            \BccTransitionWorld::$writes[] = 'chain.rest_url=' . $restUrl;
            self::clearCache();

            return 1;
        }

        public static function clearCache(): void
        {
            \BccTransitionWorld::$cacheBusts++;
        }
    }
}

}

namespace BCC\Trust\Core\Security {

if (!class_exists(AuditLogger::class, false)) {
    final class AuditLogger
    {
        /** @param array<string, mixed> $meta */
        public static function logChecked(
            string $action,
            ?int $targetId = null,
            array $meta = [],
            ?string $targetType = null,
            ?int $userId = null
        ): ?int {
            if (!\BccTransitionWorld::$auditOk) {
                return null;
            }

            \BccTransitionWorld::$audits[] = [
                'action' => $action,
                'target' => (int) $targetId,
                'meta'   => $meta,
            ];
            \BccTransitionWorld::$writes[] = 'audit.' . $action;

            return count(\BccTransitionWorld::$audits);
        }
    }
}

}

namespace BCC\Trust\Onchain\Support {

if (!class_exists(CosmosEndpointVerifier::class, false)) {
    final class CosmosEndpointVerifier
    {
        /** @return array{ok: bool, reason: string, network: string|null} */
        public static function verify(object $chain, bool $useCache = true): array
        {
            \BccTransitionWorld::$writes[] = 'verify(' . (string) ($chain->rest_url ?? '') . ')';

            if (!\BccTransitionWorld::$verifyOk) {
                return [
                    'ok'      => false,
                    'reason'  => \BccTransitionWorld::$verifyReason,
                    'network' => null,
                ];
            }

            // The race seam: anything that changes the row between the proof
            // and the swap runs here.
            if (is_callable(\BccTransitionWorld::$afterVerify)) {
                (\BccTransitionWorld::$afterVerify)();
            }

            return ['ok' => true, 'reason' => 'ok', 'network' => 'cosmoshub-4'];
        }
    }
}

}
