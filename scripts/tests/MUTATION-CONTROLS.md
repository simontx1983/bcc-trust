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
| `breaker-charge-mutation-controls.sh` | circuit-breaker charging per provider request | no | manual |
| `keyset-cursor-mutation-controls.sh` | the keyset cursor codec | no | manual |
| `no-reactions-mutation-controls.sh` | an announcement takes no engagement (§4.32.15) | no | manual |
| `probe-ownership-mutation-controls.sh` | half-open recovery-probe ownership (#264) | yes | manual |
| `scanner-removal-mutation-controls.sh` | ownership budgets, retired discovery executor | no | manual |
| `pr-e-fail-closed-mutation-controls.sh` | PR E provider fail-closed boundaries | yes | manual |
| `pr5b-`, `pr6-`, `pr7-`, `pr71-`, `pr714-`, `pr72-`, `pr721-`, `pr73-`, `pr74-`, `pr75-`, `pr76-`, `pr77-`, `pr78-`, `pr7a-`, `pr7a1-mutation-controls.sh` | the NFT indexing / provisioning PR series, one script per PR (see each header) | most | manual |

The two #267 controls were wired first, when that incident was fixed. The
other six were wired once they were confirmed green on main: no survivors, no
broken controls, and the tree restored afterwards.

**The manual scripts have not been re-verified against current main.** Code
they target may have moved or been retired, so a `broken` verdict from one
probably means the control is out of date, not that a guarantee was lost.
Fix or delete the control rather than ignoring it.

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
  worktree or a throwaway copy, never in the live plugin directory, and never
  while another suite runs in the same tree. Check `git status` afterwards.
- Scripts that need a DB take the same `BCC_TEST_DB_*` variables as the
  integration suite, and the bootstrap **drops and recreates** that database.
  Point them at a disposable server, never the Local site's database.
- Most scripts read `PHP` (some read `PHP_BIN`) for the PHP binary. On a
  machine whose `php.ini` lacks `mysqli`, set `PHPRC` to an ini that enables
  it: separate-process PHPUnit children ignore `-d` flags.
