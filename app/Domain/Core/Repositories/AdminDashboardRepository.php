<?php
/**
 * Admin Dashboard Repository
 *
 * Handles all data-loading queries for the admin dashboard.
 * Migrated from includes/admin/dashboard-controller.php.
 *
 * @package BCC\Trust\Core\Repositories
 * @version 2.1.0
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Support\FraudClassification;
use BCC\Core\Log\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class AdminDashboardRepository
{
    private const CACHE_GROUP = 'bcc_trust_admin';
    private const CACHE_TTL   = 300; // 5 minutes

    /**
     * Escape a string for use in a LIKE clause.
     *
     * Centralises `$wpdb->esc_like()` so admin templates do not need
     * `global $wpdb` for search-term escaping.
     *
     * @param string $value Raw search term.
     * @return string Escaped string safe for LIKE (still needs %s placeholder).
     */
    public static function escLike(string $value): string
    {
        global $wpdb;
        return $wpdb->esc_like($value);
    }

    /**
     * Batch-resolve PeepSo Page IDs to linked CPT Post IDs.
     *
     * @param int[] $pageIds Array of PeepSo page IDs.
     * @return array<int, array{cpt_id: int, cpt_type: string}> Map of peepso_page_id => [ 'cpt_id' => int, 'cpt_type' => string ].
     */
    public function getCptMap(array $pageIds): array
    {
        if (empty($pageIds)) {
            return [];
        }

        global $wpdb;

        $pageIds      = array_map('intval', $pageIds);
        $placeholders = implode(',', array_fill(0, count($pageIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.meta_value AS peepso_page_id, p.ID AS cpt_id, p.post_type AS cpt_type
                 FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE pm.meta_key = '_peepso_page_id'
                   AND pm.meta_value IN ({$placeholders})
                   AND p.post_type IN ('validators','builder','nft_project')
                   AND p.post_status != 'trash'",
                ...$pageIds
            )
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->peepso_page_id] = [
                'cpt_id'   => (int) $row->cpt_id,
                'cpt_type' => $row->cpt_type,
            ];
        }

        return $map;
    }

    /**
     * Get overview statistics for the dashboard.
     *
     * Cached via transient for 5 minutes — the underlying queries are expensive at scale.
     *
     * @return array<string, mixed> Dashboard overview data.
     */
    public function getOverviewData(): array
    {
        $cached = wp_cache_get('overview_stats', self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;

        $votesTable        = TableRegistry::votes();
        $endorseTable      = TableRegistry::endorsements();
        $userInfoTable     = TableRegistry::userInfo();
        $fingerprintTable  = TableRegistry::fingerprints();
        $fraudTable        = TableRegistry::fraudAnalysis();
        $readModelTable    = TableRegistry::pageReadModel();

        // READ MODEL ONLY — DO NOT BYPASS.
        // Page counts + tier counts come from bcc_page_read_model, the single
        // source of truth for frontend/API/admin page reads.
        $totalVotes        = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$votesTable} WHERE status = %d", 1));
        $totalEndorsements = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$endorseTable} WHERE status = %d", 1));
        $totalPages        = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$readModelTable}");
        $totalUsers        = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$userInfoTable}");

        // Risk distribution from canonical user_info table — 5-tier scheme
        // aligned with FraudClassification (Critical/High/Medium/Low/Minimal).
        $fraudCritical  = BCC_TRUST_FRAUD_CRITICAL;
        $fraudHigh      = BCC_TRUST_FRAUD_HIGH;
        $fraudMedium    = BCC_TRUST_FRAUD_MEDIUM;
        $fraudLow       = BCC_TRUST_FRAUD_LOW;

        $riskDist = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(CASE WHEN fraud_score >= %d THEN 1 END) AS critical_risk,
                COUNT(CASE WHEN fraud_score >= %d AND fraud_score < %d THEN 1 END) AS high_risk,
                COUNT(CASE WHEN fraud_score >= %d AND fraud_score < %d THEN 1 END) AS medium_risk,
                COUNT(CASE WHEN fraud_score >= %d AND fraud_score < %d THEN 1 END) AS low_risk,
                COUNT(CASE WHEN fraud_score < %d THEN 1 END) AS minimal_risk
             FROM {$userInfoTable}
             WHERE fraud_score > 0",
            $fraudCritical,
            $fraudHigh, $fraudCritical,
            $fraudMedium, $fraudHigh,
            $fraudLow, $fraudMedium,
            $fraudLow
        ));

        $criticalRiskUsers = (int) ($riskDist->critical_risk ?? 0);
        $highRiskUsers     = (int) ($riskDist->high_risk ?? 0);
        $mediumRiskUsers   = (int) ($riskDist->medium_risk ?? 0);
        $lowRiskUsers      = (int) ($riskDist->low_risk ?? 0);
        $minimalRiskUsers  = (int) ($riskDist->minimal_risk ?? 0);

        // Get device stats
        $uniqueDevices = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT fingerprint) FROM {$fingerprintTable}"
        );

        $automationHigh   = BCC_TRUST_AUTOMATION_HIGH;
        $automationMedium = BCC_TRUST_AUTOMATION_MEDIUM;

        $highAutomationDevices = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$fingerprintTable} WHERE automation_score >= %d",
            $automationHigh
        ));

        $suspiciousDevices = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$fingerprintTable}
             WHERE automation_score >= %d AND automation_score < %d",
            $automationMedium,
            $automationHigh
        ));

        // Get fraud analysis stats
        $totalFraudAnalyses  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$fraudTable}");
        $recentFraudAnalyses = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$fraudTable}
                 WHERE analyzed_at > DATE_SUB(NOW(), INTERVAL %d HOUR)",
                24
            )
        );

        // READ MODEL ONLY — DO NOT BYPASS.
        // Tier distribution reads from the pre-computed reputation_tier column
        // in bcc_page_read_model. Classification logic lives in the sync pipeline
        // so the admin dashboard cannot drift from the canonical rule.
        $tierRows = $wpdb->get_results(
            "SELECT reputation_tier, COUNT(*) AS cnt FROM {$readModelTable} GROUP BY reputation_tier"
        ) ?: [];
        $tierCounts = ['elite' => 0, 'trusted' => 0, 'neutral' => 0, 'caution' => 0, 'risky' => 0];
        foreach ($tierRows as $r) {
            if (isset($tierCounts[$r->reputation_tier])) {
                $tierCounts[$r->reputation_tier] = (int) $r->cnt;
            }
        }
        $elitePages   = $tierCounts['elite'];
        $trustedPages = $tierCounts['trusted'];
        $neutralPages = $tierCounts['neutral'];
        $cautionPages = $tierCounts['caution'];
        $riskyPages   = $tierCounts['risky'];

        $data = [
            'counts' => [
                'total_votes'           => $totalVotes,
                'total_endorsements'    => $totalEndorsements,
                'total_pages'           => $totalPages,
                'total_users'           => $totalUsers,
                'total_devices'         => $uniqueDevices,
                'total_fraud_analyses'  => $totalFraudAnalyses,
                'recent_fraud_analyses' => $recentFraudAnalyses,
            ],
            'risk_distribution' => [
                'critical_risk' => $criticalRiskUsers,
                'high_risk'     => $highRiskUsers,
                'medium_risk'   => $mediumRiskUsers,
                'low_risk'      => $lowRiskUsers,
                'minimal_risk'  => $minimalRiskUsers,
            ],
            'device_risk' => [
                'high_automation' => $highAutomationDevices,
                'suspicious'      => $suspiciousDevices,
                'normal'          => $uniqueDevices - ($highAutomationDevices + $suspiciousDevices),
            ],
            'tier_distribution' => [
                'elite'   => $elitePages,
                'trusted' => $trustedPages,
                'neutral' => $neutralPages,
                'caution' => $cautionPages,
                'risky'   => $riskyPages,
            ],
            'thresholds' => [
                'fraud_high'        => $fraudHigh,
                'fraud_medium'      => $fraudMedium,
                'fraud_low'         => $fraudLow,
                'automation_high'   => $automationHigh,
                'automation_medium' => $automationMedium,
                // Reference-only display of the canonical thresholds used by
                // the read-model sync pipeline. Counts above come from
                // bcc_page_read_model.reputation_tier (not from thresholds).
                'tier_elite'        => BCC_TRUST_TIER_ELITE,
                'tier_trusted'      => BCC_TRUST_TIER_TRUSTED,
                'tier_neutral'      => BCC_TRUST_TIER_NEUTRAL,
                'tier_caution'      => BCC_TRUST_TIER_CAUTION,
            ],
        ];

        wp_cache_set('overview_stats', $data, self::CACHE_GROUP, self::CACHE_TTL);

        return $data;
    }

    /**
     * Get top pages data sorted by trust score.
     *
     * @return array<string, mixed> Pages data with CPT map.
     */
    public function getPagesData(): array
    {
        global $wpdb;

        $rmTable = TableRegistry::pageReadModel();
        $limit   = BCC_TRUST_ADMIN_PAGES_LIMIT;

        // Use the denormalized read model — vote_count is pre-computed,
        // eliminating the correlated COUNT subquery on bcc_trust_votes.
        $pages = $wpdb->get_results($wpdb->prepare(
            "SELECT rm.page_id, rm.owner_id AS page_owner_id,
                    rm.trust_score AS total_score, rm.reputation_tier,
                    rm.confidence_score, rm.positive_score, rm.negative_score,
                    rm.vote_count, rm.unique_voters, rm.endorsement_count,
                    rm.onchain_bonus,
                    p.post_title,
                    p.post_author
             FROM {$rmTable} rm
             LEFT JOIN {$wpdb->posts} p ON rm.page_id = p.ID
             ORDER BY rm.trust_score DESC
             LIMIT %d",
            $limit
        ));

        $pageIds = array_map(function ($p) { return (int) $p->page_id; }, $pages);
        $cptMap  = $this->getCptMap($pageIds);

        return [
            'pages'   => $pages,
            'cpt_map' => $cptMap,
            'total'   => count($pages),
            'limit'   => $limit,
        ];
    }

    /**
     * Get all PeepSo pages with optional trust score data.
     *
     * @return array<string, mixed> All pages data with CPT map.
     */
    public function getAllPagesData(): array
    {
        global $wpdb;

        $scoresTable = TableRegistry::scores();

        $limit = BCC_TRUST_ADMIN_ALL_PAGES_LIMIT;

        $pages = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_author, p.post_title, p.post_name, p.post_status, p.post_type, p.post_date, s.total_score, s.reputation_tier, s.vote_count, s.confidence_score
             FROM {$wpdb->posts} p
             LEFT JOIN {$scoresTable} s ON p.ID = s.page_id AND s.category_id = 0
             WHERE p.post_type = 'peepso-page'
             ORDER BY p.ID DESC
             LIMIT %d",
            $limit
        ));

        $pageIds = array_map(function ($p) { return (int) $p->ID; }, $pages);
        $cptMap  = $this->getCptMap($pageIds);

        return [
            'pages'   => $pages,
            'cpt_map' => $cptMap,
            'total'   => count($pages),
            'limit'   => $limit,
        ];
    }

    /**
     * Get users data sorted by fraud score descending.
     *
     * @return array<string, mixed> Users data with risk labels.
     */
    public function getUsersData(): array
    {
        global $wpdb;

        $userInfoTable   = TableRegistry::userInfo();
        $reputationTable = TableRegistry::reputation();

        $limit = BCC_TRUST_ADMIN_USERS_LIMIT;

        $users = $wpdb->get_results($wpdb->prepare(
            "SELECT ui.id, ui.user_id, ui.user_login, ui.user_email, ui.display_name, ui.registered, ui.usr_last_activity, ui.usr_views, ui.usr_likes, ui.usr_role, ui.fraud_score, ui.peak_fraud_score, ui.trust_rank, ui.risk_level, ui.is_suspended, ui.is_verified, ui.votes_cast, ui.endorsements_given, ui.automation_score, ui.behavior_score, ui.device_fraud_probability, ui.signals_updated_at, ui.pages_owned, ui.groups_owned, ui.posts_created, ui.comments_made, ui.last_login, ui.last_ip_address, ui.device_fingerprint, ui.fraud_triggers, ui.page_ids_owned, ui.created_at, ui.updated_at,
                    r.reputation_score,
                    r.reputation_tier as user_tier,
                    r.vote_weight,
                    u.display_name,
                    u.user_email,
                    u.user_registered
             FROM {$userInfoTable} ui
             LEFT JOIN {$reputationTable} r ON ui.user_id = r.user_id
             LEFT JOIN {$wpdb->users} u ON ui.user_id = u.ID
             ORDER BY ui.fraud_score DESC
             LIMIT %d",
            $limit
        ));

        // Derive risk labels from FraudClassification (canonical source of truth).
        // These are presentation fields — do not branch on them.
        foreach ($users as &$user) {
            $score = (int) $user->fraud_score;
            $user->risk_label = FraudClassification::label($score);
            $user->risk_color = FraudClassification::color($score);
        }

        return [
            'users' => $users,
            'total' => count($users),
            'limit' => $limit,
        ];
    }

    /**
     * Get recent activity/audit log entries.
     *
     * @return array<string, mixed> Activity data.
     */
    public function getActivityData(): array
    {
        global $wpdb;

        $auditTable = TableRegistry::activity();

        $limit = BCC_TRUST_ADMIN_ACTIVITY_LIMIT;

        $activity = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.user_id, a.action, a.target_type, a.target_id, a.ip_address, a.created_at, u.display_name as user_name
             FROM {$auditTable} a
             LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID
             ORDER BY a.created_at DESC
             LIMIT %d",
            $limit
        ));

        return [
            'activity' => $activity,
            'total'    => count($activity),
            'limit'    => $limit,
        ];
    }

    /**
     * Get high-risk fraud users from the canonical user_info table.
     *
     * Joins wp_users for display_name/user_email and aggregates
     * fraud_analysis counts inline (recent_analyses, total_analyses).
     *
     * @return array<string, mixed> Fraud data with decoded signals.
     */
    public function getFraudData(): array
    {
        global $wpdb;

        $userInfoTable = TableRegistry::userInfo();
        $fraudTable    = TableRegistry::fraudAnalysis();

        $fraudHigh     = BCC_TRUST_FRAUD_HIGH;
        $fraudCritical = BCC_TRUST_FRAUD_CRITICAL;
        $limit         = BCC_TRUST_ADMIN_FRAUD_LIMIT;

        $users = $wpdb->get_results($wpdb->prepare(
            "SELECT ui.user_id, ui.fraud_score, ui.risk_level,
                    ui.automation_score, ui.behavior_score,
                    COALESCE(fa.recent_count, 0) AS recent_analyses,
                    COALESCE(fa.total_count, 0)  AS total_analyses,
                    ui.fraud_triggers,
                    ui.risk_label, ui.risk_color,
                    u.display_name, u.user_email,
                    ui.is_suspended, ui.is_verified,
                    ui.updated_at
             FROM {$userInfoTable} ui
             LEFT JOIN {$wpdb->users} u ON ui.user_id = u.ID
             LEFT JOIN (
                 SELECT user_id,
                        COUNT(*) AS total_count,
                        SUM(CASE WHEN analyzed_at > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent_count
                 FROM {$fraudTable}
                 GROUP BY user_id
             ) fa ON ui.user_id = fa.user_id
             WHERE ui.fraud_score > %d
             ORDER BY ui.fraud_score DESC
             LIMIT %d",
            $fraudHigh,
            $limit
        ));

        $criticalCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$userInfoTable} WHERE fraud_score >= %d",
            $fraudCritical
        ));

        // Decode fraud signals from pre-stored JSON.
        foreach ($users as $u) {
            $triggers   = !empty($u->fraud_triggers) ? json_decode($u->fraud_triggers, true) : [];
            $u->signals = is_array($triggers) ? array_keys($triggers) : [];
        }

        return [
            'fraud_users'          => $users,
            'critical_count'       => $criticalCount,
            'high_risk_threshold'  => $fraudHigh,
            'critical_threshold'   => $fraudCritical,
            'total'                => count($users),
            'limit'                => $limit,
        ];
    }

    /**
     * Get device fingerprint data with automation labels.
     *
     * @return array<string, mixed> Devices data with automation labels.
     */
    public function getDevicesData(): array
    {
        global $wpdb;

        $fingerprintTable = TableRegistry::fingerprints();
        $userInfoTable    = TableRegistry::userInfo();

        $limit = BCC_TRUST_ADMIN_DEVICES_LIMIT;

        $automationHigh   = BCC_TRUST_AUTOMATION_HIGH;
        $automationMedium = BCC_TRUST_AUTOMATION_MEDIUM;

        $devices = $wpdb->get_results($wpdb->prepare(
            "SELECT f.id, f.user_id, f.fingerprint, f.automation_score, f.automation_signals, f.screen_resolution, f.first_seen, f.last_seen, f.ip_address, f.user_agent, f.risk_level AS fingerprint_risk_level,
                    u.display_name,
                    ui.fraud_score,
                    ui.risk_level,
                    COALESCE(fc.user_count, 1) as user_count
             FROM {$fingerprintTable} f
             LEFT JOIN {$wpdb->users} u ON f.user_id = u.ID
             LEFT JOIN {$userInfoTable} ui ON f.user_id = ui.user_id
             LEFT JOIN (
                 SELECT fingerprint, COUNT(*) as user_count
                 FROM {$fingerprintTable}
                 GROUP BY fingerprint
             ) fc ON f.fingerprint = fc.fingerprint
             ORDER BY f.last_seen DESC
             LIMIT %d",
            $limit
        ));

        // Add automation labels
        foreach ($devices as &$device) {
            if ($device->automation_score >= $automationHigh) {
                $device->automation_label = 'High';
                $device->automation_color = '#dc3545';
            } elseif ($device->automation_score >= $automationMedium) {
                $device->automation_label = 'Medium';
                $device->automation_color = '#fd7e14';
            } elseif ($device->automation_score > 0) {
                $device->automation_label = 'Low';
                $device->automation_color = '#ffc107';
            } else {
                $device->automation_label = 'None';
                $device->automation_color = '#28a745';
            }
        }

        return [
            'devices'    => $devices,
            'total'      => count($devices),
            'limit'      => $limit,
            'thresholds' => [
                'high'   => $automationHigh,
                'medium' => $automationMedium,
            ],
        ];
    }

    /**
     * Get vote ring data from cached cron results.
     *
     * Ring detection is expensive (graph analysis). Results are computed
     * hourly by bcc_trust_run_ring_detection_cron() and stored as a transient.
     * Admin pages always read the cached snapshot.
     *
     * @return array<string, mixed> Rings data with thresholds.
     */
    public function getRingsData(): array
    {
        $ringMinSize   = BCC_TRUST_RING_MIN_SIZE;
        $ringMinMutual = BCC_TRUST_RING_MIN_MUTUAL;

        $cached = wp_cache_get('ring_results', self::CACHE_GROUP);
        $rings  = is_array($cached) ? $cached : [];

        return [
            'rings'      => $rings,
            'thresholds' => [
                'min_size'   => $ringMinSize,
                'min_mutual' => $ringMinMutual,
            ],
        ];
    }

    /**
     * Get ML pattern detection data.
     *
     * @return array<string, mixed> Patterns data with type counts.
     */
    public function getMlData(): array
    {
        global $wpdb;

        $patternsTable = TableRegistry::patterns();

        $limit = BCC_TRUST_ADMIN_ML_LIMIT;

        $patterns = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, p.user_id, p.pattern_type, p.pattern_data, p.confidence, p.detected_at, p.expires_at, u.display_name
             FROM {$patternsTable} p
             LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
             ORDER BY p.detected_at DESC
             LIMIT %d",
            $limit
        ));

        $patternCounts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pattern_type, COUNT(*) as count
                 FROM {$patternsTable}
                 WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY pattern_type
                 ORDER BY count DESC",
                30
            )
        );

        return [
            'patterns'       => $patterns,
            'pattern_counts' => $patternCounts,
            'total'          => count($patterns),
            'limit'          => $limit,
        ];
    }

    /**
     * Get repair/diagnostic data for all trust engine tables.
     *
     * @return array<string, mixed> Table status, orphaned records, and cleanup config.
     */
    public function getRepairData(): array
    {
        global $wpdb;

        $tables      = TableRegistry::all();
        $tableStatus = [];

        foreach ($tables as $name => $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
            $count  = $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") : 0;

            $tableStatus[$name] = [
                'name'   => $table,
                'exists' => $exists,
                'count'  => $count,
            ];
        }

        // Get orphaned records
        $votesTable  = TableRegistry::votes();
        $scoresTable = TableRegistry::scores();

        $orphanedVotes = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$votesTable} v
                 LEFT JOIN {$scoresTable} s ON v.page_id = s.page_id
                 WHERE s.page_id IS NULL AND %d",
                1
            )
        );

        return [
            'repair_tools'   => true,
            'tables'         => $tableStatus,
            'orphaned_votes' => $orphanedVotes,
            'cleanup_days'   => [
                'fingerprints'   => BCC_TRUST_CLEANUP_FINGERPRINTS,
                'patterns'       => BCC_TRUST_CLEANUP_PATTERNS,
                'fraud_analysis' => BCC_TRUST_CLEANUP_FRAUD_ANALYSIS,
                'activity'       => BCC_TRUST_CLEANUP_ACTIVITY,
            ],
        ];
    }

    /**
     * Get daily average trust score trend for the last N days.
     *
     * @return list<object>
     * @phpstan-return list<object{date: string, avg_score: float|numeric-string|null}>
     */
    public function getTrustTrend(int $days = 30): array
    {
        global $wpdb;
        $table = TableRegistry::scores();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(last_calculated_at) as date, AVG(total_score) as avg_score
             FROM {$table}
             WHERE last_calculated_at > DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DATE(last_calculated_at)
             ORDER BY date ASC",
            $days
        )) ?: [];
    }

    /**
     * Get daily fraud/suspicious activity count trend for the last N days.
     *
     * @return list<object>
     * @phpstan-return list<object{date: string, count: int|numeric-string}>
     */
    public function getFraudTrend(int $days = 30): array
    {
        global $wpdb;
        $table = TableRegistry::activity();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count
             FROM {$table}
             WHERE (action LIKE 'fraud%%' OR action LIKE 'suspicious%%' OR action LIKE 'flag%%')
               AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            $days
        )) ?: [];
    }

    // ─────────────────────────────────────────────────────────────
    // Moderation — Single User Detail (moderation-user.php)
    // ─────────────────────────────────────────────────────────────

    /**
     * Get user info row from bcc_trust_user_info.
     *
     * @param int $userId
     * @return object|null
     */
    public function getUserInfo(int $userId): ?object
    {
        global $wpdb;
        $table = TableRegistry::userInfo();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, fraud_score, risk_level, trust_rank, automation_score,
                    behavior_score, votes_cast, endorsements_given, pages_owned,
                    groups_owned, posts_created, is_suspended, is_verified,
                    usr_last_activity, updated_at
             FROM {$table}
             WHERE user_id = %d",
            $userId
        ));
    }

    /**
     * Get suspension history for a user.
     *
     * @param int $userId
     * @return array<string, mixed>
     */
    public function getSuspensionHistory(int $userId, int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        $table = TableRegistry::suspensions();

        $limit  = max(1, min($limit, 500));
        $offset = max(0, $offset);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, suspended_by, reason, notes,
                    fraud_score_at_time, suspended_at, unsuspended_at, unsuspended_by
             FROM {$table}
             WHERE user_id = %d
             ORDER BY suspended_at DESC
             LIMIT %d OFFSET %d",
            $userId,
            $limit,
            $offset
        )) ?: [];
    }

    /**
     * Get fraud analysis history for a user.
     *
     * @param int $userId
     * @param int $limit
     * @return array<string, mixed>
     */
    public function getFraudHistory(int $userId, int $limit = 20): array
    {
        global $wpdb;
        $table = TableRegistry::fraudAnalysis();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, fraud_score, risk_level, confidence,
                    triggers, analyzed_at, expires_at
             FROM {$table}
             WHERE user_id = %d
             ORDER BY analyzed_at DESC
             LIMIT %d",
            $userId,
            $limit
        )) ?: [];
    }

    /**
     * Get pages owned by a user with trust scores.
     *
     * @param int $userId
     * @return array<string, mixed>
     */
    public function getUserPages(int $userId, int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        $scoresTable = TableRegistry::scores();

        $limit  = max(1, min($limit, 500));
        $offset = max(0, $offset);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.page_id, s.page_owner_id, s.total_score, s.reputation_tier,
                    s.vote_count, s.endorsement_count, s.confidence_score,
                    p.post_title
             FROM {$scoresTable} s
             JOIN {$wpdb->posts} p ON s.page_id = p.ID
             WHERE s.page_owner_id = %d
             ORDER BY s.total_score DESC
             LIMIT %d OFFSET %d",
            $userId,
            $limit,
            $offset
        )) ?: [];
    }

    /**
     * Get recent votes cast by a user.
     *
     * @param int $userId
     * @param int $limit
     * @return array<string, mixed>
     */
    public function getRecentVotesByUser(int $userId, int $limit = 20): array
    {
        global $wpdb;
        $votesTable = TableRegistry::votes();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT v.id, v.page_id, v.voter_user_id, v.vote_type, v.weight,
                    v.created_at, p.post_title AS page_title
             FROM {$votesTable} v
             LEFT JOIN {$wpdb->posts} p ON v.page_id = p.ID
             WHERE v.voter_user_id = %d AND v.status = 1
             ORDER BY v.created_at DESC
             LIMIT %d",
            $userId,
            $limit
        )) ?: [];
    }

    /**
     * Get recent activity log entries for a user.
     *
     * @param int $userId
     * @param int $limit
     * @return array<string, mixed>
     */
    public function getRecentActivityByUser(int $userId, int $limit = 20): array
    {
        global $wpdb;
        $table = TableRegistry::activity();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, action, target_id, target_type, created_at
             FROM {$table}
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $userId,
            $limit
        )) ?: [];
    }

    /**
     * Get IP addresses used by a user from the activity log.
     *
     * @param int $userId
     * @return array<string, mixed>
     */
    public function getUserIps(int $userId): array
    {
        global $wpdb;
        $table = TableRegistry::activity();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT ip_address, COUNT(*) AS count, MAX(created_at) AS last_seen
             FROM {$table}
             WHERE user_id = %d AND ip_address IS NOT NULL
             GROUP BY ip_address
             ORDER BY last_seen DESC",
            $userId
        )) ?: [];
    }

    /**
     * Get device fingerprints for a user.
     *
     * @param int $userId
     * @return array<string, mixed>
     */
    public function getUserFingerprints(int $userId): array
    {
        global $wpdb;
        $table = TableRegistry::fingerprints();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, fingerprint, automation_score, risk_level,
                    ip_address, first_seen, last_seen
             FROM {$table}
             WHERE user_id = %d
             ORDER BY last_seen DESC",
            $userId
        )) ?: [];
    }

    /**
     * Get average trust score for all pages owned by a user.
     *
     * @param int $userId
     * @return float|null
     */
    public function getUserPagesAvgScore(int $userId): ?float
    {
        global $wpdb;
        $table = TableRegistry::scores();

        $avg = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(total_score) FROM {$table} WHERE page_owner_id = %d",
            $userId
        ));

        return $avg !== null ? (float) $avg : null;
    }

    // ─────────────────────────────────────────────────────────────
    // Moderation — IP Investigation (moderation-ip.php)
    // ─────────────────────────────────────────────────────────────

    /**
     * Get activity log entries for an IP address.
     *
     * @param string $ipBin Binary IP address (from inet_pton).
     * @param int    $limit
     * @return array<string, mixed>
     */
    public function getActivitiesByIp(string $ipBin, int $limit = 200): array
    {
        global $wpdb;
        $table = TableRegistry::activity();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.user_id, a.action, a.target_id, a.target_type,
                    a.ip_address, a.created_at,
                    u.user_login, u.display_name
             FROM {$table} a
             LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID
             WHERE a.ip_address = %s
             ORDER BY a.created_at DESC
             LIMIT %d",
            $ipBin,
            $limit
        )) ?: [];
    }

    /**
     * Get users associated with an IP address.
     *
     * @param string $ipBin Binary IP address.
     * @return array<string, mixed>
     */
    public function getUsersByIp(string $ipBin): array
    {
        global $wpdb;
        $activityTable = TableRegistry::activity();
        $userInfoTable = TableRegistry::userInfo();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.user_id, u.user_login, u.display_name,
                    ui.fraud_score, ui.risk_level, ui.is_suspended,
                    COUNT(*) AS activity_count, MAX(a.created_at) AS last_seen
             FROM {$activityTable} a
             LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID
             LEFT JOIN {$userInfoTable} ui ON a.user_id = ui.user_id
             WHERE a.ip_address = %s AND a.user_id IS NOT NULL
             GROUP BY a.user_id
             ORDER BY last_seen DESC",
            $ipBin
        )) ?: [];
    }

    /**
     * Get device fingerprints associated with an IP address.
     *
     * @param string $ipBin Binary IP address.
     * @return array<string, mixed>
     */
    public function getFingerprintsByIp(string $ipBin): array
    {
        global $wpdb;
        $table = TableRegistry::fingerprints();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT fingerprint, COUNT(*) AS device_count,
                    MAX(automation_score) AS max_automation,
                    MAX(risk_level) AS risk_level, MAX(last_seen) AS last_seen
             FROM {$table}
             WHERE ip_address = %s
             GROUP BY fingerprint
             ORDER BY last_seen DESC",
            $ipBin
        )) ?: [];
    }

    /**
     * Get votes cast from an IP address.
     *
     * @param string $ipBin Binary IP address.
     * @param int    $limit
     * @return array<string, mixed>
     */
    public function getVotesByIp(string $ipBin, int $limit = 50): array
    {
        global $wpdb;
        $votesTable = TableRegistry::votes();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT v.id, v.page_id, v.voter_user_id, v.vote_type, v.weight,
                    v.ip_address, v.created_at,
                    p.post_title AS page_title,
                    u.display_name AS voter_name
             FROM {$votesTable} v
             LEFT JOIN {$wpdb->posts} p ON v.page_id = p.ID
             LEFT JOIN {$wpdb->users} u ON v.voter_user_id = u.ID
             WHERE v.ip_address = %s AND v.status = 1
             ORDER BY v.created_at DESC
             LIMIT %d",
            $ipBin,
            $limit
        )) ?: [];
    }

    // ─────────────────────────────────────────────────────────────
    // Moderation — User List (moderation-list.php)
    // ─────────────────────────────────────────────────────────────

    /**
     * Count moderation users matching filters.
     *
     * @param string  $whereClause  SQL WHERE clause (e.g. "1=1 AND fraud_score >= %d").
     * @param mixed[] $params       Parameters for prepare().
     * @return int
     */
    public function getModerationUserCount(string $whereClause, array $params): int
    {
        global $wpdb;
        $userInfoTable = TableRegistry::userInfo();

        $sql = "SELECT COUNT(*) FROM {$userInfoTable} WHERE {$whereClause}";

        return (int) ($params
            ? $wpdb->get_var($wpdb->prepare($sql, $params))
            : $wpdb->get_var($sql));
    }

    /**
     * Get paginated moderation user list.
     *
     * @param string  $whereClause  SQL WHERE clause.
     * @param mixed[] $params       Parameters for prepare().
     * @param int     $perPage
     * @param int     $offset
     * @return object[]
     */
    public function getModerationUsers(string $whereClause, array $params, int $perPage, int $offset): array
    {
        global $wpdb;
        $userInfoTable   = TableRegistry::userInfo();
        $suspensionsTable = TableRegistry::suspensions();

        $sql = $wpdb->prepare(
            "SELECT ui.user_id, ui.display_name, ui.user_login, ui.user_email,
                    ui.fraud_score, ui.risk_level, ui.is_suspended, ui.is_verified,
                    ui.usr_last_activity,
                    (SELECT COUNT(*) FROM {$suspensionsTable} s
                     WHERE s.user_id = ui.user_id AND s.unsuspended_at IS NULL) AS active_suspensions
             FROM {$userInfoTable} ui
             WHERE {$whereClause}
             ORDER BY ui.fraud_score DESC
             LIMIT %d OFFSET %d",
            array_merge($params, [$perPage, $offset])
        );

        return $wpdb->get_results($sql) ?: [];
    }

    // ─────────────────────────────────────────────────────────────
    // Moderation — Device Fingerprint (moderation-device.php)
    // ─────────────────────────────────────────────────────────────

    /**
     * Get all records for a given device fingerprint.
     *
     * @param string $fingerprint
     * @return array<string, mixed>
     */
    public function getFingerprintRecords(string $fingerprint, int $limit = 500): array
    {
        global $wpdb;
        $fpTable       = TableRegistry::fingerprints();
        $userInfoTable = TableRegistry::userInfo();

        $limit = max(1, min($limit, 2000));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT f.id, f.user_id, f.fingerprint, f.automation_score,
                    f.risk_level, f.ip_address, f.first_seen, f.last_seen,
                    u.display_name AS user_name,
                    ui.fraud_score, ui.risk_level AS user_risk_level
             FROM {$fpTable} f
             LEFT JOIN {$wpdb->users} u ON f.user_id = u.ID
             LEFT JOIN {$userInfoTable} ui ON f.user_id = ui.user_id
             WHERE f.fingerprint = %s
             ORDER BY f.last_seen DESC
             LIMIT %d",
            $fingerprint,
            $limit
        )) ?: [];
    }

    // ─────────────────────────────────────────────────────────────
    // Moderation — Page List (moderation-pages.php)
    // ─────────────────────────────────────────────────────────────

    /**
     * Check if the page_flags table exists.
     *
     * @return bool
     */
    public function pageFlagsTableExists(): bool
    {
        global $wpdb;
        $flagsTable = TableRegistry::pageFlags();

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $flagsTable)) === $flagsTable;
    }

    /**
     * Get the page list count for moderation with filters.
     *
     * @param string  $whereClause  WHERE clause for the outer (subquery) filter.
     * @param mixed[] $params       Parameters for prepare().
     * @param bool    $flagsExist   Whether page_flags table exists.
     * @return int
     */
    public function getPageListCount(string $whereClause, array $params, bool $flagsExist): int
    {
        global $wpdb;

        $selectSql = $this->buildPageListSelectSql($flagsExist);
        $countSql  = "SELECT COUNT(*) FROM ({$selectSql}) AS sub WHERE {$whereClause}";

        return (int) ($params
            ? $wpdb->get_var($wpdb->prepare($countSql, $params))
            : $wpdb->get_var($countSql));
    }

    /**
     * Get the paginated page list for moderation.
     *
     * @param string $whereClause
     * @param mixed[] $params
     * @param string $sortColumn
     * @param string $order
     * @param int    $perPage
     * @param int    $offset
     * @param bool   $flagsExist
     * @return array<string, mixed>
     */
    public function getPageListData(
        string $whereClause,
        array $params,
        string $sortColumn,
        string $order,
        int $perPage,
        int $offset,
        bool $flagsExist
    ): array {
        global $wpdb;

        $selectSql = $this->buildPageListSelectSql($flagsExist);
        $dataSql   = "SELECT sub.page_id, sub.owner_id, sub.trust_score, sub.reputation_tier,
                             sub.confidence_score, sub.vote_count, sub.unique_voters,
                             sub.endorsement_count, sub.is_verified, sub.has_wallet,
                             sub.github_username, sub.last_vote_at, sub.updated_at,
                             sub.post_title, sub.owner_name, sub.flag_count
                      FROM ({$selectSql}) AS sub
                      WHERE {$whereClause}
                      ORDER BY {$sortColumn} {$order}
                      LIMIT %d OFFSET %d";

        return $wpdb->get_results(
            $wpdb->prepare($dataSql, array_merge($params, [$perPage, $offset]))
        ) ?: [];
    }

    /**
     * Build the inner SELECT for the page list query.
     *
     * @param bool $flagsExist
     * @return string
     */
    private function buildPageListSelectSql(bool $flagsExist): string
    {
        global $wpdb;

        $rmTable    = TableRegistry::pageReadModel();
        $flagsTable = TableRegistry::pageFlags();

        $flagsJoin = '';
        $flagsCol  = '0 AS flag_count';
        if ($flagsExist) {
            $flagsJoin = "LEFT JOIN (
                              SELECT page_id, COUNT(*) AS cnt
                              FROM {$flagsTable}
                              GROUP BY page_id
                          ) fc ON p.ID = fc.page_id";
            $flagsCol  = 'COALESCE(fc.cnt, 0) AS flag_count';
        }

        return "SELECT p.ID AS page_id,
                       COALESCE(rm.owner_id, p.post_author) AS owner_id,
                       COALESCE(rm.trust_score, 50.00) AS trust_score, /* 50 = BCC_TRUST_NEUTRAL_SCORE */
                       COALESCE(rm.reputation_tier, 'neutral') AS reputation_tier,
                       COALESCE(rm.confidence_score, 0) AS confidence_score,
                       COALESCE(rm.vote_count, 0) AS vote_count,
                       COALESCE(rm.unique_voters, 0) AS unique_voters,
                       COALESCE(rm.endorsement_count, 0) AS endorsement_count,
                       COALESCE(rm.is_verified, 0) AS is_verified,
                       COALESCE(rm.has_wallet, 0) AS has_wallet,
                       rm.github_username,
                       rm.last_vote_at,
                       COALESCE(rm.updated_at, p.post_modified) AS updated_at,
                       p.post_title,
                       u.display_name AS owner_name,
                       {$flagsCol}
                FROM {$wpdb->posts} p
                LEFT JOIN {$rmTable} rm ON p.ID = rm.page_id
                LEFT JOIN {$wpdb->users} u ON COALESCE(rm.owner_id, p.post_author) = u.ID
                {$flagsJoin}
                WHERE p.post_type = 'peepso-page'";
    }

    // ─────────────────────────────────────────────────────────────
    // Dashboard — Overview Tab (tab-overview.php)
    // ─────────────────────────────────────────────────────────────

    /**
     * Get today's activity summary counts for the overview tab.
     *
     * @return array<string, int> Associative array with keys: today_fraud, today_suspensions,
     *               today_verifications, today_votes, today_admin_actions.
     */
    public function getTodaySummary(): array
    {
        global $wpdb;

        $todayStart        = date('Y-m-d 00:00:00');
        $userInfoTable     = TableRegistry::userInfo();
        $suspensionsTable  = TableRegistry::suspensions();
        $verificationsTable = TableRegistry::verifications();
        $votesTable        = TableRegistry::votes();
        $activityTable     = TableRegistry::activity();

        $todayFraud = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$userInfoTable}
             WHERE fraud_score >= %d AND updated_at >= %s",
            BCC_TRUST_FRAUD_HIGH,
            $todayStart
        ));

        $todaySuspensions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$suspensionsTable}
             WHERE suspended_at >= %s AND unsuspended_at IS NULL",
            $todayStart
        ));

        $todayVerifications = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$verificationsTable}
             WHERE verified_at >= %s",
            $todayStart
        ));

        $todayVotes = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$votesTable}
             WHERE created_at >= %s AND status = 1",
            $todayStart
        ));

        $todayAdminActions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$activityTable}
             WHERE action LIKE %s AND created_at >= %s",
            'admin_%',
            $todayStart
        ));

        return [
            'today_fraud'         => $todayFraud,
            'today_suspensions'   => $todaySuspensions,
            'today_verifications' => $todayVerifications,
            'today_votes'         => $todayVotes,
            'today_admin_actions' => $todayAdminActions,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Dashboard — Activity Tab (tab-activity.php)
    // ─────────────────────────────────────────────────────────────

    /**
     * Get admin-only activity log entries.
     *
     * @param int $limit
     * @return list<object>
     */
    public function getAdminOnlyActivity(int $limit): array
    {
        global $wpdb;
        $table = TableRegistry::activity();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.user_id, a.action, a.target_id, a.target_type,
                    a.ip_address, a.created_at,
                    u.display_name AS user_name
             FROM {$table} a
             LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID
             WHERE a.action LIKE %s
             ORDER BY a.created_at DESC
             LIMIT %d",
            'admin_%',
            $limit
        )) ?: [];
    }

    // ─────────────────────────────────────────────────────────────
    // Dashboard — Verified Tab (dashboard-verified.php)
    // ─────────────────────────────────────────────────────────────

    /**
     * Count verified users matching filters (GitHub active verifications).
     *
     * @param string $whereClause
     * @param mixed[] $params
     * @return int
     */
    public function getVerifiedUsersCount(string $whereClause, array $params): int
    {
        global $wpdb;
        $verifTable    = TableRegistry::userVerifications();
        $userInfoTable = TableRegistry::userInfo();

        $sql = "SELECT COUNT(*)
                FROM {$verifTable} v
                INNER JOIN {$userInfoTable} u ON u.user_id = v.user_id
                WHERE {$whereClause}";

        return (int) (empty($params)
            ? $wpdb->get_var($sql)
            : $wpdb->get_var($wpdb->prepare($sql, $params)));
    }

    /**
     * Get verified users with enhanced data for the dashboard verified tab.
     *
     * @param string $whereClause
     * @param mixed[] $params
     * @param string $orderbyCol  Safe column reference.
     * @param string $order       ASC or DESC.
     * @param int    $perPage
     * @param int    $offset
     * @return list<object>
     */
    public function getVerifiedUsersFiltered(
        string $whereClause,
        array $params,
        string $orderbyCol,
        string $order,
        int $perPage,
        int $offset
    ): array {
        global $wpdb;
        $verifTable    = TableRegistry::userVerifications();
        $userInfoTable = TableRegistry::userInfo();

        $sql = "SELECT
                    v.user_id,
                    u.display_name,
                    u.user_email,
                    u.fraud_score,
                    u.risk_level,
                    v.provider_username  AS github_username,
                    v.provider_avatar    AS github_avatar,
                    v.meta,
                    v.verified_at        AS github_verified_at,
                    v.trust_boost,
                    v.fraud_reduction,
                    (v.trust_boost - v.fraud_reduction) AS net_impact,
                    JSON_UNQUOTE(JSON_EXTRACT(v.meta, '$.followers')) AS followers,
                    JSON_UNQUOTE(JSON_EXTRACT(v.meta, '$.public_repos')) AS public_repos,
                    JSON_UNQUOTE(JSON_EXTRACT(v.meta, '$.org_count')) AS org_count,
                    JSON_UNQUOTE(JSON_EXTRACT(v.meta, '$.account_age_days')) AS account_age_days,
                    JSON_UNQUOTE(JSON_EXTRACT(v.meta, '$.has_verified_email')) AS has_verified_email
                FROM {$verifTable} v
                INNER JOIN {$userInfoTable} u ON u.user_id = v.user_id
                WHERE {$whereClause}
                ORDER BY {$orderbyCol} {$order}
                LIMIT %d OFFSET %d";

        $queryParams = array_merge($params, [$perPage, $offset]);

        return $wpdb->get_results($wpdb->prepare($sql, $queryParams)) ?: [];
    }

    /**
     * Get summary statistics for the verified users dashboard tab.
     *
     * @return array<string, int|float> Keys: total_verified, avg_net_impact, high_quality_count, recent_verifications.
     */
    public function getVerifiedSummaryStats(): array
    {
        global $wpdb;
        $verifTable = TableRegistry::userVerifications();

        $totalVerified = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$verifTable}
             WHERE type = 'github' AND status = 'active'"
        );

        $avgNetImpact = $wpdb->get_var(
            "SELECT AVG(trust_boost - fraud_reduction) FROM {$verifTable}
             WHERE type = 'github' AND status = 'active'"
        );

        $highQualityCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$verifTable} v
             WHERE v.type = 'github' AND v.status = 'active'
             AND JSON_EXTRACT(v.meta, '$.followers') > %d",
            100
        ));

        $recentVerifications = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$verifTable} v
             WHERE v.type = 'github' AND v.status = 'active'
             AND v.verified_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        return [
            'total_verified'       => $totalVerified,
            'avg_net_impact'       => (float) ($avgNetImpact ?: 0),
            'high_quality_count'   => $highQualityCount,
            'recent_verifications' => $recentVerifications,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Verified Users Page (page-verified.php)
    // ─────────────────────────────────────────────────────────────

    /**
     * Count all verified users (any verification type, for the standalone page).
     *
     * @param string $whereSql  Additional WHERE (already prefixed with AND).
     * @param mixed[] $params
     * @return int
     */
    public function getAllVerifiedUsersCount(string $whereSql, array $params): int
    {
        global $wpdb;
        $verifTable = TableRegistry::userVerifications();

        $baseSql = $this->buildAllVerifiedBaseSql();
        $countSql = "SELECT COUNT(DISTINCT u.ID) {$baseSql} {$whereSql}";

        return empty($params)
            ? (int) $wpdb->get_var($countSql)
            : (int) $wpdb->get_var($wpdb->prepare($countSql, $params));
    }

    /**
     * Get all verified users with pivoted verification data for the standalone page.
     *
     * @param string $whereSql    Additional WHERE (already prefixed with AND).
     * @param mixed[] $params
     * @param string $orderbyCol  Safe column reference.
     * @param string $order       ASC or DESC.
     * @param int    $perPage
     * @param int    $offset
     * @return list<object>
     */
    public function getAllVerifiedUsersFiltered(
        string $whereSql,
        array $params,
        string $orderbyCol,
        string $order,
        int $perPage,
        int $offset
    ): array {
        global $wpdb;
        $verifTable = TableRegistry::userVerifications();

        $baseSql = $this->buildAllVerifiedBaseSql();

        $selectSql = "
            SELECT
                u.ID            AS user_id,
                u.display_name,
                u.user_email,
                gh.provider_username                        AS github_username,
                JSON_UNQUOTE(JSON_EXTRACT(gh.meta, '$.has_verified_email')) AS github_verified_email_flag,
                eth.meta                                    AS eth_meta,
                sol.meta                                    AS sol_meta,
                cos.meta                                    AS cos_meta
            {$baseSql} {$whereSql}
            ORDER BY {$orderbyCol} {$order}
            LIMIT %d OFFSET %d
        ";

        $queryParams = array_merge($params, [$perPage, $offset]);

        // LIMIT %d OFFSET %d is in the $selectSql built above; the final
        // two placeholders are $perPage and $offset. Bounded query.
        $results = empty($params)
            ? $wpdb->get_results($wpdb->prepare($selectSql, [$perPage, $offset]))
            : $wpdb->get_results($wpdb->prepare($selectSql, $queryParams));

        return $results ?: [];
    }

    /**
     * Get verification type counts for the standalone page summary.
     *
     * @return array<string, int> Keys: github, wallet_ethereum, wallet_solana, wallet_cosmos.
     */
    public function getVerificationTypeCounts(): array
    {
        global $wpdb;
        $verifTable = TableRegistry::userVerifications();

        $types = ['github', 'wallet_ethereum', 'wallet_solana', 'wallet_cosmos'];
        $counts = [];

        foreach ($types as $type) {
            $counts[$type] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$verifTable}
                 WHERE type = %s AND status = 'active'",
                $type
            ));
        }

        return $counts;
    }

    /**
     * Build the shared FROM/JOIN/WHERE base for the all-verified users query.
     *
     * @return string
     */
    private function buildAllVerifiedBaseSql(): string
    {
        global $wpdb;
        $verifTable = TableRegistry::userVerifications();

        return "
            FROM {$wpdb->users} u
            INNER JOIN (
                SELECT DISTINCT user_id
                FROM {$verifTable}
                WHERE status = 'active'
            ) vany ON vany.user_id = u.ID
            LEFT JOIN {$verifTable} gh
                ON gh.user_id = u.ID AND gh.type = 'github'     AND gh.status = 'active'
            LEFT JOIN {$verifTable} eth
                ON eth.user_id = u.ID AND eth.type = 'wallet_ethereum' AND eth.status = 'active'
            LEFT JOIN {$verifTable} sol
                ON sol.user_id = u.ID AND sol.type = 'wallet_solana'   AND sol.status = 'active'
            LEFT JOIN {$verifTable} cos
                ON cos.user_id = u.ID AND cos.type = 'wallet_cosmos'   AND cos.status = 'active'
            WHERE 1=1
        ";
    }

    // ─────────────────────────────────────────────────────────────
    // Debug Panel — Page Debug Data (TrustDebugService)
    // ─────────────────────────────────────────────────────────────

    /**
     * Get votes for a page with optional filter, voter search, and enriched
     * data (user display name, fraud score, trust rank, reputation tier).
     *
     * @param int         $pageId
     * @param string      $filter      'recent' | 'top_weight' | 'suspicious' | 'all'
     * @param int         $limit
     * @param string|null $searchVoter Optional voter ID or display name search.
     * @return array{items: list<object>, total: int, filter: string, limit: int}
     */
    public function getDebugVotes(int $pageId, string $filter = 'recent', int $limit = 10, ?string $searchVoter = null): array
    {
        global $wpdb;

        $votesTable = TableRegistry::votes();
        $userInfo   = TableRegistry::userInfo();

        $where = $wpdb->prepare("v.page_id = %d AND v.status = 1", $pageId);

        if ($searchVoter) {
            $where .= $wpdb->prepare(
                " AND (v.voter_user_id = %d OR u.display_name LIKE %s)",
                (int) $searchVoter,
                '%' . $wpdb->esc_like($searchVoter) . '%'
            );
        }

        switch ($filter) {
            case 'top_weight':
                $orderBy = 'v.vested_weight DESC';
                break;
            case 'suspicious':
                $where  .= $wpdb->prepare(" AND ui.fraud_score >= %d", BCC_TRUST_FRAUD_MEDIUM);
                $orderBy = 'ui.fraud_score DESC';
                break;
            case 'all':
                $orderBy = 'v.created_at DESC';
                $limit   = 500;
                break;
            case 'recent':
            default:
                $orderBy = 'v.created_at DESC';
                break;
        }

        // LIMIT %d at end of the prepared SQL; $limit is the sole placeholder.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT v.id, v.voter_user_id, v.vote_type, v.weight, v.vested_weight,
                    v.vesting_stage, v.fraud_score_at_vote, v.created_at,
                    u.display_name AS voter_name,
                    ui.fraud_score AS voter_fraud_score,
                    ui.trust_rank AS voter_trust_rank,
                    r.reputation_tier AS voter_tier
             FROM {$votesTable} v
             LEFT JOIN {$wpdb->users} u ON v.voter_user_id = u.ID
             LEFT JOIN {$userInfo} ui ON v.voter_user_id = ui.user_id
             LEFT JOIN " . TableRegistry::reputation() . " r ON v.voter_user_id = r.user_id
             WHERE {$where}
             ORDER BY {$orderBy}
             LIMIT %d",
            $limit
        ));

        $totalCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$votesTable} WHERE page_id = %d AND status = 1",
            $pageId
        ));

        return [
            'items'  => $rows ?: [],
            'total'  => $totalCount,
            'filter' => $filter,
            'limit'  => $limit,
        ];
    }

    /**
     * Get active verifications for a user (GitHub, X, wallets).
     *
     * @param int $userId
     * @return list<object>  Rows with type, provider_id, trust_boost, fraud_reduction, status, verified_at, meta.
     * @phpstan-return list<object{
     *   type: string,
     *   provider_id: string|null,
     *   trust_boost: float|numeric-string|null,
     *   fraud_reduction: int|numeric-string|null,
     *   status: string,
     *   verified_at: string|null,
     *   meta: string|null
     * }>
     */
    public function getDebugVerifications(int $userId): array
    {
        global $wpdb;

        $table = TableRegistry::userVerifications();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT type, provider_id, trust_boost, fraud_reduction, status, verified_at, meta
             FROM {$table}
             WHERE user_id = %d AND status = 'active'",
            $userId
        )) ?: [];
    }

    /**
     * Get active endorsements for a page with endorser display names.
     *
     * @param int $pageId
     * @return list<object>  Rows with endorser_user_id, weight, vesting_stage, context, created_at, endorser_name.
     * @phpstan-return list<object{
     *   endorser_user_id: int|numeric-string,
     *   weight: float|numeric-string,
     *   vesting_stage: int|numeric-string,
     *   context: string,
     *   created_at: string,
     *   endorser_name: string|null
     * }>
     */
    public function getDebugEndorsements(int $pageId): array
    {
        global $wpdb;

        $table = TableRegistry::endorsements();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.endorser_user_id, e.weight, e.vesting_stage, e.context, e.created_at,
                    u.display_name AS endorser_name
             FROM {$table} e
             LEFT JOIN {$wpdb->users} u ON e.endorser_user_id = u.ID
             WHERE e.page_id = %d AND e.status = 1
             ORDER BY e.created_at DESC
             LIMIT 50",
            $pageId
        )) ?: [];
    }

    /**
     * Get recent vote events for a page (timeline).
     *
     * @param int $pageId
     * @param int $limit
     * @return list<object>  Rows with event_type, actor_id, action, weight, created_at.
     * @phpstan-return list<object{
     *   event_type: string,
     *   actor_id: int|numeric-string,
     *   action: string,
     *   weight: float|numeric-string,
     *   created_at: string
     * }>
     */
    public function getDebugVoteTimeline(int $pageId, int $limit): array
    {
        global $wpdb;

        $votesTable = TableRegistry::votes();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT 'vote' AS event_type, voter_user_id AS actor_id,
                    CASE WHEN vote_type = 1 THEN 'upvote' ELSE 'downvote' END AS action,
                    vested_weight AS weight, created_at
             FROM {$votesTable}
             WHERE page_id = %d AND status = 1
             ORDER BY created_at DESC
             LIMIT %d",
            $pageId,
            $limit
        )) ?: [];
    }

    /**
     * Get recent endorsement events for a page (timeline).
     *
     * @param int $pageId
     * @param int $limit
     * @return list<object>  Rows with event_type, actor_id, action, weight, created_at.
     * @phpstan-return list<object{
     *   event_type: string,
     *   actor_id: int|numeric-string,
     *   action: string,
     *   weight: float|numeric-string,
     *   created_at: string
     * }>
     */
    public function getDebugEndorsementTimeline(int $pageId, int $limit): array
    {
        global $wpdb;

        $endorseTable = TableRegistry::endorsements();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT 'endorsement' AS event_type, endorser_user_id AS actor_id,
                    'endorsed' AS action, weight, created_at
             FROM {$endorseTable}
             WHERE page_id = %d AND status = 1
             ORDER BY created_at DESC
             LIMIT %d",
            $pageId,
            $limit
        )) ?: [];
    }
}
