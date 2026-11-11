# Deferred cleanup review — `CosmwasmClassifier` and `CollectionRepository::bulkUpsert()`

**Status: RECOMMENDATION ONLY. Nothing here is implemented.** Both items were
deferred out of S8 deliberately and neither is part of S9b.

**Method.** Token-aware, receiver-aware census over `app/`, `includes/` and
`bcc-trust.php` with comments and docblocks stripped, because this tree carries
far more `{@see Foo::bar()}` references than real calls.

⚠ **A false negative to learn from.** The first pass matched only the literal
class name and reported `sanitizeExcerpt()` as test-only. It is not — it is
called twice from `CosmosFetcher` through a **variable receiver**
(`$classifier::sanitizeExcerpt(...)`). Every figure below is from the
any-receiver pass (`Foo::m`, `$x::m`, `self::m`, `static::m`, `parent::m`).
A class-name-only census under-reports, and under-reporting here means
recommending the deletion of live code.

---

## 1. `CosmwasmClassifier` — 815 lines, heavily retained

### 1.1 Retained — do not touch

| Member | Production caller |
|---|---|
| `classify()` | `Services/Validation/CosmosContractProbe.php` — **this is targeted validation**. The whole class exists for this now. |
| `errorKindFromMessage()` | `Fetchers/CosmosFetcher.php` ×2 |
| `sanitizeExcerpt()` | `Fetchers/CosmosFetcher.php` ×2, via `$classifier::` |
| `isTerminal()`, `isTransientFault()`, `isNodeCausedFault()` | internal to `classify()` |
| `CONFIRMED` / `PROBABLE` / `NOT_CW721` / `INCONCLUSIVE` / `UNREACHABLE`, the `REASON_*`, `PROBE_*` and `KIND_*` constants, `EXCERPT_MAX`, `VERSION` | read by `classify()`, `CosmosContractProbe` and `CosmosFetcher` |

⚠⚠ **`sanitizeExcerpt()` is security coverage, not a convenience.** It is the
project's designated redactor for upstream provider text, and
`AdminActionSupport` carries an explicit docblock explaining why its own
renderer is **not** a substitute: *"it is not a redactor, and `cw_last_error`
demonstrably …"*. It must not be removed, and it must not be reimplemented
elsewhere.

### 1.2 No production caller — 10 members

`requeueableClassifications()`, `allClassifications()`,
`inheritableClassifications()`, `isCw721()`, `awaitsMetadataReview()`,
`isDecisive()`, `noContractsVerdict()`, `checksumTwinVerdict()`,
`backoffSeconds()`, `isRetryable()` — plus `MAX_RETRIES`.

**Recommended for removal (8), because each is scanner-only in substance:**

| Member | Why it is scanner-only |
|---|---|
| `requeueableClassifications()` | the requeue set for a discovery pass. Nothing requeues. |
| `inheritableClassifications()` | code-family → contract classification inheritance, a property of enumeration. |
| `allClassifications()` | the full vocabulary, used to render scanner status. |
| `noContractsVerdict()` | the verdict for "enumeration found no contracts" — an enumeration outcome. |
| `checksumTwinVerdict()` | the verdict for a checksum-twin address encountered while enumerating. |
| `backoffSeconds()` + `MAX_RETRIES` | the pass retry schedule. |
| `awaitsMetadataReview()` | the metadata-review queue state, whose queue is gone. |
| `isDecisive()` | a predicate over a pass's accumulated evidence. |

**⚠ NOT recommended for removal yet (2) — the one judgement call:**

`isCw721()` and `isRetryable()` have no production caller, but
`ProbableClassificationBoundaryTest` uses them (×5 and ×6) to pin the
**`PROBABLE` boundary** — and `PROBABLE` is a verdict `classify()` still
returns to targeted validation today. Deleting the two predicates would delete
assertions about *retained* semantics.

So: either keep both, or migrate those boundary assertions onto `classify()`'s
return value **first** and only then remove. The second is tidier; the first is
free. ⚠ What must not happen is removing them and letting the boundary go
unpinned — that is losing coverage of live behaviour to tidy a dead accessor.

### 1.3 Scope

- **Code:** ~8 members, roughly 120–160 lines of `CosmwasmClassifier` plus their
  docblocks. No behaviour change: nothing calls them.
- **Tests:** `CosmwasmClassifierTest` loses ~20 cases
  (`backoffSeconds` ×6, `isRetryable` ×8, and one each for the verdict and
  vocabulary accessors). `MixedEvidenceClassificationTest` and
  `ProbableClassificationBoundaryTest` need a pass each.
- **Stubs:** `tests/Stubs/cosmwasm-discovery-stubs.php` declares doubles for
  several of these and would shrink.
- **Risk:** low, and entirely in the test edit. The production edit is the
  deletion of code with no caller under any receiver form.
- **Estimate:** 1–1.5 h as its own PR. ⚠ Not worth bundling with anything —
  it touches the one class targeted validation depends on, and it deserves a
  diff a reviewer can read in full.

---

## 2. `CollectionRepository::bulkUpsert()`

### 2.1 The finding that changes the shape of this

The deferral note said the alias invariant was pinned across **three** write
paths (`upsert`, `addManual`, `bulkUpsert`). A token walk for `INSERT INTO` /
`UPDATE` inside the repository finds **four** paths that insert:

| Method | Production caller | Alias reasoning present |
|---|---|---|
| `upsert()` | `Services/CollectionPersistBatch.php` | yes |
| `addManual()` | `Services/ManualCollectionIntakeService.php` | yes |
| `ensureExistsBatch()` | **`Services/NftHoldingsIndexer.php`** | yes — the same "right for EVM and wrong for Solana, two distinct base58 mints" reasoning, at line ~1972 |
| `bulkUpsert()` | **none** | yes |

`ensureExistsBatch()` was not named in the deferral note and is a live insert
path reached from holdings indexing. It is covered by
`CollectionCanonicalIdentityIntegrationTest`, so the invariant does hold across
every *existing* path — but the note's "three paths" framing was incomplete, and
anyone reasoning from it would have had the wrong denominator.

### 2.2 Recommendation

**Remove `bulkUpsert()`, but migrate its security assertions first — in that
order, in one PR, with the migration as the first commit.**

The reasoning that matters: the invariant must cover every write path **that
exists**, not every path that ever existed. Deleting a callerless writer removes
a path *and* its coverage simultaneously, and the remaining three paths stay
covered. That is sound — but only if the assertions `bulkUpsert` currently
carries are not unique to it.

They are not unique, but they are not free either:

- `CollectionBlankMetadataIntegrationTest` uses it ×8. These cover
  blank-metadata normalisation (`image_url = ''` and the hardcoded market
  template). `addManual` is already exercised ×13 in the same file, so the
  assertions have an obvious home.
- `CollectionCanonicalIdentityIntegrationTest` uses it ×3, alongside `upsert`
  ×7 and `addManual` ×4 — the alias/canonical-identity cases.

So the migration is: for each of the 11 references, decide whether the property
is about *that writer* or about *the table's invariant*. The invariant ones move
to `upsert`, `addManual` and `ensureExistsBatch`; writer-specific ones
(batching behaviour, multi-row semantics) go with the method.

⚠ **Do not delete an assertion because its subject is going away.** The
no-Solana-alias rule is a security property of the table, and the only honest
reason to drop one of its cases is that an equivalent case covers the same
property on a path that still exists.

### 2.3 Scope

- **Code:** `bulkUpsert()` is ~145 lines (488–634). No production caller under
  any receiver form, so no behaviour change.
- **Tests:** 11 references across 2 integration suites to triage and migrate.
  Expect the suites to get slightly *longer*, not shorter, because
  `ensureExistsBatch` currently has one canonical-identity reference and should
  have more.
- **Risk:** medium, and all of it in judging which assertions are invariant
  assertions. The code deletion is trivial; the triage is the work.
- **Estimate:** 2–2.5 h as its own PR.

### 2.4 ⚠ A separate gap worth filing, not fixing here

`ensureExistsBatch()` is a production insert path with **one** canonical-identity
test reference, against `upsert`'s seven. If the alias invariant is a security
property of the table — and the code comments say it is — then the path reached
from holdings indexing deserves the same density of coverage as the path reached
from the refresh cron. That is a coverage gap in *current* code, independent of
`bulkUpsert`, and it should be filed on its own rather than folded into a
removal PR.

---

## 3. What neither item is

Neither of these is a prerequisite for S9b, and neither should be bundled with
it. S9b is already 23 files and one destructive migration; adding a prune of the
one class targeted validation depends on, or a retriage of security assertions,
would make the destructive change harder to review for no benefit.

Recommended order, after S9b lands and is verified:

1. `bulkUpsert` migration + removal (the larger judgement call, and it unblocks
   nothing else).
2. `CosmwasmClassifier` prune of the 8 clear members.
3. `isCw721` / `isRetryable` boundary-assertion migration, or an explicit
   decision to keep both.
4. The `ensureExistsBatch` coverage gap (§2.4), filed separately.
