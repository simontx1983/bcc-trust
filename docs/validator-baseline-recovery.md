# Validator baseline recovery

How to clear one chain's saved partial-fetch baseline when a validator set has
genuinely shrunk, and what the next two scheduled runs then do.

⛔ **There is deliberately no admin control for this.** The baseline is the only
thing standing between a truncated provider response and that truncation being
written back as the new reference, so clearing it is a decision that should cost
a deliberate CLI step and leave a shell history entry. This document is the
procedure, not a feature request.

## When this is needed

`ChainRefreshService` compares each fetch against `last_good_total` — the count
from the last fetch that passed detection — and flags the run partial when
`returned * 10 < baseline * 7`, i.e. the fetch came back under 70% of the last
clean one. Two different situations produce that flag:

| Situation | What to do |
|---|---|
| The provider truncated the response | **Nothing.** The flag is correct and it clears itself on the next healthy fetch. |
| The validator set genuinely shrank by more than 30% | The flag is now permanent, and this procedure is the remedy. |

⚠ **The second case does not resolve on its own, and that is by design.** The
baseline only advances on a clean run, so a chain whose real set dropped below
70% of its old size reports partial on every run, forever, and its baseline
never moves. Two consequences follow, and the second is the one that is easy to
miss:

1. `markDeadValidators()` is skipped for the **whole run**, not just that chain,
   because its `UPDATE` carries no `chain_id`.
2. Once `last_good_at` ages past `BASELINE_MAX_AGE` (7 days), that chain's
   placeholder-page creation is withheld too — silently, apart from one `info`
   line per run.

## ⚠ Confirm the drop is real first

Clearing the baseline makes **the next fetch the new reference**. If that fetch
is itself truncated, the truncated count becomes the baseline and the ratchet
this mechanism exists to prevent happens anyway — just with your help. So
confirm the decline is genuine before clearing, from a source that is not the
indexer:

- query the chain's own API or a public explorer for the current active set size;
- compare it against `returned` in the log line
  `[Onchain] Partial fetch detected for <chain>: got N against baseline M (stored S)`;
- check that several consecutive runs agree on roughly the same `N`. One low
  run is a truncation; five runs agreeing is a smaller set.

## The procedure

Read-only first. `bcc_onchain_indexer_stats` is a single serialized option
holding one entry per chain, so **never** `wp option delete` it — that discards
every chain's baseline and the whole admin dashboard history.

```bash
# 1. Look at the entry you intend to change, and nothing else.
wp eval-file - --skip-plugins --skip-themes <<'PHP'
<?php
$all = get_option('bcc_onchain_indexer_stats', []);
var_export($all['osmosis'] ?? 'NO ENTRY');
PHP
```

Expect `last_good_total` and `last_good_at` alongside `total`, `partial` and
`timestamp`. Record the two values you are about to remove, so the step is
reversible by hand.

```bash
# 2. Remove ONLY the two baseline keys, for ONE chain.
wp eval-file - --skip-plugins --skip-themes <<'PHP'
<?php
$slug = 'osmosis';                      // <-- the one chain
$all  = get_option('bcc_onchain_indexer_stats', []);
if (!isset($all[$slug])) { echo "no entry for {$slug}\n"; return; }

unset($all[$slug]['last_good_total'], $all[$slug]['last_good_at']);
update_option('bcc_onchain_indexer_stats', $all, false);

echo isset($all[$slug]['last_good_total']) ? "STILL SET\n" : "cleared {$slug}\n";
PHP
```

⚠ `--skip-plugins --skip-themes` is not optional. Booting the plugins fires the
schema pass and the migration runner against that database, which is a much
larger action than the one you intended.

⚠ Pass `false` as the third argument to `update_option`. The option is
non-autoloaded today; writing it back autoloaded would add it to every page
load.

```bash
# 3. Verify, read-only, that exactly one chain changed.
wp eval-file - --skip-plugins --skip-themes <<'PHP'
<?php
foreach (get_option('bcc_onchain_indexer_stats', []) as $slug => $e) {
    printf("%-18s last_good_total=%s\n", $slug,
        array_key_exists('last_good_total', $e) ? $e['last_good_total'] : 'ABSENT');
}
PHP
```

Every other chain must still show a number. Only the one you named should read
`ABSENT`.

## What the next two scheduled runs do

`bcc_index_validators` runs every 4 hours, so this plays out over roughly 8.

**Run 1 — detection resumes, nothing is minted for that chain.**

- The baseline is absent, so `baselineTotal()` returns 0 and the relative test
  is **not applied**. The chain is judged by the absolute floors alone, which it
  passes unless it returns under 50 validators while holding over 200 rows.
- Being clean, the chain writes a **new** `last_good_total` from this run's
  returned count, and a fresh `last_good_at`.
- ⚠ **No pages are created for that chain on this run.** Minting requires
  `$baseline > 0 && $baselineFresh`, both read from the entry as it stood
  *before* this run's write. A chain whose baseline you just cleared has
  neither, so it logs
  ```
  [Onchain] <chain> indexed and clean, but page creation is withheld:
  no verified baseline yet (got N, baseline absent). A later run will mint
  once a baseline is established.
  ```
  and mints nothing. **That line is the procedure working, not a failure.**
- Other chains are unaffected: each is judged against its own entry.

**Run 2 — the chain is fully back.**

- The baseline now exists and is minutes-to-hours old, well inside the 7-day
  freshness window, so `$mintBaselineVerified` is true.
- Detection compares this run against the new baseline, so a further genuine
  drop is caught again.
- Placeholder pages are created for validators **this run's fetch actually
  returned**, up to the run-wide `MINT_SCAN_BUDGET` of 500 scanned candidates.

**If the chain reports partial again on run 1**, the drop was not what you
thought: the floors caught it, or the fetch really is truncated. The baseline is
then not written, run 2 behaves exactly like run 1, and you are back to the
read-only investigation above. Nothing is damaged by that outcome.

## What this does not do

- It does not mark any validator inactive. Dead-validator marking is behind a
  separate switch (`bcc_onchain_dead_marking_enabled`, absent and therefore off)
  **and** the run-wide partial-fetch gate.
- It does not delete, insert or modify a single validator row, page or post.
- It does not help a chain whose fetch returns **empty**. An empty fetch writes
  no stats at all, so there is no baseline to clear — that is a provider or
  credential problem, and the log line is
  `[Onchain] Validator index returned empty for <chain>`.
