# Persisting `vote_ring_offset` — proposal

**Status: proposal. Nothing here is implemented, and #309 does not implement
it.** #309 persists the ring *snapshot*; this is about the *cursor*, which is a
separate store with a separate failure.

---

## 1. The defect, stated exactly

`TrustGraph::detectVoteRings()` paginates the mutual-pair query:

```php
$pageLimit = 500;
$lockHeld  = AdvisoryLock::acquire('bcc_ring_cursor', 0);
try {
    $offset      = (int) (wp_cache_get('vote_ring_offset', self::CACHE_GROUP) ?: 0);
    $mutualVotes = $this->voteRepo->getMutualVotePairs($minSize, $pageLimit, $offset);
    if ($lockHeld) {
        $nextOffset = (count($mutualVotes) === $pageLimit) ? $offset + $pageLimit : 0;
        wp_cache_set('vote_ring_offset', $nextOffset, self::CACHE_GROUP, self::CACHE_TTL);
    }
} finally {
    if ($lockHeld) { AdvisoryLock::release('bcc_ring_cursor'); }
}
```

The cursor lives **only** in `wp_cache`. `wp_cache_*` is per-request unless an
`object-cache.php` drop-in is installed, and **staging has none**.

### ⚠ Measured, with a fixture that actually fills a page

An isolated container, **1,800 qualifying mutual pairs** seeded against the
500-row page limit, `wp_using_ext_object_cache()` false as on staging:

| | offset at start | after the pass |
|---|---|---|
| **within one process** — pass 1 | *absent* | **500** |
| pass 2 | 500 | **1000** |
| pass 3 | 1000 | **1500** |
| **across requests** — request 1 | *absent* | 500 |
| request 2 | **absent** | 500 |
| request 3 | **absent** | 500 |

The mechanism is not broken — inside a single process the cursor climbs
correctly. It simply **does not survive the request that wrote it**. So on a
host with no `object-cache.php`, every pass scans **rows 0–499 of 1,800**, and
**1,300 pairs are never examined**. Not rarely — never.

The `$pageLimit` comment says *"use `$offset` to paginate across multiple cron
ticks when needed"*. There is no mechanism by which an offset reaches the next
cron tick.

⚠ **An earlier version of this measurement proved nothing, and the correction
is worth recording.** Its fixture held one 3-member ring — six mutual pairs —
so `count($mutualVotes)` never equalled 500 and the end-of-stream rule reset
the cursor to 0 legitimately. "The offset reads 0" was the *correct* answer for
that fixture and said nothing whatever about persistence. A pagination claim
needs a fixture that overflows the page, and the first one did not.

### Why #309 does not fix it

#309 gives the *snapshot* a durable store. The cursor is a different key with a
different lifetime, and the read in `detectVoteRings()` is untouched. #309 does
change *how often* the detector runs — the `FraudDetector` fallback fires far
less — but a cursor stuck at 0 is stuck at 0 whether it is read once a day or
once a request.

⚠ And there is an interaction worth naming: today, on a cacheless host, the
cron pass and every page-triggered recompute all start at offset 0, so they all
scan the same slice. After #309 the recompute is rare, so **the daily cron
becomes effectively the only reader — still at offset 0**. The symptom does not
get worse; the opportunity to notice it does.

---

## 2. Reset and end-of-stream behaviour

The current rule is `nextOffset = (count === pageLimit) ? offset + 500 : 0` —
a short page means the tail was reached, so wrap to 0. Two problems once the
cursor is durable:

1. ⚠ **A short page is not proof of the tail.** `getMutualVotePairs()` has a
   `HAVING mutual_count >= %d` after `GROUP BY`, so the row count is not a
   simple function of the offset: rows can be filtered out mid-stream. A page
   of 499 can be followed by a full page.
2. ⚠ **Rows shift under the cursor.** The query has `ORDER BY` *nothing* —
   only `GROUP BY v1.voter_user_id, v2.voter_user_id` and `LIMIT/OFFSET`.
   **An unordered `LIMIT/OFFSET` has no stable paging guarantee at all**, so a
   durable cursor can skip pairs and repeat others even with no data change.
   This is the same class of defect already recorded for creator-gallery
   pagination.

**Therefore the cursor cannot be made durable on its own.** A deterministic
order is a precondition, not a follow-up:

- Add `ORDER BY v1.voter_user_id, v2.voter_user_id` — both are already in the
  `GROUP BY`, so the pair is unique per row and the order is total. No new
  tiebreak column is needed.
- ⚠ An explicit `ORDER BY` on a grouped 4-way self-join has a cost that must be
  measured before it ships, against the same fixtures used for the payload
  measurements. If it is unaffordable, keyset pagination on
  `(voter_user_id, voter_user_id)` is the alternative, and that changes the
  repository signature — a bigger change, honestly scoped up front.

**Proposed rules, once the order is total:**

| Situation | Rule |
|---|---|
| full page returned | advance by `$pageLimit` |
| short page returned | **finish the sweep**: record a sweep-complete marker and reset to 0 on the *next* pass |
| offset ≥ total pair count | reset to 0 |
| the sweep wrapped | stamp `swept_at`, so "we have seen the whole table once" is a readable fact |

The sweep marker matters: today, resetting to 0 and "having completed a sweep"
are indistinguishable, so there is no way to tell a healthy wrap from a cursor
that never moved.

---

## 3. Failures

| Failure | Required behaviour |
|---|---|
| store unreadable | treat as offset 0, log once, **do not** advance — a sweep that cannot read its cursor must not also write one |
| store unwritable | scan the slice, log, leave the cursor alone; the pass is still useful, it just does not progress |
| the pair query fails | `get_results()` returns `[]`, which is indistinguishable from an empty page — so **the failure must be detected before the cursor moves**, or a failed read will look like "tail reached" and reset the sweep to 0. This is the central failure case and the one most likely to be got wrong |
| the process dies mid-pass | the cursor was written before the graph work, so the slice is skipped. Accept it (a sweep is repeated at most once a cycle) or move the write after the work and accept re-scanning instead; **pick one deliberately** |
| offset beyond the table | reset to 0 rather than returning empty pages forever |

⚠ The third row deserves emphasis: `wpdb::get_results()` returns `[]` on a
failed query, so `count($mutualVotes) === $pageLimit` is false and the current
code resets the cursor to 0. A durable cursor would therefore **lose its sweep
position on every query failure** and silently restart. The read-failure
discrimination work already merged (#300, #301, #304, #305) is the idiom to
reuse here.

---

## 4. Concurrent runs

`AdvisoryLock::acquire($key, 0)` wraps MySQL `GET_LOCK` with timeout 0 —
refuse, never wait. That posture is right and should be kept. Three things to
tighten:

1. ⚠ **`acquire()` returns `false` for "held by a peer" AND for a driver
   error.** The two are not distinguished, so "did not advance the cursor
   because a peer held the lock" and "did not advance because the database is
   unwell" look identical. They need distinct log lines, not distinct
   behaviour.
2. ⚠ **`GET_LOCK` is reentrant per session.** Every acquire must be matched by
   exactly one release — the existing `finally` does this correctly and must
   not be refactored into something that can release twice or not at all.
3. **The read and the advance must be in the same critical section.** They are
   today. A durable cursor makes an interleaving genuinely harmful rather than
   merely wasteful, so the lock must cover `read → scan-decision → write`, and
   a non-holder must use the cursor **without advancing it** (as now).

With a durable cursor there is also a new case: the cron pass and a
page-triggered recompute running concurrently. The non-holder scans the same
slice — wasteful but safe, and already the designed behaviour.

---

## 5. Coverage proving progress beyond the first 500 rows

This is the part that cannot be hand-waved, because the current test suite
would pass against a cursor that never moves.

**Required, in an isolated database:**

1. **A fixture with more than 500 qualifying mutual pairs.** Already built and
   measured: 300 disjoint 3-member rings produce **1,800** qualifying pairs
   against the 500-row cap, which is where the numbers in §1 come from. The
   fixture work is done and the shape is known.
2. ⚠ **A planted ring beyond row 500 under the new ORDER BY**, and an
   assertion that a sweep detects it. This is the anti-vacuity case: without
   it, "the cursor advanced" can be true while nothing new is ever found.
   The ring must be placed by *ordering key*, not by insertion order.
3. **A multi-pass sweep test**: N passes cover the whole table exactly once —
   every qualifying pair seen at least once, and the sweep marker set at the
   end. Asserted as a set, not a count.
4. **A no-skip assertion.** Collect the pairs seen across a full sweep and
   assert the union equals the full set from an unpaginated query. A count
   alone cannot detect a skip plus a duplicate.
5. **The query-failure case**: inject a failure mid-sweep and assert the cursor
   does **not** reset to 0.
6. **The lock-contention case**: a non-holder scans without advancing, proved
   by the cursor's value before and after.
7. **The no-object-cache case explicitly**, asserting
   `wp_using_ext_object_cache()` is false, so the durable path is genuinely
   what is under test — the same precondition assertion #307 added.
8. ⚠ **A regression test that fails on today's code.** If the suite passes
   against `main`, it is not testing this.

---

## 6. Sequencing, and what is NOT proposed

**Order matters, and the cursor is last:**

1. Give the pair query a total `ORDER BY`, and measure its cost. Without this
   a durable cursor is worse than a stuck one, because it skips silently
   instead of repeating visibly.
2. Add read-failure discrimination to the pair read, so a failed query cannot
   be mistaken for the tail.
3. *Then* make the cursor durable, with the sweep marker.

**Not proposed:**

- **No change to `$pageLimit`.** 500 is a deliberate bound on a 4-way
  self-join; a durable cursor is the mechanism for covering the table, not a
  larger page.
- **No change to detection, thresholds, or the +15/+20/+40 weights.**
- **No object cache installed.** The point is that the cursor works without
  one.
- **No new cron hook.** The daily producer already exists.
- **No table.** A transient beside the snapshot is sufficient for an integer
  and a marker.

⚠ **This change alters what the detector FINDS**, and therefore who gets
scored. It should not be presented as plumbing.

⚠⚠ **And it is the SECOND scoring change in this programme, not the first.**
#309 turned out to carry one too, found in review and measured: on a host
with no persistent object cache the old `FraudDetector` read always missed,
so its fallback ran and `detectVoteRings()` returned **vote rings only** —
while the stored snapshot carries both kinds. An endorsement-only ring member
goes from **+0 to +40** there. A drop-in host always scored both, so #309
unifies the two rather than adding anything; but the earlier claim that the
ring work was uniformly scoring-neutral was wrong, and this document should
not repeat it.
Rings beyond row 500 becoming visible means members of those rings start
receiving `+15` / `+40`. That is the intended correction, and it needs to be
stated as a scoring change in its own right — with the volume of newly detected
rings measured on real data before it ships.

---

## Related

- `docs/vote-ring-persistence-proposal.md` — the snapshot store. Its §1 is
  where the stuck cursor was first recorded.
- **#309** — persists the snapshot. Open, unmerged. Does not touch the cursor.
- `docs/ring-member-enrichment-proposal.md` — the display-side follow-up.
- The creator-gallery `LIMIT/OFFSET` finding — the same unordered-pagination
  class of defect, already recorded elsewhere in this project.
