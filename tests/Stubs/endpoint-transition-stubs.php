<?php

declare(strict_types=1);

/**
 * Doubles for running the REAL {@see \BCC\Trust\Onchain\Services\CosmosEndpointTransition::execute()}
 * against the REAL {@see \BCC\Trust\Onchain\Support\OnchainCircuitBreaker}
 * and the REAL {@see \BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy}.
 *
 * ── WHAT IS FAKED, AND WHY ONLY THIS ────────────────────────────────────
 * The claims under test are about ORDER and SCOPE: that the switch takes the
 * worker's lock, clears the old provider's breaker only after the row moved,
 * and records the new endpoint's fingerprint. Those are decided by the
 * transition itself, so it runs for real. The breaker runs for real too,
 * because "the breaker was cleared" is only evidence if the production
 * clearing code produced it.
 *
 * Faked at their production FQNs, and nothing else:
 *   - the chain / family / checkpoint repositories (in-memory rows),
 *   - the identity verifier (no network),
 *   - AdminActionSupport::audit (records the payload).
 *
 * ⚠ Requires `breaker-real-stubs.php` for the option/transient/cache surface,
 * the faithful non-blocking AdvisoryLock and the counter repository. Nothing
 * here re-declares any of that.
 *
 * ⚠ Suites using this MUST run in separate processes: these classes shadow
 * real ones by FQN, so they have to be declared before the autoloader ever
 * sees the real names.
 *
 * @package BCC\Trust\Onchain\Tests\Stubs
 */

namespace {
    // Inside the braced global block: a file with braced namespaces may have
    // no code outside them, and a bare require at the top is a parse error.
    require_once __DIR__ . '/breaker-real-stubs.php';

    /** In-memory state the fakes read and write. */
    final class BccTransitionWorld
    {
        /** @var array<int, object> */
        public static array $chains = [];

        /** @var array<int, array<int, string|null>> chainId => [codeId => cursor] */
        public static array $cursors = [];

        /** @var array<int, object> */
        public static array $checkpoints = [];

        /** @var list<array{action: string, type: string, id: int, meta: array<string, mixed>}> */
        public static array $audits = [];

        /** @var list<string> every write, in order — lets a test prove "no write happened" */
        public static array $writes = [];

        public static bool $verifyOk = true;

        public static function reset(): void
        {
            self::$chains      = [];
            self::$cursors     = [];
            self::$checkpoints = [];
            self::$audits      = [];
            self::$writes      = [];
            self::$verifyOk    = true;
        }

        public static function seedChain8(string $restUrl, array $cursorCodeIds): void
        {
            self::$chains[8] = (object) [
                'id'       => '8',
                'slug'     => 'cosmos',
                'rest_url' => $restUrl,
                'rpc_url'  => 'https://rpc.cosmos.directory/cosmoshub',
            ];
            self::$cursors[8] = [];
            foreach ($cursorCodeIds as $codeId) {
                self::$cursors[8][(int) $codeId] = 'opaque-node-minted-key';
            }
            self::$checkpoints[8] = (object) ['cw_code_cursor' => null, 'cw_max_code_id' => 742];
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    final class ChainRepository
    {
        public static function getById(int $id): ?object
        {
            return isset(\BccTransitionWorld::$chains[$id]) ? clone \BccTransitionWorld::$chains[$id] : null;
        }

        public static function updateRestUrl(int $chainId, string $restUrl): bool
        {
            \BccTransitionWorld::$writes[] = "chain.rest_url={$restUrl}";
            if (!isset(\BccTransitionWorld::$chains[$chainId])) {
                return false;
            }
            \BccTransitionWorld::$chains[$chainId]->rest_url = $restUrl;

            return true;
        }
    }

    final class CosmwasmCodeFamilyRepository
    {
        /** @return list<int> */
        public static function openContractCursorCodeIds(int $chainId): array
        {
            $ids = [];
            foreach (\BccTransitionWorld::$cursors[$chainId] ?? [] as $codeId => $cursor) {
                if ($cursor !== null && $cursor !== '') {
                    $ids[] = (int) $codeId;
                }
            }
            sort($ids);

            return $ids;
        }

        public static function clearContractCursors(int $chainId): int
        {
            \BccTransitionWorld::$writes[] = 'families.clear_cursors';
            $n = 0;
            foreach (\BccTransitionWorld::$cursors[$chainId] ?? [] as $codeId => $cursor) {
                if ($cursor !== null && $cursor !== '') {
                    \BccTransitionWorld::$cursors[$chainId][$codeId] = null;
                    $n++;
                }
            }

            return $n;
        }

        public static function countOpenContractCursors(int $chainId): int
        {
            return count(self::openContractCursorCodeIds($chainId));
        }
    }

    final class ChainCheckpointRepository
    {
        public static function get(int $chainId): ?object
        {
            return \BccTransitionWorld::$checkpoints[$chainId] ?? null;
        }

        public static function requestCwBackfillRestart(int $chainId, string $reason): bool
        {
            \BccTransitionWorld::$writes[] = "checkpoint.restart={$reason}";

            return true;
        }
    }
}

namespace BCC\Trust\Onchain\Support {
    /** Identity verification without the network. */
    final class CosmosEndpointVerifier
    {
        /** @return array{ok: bool, reason: string, network: string|null, fingerprint: string|null} */
        public static function verify(object $chain, bool $useCache = true): array
        {
            return \BccTransitionWorld::$verifyOk
                ? ['ok' => true, 'reason' => 'ok', 'network' => 'cosmoshub-4', 'fingerprint' => null]
                : ['ok' => false, 'reason' => 'network_mismatch', 'network' => null, 'fingerprint' => null];
        }
    }
}

namespace BCC\Trust\Onchain\Admin {
    /** Records the audit payload instead of writing a row. */
    final class AdminActionSupport
    {
        /** @param array<string, mixed> $meta */
        public static function audit(string $action, string $targetType, int $targetId, array $meta = []): void
        {
            \BccTransitionWorld::$writes[] = "audit.{$action}";
            \BccTransitionWorld::$audits[] = ['action' => $action, 'type' => $targetType, 'id' => $targetId, 'meta' => $meta];
        }
    }
}
