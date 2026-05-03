<?php

namespace BCC\Trust\Core\Integration;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\PeepSoPageResolver;
use BCC\Trust\Core\Support\Formatting;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PeepSo Pages integration for the Trust Engine.
 *
 * Data-sync only: page creation/deletion/save/owner-change hooks and
 * email-verification mirroring. UI rendering moved to Next.js.
 */
class PeepSoIntegration
{
    private ScoreRepository $scoreRepo;
    private UserInfoRepository $userInfoRepo;

    public function __construct(
        ScoreRepository    $scoreRepo,
        UserInfoRepository $userInfoRepo
    ) {
        $this->scoreRepo    = $scoreRepo;
        $this->userInfoRepo = $userInfoRepo;
    }

    /**
     * Register all PeepSo hooks. Called from bootstrap.
     */
    public function register(): void
    {
        add_action('peepso_page_after_create', [$this, 'onPageCreated'], 10, 2);
        add_action('peepso_page_after_delete', [$this, 'onPageDeleted']);
        add_action('save_post_peepso-page', [$this, 'onPageSaved'], 10, 3);
        add_filter('peepso_page_create_response', [$this, 'onPageCreateResponse']);

        // Email verification is delegated to PeepSo. When a user completes
        // activation via their shortcode flow, PeepSo fires this action with
        // a PeepSoUser instance; we mirror the verified state into
        // bcc_trust_user_info.is_verified so downstream trust logic
        // (VoteEligibilityChecker, FraudDetector) sees it.
        add_action('peepso_register_verified', [$this, 'onEmailVerified'], 10, 1);
    }

    // ── Lifecycle hooks ─────────────────────────────────────────────────

    /**
     * PeepSo fires `peepso_register_verified` after a successful activation
     * (peepso/classes/registershortcode.php::activate_account). Mirror the
     * verified state into user_info so the trust engine's own checks pass,
     * then fan out to downstream listeners.
     *
     * @param mixed $wpuser PeepSoUser instance with ID/get_user_id().
     */
    public function onEmailVerified($wpuser): void
    {
        $userId = 0;
        if (is_object($wpuser)) {
            if (method_exists($wpuser, 'get_user_id')) {
                $userId = (int) $wpuser->get_user_id();
            } elseif (isset($wpuser->ID)) {
                $userId = (int) $wpuser->ID;
            }
        }

        if ($userId <= 0) {
            return;
        }

        try {
            Plugin::instance()->userInfoRepository()->updateVerificationStatus($userId, true);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] onEmailVerified: updateVerificationStatus failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return;
        }

        AuditLogger::verificationComplete($userId);

        // Fire the read-model sync action (listened to by PageReadModelSync::onOwnerDataChanged)
        // so the owner's pages pick up the new is_verified flag without waiting for the
        // daily full sync. Same action XVerificationService/GitHubVerificationService use.
        do_action('bcc_trust_verification_changed', $userId);

        // Domain extensibility point (kept for third-party listeners).
        do_action('bcc_trust_user_verified', $userId);
    }

    public function onPageCreated(int $pageId, int $userId): void
    {
        $this->scoreRepo->createDefault($pageId, $userId);
        $this->userInfoRepo->incrementPageCount($userId, $pageId);

        // Eagerly sync the read model so brand-new pages are immediately
        // visible in /discover and have trust data in /search — rather than
        // waiting for the first vote or the daily full sync.
        Plugin::instance()->pageReadModelRepository()->syncPage($pageId);

        AuditLogger::log('page_created', $pageId, [
            'user_id' => $userId,
            'action'  => 'create',
        ], 'page');
    }

    public function onPageDeleted(int $pageId): void
    {
        $ownerId = $this->scoreRepo->getPageOwnerId($pageId);

        $this->scoreRepo->delete($pageId);

        if ($ownerId) {
            $this->userInfoRepo->decrementPageCount($ownerId, $pageId);
        }
    }

    public function onPageSaved(int $postId, \WP_Post $post, bool $update): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }
        if ($post->post_type !== 'peepso-page') {
            return;
        }

        if (!$update) {
            $this->onPageCreated($postId, (int) $post->post_author);
            return;
        }

        $old_owner = get_post_meta($postId, '_peepso_page_owner_id', true);
        $new_owner = (int) $post->post_author;
        if ($old_owner && (int) $old_owner !== $new_owner) {
            $this->handleOwnerChange($postId, $new_owner);
        }
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public function onPageCreateResponse(array $response): array
    {
        if (!empty($response['success']) && !empty($response['id'])) {
            $this->onPageCreated((int) $response['id'], get_current_user_id());
            $response['trust_score'] = (float) BCC_TRUST_NEUTRAL_SCORE;
            $response['trust_tier']  = 'neutral';
        }
        return $response;
    }

    // ── Owner change ────────────────────────────────────────────────────

    private function handleOwnerChange(int $pageId, int $newOwnerId): void
    {
        $oldOwner = $this->scoreRepo->getPageOwnerId($pageId);

        if ($oldOwner && $oldOwner !== $newOwnerId) {
            $this->scoreRepo->updateOwner($pageId, $newOwnerId);

            $this->userInfoRepo->transferPageOwnership($oldOwner, $newOwnerId, $pageId);

            AuditLogger::log('page_owner_changed', $pageId, [
                'old_owner' => $oldOwner,
                'new_owner' => $newOwnerId,
            ], 'page');
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public static function isPeepSoPagesActive(): bool
    {
        return class_exists('PeepSoPage')
            || defined('PEEPSO_PAGES_PLUGIN_VERSION')
            || function_exists('PeepSoPage');
    }
}
