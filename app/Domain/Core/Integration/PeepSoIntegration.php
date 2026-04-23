<?php

namespace BCC\Trust\Core\Integration;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VerificationRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\PeepSoPageResolver;
use BCC\Trust\Core\Support\Formatting;
use BCC\Trust\Core\ValueObjects\PageScore;
use BCC\Core\Contracts\TrustHeaderDataInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PeepSo Pages integration for the Trust Engine.
 *
 * Handles trust score display, page creation hooks, owner management,
 * and label swapping. Migrated from includes/frontend/peepso-integration.php.
 */
class PeepSoIntegration implements TrustHeaderDataInterface
{
    private ScoreRepository $scoreRepo;
    private UserInfoRepository $userInfoRepo;
    private VerificationRepository $verificationRepo;

    public function __construct(
        ScoreRepository        $scoreRepo,
        UserInfoRepository     $userInfoRepo,
        VerificationRepository $verificationRepo
    ) {
        $this->scoreRepo        = $scoreRepo;
        $this->userInfoRepo     = $userInfoRepo;
        $this->verificationRepo = $verificationRepo;
    }

    /**
     * Register all PeepSo hooks. Called from bootstrap.
     */
    public function register(): void
    {
        add_action('plugins_loaded', [$this, 'onPluginsLoaded']);
        add_action('peepso_page_after_create', [$this, 'onPageCreated'], 10, 2);
        add_action('peepso_page_after_delete', [$this, 'onPageDeleted']);
        add_action('save_post_peepso-page', [$this, 'onPageSaved'], 10, 3);
        add_filter('peepso_page_create_response', [$this, 'onPageCreateResponse']);

        // Label swap: Pages → Projects
        add_filter('gettext', [__CLASS__, 'labelSwap'], 999, 3);
        add_filter('gettext_with_context', function ($translated, $text, $context, $domain) {
            return self::labelSwap($translated, $text, $domain);
        }, 999, 4);
    }

    // ── Lifecycle hooks ─────────────────────────────────────────────────

    public function onPluginsLoaded(): void
    {
        if (!class_exists('PeepSo')) {
            return;
        }

        // ── User profile: Quest tab (next to About) ─────────────────────
        add_filter('peepso_navigation_profile', [$this, 'addQuestProfileTab']);
        add_action('peepso_profile_segment_quests', [$this, 'renderQuestProfileSegment']);

        // Position the tab right after "about" in the navigation order.
        add_filter('peepso_filter_navigation_profile_order', function (array $order): array {
            $pos = array_search('about', $order, true);
            if (is_int($pos)) {
                array_splice($order, $pos + 1, 0, 'quests');
            } else {
                $order[] = 'quests';
            }
            return $order;
        });

        if (!self::isPeepSoPagesActive()) {
            return;
        }

        // Trust widget on page profile
        add_action('peepso_page_profile_single_after_bio', function ($page) {
            if (isset($page->ID) && function_exists('bcc_trust_display_widget')) {
                bcc_trust_display_widget($page->ID, true);
            }
        });

        // Trust badge in page header
        add_action('peepso_page_profile_single_before_title', [$this, 'renderTrustBadge']);

        // Trust tab on page profile
        add_filter('peepso_page_profile_tabs', function ($tabs, $page_id) {
            $tabs['trust'] = ['href' => '#trust', 'label' => 'Trust', 'icon' => 'shield'];
            return $tabs;
        }, 10, 2);

        add_action('peepso_page_profile_tab_content', function ($tab, $page_id) {
            if ($tab === 'trust') {
                echo '<div class="peepso-tab-content">';
                echo do_shortcode('[bcc_trust page_id="' . (int) $page_id . '" show_actions="true"]');
                echo '</div>';
            }
        }, 10, 2);
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

    // ── Trust badge rendering ───────────────────────────────────────────

    /**
     * @param object $page PeepSo page object with ->ID property.
     */
    public function renderTrustBadge(object $page): void
    {
        if (!isset($page->ID)) {
            return;
        }

        $score = $this->scoreRepo->getByPageId((int) $page->ID);
        if (!$score) {
            return;
        }

        $tierInfo   = Formatting::tierInfo($score->getReputationTier());
        $ownerId    = $score->getPageOwnerId();
        $totalScore = $score->getTotalScore();
        $hasFraud   = $score->hasFraudAlerts();
        $isReliable = $score->getVoteCount() >= BCC_TRUST_MIN_VOTES_RELIABLE;

        $verification = $this->verificationRepo->getForUser($ownerId);
        $isVerified   = $verification && $verification->verified_at;

        ?>
        <div class="bcc-page-trust-badge" style="display: inline-block; margin-left: 10px; padding: 3px 8px; border-radius: 3px; background: <?php echo esc_attr($tierInfo['color']); ?>; color: #fff; font-size: 12px;">
            <span title="Trust Score: <?php echo number_format($totalScore, 1); ?>/100">
                <?php echo esc_html($tierInfo['icon']); ?> <?php echo number_format($totalScore, 1); ?>
            </span>
            <?php if ($hasFraud): ?>
                <span style="margin-left: 5px; background: rgba(255,255,255,0.3); padding: 2px 4px; border-radius: 2px;" title="Fraud alerts detected">⚠️</span>
            <?php endif; ?>
            <?php if ($isVerified): ?>
                <span style="margin-left: 5px; background: rgba(255,255,255,0.3); padding: 2px 4px; border-radius: 2px;" title="Verified user">✓</span>
            <?php endif; ?>
            <?php if (!$isReliable): ?>
                <span style="margin-left: 5px; background: rgba(255,255,255,0.3); padding: 2px 4px; border-radius: 2px;" title="Insufficient data for reliable scoring">?</span>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Trust header data (for templates) ──────────────────────────────

    /**
     * Prepare all data the trust-header-panel template needs.
     *
     * The template should only render this array — no service calls,
     * no DB queries, no business logic.
     */
    public function getTrustHeaderData(int $pageId, string $mode = 'public'): array
    {
        // ── Read model: pre-aggregated score + counts (cached 10 min) ───
        // Falls back to ScoreRepository if read model row is missing.
        $rm = Plugin::instance()->pageReadModelRepository()->getByPageId($pageId);

        if ($rm) {
            $total        = (int) round((float) $rm->trust_score);
            $confidence   = (int) round((float) $rm->confidence_score * 100);
            $endorsements = (int) $rm->endorsement_count;
            $uniqueVoters = (int) $rm->unique_voters;
            $ownerId      = (int) $rm->owner_id;
            $isVerified   = (bool) $rm->is_verified;
        } else {
            // Fallback: compute from source tables (same as before).
            $score        = $this->scoreRepo->getByPageId($pageId);
            $total        = $score ? (int) round($score->getTotalScore()) : 50;
            $confidence   = $score ? (int) round($score->getConfidenceScore() * 100) : 0;
            $endorsements = $score ? (int) $score->getEndorsementCount() : 0;
            $uniqueVoters = $score ? (int) $score->getUniqueVoters() : 0;
            $ownerId      = PeepSoPageResolver::getOwnerId($pageId);
            $isVerified   = false;
        }

        $grade = Formatting::scoreToGrade((float) $total);

        // Vote counts (up/down split not in read model — still needs repo).
        $voteRepo   = Plugin::instance()->voteRepository();
        $voteCounts = $voteRepo->getVoteCountsByType($pageId);
        $votesUp    = (int) ($voteCounts['upvotes'] ?? 0);
        $votesDown  = (int) ($voteCounts['downvotes'] ?? 0);

        // Viewer state (per-user, never in read model).
        $viewerId       = get_current_user_id();
        $viewerVote     = 0;
        $viewerEndorsed = false;

        if ($viewerId) {
            $vote       = $voteRepo->get($viewerId, $pageId);
            $viewerVote = $vote ? (int) $vote->vote_type : 0;

            $viewerEndorsed = Plugin::instance()->endorsementRepository()->hasEndorsed($viewerId, $pageId);
        }

        // Owner check — hide actions if viewer owns the page. No admin
        // exemption: self-vote and self-endorse are now blocked server-side
        // for everyone (see VoteEligibilityChecker::assertNotSelfVote and
        // EndorsementService::endorsePage), so the UI must match.
        $showActions = true;
        if ($ownerId && $viewerId === $ownerId) {
            $showActions = false;
        }

        // Verification + profile data.
        // Read model provides github/x/wallet status (synced on changes + daily).
        // Falls back to live queries only when read model is missing.
        if ($rm) {
            $ghUsername   = $rm->github_username ?? null;
            $ghFollowers  = (int) ($rm->github_followers ?? 0);
            $xUsername    = $rm->x_username ?? null;
            $hasWallet   = (bool) ($rm->has_wallet ?? false);
        } else {
            $ownerInfo  = $ownerId ? Plugin::instance()->userInfoRepository()->getByUserId($ownerId) : null;
            $isVerified = $ownerInfo ? (bool) $ownerInfo->is_verified : false;

            $verificationRepo = Plugin::instance()->verificationRepository();
            $ghData  = function_exists('bcc_trust_get_github_data')
                ? $verificationRepo->getGithubData($ownerId)
                : ['connected' => false];
            $xData   = function_exists('bcc_trust_get_x_data')
                ? $verificationRepo->getXData($ownerId)
                : ['connected' => false];
            $wallets = \BCC\Trust\Core\Repositories\WalletSignalRepository::getAllForUser($ownerId);

            $ghUsername  = $ghData['username'] ?? null;
            $ghFollowers = (int) ($ghData['followers'] ?? 0);
            $xUsername   = $xData['username'] ?? null;
            $hasWallet  = !empty($wallets);
        }

        $verification = [
            'email'  => $isVerified,
            'wallet' => $hasWallet,
            'github' => (bool) $ghUsername,
            'x'      => (bool) $xUsername,
        ];

        $profiles = [
            'github' => null,
            'x'      => null,
        ];
        if ($ghUsername) {
            $profiles['github'] = [
                'username'  => sanitize_text_field($ghUsername),
                'followers' => $ghFollowers,
                'repos'     => 0, // not in read model — acceptable for header display
            ];
        }
        if ($xUsername) {
            $profiles['x'] = [
                'username' => sanitize_text_field($xUsername),
            ];
        }

        // Page categories for context label
        $contextLabel   = 'Page';
        $pageCategories = $this->getPageCategories($pageId);
        if (count($pageCategories) === 1) {
            $catMap    = function_exists('bcc_get_category_map') ? bcc_get_category_map() : [];
            $singleCat = $pageCategories[0];
            if (isset($catMap[$singleCat]['label'])) {
                $contextLabel = rtrim($catMap[$singleCat]['label'], 's');
            }
        }

        // ── Viewer permissions (for tooltip + greyed-out states) ────────
        $viewerPerms = [
            'can_upvote'   => false,
            'can_downvote' => false,
            'can_endorse'  => false,
            'can_flag'     => true,   // any logged-in user
            'can_report'   => true,   // any logged-in user

            // Reasons why a permission is blocked (shown in tooltip)
            'upvote_reason'   => '',
            'downvote_reason' => '',
            'endorse_reason'  => '',
        ];

        if ($viewerId && $showActions) {
            $viewerInfo = Plugin::instance()->userInfoRepository()->getByUserId($viewerId);
            // Check trust-engine user_info flag first, fall back to WP/PeepSo
            $viewerIsVerified = ($viewerInfo && (bool) $viewerInfo->is_verified)
                || (bool) get_user_meta($viewerId, 'bcc_trust_email_verified', true)
                || (bool) get_user_meta($viewerId, 'peepso_is_verified_email', true)
                || ((int) (get_userdata($viewerId)->user_status ?? 1)) === 0;
            $viewerFraudScore = $viewerInfo ? (int) $viewerInfo->fraud_score : 0;
            $viewerReputation = Plugin::instance()->reputationRepository()->getByUserId($viewerId);
            $viewerTier = $viewerReputation->reputation_tier ?? 'neutral';

            // Upvote: email verified + not fraud-blocked
            if (!$viewerIsVerified) {
                $viewerPerms['upvote_reason'] = 'Verify your email to unlock voting.';
            } elseif ($viewerFraudScore >= BCC_TRUST_FRAUD_HIGH) {
                $viewerPerms['upvote_reason'] = 'Voting is temporarily restricted for your account.';
            } else {
                $viewerPerms['can_upvote'] = true;
            }

            // Downvote: all upvote requirements + trusted/elite tier + GitHub or wallet
            if (!$viewerPerms['can_upvote']) {
                $viewerPerms['downvote_reason'] = $viewerPerms['upvote_reason'];
            } elseif (!in_array($viewerTier, ['trusted', 'elite'], true)) {
                $viewerPerms['downvote_reason'] = 'Reach Trusted tier to unlock downvoting. Keep contributing quality votes and endorsements.';
            } else {
                $verificationService = new \BCC\Trust\Core\Application\WalletVerificationReadService();
                $hasGithub = $verificationService->hasVerification($viewerId, 'github');
                $hasWallet = $verificationService->hasVerifiedWallet($viewerId);
                if (!$hasGithub && !$hasWallet) {
                    $viewerPerms['downvote_reason'] = 'Connect GitHub or a wallet to unlock downvoting.';
                } else {
                    $viewerPerms['can_downvote'] = true;
                }
            }

            // Endorse: milestone-based — requires enough quests + identity verification
            $questService   = Plugin::instance()->questProgressService();
            $endorseProgress = $questService->getEndorseProgress($viewerId);
            if (!$endorseProgress['unlocked']) {
                $viewerPerms['endorse_reason'] = $endorseProgress['missing_reason'];
            } else {
                $viewerPerms['can_endorse'] = true;
            }
        } elseif (!$viewerId) {
            $viewerPerms['can_flag']   = false;
            $viewerPerms['can_report'] = false;
            $viewerPerms['upvote_reason']   = 'Log in to vote.';
            $viewerPerms['downvote_reason'] = 'Log in to vote.';
            $viewerPerms['endorse_reason']  = 'Log in to endorse.';
        }

        // ── System degradation flag ──────────────────────────────────
        // When a critical service is running on NullObject fallback, the
        // scores shown are defaults (50.00) — not real trust data. The
        // frontend should show a subtle "trust data degraded" banner and
        // disable voting/endorsing to prevent users from acting on
        // misinformation. This is a trust system, not a blog.
        $degraded = !$rm && !\BCC\Core\ServiceLocator::hasRealService(
            \BCC\Core\Contracts\ScoreReadServiceInterface::class
        );

        return [
            'page_id'          => $pageId,
            'mode'             => $mode,
            'total'            => $total,
            'confidence'       => $confidence,
            'endorsements'     => $endorsements,
            'grade'            => $grade,
            'votes_up'         => $votesUp,
            'votes_down'       => $votesDown,
            'unique_voters'    => $uniqueVoters,
            'verification'     => $verification,
            'profiles'         => $profiles,
            'viewer_vote'      => $viewerVote,
            'viewer_endorsed'  => $viewerEndorsed,
            'page_owner_id'    => $ownerId,
            'show_actions'     => $showActions && !$degraded,
            'show_interactive' => $showActions && !$degraded,
            'context_label'    => $contextLabel,
            'page_name'        => get_the_title($pageId) ?: 'this page',
            'logged_in'        => is_user_logged_in(),
            'viewer_perms'     => $viewerPerms,
            'system_degraded'  => $degraded,
        ];
    }

    /**
     * Get PeepSo category IDs for a page.
     *
     * Cross-plugin read integration: queries PeepSo's peepso_page_categories
     * table (not owned by trust-engine). Acceptable as a read-only query
     * against an external table for context label display.
     *
     * @return int[]
     */
    private function getPageCategories(int $pageId): array
    {
        $cats = \BCC\Trust\Core\Repositories\PeepSoQueryRepository::getPageCategories($pageId);
        return $cats ? array_map(fn($c) => (int) $c->term_id, $cats) : [];
    }

    // ── User profile: Quest tab ────────────────────────────────────────

    /**
     * Add the "Quests" tab to the PeepSo user profile navigation.
     *
     * @param array<string, array{href: string, label: string, icon: string}> $links
     * @return array<string, array{href: string, label: string, icon: string}>
     */
    public function addQuestProfileTab(array $links): array
    {
        $links['quests'] = [
            'href'  => 'quests',
            'label' => __('Quests', 'bcc-trust-engine'),
            'icon'  => 'gcis gci-trophy',
        ];
        return $links;
    }

    /**
     * Render the Quests profile segment content.
     *
     * Includes PeepSo's profile focus header (cover, avatar, name, nav tabs)
     * so the segment has the same layout as About, Blogposts, Followers, etc.
     */
    public function renderQuestProfileSegment(): void
    {
        $pro    = \PeepSoProfileShortcode::get_instance();
        $userId = \PeepSoUrlSegments::get_view_id($pro->get_view_user_id());

        if (!$userId) {
            return;
        }

        $questService = Plugin::instance()->questProgressService();
        $isOwnProfile = $userId === get_current_user_id();

        // When viewing your own quest tab, re-evaluate auto-completable quests.
        // This catches cases where the user completed the action (filled profile,
        // explored pages) but the signal didn't fire at the time.
        if ($isOwnProfile) {
            foreach (['complete_profile', 'explore_projects', 'verify_x', 'verify_github', 'connect_wallet', 'first_vote', 'share_x'] as $slug) {
                do_action('bcc_trust_quest_signal', $userId, $slug);
            }
        }

        $progress        = $questService->getProgress($userId);
        $endorseProgress = $questService->getEndorseProgress($userId);

        // Fetch verified account details for display on completed quest cards.
        // Single query each — already cached by the repository layer.
        $verifiedAccounts = [];
        if ($progress['quests']['verify_x']['done'] ?? false) {
            try {
                $xConn = (new \BCC\Trust\Core\Repositories\XRepository())->getConnection($userId);
                if (
                    $xConn !== null
                    && $xConn->x_username !== null
                    && $xConn->x_username !== ''
                    && strpos($xConn->x_username, 'user_') !== 0
                ) {
                    $verifiedAccounts['verify_x'] = [
                        'username' => $xConn->x_username,
                        'avatar'   => $xConn->x_avatar,
                    ];
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }
        if ($progress['quests']['verify_github']['done'] ?? false) {
            try {
                $ghData = Plugin::instance()->verificationRepository()->getGithubData($userId);
                if (!empty($ghData['username'])) {
                    $verifiedAccounts['verify_github'] = [
                        'username' => $ghData['username'],
                        'avatar'   => $ghData['avatar'] ?? null,
                    ];
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        // PeepSo profile wrapper + focus header (cover, avatar, name, nav)
        echo '<div class="peepso"><div class="ps-page ps-page--quests">';
        \PeepSoTemplate::exec_template('general', 'navbar');
        echo '<div class="ps-quests">';
        \PeepSoTemplate::exec_template('profile', 'focus', ['current' => 'quests']);

        $templatePath = BCC_TRUST_PATH . 'templates/profile-quests.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }

        echo '</div></div></div>';
    }

    // ── Label swap (Pages → Projects) ───────────────────────────────────

    public static function labelSwap(string $translated, string $text, string $domain): string
    {
        static $map = [
            'Pages'            => 'Projects',
            'Page'             => 'Project',
            'PeepSo Pages'     => 'PeepSo Projects',
            'Create Page'      => 'Create Project',
            'Edit Page'        => 'Edit Project',
            'Delete Page'      => 'Delete Project',
            'Page Settings'    => 'Project Settings',
            'Page Name'        => 'Project Name',
            'Page Description' => 'Project Description',
            'Page Categories'  => 'Project Categories',
        ];

        if ($domain !== 'peepso-core' && $domain !== 'peepso-block-theme' && $domain !== 'pageso' && $domain !== 'default') {
            return $translated;
        }

        return $map[$text] ?? $translated;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public static function isPeepSoPagesActive(): bool
    {
        return class_exists('PeepSoPage')
            || defined('PEEPSO_PAGES_PLUGIN_VERSION')
            || function_exists('PeepSoPage');
    }
}
