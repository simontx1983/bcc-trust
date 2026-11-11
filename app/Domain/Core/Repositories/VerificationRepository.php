<?php
/**
 * Verification Repository
 *
 * Reads identity verification state from the bcc_trust_user_verifications
 * table (GitHub, X, wallet). Email verification is owned by PeepSo; the
 * PeepSo bridge mirrors its state into bcc_trust_user_info.is_verified.
 *
 * @package BCC\Trust\Core\Repositories
 */

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class VerificationRepository {

    private UserInfoRepository $userInfoRepo;

    public function __construct() {
        $this->userInfoRepo = new UserInfoRepository();
    }

    /**
     * Email-verified flag. Source of truth is bcc_trust_user_info.is_verified,
     * which is written by the PeepSo bridge on peepso_register_verified.
     */
    public function isVerified(int $userId): bool {
        $userInfo = $this->userInfoRepo->getByUserId($userId);
        return $userInfo ? (bool) $userInfo->is_verified : false;
    }

    /**
     * Check if a user has an active verification of a specific type.
     *
     * Uses the user_verifications table (identity providers: github, x, wallet).
     */
    public function hasActiveVerificationByType(int $userId, string $type): bool
    {
        global $wpdb;
        $table = \BCC\Trust\Core\Database\TableRegistry::userVerifications();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE user_id = %d AND type = %s AND status = 'active' LIMIT 1",
            $userId,
            $type
        ));
    }

    /**
     * Alias for hasActiveVerificationByType — used by QuestValidator.
     */
    public function hasVerification(int $userId, string $type): bool
    {
        return $this->hasActiveVerificationByType($userId, $type);
    }

    /**
     * @return array{count: int, types: string[]}
     */
    public function getVerifiedIdentityCount(int $userId): array
    {
        if (!$userId) {
            return ['count' => 0, 'types' => []];
        }

        global $wpdb;
        $table = \BCC\Trust\Core\Database\TableRegistry::userVerifications();
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT type FROM {$table} WHERE user_id = %d AND status = 'active'",
            $userId
        ));

        return [
            'count' => count($rows),
            'types' => array_map(fn($r) => $r->type, $rows),
        ];
    }

    /**
     * Hard-delete every identity-verification row (github / x / wallet)
     * owned by a deleted user. Closes the orphan gap on hard account
     * deletion — bcc_trust_user_verifications keys on user_id.
     *
     * Called from UserLifecycleService::onUserDelete (delete_user).
     */
    public function deleteForUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        global $wpdb;
        $table = \BCC\Trust\Core\Database\TableRegistry::userVerifications();
        $wpdb->delete($table, ['user_id' => $userId], ['%d']);
    }
}
