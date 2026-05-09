<?php

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\DTO\GitHubConnectionDTO;
use BCC\Trust\Core\DTO\RowAssert;
use BCC\Trust\Core\Exceptions\RepositoryException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub Repository
 *
 * Reads and writes GitHub verification data from the
 * bcc_trust_user_verifications table (type = 'github').
 *
 * getConnection() returns column aliases that match the old
 * github_* field names so that GitHubController and
 * GitHubVerificationService need no changes.
 */
class GitHubRepository {

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

        throw new \RuntimeException('Encryption failed — BCC_ENCRYPTION_KEY may be missing.');
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
     * @param array<string, mixed> $githubData
     * @throws \RuntimeException     if encryption is unavailable
     * @throws RepositoryException   on database failure
     */
    public function saveConnection(int $userId, array $githubData, string $accessToken): void {

        global $wpdb;

        $encryptedToken = $this->encryptToken($accessToken);
        $emails         = $githubData['emails'] ?? [];

        // GitHub-specific data stored in meta as JSON
        $meta = json_encode([
            'followers'          => $githubData['followers'] ?? 0,
            'public_repos'       => $githubData['public_repos'] ?? 0,
            'org_count'          => isset($githubData['organizations']) ? count($githubData['organizations']) : 0,
            'account_created'    => $githubData['created_at'] ?? null,
            'account_age_days'   => isset($githubData['created_at'])
                                        ? $this->calculateAccountAge($githubData['created_at'])
                                        : 0,
            'has_verified_email' => $this->hasVerifiedEmail($emails),
        ]);

        $data = [
            'user_id'          => $userId,
            'type'             => 'github',
            'provider_id'      => $githubData['id'] ?? null,
            'provider_username'=> $githubData['login'] ?? null,
            'provider_avatar'  => $githubData['avatar_url'] ?? null,
            'meta'             => $meta,
            'access_token'     => $encryptedToken,
            'status'           => 'active',
            'verified_at'      => current_time('mysql'),
            'last_synced'      => current_time('mysql'),
        ];

        // Prevent the same GitHub account from being linked to multiple WP users.
        $providerId = $githubData['id'] ?? null;
        if ($providerId !== null) {
            $existingOwner = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$this->table} WHERE type = 'github' AND provider_id = %s AND user_id != %d LIMIT 1",
                (string) $providerId,
                $userId
            ));
            if ($existingOwner) {
                throw new RepositoryException('This GitHub account is already linked to another user.');
            }
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE user_id = %d AND type = 'github'",
            $userId
        ));

        if ($exists) {
            $result = $wpdb->update(
                $this->table,
                $data,
                ['user_id' => $userId, 'type' => 'github'],
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
            throw new RepositoryException('GitHubRepository::saveConnection failed for user ' . $userId . ': ' . $wpdb->last_error);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | GET CONNECTION
    |
    | Returns aliases matching the old github_* column names so that
    | GitHubController and GitHubVerificationService work unchanged.
    |--------------------------------------------------------------------------
    */

    /**
     * Bounded list of user_ids with an active GitHub connection. Powers
     * the §G1 /members directory's "GitHub verified" filter pill — the
     * caller pre-resolves the verified set, then intersects with other
     * filter axes before passing to WP_User_Query::include.
     *
     * Bounded by LIMIT 5000 (mirrors XRepository::getVerifiedUserIds).
     *
     * @return list<int>
     */
    public function getVerifiedUserIds(): array {
        global $wpdb;

        /** @var list<string> $rows */
        $rows = $wpdb->get_col(
            "SELECT user_id
               FROM {$this->table}
              WHERE type = 'github'
                AND status = 'active'
              LIMIT 5000"
        );

        return array_map('intval', $rows);
    }

    /**
     * Batched: lifetime GitHub-connection summary per user. Returns
     * only the public-display fields the directory needs (provider
     * username + verified_at). Users without an active GitHub
     * connection are absent from the map.
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
                  WHERE type = 'github'
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

    public function getConnection(int $userId): ?GitHubConnectionDTO {

        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                provider_id         AS github_id,
                provider_username   AS github_username,
                provider_avatar     AS github_avatar,
                trust_boost         AS github_trust_boost,
                fraud_reduction     AS github_fraud_reduction,
                access_token        AS github_access_token,
                verified_at         AS github_verified_at,
                last_synced         AS github_last_synced,
                meta
            FROM {$this->table}
            WHERE user_id = %d AND type = 'github' AND status = 'active'",
            $userId
        ), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        // Decode meta JSON; tolerate missing/malformed meta by defaulting to
        // empty array. Meta is defensively populated on write (saveConnection
        // always json_encodes a shape) so only legacy rows would hit this.
        $metaRaw = $row['meta'] ?? null;
        $meta    = is_string($metaRaw) && $metaRaw !== '' ? json_decode($metaRaw, true) : [];
        if (!is_array($meta)) {
            $meta = [];
        }

        $encryptedToken = RowAssert::optString($row, 'github_access_token');
        $decryptedToken = $encryptedToken !== null ? $this->decryptToken($encryptedToken) : null;

        return new GitHubConnectionDTO(
            github_id:                     RowAssert::optString($row, 'github_id'),
            github_username:               RowAssert::optString($row, 'github_username'),
            github_avatar:                 RowAssert::optString($row, 'github_avatar'),
            github_trust_boost:            RowAssert::requireFloat($row, 'github_trust_boost'),
            github_fraud_reduction:        RowAssert::requireDigitInt($row, 'github_fraud_reduction'),
            github_access_token:           $encryptedToken,
            github_verified_at:            RowAssert::optString($row, 'github_verified_at'),
            github_last_synced:            RowAssert::optString($row, 'github_last_synced'),
            github_followers:              self::metaInt($meta, 'followers'),
            github_public_repos:           self::metaInt($meta, 'public_repos'),
            github_org_count:              self::metaInt($meta, 'org_count'),
            github_account_created:        self::metaNullableString($meta, 'account_created'),
            github_account_age_days:       self::metaInt($meta, 'account_age_days'),
            github_has_verified_email:     self::metaBinary($meta, 'has_verified_email'),
            github_access_token_decrypted: $decryptedToken,
        );
    }

    /**
     * Coerce a meta JSON field to a non-negative int.
     *
     * Meta is an untrusted JSON blob — legacy rows may be missing fields or
     * carry unexpected types from older write-paths. This helper matches the
     * spirit of the previous `(int) ($meta[$key] ?? 0)` call but closes two
     * corner cases that would otherwise leak past `DTOAssert::nonNegativeInt`:
     *
     *   - negative numeric values (int / float / "-5") are clamped to 0
     *     instead of being passed through. Previous code let "-5 followers"
     *     through silently; clamp is a safer no-throw default for a count.
     *   - NaN / INF floats and non-numeric strings become 0 (previously
     *     `(int) "abc"` also returned 0 — same outcome).
     *
     * Behaviour matrix (meta value → return):
     *   missing key / null  → 0
     *   int 5               → 5
     *   int -5              → 0           (clamped; drift vs. (int) cast)
     *   float 1.7           → 1
     *   float NAN / INF     → 0           (no (int) cast on non-finite)
     *   "5"                 → 5
     *   "-5"                → 0           (clamped)
     *   "1e3"               → 1000        (is_numeric scientific accepted)
     *   "abc" / bool / []   → 0
     *
     * @param array<string, mixed> $meta
     */
    private static function metaInt(array $meta, string $key): int
    {
        $value = $meta[$key] ?? 0;
        if (is_int($value)) {
            return $value < 0 ? 0 : $value;
        }
        if (is_float($value) && is_finite($value)) {
            return $value < 0 ? 0 : (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            $int = (int) $value;
            return $int < 0 ? 0 : $int;
        }
        return 0;
    }

    /**
     * Coerce a meta JSON field to strictly 0 or 1.
     *
     * Used only by `has_verified_email`. Anything other than the canonical
     * truthy sentinels (`1` / `"1"` / `true`) returns 0 — matches the old
     * `(int) ($meta[$key] ?? 0)` result for every other input, and avoids
     * accepting malformed values like `"yes"` as truthy.
     *
     * @param array<string, mixed> $meta
     */
    private static function metaBinary(array $meta, string $key): int
    {
        $value = $meta[$key] ?? 0;
        if ($value === 1 || $value === '1' || $value === true) {
            return 1;
        }
        return 0;
    }

    /**
     * Coerce a meta JSON field to a non-empty string, or null.
     *
     * Missing / null / empty-string / non-string → null. Non-empty string
     * passes through. Mirrors `?? null` semantics of the previous code but
     * rejects non-string types (e.g. an int accidentally stored as
     * `account_created`) instead of letting them through to the DTO.
     *
     * @param array<string, mixed> $meta
     */
    private static function metaNullableString(array $meta, string $key): ?string
    {
        $value = $meta[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        return is_string($value) ? $value : null;
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
            ['user_id' => $userId, 'type' => 'github'],
            ['%s', '%s', '%f', '%d'],
            ['%d', '%s']
        );

        if ($result === false) {
            throw new RepositoryException('GitHubRepository::disconnect failed for user ' . $userId . ': ' . $wpdb->last_error);
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
            ['user_id' => $userId, 'type' => 'github'],
            ['%f', '%d'],
            ['%d', '%s']
        );

        if ($result === false) {
            throw new RepositoryException('GitHubRepository::applyTrustBoost failed for user ' . $userId . ': ' . $wpdb->last_error);
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
             WHERE user_id = %d AND type = 'github' AND status = 'active'",
            $userId
        ), ARRAY_A);

        if (!is_array($row)) {
            return 0.0;
        }

        return RowAssert::requireFloat($row, 'trust_boost')
            - (float) RowAssert::requireDigitInt($row, 'fraud_reduction');
    }


    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    private function calculateAccountAge(string $createdAt): int {
        return (int) ((time() - strtotime($createdAt)) / DAY_IN_SECONDS);
    }

    /**
     * @param array<int, array<string, mixed>> $emails
     */
    private function hasVerifiedEmail(array $emails): int {
        foreach ($emails as $email) {
            if (!empty($email['verified'])) {
                return 1;
            }
        }
        return 0;
    }
}
