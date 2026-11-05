# Ring detection: a policy for reciprocity

**Status: proposal. Nothing here is implemented, no score is altered, and no
threshold is changed.** It exists to be accepted, amended or rejected.

The starting position, which this document takes as given:

> **Reciprocal endorsement structure alone is not sufficient evidence of
> fraud.**

The current rule treats it as sufficient. `docs/ring-scoring-trace.md` has the
measurements; the short version is that a trio of tradespeople who have
genuinely used each other's services produces **exactly the same detection
verdict** as three sock puppets, because the rule has no input that could tell
them apart.

⚠ This proposal deliberately does **not** offer a new threshold. Moving 3 to 4
members, or 5.0 to 20.0 strength, would change who is caught without changing
*what the system claims to know* — and a different arbitrary line is not a
policy. The proposal is about **what evidence justifies what consequence**.

---

## 1. Three tiers

### Tier 1 — Reciprocal community relationship (no consequence)

**What it is.** Mutual votes or vouches among members with no corroborating
indicator of coordination.

**Why it is the default.** This is what the product is *for*. BCC exists so
that tradespeople in a locality can vouch for each other's work. Three roofers
in one town who have each hired the others and said so is the **intended
output** of the system, not an anomaly in it. A rule that penalises it is
penalising success.

⚠ **And it is not only trios.** The pairwise contribution (A2, `vote_ring`
+25) needs **no ring at all** — two tradespeople with four mutual jobs
between them qualify. So Tier 1 has to cover *pairs*, not just groups, or the
direction is not implemented. Measured: that pair reaches **Low band with a
permanent decay floor of 12**.

**Measured examples** (trace §7.1) — all currently detected:

- 3 tradespeople, each used the others, 2 jobs each → strength **24.0**
- a chain: A–B and B–C with no A–C → strength **16.0**
- a 6-person hall where everyone has used everyone → strength **48.0**
- A vouches B, B vouches C, C vouches A, one vouch each → endorsement ring
- a mentor and two apprentices → strength **24.0**

**Proposed consequence: none.** No score, no rate limit, no weight discount,
no record on the member. Not "a small penalty" — **none**. A system that
cannot distinguish this from fraud should not act on it at all.

### Tier 2 — Suspicious pattern requiring review (visible to operators only)

**What it is.** Reciprocal structure **plus at least one** independent
indicator that the relationships may not be independent.

**Candidate corroborating indicators** (§3 discusses each):

- shared device fingerprint or IP across ≥2 members
- account registrations clustered in time
- reciprocal votes clustered in time (within minutes, repeatedly)
- the reciprocal votes are a member's *only* activity
- vouches with no accompanying context note
- a member's entire vote history confined to the ring

**Proposed consequence: a review queue entry. Nothing automatic.**

- appears on the Vote Rings tab with the corroborating indicators **named**
- **no** fraud score, **no** rate limit, **no** weight discount
- an operator decides; the decision is audited
- ⚠ the queue entry **expires** if nobody reviews it. An unreviewed suspicion
  must not harden into a penalty by default — that is how the current state
  arose.

### Tier 3 — Corroborated manipulation (penalties may be justified)

**What it is.** Reciprocal structure **plus corroboration strong enough to
stand on its own**, or an operator's reviewed finding.

Examples that would qualify:

- confirmed multi-account (same device fingerprint, already a **0.50**
  penalty via `multi_account_risk` — independent of any ring rule)
- confirmed automation (already **0.90**)
- a reviewed operator finding, recorded with a reason
- reciprocity at a scale with no plausible legitimate reading — e.g. the
  measured 12-account dense scheme (strength **96.0**) or 20-account chain

**Proposed consequence: penalties as today, but only from this tier**, and
with notification and appeal (§4).

⚠ **Note what Tier 3 implies about the ring signal's value.** Its strongest
corroborators — shared device, automation — *already* carry larger penalties
than the ring does, and they do not need the ring rule to fire. So the ring
signal's marginal contribution in Tier 3 is small. Its real value is as a
**pointer for review** (Tier 2), not as a penalty source.

---

## 2. A review-only approach for uncorroborated reciprocity

The concrete shape, for discussion:

| | today | proposed |
|---|---|---|
| detection runs | daily | unchanged |
| uncorroborated reciprocity | `+40` analysis, `+15`/`+20` direct | **recorded, scored 0** |
| corroborated | same as uncorroborated | penalties, with notice + appeal |
| operator sees | a ring list | a ring list **with corroboration named**, or "none found" |
| member sees | nothing | nothing in Tier 1–2; a notice in Tier 3 |

**What this requires that does not exist:**

1. **A place to record a ring without scoring it.** `bcc_trust_patterns`
   already stores ring detections as soft flags — it is plausibly the right
   home, and it is already written at `TrustGraph.php:739`
   (`storeVoteRingPattern`, *outside* the 30-day guard, so it writes every
   pass). Reusing it would satisfy §11.
2. **A corroboration evaluation** that reads existing signals rather than
   inventing new ones. Device fingerprint, automation score and
   `device_fraud_probability` are already computed and already on
   `user_info`.
3. **Separating "detected" from "scored"** in the detectors themselves —
   which is the one genuinely invasive change, because `+15`/`+20` are applied
   *inside* `detectVoteRings()`/`detectEndorsementRings()` rather than by a
   caller that could decide.

⚠ Point 3 is the real work and the real risk. It means the detectors stop
being write paths. That is a better design on its own terms — a detector that
scores is why a page request can currently increment a stranger's fraud score
(trace §1) — but it is not a small change and it must not be bundled with a
storage fix.

---

## 3. Corroborating evidence — what would actually help

| Indicator | Already computed? | Why it corroborates | ⚠ Caution |
|---|---|---|---|
| shared device fingerprint | **yes** — `device_fingerprint`, `multi_account_risk` | strongest available; one person, several accounts | shared household, shared work laptop, site office |
| automation score | **yes** — `automation_score` | scripted behaviour is not community | — |
| `device_fraud_probability` | **yes** | composite already in the model | — |
| registration-time clustering | no | puppets are usually created together | a crew joining after one site visit clusters too |
| vote-time clustering | no | "vote mine, I'll vote yours" happens in minutes | a group at the same jobsite on the same evening |
| reciprocity is the member's *only* activity | partly — `votes_cast`, `pages_owned` | a real tradesperson does other things | a genuinely new member has no other activity yet |
| vouch has no context note | **yes** — `context_note` | a real vouch usually says something | brevity is not dishonesty |
| geography | no | a "local" ring spanning three countries is odd | BCC members legitimately travel |

⚠ **Every one of these has a legitimate reading**, which is exactly why they
belong in a **review** tier and not an automatic-penalty tier. The pattern to
avoid is adding six weak indicators, summing them, and calling the total
evidence. That is how the current strength threshold came to look rigorous
while excluding only the newest accounts.

**The strongest practical recommendation in this document:** prefer the two
indicators that are already penalties in their own right — device sharing and
automation — and treat the ring signal as the thing that tells an operator
*where to look*.

---

## 4. Notification and appeal

Today: **nothing**. No notice at any score. The first signal a member gets is
a 403 when they try to vouch, at score ≥ 60 (trace §4–5).

That is indefensible for a trust product whose members are the subject of the
judgement. Proposed minimum, for Tier 3 only:

1. **Notice when a penalty is first applied.** What was found, in plain
   language, and that it affects how much their votes count.
2. **Say what it does not mean.** It is not a suspension and not a public
   mark. Nothing about `fraud_score` is publicly exposed today (trace §5) and
   that should stay true.
3. **An appeal route to a human.** The decision was a human's in Tier 3, so
   the appeal can be too.
4. **Notice when it is lifted** — and ⚠ note that under current decay a
   penalty is **never fully lifted**: the floor is 50% of peak and an active
   member never decays at all (trace §6). Either the floor needs an
   appeal-driven exception, or the notice has to honestly say the reduction is
   permanent.
5. **No notification in Tier 1 or 2.** Telling members they are "under
   review" for having a normal professional relationship would be worse than
   silence, and would leak the operator queue.

⚠ **Appeal implies reversibility, and reversibility does not currently
exist.** `peak_fraud_score` is monotonic by design — it is the
anti-oscillation defence (`UserInfoRepository.php:712` comments). An appeal
process that cannot lower the peak is not an appeal. This needs an explicit,
audited operator capability to reset a peak, and that is a security-relevant
addition, not a convenience.

---

## 5. Effects, by host and by account

### 5.1 Cached vs uncached hosts

The two configurations do not behave the same today — this is the #312
finding, measured.

| | host **with** `object-cache.php` | host **without** (staging) |
|---|---|---|
| ring snapshot | survives, cron's write is read | dies with the cron request |
| `FraudDetector` reads | the snapshot — **both** ring kinds | always misses → fallback → `detectVoteRings()` only → **vote rings only** |
| endorsement ring → `in_vote_ring` +40 | **yes, already** | **no** |
| `+15` / `+20` direct increments | yes, from cron | yes, from cron **and** from page-triggered recomputes |
| pattern rows | one set per cron pass | ⚠ **one per recompute**, unguarded |

So:

- **On a cached host, Tier-1 reciprocity is already being penalised through
  both rules.** A policy change there is a *reduction* in penalties for
  existing behaviour.
- **On an uncached host, only vote rings penalise via `in_vote_ring`** — but
  `+15`/`+20` still land from cron, so endorsement rings are penalised anyway,
  just by a different mechanism. The asymmetry is in mechanism **A1** only.
- ⚠ **A2 is cache-independent.** It is a plain SQL count, so the pairwise
  **+25 has been applying on every host all along**. Any statement that
  uncached hosts escape reciprocity penalties is wrong, and this document said
  something close to it before the fourth mechanism was found.
- ⚠ **#312 would unify them upward** — making uncached hosts behave like
  cached ones. Under this proposal that is the wrong direction: the policy
  question should be settled first, and if Tier 1 carries no consequence then
  #312's scoring change largely dissolves rather than needing a decision.

### 5.2 Accounts already scored

⚠ This is the part most easily overlooked, and the proposal takes a position:

- Members already carrying ring-derived score **keep it** unless something is
  done deliberately. Decay floors at 50% of peak and never completes for an
  active member.
- A policy that stops *new* Tier-1 penalties while leaving *old* ones in place
  is **inconsistent** — the same relationship is penalised or not depending on
  when it was detected.
- But a bulk historical correction is a **data repair**, explicitly out of
  scope here, and it would need to distinguish ring-derived score from other
  contributions. ⚠ `fraud_score` is a **single blended integer** — it does not
  record which signal contributed what. `fraud_analysis` stores triggers per
  analysis, so the *presence* of `in_vote_ring` is recoverable, but the
  *arithmetic contribution* is not.

So: **a clean retroactive correction may not be possible from stored data.**
That has to be known before a policy is adopted, not after. Options, none
free:

1. accept the inconsistency and document it;
2. recompute affected accounts from current signals under the new policy —
   `getEnhancedFraudScore` already recomputes, so the daily pass would
   converge accounts downward *if* the detectors stopped contributing;
   ⚠ but the `peak * 0.5` floor would hold them at half their peak forever
   unless the peak is reset;
3. an audited operator capability to reset a member's peak, used case by case
   on appeal (§4).

Option 2 plus 3 is probably the honest combination, and both are out of scope
for this document.

---

## 6. The fixture harness

Synthetic only. **Runs no detection, writes no score, touches no user.** It
replicates three production behaviours so the numbers in the trace are
reproducible:

- the `HAVING mutual_count >= BCC_TRUST_RING_MIN_SIZE` gate, over the join's
  **cross product** (`p²` rows for `p` pages each way) —
  `VoteRepository::getMutualVotePairs()`;
- `strength = avgWeight × density × count > BCC_TRUST_RING_STRENGTH_THRESHOLD`
  — `TrustGraph::calculateRingStrength()`;
- the endorsement rule: any directed cycle of ≥ `RING_MIN_SIZE`, **no**
  strength gate — `TrustGraph::detectEndorsementRings()`.

`docs/fixtures/ring-shape-fixtures.php`. Run it as `wp eval-file docs/fixtures/ring-shape-fixtures.php --skip-themes` in an **isolated** container only.
It needs only the config constants, so it has no database dependency.

Shapes it covers, and the measured verdicts, are in trace §7.1. The one line
worth repeating: **every legitimate shape with normal vote weight is detected
on identical terms to the manipulation shapes.**

⚠ **Production measurement is deliberately absent.** How many real accounts
are currently ring-flagged, and what proportion are legitimate communities, is
**unknown** and needs its own scoped authorization. No such read has been
attempted. Adopting this proposal does not require that number; **sizing the
historical-correction problem in §5.2 does.**

---

## 7. What is explicitly NOT proposed

- **No new threshold.** Not a larger minimum ring size, not a higher strength
  bar. A different arbitrary line is not a policy, and the current one already
  demonstrates the failure mode.
- **No change to the detection algorithm**, its page limits, or its cursor.
- **No widening of any penalty** — including #312's unification.
- **No trigger rename.** `in_vote_ring` is the wrong name for something that
  also matches endorsement rings, but renaming it is cosmetic beside this.
- **No historical repair.** §5.2 states the problem; it does not act on it.
- **No public exposure.** Ring membership and fraud score stay operator-only.
- **No implementation at all.** This document changes nothing.

---

## 8. The decision being asked for

1. **Is Tier 1 zero-consequence?** The central question. Everything else
   follows from it.
2. **Is review-only acceptable for Tier 2**, including that an unreviewed
   entry expires rather than hardening?
3. **Which corroborators count for Tier 3?** The recommendation is to start
   with the two that are already independent penalties — device sharing and
   automation — and add nothing until they are shown insufficient.
4. **Notification and appeal in Tier 3** — and whether an audited peak-reset
   capability is acceptable, since without it an appeal cannot restore
   anything.
5. **Existing scored accounts** — accept the inconsistency, or scope the
   correction (which first needs the production measurement in §6).

---

## Related

- `docs/ring-scoring-trace.md` — the measurements and code references this
  proposal rests on.
- `docs/ring-detection-scoring-separation-design.md` — the implementation
  design for these tiers, including the fourth (pairwise) contribution.
- #312 — snapshot storage, **blocked**. Its scoring change is the immediate
  occasion for this document.
- #310, #311 — merged moderation-safety fixes; no scoring change.
- `docs/vote-ring-persistence-proposal.md`,
  `docs/ring-member-enrichment-proposal.md`,
  `docs/vote-ring-cursor-persistence-proposal.md` — adjacent, all deferred.
