<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Allow/deny rules for NFT contracts at the indexer write path.
 *
 * Owns `wp_bcc_nft_spam_contracts`. Read-hot path: getRule() is called
 * once per (chain, contract) pair in NftSpamFilter; backed by a
 * request-level cache to avoid re-querying for the same contract
 * across an indexer batch.
 *
 * @phpstan-type SpamContractRow object{
 *     id: string,
 *     chain_id: string,
 *     contract_address: string,
 *     rule: string,
 *     reason: string|null,
 *     created_at: string
 * }
 */
final class NftSpamContractRepository
{
    public const RULE_DENY  = 'deny';
    public const RULE_ALLOW = 'allow';

    private const COLUMNS = 'id, chain_id, contract_address, rule, reason, created_at';

    /**
     * Per-request rule cache keyed `chain_id:contract`. Value is the
     * rule string ('deny' / 'allow') or null when no rule exists.
     *
     * @var array<string, string|null>
     */
    private static array $cache = [];

    public static function table(): string
    {
        return DB::table('nft_spam_contracts');
    }

    /**
     * Returns 'deny', 'allow', or null (no explicit rule).
     */
    public static function getRule(int $chainId, string $contract): ?string
    {
        if ($chainId <= 0 || $contract === '') {
            return null;
        }

        $contractLc = strtolower($contract);
        $key        = $chainId . ':' . $contractLc;

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        global $wpdb;
        $table = self::table();

        $rule = $wpdb->get_var($wpdb->prepare(
            "SELECT rule
               FROM {$table}
              WHERE chain_id = %d
                AND contract_address = %s
              LIMIT 1",
            $chainId,
            $contractLc
        ));

        $resolved = is_string($rule) ? $rule : null;
        self::$cache[$key] = $resolved;
        return $resolved;
    }

    public static function addRule(int $chainId, string $contract, string $rule, string $reason = ''): bool
    {
        if ($chainId <= 0 || $contract === '') {
            return false;
        }

        if (!in_array($rule, [self::RULE_DENY, self::RULE_ALLOW], true)) {
            return false;
        }

        global $wpdb;
        $table      = self::table();
        $contractLc = strtolower($contract);
        $now        = current_time('mysql', true);

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (chain_id, contract_address, rule, reason, created_at)
             VALUES (%d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                rule = VALUES(rule),
                reason = VALUES(reason)",
            $chainId,
            $contractLc,
            $rule,
            $reason !== '' ? mb_substr($reason, 0, 255) : '',
            $now
        ));

        // Invalidate request-level cache so the new rule applies immediately.
        unset(self::$cache[$chainId . ':' . $contractLc]);

        return $result !== false;
    }

    public static function removeRule(int $chainId, string $contract): bool
    {
        if ($chainId <= 0 || $contract === '') {
            return false;
        }

        global $wpdb;
        $table      = self::table();
        $contractLc = strtolower($contract);

        $deleted = $wpdb->delete(
            $table,
            ['chain_id' => $chainId, 'contract_address' => $contractLc],
            ['%d', '%s']
        );

        unset(self::$cache[$chainId . ':' . $contractLc]);

        return is_int($deleted) && $deleted > 0;
    }

    /**
     * Admin-page list with pagination.
     *
     * @return list<SpamContractRow>
     */
    public static function findAll(int $limit = 100, int $offset = 0): array
    {
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        /** @var list<SpamContractRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              ORDER BY chain_id ASC, contract_address ASC
              LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
        return $rows ?: [];
    }

    /**
     * Test-only / admin-tool reset of the per-request cache.
     */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
