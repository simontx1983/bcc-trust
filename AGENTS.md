# Codex instructions

Before making changes, read the umbrella repository's AI guidance:

- In the full local tree, read `../../../../../docs/ai/README.md`.
- If this repository is opened standalone or in a cloud environment and that
  path is unavailable, clone or open the umbrella repository
  (`simontx1983/blue-collar-crypto-custom`) and read `docs/ai/README.md`.

Do not begin implementation until you have read that guidance.

## bcc-trust scope

This repository owns reputation, disputes, and on-chain functionality. Keep
changes within those domains and follow the established plugin architecture.

## Required workflow

1. Follow §11 first: perform and report a cross-codebase duplicate scan before
   writing or modifying code. Reuse or extend existing implementations rather
   than creating parallel logic.
2. For backend changes, follow the bcc-trust §1–§9 architecture guardrails.
3. Keep REST endpoints and view models compatible with the API contract in
   `docs/api-contract-v1.md`; verify contract changes and update the contract
   when required.
4. Run the relevant tests and checks for the change. Add focused tests for new
   or changed behavior, and report the checks run and their results.
