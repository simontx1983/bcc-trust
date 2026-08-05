# Rank Phase 9 — calibration simulation harness

Drives the REAL production Rank calculators with synthetic member
histories to verify the approved plan's calibration targets. Pure PHP:
**no WordPress, no database, no staging mutation, no network.** Not
wired into CI (same status as `scripts/perf/`) — run manually at
calibration checkpoints.

## Running

```bash
cd app/public/wp-content/plugins/bcc-trust
php scripts/calibration/run.php                 # full suite (~10 s)
php scripts/calibration/run.php --scenario=X    # X ∈ honest|rings|weights|quorum|decay|helping
```

Outputs one `results/<scenario>.json` per scenario (raw distributions +
stats + PASS/FAIL per target) and a human `results/summary.txt`.
Everything is deterministic — `mt_srand` seeds derive from fixed labels,
timestamps derive from the fixed epoch (day 0 = 2026-01-01, never
`now()`) — so committed results reproduce byte-identically on the same
PHP major (Mersenne Twister is stable since PHP 7.1).

## What is REAL vs what is MIRRORED

The design intent held: the score/weight math is pure and config-fed
and runs verbatim. The *orchestration* around it (promotion engine,
poll service, ingestor) is repository/transaction-bound and cannot run
without WP — those pieces are mirrored, as small as possible, and
labeled below. **Any drift between a mirror and its production source
is a harness bug; the production file is always the source of truth.**

### Executed verbatim (production code)

| Piece | Source |
|---|---|
| Config load + validation | `RankScoringConfig::fromDefaultFile()` over the real `includes/config/rank-scoring.php` |
| PROPOSED-RESTORE config (`helping` scenario) | the shipped config array with only the three helping gates overridden, re-validated through the REAL `RankScoringConfig::fromArray()` — the shipped file is never edited (`calib_restore_config()` in `bootstrap.php`) |
| Rank Score math (share caps, category ceilings, time credit, decay, floor) | `RankScoreCalculator::calculate()` / `decayFor()` |
| §4.4 event-cap allocation | `RankEvidenceIngestor::allocateWithinCap()` (public static, pure) |
| Vote weight (maturity, rank/trust multipliers, ceiling clamp) | `VoteWeightCalculator::calculate()` (+ `FraudDiscountCalculator::compute()` with clean signals; constants loaded from the real `includes/config/fraud-detection.php`) |

### Mirrored (documented, drift = harness bug)

| Mirror | Production source | Why mirrored |
|---|---|---|
| Promotion requirement predicate (thresholds 40/80, category minimums, diversity/recognizer/outcome minimums, trust windows 45-of-60 / 120-of-180) | `RankPromotionEngine::assess()` `$satisfied[]` block + its diversity/headcount row pass | `assess()` reads five repositories + `Permissions` — not reachable without WP/DB. Score inside the mirror still comes from the REAL calculator. Baseline: no suspensions, no misconduct findings. → `lib/RequirementsMirror.php` |
| Poll arithmetic: dual quorum (10 voters AND 7.5 post-cap weight), 60% majority (exactly 60.00% passes), suspected cap `min(W_susp, 0.25 × W_nc)` pro-rata | `PollService::decideOutcome()` / `evaluatePoll()` (private) | Private + repository-bound. Self-checked against hand-computed PollService semantics on every run. → `lib/PollMath.php` |
| Ingest routing (`sources`/`points` maps, `POINT_KEY_BY_SOURCE`) + R3 zero-credit for confirmed-cluster non-representatives | `RankEvidenceIngestor::ingest()` + `IndependenceResolver::isZeroCredit()` | Transaction/repository-bound. Routing DATA and the cap allocator are the real ones; only the plumbing is mirrored. `POINT_KEY_BY_SOURCE` is a verbatim copy of a private const. → `lib/MemberSimulator.php` |
| Tier-days window counting | `TierDaysRepository::countQualifyingDays()` | Data-plane table; the harness models one row per completed day and counts over `[day − window, day − 1]`. → `RequirementsMirror::modeledWindows()` |
| Day-loop state machine (who acts when) | — | Harness-only by design (the brief's day-stepped simulation). |

## Modeling assumptions (load-bearing — read before trusting numbers)

1. **Trust tiers are modeled, not computed.** 80% of members are
   neutral+ from day 0 and trusted+ from day 90; 20% sit at caution and
   never qualify. Ring members are all tier-qualified
   (adversary-favourable). The 90-day trusted onset directly sets the
   earliest-Veteran floor (90 + 120-of-180 = day 210).
2. **The brief's activity profiles carry NO helping-category and NO
   outcomes-category actions**, yet §4.3 minimums require both — with
   the profiles as literally specified, *nobody can ever promote*.
   The harness therefore adds ASSUMED rates (marked in
   `CalibProfiles::HONEST`): attestation-received rates for non-heavy
   profiles, outcomes rates for regular/heavy, and the two helping
   streams described next. Every promotion-day number downstream is
   sensitive to these rates.
3. **The two helping emitters are modeled after the REAL merged
   emitters** (`HelpfulMarkEvidenceListener` + `StewardshipSweepService`),
   not as generic helping points:
   - *helpful_mark* — a member EARNS helping when OTHER credible members
     mark THEIR content helpful. The mark credits the content author and
     is keyed by the MARKER as the §9.3 `helping_recipient` relationship
     id, so a single marker is capped at 0.10 × 25 = **2.5** of the
     recipient's helping (5 marks × 0.5). Filling helping is therefore
     DISTINCT-MARKER-bounded: `distinct_markers` (ASSUMED) sizes each
     member's credible-marker pool and `helpful_received_per_month`
     (ASSUMED) is the arrival rate, spread uniformly across that pool.
     Reaching helping 25 needs ≥10 distinct markers; Veteran 15 needs
     ≥6; Journeyman 7.5 needs ≥3. Non-credible marks mint no evidence in
     production, so the harness only emits credible ones.
   - *stewardship* — a member who OWNS an active User-kind community
     earns one `stewardship` event per ISO week (→ contribution +
     helping, relationship id 0). All stewardship-helping shares the
     single identity-0 bucket, capped at 2.5. `owner_period` (ASSUMED)
     makes members at `index % owner_period == 0` owners (regular ≈ 33%,
     heavy 50%); stewardship starts at
     `STEWARDSHIP_ACTIVATION_DAY` (14). Stewardship is the ONLY 5th
     contribution source type (post, comment, review, report_upheld,
     stewardship), so **Veteran contribution-diversity-5 is reachable
     ONLY by community owners** — a load-bearing consequence the
     `helping` scenario tests directly.
4. Trust scores for vote weights are tier-consistent draws
   (caution 30–44, neutral 45–59, trusted 60–80); fraud signals are
   clean (discount 1.0); every member's apprentice epoch is day 0.
5. Cadence model: actions occur on login days with
   `monthly_rate / logins_per_month` probability; attestations received
   arrive independently (`rate / 30` per day); counterparties are drawn
   uniformly from the 800-member pool.

## Scenarios and targets

| # | Scenario | Targets |
|---|---|---|
| 1 | `honest` — 200 members × 4 profiles, day 0–400 | median first-Journeyman 75–110d; earliest Journeyman ≥ 45d (trust-window floor); Veteran counts by day 120/180/365 + earliest (plan expectation: first Veteran months 10–16) |
| 2 | `rings` — R ∈ {3,5,10}, mutual attest/help, no outside recognition | CONFIRMED: non-reps earn exactly 0.0 recognition (and 0.0 of every ledgered evidence category), 100% fail promotion at day 180, ring combined ledgered evidence ≤ one honest member's equivalent. SUSPECTED: R=3 blocked by the recognizer minimum; R=5/10 reported honestly |
| 3 | `weights` — real VoteWeightCalculator over the cohort at days 90/180/365 | max ≤ 1.75 with zero above; Gini ≤ 0.65; histogram |
| 4 | `quorum` — pools E ∈ {5..50}, top-10 sum + 500-trial participation draws (30%/50%) | informational: locate where binding outcomes become feasible (plan expects early Inconclusive dominance) |
| 5 | `decay` — heavy member inactive from day 200 (run to day 800); 30% evidence reversal at day 150 per profile | real decay == config arithmetic (grace 365d, 30d step, 1pt → nothing decays inside day 400); score floors at 0; recovery vs the 90-day grace |
| 6 | `helping` — the honest cohort under SHIPPED vs PROPOSED-RESTORE config (helping min 7.5/15, veteran-diversity 5), driven by the two modeled helping emitters | SHIPPED baseline still promotes; helping ≥7.5/≥15 reachable for regular/heavy; diversity-5 reached ONLY by owners; RESTORE still reaches Veteran (not over-gated); Journeyman median shift bounded; ANTI-FARM: <10 distinct markers can never fill helping and volume can't beat the 0.10 cap |

Note on the "combined ≤ one honest member" ring assertion: it compares
**ledgered evidence** (contribution + helping + recognition + outcomes).
The `time` category is derived from each member's own logins and is
never ledgered, so each ring member still accrues time credit — the
collapse claim is about evidence, and the results record that nuance.

## Bounded-test claims (what this run exercised vs did NOT)

Exercised: the real score/weight/cap/decay arithmetic over ~450k
synthetic ledger rows; the mirrored promotion predicate; ring collapse
under confirmed findings; quorum arithmetic over modeled weight
distributions.

NOT exercised — do not extrapolate to these regimes:

- the real ingest path (hooks, transactions, idempotency, UNIQUE
  constraints), repositories, cron scheduling, or any DB behavior;
- the real promotion ENGINE state machine (grace start/expiry writes,
  demotion sweeps, notifications) — only its requirement predicate;
- real trust-tier dynamics (tier days are modeled, not computed from
  the trust engine), misconduct findings, suspensions, fraud signals;
- real human behavior — every cadence is a stationary Bernoulli
  process; bursts, churn, seasonality, and strategic behavior are out
  of scope;
- poll lifecycle (windows, recasts, withdraws, close sweep concurrency)
  — only the close-time arithmetic.

Findings from a run are calibration EVIDENCE, not proof of production
behavior.
