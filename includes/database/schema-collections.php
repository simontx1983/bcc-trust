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
 *  1. Pre-PR-5a Solana rows may hold Magic Eden *symbols* rather than
 *     mints, and some are verified and back a holder community. The
 *     service cannot canonicalise a symbol, so those rows keep
 *     `canonical_identifier = NULL`. MySQL exempts NULLs from a unique key,
 *     so `uq_chain_canonical` ALONE would leave every such row with no
 *     uniqueness constraint at all. Measured prevalence is environment
 *     specific and lives in the PR 5a handoff, not here.
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
 * which pre-PR-5a Magic Eden alias rows survive this migration untouched.
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
        // and is expected to be non-empty wherever pre-PR-5a Magic Eden
        // alias rows exist, until PR 5b resolves them.
        \BCC\Core\Log\Logger::info(
            '[schema-collections-canonical] backfill complete',
            ['accepted' => $accepted, 'refused_by_reason' => $refused]
        );
    }

    return true;
}

/** Advisory-lock key for the PR 6 provisioning-state migration. */
if (!defined('BCC_TRUST_PROVISIONING_STATE_LOCK')) {
    define('BCC_TRUST_PROVISIONING_STATE_LOCK', 'bcc_trust_mig_collection_provisioning_v1');
}

/**
 * PR 6 — add the four provisioning-intent columns, the composite queue
 * index, and backfill `provisioned` for communities that already exist.
 *
 * ── WHY COLUMNS, AND WHY FOUR ───────────────────────────────────────────
 * Issue #215 proposed three: `provisioning_state`, `provisioning_requested_at`,
 * `provisioning_last_error`. Two changes:
 *
 *  - `provisioning_last_error VARCHAR(255)` is REPLACED by
 *    `provisioning_failure_code VARCHAR(32)` over a closed vocabulary
 *    ({@see \BCC\Trust\Onchain\ValueObjects\ProvisioningFailureCode}).
 *    A durable free-text column is where provider prose and exception
 *    messages eventually land, and PR 5b removed exactly that channel from
 *    the audit path. Prose stays in the short-retention file log.
 *
 *  - `provisioning_requested_by BIGINT UNSIGNED` is ADDED. The cron reads
 *    the ROW, not the audit log, so without it "authorized by administrator
 *    N" cannot reach provision time except by a fragile query back into
 *    `wp_bcc_trust_activity`. It is also what replaces the lowest-user-id
 *    guess as the authorization record.
 *
 * ── WHY THIS INDEX ──────────────────────────────────────────────────────
 * The queue query that actually runs is
 * {@see \BCC\Trust\Onchain\Repositories\CollectionRepository::listRequested()}:
 *
 *     WHERE c.provisioning_state = 'requested' AND c.id > <cursor>
 *     ORDER BY c.id ASC LIMIT <n>
 *
 * `(provisioning_state, id)` serves the equality, the cursor range AND the
 * ordering from one index, so the plan is a bounded range scan with no
 * filesort. A state-only index would satisfy the equality and then sort.
 * The composite is chosen for that query shape, not because it looks
 * plausible; the EXPLAIN proof is in the integration suite.
 *
 * ── SAFETY ──────────────────────────────────────────────────────────────
 * Additive, idempotent, fail-closed, concurrency-aware, resumable. It drops
 * nothing, does not touch `canonical_identifier`, does not touch
 * `is_verified`, creates no community, and cannot modify the legacy alias
 * rows beyond giving them the same `'none'` default every other row gets.
 */
function bcc_onchain_add_collections_provisioning_state(): void
{
    global $wpdb;

    $table = bcc_onchain_collections_table();

    if (!\BCC\Core\DB\AdvisoryLock::acquire(BCC_TRUST_PROVISIONING_STATE_LOCK, 0)) {
        // Another request is doing this work right now. Not an error.
        return;
    }

    try {
        // ── Step 1: the four columns, each probed independently ─────────
        // Probed one at a time rather than as a group: a partially applied
        // migration (one ALTER succeeded, the process died) must be
        // resumable, and "some of them exist" must not read as "done".
        $columns = [
            'provisioning_state'        => "ADD COLUMN provisioning_state VARCHAR(20) NOT NULL DEFAULT 'none' AFTER source",
            'provisioning_requested_at' => 'ADD COLUMN provisioning_requested_at DATETIME NULL AFTER provisioning_state',
            'provisioning_requested_by' => 'ADD COLUMN provisioning_requested_by BIGINT UNSIGNED NULL AFTER provisioning_requested_at',
            'provisioning_failure_code' => 'ADD COLUMN provisioning_failure_code VARCHAR(32) NULL AFTER provisioning_requested_by',
        ];

        foreach ($columns as $column => $clause) {
            $exists = bcc_onchain_probe_count(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
                  LIMIT 1",
                [$table, $column]
            );

            if ($exists === null) {
                \BCC\Core\Log\Logger::error(
                    '[schema-collections-provisioning] could not determine whether a column exists; treating as UNVERIFIED, not absent',
                    ['table' => $table, 'column' => $column]
                );
                return;
            }

            if ($exists > 0) {
                continue;
            }

            // A successful DDL returns 0 rows affected, so `=== false` is the
            // only correct failure test.
            $added = $wpdb->query("ALTER TABLE {$table} {$clause}");
            if ($added === false) {
                \BCC\Core\Log\Logger::error(
                    '[schema-collections-provisioning] failed to add a column',
                    ['table' => $table, 'column' => $column, 'db_error' => $wpdb->last_error]
                );
                return;
            }

            $reVerified = bcc_onchain_probe_count(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
                  LIMIT 1",
                [$table, $column]
            );
            if ($reVerified === null || $reVerified === 0) {
                \BCC\Core\Log\Logger::error(
                    '[schema-collections-provisioning] column still absent after ALTER',
                    ['table' => $table, 'column' => $column]
                );
                return;
            }
        }

        // ── Step 2: the composite queue index ───────────────────────────
        $indexExists = bcc_onchain_probe_count(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              LIMIT 1",
            [$table, 'idx_provisioning_state_id']
        );

        if ($indexExists === null) {
            \BCC\Core\Log\Logger::error(
                '[schema-collections-provisioning] could not verify idx_provisioning_state_id; treating as UNVERIFIED, not absent',
                ['table' => $table]
            );
            return;
        }

        if ($indexExists === 0) {
            $addedKey = $wpdb->query(
                "ALTER TABLE {$table} ADD KEY idx_provisioning_state_id (provisioning_state, id)"
            );
            if ($addedKey === false) {
                \BCC\Core\Log\Logger::error(
                    '[schema-collections-provisioning] failed to add idx_provisioning_state_id',
                    ['table' => $table, 'db_error' => $wpdb->last_error]
                );
                return;
            }

            $keyReVerified = bcc_onchain_probe_count(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
                  LIMIT 1",
                [$table, 'idx_provisioning_state_id']
            );
            if ($keyReVerified === null || $keyReVerified === 0) {
                \BCC\Core\Log\Logger::error(
                    '[schema-collections-provisioning] idx_provisioning_state_id still not present after ALTER',
                    ['table' => $table]
                );
                return;
            }
        }

        // ── Step 3: backfill existing communities ───────────────────────
        bcc_onchain_backfill_collections_provisioning_state();
    } finally {
        \BCC\Core\DB\AdvisoryLock::release(BCC_TRUST_PROVISIONING_STATE_LOCK);
    }
}

/**
 * Mark every collection that ALREADY has a live holder community as
 * `provisioned`. Everything else keeps the `'none'` column default.
 *
 * ── WHY EVERYTHING ELSE STAYS `none`, INCLUDING VERIFIED ROWS ───────────
 * This is the whole point of PR 6. Pre-existing verification must NOT be
 * retro-read as authorization: a verified collection that nobody asked for a
 * community for has, by definition, no recorded request, and inventing one
 * would reproduce the coupling this migration exists to break. The accepted
 * consequence — stated rather than hidden — is that a verified-but-
 * unprovisioned collection simply stops being auto-provisioned until an
 * operator asks.
 *
 * ── WHY THE REQUESTER IS LEFT NULL ──────────────────────────────────────
 * These communities predate the concept of a request. There is no
 * administrator who authorized them, and writing a plausible one (the site
 * owner, the lowest-id admin) would fabricate an authorization that never
 * happened. {@see \BCC\Trust\Onchain\ValueObjects\ProvisioningState::fieldViolations()}
 * exempts exactly this case and no other.
 *
 * ── WHY NOT `UPDATE ... JOIN ... LIMIT` ─────────────────────────────────
 * MySQL silently ignores LIMIT on a multi-table UPDATE — the statement runs
 * unbounded or, with some configurations, no-ops. Collecting ids in bounded
 * batches and updating by primary key keeps the write genuinely bounded and
 * the failure modes visible.
 *
 * @return bool true when the backfill completed and its postcondition held
 */
function bcc_onchain_backfill_collections_provisioning_state(): bool
{
    global $wpdb;

    $table     = bcc_onchain_collections_table();
    $batchSize = 200;
    $afterPost = 0;
    $marked    = 0;

    // Ceiling: 200 * 500 = 100,000 gated communities, far above any
    // plausible install, so a pathological loop cannot run forever.
    for ($pass = 0; $pass < 500; $pass++) {
        // Only a PUBLISHED peepso-group post carrying `_bcc_group_kind =
        // 'holders'` counts as a live community. A draft or trashed group is
        // not one, and neither is a hall or a delegator group.
        /** @var list<object{post_id: string, collection_id: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm_coll.post_id AS post_id, pm_coll.meta_value AS collection_id
               FROM {$wpdb->postmeta} pm_coll
               INNER JOIN {$wpdb->postmeta} pm_kind
                       ON pm_kind.post_id = pm_coll.post_id
                      AND pm_kind.meta_key = %s
                      AND pm_kind.meta_value = %s
               INNER JOIN {$wpdb->posts} p
                       ON p.ID = pm_coll.post_id
                      AND p.post_type = %s
                      AND p.post_status = %s
              WHERE pm_coll.meta_key = %s
                AND pm_coll.post_id > %d
              ORDER BY pm_coll.post_id ASC
              LIMIT %d",
            '_bcc_group_kind',
            'holders',
            'peepso-group',
            'publish',
            '_bcc_gate_collection_id',
            $afterPost,
            $batchSize
        ));

        // get_results() returns [] both for "no rows" and for a failed
        // query, so an empty batch alone is not proof of completion.
        if ($rows === null || $wpdb->last_error !== '') {
            \BCC\Core\Log\Logger::error(
                '[schema-collections-provisioning] backfill read failed; aborting without writing',
                ['table' => $table, 'db_error' => $wpdb->last_error, 'after_post' => $afterPost]
            );
            return false;
        }

        if ($rows === []) {
            break;
        }

        foreach ($rows as $row) {
            $afterPost    = max($afterPost, (int) $row->post_id);
            $collectionId = (int) $row->collection_id;
            if ($collectionId <= 0) {
                // A gate pointing at nothing is an orphan. It is surfaced by
                // the needs-attention tab, not repaired here.
                continue;
            }

            // Guarded UPDATE: only a row still sitting on the default is
            // touched, so a rerun cannot overwrite an operator's later state
            // and a concurrent run cannot double-apply.
            $written = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                    SET provisioning_state = %s
                  WHERE id = %d AND provisioning_state = %s",
                \BCC\Trust\Onchain\ValueObjects\ProvisioningState::PROVISIONED,
                $collectionId,
                \BCC\Trust\Onchain\ValueObjects\ProvisioningState::NONE
            ));

            if ($written === false) {
                \BCC\Core\Log\Logger::error(
                    '[schema-collections-provisioning] backfill UPDATE failed; aborting',
                    ['table' => $table, 'row_id' => $collectionId, 'db_error' => $wpdb->last_error]
                );
                return false;
            }

            $marked += (int) $written;
        }
    }

    // ── Postcondition, by SHAPE not by count ────────────────────────────
    // The invariant is a RELATIONSHIP: every collection marked `provisioned`
    // must have a live community. Asserting "28" would be asserting a
    // production census as a schema invariant, which stops being true the
    // first time anyone adds a community. The relationship stays true.
    $provisionedWithoutCommunity = bcc_onchain_probe_count(
        "SELECT COUNT(*) FROM {$table} c
          WHERE c.provisioning_state = %s
            AND NOT EXISTS (
                SELECT 1
                  FROM {$wpdb->postmeta} pm_coll
                  INNER JOIN {$wpdb->postmeta} pm_kind
                          ON pm_kind.post_id = pm_coll.post_id
                         AND pm_kind.meta_key = %s
                         AND pm_kind.meta_value = %s
                  INNER JOIN {$wpdb->posts} p
                          ON p.ID = pm_coll.post_id
                         AND p.post_type = %s
                         AND p.post_status = %s
                 WHERE pm_coll.meta_key = %s
                   AND pm_coll.meta_value = c.id
            )",
        [
            \BCC\Trust\Onchain\ValueObjects\ProvisioningState::PROVISIONED,
            '_bcc_group_kind',
            'holders',
            'peepso-group',
            'publish',
            '_bcc_gate_collection_id',
        ]
    );

    if ($provisionedWithoutCommunity === null) {
        \BCC\Core\Log\Logger::error(
            '[schema-collections-provisioning] could not verify the backfill postcondition; treating as INCOMPLETE',
            ['table' => $table]
        );
        return false;
    }

    if ($provisionedWithoutCommunity > 0) {
        // Not necessarily this migration's doing — a community trashed
        // between the walk and the check produces the same shape — but it is
        // a contradictory state either way, and saying so beats silence.
        \BCC\Core\Log\Logger::error(
            '[schema-collections-provisioning] rows marked provisioned without a live community',
            ['table' => $table, 'count' => $provisionedWithoutCommunity]
        );
        return false;
    }

    if ($marked > 0) {
        \BCC\Core\Log\Logger::info(
            '[schema-collections-provisioning] backfill complete',
            ['marked_provisioned' => $marked]
        );
    }

    return true;
}
