# Mutation controls

A passing test only shows the code does what the test checks. It does not show
the test would fail if the code were wrong. Each `*-mutation-controls.sh`
script checks that second part. It plants one defect in production code at a
time, runs the test named for that defect, and restores the file. Each
planted defect ends in one of three verdicts:

- **killed**: the named test failed. The guarantee is protected.
- **survived**: everything stayed green. The test does not constrain the code.
- **broken**: the defect could not be planted, usually because the code it
  targets has moved. The control itself needs fixing.

A script exits non-zero on any `survived` or `broken` result.

## Which ones run in CI

Only the **security, authorization and privacy** controls run in CI (F-96).
A test that quietly stops guarding these must turn the build red. All the
others are **manual**: run them when you change the code they cover.

| Script | Covers | Needs DB | CI |
|---|---|---|---|
| `endpoint-redaction-mutation-controls.sh` | credential redaction is deny-by-default (#267) | no | `php` job |
| `alchemy-credential-mutation-controls.sh` | one secure Alchemy credential source (#267) | no | `php` job |
| `operator-authority-mutation-controls.sh` | the authoritative validator-page operator check (§4.32.2) | no | `php` job |
| `announcement-capability-mutation-controls.sh` | who may do what with announcements (§4.32.11) | no | `php` job |
| `announcement-endpoint-mutation-controls.sh` | announcement read routes and the feature switch | no | `php` job |
| `announcement-read-mutation-controls.sh` | announcement visibility predicates (§4.32.1) | no | `php` job |
| `announcement-reporting-mutation-controls.sh` | reporting does not disclose drafts or author ids | no | `php` job |
| `operator-resolver-meta-leg-mutation-controls.sh` | the verified-operator resolver's post_meta leg | yes | `mutation-controls-db` job |

## Manual scripts: status

Last verified **2026-11-12** against `main` at `5b0897d4`, one script at a
time, in a git worktree, against a disposable MySQL 8. "Killed" counts are
for that run.

| Script | Covers | Needs DB | Status |
|---|---|---|---|
| `breaker-charge-mutation-controls.sh` | circuit-breaker charging per provider request | no | ✅ 12/12 killed (M4, M5 re-anchored) |
| `keyset-cursor-mutation-controls.sh` | the keyset cursor codec | no | ✅ 10/10 killed |
| `no-reactions-mutation-controls.sh` | an announcement takes no engagement (§4.32.15) | no | ✅ 8/8 killed |
| `probe-ownership-mutation-controls.sh` | half-open recovery-probe ownership (#264) | yes | ✅ 9/9 killed (M1d removed with its target, M4 re-anchored) |
| `scanner-removal-mutation-controls.sh` | ownership budgets, retired discovery executor | no | ✅ 3/3 killed |
| `pr-e-fail-closed-mutation-controls.sh` | PR E provider fail-closed boundaries | yes | ✅ 29/29 killed |
| `pr5b-mutation-controls.sh` | PR 5b provisioning fixes | yes | ✅ 6/6 killed |
| `pr6-mutation-controls.sh` | PR 6 provisioning and intake | yes | ✅ 17/17 killed (compensation re-anchored after F-160) |
| `pr7-mutation-controls.sh` | collection metadata rules, research URLs, description review | yes | ✅ 19/19 killed (3 scan-action controls removed with their target, 1 re-anchored) |
| `pr71-mutation-controls.sh` | the retired enrichment cron stays retired | no | ✅ 5/5 killed (15 discovery-readiness controls removed with their targets) |
| `pr714-mutation-controls.sh` | NFT holder-community membership revocation fails safe | yes | ✅ 39/39 killed |
| `pr78-mutation-controls.sh` | centralized breaker failure attribution | no | ✅ 23/23 killed (5 re-anchored; see the CRLF note below) |
| `pr76-mutation-controls.sh` | CosmWasm classification and the breaker lifecycle | no | ✅ 12/12 killed (smart-query opt-in re-pinned, below) |

### pr76 smart-query opt-in: re-pinned (was the open survivor)

`CosmosFetcher::lcdGetResult()` is called with `$smartQuery = true` by
`wasmSmartQueryResult()`, which the CW721 probes use. A recognised
unknown-variant 500 is then an *answer*, not a provider failure, and must not
charge the breaker. The tests that pinned this (`SmartQueryOptInWiringTest`,
`CosmwasmSmartQueryRetryTest`) were deleted in S8. Control 4 now runs:

- **`tests/Unit/Cw721ProbeSmartQueryOptInTest`**, which covers `probeCw721()`.
  Refusals cost one request each and no charge, and repeated probes never open
  the breaker. A node fault still retries and charges.
- **`CollectionMetadataApplicationErrorTest`**, which covers
  `cw721CollectionInfoQuery()`. It already went red under the mutation but was
  missing from the filter.

Disabling the opt-in (`if (false)`) turns both red.

### Retired 2026-11-12

These scripts were **deleted**. Everything they mutated was removed in the
scanner retirement (S1-S8), and so was every test they named. There is no
guarantee left for them to check. `scanner-removal-mutation-controls.sh` and
`ScannerRemovedInventoryTest` guard that the retired surface stays gone.

`pr72-` (scan completeness), `pr721-` (scan panel liveness), `pr73-`
(operator scan sessions), `pr74-` (session totals), `pr75-` (classification
wording and pacing), `pr77-` (contract-list enumeration telemetry), `pr7a-`
(discovery-run ledger), `pr7a1-` (discovery-run maintenance cron).

### ⚠ The CRLF false-survivor trap

`pr78` and `pr76` used to read the target file in Python text mode, which
turns CRLF into LF. Most of `app/Domain/Onchain/` is CRLF, so a mutation
whose anchor matched **nothing** still changed the file's bytes. That passed
the "did the mutation apply?" md5 check, and an **unmutated** tree was
reported as SURVIVED. All five `pr78` survivors on 2026-11-12 were this:
stale anchors disguised as survivors. Both scripts now preserve line endings.
`pr76` also writes **nothing** when the mutation left the text unchanged, so a
no-match is BROKEN even on a file with mixed line endings.
A new script must read with `newline=''` (or use PHP's `file_get_contents`,
as `breaker-charge` does) and refuse an anchor that does not match exactly
once.

## Promoting a manual script into CI

Only do this once it is green on main. Unit-only scripts go in the `php` job
in `.github/workflows/ci.yml`. A script that runs `phpunit-integration.xml.dist`
needs a MySQL service, so it goes in `mutation-controls-db`. Promote one only
when a silent regression in what it guards would be a security or privacy
defect. Otherwise it costs CI time without buying much.

## Running one locally

```bash
cd app/public/wp-content/plugins/bcc-trust
bash scripts/tests/operator-authority-mutation-controls.sh
```

- The scripts **edit production files in place** and restore them after
  each control. Most do not restore if you kill them mid-run. Run them in a
  **git worktree**, never in the live plugin directory, and never while
  another suite runs in the same tree. Check `git status` afterwards.
- `pr5b` and `pr6` restore with `git checkout --`. In a copy that is not a git
  checkout the restore silently fails and the next control runs on a mutated
  tree. Run them only in a worktree.
- Scripts that need a DB take the same `BCC_TEST_DB_*` variables as the
  integration suite, and the bootstrap **drops and recreates** that database.
  Point them at a disposable server, never the Local site's database.
- Most scripts read `PHP` (some read `PHP_BIN`) for the PHP binary. On a
  machine whose `php.ini` lacks `mysqli`, set `PHPRC` to an ini that enables
  it: separate-process PHPUnit children ignore `-d` flags.
