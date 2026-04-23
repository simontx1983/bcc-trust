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

        // Quest: Share on X completion (logged-in users only via wp_ajax_)
        add_action('wp_ajax_bcc_quest_complete_share_x', [__CLASS__, 'ajaxCompleteShareX']);

        // Quest: Disconnect verification accounts
        add_action('wp_ajax_bcc_quest_disconnect', [__CLASS__, 'ajaxQuestDisconnect']);

        // NOTE: bcc_trust_archive_activity_event hook removed — retired cron, never scheduled.
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
     * AJAX: Verify the "Share on X" quest via API.
     * Called from the quest dashboard when the user clicks "I posted it!".
     * Checks the X API for a recent tweet containing the site URL.
     */
    public static function ajaxCompleteShareX(): void
    {
        // CSRF protection — action-scoped nonce. The 'wp_rest' nonce was
        // previously reused here, but a single broad nonce action is a
        // weaker authorisation signal than one tied to the specific
        // mutation. The matching nonce is localised as bccQuestShareXNonce
        // in templates/profile-quests.php (action 'bcc_quest_share_x').
        if (!check_ajax_referer('bcc_quest_share_x', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Security check failed'], 403);
        }

        $userId = get_current_user_id();
        if (!$userId) {
            wp_send_json_error(['message' => 'Not logged in']);
        }

        // Already done?
        $questService = Plugin::instance()->questProgressService();
        $progress     = $questService->getProgress($userId);
        if (!empty($progress['quests']['share_x']['done'])) {
            wp_send_json_success(['message' => 'Already complete']);
        }

        // Fire the quest signal — validator checks the X API
        do_action('bcc_trust_quest_signal', $userId, 'share_x');

        // Check if it completed
        $questService->invalidateCache($userId);
        $progress = $questService->getProgress($userId);
        if (!empty($progress['quests']['share_x']['done'])) {
            wp_send_json_success(['message' => 'Tweet verified! Quest complete.']);
        } else {
            wp_send_json_error(['message' => 'Tweet not found yet. Make sure you posted the tweet with your profile link, then try again.']);
        }
    }

    /**
     * AJAX: Disconnect a verified account (X or GitHub) and revoke its quest.
     */
    public static function ajaxQuestDisconnect(): void
    {
        // CSRF protection — action-scoped nonce. Previously verified against
        // the broad 'wp_rest' nonce (also used for every REST request);
        // tightening to the dedicated 'bcc_quest_disconnect' action prevents
        // the same nonce from authorising unrelated mutations. The matching
        // nonce is localised as bccQuestDisconnectNonce in
        // templates/profile-quests.php.
        if (!check_ajax_referer('bcc_quest_disconnect', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Security check failed'], 403);
        }

        $userId = get_current_user_id();
        if (!$userId) {
            wp_send_json_error(['message' => 'Not logged in']);
        }

        $slug = sanitize_key((string) ($_POST['slug'] ?? ''));
        $allowed = ['verify_x', 'verify_github'];
        if (!in_array($slug, $allowed, true)) {
            wp_send_json_error(['message' => 'Invalid action']);
        }

        try {
            if ($slug === 'verify_x') {
                (new \BCC\Trust\Core\Services\x\XVerificationService())->disconnect($userId);
            } elseif ($slug === 'verify_github') {
                (new \BCC\Trust\Core\Services\github\GitHubVerificationService())->disconnect($userId);
            }

            wp_send_json_success(['message' => 'Disconnected']);
        } catch (\Throwable $e) {
            // Log the real error at ERROR level for operators; return a
            // generic message to the client. Leaking raw exception text
            // exposes internal implementation (DB errors, token-store
            // layout, contract signatures) to any logged-in user who can
            // trigger the failure path — a reconnaissance aid.
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] quest disconnect failed', [
                    'user_id' => $userId,
                    'slug'    => $slug,
                    'error'   => $e->getMessage(),
                ]);
            }
            wp_send_json_error(['message' => 'Failed to disconnect.']);
        }
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
