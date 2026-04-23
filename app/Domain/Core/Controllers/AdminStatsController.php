<?php
/**
 * Admin Stats REST Controller
 *
 * Handles admin-only read endpoints for fraud stats, risk distribution,
 * device analytics, and user analysis.
 *
 * Extracted from TrustRestController — all behavior, response shapes,
 * SQL queries, and permission rules preserved exactly.
 *
 * @package BCC\Trust\Core\Controllers
 */

namespace BCC\Trust\Core\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Exception;

use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Security\DeviceFingerprinter;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\FraudDetector;

if (!defined('ABSPATH')) {
    exit;
}

class AdminStatsController {

    public static function admin_permission_check(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get fraud statistics
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function get_fraud_stats(WP_REST_Request $request) {
        try {
            $stats       = FraudDetector::getStats();
            $deviceStats = DeviceFingerprinter::getStats();

            return self::success(array_merge($stats, [
                'device_stats'    => $deviceStats,
                'total_alerts'    => $stats['suspended_users'] ?? 0,
                'high_risk_count' => $stats['risk_distribution']['high'] ?? 0,
                'suspended_count' => $stats['suspended_users'] ?? 0
            ]));

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'REST API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return self::error('An unexpected error occurred.', 500);
        }
    }

    /**
     * Get high risk users
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function get_high_risk_users(WP_REST_Request $request) {
        try {
            $limit     = min(100, (int) $request->get_param('limit') ?: 20);
            $threshold = min(100, (int) $request->get_param('threshold') ?: 70);

            $userInfoRepo = \BCC\Trust\Core\Plugin::instance()->userInfoRepository();
            $users        = $userInfoRepo->getHighRiskUsers($threshold, $limit);

            $allowedFields = ['user_id', 'display_name', 'fraud_score', 'risk_level',
                              'is_suspended', 'is_verified', 'reputation_tier', 'votes_cast',
                              'automation_score', 'created_at'];

            $filtered = array_map(function ($user) use ($allowedFields) {
                $row = (array) $user;
                return array_intersect_key($row, array_flip($allowedFields));
            }, $users);

            return self::success(['users' => $filtered]);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'REST API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return self::error('An unexpected error occurred.', 500);
        }
    }

    /**
     * Get fraud activity
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function get_fraud_activity(WP_REST_Request $request) {
        try {
            $limit    = min(500, (int) $request->get_param('limit') ?: 10);
            $activity = AuditLogger::getSuspiciousActivity(24, $limit);

            $formatted = [];
            foreach ($activity as $event) {
                $formatted[] = [
                    'id'       => $event->id,
                    'user_id'  => $event->user_id,
                    'action'   => $event->action,
                    'message'  => $event->action,
                    'time'     => \BCC\Trust\Core\Support\Formatting::timeAgo($event->created_at),
                    'severity' => self::getSeverityFromAction($event->action)
                ];
            }

            return self::success(['events' => $formatted]);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'REST API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return self::error('An unexpected error occurred.', 500);
        }
    }

    /**
     * Get trust score trend
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function get_trust_trend(WP_REST_Request $request) {
        try {
            $days    = (int) $request->get_param('days') ?: 30;
            $results = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository()->getTrustTrend($days);

            $labels = [];
            $scores = [];

            foreach ($results as $row) {
                $labels[] = date('M d', strtotime($row->date) ?: 0);
                $scores[] = round((float) $row->avg_score, 1);
            }

            return self::success([
                'labels' => $labels,
                'scores' => $scores
            ]);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'REST API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return self::error('An unexpected error occurred.', 500);
        }
    }

    /**
     * Get risk distribution
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function get_risk_distribution(WP_REST_Request $request) {
        try {
            $stats = FraudDetector::getStats();

            return self::success([
                'critical' => $stats['risk_distribution']['critical'] ?? 0,
                'high'     => $stats['risk_distribution']['high'] ?? 0,
                'medium'   => $stats['risk_distribution']['medium'] ?? 0,
                'low'      => $stats['risk_distribution']['low'] ?? 0,
                'minimal'  => $stats['risk_distribution']['minimal'] ?? 0
            ]);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'REST API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return self::error('An unexpected error occurred.', 500);
        }
    }

    /**
     * Get fraud trend
     *
     * FIX: wrapped OR clauses in parentheses to prevent AND from binding
     * only to the last OR condition. Previously, fraud and suspicious events
     * were returned regardless of the date filter.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function get_fraud_trend(WP_REST_Request $request) {
        try {
            $days    = (int) $request->get_param('days') ?: 30;
            $results = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository()->getFraudTrend($days);

            $labels = [];
            $counts = [];

            foreach ($results as $row) {
                $labels[] = date('M d', strtotime($row->date) ?: 0);
                $counts[] = $row->count;
            }

            return self::success([
                'labels' => $labels,
                'counts' => $counts
            ]);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'REST API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return self::error('An unexpected error occurred.', 500);
        }
    }

    /**
     * Get device statistics
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function get_device_stats(WP_REST_Request $request) {
        try {
            $stats = DeviceFingerprinter::getStats();

            return self::success([
                'clean'     => $stats['total_records'] - $stats['automated_detected'] - $stats['high_risk'],
                'suspicious'=> $stats['medium_risk'] ?? 0,
                'automated' => $stats['automated_detected'],
                'shared'    => $stats['shared_devices']
            ]);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'REST API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return self::error('An unexpected error occurred.', 500);
        }
    }

    /**
     * Analyze a user
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function analyze_user(WP_REST_Request $request) {
        // Nonce is validated automatically by WordPress via the X-WP-Nonce header.

        try {
            $userId = (int) $request['id'];

            if (!$userId) {
                throw new Exception('User ID required');
            }

            $analysis = FraudDetector::analyzeFraud($userId);

            FraudDetector::getEnhancedFraudScore($userId);

            return self::success([
                'user_id'     => $userId,
                'fraud_score' => $analysis['score'],
                'risk_level'  => $analysis['risk_level'],
                'triggers'    => $analysis['triggers'],
                'analysis'    => $analysis
            ]);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'REST API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return self::error('An unexpected error occurred.', 500);
        }
    }

    // ======================================================
    // HELPER METHODS
    // ======================================================

    private static function getSeverityFromAction(string $action): string {
        if (strpos($action, 'critical') !== false || strpos($action, 'suspend') !== false) {
            return 'critical';
        }
        if (strpos($action, 'high') !== false || strpos($action, '_ring') !== false || strpos($action, 'ring_') !== false) {
            return 'high';
        }
        if (strpos($action, 'medium') !== false || strpos($action, 'flag') !== false) {
            return 'medium';
        }
        return 'low';
    }

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
