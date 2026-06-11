<?php

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\PageReadModelRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Security\IpResolver;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles user lifecycle events: registration, login, deletion, page creation.
 *
 * Migrated from includes/database/hooks.php.
 */
class UserLifecycleService
{
    private UserSyncService $syncService;
    private ScoreRepository $scoreRepo;

    public function __construct(
        UserSyncService $syncService,
        ScoreRepository $scoreRepo
    ) {
        $this->syncService = $syncService;
        $this->scoreRepo   = $scoreRepo;
    }

    /**
     * Register all WordPress lifecycle hooks.
     */
    public function register(): void
    {
        add_action('user_register', [$this, 'onUserRegister']);
        add_action('profile_update', [$this, 'onProfileUpdate']);
        add_action('delete_user', [$this, 'onUserDelete']);
        add_action('wp_login', [$this, 'onLogin'], 10, 2);
        add_action('peepso_page_created', [$this, 'onPageCreated']);
        add_action('before_delete_post', [$this, 'onPageDelete'], 10, 1);

        // PeepSo avatar changes don't trigger WP's profile_update hook.
        // Listen for PeepSo's avatar event to re-evaluate the complete_profile quest.
        add_action('peepso_user_after_change_avatar', [$this, 'onPeepSoAvatarChange']);

        // Admin actions
        add_action('admin_action_bcc_trust_sync_all_users', [$this, 'handleSyncAllUsers']);

        // AJAX handlers
        add_action('wp_ajax_bcc_trust_sync_user', [$this, 'ajaxSyncUser']);
        add_action('wp_ajax_bcc_trust_bulk_sync_users', [$this, 'ajaxBulkSyncUsers']);
        add_action('wp_ajax_bcc_trust_init_page_score', [$this, 'ajaxInitPageScore']);

        // NOTE: bcc_trust_archive_activity_event hook removed — retired cron, never scheduled.
        // NOTE: bcc_quest_complete_share_x and bcc_quest_disconnect AJAX handlers
        // retired in the FSE/blocks cleanup — replace with REST endpoints if/when
        // the quest UI returns in Next.js.
    }

    public function onUserRegister(int $userId): void
    {
        $this->syncService->sync($userId);
    }

    public function onProfileUpdate(int $userId): void
    {
        $this->syncService->sync($userId);

        // Attempt to auto-complete the profile quest whenever the user
        // saves their profile. The quest signal is validated server-side
        // by QuestValidator::validateCompleteProfile() which checks
        // PeepSo avatar + display name + bio/social link — so firing
        // the signal on every save is safe (no-op if conditions aren't met).
        do_action('bcc_trust_quest_signal', $userId, 'complete_profile');
    }

    /**
     * PeepSo avatar change — re-evaluate the complete_profile quest.
     * PeepSo passes (user_id, thumb_path, full_path, orig_path).
     */
    public function onPeepSoAvatarChange(int $userId): void
    {
        do_action('bcc_trust_quest_signal', $userId, 'complete_profile');
    }

    public function onUserDelete(int $userId): void
    {
        $plugin = Plugin::instance();

        // Soft-delete votes
        $plugin->voteRepository()->softDeleteAllByVoter($userId);

        // Soft-delete endorsements
        $plugin->endorsementRepository()->softDeleteByEndorser($userId);

        // Hard-delete analytics data
        $plugin->userInfoRepository()->deleteByUserId($userId);
        $plugin->reputationRepository()->delete($userId);
        (new \BCC\Trust\Core\Repositories\DeviceFingerprintRepository())->deleteForUser($userId);
        $plugin->fraudAnalysisRepository()->deleteForUser($userId);

        // Delete edges where user is source or target
        $plugin->edgeRepository()->deleteByUser($userId);

        // Clean up additional tables not covered by the above repositories.
        PageReadModelRepository::deleteUserData($userId, [
            TableRegistry::questLog()    => 'user_id',
            TableRegistry::patterns()    => 'user_id',
            TableRegistry::suspensions() => 'user_id',
            TableRegistry::pageFlags()   => 'user_id',
            TableRegistry::scoreEvents() => 'actor_user_id',
        ]);

        // Identity verifications (github / x / wallet).
        $plugin->verificationRepository()->deleteForUser($userId);

        // Rank awards + reputation-event ledger (the reputation SNAPSHOT row
        // is already cleared above via reputationRepository()->delete()).
        $plugin->userRankRepository()->deleteForUser($userId);
        $plugin->reputationEventRepository()->deleteForUser($userId);

        // Push subscriptions.
        (new \BCC\Trust\Core\Repositories\PushSubscriptionRepository())->deleteAllForUser($userId);

        // Trust attestations: rows the user cast AND rows cast against the
        // user's profile (target_kind='user_profile').
        $plugin->attestationRepository()->deleteForUser($userId);

        // Dispute footprint: panel seats, credited participations, and
        // user-reports in both directions (reporter + reported). Dispute
        // rows themselves are page-scoped and survive — see deleteForUser.
        \BCC\Trust\Disputes\Repositories\DisputeRepository::deleteForUser($userId);

        // On-chain wallet data. NFT holdings have no user_id of their own —
        // they resolve ownership via a join to wallet_links, so they MUST be
        // deleted BEFORE the wallet links are removed (otherwise the join
        // finds nothing and the holdings orphan). Selections key on user_id
        // directly. Claims and onchain signals also key on user_id.
        \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::deleteForUser($userId);
        \BCC\Trust\Onchain\Repositories\NftSelectionRepository::deleteForUser($userId);
        \BCC\Trust\Onchain\Repositories\ClaimRepository::deleteForUser($userId);
        \BCC\Trust\Onchain\Repositories\WalletRepository::deleteForUser($userId);

        // Wallet signals: delegate to onchain-signals via contract.
        \BCC\Trust\Core\Repositories\WalletSignalRepository::deleteForUser($userId);
    }

    /**
     * Clean up trust data when a PeepSo page (post) is permanently deleted.
     *
     * Only acts on 'peepso-page' post type. Soft-deletes votes and
     * endorsements (preserving audit trail), hard-deletes scores, flags,
     * score events, read model, and dirty queue entries.
     */
    public function onPageDelete(int $postId): void
    {
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'peepso-page') {
            return;
        }

        $plugin = Plugin::instance();

        // Soft-delete all votes and endorsements on this page.
        PageReadModelRepository::softDeleteVotesForPage($postId);
        PageReadModelRepository::softDeleteEndorsementsForPage($postId);

        // Hard-delete derived/materialized data (no audit value).
        PageReadModelRepository::deletePageData($postId, [
            TableRegistry::scores()       => 'page_id',
            TableRegistry::scoreVelocity() => 'page_id',
            TableRegistry::pageFlags()     => 'page_id',
            TableRegistry::scoreEvents()   => 'page_id',
            TableRegistry::dirtyQueue()    => 'page_id',
            TableRegistry::pageReadModel() => 'page_id',
        ]);

        // Disputes attached to this page — cascade panel + participation
        // children FIRST, then the disputes, in one transaction (the SQL
        // lives in the Disputes domain per §1).
        \BCC\Trust\Disputes\Repositories\DisputeRepository::deleteForPage($postId);

        // Card-attestations cast AGAINST this page (validator/project/creator
        // target_kinds key target_id = page_id) and the §J.8 divergence-state
        // sidecar rows for the same page-keyed kinds.
        $plugin->attestationRepository()->deleteForPageTarget($postId);
        $plugin->targetDivergenceStateRepository()->deleteForPageTarget($postId);

        // Invalidate caches.
        $plugin->scoreRepository()->invalidateCache($postId);
    }

    public function onLogin(string $userLogin, \WP_User $user): void
    {
        $ip = IpResolver::getClientIp();
        update_user_meta($user->ID, 'bcc_trust_last_ip', $ip);
        update_user_meta($user->ID, 'bcc_trust_last_login', current_time('mysql'));
        $this->syncService->sync($user->ID);
    }

    public function onPageCreated(int $pageId): void
    {
        $ownerId = (int) \BCC\Trust\Core\Services\PeepSoPageResolver::getOwnerId($pageId);
        if (!$ownerId) {
            $post = get_post($pageId);
            $ownerId = $post ? (int) $post->post_author : 0;
        }

        if (!$ownerId || !$pageId) {
            return;
        }

        $this->scoreRepo->initializeForPage($pageId, $ownerId);
    }

    public function handleSyncAllUsers(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('bcc_trust_sync_all_users');
        $count = $this->syncService->sync();

        wp_safe_redirect(add_query_arg([
            'page'       => 'bcc-trust-dashboard',
            'synced'     => (int) $count,
            'bcc_notice' => 'sync_complete',
        ], admin_url('admin.php')));
        exit;
    }

    public function ajaxSyncUser(): void
    {
        // Phase 1.7 dead-code observability (2026-05-09): no caller found
        // in any JS / PHP / TS in the codebase. The admin Repair tab uses
        // `admin_post_bcc_trust_sync_users` (PLURAL, different handler);
        // this singular AJAX handler appears orphaned. 30-day zero-hit
        // window → safe to retire per V-08 Phase D.
        \BCC\Core\Observability\DegradationMetrics::record('legacy_ajax', 'trust_sync_user');
        check_ajax_referer('bcc_trust_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $userId = absint($_POST['user_id'] ?? 0);
        if (!$userId) {
            wp_send_json_error(['message' => 'Invalid user ID']);
        }

        $this->syncService->sync($userId);
        wp_send_json_success(['message' => 'User synced', 'user_id' => $userId]);
    }

    public function ajaxBulkSyncUsers(): void
    {
        // Phase 1.7 dead-code observability (2026-05-09) — see ajaxSyncUser above.
        \BCC\Core\Observability\DegradationMetrics::record('legacy_ajax', 'trust_bulk_sync_users');
        check_ajax_referer('bcc_trust_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $userIds = array_filter(array_map('absint', (array) ($_POST['user_ids'] ?? [])));
        if (empty($userIds)) {
            wp_send_json_error(['message' => 'No users specified']);
        }

        $synced = 0;
        foreach ($userIds as $uid) {
            $synced += $this->syncService->sync($uid);
        }

        wp_send_json_success(['message' => "Synced {$synced} users", 'count' => $synced]);
    }

    public function ajaxInitPageScore(): void
    {
        // Phase 1.7 dead-code observability (2026-05-09) — see ajaxSyncUser above.
        \BCC\Core\Observability\DegradationMetrics::record('legacy_ajax', 'trust_init_page_score');
        check_ajax_referer('bcc_trust_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $pageId = absint($_POST['page_id'] ?? 0);
        if (!$pageId) {
            wp_send_json_error(['message' => 'Invalid page ID']);
        }

        $ownerId = (int) \BCC\Trust\Core\Services\PeepSoPageResolver::getOwnerId($pageId);
        if (!$ownerId) {
            $post = get_post($pageId);
            $ownerId = $post ? (int) $post->post_author : 0;
        }

        if (!$ownerId) {
            wp_send_json_error(['message' => 'Could not determine page owner']);
        }

        $this->scoreRepo->initializeForPage($pageId, $ownerId);
        wp_send_json_success(['message' => 'Page score initialized', 'page_id' => $pageId]);
    }

    /**
     * Archive old activity logs (90+ days) to archive table.
     */
    public function archiveActivity(): void
    {
        if (get_transient('bcc_archive_lock')) {
            return;
        }
        set_transient('bcc_archive_lock', 1, HOUR_IN_SECONDS);

        try {
            $auditLogRepo = new \BCC\Trust\Core\Repositories\AuditLogRepository();
            $auditLogRepo->archiveBatch(5000);
        } finally {
            delete_transient('bcc_archive_lock');
        }
    }
}
