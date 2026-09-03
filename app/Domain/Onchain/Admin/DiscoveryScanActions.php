<?php

declare(strict_types=1);

/**
 * The administrator "Scan On-Chain for Easy Discovery" actions.
 *
 * ── WHAT THIS IS, AND WHAT IT DELIBERATELY IS NOT ───────────────────────
 * A thin, safe HTTP shell around the PR 7A ledger. It authorizes an
 * administrator, resolves a chain, and hands the decision to
 * {@see DiscoveryRunService}. It contains NO discovery logic, NO provider
 * call, NO scan-mode choice and NO state vocabulary of its own — every one
 * of those already exists in PR 7A, and a second copy here would be the
 * parallel implementation §11 forbids.
 *
 * ⚠ NOTHING HERE CONTACTS A BLOCKCHAIN. The request creates a queued row and
 * returns; the executor does the work later, out of band. Scanning inside an
 * admin POST would tie a provider's latency to a browser request and put a
 * multi-minute operation behind a PHP timeout.
 *
 * ── THE AUTHORIZATION SHAPE ─────────────────────────────────────────────
 * Four independent gates, in this order:
 *
 *   1. `requireCapability()` — `manage_options` on the SESSION. The menu
 *      capability is not a gate on the write, because admin-post.php is
 *      reachable without ever rendering the page.
 *   2. `requirePost()` — admin-post.php dispatches its hook for GET too, so
 *      a handler that merely reads `$_POST` is not POST-only.
 *   3. `requireNonce()` — action- and chain-scoped CSRF.
 *   4. an EXPLICIT operator id, re-checked by
 *      {@see DiscoveryRunService} against `get_userdata()` and
 *      `user_can(..., 'manage_options')`.
 *
 * ⚠ (4) is not redundant with (1). `current_user_can()` answers "is the
 * session privileged"; the ledger records WHO asked, and a run attributed to
 * an implicit session is a run with no accountable author. The service
 * refuses id 0, a nonexistent user and a non-administrator — so the id that
 * lands in `requested_by` is always a real, checked administrator.
 *
 * @package BCC\Trust\Onchain\Admin
 */

namespace BCC\Trust\Onchain\Admin;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Services\DiscoveryRunService;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;

if (!defined('ABSPATH')) {
    exit;
}

final class DiscoveryScanActions
{
    public const ACTION_REQUEST = 'bcc_discovery_scan_request';
    public const ACTION_RETRY   = 'bcc_discovery_scan_retry';
    public const ACTION_CANCEL  = 'bcc_discovery_scan_cancel';

    public static function register(): void
    {
        foreach ([self::ACTION_REQUEST, self::ACTION_RETRY, self::ACTION_CANCEL] as $action) {
            add_action('admin_post_' . $action, [self::class, 'handle']);
        }
    }

    /**
     * The per-chain nonce action.
     *
     * Chain-scoped so a nonce minted for one chain's button cannot start a
     * scan on another — the operator authorized THAT chain, not any chain.
     */
    public static function nonceAction(string $action, int $chainId): string
    {
        return $action . '_' . $chainId;
    }

    public static function handle(): void
    {
        // (1) session capability, (2) method.
        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        $action = self::resolveAction();
        if ($action === null) {
            // Unreachable through the registered hooks; defensive only.
            wp_die(
                esc_html__('Unknown discovery action.', 'bcc-trust'),
                esc_html__('Bad Request', 'bcc-trust'),
                ['response' => 400]
            );
        }

        $chainId = isset($_POST['chain_id']) ? (int) $_POST['chain_id'] : 0;

        // ⚠ The browser names a run by its OPAQUE PUBLIC HANDLE, never by the
        // internal id. `run_uuid` is what the read model exposes; resolving it
        // here keeps the id inside the backend and means a form field cannot
        // address a row by guessing a small integer.
        //
        // Resolving is NOT authorizing: the service still re-checks the
        // operator and the state transition, so knowing a uuid is a name and
        // not a capability.
        $runUuid = isset($_POST['run_uuid']) ? sanitize_text_field((string) $_POST['run_uuid']) : '';

        // (3) CSRF, bound to this action AND this chain.
        AdminActionSupport::requireNonce(self::nonceAction($action, $chainId));

        // (4) the EXPLICIT operator. Resolved here and passed as a VALUE the
        // service re-checks — never assumed from the session.
        //
        // ⚠ There is deliberately no `if ($operatorId <= 0)` branch here.
        // `DiscoveryRunService::resolveOperator()` already refuses id 0, a
        // nonexistent user and a non-administrator, and a second copy of that
        // rule is a second place it can drift. A mutation control that deleted
        // an earlier duplicate check SURVIVED — which is the proof it was
        // redundant, not the proof it was safe to have. One authority.
        $operatorId = get_current_user_id();

        $service = new DiscoveryRunService();

        $result = match ($action) {
            self::ACTION_REQUEST => $service->request(
                $chainId,
                $operatorId,
                DiscoveryJobKind::COSMWASM_DISCOVERY
                // ⚠ No fourth argument. The SERVER picks historical or
                // incremental from the chain's checkpoint; the browser has no
                // say, and passing one here would make a full backfill
                // reachable from a form field.
            ),
            self::ACTION_RETRY   => $service->retry(self::resolveRunId($runUuid), $operatorId),
            self::ACTION_CANCEL  => $service->cancel(self::resolveRunId($runUuid), $operatorId),
            default              => ['ok' => false, 'status' => 'refused', 'reason' => DiscoveryRunError::UNSUPPORTED_REQUEST],
        };

        self::redirectWithResult($chainId, $action, $result);
    }

    /**
     * Public handle → internal id.
     *
     * Returns 0 when the uuid is malformed or names nothing, which the
     * service turns into a bounded refusal — the same answer an operator
     * gets for a run that has since been pruned. It never falls back to
     * "the newest run" or any other guess: acting on a DIFFERENT run than
     * the one the operator named would be worse than refusing.
     */
    private static function resolveRunId(string $uuid): int
    {
        if ($uuid === '') {
            return 0;
        }

        $row = DiscoveryRunRepository::findByUuid($uuid);

        return $row !== null ? (int) $row->id : 0;
    }

    /**
     * Reduce any reason to the CLOSED vocabulary, or to nothing.
     *
     * ⚠ Extracted as a pure, public helper so it can be tested directly. The
     * service only ever emits bounded codes today, so the defensive branch is
     * unreachable through `handle()` — and an untestable guard is a guard
     * nobody will notice breaking. A mutation control that removed the
     * validity check survived precisely because of that.
     *
     * A provider body, an exception message, a credentialed URL or a token
     * must never reach the browser or the address bar.
     */
    public static function boundedReason(string $reason): string
    {
        if ($reason === '') {
            return '';
        }

        if (DiscoveryRunError::isValid($reason)) {
            return $reason;
        }

        // An unrecognised code is itself the finding: surface a generic
        // marker rather than echoing something unbounded.
        return DiscoveryRunError::UNSUPPORTED_REQUEST;
    }

    /** Map the firing admin_post_ hook back to its action. */
    private static function resolveAction(): ?string
    {
        $hook   = (string) current_action();
        $action = str_starts_with($hook, 'admin_post_')
            ? substr($hook, strlen('admin_post_'))
            : '';

        return in_array($action, [self::ACTION_REQUEST, self::ACTION_RETRY, self::ACTION_CANCEL], true)
            ? $action
            : null;
    }

    /**
     * POST/redirect/GET back to the discovery page.
     *
     * ⚠ Only BOUNDED codes cross into the query string. A provider body, an
     * exception message, a credentialed URL or a token must never reach the
     * browser or the address bar — {@see DiscoveryRunError} is a closed
     * vocabulary and the reason is refused if it is not in it.
     *
     * @param array{ok: bool, status: string, reason?: string, run_id?: int,
     *              run_uuid?: string, scan_mode?: string, active_run_id?: int} $result
     * @return never
     */
    private static function redirectWithResult(int $chainId, string $action, array $result): void
    {
        $args = [
            'page'      => 'bcc-nft-discovery',
            'bcc_chain' => $chainId,
            'bcc_scan'  => ($result['ok'] ?? false) ? 'ok' : 'refused',
        ];

        $reason = self::boundedReason(isset($result['reason']) ? (string) $result['reason'] : '');
        if ($reason !== '') {
            $args['bcc_reason'] = $reason;
        }

        // The existing active run is useful to the operator ("you already have
        // one running") and is a plain integer, so it is safe to carry.
        if (isset($result['active_run_id'])) {
            $args['bcc_active_run'] = (int) $result['active_run_id'];
        }
        if (isset($result['run_id'])) {
            $args['bcc_run'] = (int) $result['run_id'];
        }

        $args['bcc_action'] = $action;

        AdminActionSupport::redirect($args);
    }

    /**
     * Is this chain a plausible discovery target at all?
     *
     * Used by the VIEW to decide whether to render an enabled button. It is
     * NOT a security boundary — the service re-decides on every request, and
     * a disabled button is a courtesy, not a gate.
     */
    public static function chainIsKnown(int $chainId): bool
    {
        if ($chainId <= 0) {
            return false;
        }

        return ChainRepository::getById($chainId) !== null;
    }
}
