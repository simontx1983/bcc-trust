<?php

/**
 * CosmWasm CW-721 discovery test stubs.
 *
 * Loaded ONLY from inside #[RunTestsInSeparateProcesses] subprocesses so
 * the main PHPUnit process never sees these definitions — the same
 * isolation strategy as nft-indexer-stubs.php / resolver-stubs.php.
 *
 * The REAL classes under test load via PSR-4 in the subprocess:
 *   CosmosFetcher, CosmwasmClassifier, CosmwasmDiscoveryService,
 *   CosmwasmDiscoveryGate, CosmwasmTickBudget, CosmwasmDiscoveryWorker,
 *   NftSpamFilter.
 *
 * Everything they touch that would need a database or a network — the two
 * cosmwasm repositories, the collections repository, the chain checkpoint
 * repository, the operator spam-rule repository, the fetcher factory — is
 * stubbed here at the PRODUCTION FQN with `class_exists(..., false)`
 * guards so the autoloader leaves them alone.
 *
 * ORDER MATTERS: these definitions come FIRST, then nft-indexer-stubs.php
 * is required for the shared WP shims / Logger / ApiRetry transport fake /
 * AdvisoryLock / OnchainCircuitBreaker. Its own guards then skip anything
 * defined here, so the richer versions below win.
 *
 * CI never touches a public RPC: every chain response is queued or keyed
 * by URL on the ApiRetry fake.
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(__NAMESPACE__ . '\\CosmwasmCodeFamilyRepository', false)) {
        /** In-memory code-family inventory. */
        final class CosmwasmCodeFamilyRepository
        {
            /** @var array<int, array<int, object>> chainId => codeId => row */
            public static array $families = [];

            /** @var list<array{chain_id: int, code_id: int, verdict: array<string, mixed>, sample: ?string, retry: int}> */
            public static array $classifications = [];

            /** @var list<array{chain_id: int, code_id: int}> */
            public static array $closed = [];

            /** @var list<array{chain_id: int, code_id: int, error: string, retry: int}> */
            public static array $failures = [];

            /** @var list<array{chain_id: int, version: int, affected: list<string>|null}> */
            public static array $requeues = [];

            /** @var list<array{chain_id: int, code_id: int, cursor: ?string, position: int, complete: bool}> */
            public static array $progress = [];

            /** @var list<array{chain_id: int, code_id: int}> */
            public static array $metadataTouches = [];

            public static function reset(): void
            {
                self::$families        = [];
                self::$classifications = [];
                self::$closed          = [];
                self::$failures        = [];
                self::$requeues        = [];
                self::$progress        = [];
                self::$metadataTouches = [];
            }

            /** Test helper: seed one family row. */
            public static function seed(int $chainId, int $codeId, string $classification = 'inconclusive', ?string $checksum = null, int $classifierVersion = 0, ?string $classifiedAt = null): void
            {
                self::$families[$chainId][$codeId] = (object) [
                    'id'                    => (string) (count(self::$families[$chainId] ?? []) + 1),
                    'chain_id'              => (string) $chainId,
                    'code_id'               => (string) $codeId,
                    'checksum'              => $checksum,
                    'classification'        => $classification,
                    'classification_reason' => null,
                    'probes_ok'             => null,
                    'probes_failed'         => null,
                    'classifier_version'    => (string) $classifierVersion,
                    'sample_contract'       => null,
                    'classified_at'         => $classifiedAt,
                    'last_attempted_at'     => null,
                    'next_attempt_at'       => null,
                    'retry_count'           => '0',
                    'last_error'            => null,
                    'contracts_cursor'      => null,
                    'contracts_enumerated'  => '0',
                    'enumeration_complete'  => '0',
                    'last_enumerated_at'    => null,
                    'metadata_checked_at'   => null,
                    'created_at'            => '2026-08-01 00:00:00',
                ];
            }

            /** @param list<array{code_id: int, checksum: ?string}> $families */
            public static function recordDiscovered(int $chainId, array $families): int
            {
                $written = 0;
                foreach ($families as $family) {
                    $codeId = (int) $family['code_id'];
                    if ($codeId <= 0) {
                        continue;
                    }
                    if (isset(self::$families[$chainId][$codeId])) {
                        // Idempotent: refresh only the checksum, never the verdict.
                        if ($family['checksum'] !== null) {
                            self::$families[$chainId][$codeId]->checksum = $family['checksum'];
                        }
                        continue;
                    }
                    self::seed($chainId, $codeId, 'inconclusive', $family['checksum']);
                    $written++;
                }

                return $written;
            }

            public static function find(int $chainId, int $codeId): ?object
            {
                return self::$families[$chainId][$codeId] ?? null;
            }

            /**
             * @param  list<int> $priorityCodeIds
             * @return list<object>
             */
            public static function findPendingClassification(int $chainId, int $limit, int $classifierVersion, array $priorityCodeIds = []): array
            {
                $rows = [];
                foreach (self::$families[$chainId] ?? [] as $row) {
                    $classification = (string) $row->classification;
                    if (in_array($classification, ['not_cw721', 'confirmed_cw721', 'probable_cw721'], true)) {
                        continue;
                    }
                    if ((int) $row->retry_count >= 6) {
                        continue;
                    }
                    if ($row->classified_at !== null && (int) $row->classifier_version >= $classifierVersion) {
                        continue;
                    }
                    $rows[] = $row;
                }

                usort($rows, static function (object $a, object $b) use ($priorityCodeIds): int {
                    $pa = in_array((int) $a->code_id, $priorityCodeIds, true) ? 0 : 1;
                    $pb = in_array((int) $b->code_id, $priorityCodeIds, true) ? 0 : 1;

                    return $pa === $pb ? ((int) $a->code_id <=> (int) $b->code_id) : ($pa <=> $pb);
                });

                return array_slice($rows, 0, $limit);
            }

            /** @return list<object> */
            public static function findEnumerable(int $chainId, int $limit, bool $onlyIncomplete): array
            {
                $rows = [];
                foreach (self::$families[$chainId] ?? [] as $row) {
                    if (!in_array((string) $row->classification, ['confirmed_cw721', 'probable_cw721'], true)) {
                        continue;
                    }
                    if ($onlyIncomplete && (int) $row->enumeration_complete === 1) {
                        continue;
                    }
                    $rows[] = $row;
                }

                return array_slice($rows, 0, $limit);
            }

            public static function findClassifiedTwinByChecksum(int $chainId, string $checksum, int $excludeCodeId, int $classifierVersion): ?object
            {
                foreach (self::$families[$chainId] ?? [] as $row) {
                    if ((int) $row->code_id === $excludeCodeId) {
                        continue;
                    }
                    if ($row->checksum === null || strtolower((string) $row->checksum) !== strtolower($checksum)) {
                        continue;
                    }
                    if ($row->classified_at === null || (int) $row->classifier_version < $classifierVersion) {
                        continue;
                    }
                    if (!in_array((string) $row->classification, ['confirmed_cw721', 'probable_cw721', 'not_cw721'], true)) {
                        continue;
                    }

                    return $row;
                }

                return null;
            }

            /** @param array<string, mixed> $verdict */
            public static function recordClassification(int $chainId, int $codeId, array $verdict, ?string $sampleContract, int $retryCount): bool
            {
                self::$classifications[] = [
                    'chain_id' => $chainId,
                    'code_id'  => $codeId,
                    'verdict'  => $verdict,
                    'sample'   => $sampleContract,
                    'retry'    => $retryCount,
                ];
                if (isset(self::$families[$chainId][$codeId])) {
                    $row                     = self::$families[$chainId][$codeId];
                    $row->classification     = (string) $verdict['classification'];
                    $row->classification_reason = (string) $verdict['reason'];
                    $row->classifier_version = (string) $verdict['classifier_version'];
                    $row->classified_at      = '2026-08-06 00:00:00';
                    $row->sample_contract    = $sampleContract;
                    $row->retry_count        = (string) $retryCount;
                }

                return true;
            }

            public static function recordEnumerationProgress(int $chainId, int $codeId, ?string $cursor, int $position, bool $complete): bool
            {
                self::$progress[] = [
                    'chain_id' => $chainId,
                    'code_id'  => $codeId,
                    'cursor'   => $cursor,
                    'position' => $position,
                    'complete' => $complete,
                ];
                if (isset(self::$families[$chainId][$codeId])) {
                    $row                       = self::$families[$chainId][$codeId];
                    $row->contracts_cursor     = $cursor;
                    $row->contracts_enumerated = (string) max((int) $row->contracts_enumerated, $position);
                    $row->enumeration_complete = $complete ? '1' : '0';
                }

                return true;
            }

            public static function closeEnumeration(int $chainId, int $codeId): bool
            {
                self::$closed[] = ['chain_id' => $chainId, 'code_id' => $codeId];
                if (isset(self::$families[$chainId][$codeId])) {
                    self::$families[$chainId][$codeId]->enumeration_complete = '1';
                    self::$families[$chainId][$codeId]->contracts_cursor     = null;
                }

                return true;
            }

            public static function recordAttemptFailure(int $chainId, int $codeId, string $error, int $retryCount): bool
            {
                self::$failures[] = [
                    'chain_id' => $chainId,
                    'code_id'  => $codeId,
                    'error'    => $error,
                    'retry'    => $retryCount,
                ];
                if (isset(self::$families[$chainId][$codeId])) {
                    self::$families[$chainId][$codeId]->retry_count = (string) $retryCount;
                }

                return true;
            }

            /** @param list<string>|null $affected */
            public static function requeueForClassifierVersion(int $chainId, int $classifierVersion, int $limit, ?array $affected = null): int
            {
                $affected = $affected ?? \BCC\Trust\Onchain\Services\CosmwasmClassifier::requeueableClassifications();
                self::$requeues[] = ['chain_id' => $chainId, 'version' => $classifierVersion, 'affected' => $affected];

                $count = 0;
                foreach (self::$families[$chainId] ?? [] as $row) {
                    if (!in_array((string) $row->classification, $affected, true)) {
                        continue;
                    }
                    if ((int) $row->classifier_version >= $classifierVersion) {
                        continue;
                    }
                    $row->classified_at   = null;
                    $row->next_attempt_at = null;
                    $row->retry_count     = '0';
                    $count++;
                    if ($count >= $limit) {
                        break;
                    }
                }

                return $count;
            }

            /** @return array<string, int> */
            public static function countsByClassification(int $chainId): array
            {
                $out = [];
                foreach (\BCC\Trust\Onchain\Services\CosmwasmClassifier::allClassifications() as $classification) {
                    $out[$classification] = 0;
                }
                foreach (self::$families[$chainId] ?? [] as $row) {
                    $out[(string) $row->classification] = ($out[(string) $row->classification] ?? 0) + 1;
                }

                return $out;
            }

            public static function countForChain(int $chainId): int
            {
                return count(self::$families[$chainId] ?? []);
            }

            public static function countPendingClassification(int $chainId, int $classifierVersion): int
            {
                return count(self::findPendingClassification($chainId, 1000, $classifierVersion));
            }

            /** @return list<object> */
            public static function findDueForMetadataCheck(int $chainId, string $cutoff, int $limit): array
            {
                $rows = [];
                foreach (self::$families[$chainId] ?? [] as $row) {
                    if (!in_array((string) $row->classification, ['confirmed_cw721', 'probable_cw721'], true)) {
                        continue;
                    }
                    if ($row->metadata_checked_at !== null && (string) $row->metadata_checked_at > $cutoff) {
                        continue;
                    }
                    $rows[] = $row;
                }

                return array_slice($rows, 0, $limit);
            }

            public static function touchMetadataChecked(int $chainId, int $codeId): bool
            {
                self::$metadataTouches[] = ['chain_id' => $chainId, 'code_id' => $codeId];
                if (isset(self::$families[$chainId][$codeId])) {
                    self::$families[$chainId][$codeId]->metadata_checked_at = '2026-08-06 00:00:00';
                }

                return true;
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\CosmwasmContractRepository', false)) {
        /** In-memory contract inventory. */
        final class CosmwasmContractRepository
        {
            /** @var array<int, array<string, object>> chainId => address => row */
            public static array $contracts = [];

            /** @var list<array{chain_id: int, contract: string, verdict: array<string, mixed>, retry: int}> */
            public static array $classifications = [];

            /** @var list<array{chain_id: int, contract: string, code_id: int}> */
            public static array $migrations = [];

            /** @var list<string> */
            public static array $marked = [];

            public static function reset(): void
            {
                self::$contracts       = [];
                self::$classifications = [];
                self::$migrations      = [];
                self::$marked          = [];
            }

            public static function seed(int $chainId, string $address, string $classification = 'inconclusive', int $codeId = 0, bool $denied = false, bool $written = false, int $classifierVersion = 0, ?string $classifiedAt = null): void
            {
                $address = strtolower($address);
                self::$contracts[$chainId][$address] = (object) [
                    'id'                     => (string) (count(self::$contracts[$chainId] ?? []) + 1),
                    'chain_id'               => (string) $chainId,
                    'contract_address'       => $address,
                    'code_id'                => (string) $codeId,
                    'classification'         => $classification,
                    'classification_reason'  => null,
                    'probes_ok'              => null,
                    'probes_failed'          => null,
                    'classifier_version'     => (string) $classifierVersion,
                    'classified_at'          => $classifiedAt,
                    'last_attempted_at'      => null,
                    'next_attempt_at'        => null,
                    'retry_count'            => '0',
                    'last_error'             => null,
                    'denied'                 => $denied ? '1' : '0',
                    'collection_row_written' => $written ? '1' : '0',
                    'migrated_at'            => null,
                    'discovered_at'          => '2026-08-01 00:00:00',
                ];
            }

            /** @param list<array{contract_address: string, denied: bool}> $contracts */
            public static function recordDiscovered(int $chainId, int $codeId, array $contracts): int
            {
                $affected = 0;
                foreach ($contracts as $entry) {
                    $address = strtolower(trim($entry['contract_address']));
                    if ($address === '') {
                        continue;
                    }
                    if (isset(self::$contracts[$chainId][$address])) {
                        // Idempotent: only the deny flag and an unknown
                        // code id are refreshed. The verdict survives.
                        $row         = self::$contracts[$chainId][$address];
                        $row->denied = $entry['denied'] ? '1' : '0';
                        if ((int) $row->code_id === 0) {
                            $row->code_id = (string) $codeId;
                        }
                        $affected++;
                        continue;
                    }
                    self::seed($chainId, $address, 'inconclusive', $codeId, $entry['denied']);
                    $affected++;
                }

                return $affected;
            }

            /**
             * @param  list<string> $addresses
             * @return array<string, true>
             */
            public static function knownMap(int $chainId, array $addresses): array
            {
                $map = [];
                foreach ($addresses as $address) {
                    $lower = strtolower(trim($address));
                    if ($lower !== '' && isset(self::$contracts[$chainId][$lower])) {
                        $map[$lower] = true;
                    }
                }

                return $map;
            }

            public static function find(int $chainId, string $contract): ?object
            {
                return self::$contracts[$chainId][strtolower($contract)] ?? null;
            }

            /**
             * @param  list<int> $codeIds
             * @return list<object>
             */
            public static function findPendingClassification(int $chainId, int $limit, int $classifierVersion, array $codeIds = []): array
            {
                $rows = [];
                foreach (self::$contracts[$chainId] ?? [] as $row) {
                    if ((int) $row->denied === 1) {
                        continue;
                    }
                    if (in_array((string) $row->classification, ['not_cw721', 'confirmed_cw721', 'probable_cw721'], true)) {
                        continue;
                    }
                    if ((int) $row->retry_count >= 6) {
                        continue;
                    }
                    if ($row->classified_at !== null && (int) $row->classifier_version >= $classifierVersion) {
                        continue;
                    }
                    if ($codeIds !== [] && !in_array((int) $row->code_id, $codeIds, true)) {
                        continue;
                    }
                    $rows[] = $row;
                }

                return array_slice($rows, 0, $limit);
            }

            /** @return list<object> */
            public static function findEmittable(int $chainId, int $limit): array
            {
                $rows = [];
                foreach (self::$contracts[$chainId] ?? [] as $row) {
                    if ((int) $row->denied === 1 || (int) $row->collection_row_written === 1) {
                        continue;
                    }
                    if (!in_array((string) $row->classification, ['confirmed_cw721', 'probable_cw721'], true)) {
                        continue;
                    }
                    $rows[] = $row;
                }

                return array_slice($rows, 0, $limit);
            }

            /** @param array<string, mixed> $verdict */
            public static function recordClassification(int $chainId, string $contract, array $verdict, int $retryCount): bool
            {
                $address                 = strtolower($contract);
                self::$classifications[] = [
                    'chain_id' => $chainId,
                    'contract' => $address,
                    'verdict'  => $verdict,
                    'retry'    => $retryCount,
                ];
                if (!isset(self::$contracts[$chainId][$address])) {
                    self::seed($chainId, $address);
                }
                $row                     = self::$contracts[$chainId][$address];
                $row->classification     = (string) $verdict['classification'];
                $row->classification_reason = (string) $verdict['reason'];
                $row->classifier_version = (string) $verdict['classifier_version'];
                $row->classified_at      = '2026-08-06 00:00:00';
                $row->retry_count        = (string) $retryCount;

                return true;
            }

            public static function markCollectionRowWritten(int $chainId, string $contract): bool
            {
                $address        = strtolower($contract);
                self::$marked[] = $address;
                if (isset(self::$contracts[$chainId][$address])) {
                    self::$contracts[$chainId][$address]->collection_row_written = '1';
                }

                return true;
            }

            public static function setDenied(int $chainId, string $contract, bool $denied): bool
            {
                $address = strtolower($contract);
                if (isset(self::$contracts[$chainId][$address])) {
                    self::$contracts[$chainId][$address]->denied = $denied ? '1' : '0';
                }

                return true;
            }

            public static function recordAttemptFailure(int $chainId, string $contract, string $error, int $retryCount): bool
            {
                $address = strtolower($contract);
                if (isset(self::$contracts[$chainId][$address])) {
                    self::$contracts[$chainId][$address]->retry_count = (string) $retryCount;
                    self::$contracts[$chainId][$address]->last_error  = $error;
                }

                return true;
            }

            public static function recordMigration(int $chainId, string $contract, int $newCodeId): bool
            {
                $address            = strtolower($contract);
                self::$migrations[] = ['chain_id' => $chainId, 'contract' => $address, 'code_id' => $newCodeId];
                if (isset(self::$contracts[$chainId][$address])) {
                    self::$contracts[$chainId][$address]->code_id     = (string) $newCodeId;
                    self::$contracts[$chainId][$address]->migrated_at = '2026-08-06 00:00:00';
                }

                return true;
            }

            /** @param list<string>|null $affected */
            public static function requeueForClassifierVersion(int $chainId, int $classifierVersion, int $limit, ?array $affected = null): int
            {
                $affected = $affected ?? \BCC\Trust\Onchain\Services\CosmwasmClassifier::requeueableClassifications();

                $count = 0;
                foreach (self::$contracts[$chainId] ?? [] as $row) {
                    if (!in_array((string) $row->classification, $affected, true)) {
                        continue;
                    }
                    if ((int) $row->classifier_version >= $classifierVersion) {
                        continue;
                    }
                    $row->classified_at = null;
                    $row->retry_count   = '0';
                    $count++;
                    if ($count >= $limit) {
                        break;
                    }
                }

                return $count;
            }

            /** @return array<string, int> */
            public static function countsByClassification(int $chainId): array
            {
                $out = [];
                foreach (\BCC\Trust\Onchain\Services\CosmwasmClassifier::allClassifications() as $classification) {
                    $out[$classification] = 0;
                }
                foreach (self::$contracts[$chainId] ?? [] as $row) {
                    $out[(string) $row->classification] = ($out[(string) $row->classification] ?? 0) + 1;
                }

                return $out;
            }

            public static function countDenied(int $chainId): int
            {
                $n = 0;
                foreach (self::$contracts[$chainId] ?? [] as $row) {
                    if ((int) $row->denied === 1) {
                        $n++;
                    }
                }

                return $n;
            }

            public static function countForChain(int $chainId): int
            {
                return count(self::$contracts[$chainId] ?? []);
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\CollectionRepository', false)) {
        /** Collections table fake — presence map + bulkUpsert recorder. */
        final class CollectionRepository
        {
            /** @var array<int, array<int, object>> chainId => known collection rows */
            public static array $knownByChain = [];

            /** @var list<array<string, mixed>> every row handed to bulkUpsert */
            public static array $upserted = [];

            public static function reset(): void
            {
                self::$knownByChain = [];
                self::$upserted     = [];
            }

            /** @return array<int, object> */
            public static function listKnownByChain(int $chainId, int $limit): array
            {
                return array_slice(self::$knownByChain[$chainId] ?? [], 0, $limit);
            }

            /**
             * @param  list<string> $contracts
             * @return array<string, bool>
             */
            public static function verifiedMapForContracts(int $chainId, array $contracts): array
            {
                $rows = [];
                foreach (self::$knownByChain[$chainId] ?? [] as $row) {
                    $rows[strtolower((string) ($row->contract_address ?? ''))] =
                        ((int) ($row->is_verified ?? 0) === 1);
                }

                $map = [];
                foreach ($contracts as $contract) {
                    $lower = strtolower($contract);
                    if (array_key_exists($lower, $rows)) {
                        $map[$lower] = $rows[$lower];
                    }
                }

                return $map;
            }

            /** @param array<int, array<string, mixed>> $collections */
            public static function bulkUpsert(array $collections, int $ttlSeconds = 0): int
            {
                foreach ($collections as $row) {
                    self::$upserted[] = $row;
                    // Mirror production: a discovery row lands UNVERIFIED
                    // (the schema default). Nothing in discovery sets the flag.
                    self::$knownByChain[(int) $row['chain_id']][] = (object) [
                        'contract_address' => (string) $row['contract_address'],
                        'collection_name'  => $row['collection_name'] ?? null,
                        'image_url'        => $row['image_url'] ?? null,
                        'is_verified'      => 0,
                    ];
                }

                return count($collections);
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\ChainCheckpointRepository', false)) {
        /** Checkpoint fake carrying the cw_* discovery extension. */
        final class ChainCheckpointRepository
        {
            public const STATE_HEALTHY      = 'healthy';
            public const STATE_DEGRADED     = 'degraded';
            public const STATE_BREAKER_OPEN = 'breaker_open';
            public const STATE_DISABLED     = 'disabled';

            public const CW_STATE_IDLE        = 'idle';
            public const CW_STATE_BACKFILLING = 'backfilling';
            public const CW_STATE_BACKFILLED  = 'backfilled';
            public const CW_STATE_UNSUPPORTED = 'unsupported';
            public const CW_STATE_PAUSED      = 'paused';

            /** @var array<int, object> */
            public static array $rows = [];

            /** @var list<array{chain_id: int, cursor: ?string, max: int, complete: bool}> */
            public static array $codeProgress = [];

            /** @var list<int> */
            public static array $discoveryTouches = [];

            /** @var list<int> */
            public static array $metadataTouches = [];

            public static function reset(): void
            {
                self::$rows              = [];
                self::$codeProgress      = [];
                self::$discoveryTouches  = [];
                self::$metadataTouches   = [];
                self::$watermarkAdvances = [];
                self::$backfillRestarts  = [];
            }

            /** @return list<string> */
            public static function cwStates(): array
            {
                return [
                    self::CW_STATE_IDLE,
                    self::CW_STATE_BACKFILLING,
                    self::CW_STATE_BACKFILLED,
                    self::CW_STATE_UNSUPPORTED,
                    self::CW_STATE_PAUSED,
                ];
            }

            public static function ensureExists(int $chainId): void
            {
                if (isset(self::$rows[$chainId])) {
                    return;
                }
                self::$rows[$chainId] = (object) [
                    'chain_id'                  => (string) $chainId,
                    'last_processed_block'      => '0',
                    'head_block'                => '0',
                    'state'                     => self::STATE_DISABLED,
                    'cu_used_today'             => '0',
                    'cu_budget_reset_at'        => '1970-01-01',
                    'last_run_at'               => null,
                    'last_error'                => null,
                    'block_progression_history' => null,
                    'cw_discovery_state'        => self::CW_STATE_IDLE,
                    'cw_code_cursor'            => null,
                    'cw_max_code_id'            => '0',
                    'cw_backfill_completed_at'  => null,
                    'cw_last_discovery_at'      => null,
                    'cw_metadata_refreshed_at'  => null,
                    'cw_last_error'             => null,
                ];
            }

            public static function get(int $chainId): ?object
            {
                return self::$rows[$chainId] ?? null;
            }

            public static function setCwDiscoveryState(int $chainId, string $state, ?string $error = null): bool
            {
                if (!in_array($state, self::cwStates(), true)) {
                    return false;
                }
                self::ensureExists($chainId);
                self::$rows[$chainId]->cw_discovery_state = $state;
                self::$rows[$chainId]->cw_last_error      = $error;

                return true;
            }

            /** @var list<array{chain_id: int, max: int}> */
            public static array $watermarkAdvances = [];

            /** @var list<array{chain_id: int, reason: string}> */
            public static array $backfillRestarts = [];

            public static function advanceCwCodeWatermark(int $chainId, int $maxCodeId): bool
            {
                if ($maxCodeId <= 0) {
                    return false;
                }
                self::ensureExists($chainId);
                self::$watermarkAdvances[] = ['chain_id' => $chainId, 'max' => $maxCodeId];
                $row                       = self::$rows[$chainId];
                $row->cw_max_code_id       = (string) max((int) $row->cw_max_code_id, $maxCodeId);
                $row->cw_last_discovery_at = '2026-08-06 00:00:00';
                $row->cw_last_error        = null;

                return true;
            }

            public static function requestCwBackfillRestart(int $chainId, string $reason): bool
            {
                self::ensureExists($chainId);
                self::$backfillRestarts[] = ['chain_id' => $chainId, 'reason' => $reason];
                $row                      = self::$rows[$chainId];
                $row->cw_code_cursor      = null;
                $row->cw_discovery_state  = self::CW_STATE_BACKFILLING;
                $row->cw_last_error       = mb_substr($reason, 0, 255);

                return true;
            }

            public static function recordCwCodeProgress(int $chainId, ?string $cursor, int $maxCodeId, bool $complete): bool
            {
                self::ensureExists($chainId);
                self::$codeProgress[] = [
                    'chain_id' => $chainId,
                    'cursor'   => $cursor,
                    'max'      => $maxCodeId,
                    'complete' => $complete,
                ];
                $row                     = self::$rows[$chainId];
                $row->cw_code_cursor     = $complete ? null : $cursor;
                $row->cw_max_code_id     = (string) max((int) $row->cw_max_code_id, $maxCodeId);
                $row->cw_discovery_state = $complete ? self::CW_STATE_BACKFILLED : self::CW_STATE_BACKFILLING;
                if ($complete) {
                    $row->cw_backfill_completed_at = '2026-08-06 00:00:00';
                }
                $row->cw_last_error = null;

                return true;
            }

            public static function touchCwDiscovery(int $chainId): bool
            {
                self::ensureExists($chainId);
                self::$discoveryTouches[]                    = $chainId;
                self::$rows[$chainId]->cw_last_discovery_at = '2026-08-06 00:00:00';

                return true;
            }

            public static function touchCwMetadataRefresh(int $chainId): bool
            {
                self::ensureExists($chainId);
                self::$metadataTouches[]                         = $chainId;
                self::$rows[$chainId]->cw_metadata_refreshed_at = '2026-08-06 00:00:00';

                return true;
            }

            /** @param list<int> $chainIds */
            public static function nextCwDiscoveryChain(array $chainIds): ?int
            {
                $eligible = [];
                foreach ($chainIds as $chainId) {
                    $row = self::$rows[$chainId] ?? null;
                    if ($row === null) {
                        continue;
                    }
                    $state = (string) $row->cw_discovery_state;
                    if ($state === self::CW_STATE_UNSUPPORTED || $state === self::CW_STATE_PAUSED) {
                        continue;
                    }
                    $eligible[$chainId] = (string) ($row->cw_last_discovery_at ?? '');
                }
                if ($eligible === []) {
                    return null;
                }
                asort($eligible);

                return (int) array_key_first($eligible);
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\NftSpamContractRepository', false)) {
        /** Operator allow/deny rule table fake. */
        final class NftSpamContractRepository
        {
            public const RULE_DENY  = 'deny';
            public const RULE_ALLOW = 'allow';

            /** @var array<string, string> keyed "chainId|contract(lower)" */
            public static array $rules = [];

            public static function reset(): void
            {
                self::$rules = [];
            }

            public static function getRule(int $chainId, string $contract): ?string
            {
                return self::$rules[$chainId . '|' . strtolower($contract)] ?? null;
            }

            public static function addRule(int $chainId, string $contract, string $rule, string $reason = ''): bool
            {
                self::$rules[$chainId . '|' . strtolower($contract)] = $rule;

                return true;
            }

            public static function removeRule(int $chainId, string $contract): bool
            {
                unset(self::$rules[$chainId . '|' . strtolower($contract)]);

                return true;
            }

            public static function flushCache(): void
            {
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\ChainRepository', false)) {
        /** Chain registry fake. */
        final class ChainRepository
        {
            /** @var array<int, object> */
            public static array $chains = [];

            public static function reset(): void
            {
                self::$chains = [];
            }

            public static function seed(int $id, string $slug, string $rest = 'https://lcd.example', string $type = 'cosmos'): void
            {
                self::$chains[$id] = (object) [
                    'id'         => $id,
                    'slug'       => $slug,
                    'chain_type' => $type,
                    'rest_url'   => $rest,
                    'decimals'   => 6,
                ];
            }

            public static function getById(int $chainId): ?object
            {
                return self::$chains[$chainId] ?? null;
            }

            /** @return list<object> */
            public static function getActive(): array
            {
                return array_values(self::$chains);
            }
        }
    }
}

namespace BCC\Core\DB {

    if (!class_exists(__NAMESPACE__ . '\\AdvisoryLock', false)) {
        /**
         * Advisory-lock fake with a controllable acquire outcome, so the
         * "two overlapping workers cannot corrupt cursor state" case is
         * testable without real concurrency.
         */
        final class AdvisoryLock
        {
            public static bool $acquirable = true;

            /** @var list<string> */
            public static array $acquired = [];

            /** @var list<string> */
            public static array $released = [];

            public static function reset(): void
            {
                self::$acquirable = true;
                self::$acquired   = [];
                self::$released   = [];
            }

            public static function acquire(string $key, int $timeout = 0): bool
            {
                if (!self::$acquirable) {
                    return false;
                }
                self::$acquired[] = $key;

                return true;
            }

            public static function release(string $key): void
            {
                self::$released[] = $key;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Factories {

    if (!class_exists(__NAMESPACE__ . '\\FetcherFactory', false)) {
        /** Always produces the REAL CosmosFetcher over the ApiRetry fake. */
        final class FetcherFactory
        {
            public static function has_driver(string $chainType): bool
            {
                return $chainType === 'cosmos';
            }

            public static function make_for_chain(object $chain): object
            {
                return new \BCC\Trust\Onchain\Fetchers\CosmosFetcher($chain);
            }
        }
    }
}

namespace {

    if (!function_exists('current_time')) {
        /** @return string|int */
        function current_time(string $type, bool $gmt = false)
        {
            if ($type === 'timestamp') {
                return time();
            }
            if ($type === 'Y-m-d') {
                return gmdate('Y-m-d');
            }

            return gmdate('Y-m-d H:i:s');
        }
    }

    if (!class_exists('BccTestCronStore', false)) {
        /** In-memory wp-cron fake. */
        final class BccTestCronStore
        {
            /** @var array<string, array{interval: string, timestamp: int}> */
            public static array $events = [];

            public static function reset(): void
            {
                self::$events = [];
            }
        }
    }

    if (!function_exists('wp_next_scheduled')) {
        /** @return int|false */
        function wp_next_scheduled(string $hook)
        {
            return isset(\BccTestCronStore::$events[$hook])
                ? \BccTestCronStore::$events[$hook]['timestamp']
                : false;
        }
    }

    if (!function_exists('wp_schedule_event')) {
        function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
        {
            \BccTestCronStore::$events[$hook] = ['interval' => $recurrence, 'timestamp' => $timestamp];

            return true;
        }
    }

    // Shared WP shims, Logger, the ApiRetry transport fake, AdvisoryLock,
    // DegradationMetrics and OnchainCircuitBreaker. Loaded LAST so its
    // class_exists() guards skip everything defined above.
    require_once __DIR__ . '/nft-indexer-stubs.php';
}
