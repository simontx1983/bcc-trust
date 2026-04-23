<?php
/**
 * Fraud Analysis Repository
 * 
 * Handles storage and retrieval of fraud analysis results
 * 
 * @package BCC\Trust\Core\Repositories
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Exceptions\RepositoryException;

if (!defined('ABSPATH')) exit;

/**
 * Row + aggregate shapes returned by bcc_trust_fraud_analysis reads. Methods
 * that decode the JSON-encoded `triggers` / `details` columns return them
 * as arrays; callers that read without decoding get the raw strings.
 *
 * @phpstan-type FraudAnalysisRow object{
 *   id: int|numeric-string,
 *   user_id: int|numeric-string,
 *   fraud_score: int|numeric-string,
 *   risk_level: string,
 *   confidence: float|numeric-string,
 *   triggers: list<string>,
 *   details: array<string, mixed>,
 *   analyzed_at: string,
 *   expires_at: string|null
 * }
 * @phpstan-type FraudAnalysisJoinRow object{
 *   id: int|numeric-string,
 *   user_id: int|numeric-string,
 *   fraud_score: int|numeric-string,
 *   risk_level: string,
 *   confidence: float|numeric-string,
 *   triggers: list<string>,
 *   details: array<string, mixed>,
 *   analyzed_at: string,
 *   expires_at: string|null,
 *   display_name: string|null,
 *   user_email?: string|null,
 *   recent_analyses?: int
 * }
 * @phpstan-type FraudAnalysisStats object{
 *   total_analyses: int|numeric-string,
 *   unique_users_analyzed: int|numeric-string,
 *   avg_fraud_score: float|numeric-string|null,
 *   max_fraud_score: int|numeric-string|null,
 *   critical_count: int|numeric-string,
 *   high_count: int|numeric-string,
 *   medium_count: int|numeric-string,
 *   low_count: int|numeric-string,
 *   minimal_count: int|numeric-string,
 *   expired_count: int|numeric-string,
 *   last_analysis: string|null
 * }
 * @phpstan-type FraudScoreTrendRow object{
 *   analysis_date: string,
 *   avg_score: float|numeric-string,
 *   max_score: int|numeric-string,
 *   analysis_count: int|numeric-string
 * }
 * @phpstan-type FraudOrphanRow object{id: int|numeric-string, user_id: int|numeric-string}
 */
class FraudAnalysisRepository {

    private string $table;
    
    public function __construct() {
        $this->table = \BCC\Trust\Core\Database\TableRegistry::fraudAnalysis();
    }
    
    /**
     * Store fraud analysis result
     * 
     * @param int                   $userId
     * @param int                   $fraudScore
     * @param string                $riskLevel
     * @param float                 $confidence
     * @param string[]              $triggers
     * @param array<string, mixed>  $details
     * @param string|null           $expiresAt
     * @return int Inserted ID
     */
    public function storeAnalysis(
        int $userId, 
        int $fraudScore, 
        string $riskLevel, 
        float $confidence, 
        array $triggers, 
        array $details = [], 
        ?string $expiresAt = null
    ): int {
        global $wpdb;
        
        // Use config constant for default expiration if not provided
        if ($expiresAt === null) {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . BCC_TRUST_CLEANUP_FRAUD_ANALYSIS . ' days'));
        }
        
        $result = $wpdb->insert(
            $this->table,
            [
                'user_id' => $userId,
                'fraud_score' => $fraudScore,
                'risk_level' => $riskLevel,
                'confidence' => $confidence,
                'triggers' => json_encode($triggers),
                'details' => !empty($details) ? json_encode($details) : null,
                'analyzed_at' => current_time('mysql'),
                'expires_at' => $expiresAt
            ],
            ['%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s']
        );
        if ($result === false) {
            throw new RepositoryException('FraudAnalysisRepository::storeAnalysis failed: ' . $wpdb->last_error);
        }

        return $wpdb->insert_id;
    }
    
    /**
     * Store fraud analysis with automatic risk level determination
     * 
     * @param int                   $userId
     * @param int                   $fraudScore
     * @param string[]              $triggers
     * @param array<string, mixed>  $details
     * @return int Inserted ID
     */
    public function storeAnalysisWithAutoRisk(int $userId, int $fraudScore, array $triggers, array $details = []): int {
        $riskLevel = $this->determineRiskLevel($fraudScore);
        $confidence = $this->calculateConfidence($fraudScore, $triggers);
        
        return $this->storeAnalysis(
            $userId,
            $fraudScore,
            $riskLevel,
            $confidence,
            $triggers,
            $details
        );
    }
    
    /**
     * Determine risk level based on fraud score.
     *
     * Delegates to FraudClassification::level() — the single source of truth.
     */
    private function determineRiskLevel(int $fraudScore): string {
        return \BCC\Trust\Core\Support\FraudClassification::level($fraudScore);
    }
    
    /**
     * Calculate confidence based on fraud score and triggers
     *
     * @param string[] $triggers
     */
    private function calculateConfidence(int $fraudScore, array $triggers): float {
        // Base confidence on fraud score
        $scoreConfidence = min(1.0, $fraudScore / 100);
        
        // Boost confidence based on number of triggers
        $triggerCount = count($triggers);
        $triggerBoost = min(0.3, $triggerCount * 0.1);
        
        // Combine and cap
        $confidence = min(1.0, $scoreConfidence + $triggerBoost);
        
        return round($confidence, 2);
    }
    
    /**
     * Get users with high fraud scores
     *
     * @param int $threshold
     * @param int $limit
     * @return object[]
     * @phpstan-return list<FraudAnalysisJoinRow>
     */
    public function getHighRiskUsers(int $threshold = BCC_TRUST_FRAUD_HIGH, int $limit = 100): array {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT fa.id, fa.user_id, fa.fraud_score, fa.risk_level, fa.confidence, fa.triggers, fa.details, fa.analyzed_at, fa.expires_at, u.display_name, u.user_email,
                    (SELECT COUNT(*) FROM {$this->table} f2
                     WHERE f2.user_id = fa.user_id
                     AND f2.analyzed_at > DATE_SUB(NOW(), INTERVAL 30 DAY)) as recent_analyses
             FROM {$this->table} fa
             INNER JOIN (
                 SELECT user_id, MAX(analyzed_at) as max_analyzed
                 FROM {$this->table}
                 WHERE fraud_score >= %d
                 GROUP BY user_id
             ) latest ON fa.user_id = latest.user_id AND fa.analyzed_at = latest.max_analyzed
             LEFT JOIN {$wpdb->users} u ON fa.user_id = u.ID
             ORDER BY fa.fraud_score DESC
             LIMIT %d",
            $threshold,
            $limit
        ));
        
        // Decode JSON fields
        foreach ($results as &$row) {
            $row->triggers = json_decode($row->triggers, true);
            $row->details = !empty($row->details) ? json_decode($row->details, true) : [];
            $row->recent_analyses = (int) ($row->recent_analyses ?? 0);
        }
        
        return $results;
    }
    
    /**
     * Delete old analyses using config constant
     * 
     * @return int Number of rows deleted
     */
    public function deleteExpired(): int {
        global $wpdb;

        // Batched DELETE so a table-wide cleanup cannot DDL-lock the
        // fraud_analysis table on large installs. Each batch caps its
        // work at $batchSize and loops until no rows match or the hard
        // ceiling is hit.
        $retentionDays = BCC_TRUST_CLEANUP_FRAUD_ANALYSIS;
        $batchSize     = (int) apply_filters('bcc_trust_fraud_analysis_cleanup_batch', 5000);
        $maxLoops      = 40;
        $total         = 0;

        for ($i = 0; $i < $maxLoops; $i++) {
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$this->table}
                     WHERE expires_at < NOW()
                        OR analyzed_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                     LIMIT %d",
                    $retentionDays,
                    $batchSize
                )
            );

            if ($deleted === false) {
                throw new RepositoryException('FraudAnalysisRepository::deleteExpired failed: ' . $wpdb->last_error);
            }

            $total += (int) $deleted;

            if ((int) $deleted < $batchSize) {
                break;
            }
        }

        $deleted = $total;

        return $deleted;
    }
    
    /**
     * Delete analyses for a specific user
     * 
     * @param int $userId
     * @return bool
     */
    /**
     * Delete analyses for a specific user.
     *
     * @throws RepositoryException on database failure
     */
    public function deleteForUser(int $userId): void {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table,
            ['user_id' => $userId],
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('FraudAnalysisRepository::deleteForUser failed: ' . $wpdb->last_error);
        }
    }
    
    /**
     * Count orphaned fraud analysis records (user no longer exists).
     */
    public function countOrphaned(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} fa
             LEFT JOIN {$wpdb->users} u ON fa.user_id = u.ID
             WHERE u.ID IS NULL AND %d",
            1
        ));
    }

    /**
     * Delete orphaned fraud analysis records (user no longer exists).
     *
     * @return int Number of rows deleted.
     */
    public function deleteOrphaned(): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE fa FROM {$this->table} fa
             LEFT JOIN {$wpdb->users} u ON fa.user_id = u.ID
             WHERE u.ID IS NULL AND %d",
            1
        ));
    }

    /**
     * Get orphaned fraud analysis records — batch paginated.
     *
     * SAFETY: previously unbounded; after GDPR purges the orphan set can
     * exceed hundreds of thousands of rows. Callers must loop until the
     * returned batch is smaller than $limit.
     *
     * @param int $limit Max rows per batch (default 500, capped at 5000).
     * @return object[]
     * @phpstan-return list<FraudOrphanRow>
     */
    public function getOrphaned(int $limit = 500): array
    {
        global $wpdb;

        $limit = max(1, min($limit, 5000));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT fa.id, fa.user_id
             FROM {$this->table} fa
             LEFT JOIN {$wpdb->users} u ON fa.user_id = u.ID
             WHERE u.ID IS NULL
             ORDER BY fa.id ASC
             LIMIT %d",
            $limit
        )) ?: [];
    }

    /**
     * Delete a single analysis record by ID.
     */
    public function deleteById(int $id): bool
    {
        global $wpdb;

        return $wpdb->delete(
            $this->table,
            ['id' => $id],
            ['%d']
        ) !== false;
    }

    /**
     * Count old analysis records past the retention period.
     */
    public function countOld(int $retentionDays): int
    {
        global $wpdb;

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days") ?: 0);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE analyzed_at < %s",
            $cutoff
        ));
    }

    /**
     * Delete expired fraud analysis records (for cron cleanup).
     *
     * @return int Number of rows deleted.
     */
    public function deleteExpiredRecords(): int
    {
        global $wpdb;

        return (int) $wpdb->query(
            "DELETE FROM {$this->table} WHERE expires_at IS NOT NULL AND expires_at < NOW()"
        );
    }

    /**
     * Check if the fraud analysis table exists.
     *
     * @return bool True if the table exists.
     */
    public function tableExists(): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table)) === $this->table;
    }
}