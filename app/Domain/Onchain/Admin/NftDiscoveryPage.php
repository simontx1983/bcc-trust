<?php

namespace BCC\Trust\Onchain\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Admin\Views\NftCapabilityEditorPanel;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryService;
use BCC\Trust\Onchain\Services\NftCapabilityEditor;
use BCC\Trust\Onchain\Services\NftDiscoveryControlPlaneSnapshot;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\CosmwasmPassReport;
use BCC\Trust\Onchain\Support\CosmwasmPassStopReason;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\Services\ManualCollectionIntakeService;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;

/**
 * Admin page: NFT Discovery — the per-chain control plane.
 *
 * ── WHAT THIS PAGE IS ───────────────────────────────────────────────────
 * The one place that answers, for every chain BCC knows about: what NFT
 * work can this chain actually do, which driver would do it, is that driver
 * enabled, is it configured, and if the answer is no — WHICH no.
 *
 * It is the first consumer of the capability model
 * ({@see NftChainCapability}, {@see NftDriverRegistry},
 * {@see \BCC\Trust\Onchain\Support\NftProviderReadiness}), which shipped
 * deliberately unread so that the surface reading it could be reviewed on
 * its own.
 *
 * ── IT ABSORBED THE CHAINS SUB-TAB; IT DID NOT DUPLICATE IT ─────────────
 * `?page=bcc-onchain-chains&subtab=nft-discovery` used to render the
 * CosmWasm/CW-721 engine section and owned six admin-post routes. All of it
 * moved HERE, and the old sub-tab is gone — a second "NFT Discovery"
 * surface beside the first would be a §11 violation and, worse, two places
 * an operator could read a different answer about one chain.
 *
 * Every moved ROUTE STRING is byte-identical to what it was
 * (`bcc_chain_cw_pause`, …). Nonce actions are still `<route>_<chainId>`,
 * the audit vocabulary is unchanged, and a bookmarked form still posts to a
 * route that exists. Only the destination of the PRG redirect changed, from
 * `subtab=nft-discovery` to `family=cosmos`, and
 * {@see maybe_redirect_legacy_url()} keeps the old URL working.
 *
 * ── WHAT IT CANNOT DO, AND WHY THAT IS THE POINT ────────────────────────
 * This page is READ-ONLY about capability. It has no writer for
 * `bcc_supports_nft_collections`, none for
 * `manual_collection_discovery_enabled`, and none for a driver override
 * row. It explains those values; it cannot change them, and it cannot seed
 * one. The editor that changes them is a later, separately reviewed change.
 *
 * A consequence worth stating plainly, because an operator will meet it
 * first: both capability columns are `DEFAULT 0` with no backfill and no
 * writer anywhere in this build, so on a stock install every chain reads
 * `no_bcc_support` and the backfill control is not offered. That is the
 * intended fail-closed state, not a defect, and the page names the exact
 * missing permission rather than showing a dead button.
 *
 * ── AND WHAT IT MUST NEVER GROW ─────────────────────────────────────────
 * No cron hook, no `wp_schedule_event`, no `register()` on
 * {@see CosmwasmDiscoveryWorker}, no REST or AJAX route that reaches a
 * discovery entry point. Automatic collection discovery was retired
 * deliberately; the only sanctioned way in is admin-post + capability +
 * POST-only + a route-and-chain-scoped nonce, which is what this file
 * implements and what its tests pin.
 */
class NftDiscoveryPage
{
    const PAGE_SLUG = 'bcc-onchain-nft-discovery';

    /**
     * Where this surface used to live.
     *
     * Kept as constants rather than literals so the compatibility redirect
     * and its test name the same thing, and so grepping for the old
     * location finds the one place that still knows about it.
     */
    public const LEGACY_PAGE_SLUG = 'bcc-onchain-chains';
    public const LEGACY_SUBTAB    = 'nft-discovery';

    public static function register_page(): void
    {
        add_submenu_page(
            'bcc-system-health',
            'NFT Discovery',
            'NFT Discovery',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

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
     * The four CosmWasm scanner OPERATIONS.
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
     * Backfill on a different chain.
     */
    public const ACTION_CW_PAUSE    = 'bcc_chain_cw_pause';
    public const ACTION_CW_RESUME   = 'bcc_chain_cw_resume';
    public const ACTION_CW_BACKFILL = 'bcc_chain_cw_backfill';
    public const ACTION_CW_RETRY    = 'bcc_chain_cw_retry';

    /**
     * Retry bound, unchanged by the move.
     *
     * Up to 100 unresolved code families AND up to 100 unresolved
     * contracts — two separate limits, not a shared budget.
     */
    private const FORCE_RETRY_LIMIT = 100;

    /**
     * The admin backfill slice budget, unchanged by the move.
     *
     * Deliberately SMALLER than a cron tick because this one executes
     * inside an admin page load. The worker owns the downstream reserve
     * floors; this handler supplies the budget object and does not reserve
     * against it.
     */
    private const ADMIN_BACKFILL_REQUESTS = 20;
    private const ADMIN_BACKFILL_SECONDS  = 8;

    /**
     * Where a finished run's report waits for the redirect to land.
     *
     * ── WHY A TRANSIENT AND NOT THE URL ─────────────────────────────────
     * The PRG allowlist below carries a bounded result code and nothing
     * else — no chain id, no submitted value, no provider response. A run
     * report is all three of those things, so it cannot travel in the
     * query string without dismantling the rule that keeps the destination
     * un-resubmittable and free of upstream text.
     *
     * So it goes in a short-lived transient and the URL carries only
     * `bcc_run` — 16 opaque hex characters naming it. Same mechanism
     * {@see VerifyCollectionsPage} already uses for its richer notices.
     *
     * ── SCOPED BY OPERATOR *AND* BY INVOCATION ──────────────────────────
     * The key is built from both, and both matter for different reasons.
     *
     * Per operator, because two administrators acting at once must not read
     * each other's results.
     *
     * Per invocation, because a report is not the operator's "latest run" —
     * it belongs to ONE redirect. Keyed on the user alone, a backfill whose
     * landing was never visited leaves a report sitting there, and the next
     * pause, resume or refused backfill picks it up and presents an older
     * run's figures as its own result. So a landing displays a report only
     * when it NAMES one, and only a run that actually reached the worker
     * gets a name to carry.
     *
     * It is read ONCE and deleted, so a refresh does not re-show a run that
     * is no longer happening.
     */
    private const RUN_REPORT_TRANSIENT_PREFIX = 'bcc_nftd_run_';
    private const RUN_REPORT_TTL              = 120;

    /**
     * The CAPABILITY EDITOR routes — the only sanctioned way any of the
     * three capability values is written.
     *
     * ── EIGHT ROUTES, NOT THREE TOGGLES ─────────────────────────────────
     * Every one names its direction, for the reason this file has argued
     * since the CosmWasm opt-in was written: a toggle takes its direction
     * from the state the page held at RENDER time, so a tab left open across
     * somebody else's change applies the opposite of what its operator is
     * looking at. The route names the direction and the nonce is bound to
     * it, so a stale submit is a no-op instead of a reversal.
     *
     * The four driver routes bind their nonce to the DRIVER and OPERATION as
     * well as the chain, so a nonce minted to disable `cw721_lcd` for
     * `metadata` cannot authorise anything else — including the stale
     * removal, which is the one route whose strings are not registry-checked.
     *
     * There is deliberately no bulk route, no family-wide route and no
     * "enable everything" route. Capability is granted one decision at a
     * time or not at all.
     */
    public const ACTION_CAP_PRODUCT_ENABLE  = 'bcc_nft_cap_product_enable';
    public const ACTION_CAP_PRODUCT_DISABLE = 'bcc_nft_cap_product_disable';
    public const ACTION_CAP_MANUAL_ENABLE   = 'bcc_nft_cap_manual_enable';
    public const ACTION_CAP_MANUAL_DISABLE  = 'bcc_nft_cap_manual_disable';
    public const ACTION_CAP_DRIVER_DISABLE  = 'bcc_nft_cap_driver_disable';
    public const ACTION_CAP_DRIVER_ENABLE   = 'bcc_nft_cap_driver_enable';
    public const ACTION_CAP_DRIVER_INHERIT  = 'bcc_nft_cap_driver_inherit';
    public const ACTION_CAP_STALE_REMOVE    = 'bcc_nft_cap_stale_remove';

    /**
     * PR 6: the ONE manual collection-intake route.
     *
     * Replaces `bcc_vc_add_collection` and `bcc_vc_add_cosmos` on Verify
     * Collections, neither of which is registered any more. The nonce is
     * bound to the CHAIN (`<route>_<chainId>`), so an add authorised for
     * one chain cannot be replayed against another.
     */
    public const ACTION_ADD_COLLECTION = 'bcc_nftd_add_collection';

    public static function register_actions(): void
    {
        add_action('admin_post_' . self::ACTION_CAP_PRODUCT_ENABLE,  [self::class, 'handle_cap_product_enable']);
        add_action('admin_post_' . self::ACTION_CAP_PRODUCT_DISABLE, [self::class, 'handle_cap_product_disable']);
        add_action('admin_post_' . self::ACTION_CAP_MANUAL_ENABLE,   [self::class, 'handle_cap_manual_enable']);
        add_action('admin_post_' . self::ACTION_CAP_MANUAL_DISABLE,  [self::class, 'handle_cap_manual_disable']);
        add_action('admin_post_' . self::ACTION_CAP_DRIVER_DISABLE,  [self::class, 'handle_cap_driver_disable']);
        add_action('admin_post_' . self::ACTION_CAP_DRIVER_ENABLE,   [self::class, 'handle_cap_driver_enable']);
        add_action('admin_post_' . self::ACTION_CAP_DRIVER_INHERIT,  [self::class, 'handle_cap_driver_inherit']);
        add_action('admin_post_' . self::ACTION_CAP_STALE_REMOVE,    [self::class, 'handle_cap_stale_remove']);
        add_action('admin_post_' . self::ACTION_ADD_COLLECTION,      [self::class, 'handle_add_collection']);

        add_action(
            'admin_post_' . self::ACTION_CW_DISCOVERY_ENABLE,
            [self::class, 'handle_cw_discovery_enable']
        );
        add_action(
            'admin_post_' . self::ACTION_CW_DISCOVERY_DISABLE,
            [self::class, 'handle_cw_discovery_disable']
        );

        add_action('admin_post_' . self::ACTION_CW_PAUSE,    [self::class, 'handle_cw_pause']);
        add_action('admin_post_' . self::ACTION_CW_RESUME,   [self::class, 'handle_cw_resume']);
        add_action('admin_post_' . self::ACTION_CW_BACKFILL, [self::class, 'handle_cw_backfill']);
        add_action('admin_post_' . self::ACTION_CW_RETRY,    [self::class, 'handle_cw_retry']);

        // Bookmarks, browser history and any link written before the move.
        add_action('admin_init', [self::class, 'maybe_redirect_legacy_url']);
    }

    /**
     * Send the retired Chains sub-tab URL here.
     *
     * ── WHY admin_init AND NOT THE PAGE CALLBACK ────────────────────────
     * A submenu page callback runs after wp-admin has already sent headers
     * and printed the chrome, so a redirect from there is too late.
     * `admin_init` fires before any of that.
     *
     * ── WHAT IT CARRIES, AND WHAT IT REFUSES TO ─────────────────────────
     * Only the three notice keys, so a PRG landing that was in flight when
     * this shipped — or a tab left open across the deploy — still shows its
     * result. Everything else is dropped: the old URL could carry a stale
     * `subtab`, and forwarding arbitrary query args would let this redirect
     * be used to smuggle values onto the new page.
     *
     * The capability check is not authorization — this changes nothing —
     * but there is no reason to hand a logged-out visitor a map of the
     * admin surface, and `wp_safe_redirect` refuses off-host targets.
     */
    public static function maybe_redirect_legacy_url(): void
    {
        $page   = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        $subtab = isset($_GET['subtab']) ? sanitize_key((string) $_GET['subtab']) : '';

        if ($page !== self::LEGACY_PAGE_SLUG || $subtab !== self::LEGACY_SUBTAB) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $args = [
            'page'   => self::PAGE_SLUG,
            'family' => NftDiscoveryControlPlaneSnapshot::FAMILY_COSMOS,
        ];

        // A 302, not a 301. The old URL is retired, but a permanent
        // redirect is cached by the browser indefinitely and would be a
        // nuisance to undo if this move ever needed reverting.
        foreach (['bcc_cwd', 'bcc_cwo', 'bcc_ref'] as $key) {
            if (isset($_GET[$key]) && is_scalar($_GET[$key])) {
                $args[$key] = sanitize_text_field((string) $_GET[$key]);
            }
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')), 302);
        exit;
    }

    // ── CosmWasm scanner operations ─────────────────────────────────────

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
            // An exception AFTER the report was stored would otherwise send
            // the operator a run reference alongside a generic failure
            // notice, inviting them to read a report for a request that did
            // not finish. The error landing shows the correlation id and
            // nothing else; the orphaned report simply expires.
            self::$pendingRunReference = '';

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
                return self::apply_cw_backfill($chainId, $chain);
            case self::ACTION_CW_RETRY:
                return self::apply_cw_retry($chainId);
        }

        // Unreachable: handle_cw_operation() is only ever called with the
        // four route constants. It throws rather than falling through to
        // `unknown_chain`, because that code says "the chain id you sent
        // does not exist" — a confident, wrong explanation for what is
        // actually a wiring bug here.
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
     * ── THE CAPABILITY GATE IS NEW; EVERY OLD GATE IS STILL HERE ────────
     * This control used to run on the environment constants and the pause
     * state alone. It now passes the capability model FIRST, for the exact
     * operation and the exact driver:
     *
     *   OP_READY on `enumeration` proves, in one answer, that the override
     *   store was readable, that both capability columns are present, that
     *   the chain is not measured-unsupported, that BCC supports NFT
     *   collections here, that a driver is registered, that no override
     *   disabled it, that the MANUAL PERMISSION is granted, and that at
     *   least one driver is ready.
     *
     *   The `in_array` after it proves the specific driver this button
     *   drives — `cosmwasm_enumeration` — is one of the ready ones. A chain
     *   whose only ready enumeration driver was something else would
     *   otherwise pass a check it has no business passing.
     *
     * Provider readiness ALONE is deliberately not sufficient: a configured
     * LCD endpoint on a chain nobody granted the manual permission for is
     * still a refusal, and it is refused with a reason that names the
     * permission rather than the endpoint.
     *
     * ── THE GATES ARE RE-CHECKED HERE ───────────────────────────────────
     * The button is not rendered without them, but an absent button is a UI
     * fact, not authorization: a crafted POST must reach the same
     * fail-closed answer.
     *
     * ── WHAT THE AUDIT CAN NOW HONESTLY CLAIM ───────────────────────────
     * `runBackfillForChain()` used to return void, so the durable event had
     * to be `..._requested`. It now returns a `PASS_*` outcome and fills a
     * {@see CosmwasmPassReport}, so the event says which of `ran`,
     * `locked`, `skipped` actually happened. That is a claim the contract
     * supports; `_completed` still is not, because a `ran` pass may have
     * been cut short by its own budget.
     *
     * ── THE BUDGET IS CONSTRUCTED ONCE AND HANDED OVER ──────────────────
     * This handler does not call reserve() or available(), and does not
     * reproduce the worker's downstream floors. Second-guessing them here
     * is how the two copies drift apart.
     *
     * @param object $chain the authoritative row, already resolved
     */
    private static function apply_cw_backfill(int $chainId, object $chain): string
    {
        // ── THE CAPABILITY GATE ─────────────────────────────────────────
        $matrix     = NftChainCapability::operationMatrix($chain);
        $operations = is_array($matrix['operations'] ?? null) ? $matrix['operations'] : [];
        $enumeration = is_array($operations[NftDriverRegistry::OP_ENUMERATION] ?? null)
            ? $operations[NftDriverRegistry::OP_ENUMERATION]
            : [];

        $status = is_string($enumeration['status'] ?? null)
            ? (string) $enumeration['status']
            : NftChainCapability::OP_UNKNOWN;

        if (!NftChainCapability::isOperationReady($status)) {
            \BCC\Core\Log\Logger::info('[bcc-trust] CosmWasm backfill refused by the capability model', [
                'action'   => 'cosmwasm_scanner_backfill_capability_refused',
                'chain_id' => $chainId,
                'status'   => $status,
                'reason'   => is_string($enumeration['reason'] ?? null) ? $enumeration['reason'] : '',
                'operator' => get_current_user_id(),
            ]);

            return 'backfill_' . $status;
        }

        $ready = is_array($enumeration['ready'] ?? null) ? $enumeration['ready'] : [];
        if (!in_array(NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION, $ready, true)) {
            \BCC\Core\Log\Logger::info('[bcc-trust] CosmWasm backfill refused — its own driver is not ready', [
                'action'   => 'cosmwasm_scanner_backfill_driver_not_ready',
                'chain_id' => $chainId,
                'operator' => get_current_user_id(),
            ]);

            return 'backfill_driver_not_ready';
        }

        // ── EVERY PRE-EXISTING GATE, UNCHANGED ──────────────────────────
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

        // Deltas come from the SAME aggregate the engine section prints, so
        // the run report and the table under it cannot disagree about what
        // this chain holds.
        $before = CosmwasmDiscoveryService::chainSummary($chainId);

        $budget  = new CosmwasmTickBudget(self::ADMIN_BACKFILL_REQUESTS, self::ADMIN_BACKFILL_SECONDS);
        $report  = new CosmwasmPassReport();
        $outcome = CosmwasmDiscoveryWorker::runBackfillForChain($chainId, $budget, $report);

        $after = CosmwasmDiscoveryService::chainSummary($chainId);

        // The reference is parked for the redirect. This line is reached
        // ONLY by a backfill that got past every gate and actually called
        // the worker — which is precisely what makes `bcc_run` mean "this
        // redirect is the one that ran".
        self::$pendingRunReference = self::store_run_report(
            $chainId,
            $outcome,
            $budget,
            $report,
            $before,
            $after
        );

        AdminActionSupport::audit('admin_chain_cw_backfill_' . $outcome, 'chain', $chainId);

        \BCC\Core\Log\Logger::info('[bcc-trust] CosmWasm scanner backfill slice finished', [
            'action'    => 'cosmwasm_scanner_backfill_slice',
            'chain_id'  => $chainId,
            'outcome'   => $outcome,
            'requests'  => $budget->spent(),
            'operator'  => get_current_user_id(),
        ]);

        return 'backfill_' . $outcome;
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
     * Deliberately does NOT touch `classifier_version`: the version bump is
     * the one and only staleness-requeue mechanism, and a second one here
     * would make "why did this requeue?" unanswerable.
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
     * Same allowlist discipline the retired sub-tab had, with `subtab`
     * replaced by `family`: page, family, a bounded result code, an
     * optional correlation id, and an optional opaque run reference. No
     * chain id under any name, no action, no nonce, no direction, no
     * submitted value, no provider response, no scanner error text.
     *
     * ── `bcc_run` IS A NAME, NOT A PAYLOAD ──────────────────────────────
     * It is 16 hex characters identifying a report held server-side for
     * this operator. It carries no counts, no endpoint, no error text and
     * nothing an operator submitted — the report itself never enters the
     * URL, which is the whole reason it lives in a transient.
     *
     * @var list<string> OPERATION_REDIRECT_KEYS
     */
    public const OPERATION_REDIRECT_KEYS = ['page', 'family', 'bcc_cwo', 'bcc_ref', 'bcc_run'];

    private static function redirect_cw_operation(string $result, string $ref = ''): never
    {
        $args = [
            'page'    => self::PAGE_SLUG,
            'family'  => NftDiscoveryControlPlaneSnapshot::FAMILY_COSMOS,
            'bcc_cwo' => $result,
        ];
        if ($ref !== '') {
            $args['bcc_ref'] = $ref;
        }

        // Present only when a run actually stored a report. Pause, resume,
        // retry and every backfill refused before the worker ran leave
        // this empty, so their landings cannot pick up somebody's
        // unvisited report and present it as their own result.
        if (self::$pendingRunReference !== '') {
            $args['bcc_run'] = self::$pendingRunReference;
        }

        AdminActionSupport::redirect($args);
    }

    // ── CosmWasm / CW-721 discovery opt-in ──────────────────────────────

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

        // FAILURE POLICY:
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
     * have been verified for a target that does not parse.
     *
     * ── AND WHY IT NEVER TOUCHES THE RAW VALUE ──────────────────────────
     * `$_POST['chain_id']` may be an array, a very long string, or contain
     * CR/LF. `is_scalar()` rejects the array before any cast (an array-to-int
     * cast raises a PHP warning and yields 1 — a valid-looking chain id from
     * pure garbage). Nothing derived from the raw value reaches the wp_die
     * message, so it cannot be reflected back.
     *
     * ── `\z` AND NOT `$` ────────────────────────────────────────────────
     * In PCRE, `$` matches at the end of the subject OR immediately before
     * a trailing newline. So `/^[1-9][0-9]*$/` ACCEPTS "4\n" — a value that
     * looks validated, casts to 4, and carries a control character that a
     * caller could rely on being stripped. `\z` matches only the true end
     * of the subject, so a trailing newline is what it is: not a chain id.
     * The same anchor is used by {@see require_key_shape()} and
     * {@see require_priority_shape()}, where the value is used as a STRING
     * and the newline would survive into the nonce action and the domain.
     */
    private static function require_chain_id_shape(): int
    {
        $raw = $_POST['chain_id'] ?? null;

        $valid = is_scalar($raw)
            && preg_match('/^[1-9][0-9]{0,17}\z/', (string) $raw) === 1;

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
     * responsible for, whereas this decides whether the scanner is
     * responsible for the chain at all.
     *
     * It is also NOT the NFT capability model's
     * `manual_collection_discovery_enabled`. That one is a permission to
     * START a discovery and is READ-ONLY in this build. This one is the
     * CosmWasm engine's own per-chain opt-in and has had a writer for as
     * long as the engine has existed. Two different switches, deliberately
     * not merged.
     *
     * Enabling starts nothing. The environment gate, the canary allowlist
     * and the chain's own measured wasm capability all still apply, and
     * everything discovered still arrives UNVERIFIED.
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
     * PRG terminator for the discovery opt-in.
     *
     * ── THE DESTINATION CARRIES NO TARGET ───────────────────────────────
     * The allowlist below is the whole contract —
     *
     *   page     fixed
     *   family   fixed
     *   bcc_cwd  a bounded result code from a closed set
     *   bcc_ref  a sanitized correlation id, exceptions only
     *
     * — and nothing else. No chain id under any name, no action, no nonce,
     * no direction, no submitted value.
     *
     * The cost is that the operator notice is generic. That is the right
     * trade: the DURABLE AUDIT ROW carries the real chain target.
     *
     * @var list<string> REDIRECT_KEYS the only keys this destination may carry
     */
    public const REDIRECT_KEYS = ['page', 'family', 'bcc_cwd', 'bcc_ref'];

    private static function redirect_cw_discovery(string $result, string $ref = ''): never
    {
        $args = [
            'page'    => self::PAGE_SLUG,
            'family'  => NftDiscoveryControlPlaneSnapshot::FAMILY_COSMOS,
            'bcc_cwd' => $result,
        ];
        if ($ref !== '') {
            $args['bcc_ref'] = $ref;
        }

        AdminActionSupport::redirect($args);
    }

    // ── The capability editor ───────────────────────────────────────────

    public static function handle_cap_product_enable(): void
    {
        self::handle_cap_flag(self::ACTION_CAP_PRODUCT_ENABLE);
    }

    public static function handle_cap_product_disable(): void
    {
        self::handle_cap_flag(self::ACTION_CAP_PRODUCT_DISABLE);
    }

    public static function handle_cap_manual_enable(): void
    {
        self::handle_cap_flag(self::ACTION_CAP_MANUAL_ENABLE);
    }

    public static function handle_cap_manual_disable(): void
    {
        self::handle_cap_flag(self::ACTION_CAP_MANUAL_DISABLE);
    }

    public static function handle_cap_driver_disable(): void
    {
        self::handle_cap_driver(self::ACTION_CAP_DRIVER_DISABLE);
    }

    public static function handle_cap_driver_enable(): void
    {
        self::handle_cap_driver(self::ACTION_CAP_DRIVER_ENABLE);
    }

    public static function handle_cap_driver_inherit(): void
    {
        self::handle_cap_driver(self::ACTION_CAP_DRIVER_INHERIT);
    }

    public static function handle_cap_stale_remove(): void
    {
        self::handle_cap_driver(self::ACTION_CAP_STALE_REMOVE);
    }

    /**
     * Request boundary for the two chain-flag pairs.
     *
     * Order, and every step of it is load-bearing:
     *
     *   refusal trace (server-known values only)
     *   → capability          reachable via admin-post without the page
     *   → POST-only           admin-post dispatches GET too
     *   → chain-id SHAPE      no lookup: the nonce action is built from it
     *   → direction-and-chain nonce
     *   → the domain          which does the authoritative lookup itself
     *   → PRG
     *
     * Nothing touches a repository before the nonce has proven the request
     * authentic, and the shape check does no lookup — so an unauthenticated
     * POST cannot probe which chain ids exist.
     */
    private static function handle_cap_flag(string $route): never
    {
        // The trace carries NOTHING from the request. `chain_id` is
        // attacker-controlled and unvalidated at this point: echoing it
        // would let an unauthenticated caller write our log, and would
        // answer "does chain 41 exist?" for anyone who can POST.
        if (!current_user_can('manage_options')) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] NFT capability change refused', [
                'action'   => 'nft_capability_edit_denied',
                'route'    => self::cap_route_slug($route),
                'operator' => get_current_user_id(),
            ]);
        }

        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        $chainId = self::require_chain_id_shape();

        AdminActionSupport::requireNonce($route . '_' . $chainId);

        try {
            $result = match ($route) {
                self::ACTION_CAP_PRODUCT_ENABLE  => NftCapabilityEditor::enableProductSupport($chainId),
                self::ACTION_CAP_PRODUCT_DISABLE => NftCapabilityEditor::disableProductSupport($chainId),
                self::ACTION_CAP_MANUAL_ENABLE   => NftCapabilityEditor::enableManualDiscovery($chainId),
                self::ACTION_CAP_MANUAL_DISABLE  => NftCapabilityEditor::disableManualDiscovery($chainId),
                default                          => NftCapabilityEditor::RESULT_UNKNOWN_CHAIN,
            };
        } catch (\Throwable $e) {
            // The correlation id goes to the LOG ONLY. Unlike the scanner
            // routes above, the capability PRG carries no reference: see
            // redirect_capability().
            AdminActionSupport::failure(
                $e,
                'admin_nft_capability_error',
                'chain',
                $chainId
            );

            self::redirect_capability(self::CAP_RESULT_ERROR);
        }

        self::redirect_capability($result);
    }

    /**
     * Request boundary for the four driver-override routes.
     *
     * Same order as the flag routes, with the target widened: the nonce is
     * bound to `route + chain + operation + driver`, so a nonce minted for
     * one triple authorises exactly that triple and nothing else.
     *
     * ── SHAPE IS CHECKED BEFORE THE NONCE, AND SEPARATELY FROM MEANING ──
     * The operation and driver strings are part of the nonce ACTION, so
     * they must be read before the nonce can be verified. What is checked
     * here is only that they are plausible column values — a lowercase key
     * of at most 32 characters, which is what the storage holds. Whether
     * they name something this build implements is a DOMAIN question, and
     * it is answered by {@see NftCapabilityEditor} after the nonce passes,
     * because the stale-removal route deliberately accepts strings the
     * registry no longer recognises.
     */
    private static function handle_cap_driver(string $route): never
    {
        if (!current_user_can('manage_options')) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] NFT driver override refused', [
                'action'   => 'nft_capability_edit_denied',
                'route'    => self::cap_route_slug($route),
                'operator' => get_current_user_id(),
            ]);
        }

        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        $chainId   = self::require_chain_id_shape();
        $operation = self::require_key_shape('operation');
        $driverKey = self::require_key_shape('driver_key');

        // The priority is read for exactly one route, and BEFORE the nonce,
        // so a malformed value cannot reach the domain even with a valid
        // nonce. Shape only — the 0..1000 RANGE is the domain's to enforce,
        // and it refuses rather than clamping.
        $priority = $route === self::ACTION_CAP_DRIVER_ENABLE
            ? self::require_priority_shape()
            : 0;

        AdminActionSupport::requireNonce(
            $route . '_' . $chainId . '_' . $operation . '_' . $driverKey
        );

        try {
            $result = match ($route) {
                self::ACTION_CAP_DRIVER_DISABLE =>
                    NftCapabilityEditor::disableDriver($chainId, $operation, $driverKey),
                self::ACTION_CAP_DRIVER_ENABLE =>
                    NftCapabilityEditor::enableDriver($chainId, $operation, $driverKey, $priority),
                self::ACTION_CAP_DRIVER_INHERIT =>
                    NftCapabilityEditor::inheritDriver($chainId, $operation, $driverKey),
                self::ACTION_CAP_STALE_REMOVE =>
                    NftCapabilityEditor::removeStaleOverride($chainId, $operation, $driverKey),
                default => NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE,
            };
        } catch (\Throwable $e) {
            AdminActionSupport::failure(
                $e,
                'admin_nft_capability_error',
                'chain',
                $chainId
            );

            self::redirect_capability(self::CAP_RESULT_ERROR);
        }

        self::redirect_capability($result);
    }

    /** Short, fixed route name for a log line — derived from the ROUTE, never the request. */
    private static function cap_route_slug(string $route): string
    {
        return match ($route) {
            self::ACTION_CAP_PRODUCT_ENABLE  => 'product_enable',
            self::ACTION_CAP_PRODUCT_DISABLE => 'product_disable',
            self::ACTION_CAP_MANUAL_ENABLE   => 'manual_enable',
            self::ACTION_CAP_MANUAL_DISABLE  => 'manual_disable',
            self::ACTION_CAP_DRIVER_DISABLE  => 'driver_disable',
            self::ACTION_CAP_DRIVER_ENABLE   => 'driver_enable',
            self::ACTION_CAP_DRIVER_INHERIT  => 'driver_inherit',
            self::ACTION_CAP_STALE_REMOVE    => 'stale_remove',
            default                          => 'unknown',
        };
    }

    /**
     * The shape an operation or driver key must have to be looked at at all.
     *
     * Lowercase letters, digits and underscores, 1–32 characters — exactly
     * what `VARCHAR(32)` columns hold, and exactly the alphabet every real
     * operation and driver key uses.
     *
     * ── NOTHING IS SANITISED INTO VALIDITY ──────────────────────────────
     * A value is either already of this shape or the request is refused.
     * Running `sanitize_key()` over it first — which lowercases and strips
     * disallowed characters — would turn `Cw721_LCD!` into `cw721_lcd` and
     * accept a request nobody sent, and would turn a 200-character string
     * into a 200-character key that still fails but only at the database.
     * `is_scalar()` comes first so an ARRAY is rejected before any cast: an
     * array-to-string cast raises a warning and yields "Array", which is a
     * perfectly well-shaped-looking key.
     *
     * A real HTTP 400, not a redirect: a malformed target has no page to
     * send the operator back to, and no nonce could have been verified for
     * a triple that does not parse. Nothing derived from the raw value
     * reaches the response, so it cannot be reflected back.
     */
    private static function require_key_shape(string $field): string
    {
        $raw = $_POST[$field] ?? null;

        $valid = is_scalar($raw)
            && preg_match('/^[a-z0-9_]{1,32}\z/', (string) $raw) === 1;

        if (!$valid) {
            wp_die(
                esc_html__('Invalid capability target.', 'bcc-trust'),
                esc_html__('Bad Request', 'bcc-trust'),
                ['response' => 400]
            );
        }

        return (string) $raw;
    }

    /**
     * Shape-only validation of a driver priority.
     *
     * Up to four digits, no sign, no whitespace, no separators. That admits
     * 0–9999, which is deliberately WIDER than the accepted range: the
     * 0–1000 bound is a domain rule, and refusing 5000 with a bounded
     * `override_invalid_priority` notice tells an operator what the limit is,
     * where a 400 would only tell them the request was malformed.
     *
     * What this stops is the other thing: an array, a negative number, a
     * float, `1e3`, or a value long enough to overflow — none of which is a
     * priority anybody typed.
     */
    private static function require_priority_shape(): int
    {
        $raw = $_POST['priority'] ?? null;

        $valid = is_scalar($raw)
            && preg_match('/^[0-9]{1,4}\z/', (string) $raw) === 1;

        if (!$valid) {
            wp_die(
                esc_html__('Invalid priority.', 'bcc-trust'),
                esc_html__('Bad Request', 'bcc-trust'),
                ['response' => 400]
            );
        }

        return (int) $raw;
    }

    /**
     * PRG terminator for every capability edit.
     *
     * ── NARROWER THAN THE SCANNER ROUTES ABOVE, ON PURPOSE ──────────────
     * The destination carries three keys and no fourth:
     *
     *   page        fixed
     *   family      fixed
     *   bcc_nftcap  a bounded result code from a closed set that this
     *               codebase authors — see NftCapabilityEditor's constants
     *
     * No chain id under any name. No operation, no driver key, no priority,
     * no submitted value, no exception text, and — unlike
     * {@see redirect_cw_discovery()} — not even a correlation reference.
     * The scanner routes carry `bcc_ref` because they report on WORK that
     * ran; a capability edit reports on a CONFIGURATION CHANGE, its failure
     * modes are ours rather than a provider's, and the fewer things this URL
     * can carry the less there is to reason about. The correlation id is
     * still minted and still written to the file log under the durable audit
     * row; it simply does not travel in the browser.
     *
     * The cost is that the notice is generic and the editor closes. The
     * DURABLE AUDIT ROW carries the real chain target, which is where
     * "which chain was that?" is answered.
     *
     * @var list<string> CAPABILITY_REDIRECT_KEYS the only keys this destination may carry
     */
    public const CAPABILITY_REDIRECT_KEYS = ['page', 'family', 'bcc_nftcap'];

    /** The one result code that is the PAGE's rather than the editor's. */
    public const CAP_RESULT_ERROR = 'error';

    private static function redirect_capability(string $result): never
    {
        AdminActionSupport::redirect([
            'page'       => self::PAGE_SLUG,
            'family'     => self::current_family(),
            'bcc_nftcap' => $result,
        ]);
    }

    /**
     * The family tab to land on.
     *
     * Read from the SUBMITTED form, because the four families are a closed
     * set this class owns and an unrecognised value falls back to the
     * default — so the worst a hostile value achieves is landing the
     * operator on the Cosmos tab. It is navigation, not a target: the write
     * has already happened, and nothing downstream reads this.
     */
    private static function current_family(): string
    {
        $family = isset($_POST['family']) && is_scalar($_POST['family'])
            ? sanitize_key((string) $_POST['family'])
            : '';

        return NftDiscoveryControlPlaneSnapshot::isFamily($family)
            ? $family
            : NftDiscoveryControlPlaneSnapshot::DEFAULT_FAMILY;
    }

    // ── The run report ──────────────────────────────────────────────────

    /**
     * The opaque reference minted by the run that is about to redirect.
     *
     * ── WHY A STATIC AND NOT A RETURN VALUE ─────────────────────────────
     * `apply_cw_operation()` returns one string — the PRG result code — to
     * a shared boundary that drives four different operations. Widening
     * that return type so ONE of the four can carry a second value would
     * push a backfill-specific concern into the dispatcher every operation
     * goes through.
     *
     * So the reference is parked here instead, by the only method that
     * mints one, and read by the only method that redirects. It is set
     * AFTER the report is stored, which is what makes the guarantee below
     * structural rather than a matter of remembering: pause, resume, retry
     * and every backfill refused before the worker ran never reach the
     * line that sets it, so they cannot carry `bcc_run`.
     */
    private static string $pendingRunReference = '';

    /**
     * The transient key for ONE report belonging to ONE operator.
     *
     * ── BOTH HALVES ARE LOAD-BEARING ────────────────────────────────────
     * The user id scopes the report to the administrator who ran it: two
     * operators acting at once must not read each other's results, and a
     * reference leaking out of one browser must not open another's.
     *
     * The reference scopes it to ONE INVOCATION. Keyed on the user alone,
     * a report stored by a backfill that its operator never looked at
     * would still be sitting there on their next visit — and would render
     * beside whatever they did next, presenting a stale run as though the
     * pause or retry they just performed had produced it.
     */
    private static function run_report_key(string $reference): string
    {
        return self::RUN_REPORT_TRANSIENT_PREFIX . get_current_user_id() . '_' . $reference;
    }

    /**
     * The shape a run reference must have to be looked up at all.
     *
     * 16 hex characters. Checked BEFORE the store is touched, so a
     * malformed value cannot reach a key builder, and cannot consume or
     * disturb a real pending report.
     */
    private const RUN_REFERENCE_PATTERN = '/^[0-9a-f]{16}$/';

    /**
     * A bounded, opaque, single-use reference.
     *
     * Opaque on purpose: it identifies a report and describes nothing. It
     * is the only thing about a run that travels in the URL, which is what
     * lets the PRG allowlist stay a list of bounded codes.
     */
    private static function mint_run_reference(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            // A reference only needs to be unguessable enough to scope a
            // 120-second, user-keyed lookup. If the CSPRNG is unavailable
            // the run still has to be reportable.
            return substr(md5(uniqid('', true)), 0, 16);
        }
    }

    /**
     * Park a finished run's report for the redirect to pick up.
     *
     * ── EVERY NUMBER HERE IS OBSERVED, NOT ASSUMED ──────────────────────
     * The outcome comes from the worker, the spend from the budget object
     * the worker was handed, the page counts from the report the worker
     * filled, and the deltas from two reads of the same aggregate the
     * engine table prints. Nothing is inferred from "the call returned".
     *
     * Returns the opaque reference the redirect must carry to find it
     * again. A caller that discards the reference has stored a report
     * nobody can reach — which is the correct outcome for a run whose
     * redirect never happens.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return string the opaque run reference
     */
    private static function store_run_report(
        int $chainId,
        string $outcome,
        CosmwasmTickBudget $budget,
        CosmwasmPassReport $report,
        array $before,
        array $after
    ): string {
        $stopReason = CosmwasmPassStopReason::forOutcome($outcome, $budget);
        $reference  = self::mint_run_reference();

        set_transient(
            self::run_report_key($reference),
            [
                'chain_id'    => $chainId,
                'outcome'     => $outcome,
                'stop_reason' => $stopReason,
                // A pass is PARTIAL if it stopped for any reason other than
                // reaching its own conclusion, OR if it recorded an error on
                // the way. The second half matters: a provider refusal can
                // abort a walk with the budget barely touched, and the stop
                // reason alone would then read `pass_completed`.
                'partial'     => CosmwasmPassStopReason::isPartial($stopReason) || $report->errors !== [],
                'requests'    => [
                    'used'      => $budget->spent(),
                    'remaining' => $budget->remaining(),
                ],
                'pages'       => [
                    'code'      => $report->codePagesFetched,
                    'contracts' => $report->contractPagesFetched,
                    'total'     => $report->pagesFetched(),
                ],
                'classified'  => [
                    'families'  => $report->familiesClassified,
                    'contracts' => $report->contractsClassified,
                ],
                'collections' => [
                    'emitted' => $report->collectionsEmitted,
                    'denied'  => $report->collectionsDenied,
                ],
                'errors'      => $report->errors,
                'delta'       => [
                    'families_before'  => (int) ($before['families_total'] ?? 0),
                    'families_after'   => (int) ($after['families_total'] ?? 0),
                    'contracts_before' => (int) ($before['contracts_total'] ?? 0),
                    'contracts_after'  => (int) ($after['contracts_total'] ?? 0),
                    'state_before'     => (string) ($before['state'] ?? ''),
                    'state_after'      => (string) ($after['state'] ?? ''),
                ],
            ],
            self::RUN_REPORT_TTL
        );

        return $reference;
    }

    /**
     * Read ONE named run report, once, and delete it.
     *
     * ── THREE THINGS HAVE TO LINE UP ────────────────────────────────────
     * The reference must be well-formed, it must name a report that
     * exists, and the CURRENT operator must be the one who stored it —
     * because the key is built from both. Any of the three failing returns
     * null and touches nothing.
     *
     * "Touches nothing" is the part worth stating: a wrong or malformed
     * reference must not consume, shorten or disturb a report that is
     * legitimately pending. The malformed case is rejected before a key is
     * even built; the wrong-reference and wrong-operator cases build a
     * DIFFERENT key, which simply is not there.
     *
     * ── AND IT IS SINGLE USE ────────────────────────────────────────────
     * Deleted on the one successful read, so a refresh does not re-display
     * a run that finished minutes ago as though it had just happened.
     *
     * @return array<string, mixed>|null
     */
    private static function take_run_report(string $reference): ?array
    {
        if (preg_match(self::RUN_REFERENCE_PATTERN, $reference) !== 1) {
            return null;
        }

        $key    = self::run_report_key($reference);
        $stored = get_transient($key);

        if (!is_array($stored) || $stored === []) {
            return null;
        }

        delete_transient($key);

        return $stored;
    }

    // ── Render ──────────────────────────────────────────────────────────

    public static function render_page(): void
    {
        // Defense in depth: add_submenu_page() already gates on this
        // capability, but relying on menu registration alone is the gap
        // every sibling page has already closed.
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('Sorry, you are not allowed to access this page.', 'bcc-trust'),
                esc_html__('Forbidden', 'bcc-trust'),
                ['response' => 403]
            );
        }

        $family = isset($_GET['family'])
            ? sanitize_key((string) $_GET['family'])
            : NftDiscoveryControlPlaneSnapshot::DEFAULT_FAMILY;

        if (!NftDiscoveryControlPlaneSnapshot::isFamily($family)) {
            $family = NftDiscoveryControlPlaneSnapshot::DEFAULT_FAMILY;
        }

        $snapshot = NftDiscoveryControlPlaneSnapshot::buildForFamily($family);

        // Three independent notice sources, only one of which can be
        // present on any given PRG landing since each redirect carries its
        // own key.
        $notice = self::cw_discovery_notice_from_query()
            ?? self::cw_operation_notice_from_query()
            ?? self::capability_notice_from_query();

        // ── THE SELECTED CHAIN COMES FROM THE CANONICAL ROWS ────────────
        //
        // `?chain=` is a request value and is never trusted as an identity:
        // it selects among the rows the SNAPSHOT already built from
        // `ChainRepository::getAll()`, and an id that matches none of them
        // simply selects nothing. So the editor cannot be pointed at a chain
        // this family does not contain, at a chain that does not exist, or
        // at a row assembled from the query string.
        $selected = self::selected_chain($snapshot);

        // ── A REPORT IS SHOWN ONLY WHEN THIS LANDING NAMES ONE ──────────
        //
        // Keyed off `bcc_run` ALONE, never off the presence of a result
        // code. An earlier draft read the operator's pending report
        // whenever any `bcc_cwo` was set, which meant a report stored by a
        // backfill nobody looked at would surface beside the next pause,
        // resume, retry or refused backfill — presenting an older run's
        // figures as though that action had produced them.
        $runRef = isset($_GET['bcc_run']) ? sanitize_key((string) $_GET['bcc_run']) : '';
        $run    = $runRef !== '' ? self::take_run_report($runRef) : null;
        ?>
        <div class="wrap">
            <h1>NFT Discovery</h1>

            <p style="max-width:900px;">
                What each chain can actually do for NFTs, which driver would do it, and — when it
                cannot — exactly which permission, driver or credential is missing. Select a chain
                to edit the two permissions BCC controls and to narrow or reorder the drivers the
                code already offers.
            </p>

            <p style="max-width:900px;color:#646970;">
                <strong>Nothing on this page starts work.</strong> Granting product support does not
                start a discovery. Granting the manual permission does not start a discovery — it
                only allows an administrator to start one later. A driver override can narrow or
                reorder what the code already declares; it can never add a capability the build does
                not have. Provider readiness is observed here, never edited. The backfill is a
                separate, explicit action and appears only when every gate passes. Nothing here
                verifies a collection or creates a community.
            </p>

            <?php if ($notice !== null): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper" style="margin-bottom:16px">
                <?php foreach (NftDiscoveryControlPlaneSnapshot::FAMILIES as $key): ?>
                    <a href="<?php echo esc_url(add_query_arg(
                        ['page' => self::PAGE_SLUG, 'family' => $key],
                        admin_url('admin.php')
                    )); ?>"
                       class="nav-tab <?php echo $family === $key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html(NftDiscoveryControlPlaneSnapshot::familyLabel($key)); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($run !== null): ?>
                <?php self::render_run_report($run); ?>
            <?php endif; ?>

            <?php self::render_capability_matrix(
                $snapshot,
                $selected === null ? null : (int) ($selected['chain_id'] ?? 0)
            ); ?>

            <?php NftCapabilityEditorPanel::render($snapshot, $selected); ?>

            <?php
            // PR 6: the one manual intake form, chain-locked to this family.
            // Rendered AFTER the capability editor deliberately — when it is
            // refused, the control that fixes it is the thing directly above.
            self::add_result_notice(
                isset($_GET['bcc_nftadd']) ? sanitize_key((string) $_GET['bcc_nftadd']) : ''
            );
            self::render_add_collection($family, $snapshot['chains']);
            ?>

            <?php if ($snapshot['supports_enumeration_engine']): ?>
                <?php self::render_cw_discovery_section($snapshot['cw_chains']); ?>
            <?php else: ?>
                <?php self::render_no_enumeration_notice($snapshot); ?>
            <?php endif; ?>

            <?php self::render_wallet_refresh_method($snapshot); ?>
        </div>
        <?php
    }

    /**
     * The chain whose editor is open, chosen from the SNAPSHOT's own rows.
     *
     * ── A REQUEST VALUE SELECTS; IT NEVER IDENTIFIES ────────────────────
     * `?chain=` is compared against rows the snapshot already built from
     * `ChainRepository::getAll()`. It cannot introduce a chain, cannot reach
     * a chain of another family, and cannot produce a row of its own — an id
     * matching nothing selects nothing and the editor is simply not shown.
     *
     * This also means the editor and the matrix directly above it are the
     * same rows from the same read, so they cannot disagree about a chain
     * they are both describing.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>|null
     */
    private static function selected_chain(array $snapshot): ?array
    {
        $raw = $_GET['chain'] ?? null;
        if (!is_scalar($raw) || preg_match('/^[1-9][0-9]{0,17}\z/', (string) $raw) !== 1) {
            return null;
        }

        $wanted = (int) $raw;
        /** @var list<array<string, mixed>> $chains */
        $chains = is_array($snapshot['chains'] ?? null) ? $snapshot['chains'] : [];

        foreach ($chains as $chain) {
            if ((int) ($chain['chain_id'] ?? 0) === $wanted) {
                return $chain;
            }
        }

        return null;
    }

    /**
     * Rebuild the capability-editor notice from the PRG landing.
     *
     * Deliberately GENERIC about WHICH chain: the destination carries no
     * target (see {@see CAPABILITY_REDIRECT_KEYS}), so this cannot name one
     * and must not try. The durable audit row answers "which chain was
     * that?".
     *
     * Every sentence is written to be true of an action that CHANGED
     * CONFIGURATION and did nothing else. None of them may say a provider
     * ran, a collection was discovered, or a capability was proven ready —
     * because none of that happened, and a notice is the place an operator
     * is most likely to take our word for it.
     *
     * @return array{type: string, message: string}|null
     */
    private static function capability_notice_from_query(): ?array
    {
        $result = isset($_GET['bcc_nftcap']) ? sanitize_key((string) $_GET['bcc_nftcap']) : '';
        if ($result === '') {
            return null;
        }

        switch ($result) {
            // ── Product support ─────────────────────────────────────────
            case NftCapabilityEditor::RESULT_PRODUCT_ENABLED:
                return ['type' => 'success', 'message' =>
                    'NFT product support is now ON for that chain. This does NOT start a discovery and '
                    . 'does NOT permit one — the manual permission is a separate grant, and it was left '
                    . 'as it was. Nothing was contacted and no collection was touched.'];

            case NftCapabilityEditor::RESULT_PRODUCT_DISABLED:
                return ['type' => 'success', 'message' =>
                    'NFT product support is now OFF for that chain. Existing collections were kept and '
                    . 'nothing was unverified or removed; the chain simply reports no NFT capability.'];

            case NftCapabilityEditor::RESULT_PRODUCT_DISABLED_CASCADE:
                return ['type' => 'success', 'message' =>
                    'NFT product support is now OFF for that chain, AND the manual discovery permission '
                    . 'was cleared with it. That is deliberate: a permission left behind on an '
                    . 'unsupported chain is invisible until support is granted again, and would then '
                    . 'come back already permitted. Grant it again explicitly if you re-enable support. '
                    . 'Existing collections were kept.'];

            case NftCapabilityEditor::RESULT_PRODUCT_NOOP_ENABLED:
                return ['type' => 'info', 'message' =>
                    'NFT product support was already ON for that chain. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_PRODUCT_NOOP_DISABLED:
                return ['type' => 'info', 'message' =>
                    'NFT product support was already OFF for that chain, and no manual permission was '
                    . 'left set. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_PRODUCT_WRITE_FAILED:
                return ['type' => 'error', 'message' =>
                    'Product support could NOT be changed — the database write failed and nothing was '
                    . 'changed. Check the bcc-trust error log.'];

            case NftCapabilityEditor::RESULT_PRODUCT_UNVERIFIED:
                return ['type' => 'error', 'message' =>
                    'The change was attempted but could NOT be confirmed: the stored value does not read '
                    . 'back as expected, so this is not being reported as done. Nothing else was touched '
                    . '— no discovery, no provider, no collection. Reload to see the current state and '
                    . 'check the bcc-trust error log.'];

            // ── Manual discovery permission ─────────────────────────────
            case NftCapabilityEditor::RESULT_MANUAL_ENABLED:
                return ['type' => 'success', 'message' =>
                    'An administrator may now START a chain-wide discovery on that chain. Nothing was '
                    . 'started by this action and nothing is scheduled — the permission only allows the '
                    . 'button. Every other gate still applies: the driver must be registered and '
                    . 'enabled, its provider configured, and the chain must not be measured as having '
                    . 'no CosmWasm module.'];

            case NftCapabilityEditor::RESULT_MANUAL_DISABLED:
                return ['type' => 'success', 'message' =>
                    'The manual discovery permission was withdrawn for that chain. No administrator can '
                    . 'start a chain-wide discovery on it. Existing collections and progress were kept.'];

            case NftCapabilityEditor::RESULT_MANUAL_NOOP_ENABLED:
                return ['type' => 'info', 'message' =>
                    'That chain already permitted operator-started discovery. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_MANUAL_NOOP_DISABLED:
                return ['type' => 'info', 'message' =>
                    'That chain did not permit operator-started discovery. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_MANUAL_NO_PRODUCT:
                return ['type' => 'warning', 'message' =>
                    'The manual permission was refused because BCC product support for NFT collections '
                    . 'is currently OFF for that chain. Grant product support first — it is a separate '
                    . 'decision, and it starts nothing on its own. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_MANUAL_NO_STARTABLE:
                return ['type' => 'warning', 'message' =>
                    'The manual permission was refused: no driver in this build can perform an '
                    . 'administrator-started operation on that chain, so the permission could not '
                    . 'authorise anything. This is a structural limit, not a configuration gap — no '
                    . 'credential or setting adds chain-wide enumeration to EVM or Solana. Nothing was '
                    . 'changed.'];

            case NftCapabilityEditor::RESULT_MANUAL_WRITE_FAILED:
                return ['type' => 'error', 'message' =>
                    'The manual permission could NOT be changed — the database write failed and nothing '
                    . 'was changed. Check the bcc-trust error log.'];

            case NftCapabilityEditor::RESULT_MANUAL_UNVERIFIED:
                return ['type' => 'error', 'message' =>
                    'The permission change was attempted but could NOT be confirmed: the stored value '
                    . 'does not read back as expected, so this is not being reported as done. Nothing '
                    . 'was started. Reload to see the current state and check the bcc-trust error log.'];

            // ── Driver overrides ────────────────────────────────────────
            case NftCapabilityEditor::RESULT_OVERRIDE_DISABLED:
                return ['type' => 'success', 'message' =>
                    'That driver is now switched OFF for that operation on that chain. The capability '
                    . 'table above has been rebuilt from the stored state — if it was the only driver, '
                    . 'the operation now reads Disabled.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_ENABLED:
                return ['type' => 'success', 'message' =>
                    'That driver is switched ON for that operation at the priority you set (lower runs '
                    . 'first). An override can only restore or reorder a driver the code already offers '
                    . '— it cannot add one, and it does not make an unconfigured provider ready.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_INHERITED:
                return ['type' => 'success', 'message' =>
                    'The override row was removed, so that driver follows the code registry again — '
                    . 'including its priority, now and after any future change to it.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_NOOP:
                return ['type' => 'info', 'message' =>
                    'That driver was already in the state you asked for. Nothing was changed and no '
                    . 'row was written.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_UNREADABLE:
                return ['type' => 'error', 'message' =>
                    'That chain\'s driver-override rows could not be established — the read failed, a '
                    . 'row is malformed, or there are more rows than can be read at once. No override '
                    . 'may be changed while the stored set is unknown, because a change applied to a '
                    . 'set we only partly read could silently drop another restriction. Nothing was '
                    . 'changed. Check the bcc-trust error log.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE:
                return ['type' => 'error', 'message' =>
                    'That combination of chain, operation and driver is not one this build offers, so '
                    . 'no override was written. Configuration can narrow or reorder what the code '
                    . 'declares; it can never add a capability. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_INVALID_PRIORITY:
                return ['type' => 'error', 'message' =>
                    'That priority is outside the accepted range of 0–1000, so nothing was written. It '
                    . 'was refused rather than adjusted — storing a number you did not choose would be '
                    . 'an ordering nobody decided on.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_WRITE_FAILED:
                return ['type' => 'error', 'message' =>
                    'The driver override could NOT be saved — the database write failed and nothing was '
                    . 'changed. Check the bcc-trust error log.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_UNVERIFIED:
                return ['type' => 'error', 'message' =>
                    'The driver override was attempted but could NOT be confirmed: the stored rows do '
                    . 'not read back as expected, so this is not being reported as done. Caches were '
                    . 'invalidated in case the write did land. Reload to see the current state and '
                    . 'check the bcc-trust error log.'];

            // ── Stale rows ──────────────────────────────────────────────
            case NftCapabilityEditor::RESULT_STALE_REMOVED:
                return ['type' => 'success', 'message' =>
                    'That leftover override row was removed. It was already inert — this build discards '
                    . 'rows it does not recognise at every read — so nothing was enabled, nothing was '
                    . 'granted, and no capability changed. Only the row is gone.'];

            case NftCapabilityEditor::RESULT_STALE_NOT_FOUND:
                return ['type' => 'info', 'message' =>
                    'There is no such override row on that chain. Nothing was changed — it may already '
                    . 'have been removed.'];

            case NftCapabilityEditor::RESULT_STALE_STILL_VALID:
                return ['type' => 'warning', 'message' =>
                    'That row is NOT a leftover — this build still recognises that driver for that '
                    . 'operation on that chain, so it was not removed here. Use "Use code default" on '
                    . 'the driver itself to return it to the registry. Nothing was changed.'];

            // ── Shared ──────────────────────────────────────────────────
            case NftCapabilityEditor::RESULT_UNKNOWN_CHAIN:
                return ['type' => 'error', 'message' =>
                    'Capability: chain not found. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_COLUMN_ABSENT:
                return ['type' => 'error', 'message' =>
                    'This install cannot store that capability value — the column is absent from the '
                    . 'chain projection, which means the migration has not run here. Nothing was '
                    . 'changed, and nothing was assumed about the chain.'];

            case self::CAP_RESULT_ERROR:
                // No reference in the URL by design — the correlation id is
                // in the file log beside the durable audit row.
                return ['type' => 'error', 'message' =>
                    'Capability: the change could not be completed, and nothing was started. The full '
                    . 'error is in the bcc-trust log.'];
        }

        return null;
    }

    /**
     * The capability matrix: one row per chain, one column per operation.
     *
     * ── A PURE PRINTER ──────────────────────────────────────────────────
     * Every status word, reason sentence and driver name below arrived in
     * `$snapshot`. This method consults no repository, no registry, no
     * readiness check and no environment. That is what makes the "one
     * status authority" claim testable rather than asserted: feed it rows
     * carrying distinctive values and they come out unchanged, and a
     * capability model wired to throw is never reached.
     *
     * @param array<string, mixed> $snapshot
     */
    private static function render_capability_matrix(array $snapshot, ?int $selectedId = null): void
    {
        /** @var list<array<string, mixed>> $chains */
        $chains = is_array($snapshot['chains'] ?? null) ? $snapshot['chains'] : [];
        $operations = NftDriverRegistry::operations();
        $family     = (string) ($snapshot['family'] ?? NftDiscoveryControlPlaneSnapshot::DEFAULT_FAMILY);
        ?>
        <h2>Capability by chain</h2>

        <?php if ($chains === []): ?>
            <p><em>No chains of this family are registered.</em></p>
            <p style="color:#646970;">
                This says nothing about capability — it means the chains table returned no row with
                this <code>chain_type</code>.
            </p>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:170px;">Chain</th>
                    <?php foreach ($operations as $operation): ?>
                        <th><?php echo esc_html(self::operation_label($operation)); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($chains as $chain): ?>
                    <?php self::render_capability_row($chain, $operations, $family, $selectedId); ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="color:#646970;margin-top:12px;">
            <strong>Ready</strong> means a registered driver for that operation is enabled and
            configured. It does not mean anything is running — nothing on this page runs on a
            schedule.
        </p>
        <?php
    }

    /**
     * One chain's row. Prints what it is handed; derives nothing.
     *
     * @param array<string, mixed> $chain
     * @param list<string>         $operations
     */
    private static function render_capability_row(
        array $chain,
        array $operations,
        string $family = NftDiscoveryControlPlaneSnapshot::DEFAULT_FAMILY,
        ?int $selectedId = null
    ): void {
        $chainId = (int) ($chain['chain_id'] ?? 0);
        $slug    = (string) ($chain['slug'] ?? '');
        $name    = (string) ($chain['name'] ?? $slug);
        $ops     = is_array($chain['operations'] ?? null) ? $chain['operations'] : [];
        $isOpen  = $selectedId !== null && $selectedId === $chainId;
        ?>
        <tr<?php echo $isOpen ? ' style="outline:2px solid #2271b1;"' : ''; ?>>
            <td>
                <strong><?php echo esc_html($name); ?></strong><br>
                <code><?php echo esc_html($slug); ?></code>
                <span style="color:#646970;font-size:11px;">#<?php echo $chainId; ?></span>
                <?php if (($chain['is_active'] ?? true) !== true): ?>
                    <div style="color:#dba617;font-size:11px;">deactivated</div>
                <?php endif; ?>
                <?php if (($chain['is_testnet'] ?? false) === true): ?>
                    <div style="color:#646970;font-size:11px;">testnet</div>
                <?php endif; ?>
                <?php if ($chainId > 0): ?>
                    <div style="margin-top:6px;">
                        <?php if ($isOpen): ?>
                            <strong style="font-size:11px;color:#2271b1;">editing below</strong>
                        <?php else: ?>
                            <a style="font-size:11px;" href="<?php echo esc_url(add_query_arg(
                                ['page' => self::PAGE_SLUG, 'family' => $family, 'chain' => $chainId],
                                admin_url('admin.php')
                            )); ?>">Edit capability</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </td>
            <?php foreach ($operations as $operation): ?>
                <?php
                $op = is_array($ops[$operation] ?? null) ? $ops[$operation] : [];
                self::render_capability_cell($op);
                ?>
            <?php endforeach; ?>
        </tr>
        <?php
    }

    /**
     * One (chain, operation) cell: the status, why, and which driver.
     *
     * @param array<string, mixed> $op
     */
    private static function render_capability_cell(array $op): void
    {
        $status = is_string($op['status'] ?? null) ? (string) $op['status'] : NftChainCapability::OP_UNKNOWN;
        $reason = is_string($op['reason'] ?? null) ? (string) $op['reason'] : '';

        /** @var list<string> $drivers */
        $drivers = is_array($op['drivers'] ?? null) ? $op['drivers'] : [];
        /** @var list<string> $ready */
        $ready = is_array($op['ready'] ?? null) ? $op['ready'] : [];
        /** @var array<string, array<string, mixed>> $refusals */
        $refusals = is_array($op['endpoint_refusals'] ?? null) ? $op['endpoint_refusals'] : [];
        ?>
        <td style="vertical-align:top;">
            <span style="font-weight:600;color:<?php echo esc_attr(self::status_colour($status)); ?>;">
                <?php echo esc_html(self::status_label($status)); ?>
            </span>
            <div style="font-size:11px;color:#646970;margin-top:2px;">
                <?php echo esc_html(self::reason_sentence($status, $reason)); ?>
            </div>

            <?php if ($drivers !== []): ?>
                <div style="font-size:11px;margin-top:4px;">
                    <?php foreach ($drivers as $driver): ?>
                        <?php $isReady = in_array($driver, $ready, true); ?>
                        <div>
                            <code><?php echo esc_html($driver); ?></code>
                            <span style="color:<?php echo $isReady ? '#00a32a' : '#d63638'; ?>;">
                                <?php echo $isReady ? '✓' : '✗'; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php foreach ($refusals as $driver => $refusal): ?>
                <div style="font-size:11px;color:#d63638;margin-top:4px;">
                    <?php
                    // The stored endpoint was redacted by the writer before
                    // it was ever persisted, so it carries no query string
                    // and therefore no key. The provider MESSAGE is upstream
                    // text and gets the redactor on the way out — esc_html()
                    // stops markup executing and does nothing about a
                    // credentialed URL or an absolute path.
                    $endpoint = isset($refusal['rpc_url']) ? (string) $refusal['rpc_url'] : '';
                    $message  = isset($refusal['message']) ? (string) $refusal['message'] : '';
                    ?>
                    <strong><?php echo esc_html((string) $driver); ?></strong>: this endpoint has already
                    answered that it cannot serve DAS.
                    <?php if ($endpoint !== ''): ?>
                        <div><code><?php echo esc_html($endpoint); ?></code></div>
                    <?php endif; ?>
                    <?php if ($message !== ''): ?>
                        <div><?php echo esc_html(AdminActionSupport::operatorSafeExcerpt($message)); ?></div>
                    <?php endif; ?>
                    <div style="color:#646970;">
                        Point this chain's RPC URL at a DAS-capable endpoint to clear it.
                    </div>
                </div>
            <?php endforeach; ?>
        </td>
        <?php
    }

    /** PURE. The column heading for one operation. */
    private static function operation_label(string $operation): string
    {
        switch ($operation) {
            case NftDriverRegistry::OP_ENUMERATION:
                return 'Chain enumeration';
            case NftDriverRegistry::OP_CURATED_FEED:
                return 'Curated feed';
            case NftDriverRegistry::OP_WALLET_DISCOVERY:
                return 'Wallet discovery';
            case NftDriverRegistry::OP_VALIDATION:
                return 'Validation';
            case NftDriverRegistry::OP_METADATA:
                return 'Metadata';
            case NftDriverRegistry::OP_OWNERSHIP:
                return 'Ownership';
        }

        return $operation;
    }

    /** PURE. The one-word status an operator reads first. */
    private static function status_label(string $status): string
    {
        switch ($status) {
            case NftChainCapability::OP_READY:
                return 'Ready';
            case NftChainCapability::OP_UNKNOWN:
                return 'Unknown';
            case NftChainCapability::OP_CHAIN_UNSUPPORTED:
                return 'Chain cannot';
            case NftChainCapability::OP_NO_BCC_SUPPORT:
                return 'Not supported';
            case NftChainCapability::OP_NO_DRIVER:
                return 'No driver';
            case NftChainCapability::OP_DISABLED:
                return 'Disabled';
            case NftChainCapability::OP_MANUAL_DISABLED:
                return 'Not permitted';
            case NftChainCapability::OP_PROVIDER_UNAVAILABLE:
                return 'Not configured';
        }

        // An unrecognised status is shown as Unknown rather than printed
        // raw: a value from a newer build must never read as a permission.
        return 'Unknown';
    }

    /** PURE. Colour is a hint; the words carry the meaning. */
    private static function status_colour(string $status): string
    {
        switch ($status) {
            case NftChainCapability::OP_READY:
                return '#00a32a';
            case NftChainCapability::OP_PROVIDER_UNAVAILABLE:
            case NftChainCapability::OP_MANUAL_DISABLED:
            case NftChainCapability::OP_DISABLED:
                return '#dba617';
            case NftChainCapability::OP_UNKNOWN:
                return '#d63638';
        }

        return '#646970';
    }

    /**
     * PURE. The sentence under the status.
     *
     * The load-bearing half of this page. "No driver" and "not configured"
     * look similar and send an operator to completely different places, so
     * each one says what would actually change it — including when the
     * honest answer is that nothing would.
     */
    private static function reason_sentence(string $status, string $reason): string
    {
        // The override-store reasons carry their own sub-code.
        if (str_starts_with($reason, NftChainCapability::REASON_OVERRIDES_UNAVAILABLE)) {
            $detail = substr($reason, strlen(NftChainCapability::REASON_OVERRIDES_UNAVAILABLE) + 1);

            switch ($detail) {
                case 'overflow':
                    return 'The driver-override table returned more rows than can be read at once for '
                        . 'this chain, so the set we have may be a subset. Applying part of it could '
                        . 'honour some restrictions and silently drop others, so nothing is claimed.';
                case 'read_failed':
                    return 'The driver-override table could not be read, so what the operator '
                        . 'configured is unknown and no capability is claimed.';
                case 'malformed':
                    return 'A driver-override row for this chain is malformed, so the override set '
                        . 'cannot be trusted and no capability is claimed.';
                case 'invalid_chain':
                    return 'This chain id could not be used to read driver overrides.';
            }

            return 'The driver overrides for this chain could not be established, so no capability '
                . 'is claimed.';
        }

        switch ($reason) {
            case NftChainCapability::REASON_PRODUCT_COLUMN_ABSENT:
                return 'This install cannot store whether BCC supports NFT collections on this chain '
                    . '(the column is absent from the projection), so nothing can be said yet.';
            case NftChainCapability::REASON_MANUAL_COLUMN_ABSENT:
                return 'This install cannot store the manual-discovery permission (the column is '
                    . 'absent from the projection), so nothing can be said yet.';
            case NftChainCapability::REASON_MEASURED_NO_WASM:
                return 'Measured: this chain answered that it has no CosmWasm module. No operator '
                    . 'setting can change that.';
            case NftChainCapability::REASON_PRODUCT_SUPPORT_DISABLED:
                return 'BCC does not currently support NFT collections on this chain. This is a '
                    . 'product decision, not a technical limit, and it is not editable from this page.';
            case NftChainCapability::REASON_NO_REGISTERED_DRIVER:
                return 'No driver in this build performs this operation on this chain family, on any '
                    . 'configuration. Credentials would not change it.';
            case NftChainCapability::REASON_ALL_DRIVERS_DISABLED:
                return 'A driver exists for this operation and every one of them has been switched '
                    . 'off by a driver-override row.';
            case NftChainCapability::REASON_MANUAL_PERMISSION_DISABLED:
                return 'Operator-started discovery is not permitted on this chain. The permission is '
                    . 'read-only in this build.';
            case NftChainCapability::REASON_NO_READY_DRIVER:
                return 'A driver exists and is enabled, but nothing is configured to run it — a '
                    . 'missing endpoint or credential.';
            case NftChainCapability::REASON_READY:
                return 'A registered driver is enabled and configured.';
        }

        return 'No further detail is available.';
    }

    /**
     * The families with no enumeration engine, said plainly.
     *
     * ── WHY THIS IS NOT AN EMPTY SECTION ────────────────────────────────
     * A blank space where Cosmos has controls reads as "not set up yet",
     * and an operator would go looking for the credential that turns it on.
     * There is no such credential. No provider sells chain-wide NFT
     * contract enumeration on EVM or Solana — Alchemy's
     * `getContractsForOwner` answers a WALLET's contracts, which is a
     * different question — so the honest thing is to say so, name the
     * method that does exist, and offer no button.
     *
     * @param array<string, mixed> $snapshot
     */
    private static function render_no_enumeration_notice(array $snapshot): void
    {
        $label = (string) ($snapshot['label'] ?? '');
        ?>
        <h2>Chain-wide discovery</h2>

        <div class="notice notice-info inline" style="margin:0 0 12px;">
            <p style="max-width:900px;">
                <strong>There is no chain-wide NFT collection discovery for <?php echo esc_html($label); ?>,
                and there is no setting that would add one.</strong>
                No provider offers enumeration of every NFT contract on these chains. This is a
                structural limit of what the providers sell, not a gap in this installation's
                configuration.
            </p>
        </div>

        <p style="color:#646970;max-width:900px;">
            What does exist for this family is <strong>wallet-linked refresh</strong>, described
            below. It is a genuinely different operation — it enumerates the contracts held by a
            wallet somebody has linked, not the contracts that exist on the chain — and it is
            never presented here as a chain scan.
        </p>
        <?php
    }

    /**
     * Wallet-linked refresh, described and NOT offered as a control.
     *
     * ── READ-ONLY ON PURPOSE ────────────────────────────────────────────
     * This method exists and runs today on its own schedule. Putting a
     * button on it here would create a NEW discovery entry point, and every
     * gate the surrounding machinery applies upstream would have to be
     * re-established on it — which is exactly the mistake that had to be
     * fixed when the automatic collection sweep was retired and the admin
     * backfill silently became the only way in.
     *
     * So this section explains the method and stops. No form, no route, no
     * trigger.
     *
     * ── IT ALSO DOES NOT CLAIM A LAST-RUN TIME ──────────────────────────
     * Nothing here reads the scheduler. A "last run" field would need a
     * cron introspection call this page deliberately does not make, and a
     * field showing an unverified timestamp is worse than no field.
     *
     * @param array<string, mixed> $snapshot
     */
    private static function render_wallet_refresh_method(array $snapshot): void
    {
        ?>
        <h2>Wallet-linked refresh <span style="font-weight:400;color:#646970;">— a separate method</span></h2>

        <p style="color:#646970;max-width:900px;">
            When a member links a wallet, the collections that wallet holds can be read back from the
            provider and recorded. That is <strong>not</strong> chain discovery: it finds only what a
            linked wallet happens to hold, so a collection nobody on this platform owns is invisible
            to it.
        </p>

        <p style="color:#646970;max-width:900px;">
            It runs on its own existing schedule and is not started from this page. Anything it
            records arrives <strong>unverified</strong>, exactly like anything the CosmWasm engine
            finds, and it verifies nothing and creates no community.
        </p>
        <?php
    }

    /**
     * What the last operator-started run actually did.
     *
     * ── A PARTIAL RUN IS NEVER SHOWN AS A FINISHED ONE ──────────────────
     * The bounded slice stops on whichever ceiling it reaches first, and it
     * can also abort early on a provider answer with most of its budget
     * untouched. Both are partial, and both say so in the heading — before
     * any number, because an operator who reads "12 contracts classified"
     * and stops reading must not come away thinking that was all of them.
     *
     * @param array<string, mixed> $run
     */
    private static function render_run_report(array $run): void
    {
        $partial  = ($run['partial'] ?? false) === true;
        $outcome  = (string) ($run['outcome'] ?? '');
        $stop     = (string) ($run['stop_reason'] ?? '');
        $requests = is_array($run['requests'] ?? null) ? $run['requests'] : [];
        $pages    = is_array($run['pages'] ?? null) ? $run['pages'] : [];
        $classified = is_array($run['classified'] ?? null) ? $run['classified'] : [];
        $collections = is_array($run['collections'] ?? null) ? $run['collections'] : [];
        $delta    = is_array($run['delta'] ?? null) ? $run['delta'] : [];
        /** @var list<string> $errors */
        $errors = is_array($run['errors'] ?? null) ? $run['errors'] : [];

        // A pass that never ran has no numbers worth printing, and printing
        // zeroes beside "locked" invites reading them as a result.
        $ran = $outcome === CosmwasmDiscoveryWorker::PASS_RAN;
        ?>
        <div class="notice notice-<?php echo $partial ? 'warning' : 'success'; ?>"
             style="padding:8px 12px;">
            <h3 style="margin:4px 0;">
                <?php if (!$ran): ?>
                    Nothing ran — <?php echo esc_html(self::stop_reason_sentence($stop)); ?>
                <?php elseif ($partial): ?>
                    Stopped early — this is a slice, not a complete scan
                <?php else: ?>
                    The slice ran to its own conclusion
                <?php endif; ?>
            </h3>

            <p style="margin:4px 0;color:#646970;">
                <?php echo esc_html(self::stop_reason_sentence($stop)); ?>
            </p>

            <?php if ($ran): ?>
                <div style="display:flex;flex-wrap:wrap;gap:24px;font-size:12px;margin-top:8px;">
                    <div>
                        <strong>Requests</strong><br>
                        <?php echo esc_html(number_format_i18n((int) ($requests['used'] ?? 0))); ?> used
                        · <?php echo esc_html(number_format_i18n((int) ($requests['remaining'] ?? 0))); ?> left
                    </div>
                    <div>
                        <strong>Pages fetched</strong><br>
                        <?php echo esc_html(number_format_i18n((int) ($pages['code'] ?? 0))); ?> code
                        · <?php echo esc_html(number_format_i18n((int) ($pages['contracts'] ?? 0))); ?> contract
                    </div>
                    <div>
                        <strong>Classified</strong><br>
                        <?php echo esc_html(number_format_i18n((int) ($classified['families'] ?? 0))); ?> families
                        · <?php echo esc_html(number_format_i18n((int) ($classified['contracts'] ?? 0))); ?> contracts
                    </div>
                    <div>
                        <strong>Collections</strong><br>
                        <?php echo esc_html(number_format_i18n((int) ($collections['emitted'] ?? 0))); ?> emitted
                        · <?php echo esc_html(number_format_i18n((int) ($collections['denied'] ?? 0))); ?> hidden
                        <div style="color:#646970;">all unverified</div>
                    </div>
                    <div>
                        <strong>Inventory</strong><br>
                        families <?php echo esc_html(number_format_i18n((int) ($delta['families_before'] ?? 0))); ?>
                        → <?php echo esc_html(number_format_i18n((int) ($delta['families_after'] ?? 0))); ?>
                        · contracts <?php echo esc_html(number_format_i18n((int) ($delta['contracts_before'] ?? 0))); ?>
                        → <?php echo esc_html(number_format_i18n((int) ($delta['contracts_after'] ?? 0))); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <details style="margin-top:8px;">
                    <summary style="cursor:pointer;color:#d63638;">
                        <?php echo esc_html(sprintf(
                            count($errors) === 1 ? '%s recorded reason' : '%s recorded reasons',
                            number_format_i18n(count($errors))
                        )); ?>
                    </summary>
                    <?php foreach ($errors as $error): ?>
                        <?php
                        // UPSTREAM TEXT. Redacted before escaping:
                        // esc_html() stops markup executing and does nothing
                        // about a credentialed URL or an absolute path.
                        ?>
                        <code style="display:block;margin-top:4px;white-space:pre-wrap;word-break:break-word;"><?php
                            echo esc_html(AdminActionSupport::operatorSafeExcerpt((string) $error));
                        ?></code>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    /** PURE. The stop reason as a sentence an operator can act on. */
    private static function stop_reason_sentence(string $stop): string
    {
        switch ($stop) {
            case CosmwasmPassStopReason::LOCK_CONTENDED:
                return 'Another pass already held this chain\'s lock, so this one did nothing. '
                    . 'Nothing was changed.';
            case CosmwasmPassStopReason::CHAIN_REFUSED_TO_PREPARE:
                return 'The chain refused to prepare — paused, measured unsupported, circuit breaker '
                    . 'open, or no CosmWasm driver. Nothing was contacted.';
            case CosmwasmPassStopReason::EXECUTION_FAILED:
                return 'The pass failed. The reason is recorded on the chain\'s checkpoint row and in '
                    . 'the bcc-trust log.';
            case CosmwasmPassStopReason::RUNTIME_DEADLINE_REACHED:
                return 'It stopped on the time limit, with requests still available. Progress was '
                    . 'written as it went, so running it again continues from where it stopped.';
            case CosmwasmPassStopReason::REQUEST_BUDGET_EXHAUSTED:
                return 'It stopped on the request budget, with time still on the clock. Progress was '
                    . 'written as it went, so running it again continues from where it stopped.';
            case CosmwasmPassStopReason::PASS_COMPLETED:
                return 'It finished inside both its ceilings. That means this slice completed — not '
                    . 'that the chain is fully scanned.';
        }

        return 'The pass stopped for a reason this build does not recognise, so it is treated as '
            . 'incomplete.';
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
     * If a second opinion ever creeps in here, the panel and this section
     * can disagree about the same chain, which is precisely the failure
     * this whole feature exists to prevent.
     *
     * @param list<array<string, mixed>> $chains completed snapshot rows
     */
    public static function render_cw_discovery_section(array $chains): void
    {
        ?>
        <p style="max-width:900px;">
            NFT discovery can use different engines for different chain families.
            This section currently manages automatic CW-721 discovery for CosmWasm-enabled chains only.
            It does not control EVM NFTs, Solana NFTs, Helius indexing, manual verification or community provisioning.
        </p>

        <h2>CosmWasm / CW-721 Discovery</h2>

        <p style="color:#646970;max-width:900px;">
            The only engine in this build that can enumerate collections chain-wide. Turning
            discovery on for a chain tells the scanner it may look for CW-721 collections there. It
            does not start a scan, and it does not verify anything it finds — everything discovered
            arrives unverified for you to approve on
            <a href="<?php echo esc_url(admin_url('admin.php?page=bcc-verify-collections')); ?>">Verify Collections</a>.
        </p>

        <p style="color:#646970;max-width:900px;">
            <strong>Enabling or disabling discovery is not the same as pausing the scanner.</strong>
            Disabling says this chain should not take part in automatic CW-721 discovery at all.
            Pausing is a temporary hold on a chain the scanner is still responsible for.
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

        <p style="color:#646970;margin-top:12px;max-width:900px;">
            <?php
            // ── THE PARAGRAPH THAT STOPS AN ABSENCE BEING READ AS A VERDICT ──
            //
            // This table lists cosmos chains and nothing else, because they
            // are the only candidates for the CW-721 engine. Without this
            // sentence, an operator who scrolled to it first would read the
            // absence of Ethereum as "Ethereum cannot do NFTs" — which is
            // false, and unfixable from this screen.
            //
            // The capability matrix above already says what EVM and Solana
            // CAN do, but the section has to be safe read on its own,
            // because it is what a deep link or a stale bookmark lands on.
            ?>
            Only chains whose type is <code>cosmos</code> appear above, because they are the only
            candidates for the CW-721 engine. Chains on other families are not listed here and are
            <strong>not</strong> being described as unable to support NFTs — they are simply not
            managed by this engine. What those families can and cannot do is in the capability
            table above.
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
        $optedIn     = ($chain['discovery_opted_in'] ?? null) === true;
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
     * The four scanner operations for one chain.
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
     *   not paused         Pause, plus Backfill when every gate allows
     *   Retry              whenever discovery is on
     *
     * ── THE CAPABILITY GATE IS PART OF BACKFILL'S VISIBILITY ────────────
     * `enumeration_status` arrives on the row, already joined by the
     * snapshot. When it is not READY the button is not drawn AND the reason
     * is printed in its place — a missing control with no explanation is
     * how an operator concludes the page is broken. The handler re-checks
     * regardless; this is presentation, not authorization.
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

        $enumerationStatus = is_string($chain['enumeration_status'] ?? null)
            ? (string) $chain['enumeration_status']
            : NftChainCapability::OP_UNKNOWN;
        $enumerationReason = is_string($chain['enumeration_reason'] ?? null)
            ? (string) $chain['enumeration_reason']
            : '';

        $capabilityAllows = NftChainCapability::isOperationReady($enumerationStatus);

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
                        // Offered only when the capability model says the
                        // enumeration operation is ready, both environment
                        // gates allow it, and the chain is not paused — the
                        // same conditions the handler re-checks server-side.
                        if ($capabilityAllows && $discoveryOn && $backfillOn && !$paused) {
                            self::render_cw_operation_control(self::ACTION_CW_BACKFILL, $chainId, $slug);
                        }

                        if ($discoveryOn) {
                            self::render_cw_operation_control(self::ACTION_CW_RETRY, $chainId, $slug);
                        }
                        ?>
                    </div>

                    <?php if (!$capabilityAllows): ?>
                        <div style="font-size:11px;color:#646970;margin-top:4px;">
                            <strong>No backfill control:</strong>
                            <?php echo esc_html(self::reason_sentence($enumerationStatus, $enumerationReason)); ?>
                        </div>
                    <?php endif; ?>
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
     * The scanner-status detail row.
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

        $familyCounts = is_array($chain['families_by_classification'] ?? null)
            ? $chain['families_by_classification']
            : [];
        $familiesPending = (int) ($chain['families_pending'] ?? 0);

        // Fail closed: anything non-numeric reads as 0 errored, and a
        // positive count is stated plainly rather than buried in a total.
        $familiesErrored = (int) ($chain['families_errored'] ?? 0);

        $inspected  = (int) ($chain['contracts_inspected'] ?? 0);
        $denied     = (int) ($chain['contracts_denied'] ?? 0);
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
     * because "On"/"Off" beside a Pause/Resume control is exactly how the
     * two get confused.
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

            // ── Backfill: the run outcomes ──────────────────────────────
            //
            // The detail lives in the run report rendered above the table.
            // These sentences say only what the outcome itself means, so
            // the two cannot contradict each other.
            case 'backfill_ran':
                return ['type' => 'success', 'message' =>
                    'The backfill slice ran. See the run report above for what it actually did, including '
                    . 'whether it stopped early. Anything discovered arrives unverified.'];

            case 'backfill_locked':
                return ['type' => 'info', 'message' =>
                    'Another pass already held that chain\'s lock, so nothing ran and nothing was changed. '
                    . 'Try again in a moment.'];

            case 'backfill_skipped':
                return ['type' => 'warning', 'message' =>
                    'The chain refused to prepare, so no slice ran and nothing was contacted. See the run '
                    . 'report above.'];

            case 'backfill_failed':
                return ['type' => 'error', 'message' =>
                    'The backfill slice failed. Nothing was verified and nothing was enabled. The reason is '
                    . 'recorded on the chain\'s checkpoint row and in the bcc-trust log.'];

            // ── Backfill: refused before anything ran ───────────────────
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

            case 'backfill_driver_not_ready':
                return ['type' => 'error', 'message' =>
                    'The CosmWasm enumeration driver is not ready for that chain, so no slice was run and '
                    . 'nothing was contacted. See the capability table above.'];

            // ── Backfill: refused by the capability model ───────────────
            //
            // One case per status, because "not allowed" without saying
            // WHICH permission is missing sends an operator hunting.
            case 'backfill_' . NftChainCapability::OP_UNKNOWN:
                return ['type' => 'error', 'message' =>
                    'That chain\'s NFT capability could not be established — the driver-override store was '
                    . 'unreadable, incomplete, or a capability column is missing. Nothing was started, and '
                    . 'no capability was assumed. See the capability table above.'];

            case 'backfill_' . NftChainCapability::OP_CHAIN_UNSUPPORTED:
                return ['type' => 'warning', 'message' =>
                    'That chain has been measured as having no CosmWasm module, so no slice was run. '
                    . 'Nothing was contacted.'];

            case 'backfill_' . NftChainCapability::OP_NO_BCC_SUPPORT:
                return ['type' => 'warning', 'message' =>
                    'BCC does not currently support NFT collections on that chain, so no slice was run. '
                    . 'That setting is read-only in this build.'];

            case 'backfill_' . NftChainCapability::OP_NO_DRIVER:
                return ['type' => 'warning', 'message' =>
                    'No driver in this build can enumerate that chain, so no slice was run. This is a '
                    . 'structural limit, not a configuration gap.'];

            case 'backfill_' . NftChainCapability::OP_DISABLED:
                return ['type' => 'warning', 'message' =>
                    'Every enumeration driver for that chain has been switched off by a driver-override '
                    . 'row, so no slice was run.'];

            case 'backfill_' . NftChainCapability::OP_MANUAL_DISABLED:
                return ['type' => 'warning', 'message' =>
                    'Operator-started discovery is not permitted on that chain, so no slice was run. '
                    . 'That permission is read-only in this build.'];

            case 'backfill_' . NftChainCapability::OP_PROVIDER_UNAVAILABLE:
                return ['type' => 'warning', 'message' =>
                    'An enumeration driver exists for that chain but nothing is configured to run it, so '
                    . 'no slice was run and nothing was contacted.'];

            // ── Retry ───────────────────────────────────────────────────
            case 'retry_requeued':
                return ['type' => 'success', 'message' =>
                    'Unresolved code families and contracts were queued for another look — up to 100 of each. '
                    . 'Nothing was contacted now: a future pass does the work. Settled non-NFT '
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
     * Rebuild the discovery opt-in notice from the PRG redirect args.
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
        // try. "Which chain was it?" is answered by the durable audit row.
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

    // ── PR 6: the one manual Add Collection entry point ─────────────────

    /**
     * @var list<string> ADD_REDIRECT_KEYS the only keys this destination may carry
     *
     * Same allowlist discipline as the capability and operation redirects.
     * Deliberately carries NO chain id: the result copy is family-scoped, and
     * a chain id in the URL is a target an operator can edit by hand into an
     * action they were never shown.
     */
    public const ADD_REDIRECT_KEYS = ['page', 'family', 'bcc_nftadd'];

    /**
     * Request boundary for the manual Add Collection route.
     *
     * Ordering, identical to the capability routes: capability → POST →
     * shape → scoped nonce → domain. The chain id is part of the nonce
     * action, so it has to be READ before the nonce can be verified — but
     * nothing is looked up, contacted or written until the nonce has proven
     * the request authentic.
     */
    public static function handle_add_collection(): void
    {
        if (!current_user_can('manage_options')) {
            // The trace carries nothing from the request: `chain_id` is
            // attacker-controlled and unvalidated here, so echoing it would
            // let an unauthenticated caller write our log.
            \BCC\Core\Log\Logger::warning('[bcc-trust] manual collection add refused', [
                'action'   => 'nft_manual_add_denied',
                'operator' => get_current_user_id(),
            ]);
        }

        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        $chainId = self::require_chain_id_shape();

        AdminActionSupport::requireNonce(self::ACTION_ADD_COLLECTION . '_' . $chainId);

        $family = self::current_family();

        $identifier = isset($_POST['bcc_nftd_identifier']) && is_scalar($_POST['bcc_nftd_identifier'])
            ? trim(sanitize_text_field((string) $_POST['bcc_nftd_identifier']))
            : '';

        try {
            $result = (new ManualCollectionIntakeService())->add(
                $family,
                $chainId,
                $identifier,
                get_current_user_id()
            );
        } catch (\Throwable $e) {
            AdminActionSupport::failure($e, 'admin_nftd_collection_add_refused', 'chain', $chainId);
            self::redirect_add(ManualCollectionIntakeService::REFUSED_WRITE_FAILED);
        }

        self::redirect_add(
            $result['ok'] === true
                ? 'added'
                : (string) ($result['reason'] ?? ManualCollectionIntakeService::REFUSED_WRITE_FAILED)
        );
    }

    private static function redirect_add(string $result): never
    {
        AdminActionSupport::redirect([
            'page'       => self::PAGE_SLUG,
            'family'     => self::current_family(),
            'bcc_nftadd' => $result,
        ]);
    }

    /**
     * Render the chain-locked Add Collection form for one family.
     *
     * ── WHY THE FORM IS RENDERED EVEN WHEN IT CANNOT SUCCEED ────────────
     * Every chain on this install currently has both capability flags off,
     * so on most chains this control is refused. It still renders, with the
     * specific flag named and a link to the editor that sets it — because
     * an absent control tells an operator nothing, and a control that fails
     * with "not permitted" tells them exactly which switch to find.
     *
     * ── ZERO PROVIDER CALLS HERE ────────────────────────────────────────
     * Everything below is drawn from the snapshot the page already built.
     * Nothing is fetched, probed, or asked of a chain to draw this form; the
     * one bounded validation happens on SUBMIT, and only for Cosmos.
     *
     * @param list<array<string, mixed>> $chains snapshot rows of the current family
     */
    private static function render_add_collection(string $family, array $chains): void
    {
        // Chains that can actually take an add today. The form still renders
        // when this is empty — with an explanation, not silence.
        //
        // ⚠ `bcc_supports` and `manual_enabled` are bool|NULL. Null means the
        // column could not be read, which is NOT the same as false and must
        // not be treated as true: `=== true` is the only correct test, and it
        // is what makes an unreadable capability store fail closed here
        // rather than opening every chain in the family.
        $eligible = [];
        foreach ($chains as $row) {
            if (($row['is_active'] ?? false) !== true) {
                continue;
            }
            if (($row['bcc_supports'] ?? null) !== true) {
                continue;
            }
            if (($row['manual_enabled'] ?? null) !== true) {
                continue;
            }
            $eligible[] = $row;
        }

        // What this family can actually PROVE about an identifier. Taken from
        // NftDriverRegistry rather than restated, so a family that gains a
        // validation driver stops being labelled "accepted as entered"
        // without anyone remembering to edit this copy.
        $hasValidation = self::family_has_validation_driver($family);

        ?>
        <h2 style="margin-top:32px;">Add a collection</h2>
        <p style="color:#646970;max-width:60em;">
            Manual intake for a collection that discovery cannot reach. The chain you
            pick is authoritative: the form is bound to it, and a submission whose
            chain does not belong to this family is refused.
            The new row lands <strong>unverified</strong>, with <strong>no community</strong>,
            and enabling neither. Verifying it later is a separate decision, and so is
            requesting its community.
        </p>

        <?php if ($hasValidation): ?>
            <p style="color:#646970;max-width:60em;">
                On this family the contract is checked with a real
                <code>contract_info</code> query before the row is written. A contract
                that does not answer is refused — and refused as
                <em>could not confirm</em>, which is not the same as
                <em>not an NFT collection</em>.
            </p>
        <?php else: ?>
            <p style="max-width:60em;padding:8px 12px;background:#fcf9e8;border-left:4px solid #dba617;">
                <strong>Accepted as entered.</strong> On this family nothing proves the
                address is an NFT contract — there is no validation driver for it in
                this build. The identifier is checked for shape and canonical form
                only. A valid address is not a verified collection.
            </p>
        <?php endif; ?>

        <?php if ($eligible === []): ?>
            <p style="max-width:60em;padding:8px 12px;background:#f6f7f7;border-left:4px solid #72aee6;">
                No chain in this family can take a manual collection yet. A chain needs
                <strong>product support</strong> and <strong>manual collection discovery</strong>
                both enabled. Set them per chain in the capability editor on this page.
            </p>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  style="margin:8px 0 24px 0;padding:12px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ADD_COLLECTION); ?>">
                <input type="hidden" name="family" value="<?php echo esc_attr($family); ?>">

                <label style="display:flex;flex-direction:column;font-size:12px;">
                    Chain
                    <select name="chain_id" id="bcc-nftd-add-chain" required
                            style="min-width:200px;"
                            onchange="(function(s){var f=s.form;var n=f.querySelector('input[name=_wpnonce]');if(n){n.value=s.options[s.selectedIndex].dataset.nonce||'';}})(this)">
                        <?php foreach ($eligible as $row): ?>
                            <option value="<?php echo (int) $row['chain_id']; ?>"
                                    data-nonce="<?php
                                        // ⚠ ONE NONCE PER CHAIN, minted here.
                                        //
                                        // The nonce action is bound to the chain
                                        // (`<route>_<chainId>`), so a single form-wide
                                        // nonce could not be verified for whichever
                                        // chain the operator picks. Each option carries
                                        // its own, and changing the select swaps the
                                        // hidden field. A nonce minted for chain 8
                                        // therefore authorises an add on chain 8 and
                                        // nothing else — including no other route.
                                        echo esc_attr(wp_create_nonce(self::ACTION_ADD_COLLECTION . '_' . (int) $row['chain_id']));
                                    ?>"
                                    >
                                <?php echo esc_html((string) ($row['name'] ?? $row['slug'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <input type="hidden" name="_wpnonce"
                       value="<?php echo esc_attr(wp_create_nonce(self::ACTION_ADD_COLLECTION . '_' . (int) $eligible[0]['chain_id'])); ?>">

                <label style="display:flex;flex-direction:column;font-size:12px;flex:1;min-width:300px;">
                    <?php echo esc_html(self::identifier_label($family)); ?>
                    <input type="text"
                           name="bcc_nftd_identifier"
                           required
                           spellcheck="false"
                           autocomplete="off"
                           placeholder="<?php echo esc_attr(self::identifier_placeholder($family)); ?>"
                           style="font-family:monospace;font-size:12px;">
                </label>

                <button type="submit" class="button button-primary">Add collection</button>
            </form>
        <?php endif; ?>
        <?php
    }

    /** Does any registered driver claim VALIDATION for this family's chains? */
    private static function family_has_validation_driver(string $family): bool
    {
        // Only `cw721_lcd` registers OP_VALIDATION, and it serves Cosmos.
        // Asked through the registry rather than hardcoded, so whoever builds
        // EVM or Solana validation flips this copy by registering the driver
        // — not by remembering that this file exists and restates the claim.
        return $family === 'cosmos'
            && NftDriverRegistry::driverPerformsOperation(
                NftDriverRegistry::DRIVER_CW721_LCD,
                NftDriverRegistry::OP_VALIDATION
            );
    }

    private static function identifier_label(string $family): string
    {
        switch ($family) {
            case 'cosmos':
                return 'CW-721 contract address';
            case 'evm':
                return 'Contract address (0x…)';
            case 'solana':
                return 'Collection mint address';
            default:
                return 'Collection identifier';
        }
    }

    private static function identifier_placeholder(string $family): string
    {
        switch ($family) {
            case 'cosmos':
                return 'cosmos1… / inj1… / juno1…';
            case 'evm':
                return '0x0000000000000000000000000000000000000000';
            case 'solana':
                return 'base58 mint — case is preserved exactly';
            default:
                return '';
        }
    }

    /** Operator notice for the Add Collection PRG result. */
    private static function add_result_notice(string $result): void
    {
        if ($result === '') {
            return;
        }

        if ($result === 'added') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>Collection added. It is <strong>unverified</strong> and has
                <strong>no community</strong> — both are separate decisions, taken on
                the Verify Collections page.</p>
            </div>
            <?php
            return;
        }

        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html(ManualCollectionIntakeService::refusalMessage($result)); ?></p>
        </div>
        <?php
    }

}
