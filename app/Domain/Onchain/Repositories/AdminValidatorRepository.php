<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories;

/** Bounded, uncached reads for the staff validator workspace. */
final class AdminValidatorRepository
{
    use GuardsReadFailures;

    public const MAX_BINDINGS = 5000;

    /** @return list<array<string,mixed>> */
    public static function bindings(?int $pageId = null): array
    {
        global $wpdb;
        $validators = ValidatorRepository::table();
        $wallets = WalletRepository::table();
        $chains = ChainRepository::table();
        // Include both recognized binding legs. The canonical owner resolver
        // decides which claims apply; never attach a claim outside these legs.
        $binding = "SELECT w.post_id AS page_id, v.id AS validator_id, v.chain_id, v.operator_address, 0 AS binding_order
            FROM {$validators} v JOIN {$wallets} w ON w.id = v.wallet_link_id WHERE w.post_id > 0
            UNION
            SELECT pm.post_id AS page_id, v.id AS validator_id, v.chain_id, v.operator_address, 1 AS binding_order
            FROM {$wpdb->postmeta} pm JOIN {$validators} v ON v.id = CAST(pm.meta_value AS UNSIGNED)
            WHERE pm.meta_key = '_bcc_onchain_validator_id'";
        $where = $pageId === null ? '' : $wpdb->prepare(' AND p.ID = %d', $pageId);
        $sql = "SELECT p.ID AS page_id, p.post_title AS title, p.post_name AS slug,
            b.validator_id, b.operator_address, c.slug AS chain_slug, c.name AS chain_name, c.chain_type
            FROM ({$binding}) b JOIN {$wpdb->posts} p ON p.ID = b.page_id
            JOIN {$chains} c ON c.id = b.chain_id
            WHERE p.post_type = 'peepso-page' AND p.post_status <> 'trash' {$where}
            ORDER BY p.ID, b.binding_order, b.validator_id LIMIT " . (self::MAX_BINDINGS + 1);
        $rows = self::rows($sql, __FUNCTION__);
        if (count($rows) > self::MAX_BINDINGS) {
            throw new RepositoryReadFailure(self::class, __FUNCTION__, 'directory_bound_exceeded');
        }
        return $rows;
    }

    /** @param list<int> $ids
     * @return array<int,array<string,mixed>> */
    public static function claims(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        global $wpdb;
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 100) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = self::rows($wpdb->prepare('SELECT id, entity_type, entity_id, user_id, claim_role, status, verification_method, verified_by, verified_at FROM ' . ClaimRepository::table() . " WHERE id IN ({$ph}) LIMIT 100", ...$chunk), __FUNCTION__);
            foreach ($rows as $row) {
                $out[(int) $row['id']] = $row;
            }
        }
        return $out;
    }

    /**
     * Unlike the fail-soft member mini reader, staff reads must distinguish
     * a deleted account from an unavailable account. Choose one handle row.
     * @param list<int> $ids
     * @return array<int,array<string,mixed>>
     */
    public static function users(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        global $wpdb;
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 100) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = self::rows($wpdb->prepare("SELECT u.ID AS user_id, u.user_login, u.display_name, m.meta_value AS handle
                FROM {$wpdb->users} u LEFT JOIN {$wpdb->usermeta} m ON m.umeta_id =
                (SELECT MIN(h.umeta_id) FROM {$wpdb->usermeta} h WHERE h.user_id = u.ID AND h.meta_key = 'bcc_handle')
                WHERE u.ID IN ({$ph}) LIMIT 100", ...$chunk), __FUNCTION__);
            foreach ($rows as $row) {
                $out[(int) $row['user_id']] = $row;
            }
        }
        return $out;
    }

    /** @return list<int> */
    public static function searchUserIds(string $query): array
    {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($query) . '%';
        $rows = self::rows($wpdb->prepare("SELECT u.ID AS user_id FROM {$wpdb->users} u
            WHERE u.user_email = %s OR u.user_login = %s OR u.display_name LIKE %s
              OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} h WHERE h.user_id = u.ID AND h.meta_key = 'bcc_handle' AND h.meta_value LIKE %s)
            ORDER BY u.ID LIMIT 10", $query, $query, $like, $like), __FUNCTION__);
        return array_map(static fn (array $row): int => (int) $row['user_id'], $rows);
    }

    /** @return array<string,mixed>|null */
    public static function linkEvent(int $pageId, int $userId, int $validatorId): ?array
    {
        return ValidatorRelationshipLogRepository::latestOwnerLink($userId, $validatorId);
    }

    /** @param list<int> $ids
     * @return array<int,string> */
    public static function pageTitles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        global $wpdb;
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 100) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%d'));
            foreach (self::rows($wpdb->prepare("SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$ph}) LIMIT 100", ...$chunk), __FUNCTION__) as $row) {
                $out[(int) $row['ID']] = (string) $row['post_title'];
            }
        }
        return $out;
    }

    /**
     * Stored cron verdict only: a GET must never run the verifier or send mail.
     * @return array<string,mixed>|null
     */
    public static function logHealth(): ?array
    {
        global $wpdb;
        $rows = self::rows($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", \BCC\Trust\Onchain\Services\RelationshipLogHealthCheck::RESULT), __FUNCTION__);
        if ($rows === []) {
            return null;
        }
        $value = $rows[0]['option_value'] ?? null;
        $decoded = is_string($value) ? @unserialize($value, ['allowed_classes' => false]) : null;
        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<array<string,mixed>> */
    private static function rows(string $sql, string $method): array
    {
        return array_map(static fn (object $row): array => get_object_vars($row), self::runRead($sql, $method, true));
    }
}
