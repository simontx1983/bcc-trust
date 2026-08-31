<?php
/**
 * On-Chain NFT Collections Schema
 *
 * Contains the `CREATE TABLE` (via dbDelta) AND the PR 5a canonical-identity
 * migration that amends it. They are deliberately co-located: the umbrella
 * `scripts/schema-drift-guard.php` takes the FIRST file that declares a table
 * as the authority on its index set, and files are visited in glob order. A
 * separate `schema-collections-canonical.php` sorts BEFORE this file ('-' <
 * '.'), so its lone `ALTER … ADD UNIQUE KEY` became the whole declared set
 * and the eight indexes below read as undeclared live drift. In one file the
 * ALTER-declared key merges into the CREATE TABLE's set instead.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_onchain_collections_table(): string {
    return \BCC\Core\DB\DB::table('onchain_collections');
}

function bcc_onchain_create_collections_table(): void {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_collections_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wallet_link_id BIGINT UNSIGNED DEFAULT NULL,
        contract_address VARCHAR(128) NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        collection_name VARCHAR(200) DEFAULT NULL,
        token_standard VARCHAR(20) DEFAULT NULL,
        total_supply INT UNSIGNED DEFAULT NULL,
        floor_price DECIMAL(20,8) DEFAULT NULL,
        floor_currency VARCHAR(20) DEFAULT NULL,
        unique_holders INT UNSIGNED DEFAULT NULL,
        total_volume DECIMAL(20,8) DEFAULT NULL,
        listed_percentage DECIMAL(5,2) DEFAULT NULL,
        royalty_percentage DECIMAL(5,2) DEFAULT NULL,
        metadata_storage VARCHAR(30) DEFAULT NULL,
        image_url VARCHAR(500) DEFAULT NULL,
        show_on_profile TINYINT(1) NOT NULL DEFAULT 1,
        is_verified TINYINT(1) NOT NULL DEFAULT 0,
        source VARCHAR(20) NOT NULL DEFAULT 'discovery',
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_chain_contract (chain_id, contract_address),
        KEY wallet_link_id (wallet_link_id),
        KEY chain_id (chain_id),
        KEY contract_address (contract_address),
        KEY expires_at (expires_at),
        KEY idx_volume (total_volume),
        KEY idx_floor (floor_price),
        KEY idx_verified (is_verified)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

require_once __DIR__ . '/schema-probe.php';

/** Advisory-lock key. Shared with nothing else. */
if (!defined('BCC_TRUST_CANONICAL_ID_LOCK')) {
    define('BCC_TRUST_CANONICAL_ID_LOCK', 'bcc_trust_mig_collection_canonical_v1');
}

/**
 * PR 5a — add + backfill `canonical_identifier`, then add
 * `uq_chain_canonical`. Leaves `uq_chain_contract` in place.
 *
 * ── WHY THIS IS NOT PART OF THE dbDelta ABOVE ───────────────────────────
 * dbDelta cannot be trusted with either half of this change. It does not
 * reliably emit per-column `COLLATE`, and its index handling is the reason
 * drop-legacy-indexes.php exists. If dbDelta created the column while
 * inheriting the table's utf8mb4_unicode_520_ci default, the column would
 * be present, correct-looking, and still case-folding every Solana mint —
 * defeating the entire point. An explicit ALTER is unambiguous, and the
 * probe -> act -> re-verify cycle around it is how every recent schema
 * change in this directory is written (see schema-chain-nft-capabilities.php).
 *
 * ── WHY `uq_chain_contract` IS DELIBERATELY LEFT IN PLACE ───────────────
 * The obvious "finished" shape drops the old case-insensitive unique key,
 * since it is the thing preventing two case-distinct Solana mints from
 * coexisting. PR 5a does NOT do that, for two reasons:
 *
 *  1. 99 rows on chain 13 hold Magic Eden *symbols* rather than mints
 *     (4-31 chars; a mint is 32-44 base58). 24 are verified and back a
 *     holder community. The service cannot canonicalise them, so they keep
 *     `canonical_identifier = NULL`. MySQL exempts NULLs from a unique key,
 *     so `uq_chain_canonical` ALONE would leave those 99 rows with no
 *     uniqueness constraint at all.
 *
 *  2. All four `INSERT … ON DUPLICATE KEY UPDATE` writers in
 *     CollectionRepository resolve their conflict against `uq_chain_contract`.
 *     Removing it while any row can still carry a NULL canonical identity
 *     turns every re-sync of those rows into duplicate-row creation.
 *
 * Both keys therefore coexist. `uq_chain_contract` is case-INSENSITIVE and
 * so strictly stronger than `uq_chain_canonical` for every family, which
 * means it stays the binding constraint and upsert collision behaviour is
 * unchanged by this migration.
 *
 * The consequence is stated plainly rather than hidden: **case-distinct
 * Solana mints still cannot coexist in this table.** PR 5a installs and
 * proves the mechanism; PR 5b resolves the legacy aliases, and only then can
 * the old key be dropped and the column made NOT NULL.
 *
 * ── SAFETY ──────────────────────────────────────────────────────────────
 * Additive, idempotent, fail-closed, concurrency-aware, and resumable after
 * every step (MySQL auto-commits DDL, so each step must be independently
 * detectable — which is what the probes provide). It cannot delete, merge or
 * overwrite a collection, cannot change verification or community
 * relationships, and cannot enable any capability.
 *
 * Safe to call on every request that bumps the schema version.
 */
function bcc_onchain_add_collections_canonical_identifier(): void
{
    global $wpdb;

    $table = bcc_onchain_collections_table();

    // ── Concurrency ─────────────────────────────────────────────────────
    // Two requests racing here would both see "column absent" and both
    // ALTER; the loser errors. The schema lock in tables.php lets losers
    // "proceed un-migrated", so this migration needs its own.
    if (!\BCC\Core\DB\AdvisoryLock::acquire(BCC_TRUST_CANONICAL_ID_LOCK, 0)) {
        // Not an error: another request is doing this work right now.
        return;
    }

    try {
        // ── Step 1: does the column exist? ──────────────────────────────
        $columnExists = bcc_onchain_probe_count(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
              LIMIT 1",
            [$table, 'canonical_identifier']
        );

        if ($columnExists === null) {
            \BCC\Core\Log\Logger::error(
                '[schema-collections-canonical] could not determine whether canonical_identifier exists; treating as UNVERIFIED, not absent',
                ['table' => $table]
            );
            return;
        }

        // ── Step 2: add it, with an EXPLICIT collation ──────────────────
        // utf8mb4_bin is the whole point: the table default is
        // utf8mb4_unicode_520_ci, and a column that INHERITS the table
        // collation would silently case-fold Solana mints — the exact bug
        // this column exists to fix. It must be declared, never inherited.
        if ($columnExists === 0) {
            $added = $wpdb->query(
                "ALTER TABLE {$table}
                   ADD COLUMN canonical_identifier VARCHAR(128)
                       CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
                       AFTER contract_address"
            );

            // A successful DDL returns 0 rows affected, so `=== false` is
            // the only correct failure test. Truthiness would read every
            // success as a failure.
            if ($added === false) {
                \BCC\Core\Log\Logger::error(
                    '[schema-collections-canonical] failed to add canonical_identifier',
                    ['table' => $table, 'db_error' => $wpdb->last_error]
                );
                return;
            }

            $reVerified = bcc_onchain_probe_count(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
                  LIMIT 1",
                [$table, 'canonical_identifier']
            );
            if ($reVerified === null || $reVerified === 0) {
                \BCC\Core\Log\Logger::error(
                    '[schema-collections-canonical] canonical_identifier still absent after ALTER',
                    ['table' => $table]
                );
                return;
            }
        }

        // ── Step 2b: the collation must be BINARY, not merely present ───
        // If the column ever came into existence by some other route (a
        // dbDelta pass, a hand-run ALTER, a restored dump) it could be
        // sitting on the table's default utf8mb4_unicode_520_ci — present,
        // correct-looking, and still case-folding every Solana mint. The
        // existence probe above cannot tell the difference, so verify the
        // property the column exists FOR and fail closed if it is wrong.
        $collation = $wpdb->get_var($wpdb->prepare(
            "SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
              LIMIT 1",
            $table,
            'canonical_identifier'
        ));

        if (!is_string($collation) || $collation === '') {
            \BCC\Core\Log\Logger::error(
                '[schema-collections-canonical] could not read canonical_identifier collation; treating as UNVERIFIED',
                ['table' => $table]
            );
            return;
        }

        if ($collation !== 'utf8mb4_bin') {
            \BCC\Core\Log\Logger::error(
                '[schema-collections-canonical] canonical_identifier has a case-INSENSITIVE collation; '
                . 'refusing to build a unique key on it. No row was modified.',
                ['table' => $table, 'collation' => $collation, 'expected' => 'utf8mb4_bin']
            );
            return;
        }

        // ── Step 3: backfill what the service accepts ───────────────────
        if (!bcc_onchain_backfill_collections_canonical_identifier()) {
            // The backfill logs its own reason. Do not proceed to the
            // unique key on a partial backfill — the next run resumes.
            return;
        }

        // ── Step 4: collision preflight, BEFORE creating the key ────────
        // An inspection failure is NOT "no collisions". Both a null probe
        // and a positive count abort without touching anything.
        $collisions = bcc_onchain_probe_count(
            "SELECT COUNT(*) FROM (
                 SELECT chain_id FROM {$table}
                  WHERE canonical_identifier IS NOT NULL
                  GROUP BY chain_id, canonical_identifier
                 HAVING COUNT(*) > %d
             ) AS dupes",
            [1]
        );

        if ($collisions === null) {
            \BCC\Core\Log\Logger::error(
                '[schema-collections-canonical] collision preflight was unreadable; refusing to add uq_chain_canonical',
                ['table' => $table]
            );
            return;
        }

        if ($collisions > 0) {
            // Fail closed and LOUD. Nothing is deleted, merged or altered:
            // resolving a genuine collision is an operator decision, not a
            // migration's.
            \BCC\Core\Log\Logger::error(
                '[schema-collections-canonical] canonical collisions present; refusing to add uq_chain_canonical. No row was modified.',
                ['table' => $table, 'colliding_groups' => $collisions]
            );
            return;
        }

        // ── Step 5: add the unique key ──────────────────────────────────
        $uniqueExists = bcc_onchain_probe_count(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              LIMIT 1",
            [$table, 'uq_chain_canonical']
        );

        if ($uniqueExists === null) {
            \BCC\Core\Log\Logger::error(
                '[schema-collections-canonical] could not verify uq_chain_canonical; treating as UNVERIFIED, not absent',
                ['table' => $table]
            );
            return;
        }

        if ($uniqueExists === 0) {
            $addedKey = $wpdb->query(
                "ALTER TABLE {$table} ADD UNIQUE KEY uq_chain_canonical (chain_id, canonical_identifier)"
            );
            if ($addedKey === false) {
                \BCC\Core\Log\Logger::error(
                    '[schema-collections-canonical] failed to add uq_chain_canonical',
                    ['table' => $table, 'db_error' => $wpdb->last_error]
                );
                return;
            }

            $keyReVerified = bcc_onchain_probe_count(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
                  LIMIT 1",
                [$table, 'uq_chain_canonical']
            );
            if ($keyReVerified === null || $keyReVerified === 0) {
                \BCC\Core\Log\Logger::error(
                    '[schema-collections-canonical] uq_chain_canonical still not present after ALTER',
                    ['table' => $table]
                );
            }
        }

        // ── Step 6: NOTHING. `uq_chain_contract` stays. ─────────────────
        // See the file docblock. Dropping it is PR 5b's work, gated on the
        // legacy Solana aliases being resolved.
    } finally {
        \BCC\Core\DB\AdvisoryLock::release(BCC_TRUST_CANONICAL_ID_LOCK);
    }
}

/**
 * Populate `canonical_identifier` for every row the chain-aware service
 * accepts, in bounded batches.
 *
 * Rows the service refuses are LEFT ALONE with a NULL canonical identity —
 * never guessed at, never derived from a name or symbol, never deleted.
 * That NULL means "legacy identity unresolved", and it is the mechanism by
 * which the 99 Magic Eden alias rows survive this migration untouched.
 *
 * @return bool true when the whole table was walked successfully.
 */
function bcc_onchain_backfill_collections_canonical_identifier(): bool
{
    global $wpdb;

    $table = bcc_onchain_collections_table();

    // Resolved directly rather than via bcc_onchain_chains_table(): that
    // helper lives in schema-chains.php, and depending on it here would make
    // this file silently require a particular include ORDER. It happens to
    // hold in production and in the test bootstrap, which is exactly why the
    // coupling would go unnoticed until some caller loaded the two the other
    // way round.
    $chains = \BCC\Core\DB\DB::table('chains');

    $batchSize = 500;
    $lastId    = 0;
    $accepted  = 0;
    /** @var array<string, int> $refused reason => count */
    $refused   = [];

    // Hard ceiling so a pathological loop cannot run forever. 500 * 2000 =
    // one million rows, far above any plausible collections table.
    for ($pass = 0; $pass < 2000; $pass++) {
        // `wpdb::get_results()` returns [] BOTH for "no more rows" and for a
        // failed query, so an empty batch is not by itself proof of
        // completion. last_error is cleared at the start of every query(),
        // so reading it immediately after is a valid failure test — and the
        // postcondition check below is the real backstop.
        /** @var list<object{id: string, contract_address: string, chain_type: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.contract_address, ch.chain_type
               FROM {$table} c
               JOIN {$chains} ch ON ch.id = c.chain_id
              WHERE c.canonical_identifier IS NULL
                AND c.id > %d
              ORDER BY c.id ASC
              LIMIT %d",
            $lastId,
            $batchSize
        ));

        if ($rows === null || $wpdb->last_error !== '') {
            \BCC\Core\Log\Logger::error(
                '[schema-collections-canonical] backfill read failed; aborting without writing',
                ['table' => $table, 'db_error' => $wpdb->last_error, 'after_id' => $lastId]
            );
            return false;
        }

        if ($rows === []) {
            break;
        }

        foreach ($rows as $row) {
            $id     = (int) $row->id;
            $lastId = max($lastId, $id);

            $identity = \BCC\Trust\Onchain\Support\NftCollectionIdentifier::canonicalize(
                (string) $row->chain_type,
                (string) $row->contract_address
            );

            if (!$identity->isAccepted()) {
                $reason           = $identity->reason();
                $refused[$reason] = ($refused[$reason] ?? 0) + 1;
                continue;
            }

            // Guarded UPDATE: `canonical_identifier IS NULL` in the WHERE
            // means a concurrent run that already wrote this row cannot be
            // overwritten, and a row can never be re-pointed at a different
            // identity by a rerun.
            $written = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                    SET canonical_identifier = %s
                  WHERE id = %d AND canonical_identifier IS NULL",
                $identity->canonical(),
                $id
            ));

            if ($written === false) {
                \BCC\Core\Log\Logger::error(
                    '[schema-collections-canonical] backfill UPDATE failed; aborting',
                    ['table' => $table, 'row_id' => $id, 'db_error' => $wpdb->last_error]
                );
                return false;
            }

            $accepted++;
        }
    }

    // ── Postcondition: prove the walk actually finished ─────────────────
    // Every row still NULL must be one this run deliberately refused. If
    // more rows are NULL than we refused, the loop exited early — a silent
    // read failure, or the pass ceiling — and reporting success would be a
    // lie that the unique-key step would then build on.
    // The JOIN mirrors the backfill SELECT exactly. A row whose chain_id
    // does not resolve has no determinable family, so it is invisible to
    // both queries and correctly stays NULL — the two counts stay coherent.
    // `id > %d` is a no-op filter (ids are positive) that keeps this a
    // genuine prepared statement; wpdb::prepare() warns on a placeholderless
    // query, and the test suite runs with failOnWarning.
    $refusedTotal = array_sum($refused);
    $stillNull    = bcc_onchain_probe_count(
        "SELECT COUNT(*) FROM {$table} c
           JOIN {$chains} ch ON ch.id = c.chain_id
          WHERE c.canonical_identifier IS NULL AND c.id > %d",
        [0]
    );

    if ($stillNull === null) {
        \BCC\Core\Log\Logger::error(
            '[schema-collections-canonical] could not verify the backfill postcondition; treating as INCOMPLETE',
            ['table' => $table]
        );
        return false;
    }

    if ($stillNull !== $refusedTotal) {
        \BCC\Core\Log\Logger::error(
            '[schema-collections-canonical] backfill did not walk the whole table; refusing to continue',
            ['table' => $table, 'still_null' => $stillNull, 'refused' => $refusedTotal, 'accepted' => $accepted]
        );
        return false;
    }

    if ($accepted > 0 || $refused !== []) {
        // Counts only — no identifiers. `refused` is the quarantine census
        // and is expected to be non-empty until PR 5b lands (99 legacy
        // Magic Eden aliases, of which 24 are verified).
        \BCC\Core\Log\Logger::info(
            '[schema-collections-canonical] backfill complete',
            ['accepted' => $accepted, 'refused_by_reason' => $refused]
        );
    }

    return true;
}
