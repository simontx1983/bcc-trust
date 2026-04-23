<?php

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\DTO\RowAssert;
use BCC\Trust\Core\DTO\XConnectionDTO;
use BCC\Trust\Core\Exceptions\RepositoryException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * X (Twitter) Repository
 *
 * Reads and writes X verification data from the
 * bcc_trust_user_verifications table (type = 'x').
 */
class XRepository {

    private string $table;

    public function __construct() {
        $this->table = \BCC\Trust\Core\Database\TableRegistry::userVerifications();
    }

    /*
    |--------------------------------------------------------------------------
    | TOKEN ENCRYPTION
    |--------------------------------------------------------------------------
    */

    private function encryptToken(string $token): string {
        $encrypted = \BCC\Trust\Core\Support\Encryption::encrypt($token);
        if ($encrypted !== '') {
            return $encrypted;
        }

        return wp_hash($token);
    }

    private function decryptToken(string $data): ?string {
        $decrypted = \BCC\Trust\Core\Support\Encryption::decrypt($data);
        if ($decrypted !== '') {
            return $decrypted;
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE CONNECTION
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $xData
     * @throws RepositoryException on database failure
     */
    public function saveConnection(int $userId, array $xData, string $accessToken): void {

        global $wpdb;

        $encryptedToken = $this->encryptToken($accessToken);

        $meta = json_encode([
            'email' => $xData['email'] ?? null,
        ]);

        $data = [
            'user_id'           => $userId,
            'type'              => 'x',
            'provider_id'       => $xData['id'] ?? null,
            'provider_username' => $xData['username'] ?? null,
            'provider_avatar'   => $xData['profile_image_url'] ?? null,
            'meta'              => $meta,
            'access_token'      => $encryptedToken,
            'status'            => 'active',
            'verified_at'       => current_time('mysql'),
            'last_synced'       => current_time('mysql'),
        ];

        // Prevent the same X account from being linked to multiple WP users.
        $providerId = $xData['id'] ?? null;
        if ($providerId !== null) {
            $existingOwner = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$this->table} WHERE type = 'x' AND provider_id = %s AND user_id != %d LIMIT 1",
                (string) $providerId,
                $userId
            ));
            if ($existingOwner) {
                throw new RepositoryException('This X account is already linked to another user.');
            }
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE user_id = %d AND type = 'x'",
            $userId
        ));

        if ($exists) {
            $result = $wpdb->update(
                $this->table,
                $data,
                ['user_id' => $userId, 'type' => 'x'],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
                ['%d', '%s']
            );
        } else {
            $result = $wpdb->insert(
                $this->table,
                $data,
                ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
        }

        if ($result === false) {
            throw new RepositoryException('XRepository::saveConnection failed for user ' . $userId . ': ' . $wpdb->last_error);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET CONNECTION
    |--------------------------------------------------------------------------
    */

    public function getConnection(int $userId): ?XConnectionDTO {

        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                provider_id         AS x_id,
                provider_username   AS x_username,
                provider_avatar     AS x_avatar,
                trust_boost         AS x_trust_boost,
                fraud_reduction     AS x_fraud_reduction,
                access_token        AS x_access_token,
                verified_at         AS x_verified_at,
                last_synced         AS x_last_synced,
                meta
            FROM {$this->table}
            WHERE user_id = %d AND type = 'x' AND status = 'active'",
            $userId
        ), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        // Decode meta JSON — tolerate missing/malformed blobs for legacy rows.
        $metaRaw = $row['meta'] ?? null;
        $meta    = is_string($metaRaw) && $metaRaw !== '' ? json_decode($metaRaw, true) : [];
        if (!is_array($meta)) {
            $meta = [];
        }
        $emailRaw = $meta['email'] ?? null;
        $email    = is_string($emailRaw) && $emailRaw !== '' ? $emailRaw : null;

        $encryptedToken = RowAssert::optString($row, 'x_access_token');
        $decryptedToken = $encryptedToken !== null ? $this->decryptToken($encryptedToken) : null;

        return new XConnectionDTO(
            x_id:                     RowAssert::optString($row, 'x_id'),
            x_username:               RowAssert::optString($row, 'x_username'),
            x_avatar:                 RowAssert::optString($row, 'x_avatar'),
            x_trust_boost:            RowAssert::requireFloat($row, 'x_trust_boost'),
            x_fraud_reduction:        RowAssert::requireDigitInt($row, 'x_fraud_reduction'),
            x_access_token:           $encryptedToken,
            x_verified_at:            RowAssert::optString($row, 'x_verified_at'),
            x_last_synced:            RowAssert::optString($row, 'x_last_synced'),
            x_email:                  $email,
            x_access_token_decrypted: $decryptedToken,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DISCONNECT
    |--------------------------------------------------------------------------
    */

    /**
     * @throws RepositoryException on database failure
     */
    public function disconnect(int $userId): void {

        global $wpdb;

        $result = $wpdb->update(
            $this->table,
            [
                'status'          => 'revoked',
                'access_token'    => null,
                'trust_boost'     => 0,
                'fraud_reduction' => 0,
            ],
            ['user_id' => $userId, 'type' => 'x'],
            ['%s', '%s', '%f', '%d'],
            ['%d', '%s']
        );

        if ($result === false) {
            throw new RepositoryException('XRepository::disconnect failed for user ' . $userId . ': ' . $wpdb->last_error);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | APPLY TRUST BOOST
    |--------------------------------------------------------------------------
    */

    /**
     * @throws RepositoryException on database failure
     */
    public function applyTrustBoost(int $userId, float $trustBoost, int $fraudReduction): void {

        global $wpdb;

        $result = $wpdb->update(
            $this->table,
            [
                'trust_boost'     => $trustBoost,
                'fraud_reduction' => $fraudReduction,
            ],
            ['user_id' => $userId, 'type' => 'x'],
            ['%f', '%d'],
            ['%d', '%s']
        );

        if ($result === false) {
            throw new RepositoryException('XRepository::applyTrustBoost failed for user ' . $userId . ': ' . $wpdb->last_error);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | NET TRUST IMPACT
    |--------------------------------------------------------------------------
    */

    public function getNetTrustImpact(int $userId): float {

        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT trust_boost, fraud_reduction
             FROM {$this->table}
             WHERE user_id = %d AND type = 'x' AND status = 'active'",
            $userId
        ), ARRAY_A);

        if (!is_array($row)) {
            return 0.0;
        }

        return RowAssert::requireFloat($row, 'trust_boost')
            - (float) RowAssert::requireDigitInt($row, 'fraud_reduction');
    }
}