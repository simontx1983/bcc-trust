<?php
/**
 * PeepSoLeftoversRepository — the raw reads/DDL behind the one-off
 * "Remove PeepSo leftovers" admin action (PeepSoLeftoversService).
 *
 * Every table name it accepts is checked against a strict identifier
 * pattern AND must start with the site prefix; callers pass only names
 * from the service's hardcoded inventory. No LIKE-based deletes: the one
 * prefix read ({@see self::usermetaKeysWithPrefix()}) only LISTS keys, and
 * deletion happens per exact key through WordPress's metadata API.
 *
 * @package BCC\Trust\Core\Repositories
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoLeftoversRepository
{
    /** Upper bound on distinct meta keys listed for one prefix. */
    public const PREFIX_KEY_LIMIT = 200;

    public function tableExists(string $table): bool
    {
        global $wpdb;
        $table = $this->identifier($table);

        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));

        return is_string($found) && $found === $table;
    }

    /** Row count, or null when the table is absent or unreadable. */
    public function countRows(string $table): ?int
    {
        global $wpdb;
        $table = $this->identifier($table);
        if (!$this->tableExists($table)) {
            return null;
        }

        $wpdb->last_error = '';
        $n = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");

        // Same read-back as AnnouncementRepository::findOne().
        $lastError = (string) $wpdb->last_error;
        if (strlen($lastError) > 0 || !is_numeric($n)) {
            return null;
        }
        return (int) $n;
    }

    /** True when the table is gone afterwards. */
    public function dropTable(string $table): bool
    {
        global $wpdb;
        $table = $this->identifier($table);

        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");

        return !$this->tableExists($table);
    }

    public function countUsermeta(string $metaKey): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
            $metaKey
        ));
    }

    /**
     * Distinct meta keys starting with $prefix, with row counts. Listing
     * only — never used to delete.
     *
     * @return array<string, int> meta_key => rows
     */
    public function usermetaKeysWithPrefix(string $prefix): array
    {
        global $wpdb;
        if ($prefix === '') {
            return [];
        }

        /** @var list<object{meta_key: string, n: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, COUNT(*) AS n FROM {$wpdb->usermeta}
              WHERE meta_key LIKE %s
              GROUP BY meta_key
              ORDER BY meta_key
              LIMIT %d",
            $wpdb->esc_like($prefix) . '%',
            self::PREFIX_KEY_LIMIT
        ));

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $key = isset($row->meta_key) ? (string) $row->meta_key : '';
            if ($key !== '' && str_starts_with($key, $prefix)) {
                $out[$key] = (int) ($row->n ?? 0);
            }
        }
        return $out;
    }

    /**
     * Only `{prefix}` + [A-Za-z0-9_] names. The inventory is hardcoded, so
     * anything else is a programming error, not input to sanitise.
     */
    private function identifier(string $table): string
    {
        global $wpdb;
        $prefix = (string) $wpdb->prefix;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !str_starts_with($table, $prefix)) {
            throw new \InvalidArgumentException('Refusing table name: ' . $table);
        }
        return $table;
    }
}
