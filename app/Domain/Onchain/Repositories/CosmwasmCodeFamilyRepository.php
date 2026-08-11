<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CosmWasm code-family inventory repository.
 *
 * Owns `wp_bcc_cosmwasm_code_families` — the durable per-(chain, code id)
 * record of what a stored wasm binary IS, how we decided, and how far
 * its contract enumeration has got. See
 * includes/database/schema-cosmwasm-code-families.php for the full
 * five-table division of responsibility.
 *
 * §1: every `$wpdb` touch for this table lives here. §2: explicit
 * COLUMNS, never `SELECT *`. §4: every SELECT is bounded by a unique
 * key, a LIMIT, or is an aggregate.
 *
 * The count/summary methods at the bottom exist so a future admin
 * surface can render per-chain progress with BOUNDED AGGREGATE queries
 * and never a per-row recompute.
 *
 * @phpstan-type CodeFamilyRow object{
 *     id: string,
 *     chain_id: string,
 *     code_id: string,
 *     checksum: string|null,
 *     classification: string,
 *     classification_reason: string|null,
 *     probes_ok: string|null,
 *     probes_failed: string|null,
 *     classifier_version: string,
 *     sample_contract: string|null,
 *     classified_at: string|null,
 *     last_attempted_at: string|null,
 *     next_attempt_at: string|null,
 *     retry_count: string,
 *     last_error: string|null,
 *     contracts_cursor: string|null,
 *     contracts_enumerated: string,
 *     enumeration_complete: string,
 *     last_enumerated_at: string|null,
 *     metadata_checked_at: string|null,
 *     created_at: string
 * }
 */
final class CosmwasmCodeFamilyRepository
{
    use GuardsReadFailures;

    private const COLUMNS = 'id, chain_id, code_id, checksum, classification, classification_reason,'
        . ' probes_ok, probes_failed, classifier_version, sample_contract, classified_at,'
        . ' last_attempted_at, next_attempt_at, retry_count, last_error, contracts_cursor,'
        . ' contracts_enumerated, enumeration_complete, last_enumerated_at, metadata_checked_at,'
        . ' created_at';

    /** Defensive ceiling on any list read. */
    private const MAX_LIMIT = 200;

    public static function table(): string
    {
        return DB::table('cosmwasm_code_families');
    }

    /**
     * UTC datetime this row becomes eligible again.
     *
     * Computed from the epoch (not by re-parsing a formatted string) so
     * the value is unambiguous regardless of the MySQL session time zone
     * — the repo's `time_zone = SYSTEM` split is a known trap and BCC's
     * norm is to compute datetimes in PHP, in UTC.
     */
    private static function nextAttemptAt(int $retryCount): string
    {
        return gmdate('Y-m-d H:i:s', time() + CosmwasmClassifier::backoffSeconds($retryCount));
    }

    /**
     * Record code families learned from the code LISTING endpoint.
     *
     * Idempotent by (chain_id, code_id). The checksum is refreshed on
     * conflict (a listing re-read is authoritative) but NOTHING else is
     * touched — an already-classified family must not lose its verdict,
     * its retry state or its enumeration cursor just because the listing
     * was walked again. That is what makes a duplicate response harmless.
     *
     * @param list<array{code_id: int, checksum: ?string}> $families
     * @return int rows inserted or updated
     */
    public static function recordDiscovered(int $chainId, array $families): int
    {
        if ($chainId <= 0 || $families === []) {
            return 0;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        $values       = [];
        $placeholders = [];
        foreach ($families as $family) {
            $codeId = (int) $family['code_id'];
            if ($codeId <= 0) {
                continue;
            }
            $checksum = $family['checksum'] ?? null;
            $checksum = is_string($checksum) && $checksum !== ''
                ? substr(strtolower($checksum), 0, 64)
                : null;

            // A missing checksum must land as SQL NULL, not ''. `prepare()`
            // coerces a null `%s` to an empty string, and an empty-string
            // checksum would both defeat `COALESCE(VALUES(checksum), …)`
            // on a later refresh and pretend to be a real hash to the
            // twin lookup. So the NULL case emits a literal instead.
            if ($checksum === null) {
                $placeholders[] = '(%d, %d, NULL, %s, %d, %s)';
                $values[] = $chainId;
                $values[] = $codeId;
                $values[] = CosmwasmClassifier::INCONCLUSIVE;
                $values[] = 0;
                $values[] = $now;
                continue;
            }

            $placeholders[] = '(%d, %d, %s, %s, %d, %s)';
            $values[] = $chainId;
            $values[] = $codeId;
            $values[] = $checksum;
            $values[] = CosmwasmClassifier::INCONCLUSIVE;
            $values[] = 0;
            $values[] = $now;
        }

        if ($placeholders === []) {
            return 0;
        }

        $sql = "INSERT INTO {$table}
                    (chain_id, code_id, checksum, classification, classifier_version, created_at)
                 VALUES " . implode(', ', $placeholders) . "
                 ON DUPLICATE KEY UPDATE
                    checksum = COALESCE(VALUES(checksum), checksum)";

        /** @var string $prepared */
        $prepared = $wpdb->prepare($sql, $values);
        $result   = $wpdb->query($prepared);

        return is_int($result) ? $result : 0;
    }

    /** @return CodeFamilyRow|null */
    public static function find(int $chainId, int $codeId): ?object
    {
        if ($chainId <= 0 || $codeId <= 0) {
            return null;
        }

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        /** @var CodeFamilyRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id = %d
                AND code_id = %d
              LIMIT 1",
            $chainId,
            $codeId
        ));
        self::guardRead(__FUNCTION__);

        return $row;
    }

    /**
     * Families awaiting (re)classification for this chain, oldest-due
     * first.
     *
     * Selection rules, all expressed in SQL so the worker cannot forget
     * one:
     *   - never a settled `not_cw721` (terminal — no routine
     *     reclassification, ever);
     *   - never an already-decided CW-721 family (its classification is
     *     done; NEW CONTRACTS under it are a different sweep);
     *   - respect the retry cap;
     *   - respect the precomputed backoff (`next_attempt_at`);
     *   - include anything whose classifier_version is behind, so a
     *     version bump requeues without a separate write pass.
     *
     * Priority code IDs (the `BCC_CW721_CODE_IDS` hint) are ordered
     * FIRST — a hint that only reorders, never restricts.
     *
     * @param list<int> $priorityCodeIds
     * @return list<CodeFamilyRow>
     */
    public static function findPendingClassification(
        int $chainId,
        int $limit,
        int $classifierVersion,
        array $priorityCodeIds = []
    ): array {
        if ($chainId <= 0) {
            return [];
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;
        $now   = current_time('mysql', true);

        // Bounded IN() list — the hint is operator-authored and tiny; the
        // slice keeps a pathological wp-config from widening the query.
        $priority = array_slice(array_values(array_unique(array_map('intval', $priorityCodeIds))), 0, 50);

        // ── ORDER BY IS BUILT CONDITIONALLY, NOT WITH A PLACEHOLDER TERM ──
        // An earlier revision emitted the string '0' as a no-op leading
        // term when there was no priority hint. IN MYSQL AN INTEGER
        // LITERAL IN ORDER BY IS A COLUMN ORDINAL, AND ORDINALS ARE
        // 1-BASED — so `ORDER BY 0` is the error "Unknown column '0' in
        // 'order clause'", not a constant. `get_results()` returned null,
        // this method returned [], and the worker read that as "nothing
        // pending": classification never ran, silently, in the DEFAULT
        // configuration (no BCC_CW721_CODE_IDS). With a hint configured
        // the CASE expression is valid and everything worked, which is
        // exactly why it survived review.
        //
        // So: no placeholder term. When there is no hint the clause simply
        // does not carry a priority component. (A parenthesised `(SELECT
        // 0)` or a literal NULL would also parse as an expression rather
        // than an ordinal, but omitting the term is clearer and cheaper.)
        $orderBy = 'next_attempt_at IS NOT NULL, code_id ASC';
        if ($priority !== []) {
            $orderBy = 'CASE WHEN code_id IN (' . implode(',', $priority) . ') THEN 0 ELSE 1 END, ' . $orderBy;
        }

        /** @var list<CodeFamilyRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id = %d
                AND classification NOT IN (%s, %s, %s)
                AND retry_count < %d
                AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
                AND (classified_at IS NULL OR classifier_version < %d)
              ORDER BY {$orderBy}
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
     * CW-721 families whose contract enumeration still has work, oldest
     * first.
     *
     * Used by BOTH the backfill (drain a family's contract list) and the
     * daily "any new contracts under a known CW-721 family?" pass — the
     * daily pass simply re-opens a completed family from its last cursor
     * position, which is why `enumeration_complete` is a filter the
     * caller chooses rather than a hard predicate here.
     *
     * @param bool $onlyIncomplete true → families mid-enumeration only
     * @return list<CodeFamilyRow>
     */
    public static function findEnumerable(int $chainId, int $limit, bool $onlyIncomplete): array
    {
        if ($chainId <= 0) {
            return [];
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        $completeClause = $onlyIncomplete ? 'AND enumeration_complete = 0' : '';

        /** @var list<CodeFamilyRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id = %d
                AND classification IN (%s, %s)
                {$completeClause}
              ORDER BY last_enumerated_at IS NOT NULL, last_enumerated_at ASC, code_id ASC
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
     * An already-settled family on the same chain sharing this checksum.
     *
     * Identical checksum = identical binary, so its verdict about the
     * BINARY transfers. Only the inheritable (binary-determined) states
     * are considered — `temporarily_unreachable` / `inconclusive`
     * describe a node or a sample, not the code, and must never spread.
     *
     * Bounded: unique-ish index on (chain_id, checksum) + LIMIT 1.
     *
     * @return CodeFamilyRow|null
     */
    public static function findClassifiedTwinByChecksum(
        int $chainId,
        string $checksum,
        int $excludeCodeId,
        int $classifierVersion
    ): ?object {
        if ($chainId <= 0 || $checksum === '') {
            return null;
        }

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        $inheritable = CosmwasmClassifier::inheritableClassifications();

        /** @var CodeFamilyRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id = %d
                AND checksum = %s
                AND code_id <> %d
                AND classifier_version >= %d
                AND classified_at IS NOT NULL
                AND classification IN (%s, %s, %s)
              ORDER BY classified_at ASC
              LIMIT 1",
            $chainId,
            substr(strtolower($checksum), 0, 64),
            $excludeCodeId,
            $classifierVersion,
            $inheritable[0],
            $inheritable[1],
            $inheritable[2]
        ));
        self::guardRead(__FUNCTION__);

        return $row;
    }

    /**
     * Persist a classification verdict.
     *
     * `retry_count` and `next_attempt_at` are only advanced for
     * non-settled verdicts; a settled family's backoff is cleared so the
     * retry index stays small.
     *
     * @param array{classification: string, reason: string, probes_ok: string, probes_failed: string, last_error: string, classifier_version: int} $verdict
     */
    public static function recordClassification(
        int $chainId,
        int $codeId,
        array $verdict,
        ?string $sampleContract,
        int $retryCount
    ): bool {
        if ($chainId <= 0 || $codeId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        $classification = $verdict['classification'];
        $settled        = !CosmwasmClassifier::isRetryable($classification, $retryCount);

        $nextAttempt = $settled ? null : self::nextAttemptAt($retryCount);

        $data = [
            'classification'        => $classification,
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
            'sample_contract'       => $sampleContract !== null && $sampleContract !== ''
                ? mb_substr(strtolower($sampleContract), 0, 128)
                : null,
        ];
        $formats = ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s'];

        $updated = $wpdb->update(
            $table,
            $data,
            ['chain_id' => $chainId, 'code_id' => $codeId],
            $formats,
            ['%d', '%d']
        );

        return is_int($updated) && $updated >= 0;
    }

    /**
     * Advance (or close) a family's contract-enumeration cursor.
     *
     * `$cursor === null && $complete === true` is the normal drain; a
     * non-null cursor means more pages remain and the next tick resumes
     * there. Progress is written BEFORE the tick's deadline can bite,
     * which is what makes the walk resumable rather than restartable.
     *
     * `$position` is an ABSOLUTE offset into the code's contract listing,
     * not a delta, and it is written with GREATEST() so it is monotonic.
     * A delta would be corrupted by the deliberate overlap re-read the
     * incremental tail pass performs (and by any duplicate LCD page);
     * an absolute high-water mark is idempotent under both.
     */
    public static function recordEnumerationProgress(
        int $chainId,
        int $codeId,
        ?string $cursor,
        int $position,
        bool $complete
    ): bool {
        if ($chainId <= 0 || $codeId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        // The cursor is legitimately NULL on the last page, and
        // `prepare()` coerces a null `%s` to an empty string — so the
        // NULL/non-NULL branches are separate statements rather than one
        // with a nullable placeholder. GREATEST() stays in SQL because
        // the position is an absolute high-water mark and must be
        // monotonic even under a concurrent writer.
        $storedCursor = $cursor !== null && $cursor !== '' ? mb_substr($cursor, 0, 255) : null;

        if ($storedCursor === null) {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                    SET contracts_cursor = NULL,
                        contracts_enumerated = GREATEST(contracts_enumerated, %d),
                        enumeration_complete = %d,
                        last_enumerated_at = %s
                  WHERE chain_id = %d
                    AND code_id = %d
                  LIMIT 1",
                max(0, $position),
                $complete ? 1 : 0,
                $now,
                $chainId,
                $codeId
            ));

            return $result !== false;
        }

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET contracts_cursor = %s,
                    contracts_enumerated = GREATEST(contracts_enumerated, %d),
                    enumeration_complete = %d,
                    last_enumerated_at = %s
              WHERE chain_id = %d
                AND code_id = %d
              LIMIT 1",
            $storedCursor,
            max(0, $position),
            $complete ? 1 : 0,
            $now,
            $chainId,
            $codeId
        ));

        return $result !== false;
    }

    /**
     * Mark a family as fully settled without enumerating the rest of its
     * contracts.
     *
     * This is the "a non-NFT family settles WITHOUT scanning all its
     * contracts" guarantee, written down: three sampled probes decide the
     * family, the cursor is closed, and the remaining contracts are never
     * requested.
     */
    public static function closeEnumeration(int $chainId, int $codeId): bool
    {
        if ($chainId <= 0 || $codeId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET enumeration_complete = 1,
                    contracts_cursor = NULL,
                    last_enumerated_at = %s
              WHERE chain_id = %d
                AND code_id = %d
              LIMIT 1",
            $now,
            $chainId,
            $codeId
        ));

        return $result !== false;
    }

    /** Record a failed attempt without changing the verdict. */
    public static function recordAttemptFailure(
        int $chainId,
        int $codeId,
        string $error,
        int $retryCount
    ): bool {
        if ($chainId <= 0 || $codeId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);
        $next  = self::nextAttemptAt($retryCount);

        $updated = $wpdb->update(
            $table,
            [
                'last_attempted_at' => $now,
                'next_attempt_at'   => $next,
                'retry_count'       => $retryCount,
                'last_error'        => mb_substr(CosmwasmClassifier::sanitizeExcerpt($error), 0, 255),
            ],
            ['chain_id' => $chainId, 'code_id' => $codeId],
            ['%s', '%s', '%d', '%s'],
            ['%d', '%d']
        );

        return is_int($updated) && $updated >= 0;
    }

    /**
     * Requeue rows left behind by a classifier-version bump — AND ONLY
     * THOSE.
     *
     * `$affected` defaults to
     * {@see CosmwasmClassifier::requeueableClassifications()}, which
     * deliberately EXCLUDES `not_cw721`: a settled negative is never
     * routinely reclassified, and a version bump is routine. Confirmed
     * families are excluded too — re-proving them costs requests and
     * changes nothing.
     *
     * Requeueing = clearing the decision timestamp and the backoff, so
     * the ordinary pending query picks the row up. Nothing else about the
     * row changes; in particular, the previous verdict stays readable
     * until a new one replaces it.
     *
     * @param list<string>|null $affected explicit override for one-off remediation
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
     * Per-classification family counts for one chain.
     *
     * ONE bounded aggregate (GROUP BY over an indexed prefix), never a
     * per-row recompute. Missing classifications come back as 0 so a
     * caller can render a stable table.
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
     * Total families known for a chain.
     *
     * Doubles as the OFFSET anchor for the daily code-listing tail read:
     * codes are append-only, so "how many do we already have" is exactly
     * "how far into the listing to skip". Derived rather than stored so a
     * partial insert self-heals instead of permanently skipping codes.
     */
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
     * Families still awaiting a first or refreshed classification.
     *
     * Bounded aggregate mirroring findPendingClassification's predicate
     * so an admin "N remaining" figure never disagrees with what the
     * worker will actually pick up.
     */
    public static function countPendingClassification(int $chainId, int $classifierVersion): int
    {
        if ($chainId <= 0) {
            return 0;
        }

        global $wpdb;
        $table = self::table();

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$table}
              WHERE chain_id = %d
                AND classification NOT IN (%s, %s, %s)
                AND retry_count < %d
                AND (classified_at IS NULL OR classifier_version < %d)",
            $chainId,
            CosmwasmClassifier::NOT_CW721,
            CosmwasmClassifier::CONFIRMED,
            CosmwasmClassifier::PROBABLE,
            CosmwasmClassifier::MAX_RETRIES,
            $classifierVersion
        ));
        self::guardRead(__FUNCTION__);

        return (int) $total;
    }

    /**
     * ALL-CHAIN per-classification family counts. ONE bounded aggregate.
     *
     * The admin scanner panel renders every cosmos chain at once. Calling
     * {@see countsByClassification()} in a loop would be one query per
     * chain — the per-row/per-chain recompute the panel is forbidden to
     * do. This is the same GROUP BY with `chain_id` added to the grouping
     * key, so the whole panel costs ONE read no matter how many chains
     * exist. Result cardinality is (#chains × #classifications) ≈ 45.
     *
     * @return array<int, array<string, int>> chainId => classification => count
     */
    public static function countsByChainAndClassification(): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<object{chain_id: string, classification: string, total: string}>|null $rows */
        $rows = $wpdb->get_results(
            "SELECT chain_id, classification, COUNT(*) AS total
               FROM {$table}
              GROUP BY chain_id, classification"
        );

        $out = [];
        foreach ($rows ?: [] as $row) {
            $chainId = (int) $row->chain_id;
            if (!isset($out[$chainId])) {
                $out[$chainId] = self::zeroedClassificationMap();
            }
            $out[$chainId][(string) $row->classification] = (int) $row->total;
        }

        return $out;
    }

    /**
     * ALL-CHAIN "families still awaiting classification" counts. ONE
     * bounded aggregate.
     *
     * The predicate is a deliberate copy of
     * {@see findPendingClassification()}'s WHERE clause MINUS the
     * `next_attempt_at` backoff term: the panel's "N remaining" must mean
     * "N the worker still has to decide", not "N eligible in this exact
     * second" — a number that would flicker as backoffs expire. It
     * matches {@see countPendingClassification()} exactly, which is the
     * single-chain version of this query.
     *
     * @return array<int, int> chainId => pending count
     */
    public static function pendingCountsByChain(int $classifierVersion): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<object{chain_id: string, total: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT chain_id, COUNT(*) AS total
               FROM {$table}
              WHERE classification NOT IN (%s, %s, %s)
                AND retry_count < %d
                AND (classified_at IS NULL OR classifier_version < %d)
              GROUP BY chain_id",
            CosmwasmClassifier::NOT_CW721,
            CosmwasmClassifier::CONFIRMED,
            CosmwasmClassifier::PROBABLE,
            CosmwasmClassifier::MAX_RETRIES,
            $classifierVersion
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[(int) $row->chain_id] = (int) $row->total;
        }

        return $out;
    }

    /**
     * Family rows for a bounded set of (chain, code id) pairs.
     *
     * The admin candidate table renders ≤ 50 collection rows spanning a
     * handful of chains and needs each row's code family (checksum,
     * family verdict). Doing that per row would be 50 queries; this is
     * ONE, bounded by two capped IN() lists plus a LIMIT. The caller
     * re-keys by (chain_id, code_id) in PHP, so the cross-product
     * over-fetch is discarded there rather than paid for per row.
     *
     * @param  list<int> $chainIds
     * @param  list<int> $codeIds
     * @return list<CodeFamilyRow>
     */
    public static function findManyForChains(array $chainIds, array $codeIds): array
    {
        $chains = self::boundedIntList($chainIds, 50);
        $codes  = self::boundedIntList($codeIds, self::MAX_LIMIT);
        if ($chains === [] || $codes === []) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        $chainIn = implode(',', $chains);
        $codeIn  = implode(',', $codes);

        /** @var list<CodeFamilyRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id IN ({$chainIn})
                AND code_id IN ({$codeIn})
              ORDER BY chain_id ASC, code_id ASC
              LIMIT %d",
            self::MAX_LIMIT
        ));

        return $rows ?: [];
    }

    /**
     * OPERATOR force-retry: clear the backoff and the retry cap for a
     * bounded set of unresolved families on one chain.
     *
     * Distinct from {@see requeueForClassifierVersion()} on purpose. That
     * one is the AUTOMATIC sweep and only touches rows left behind by a
     * classifier-version bump, so it can never resurrect anything at the
     * current version. This one is an explicit human decision ("the node
     * was down all week, look again now") and therefore ignores the
     * version — but it still refuses to touch a settled `not_cw721` or a
     * decided CW-721, because "no routine reclassification of settled
     * families" is not a policy an operator button gets to override.
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
     * Deduplicated, positive, capped int list for an inlined IN() clause.
     *
     * The values are cast with `intval` and re-cast on implode, so no
     * caller-supplied string can reach the SQL — the IN() list is inlined
     * (rather than placeholdered) only because its cardinality varies and
     * `prepare()` cannot express a variadic list.
     *
     * @param  list<int> $values
     * @return list<int>
     */
    private static function boundedIntList(array $values, int $cap): array
    {
        $ints = array_values(array_unique(array_map('intval', $values)));
        $ints = array_values(array_filter($ints, static fn(int $v): bool => $v > 0));

        return array_slice($ints, 0, max(1, $cap));
    }

    /**
     * Families due for the "monthly" migration/metadata check.
     *
     * @return list<CodeFamilyRow>
     */
    public static function findDueForMetadataCheck(int $chainId, string $cutoff, int $limit): array
    {
        if ($chainId <= 0) {
            return [];
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        /** @var list<CodeFamilyRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id = %d
                AND classification IN (%s, %s)
                AND (metadata_checked_at IS NULL OR metadata_checked_at <= %s)
              ORDER BY metadata_checked_at IS NOT NULL, metadata_checked_at ASC, code_id ASC
              LIMIT %d",
            $chainId,
            CosmwasmClassifier::CONFIRMED,
            CosmwasmClassifier::PROBABLE,
            $cutoff,
            $limit
        ));
        self::guardRead(__FUNCTION__);

        return $rows ?: [];
    }

    public static function touchMetadataChecked(int $chainId, int $codeId): bool
    {
        if ($chainId <= 0 || $codeId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $updated = $wpdb->update(
            $table,
            ['metadata_checked_at' => current_time('mysql', true)],
            ['chain_id' => $chainId, 'code_id' => $codeId],
            ['%s'],
            ['%d', '%d']
        );

        return is_int($updated) && $updated >= 0;
    }
}
