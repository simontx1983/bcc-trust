<?php
/**
 * GitHub Verification Service
 *
 * Orchestrates all GitHub operations: connect, disconnect, refresh, status.
 * Controllers delegate here; this class owns all domain logic.
 *
 * @package BCC_Trust_Engine
 * @subpackage GitHub
 */

namespace BCC\Trust\Core\Services\github;

use Exception;
use BCC\Trust\Core\DTO\GitHubConnectionDTO;
use BCC\Trust\Core\Repositories\GitHubRepository;
use BCC\Trust\Core\Security\AuditLogger;

if (!defined('ABSPATH')) {
    exit;
}

class GitHubVerificationService {

    private GitHubOAuthService $oauthService;
    private GitHubApiService   $apiService;
    private GitHubScoreService $scoreService;
    private GitHubRepository   $repository;

    public function __construct() {
        $this->oauthService = new GitHubOAuthService();
        $this->apiService   = new GitHubApiService();
        $this->scoreService = new GitHubScoreService();
        $this->repository   = new GitHubRepository();
    }

    /**
     * Return the GitHub OAuth authorisation URL.
     */
    public function getAuthUrl(): string {
        if (!get_current_user_id()) {
            throw new Exception('User must be logged in');
        }
        return $this->oauthService->getAuthUrl();
    }

    /**
     * Complete a GitHub OAuth connection for a user.
     *
     * Reads any previously-stored impact before overwriting it so the
     * page-score update is always idempotent (no double-counting).
     *
     * @param int    $userId WordPress user ID (resolved from validated state)
     * @param string $code   OAuth code from GitHub callback
     * @return array<string, mixed> username, trust_boost, fraud_reduction, user_id
     */
    public function connect(int $userId, string $code): array {
        if (!$userId) {
            throw new Exception('Invalid user ID');
        }
        if (!$code) {
            throw new Exception('Missing OAuth code');
        }
        if (!get_userdata($userId)) {
            throw new Exception('User not found');
        }

        // Capture old impact BEFORE anything is overwritten
        $old_conn   = $this->repository->getConnection($userId);
        $old_impact = $old_conn !== null
            ? $old_conn->github_trust_boost - (float) $old_conn->github_fraud_reduction
            : 0.0;

        // Exchange code for access token
        $accessToken = $this->oauthService->getAccessToken($code);
        if (!$accessToken) {
            throw new Exception('Failed to retrieve GitHub access token');
        }

        // Fetch profile
        $githubData = $this->apiService->getUserData($accessToken);
        if (empty($githubData['login'])) {
            throw new Exception('Invalid GitHub response');
        }

        // Persist connection (void — throws on failure)
        $this->repository->saveConnection($userId, $githubData, $accessToken);

        // Calculate and store trust scores
        $trustBoost     = $this->scoreService->calculateTrustBoost($githubData);
        $fraudReduction = $this->scoreService->calculateFraudReduction($githubData, $trustBoost);
        $this->repository->applyTrustBoost($userId, $trustBoost, $fraudReduction);

        // Idempotent page score update: remove old impact, apply new
        $this->updatePageScores($userId, $trustBoost, $fraudReduction, $old_impact);

        // Invalidate cached GitHub data so widgets reflect the new connection immediately.
        delete_transient( 'bcc_trust_github_' . $userId );

        AuditLogger::log('github_verified', $userId, [
            'username'        => $githubData['login'],
            'trust_boost'     => $trustBoost,
            'fraud_reduction' => $fraudReduction,
            'followers'       => $githubData['followers'] ?? 0,
            'repos'           => $githubData['public_repos'] ?? 0,
        ], 'user');

        // Signal quest completion: verify_github unlocks endorsements.
        do_action('bcc_trust_quest_signal', $userId, 'verify_github');

        return [
            'username'        => $githubData['login'],
            'trust_boost'     => $trustBoost,
            'fraud_reduction' => $fraudReduction,
            'user_id'         => $userId,
        ];
    }

    /**
     * Refresh GitHub data for an already-connected user.
     *
     * @param int $userId WordPress user ID
     * @return array<string, mixed> username, trust_boost, fraud_reduction
     */
    public function refresh(int $userId): array {
        $connection = $this->repository->getConnection($userId);

        if ($connection === null || $connection->github_username === null || $connection->github_username === '') {
            throw new Exception('GitHub not connected');
        }

        $accessToken = $connection->github_access_token_decrypted;
        if ($accessToken === null || $accessToken === '') {
            throw new Exception('GitHub token missing');
        }

        // Capture old impact BEFORE overwriting
        $old_impact = $connection->github_trust_boost - (float) $connection->github_fraud_reduction;

        // Fetch fresh data, persist, recalculate
        $githubData = $this->apiService->getUserData($accessToken);
        $this->repository->saveConnection($userId, $githubData, $accessToken);

        $trustBoost     = $this->scoreService->calculateTrustBoost($githubData);
        $fraudReduction = $this->scoreService->calculateFraudReduction($githubData, $trustBoost);
        $this->repository->applyTrustBoost($userId, $trustBoost, $fraudReduction);

        $this->updatePageScores($userId, $trustBoost, $fraudReduction, $old_impact);

        // Invalidate cached GitHub data so widgets reflect the refreshed stats.
        delete_transient( 'bcc_trust_github_' . $userId );

        return [
            'username'        => $githubData['login'],
            'trust_boost'     => $trustBoost,
            'fraud_reduction' => $fraudReduction,
        ];
    }

    /**
     * Disconnect GitHub for a user and remove its score impact from their pages.
     *
     * @param int $userId WordPress user ID
     * @return array<string, mixed> username
     */
    public function disconnect(int $userId): array {
        if (!$userId) {
            throw new Exception('Invalid user ID');
        }

        $connection          = $this->repository->getConnection($userId);
        $username            = ($connection !== null && $connection->github_username !== null && $connection->github_username !== '')
            ? $connection->github_username
            : 'unknown';
        $old_trust_boost     = $connection !== null ? $connection->github_trust_boost     : 0.0;
        $old_fraud_reduction = $connection !== null ? $connection->github_fraud_reduction : 0;

        $this->repository->disconnect($userId);

        // new_impact = 0, so page scores drop by the previously applied amount
        $this->updatePageScores($userId, 0, 0, $old_trust_boost - $old_fraud_reduction);

        // Invalidate cached GitHub data so widgets stop showing the old connection.
        delete_transient( 'bcc_trust_github_' . $userId );

        AuditLogger::log('github_disconnected', $userId, [
            'username' => $username,
        ], 'user');

        // Revoke verify_github quest — re-locks endorsements until re-verified.
        do_action('bcc_trust_quest_signal_revoke', $userId, 'verify_github');

        return ['username' => $username];
    }

    /**
     * Return the hydrated connection DTO for a user, or null if not connected.
     *
     * @param int $userId WordPress user ID
     */
    public function getStatus(int $userId): ?GitHubConnectionDTO {
        return $this->repository->getConnection($userId);
    }

    /*
    |--------------------------------------------------------------------------
    | PRIVATE HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Flag all pages owned by this user for deferred score recalculation.
     *
     * Instead of looping through N pages with individual UPDATE queries
     * (O(N) queries), this issues a single UPDATE that sets the
     * recalculate_required flag. The bcc_trust_process_recalculations cron
     * (every 5 minutes) picks up flagged pages and recalculates scores in
     * batch, collapsing multiple change events into a single recalculation.
     *
     * At 200+ pages per user this reduces query count from N+1 to 1.
     */
    private function updatePageScores(
        int   $userId,
        float $trustBoost,
        int   $fraudReduction,
        float $old_impact = 0.0
    ): void {
        \BCC\Trust\Core\Plugin::instance()->scoreRepository()->flagOwnerPagesForRecalculation($userId);
    }
}
