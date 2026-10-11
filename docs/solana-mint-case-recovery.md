# Solana mint case — the defect, and a recovery grounded in on-row evidence

**Status: the forward fix is in this PR. THE RECOVERY IS A PROPOSAL AND HAS
NOT BEEN RUN.** No stored identity has been uppercased, rewritten, merged or
deleted, and nothing here does so on its own.

---

## 1. The defect

`NftHoldingsIndexer::planBatch()` normalised the contract identity with
`strtolower()`:

```php
$contract = strtolower((string) ($e['contract_address'] ?? ''));
```

On EVM and Cosmos that agrees with the canonical rule, so it was invisible. On
**Solana** it does not. `contract_address` carries the **mint**, base58 is
case-sensitive, and a folded mint is a *different* key that no longer names
the asset.

`NftCollectionIdentifier` already said exactly this, in a docblock written
before the indexer:

> `strtolower()`. On EVM and Cosmos that happens to agree with the canonical
> rule, so nobody noticed. On Solana it does not: base58 is case-sensitive …
> a folded comparison can never match what Solana DAS actually returns.

The single correct rule existed. The write path was not using it.

Five further folds of the same column lived in `NftHoldingsRepository` — three
on write/delete paths (`upsertMany`, the 1155 delta path, `deleteByWalletAndToken`)
and two on read paths.

### How Solana reaches it

```
Helius webhook
  → HeliusWebhookEndpoint:208  SolanaFetcher::normalizeWebhookPayload()
  → HeliusWebhookEndpoint:217  NftHoldingsIndexer::ingest()
  → planBatch()                ← the fold
  → NftHoldingsRepository::ingestBatch()   ← and three more
```

`CollectionDemandService` states the route plainly: *"Solana (Helius →
NftHoldingsIndexer) only — Cosmos is read-time by design."*

## 2. ⚠ Severity — what this was, and what it was not

**Nothing was observably broken, and the honest framing matters.** The fold was
applied on **both** read and write, and `contract_address` has a
case-**insensitive** collation, so:

- holdings reads folded their needle too, and matched the folded rows;
- the ERC-1155 holder gate is reached only when `token_standard` contains
  `1155`, and Solana rows carry `SPL-NFT`, so that path is not involved;
- the one consumer that compares byte-exact under `utf8mb4_bin`,
  `GatedGroupRepository`, reads **`wp_postmeta`**, not this table.

So this is **information loss in a canonical column**, not an outage. The
stored value is not the mint, so anything that compares byte-exact — a binary
collation, an export, a provider round-trip, a future join against
`canonical_identifier` — cannot match it.

It is worth fixing for that reason, and worth **not** overstating.

## 3. The forward fix (in this PR)

- `ingest()` resolves `chain_type` **once** and passes it to `planBatch()`.
- `planBatch()` canonicalises through `NftCollectionIdentifier` instead of
  folding. The family parameter is **required**, so a caller that omits it is a
  type error rather than a silent no-op batch.
- A value the family rule refuses is **skipped**, not stored under a guessed
  key.
- The three write/delete folds in `NftHoldingsRepository` are removed.
- The two **read** folds stay, deliberately, and carry a comment saying so.

### ⚠⚠ Why the fix rewrites nothing

`contract_address` is **not** in `upsertMany`'s `ON DUPLICATE KEY UPDATE`
list:

```sql
ON DUPLICATE KEY UPDATE
   balance = VALUES(balance),
   metadata_status = CASE …,
   last_seen_block = GREATEST(…),
   confirmed_at = GREATEST(…),
   indexed_at = VALUES(indexed_at)
```

`uk_wallet_token (wallet_link_id, contract_address, token_id)` is matched under
the case-insensitive collation, so an upsert carrying the true-case mint
**matches the existing folded row and updates only the listed columns**. The
folded `contract_address` is left exactly as it was.

That is the property that makes this fix safe to ship ahead of any recovery:
new rows are correct, old rows are untouched, and no lookup changes because the
collation makes both needles match both forms.

## 4. The recovery — authoritative, on-row, no provider call

`SolanaFetcher::normalizeWebhookPayload()` sets **both** fields from the same
value:

```php
'contract_address' => $mint,
'token_id'         => $mint,
```

and `planBatch()` folded **only** `contract_address` — `token_id` was never
folded, by any path. **So every affected row still carries its own original
mint, in its own `token_id` column.**

That makes recovery self-evidencing: no Helius call, no re-index, no guess.

```sql
-- PROPOSAL. Read-only form shown; the UPDATE is deliberately not written here.
SELECT id, contract_address, token_id
  FROM wp_bcc_nft_holdings
 WHERE chain_id IN (<solana chain ids>)
   AND BINARY LOWER(token_id) = BINARY contract_address
   AND BINARY token_id <> BINARY contract_address;
```

Each matching row's correct `contract_address` **is** its `token_id`.

### Preconditions before any write

1. **Measure first** (§5). The blast radius is currently unknown on production.
2. **Partition the rows into three classes** and treat only the first as
   recoverable:
   - `LOWER(token_id) = contract_address` and they differ → **recoverable**,
     evidence on the row.
   - both already all-lowercase → **ambiguous**. A genuinely all-lowercase
     base58 mint is possible, if unlikely. ⛔ Not recoverable from this
     evidence; leave it alone.
   - they differ other than by case → **unexplained**. ⛔ Stop; the premise
     does not hold for that row and something else wrote it.
3. **Check the unique key cannot collide.** For each folded
   `contract_address`, count `DISTINCT BINARY token_id`. More than one means
   two mints folded onto one key, and restoring either would need the
   `(wallet_link_id, contract_address, token_id)` tuple re-checked first. The
   measurement reports this count.
4. **Bounded and idempotent**, with the inventory logged before the mutation,
   following `cleanup-dispute-participations.php`. A second run matches zero
   rows.
5. **Backup first.** This rewrites an identity column; it is the same class of
   operation as S9b and deserves the same treatment.

### The collation question, which is the real end state

Restoring the case does not stop two Solana mints differing only by case from
colliding in `uk_wallet_token` — the collation does that. Making
`contract_address` binary would fix it properly, and that is a **separate,
later** change, because:

- it must land in the SAME commit that removes the two deliberate **read**
  folds, or Solana reads stop matching;
- it must land AFTER the case is restored, or legacy folded rows become
  unreachable;
- and it changes an index definition, so `schema-drift-guard.php` has an
  opinion.

Order: **fix forward (this PR) → measure → restore case → make the collation
binary and drop the read folds together.**

## 5. Measurement — read-only, and what it reports

`scripts/solana-mint-case-measure.php` runs under
`wp eval-file … --skip-plugins --skip-themes`. It issues **no** `UPDATE`,
`INSERT` or `DELETE`, makes no provider call, and prints **no address** — only
counts and case *patterns* (`U`/`L`/`D`), so output can be pasted into a
ticket.

It reports: total Solana rows; rows where the two columns differ under
`BINARY`; rows whose `contract_address` is all-lowercase; rows whose `token_id`
retains uppercase; and the three classes above — **recoverable**, **ambiguous**,
**unexplained** — plus the per-key `DISTINCT BINARY token_id` collision count.

### Results so far

| environment | measured | result |
|---|---|---|
| **staging** | 2026-10-10 | 1 Solana chain (`chain_id` 20), **0 holdings rows**. The fold has written nothing here, so staging cannot demonstrate the defect or the recovery. |
| **production** | ⛔ **NOT MEASURED** | The sandbox declined the production read. **This is the gap that blocks the recovery**, not the fix. |

⚠ Production is where the rows are: `GatedGroupRepository` refers to *"the
eight production Solana gates"*, so Solana is live there. Until this is
measured, the recovery's blast radius — and whether any row falls in the
ambiguous or unexplained class — is unknown, and no recovery should be
authorised.

## 6. Related

- `app/Domain/Onchain/Support/NftCollectionIdentifier.php` — the single rule
- `app/Domain/Onchain/Repositories/GatedGroupRepository.php` — the same
  reasoning applied to `wp_postmeta`, including why that comparison is forced
  to `utf8mb4_bin`
- `tests/Unit/NftHoldingsIndexerSolanaMintCaseTest.php` — pins the forward fix
  **and** pins that `token_id` keeps its case, so the recovery evidence cannot
  be destroyed by a later change
