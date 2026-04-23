<?php

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\UserSyncRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Syncs WordPress user data to the user_info table.
 *
 * Migrated from includes/database/sync-functions.php.
 */
class UserSyncService
{
    private UserInfoRepository $userInfoRepo;

    public function __construct(UserInfoRepository $userInfoRepo)
    {
        $this->userInfoRepo = $userInfoRepo;
    }

    /**
     * Sync one user or all users to the user_info table.
     *
     * @param int|null $userId Specific user ID, or null for all users.
     * @return int Number of users synced.
     */
    public function sync(?int $userId = null): int
    {
        if ($userId) {
            $user = get_userdata($userId);
            return $user ? $this->syncSingleUser($user) : 0;
        }

        $synced = 0;
        $batch  = 500;
        $offset = 0;

        do {
            $users = get_users([
                'number' => $batch,
                'offset' => $offset,
                'fields' => 'all',
            ]);

            if (empty($users)) {
                break;
            }

            $user_ids     = array_map(fn($u) => (int) $u->ID, $users);
            $batch_counts = UserSyncRepository::batchFetchCounts($user_ids);

            foreach ($users as $user) {
                $counts = $batch_counts[(int) $user->ID] ?? [];
                $synced += $this->syncSingleUser($user, $counts);
            }

            $offset += $batch;
        } while (count($users) === $batch);

        return $synced;
    }

    /**
     * Sync a single WP_User to the user_info table.
     *
     * @param array<string, mixed> $counts Pre-fetched batch counts from UserSyncRepository.
     *                                     When empty (single-user sync), fetches per-user.
     *
     * @phpstan-param \WP_User|object{
     *   ID: int,
     *   user_login: string,
     *   user_email: string,
     *   display_name: string,
     *   user_registered: string
     * } $user
     */
    private function syncSingleUser(object $user, array $counts = []): int
    {

        if (!empty($counts)) {
            $peepso_user   = $counts['peepso_user'] ?? null;
            $pages_owned   = (int) ($counts['pages_owned'] ?? 0);
            $groups_owned  = (int) ($counts['groups_owned'] ?? 0);
            $posts_created = (int) ($counts['posts_created'] ?? 0);
            $comments_made = (int) ($counts['comments_made'] ?? 0);
        } else {
            $single        = UserSyncRepository::fetchCountsForUser((int) $user->ID);
            $peepso_user   = $single['peepso_user'];
            $pages_owned   = (int) $single['pages_owned'];
            $groups_owned  = (int) $single['groups_owned'];
            $posts_created = (int) $single['posts_created'];
            $comments_made = (int) $single['comments_made'];
        }

        $trust_data         = get_user_meta($user->ID, 'bcc_trust_fraud_analysis', true);
        $fraud_score        = (int) get_user_meta($user->ID, 'bcc_trust_fraud_score', true);
        $trust_rank         = (float) get_user_meta($user->ID, 'bcc_trust_graph_rank', true);
        $votes_cast         = (int) get_user_meta($user->ID, 'bcc_trust_votes_cast', true);
        $endorsements_given = (int) get_user_meta($user->ID, 'bcc_trust_endorsements_given', true);

        $data = [
            'user_id'            => $user->ID,
            'user_login'         => $user->user_login,
            'user_email'         => $user->user_email,
            'display_name'       => $user->display_name ?: $user->user_login,
            'registered'         => $user->user_registered,
            'usr_last_activity'  => $peepso_user ? $peepso_user->usr_last_activity : null,
            'usr_views'          => $peepso_user ? (int) $peepso_user->usr_views : 0,
            'usr_likes'          => $peepso_user ? (int) $peepso_user->usr_likes : 0,
            'usr_role'           => $peepso_user ? $peepso_user->usr_role : null,
            'fraud_score'        => $fraud_score,
            'trust_rank'         => $trust_rank,
            'risk_level'         => is_array($trust_data) ? ($trust_data['risk_level'] ?? 'unknown') : 'unknown',
            'is_suspended'       => (int) get_user_meta($user->ID, 'bcc_trust_suspended', true),
            'is_verified'        => self::isEmailVerified($user->ID),
            'votes_cast'         => $votes_cast,
            'endorsements_given' => $endorsements_given,
            'pages_owned'        => $pages_owned,
            'groups_owned'       => $groups_owned,
            'posts_created'      => $posts_created,
            'comments_made'      => $comments_made,
            'last_ip_address'    => get_user_meta($user->ID, 'bcc_trust_last_ip', true),
            'device_fingerprint' => get_user_meta($user->ID, 'bcc_trust_device_fingerprint', true),
            'automation_score'   => (int) get_user_meta($user->ID, 'bcc_trust_automation_score', true),
        ];

        $formats = [];
        foreach ($data as $key => $value) {
            if (in_array($key, [
                'fraud_score', 'automation_score', 'behavior_score', 'votes_cast', 'endorsements_given',
                'pages_owned', 'groups_owned', 'posts_created', 'comments_made',
                'usr_views', 'usr_likes', 'is_suspended', 'is_verified',
                'github_id', 'github_followers', 'github_public_repos', 'github_org_count',
                'github_account_age_days', 'github_has_verified_email', 'github_fraud_reduction',
            ], true)) {
                $formats[] = '%d';
            } elseif (in_array($key, ['trust_rank', 'github_trust_boost'], true)) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }

        if ($this->userInfoRepo->exists((int) $user->ID)) {
            $this->userInfoRepo->updateByUserId((int) $user->ID, $data, $formats);
        } else {
            $this->userInfoRepo->insert($data, $formats);
        }

        return 1;
    }

    /**
     * Determine if a user's email is verified.
     *
     * Checks three sources (any true = verified):
     *  1. Trust engine's own token-based verification (bcc_trust_email_verified meta)
     *  2. WordPress confirmed user status (user_status = 0 means confirmed)
     *  3. PeepSo verification status (peepso_is_verified_email meta)
     *
     * This ensures users who registered through WordPress or PeepSo's
     * normal flow are treated as verified without needing a separate
     * trust-engine verification step.
     */
    private static function isEmailVerified(int $userId): int
    {
        // Trust engine's own flag (set via /verify-email endpoint)
        if ((int) get_user_meta($userId, 'bcc_trust_email_verified', true)) {
            return 1;
        }

        // WordPress: user_status = 0 means account is active/confirmed
        $user = get_userdata($userId);
        if ($user && (int) $user->user_status === 0) {
            return 1;
        }

        // PeepSo email verification
        if ((int) get_user_meta($userId, 'peepso_is_verified_email', true)) {
            return 1;
        }

        return 0;
    }
}
