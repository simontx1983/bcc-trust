# Retained-feature verification for S9b

**What this is for.** S9b removes the last of the CosmWasm scanner. Everything
listed here is a feature that **must keep working**, and each line says what
evidence exists for it today and what kind of evidence is still missing.

**Status of this document:** the automated column is **measured**. The
read-only staging column is **measured** where marked. The live-authorization
column is **not done** — nothing in it has been exercised, by design.

⚠ **Three columns, and they are not interchangeable.** An automated test
proves behaviour against a real engine in isolation. A read-only staging check
proves the deployed code and schema are what we think they are. Neither proves
an operator can complete a journey in a browser. Do not let a green tick in one
column be read as cover for another.

---

## A. Isolated automated evidence — runs in CI, no environment touched

| Feature | Evidence | Where |
|---|---|---|
| Chain projection reads with the retired column **present** | `testTheChainProjectionReadsWithTheRetiredScannerColumnPresent` | `ChainsCapabilityColumnAnchorIntegrationTest` |
| Chain projection reads with it **absent** | `testTheChainProjectionReadsWithTheRetiredScannerColumnAbsent` | same |
| Projection constant never names the retired column | `testTheProjectionConstantDoesNotNameTheRetiredColumn` | same |
| Capability columns come from the base `CREATE TABLE` on a fresh install | `testAFreshInstallHasTheTwoCapabilityColumnsAndNotTheRetiredOne` | same |
| Checkpoint projection reads with the seven columns **present** and **absent** | the paired cases, both add-then-drop so a real transition is exercised | `ChainCheckpointRetiredColumnsIntegrationTest` |
| Retained checkpoint **writers** still write after the drop | `recordSuccess`, `addCuUsage`, `setState` exercised post-drop | `ScannerSchemaDropIntegrationTest` |
| Block-progression history survives the drop | `testTheProgressionHistorySurvivesTheDrop` | `ChainCheckpointRetiredColumnsIntegrationTest` |
| Populated cleanup, repeat execution, 4-way partial resume, real injected DB fault → `INCOMPLETE` → recovery, unreadable probe → nothing dropped, unreadable postcondition → refuses completion | 14 cases, `integration` + `mariadb` groups so both engines run them | `ScannerSchemaDropIntegrationTest` |
| Unrelated columns, indexes, rows and values preserved | `testUnrelatedColumnsIndexesAndRowsArePreserved` | same |
| Nothing re-adds the dropped structures on a schema pass | `testNothingReAddsTheDroppedColumnOrTables` | same |
| Option sweep touches only its own prefix, and no sibling `done_option` | 2 cases incl. a registry-wide assertion | same |
| Backup export round-trips NULLs, empty strings, `0x1e`/`0x1f`, quotes, backslashes, newline, CR, NUL, Ctrl-Z, trailing space, framing-imitating values and **Unicode** incl. a 4-byte emoji | 31-row adversarial corpus, seeded via `UNHEX()` so the fixture does not use the encoder under test | `ScannerSchemaBackupExportIntegrationTest` |
| The digest **distinguishes** all of those, and the naive `CONCAT_WS` form provably collides | planted positive against a kept `..._naive()` variant | same |
| Restored columns land in their **original positions** | `testRestoredColumnsLandInTheirOriginalPositions` + the contiguous-run case | same |
| Captured DDL is verbatim, not reconstructed | `testCapturedDefinitionsAreVerbatimRatherThanReconstructed` | same |
| Index over retired columns is captured and restores **after** its columns; the reverse order fails | `testTheIndexOverRetiredColumnsIsCapturedAndRestoresAfterItsColumns` | same |
| No Solana alias row via **any** collection write path | pre-existing suites, unchanged by S9b | see `docs/s9b-deferred-cleanup-review.md` §3 |

⚠ **Not covered automatically, and cannot be:** anything requiring a browser
session, a provider response, or an operator's judgement about what a screen
says. Those are sections B and C.

---

## B. Read-only staging checks — no mutation, no cron, no provider calls

Each of these is a `wp eval-file … --skip-plugins --skip-themes` read or an
unauthenticated HTTP read. ⚠ `--skip-plugins --skip-themes` is not a speed
flag: a plain `wp` boot loads bcc-trust and **fires the schema pass** these
checks are measuring.

| # | Check | Status |
|---|---|---|
| B1 | `bcc_trust_schema_version` equals the expected stamp | ✅ measured 2026-10-09 — `1a0bf150b1` pre-S9b |
| B2 | The three tables, eight columns and `idx_cw_discovery` are present pre-S9b | ✅ measured — 742 / 3762 / 10 rows, 7 `cw_*`, flag present, index present |
| B3 | `bcc_trust_scanner_schema_dropped` absent pre-S9b | ✅ measured — ABSENT |
| B4 | Sibling migration markers intact | ✅ measured — `bcc_trust_cw721_scan_options_cleaned`, `…chunks_used_added`, `…maintenance_unscheduled` all present |
| B5 | Executor queue quiet | ✅ measured — `bcc_discovery_run_execute` 37 rows all `complete`, 0 pending, denominator 249; `active_marker = 1` → 0 |
| B6 | Chain projection returns every chain, none `UNKNOWN` | ⬜ **after deploy** — the symptom of a projection naming a dropped column, and it is cached |
| B7 | Checkpoint read + the three retained writers against a real row | ⬜ **after deploy** |
| B8 | Content-and-path manifest matches the merge tree | ⬜ **after deploy** |
| B9 | Stamp advanced to `db054e2c71` | ⬜ **after deploy** |
| B10 | Application log by level, against a pre-deploy baseline | ⬜ **after deploy** — ⚠ baseline the log *before* deploying or new-vs-pre-existing is unprovable |
| B11 | `bcc_discovery_run_maintenance` still 0; executor still bound | ⬜ **after deploy** |
| B12 | Postconditions of §13.1 | ⬜ **after deploy** |

---

## C. Requires explicit live authorization — NOT done, NOT attempted

⚠ Everything here mutates state, calls a provider, or needs an admin session.
**None of it has been performed.** No credentials were obtained, no admin
action invoked, no endpoint switched and no provider probed.

| # | Feature | What verifying it would involve | Why it needs authorization |
|---|---|---|---|
| C1 | **Manual Add Collection — Cosmos** | Submit a chain + contract in wp-admin | Runs the bounded CW-721 `contract_info` probe: **up to two live LCD queries**, and may write a collection row |
| C2 | **Manual Add Collection — EVM / Solana** | Submit a contract | Writes a collection row. No provider validation on these families — accepted as entered |
| C3 | **Targeted validation** | Validate a submitted contract | **Live provider call** |
| C4 | **Metadata retrieval** | Open an NFT piece / run enrichment | **Live provider call** (EVM + Solana fetchers are retained and unfrozen) |
| C5 | **Ownership / holdings** | Refresh a wallet's holdings | **Live provider calls**, and writes holdings rows |
| C6 | **Holder-gated group join** | Attempt a gated join | Evaluates a gate against live holdings; writes membership |
| C7 | **Collection removal protection** | Attempt to remove a collection in use | Destructive if the protection does not hold |
| C8 | **Hide / Unhide** ⚠ | Toggle a collection's stance | Writes an audit row. ⚠ **Check the actual semantics, not the name:** since S3 this toggles the operator-facing *stance* only — the scanner deny-flag sync (`syncScannerDenyFlag`) was deleted and the audit vocabulary collapsed to two actions. It no longer writes `wp_bcc_cosmwasm_contracts.denied`, and after S9b that table does not exist. Anyone expecting a deny-flag write will read its absence as a regression; it is not |
| C9 | **Capability editing** | Grant/withdraw product support or manual discovery | Admin mutation + nonce; writes `wp_bcc_chains` flags and override rows |
| C10 | **Cosmos endpoint switch** ⚠ | Move a chain's `rest_url` | **Live uncached identity verification before the write**, a breaker `forget()`, and an audit row. ⚠ Also note the operator surface: the plan's §1.5 found there is **no render side** — no form, no plan panel, no result notice — so a switch currently requires a hand-built POST. Verifying C10 means verifying that gap too |

---

## D. Outstanding visual checks — recorded, not done

No browser session was opened. These remain outstanding from the S8 definition
of done and are **still outstanding**:

1. **Capability editor** (`NftDiscoveryPage`) renders, the matrix and family
   tabs populate, and a capability edit round-trips — with **zero PHP notices**.
2. **Manual Add Collection** form renders and refuses a bad contract with a
   readable message.
3. **Verify Collections** page renders, and Hide/Unhide shows the two-action
   audit vocabulary (per C8, not a deny-flag).
4. **Chains ▸ Identity** sub-tab renders; the legacy-URL forward still lands.
5. **No chain shows `UNKNOWN`** anywhere in the capability admin. This is the
   cached-sentinel symptom and a screenshot is the fastest way to see it.
6. Admin screens that referenced the scanner show no empty panels or dead
   controls left behind by S4's surface withdrawal.

⚠ Item 5 is the one that matters most after S9b, and it is the one an
automated test cannot see the way a person can: the projection failing is
silent, cached, and looks like "no data" rather than an error.
