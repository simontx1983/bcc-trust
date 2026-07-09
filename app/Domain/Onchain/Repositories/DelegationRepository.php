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

        try {
            // Fail closed if the transaction never opened (DB failover): a
            // no-op START leaves the delete+insert in autocommit with no
            // rollback on partial failure.
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new \RuntimeException('START TRANSACTION failed');
            }
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
     * Shared-validator-backing overlap for the who-to-follow recommender.
     *
     * For the given viewer, find OTHER users who delegate to at least one
     * of the same validators the viewer delegates to. Returns, per
     * candidate user, the count of shared validators and a representative
     * `(chain_id, validator_address)` pair the caller resolves to a
     * moniker for the "Backs {moniker} too" reason label.
     *
     * Walk:
     *   viewer's VERIFIED wallet_links
     *     → viewer's delegations  → (chain_id, validator_address) set V
     *   other users' delegations on a pair in V
     *     → JOIN their VERIFIED wallet_links → candidate user_id
     *
     * Only VERIFIED wallets count on both sides (verified_at IS NOT NULL)
     * — an unverified wallet is an unproven claim and must not seed an
     * affinity edge. The viewer themselves is excluded
     * (w2.user_id <> viewerId).
     *
     * This is an AFFINITY signal, not a trust/popularity one: the count
     * is "how many validators you both back," and the representative
     * validator is chosen deterministically (MIN by chain/address), NOT
     * by stake size, voting power, or any ranking metric. The service
     * layer applies its own affinity weight; ordering here is a
     * SELECTION bound only.
     *
     * Bounded (§4): the candidate set is grouped and capped by `$limit`.
     * The viewer-side delegation walk is naturally bounded — a single
     * user's verified wallets × their delegations is small (tens), and
     * the indexes `wallet_link_id` + `chain_validator (chain_id,
     * validator_address)` keep the candidate-side join tight.
     *
     * @return array<int, array{shared_count: int, chain_id: int, validator_address: string}>
     *         candidate user_id => shared-backing info
     */
    public static function getSharedValidatorBackers(int $viewerId, int $limit = 200): array
    {
        if ($viewerId <= 0 || $limit <= 0) {
            return [];
        }
        if ($limit > 500) {
            $limit = 500;
        }

        global $wpdb;
        $delegations = self::table();
        $wallets     = \BCC\Trust\Onchain\Repositories\WalletRepository::table();

        // d1 = viewer's delegations (via their verified wallets, w1).
        // d2 = anyone-else's delegations on the SAME (chain, validator),
        //      joined to their verified wallets (w2) to get the user_id.
        // MIN(d2.chain_id) + MIN(d2.validator_address) give a stable
        // representative validator for the label; COUNT(DISTINCT ...) is
        // the strength. The validator pair is chosen for determinism, not
        // by stake — affinity, never popularity.
        $sql = $wpdb->prepare(
            "SELECT w2.user_id AS user_id,
                    COUNT(DISTINCT CONCAT(d2.chain_id, ':', d2.validator_address)) AS shared_count,
                    MIN(d2.chain_id)          AS chain_id,
                    MIN(d2.validator_address) AS validator_address
               FROM {$wallets} w1
               INNER JOIN {$delegations} d1 ON d1.wallet_link_id = w1.id
               INNER JOIN {$delegations} d2 ON d2.chain_id = d1.chain_id
                                           AND d2.validator_address = d1.validator_address
               INNER JOIN {$wallets} w2 ON w2.id = d2.wallet_link_id
              WHERE w1.user_id = %d
                AND w1.verified_at IS NOT NULL
                AND w2.verified_at IS NOT NULL
                AND w2.user_id <> %d
              GROUP BY w2.user_id
              ORDER BY shared_count DESC, w2.user_id ASC
              LIMIT %d",
            $viewerId,
            $viewerId,
            $limit
        );

        /** @var list<array{user_id: string, shared_count: string, chain_id: string, validator_address: string}>|null $rows */
        $rows = $wpdb->get_results($sql, ARRAY_A);

        $out = [];
        foreach (($rows ?: []) as $row) {
            $userId = (int) $row['user_id'];
            if ($userId > 0) {
                $out[$userId] = [
                    'shared_count'      => (int) $row['shared_count'],
                    'chain_id'          => (int) $row['chain_id'],
                    'validator_address' => (string) $row['validator_address'],
                ];
            }
        }
        return $out;
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
