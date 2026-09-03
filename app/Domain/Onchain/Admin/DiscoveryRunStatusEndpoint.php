<?php

declare(strict_types=1);

/**
 * The authenticated, read-only status endpoint PR 7's Scan UI will poll.
 *
 * ── WHY AJAX AND NOT REST ───────────────────────────────────────────────
 * This is an administrator-only operational surface with no public
 * consumer. A REST route would oblige an entry in `api-contract-v1.md` §4
 * or an `EXEMPT_INTERNAL` allowlist entry, and `contract-parity-guard`
 * fails the build otherwise — real cost for no benefit here. `wp_ajax_` is
 * the smaller surface and matches the existing per-row admin actions on
 * VerifyCollectionsPage.
 *
 * ── IT IS A READ ────────────────────────────────────────────────────────
 * Capability-checked, nonce-checked, and it writes nothing: no state, no
 * transient, no touch. It starts no work and contacts no provider. PR 7A
 * ships no UI — this endpoint exists so the read model is exercised and
 * proven before anything renders it.
 *
 * @package BCC\Trust\Onchain\Admin
 */

namespace BCC\Trust\Onchain\Admin;

use BCC\Trust\Onchain\Services\DiscoveryRunStatusReader;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;

if (!defined('ABSPATH')) {
    exit;
}

final class DiscoveryRunStatusEndpoint
{
    public const AJAX_ACTION = 'bcc_discovery_run_status';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'handle']);
    }

    public static function handle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $chainId = isset($_GET['chain_id']) ? (int) $_GET['chain_id'] : 0;
        if ($chainId <= 0) {
            wp_send_json_error(['message' => 'Invalid chain id.']);
        }

        // Bound to the chain being read, matching the per-row nonce pattern
        // the rest of the admin surface uses.
        check_ajax_referer(self::AJAX_ACTION . '_' . $chainId, 'nonce');

        $jobKind = isset($_GET['job_kind'])
            ? sanitize_key((string) $_GET['job_kind'])
            : DiscoveryJobKind::COSMWASM_DISCOVERY;

        if (!DiscoveryJobKind::isValid($jobKind)) {
            wp_send_json_error(['message' => 'Unknown job kind.']);
        }

        $status = DiscoveryRunStatusReader::forChain($chainId, $jobKind);

        if (($status['ok'] ?? false) !== true) {
            wp_send_json_error(['message' => 'No such discovery target.']);
        }

        wp_send_json_success($status);
    }
}
