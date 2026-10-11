# Vote-ring snapshot persistence — proposal

**Status: proposal. No storage behaviour changes in this document or the commit
that carries it.** It exists to be accepted, amended or rejected before anything
moves.

The honest-display half already shipped in #301: the Vote Rings tab no longer
prints **"✓ No vote rings detected"** when it has no snapshot. This document is
about the storage underneath it.

---

## 1. What exists today, measured

### The writer — one, in cron

`CronService.php:318`, inside the `bcc_trust_hourly_graph_update` work and under
`bcc_cron_graph_lock`:

```php
$rings = $graph->getSuspiciousClusters($ringMinSize);
// Shared cache key with AdminDashboardRepository::getRingsData()
wp_cache_set('ring_results', $rings, 'bcc_trust_admin', DAY_IN_SECONDS);
```

`wp_cache_set` **and nothing else**. No transient, no option, no table.

`bcc_trust_hourly_ring_detection` is declared in `includes/cron-hooks.php:173`
as a recurring hook.

### The readers — two, with opposite failure behaviour

| Reader | On a cold key | Consequence |
|---|---|---|
| `AdminDashboardRepository::getRingsData()` (`:792`) | `[]` | the tab showed "✓ No vote rings detected" — **fixed in #301**, now says "No ring-detection snapshot yet" |
| `FraudDetector` (`:383`) | **recomputes inline** via `$trustGraph->detectVoteRings()` | the request pays for a full graph analysis |

```php
$rings = wp_cache_get('ring_results', 'bcc_trust_admin');
if ( ! is_array( $rings ) ) {
    // Fallback: compute only when no cached results are available
    $rings = $trustGraph->detectVoteRings( BCC_TRUST_RING_MIN_SIZE );
}
```

⚠ **This corrects something I wrote in #301's description.** I said ring-based
fraud signalling was "equally inert" without a persistent object cache. It is
not. The signal still fires — `in_vote_ring`, +40 points — because the detector
recomputes rather than reading a zero. The exposure is **cost on the request
path**, not a missing signal. `getRingsData()`'s own docblock calls ring
detection "expensive (graph analysis)", which is why the hourly job exists at
all.

A third mention, `TrustGraph.php:678`, is a comment about the cold-cache case,
not a read.

### Also wrong in the code's own words

`getRingsData()`'s docblock says results are *"stored as a transient"*. They are
not, and never were. That sentence is why the gap survived review.

---

## 2. The behaviour that follows, with and without a persistent object cache

`wp_cache_*` is per-request unless an `object-cache.php` drop-in is installed.

**Measured on staging, 2026-10-11:** `wp-content/object-cache.php` is
**absent**, so `wp_using_ext_object_cache()` is false there.

| | with a persistent object cache | without one (staging today) |
|---|---|---|
| cron writes the snapshot | survives up to `DAY_IN_SECONDS` | **dies with the cron request** |
| admin tab | shows the snapshot | shows "No ring-detection snapshot yet" (post-#301) |
| `FraudDetector` | reads the snapshot | **recomputes the graph, every fraud analysis** |
| cost profile | one graph pass per hour | one graph pass per fraud analysis |

Two consequences worth stating plainly:

1. **The tab cannot show a ring on staging as configured.** Not "rarely" —
   never. The writer and the reader are in different requests.
2. **The hourly job's entire purpose is defeated.** It computes the expensive
   thing once an hour and then throws it away.

⚠ The same is true of other `wp_cache_set` users on such a host — the Ecosystem
rollup's five-minute cache (#305) never takes effect either. That is a
host-configuration fact about this deployment, not a defect in each caller, and
it is the reason this proposal prefers a storage change over a per-caller one.

---

## 3. The precedent to copy, in this codebase

`PushMetrics` already solves exactly this, in the same namespace
(`app/Domain/Core/Support/`), and #307 pins its behaviour with tests:

```php
private static function increment(string $outcome, string $eventType): void
{
    $key = self::buildKey($outcome, $eventType, gmdate('YmdH'));

    if (wp_using_ext_object_cache()) {
        if (wp_cache_add($key, 1, self::CACHE_GROUP, self::TTL)) { return; }
        $incr = wp_cache_incr($key, 1, self::CACHE_GROUP);
        if ($incr !== false) { return; }
        // Cache backend hiccup — fall through to transient so the
        // increment is not lost entirely.
    }

    $current = (int) get_transient($key);
    set_transient($key, $current + 1, self::TTL);
}
```

Four properties worth taking verbatim:

- **`wp_using_ext_object_cache()` is the gate**, not a guess about the host.
- **The transient is a fallback on BOTH write and read**, in the same order.
- **One TTL for both paths**, so the two cannot disagree about freshness.
- **A backend hiccup falls through** rather than losing the write.

§11 says reuse before inventing. This is the thing to reuse.

---

## 4. Proposal

### 4.1 Storage

A single writer helper and a single reader helper, owned by one class, used by
both readers. Suggested home: `app/Domain/Core/Support/RingSnapshot.php`,
alongside `PushMetrics`.

```
RingSnapshot::store(array $rings): void
RingSnapshot::read(): array{available: bool, rings: list<array>, computed_at: int|null, stale: bool}
```

Storage order, mirroring `PushMetrics`:

- **write** — object cache when `wp_using_ext_object_cache()`, **and always** a
  transient as the durable copy
- **read** — object cache first, then the transient
- both carry `computed_at`, so freshness is a property of the payload rather
  than of the store

⚠ Unlike `PushMetrics`, the ring payload is **not a counter** — it is a list of
clusters, and it can be large. The write is therefore a plain `set`, never an
`incr`, and §6 flags size as the open question.

### 4.2 Freshness and expiry

| | value | reason |
|---|---|---|
| producer cadence | hourly (unchanged) | `bcc_trust_hourly_ring_detection` already exists |
| TTL | **3 hours** | survives two missed runs; the current `DAY_IN_SECONDS` lets a snapshot look current a day after detection stopped |
| stale threshold | **90 minutes** | one missed run is visible to an operator without being alarming |
| `computed_at` | stored with the payload | the only way a reader can judge age |

`read()` returns `stale: true` past 90 minutes while still returning the rings,
the same shape #300 gave the overview snapshot: a dated number beats a wrong
one.

⚠ The TTL reduction from a day to 3 hours is a **behaviour change in its own
right**. On a host where the object cache works today, a snapshot that currently
survives 24 hours would start expiring in 3. That is the intent — a day-old ring
list presented as current is the problem — but it is a change to call out, not
to slip in.

### 4.3 Failure states, and what each reader does

| state | `read()` | admin tab | `FraudDetector` |
|---|---|---|---|
| fresh snapshot | `available: true, stale: false` | rings, as today | reads it |
| stale snapshot | `available: true, stale: true` | rings **plus** an age label | reads it, and logs that it is stale |
| no snapshot | `available: false` | "No ring-detection snapshot yet" (already shipped) | **recompute, as it does today** |
| store unreadable | `available: false` | same as no snapshot | recompute, and log at ERROR |

**Nothing here presents missing data as "no rings".** That is the one invariant
the proposal will not trade away, and #301 already enforces it in the view.

⚠ `FraudDetector`'s recompute **stays**. It is a correctness-preserving
fallback: a fraud analysis that silently skipped the ring signal would under-score
a user in a ring, and that is worse than the cost. What changes is how often it
fires — from "every analysis on a cacheless host" to "only when the producer has
genuinely not run".

### 4.4 What is explicitly NOT proposed

- **No database table.** The data is derived, regenerated hourly, and bounded by
  `BCC_TRUST_RING_MIN_SIZE`. A table would need a schema, a migration, a
  drift-guard row and a retention policy for something a transient can hold.
- **No change to the detection algorithm**, thresholds, or the +40 fraud
  weighting.
- **No change to the Hide policy, any public surface, or anything user-facing.**
  Ring membership is operator-only today and stays so.
- **No new cron hook.** The producer already exists.

---

## 5. Rollout requirements

1. **Decide the TTL change first** (§4.2). It is the only part that alters
   behaviour on a host where things currently work.
2. **Land the storage helper with tests before either reader moves**, so the
   helper is exercised independently of its callers — `PushMetricsPresenceIntegrationTest`
   (#307) is the model, including its assertion that
   `wp_using_ext_object_cache()` is false so the fallback is genuinely the path
   under test.
3. **Switch the writer, then the admin reader, then `FraudDetector`**, as
   separate reviewable changes. The writer alone is inert — nothing reads the
   new location yet — which makes it a safe first step.
4. **Browser-verify all four states** in the disposable authenticated
   environment: fresh, stale, absent, unreadable. Absent is already covered by
   #301.
5. **Measure the `FraudDetector` recompute rate before and after.** The claim
   "this removes a per-request graph pass" should be a measurement, not an
   inference. There is no counter for it today; adding one is part of the work.
6. **Do not couple this to installing an object cache.** The point is that the
   feature works without one. If a drop-in is installed later, the object-cache
   path simply starts being taken.

### Rollback

Pure revert. No schema, no migration, no data. A transient left behind by a
reverted build expires on its own and is read by nothing.

---

## 6. Open questions for the decision

1. **Payload size.** `getSuspiciousClusters()` is bounded by cluster size, not
   by cluster *count*. A transient row holds a serialised array in
   `wp_options`; a pathological graph could make it large. Worth measuring the
   real payload on production data before choosing a store — this is the one
   fact that could change the recommendation away from transients.
2. **TTL: 3 hours, or two producer intervals expressed as a constant?** The
   latter survives a cadence change without a second edit.
3. **Should a stale snapshot be surfaced to the operator at all**, or only
   logged? The overview precedent (#300) shows it with its age; rings may want
   the same, since an operator reading "no rings" wants to know how old that is.
4. **Does `FraudDetector` need its own stale tolerance?** It currently accepts
   any cached value. Reading a 2-hour-old ring list for a scoring decision may
   be fine, or may deserve its own threshold.

---

## Related

- #301 — the Vote Rings tab stops printing "✓ No vote rings detected" from an
  absent snapshot. The display half; already merged.
- #305 — the Ecosystem rollup, which has the same object-cache dependency for
  its 5-minute cache.
- #307 — `PushMetrics` presence, which documents and tests the transient-fallback
  pattern this proposal copies.
- #300 — the overview snapshot's "serve stale, labelled, never cache a failure"
  shape, reused in §4.2.
