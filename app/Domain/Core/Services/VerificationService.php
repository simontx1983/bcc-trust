<?php
/**
 * Verification Service
 * 
 * Handles email verification requests and processing
 * Uses config constants for expiry times and rate limits
 * 
 * @package BCC\Trust\Core\Services
 * @version 2.0.0
 */

namespace BCC\Trust\Core\Services;

use Exception;
use BCC\Trust\Core\Repositories\VerificationRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Security\RateLimiter;
use BCC\Trust\Core\Security\AuditLogger;

if (!defined('ABSPATH')) {
    exit;
}

class VerificationService {

    private VerificationRepository $repo;
    private UserInfoRepository $userInfoRepo;
    
    /**
     * Config constants
     */
    private int $tokenExpiryHours;
    private int $rateLimitMax;
    private int $rateLimitWindow;

    public function __construct() {
        $this->repo = new VerificationRepository();
        $this->userInfoRepo = new UserInfoRepository();
        
        $this->tokenExpiryHours      = BCC_TRUST_VERIFICATION_EXPIRY_HOURS;
        $this->rateLimitMax          = BCC_TRUST_RATE_LIMIT_VERIFY;
        $this->rateLimitWindow       = BCC_TRUST_RATE_WINDOW_VERIFY;
    }

    /**
     * Request verification email
     *
     * @return array<string, mixed>
     */
    public function requestVerification(): array {
        if (!is_user_logged_in()) {
            throw new Exception('Authentication required');
        }

        $userId = get_current_user_id();

        // Check if already verified using user_info table
        if ($this->isVerified($userId)) {
            throw new Exception('Email already verified');
        }

        // Check rate limit using config
        if (!$this->repo->canRequestVerification($userId)) {
            $timeUntilNext = $this->repo->getTimeUntilNextAllowed($userId);
            throw new Exception(sprintf(
                'Too many verification attempts. Please wait %d minutes.',
                ceil($timeUntilNext / 60)
            ));
        }

        // Enforce rate limit
        RateLimiter::enforce('verify', $this->rateLimitMax, $this->rateLimitWindow);

        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        // Store in database (repository handles expiry)
        $created = $this->repo->create($userId, $tokenHash);
        
        if (!$created) {
            throw new Exception('Failed to create verification token');
        }

        // Send email
        $this->sendVerificationEmail($userId, $token);

        // Log the request
        AuditLogger::verificationRequest($userId);

        return [
            'success' => true,
            'message' => 'Verification email sent',
            'expires_in' => $this->tokenExpiryHours * 3600,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($this->tokenExpiryHours * 3600))
        ];
    }

    /**
     * Verify email with token
     *
     * @return array<string, mixed>
     */
    public function verifyEmail(int $userId, string $token): array {
        $tokenHash = hash('sha256', $token);

        // Wrap the entire verify flow in a transaction with FOR UPDATE
        // to prevent token replay attacks. Without this, two concurrent
        // requests with the same token can both SELECT the unverified
        // row, pass the check, and both proceed to markVerified().
        // FOR UPDATE locks the row so the second request blocks until
        // the first commits, at which point verified_at is no longer NULL.
        \BCC\Trust\Core\Security\TransactionManager::run(function () use ($userId, $tokenHash) {
            $record = $this->repo->getValidForUpdate($userId, $tokenHash);

            if (!$record) {
                throw new Exception('Invalid or expired verification token');
            }

            // Mark as verified (this will also update user_info via the repository)
            $this->repo->markVerified((int) $record->id);

            // Clear any existing tokens
            $this->cleanupUserTokens($userId);

            return $record;
        });

        // Fire non-transactional side effects AFTER commit so they
        // cannot cause a rollback of the verification itself.

        // Log verification
        AuditLogger::verificationComplete($userId);

        // Trigger action for other plugins
        do_action('bcc_trust_user_verified', $userId);

        // Get updated user info for response
        $userInfo = $this->userInfoRepo->getByUserId($userId);

        // Calculate trust impact
        $trustBoost = $userInfo ? $this->calculateTrustBoost($userInfo) : 0;

        return [
            'success' => true,
            'message' => 'Email verified successfully',
            'verified_at' => current_time('mysql'),
            'user_id' => $userId,
            'trust_boost' => $trustBoost,
            'reputation_tier' => $userInfo ? ($userInfo->reputation_tier ?? 'neutral') : 'neutral'
        ];
    }

    /**
     * Calculate trust boost from verification.
     *
     * Accepts the user_info row. `github_verified_at` is a legacy derived
     * field that callers may enrich dynamically; treat it as optional.
     *
     * @phpstan-param object{
     *   automation_score: int|numeric-string,
     *   github_verified_at?: string|null
     * } $userInfo
     */
    private function calculateTrustBoost(object $userInfo): float {
        $boost = 0.1; // Base boost from config

        // Additional boost based on other factors
        if (!empty($userInfo->github_verified_at)) {
            $boost += 0.2;
        }

        if ((int) $userInfo->automation_score < 20) {
            $boost += 0.1;
        }

        return min(0.5, $boost); // Cap at 0.5
    }

    /**
     * Check if user is verified using user_info table
     */
    public function isVerified(int $userId): bool {
        // Get from user_info table (source of truth)
        $userInfo = $this->userInfoRepo->getByUserId($userId);
        
        if ($userInfo) {
            return (bool) $userInfo->is_verified;
        }

        // Fallback to repository check if user_info not found
        return $this->repo->isVerified($userId);
    }

    /**
     * Send verification email
     */
    private function sendVerificationEmail(int $userId, string $token): void {
        $user = get_userdata($userId);

        if (!$user) {
            throw new Exception('User not found');
        }

        // Build verification URL
        $verifyUrl = $this->getVerificationUrl($userId, $token);

        // Email subject
        $subject = sprintf(
            __('[%s] Verify Your Email Address', 'bcc-trust'),
            get_bloginfo('name')
        );

        // Email message with HTML support
        $message = $this->buildEmailHtml($user->display_name, $verifyUrl);

        // Headers for HTML email
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        // Send email
        $sent = wp_mail($user->user_email, $subject, $message, $headers);

        if (!$sent) {
            throw new Exception('Failed to send verification email');
        }
    }

    /**
     * Build HTML email template
     */
    private function buildEmailHtml(string $displayName, string $verifyUrl): string {
        $siteName = get_bloginfo('name');
        $logo = get_theme_mod('custom_logo') ? wp_get_attachment_image_url(get_theme_mod('custom_logo'), 'full') : '';
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 8px; }
                .header { text-align: center; padding: 20px 0; border-bottom: 1px solid #eee; }
                .content { padding: 20px; }
                .button { display: inline-block; padding: 12px 24px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 4px; margin: 20px 0; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666; text-align: center; }
                .warning { background: #fff8e5; border-left: 4px solid #ffb900; padding: 12px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <?php if ($logo): ?>
                        <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($siteName); ?>" style="max-height: 60px;">
                    <?php else: ?>
                        <h2><?php echo esc_html($siteName); ?></h2>
                    <?php endif; ?>
                </div>
                
                <div class="content">
                    <h3>Hello <?php echo esc_html($displayName); ?>,</h3>
                    
                    <p>Please verify your email address by clicking the button below:</p>
                    
                    <p style="text-align: center;">
                        <a href="<?php echo esc_url($verifyUrl); ?>" class="button">Verify Email Address</a>
                    </p>
                    
                    <div class="warning">
                        <strong>⏰ This link will expire in <?php echo $this->tokenExpiryHours; ?> hour(s).</strong>
                    </div>
                    
                    <p>If the button doesn't work, copy and paste this link into your browser:</p>
                    <p style="word-break: break-all; background: #f5f5f5; padding: 10px; border-radius: 4px;">
                        <?php echo esc_url($verifyUrl); ?>
                    </p>
                    
                    <p>If you didn't request this verification, please ignore this email.</p>
                </div>
                
                <div class="footer">
                    <p>&copy; <?php echo date('Y'); ?> <?php echo esc_html($siteName); ?>. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Clean up old tokens for user
     */
    private function cleanupUserTokens(int $userId): void {
        \BCC\Trust\Core\Plugin::instance()->verificationRepository()->deleteUnverifiedForUser($userId);
    }

    /**
     * Get verification URL for manual sending
     */
    public function getVerificationUrl(int $userId, string $token): string {
        return add_query_arg([
            'bcc_action' => 'verify',
            'token' => $token,
            'user_id' => $userId
        ], home_url('/verify-email'));
    }

    /**
     * Admin: Manually verify a user
     */
    public function adminVerifyUser(int $userId, int $adminId): bool {
        if (!user_can($adminId, 'manage_options')) {
            throw new Exception('Unauthorized');
        }

        // Check if already verified
        if ($this->isVerified($userId)) {
            return false;
        }

        // Update user_info directly (void — throws on failure)
        $this->userInfoRepo->updateVerificationStatus($userId, true);

        AuditLogger::log('admin_verified_user', $userId, [
            'admin_id' => $adminId
        ], 'user');

        return true;
    }

    /**
     * Get users with pending verification
     *
     * @return object[]
     */
    public function getPendingVerifications(int $limit = 100): array {
        return $this->repo->getUnverifiedUsers($limit);
    }

    /**
     * Get verification history for user
     *
     * @return object[]
     */
    public function getHistory(int $userId, int $limit = 10): array {
        return $this->repo->getHistory($userId, $limit);
    }

}