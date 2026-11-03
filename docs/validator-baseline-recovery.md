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
two tiers, because a ratio alone is meaningless on a handful of validators:

| the chain's baseline | a run is flagged partial when |
|---|---|
| **11 or more** | `returned * 10 < baseline * 7` — under **70%** of it |
| **10 or fewer** | `returned * 2 < baseline` — a loss of **more than 50%** |

⚠ **Check which tier your chain is in before reading anything below.** At a
baseline of 8, returning 7, 6, 5 or 4 is healthy and returning 3, 2 or 1 is
partial — so kujira losing 3 of 8 validators raises nothing, by design. The
ceiling is `SMALL_BASELINE_MAX` in `ChainRefreshService`. ⚠ At a baseline of 2
a drop to 1 is exactly 50% and is **never** flagged; a baseline of 1 cannot be
flagged by ratio at all.

Two absolute floors sit beside the ratio and are unaffected by the tiers: over
200 stored rows returning under 50, and a first-ever index returning under 30.

The sustained-drop case below is about the fetch coming back under the last
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
2. That chain's placeholder-page creation stops **on the very first partial
   run**, not later and not gradually. Both the mint allowlist and the
   `page creation is withheld` log line sit inside the `if (!$isPartialFetch)`
   branch, so a partial chain is never added to the mint set at all.

⚠⚠ **An earlier version of this document got point 2 wrong in both halves**,
and the correction matters because it changes what you go looking for. It said
minting continued until `last_good_at` aged past `BASELINE_MAX_AGE` (7 days)
and that the chain emitted one `info` line per run meanwhile. Neither is true:
there is **no week of grace**, and a partial chain emits **no withheld line**.

**The line a partial chain does emit** is a WARNING, once per run, and it is
the one to grep for:

⚠ It is **one line** in the log. Grep a short fragment rather than pasting the
whole sentence — an earlier version of this document wrapped it across two
lines, so copying the block as a pattern matched nothing:

```bash
grep 'Partial fetch detected for' ~/domains/<site>/bcc-logs/bcc-*.log | tail -20
```

The full line reads:

```
[Onchain] Partial fetch detected for <chain>: got N against baseline M (stored S). Dead-validator marking is skipped for the WHOLE RUN.
```

The 7-day `BASELINE_MAX_AGE` bound is real but applies to a different case: a
chain that is **clean** while carrying a baseline stamp older than a week. That
withholds minting for that one run and resolves itself, because the clean run
writes a fresh stamp. It is not the sustained-drop case this document is about.

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

⚠⚠ **The write must hold the cron's own lock.** `bcc_onchain_indexer_stats`
is a SINGLE serialized option holding every chain's entry, so the clear is a
read-modify-write over all of them. `bcc_index_validators` runs every 4 hours
and rewrites that same option per chain. Without a lock, a run landing between
your read and your write means either your clear is silently reverted, or —
worse — **your stale snapshot overwrites every other chain's entry**,
including a `last_good_total` the run had just advanced. The script below takes
`bcc_cron:index_validators`, the exact lock `index_validators()` takes, so the
two cannot interleave.

⚠ It uses **raw `GET_LOCK` through `$wpdb`**, not `AdvisoryLock`: under
`--skip-plugins` the plugin classes are not loaded, so `AdvisoryLock` does not
exist in that process. The key is passed to `GET_LOCK` verbatim by that class,
so the raw call and the plugin call contend for the same lock.

⚠ Accepted cost: the cron takes its lock with timeout 0, so if a run fires
while you hold it, **that run skips entirely** rather than waiting. One skipped
index tick is cheaper than a corrupted option.

```bash
# 2. Remove ONLY the two baseline keys, for ONE chain, under the cron's lock.
wp eval-file - --skip-plugins --skip-themes <<'PHP'
<?php
global $wpdb;
$slug = 'osmosis';                    // <-- the one chain
$lock = 'bcc_cron:index_validators';  // the SAME lock index_validators takes

if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 0)) !== 1) {
    echo "LOCK BUSY - an index run is in progress. Try again in a minute.\n";
    return;
}

try {
    // Read INSIDE the lock, so no run can have written since.
    $all = get_option('bcc_onchain_indexer_stats', []);
    if (!is_array($all) || !isset($all[$slug])) {
        echo "no entry for {$slug}\n";
        return;
    }

    $hadTotal = $all[$slug]['last_good_total'] ?? '(absent)';
    $hadAt    = $all[$slug]['last_good_at'] ?? '(absent)';
    $others   = 0;
    foreach ($all as $s => $e) {
        if ($s !== $slug && isset($e['last_good_total'])) { $others++; }
    }

    unset($all[$slug]['last_good_total'], $all[$slug]['last_good_at']);
    $ok = update_option('bcc_onchain_indexer_stats', $all, false);

    // Verify by reading wp_options DIRECTLY - no get_option, no cache.
    $raw   = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        'bcc_onchain_indexer_stats'
    ));
    $fresh = is_string($raw) ? maybe_unserialize($raw) : [];
    if (!is_array($fresh)) { $fresh = []; }

    $cleared = !isset($fresh[$slug]['last_good_total'])
            && !isset($fresh[$slug]['last_good_at']);
    $kept = 0;
    foreach ($fresh as $s => $e) {
        if ($s !== $slug && isset($e['last_good_total'])) { $kept++; }
    }

    printf("removed: total=%s at=%s\n", $hadTotal, $hadAt);
    printf("update_option returned: %s\n", var_export($ok, true));
    printf("%s\n", $cleared ? "VERIFIED cleared for {$slug}" : "FAILED - still set");
    printf("other chains holding a baseline: %d before, %d after\n", $others, $kept);
} finally {
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
}
PHP
```

⛔⛔ **Read `VERIFIED cleared`, not the return value.** An earlier version of
this document checked `isset($all[$slug]['last_good_total'])` — the LOCAL array,
two lines after `unset()` on it — so it printed `cleared` unconditionally, even
if the write had failed. The script above reads the row back, which is the only
check that can fail.

⚠ **It reads `wp_options` directly rather than calling `get_option()`.** A
`wp_cache_delete(..., 'options')` plus `get_option()` also works while this
option stays non-autoloaded — that is the group a non-autoloaded option is
cached in — but `get_option()` consults `wp_load_alloptions()` **first**, so the
correctness of the check would depend on the option's autoload state. A direct
`SELECT` cannot be served from any cache and needs no such reasoning.

⚠ `update_option` returning `false` is **not** proof of failure: it also
returns `false` when the stored value is unchanged. That is why it is printed
for information and the re-read is the verdict.

⚠ The before/after count of OTHER chains holding a baseline must be equal.
If it dropped, the write clobbered someone else's entry and the lock did not
do its job — restore from the values you recorded in step 1 and stop.

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

Every other chain must still show a number.

⚠⚠ **The chain you cleared may already show a number again, and that is
SUCCESS, not failure.** This step runs outside the lock, and `bcc_index_validators`
fires every 4 hours. Its next clean run writes that chain a fresh
`last_good_total` from its own return — which is the entire point of clearing.
So read this step as:

| the cleared chain shows | meaning |
|---|---|
| `ABSENT` | cleared, and no run has happened yet — the next one re-baselines it |
| a number **different** from the one you recorded in step 1 | a run has already re-baselined it. Done. |
| the **same** number you recorded in step 1 | the clear did not take — go back to step 2 |

⚠ Every OTHER chain must still show a number. If one reads `ABSENT` that you
did not clear, a write clobbered it — restore from step 1's record and stop.

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
- It does not help a chain whose fetch returns **empty**. ⚠ The reason is not
  that there is no baseline to clear — an earlier version of this document said
  that and it is wrong. The empty branch simply never touches the stats option,
  so a chain that already had a baseline keeps it sitting there untouched;
  clearing it changes nothing because the chain writes no new one either. An
  empty fetch is a provider or credential problem, and its log line is
  `[Onchain] Validator index returned empty for <chain>`.
