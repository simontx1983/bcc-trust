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
     * @param int|null             $expiresIn access-token lifetime in seconds
     *                                        (from the OAuth `expires_in`), used
     *                                        to stamp token_expires_at so the
     *                                        service can proactively refresh.
     * @throws RepositoryException on database failure
     */
    public function saveConnection(
        int $userId,
        array $xData,
        string $accessToken,
        ?string $refreshToken = null,
        ?int $expiresIn = null
    ): void {

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
            'refresh_token'     => ($refreshToken !== null && $refreshToken !== '')
                ? $this->encryptToken($refreshToken)
                : null,
            'token_expires_at'  => $expiresIn !== null
                ? gmdate('Y-m-d H:i:s', time() + $expiresIn)
                : null,
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

        // Formats align 1:1 with $data insertion order: user_id (%d) then
        // 11 string/datetime columns (%s), including the two new token columns.
        $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        if ($exists) {
            $result = $wpdb->update(
                $this->table,
                $data,
                ['user_id' => $userId, 'type' => 'x'],
                $formats,
                ['%d', '%s']
            );
        } else {
            $result = $wpdb->insert(
                $this->table,
                $data,
                $formats
            );
        }

        if ($result === false) {
            throw new RepositoryException('XRepository::saveConnection failed for user ' . $userId . ': ' . $wpdb->last_error);
        }
    }

    /**
     * Persist a refreshed OAuth token set (from XOAuthService::refreshAccessToken).
     * UPDATE-only — the connection row already exists. refresh_token is
     * rewritten only when X returns a rotated one; token_expires_at only when
     * an `expires_in` is present.
     */
    public function updateTokens(int $userId, string $accessToken, ?string $refreshToken, ?int $expiresIn): void {

        global $wpdb;

        $data    = ['access_token' => $this->encryptToken($accessToken), 'last_synced' => current_time('mysql')];
        $formats = ['%s', '%s'];

        if ($refreshToken !== null && $refreshToken !== '') {
            $data['refresh_token'] = $this->encryptToken($refreshToken);
            $formats[]             = '%s';
        }
        if ($expiresIn !== null) {
            $data['token_expires_at'] = gmdate('Y-m-d H:i:s', time() + $expiresIn);
            $formats[]                = '%s';
        }

        $wpdb->update($this->table, $data, ['user_id' => $userId, 'type' => 'x'], $formats, ['%d', '%s']);
    }

    /*
    |--------------------------------------------------------------------------
    | GET CONNECTION
    |--------------------------------------------------------------------------
    */

    /**
     * Bounded list of user_ids with an active X connection. Powers
     * the §G1 /members directory's "X verified" filter pill — the
     * caller pre-resolves the verified set, then intersects with
     * other filter axes before passing to WP_User_Query::include.
     *
     * Bounded by LIMIT 5000 — covers the realistic V1.5 directory size
     * with abundant headroom while keeping the IN() clause sane when
     * passed downstream to WP_User_Query.
     *
     * @return list<int>
     */
    public function getVerifiedUserIds(): array {
        global $wpdb;

        /** @var list<string> $rows */
        $rows = $wpdb->get_col(
            "SELECT user_id
               FROM {$this->table}
              WHERE type = 'x'
                AND status = 'active'
              LIMIT 5000"
        );

        return array_map('intval', $rows);
    }

    /**
     * Batched: lifetime X-connection summary per user. Returns only
     * the public-display fields the directory needs (provider username
     * + verified_at) — never decrypts tokens or surfaces email. Users
     * without an active X connection are absent from the map.
     *
     * Empty `$userIds` short-circuits to an empty map.
     *
     * @param int[] $userIds Bounded by caller (directory per_page cap).
     * @return array<int, array{provider_username: string|null, verified_at: string|null}>
     */
    public function getConnectionsForUsers(array $userIds): array {
        if ($userIds === []) {
            return [];
        }

        $clean = [];
        foreach ($userIds as $id) {
            $intVal = (int) $id;
            if ($intVal > 0) {
                $clean[$intVal] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $idList = array_keys($clean);

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($idList), '%d'));

        /** @var list<array{user_id: string, provider_username: string|null, verified_at: string|null}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, provider_username, verified_at
                   FROM {$this->table}
                  WHERE type = 'x'
                    AND status = 'active'
                    AND user_id IN ({$placeholders})",
                ...$idList
            ),
            ARRAY_A
        );

        $out = [];
        foreach (($rows ?: []) as $row) {
            $out[(int) $row['user_id']] = [
                'provider_username' => $row['provider_username'] !== null
                    ? (string) $row['provider_username']
                    : null,
                'verified_at' => $row['verified_at'] !== null
                    ? (string) $row['verified_at']
                    : null,
            ];
        }
        return $out;
    }

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
                refresh_token       AS x_refresh_token,
                token_expires_at    AS x_token_expires_at,
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

        $encryptedRefresh = RowAssert::optString($row, 'x_refresh_token');
        $decryptedRefresh = $encryptedRefresh !== null ? $this->decryptToken($encryptedRefresh) : null;

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
            x_refresh_token_decrypted: $decryptedRefresh,
            x_token_expires_at:        RowAssert::optString($row, 'x_token_expires_at'),
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