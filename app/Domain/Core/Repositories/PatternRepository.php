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
     * Per-type pattern counts within a rolling time window.
     *
     * Drives the §O5 operator-journal Ecosystem tab — surfaces which
     * pattern classes are firing this week without requiring the
     * operator to query the patterns table directly. Composes cleanly
     * on the existing pattern_type allowlist (vote_ring,
     * endorsement_ring, slow_endorsement_ring, suspicious_behavior, ...)
     * — caller supplies the type list it cares about.
     *
     * Bounded: type-list whitelist + windowed `detected_at`. Empty
     * `$types` short-circuits to an empty map.
     *
     * @param list<string> $types Allowlist (typically vote/endorse ring families).
     * @param int          $days  Rolling window in days. Caller-bounded.
     * @return array<string, int> pattern_type => count
     */
    public function getCountsByTypesSince(array $types, int $days = 7): array {
        $cleanTypes = [];
        foreach ($types as $t) {
            if (is_string($t) && $t !== '') {
                $cleanTypes[] = $t;
            }
        }
        if ($cleanTypes === []) {
            return [];
        }

        $days = max(1, $days);

        global $wpdb;
        $typePlaceholders = implode(',', array_fill(0, count($cleanTypes), '%s'));

        $params = $cleanTypes;
        $params[] = $days;

        /** @var list<array{pattern_type: string, count: string|int}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pattern_type, COUNT(*) AS count
             FROM {$this->table}
             WHERE pattern_type IN ({$typePlaceholders})
               AND detected_at > DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY pattern_type",
            ...$params
        ), ARRAY_A);

        // Default every requested type to 0 so the caller can render a
        // stable order without presence-guarding each row.
        $out = [];
        foreach ($cleanTypes as $t) {
            $out[$t] = 0;
        }
        foreach (($rows ?: []) as $row) {
            $type = (string) ($row['pattern_type'] ?? '');
            if ($type !== '' && isset($out[$type])) {
                $out[$type] = (int) ($row['count'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Latest pattern row for a given type — used by the operator
     * journal to surface "most recent slow-ring detection" with its
     * member-list metadata for one-glance review.
     *
     * Returns null when no rows match. Bounded by indexed lookup on
     * pattern_type + ORDER BY detected_at DESC LIMIT 1.
     *
     * @return object|null
     */
    public function getLatestByType(string $type): ?object {
        if ($type === '') {
            return null;
        }

        global $wpdb;
        /** @var object|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . "
             FROM {$this->table}
             WHERE pattern_type = %s
             ORDER BY detected_at DESC
             LIMIT 1",
            $type
        ));
        return $row ?: null;
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