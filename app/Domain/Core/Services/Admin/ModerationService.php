<?php

namespace BCC\Trust\Core\Services\Admin;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\SuspensionRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Core\Log\Logger;
use BCC\Trust\Core\Security\AuditLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Moderation Service
 *
 * Migrated from includes/admin/moderation-handlers.php.
 * Contains all admin moderation action logic: suspend/unsuspend users,
 * clear votes/fingerprints, reanalyze, score override, ring actions,
 * whitelist management, and bulk actions.
 *
 * @package BCC\Trust\Core\Services\Admin
 */
final class ModerationService
{
    private ScoreRepository $scoreRepository;
    private UserInfoRepository $userInfoRepository;
    private VoteRepository $voteRepository;
    private SuspensionRepository $suspensionRepository;

    public function __construct(
        ScoreRepository $scoreRepository,
        UserInfoRepository $userInfoRepository,
        VoteRepository $voteRepository
    ) {
        $this->scoreRepository      = $scoreRepository;
        $this->userInfoRepository   = $userInfoRepository;
        $this->voteRepository       = $voteRepository;
        $this->suspensionRepository = \BCC\Trust\Core\Plugin::instance()->suspensionRepository();
    }

    // ──────────────────────────────────────────────────────────────
    // SUSPEND / UNSUSPEND
    // ──────────────────────────────────────────────────────────────

    /**
     * Suspend a user by inserting a suspension record and flagging user_info.
     *
     * @param int $userId The user to suspend.
     * @return array{success: bool, message: string}
     */
    public function suspendUser(int $userId, string $reason = 'manual_suspension', string $notes = ''): array
    {
        $fraudScore = $this->userInfoRepository->getFraudScore($userId);

        try {
            $this->suspensionRepository->create($userId, get_current_user_id(), $reason, $notes, $fraudScore);
        } catch (\Exception $e) {
            Logger::error('[bcc-trust] suspend_insert_failed', [
                'user_id'  => $userId,
                'db_error' => $e->getMessage(),
            ]);
            echo '<div class="notice notice-error"><p>Failed to suspend user: database error.</p></div>';
            return ['success' => false, 'message' => 'Failed to suspend user: database error.'];
        }

        try {
            $this->userInfoRepository->suspendUser($userId, $reason);
        } catch (\Exception $e) {
            Logger::error('[bcc-trust] suspend_flag_update_failed', [
                'user_id'  => $userId,
                'db_error' => $e->getMessage(),
            ]);
            echo '<div class="notice notice-error"><p>Suspension recorded but user flag update failed.</p></div>';
            return ['success' => false, 'message' => 'Suspension recorded but user flag update failed.'];
        }

        $this->logAction('admin_suspend', $userId, [
            'reason' => $reason,
            'notes'  => $notes,
        ]);

        echo '<div class="notice notice-success"><p>User suspended. Reason: ' . esc_html($reason) . '</p></div>';
        return ['success' => true, 'message' => 'User suspended. Reason: ' . $reason];
    }

    /**
     * Unsuspend a user by closing the active suspension record and clearing the flag.
     *
     * @param int $userId The user to unsuspend.
     * @return array{success: bool, message: string}
     */
    public function unsuspendUser(int $userId): array
    {
        try {
            \BCC\Trust\Core\Security\TransactionManager::run(function () use ($userId) {
                $this->suspensionRepository->closeActive($userId, get_current_user_id());
                $this->userInfoRepository->unsuspendUser($userId);
            });
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] unsuspend_failed', [
                'user_id'  => $userId,
                'db_error' => $e->getMessage(),
            ]);
            echo '<div class="notice notice-error"><p>Failed to unsuspend user: database error.</p></div>';
            return ['success' => false, 'message' => 'Failed to unsuspend user: database error.'];
        }

        $this->logAction('admin_unsuspend', $userId);

        echo '<div class="notice notice-success"><p>User unsuspended.</p></div>';
        return ['success' => true, 'message' => 'User unsuspended.'];
    }

    // ──────────────────────────────────────────────────────────────
    // CLEAR VOTES
    // ──────────────────────────────────────────────────────────────

    /**
     * Soft-delete all votes cast by a user and recalculate affected page scores.
     *
     * @param int $userId The voter whose votes to clear.
     * @return array{success: bool, message: string, pages_affected: int}
     */
    public function clearVotes(int $userId): array
    {
        $affectedPages = $this->voteRepository->getActivePageIdsByVoter($userId);

        $r1 = $this->voteRepository->softDeleteAllByVoter($userId);

        if ($r1 === false) {
            Logger::error('[bcc-trust] clear_votes_failed', [
                'user_id' => $userId,
            ]);
            echo '<div class="notice notice-error"><p>Failed to clear votes: database error.</p></div>';
            return ['success' => false, 'message' => 'Failed to clear votes: database error.', 'pages_affected' => 0];
        }

        $this->userInfoRepository->updateVotesCast($userId, 0);

        foreach ($affectedPages as $pageId) {
            do_action('bcc.trust.recalculate_score', (int) $pageId);
        }

        $this->logAction('admin_clear_votes', $userId, [
            'pages_affected' => count($affectedPages),
        ]);

        echo '<div class="notice notice-success"><p>Votes cleared. ' . count($affectedPages) . ' page scores recalculated.</p></div>';
        return ['success' => true, 'message' => 'Votes cleared.', 'pages_affected' => count($affectedPages)];
    }

    // ──────────────────────────────────────────────────────────────
    // CLEAR FINGERPRINTS
    // ──────────────────────────────────────────────────────────────

    /**
     * Delete all device fingerprints for a user and reset fingerprint-related fields.
     *
     * @param int $userId The user whose fingerprints to clear.
     * @return array{success: bool, message: string}
     */
    public function clearFingerprints(int $userId): array
    {
        // Delete fingerprint records
        (new \BCC\Trust\Core\Repositories\DeviceFingerprintRepository())->deleteForUser($userId);

        // Clear fingerprint/automation data in user_info
        $this->userInfoRepository->clearFingerprint($userId);

        $this->logAction('admin_clear_fingerprints', $userId);

        echo '<div class="notice notice-success"><p>Device fingerprints cleared.</p></div>';
        return ['success' => true, 'message' => 'Device fingerprints cleared.'];
    }

    // ──────────────────────────────────────────────────────────────
    // REANALYZE USER
    // ──────────────────────────────────────────────────────────────

    /**
     * Re-run fraud analysis for a user via FraudDetector and update their scores.
     *
     * @param int $userId The user to reanalyze.
     * @return array{success: bool, message: string, new_score?: int}
     */
    public function reanalyzeUser(int $userId): array
    {
        \BCC\Trust\Core\Security\FraudDetector::clearCache($userId);
        $newScore = \BCC\Trust\Core\Security\FraudDetector::getEnhancedFraudScore($userId);
        $analysis = \BCC\Trust\Core\Security\FraudDetector::analyzeFraud($userId);

        $this->userInfoRepository->updateFraudFields(
            $userId,
            (int) $newScore,
            $analysis['risk_level'] ?? 'unknown',
            (int) ($analysis['details']['behavior_score'] ?? 0)
        );

        $this->logAction('admin_reanalyze', $userId, ['new_score' => $newScore]);

        echo '<div class="notice notice-success"><p>Reanalysis complete. New fraud score: <strong>' . intval($newScore) . '</strong></p></div>';
        return ['success' => true, 'message' => 'Reanalysis complete.', 'new_score' => (int) $newScore];
    }

    // ──────────────────────────────────────────────────────────────
    // MANUAL TRUST SCORE OVERRIDE
    // ──────────────────────────────────────────────────────────────

    /**
     * Override the trust score for all pages owned by a user.
     *
     * @param int $userId   The page owner whose scores to override.
     * @param int $newScore The new score (clamped 0-100).
     * @return array{success: bool, message: string, tier?: string, pages_updated?: int}
     */
    public function overrideScore(int $userId, int $newScore, string $reason = ''): array
    {
        $newScore = min(100, max(0, $newScore));

        if (empty($reason)) {
            echo '<div class="notice notice-error"><p>Override reason is required.</p></div>';
            return ['success' => false, 'message' => 'Override reason is required.'];
        }

        // Determine tier from new score
        $tier = 'risky';
        if ($newScore >= BCC_TRUST_TIER_ELITE) {
            $tier = 'elite';
        } elseif ($newScore >= BCC_TRUST_TIER_TRUSTED) {
            $tier = 'trusted';
        } elseif ($newScore >= BCC_TRUST_TIER_NEUTRAL) {
            $tier = 'neutral';
        } elseif ($newScore >= BCC_TRUST_TIER_CAUTION) {
            $tier = 'caution';
        }

        // Collect affected page IDs before the UPDATE (for read model sync).
        $affectedPageIds = $this->scoreRepository->getPageIdsByOwner($userId);

        $updated = $this->scoreRepository->overrideScoreByOwner($userId, (float) $newScore, $tier);

        // Sync read model for all affected pages.
        foreach ($affectedPageIds as $pid) {
            do_action('bcc.trust.recalculate_score', (int) $pid);
        }

        $this->logAction('admin_score_override', $userId, [
            'new_score'     => $newScore,
            'new_tier'      => $tier,
            'reason'        => $reason,
            'pages_updated' => $updated,
        ]);

        echo '<div class="notice notice-success"><p>Trust score overridden to <strong>' . $newScore . '</strong> (' . esc_html($tier) . ') for ' . intval($updated) . ' page(s). Reason: ' . esc_html($reason) . '</p></div>';
        return ['success' => true, 'message' => 'Trust score overridden.', 'tier' => $tier, 'pages_updated' => $updated];
    }

    // ──────────────────────────────────────────────────────────────
    // VOTE RING ACTIONS
    // ──────────────────────────────────────────────────────────────

    /**
     * Handle vote ring moderation actions (invalidate votes or suspend all members).
     *
     * Expects the nonce 'bcc_trust_ring_action' to have been verified by the caller.
     *
     * @param array  $userIds Array of user IDs in the ring.
     * @param int[]  $userIds
     * @param string $action  One of 'invalidate_votes' or 'suspend_all'.
     * @return void
     */
    public function handleRingAction(array $userIds, string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('bcc_trust_ring_action');

        $userIds = array_filter(array_map('absint', $userIds));

        if (empty($userIds)) {
            wp_safe_redirect(add_query_arg('ring_msg', 'no_users', wp_get_referer()));
            exit;
        }

        $adminId = get_current_user_id();

        switch ($action) {

            case 'invalidate_votes':
                // Soft-delete all votes cast by ring members
                $affectedPages = $this->voteRepository->getActivePageIdsByVoterIds($userIds);
                $this->voteRepository->softDeleteByVoterIds($userIds);

                // Invalidate inbound votes (votes on pages owned by ring members).
                // Single batched query instead of one per user.
                $ownedPages = $this->scoreRepository->getPageIdsByOwners($userIds);

                if (!empty($ownedPages)) {
                    $this->voteRepository->softDeleteByPageIds($ownedPages);
                    $affectedPages = array_unique(array_merge($affectedPages, $ownedPages));
                }

                // Recalculate affected pages
                foreach ($affectedPages as $pageId) {
                    do_action('bcc.trust.recalculate_score', (int) $pageId);
                }

                $this->logAction('admin_ring_invalidate_votes', 0, [
                    'ring_users'     => $userIds,
                    'pages_affected' => count($affectedPages),
                ]);

                $msg = 'invalidated_' . count($affectedPages);
                break;

            case 'suspend_all':
                $suspended = 0;
                foreach ($userIds as $uid) {
                    $fraudScore = $this->userInfoRepository->getFraudScore($uid);

                    try {
                        $this->suspensionRepository->create($uid, $adminId, 'vote_ring', 'Suspended as part of vote ring action.', $fraudScore);
                        $this->userInfoRepository->suspendUser($uid, 'vote_ring');
                        $suspended++;
                    } catch (\Exception $e) {
                        // Continue with remaining users
                    }
                }

                $this->logAction('admin_ring_suspend_all', 0, [
                    'ring_users' => $userIds,
                    'suspended'  => $suspended,
                ]);

                $msg = 'suspended_' . $suspended;
                break;

            default:
                $msg = 'unknown_action';
        }

        wp_safe_redirect(add_query_arg(['tab' => 'rings', 'ring_msg' => $msg],
            admin_url('admin.php?page=bcc-trust-dashboard')));
        exit;
    }

    // ──────────────────────────────────────────────────────────────
    // IP / DEVICE WHITELIST
    // ──────────────────────────────────────────────────────────────

    /**
     * Add an IP or fingerprint to the whitelist.
     *
     * @param string $type  One of 'ip' or 'fingerprint'.
     * @param string $value The IP address or fingerprint hash.
     * @return array{success: bool, message: string}
     */
    public function addToWhitelist(string $type, string $value): array
    {
        $note = sanitize_text_field($_POST['whitelist_note'] ?? '');

        if (empty($value) || !in_array($type, ['ip', 'fingerprint'], true)) {
            echo '<div class="notice notice-error"><p>Invalid whitelist entry.</p></div>';
            return ['success' => false, 'message' => 'Invalid whitelist entry.'];
        }

        $list = $this->getWhitelist($type);
        if (!isset($list[$value])) {
            $list[$value] = [
                'added_by' => get_current_user_id(),
                'added_at' => current_time('mysql'),
                'note'     => $note,
            ];
            update_option("bcc_trust_whitelist_{$type}", $list, false);
            $this->logAction('admin_whitelist_add', 0, ['type' => $type, 'value' => $value]);
        }

        echo '<div class="notice notice-success"><p>' . esc_html(strtoupper($type)) . ' <code>' . esc_html($value) . '</code> added to whitelist.</p></div>';
        return ['success' => true, 'message' => strtoupper($type) . ' ' . $value . ' added to whitelist.'];
    }

    /**
     * Remove an IP or fingerprint from the whitelist.
     *
     * @param string $type  One of 'ip' or 'fingerprint'.
     * @param string $value The IP address or fingerprint hash.
     * @return array{success: bool, message: string}
     */
    public function removeFromWhitelist(string $type, string $value): array
    {
        if (empty($value) || !in_array($type, ['ip', 'fingerprint'], true)) {
            return ['success' => false, 'message' => 'Invalid whitelist entry.'];
        }

        $list = $this->getWhitelist($type);
        unset($list[$value]);
        update_option("bcc_trust_whitelist_{$type}", $list, false);

        $this->logAction('admin_whitelist_remove', 0, ['type' => $type, 'value' => $value]);

        echo '<div class="notice notice-success"><p>Removed <code>' . esc_html($value) . '</code> from whitelist.</p></div>';
        return ['success' => true, 'message' => 'Removed ' . $value . ' from whitelist.'];
    }

    // ──────────────────────────────────────────────────────────────
    // BULK USER ACTION
    // ──────────────────────────────────────────────────────────────

    /**
     * Handle bulk moderation actions on multiple users.
     *
     * Expects the nonce 'bcc_trust_bulk_action' to have been verified by the caller.
     *
     * @param array  $userIds Array of user IDs.
     * @param int[]  $userIds
     * @param string $action  One of 'suspend', 'unsuspend', 'clear_votes', 'reanalyze'.
     * @return void
     */
    public function handleBulkAction(array $userIds, string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('bcc_trust_bulk_action');

        $userIds = array_filter(array_map('absint', $userIds));

        if ($action === '-1' || empty($userIds)) {
            wp_safe_redirect(add_query_arg('bulk', 'no_action', wp_get_referer()));
            exit;
        }

        $adminId   = get_current_user_id();
        $processed = 0;

        foreach ($userIds as $uid) {
            switch ($action) {
                case 'suspend':
                    $score = $this->userInfoRepository->getFraudScore($uid);
                    try {
                        $this->suspensionRepository->create($uid, $adminId, 'manual_suspension', '', $score);
                        $this->userInfoRepository->suspendUser($uid, 'manual_suspension');
                    } catch (\Exception $e) {
                        // Continue with remaining users
                    }
                    $processed++;
                    break;

                case 'unsuspend':
                    try {
                        $this->suspensionRepository->closeActive($uid, $adminId);
                        $this->userInfoRepository->unsuspendUser($uid);
                    } catch (\Exception $e) {
                        // Continue with remaining users
                    }
                    $processed++;
                    break;

                case 'clear_votes':
                    $this->voteRepository->softDeleteAllByVoter($uid);
                    $processed++;
                    break;

                case 'reanalyze':
                    \BCC\Trust\Core\Security\FraudDetector::clearCache($uid);
                    $processed++;
                    break;
            }
        }

        $this->logAction('admin_bulk_' . $action, 0, [
            'users'     => $userIds,
            'processed' => $processed,
        ]);

        wp_safe_redirect(add_query_arg('bulk', $processed, wp_get_referer()));
        exit;
    }

    // ──────────────────────────────────────────────────────────────
    // ADMIN ACTION LOGGER
    // ──────────────────────────────────────────────────────────────

    /**
     * Log an admin moderation action to the audit trail.
     *
     * @param string $action   Action slug, e.g. 'admin_suspend'.
     * @param int                   $targetId Subject user ID (0 for ring/bulk actions).
     * @param array<string, mixed>  $data     Extra context stored as JSON.
     */
    public function logAction(string $action, int $targetId, array $data = []): void
    {
        $data['admin_id']   = get_current_user_id();
        $data['admin_name'] = wp_get_current_user()->display_name ?? '';

        AuditLogger::log($action, $targetId, $data, 'user');
    }

    // ──────────────────────────────────────────────────────────────
    // WHITELIST HELPERS (private)
    // ──────────────────────────────────────────────────────────────

    /**
     * Get the whitelist array for a given type.
     *
     * @param string $type One of 'ip' or 'fingerprint'.
     * @return array<string, mixed> Associative array [value => meta].
     */
    private function getWhitelist(string $type): array
    {
        return (array) get_option("bcc_trust_whitelist_{$type}", []);
    }

    /**
     * Returns whitelisted IPs as an associative array [ip => meta].
     *
     * @return array<string, mixed>
     */
    public function getIpWhitelist(): array
    {
        return $this->getWhitelist('ip');
    }

    /**
     * Returns whitelisted fingerprints as an associative array [fp => meta].
     *
     * @return array<string, mixed>
     */
    public function getFingerprintWhitelist(): array
    {
        return $this->getWhitelist('fingerprint');
    }

    /**
     * Returns true if the given IP is whitelisted.
     *
     * @param string $ip The IP address to check.
     * @return bool
     */
    public function isIpWhitelisted(string $ip): bool
    {
        return isset($this->getIpWhitelist()[$ip]);
    }

    /**
     * Returns true if the given fingerprint is whitelisted.
     *
     * @param string $fp The fingerprint hash to check.
     * @return bool
     */
    public function isFingerprintWhitelisted(string $fp): bool
    {
        return isset($this->getFingerprintWhitelist()[$fp]);
    }
}
