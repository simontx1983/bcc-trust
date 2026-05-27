# CLAUDE.md — bcc-trust

This file provides guidance to Claude Code (claude.ai/code) when
working in this plugin.

## What this plugin is

`bcc-trust` is the unified home for reputation, disputes, and on-chain
signals. It replaces three predecessor plugins:

- `bcc-trust-engine` → `app/Domain/Core/` (reputation, voting, scoring,
  fraud detection, read model)
- `bcc-disputes` → `app/Domain/Disputes/` (vote-dispute panel adjudication)
- `bcc-onchain-signals` → `app/Domain/Onchain/` (wallets, validator /
  NFT / builder / DAO signals, trust-score bonuses)

The split lives as **bounded contexts inside one plugin**, not three
sub-plugins. All three contexts were already tightly coupled via
cross-plugin ServiceLocator calls, shared DB transactions, and
shared-cron ordering dependencies — merging removes contract drift at
the boundary rather than adding coupling.

`bcc-core` is still a required sibling plugin (ServiceLocator, DB
helpers, permissions, logging). `bcc-trust` depends on it; it does not
absorb it.

## M1 history (complete)

M1 was the merge. Predecessor plugins (`bcc-trust-engine`,
`bcc-onchain-signals`, `bcc-disputes`) were deactivated and deleted in
M1.5; their code now lives under `app/Domain/{Core,Onchain,Disputes}/`.
See [MIGRATED-FROM.md](MIGRATED-FROM.md) for namespace + class renames
and pre-M1 commit hashes.

The sub-milestones below are kept for archaeology only — each was a
single commit labelled `[M1.N]` so the merge could have been rewound
file-by-file:

- **M1.0** — skeleton (this directory, plugin header, composer.json,
  phpstan.neon, CLAUDE.md). **Active plugin is still inert.**
- **M1.1** — move bcc-trust-engine to `app/Domain/Core/` with
  `BCC\Trust\Core\` namespace rewrite. Aggressive cleanup (constants,
  migrations, dead branches) applied mid-move.
- **M1.2** — move bcc-onchain-signals to `app/Domain/Onchain/` with
  static→instance conversion on service classes. CircuitBreaker
  collapsed to the single stored shape.
- **M1.3** — move bcc-disputes to `app/Domain/Disputes/` with service
  rename (`ResolveDisputeService` → `DisputeResolver`,
  `DisputeAdjudicationService` → `DisputeAdjudicator`), bridge-file
  deletion, `all_on_page` branch killed.
- **M1.4** — collapse shared utilities (3× TableRegistry, duplicate
  cron/admin_notices registration, etc.) into `app/Infrastructure/`.
  Move `scripts/arch-guardrails.sh`, `scripts/phpstan-all.sh`, and
  `scripts/intent-guard.sh` to `bcc-trust/scripts/`; update each
  script's `ALL_PLUGINS` array.
- **M1.5** — deactivate predecessors, activate bcc-trust, smoke test,
  delete predecessor directories. Post-move.

## Conventions (inherited)

> **§11 (Cross-Codebase Reuse Rule) runs BEFORE all others.** Do not
> proceed to implementation until the duplicate scan is complete. See
> §11 below and [docs/prompts/duplicate-scan.md](../../../../docs/prompts/duplicate-scan.md)
> for the required scan report shape.

Same architecture guardrails as the predecessor plugins:

1. **Repository-only DB access** — all `$wpdb` lives in
   `app/Domain/*/Repositories/` or `app/Infrastructure/`.
2. **No `SELECT *`** — explicit column lists, typically exposed as
   `private const COLUMNS`.
3. **No template queries** — templates receive data from
   controllers / services.
4. **Bounded queries** — every `SELECT` has `LIMIT`, unique-key filter,
   bounded `IN ()`, or is an aggregate.
5. **Cache invalidation via generation counters** — write-paths
   `wp_cache_incr()` the generation; read-paths key their cache by it.
6. **PHPStan level 8, no `@var` or `assert()` overrides** — fix the
   types, don't suppress them.
7. **Named parameters forbidden in cross-plugin calls** — positional
   only, to keep compiled autoloads stable under composer dump.
8. **No user-facing UI in PHP.** PHP returns arrays, DTOs, or JSON via
   REST. No `add_shortcode`, no `register_block_type`, no `templates/`
   directory, no `echo '<...'` in services or controllers. The Next.js
   app at `bcc-frontend/` is the only user-facing renderer. wp-admin
   pages and admin notices are a documented exception (paths under
   `*/Admin/`, files subscribing to `admin_notices`, methods named
   `*Notice`). Enforced by `scripts/arch-guardrails.sh` rules 6–9.

   **Admin split (locked 2026-05-27):** the admin is **two surfaces by
   role**, not one transitional state. wp-admin = *infrastructure
   cockpit* — system configuration, plugin settings, secrets-related
   metadata (never raw values), cron and queue tools, emergency
   operations, low-frequency maintenance, indexer configuration, raw
   data inspection, developer tools, schema/version management.
   Next.js `/admin/*` = *operational command center* — moderation,
   trust review, disputes, onchain monitoring, holder-group operations,
   analytics, user investigations, live operational workflows. Routing
   rule: ask whether the new surface is a *configuration / repair
   operation* (wp-admin) or a *daily workflow* (Next.js). New admin
   work lands in one of these two surfaces; do not invent a third.
9. **Load-bearing contract must be verified, not assumed.** Every
   response under `/wp-json/bcc/v1/` and `/wp-json/bcc-trust/v1/`
   conforms to `docs/api-contract-v1.md` §1.4–§1.5. The
   [Envelope](app/Domain/Core/REST/Envelope.php) class wraps every
   response automatically. PRs touching `app/Domain/*/REST/` or
   view-model builders MUST run
   `bash scripts/arch-guardrails.sh --with-contract` (which invokes
   `scripts/api-contract-check.sh` against the live site). A contract
   break is P0. Use the `/api-contract-guard` skill mid-flow — it
   wraps the same check plus a manual diff against the contract doc
   and the frontend's `lib/api/types.ts`.

### 11. Cross-Codebase Reuse Rule

Before writing or modifying any code, you MUST perform a cross-codebase
duplicate scan.

You MUST search:

1. The current repository
2. `/bcc-global-library/` (if present)
3. `docs/pattern-registry.md` (if present)
4. Any explicitly referenced external repos

Your objective is to determine whether the requested logic already
exists in any form.

If similar logic exists:

- Prefer **REUSE** or **EXTEND**.
- Do **NOT** create parallel implementations.

Creating duplicate logic across files, domains, or repositories is a
guardrail violation.

You must produce a "CROSS-CODEBASE SCAN REPORT" before writing any new
code. The report shape is documented in
[docs/prompts/duplicate-scan.md](../../../../docs/prompts/duplicate-scan.md).

**The scan must include at least one grep/search attempt and reference
concrete file paths. Vague or empty reports are invalid.** "No matches
found" without evidence of searching counts as not searching.

This rule runs **before** all others (§1–§9). Do not proceed to
implementation until the duplicate scan is complete.

## Commands

```bash
# PHP syntax check
php -l app/Domain/Core/Services/VoteService.php

# Regenerate classmap
composer dump-autoload -o

# Verify all app/ files parse cleanly
for f in $(find app -name '*.php'); do php -l "$f"; done

# PHPStan (level 8)
bash scripts/phpstan-all.sh bcc-trust

# Architecture guardrails (no raw $wpdb outside Repositories, etc.)
bash scripts/arch-guardrails.sh bcc-trust

# Intent guard (runtime invariants — trust_score formula, etc.)
bash scripts/intent-guard.sh
```

## Naming

- `$pageId` — PeepSo page (`peepso-page` CPT). Where votes and scores
  live.
- `$projectId` — on-chain project (shadow CPT). Emits signals that
  feed into page bonuses.
- Both are WP post IDs but refer to different post types — never mix.