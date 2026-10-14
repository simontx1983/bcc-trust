# Ring detection → fraud score → consequences: the trace

**Status: review document. Describes production as of bcc-trust `2a35af65`.
Nothing here changes behaviour.**

It exists because the phrase *"the ring signal adds +40"* was used repeatedly
during the #309–#312 work as if it were self-explanatory, and it is not. The
+40 is one of **three** separate mechanisms, it is **not applied directly**,
and what it eventually does to a member depends on what else is on their
record.

All line references are against `2a35af65`. Every number below is either a
config constant or was measured with the harness in §7.

---

> ⚠⚠ **CORRECTION, 2026-10-12.** This section said "three mechanisms". There
> are **FOUR**. The one it missed is **pairwise** and needs no ring at all:
> `vote_ring` **+25**, `FraudDetector.php:197`, via
> `countMutualVotePages() > BCC_TRUST_RING_MIN_MUTUAL` — i.e. **≥4 distinct
> mutual pages between two people**. No third member, no component, no
> strength gate, and **no cache dependency**, so it has been applying on every
> host all along.
>
> It is listed as **A2** below, and §7.2's trajectory is **superseded** — it
> modelled A1 alone and therefore understated the effect substantially. See
> `docs/ring-detection-scoring-separation-design.md` §1 for the corrected
> measurements: a genuine trio reaches **HIGH on pass 2**, not Medium on
> pass 4.

## 1. Four mechanisms, not one

| # | Mechanism | Value | Applied | Blended? |
|---|---|---|---|---|
| **A1** | `in_vote_ring` signal in the fraud analysis | **+40** | per analysis, when the member matches a ring (≥3 members, strength > 5.0) | ⚠ **YES** |
| **A2** | `vote_ring` signal in the fraud analysis | **+25** | ⚠ per analysis, on **PAIRWISE** reciprocity alone — ≥4 distinct mutual pages, **no ring required** | ⚠ **YES** |
| **B** | direct increment inside `detectVoteRings()` | **+15** | once per ring per 30 days | no — straight add |
| **C** | direct increment inside `detectEndorsementRings()` | **+20** | once per ring per 30 days | no — straight add |

⚠ **A1 and A2 both fire for a ring member**, because a ring member necessarily
has pairwise reciprocity. The analysis contribution for a trio is therefore
**+65**, not +40.

- **A1** — `FraudDetector.php:390`, `$results['score'] += 40`, trigger
  `in_vote_ring`.
- **A2** — `FraudDetector.php:197`, `$results['score'] += 25`, trigger
  `vote_ring`, gated by `FraudDetector::detectVoteRing()` (`:77`) →
  `VoteRepository::countMutualVotePages()`, compared
  `> BCC_TRUST_RING_MIN_MUTUAL` (3). ⚠ A plain SQL count — no snapshot, no
  cache, no graph.
- **B** — `TrustGraph.php:747`,
  `incrementFraudScore((int)$uid, 15, 'vote_ring_member')`, inside
  `detectVoteRings()` (`:663`), behind a 30-day per-ring transient.
- **C** — `TrustGraph.php:1123`,
  `incrementFraudScore((int)$uid, 20, 'endorsement_ring')`, inside
  `detectEndorsementRings()` (`:1064`), same transient guard.

⚠ **A member of both a vote ring and an endorsement ring takes B + C = +35
directly**, in addition to A's blended contribution.

### Why A is not +40 on the record

`FraudDetector::getEnhancedFraudScore()` (`:520`) blends:

```
newScore = round(analysis['score'] * 0.7 + existing * 0.3)
newScore = max(newScore, floor(peak_fraud_score * 0.5))     // anti-oscillation floor
updateFraudScore(userId, newScore, peakScore, monotonic: true)
```

So an analysis score of 40 does not store 40. It converges there over
several daily passes — measured in §7.2.

`incrementFraudScore()` (`UserInfoRepository.php:712`) is the other path, and
it is **not** blended:

```sql
SET fraud_score      = LEAST(100, fraud_score + %d),
    peak_fraud_score = GREATEST(COALESCE(peak_fraud_score,0), LEAST(100, fraud_score + %d))
```

It also fires a **threshold-crossing fan-out** when the score crosses
`BCC_TRUST_FRAUD_HIGH` from below — see §4.

---

## 2. The detection rule

### 2.1 Vote rings — three gates

`TrustGraph::detectVoteRings()` (`:663`).

**Gate 1 — the pair.** `VoteRepository::getMutualVotePairs()` (`:1900`)
selects pairs (A,B) where A voted on a page owned by B *and* B voted on a page
owned by A, with:

```sql
HAVING mutual_count >= %d        -- bound to BCC_TRUST_RING_MIN_SIZE = 3
LIMIT 500 OFFSET %d
```

⚠ `mutual_count` is `COUNT(*)` over a **4-way join**, so it counts the
**cross product** of A's votes on B's pages against B's votes on A's pages.
With *p* pages voted each way that is *p²* rows:

| pages each way | joined rows | qualifies as a pair? |
|---|---|---|
| 1 | 1 | **no** |
| 2 | 4 | yes |
| 3 | 9 | yes |

So one reciprocal job each way is *not* a pair. Two is.

⚠ The query is bound to `BCC_TRUST_RING_MIN_SIZE`, **not**
`BCC_TRUST_RING_MIN_MUTUAL`. Both are `3`, so there is no behavioural
difference today — but the Vote Rings tab prints *"Min mutual votes"* from
`RING_MIN_MUTUAL`, a constant this query never reads. Changing one would not
change the other.

**Gate 2 — the component.** Those pairs form an *undirected* graph; BFS
components with `count >= BCC_TRUST_RING_MIN_SIZE` survive. A **chain**
qualifies — A–B and B–C with no A–C is a 3-member component.

**Gate 3 — strength.** `calculateRingStrength()` (`:1029`):

```
strength = avgWeight × density × count        must EXCEED 5.0
```

where `avgWeight` is the mean `total_weight` over the component's present
pairs, `density` = present edges ÷ possible edges, `count` = members.
`total_weight` is `SUM(v1.weight + v2.weight)` over the join — i.e. *p²* rows
each contributing twice the vote weight.

⚠ **Gate 3 excludes almost nothing for established members.** Measured in
§7.1: a 3-member trio at vote weight 1.0 scores **24.0** against a threshold
of 5.0 — nearly five times over. It only fails at vote weights around 0.1,
which is brand-new or low-rank accounts.

### 2.2 Endorsement rings — one gate

`TrustGraph::detectEndorsementRings()` (`:1064`).
`AttestationRepository::getVouchEdges(10000)` (`:1511`) builds a **directed**
graph `attestor → target`; `dfsCycleDetect` enumerates cycles; cycles with
`count >= BCC_TRUST_RING_MIN_SIZE` are kept, de-duplicated by sorted member
set.

⚠ **There is no strength gate, no volume gate and no weighting.** One vouch
per edge is enough. A vouches B, B vouches C, C vouches A is a ring.

---

## 3. What the stored score does

`fraud_score` is an integer 0–100. Bands come from
`FraudClassification::label()`.

| Score | Band | Effect |
|---|---|---|
| ≥ 20 | Low | rate limits **×0.8** |
| ≥ 40 | Medium | rate limits **×0.6**; vote weight **×0.8** |
| > 40 | — | GitHub trust multiplier halved (**×0.5**) — *GitHub-verified members only*, and the comparison is strictly `>` |
| ≥ 60 | High | rate limits **×0.4**; **attestations blocked**; **rank progression blocked**; crossing fan-out re-weights past votes |
| ≥ 80 | Critical | rate limits **×0.2** |

**Vote weight** — `FraudDiscountCalculator::compute()`
(`FraudDiscountCalculator.php:45`):

```php
if ($fraudScore >= BCC_TRUST_FRAUD_MEDIUM) {
    $penalties['fraud_score'] = ($fraudScore / 100) * BCC_TRUST_FRAUD_SCORE_WEIGHT;  // weight 0.50
}
```

`VoteWeightCalculator::calculate()` then applies
`effective = base × discount`. At score 40 the penalty is `0.40 × 0.5 = 0.20`,
so the discount is **0.80** and the member's votes count 80%.

⚠ The `fraud_score` term alone maxes at `1.0 × 0.5 = 0.50`, and the hard-block
tier is `BCC_TRUST_FRAUD_BLOCK_THRESHOLD = 0.90`. **A fraud score alone can
never zero a vote.** It needs to combine with automation (0.90), multi-account
(0.50) or device signals.

**Rate limits** — `RateLimiter::getAdjustedLimit()` (`:270`), covering
`vote`, `endorse`, `flag`, `comment`, `message`, `login`, `api`, `watch`,
`unwatch`. The final multiplier is `min(riskMultiplier, trustMultiplier)`,
so **being verified, trusted or elite cannot offset a fraud penalty**.

---

## 4. The 60 threshold is where it changes character

Crossing `BCC_TRUST_FRAUD_HIGH` from below triggers
`dispatchFraudCrossingFanout()` → `bcc_trust_async_fraud_crossing_fanout` →
`markVotedPagesForRecalculation()` + `markEndorsedPagesForRecalculation()`.

⚠ **Every page the member ever voted on or endorsed is re-scored.** Below 60
there is no fan-out, so existing votes keep their stored weight and only
*future* votes are discounted.

Two hard gates, both at 60:

- `AttestationService::assertFraudClear()` (`:1853`) throws
  `CODE_FRAUD_BLOCKED` 403:
  > *"Your account has been flagged for unusual activity. Attestations are
  > temporarily restricted."*
- `CredibilityAtoms::fraudClear()` (`:116`) returns false, so
  `RankCredibilityGate` (`:82`) fails wherever `requireFraudClear` — **rank
  progression stops**.

---

## 5. What never happens

⚠ **No automatic suspension.** Every caller of
`UserInfoRepository::suspendUser()` is in `ModerationService`, reached only
from admin surfaces (`moderation.php`, and `handleRingAction()` at
`ModerationService.php:329`). No score, no band and no ring membership
suspends anybody. Suspension of a ring is a human pressing *"Suspend All
Members"*.

⚠ **No notification of any kind.** No email, no on-site notice, no entry the
member can see — at any score. The first thing a flagged member learns is the
403 above, and only if they try to vouch for someone, and only at 60. Below
that, their votes quietly count less and their rate limits are quietly
tighter, with nothing stating why.

⚠ **Nothing is publicly exposed.** `fraud_score`, `risk_level` and
`risk_label` appear in no REST response and no public view model.

---

## 6. Decay: the mark is semi-permanent

`UserInfoRepository::applyDailyFraudDecay()` (`:1309`):

```sql
WHERE fraud_score > 0 AND is_suspended = 0
  AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
SET fraud_score = GREATEST(FLOOR(COALESCE(peak_fraud_score,0) * 0.5), fraud_score - 1)
```

- −1 per day, and only after **30 days of no `updated_at` change**.
- ⚠ **Floor is 50% of peak.** A peak of 40 can never fall below **20** —
  which is still the Low band, still `×0.8` rate limits, permanently.
- ⚠ Any `updated_at` write resets the idle clock, so an **active member never
  decays at all**.

---

## 7. Reproducible measurements

Harness: `scripts/` is not the right home for a review artefact, so the
fixture script used for these numbers is reproduced in §7.3. It replicates
`calculateRingStrength()`, the `HAVING` gate and the endorsement rule, and
**runs no detection, writes no score and touches no user**.

### 7.1 Which shapes the rule detects

| shape | pair? | members | strength | detected |
|---|---|---|---|---|
| **LEGIT** 3 tradespeople, each used the others, 2 pages each | yes | 3 | **24.00** | **YES** |
| **LEGIT** same trio, chain only (A–B, B–C) | yes | 3 | **16.00** | **YES** |
| **LEGIT** 3 members, ONE page each way | **no** | 3 | 0.00 | no |
| **LEGIT** 6-person hall, everyone has used everyone | yes | 6 | **48.00** | **YES** |
| **LEGIT** 3 brand-new members, vote weight 0.1 | yes | 3 | 2.40 | no |
| **LEGIT** mentor + 2 apprentices | yes | 3 | **24.00** | **YES** |
| **MANIP** 3 sock puppets, 2 pages each | yes | 3 | 24.00 | YES |
| **MANIP** 12-account reciprocity scheme, dense | yes | 12 | 96.00 | YES |
| **MANIP** 20-account chain ring | yes | 20 | 16.84 | YES |
| **MANIP** 3 accounts, 5 pages each way | yes | 3 | 150.00 | YES |

Endorsement rings, same harness:

| shape | members | detected |
|---|---|---|
| **LEGIT** A→B→C→A, one vouch each | 3 | **YES** |
| **LEGIT** 5-person collective vouching in a loop | 5 | **YES** |
| **LEGIT** two people vouching for each other only | 2 | no |
| **MANIP** 3 sock puppets vouching in a loop | 3 | YES |
| **MANIP** 10 accounts vouching in a loop | 10 | YES |

⚠ **Every legitimate shape with normal vote weight is detected on identical
terms to the manipulation shapes.** The two that escape do so for reasons
unrelated to intent: too little reciprocal volume, or a vote weight so low the
account must be new. **The rule is most lenient toward the newest accounts**,
which inverts the risk profile it is meant to describe.

### 7.2 What the score actually does over time

> ⚠⚠ **SUPERSEDED.** The two scenarios below model **A1 alone**. In reality A2
> fires alongside it for any ring member, and B adds a direct +15, so the real
> analysis contribution for a trio is **+65 with +15 direct** — not 40. The
> corrected measurement is in
> `docs/ring-detection-scoring-separation-design.md` §1: a genuine trio
> reaches **HIGH (61) on pass 2**, with attestations and rank progression
> blocked and a permanent decay floor of 32.
>
> The tables are kept because the *shape* of the blend they illustrate is
> correct and still useful. The **magnitudes understate the effect**.

Scenario A — a ring flag is the member's **only** signal (analysis score 40):

| pass | stored | peak | band | rate limit | vote weight | attest? |
|---|---|---|---|---|---|---|
| 1 | **28** | 28 | Low | ×0.8 | 1.00 | allowed |
| 2 | 36 | 36 | Low | ×0.8 | 1.00 | allowed |
| 3 | 39 | 39 | Low | ×0.8 | 1.00 | allowed |
| 4 | **40** | 40 | **Medium** | **×0.6** | **0.80** | allowed |
| 5–6 | 40 | 40 | Medium | ×0.6 | 0.80 | allowed |

Scenario B — the same flag on an account already carrying **25** from
unrelated signals:

| pass | stored | peak | band | rate limit | vote weight | attest? |
|---|---|---|---|---|---|---|
| 1 | **53** | 53 | Medium | ×0.6 | 0.735 | allowed |
| 2 | **61** | 61 | **High** | **×0.4** | 0.695 | **BLOCKED** |
| 3–6 | 64→65 | 65 | High | ×0.4 | 0.675 | BLOCKED |

⚠ The difference between the two columns is the whole point. A ring flag on a
clean account is a modest, invisible, slow-moving penalty. The same flag on an
account with a little prior history **blocks attestation and rank progression
on the second day**, and retroactively re-weights every vote it ever cast.

Add mechanism B (+15) or C (+20) and Scenario A reaches High sooner too.

### 7.3 The fixture script

Kept with this document rather than in `scripts/`, because it is review
evidence and not a guard. Replicates three production behaviours, cited
inline, and performs no writes:

- the `HAVING mutual_count >= RING_MIN_SIZE` gate over the join's cross
  product — `VoteRepository::getMutualVotePairs()`;
- `strength = avgWeight × density × count > 5.0` —
  `TrustGraph::calculateRingStrength()`;
- the endorsement rule: any directed cycle ≥ `RING_MIN_SIZE`, no strength
  gate — `TrustGraph::detectEndorsementRings()`.

The script is `docs/fixtures/ring-shape-fixtures.php`; `docs/ring-policy-proposal.md` §6 says how to run it and what it covers.

---

## 8. What this trace does NOT establish

- **No production measurement.** How many real accounts are currently
  ring-flagged, and how many of those are legitimate communities, is
  **unmeasured**. That needs its own scoped authorization and has not been
  requested here. Every number above is a config constant, a code path, or a
  synthetic fixture.
- **No claim about intent.** The fixtures show the rule returns the same
  verdict for both; they do not show how often each occurs.
- **No conclusion about #312.** That PR's scoring change is described in its
  own body and is held pending policy.

---

## Related

- `docs/ring-policy-proposal.md` — the policy question this trace exists to
  inform.
- `docs/ring-detection-scoring-separation-design.md` — the implementation
  design, and the **corrected** measurements that supersede §7.2 here.
- #312 — snapshot storage, **blocked** on this policy work.
- #310, #311 — the moderation-safety fixes, which carry no scoring change.
