<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\NftSelectionRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Business logic for user-curated NFT selections (profile display).
 *
 * Composition:
 *   HoldingsService → live chain data (cached)
 *   NftSelectionRepository → persistence
 *   this service → ownership verification + snapshot metadata
 */
final class NftSelectionService
{
    /**
     * Build the data payload the picker modal renders from.
     *
     * Returns the user's live holdings (across all connected wallets)
     * annotated with which items are already selected. Timestamp data
     * comes from WalletRepository so the UI can render "Refreshed N ago".
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     truncated: bool,
     *     wallets_checked: int,
     *     wallets_truncated: int,
     *     selected_keys: array<string, bool>,
     *     refreshed_at: array<int, string>
     * }
     */
    public static function buildPickerData(int $userId, bool $force = false): array
    {
        $holdings = HoldingsService::getForUser($userId, $force);
        $selected = NftSelectionRepository::selectedKeysForUser($userId);

        // Annotate each item with "already selected" state so the UI can
        // render checked checkboxes on modal open.
        $annotated = [];
        foreach ($holdings['items'] as $item) {
            $key = self::itemKey($item);
            $copy = $item;
            $copy['is_selected'] = isset($selected[$key]);
            $annotated[] = $copy;
        }

        return [
            'items'             => $annotated,
            'truncated'         => (bool) ($holdings['truncated'] ?? false),
            'wallets_checked'   => (int) ($holdings['wallets_checked'] ?? 0),
            'wallets_truncated' => (int) ($holdings['wallets_truncated'] ?? 0),
            'selected_keys'     => array_fill_keys(array_keys($selected), true),
            'refreshed_at'      => WalletRepository::getHoldingsRefreshForUser($userId),
        ];
    }

    /**
     * Save a selection. Fails if the user doesn't actually own this NFT
     * — checked against the (cached) holdings list. Callers can pass
     * $force=true to re-verify against chain before saving (paranoid
     * mode for anti-fraud at save time).
     *
     * @return array{ok: bool, id?: int, error?: string}
     */
    public static function saveSelection(
        int $userId,
        int $chainId,
        string $contract,
        string $tokenId,
        bool $verifyFresh = false
    ): array {
        $match = self::findOwnedToken($userId, $chainId, $contract, $tokenId, $verifyFresh);
        if ($match === null) {
            return ['ok' => false, 'error' => 'not_owned'];
        }

        $id = NftSelectionRepository::save([
            'user_id'          => $userId,
            'wallet_link_id'   => (int) $match['wallet_link_id'],
            'chain_id'         => $chainId,
            'contract_address' => $contract,
            'token_id'         => $tokenId,
            'collection_name'  => $match['collection_name'] ?? null,
            'name'             => $match['name'] ?? null,
            'image_url'        => $match['image_url'] ?? null,
            'metadata_uri'     => $match['metadata_uri'] ?? null,
            'token_standard'   => $match['token_standard'] ?? null,
        ]);

        if ($id === false) {
            return ['ok' => false, 'error' => 'db_error'];
        }

        return ['ok' => true, 'id' => (int) $id];
    }

    public static function removeSelection(int $userId, int $chainId, string $contract, string $tokenId): bool
    {
        return NftSelectionRepository::deleteByIdentity($userId, $chainId, $contract, $tokenId);
    }

    public static function removeById(int $userId, int $id): bool
    {
        return NftSelectionRepository::deleteById($id, $userId);
    }

    /**
     * @param list<int> $orderedIds
     */
    public static function reorder(int $userId, array $orderedIds): int
    {
        return NftSelectionRepository::reorder($userId, $orderedIds);
    }

    /**
     * Force-refresh holdings for the user's wallets. Clears the
     * HoldingsService transient cache and pulls a fresh list, which
     * also stamps wallet_links.last_holdings_refresh_at as a side effect.
     *
     * @return array<string, mixed> Same shape as buildPickerData.
     */
    public static function refreshHoldings(int $userId): array
    {
        HoldingsService::invalidateUser($userId);
        return self::buildPickerData($userId, true);
    }

    // ── Internal ───────────────────────────────────────────────────────────

    /**
     * Locate a holdings item matching (chain, contract, token_id) among
     * the user's connected wallets. Returns the matching item (including
     * wallet_link_id) or null if the user doesn't own it.
     *
     * @return ?array<string, mixed>
     */
    private static function findOwnedToken(
        int $userId,
        int $chainId,
        string $contract,
        string $tokenId,
        bool $force
    ): ?array {
        $holdings = HoldingsService::getForUser($userId, $force);

        $targetContract = strtolower($contract);
        $targetToken    = strtolower($tokenId);

        foreach ($holdings['items'] as $item) {
            if ((int) ($item['chain_id'] ?? 0) !== $chainId) {
                continue;
            }
            if (strtolower((string) ($item['contract_address'] ?? '')) !== $targetContract) {
                continue;
            }
            if (strtolower((string) ($item['token_id'] ?? '')) !== $targetToken) {
                continue;
            }
            return $item;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function itemKey(array $item): string
    {
        return strtolower(
            ($item['chain_id'] ?? '') . '|'
            . ($item['contract_address'] ?? '') . '|'
            . ($item['token_id'] ?? '')
        );
    }
}
