# Separating relationship detection from punitive scoring — implementation design

**Status: design. Nothing here is implemented. No score is altered, no
threshold changed, no operator capability added.**

Written for whoever implements this, and for the decision-maker who has to
approve §9 first.

## The direction this implements

> Reciprocal relationships alone must not create fraud penalties,
> restrictions or adverse ranking effects. Suspicious patterns may enter an
> expiring review queue, with no penalty merely for being queued.

---

## 1. ⚠⚠ There are FOUR reciprocity contributions, not three

This was the starting assumption and it was wrong. A fourth exists, it is
**pairwise**, and it needs no ring at all.

| | Trigger | Points | Rule | Blended? | Traceable after the fact? |
|---|---|---|---|---|---|
| **A1** | `in_vote_ring` | **+40** | member of a detected ring: ≥3 members, strength > 5.0 | ⚠ yes | yes — `fraud_analysis.triggers` |
| **A2** | `vote_ring` | **+25** | ⚠ **PAIRWISE**: `countMutualVotePages() > 3`, i.e. ≥4 distinct mutual pages. **No ring, no third member, no strength gate** | ⚠ yes | yes — `fraud_analysis.triggers` |
| **B** | — | **+15** | direct, inside `detectVoteRings()`, once per ring per 30 days | no | ⚠ **no** |
| **C** | — | **+20** | direct, inside `detectEndorsementRings()`, once per cycle per 30 days | no | ⚠ **no** |

Code references, against `f6f13f59`:

- **A1** `FraudDetector.php:390-391` — `$results['triggers'][] = 'in_vote_ring'; $results['score'] += 40;`
- **A2** `FraudDetector.php:197-199` — `if (self::detectVoteRing($userId))`, via
  `FraudDetector::detectVoteRing()` (`:77`) →
  `VoteRepository::countMutualVotePages()` (`:1900`-ish), compared
  `> BCC_TRUST_RING_MIN_MUTUAL` (= 3)
- **B** `TrustGraph.php:747` — `incrementFraudScore((int)$uid, 15, 'vote_ring_member')`
- **C** `TrustGraph.php:1123` — `incrementFraudScore((int)$uid, 20, 'endorsement_ring')`

⚠ **A1 and A2 both fire for a ring member.** A ring member necessarily has
pairwise reciprocity, so the analysis contribution is **+65**, not +40.

### What that actually does — measured

Replicating `getEnhancedFraudScore()` (blend 70/30, floor at 50% of peak,
monotonic):

**Two tradespeople, 4+ mutual jobs, no ring at all** — A2 only:

| pass | score | band | rate limit | vote weight |
|---|---|---|---|---|
| 1 | 18 | Minimal | ×1.0 | 1.00 |
| 2–5 | 23 → **25** | **Low** | **×0.8** | 1.00 |

Permanent decay floor: **12**.

**Three tradespeople in a genuine mutual trio** — A1 + A2 + B:

| pass | score | band | rate limit | vote weight | attest |
|---|---|---|---|---|---|
| 1 | **50** | Medium | ×0.6 | 0.750 | allowed |
| 2 | **61** | **High** | **×0.4** | 0.695 | ⚠ **BLOCKED** |
| 3–5 | 64 → **65** | High | ×0.4 | 0.675 | BLOCKED |

Permanent decay floor: **32**.

⚠ **This supersedes the figure in `docs/ring-scoring-trace.md` §7.2.** That
table modelled A1 alone and concluded a ring flag on a clean account was a
slow, modest, invisible penalty reaching Medium on pass 4. With A2 and B
included — which is what actually happens — **a genuine trio reaches HIGH on
pass 2**: attestations blocked, rank progression blocked, and every page they
ever voted on re-scored. The trace is corrected in the same change as this
document.

---

## 2. The separation

One sentence: **detectors emit observations; a separate evaluator decides
consequence; today the evaluator's answer for uncorroborated reciprocity is
"none".**

```
                    ┌──────────────────────────────┐
  TrustGraph        │ detectVoteRings()            │  pure: returns components
  (detection)       │ detectEndorsementRings()     │  ⚠ NO incrementFraudScore
                    │ FraudDetector::detectVoteRing│  pure: returns bool
                    └──────────────┬───────────────┘
                                   │ observations
                    ┌──────────────▼───────────────┐
  NEW               │ RelationshipObservation      │  records WHAT was seen
  (recording)       │  → bcc_trust_patterns        │  reuses the existing store
                    └──────────────┬───────────────┘
                                   │
                    ┌──────────────▼───────────────┐
  NEW               │ ReciprocityEvaluator         │  decides CONSEQUENCE
  (policy)          │  tier1 → none                │  ⚠ today: always tier1
                    │  tier2 → review queue        │     unless an operator
                    │  tier3 → operator finding    │     has recorded a finding
                    └──────────────┬───────────────┘
                                   │ consequence (today: none)
                    ┌──────────────▼───────────────┐
  EXISTING          │ FraudDetector / scoring      │  unchanged for every
  (scoring)         │                              │  NON-reciprocity signal
                    └──────────────────────────────┘
```

### 2.1 What changes, per contribution

**A1 `in_vote_ring` (+40) and A2 `vote_ring` (+25)** — keep emitting the
**trigger**, stop adding the **points**.

The trigger must survive: it is the only durable record that the relationship
was observed (`fraud_analysis.triggers`), it is what the review queue reads,
and it is what any future measurement depends on. Only the `$results['score']
+= N` lines go.

⚠ Removing the points changes `confidence`, which is
`count($results['details']) / 15`. `details` is written alongside each
trigger, so leaving the trigger in leaves `details` in, and confidence is
unaffected. Verify this in a test rather than assuming it.

**B (+15) and C (+20)** — remove the `incrementFraudScore` calls from inside
`detectVoteRings()` and `detectEndorsementRings()` entirely.

⚠ This is the structurally important half. A detector that scores is why a
page request can currently increment a stranger's fraud score: `FraudDetector`
falls back to `detectVoteRings()` when no snapshot is available, and that
fallback writes. Removing the write makes the detectors safe to call from a
request, which is a prerequisite for everything else — including #312.

⚠ The 30-day `bcc_ring_*` transients exist **only** to rate-limit those
increments (`TrustGraph::ringTransientKey()`, `:993`). With the increments
gone they have no other reader. Do not delete them in the same change —
leave them to expire, and remove the key-minting in a follow-up, so a rollback
of this change does not immediately re-penalise everyone whose transient has
since lapsed.

### 2.2 What the detectors become

`detectVoteRings()` and `detectEndorsementRings()` keep:

- the graph construction and component/cycle finding;
- `calculateRingStrength()` and its threshold — **unchanged**, because the
  threshold now only decides what is *recorded*, not what is penalised;
- `storeVoteRingPattern()` (`TrustGraph.php:739`) — already the observation
  store, already outside the 30-day guard, already writing every pass;
- the `wp_cache_set` of their results.

They lose: both `incrementFraudScore` calls. Nothing else.

⚠ `storeVoteRingPattern()` writing on **every** pass is a pre-existing
write-amplification issue (one pattern row per recompute on a cacheless
host). It is not caused by this change and must not be "fixed" inside it —
but once the detectors stop being reachable from the request path via a
scoring fallback, its blast radius shrinks on its own.

---

## 3. Retained independent fraud signals — do not change incidentally

These are **not** reciprocity signals and must behave exactly as they do
today. A regression test should pin each one's points.

From `FraudDetector::analyzeFraud()`:

| Trigger | Points | Keep |
|---|---|---|
| `rapid_voting` | +30 | ✓ |
| `uniform_voting` | +20 | ✓ ⚠ see note |
| `vote_changing` | +15 | ✓ |
| `multi_account` | +25 | ✓ |
| `high_device_fraud` | +30 | ✓ |
| `medium_device_fraud` | +15 | ✓ |
| `shared_device_<N>_users` | variable | ✓ |
| `critical_behavior` | +40 | ✓ |
| `high_risk_behavior` | +25 | ✓ |
| `suspicious_behavior` | +15 | ✓ |
| `behavior_<flag>` | variable | ✓ |
| `very_low_trust` | +30 | ✓ |
| `low_trust` | +15 | ✓ |
| `brand_new_account` | +20 | ✓ |
| `new_account` | +10 | ✓ |
| `unverified_email` | +15 | ✓ |
| `user_info_missing` | +15 | ✓ |

From `FraudDiscountCalculator::compute()` — **entirely unchanged**: automation
0.90, `automation_suspected`, `multi_account` 0.50, `device_fraud`,
`behavior`, the `fraud_score` term, and the `trust_rank_boost` reduction. Note
the `fraud_score` term keeps working; it just has a smaller input once
reciprocity stops feeding it.

Also unchanged: `RateLimiter::getAdjustedLimit()` bands, the crossing fan-out,
`applyDailyFraudDecay()`, `peak_fraud_score` monotonicity, and both HIGH gates
(`AttestationService::assertFraudClear()`,
`CredibilityAtoms::fraudClear()`).

⚠ **`uniform_voting` +20 is adjacent and out of scope, but worth naming.**
"All up or all down" is what a genuinely satisfied customer base produces. It
is not a reciprocity signal, so it stays — but it is the next candidate for
this same scrutiny, and it should not be quietly inherited as sound.

---

## 4. The evidence standard for sanctions

### 4.1 What is explicitly NOT sufficient

⚠ **Shared devices, shared IPs and account proximity do not, alone,
constitute corroborated manipulation.** Each has an ordinary explanation in
this product's population:

- a shared device or IP is a site office, a works van, a shared household, a
  job-site wifi, a family business;
- registrations close in time are a crew joining after one visit;
- votes close in time are a group in the same place on the same evening.

They may raise a reciprocity observation to **Tier 2 (review)**. They may not
promote it to Tier 3.

⚠ Note what this does **not** mean: `multi_account` (+25) and
`shared_device_*` remain fraud signals in their own right, scoring exactly as
today (§3). What changes is that they cannot be used as the corroboration
that makes a *reciprocity* observation sanctionable. Those are different
questions and the design keeps them apart.

### 4.2 What Tier 3 requires

**All three, together:**

1. a reciprocity observation (necessary, never sufficient);
2. evidence that the relationships are **not independent** — i.e. evidence
   about the votes or vouches themselves, not merely about the accounts'
   context;
3. ⚠ **an operator decision, recorded with a reason.** There is no automatic
   path from any combination of signals to a sanction. None.

Candidate (2) evidence, honestly graded:

| Evidence | Computable today? | Weight |
|---|---|---|
| confirmed automation (`is_automated`, not "suspected") | yes | strong — and already a 0.90 penalty on its own |
| identical or templated vouch text across members | partially — `context_note` exists | moderate |
| votes on pages with no plausible relationship to the voter's trade or locality | no | moderate |
| an external report of a solicited exchange | no — out of band | strong |
| an admission | no | strong |
| consideration/payment evidence | no | strong |

⚠ **Most of the strong evidence is not computable.** That is the honest
finding and it is the reason the design puts a human at the decision point
rather than a formula. A system that cannot distinguish legitimate from
manufactured reciprocity should identify and present, not adjudicate.

### 4.3 The review queue

- An observation enters Tier 2 only when at least one corroborating indicator
  is present. A bare observation stays Tier 1 and is recorded, not queued.
- ⚠ **No penalty of any kind for being queued** — no score, no rate limit, no
  weight discount, no ranking effect, nothing on the member's record that any
  gate reads.
- ⚠ **Entries expire.** An unreviewed suspicion must not harden into a
  penalty by default; that is precisely how the current state arose. Expiry
  closes the entry with an audited "not reviewed" outcome, not with a
  sanction.
- The queue is operator-only. Nothing about it is user-visible or publicly
  exposed.

---

## 5. Notification, appeal, audited reversal

### 5.1 Notification — Tier 3 only

Today there is **no notification at any score** (`ring-scoring-trace.md` §5).
Proposed:

1. Notice when a sanction is first applied: what was found, in plain words,
   and that it reduces how much their votes count.
2. State what it is **not**: not a suspension, not public. Nothing about
   `fraud_score` is publicly exposed today and that must stay true.
3. An appeal route to a human.
4. Notice when it is lifted.
5. ⚠ **No notification in Tier 1 or Tier 2.** Telling a member they are
   "under review" for an ordinary professional relationship is worse than
   silence and would leak the operator queue.

### 5.2 ⚠ Appeal requires reversal, and reversal does not currently exist

`peak_fraud_score` is **monotonic by design** — it is the anti-oscillation
defence against idle → decay → resume (`UserInfoRepository.php:712`
comments). `applyDailyFraudDecay()` floors at 50% of peak. So:

- an appeal that cannot lower the peak cannot restore anything;
- a successful appeal today would leave the member permanently at half their
  peak.

### 5.3 Proposed peak handling — an adjustment ledger, not a peak reset

⚠ **Do not add a "reset peak" capability.** It would be an oscillation bypass
with an operator's name on it, and the defence it removes is real.

Instead:

- a **reversal record** per incident: user, what was reversed, which
  observation, operator, reason, timestamp;
- the effective floor becomes `floor((peak − reversedAmount) × 0.5)` rather
  than `floor(peak × 0.5)`;
- `peak_fraud_score` itself is **never lowered**, so the oscillation history
  is intact and a repeat offender's peak still stands;
- reversals are additive-only and audited, so a second reversal is visible as
  a second record rather than as a mutated number.

This keeps the anti-oscillation property (peak is still monotonic) while
making an appeal mean something (the floor it implies can move).

⚠ **This is a new operator capability and is therefore out of scope to build
here.** It is described so the decision in §9 can be taken with its cost
visible. It is also the single largest piece of work in this design.

---

## 6. Existing scores

Constraints taken as given: **do not subtract guessed contributions, do not
reset whole accounts.**

### 6.1 What available evidence CAN establish

- **Presence of a reciprocity trigger.** `fraud_analysis.triggers` is a JSON
  array of trigger names, one row per analysis, indexed
  `idx_user_recent (user_id, analyzed_at)`. So "did `in_vote_ring` or
  `vote_ring` appear for this user" is answerable.
- **Current trigger set.** `user_info.fraud_triggers` holds a JSON snapshot of
  the most recent analysis's triggers.
- **Context.** `fraud_analysis.details` carries `ring_size`, `ring_strength`
  and `vote_ring => true`.
- **The stored score and peak** at each analysis (`fraud_analysis.fraud_score`,
  `user_info.peak_fraud_score`).
- **Whether reciprocity was the ONLY trigger.** Derivable by set difference
  against the §3 list — and this is the most decision-relevant fact available.

### 6.2 ⚠ What CANNOT be reconstructed

1. **The arithmetic share of any contribution.** `fraud_score` is a single
   blended integer. The per-signal `points` live in `$results['signals']`,
   which `storeAnalysis()` **does not persist** — only `triggers` and
   `details` are stored.
2. **Anything from B or C.** The direct increments go through
   `incrementFraudScore()`, which writes `user_info` only. ⚠ Its `$reason`
   parameter is **accepted and never used** — no audit row, no log line, no
   `fraud_analysis` entry. Their contribution is invisible after the fact; the
   only evidence they happened was a 30-day transient that has since expired.
3. **Variable-point triggers.** `shared_device_<N>_users` and `behavior_<flag>`
   carry computed points, so even a trigger-set replay cannot reproduce the
   analysis score exactly.
4. **History beyond retention.** `fraud_analysis.expires_at` is +90 days
   (`BCC_TRUST_CLEANUP_FRAUD_ANALYSIS`). Older analyses are pruned.
5. **The order of events.** Because the blend is 70/30 and the peak floor is
   monotonic, the same set of contributions arriving in a different order
   leaves a different stored score. The sequence is not recorded.

**Therefore a precise retroactive correction is not possible from stored
data.** Any attempt would be the "guessed contribution" the direction
forbids.

### 6.3 What happens to existing scores if nothing is done

⚠ Stating this plainly because it is the uncomfortable part. Once the four
contributions stop feeding new analyses:

- `getEnhancedFraudScore()` recomputes on the daily pass, so affected accounts
  **converge downward on their own** toward whatever their non-reciprocity
  signals justify;
- ⚠ but `max(newScore, floor(peak × 0.5))` holds them at **half their
  historical peak, forever** — the trio in §1 floors at 32, still Low band,
  still ×0.8 rate limits;
- and `updated_at` churn means an active member never reaches even that via
  decay; the convergence comes from the recompute, not the decay.

So: new penalties stop immediately; **old ones persist at half-peak
indefinitely.** That is an inconsistency, and it cannot be fixed without
either the §5.3 reversal ledger or a bulk operation that this direction
rules out.

### 6.4 Scoped production measurement that would be needed

⚠ **Not run. Requires its own authorization.** Enough to size §6.3 without
reconstructing any arithmetic — all read-only, all on indexed columns:

1. **How many accounts carry a reciprocity trigger** in `fraud_analysis`
   within retention, and in `user_info.fraud_triggers` now.
2. **Of those, how many have ONLY reciprocity triggers** versus reciprocity
   plus at least one §3 signal. ⚠ This is the load-bearing number: the first
   group is who the policy change is for.
3. **Distribution of `fraud_score` and `peak_fraud_score`** across both
   groups, bucketed by band.
4. **How many sit at or above 40 and 60** — i.e. how many are currently
   weight-discounted, and how many are attestation- and rank-blocked.
5. **How many would still be at or above each threshold** on
   non-reciprocity triggers alone — a lower bound on who is correctly scored.

What it will **not** answer: how much of any individual score came from
reciprocity (§6.2). The measurement is for sizing and prioritisation, not for
computing a correction.

---

## 7. Effects on cached vs uncached hosts

| | with `object-cache.php` | without (staging) |
|---|---|---|
| A1 `in_vote_ring` today | fires (snapshot holds both ring kinds) | does not fire (read misses → fallback returns vote rings only) |
| A2 `vote_ring` today | ⚠ **fires** — it is a direct DB query, no cache involved | ⚠ **fires** |
| B / C today | fire from cron | fire from cron **and** from page-triggered recomputes |
| after this design | none fire | none fire |

⚠ **A2 is cache-independent**, so the pairwise +25 has been applying on
*every* host all along. The cached/uncached asymmetry only ever affected A1.
This change removes the asymmetry by removing all four, which is a better
outcome than #312's unification and is why #312 is blocked rather than
merged.

⚠ **On a cached host this is a reduction in penalties for behaviour already
penalised.** On an uncached host it is a reduction too, just a smaller one.
Neither is a widening — nothing here increases any penalty for anyone.

---

## 8. Implementation order

Each step independently revertible. ⚠ No step may be combined with a storage
change.

| # | Step | Risk |
|---|---|---|
| 1 | Pin the §3 signals with a regression test asserting each trigger's points. **Must pass on `main` before anything changes.** | none — test only |
| 2 | Remove B and C: the two `incrementFraudScore` calls inside the detectors. Detectors become write-free for scoring. | low — pure removal; the detectors still record observations |
| 3 | Remove A1's and A2's `score +=`, keeping both triggers and their `details`. Assert `confidence` is unchanged. | low |
| 4 | Observation recording: formalise what `storeVoteRingPattern()` already writes; add the endorsement equivalent. | low |
| 5 | `ReciprocityEvaluator` returning Tier 1 for everything, with the corroboration inputs stubbed and unused. | none — no caller acts on it |
| 6 | Review queue surface, operator-only, with expiry. | medium — new UI |
| 7 | Tier 3 path: operator finding, notification, appeal. ⚠ Needs §5.3 first. | high |
| 8 | §5.3 adjustment ledger. ⚠ New operator capability; separate authorization. | high |
| 9 | Rebuild #312 on top of 1–5. | low by then |

⚠ Steps 1–3 deliver the whole of the stated direction: after step 3 no
reciprocity creates a penalty, restriction or ranking effect. 4–6 add the
review queue. 7–8 are only needed if Tier 3 is ever to be used.

**Retire the 30-day `bcc_ring_*` transients after step 2 has held for one
full cycle**, not within it (§2.1).

---

## 9. Decisions needed before coding

1. ⚠ **Does the direction cover all FOUR contributions, including the
   pairwise A2?** A2 penalises two people with no ring at all. It was not in
   the original framing. **If A2 stays, the direction is not implemented** —
   two tradespeople with four mutual jobs would still reach Low band with a
   permanent floor of 12.
2. **Does the trigger stay when the points go?** The design assumes yes —
   it is the only durable record and the queue's only input. Confirm, because
   it means `fraud_analysis` keeps recording relationships that carry no
   consequence.
3. **Is Tier 2 worth building at all** (steps 4–6), given §4.2 concludes most
   strong evidence is not computable? A defensible alternative is: steps 1–3
   only, and handle genuine manipulation entirely out of band.
4. ⚠ **Existing scores** — accept the half-peak persistence in §6.3, or
   authorize the §6.4 measurement to size a correction? The measurement is
   read-only and cheap; the correction is neither.
5. **The §5.3 adjustment ledger** — approve in principle, or defer Tier 3
   until it exists? Appeal without it is not appeal.
6. **`uniform_voting` +20** (§3) — in scope later, or explicitly accepted?

---

## Related

- `docs/ring-scoring-trace.md` — the measured trace. ⚠ Its §7.2 trajectory is
  **superseded by §1 here**: it modelled A1 alone and understated the effect.
- `docs/ring-policy-proposal.md` — the three tiers this design implements.
- `docs/fixtures/ring-shape-fixtures.php` — the synthetic shapes.
- **#312** — blocked. Its storage half survives; its scoring effect does not.
- #310, #311 — merged moderation safety; no scoring change.
