<?php
/**
 * Device Fingerprint Repository
 *
 * Handles all database operations for the fingerprints table.
 *
 * @package BCC\Trust\Core\Repositories
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Exceptions\RepositoryException;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Row shapes returned by bcc_trust_device_fingerprints reads.
 *
 * @phpstan-type FingerprintUserRow object{
 *   id: int|numeric-string,
 *   user_id: int|numeric-string,
 *   fingerprint: string,
 *   automation_score: int|numeric-string,
 *   risk_level: string|null,
 *   last_seen: string
 * }
 * @phpstan-type FingerprintSharedUserRow object{
 *   user_id: int|numeric-string,
 *   first_seen: string,
 *   last_seen: string,
 *   automation_score: int|numeric-string,
 *   risk_level: string|null
 * }
 * @phpstan-type FingerprintIdRow object{id: int|numeric-string}
 * @phpstan-type FingerprintUserIdRow object{user_id: int|numeric-string}
 */
class DeviceFingerprintRepository {

    private string $table;

    public function __construct() {
        $this->table = \BCC\Trust\Core\Database\TableRegistry::fingerprints();
    }

    // -------------------------------------------------------------------------
    // Read methods
    // -------------------------------------------------------------------------

    /**
     * Check if the fingerprints table exists.
     */
    public function tableExists(): bool {
        return \BCC\Trust\Core\Database\TableRegistry::exists( $this->table );
    }

    /**
     * Get all fingerprints for a user.
     *
     * @param int $userId
     * @return object[]
     * @phpstan-return list<FingerprintUserRow>
     */
    public function getUserFingerprints( int $userId ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id, fingerprint, automation_score, risk_level, last_seen
             FROM {$this->table}
             WHERE user_id = %d
             ORDER BY last_seen DESC
             LIMIT 100",
            $userId
        ) );
    }

    /**
     * Get all users sharing a specific fingerprint.
     *
     * @param string $fingerprint
     * @return object[]
     * @phpstan-return list<FingerprintSharedUserRow>
     */
    public function getUsersByFingerprint( string $fingerprint ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT user_id, first_seen, last_seen, automation_score, risk_level
             FROM {$this->table}
             WHERE fingerprint = %s
             ORDER BY last_seen DESC
             LIMIT 100",
            $fingerprint
        ) );
    }

    /**
     * Count distinct users associated with a fingerprint.
     *
     * @param string $fingerprint
     * @return int
     */
    public function getFingerprintUserCount( string $fingerprint ): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$this->table}
             WHERE fingerprint = %s",
            $fingerprint
        ) );
    }

    /**
     * Batch-fetch user counts for multiple fingerprints in a single query.
     *
     * @param  string[] $fingerprints Fingerprint hashes.
     * @return array<string, int>     Map of fingerprint => distinct user count.
     */
    public function getFingerprintUserCounts( array $fingerprints ): array {
        global $wpdb;

        if ( empty( $fingerprints ) ) {
            return [];
        }

        $fingerprints = array_unique( $fingerprints );
        $placeholders = implode( ',', array_fill( 0, count( $fingerprints ), '%s' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT fingerprint, COUNT(DISTINCT user_id) AS user_count
             FROM {$this->table}
             WHERE fingerprint IN ({$placeholders})
             GROUP BY fingerprint",
            $fingerprints
        ) );

        $counts = [];
        foreach ( $rows as $row ) {
            $counts[ $row->fingerprint ] = (int) $row->user_count;
        }

        // Fill in zeros for any fingerprints with no matches.
        foreach ( $fingerprints as $fp ) {
            if ( ! isset( $counts[ $fp ] ) ) {
                $counts[ $fp ] = 0;
            }
        }

        return $counts;
    }

    /**
     * Find existing fingerprint record for a user + fingerprint pair.
     *
     * @param int    $userId
     * @param string $fingerprint
     * @return object|null Row with id column, or null.
     * @phpstan-return FingerprintIdRow|null
     */
    public function findByUserAndFingerprint( int $userId, string $fingerprint ): ?object {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE user_id = %d AND fingerprint = %s",
            $userId,
            $fingerprint
        ) );
    }

    /**
     * Find distinct users sharing a fingerprint, excluding a given user.
     *
     * @param string $fingerprint
     * @param int    $excludeUserId
     * @return object[]
     * @phpstan-return list<FingerprintUserIdRow>
     */
    public function getOtherUsersForFingerprint( string $fingerprint, int $excludeUserId ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$this->table}
             WHERE fingerprint = %s AND user_id != %d
             LIMIT 100",
            $fingerprint,
            $excludeUserId
        ) );
    }

    // -------------------------------------------------------------------------
    // Write methods
    // -------------------------------------------------------------------------

    /**
     * Insert a new fingerprint record.
     *
     * @param array<string, mixed> $data   Associative column => value array.
     * @param string[]             $formats wpdb format specifiers.
     * @return int|false Insert ID or false on failure.
     */
    public function insert( array $data, array $formats ): int|false {
        global $wpdb;

        $result = $wpdb->insert( $this->table, $data, $formats );
        if ( $result === false ) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Update an existing fingerprint record by ID.
     *
     * @param int                   $id
     * @param array<string, mixed>  $data    Column => value pairs.
     * @param string[]              $formats Format specifiers for $data.
     * @return int|false Number of rows updated, or false on error.
     */
    public function updateById( int $id, array $data, array $formats ): int|false {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            $data,
            [ 'id' => $id ],
            $formats,
            [ '%d' ]
        );
    }

    /**
     * Upsert a server-side fingerprint record (insert or update last_seen).
     *
     * @param int    $userId
     * @param string $fingerprint
     * @param string $ipHash
     * @param string $userAgent
     */
    public function upsertServerFingerprint( int $userId, string $fingerprint, string $ipHash, string $userAgent ): void {
        global $wpdb;

        $existing = $this->findByUserAndFingerprint( $userId, $fingerprint );

        if ( $existing ) {
            $wpdb->update(
                $this->table,
                [ 'last_seen' => current_time( 'mysql' ), 'ip_address' => $ipHash ],
                [ 'id' => (int) $existing->id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        } else {
            $wpdb->insert(
                $this->table,
                [
                    'user_id'     => $userId,
                    'fingerprint' => $fingerprint,
                    'first_seen'  => current_time( 'mysql' ),
                    'last_seen'   => current_time( 'mysql' ),
                    'ip_address'  => $ipHash,
                    'user_agent'  => $userAgent,
                    'risk_level'  => 'low',
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
        }
    }

    /**
     * Delete fingerprint records older than the given cutoff date.
     *
     * @param string $cutoffDate MySQL datetime string.
     * @return int Number of deleted records.
     */
    public function deleteOlderThan( string $cutoffDate ): int {
        global $wpdb;

        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->table} WHERE last_seen < %s",
            $cutoffDate
        ) );
    }

    /**
     * Delete all fingerprint records for a user.
     *
     * @return int Number of rows deleted.
     */
    public function deleteForUser( int $userId ): int {
        global $wpdb;

        $result = $wpdb->delete( $this->table, [ 'user_id' => $userId ], [ '%d' ] );

        return ( $result !== false ) ? (int) $result : 0;
    }

    /**
     * Delete high-automation fingerprints older than a cutoff date.
     *
     * @return int Number of rows deleted.
     */
    public function deleteHighAutomationOlderThan( int $automationThreshold, string $cutoff ): int {
        global $wpdb;

        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->table}
             WHERE automation_score >= %d
             AND last_seen < %s",
            $automationThreshold,
            $cutoff
        ) );
    }
}
