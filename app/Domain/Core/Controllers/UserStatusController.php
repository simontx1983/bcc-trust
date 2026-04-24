<?php
/**
 * User Status REST Controller
 *
 * Handles user-facing status and device fingerprint endpoints.
 *
 * Extracted from TrustRestController — all behavior, response shapes,
 * status codes, permission rules, and security logic preserved exactly.
 *
 * @package BCC\Trust\Core\Controllers
 * @version 2.1.1
 *
 * Security notes preserved from TrustRestController:
 *  - get_user_status: removed reference to non-existent `pages_joined` column
 *  - store_fingerprint: automation_score now capped at 100 with LEAST()
 *
 * Email verification is handled by PeepSo; the PeepSo bridge in
 * BCC\Trust\Core\Integration\PeepSoIntegration::onEmailVerified mirrors its
 * state into bcc_trust_user_info.is_verified.
 */

namespace BCC\Trust\Core\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Exception;

use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\RateLimiter;

if (!defined('ABSPATH')) {
    exit;
}

class UserStatusController {

    /**
     * Store device fingerprint
     *
     * FIX: automation_score increment is now capped at 100 with LEAST()
     * to match the same guard already applied to fraud_score.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function store_fingerprint(WP_REST_Request $request) {
        // Nonce is validated automatically by WordPress via the X-WP-Nonce header.

        if (!RateLimiter::allow('api')) {
            return self::error('Too many requests. Please try again later.', 429);
        }

        try {
            $userId = get_current_user_id();
            if (!$userId) {
                throw new Exception('User not authenticated');
            }

            $data = $request->get_json_params();

            if (empty($data['fingerprint']) || empty($data['fingerprint']['hash'])) {
                throw new Exception('Invalid fingerprint data');
            }

            // Validate fingerprint hash: must be a hex string, max 128 chars.
            $clientHash = $data['fingerprint']['hash'];
            if (!is_string($clientHash) || strlen($clientHash) < 32 || strlen($clientHash) > 128 || !preg_match('/^[a-f0-9]+$/i', $clientHash)) {
                throw new Exception('Invalid fingerprint format');
            }

            $fingerprinter  = \BCC\Trust\Core\Plugin::instance()->deviceFingerprinter();
            $automationData = $fingerprinter->detectAutomation();

            // SECURITY: identity hash is server-only. $clientHash is kept for
            // correlation in automation_signals but MUST NOT influence the
            // stored identifier — a sock-puppet who rotates client-hash per
            // account would otherwise split its bucket and bypass shared-device
            // detection entirely.
            $serverFp = $fingerprinter->generateServerSideFingerprint();
            $hash     = hash('sha256', $serverFp . '|' . wp_salt('auth'));
            unset($clientHash); // explicit: not used as identity.

            $fingerprintId = $fingerprinter->storeFingerprint(
                $userId,
                $hash,
                $automationData
            );

            $userCount = $fingerprinter->getFingerprintUserCount($hash);

            $alert = false;

            if ($userCount > 3) {
                AuditLogger::log('multiple_accounts_detected', $userId, [
                    'fingerprint'      => $hash,
                    'account_count'    => $userCount,
                    'automation_score' => $automationData['confidence']
                ], 'user');

                $alert = true;
            }

            if ($automationData['is_automated'] && $automationData['confidence'] > 70) {
                AuditLogger::log('automation_detected', $userId, [
                    'confidence' => $automationData['confidence'],
                    'signals'    => $automationData['signals']
                ], 'user');

                \BCC\Trust\Core\Plugin::instance()->userInfoRepository()->incrementAutomationAndFraudScore($userId, 20);

                $alert = true;
            }

            if (!empty($data['data'])) {
                // Size guard: reject payloads > 10KB to prevent user_meta bloat.
                // An attacker sending multi-MB fingerprint data could exhaust storage.
                $encodedSize = strlen(wp_json_encode($data['data']) ?: '');
                if ($encodedSize <= 10240) {
                    update_user_meta($userId, 'bcc_last_fingerprint_data', [
                        'data'       => $data['data'],
                        'time'       => time(),
                        'automation' => $automationData
                    ]);
                }
            }

            // Security: never expose internal fraud detection signals to the client.
            // Attackers can use feedback (automation_detected, account_count, etc.)
            // to iteratively evade detection. All signals remain server-side only.
            return self::success([
                'stored' => true,
            ]);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] store_fingerprint failed', ['error' => $e->getMessage()]);
            $safeMessages = ['User not authenticated', 'Invalid fingerprint data', 'Invalid fingerprint format'];
            $message = in_array($e->getMessage(), $safeMessages, true) ? $e->getMessage() : 'An unexpected error occurred.';
            return self::error($message, 400);
        }
    }

    /**
     * Get user's fraud/trust status
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function get_user_status(WP_REST_Request $request) {
        if (!RateLimiter::allow('api')) {
            return new \WP_Error('rate_limited', 'Too many requests.', ['status' => 429]);
        }

        try {
            $userId = get_current_user_id();
            if (!$userId) {
                throw new Exception('User not authenticated');
            }

            $userInfo = \BCC\Trust\Core\Plugin::instance()->userInfoRepository()->getByUserId($userId);

            // Security: internal fraud metrics are never exposed to the client.
            return self::success([
                'user_id'   => $userId,
                'suspended' => $userInfo ? (bool) $userInfo->is_suspended : false,
                'verified'  => $userInfo ? (bool) $userInfo->is_verified : false,
                'stats'     => [
                    'votes_cast'        => $userInfo ? (int) $userInfo->votes_cast : 0,
                    'endorsements_given'=> $userInfo ? (int) $userInfo->endorsements_given : 0,
                    'pages_owned'       => $userInfo ? (int) $userInfo->pages_owned : 0,
                ],
            ]);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] get_user_status failed', ['error' => $e->getMessage()]);
            $message = $e->getMessage() === 'User not authenticated' ? $e->getMessage() : 'An unexpected error occurred.';
            return self::error($message, 400);
        }
    }

    // ======================================================
    // HELPER METHODS
    // ======================================================

    /**
     * @param array<string, mixed> $data
     */
    private static function success(array $data): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'data'    => $data
        ], 200);
    }

    private static function error(string $message, int $status): WP_Error {
        return new WP_Error('trust_error', $message, ['status' => $status]);
    }
}
