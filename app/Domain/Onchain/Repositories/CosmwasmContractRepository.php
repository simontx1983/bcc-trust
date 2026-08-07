<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CosmWasm contract inventory repository.
 *
 * Owns `wp_bcc_cosmwasm_contracts` — the durable per-(chain, contract)
 * candidate ledger. See
 * includes/database/schema-cosmwasm-contracts.php for the five-table
 * division of responsibility and why these rows do not belong on
 * `wp_bcc_onchain_collections`.
 *
 * §1: every `$wpdb` touch for this table lives here. §2: explicit
 * COLUMNS, never `SELECT *`. §4: every SELECT is bounded by a unique
 * key, a LIMIT, or is an aggregate.
 *
 * DENY IS NOT ENFORCED HERE. This repository stores the observed deny
 * FLAG so bounded aggregates and sweep predicates are cheap; the
 * authority is `wp_bcc_onchain_nft_spam_contracts` via
 * {@see \BCC\Trust\Onchain\Services\NftSpamFilter}, and every write and
 * emit path re-consults it. A repository that decided policy would be a
 * second rejection system.
 *
 * @phpstan-type CosmwasmContractRow object{
 *     id: string,
 *     chain_id: string,
 *     contract_address: string,
 *     code_id: string,
 *     classification: string,
 *     classification_reason: string|null,
 *     probes_ok: string|null,
 *     probes_failed: string|null,
 *     classifier_version: string,
 *     classified_at: string|null,
 *     last_attempted_at: string|null,
 *     next_attempt_at: string|null,
 *     retry_count: string,
 *     last_error: string|null,
 *     denied: string,
 *     collection_row_written: string,
 *     migrated_at: string|null,
 *     discovered_at: string
 * }
 */
final class CosmwasmContractRepository
{
    use GuardsReadFailures;

    private const COLUMNS = 'id, chain_id, contract_address, code_id, classification,'
        . ' classification_reason, probes_ok, probes_failed, classifier_version, classified_at,'
        . ' last_attempted_at, next_attempt_at, retry_count, last_error, denied,'
        . ' collection_row_written, migrated_at, discovered_at';

    /** Defensive ceiling on any list read. */
    private const MAX_LIMIT = 200;

    /** Bounded ceiling for an IN() membership probe. */
    private const MAX_IN_CLAUSE = 200;

    public static function table(): string
    {
        return DB::table('cosmwasm_contracts');
    }

    /**
     * Record contracts observed under a code family.
     *
     * Idempotent by (chain_id, contract_address): a duplicate LCD page,
     * a re-walk after a resumed backfill, or a contract that appears
     * under two families all converge on ONE row. On conflict NOTHING is
     * overwritten except `code_id` when it was previously unknown —
     * a contract we have already inspected must never lose its verdict
     * or its retry state, which is what makes "previously-seen contracts
     * are not reprocessed" hold across duplicate responses.
     *
     * `$denied` is the caller's already-resolved deny observation. The
     * caller resolves it through NftSpamFilter BEFORE calling; passing
     * it in keeps policy in one place and still lets a denied contract
     * be RECORDED (so it is not rediscovered as "new" on every sweep and
     * spam-logged forever) without ever being EMITTED.
     *
     * @param list<array{contract_address: string, denied: bool}> $contracts
     * @return int rows affected
     */
    public static function recordDiscovered(int $chainId, int $codeId, array $contracts): int
    {
        if ($chainId <= 0 || $contracts === []) {
            return 0;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        $values       = [];
        $placeholders = [];
        $seen         = [];
        foreach ($contracts as $entry) {
            $address = strtolower(trim($entry['contract_address']));
            if ($address === '' || isset($seen[$address])) {
                continue;
            }
            $seen[$address] = true;

            $placeholders[] = '(%d, %s, %d, %s, %d, %d, %s)';
            $values[] = $chainId;
            $values[] = mb_substr($address, 0, 128);
            $values[] = max(0, $codeId);
            $values[] = CosmwasmClassifier::INCONCLUSIVE;
            $values[] = 0;
            $values[] = $entry['denied'] ? 1 : 0;
            $values[] = $now;
        }

        if ($placeholders === []) {
            return 0;
        }

        $sql = "INSERT INTO {$table}
                    (chain_id, contract_address, code_id, classification, classifier_version, denied, discovered_at)
                 VALUES " . implode(', ', $placeholders) . "
                 ON DUPLICATE KEY UPDATE
                    code_id = IF(code_id = 0, VALUES(code_id), code_id),
                    denied = VALUES(denied)";

        /** @var string $prepared */
        $prepared = $wpdb->prepare($sql, $values);
        $result   = $wpdb->query($prepared);

        return is_int($result) ? $result : 0;
    }

    /**
     * Which of these addresses do we ALREADY have a row for?
     *
     * The "previously inspected contracts are never reprocessed" filter.
     * Bounded by an IN() capped at {@see MAX_IN_CLAUSE} (the caller pages
     * an LCD page of ≤100 at a time).
     *
     * @param  list<string> $addresses
     * @return array<string, true> keyed by lowercase address
     */
    public static function knownMap(int $chainId, array $addresses): array
    {
        if ($chainId <= 0 || $addresses === []) {
            return [];
        }

        $normalized = [];
        foreach ($addresses as $address) {
            $lower = strtolower(trim($address));
            if ($lower !== '') {
                $normalized[$lower] = true;
            }
        }
        if ($normalized === []) {
            return [];
        }
        $list = array_slice(array_keys($normalized), 0, self::MAX_IN_CLAUSE);

        global $wpdb;
        $table = self::table();

        $inPlaceholders = implode(', ', array_fill(0, count($list), '%s'));

        /** @var array<int, string|int> $args */
        $args = array_merge([$chainId], $list);

        /** @var list<string>|null $rows */
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT contract_address
               FROM {$table}
              WHERE chain_id = %d
                AND contract_address IN ({$inPlaceholders})",
            ...$args
        ));
        self::guardRead(__FUNCTION__);

        $map = [];
        foreach ($rows ?: [] as $address) {
            $map[strtolower((string) $address)] = true;
        }

        return $map;
    }

    /** @return CosmwasmContractRow|null */
    public static function find(int $chainId, string $contract): ?object
    {
        if ($chainId <= 0 || $contract === '') {
            return null;
        }

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        /** @var CosmwasmContractRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id = %d
                AND contract_address = %s
              LIMIT 1",
            $chainId,
            strtolower($contract)
        ));
        self::guardRead(__FUNCTION__);

        return $row;
    }

    /**
     * Contracts awaiting their own classification pass.
     *
     * Never returns a denied row (an operator DENY suppresses the
     * candidate everywhere, including the work queue), never a settled
     * `not_cw721`, never an already-decided CW-721, and always respects
     * the retry cap and the precomputed backoff.
     *
     * @param list<int> $codeIds restrict to these families (bounded)
     * @return list<CosmwasmContractRow>
     */
    public static function findPendingClassification(
        int $chainId,
        int $limit,
        int $classifierVersion,
        array $codeIds = []
    ): array {
        if ($chainId <= 0) {
            return [];
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;
        $now   = current_time('mysql', true);

        $codeClause = '';
        $codeIds    = array_slice(array_values(array_unique(array_map('intval', $codeIds))), 0, 50);
        if ($codeIds !== []) {
            $codeClause = 'AND code_id IN (' . implode(',', array_map('intval', $codeIds)) . ')';
        }

        /** @var list<CosmwasmContractRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id = %d
                AND denied = 0
                AND classification NOT IN (%s, %s, %s)
                AND retry_count < %d
                AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
                AND (classified_at IS NULL OR classifier_version < %d)
                {$codeClause}
              ORDER BY next_attempt_at IS NOT NULL, id ASC
              LIMIT %d",
            $chainId,
            CosmwasmClassifier::NOT_CW721,
            CosmwasmClassifier::CONFIRMED,
            CosmwasmClassifier::PROBABLE,
            CosmwasmClassifier::MAX_RETRIES,
            $now,
            $classifierVersion,
            $limit
        ));
        self::guardRead(__FUNCTION__);

        return $rows ?: [];
    }

    /**
     * Classified CW-721 contracts that have NOT yet been emitted toward
     * `wp_bcc_onchain_collections`.
     *
     * `denied = 0` is a hard predicate here — a DENY rule must prevent
     * reinsertion AND user-facing rediscovery, and this is the query the
     * emit path reads. The caller ALSO re-checks the live rule table
     * per row (a rule can land between two sweeps), so deny is enforced
     * twice on the only path that can create a user-facing row.
     *
     * @return list<CosmwasmContractRow>
     */
    public static function findEmittable(int $chainId, int $limit): array
    {
        if ($chainId <= 0) {
            return [];
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        /** @var list<CosmwasmContractRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id = %d
                AND denied = 0
                AND collection_row_written = 0
                AND classification IN (%s, %s)
              ORDER BY id ASC
              LIMIT %d",
            $chainId,
            CosmwasmClassifier::CONFIRMED,
            CosmwasmClassifier::PROBABLE,
            $limit
        ));
        self::guardRead(__FUNCTION__);

        return $rows ?: [];
    }

    /**
     * Persist a per-contract classification verdict.
     *
     * @param array{classification: string, reason: string, probes_ok: string, probes_failed: string, last_error: string, classifier_version: int} $verdict
     */
    public static function recordClassification(
        int $chainId,
        string $contract,
        array $verdict,
        int $retryCount
    ): bool {
        if ($chainId <= 0 || $contract === '') {
            return false;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        $settled     = !CosmwasmClassifier::isRetryable($verdict['classification'], $retryCount);
        $nextAttempt = $settled ? null : self::nextAttemptAt($retryCount);

        $updated = $wpdb->update(
            $table,
            [
                'classification'        => $verdict['classification'],
                'classification_reason' => mb_substr($verdict['reason'], 0, 64),
                'probes_ok'             => mb_substr($verdict['probes_ok'], 0, 128),
                'probes_failed'         => mb_substr($verdict['probes_failed'], 0, 128),
                'classifier_version'    => $verdict['classifier_version'],
                'classified_at'         => $now,
                'last_attempted_at'     => $now,
                'next_attempt_at'       => $nextAttempt,
                'retry_count'           => $retryCount,
                'last_error'            => $verdict['last_error'] !== ''
                    ? mb_substr($verdict['last_error'], 0, 255)
                    : null,
            ],
            ['chain_id' => $chainId, 'contract_address' => strtolower($contract)],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s'],
            ['%d', '%s']
        );

        return is_int($updated) && $updated >= 0;
    }

    /** Flag a contract as emitted so a later sweep does not re-emit it. */
    public static function markCollectionRowWritten(int $chainId, string $contract): bool
    {
        if ($chainId <= 0 || $contract === '') {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $updated = $wpdb->update(
            $table,
            ['collection_row_written' => 1],
            ['chain_id' => $chainId, 'contract_address' => strtolower($contract)],
            ['%d'],
            ['%d', '%s']
        );

        return is_int($updated) && $updated >= 0;
    }

    /**
     * Sync the cached deny flag from the authoritative rule table.
     *
     * Called whenever the caller has just resolved the live rule for a
     * contract. Setting `denied = 1` is what stops a DENY-ruled contract
     * being picked up by the pending/emit queries — i.e. the rule
     * survives rediscovery instead of the contract being re-inserted on
     * every sweep. Clearing it (an operator unhide) puts the contract
     * back in the ordinary queues, which is what "unhiding permits later
     * discovery / explicit retry" means.
     */
    public static function setDenied(int $chainId, string $contract, bool $denied): bool
    {
        if ($chainId <= 0 || $contract === '') {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $updated = $wpdb->update(
            $table,
            ['denied' => $denied ? 1 : 0],
            ['chain_id' => $chainId, 'contract_address' => strtolower($contract)],
            ['%d'],
            ['%d', '%s']
        );

        return is_int($updated) && $updated >= 0;
    }

    /** Record a failed attempt without changing the verdict. */
    public static function recordAttemptFailure(
        int $chainId,
        string $contract,
        string $error,
        int $retryCount
    ): bool {
        if ($chainId <= 0 || $contract === '') {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $updated = $wpdb->update(
            $table,
            [
                'last_attempted_at' => current_time('mysql', true),
                'next_attempt_at'   => self::nextAttemptAt($retryCount),
                'retry_count'       => $retryCount,
                'last_error'        => mb_substr(CosmwasmClassifier::sanitizeExcerpt($error), 0, 255),
            ],
            ['chain_id' => $chainId, 'contract_address' => strtolower($contract)],
            ['%s', '%s', '%d', '%s'],
            ['%d', '%s']
        );

        return is_int($updated) && $updated >= 0;
    }

    /**
     * Re-point a contract that MIGRATED to a new code id.
     *
     * CosmWasm contracts can be migrated; the address is stable and the
     * code id is not. Recording the move here — rather than inserting a
     * new row or a second collection — is what keeps "contract migration
     * recorded without duplicating the collection" true. The
     * classification is deliberately NOT reset: the running contract is
     * the same contract, and re-probing it is the monthly pass's job,
     * not a side effect of noticing the move.
     */
    public static function recordMigration(int $chainId, string $contract, int $newCodeId): bool
    {
        if ($chainId <= 0 || $contract === '' || $newCodeId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $updated = $wpdb->update(
            $table,
            [
                'code_id'     => $newCodeId,
                'migrated_at' => current_time('mysql', true),
            ],
            ['chain_id' => $chainId, 'contract_address' => strtolower($contract)],
            ['%d', '%s'],
            ['%d', '%s']
        );

        return is_int($updated) && $updated >= 0;
    }

    /**
     * Requeue rows left behind by a classifier-version bump — AND ONLY
     * THOSE. Mirrors
     * {@see CosmwasmCodeFamilyRepository::requeueForClassifierVersion()};
     * settled `not_cw721` contracts are never swept by the default set.
     *
     * @param  list<string>|null $affected
     * @return int rows requeued
     */
    public static function requeueForClassifierVersion(
        int $chainId,
        int $classifierVersion,
        int $limit,
        ?array $affected = null
    ): int {
        if ($chainId <= 0) {
            return 0;
        }
        $limit    = max(1, min(self::MAX_LIMIT, $limit));
        $affected = $affected ?? CosmwasmClassifier::requeueableClassifications();
        $affected = array_values(array_intersect($affected, CosmwasmClassifier::allClassifications()));
        if ($affected === []) {
            return 0;
        }

        global $wpdb;
        $table = self::table();

        $inPlaceholders = implode(', ', array_fill(0, count($affected), '%s'));

        /** @var array<int, string|int> $args */
        $args = array_merge([$chainId], $affected, [$classifierVersion, $limit]);

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET classified_at = NULL,
                    next_attempt_at = NULL,
                    retry_count = 0
              WHERE chain_id = %d
                AND classification IN ({$inPlaceholders})
                AND classifier_version < %d
              LIMIT %d",
            ...$args
        ));

        return is_int($result) ? $result : 0;
    }

    // ── Bounded aggregates for the (future) admin surface ───────────────

    /**
     * Per-classification contract counts for one chain. ONE bounded
     * aggregate; no per-row recompute.
     *
     * @return array<string, int>
     */
    public static function countsByClassification(int $chainId): array
    {
        $out = [];
        foreach (CosmwasmClassifier::allClassifications() as $classification) {
            $out[$classification] = 0;
        }
        if ($chainId <= 0) {
            return $out;
        }

        global $wpdb;
        $table = self::table();

        /** @var list<object{classification: string, total: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT classification, COUNT(*) AS total
               FROM {$table}
              WHERE chain_id = %d
              GROUP BY classification",
            $chainId
        ));
        self::guardRead(__FUNCTION__);

        foreach ($rows ?: [] as $row) {
            $out[(string) $row->classification] = (int) $row->total;
        }

        return $out;
    }

    /**
     * Candidates suppressed by an operator DENY rule, for one chain.
     * Bounded aggregate — lets an admin surface show "N hidden" without
     * joining the rule table per row.
     */
    public static function countDenied(int $chainId): int
    {
        if ($chainId <= 0) {
            return 0;
        }

        global $wpdb;
        $table = self::table();

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE chain_id = %d AND denied = 1",
            $chainId
        ));
        self::guardRead(__FUNCTION__);

        return (int) $total;
    }

    /** Total contracts inventoried for a chain. Bounded aggregate. */
    public static function countForChain(int $chainId): int
    {
        if ($chainId <= 0) {
            return 0;
        }

        global $wpdb;
        $table = self::table();

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE chain_id = %d",
            $chainId
        ));
        self::guardRead(__FUNCTION__);

        return (int) $total;
    }

    /**
     * ALL-CHAIN contract inventory in ONE bounded aggregate.
     *
     * The admin scanner panel needs, per chain: total inventoried, how
     * many were actually probed, how many are suppressed by a DENY rule,
     * the classification split, and how many CW-721 candidates are still
     * waiting to reach the review queue. Every one of those is a slice of
     * the SAME grouping, so they are read ONCE and sliced in PHP rather
     * than issued as five queries per chain.
     *
     * Grouping key is (chain_id, classification, denied,
     * collection_row_written): cardinality is (#chains × 5 × 2 × 2) ≈ 180
     * rows worst case, which is why no LIMIT is needed — it is an
     * aggregate over an indexed prefix, not a row scan handed to PHP.
     *
     * `inspected` counts rows that have actually been probed
     * (`classified_at IS NOT NULL`), which is deliberately NOT the same as
     * `total`: a freshly-enumerated contract is inventoried but not yet
     * inspected, and conflating the two would let the panel claim work it
     * has not done.
     *
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
        global $wpdb;
        $table = self::table();

        /** @var list<object{chain_id: string, classification: string, denied: string, collection_row_written: string, total: string, inspected: string|null}>|null $rows */
        $rows = $wpdb->get_results(
            "SELECT chain_id,
                    classification,
                    denied,
                    collection_row_written,
                    COUNT(*) AS total,
                    SUM(classified_at IS NOT NULL) AS inspected
               FROM {$table}
              GROUP BY chain_id, classification, denied, collection_row_written"
        );

        $out = [];
        foreach ($rows ?: [] as $row) {
            $chainId = (int) $row->chain_id;
            if (!isset($out[$chainId])) {
                $out[$chainId] = [
                    'total'                    => 0,
                    'inspected'                => 0,
                    'denied'                   => 0,
                    'candidates'               => 0,
                    'candidates_awaiting_emit' => 0,
                    'by_classification'        => self::zeroedClassificationMap(),
                ];
            }

            $classification = (string) $row->classification;
            $denied         = (int) $row->denied === 1;
            $written        = (int) $row->collection_row_written === 1;
            $total          = (int) $row->total;
            $inspected      = (int) $row->inspected;

            $out[$chainId]['total']     += $total;
            $out[$chainId]['inspected'] += $inspected;
            if ($denied) {
                $out[$chainId]['denied'] += $total;
            }
            if (isset($out[$chainId]['by_classification'][$classification])) {
                $out[$chainId]['by_classification'][$classification] += $total;
            } else {
                $out[$chainId]['by_classification'][$classification] = $total;
            }

            // A CANDIDATE is a CW-721-classified contract an operator is
            // allowed to see. A denied one is not a candidate — the deny
            // rule suppresses it everywhere, which is the whole point.
            if (!$denied && CosmwasmClassifier::isCw721($classification)) {
                $out[$chainId]['candidates'] += $total;
                if (!$written) {
                    $out[$chainId]['candidates_awaiting_emit'] += $total;
                }
            }
        }

        return $out;
    }

    /**
     * Contract rows for a bounded set of (chain, address) pairs.
     *
     * ONE query behind the admin candidate table's per-row classification
     * detail. Bounded by two capped IN() lists plus a LIMIT; the caller
     * re-keys by (chain_id, address) so the cross-product over-fetch is
     * discarded in PHP instead of being paid for as one query per row.
     *
     * @param  list<int>    $chainIds
     * @param  list<string> $addresses
     * @return list<CosmwasmContractRow>
     */
    public static function findManyForChains(array $chainIds, array $addresses): array
    {
        $chains = array_values(array_unique(array_map('intval', $chainIds)));
        $chains = array_values(array_filter($chains, static fn(int $v): bool => $v > 0));
        $chains = array_slice($chains, 0, 50);

        $normalized = [];
        foreach ($addresses as $address) {
            $lower = strtolower(trim($address));
            if ($lower !== '') {
                $normalized[$lower] = true;
            }
        }
        $list = array_slice(array_keys($normalized), 0, self::MAX_IN_CLAUSE);

        if ($chains === [] || $list === []) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        $chainIn        = implode(',', array_map('intval', $chains));
        $inPlaceholders = implode(', ', array_fill(0, count($list), '%s'));

        /** @var array<int, string|int> $args */
        $args = array_merge($list, [self::MAX_LIMIT]);

        /** @var list<CosmwasmContractRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id IN ({$chainIn})
                AND contract_address IN ({$inPlaceholders})
              ORDER BY chain_id ASC, id ASC
              LIMIT %d",
            ...$args
        ));

        return $rows ?: [];
    }

    /**
     * OPERATOR force-retry: clear the backoff and the retry cap for a
     * bounded set of unresolved contracts on one chain.
     *
     * Mirrors {@see CosmwasmCodeFamilyRepository::forceRetryUnresolved()}
     * — an explicit human decision, so it ignores the classifier version,
     * but it still refuses to touch a settled `not_cw721`, a decided
     * CW-721, or anything a DENY rule covers. `denied = 0` is a hard
     * predicate: a force-retry button must not be a back door around the
     * operator's own hide decision.
     *
     * @return int rows requeued
     */
    public static function forceRetryUnresolved(int $chainId, int $limit): int
    {
        if ($chainId <= 0) {
            return 0;
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        global $wpdb;
        $table = self::table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET classified_at = NULL,
                    next_attempt_at = NULL,
                    retry_count = 0
              WHERE chain_id = %d
                AND denied = 0
                AND classification IN (%s, %s)
              LIMIT %d",
            $chainId,
            CosmwasmClassifier::INCONCLUSIVE,
            CosmwasmClassifier::UNREACHABLE,
            $limit
        ));

        return is_int($result) ? $result : 0;
    }

    /** @return array<string, int> every classification, zeroed. */
    private static function zeroedClassificationMap(): array
    {
        $out = [];
        foreach (CosmwasmClassifier::allClassifications() as $classification) {
            $out[$classification] = 0;
        }

        return $out;
    }

    /**
     * UTC datetime this row becomes eligible again. Computed from the
     * epoch in PHP (BCC norm) rather than by re-parsing a formatted
     * string against an ambiguous MySQL session time zone.
     */
    private static function nextAttemptAt(int $retryCount): string
    {
        return gmdate('Y-m-d H:i:s', time() + CosmwasmClassifier::backoffSeconds($retryCount));
    }
}
