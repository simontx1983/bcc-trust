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

    private const CACHE_GROUP = 'bcc_trust_verifications';
    private const CACHE_TTL   = 60;

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
     * @return array<string, mixed>
     */
    public function getGithubData(int $userId): array
    {
        $empty = [
            'connected'       => false,
            'username'        => null,
            'verified_at'     => null,
            'github_id'       => null,
            'followers'       => 0,
            'public_repos'    => 0,
            'org_count'       => 0,
            'trust_boost'     => 0.0,
            'fraud_reduction' => 0,
        ];

        if (!$userId) {
            return $empty;
        }

        $cache_key = 'github_' . $userId;
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return is_array($cached) ? $cached : $empty;
        }

        global $wpdb;
        $table = \BCC\Trust\Core\Database\TableRegistry::userVerifications();
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT provider_id, provider_username, verified_at,
                    trust_boost, fraud_reduction, meta
             FROM {$table}
             WHERE user_id = %d AND type = 'github' AND status = 'active'",
            $userId
        ));

        if (!$row || !$row->provider_username) {
            wp_cache_set($cache_key, 0, self::CACHE_GROUP, self::CACHE_TTL);
            return $empty;
        }

        $meta   = $row->meta ? json_decode($row->meta, true) : [];
        $result = [
            'connected'       => true,
            'username'        => $row->provider_username,
            'verified_at'     => $row->verified_at,
            'github_id'       => $row->provider_id,
            'followers'       => (int) ($meta['followers'] ?? 0),
            'public_repos'    => (int) ($meta['public_repos'] ?? 0),
            'org_count'       => (int) ($meta['org_count'] ?? 0),
            'trust_boost'     => (float) $row->trust_boost,
            'fraud_reduction' => (int) $row->fraud_reduction,
        ];

        wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL);
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getXData(int $userId): array
    {
        $empty = [
            'connected'       => false,
            'username'        => null,
            'email'           => null,
            'verified_at'     => null,
            'trust_boost'     => 0.0,
            'fraud_reduction' => 0,
        ];

        if (!$userId) {
            return $empty;
        }

        $cache_key = 'x_' . $userId;
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return is_array($cached) ? $cached : $empty;
        }

        global $wpdb;
        $table = \BCC\Trust\Core\Database\TableRegistry::userVerifications();
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT provider_username, verified_at,
                    trust_boost, fraud_reduction, meta
             FROM {$table}
             WHERE user_id = %d AND type = 'x' AND status = 'active'",
            $userId
        ));

        if (!$row || !$row->provider_username) {
            wp_cache_set($cache_key, 0, self::CACHE_GROUP, self::CACHE_TTL);
            return $empty;
        }

        $meta   = $row->meta ? json_decode($row->meta, true) : [];
        $result = [
            'connected'       => true,
            'username'        => $row->provider_username,
            'email'           => $meta['email'] ?? null,
            'verified_at'     => $row->verified_at,
            'trust_boost'     => (float) $row->trust_boost,
            'fraud_reduction' => (int) $row->fraud_reduction,
        ];

        wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL);
        return $result;
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
