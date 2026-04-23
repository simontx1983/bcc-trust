<?php
/**
 * X (Twitter) Verification Service
 *
 * Orchestrates all X operations: connect, disconnect, status.
 * Controllers delegate here; this class owns all domain logic.
 *
 * @package BCC_Trust_Engine
 * @subpackage X
 */

namespace BCC\Trust\Core\Services\x;

use Exception;
use BCC\Trust\Core\DTO\XConnectionDTO;
use BCC\Trust\Core\Repositories\XRepository;
use BCC\Trust\Core\Security\AuditLogger;

if (!defined('ABSPATH')) {
    exit;
}

class XVerificationService {

    private XOAuthService $oauthService;
    private XApiService   $apiService;
    private XScoreService $scoreService;
    private XRepository   $repository;

    public function __construct() {
        $this->oauthService = new XOAuthService();
        $this->apiService   = new XApiService();
        $this->scoreService = new XScoreService();
        $this->repository   = new XRepository();
    }

    /**
     * Return the X OAuth authorisation URL.
     */
    public function getAuthUrl(): string {
        if (!get_current_user_id()) {
            throw new Exception('User must be logged in');
        }
        return $this->oauthService->getAuthUrl();
    }

    /**
     * Complete an X OAuth connection for a user.
     *
     * Reads any previously-stored impact before overwriting it so the
     * page-score update is always idempotent (no double-counting).
     *
     * @param int    $userId WordPress user ID (resolved from validated state)
     * @param string $code   OAuth code from X callback
     * @return array<string, mixed> username, email, trust_boost, fraud_reduction, user_id
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
            ? $old_conn->x_trust_boost - (float) $old_conn->x_fraud_reduction
            : 0.0;

        // Exchange code for access token (PKCE flow needs userId for verifier).
        // Returns full token response — may include id_token when openid scope is granted.
        $tokenResponse = $this->oauthService->getAccessToken($code, $userId);

        $accessToken = $tokenResponse['access_token'] ?? '';

        if (!$accessToken) {
            throw new Exception('Failed to retrieve X access token');
        }

        // ── Extract user info (4-tier fallback) ──────────────────────────
        $xData = null;

        // Tier 1: OIDC id_token (free — no API call, decoded from JWT)
        if (!empty($tokenResponse['id_token'])) {
            $parts = explode('.', $tokenResponse['id_token']);
            if (count($parts) === 3) {
                $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                if (!empty($claims['sub'])) {
                    $xData = [
                        'id'                => $claims['sub'],
                        'username'          => $claims['preferred_username'] ?? $claims['username'] ?? '',
                        'name'              => $claims['name'] ?? '',
                        'profile_image_url' => $claims['picture'] ?? '',
                        'email'             => $claims['email'] ?? null,
                    ];
                }
            }
        }

        // Tier 2: v2 API (requires paid tier)
        if (empty($xData['username'])) {
            try {
                $apiResult = $this->apiService->getUserData($accessToken);
                if (!empty($apiResult['username'])) {
                    $xData = $apiResult;
                    \BCC\Core\Log\Logger::info('x_verification_tier_succeeded', [
                        'tier'     => 'v2',
                        'user_id'  => $userId,
                        'username' => $apiResult['username'],
                    ]);
                } else {
                    \BCC\Core\Log\Logger::warning('x_verification_tier_empty_response', [
                        'tier'    => 'v2',
                        'user_id' => $userId,
                    ]);
                }
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::warning('x_verification_tier_failed', [
                    'tier'      => 'v2',
                    'user_id'   => $userId,
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                ]);
            }
        }

        // Tier 3: v1.1 API (may work on legacy apps)
        if (empty($xData['username'])) {
            try {
                $v1Data = $this->apiService->getUserDataV1Fallback($accessToken);
                if ($v1Data && !empty($v1Data['username'])) {
                    $xData = $v1Data;
                    \BCC\Core\Log\Logger::info('x_verification_tier_succeeded', [
                        'tier'     => 'v1.1',
                        'user_id'  => $userId,
                        'username' => $v1Data['username'],
                    ]);
                }
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::warning('x_verification_tier_failed', [
                    'tier'      => 'v1.1',
                    'user_id'   => $userId,
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                ]);
            }
        }

        // Tier 4: Placeholder — OAuth proves identity regardless
        if (empty($xData['username'])) {
            \BCC\Core\Log\Logger::error('x_verification_all_tiers_failed', [
                'user_id' => $userId,
                'note'    => 'OAuth succeeded but no tier returned a username; falling back to placeholder identity',
            ]);
            $xData = $xData ?? [];
            $xData['id']       = $xData['id'] ?? ('x_' . $userId);
            $xData['username'] = $xData['username'] ?? ('user_' . $userId);
            $xData['name']     = $xData['name'] ?? '';
        }

        // Persist connection (void — throws on failure)
        $this->repository->saveConnection($userId, $xData, $accessToken);

        // Calculate and store trust scores
        $trustBoost     = $this->scoreService->calculateTrustBoost($xData);
        $fraudReduction = $this->scoreService->calculateFraudReduction($xData, $trustBoost);
        $this->repository->applyTrustBoost($userId, $trustBoost, $fraudReduction);

        // Idempotent page score update: remove old impact, apply new
        $this->updatePageScores($userId, $trustBoost, $fraudReduction, $old_impact);

        // Invalidate cached X data
        delete_transient('bcc_trust_x_' . $userId);

        AuditLogger::log('x_verified', $userId, [
            'username'        => $xData['username'],
            'trust_boost'     => $trustBoost,
            'fraud_reduction' => $fraudReduction,
        ], 'user');

        // Signal quest completion for X verification.
        do_action('bcc_trust_quest_signal', $userId, 'verify_x');

        // Notify read-model sync that owner data changed.
        do_action('bcc_trust_verification_changed', $userId);

        return [
            'username'        => $xData['username'],
            'email'           => $xData['email'] ?? null,
            'trust_boost'     => $trustBoost,
            'fraud_reduction' => $fraudReduction,
            'user_id'         => $userId,
        ];
    }

    /**
     * Disconnect X for a user and remove its score impact from their pages.
     *
     * @return array<string, mixed>
     */
    public function disconnect(int $userId): array {
        if (!$userId) {
            throw new Exception('Invalid user ID');
        }

        $connection          = $this->repository->getConnection($userId);
        $username            = ($connection !== null && $connection->x_username !== null && $connection->x_username !== '')
            ? $connection->x_username
            : 'unknown';
        $old_trust_boost     = $connection !== null ? $connection->x_trust_boost     : 0.0;
        $old_fraud_reduction = $connection !== null ? $connection->x_fraud_reduction : 0;

        $this->repository->disconnect($userId);

        // new_impact = 0, so page scores drop by the previously applied amount
        $this->updatePageScores($userId, 0, 0, $old_trust_boost - $old_fraud_reduction);

        delete_transient('bcc_trust_x_' . $userId);

        AuditLogger::log('x_disconnected', $userId, [
            'username' => $username,
        ], 'user');

        // Revoke quest completion when X is disconnected.
        do_action('bcc_trust_quest_signal_revoke', $userId, 'verify_x');
        do_action('bcc_trust_quest_signal_revoke', $userId, 'share_x');

        // Clear the share_x validation cache so reconnecting requires a fresh
        // tweet verification. Otherwise a stale meta flag would grant the
        // bonus forever after a single legitimate share.
        delete_user_meta($userId, '_bcc_quest_shared_x');

        do_action('bcc_trust_verification_changed', $userId);

        return ['username' => $username];
    }

    /**
     * Return the hydrated connection DTO for a user, or null if not connected.
     */
    public function getStatus(int $userId): ?XConnectionDTO {
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
     * Replaces the N+1 per-page UPDATE loop with a single query.
     * The bcc_trust_process_recalculations cron picks up flagged pages
     * and recalculates scores in batch every 5 minutes.
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