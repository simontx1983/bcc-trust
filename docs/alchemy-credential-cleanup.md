# Removing Alchemy credentials from `bcc_chains.rpc_url`

**Status:** procedure only — NOT run. Requires separate review and explicit
operator authorization per environment.

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

## 2. ⚠⚠⚠ Why the credential must be rotated afterwards, regardless

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

1. mint a **new** Alchemy key
2. set `BCC_ALCHEMY_API_KEY` to the new key in `wp-config.php`
3. verify (§4) that resolution now works from the constant alone
4. run the cleanup (§5) to remove the **old** key from the rows
5. revoke the old key at Alchemy

Step 5 is what actually ends the exposure. Steps 1–4 make it safe to take.

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
2. **Refuse** unless *all* of:
   - `chain_type === 'evm'`
   - `chain_id_hex` is in `AlchemyCredential`'s network map
   - the row's host is exactly `<network>.g.alchemy.com` for that chain's mapped
     network — not a suffix match, not a subdomain of it
   - the row's path is exactly `/v2/<segment>` with a non-empty segment
   - the `<segment>` is `hash_equals()`-equal to the **designated** credential for
     this run
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

### Rollback

Restore the row from the backup, or re-set the credentialed URL through the admin
UI. Rollback restores the **old** key, so if it has already been revoked (§2 step
5) rollback does not restore service — which is why revocation is last.

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
- [ ] The old key is **revoked** at Alchemy.

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
  load-bearing for more than NFT discovery once the rows are clean.

Refs #267
