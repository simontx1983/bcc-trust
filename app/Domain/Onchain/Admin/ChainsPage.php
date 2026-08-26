<?php

namespace BCC\Trust\Onchain\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;

/**
 * Admin page: Chains
 *
 * Two sub-tabs:
 *  - Validators: per-chain validator refresh (existing)
 *  - NFT Collections: per-chain collection refresh (new)
 *
 * @phpstan-import-type ChainRow from ChainRepository
 * @phpstan-import-type ValidatorCountByChain from ValidatorRepository
 */
class ChainsPage
{
    const PAGE_SLUG = 'bcc-onchain-chains';

    public static function register_page(): void
    {
        // Audit follow-up: relocated under BCC System alongside the
        // other onchain admin pages. Page slug unchanged.
        add_submenu_page(
            'bcc-system-health',
            'Chains',
            'Chains',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    /** admin-post action for the Identity editor (Batch 1: PRG). */
    public const ACTION_IDENTITY_SAVE = 'bcc_chain_identity_save';

    /**
     * The NFT Discovery sub-tab.
     *
     * Named for the CAPABILITY, not for one engine. NFT discovery is not a
     * Cosmos-only concern — the platform already indexes EVM NFTs through a
     * separate worker, Solana through Helius, and other standards may follow.
     * This page is the place where per-chain NFT-discovery configuration
     * lives, and it is organised into engine-scoped sections so a second
     * engine can be added beside the first without rewriting it.
     *
     * VC-B2 ships exactly one section — CosmWasm / CW-721 — because that is
     * the only engine whose per-chain opt-in is operator-configurable today.
     * No placeholder sections, no empty buttons, no invented status.
     */
    public const SUBTAB_NFT_DISCOVERY = 'nft-discovery';

    /**
     * Per-chain CosmWasm/CW-721 discovery opt-in, one route per direction.
     *
     * Two routes rather than one toggle, for the reason the code has argued
     * since this control was written: a toggle takes its direction from the
     * state the page had at RENDER time, so a stale tab silently applies the
     * opposite of what the operator is looking at. The route names the
     * direction, and the nonce is bound to the direction AND the chain.
     *
     * The names say `cw_discovery` and not `nft_discovery`: they write
     * `wp_bcc_chains.cosmwasm_nft_discovery_enabled`, which governs the
     * CosmWasm engine only. A chain-neutral route name over an
     * engine-specific column would be a lie the audit log then inherits.
     */
    public const ACTION_CW_DISCOVERY_ENABLE  = 'bcc_chain_cw_discovery_enable';
    public const ACTION_CW_DISCOVERY_DISABLE = 'bcc_chain_cw_discovery_disable';

    /**
     * VC-B3b: the four CosmWasm scanner OPERATIONS.
     *
     * These are four different things, not four spellings of one button:
     *
     *   Pause    operational hold      writes checkpoint state
     *   Resume   clears the hold       derives state from durable progress
     *   Backfill immediate bounded work THE ONLY provider-consuming control
     *   Retry    requeues failed work   clears a wait, contacts nothing
     *
     * Each gets its own route and its own direction-and-chain-scoped nonce,
     * so a Pause nonce can no longer authorise a provider-consuming
     * Backfill on a different chain — which is exactly what the single
     * shared page nonce allowed.
     */
    public const ACTION_CW_PAUSE    = 'bcc_chain_cw_pause';
    public const ACTION_CW_RESUME   = 'bcc_chain_cw_resume';
    public const ACTION_CW_BACKFILL = 'bcc_chain_cw_backfill';
    public const ACTION_CW_RETRY    = 'bcc_chain_cw_retry';

    /**
     * Retry bound, relocated from VerifyCollectionsPage unchanged.
     *
     * Up to 100 unresolved code families AND up to 100 unresolved
     * contracts — two separate limits, not a shared budget.
     */
    private const FORCE_RETRY_LIMIT = 100;

    /**
     * The admin backfill slice budget, relocated unchanged.
     *
     * Deliberately SMALLER than a cron tick because this one executes
     * inside an admin page load. PR #200 added downstream reserve floors
     * INSIDE the worker; this handler supplies the budget object and does
     * not reserve against it — the worker owns that sequence.
     */
    private const ADMIN_BACKFILL_REQUESTS = 20;
    private const ADMIN_BACKFILL_SECONDS  = 8;

    public static function register_ajax(): void
    {
        add_action('wp_ajax_bcc_chain_refresh', [self::class, 'ajax_refresh']);
    }

    /**
     * Batch 1: the Identity editor posted back to the page and rendered its
     * notice inline, so a refresh re-triggered the browser's resubmit dialog
     * and could re-run the write. It now goes through admin-post.php and
     * redirects, and the notice is rebuilt from the redirect args.
     */
    public static function register_actions(): void
    {
        add_action('admin_post_' . self::ACTION_IDENTITY_SAVE, [self::class, 'handle_identity_save']);

        // VC-B2: per-chain CosmWasm discovery opt-in moved here from the
        // Verify Collections scanner panel, where both directions shared one
        // page-wide nonce with a provider-consuming backfill.
        add_action(
            'admin_post_' . self::ACTION_CW_DISCOVERY_ENABLE,
            [self::class, 'handle_cw_discovery_enable']
        );
        add_action(
            'admin_post_' . self::ACTION_CW_DISCOVERY_DISABLE,
            [self::class, 'handle_cw_discovery_disable']
        );

        // VC-B3b: the four scanner operations, moved off the Verify
        // Collections shared-nonce dispatcher.
        add_action('admin_post_' . self::ACTION_CW_PAUSE,    [self::class, 'handle_cw_pause']);
        add_action('admin_post_' . self::ACTION_CW_RESUME,   [self::class, 'handle_cw_resume']);
        add_action('admin_post_' . self::ACTION_CW_BACKFILL, [self::class, 'handle_cw_backfill']);
        add_action('admin_post_' . self::ACTION_CW_RETRY,    [self::class, 'handle_cw_retry']);
    }

    // ── VC-B3b: CosmWasm scanner operations ─────────────────────────────

    public static function handle_cw_pause(): void
    {
        self::handle_cw_operation(self::ACTION_CW_PAUSE);
    }

    public static function handle_cw_resume(): void
    {
        self::handle_cw_operation(self::ACTION_CW_RESUME);
    }

    public static function handle_cw_backfill(): void
    {
        self::handle_cw_operation(self::ACTION_CW_BACKFILL);
    }

    public static function handle_cw_retry(): void
    {
        self::handle_cw_operation(self::ACTION_CW_RETRY);
    }

    /**
     * Shared request boundary for all four operations.
     *
     * One method, not four copies: the ONLY thing that differs at the
     * boundary is which route the nonce is bound to and which domain call
     * runs. Duplicating the gate order four times is how one copy later
     * drifts out of step with the others — and the copy that drifts would
     * most likely be Backfill, the one that spends provider budget.
     *
     * Order: refusal trace (server-known only) → capability → POST-only →
     * chain-id shape → direction-and-chain nonce → authoritative lookup →
     * operation-specific gates → the operation, at most once → verify where
     * the contract allows → at most one durable row → PRG.
     */
    private static function handle_cw_operation(string $route): never
    {
        // The trace carries NO request input. `chain_id` is
        // attacker-controlled and unvalidated here; echoing it would let an
        // unauthenticated caller write our log and probe which chains exist.
        if (!current_user_can('manage_options')) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] CosmWasm scanner operation refused', [
                'action'    => 'cosmwasm_scanner_operation_denied',
                'operation' => self::cw_operation_slug($route),
                'operator'  => get_current_user_id(),
            ]);
        }

        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        $chainId = self::require_chain_id_shape();

        AdminActionSupport::requireNonce($route . '_' . $chainId);

        try {
            $result = self::apply_cw_operation($route, $chainId);
        } catch (\Throwable $e) {
            $ref = AdminActionSupport::failure(
                $e,
                'admin_chain_cw_' . self::cw_operation_slug($route) . '_error',
                'chain',
                $chainId
            );

            self::redirect_cw_operation('error', $ref);
        }

        self::redirect_cw_operation($result);
    }

    /** Short, fixed operation name — derived from the ROUTE, never the request. */
    private static function cw_operation_slug(string $route): string
    {
        switch ($route) {
            case self::ACTION_CW_PAUSE:
                return 'pause';
            case self::ACTION_CW_RESUME:
                return 'resume';
            case self::ACTION_CW_BACKFILL:
                return 'backfill';
            case self::ACTION_CW_RETRY:
                return 'retry';
        }

        return 'unknown';
    }

    /**
     * Dispatch to the domain call and report what actually happened.
     *
     * @return string result code for the PRG notice
     */
    private static function apply_cw_operation(string $route, int $chainId): string
    {
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return 'unknown_chain';
        }

        switch ($route) {
            case self::ACTION_CW_PAUSE:
                return self::apply_cw_pause($chainId);
            case self::ACTION_CW_RESUME:
                return self::apply_cw_resume($chainId);
            case self::ACTION_CW_BACKFILL:
                return self::apply_cw_backfill($chainId);
            case self::ACTION_CW_RETRY:
                return self::apply_cw_retry($chainId);
        }

        // Unreachable: handle_cw_operation() is only ever called with the
        // four route constants. It throws rather than falling through to
        // `unknown_chain`, because that code says "the chain id you sent
        // does not exist" — a confident, wrong explanation for what is
        // actually a wiring bug here. The caller turns this into the
        // generic error notice plus a correlation id.
        throw new \LogicException('Unroutable CosmWasm scanner operation.');
    }

    /**
     * PAUSE — an operational hold on a chain the scanner still owns.
     *
     * `pauseCwDiscovery()` returns false for TWO different reasons —
     * already paused, and `unsupported` (which is terminal and must not be
     * overwritten, or the durable "no wasm module" fact is lost and the
     * chain re-enters the rotation on resume). The state is read first so
     * those are reported apart instead of as one vague failure.
     *
     * It cannot cancel a pass already inside the worker's advisory lock.
     * Nothing here signals a running pass, and the copy does not pretend
     * otherwise.
     */
    private static function apply_cw_pause(int $chainId): string
    {
        $before = ChainCheckpointRepository::get($chainId);
        $state  = $before !== null ? (string) $before->cw_discovery_state : '';

        if ($state === ChainCheckpointRepository::CW_STATE_UNSUPPORTED) {
            return 'pause_unsupported';
        }
        if ($state === ChainCheckpointRepository::CW_STATE_PAUSED) {
            return 'pause_noop';
        }

        if (!ChainCheckpointRepository::pauseCwDiscovery($chainId)) {
            \BCC\Core\Log\Logger::error('[bcc-trust] CosmWasm scanner pause write failed', [
                'action'   => 'cosmwasm_scanner_pause_write_failed',
                'chain_id' => $chainId,
                'operator' => get_current_user_id(),
            ]);

            AdminActionSupport::audit('admin_chain_cw_pause_failed', 'chain', $chainId);

            return 'pause_failed';
        }

        $after = ChainCheckpointRepository::get($chainId);
        if ($after === null
            || (string) $after->cw_discovery_state !== ChainCheckpointRepository::CW_STATE_PAUSED
        ) {
            \BCC\Core\Log\Logger::error('[bcc-trust] CosmWasm scanner pause could not be confirmed', [
                'action'   => 'cosmwasm_scanner_pause_unconfirmed',
                'chain_id' => $chainId,
                'observed' => $after !== null ? (string) $after->cw_discovery_state : null,
                'operator' => get_current_user_id(),
            ]);

            AdminActionSupport::audit('admin_chain_cw_pause_unconfirmed', 'chain', $chainId);

            return 'pause_unconfirmed';
        }

        AdminActionSupport::audit('admin_chain_cw_paused', 'chain', $chainId);

        \BCC\Core\Log\Logger::info('[bcc-trust] CosmWasm scanner paused', [
            'action'   => 'cosmwasm_scanner_pause',
            'chain_id' => $chainId,
            'operator' => get_current_user_id(),
        ]);

        return 'paused';
    }

    /**
     * RESUME — clears the hold and returns the chain to the state its own
     * durable progress says it is in.
     *
     * `cwResumeState()` is preserved exactly and NOT reimplemented here:
     * completed → `backfilled`, cursor-or-watermark → `backfilling`,
     * nothing → `idle`. Resuming a drained chain to `idle` would make the
     * worker re-walk its entire code listing.
     *
     * Resume starts nothing and contacts nothing.
     */
    private static function apply_cw_resume(int $chainId): string
    {
        $before = ChainCheckpointRepository::get($chainId);
        $state  = $before !== null ? (string) $before->cw_discovery_state : '';

        if ($state !== ChainCheckpointRepository::CW_STATE_PAUSED) {
            return 'resume_noop';
        }

        // The state the repository will derive — read BEFORE the write so
        // the read-back can be compared against an expectation rather than
        // against whatever it happens to find.
        $expected = ChainCheckpointRepository::cwResumeState($before);

        if (!ChainCheckpointRepository::resumeCwDiscovery($chainId)) {
            \BCC\Core\Log\Logger::error('[bcc-trust] CosmWasm scanner resume write failed', [
                'action'   => 'cosmwasm_scanner_resume_write_failed',
                'chain_id' => $chainId,
                'operator' => get_current_user_id(),
            ]);

            AdminActionSupport::audit('admin_chain_cw_resume_failed', 'chain', $chainId);

            return 'resume_failed';
        }

        $after = ChainCheckpointRepository::get($chainId);
        if ($after === null || (string) $after->cw_discovery_state !== $expected) {
            \BCC\Core\Log\Logger::error('[bcc-trust] CosmWasm scanner resume could not be confirmed', [
                'action'   => 'cosmwasm_scanner_resume_unconfirmed',
                'chain_id' => $chainId,
                'expected' => $expected,
                'observed' => $after !== null ? (string) $after->cw_discovery_state : null,
                'operator' => get_current_user_id(),
            ]);

            AdminActionSupport::audit('admin_chain_cw_resume_unconfirmed', 'chain', $chainId);

            return 'resume_unconfirmed';
        }

        AdminActionSupport::audit('admin_chain_cw_resumed', 'chain', $chainId);

        \BCC\Core\Log\Logger::info('[bcc-trust] CosmWasm scanner resumed', [
            'action'   => 'cosmwasm_scanner_resume',
            'chain_id' => $chainId,
            'state'    => $expected,
            'operator' => get_current_user_id(),
        ]);

        return 'resumed';
    }

    /**
     * BACKFILL — the only provider-consuming control on this page.
     *
     * ── THE GATES ARE RE-CHECKED HERE ───────────────────────────────────
     * The button renders disabled without them, but a `disabled` attribute
     * is a UI hint, not authorization: a crafted POST must reach the same
     * fail-closed answer the cron path does.
     *
     * ── WHAT THE AUDIT CAN HONESTLY CLAIM ───────────────────────────────
     * `runBackfillForChain()` returns VOID. On a normal return this handler
     * cannot prove that the advisory lock was acquired, that any provider
     * request was made, or that progress advanced — a concurrent run may
     * hold the lock and the worker simply logs and returns. So the durable
     * event is `admin_chain_cw_backfill_requested`, which is what we can
     * actually observe. Calling it `_ran` or `_completed` would be a claim
     * the contract does not support.
     *
     * ── PR #200 OWNS THE RESERVE SEQUENCE ───────────────────────────────
     * This handler constructs the budget ONCE and hands it over. It does
     * not call reserve() or available(), and it does not reproduce the
     * worker's downstream floors. Second-guessing them here is how the two
     * copies drift apart.
     */
    private static function apply_cw_backfill(int $chainId): string
    {
        if (!CosmwasmDiscoveryGate::discoveryEnabled()) {
            return 'backfill_discovery_off';
        }
        if (!CosmwasmDiscoveryGate::backfillEnabled()) {
            return 'backfill_gate_off';
        }

        $checkpoint = ChainCheckpointRepository::get($chainId);
        if ($checkpoint !== null
            && (string) $checkpoint->cw_discovery_state === ChainCheckpointRepository::CW_STATE_PAUSED
        ) {
            return 'backfill_paused';
        }

        CosmwasmDiscoveryWorker::runBackfillForChain(
            $chainId,
            new CosmwasmTickBudget(self::ADMIN_BACKFILL_REQUESTS, self::ADMIN_BACKFILL_SECONDS)
        );

        AdminActionSupport::audit('admin_chain_cw_backfill_requested', 'chain', $chainId);

        \BCC\Core\Log\Logger::info('[bcc-trust] CosmWasm scanner backfill slice requested', [
            'action'   => 'cosmwasm_scanner_backfill_slice',
            'chain_id' => $chainId,
            'operator' => get_current_user_id(),
        ]);

        return 'backfill_requested';
    }

    /**
     * RETRY — clears the WAIT on unresolved work. It contacts nothing.
     *
     * Scope is preserved exactly: `inconclusive` and
     * `temporarily_unreachable` only, up to 100 code families AND up to 100
     * contracts (two separate limits). Settled `not_cw721`, decided CW-721
     * families, DENY-ruled contracts, the checkpoint, the cursor and the
     * breaker are all untouched. The work happens on a FUTURE pass.
     *
     * Deliberately does NOT touch `classifier_version`: PR #198's version
     * bump is the one and only staleness-requeue mechanism, and a second
     * one here would make "why did this requeue?" unanswerable.
     */
    private static function apply_cw_retry(int $chainId): string
    {
        if (!CosmwasmDiscoveryGate::discoveryEnabled()) {
            return 'retry_discovery_off';
        }

        $families  = CosmwasmCodeFamilyRepository::forceRetryUnresolved($chainId, self::FORCE_RETRY_LIMIT);
        $contracts = CosmwasmContractRepository::forceRetryUnresolved($chainId, self::FORCE_RETRY_LIMIT);

        if ($families === 0 && $contracts === 0) {
            // Nothing was waiting. Truthful, and not a state change, so it
            // writes no durable row.
            return 'retry_none_pending';
        }

        AdminActionSupport::audit('admin_chain_cw_retry_requeued', 'chain', $chainId);

        \BCC\Core\Log\Logger::info('[bcc-trust] CosmWasm scanner force retry', [
            'action'    => 'cosmwasm_scanner_force_retry',
            'chain_id'  => $chainId,
            'families'  => $families,
            'contracts' => $contracts,
            'operator'  => get_current_user_id(),
        ]);

        return 'retry_requeued';
    }

    /**
     * PRG terminator for the scanner operations.
     *
     * Same allowlist discipline as the discovery toggle: page, subtab, a
     * bounded result code, and an optional correlation id. No chain id
     * under any name, no action, no nonce, no direction, no submitted
     * value, no provider response, no scanner error text.
     *
     * @var list<string> OPERATION_REDIRECT_KEYS
     */
    public const OPERATION_REDIRECT_KEYS = ['page', 'subtab', 'bcc_cwo', 'bcc_ref'];

    private static function redirect_cw_operation(string $result, string $ref = ''): never
    {
        $args = [
            'page'    => self::PAGE_SLUG,
            'subtab'  => self::SUBTAB_NFT_DISCOVERY,
            'bcc_cwo' => $result,
        ];
        if ($ref !== '') {
            $args['bcc_ref'] = $ref;
        }

        AdminActionSupport::redirect($args);
    }

    // ── VC-B2: CosmWasm / CW-721 discovery opt-in ───────────────────────────

    public static function handle_cw_discovery_enable(): void
    {
        self::handle_cw_discovery(true);
    }

    public static function handle_cw_discovery_disable(): void
    {
        self::handle_cw_discovery(false);
    }

    /**
     * Shared request boundary for both directions.
     *
     * Order: capability → method → chain-id shape → direction-and-chain
     * scoped nonce → authoritative lookup → at most one write → read-back.
     * Nothing touches a repository before the nonce has proven the request
     * authentic, and the shape check does no lookup, so an unauthenticated
     * request cannot probe which chain ids exist.
     */
    private static function handle_cw_discovery(bool $enable): never
    {
        // ── THE REFUSAL TRACE CARRIES NO REQUEST INPUT ──────────────────
        //
        // An unauthorised attempt on a write is worth seeing, and it can
        // only be seen HERE, because requireCapability() wp_die()s and never
        // returns. But an unauthenticated caller must not be able to put
        // anything of their own choosing into our log: `chain_id` is
        // attacker-controlled at this point, unvalidated, and could be an
        // array, a megabyte of text, or CR/LF sequences that forge extra log
        // lines. Echoing it would also answer "does chain 41 exist?" for
        // anyone who can POST.
        //
        // So the trace is built ONLY from data the server already knows:
        // a fixed event name, the direction taken from which handler was
        // registered (not from the request), and the current actor. No
        // target, no raw value, no lookup.
        if (!current_user_can('manage_options')) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] CosmWasm chain discovery toggle refused', [
                'action'    => 'cosmwasm_chain_discovery_denied',
                'direction' => $enable ? 'enable' : 'disable',
                'operator'  => get_current_user_id(),
            ]);
        }

        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        $chainId = self::require_chain_id_shape();

        $route = $enable ? self::ACTION_CW_DISCOVERY_ENABLE : self::ACTION_CW_DISCOVERY_DISABLE;
        AdminActionSupport::requireNonce($route . '_' . $chainId);

        // FAILURE POLICY, matching VC-A/VC-B1:
        //   - expected negatives (unknown chain, no-op, a write that returns
        //     false, a read-back that disagrees) return a result code and,
        //     where a state change was genuinely attempted and refused, a
        //     durable row. No correlation ID: nothing was captured to
        //     correlate to.
        //   - an UNEXPECTED exception is a fault in our code or transport.
        //     It routes through AdminActionSupport::failure(), the one path
        //     that mints a correlation ID and writes a durable row.
        try {
            $result = self::apply_cw_discovery($chainId, $enable);
        } catch (\Throwable $e) {
            $ref = AdminActionSupport::failure(
                $e,
                $enable
                    ? 'admin_chain_cw_discovery_enable_error'
                    : 'admin_chain_cw_discovery_disable_error',
                'chain',
                $chainId
            );

            self::redirect_cw_discovery('error', $ref);
        }

        self::redirect_cw_discovery($result);
    }

    /**
     * Shape-only validation of the target chain id.
     *
     * Returns a validated POSITIVE INTEGER or terminates with a real HTTP
     * 400. It deliberately does no repository work: the id feeds the nonce
     * action, so it must be read pre-CSRF, and a lookup here would let an
     * authorised-but-unverified request probe which chains exist.
     *
     * ── WHY IT REFUSES RATHER THAN REDIRECTS ────────────────────────────
     * An earlier draft redirected with an `invalid_chain` notice. That is a
     * 302 dressed up as a rejection: the request was malformed, so there is
     * no legitimate page to send the operator back to, and no nonce could
     * have been verified for a target that does not parse. A malformed
     * request gets a status that says so.
     *
     * ── AND WHY IT NEVER TOUCHES THE RAW VALUE ──────────────────────────
     * `$_POST['chain_id']` may be an array, a very long string, or contain
     * CR/LF. `is_scalar()` rejects the array before any cast (an array-to-int
     * cast raises a PHP warning and yields 1 — a valid-looking chain id from
     * pure garbage). Nothing derived from the raw value reaches the wp_die
     * message, so it cannot be reflected back.
     */
    private static function require_chain_id_shape(): int
    {
        $raw = $_POST['chain_id'] ?? null;

        $valid = is_scalar($raw)
            && preg_match('/^[1-9][0-9]{0,17}$/', (string) $raw) === 1;

        if (!$valid) {
            wp_die(
                esc_html__('Invalid chain.', 'bcc-trust'),
                esc_html__('Bad Request', 'bcc-trust'),
                ['response' => 400]
            );
        }

        return (int) $raw;
    }

    /**
     * Apply the opt-in and report what actually happened.
     *
     * ── WHAT THIS SWITCH IS ─────────────────────────────────────────────
     * `wp_bcc_chains.cosmwasm_nft_discovery_enabled` — OPERATOR INTENT for
     * the CosmWasm engine, one of the conditions
     * {@see CosmwasmDiscoveryWorker::eligibleChainIds()} intersects. It is
     * NOT Pause: pause is a temporary hold on a chain the scanner is still
     * responsible for and lives on the checkpoint row, whereas this decides
     * whether the scanner is responsible for the chain at all. Both exist
     * because "stop for now" and "this is not ours" are different
     * statements, and collapsing them loses the difference.
     *
     * Enabling starts nothing. The environment gate, the schedule, the
     * canary allowlist and the chain's own measured wasm capability all
     * still apply, and everything discovered still arrives UNVERIFIED.
     *
     * ── IT VERIFIES INSTEAD OF ASSUMING ─────────────────────────────────
     * The repository returns "the UPDATE did not error", which is not "the
     * flag is now what you asked for" — the row may have gone, or the
     * projection may not carry the column at all on a pre-migration
     * install. The value is READ BACK and a disagreement is reported as
     * unconfirmed, never as success.
     *
     * @return string result code for the PRG notice
     */
    private static function apply_cw_discovery(int $chainId, bool $enable): string
    {
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return 'unknown_chain';
        }

        $slug = (string) $chain->slug;

        // null = the projection has no such column (pre-migration install).
        // Reported as-is rather than folded into false: "it was off" and
        // "this install cannot store the answer" are different facts.
        $before = CosmwasmDiscoveryWorker::discoveryOptInState($chain);

        if ($before === $enable) {
            // A no-op is not a state transition, so it writes nothing and
            // audits nothing. This is also what makes a stale tab safe: the
            // route names its direction, so a second submit of an already
            // applied direction lands here instead of flipping it back.
            return $enable ? 'noop_enabled' : 'noop_disabled';
        }

        if (!ChainRepository::setCosmwasmNftDiscoveryEnabled($chainId, $enable)) {
            \BCC\Core\Log\Logger::error('[bcc-trust] CosmWasm chain discovery write failed', [
                'action'   => 'cosmwasm_chain_discovery_write_failed',
                'chain_id' => $chainId,
                'chain'    => $slug,
                'enable'   => $enable,
                'operator' => get_current_user_id(),
            ]);

            // An authorised operator asked for a state change and the
            // authoritative write refused it. Durable, but no correlation
            // ID — no exception was captured.
            AdminActionSupport::audit(
                $enable
                    ? 'admin_chain_cw_discovery_enable_write_failed'
                    : 'admin_chain_cw_discovery_disable_write_failed',
                'chain',
                $chainId,
                ['chain' => $slug]
            );

            return $enable ? 'enable_write_failed' : 'disable_write_failed';
        }

        // The read-back. The cache was busted inside the write, so this
        // reaches the database rather than the projection just changed.
        $after = self::read_cw_discovery_opt_in($chainId);

        if ($after !== $enable) {
            \BCC\Core\Log\Logger::error('[bcc-trust] CosmWasm chain discovery toggle could not be confirmed', [
                'action'   => 'cosmwasm_chain_discovery_unconfirmed',
                'chain_id' => $chainId,
                'chain'    => $slug,
                'wanted'   => $enable,
                'observed' => $after,
                'operator' => get_current_user_id(),
            ]);

            AdminActionSupport::audit(
                $enable
                    ? 'admin_chain_cw_discovery_enable_unconfirmed'
                    : 'admin_chain_cw_discovery_disable_unconfirmed',
                'chain',
                $chainId,
                ['chain' => $slug]
            );

            return $enable ? 'enable_unconfirmed' : 'disable_unconfirmed';
        }

        AdminActionSupport::audit(
            $enable
                ? 'admin_chain_cw_discovery_enabled'
                : 'admin_chain_cw_discovery_disabled',
            'chain',
            $chainId,
            ['chain' => $slug, 'enabled_before' => $before, 'enabled_after' => $after]
        );

        \BCC\Core\Log\Logger::info('[bcc-trust] CosmWasm chain discovery toggle', [
            'action'         => 'cosmwasm_chain_discovery_toggle',
            'chain_id'       => $chainId,
            'chain'          => $slug,
            'enabled_before' => $before,
            'enabled_after'  => $after,
            'operator'       => get_current_user_id(),
        ]);

        return $enable ? 'enabled' : 'disabled';
    }

    /**
     * Re-resolve the chain and read the opt-in back.
     *
     * Deliberately re-resolves instead of reusing the row the handler
     * already had: that row is the BEFORE picture, and comparing a write
     * against the value it was supposed to replace proves nothing.
     *
     * @return bool|null null when the chain is gone or the projection
     *                   carries no such column — both mean "could not
     *                   confirm", never "it worked"
     */
    private static function read_cw_discovery_opt_in(int $chainId): ?bool
    {
        $chain = ChainRepository::getById($chainId);

        return $chain === null ? null : CosmwasmDiscoveryWorker::discoveryOptInState($chain);
    }

    /**
     * PRG terminator for the NFT Discovery sub-tab.
     *
     * ── THE DESTINATION CARRIES NO TARGET ───────────────────────────────
     * An earlier draft echoed the chain id back so the notice could name
     * the chain. That was wrong: it is still the mutation's target, just
     * spelled differently, and a destination that carries the target is one
     * rename away from being resubmittable. The allowlist below is the
     * whole contract —
     *
     *   page     fixed
     *   subtab   fixed
     *   bcc_cwd  a bounded result code from a closed set
     *   bcc_ref  a sanitized correlation id, exceptions only
     *
     * — and nothing else. No chain id under any name, no action, no nonce,
     * no direction, no submitted value.
     *
     * The cost is that the operator notice is generic. That is the right
     * trade: the DURABLE AUDIT ROW carries the real chain target, which is
     * where "which chain was this?" should be answered from anyway.
     *
     * @var list<string> REDIRECT_KEYS the only keys this destination may carry
     */
    public const REDIRECT_KEYS = ['page', 'subtab', 'bcc_cwd', 'bcc_ref'];

    private static function redirect_cw_discovery(string $result, string $ref = ''): never
    {
        $args = [
            'page'    => self::PAGE_SLUG,
            'subtab'  => self::SUBTAB_NFT_DISCOVERY,
            'bcc_cwd' => $result,
        ];
        if ($ref !== '') {
            $args['bcc_ref'] = $ref;
        }

        AdminActionSupport::redirect($args);
    }

    // ── AJAX: Validator Refresh ─────────────────────────────────────────────

    public static function ajax_refresh(): void
    {
        check_ajax_referer('bcc_chain_refresh', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $chainId = (int) ($_POST['chain_id'] ?? 0);
        $chain   = ChainRepository::getById($chainId);

        \BCC\Core\Log\Logger::info('[bcc-trust] Chain validator refresh (manual)', [
            'action'   => 'chain_refresh_validators',
            'chain_id' => $chainId,
            'operator' => get_current_user_id(),
        ]);

        if (!$chain) {
            wp_send_json_error(['message' => 'Chain not found.']);
        }

        if (!FetcherFactory::has_driver($chain->chain_type)) {
            wp_send_json_error(['message' => "No fetcher driver for chain type: {$chain->chain_type}"]);
        }

        try {
            $fetcher = FetcherFactory::make_for_chain($chain);

            if (!$fetcher->supports_feature('validator')) {
                wp_send_json_error(['message' => 'This chain type does not support validator indexing.']);
            }

            $validators = $fetcher->fetch_all_validators();
            $fetchErr   = $fetcher->last_fetch_error();

            if (empty($validators)) {
                // Distinguish transport failure from API-returned-empty.
                // The two used to render the same "No validators returned"
                // message, which made dead-endpoint diagnostics painful
                // (operator spent N round-trips probing alternative URLs
                // before realising the upstream was unreachable).
                if ($fetchErr !== null) {
                    wp_send_json_error([
                        'message' => sprintf(
                            'Refresh failed for %s: %s. Check Logger for full context; the upstream endpoint may be down or its URL may have changed (see wp_bcc_chains.rest_url).',
                            $chain->name,
                            $fetchErr
                        ),
                    ]);
                }
                wp_send_json_success([
                    'message' => "No validators returned for {$chain->name} (API succeeded; empty response).",
                    'stats'   => ['total' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'enriched' => 0],
                ]);
            }

            $stats = ValidatorRepository::bulkUpsert($validators, 4 * HOUR_IN_SECONDS);

            // Per-validator enrichment is handled by the hourly EnrichmentScheduler
            // cron, not inline during admin refresh. Running 500 sequential API calls
            // in a single AJAX request guarantees a PHP timeout.
            // Schedule an immediate enrichment run if one isn't already pending.
            $enrichHook = 'bcc_refresh_validators';
            $scheduled  = false;
            if (!wp_next_scheduled($enrichHook)) {
                \BCC\Core\Cron\AsyncDispatcher::scheduleSingle(
                    time() + 10,
                    $enrichHook,
                    [],
                    'bcc-onchain'
                );
                $scheduled = true;
            }

            $stats['enriched'] = 0;

            AdminActionSupport::audit(
                'admin_chain_validators_refreshed',
                'chain',
                $chainId,
                [
                    'total'   => (int) $stats['total'],
                    'new'     => (int) $stats['new'],
                    'updated' => (int) $stats['updated'],
                ]
            );

            wp_send_json_success([
                'message' => sprintf(
                    '%s: %d indexed (%d new, %d updated). %s',
                    $chain->name,
                    $stats['total'],
                    $stats['new'],
                    $stats['updated'],
                    $scheduled ? 'Enrichment scheduled.' : 'Enrichment already scheduled.'
                ),
                'stats'     => $stats,
                'scheduled' => (bool) $scheduled,
            ]);
        } catch (\Throwable $e) {
            // Raw Throwable text used to be painted straight into the page —
            // it can carry SQL fragments and absolute paths.
            $correlationId = AdminActionSupport::failure(
                $e,
                'admin_chain_validators_refresh_failed',
                'chain',
                $chainId,
                ['chain' => (string) $chain->name]
            );
            wp_send_json_error([
                'message' => $chain->name . ': ' . AdminActionSupport::failureMessage($correlationId),
            ]);
        }
    }

    // ── Render ──────────────────────────────────────────────────────────────

    public static function render_page(): void
    {
        // Defense in depth: add_submenu_page() already gates on this
        // capability, but relying on menu registration alone was the gap
        // every sibling page had already closed.
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('Sorry, you are not allowed to access this page.', 'bcc-trust'),
                esc_html__('Forbidden', 'bcc-trust'),
                ['response' => 403]
            );
        }

        // Identity saves now redirect here after writing (PRG), so the notice
        // is rebuilt from the redirect args rather than from a live POST.
        $notice = self::identity_notice_from_query();

        $chains         = ChainRepository::getAll();
        $valCountMap    = ValidatorRepository::getCountsByChain();
        $nonce          = wp_create_nonce('bcc_chain_refresh');
        $activeTab      = sanitize_key($_GET['subtab'] ?? 'validators');
        if (!in_array($activeTab, ['validators', 'identity', self::SUBTAB_NFT_DISCOVERY], true)) {
            $activeTab = 'validators';
        }
        ?>
        <div class="wrap">
            <h1>Chains</h1>

            <?php if ($notice !== null): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php self::render_sweep_notice(); ?>

            <?php self::render_sweep_bar(); ?>

            <nav class="nav-tab-wrapper" style="margin-bottom:16px">
                <a href="<?php echo esc_url(add_query_arg('subtab', 'validators')); ?>"
                   class="nav-tab <?php echo $activeTab === 'validators' ? 'nav-tab-active' : ''; ?>">
                    Validators
                </a>
                <a href="<?php echo esc_url(add_query_arg('subtab', self::SUBTAB_NFT_DISCOVERY)); ?>"
                   class="nav-tab <?php echo $activeTab === self::SUBTAB_NFT_DISCOVERY ? 'nav-tab-active' : ''; ?>">
                    NFT Discovery
                </a>
                <a href="<?php echo esc_url(add_query_arg('subtab', 'identity')); ?>"
                   class="nav-tab <?php echo $activeTab === 'identity' ? 'nav-tab-active' : ''; ?>">
                    Identity
                </a>
            </nav>

            <?php if ($activeTab === 'validators'): ?>
                <?php self::render_validators_tab($chains, $valCountMap); ?>
            <?php elseif ($activeTab === self::SUBTAB_NFT_DISCOVERY): ?>
                <?php self::render_nft_discovery_tab(); ?>
            <?php else: ?>
                <?php self::render_identity_tab($chains); ?>
            <?php endif; ?>
        </div>

        <?php self::render_js($nonce); ?>
        <?php
    }

    /**
     * @param list<ChainRow> $chains
     * @param array<int, ValidatorCountByChain> $countMap
     */
    private static function render_validators_tab(array $chains, array $countMap): void
    {
        ?>
        <p>Click <strong>Refresh</strong> to re-index validators for a specific chain.</p>

        <table class="widefat striped" style="max-width:1100px">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Type</th>
                    <th>Token</th>
                    <th>Validators</th>
                    <th>Last Indexed</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($chains as $chain):
                    $cid       = (int) $chain->id;
                    $info      = $countMap[$cid] ?? null;
                    $valCount  = $info ? (int) $info->cnt : 0;
                    $lastFetch = $info->last_fetched ?? null;
                    $hasDriver = FetcherFactory::has_driver($chain->chain_type);
                    $isActive  = (int) $chain->is_active;
                    $hasValidators = $hasDriver && $isActive
                        && FetcherFactory::make_for_chain($chain)->supports_feature('validator');
                ?>
                <tr>
                    <td><?php echo esc_html((string) $cid); ?></td>
                    <td><strong><?php echo esc_html($chain->name); ?></strong></td>
                    <td><code><?php echo esc_html($chain->slug); ?></code></td>
                    <td><code><?php echo esc_html($chain->chain_type); ?></code></td>
                    <td><?php echo esc_html($chain->native_token ?? '—'); ?></td>
                    <td><?php echo esc_html((string) $valCount); ?></td>
                    <td><?php echo $lastFetch ? esc_html($lastFetch) : '<em>Never</em>'; ?></td>
                    <td>
                        <?php if (!$isActive): ?>
                            <span style="color:#d63638;">Inactive</span>
                        <?php elseif (!$hasDriver): ?>
                            <span style="color:#dba617;">No Driver</span>
                        <?php else: ?>
                            <span style="color:#00a32a;">Active</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($hasValidators): ?>
                        <button class="button bcc-chain-refresh-btn"
                                data-chain-id="<?php echo esc_attr((string) $cid); ?>"
                                data-chain-name="<?php echo esc_attr($chain->name); ?>"
                                data-action="bcc_chain_refresh">
                            Refresh
                        </button>
                        <span class="bcc-chain-status" style="margin-left:8px;font-size:12px;"></span>
                        <?php else: ?>
                        <span style="color:#94a3b8;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:16px">
            <button class="button button-primary"
                    id="bcc-refresh-all"
                    onclick="return confirm('Refresh validators for ALL chains? This fans out per-chain API calls (Alchemy / Helius / Subscan / etc.), can take 1-3 minutes, and consumes provider quota. Confirm only if you actually want a full refresh — for one chain, use the per-row Refresh button instead.');">Refresh All Chains</button>
            <span id="bcc-refresh-all-status" style="margin-left:12px;font-size:13px;"></span>
        </p>
        <?php
    }

    // ── Identity editor (per-chain "About this chain") ──────────────────────

    /**
     * Handle a POST from the Identity tab's per-chain editor.
     *
     * add_submenu_page already gates this page on `manage_options`; the
     * write re-checks the capability and a per-action nonce before touching
     * the DB (defense-in-depth). Sanitisation is strict: description →
     * plain-text (sanitize_textarea_field), icon_url → esc_url_raw, color →
     * validated #RRGGBB (sanitize_hex_color, which returns null on a bad
     * value). A non-empty submitted value the sanitiser rejects is NOT
     * written as NULL — the field keeps its stored value and the notice
     * names the rejected field; empty input remains the deliberate
     * clear → NULL path. All the actual DB work + cache-bust lives in
     * ChainRepository::updateIdentity (§1 — no $wpdb in an Admin page).
     *
     * Batch 1: this was a render-path POST handler returning a notice array.
     * It is now an admin-post handler that redirects (PRG), writes a durable
     * audit row, and never renders a raw exception. The validation semantics
     * below — including the "reject the field, keep the stored value" rule —
     * are unchanged.
     */
    public static function handle_identity_save(): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requireNonce(self::ACTION_IDENTITY_SAVE, 'bcc_chain_identity_nonce');

        $chainId = (int) ($_POST['chain_id'] ?? 0);
        if ($chainId <= 0) {
            self::redirect_identity('invalid_chain');
        }

        // The chain id must resolve through the authoritative repository —
        // a positive integer is not by itself a valid target.
        if (ChainRepository::getById($chainId) === null) {
            self::redirect_identity('invalid_chain');
        }

        $descriptionRaw = isset($_POST['description']) ? wp_unslash($_POST['description']) : '';
        $iconUrlRaw     = isset($_POST['icon_url']) ? wp_unslash($_POST['icon_url']) : '';
        $colorRaw       = isset($_POST['color']) ? wp_unslash($_POST['color']) : '';

        $descriptionRaw = is_string($descriptionRaw) ? $descriptionRaw : '';
        $iconUrlRaw     = is_string($iconUrlRaw) ? $iconUrlRaw : '';
        $colorRaw       = is_string($colorRaw) ? $colorRaw : '';

        $description = sanitize_textarea_field($descriptionRaw);
        $iconUrl     = esc_url_raw($iconUrlRaw);
        $color       = sanitize_hex_color($colorRaw);

        // Empty input = deliberate clear → NULL (documented contract).
        // But a NON-empty input the sanitiser rejects must never silently
        // become a clear: surface the field error and keep that column's
        // stored value untouched — every other valid field still saves.
        $current     = ChainRepository::getById($chainId);
        $fieldErrors = [];

        $iconUrlValue = $iconUrl !== '' ? $iconUrl : null;
        if (trim($iconUrlRaw) !== '' && $iconUrl === '') {
            $fieldErrors[] = 'Icon URL must be a valid URL like https://example.com/icon.svg — nothing was saved for this field.';
            $iconUrlValue  = isset($current->icon_url) && is_string($current->icon_url) && $current->icon_url !== ''
                ? $current->icon_url
                : null;
        }

        $colorValue = ($color !== null && $color !== '') ? $color : null;
        if (trim($colorRaw) !== '' && ($color === null || $color === '')) {
            $fieldErrors[] = 'Color must be a hex value like #627EEA — nothing was saved for this field.';
            $colorValue    = isset($current->color) && is_string($current->color) && $current->color !== ''
                ? $current->color
                : null;
        }

        try {
            $ok = ChainRepository::updateIdentity(
                $chainId,
                $description !== '' ? $description : null,
                $iconUrlValue,
                $colorValue
            );
        } catch (\Throwable $e) {
            $correlationId = AdminActionSupport::failure(
                $e,
                'admin_chain_identity_failed',
                'chain',
                $chainId
            );
            self::redirect_identity('exception', $chainId, $correlationId);
        }

        if (!$ok) {
            self::redirect_identity('not_found', $chainId);
        }

        // Public-facing chain profile changed (chain_profile on /halls/:slug).
        // This was the only mutation on the page with no record of any kind.
        AdminActionSupport::audit(
            'admin_chain_identity_saved',
            'chain',
            $chainId,
            [
                'fields_rejected' => count($fieldErrors),
                'cleared'         => $description === '' ? 'description' : '',
            ]
        );

        self::redirect_identity($fieldErrors !== [] ? 'partial' : 'ok', $chainId);
    }

    /**
     * Descriptors for the four "Run cron now" sweeps.
     *
     * Pure and public so the confirmation copy is assertable without
     * rendering the whole page. Each `confirm` must truthfully state the
     * scope — every active chain, synchronous, live provider APIs, quota —
     * because three of these four controls previously had no confirmation
     * at all.
     *
     * @return list<array{action: string, label: string, primary: bool, confirm: string}>
     */
    public static function sweepControls(): array
    {
        return [
            [
                'action'  => ChainSweepActions::ACTION_ALL,
                'label'   => 'All (validators + enrichment)',
                'primary' => true,
                'confirm' => 'Run validator index + validator enrichment for EVERY active chain, right now?'
                    . "\n\n"
                    . 'This runs synchronously against live provider APIs (Alchemy / Helius / Subscan / Cosmos LCD) '
                    . 'and consumes provider quota. It cannot be cancelled once started and may take 30+ seconds.'
                    . "\n\n"
                    . 'It does NOT discover NFT collections. Chain-wide collection discovery is started one named '
                    . 'chain at a time and is never part of a sweep.',
            ],
            [
                'action'  => ChainSweepActions::ACTION_VALIDATORS,
                'label'   => 'Validators only',
                'primary' => false,
                'confirm' => 'Re-index validators for EVERY active chain now?'
                    . "\n\n"
                    . 'Synchronous, runs against live provider APIs, and consumes provider quota.',
            ],
            [
                'action'  => ChainSweepActions::ACTION_ENRICHMENT,
                'label'   => 'Enrichment only',
                'primary' => false,
                'confirm' => 'Run validator enrichment for EVERY active chain now?'
                    . "\n\n"
                    . 'Synchronous and bounded by the EnrichmentScheduler API-call cap, but still consumes provider quota.',
            ],
        ];
    }

    /**
     * "Run cron now" bar — manual trigger for the all-chains indexers.
     *
     * Routes through ChainRefreshService, which calls
     * OnchainCircuitBreaker::recordSuccess() on success and so clears the
     * "Chain data is stale" notice. The per-chain AJAX "Refresh" buttons
     * further down call the fetchers directly and do NOT clear it.
     *
     * Batch 1: these were four GET links sharing one nonce, only one of which
     * had a confirmation. Each is now a POST form to admin-post.php with its
     * own operation-scoped nonce.
     */
    public static function render_sweep_bar(): void
    {
        ?>
        <div style="margin:8px 0 16px 0;padding:10px 12px;background:#f6f7f7;border:1px solid #c3c4c7;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <strong style="margin-right:4px;">Run cron now:</strong>
            <?php foreach (self::sweepControls() as $sweep): ?>
                <form method="post"
                      action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      style="display:inline;margin:0;"
                      onsubmit="return confirm(<?php echo esc_attr(AdminActionSupport::confirmLiteral($sweep['confirm'])); ?>);">
                    <input type="hidden" name="action" value="<?php echo esc_attr($sweep['action']); ?>">
                    <?php wp_nonce_field($sweep['action']); ?>
                    <button type="submit"
                            class="button<?php echo $sweep['primary'] ? ' button-primary' : ''; ?>">
                        <?php echo esc_html($sweep['label']); ?>
                    </button>
                </form>
            <?php endforeach; ?>
            <span style="color:#646970;font-size:11px;margin-left:auto;">
                Clears the &ldquo;Chain data is stale&rdquo; notice via <code>OnchainCircuitBreaker::recordSuccess</code> on the chains that respond.
            </span>
        </div>
        <?php
    }

    /**
     * The NFT Discovery sub-tab.
     *
     * ── WHY THE PAGE IS NAMED FOR THE CAPABILITY ────────────────────────
     * NFT discovery is not a Cosmos concern. EVM NFTs are indexed by their
     * own worker, Solana arrives through Helius, and further standards may
     * follow. This tab owns per-chain NFT-discovery CONFIGURATION, and it
     * is organised into engine-scoped sections so a second engine can be
     * added beside the first later without touching this one.
     *
     * ── AND WHY THE SECTION IS NAMED FOR THE ENGINE ─────────────────────
     * Every control, status and audit event below is CosmWasm/CW-721 and is
     * labelled as such. Nothing here is generalised into a chain-neutral
     * abstraction it cannot honour.
     *
     * ── WHAT IS NOT SAID ABOUT OTHER CHAINS ─────────────────────────────
     * The chain list comes from CosmwasmDiscoveryHealthSnapshot, which
     * selects `chain_type === 'cosmos'` and nothing else. An Ethereum or
     * Solana chain is therefore never assigned a CosmWasm eligibility
     * verdict — it is simply absent from this section, and the note below
     * says why. "Not managed by this engine" is not "cannot do NFTs".
     */
    private static function render_nft_discovery_tab(): void
    {
        $summary = CosmwasmDiscoveryHealthSnapshot::buildSummary();

        /** @var list<array<string, mixed>> $chains */
        $chains = is_array($summary['chains'] ?? null) ? $summary['chains'] : [];

        // Two independent notice sources: the discovery opt-in (VC-B2) and
        // the scanner operations (VC-B3b). Only one can be present on any
        // given PRG landing, since each redirect carries its own key.
        $notice = self::cw_discovery_notice_from_query() ?? self::cw_operation_notice_from_query();

        self::render_cw_discovery_section($chains, $notice);
    }

    /**
     * Render the CosmWasm/CW-721 section from COMPLETED rows.
     *
     * ── THE SEAM THAT MAKES "ONE STATUS AUTHORITY" CHECKABLE ────────────
     * This method takes finished rows and prints them. It derives no
     * verdict: it does not consult CosmwasmScanEligibility, the discovery
     * gate, the worker's eligibleChainIds(), a repository, or the
     * environment. Every status word an operator reads here — the
     * eligibility label, the reason sentence, the capability and pause
     * flags — arrived in the array.
     *
     * That is what makes the claim testable rather than merely asserted:
     * feed it rows carrying distinctive values and they come out unchanged,
     * and a fake gate/eligibility service wired to throw is never called.
     * If a second opinion ever creeps in here, the panel and this tab can
     * disagree about the same chain, which is precisely the failure this
     * whole feature exists to prevent.
     *
     * @param list<array<string, mixed>>             $chains completed snapshot rows
     * @param array{type: string, message: string}|null $notice
     */
    public static function render_cw_discovery_section(array $chains, ?array $notice = null): void
    {
        ?>
        <?php if ($notice !== null): ?>
            <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                <p><?php echo esc_html($notice['message']); ?></p>
            </div>
        <?php endif; ?>

        <p>
            NFT discovery can use different engines for different chain families. This section
            currently manages automatic CW-721 discovery for CosmWasm-enabled chains only. It does
            not control EVM NFTs, Solana NFTs, Helius indexing, manual verification or community
            provisioning.
        </p>

        <h2>CosmWasm / CW-721 Discovery</h2>

        <p style="color:#646970;">
            Turning discovery on for a chain tells the scanner it may look for CW-721 collections
            there. It does not start a scan, and it does not verify anything it finds — everything
            discovered arrives unverified for you to approve on
            <a href="<?php echo esc_url(admin_url('admin.php?page=bcc-verify-collections')); ?>">Verify Collections</a>.
        </p>

        <p style="color:#646970;">
            <strong>Enabling or disabling discovery is not the same as pausing the scanner.</strong>
            Disabling says this chain should not take part in automatic CW-721 discovery at all.
            Pausing is a temporary hold on a chain the scanner is still responsible for, and it
            still lives on Verify Collections.
        </p>

        <?php if ($chains === []): ?>
            <p><em>No active CosmWasm-capable chains are registered, so there is nothing for this
            engine to manage.</em></p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:150px;">Chain</th>
                        <th style="width:90px;">Type</th>
                        <th style="width:150px;">Discovery intent</th>
                        <th style="width:130px;">CW-721 capability</th>
                        <th>Effective status &amp; reason</th>
                        <th style="width:200px;">CW-721 discovery</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($chains as $chain): ?>
                        <?php self::render_cw_discovery_row($chain); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p style="color:#646970;margin-top:12px;">
            Only chains whose type is <code>cosmos</code> appear above, because they are the only
            candidates for the CW-721 engine. Chains on other families are not listed here and are
            <strong>not</strong> being described as unable to support NFTs — they are simply not
            managed by this engine. EVM and Solana NFT indexing is configured elsewhere and is
            unchanged by this page.
        </p>
        <?php
    }

    /**
     * One chain row: the authoritative status an operator needs, then the
     * single control that changes it.
     *
     * Every field is taken from the snapshot row and printed. No verdict is
     * recomputed here — a second opinion on eligibility is exactly how the
     * panel and the worker end up disagreeing.
     *
     * @param array<string, mixed> $chain
     */
    private static function render_cw_discovery_row(array $chain): void
    {
        $chainId = (int) ($chain['chain_id'] ?? 0);
        if ($chainId <= 0) {
            return;
        }

        $slug = (string) ($chain['slug'] ?? '');
        $name = (string) ($chain['name'] ?? $slug);

        // `=== true` on purpose: '1', 1 and a missing key must all read as
        // NOT opted in. An absent field must never read as a permission.
        $optedIn    = ($chain['discovery_opted_in'] ?? null) === true;
        $unsupported = (bool) ($chain['unsupported'] ?? false);
        $paused      = (bool) ($chain['paused'] ?? false);

        $eligibility = is_string($chain['eligibility'] ?? null) && $chain['eligibility'] !== ''
            ? (string) $chain['eligibility']
            : CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_UNKNOWN;

        $reason = is_string($chain['eligibility_reason'] ?? null) && $chain['eligibility_reason'] !== ''
            ? (string) $chain['eligibility_reason']
            : CosmwasmDiscoveryHealthSnapshot::eligibilityReason($eligibility, null);

        $route  = $optedIn ? self::ACTION_CW_DISCOVERY_DISABLE : self::ACTION_CW_DISCOVERY_ENABLE;
        $formId = self::cw_discovery_form_id($chainId, $optedIn);
        ?>
        <tr>
            <td>
                <strong><?php echo esc_html($name); ?></strong><br>
                <code><?php echo esc_html($slug); ?></code>
                <span style="color:#646970;font-size:11px;">#<?php echo $chainId; ?></span>
            </td>
            <td><code>cosmos</code></td>
            <td>
                <?php if ($optedIn): ?>
                    <span style="color:#00a32a;font-weight:600;">Enabled</span>
                <?php else: ?>
                    <span style="color:#646970;font-weight:600;">Disabled</span>
                <?php endif; ?>
                <div style="font-size:11px;color:#646970;">operator setting</div>
            </td>
            <td>
                <?php if ($unsupported): ?>
                    <span style="color:#646970;">No CosmWasm module</span>
                <?php else: ?>
                    <span style="color:#00a32a;">CosmWasm detected</span>
                <?php endif; ?>
            </td>
            <td>
                <strong><?php echo esc_html(CosmwasmDiscoveryHealthSnapshot::eligibilityLabel($eligibility)); ?></strong>
                <?php if ($paused): ?>
                    <span style="color:#dba617;">· scanner paused</span>
                <?php endif; ?>
                <div style="font-size:12px;color:#646970;"><?php echo esc_html($reason); ?></div>
            </td>
            <td>
                <form id="<?php echo esc_attr($formId); ?>" method="post"
                      action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                    <input type="hidden" name="action" value="<?php echo esc_attr($route); ?>">
                    <input type="hidden" name="chain_id" value="<?php echo $chainId; ?>">
                    <?php wp_nonce_field($route . '_' . $chainId); ?>
                    <?php self::render_cw_discovery_button($chainId, $optedIn, $slug); ?>
                </form>
            </td>
        </tr>
        <?php
        self::render_cw_status_row($chain);
        self::render_cw_operations_row($chain);
    }

    /**
     * The four scanner operations for one chain (VC-B3b).
     *
     * Each is its own POST form to admin-post.php with its own
     * direction-and-chain-scoped nonce. They are rendered in their own row
     * rather than inside the status row so no form is ever nested, and so
     * a DOM test can pair each button with exactly one form.
     *
     * Visibility is driven by the SAME snapshot row the status uses:
     *
     *   unsupported chain  no operations at all — nothing runs for it
     *   paused             Resume only; Backfill is refused while paused
     *   not paused         Pause, plus Backfill when both gates allow
     *   Retry              whenever discovery is on
     *
     * @param array<string, mixed> $chain a completed snapshot row
     */
    private static function render_cw_operations_row(array $chain): void
    {
        $chainId = (int) ($chain['chain_id'] ?? 0);
        if ($chainId <= 0) {
            return;
        }

        $slug        = (string) ($chain['slug'] ?? '');
        $unsupported = (bool) ($chain['unsupported'] ?? false);
        $paused      = (bool) ($chain['paused'] ?? false);

        // Environment gates, read from the same authority the worker uses.
        $discoveryOn = CosmwasmDiscoveryGate::discoveryEnabled();
        $backfillOn  = CosmwasmDiscoveryGate::backfillEnabled();
        ?>
        <tr class="bcc-cw-operations-row">
            <td colspan="6" style="padding:6px 12px;">
                <?php if ($unsupported): ?>
                    <span style="color:#646970;font-size:12px;">
                        No CosmWasm module on this chain — no scanner pass runs for it, so there is
                        nothing to pause, resume, backfill or retry.
                    </span>
                <?php else: ?>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                        <?php
                        if ($paused) {
                            self::render_cw_operation_control(self::ACTION_CW_RESUME, $chainId, $slug);
                        } else {
                            self::render_cw_operation_control(self::ACTION_CW_PAUSE, $chainId, $slug);
                        }

                        // Backfill is the only provider-consuming control.
                        // Offered only when both gates allow it AND the
                        // chain is not paused — the same conditions the
                        // handler re-checks server-side.
                        if ($discoveryOn && $backfillOn && !$paused) {
                            self::render_cw_operation_control(self::ACTION_CW_BACKFILL, $chainId, $slug);
                        }

                        if ($discoveryOn) {
                            self::render_cw_operation_control(self::ACTION_CW_RETRY, $chainId, $slug);
                        }
                        ?>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /** The id of one operation form. Operation and chain are both in it. */
    public static function cw_operation_form_id(string $route, int $chainId): string
    {
        return 'cwo-' . self::cw_operation_slug($route) . '-' . (int) $chainId;
    }

    /**
     * One operation as a self-contained POST form.
     *
     * Public so the DOM wiring test asserts the markup production emits
     * rather than a copy of it.
     */
    public static function render_cw_operation_control(string $route, int $chainId, string $slug = ''): void
    {
        $chainId = (int) $chainId;
        $name    = $slug !== '' ? $slug : ('chain ' . $chainId);
        $formId  = self::cw_operation_form_id($route, $chainId);

        [$label, $title, $confirm] = self::cw_operation_copy($route, $name);
        ?>
        <form id="<?php echo esc_attr($formId); ?>" method="post"
              action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
            <input type="hidden" name="action" value="<?php echo esc_attr($route); ?>">
            <input type="hidden" name="chain_id" value="<?php echo $chainId; ?>">
            <?php wp_nonce_field($route . '_' . $chainId); ?>
            <button type="submit"
                    class="button button-small"
                    title="<?php echo esc_attr($title); ?>"
                    onclick="return confirm(<?php echo esc_attr(AdminActionSupport::confirmLiteral($confirm)); ?>);">
                <?php echo esc_html($label); ?>
            </button>
        </form>
        <?php
    }

    /**
     * Label, hover title and confirmation for one operation.
     *
     * The copy states what each control DOES and, just as importantly,
     * what it does not: Pause cannot cancel a pass already running,
     * Resume starts nothing, Backfill is one bounded slice and not a full
     * scan, and Retry contacts nothing now. None of them verifies a
     * collection or creates a community.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function cw_operation_copy(string $route, string $name): array
    {
        switch ($route) {
            case self::ACTION_CW_PAUSE:
                return [
                    'Pause scanning',
                    'Stop selecting this chain for future automatic passes. Keeps all progress.',
                    sprintf(
                        'Pause CW-721 scanning for %s?' . "\n\n"
                            . 'Future automatic passes will not select this chain. A pass that is already '
                            . 'running is NOT cancelled — this cannot stop work already in flight.' . "\n\n"
                            . 'All scan progress and inventory are kept. This is different from disabling '
                            . 'discovery: pausing is a temporary hold on a chain the scanner still owns.',
                        $name
                    ),
                ];

            case self::ACTION_CW_RESUME:
                return [
                    'Resume scanning',
                    'Return this chain to the rotation at the state its own progress says it is in. Starts nothing now.',
                    sprintf(
                        'Resume CW-721 scanning for %s?' . "\n\n"
                            . 'The chain returns to the rotation at the state its own durable progress says it '
                            . 'is in, so a completed backfill is not re-walked.' . "\n\n"
                            . 'This does NOT start a scan now and contacts nothing. It also does not change '
                            . 'whether discovery is enabled for the chain.',
                        $name
                    ),
                ];

            case self::ACTION_CW_BACKFILL:
                return [
                    'Run one backfill slice',
                    'Contacts the chain now. One bounded slice: at most 20 requests or 8 seconds. Not a full scan.',
                    sprintf(
                        'Run one backfill slice for %s now?' . "\n\n"
                            . 'This CONTACTS THE CHAIN IMMEDIATELY. It runs one bounded slice — at most 20 '
                            . 'requests or 8 seconds — and then stops.' . "\n\n"
                            . 'It is not a full scan. Progress is written durably as it goes, so running it '
                            . 'again continues from where it stopped. If another pass already holds the lock, '
                            . 'this does nothing.' . "\n\n"
                            . 'Anything discovered arrives unverified. Nothing is verified and no community '
                            . 'is created.',
                        $name
                    ),
                ];

            case self::ACTION_CW_RETRY:
                return [
                    'Retry unresolved',
                    'Clear the wait on unresolved code families and contracts. Contacts nothing now.',
                    sprintf(
                        'Retry unresolved scanner work for %s?' . "\n\n"
                            . 'This clears the wait on up to 100 unresolved code families and up to 100 '
                            . 'unresolved contracts, so the next scheduled pass looks at them again.' . "\n\n"
                            . 'Nothing is contacted now. Settled non-NFT results, decided CW-721 families and '
                            . 'hidden contracts are left alone, and no collection is verified.',
                        $name
                    ),
                ];
        }

        return ['', '', ''];
    }

    /**
     * The scanner-status detail row (VC-B3a).
     *
     * ── WHY THIS EXISTS BEFORE THE CONTROLS MOVE ────────────────────────
     * VC-B3b will move Pause / Resume / Backfill / Retry here from the
     * Verify Collections scanner panel. Those controls are unusable without
     * the state they act on — "should I resume this chain?" is unanswerable
     * from an eligibility verdict alone — so the READOUT lands first, is
     * proven, and only then does the switch follow. Until VC-B3b the four
     * controls stay exactly where they are and behave exactly as before.
     *
     * ── TRANSITIONAL DUPLICATION, DELIBERATE AND BOUNDED ────────────────
     * These same values are also rendered by CosmwasmScannerPanel during
     * the transition. That is approved and temporary:
     *
     *   • ONE authority. Both surfaces print fields from the SAME
     *     CosmwasmDiscoveryHealthSnapshot::buildSummary() row. Nothing here
     *     recalculates eligibility, health, progress or a gate, and nothing
     *     here touches a repository — so the two cannot disagree.
     *   • READ-ONLY. No mutation control is added by this batch.
     *   • The panel copy is deleted in VC-B3b, once parity is proven.
     *
     * Every value below arrives in $chain already computed — including the
     * derived labels `state_label`, `progress_label` and
     * `last_discovery_age_seconds`. This renderer only formats and escapes.
     *
     * @param array<string, mixed> $chain a completed snapshot row
     */
    private static function render_cw_status_row(array $chain): void
    {
        $stateLabel = is_string($chain['state_label'] ?? null) && $chain['state_label'] !== ''
            ? (string) $chain['state_label']
            : CosmwasmDiscoveryHealthSnapshot::stateLabel((string) ($chain['state'] ?? ''));

        $progress = is_string($chain['progress_label'] ?? null) ? (string) $chain['progress_label'] : '';

        $familyCounts   = is_array($chain['families_by_classification'] ?? null)
            ? $chain['families_by_classification']
            : [];
        $familiesPending = (int) ($chain['families_pending'] ?? 0);

        // PR #196. Fail closed: anything non-numeric reads as 0 errored, and
        // a positive count is stated plainly rather than buried in a total.
        $familiesErrored = (int) ($chain['families_errored'] ?? 0);

        $inspected = (int) ($chain['contracts_inspected'] ?? 0);
        $denied    = (int) ($chain['contracts_denied'] ?? 0);
        $candidates = (int) ($chain['candidates'] ?? 0);

        $lastError = is_string($chain['last_error'] ?? null) && $chain['last_error'] !== ''
            ? (string) $chain['last_error']
            : null;

        $age = is_int($chain['last_discovery_age_seconds'] ?? null)
            ? (int) $chain['last_discovery_age_seconds']
            : null;

        $metadataAt = is_string($chain['metadata_refreshed_at'] ?? null) && $chain['metadata_refreshed_at'] !== ''
            ? (string) $chain['metadata_refreshed_at']
            : null;
        ?>
        <tr class="bcc-cw-status-row">
            <td colspan="6" style="background:#f6f7f7;padding:8px 12px;">
                <div style="display:flex;flex-wrap:wrap;gap:24px;font-size:12px;">

                    <div>
                        <strong>Scanner state</strong><br>
                        <span><?php echo esc_html($stateLabel); ?></span>
                        <?php if ($progress !== ''): ?>
                            <div style="color:#646970;"><?php echo esc_html($progress); ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <strong>Classification queue</strong><br>
                        <?php
                        // The SAME three values the scanner panel shows, in the
                        // same order and from the same row: the CW-721 total
                        // (confirmed + probable, via the snapshot's own
                        // cw721Total), the settled non-NFT count read by its
                        // classifier constant, and the pending count. Dropping
                        // "non-NFT" would leave an operator unable to tell a
                        // chain nobody has classified from one where almost
                        // everything turned out not to be an NFT.
                        ?>
                        <span><?php echo esc_html(number_format_i18n(
                            CosmwasmDiscoveryHealthSnapshot::cw721Total($familyCounts)
                        )); ?> CW-721</span>
                        · <span><?php echo esc_html(number_format_i18n(
                            (int) ($familyCounts[CosmwasmClassifier::NOT_CW721] ?? 0)
                        )); ?> non-NFT</span>
                        · <span><?php echo esc_html(number_format_i18n($familiesPending)); ?> pending</span>
                        <?php if ($familiesErrored > 0): ?>
                            · <span style="color:#d63638;font-weight:600;"><?php
                                echo esc_html(number_format_i18n($familiesErrored));
                            ?> errored</span>
                            <?php
                            // PR #196's SENTENCE, moved here verbatim rather
                            // than reduced to the count beside it.
                            //
                            // "N errored" alone is the half that makes an
                            // operator reach for a rebuild they do not need.
                            // The word that stops them is "retry", and now
                            // that Retry is a control on THIS page, the
                            // sentence and the button that acts on it finally
                            // sit together. The scanner panel that used to
                            // carry this no longer renders per-chain rows.
                            ?>
                            <div style="color:#d63638;font-size:11px;margin-top:2px;">
                                <?php echo esc_html(sprintf(
                                    $familiesErrored === 1
                                        ? '%s code family has unresolved discovery errors and remains eligible for retry.'
                                        : '%s code families have unresolved discovery errors and remain eligible for retry.',
                                    number_format_i18n($familiesErrored)
                                )); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <strong>Inventory</strong><br>
                        <span><?php echo esc_html(number_format_i18n($inspected)); ?> contracts inspected</span>
                        · <span><?php echo esc_html(number_format_i18n($candidates)); ?> candidates</span>
                        <?php if ($denied > 0): ?>
                            · <span><?php echo esc_html(number_format_i18n($denied)); ?> hidden</span>
                        <?php endif; ?>
                    </div>

                    <div>
                        <strong>Freshness</strong><br>
                        <?php if ($age !== null): ?>
                            <span><?php echo esc_html(
                                CosmwasmDiscoveryHealthSnapshot::formatDuration($age)
                            ); ?> ago</span>
                        <?php else: ?>
                            <span style="color:#646970;">never</span>
                        <?php endif; ?>
                        <?php if ($metadataAt !== null): ?>
                            <div style="color:#646970;">
                                capability check <?php echo esc_html($metadataAt); ?> UTC
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($lastError !== null): ?>
                        <div style="flex-basis:100%;">
                            <?php
                            // UPSTREAM TEXT. Same treatment the panel gives it:
                            // behind a disclosure and labelled as not ours,
                            // because it is the one field whose content we do
                            // not control. esc_html() on the way out.
                            ?>
                            <details>
                                <summary style="cursor:pointer;color:#d63638;">Last recorded reason</summary>
                                <code style="display:block;margin-top:4px;white-space:pre-wrap;word-break:break-word;"><?php
                                    // REDACTED BEFORE ESCAPING. esc_html()
                                    // stops markup executing; it does nothing
                                    // about a credentialed URL, an absolute
                                    // server path or an SQL fragment, and
                                    // `cw_last_error` demonstrably carries
                                    // raw $e->getMessage() and LCD bodies.
                                    echo esc_html(AdminActionSupport::operatorSafeExcerpt($lastError));
                                ?></code>
                            </details>
                        </div>
                    <?php endif; ?>

                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * The id of a chain's discovery form. Direction is part of the id, so
     * the two can never collide and a DOM test can tell from the id alone
     * which direction a chain offers.
     */
    public static function cw_discovery_form_id(int $chainId, bool $optedIn): string
    {
        return ($optedIn ? 'cwd-disable-' : 'cwd-enable-') . (int) $chainId;
    }

    /**
     * The single production control for this chain's discovery opt-in.
     *
     * Public so the DOM wiring test asserts the markup production emits
     * rather than a copy of it that can drift.
     *
     * Labels say what they do — "Enable automatic discovery", not "On" —
     * because "On"/"Off" beside a Pause/Resume control on a neighbouring
     * page is exactly how the two get confused.
     */
    public static function render_cw_discovery_button(int $chainId, bool $optedIn, string $slug = ''): void
    {
        $name = $slug !== '' ? $slug : ('chain ' . (int) $chainId);

        $confirm = $optedIn
            ? sprintf(
                'Disable automatic CW-721 discovery for %s?' . "\n\n"
                    . 'This opts the chain out of future automatic CosmWasm discovery passes. '
                    . 'Nothing already found is removed: existing collections, scan progress and '
                    . 'inventory are all kept, and no collection is un-verified.' . "\n\n"
                    . 'This is different from temporarily pausing the scanner. Pausing holds a chain '
                    . 'the scanner is still responsible for; this says the chain should not take part '
                    . 'at all.',
                $name
            )
            : sprintf(
                'Enable automatic CW-721 discovery for %s?' . "\n\n"
                    . 'This opts the chain in. It does NOT start a scan now — future scheduled passes '
                    . 'may make bounded requests to the chain, and discovery still depends on the '
                    . 'chain reporting a working CosmWasm module, not being paused, and the other '
                    . 'safety gates.' . "\n\n"
                    . 'Anything found arrives unverified. Collections are not automatically verified, '
                    . 'and no community is created or provisioned for them.',
                $name
            );

        $label = $optedIn ? 'Disable automatic discovery' : 'Enable automatic discovery';
        $title = $optedIn
            ? 'Opt this chain out of future automatic CW-721 discovery. Keeps everything already discovered.'
            : 'Opt this chain in to automatic CW-721 discovery. Starts nothing now; the usual gates still apply.';
        ?>
        <button type="submit"
                class="button button-small"
                title="<?php echo esc_attr($title); ?>"
                onclick="return confirm(<?php echo esc_attr(AdminActionSupport::confirmLiteral($confirm)); ?>);">
            <?php echo esc_html($label); ?>
        </button>
        <?php
    }

    /**
     * Rebuild the scanner-operation notice from the PRG redirect args.
     *
     * Generic by necessity: the destination carries no chain id (see
     * OPERATION_REDIRECT_KEYS), so this cannot name the chain and does not
     * pretend to. The durable audit row holds the real target.
     *
     * @return array{type: string, message: string}|null
     */
    private static function cw_operation_notice_from_query(): ?array
    {
        $result = isset($_GET['bcc_cwo']) ? sanitize_key((string) $_GET['bcc_cwo']) : '';
        if ($result === '') {
            return null;
        }

        switch ($result) {
            // ── Pause ───────────────────────────────────────────────────
            case 'paused':
                return ['type' => 'success', 'message' =>
                    'Scanner paused. Future automatic passes will not select this chain. '
                    . 'A pass already running is not cancelled, and all progress is kept. '
                    . 'This is not the same as disabling discovery.'];

            case 'pause_noop':
                return ['type' => 'info', 'message' =>
                    'That chain was already paused. Nothing was changed.'];

            case 'pause_unsupported':
                return ['type' => 'info', 'message' =>
                    'That chain reports no CosmWasm module, so no scanner pass runs for it and there is '
                    . 'nothing to pause. Nothing was changed.'];

            case 'pause_failed':
                return ['type' => 'error', 'message' =>
                    'The chain could NOT be paused — the database write failed and nothing was changed. '
                    . 'It is still in the rotation. Check the bcc-trust error log.'];

            case 'pause_unconfirmed':
                return ['type' => 'error', 'message' =>
                    'The pause was attempted but could NOT be confirmed: the stored scanner state does not '
                    . 'read back as paused, so this is not being reported as done. Reload to see the current '
                    . 'state and check the bcc-trust error log.'];

            // ── Resume ──────────────────────────────────────────────────
            case 'resumed':
                return ['type' => 'success', 'message' =>
                    'Scanner resumed. The chain returns to the rotation at the state its own progress says '
                    . 'it is in — a completed backfill is not re-walked. No scan was started by this action, '
                    . 'and nothing was contacted.'];

            case 'resume_noop':
                return ['type' => 'info', 'message' =>
                    'That chain was not paused, so there was nothing to resume. Nothing was changed.'];

            case 'resume_failed':
                return ['type' => 'error', 'message' =>
                    'The chain could NOT be resumed — the database write failed and nothing was changed. '
                    . 'It is still paused. Check the bcc-trust error log.'];

            case 'resume_unconfirmed':
                return ['type' => 'error', 'message' =>
                    'The resume was attempted but could NOT be confirmed: the stored scanner state does not '
                    . 'read back as expected, so this is not being reported as done. Reload to see the current '
                    . 'state and check the bcc-trust error log.'];

            // ── Backfill ────────────────────────────────────────────────
            case 'backfill_requested':
                return ['type' => 'success', 'message' =>
                    'One bounded backfill slice was requested for this chain — at most 20 requests or 8 '
                    . 'seconds. Whether it did any work depends on the scanner: another pass may already hold '
                    . 'the lock, in which case it does nothing. Run it again to continue, and check the '
                    . 'scanner state below to see where it got to. Anything discovered arrives unverified.'];

            case 'backfill_discovery_off':
                return ['type' => 'error', 'message' =>
                    'Discovery is switched off for this installation, so no scanner work can run. '
                    . 'Nothing was started.'];

            case 'backfill_gate_off':
                return ['type' => 'error', 'message' =>
                    'The historical backfill is switched off for this installation, so the walk cannot run. '
                    . 'Nothing was started.'];

            case 'backfill_paused':
                return ['type' => 'warning', 'message' =>
                    'That chain is paused, so no slice was run. Resume it first. Nothing was started.'];

            // ── Retry ───────────────────────────────────────────────────
            case 'retry_requeued':
                return ['type' => 'success', 'message' =>
                    'Unresolved code families and contracts were queued for another look — up to 100 of each. '
                    . 'Nothing was contacted now: the next scheduled pass does the work. Settled non-NFT '
                    . 'results, decided CW-721 families and hidden contracts were left alone.'];

            case 'retry_none_pending':
                return ['type' => 'info', 'message' =>
                    'Nothing was waiting to be retried on that chain — no unresolved code families and no '
                    . 'unresolved contracts. Nothing was changed.'];

            case 'retry_discovery_off':
                return ['type' => 'error', 'message' =>
                    'Discovery is switched off for this installation, so a retry would never run. '
                    . 'Nothing was changed.'];

            // ── Shared ──────────────────────────────────────────────────
            case 'unknown_chain':
                return ['type' => 'error', 'message' =>
                    'Scanner: chain not found. Nothing was changed.'];

            case 'error':
                $ref = isset($_GET['bcc_ref']) ? sanitize_text_field((string) $_GET['bcc_ref']) : '';

                return ['type' => 'error', 'message' => $ref !== ''
                    ? AdminActionSupport::failureMessage($ref)
                    : 'Scanner: the operation could not be completed. Check the bcc-trust error log.'];
        }

        return null;
    }

    /**
     * Rebuild the NFT Discovery notice from the PRG redirect args.
     *
     * @return array{type: string, message: string}|null
     */
    private static function cw_discovery_notice_from_query(): ?array
    {
        $result = isset($_GET['bcc_cwd']) ? sanitize_key((string) $_GET['bcc_cwd']) : '';
        if ($result === '') {
            return null;
        }

        // Deliberately GENERIC. The destination carries no chain id (see
        // REDIRECT_KEYS), so this cannot name the chain — and should not
        // try. "Which chain was it?" is answered by the durable audit row,
        // which holds the real target. A notice that guessed would be worse
        // than one that does not claim to know.
        switch ($result) {
            case 'enabled':
                return ['type' => 'success', 'message' =>
                    'Automatic CW-721 discovery was enabled. No scan was started by this action. Future '
                    . 'automatic passes may scan this chain only when the existing capability, eligibility, '
                    . 'pause and safety gates allow it. Anything discovered still arrives unverified for you '
                    . 'to approve.'];

            case 'disabled':
                return ['type' => 'success', 'message' =>
                    'Automatic CW-721 discovery was disabled. Future automatic passes will not select this '
                    . 'chain. Existing inventory and progress were kept, and no collection was unverified or '
                    . 'removed.'];

            case 'noop_enabled':
                return ['type' => 'info', 'message' =>
                    'Automatic CW-721 discovery was already enabled. Nothing was changed.'];

            case 'noop_disabled':
                return ['type' => 'info', 'message' =>
                    'Automatic CW-721 discovery was already disabled. Nothing was changed.'];

            case 'invalid_chain':
                return ['type' => 'error', 'message' =>
                    'Discovery: invalid chain. Nothing was changed.'];

            case 'unknown_chain':
                return ['type' => 'error', 'message' =>
                    'Discovery: chain not found. Nothing was changed.'];

            // Direction-specific, because "it failed" without saying which
            // way leaves the operator unable to tell what the chain is now.
            // The write returned false, so nothing moved and the previous
            // state genuinely does still stand — that is safe to assert.
            case 'enable_write_failed':
                return ['type' => 'error', 'message' =>
                    'Automatic CW-721 discovery could NOT be enabled — the database write failed and nothing '
                    . 'was changed. It remains disabled. Check the bcc-trust error log.'];

            case 'disable_write_failed':
                return ['type' => 'error', 'message' =>
                    'Automatic CW-721 discovery could NOT be disabled — the database write failed and nothing '
                    . 'was changed. It remains enabled. Check the bcc-trust error log.'];

            // Read-back mismatch deliberately does NOT claim the previous
            // state survived. The write reported success and only the
            // read-back disagreed, so what is stored is genuinely unknown.
            // Say what was observed and nothing more.
            case 'enable_unconfirmed':
                return ['type' => 'error', 'message' =>
                    'The change was attempted but could NOT be confirmed: the stored setting does not read back '
                    . 'as enabled, so this is not being reported as done. Nothing else was touched — no '
                    . 'collection, no scan, no verification. Reload to see the current state and check the '
                    . 'bcc-trust error log.'];

            case 'disable_unconfirmed':
                return ['type' => 'error', 'message' =>
                    'The change was attempted but could NOT be confirmed: the stored setting does not read back '
                    . 'as disabled, so this is not being reported as done. Nothing else was touched — no '
                    . 'collection, no scan, no verification. Reload to see the current state and check the '
                    . 'bcc-trust error log.'];

            case 'error':
                $ref = isset($_GET['bcc_ref']) ? sanitize_text_field((string) $_GET['bcc_ref']) : '';

                return ['type' => 'error', 'message' => $ref !== ''
                    ? AdminActionSupport::failureMessage($ref)
                    : 'Discovery: the change could not be completed. Check the bcc-trust error log.'];
        }

        return null;
    }

    /**
     * PRG terminator for the Identity editor.
     */
    private static function redirect_identity(string $result, int $chainId = 0, string $ref = ''): never
    {
        $args = [
            'page'         => self::PAGE_SLUG,
            'subtab'       => 'identity',
            'bcc_identity' => $result,
        ];
        if ($chainId > 0) {
            $args['bcc_chain'] = $chainId;
        }
        if ($ref !== '') {
            $args['bcc_ref'] = $ref;
        }

        AdminActionSupport::redirect($args);
    }

    /**
     * Rebuild the Identity-editor notice from the PRG redirect args.
     *
     * @return array{type: string, message: string}|null
     */
    private static function identity_notice_from_query(): ?array
    {
        $result = isset($_GET['bcc_identity']) ? sanitize_key((string) $_GET['bcc_identity']) : '';
        if ($result === '') {
            return null;
        }

        switch ($result) {
            case 'ok':
                return ['type' => 'success', 'message' => __('Chain identity saved.', 'bcc-trust')];
            case 'partial':
                return [
                    'type'    => 'error',
                    'message' => __(
                        'Some fields were rejected and kept their stored values — check the Icon URL (https://…) and Color (#RRGGBB) formats. Every other field was saved.',
                        'bcc-trust'
                    ),
                ];
            case 'invalid_chain':
                return ['type' => 'error', 'message' => __('Invalid chain.', 'bcc-trust')];
            case 'not_found':
                return ['type' => 'error', 'message' => __('Chain not found — nothing was saved.', 'bcc-trust')];
            case 'exception':
                $ref = isset($_GET['bcc_ref']) ? sanitize_text_field((string) $_GET['bcc_ref']) : '';
                return [
                    'type'    => 'error',
                    'message' => AdminActionSupport::failureMessage($ref),
                ];
            default:
                return null;
        }
    }

    /**
     * Result notice for a completed "Run cron now" sweep, rebuilt from the
     * PRG redirect args written by ChainSweepActions.
     */
    private static function render_sweep_notice(): void
    {
        $slug = isset($_GET['bcc_sweep']) ? sanitize_key((string) $_GET['bcc_sweep']) : '';
        if ($slug === '') {
            return;
        }

        $steps = ChainSweepActions::stepsForSlug($slug);
        if ($steps === []) {
            return;
        }

        $result = isset($_GET['bcc_result']) ? sanitize_key((string) $_GET['bcc_result']) : '';

        if ($result === 'failed') {
            $failedAt = isset($_GET['bcc_failed_at']) ? sanitize_key((string) $_GET['bcc_failed_at']) : '';
            $ref      = isset($_GET['bcc_ref']) ? sanitize_text_field((string) $_GET['bcc_ref']) : '';

            echo '<div class="notice notice-error is-dismissible"><p><strong>BCC On-Chain:</strong> ';
            printf(
                /* translators: %s: the sweep step that failed, e.g. "collections". */
                esc_html__('Indexing stopped during the %s step.', 'bcc-trust'),
                esc_html($failedAt !== '' ? $failedAt : 'unknown')
            );
            echo ' ' . esc_html(AdminActionSupport::failureMessage($ref)) . '</p></div>';
            return;
        }

        if ($result !== 'ok') {
            return;
        }

        $enrichStats = get_option('bcc_onchain_enrichment_stats', []);

        echo '<div class="notice notice-success is-dismissible"><p><strong>BCC On-Chain:</strong> ';
        printf(
            /* translators: %s: completed step list, e.g. "validators + collections". */
            esc_html__('Indexing complete (%s).', 'bcc-trust'),
            esc_html(ChainSweepActions::stepLabels($steps))
        );
        echo '</p>';

        if (is_array($enrichStats) && $enrichStats !== [] && in_array('enrichment', $steps, true)) {
            printf(
                '<p>Enrichment: %d processed, %d failed, %d skipped, %d API calls. Stop: %s</p>',
                (int) ($enrichStats['processed'] ?? 0),
                (int) ($enrichStats['failed'] ?? 0),
                (int) ($enrichStats['skipped'] ?? 0),
                (int) ($enrichStats['api_calls'] ?? 0),
                esc_html((string) ($enrichStats['stopped_reason'] ?? '—'))
            );
        }

        echo '</div>';
    }

    /**
     * @param list<ChainRow> $chains
     */
    private static function render_identity_tab(array $chains): void
    {
        ?>
        <p>
            Edit the public chain-identity fields surfaced on each chain's Hall
            (the <code>chain_profile</code> block of the
            <code>/halls/:slug</code> payload): the &ldquo;About this
            chain&rdquo; description, icon, and accent color. Infrastructure
            endpoints (RPC / REST) are configured elsewhere and are never part
            of the public payload.
        </p>

        <?php foreach ($chains as $chain):
            $cid = (int) $chain->id;
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              style="margin:0 0 12px 0;padding:12px;background:#fff;border:1px solid #c3c4c7;max-width:1100px;">
            <?php wp_nonce_field(self::ACTION_IDENTITY_SAVE, 'bcc_chain_identity_nonce'); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_IDENTITY_SAVE); ?>">
            <input type="hidden" name="chain_id" value="<?php echo esc_attr((string) $cid); ?>">
            <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
                <div style="min-width:150px;">
                    <strong><?php echo esc_html($chain->name); ?></strong><br>
                    <code><?php echo esc_html($chain->slug); ?></code>
                </div>
                <div style="flex:1 1 320px;min-width:280px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">About this chain</label>
                    <textarea name="description" rows="3" style="width:100%;"
                              placeholder="Short description shown on this chain's Hall…"><?php echo esc_textarea($chain->description ?? ''); ?></textarea>
                </div>
                <div style="flex:0 0 240px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Icon URL</label>
                    <input type="url" name="icon_url" style="width:100%;"
                           value="<?php echo esc_attr($chain->icon_url ?? ''); ?>" placeholder="https://…">
                    <label style="display:block;font-weight:600;margin:8px 0 4px;">Color</label>
                    <input type="text" name="color" style="width:100px;" maxlength="7"
                           value="<?php echo esc_attr($chain->color ?? ''); ?>" placeholder="#RRGGBB">
                </div>
                <div style="flex:0 0 auto;align-self:center;">
                    <button type="submit" class="button button-primary">Save</button>
                </div>
            </div>
        </form>
        <?php endforeach; ?>
        <?php
    }

    private static function render_js(string $nonce): void
    {
        ?>
        <script>
        (function() {
            var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var nonce   = '<?php echo esc_js($nonce); ?>';

            function refreshChain(btn) {
                var chainId   = btn.getAttribute('data-chain-id');
                var action    = btn.getAttribute('data-action');
                var statusEl  = btn.parentElement.querySelector('.bcc-chain-status');

                btn.disabled = true;
                btn.textContent = 'Indexing...';
                if (statusEl) statusEl.textContent = '';

                var body = new FormData();
                body.append('action', action);
                body.append('nonce', nonce);
                body.append('chain_id', chainId);

                return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        btn.disabled = false;
                        btn.textContent = 'Refresh';
                        if (statusEl) {
                            statusEl.style.color = resp.success ? '#00a32a' : '#d63638';
                            statusEl.textContent = resp.success ? resp.data.message : (resp.data.message || 'Error');
                        }
                        return resp;
                    })
                    .catch(function() {
                        btn.disabled = false;
                        btn.textContent = 'Refresh';
                        if (statusEl) {
                            statusEl.style.color = '#d63638';
                            statusEl.textContent = 'Network error';
                        }
                    });
            }

            document.querySelectorAll('.bcc-chain-refresh-btn').forEach(function(btn) {
                btn.addEventListener('click', function() { refreshChain(btn); });
            });

            var refreshAllBtn    = document.getElementById('bcc-refresh-all');
            var refreshAllStatus = document.getElementById('bcc-refresh-all-status');

            if (refreshAllBtn) {
                refreshAllBtn.addEventListener('click', function() {
                    var buttons = Array.from(document.querySelectorAll('.bcc-chain-refresh-btn:not(:disabled)'));
                    var total   = buttons.length;
                    var done    = 0;

                    refreshAllBtn.disabled = true;
                    refreshAllBtn.textContent = 'Refreshing...';
                    if (refreshAllStatus) refreshAllStatus.textContent = '0 / ' + total;

                    function next() {
                        if (buttons.length === 0) {
                            refreshAllBtn.disabled = false;
                            refreshAllBtn.textContent = 'Refresh All Chains';
                            if (refreshAllStatus) refreshAllStatus.textContent = 'Done! ' + done + ' / ' + total + ' completed.';
                            return;
                        }

                        var btn = buttons.shift();
                        refreshChain(btn).then(function() {
                            done++;
                            if (refreshAllStatus) refreshAllStatus.textContent = done + ' / ' + total;
                            next();
                        });
                    }

                    next();
                });
            }
        })();
        </script>
        <?php
    }
}
