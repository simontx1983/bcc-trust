<?php

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\PageReadModelRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Security\IpResolver;
use BCC\Trust\Core\Support\JwtToken;
use BCC\Trust\Infrastructure\EdgeCache;

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

    /**
     * Handles captured on `delete_user` (row + meta still present) for
     * the edge purge on `deleted_user` (row gone — nothing resolvable).
     *
     * @var array<int, string>
     */
    private array $pendingDeleteHandles = [];

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
        add_action('profile_update', [$this, 'onProfileUpdate'], 10, 2);
        add_action('delete_user', [$this, 'onUserDelete']);
        // Edge purge runs on `deleted_user` (AFTER the wp_users row is
        // gone) so a request racing the purge can't re-cache the member
        // list with the user still present.
        add_action('deleted_user', [$this, 'onUserDeleted']);

        // JWT revocation on credential change outside the BCC REST
        // endpoints (audit H-2 residual). WP core reset_password() fires
        // after_password_reset — this also covers PeepSo's own
        // reset-password shortcode, which calls reset_password(). The
        // wp-admin / PeepSo-profile password-CHANGE path is handled by the
        // hash diff in onProfileUpdate below.
        add_action('after_password_reset', [$this, 'onPasswordReset'], 10, 1);
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

    /**
     * WP `profile_update` — fires on every profile save. The second arg is
     * the pre-update WP_User snapshot (accepted_args=2).
     *
     * @param \WP_User|mixed $oldUserData pre-update user row, when supplied
     */
    public function onProfileUpdate(int $userId, mixed $oldUserData = null): void
    {
        $this->syncService->sync($userId);

        // Revoke bearer JWTs when the PASSWORD actually changed (audit H-2
        // residual). profile_update fires on every save, so diff the stored
        // hash — bumping the token version unconditionally would log the
        // user out of the frontend on any profile edit. This closes the
        // path where a password changes OUTSIDE the BCC REST endpoints
        // (wp-admin user edit, PeepSo profile shortcode), which those
        // endpoints' point-wise revokes don't cover.
        if ($oldUserData instanceof \WP_User) {
            $fresh = get_userdata($userId);
            if ($fresh instanceof \WP_User
                && is_string($fresh->user_pass)
                && $fresh->user_pass !== $oldUserData->user_pass
            ) {
                JwtToken::revokeAllForUser($userId);
            }
        }

        // Attempt to auto-complete the profile quest whenever the user
        // saves their profile. The quest signal is validated server-side
        // by QuestValidator::validateCompleteProfile() which checks
        // PeepSo avatar + display name + bio/social link — so firing
        // the signal on every save is safe (no-op if conditions aren't met).
        do_action('bcc_trust_quest_signal', $userId, 'complete_profile');
    }

    /**
     * WP `after_password_reset` — fires from core reset_password(), used by
     * WP's own reset flow AND PeepSo's reset-password shortcode. Revoke
     * every bearer JWT so a token stolen before the reset can't outlive it.
     * WP passes ($user, $new_pass); only the user is needed.
     *
     * @param \WP_User|mixed $user
     */
    public function onPasswordReset(mixed $user): void
    {
        if ($user instanceof \WP_User) {
            JwtToken::revokeAllForUser((int) $user->ID);
        }
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

        // Capture the public handle while usermeta still exists — the
        // deleted_user purge below needs it to target the profile URL.
        $handle = get_user_meta($userId, 'bcc_handle', true);
        if (!is_string($handle) || $handle === '') {
            $user   = get_userdata($userId);
            $handle = $user instanceof \WP_User ? $user->user_nicename : '';
        }
        $this->pendingDeleteHandles[$userId] = $handle;

        // Soft-delete votes
        $plugin->voteRepository()->softDeleteAllByVoter($userId);

        // Endorsement parity: the legacy endorsements table is retired.
        // The user's vouch/stand_behind footprint is removed by the
        // attestationRepository()->deleteForUser() call below (rows they
        // cast AND rows cast against their profile), which also stops the
        // attestation-backed endorsement_count denorm from counting them
        // on the next per-target recompute.

        // Hard-delete analytics data
        $plugin->userInfoRepository()->deleteByUserId($userId);
        // Architecture A: the member's trust now lives on their self-page
        // score row, not the legacy reputation snapshot — delete that instead.
        $plugin->scoreRepository()->deleteSelfPage(
            MemberSelfPageService::selfPageId($userId)
        );
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

        // Push subscriptions.
        (new \BCC\Trust\Core\Repositories\PushSubscriptionRepository())->deleteAllForUser($userId);

        // Trust attestations: rows the user cast AND rows cast against the
        // user's profile (target_kind='user_profile'). Snapshot the distinct
        // targets the user actively backed FIRST so their scores can be
        // re-synthesized below — with the endorsement_count denorm +
        // attestation_bonus both sourced from attestations, a deleted
        // user's vouches must stop counting immediately, not at the next
        // nightly decay sweep.
        $castTargets = [];
        foreach ($plugin->attestationRepository()->listActiveByAttestor($userId) as $row) {
            $castTargets[$row->target_kind . ':' . $row->target_id] = [$row->target_kind, $row->target_id];
        }

        $plugin->attestationRepository()->deleteForUser($userId);

        foreach ($castTargets as [$targetKind, $targetId]) {
            try {
                $plugin->attestationScoreSynthesis()->recomputeFor($targetKind, $targetId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] user-delete attestation recompute failed', [
                    'target_kind' => $targetKind,
                    'target_id'   => $targetId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

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
     * WP `deleted_user` — the wp_users row is gone. Purge the LiteSpeed
     * edge entries that still show the user: the anon member directory
     * (tag) and the user's own profile REST URL (best-effort — query
     * variants fall back to the 15s/ttl_rest bound). Without this, the
     * edge serves the deleted member until its configured TTL expires;
     * `wp cache flush` never reaches it.
     */
    public function onUserDeleted(int $userId): void
    {
        EdgeCache::purge(EdgeCache::TAG_MEMBERS);

        $handle = $this->pendingDeleteHandles[$userId] ?? '';
        unset($this->pendingDeleteHandles[$userId]);
        if ($handle !== '') {
            EdgeCache::purgeUrl(rest_url('bcc/v1/users/' . rawurlencode($handle)));
        }
    }

    /**
     * Clean up trust data when a PeepSo page (post) is permanently deleted.
     *
     * Only acts on 'peepso-page' post type. Soft-deletes votes
     * (preserving audit trail), hard-deletes scores, flags, score
     * events, read model, dirty queue entries, and the page-target
     * attestations (the endorse-shaped signal since the legacy
     * endorsements-table retirement).
     */
    public function onPageDelete(int $postId): void
    {
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'peepso-page') {
            return;
        }

        $plugin = Plugin::instance();

        // Soft-delete all votes on this page.
        PageReadModelRepository::softDeleteVotesForPage($postId);

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
        // The bcc_trust_last_login usermeta write retired here (Rank
        // Phase 1): RankLoginListener's login_days row is the canonical
        // login record, and DormancyDetector reads it. The stale meta
        // rows are drained by the cleanup_last_login_usermeta migration.
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
