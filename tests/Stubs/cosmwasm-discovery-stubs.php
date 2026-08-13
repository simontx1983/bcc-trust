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

            /**
             * Methods that must behave as though their SQL failed.
             *
             * The production repository answers a failed read by throwing
             * {@see \BCC\Trust\Onchain\Repositories\RepositoryReadFailure}
             * from the fail-closed reads; this reproduces that at the seam
             * so the summary/panel composition can be tested without a
             * database. The REAL throw-on-SQL-error is pinned against a
             * live MySQL in CosmwasmReadFailureIntegrationTest.
             *
             * @var list<string>
             */
            public static array $failReads = [];

            public static function reset(): void
            {
                self::$families        = [];
                self::$classifications = [];
                self::$closed          = [];
                self::$failures        = [];
                self::$requeues        = [];
                self::$progress        = [];
                self::$metadataTouches = [];
                self::$failReads       = [];
            }

            private static function failIfArmed(string $method): void
            {
                if (in_array($method, self::$failReads, true)) {
                    throw new \BCC\Trust\Onchain\Repositories\RepositoryReadFailure(
                        self::class,
                        $method,
                        "Table 'bcc_test.wp_bcc_cosmwasm_code_families' doesn't exist"
                    );
                }
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

            // ── all-chain aggregates behind the admin panel ─────────────

            /** @return array<int, array<string, int>> */
            public static function countsByChainAndClassification(): array
            {
                self::failIfArmed(__FUNCTION__);

                $out = [];
                foreach (self::$families as $chainId => $rows) {
                    foreach ($rows as $row) {
                        if (!isset($out[$chainId])) {
                            $out[$chainId] = [];
                            foreach (\BCC\Trust\Onchain\Services\CosmwasmClassifier::allClassifications() as $c) {
                                $out[$chainId][$c] = 0;
                            }
                        }
                        $classification                 = (string) $row->classification;
                        $out[$chainId][$classification] = ($out[$chainId][$classification] ?? 0) + 1;
                    }
                }

                return $out;
            }

            /** @return array<int, int> */
            public static function pendingCountsByChain(int $classifierVersion): array
            {
                self::failIfArmed(__FUNCTION__);

                $out = [];
                foreach (self::$families as $chainId => $_rows) {
                    $out[$chainId] = count(self::findPendingClassification($chainId, 1000, $classifierVersion));
                }

                return $out;
            }

            /**
             * @param  list<int> $chainIds
             * @param  list<int> $codeIds
             * @return list<object>
             */
            public static function findManyForChains(array $chainIds, array $codeIds): array
            {
                self::failIfArmed(__FUNCTION__);

                $out = [];
                foreach ($chainIds as $chainId) {
                    foreach (self::$families[$chainId] ?? [] as $row) {
                        if (in_array((int) $row->code_id, $codeIds, true)) {
                            $out[] = $row;
                        }
                    }
                }

                return $out;
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

            /**
             * Methods that must behave as though their SQL failed.
             * See the twin on the code-family fake.
             *
             * @var list<string>
             */
            public static array $failReads = [];

            /**
             * Make `setDenied()` a silent no-op that still reports true.
             *
             * The nastier half of "the cache sync failed": the rule IS
             * written, `syncDenyFlags()` runs and reports rows changed,
             * and the flag never moved. Nothing throws — which is exactly
             * why the hide/unhide path reads the flag back instead of
             * trusting a return value.
             */
            public static bool $swallowSetDenied = false;

            public static function reset(): void
            {
                self::$contracts        = [];
                self::$classifications  = [];
                self::$migrations       = [];
                self::$marked           = [];
                self::$failReads        = [];
                self::$swallowSetDenied = false;
            }

            private static function failIfArmed(string $method): void
            {
                if (in_array($method, self::$failReads, true)) {
                    throw new \BCC\Trust\Onchain\Repositories\RepositoryReadFailure(
                        self::class,
                        $method,
                        "Table 'bcc_test.wp_bcc_cosmwasm_contracts' doesn't exist"
                    );
                }
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
                if (self::$swallowSetDenied) {
                    return true;
                }

                $address = strtolower($contract);
                if (isset(self::$contracts[$chainId][$address])) {
                    self::$contracts[$chainId][$address]->denied = $denied ? '1' : '0';
                }

                return true;
            }

            public static function deniedFlag(int $chainId, string $contract): ?bool
            {
                self::failIfArmed(__FUNCTION__);

                $row = self::$contracts[$chainId][strtolower($contract)] ?? null;

                return $row === null ? null : ((int) $row->denied === 1);
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

            // ── all-chain aggregates behind the admin panel ─────────────

            /**
             * @return array<int, array{
             *     total: int,
             *     inspected: int,
             *     denied: int,
             *     candidates: int,
             *     candidates_awaiting_emit: int,
             *     by_classification: array<string, int>
             * }>
             */
            public static function inventoryByChain(): array
            {
                self::failIfArmed(__FUNCTION__);

                $out = [];
                foreach (self::$contracts as $chainId => $rows) {
                    foreach ($rows as $row) {
                        if (!isset($out[$chainId])) {
                            $out[$chainId] = [
                                'total'                    => 0,
                                'inspected'                => 0,
                                'denied'                   => 0,
                                'candidates'               => 0,
                                'candidates_awaiting_emit' => 0,
                                'by_classification'        => [],
                            ];
                        }

                        $classification = (string) $row->classification;
                        $denied         = (int) $row->denied === 1;

                        $out[$chainId]['total']++;
                        if ($row->classified_at !== null) {
                            $out[$chainId]['inspected']++;
                        }
                        if ($denied) {
                            $out[$chainId]['denied']++;
                        }
                        $out[$chainId]['by_classification'][$classification] =
                            ($out[$chainId]['by_classification'][$classification] ?? 0) + 1;

                        if (!$denied && \BCC\Trust\Onchain\Services\CosmwasmClassifier::isCw721($classification)) {
                            $out[$chainId]['candidates']++;
                            if ((int) $row->collection_row_written !== 1) {
                                $out[$chainId]['candidates_awaiting_emit']++;
                            }
                        }
                    }
                }

                return $out;
            }

            /**
             * @param  list<int>    $chainIds
             * @param  list<string> $addresses
             * @return list<object>
             */
            public static function findManyForChains(array $chainIds, array $addresses): array
            {
                self::failIfArmed(__FUNCTION__);

                $wanted = [];
                foreach ($addresses as $address) {
                    $wanted[strtolower(trim($address))] = true;
                }

                $out = [];
                foreach ($chainIds as $chainId) {
                    foreach (self::$contracts[$chainId] ?? [] as $address => $row) {
                        if (isset($wanted[$address])) {
                            $out[] = $row;
                        }
                    }
                }

                return $out;
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
                self::$knownByChain          = [];
                self::$upserted              = [];
                self::$adminRows             = [];
                self::$findManyByIdsOverride = null;
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

            /**
             * Admin rows keyed by collection id, for the Verify
             * Collections handler path.
             *
             * @var array<int, object>
             */
            public static array $adminRows = [];

            /**
             * A verbatim `findManyByIds()` result, for tests that need a
             * shape the faithful fake below cannot produce.
             *
             * The fake keys by the row's own id exactly as production
             * does, which makes "key present, row id different" genuinely
             * unreachable through it — and that shape is precisely what
             * the handler's identity check exists to survive. Rather than
             * weaken the fake, hostile maps are injected here.
             *
             * @var array<int, object>|null
             */
            public static ?array $findManyByIdsOverride = null;

            /** Test helper: one row as the admin page sees it. */
            public static function seedAdminRow(int $id, int $chainId, string $contract, string $name, ?int $rowId = null): void
            {
                self::$adminRows[$id] = (object) [
                    'id'               => (string) ($rowId ?? $id),
                    'chain_id'         => (string) $chainId,
                    'contract_address' => $contract,
                    'collection_name'  => $name,
                ];
            }

            /**
             * Faithful to production: a MAP KEYED BY COLLECTION ID, built
             * from each ROW's own id — `$map[(int) $row->id] = $row`, the
             * literal shape of
             * {@see \BCC\Trust\Onchain\Repositories\CollectionRepository::findManyByIds()}.
             *
             * This used to return `$out[] = $row` — a convenient
             * zero-indexed list. That single divergence is why a handler
             * reading `$rows[0]` passed every test while answering
             * "collection not found" for every collection in production.
             * A double that is more convenient than the thing it doubles
             * is a test that proves nothing. Do not re-introduce the list.
             *
             * @param  list<int> $ids
             * @return array<int, object> collection id → row
             */
            public static function findManyByIds(array $ids): array
            {
                if (self::$findManyByIdsOverride !== null) {
                    return self::$findManyByIdsOverride;
                }

                $out = [];
                foreach ($ids as $id) {
                    $row = self::$adminRows[$id] ?? null;
                    if ($row !== null) {
                        $out[(int) $row->id] = $row;
                    }
                }

                return $out;
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
                self::$failGetAll        = false;
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

            /**
             * Fail-safe listing (worker dashboards).
             *
             * @return list<object>
             */
            public static function getAll(): array
            {
                return array_values(self::$rows);
            }

            /**
             * Fail-closed listing (the CosmWasm scanner panel).
             *
             * @return list<object>
             */
            public static function getAllOrFail(): array
            {
                if (self::$failGetAll) {
                    throw new \BCC\Trust\Onchain\Repositories\RepositoryReadFailure(
                        self::class,
                        'getAllOrFail',
                        "Table 'bcc_test.wp_bcc_chain_checkpoints' doesn't exist"
                    );
                }

                return array_values(self::$rows);
            }

            public static bool $failGetAll = false;

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

            /**
             * Make every rule write fail, the way the real repository does
             * when its INSERT is rejected. Used to prove the hide path
             * does NOT touch the scanner's cached flag on the strength of
             * a rule that was never written.
             */
            public static bool $rejectWrites = false;

            public static function reset(): void
            {
                self::$rules        = [];
                self::$rejectWrites = false;
            }

            public static function getRule(int $chainId, string $contract): ?string
            {
                return self::$rules[$chainId . '|' . strtolower($contract)] ?? null;
            }

            public static function addRule(int $chainId, string $contract, string $rule, string $reason = ''): bool
            {
                if (self::$rejectWrites) {
                    return false;
                }

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
        /**
         * Chain registry fake.
         *
         * `getActive()` is the projection the worker's eligibility
         * chokepoint reads, so the fake rows carry
         * `cosmwasm_nft_discovery_enabled` exactly as the real
         * ChainRepository::COLUMNS projection does.
         *
         * seed() defaults it to 1 — NOT because the production default is
         * 1 (it is 0; ChainCosmwasmDiscoveryFlagIntegrationTest pins that
         * against a real MySQL), but because "an operator has opted this
         * chain in" is the precondition of every OTHER discovery test in
         * this suite. Tests about the opt-in itself pass 0 explicitly, and
         * {@see seedWithoutDiscoveryColumn()} models the pre-migration row
         * where the column is ABSENT rather than 0.
         */
        final class ChainRepository
        {
            /** @var array<int, object> */
            public static array $chains = [];

            /**
             * Every setCosmwasmNftDiscoveryEnabled() call, in order.
             *
             * @var list<array{chain_id: int, enabled: bool}>
             */
            public static array $discoveryWrites = [];

            /**
             * How many times the chains cache was invalidated.
             *
             * The REAL repository busts the cache INSIDE the write (see
             * ChainRepository::setCosmwasmNftDiscoveryEnabled) because
             * getActive() serves the scanner's eligibility read from a
             * 5-minute cache — a toggle that skipped invalidation would
             * leave the worker scanning a just-disabled chain for the rest
             * of the TTL. The counter is how a unit test observes that the
             * admin control went through that write path.
             */
            public static int $cacheBusts = 0;

            /** Make the write report a DB error, the way $wpdb->query === false does. */
            public static bool $rejectWrites = false;

            /**
             * Make the write REPORT success while changing nothing.
             *
             * The quiet half of the failure space: `$wpdb->query()` not
             * returning false says the statement ran, not that the row now
             * holds the value asked for. This is what the handler's
             * read-back exists to catch.
             */
            public static bool $swallowWrites = false;

            public static function reset(): void
            {
                self::$chains         = [];
                self::$discoveryWrites = [];
                self::$cacheBusts     = 0;
                self::$rejectWrites   = false;
                self::$swallowWrites  = false;
            }

            public static function seed(
                int $id,
                string $slug,
                string $rest = 'https://lcd.example',
                string $type = 'cosmos',
                int $cosmwasmNftDiscoveryEnabled = 1
            ): void {
                self::$chains[$id] = (object) [
                    'id'                             => $id,
                    'slug'                           => $slug,
                    'chain_type'                     => $type,
                    'rest_url'                       => $rest,
                    'decimals'                       => 6,
                    // Inert here, load-bearing in the assertions: the
                    // discovery toggle must not disturb any of them.
                    'is_active'                      => '1',
                    'explorer_url'                   => 'https://explorer.example/' . $slug,
                    'icon_url'                       => 'https://cdn.example/' . $slug . '.png',
                    'color'                          => '#123456',
                    'description'                    => 'About ' . $slug . '.',
                    'cosmwasm_nft_discovery_enabled' => (string) $cosmwasmNftDiscoveryEnabled,
                ];
            }

            /**
             * A row from an install where the ALTER has NOT run: the
             * property is absent, not 0. Reading an absent property would
             * raise a PHP warning and evaluate to null, so the production
             * code must probe for presence — this is how that is exercised.
             */
            public static function seedWithoutDiscoveryColumn(
                int $id,
                string $slug,
                string $rest = 'https://lcd.example',
                string $type = 'cosmos'
            ): void {
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

            /**
             * THE ONLY WRITE PATH for the opt-in, faked at the seam.
             *
             * Mirrors the production contract exactly, including the parts
             * that are easy to get wrong:
             *   - a non-positive id is refused WITHOUT touching anything;
             *   - the cache is busted as part of the write, not by the
             *     caller;
             *   - the return value means "the UPDATE did not error", which
             *     is NOT the same as "the value is now what you asked for"
             *     ($swallowWrites is that gap made observable);
             *   - EXACTLY ONE PROPERTY MOVES. Everything else on the row is
             *     left byte-identical, which is what lets a test assert the
             *     toggle cannot disturb is_active, the endpoints or the
             *     Hall identity fields.
             */
            public static function setCosmwasmNftDiscoveryEnabled(int $chainId, bool $enabled): bool
            {
                if ($chainId <= 0) {
                    return false;
                }

                self::$discoveryWrites[] = ['chain_id' => $chainId, 'enabled' => $enabled];

                if (self::$rejectWrites) {
                    // The real one still busts the cache after a failed
                    // query (clearCache() is unconditional), so this does
                    // too — otherwise a test could "prove" invalidation
                    // that production does not actually order this way.
                    self::clearCache();

                    return false;
                }

                if (!self::$swallowWrites && isset(self::$chains[$chainId])) {
                    self::$chains[$chainId]->cosmwasm_nft_discovery_enabled = $enabled ? '1' : '0';
                }

                self::clearCache();

                return true;
            }

            public static function clearCache(): void
            {
                self::$cacheBusts++;
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

    // ── wp-admin surface shims ──────────────────────────────────────────
    //
    // Enough of wp-admin to RUN the scanner panel's render path and the
    // Verify Collections POST handler in-process. Escaping is deliberately
    // real-ish (htmlspecialchars) rather than pass-through, so a test that
    // asserts on rendered output is looking at escaped markup the way the
    // browser would.

    if (!function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
        function esc_attr(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
        function esc_url(string $url): string
        {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }
        /** @param mixed $number */
        function number_format_i18n($number, int $decimals = 0): string
        {
            return number_format((float) $number, $decimals);
        }
        /**
         * @param mixed $disabled
         * @param mixed $current
         */
        function disabled($disabled, $current = true, bool $display = true): string
        {
            $out = (string) $disabled === (string) $current ? " disabled='disabled'" : '';
            if ($display) {
                echo $out;
            }

            return $out;
        }
        /** @return string */
        function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true)
        {
            $field = '<input type="hidden" name="' . $name . '" value="test-nonce">';
            if ($display) {
                echo $field;
            }

            return $field;
        }
    }

    if (!function_exists('wp_verify_nonce')) {
        /**
         * Accepts the fixed token the stubbed nonce field emits, and
         * NOTHING else — so a test can still exercise the "security check
         * failed" branch by sending a different value.
         *
         * @return int|false
         */
        function wp_verify_nonce(string $nonce, string $action = '-1')
        {
            return $nonce === 'test-nonce' ? 1 : false;
        }
        function sanitize_text_field(string $value): string
        {
            return trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));
        }
        function get_current_user_id(): int
        {
            return 1;
        }
    }

    if (!class_exists('BccTestCapabilities', false)) {
        /**
         * Who the current user is allowed to be.
         *
         * Defaults to a full administrator, because that is the
         * precondition of every OTHER admin test in this suite. A test
         * about the capability gate itself empties the list, which is the
         * only way to reach the refusal branch — a nonce and a capability
         * are separate gates and each has to be provable on its own.
         */
        final class BccTestCapabilities
        {
            /** @var list<string> */
            public static array $granted = ['manage_options'];

            public static function reset(): void
            {
                self::$granted = ['manage_options'];
            }
        }
    }

    if (!function_exists('current_user_can')) {
        function current_user_can(string $capability): bool
        {
            return in_array($capability, \BccTestCapabilities::$granted, true);
        }
    }

    // Shared WP shims, Logger, the ApiRetry transport fake, AdvisoryLock,
    // DegradationMetrics and OnchainCircuitBreaker. Loaded LAST so its
    // class_exists() guards skip everything defined above.
    require_once __DIR__ . '/nft-indexer-stubs.php';
}
