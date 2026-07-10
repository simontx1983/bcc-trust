<?php
/**
 * Quest Validator
 *
 * Server-side validation for quest completion. Each quest slug can
 * have a validator callback that checks whether the user ACTUALLY
 * completed the required action. If no validator is registered for
 * a slug, the quest can only be completed by trusted internal hooks
 * (connect_wallet, verify_github, first_vote) that fire from
 * verified server-side actions.
 *
 * This prevents rogue plugins from awarding quests via
 *   do_action('bcc_trust_quest_signal', $userId, 'complete_profile');
 * without the user actually completing their profile.
 *
 * @package BCC\Trust\Core\Services\Quest
 */

namespace BCC\Trust\Core\Services\Quest;

use BCC\Trust\Core\Repositories\PeepSoQueryRepository;

if (!defined('ABSPATH')) {
    exit;
}

class QuestValidator {

    /**
     * Quests that require server-side validation before completion.
     *
     * ALL quests that affect trust scores or unlock capabilities must be
     * validated here. The do_action('bcc_trust_quest_signal') hook is
     * public and can be fired by any installed plugin — we cannot rely
     * on caller context for security.
     */
    private const VALIDATED_QUESTS = [
        'complete_profile',
        'explore_projects',
        'verify_github',
        'verify_x',
        'share_x',
        'connect_wallet',
        'first_vote',
    ];

    /**
     * Check if a quest requires server-side validation.
     */
    public static function requiresValidation(string $slug): bool {
        return in_array($slug, self::VALIDATED_QUESTS, true);
    }

    /**
     * Validate that a user has genuinely completed a quest.
     *
     * @return bool True if the quest conditions are met.
     */
    public static function validate(int $userId, string $slug): bool {
        return match ($slug) {
            'complete_profile'  => self::validateCompleteProfile($userId),
            'explore_projects'  => self::validateExploreProjects($userId),
            'verify_github'     => self::validateGitHubVerified($userId),
            'verify_x'          => self::validateXVerified($userId),
            'share_x'           => self::validateShareX($userId),
            'connect_wallet'    => self::validateWalletConnected($userId),
            'first_vote'        => self::validateFirstVote($userId),
            default             => false, // Unknown quests are denied, not allowed
        };
    }

    /**
     * complete_profile: pass ONLY on a meaningful profile signal.
     *
     * Previous logic accepted a non-empty WP `description` or `user_url`
     * field as proof of a "complete profile". Both are user-controlled free
     * text with no verification — a sockpuppet needed 1 char in the WP
     * profile bio to satisfy the identity-unlock gate for endorsements.
     *
     * The hardened gate requires ONE of:
     *   - PeepSo completeness >= COMPLETE_PROFILE_THRESHOLD (80%)
     *   - PeepSo filled_all >= COMPLETE_PROFILE_MIN_FIELDS (4) for sites
     *     where PeepSo doesn't expose a percentage
     *   - A verified external identity already persisted in bcc-trust
     *     (github, x, or a verified wallet) — these represent proof of
     *     work, not self-attested text.
     *
     * Standard WP `description` / `user_url` fallbacks are REMOVED. They
     * were the cheapest Sybil path on the platform.
     */
    private const COMPLETE_PROFILE_THRESHOLD   = 80;
    private const COMPLETE_PROFILE_MIN_FIELDS  = 4;

    private static function validateCompleteProfile(int $userId): bool {
        if (!get_userdata($userId)) {
            return false;
        }

        // ── PeepSo path: require a meaningful completeness bar ──────────
        if (class_exists('PeepSoUser') && class_exists('PeepSoProfileFields')) {
            try {
                $peepsoUser = \PeepSoUser::get_instance($userId);
                $fields     = $peepsoUser->profile_fields;
                $fields->load_fields();
                $stats = $fields->profile_fields_stats ?? [];

                $completeness = (int) ($stats['completeness'] ?? 0);
                $filledAll    = (int) ($stats['filled_all'] ?? 0);

                if ($completeness >= self::COMPLETE_PROFILE_THRESHOLD
                    || $filledAll >= self::COMPLETE_PROFILE_MIN_FIELDS
                ) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Fall through to identity check
            }
        }

        // ── Fallback: scan user meta directly ───────────────────────────
        // A reasonable number of PeepSo profile fields is also acceptable
        // when the site doesn't expose the completeness percentage.
        $filled = PeepSoQueryRepository::countFilledProfileFields($userId);
        if ($filled >= self::COMPLETE_PROFILE_MIN_FIELDS) {
            return true;
        }

        // ── Verified external identity overrides the profile bar ────────
        // Having proven a GitHub / X / wallet identity is a stronger
        // signal than any profile-field count and is already gated by
        // its own validator above. We reuse that work instead of
        // duplicating the checks.
        if (self::validateGitHubVerified($userId)
            || self::validateXVerified($userId)
            || self::validateWalletConnected($userId)
        ) {
            return true;
        }

        return false;
    }

    /**
     * explore_projects: User must have viewed at least 3 distinct
     * PeepSo project pages. Tracked via user meta.
     *
     * The tracking is done by bcc-trust's front-end page-view hook,
     * which records distinct page IDs in user meta. The quest signal
     * should only fire after the counter reaches 3.
     */
    private static function validateExploreProjects(int $userId): bool {
        $visited = get_user_meta($userId, '_bcc_explored_pages', true);

        if (!is_array($visited)) {
            return false;
        }

        // Require at least 3 distinct project page visits.
        return count($visited) >= 3;
    }

    /**
     * verify_github: Check that the user has a verified GitHub record
     * in the bcc_trust_user_verifications table (written by GitHubVerificationService).
     */
    private static function validateGitHubVerified(int $userId): bool {
        if (!class_exists('\\BCC\\Trust\\Core\\Plugin')) {
            return false;
        }
        try {
            $repo = \BCC\Trust\Core\Plugin::instance()->verificationRepository();
            return $repo->hasVerification($userId, 'github');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * verify_x: Check that the user has a verified X (Twitter) record
     * in the bcc_trust_user_verifications table (written by XVerificationService).
     */
    private static function validateXVerified(int $userId): bool {
        if (!class_exists('\\BCC\\Trust\\Core\\Repositories\\XRepository')) {
            return false;
        }
        try {
            $repo = new \BCC\Trust\Core\Repositories\XRepository();
            $conn = $repo->getConnection($userId);
            return $conn && !empty($conn->x_username);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * share_x: Verify via X API that the user tweeted their site URL.
     *
     * Requires the user to have a verified X connection (access token).
     * Searches the last 20 tweets (7-day window) for the site domain.
     * Falls back to user meta flag if API call fails.
     */
    private static function validateShareX(int $userId): bool {
        // Already completed via meta flag (previous successful verification)
        if (get_user_meta($userId, '_bcc_quest_shared_x', true)) {
            return true;
        }

        if (!class_exists('\\BCC\\Trust\\Core\\Repositories\\XRepository')) {
            return false;
        }

        try {
            $repo = new \BCC\Trust\Core\Repositories\XRepository();
            $conn = $repo->getConnection($userId);

            if (!$conn || empty($conn->x_id)) {
                return false;
            }

            // The stored access token may have expired (~2h) since the user
            // connected X; getValidAccessToken refreshes it transparently via
            // the stored refresh token so this quest check doesn't 401.
            $accessToken = (new \BCC\Trust\Core\Services\x\XVerificationService())
                ->getValidAccessToken($userId);
            if ($accessToken === null || $accessToken === '') {
                return false;
            }

            $siteHost = parse_url(home_url(), PHP_URL_HOST) ?: home_url();
            $api      = new \BCC\Trust\Core\Services\x\XApiService();

            $found = $api->hasRecentTweetContaining(
                $accessToken,
                $conn->x_id,
                $siteHost
            );

            if ($found) {
                // Persist so we don't re-check the API on every tab visit
                update_user_meta($userId, '_bcc_quest_shared_x', 1);
            }

            return $found;
        } catch (\Throwable $e) {
            error_log('[BCC X] share_x validation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * connect_wallet: Check that the user has at least one verified
     * wallet link (written by WalletIdentityService in bcc-core).
     */
    private static function validateWalletConnected(int $userId): bool {
        // Fail-loud: Onchain is a hard in-plugin dependency. A missing class here means
        // no user can complete the connect_wallet quest — surface as a crash, not a silent false.
        if (!class_exists(\BCC\Trust\Onchain\Repositories\WalletRepository::class)) {
            throw new \RuntimeException('Onchain domain classes not autoloaded');
        }
        $wallets = \BCC\Trust\Onchain\Repositories\WalletRepository::getForUser($userId, null, true);
        return !empty($wallets);
    }

    /**
     * first_vote: Check that the user has cast at least one vote in
     * the bcc_trust_votes table.
     */
    private static function validateFirstVote(int $userId): bool {
        if (!class_exists('\\BCC\\Trust\\Core\\Plugin')) {
            return false;
        }
        try {
            $repo = \BCC\Trust\Core\Plugin::instance()->voteRepository();
            return $repo->countByVoter($userId) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
