<?php
namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Exceptions\RepositoryException;

if (!defined('ABSPATH')) exit;

class PatternRepository {

    /** Explicit column list for bcc_trust_patterns table. */
    private const COLUMNS = 'id, user_id, pattern_type, pattern_data, confidence, detected_at, expires_at';

    private string $table;
    
    public function __construct() {
        $this->table = \BCC\Trust\Core\Database\TableRegistry::patterns();
    }
    
    /**
     * Store behavioral pattern
     *
     * @param array<string, mixed> $data
     */
    public function storePattern(int $userId, string $type, array $data, float $confidence = 1.0, ?string $expiresAt = null): int {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->table,
            [
                'user_id' => $userId,
                'pattern_type' => $type,
                'pattern_data' => json_encode($data),
                'confidence' => $confidence,
                'detected_at' => current_time('mysql'),
                'expires_at' => $expiresAt ?? date('Y-m-d H:i:s', strtotime('+30 days'))
            ],
            ['%d', '%s', '%s', '%f', '%s', '%s']
        );

        if ( $result === false ) {
            throw new RepositoryException( 'PatternRepository::storePattern failed: ' . $wpdb->last_error );
        }

        return $wpdb->insert_id;
    }
    
    /**
     * Get patterns for user
     *
     * @return list<object>
     */
    public function getUserPatterns(int $userId, ?string $type = null, int $limit = 50): array {
        global $wpdb;
        
        $sql = "SELECT " . self::COLUMNS . " FROM {$this->table} WHERE user_id = %d";
        $params = [$userId];
        
        if ($type) {
            $sql .= " AND pattern_type = %s";
            $params[] = $type;
        }
        
        $sql .= " ORDER BY detected_at DESC LIMIT %d";
        $params[] = $limit;
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    /**
     * Get most common pattern types
     *
     * @return list<object>
     */
    public function getMostCommonTypes(int $limit = 10): array {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT pattern_type, COUNT(*) as count, AVG(confidence) as avg_confidence
             FROM {$this->table}
             GROUP BY pattern_type
             ORDER BY count DESC
             LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Get most common behavior flags
     *
     * @return array<string, int>
     */
    public function getMostCommonFlags(int $limit = 10): array {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT pattern_data FROM {$this->table}
             WHERE pattern_type = 'suspicious_behavior'
             ORDER BY detected_at DESC
             LIMIT %d",
            $limit
        ));
        
        $flagCounts = [];
        foreach ($results as $row) {
            $data = json_decode($row->pattern_data, true);
            if (isset($data['flags']) && is_array($data['flags'])) {
                foreach ($data['flags'] as $flag) {
                    $flagCounts[$flag] = ($flagCounts[$flag] ?? 0) + 1;
                }
            }
        }
        
        arsort($flagCounts);
        return array_slice($flagCounts, 0, $limit, true);
    }
    
    /**
     * Delete expired patterns
     */
    public function deleteExpired(): int {
        global $wpdb;

        // No user-supplied parameters; table name is from \BCC\Trust\Core\Database\TableRegistry::patterns()
        // which returns $wpdb->prefix . 'bcc_trust_patterns' (trusted source).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->query(
            "DELETE FROM {$this->table}
             WHERE expires_at < NOW()"
        );
    }

    /**
     * Delete expired patterns with a custom cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function deleteExpiredWithCutoff(string $cutoff): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE expires_at < %s OR (expires_at IS NULL AND detected_at < %s)",
            current_time('mysql'),
            $cutoff
        ));
    }

    /**
     * Check if the patterns table exists.
     */
    public function tableExists(): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table)) === $this->table;
    }
}