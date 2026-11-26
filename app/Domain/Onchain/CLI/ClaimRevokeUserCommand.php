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
    private const REASONS = ['wallet_unlinked', 'account_deleted', 'ownership_disputed'];

    /**
     * Why the break-glass refuses to run, or null: once a validator claim is
     * affected, an approver and the evidence are both required.
     */
    public static function breakGlassRefusal(bool $validatorClaimAffected, string $approver, string $evidence): ?string
    {
        if (!$validatorClaimAffected) {
            return null;
        }
        if ($approver === '' || $evidence === '') {
            return 'This user holds a verified validator claim: --approver=<name> and --evidence=<text> are both required, and are kept in the relationship log.';
        }

        return null;
    }

    /**
     * Revoke a user's verified claims, in batches.
     *
     * ## OPTIONS
     *
     * <user_id>
     * : The user whose claims to revoke.
     *
     * --reason=<reason>
     * : wallet_unlinked, account_deleted or ownership_disputed.
     *
     * [--approver=<name>]
     * : Who approved this (required when a validator claim is affected).
     *
     * [--evidence=<text>]
     * : Why (required when a validator claim is affected). Kept in the
     *   relationship log's redactable evidence column.
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
        if ($userId <= 0 || !in_array($reason, self::REASONS, true)) {
            \WP_CLI::error('Usage: wp bcc-trust claims-revoke-user <user_id> --reason=<wallet_unlinked|account_deleted|ownership_disputed>');
        }
        $wallet   = isset($assoc['wallet']) ? (string) $assoc['wallet'] : null;
        $chain    = isset($assoc['chain']) ? (int) $assoc['chain'] : null;
        $batch    = max(1, min(ClaimRepository::REVOKE_ALL_MAX, (int) ($assoc['batch'] ?? ClaimRepository::REVOKE_ALL_MAX)));
        $approver = trim((string) ($assoc['approver'] ?? ''));
        $evidence = trim((string) ($assoc['evidence'] ?? ''));

        // A validator owner claim is ended on the record: the break-glass
        // names who approved it and why.
        $refusal = self::breakGlassRefusal(ClaimRepository::verifiedValidatorClaimExists($userId, $wallet, $chain), $approver, $evidence);
        if ($refusal !== null) {
            \WP_CLI::error($refusal);
        }

        \WP_CLI::confirm("Revoke the verified claims of user {$userId} ({$reason})?", $assoc);

        $extra = $approver !== '' ? ['approver' => $approver, 'via' => 'cli'] : ['via' => 'cli'];
        $total = 0;
        while (true) {
            $revoked = ClaimService::revokeBatchForUser($userId, $reason, $batch, $wallet, $chain, 'system', null, $extra, $evidence !== '' ? $evidence : null);
            $total  += $revoked;
            \WP_CLI::log("Revoked {$revoked} (total {$total}).");
            if ($revoked === 0) {
                break;
            }
        }
        \WP_CLI::success("Done: {$total} claims revoked.");
    }
}
