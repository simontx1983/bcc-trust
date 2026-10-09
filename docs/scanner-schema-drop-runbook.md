# Dropping the retired CosmWasm scanner schema (S9b)

**Status:** procedure only — **NOT run in any environment.** Execution requires
separate review and explicit operator authorization per environment. Merging the
PR that contains this document is itself a deployment trigger (§3), so merging and
executing are the same decision and must be authorized together.

**What makes this different from every other step of the scanner retirement:**
S1–S8 and S9a were pure code reverts. A mistake was recoverable with `git revert`.
This one **destroys three populated tables, eight columns and one index**. Revert
restores the code; it does not restore the rows.

---

## 1. Exact scope — what is destroyed

### 1.1 Three tables, dropped whole

| Table | staging | production |
|---|---|---|
| `wp_bcc_cosmwasm_code_families` | 742 rows / 528 KB | 102 rows / 128 KB |
| `wp_bcc_cosmwasm_contracts` | 3,762 rows / 2,832 KB | 36 rows / 96 KB |
| `wp_bcc_discovery_runs` | 10 rows / 112 KB | 0 rows / 112 KB |
| **total** | **4,514 rows / 3,472 KB** | **138 rows / 336 KB** |

⚠ `docs/database-schema.md` described these as *"Empty on every install"* until
2026-10-08. **They are not empty.** Measured 2026-10-09; corrected in umbrella
PR #167. The backup is load-bearing, not ceremonial.

### 1.2 Seven columns on `wp_bcc_chain_checkpoints` (8 rows, both environments)

| Column | non-empty rows (staging / prod) |
|---|---|
| `cw_discovery_state` | 8 / 8 |
| `cw_max_code_id` | 1 / 1 |
| `cw_backfill_completed_at` | 1 / 0 |
| `cw_last_discovery_at` | 1 / 1 |
| `cw_code_cursor` | 0 / 0 |
| `cw_metadata_refreshed_at` | 0 / 0 |
| `cw_last_error` | 0 / 0 |

### 1.3 One column on `wp_bcc_chains` (21 rows)

`cosmwasm_nft_discovery_enabled` — set to `1` on 1 staging chain, 0 production chains.

### 1.4 ⚠⚠ One INDEX, and it is the trap

```sql
KEY `idx_cw_discovery` (`cw_discovery_state`, `cw_last_discovery_at`)
```

It spans **two** of the retired columns. Two consequences, both discovered the
hard way during the 2026-10-09 restore rehearsal:

1. **The columns cannot be dropped while it exists.** The first restore attempt
   failed with `ERROR 1072 (42000): Key column 'cw_discovery_state' doesn't exist
   in table`. `bcc_trust_drop_scanner_schema()` therefore drops the index
   **explicitly and first**, before any column.
2. **The backup must capture it.** The original artefact captured the eight
   `ADD COLUMN` statements and not the index, so a restore would have come back
   silently missing `idx_cw_discovery`. `schema-drift-guard.php` compares index
   tuples and would eventually have caught it — but *after* a restore, not before.

The migration does not hardcode the index NAME. It derives the set from
`INFORMATION_SCHEMA.STATISTICS`, so an install carrying a differently-named index
over the same columns is handled too.

### 1.5 Leftover options — exactly one prefix

`bcc_cosmos_endpoint_authz_%` (staging 1, prod 0). Not autoloaded. Hygiene
only: the migration reports COMPLETE whether or not any were found.

⚠⚠ **Two other prefixes were in the first draft and both were wrong.** The
S9b inventory listed `bcc_trust_cw721_scan_%` (1 row on each environment) and
`bcc_trust_cw721_code_ids_%` (0 rows), and neither reading meant what it
looked like:

- `bcc_trust_cw721_scan_%` is **already owned** by the shipped migration
  `cleanup_cw721_scan_options_v1`
  (`includes/database/cleanup-cw721-scan-options.php`). Worse, that
  migration's own completion marker is `bcc_trust_cw721_scan_options_cleaned`,
  which **matches the prefix** — so the sweep would have deleted a sibling
  migration's done_option and made it run again. The "1 row on each
  environment" was that marker, not a leftover.
- `bcc_trust_cw721_code_ids_%` never matched anything. Those are
  **transients**, stored as `_transient_bcc_trust_cw721_code_ids_<id>` and
  `_transient_timeout_…`, so the predicate was broken and the measured "0
  rows" meant "this query is wrong", not "already clean". The existing
  migration handles all three spellings.

`ScannerSchemaDropIntegrationTest::testTheDropTargetsDoNotClaimAnotherMigrationsOptions()`
now asserts no drop prefix matches any done_option in the migration registry,
so this cannot come back.

---

## 2. What this must NOT touch

The two parent tables are **shared, live and heavily read**. The migration
re-verifies every one of these columns is still present before it will report
COMPLETE, and refuses otherwise.

- `wp_bcc_chain_checkpoints` — `chain_id`, `last_processed_block`, `head_block`,
  `state`, `cu_used_today`, `cu_budget_reset_at`, `last_run_at`, `last_error`,
  `block_progression_history`. This table is the shared "where is each
  chain-walking worker up to" primitive, read by the EVM indexer, the circuit
  breaker and the daily CU budget.
- `wp_bcc_chains` — `id`, `slug`, `name`, `chain_type`, `is_active`,
  `bcc_supports_nft_collections`, `manual_collection_discovery_enabled`, and every
  other column. The last two are the **retained** manual-capability columns and
  are not part of this retirement.
- Every index on `wp_bcc_chains`, and every index on `wp_bcc_chain_checkpoints`
  that does not name a retired column — `PRIMARY` included.
- Every row of both tables. The migration issues no `DELETE` and no `UPDATE`
  against either.
- `wp_bcc_chain_nft_capabilities`, `wp_bcc_onchain_collections`, and every other
  table.

---

## 3. ⚠⚠⚠ Automatic deployment and migration triggers

Read this section before merging anything. There is **no separate "run
migrations" step** — a files-only rsync is sufficient to fire everything below.

### 3.1 The schema stamp moves, so dbDelta runs

`BCC_TRUST_SCHEMA_VERSION` is a content hash over
`glob(includes/database/schema-*.php)` plus `DisputeRepository.php` and
`SignalRepository.php`, sorted by full path, `basename:md5\n`, first 10 characters.

| | stamp | inputs |
|---|---|---|
| now (S9a, `c1ecd1d9`) | `1a0bf150b1` | 53 `schema-*` files + 2 self-installers |
| after S9b | `db054e2c71` | 50 + 2 |

S9b deletes three `schema-*.php` files, so **the stamp moves and the schema pass
runs on the first request after deploy.**

⚠⚠ **Two things make this value fragile, and both have already caused a wrong
number.**

1. **It hashes file BYTES, so line endings matter.** This repo's working copies
   are CRLF and its blobs are LF, and the deploy rsyncs an LF checkout. A stamp
   computed over a CRLF working tree does not match the deployed one. Compute it
   over LF content.
2. **Any later edit to any `schema-*.php` file changes it.** `db054e2c71` is
   valid for the exact reviewed head and nothing else.

An earlier draft of this plan recorded `6e7f3a39e6`, which was wrong on both
counts. Recompute rather than trust a written value:

```sh
git -c core.autocrlf=false -c core.eol=lf archive --format=tar HEAD | tar -x -C /tmp/s9b
php -r '$d="/tmp/s9b";
  $f=glob("$d/includes/database/schema-*.php")?:[];
  foreach(["$d/app/Domain/Disputes/Repositories/DisputeRepository.php",
           "$d/app/Domain/Onchain/Repositories/SignalRepository.php"] as $s)
      if(is_file($s)) $f[]=$s;
  sort($f); $in="";
  foreach($f as $x) $in.=basename($x).":".md5_file($x)."\n";
  printf("%s (%d inputs)\n", substr(md5($in),0,10), count($f));'
```

Expected: `db054e2c71 (52 inputs)` — 50 `schema-*.php` files plus the two
self-installers. Run the same command against `c1ecd1d9` as a control; it must
print `1a0bf150b1 (55 inputs)`. **If the control does not reproduce, the method
is wrong and the post-change value means nothing** — that control is how the
`6e7f3a39e6` error was found.

A different post-change value means the tree is not what was reviewed.

Contrast with S9a, which changed no `schema-*.php` file: its stamp stayed
`1a0bf150b1` and its deploy fired no pass at all.

### 3.2 The drop runs through the migration runner, not dbDelta

`migration-runner.php` entry `drop_scanner_schema_v1`, `done_option`
`bcc_trust_scanner_schema_dropped`. Both run on the same first request, so the
migration must tolerate running either side of the schema pass — and does, because
every step re-probes rather than assuming.

### 3.3 The installer that would undo the drop is deleted in the same commit

`bcc_onchain_add_chains_cosmwasm_discovery_column()` re-added
`cosmwasm_nft_discovery_enabled` on **every** schema pass. Since the pass runs on
the same request as the drop, leaving it would put the column back before the
request ended. It is deleted in the same commit, and
`ScannerSchemaDropIntegrationTest::testNothingReAddsTheDroppedColumnOrTables()`
asserts both that the function is gone and that re-running the real installers
brings nothing back.

### 3.4 Where a merge deploys to

- **Merging to `main` auto-deploys STAGING.** There is no approval gate. Merging
  the S9b PR therefore executes the destructive migration on staging.
- **Production deploys only on `workflow_dispatch`** — but ⚠ the workflow gate
  compares `github.ref` to `refs/heads/main`, so it pins **the branch, not a SHA**.
  A production dispatch deploys `main`'s tip at that moment, whatever it is.
  Holding production back means not dispatching.
- A restore does **not** change the stamp. Restored tables are simply no longer
  managed: the `schema-*.php` files are gone, nothing re-creates or alters them,
  and they persist. `schema-drift-guard.php` treats a table marked `RETIRED` in
  the docs but present in the DB as an **ORPHAN, INFO-only**, so a restored table
  does not turn CI red. That is deliberate headroom for exactly this situation.

### 3.5 ⚠⚠ THE UMBRELLA DOCS CHANGE MUST MERGE FIRST

`scripts/schema-drift-guard.php` in the umbrella repo is a **blocking** CI
check, and the three tables are currently documented as `Status = Active` in
`docs/database-schema.md`. Its check 1a is *every documented-Active table must
be declared in code*, so the moment this PR removes the three `CREATE TABLE`
declarations, **umbrella CI goes red.**

And it cannot be avoided by timing, because the umbrella workflow checks the
plugin out with no `ref:`:

```yaml
- uses: actions/checkout@v4
  with:
    repository: simontx1983/bcc-trust
    path: app/public/wp-content/plugins/bcc-trust
```

`actions/checkout` defaults to the **default branch** for another repository,
so umbrella CI always compares its docs against bcc-trust `main` — not against
any PR head. Once this merges to `main`, every subsequent umbrella run is red
until the docs change lands.

**Docs-first is green at both points, and it is the only order that is:**

| order | check 1a (documented-Active ⊆ declared) | check 1b (declared ⊆ documented) |
|---|---|---|
| **docs RETIRED first**, then this PR | RETIRED is not Active, so not checked ✅ | the three are in the doc as RETIRED/ORPHAN ✅ |
| this PR first, then docs | three documented-Active tables undeclared ❌ | ✅ |

RETIRED is treated as ORPHAN by the guard — expected absent from code, and
INFO-only — so the window where the docs say RETIRED while `main` still
declares the tables is green.

So: flip the `Status` column of `wp_bcc_cosmwasm_code_families`,
`wp_bcc_cosmwasm_contracts` and `wp_bcc_discovery_runs` to `RETIRED` in an
umbrella PR, **merge that**, and only then merge this one.

---

## 4. ⚠ A fresh backup is required immediately before execution

**The 2026-10-09 rehearsal does NOT satisfy this.** It proved the restore
*procedure* works, against a snapshot of data taken that day in an isolated
database. It is not a backup of the rows this migration will destroy, and rows
change: `cw_discovery_state` is written by nothing now, but
`wp_bcc_cosmwasm_contracts` had 3,762 rows on staging from canary runs and the
counts above are a measurement, not a guarantee.

So, per environment, in this order:

1. Take the backup (§5).
2. **Verify it** by restoring into a throwaway database and comparing digests
   (§6 + §7). A backup that has not been restored is a file, not a backup.
3. Execute, in the same maintenance window, with no intervening writes.

If step 2 is skipped, stop. If more than one deploy has happened between the
backup and the execution, take a new backup.

### 4.1 ⚠ `wp db export` DOES NOT WORK ON THIS HOST

Measured 2026-10-09:

```
Error: Cannot do 'Process::run': The PHP functions `proc_open()` and/or
`proc_close()` are disabled.
```

wp-cli shells out to `mysqldump`, and the host's PHP policy forbids `proc_open`.
`mysqldump` *is* on `PATH` and could be driven directly, but only by handing
credentials to a command line. Do not do that. Everything in §5 runs through the
connection WordPress already holds.

---

## 5. Backup — four artefacts, per environment

All of it runs through `scripts/scanner-schema-backup.php`, inside:

```
wp eval-file <script> --skip-plugins --skip-themes --path=<abs site root>
```

`--skip-plugins --skip-themes` matters for more than speed: a plain `wp` boot
loads bcc-trust, which **fires the schema pass this procedure is measuring.**

### Artefact 1 — schema DDL, captured verbatim

`bcc_trust_backup_capture_ddl()` for the three tables, plus
`bcc_trust_backup_column_definitions()` and
`bcc_trust_backup_index_definitions()` against the two parents.

⚠ **Capture, never reconstruct.** Building `ADD COLUMN` from
`INFORMATION_SCHEMA.COLUMNS` was tried and produced two defects that are valid SQL
and silently wrong: a string default comes back **already quoted** in some
versions, giving `DEFAULT ''idle''`; and a nullable column's default comes back as
the literal string `"NULL"`, giving `DEFAULT 'NULL'` — a column that now defaults
to four characters. Only `SHOW CREATE TABLE` is byte-exact. Pinned by
`ScannerSchemaBackupExportIntegrationTest::testCapturedDefinitionsAreVerbatimRatherThanReconstructed()`.

⚠ The production column ORDER differs from the installer's:
`cosmwasm_nft_discovery_enabled` sits `AFTER description`, because an earlier
release added it with a different anchor. Order is cosmetic and nothing reads it,
but **restore from the captured DDL, not from the installer's `AFTER` clause.**

### Artefact 2 — row data as INSERT statements

`bcc_trust_backup_read_rows()` then `bcc_trust_backup_insert_statements()`, for the
three tables in full, plus `chain_id` + the seven `cw_*` values from
`wp_bcc_chain_checkpoints` and `id` + the flag from `wp_bcc_chains`.

Columns are **named**, not positional, precisely because of the column-order
divergence above. NULL is emitted unquoted; everything else is escaped through the
connection's own escaper. The encoding is covered against NULLs, empty strings,
quotes, backslashes, newlines, CR, NUL, Ctrl-Z, trailing spaces and the `0x1e` /
`0x1f` control bytes by
`ScannerSchemaBackupExportIntegrationTest::testEveryAdversarialValueRoundTripsThroughTheExport()`.

### Artefact 3 — the verification manifest

`COUNT(*)` and `bcc_trust_backup_table_digest()` per table, captured **before and
after**.

⚠ **`CHECKSUM TABLE` is NOT sufficient evidence and is deliberately not used.** It
is engine- and version-sensitive, and it cannot say *which* row or column differs.
The digest hashes every column of every row with a length prefix
(`OCTET_LENGTH(col) : col`, NULL as `~`) and folds the sorted per-row hashes in
PHP.

Why length-prefixed, and why the fold is in PHP — both of these are failure modes,
not preferences:

- A plain `CONCAT_WS(0x1f, …)` digest **cannot distinguish** values containing the
  separator: `('a<0x1f>b', 'c')` and `('a', 'b<0x1f>c')` both render as
  `a<0x1f>b<0x1f>c`. `testTheDigestDistinguishesRowsTheNaiveFormulaCollides()`
  proves that collision is real and that the length prefix fixes it.
- `GROUP_CONCAT` truncates at `group_concat_max_len` — **1024 bytes by default,
  silently.** A table big enough to matter is exactly a table whose SQL-side fold
  would be truncated, and the result would still look like a digest.

An unreadable table digests as `null`, never as the empty-table digest. Treat
`null` as UNVERIFIED and stop.

The last recorded production baseline (2026-10-09) used the earlier `CONCAT_WS`
formula, so its values are **not** comparable to the current one. That does not
matter: the pre-execution backup and its verification both use the current
formula, on both sides.

### Artefact 4 — a plugin-tree tarball at the pre-S9b SHA

`c1ecd1d9ef1cd642124130077f554b2639238ecf`. Restores code without touching data.
Only needed if the data has to be *used* again (§7 step 5), not to get it back.

---

## 6. Restore — the order is load-bearing

**The key property S9a bought:** the retained projections no longer name these
columns, so restored columns and tables are **inert** to the running post-S9b
code. It reads neither, so it neither breaks nor needs rolling back. That is what
makes recovery independent of code state — and it is why the earlier version of
this plan, which said *"restoring data requires restoring the pre-S9b code
first"*, was wrong. Recovery must not depend on a dbDelta pass doing the right
thing under pressure after a failed deploy.

1. **Three tables** — artefact 1's `CREATE TABLE` statements, verbatim.
2. **Eight columns** — artefact 1's `ALTER TABLE … ADD COLUMN` statements.
3. **The index** — artefact 1's `ALTER TABLE … ADD KEY idx_cw_discovery …`.
   ⚠ **AFTER step 2, never before.** Adding it first fails with ERROR 1072;
   `testTheIndexOverRetiredColumnsIsCapturedAndRestoresAfterItsColumns()` asserts
   both directions.
4. **Row data** — artefact 2, three tables first, then the `UPDATE`s that put the
   `cw_*` values and the chains flag back on the surviving parent rows.
5. **Only if the data must be USED again** — which nothing currently does —
   restore artefact 4. A separate, optional decision, not a precondition of
   getting the rows back.

No dbDelta, no reactivation, no `schema-*.php`, no code change at any step.

---

## 7. Verification

Run **all** of it. Each line is a separate claim.

1. `COUNT(*)` per table matches the pre-drop manifest.
2. `bcc_trust_backup_table_digest()` per table matches the pre-drop manifest
   **exactly**. A matching row count alone is not evidence.
3. The column count on both parents matches, and `idx_cw_discovery` is present
   with `cw_discovery_state, cw_last_discovery_at` **in that order**.

   ⚠ **Compare columns SEMANTICALLY, not as DDL text.** Check
   `COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_DEFAULT`, `CHARACTER_SET_NAME` and
   `COLLATION_NAME` from `INFORMATION_SCHEMA`, not the `SHOW CREATE TABLE`
   string. On **MySQL** a restored column's rendering changes in one benign
   way, and discovering that mid-restore would look like a failure:

   | | rendering |
   |---|---|
   | captured | `` `cw_discovery_state` varchar(20) COLLATE utf8mb4_unicode_ci … `` |
   | after replay | `` `cw_discovery_state` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci … `` |

   `SHOW CREATE TABLE` omits `CHARACTER SET` when a column matches the table
   default and prints only `COLLATE`; replaying that line as `ADD COLUMN`
   makes the collation an explicit column-level choice, so the redundant
   `CHARACTER SET` is then rendered too. Same type, charset, collation and
   default — the column is identical.

   **MariaDB 11.8, the production engine, renders both forms identically**, so
   a production restore really is byte-for-byte. The divergence is MySQL-only,
   and both directions are pinned by
   `ScannerSchemaBackupExportIntegrationTest::testCapturedDefinitionsAreVerbatimRatherThanReconstructed()`,
   which runs on both engines.
4. The running code is unaffected: read a chain through
   `ChainRepository::getById()` and a checkpoint through
   `ChainCheckpointRepository::get()`. Neither may surface a restored column and
   neither may return the error sentinel. (These are the same assertions
   `ChainsCapabilityColumnAnchorIntegrationTest` and
   `ChainCheckpointRetiredColumnsIntegrationTest` make in CI, run here against
   the real row.)
5. No chain reports `UNKNOWN` in the capability admin. That is the symptom of a
   `COLUMNS` constant naming a dropped column, and it is **cached**, so it
   survives a code revert until `wp cache flush`.

---

## 8. Pre-flight checks, immediately before execution

1. Re-run the row counts and digests; compare to the backup manifest (§5).
2. Confirm the Action Scheduler queue for `bcc_discovery_run_execute` is still
   all-`complete` and `wp_bcc_discovery_runs WHERE active_marker = 1` is 0 —
   **on production too**, which as of 2026-10-09 is still unmeasured. Staging was
   measured: 37 rows, all `complete`, against a denominator of 249 action rows of
   which 7 were pending for other hooks, so the zero is real and not an artifact.
3. Comment-stripped token sweep: no retained file names any dropped column.
4. Confirm the computed post-change stamp is exactly `db054e2c71` (§3.1).
5. ⚠⚠ **Manual column parity.** `schema-drift-guard.php` compares table names and
   index tuples only. It is **column-blind**, so the eight-column drop and its
   documentation are invisible to CI in both directions.
   `ScannerSchemaDropIntegrationTest` and
   `ChainCheckpointRetiredColumnsIntegrationTest` are the only automated checks
   that exist for the columns; this checklist is the only check for the docs.
6. A fresh, restored-and-verified backup exists for this environment, taken in
   this window (§4).

---

## 9. Failure modes and their specific responses

| Symptom | Response |
|---|---|
| The migration reported `INCOMPLETE` | By contract it retries on the next request, and it is idempotent, so a partially-dropped schema is a valid starting state. Read the pre-mutation inventory it logged to see how far it got. Do **not** hand-repair first. |
| Every chain reports `UNKNOWN` | A `COLUMNS` constant names a dropped column. Revert the code **and** `wp cache flush` — `ChainRepository` caches `ERROR_SENTINEL` in the `bcc_chains` group, so a code revert alone leaves the poisoned entry serving. |
| A dropped column came back on its own | A re-adding installer survived the drop commit (§3.3). Fix forward by deleting it. Do **not** re-drop first, or the next request re-adds it again. |
| The rows are wanted back | §6, steps 1–4. Independent of code state. |
| The restore failed with ERROR 1072 | The index was applied before its columns. §6 step 3. |
| S9a-era breakage, no drop yet | Revert the PR, redeploy, `wp cache flush`. No data involved; S9a moves no stamp. |

---

## 10. Related

- `includes/database/drop-scanner-schema.php` — the migration
- `scripts/scanner-schema-backup.php` — the backup and digest functions
- `tests/Integration/ScannerSchemaDropIntegrationTest.php` — populated cleanup,
  repeat execution, partial failure and recovery, retained projections,
  preservation
- `tests/Integration/ScannerSchemaBackupExportIntegrationTest.php` — value
  encoding and digest sensitivity
- `tests/Integration/ChainCheckpointRetiredColumnsIntegrationTest.php` and
  `ChainsCapabilityColumnAnchorIntegrationTest.php` — the S9a projection anchors
- `docs/database-schema.md` — the table inventory and the two-deploy sequence
- `docs/cosmwasm-discovery.md` — the retirement note
