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

## M1 status

M1 is the move. Each sub-milestone lands in a single commit labelled
`[M1.N]` so the migration can be rewound file-by-file:

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