<?php
/**
 * `wp bcc-trust claims-revoke-user` — revoke a user's verified claims in
 * batches, through the audited revoke (each claim audited, its page freed,
 * the revoke event fired). For a user with more verified claims than a
 * wallet unlink or an account deletion revokes in one go (REVOKE_ALL_MAX):
 * run this, then retry the unlink or deletion.
 *
 * PRIVACY: prints counts only, never addresses.
 *
 * @package BCC\Trust\Onchain\CLI
 */

namespace BCC\Trust\Onchain\CLI;

use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Services\ClaimService;

if (!defined('ABSPATH')) {
    exit;
}

class ClaimRevokeUserCommand
{
    /**
     * Revoke a user's verified claims, in batches.
     *
     * ## OPTIONS
     *
     * <user_id>
     * : The user whose claims to revoke.
     *
     * --reason=<reason>
     * : wallet_unlinked or account_deleted.
     *
     * [--wallet=<address>]
     * : Only the claims proven by this wallet.
     *
     * [--chain=<id>]
     * : With --wallet: only on this chain id.
     *
     * [--batch=<n>]
     * : Claims per batch (at most 200). Default 200.
     *
     * [--yes]
     * : Skip the confirmation.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        $userId = (int) ($args[0] ?? 0);
        $reason = (string) ($assoc['reason'] ?? '');
        if ($userId <= 0 || !in_array($reason, ['wallet_unlinked', 'account_deleted'], true)) {
            \WP_CLI::error('Usage: wp bcc-trust claims-revoke-user <user_id> --reason=<wallet_unlinked|account_deleted>');
        }
        $wallet = isset($assoc['wallet']) ? (string) $assoc['wallet'] : null;
        $chain  = isset($assoc['chain']) ? (int) $assoc['chain'] : null;
        $batch  = max(1, min(ClaimRepository::REVOKE_ALL_MAX, (int) ($assoc['batch'] ?? ClaimRepository::REVOKE_ALL_MAX)));

        \WP_CLI::confirm("Revoke the verified claims of user {$userId} ({$reason})?", $assoc);

        $total = 0;
        while (true) {
            $revoked = ClaimService::revokeBatchForUser($userId, $reason, $batch, $wallet, $chain);
            $total  += $revoked;
            \WP_CLI::log("Revoked {$revoked} (total {$total}).");
            if ($revoked === 0) {
                break;
            }
        }
        \WP_CLI::success("Done: {$total} claims revoked.");
    }
}
