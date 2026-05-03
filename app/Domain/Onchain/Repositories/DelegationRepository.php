<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type DelegationRow object{
 *     id: string,
 *     wallet_link_id: string,
 *     chain_id: string,
 *     validator_address: string,
 *     shares: string|null,
 *     amount: string|null,
 *     fetched_at: string,
 *     expires_at: string
 * }
 *
 * @phpstan-type DelegationWithValidator object{
 *     id: string,
 *     wallet_link_id: string,
 *     chain_id: string,
 *     validator_address: string,
 *     shares: string|null,
 *     amount: string|null,
 *     fetched_at: string,
 *     moniker: string|null,
 *     status: string|null,
 *     commission_rate: string|null,
 *     voting_power_rank: string|null
 * }
 */
final class DelegationRepository
{
    public static function table(): string
    {
        return DB::table('onchain_delegations');
    }

    /**
     * Replace the delegation set for a wallet link. Delete-then-insert
     * inside a transaction because delegations mutate — a plain upsert
     * would leak rows for validators the user has undelegated from.
     *
     * Safe to call with an empty $delegations array: clears any existing
     * rows for the wallet link.
     *
     * @param array<int, array{validator_address: string, shares?: string|null, amount?: float|null}> $delegations
     * @return int Number of rows inserted.
     */
    public static function replaceForWalletLink(
        int $walletLinkId,
        int $chainId,
        array $delegations,
        int $ttlSeconds = 6 * HOUR_IN_SECONDS
    ): int {
        global $wpdb;
        $table = self::table();

        $now       = current_time('mysql', true);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->delete($table, ['wallet_link_id' => $walletLinkId], ['%d']);

            $inserted = 0;
            foreach ($delegations as $d) {
                $validator = $d['validator_address'] ?? '';
                if ($validator === '') {
                    continue;
                }

                $shares = isset($d['shares']) ? (string) $d['shares'] : null;
                $amount = isset($d['amount']) ? (float) $d['amount'] : null;

                $row = [
                    'wallet_link_id'    => $walletLinkId,
                    'chain_id'          => $chainId,
                    'validator_address' => $validator,
                    'fetched_at'        => $now,
                    'expires_at'        => $expiresAt,
                ];
                $format = ['%d', '%d', '%s', '%s', '%s'];

                if ($shares !== null) {
                    $row['shares'] = $shares;
                    $format[]      = '%s';
                }
                if ($amount !== null) {
                    $row['amount'] = $amount;
                    $format[]      = '%f';
                }

                $wpdb->insert($table, $row, $format);
                $inserted++;
            }

            $wpdb->query('COMMIT');
            return $inserted;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public static function existsForWalletLink(int $walletLinkId): bool
    {
        global $wpdb;
        $table = self::table();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE wallet_link_id = %d LIMIT 1",
            $walletLinkId
        ));
    }

    /**
     * Load delegations for a wallet link, joined with validator metadata
     * (moniker, status, commission, rank) when the validator is indexed.
     *
     * @return list<DelegationWithValidator>
     */
    public static function getForWalletLink(int $walletLinkId, int $limit = 500): array
    {
        global $wpdb;
        $table      = self::table();
        $validators = ValidatorRepository::table();

        /** @var list<DelegationWithValidator>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id, d.wallet_link_id, d.chain_id, d.validator_address,
                    d.shares, d.amount, d.fetched_at,
                    v.moniker, v.status, v.commission_rate, v.voting_power_rank
             FROM {$table} d
             LEFT JOIN {$validators} v
               ON v.chain_id = d.chain_id
              AND v.operator_address = d.validator_address
             WHERE d.wallet_link_id = %d
             ORDER BY d.amount DESC
             LIMIT %d",
            $walletLinkId,
            $limit
        ));

        return $rows ?: [];
    }
}
