# Vote-ring snapshot persistence — proposal, now measured

**Status: decision document. No storage behaviour changes in this document or
the commit that carries it.** The implementation it recommends is
**bcc-trust #309**, which is open and unmerged.

The honest-display half already shipped in #301: the Vote Rings tab no longer
prints **"✓ No vote rings detected"** when it has no snapshot. This document is
about the storage underneath it.

> **Revision note.** The first version of this document reasoned from reading
> the code. It was wrong in four places that measurement later contradicted,
> and each is marked ⚠⚠ **CORRECTED** below rather than quietly rewritten. The
> four are: what the `FraudDetector` fallback actually does, the producer's
> cadence, the TTL that follows from it, and which half of the algorithm is
> unbounded. One further finding — a payload/reader shape mismatch — was not in
> the first version at all and is the reason storage cannot land on its own.

---

## 1. What exists today, measured

### The writer — one, in cron

`CronService::dailyGraphUpdate()`, under `bcc_cron_graph_lock`:

```php
$rings = $graph->getSuspiciousClusters($ringMinSize);
// Shared cache key with AdminDashboardRepository::getRingsData()
wp_cache_set('ring_results', $rings, 'bcc_trust_admin', DAY_IN_SECONDS);
```

`wp_cache_set` **and nothing else**. No transient, no option, no table.

⚠⚠ **CORRECTED — the cadence is DAILY, not hourly.** The first version said
ring detection runs hourly, citing `bcc_trust_hourly_ring_detection`. That hook
is a **retired alias**: `CronService` lists it as *"merged into
daily_graph_update"*. The live producer is `bcc_trust_daily_graph_update`,
declared `'interval' => 'daily'` in `includes/cron-hooks.php` and wired at
`bcc-trust.php:669`. This matters because a TTL is only worth anything if it
outlives the producer's interval — see §4.2.

The repository's own docblock was the source of the error. It said results are
*"computed hourly by `bcc_trust_run_ring_detection_cron()` and stored as a
transient"*. **All three parts are false**: the cadence is daily, that function
exists nowhere in the codebase, and nothing stored a transient at all. #309
corrects it.

### The readers — two, with opposite failure behaviour

| Reader | On a cold key | Consequence |
|---|---|---|
| `AdminDashboardRepository::getRingsData()` | `[]` | the tab showed "✓ No vote rings detected" — **fixed in #301**, now says "No ring-detection snapshot yet" |
| `FraudDetector` | **recomputes inline** via `$trustGraph->detectVoteRings()` | see the correction below |

### ⚠⚠ CORRECTED — "fraud signalling is inert" was wrong, and so was the first correction

**What I originally wrote (#301's description):** that ring-based fraud
signalling was *"equally inert"* without a persistent object cache.

**That was wrong.** The signal still fires. `FraudDetector` recomputes rather
than reading a zero, so `in_vote_ring` and its +40 points are applied normally.

**The first correction to it was also incomplete.** It said *"the exposure is
cost on the request path, not a missing signal"*. Cost is not the whole of it:
**`detectVoteRings()` is not a read. It WRITES.** Taken from the method body
and confirmed by running it:

| Write | Guard |
|---|---|
| a pattern row per detected ring (`storeVoteRingPattern`) | none — every pass |
| `incrementFraudScore(+15, 'vote_ring_member')` per member | a 30-day per-ring transient |
| `wp_cache_set('vote_ring_offset', …)` — the pagination cursor | lock holder only |
| `wp_cache_set('vote_rings', …)` | none |

`detectEndorsementRings()` is the same shape: `incrementFraudScore(+20,
'endorsement_ring')` per member behind a 30-day transient, plus its own cache
write. Measured directly — a saturated vote fixture reported **84 pattern rows
written by one pass**.

So on a host with no object cache, a page request that triggers a fraud
analysis can **write pattern rows and increment other users' fraud scores**,
not merely spend time. The scoring outcome is still correct — the 30-day
transient keeps the penalty once-per-ring — but the work happens on a request
instead of in cron, which is a different risk from a slow page.

⚠ **A second, separate consequence of the same missing cache.** The mutual-pair
query is paginated with `LIMIT 500 OFFSET n`, and the offset lives in
`wp_cache_get('vote_ring_offset')`. With no persistent cache the offset is
**always 0**, so every pass re-scans the same first page and never advances.
Persisting the ring snapshot does **not** fix that — the cursor is a separate
store — so it is recorded here as its own finding rather than folded into this
proposal.

### Also wrong in the code's own words

`getRingsData()`'s docblock said results are *"stored as a transient"*. They
were not, and never had been. That sentence is why the gap survived review.

---

## 2. The behaviour that follows, with and without a persistent object cache

`wp_cache_*` is per-request unless an `object-cache.php` drop-in is installed.

**Measured on staging, 2026-10-11:** `wp-content/object-cache.php` is
**absent**, so `wp_using_ext_object_cache()` is false there.

| | with a persistent object cache | without one (staging today) |
|---|---|---|
| cron writes the snapshot | survives up to `DAY_IN_SECONDS` | **dies with the cron request** |
| admin tab | shows the snapshot | shows "No ring-detection snapshot yet" (post-#301) |
| `FraudDetector` | reads the snapshot | **recomputes the graph, and writes, every fraud analysis** |
| pagination cursor | advances | **stuck at offset 0 forever** |
| cost profile | one graph pass per day | one graph pass per fraud analysis |

Two consequences worth stating plainly:

1. **The tab cannot show a ring on staging as configured.** Not "rarely" —
   never. The writer and the reader are in different requests.
2. **The daily job's entire purpose is defeated.** It computes the expensive
   thing once a day and then throws it away.

⚠ The same is true of other `wp_cache_set` users on such a host — the Ecosystem
rollup's five-minute cache (#305) never takes effect either. That is a
host-configuration fact about this deployment, not a defect in each caller, and
it is the reason this proposal prefers a storage change over a per-caller one.

---

## 3. Measurements — the open question, answered

All in an isolated **MariaDB 11.8.9** container (production's engine) against
the real `TrustGraph`, with fixtures built to the detectors' own caps: **500
mutual vote pairs** and **10,000 vouch edges**.

### Graph cost and payload size

| input shape | clusters | `serialize()` | graph pass |
|---|---|---|---|
| staging today (0 votes, 0 vouches) | 0 | 6 B | 0.001 s |
| 12 vote pairs + 30 vouch edges | 14 | 2.1 KiB | 0.060 s |
| vote query saturated (500-pair cap) | 84 | 11.7 KiB | 1.032 s |
| 9,999 vouch edges, disjoint 3-cycles | 3,333 | 521.7 KiB | 11.000 s |
| **9,900 vouch edges, 100-node clique** | **4,851** | **2,572.6 KiB** | **91.925 s** |

The last row is the worst case **inside the algorithm's own limits**, and it is
the number that justifies the change: 91.9 seconds of graph work against a
12.6 ms read.

⚠⚠ **CORRECTED — which half is unbounded, and why.**

- **The vote half is hard-bounded.** 500 pairs is at most ~1,000 nodes, so
  every cluster together holds ~1,000 users. The worst payload shape there is
  *many small clusters*, not one big one — 84 clusters at 11.7 KiB against 1
  cluster at 3.2 KiB — because the per-cluster keys dominate. The first version
  pointed at "cluster count" in general; the vote half is not the problem.

- **The vouch half is the unbounded one, but NOT by cycle enumeration.** The
  first version warned that `dfsCycleDetect` could enumerate exponentially many
  cycles. Cliques do not explode, because dedup is by sorted member **set**: a
  k-node clique yields C(k−1,2) clusters, measured 10 / 21 / 36 / 171 / 406 /
  1,176 / 4,851 at k = 6, 8, 10, 20, 30, 50, 100. What grows is
  *users-across-all-clusters* — **171,402** at k=100 — and that is what makes
  the payload 2.5 MiB.

### Can a transient hold it?

| payload | write | read | `autoload` | round-trip |
|---|---|---|---|---|
| 6 B | 2.2 ms | 0.1 ms | off | identical |
| 12,681 B | 1.9 ms | 0.1 ms | off | identical |
| 508,848 B | 16.7 ms | 2.4 ms | off | identical |
| **2,890,095 B** | **81.8 ms** | **12.6 ms** | **off** | identical |

⚠ **`autoload = off` is the decisive fact**, and it is precisely the open
question the first version flagged as *"the one fact that could change the
recommendation away from transients"*. An autoloaded 2.9 MiB option would be
read on **every request on the site**; a non-autoloaded one is read when
someone asks for it. Supporting limits: `option_value` is `longtext`,
`max_allowed_packet` is 16 MiB (5.7× headroom), and the site's entire
autoloaded set is 44.8 KiB across 139 rows — this does not join it.

**Verdict: a transient is sufficient.** No table — therefore no schema, no
migration, no drift-guard row, and no retention policy for something a
non-autoloaded transient holds for 12 ms.

### Representative, today

Staging holds **0 votes, 0 page scores, 0 attestations, 0 patterns** and 24
`user_info` rows. There is nothing for ring detection to find, so the storage
change has **no measurable payload effect on current data**. Everything above
is about capacity as the platform fills, which is why the high-volume cases are
the load-bearing ones.

---

## 4. ⚠⚠ NEW FINDING — storage cannot land on its own

`getSuspiciousClusters()` is `array_merge(detectVoteRings(),
detectEndorsementRings())`, and the two detectors emit **different keys**:

| detector | keys |
|---|---|
| `detectVoteRings()` | `users`, `size`, `strength`, `detected_at` |
| `detectEndorsementRings()` | `users`, `size`, `type`, `detected_at` |

Both consumers were written against one of them. Measured by caching one ring
of each shape and running the real loops:

1. **`FraudDetector` reads `$ring['strength']` unguarded**, while its own
   fallback calls `detectVoteRings()` **only** — so the fallback is shape-safe
   and the cached path is not. A user in an endorsement ring produced:

   ```
   Undefined array key "strength"
   "User is part of a vote ring with 3 members (strength: 0.00)"
   ```

   A PHP 8 warning, the wrong kind of ring named, and a fabricated `0.00`
   strength from a detector that computes none.

2. **`tab-rings.php` reads `$ring['members']`** and then
   `array_column(…, 'user_id')` over it — a different key *and* a different
   shape (a list of rows, where the producer emits a flat list of ids). Every
   ring rendered **"— 0 members"** with an empty id list.

   ⚠ That same empty list feeds the hidden `user_ids` field of the **two
   destructive forms** on that tab — invalidate-ring-votes and suspend-all —
   under a confirm dialog reading *"Suspend ALL 0 members"*. Persisting the
   snapshot without this fix would arm both buttons with no targets.

Both are dormant today **only because nothing populates the key**, which is
exactly what a storage change alters. So the normalisation is not a follow-up:
it is a precondition, and #309 ships the two together.

---

## 5. The design #309 implements

### 5.1 Storage

One writer helper and one reader helper, owned by
`app/Domain/Core/Support/RingSnapshot.php` beside `PushMetrics`:

```
RingSnapshot::store(array $rings): void
RingSnapshot::read(): array{available: bool, rings: list<array>, computed_at: int|null, age_seconds: int|null}
```

- **write** — object cache when `wp_using_ext_object_cache()`, and **always** a
  transient as the durable copy. Not a fallback-on-failure: a snapshot that
  survives only while the backend is healthy is the bug being removed, and the
  write happens once a day.
- **read** — object cache first, then the transient. The order is load-bearing:
  with a persistent cache the live snapshot is there and the transient may be an
  older copy.
- both carry `computed_at`, so freshness is a property of the payload rather
  than of the store.
- a **bare list is refused** as a payload. The old key held one, and a cache
  left warm across a deploy would otherwise be dereferenced for a `computed_at`
  that is not there. "Not available" until the next daily run is the honest and
  the safe answer.

### 5.2 Freshness and expiry

⚠⚠ **CORRECTED — the TTL stays at `DAY_IN_SECONDS`.** The first version
proposed **3 hours**, arguing it "survives two missed runs". That reasoning
assumed an **hourly** producer. Against the real **daily** producer, a 3-hour
TTL would leave the tab saying "no snapshot" for **21 of every 24 hours** — it
would have made the feature worse on exactly the hosts where it currently
works. The instruction to preserve the TTL was correct, and #309 pins it with a
test.

`read()` reports `computed_at` and `age_seconds`; the tab shows the snapshot's
age. **No staleness verdict is invented** — a shorter TTL or a stale threshold
is a separate decision with its own justification.

### 5.3 Failure states

| state | `read()` | admin tab | `FraudDetector` |
|---|---|---|---|
| snapshot present | `available: true` + age | rings, with their age | reads it |
| no snapshot | `available: false` | "No ring-detection snapshot yet" (shipped in #301) | **recompute, as it does today** |
| legacy bare value | `available: false` | same as no snapshot | recompute |
| store unreadable | `available: false` | same as no snapshot | recompute |

**Nothing presents missing data as "no rings".** That is the one invariant this
will not trade away, and #301 already enforces it in the view.

⚠ `FraudDetector`'s recompute **stays**. It is correctness-preserving: an
analysis that silently skipped the ring signal would under-score a user who is
in a ring, and that is worse than the cost. What changes is how often it
fires — from "every analysis on a cacheless host" to "only when the producer has
genuinely not run".

### 5.4 What is explicitly NOT proposed

- **No database table** (§3 measured a transient as sufficient).
- **No change to the detection algorithm**, its thresholds, or the +40/+15/+20
  weightings.
- **No change to the Hide policy, any public surface, or anything
  user-facing.** Ring membership is operator-only today and stays so.
- **No new cron hook.** The producer already exists.
- **No object cache installed.** The point is that the feature works without
  one.
- **No fix to the stuck pagination cursor** (§1). Same root cause, different
  store, separate change.

---

## 6. Rollout requirements

1. **The shape normalisation and the storage change land together** (§4). The
   first version sequenced writer → admin reader → detector, on the grounds
   that "the writer alone is inert". That sequencing is now *wrong*: splitting
   them means a window in which the snapshot is populated and both consumers
   still misread it, including two destructive buttons.
2. **Browser-verify the three states** in the disposable authenticated
   environment: snapshot present, absent, and legacy-value-present. Absent is
   already covered by #301.
3. **Measure the `FraudDetector` recompute rate before and after.** The claim
   "this removes a per-request graph pass" should be a measurement, not an
   inference. There is no counter for it today; adding one is separate work.
4. **Do not couple this to installing an object cache.** If a drop-in is
   installed later, the object-cache path simply starts being taken — and #309
   has tests on both branches.
5. **Expect a quiet window on a cached host.** A pre-change bare list left warm
   reads as "not available" until the next daily run. That is deliberate.

### Rollback

Pure revert. No schema, no migration, no data. A transient left behind by a
reverted build expires on its own and is read by nothing.

---

## 7. Open questions that remain

The payload-size question is **closed** by §3. Three remain, none blocking:

1. **Should the TTL change at all?** `DAY_IN_SECONDS` matches a daily producer
   exactly, so the honest answer may be "no". Raised only because the first
   version argued otherwise and was wrong.
2. **Does `FraudDetector` need a stale tolerance?** It accepts any stored
   snapshot. Reading a 20-hour-old ring list for a scoring decision may be
   fine, or may deserve a threshold — but that is a scoring decision, not a
   storage one.
3. **The stuck pagination cursor** (§1). `vote_ring_offset` is `wp_cache` only,
   so on a cacheless host ring detection only ever examines the first 500
   mutual pairs. Worth its own change; naming it here so it is not lost.

---

## Related

- **#309** — the implementation this document recommends. Open, unmerged.
- #301 — the Vote Rings tab stops printing "✓ No vote rings detected" from an
  absent snapshot. The display half; already merged.
- #305 — the Ecosystem rollup, which has the same object-cache dependency for
  its 5-minute cache.
- #307 — `PushMetrics` presence, which documents and tests the
  object-cache-then-transient pattern #309 copies, on **both** branches.
- #300 — the overview snapshot's "serve stale, labelled, never cache a failure"
  shape.
