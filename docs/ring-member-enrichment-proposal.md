# Ring member enrichment — proposal

**Status: proposal. Not implemented, and nothing in #309 implements it.**

#309 makes the Vote Rings tab render a stored snapshot for the first time. The
snapshot carries **membership and nothing else**, so the tab shows each member's
user id and marks fraud score, risk level and suspension state **"not loaded"**.

That is honest but thin. This document proposes loading those three facts, with
a query budget fixed before any code is written.

---

## 1. Why the columns currently say "not loaded"

The member table was written against an enriched row —
`display_name`, `fraud_score`, `risk_level`, `mutual_vote_count`,
`is_suspended` — while both detectors emit a flat list of integer user ids.
Because nothing persisted a snapshot, that branch had never rendered, so the
mismatch was invisible.

Rendering it measured **21 `Trying to access array offset on value of type int`
diagnostics across three rings**, and the `?? 0` / `?? 'unknown'` defaults meant
that silencing them would have printed **"Fraud Score 0"** and a coloured
**"Unknown"** risk pill for every member of a detected ring.

⚠ That is the failure this proposal must not reintroduce in a new form. A
fabricated zero about a person, on the screen whose buttons suspend them, is
worse than an empty column. **"not loaded" is the correct state until the data
is actually loaded, and it must stay the fallback for every row the enrichment
does not cover** — not a default of 0, and not "unknown" in a pill, which reads
as a classification rather than an absence.

---

## 2. What to load, and from where

Three facts, all already available on `wp_bcc_trust_user_info`:

| Column | Source | Today |
|---|---|---|
| fraud score | `fraud_score` | `UserInfoRepository::getFraudScore()` — **one query per user** |
| risk level | derived from `fraud_score` | `Formatting::riskColor()` + the existing tier helpers |
| suspended | `is_suspended` | on the same row |

`display_name` is **not** proposed. It lives in `wp_users`, so it is a second
table and a second budget; the id plus a Manage link already identifies the
member.

---

## 3. ⚠ The query budget, fixed first

The naive implementation is `getFraudScore()` inside the member loop. That is
**one query per member per ring**, and the bound is the snapshot, not the page:

| snapshot | members | naive queries |
|---|---|---|
| staging today | 0 | 0 |
| realistic early platform (measured fixture) | 42 | 42 |
| vote half saturated | 252 | 252 |
| 9,999 vouch edges as 3-cycles | 9,999 | **9,999** |
| 9,900 vouch edges, 100-node clique | 171,402 | **171,402** |

The last two are measured, not hypothetical — they are the payload shapes
recorded in `docs/vote-ring-persistence-proposal.md` §3. A per-member query is
therefore not an option at any scale above the current one.

### The budget

> **Exactly ONE query per page render, with a hard cap of 500 distinct user
> ids. Never a query inside the member loop.**

- **One batched read.** `SELECT user_id, fraud_score, is_suspended FROM
  …user_info WHERE user_id IN (…)`, built from the union of the ids on the
  page after de-duplication. One query whatever the ring count.
- **500 ids, then stop.** Beyond that the remaining members keep saying
  "not loaded". The cap is a number, not a behaviour change: the column has a
  truthful state for an unenriched row already, which is exactly what makes a
  cap safe here.
- **No `SELECT *`** — three named columns, per §1.
- **Bounded by the displayed page.** If ring pagination is ever added, the
  budget follows the page, not the snapshot.
- **The repository owns the SQL.** A new
  `UserInfoRepository::getFraudSnapshotForUsers(array $ids): array` beside
  `getFraudScore()`; no `$wpdb` in the view, per §1.

### What happens when the read fails

The same rule as the rest of the dashboard: **a failed read is not a zero.**
`$wpdb->get_results()` returns `[]` on failure *and* on no rows, so the
repository must report the two apart — the `GuardsReadFailures` idiom, or a
per-read `last_error` check — and every uncovered row falls back to
**"not loaded"**, never to 0.

---

## 4. Risk level is derived, and that is a decision

There is no `risk_level` column; the existing admin surfaces derive it from
`fraud_score` through the config thresholds. So enrichment gives:

- `fraud_score` — a stored number.
- risk level — a **derivation**, correct only as long as the view uses the same
  thresholds the rest of the dashboard uses.

⚠ Two surfaces deriving risk differently from the same score is worse than one
surface not showing it. The derivation must come from the shared helper, and if
that helper does not exist as a single function, extracting it is part of this
work rather than a copy of the thresholds into a third place.

---

## 5. What this does NOT propose

- **No `display_name`.** A second table, a second budget.
- **No `mutual_vote_count`.** It is not in the snapshot and not on
  `user_info`; recovering it means re-running part of the graph, which is the
  cost #309 exists to remove.
- **No change to the snapshot payload.** Adding scores to the stored snapshot
  would freeze a number that changes continuously and make a 2.5 MiB payload
  larger. The snapshot records *membership at detection time*; a fraud score is
  a *current* fact and belongs to the read.
- **No change to the destructive actions.** They resolve membership from the
  snapshot by fingerprint and must keep doing so. Enrichment is display only.
- **No change to scoring.** Reading a fraud score must not write one.

---

## 6. Acceptance

1. One query per render, asserted — a test that counts queries, not a comment.
2. 500-id cap asserted, including that member 501 says "not loaded" rather
   than 0.
3. A failed read renders "not loaded" for every row and never a zero, with the
   empty-table case proving a genuine 0 still displays as 0.
4. No `$wpdb` in the view; no `SELECT *`.
5. Rendered in the three snapshot states (absent / empty / populated) with zero
   PHP diagnostics, as #309's suite does.
6. Risk derived through one shared helper, asserted against the thresholds the
   rest of the dashboard uses.

---

## Related

- **#309** — persists the snapshot and makes the member table render at all.
  Open, unmerged. This proposal depends on it.
- `docs/vote-ring-persistence-proposal.md` §3 — the measured payload shapes
  that set the budget above.
