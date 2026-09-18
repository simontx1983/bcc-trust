<?php

declare(strict_types=1);

/**
 * Doubles for running the REAL {@see \BCC\Trust\Onchain\Services\CosmwasmDiscoveryService::emitCollections()}
 * against the REAL {@see \BCC\Trust\Onchain\Fetchers\CosmosFetcher},
 * {@see \BCC\Trust\Onchain\Support\ApiRetry}, {@see \BCC\Trust\Onchain\Support\OnchainCircuitBreaker},
 * {@see \BCC\Trust\Onchain\Support\ProviderRequestBudget}, {@see \BCC\Trust\Onchain\Services\CosmwasmClassifier}
 * and {@see \BCC\Trust\Onchain\Services\NftSpamFilter}.
 *
 * ── WHAT IS FAKED, AND WHY ONLY THIS ────────────────────────────────────
 * The claims are about REQUESTS, CHARGES, BUDGET and QUEUE ORDER. Every rule
 * that decides those runs for real, including the retry loop and the breaker —
 * "zero charges" is only evidence if the production breaker produced it.
 *
 * Faked at their production FQNs, and nothing else:
 *   - the contract / collection / spam-rule repositories (in-memory rows),
 *   - `apply_filters` (the spam heuristics hook).
 *
 * ⚠ THE FAKE QUEUE DELIBERATELY APPLIES NO HOLD. `findEmittable()` here
 * returns every seeded candidate in id order, exactly as the SQL does today,
 * so the skip under test is the production service's decision and not
 * something the double arranged. The real SQL's own behaviour is covered by
 * the integration suite.
 *
 * ⚠ Requires `enumeration-real-transport-stubs.php` for the scripted wire, the
 * WP shims and the real-breaker substrate. Nothing here re-declares any of it.
 *
 * ⚠ Suites using this MUST run in separate processes: these classes shadow
 * real ones by FQN, so they must be declared before the autoloader sees the
 * real names.
 *
 * @package BCC\Trust\Onchain\Tests\Stubs
 */

namespace {
    // A braced-namespace file may have no code outside its blocks, so the
    // require lives inside this one.
    require_once __DIR__ . '/enumeration-real-transport-stubs.php';

    /** In-memory state the emission fakes read and write. */
    final class BccEmissionWorld
    {
        /** @var list<object> candidate rows, in the order findEmittable() returns them */
        public static array $candidates = [];

        /** @var array<string, true> contract => already a verified/known collection */
        public static array $known = [];

        /** @var array<string, string|null> contract => spam rule (deny/allow/null) */
        public static array $rules = [];

        /** @var list<string> every write, in order — lets a test prove "no write happened" */
        public static array $writes = [];

        /** @var list<array<string, mixed>> rows handed to CollectionRepository::bulkUpsert() */
        public static array $upserts = [];

        /** @var array<string, string> contract => imported description */
        public static array $descriptions = [];

        public static function reset(): void
        {
            self::$candidates   = [];
            self::$known        = [];
            self::$rules        = [];
            self::$writes       = [];
            self::$upserts      = [];
            self::$descriptions = [];
        }

        /**
         * Seed one emission candidate.
         *
         * Mirrors the columns `findEmittable()` selects; the defaults are a
         * plain CONFIRMED contract, which is what most of the queue is.
         */
        public static function candidate(
            int $id,
            string $contract,
            string $classification = 'confirmed_cw721',
            ?string $reason = 'num_tokens_and_info',
            int $codeId = 100
        ): void {
            self::$candidates[] = (object) [
                'id'                     => (string) $id,
                'chain_id'               => '8',
                'contract_address'       => $contract,
                'code_id'                => (string) $codeId,
                'classification'         => $classification,
                'classification_reason'  => $reason,
                'probes_ok'              => null,
                'probes_failed'          => null,
                'classifier_version'     => '2',
                'classified_at'          => '2026-09-07 12:18:09',
                'last_attempted_at'      => '2026-09-07 12:18:09',
                'next_attempt_at'        => null,
                'retry_count'            => '0',
                'last_error'             => null,
                'denied'                 => '0',
                'collection_row_written' => '0',
                'migrated_at'            => null,
                'discovered_at'          => '2026-09-04 02:54:14',
            ];
        }
    }

    if (!function_exists('apply_filters')) {
        /**
         * @param  mixed $value
         * @return mixed
         */
        function apply_filters(string $hook, $value, ...$args)
        {
            return $value;
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    final class CosmwasmContractRepository
    {
        /** @return list<object> */
        public static function findEmittable(int $chainId, int $limit): array
        {
            return array_slice(\BccEmissionWorld::$candidates, 0, max(1, $limit));
        }

        public static function markCollectionRowWritten(int $chainId, string $contract): bool
        {
            \BccEmissionWorld::$writes[] = "mark_written:{$contract}";

            return true;
        }

        public static function setDenied(int $chainId, string $contract, bool $denied): bool
        {
            \BccEmissionWorld::$writes[] = 'set_denied:' . $contract . ':' . ($denied ? '1' : '0');

            return true;
        }

        /** Present so a stray call is a loud failure rather than a silent one. */
        public static function recordAttemptFailure(int $chainId, string $contract, string $error, int $retry): bool
        {
            \BccEmissionWorld::$writes[] = "record_attempt_failure:{$contract}";

            return true;
        }

        /** Present for the same reason: emission must never reclassify. */
        public static function recordClassification(int $chainId, string $contract, array $verdict, int $retry): bool
        {
            \BccEmissionWorld::$writes[] = "record_classification:{$contract}";

            return true;
        }
    }

    final class CollectionRepository
    {
        /**
         * @param  list<string> $contracts
         * @return array<string, object>
         */
        public static function verifiedMapForContracts(int $chainId, array $contracts): array
        {
            $out = [];
            foreach ($contracts as $contract) {
                if (isset(\BccEmissionWorld::$known[strtolower($contract)])) {
                    $out[$contract] = (object) ['id' => '1'];
                }
            }

            return $out;
        }

        /** @param list<array<string, mixed>> $collections */
        public static function bulkUpsert(array $collections, int $ttlSeconds = 0): int
        {
            foreach ($collections as $row) {
                \BccEmissionWorld::$writes[] = 'upsert:' . (string) ($row['contract_address'] ?? '?');
                \BccEmissionWorld::$upserts[] = $row;
            }

            return count($collections);
        }

        public static function findByChainContract(int $chainId, string $contract): ?object
        {
            return (object) ['id' => '501'];
        }

        /** @param mixed $rawDescription */
        public static function importChainDescription(int $collectionId, $rawDescription, string $source): bool
        {
            \BccEmissionWorld::$writes[] = "import_description:{$collectionId}";
            \BccEmissionWorld::$descriptions[(string) $collectionId] = (string) $rawDescription;

            return true;
        }
    }

    final class NftSpamContractRepository
    {
        public const RULE_DENY  = 'deny';
        public const RULE_ALLOW = 'allow';

        public static function getRule(int $chainId, string $contract): ?string
        {
            return \BccEmissionWorld::$rules[strtolower($contract)] ?? null;
        }
    }
}
