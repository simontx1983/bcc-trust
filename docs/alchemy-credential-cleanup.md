# Removing Alchemy credentials from `bcc_chains.rpc_url`

**Status: RUN in both environments, 2026-10-01.** Staging cleaned 2026-09-30,
production cleaned 2026-10-01 after both credentials were rotated with Alchemy's
rotation action. Both environments resolve from `BCC_ALCHEMY_API_KEY` alone and hold
zero keyed endpoints in rows, options, transients or caches.

Still requires separate review and explicit operator authorization **per environment,
per run** — being documented here is not standing permission.

⚠ §5a records a **procedure change** made during the production run. Read it before
reusing this document: the single identity condition became two branches, and the one
used in production makes provider calls that the original did not.

**Why it is separate from the code change:** the code change makes credentials in
the database *unnecessary*. This procedure makes them *absent*. Doing both at once
would mean a single deployment that changes how endpoints resolve **and** destroys
the fallback that proves the new path works — with nothing to roll back to.

---

## 1. What this removes, and what it does not

Removes the credential from the `rpc_url` column of EVM chain rows, replacing it
with the keyless template `schema-chains.php` originally seeded.

| | before | after |
|---|---|---|
| `rpc_url` (Ethereum) | `https://eth-mainnet.g.alchemy.com/v2/<KEY>` | `https://eth-mainnet.g.alchemy.com/v2/` |
| `rpc_url` (Base) | `https://base-mainnet.g.alchemy.com/v2/<KEY>` | `https://base-mainnet.g.alchemy.com/v2/` |
| credential source | the row **and** `BCC_ALCHEMY_API_KEY` | `BCC_ALCHEMY_API_KEY` only |

It does **not** touch:

- `BCC_ALCHEMY_API_KEY` in `wp-config.php` — this procedure depends on it
- Solana, Cosmos or NEAR rows — no Alchemy credential is involved
- Avalanche or BSC — public RPCs, no credential to remove
- any chain not named in the authorization for that run

## 2. ⚠⚠⚠ Why rotation is required, and why it comes FIRST

**Removing a secret from a live table does not un-leak it.** By the time this runs,
the value has been in:

- every automated database backup taken since it was set
- every `wp db export` and every migration dump
- the binary log, if enabled
- any read-only MySQL session opened for debugging (including several in this
  project's own history)
- rendered admin HTML — this is how the 2026-09-30 leak happened

So the sequence is **rotate, then clean** — not clean, then hope. Cleaning a key
that is still valid removes the copy you can see and leaves every copy you cannot.

Recommended order per environment:

1. rotate the credential at Alchemy. ⚠ Alchemy's **rotation action invalidates the
   superseded key as part of the rotation** — when that action is used there is no
   separate revocation step afterwards, and step 5 is a confirmation rather than a
   change. If the key was instead replaced by minting a second one, the old one is
   still live and must be revoked explicitly.
2. set `BCC_ALCHEMY_API_KEY` to the replacement in that environment's `wp-config.php`
   — ⚠ for staging that is `<webroot>/stage/wp-config.php`, **not** the parent
3. verify (§4) that resolution works from the constant alone
4. run the cleanup (§5) to remove the superseded key from the rows
5. confirm the superseded key no longer authenticates (§6)

What ends the exposure is the superseded key ceasing to authenticate — step 1 with
the rotation action, or step 5 with an explicit revoke. Steps 2–4 make the
environment keep working across it.

### ⚠⚠ Clean immediately after rotating — there is an outage window

`AlchemyCredential::rpcUrlFor()` **prefers a keyed chain row over the constant** (the
transitional branch in §1). So between step 1 and step 4 the rows still hold the
superseded credential and the live path uses it. Once the rotation has invalidated
that key, every Alchemy call on those chains fails until the cleanup runs.

This is what happened in production on 2026-10-01: after rotation the row endpoints
answered **HTTP 401** on both Ethereum and Base while the constant answered **200**,
and the cleanup was the remedy, not a tidy-up. Do not leave steps 1–4 spread across
sessions, and if the gap cannot be avoided, expect and monitor the failures.

⚠ When diagnosing in that window, probe the **row endpoint and the constant endpoint
separately**. A single blended check reads as "rotation broke the site" when the stale
row is the only cause.

## 3. Preconditions — all of them, or stop

Refuse to proceed unless **every** one holds:

- [ ] PR #269 and this PR are **deployed** to the environment being cleaned.
      Cleaning rows on a build that still reads them from the row breaks the
      environment immediately.
- [ ] `BCC_ALCHEMY_API_KEY` is set **in that environment's** `wp-config.php` and
      is **non-empty after trimming**. ⚠ `defined()` is not sufficient —
      `define('BCC_ALCHEMY_API_KEY', '')` is defined and useless.
- [ ] `AlchemyCredential::isConfigured()` returns **true** when evaluated in that
      environment.
- [ ] For every chain to be cleaned, `AlchemyCredential::rpcUrlFor($chain)`
      resolves **while the row is still credentialed**, and the resolved value is
      built from the constant — not merely returned from the row.
      ⚠ This is the load-bearing check. `rpcUrlFor()` prefers the row, so a
      passing resolution proves nothing on its own; §4 is how to distinguish them.
- [ ] A database backup exists and has been **verified restorable**.
- [ ] The operator has authorized **this environment** and **this chain list**
      explicitly. Staging authorization is not production authorization.

## 4. ⚠⚠ Proving the constant is what resolves, not the row

`rpcUrlFor()` returns the row when the row is complete, so "it resolves" is not
evidence. Compare the resolved value against the row:

```php
// Read-only. Prints no credential — booleans and a hostname only.
$chain    = ChainRepository::findBySlug('ethereum');
$resolved = AlchemyCredential::rpcUrlFor($chain);
$row      = trim((string) $chain->rpc_url);

printf(
    "chain=%s row_is_keyed=%s resolves=%s from_row=%s host=%s\n",
    $chain->slug,
    AlchemyEndpoint::isConfigured($row) ? 'yes' : 'no',
    $resolved !== null ? 'yes' : 'no',
    ($resolved !== null && $resolved === $row) ? 'YES — CONSTANT NOT PROVEN' : 'no',
    EndpointDescriptor::host($resolved)
);
```

`from_row=YES` means the row is masking the constant and the precondition is
**not** met. To prove the constant independently, evaluate a chain object whose
`rpc_url` has been blanked **in memory only**:

```php
$probe = clone $chain;
$probe->rpc_url = '';                       // in memory; the row is untouched
$fromConstant = AlchemyCredential::rpcUrlFor($probe);

printf("constant_resolves=%s host=%s\n",
    $fromConstant !== null ? 'yes' : 'no',
    EndpointDescriptor::host($fromConstant)
);
```

`constant_resolves=yes` is the precondition. Anything else: **stop**.

⚠ Never print `$resolved`, `$row`, `$fromConstant`, or any substring, length or
hash of them. `EndpointDescriptor::host()` and booleans are the whole reporting
vocabulary, and `scripts/endpoint-exposure-guard.php` enforces it in committed
code.

## 5. The cleanup itself

⚠ **Refuse to overwrite anything that is not recognisably the designated
credential in a recognisably Alchemy row.** The row may have been hand-configured
with a different provider, a different key, or a proxy; overwriting that would be
this procedure causing the outage it exists to avoid.

Per chain, in this order:

1. **Re-read** the row inside the same transaction you will write in. A value read
   minutes earlier may have been changed by an operator in the admin UI.
2. **Refuse** unless *all* of these STRUCTURAL guards hold:
   - `chain_type === 'evm'`
   - `chain_id_hex` is in `AlchemyCredential`'s network map
   - the row's host is exactly `<network>.g.alchemy.com` for that chain's mapped
     network — not a suffix match, not a subdomain of it
   - the row's path is exactly `/v2/<segment>` with a non-empty segment

   …**and** the row satisfies **one** of the two IDENTITY branches in §5a.
3. **Write** the keyless template for that network:
   `https://<network>.g.alchemy.com/v2/`
4. **Re-read and assert** the row now equals that template and
   `AlchemyEndpoint::isConfigured($row) === false`.
5. **Assert** `AlchemyCredential::rpcUrlFor($chain) !== null` — the chain must
   still resolve, now necessarily from the constant.

If step 2 refuses, **record the chain as skipped and continue**. A skip is a
correct outcome: it means a human configured something this procedure does not
recognise, and a human should look at it.

⚠ Step 5 is not optional. Without it a cleanup can leave a chain that resolves to
nothing, and the next indexer tick reports UNAVAILABLE across the board with no
obvious cause.

## 5a. The two identity branches

⚠ **PROCEDURE CHANGE, 2026-10-01.** This section replaces a single condition — "the
`<segment>` is `hash_equals()`-equal to the designated credential for this run" —
with two alternative branches. The original condition is retained unchanged as
branch **A**.

The change was forced by the production run. §2 prescribes **rotate → clean**, and
after a real rotation the row holds the **superseded** credential while the constant
holds the replacement, so they are *unequal by definition*. Under branch A alone both
Ethereum and Base were correctly skipped and nothing was cleaned — the guard blocking
the removal it exists to enable. Branch **B** covers that case.

A row is eligible if the structural guards in §5 step 2 hold **and** either:

### Branch A — the row IS the designated credential

`hash_equals($designated, $row_rpc_url)` is true, where `$designated` is what
`AlchemyCredential::rpcUrlFor()` returns for that chain with `rpc_url` blanked **in
memory**.

Use when cleaning without a rotation, or when the rotation installed the same value
in both places. Decides from local state only, makes no provider call, and is the
branch to prefer when it applies.

### Branch B — the row's credential is rejected and the designated one works

Both conditions, each proven by **one bounded `eth_chainId` call**:

| | call | required result |
|---|---|---|
| the row's own endpoint | `eth_chainId` | HTTP **401 or 403** |
| the designated endpoint | `eth_chainId` | HTTP **200** *and* `result` equals the chain's `chain_id_hex` |

Use after a rotation, when branch A cannot match because the values differ by design.

**What branch B rules out.** A hand-configured endpoint that still works answers 200
and is skipped. A broken or mis-installed replacement fails the second condition, so
the row is skipped rather than cleaned into an unusable state. Both conditions must
hold for the same chain in the same run.

**What branch B costs, and how it differs from A.** It is not a strict improvement on
branch A, and should not be described as one — it trades differently:

- It **makes network calls** (2 per candidate row). Branch A makes none. A provider
  outage, a rate limit, or a transient 5xx makes branch B *refuse* — correct, but it
  means the procedure can be blocked by conditions unrelated to the rows.
- It reasons from **observed provider behaviour**, not from a local value match. A
  credential that is rejected for a reason other than being superseded — suspended
  account, network disabled on the plan, IP restriction — also answers 401/403. Such
  a row would be treated as stale and cleaned. That is acceptable *only* because the
  second condition proves the replacement works for that same chain, so the chain
  keeps functioning either way.
- It cannot distinguish "superseded" from "revoked" from "never valid". It only
  establishes "this row's credential does not authenticate, and the configured
  replacement does."

⚠ Do not weaken either branch to "any keyed endpoint on the mapped host". That drops
every identity check and would overwrite a working hand-configured endpoint.

⚠ Record which branch fired, per row, in the run output. The two have different
evidentiary weight and a reviewer needs to know which one was relied on.

### Rollback

Restore the row from the backup, or re-set the credentialed URL through the admin UI.

⚠ Rollback restores the **superseded** key, so once that key no longer authenticates
— which, with Alchemy's rotation action, is already true before the cleanup runs —
**rollback does not restore service.** After a rotation the recovery path is not the
row but the constant: confirm `BCC_ALCHEMY_API_KEY` holds the replacement and that
`AlchemyCredential::rpcUrlFor()` resolves with the row blanked in memory (§4).

⚠ Rolling back a row to a dead credential is actively harmful, because the resolver
prefers a keyed row — it would reintroduce the outage described in §2.

No stored secret is needed to undo a cleanup performed under **branch A**: the
constant regenerates the exact prior row value. Under **branch B** the prior value is
the superseded credential and is deliberately not recoverable from the constant;
restore it from the backup only if there is a reason to, which after a rotation there
normally is not.

## 6. Verification after the run

- [ ] Every targeted chain: `rpc_url` is the keyless template, and
      `AlchemyEndpoint::isConfigured($row)` is false.
- [ ] Every targeted chain: `AlchemyCredential::rpcUrlFor($chain)` is non-null.
- [ ] `NftProviderReadiness::isReady($chain, DRIVER_ALCHEMY_NFT)` is true for
      Ethereum and Base.
- [ ] Polygon, Arbitrum and Optimism are **still not ready** — cleaning rows must
      not launch a chain. ⚠ If one of those was hand-configured and gets cleaned,
      it will stop working, because it is not in the network map. Decide that
      deliberately before including it in the chain list.
- [ ] `grep` the environment's `error_log` for the removed key: **zero** matches,
      reported as a count only.
- [ ] One bounded live call per launch chain succeeds (an existing read path — do
      **not** create a collection or call `addManual()`).
- [ ] The superseded key no longer authenticates. If the rotation was performed with
      Alchemy's **rotation action**, that action invalidates the superseded key, so
      there is **no separate revocation step** — the 401/403 from branch B is the
      evidence. Confirm it rather than assuming it.

### ⚠⚠ Cache verification when an external object cache is present

Check first:

```php
wp_using_ext_object_cache()   // true in production as of 2026-10-01
```

When this is **true**, transients live in the object cache, **not** in `wp_options`.
That changes how invalidation must be verified, and the obvious check is wrong:

- ⛔ **Do not** judge invalidation by reading the `_transient_<key>` **option row**.
  After `delete_transient()` the option row can still be present, and a run will
  report "invalidated = false" while the live cache is in fact empty. The two are
  different stores.
- ✅ Judge by `get_transient('bcc_active_chains')` — the API that reads the store
  actually in use — and by **re-scanning the contents after a forced rebuild**
  (`delete_transient()` then `ChainRepository::getActive()`).
- ✅ Scan **both** layers for a keyed endpoint and require zero in each: the value
  from `get_transient()`, and the `_transient_bcc_active_chains` option row if one
  exists.

⚠ `_transient_bcc_active_chains` may exist in `wp_options` as a **stale artifact
predating the object cache**. In production on 2026-10-01 its timeout had expired
roughly 27 days earlier, yet the row still carried all 21 chain columns including
`rpc_url`. It was credential-free after the cleanup and was left in place. Treat such
a row as a surface to scan, not as the live cache, and do not infer freshness from its
presence.

⚠ The cache mirrors whatever `rpc_url` holds, so it is credential-bearing for exactly
as long as the rows are. It is cleaned by cleaning the rows and rebuilding — never by
editing the cache.

## 7. Known limitations

- A credential already in a backup, a dump or a binary log is **not** removed by
  this. Rotation is the only remedy; this procedure reduces future exposure.
- No automated script is committed for this. It is deliberately manual and
  per-environment: a committed script that rewrites live credential columns is a
  standing hazard, and the refusal conditions in §5 need a human reading the
  skips.
- `rpcUrlFor()` keeps preferring a complete row indefinitely. Nothing forces this
  cleanup, and an uncleaned environment stays functional and stays exposed. If it
  should be forced, that is a follow-up: drop the row-preference branch **after**
  every environment is clean, with its own tests.
- The `/v2/` keyless template is itself rejected by
  `EvmFetcher::jsonRpcUrl()` as a guaranteed 401, so a cleaned chain whose
  constant is later unset loses `eth_call` as well as the Alchemy paths. That is
  correct — it cannot authenticate either way — but it means the constant becomes
  load-bearing for more than NFT discovery once the rows are clean. **Both
  environments are now in that state.**
- **Branch B depends on the provider being reachable.** If Alchemy is down or rate
  limiting, a post-rotation cleanup cannot proceed, because neither of branch B's two
  conditions can be established. There is no offline fallback for that case: branch A
  cannot match after a rotation, and relaxing the guards is not an option. Wait and
  retry.
- ⚠ **This file IS deployed to both hosts.** The plugin rsync is not scoped to code:
  `docs/alchemy-credential-cleanup.md` is present under the deployed plugin directory
  on staging and production (verified 2026-10-01). It contains no secrets and that is
  harmless, but it means operational notes written here are world-readable if the
  directory is ever served, and a host copy can be **stale relative to the repo**.
  Treat the repository as the source of truth, and never put a credential, a hostname
  worth hiding, or host-specific detail in it that you would not deploy.

Refs #267
