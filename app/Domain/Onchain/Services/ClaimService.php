<?php
/**
 * Claim Service
 *
 * Verifies on-chain claims: a user asserts they operate a validator,
 * created a collection, or hold tokens. The service checks:
 *
 *   1. User has a verified wallet
 *   2. Wallet matches the on-chain entity's ownership/operator address
 *   3. Records the verified claim
 *
 * Delegates RPC verification to bcc-trust's Core BlockchainQueryService
 * via the existing WalletVerificationService infrastructure.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use BCC\Trust\Onchain\Support\Bech32;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type ValidatorIdWithChain from ValidatorRepository
 * @phpstan-import-type CollectionIdWithChain from CollectionRepository
 * @phpstan-import-type WalletWithChain from WalletRepository
 *
 * Unified entity shape consumed by matchWalletToEntity / matchValidator / matchCollection.
 * Represents the superset of columns fetched by either
 * ValidatorRepository::getByIdWithChain or CollectionRepository::getByIdWithChain
 * — the mutually-exclusive fields are optional.
 *
 * @phpstan-type EntityForClaim object{
 *     id: string,
 *     wallet_link_id: string|null,
 *     chain_id: string,
 *     chain_slug: string,
 *     chain_type: string,
 *     operator_address?: string,
 *     moniker?: string|null,
 *     contract_address?: string,
 *     collection_name?: string|null
 * }
 */
class ClaimService {

    /** @var string[] Valid entity types. */
    private const ENTITY_TYPES = ['validator', 'collection'];

    /**
     * Exclusive roles — only ONE verified claim allowed per entity.
     * Holders are unlimited. Operators/creators are exclusive.
     */
    private const EXCLUSIVE_ROLES = ['operator', 'creator'];

    /**
     * Max wallets checked per claim match (Phase 8). Each wallet on the
     * entity's chain costs a synchronous on-chain RPC in matchCollection;
     * the linked-wallet count is attacker-controllable, so this caps the
     * per-request fan-out. Generous — real users rarely link >25 wallets
     * to one chain.
     */
    private const MAX_WALLETS_PER_CLAIM_MATCH = 25;

    /**
     * Attempt to claim an on-chain entity.
     *
     * Checks if the user has a connected wallet that matches the entity's
     * on-chain owner/operator. If matched, records a verified claim.
     *
     * @return array{success: bool, message: string, claim_id?: int, role?: string, needs_wallet?: bool, chain_slug?: string, error?: string, is_primary?: bool}
     */
    public static function claim(int $userId, string $entityType, int $entityId): array {
        if (!in_array($entityType, self::ENTITY_TYPES, true)) {
            return ['success' => false, 'message' => 'Invalid entity type.'];
        }

        // Check for existing claim by this user.
        $existing = ClaimRepository::getUserClaim($userId, $entityType, $entityId);
        if ($existing && $existing->status === 'verified') {
            return ['success' => false, 'message' => 'You have already claimed this.'];
        }

        // Load entity data.
        $entity = self::loadEntity($entityType, $entityId);
        if (!$entity) {
            return ['success' => false, 'message' => 'Entity not found.'];
        }

        $entityChainSlug = $entity->chain_slug ?? '';

        // Get user's verified wallets only — unverified wallets must not
        // be eligible for claims (prevents address-squatting attacks).
        $wallets = WalletRepository::getForUser($userId, null, true);
        if (empty($wallets)) {
            return [
                'success'      => false,
                'message'      => 'Connect a wallet first to claim this.',
                'needs_wallet' => true,
                'chain_slug'   => $entityChainSlug,
            ];
        }

        // Try to match a wallet to the entity.
        $match = self::matchWalletToEntity($wallets, $entity, $entityType);
        if (!$match) {
            // Check whether the user has ANY wallet on the entity's chain.
            $entityChainId    = (int) $entity->chain_id;
            $hasChainWallet   = false;
            foreach ($wallets as $w) {
                if ((int) $w->chain_id === $entityChainId) {
                    $hasChainWallet = true;
                    break;
                }
            }

            return [
                'success'      => false,
                'message'      => self::noMatchMessage($entityType, $entity),
                'needs_wallet' => !$hasChainWallet,
                'chain_slug'   => $entityChainSlug,
            ];
        }

        // ── Exclusivity gate: only one operator/creator per entity ────────
        if (in_array($match['role'], self::EXCLUSIVE_ROLES, true)) {
            $result = ClaimRepository::createExclusiveClaim(
                $userId,
                $entityType,
                $entityId,
                $match['wallet_address'],
                $match['chain_id'],
                $match['role']
            );

            if (!$result['success']) {
                return $result;
            }

            $claimId = $result['claim_id'];

            // Fire the verified event for exclusive claims (operator/creator).
            do_action('bcc_onchain_claim_verified', $userId, $entityType, $entityId, $match['role']);

            return [
                'success'    => true,
                'message'    => self::successMessage($entityType, $match['role']),
                'claim_id'   => $claimId,
                'role'       => $match['role'],
                'is_primary' => true,
            ];
        }

        // Non-exclusive roles (e.g. holder) — upsert is idempotent via ON DUPLICATE KEY.
        $upsertResult = ClaimRepository::upsert(
            $userId,
            $entityType,
            $entityId,
            $match['wallet_address'],
            $match['chain_id'],
            $match['role'],
            'verified'
        );

        if (!$upsertResult) {
            return ['success' => false, 'message' => 'Failed to save claim.'];
        }

        $claimId = $upsertResult['id'];

        // Only fire the action if this was a fresh insert.
        // ON DUPLICATE KEY UPDATE sets rows_affected=2 for updates, 0 for no-op.
        if ($upsertResult['inserted']) {
            do_action('bcc_onchain_claim_verified', $userId, $entityType, $entityId, $match['role']);
        }

        return [
            'success'    => true,
            'message'    => self::successMessage($entityType, $match['role']),
            'claim_id'   => $claimId,
            'role'       => $match['role'],
            'is_primary' => false,
        ];
    }

    /**
     * Load entity data by type and ID. Delegates to repository.
     *
     * @return EntityForClaim|null
     */
    private static function loadEntity(string $entityType, int $entityId): ?object {
        if ($entityType === 'validator') {
            /** @var EntityForClaim|null */
            return ValidatorRepository::getByIdWithChain($entityId);
        }

        if ($entityType === 'collection') {
            /** @var EntityForClaim|null */
            return CollectionRepository::getByIdWithChain($entityId);
        }

        return null;
    }

    /**
     * Match a user's wallets against an on-chain entity's ownership.
     *
     * For validators: wallet's derived operator address matches validator's operator_address.
     * For collections: wallet address is the contract owner (creator) or holds tokens (holder).
     *
     * @param list<WalletWithChain> $wallets
     * @param EntityForClaim $entity
     * @return array{wallet_address: string, chain_id: int, role: string}|null
     */
    private static function matchWalletToEntity(array $wallets, object $entity, string $entityType): ?array {
        $entityChainId = (int) $entity->chain_id;

        // Filter to wallets on the same chain.
        $chainWallets = array_values(array_filter($wallets, function ($w) use ($entityChainId) {
            return (int) $w->chain_id === $entityChainId;
        }));

        if (empty($chainWallets)) {
            return null;
        }

        // Bound the synchronous on-chain RPC fan-out (Phase 8): matchCollection
        // issues one getEthRole/getSolanaRole RPC per wallet, and a user's
        // linked-wallet count is attacker-controllable. Cap the wallets checked
        // per claim so a 100-wallet user can't force 100+ synchronous RPC calls
        // in a single request. A user with more than the cap on this entity's
        // chain has only their first N checked (rare in practice).
        if (count($chainWallets) > self::MAX_WALLETS_PER_CLAIM_MATCH) {
            $chainWallets = array_slice($chainWallets, 0, self::MAX_WALLETS_PER_CLAIM_MATCH);
        }

        if ($entityType === 'validator') {
            return self::matchValidator($chainWallets, $entity);
        }

        if ($entityType === 'collection') {
            return self::matchCollection($chainWallets, $entity);
        }

        return null;
    }

    /**
     * Match wallet to validator operator address.
     * For Cosmos: the operator address (valoper) is derived from the same key as the wallet address.
     *
     * @param list<WalletWithChain> $wallets
     * @param EntityForClaim $entity
     * @return array{wallet_address: string, chain_id: int, role: string}|null
     */
    private static function matchValidator(array $wallets, object $entity): ?array {
        $operatorAddr = strtolower($entity->operator_address ?? '');
        if (!$operatorAddr) {
            return null;
        }

        foreach ($wallets as $wallet) {
            $addr = strtolower($wallet->wallet_address);

            // Direct match (some chains use same address for operator).
            if ($addr === $operatorAddr) {
                return [
                    'wallet_address' => $wallet->wallet_address,
                    'chain_id'       => (int) $wallet->chain_id,
                    'role'           => 'operator',
                ];
            }

            // Cosmos: valoper prefix swap. If wallet is cosmos1..., operator is cosmosvaloper1...
            // The underlying 20-byte address is identical — only the bech32 HRP differs.
            // We must decode both to raw bytes and compare; suffix comparison is invalid
            // because bech32 checksums differ by HRP.
            if (($entity->chain_type ?? '') === 'cosmos') {
                $walletBytes  = self::bech32DecodeToBytes($addr);
                $valoperBytes = self::bech32DecodeToBytes($operatorAddr);
                // Both must decode to exactly 20 bytes (standard Cosmos address length).
                if ($walletBytes !== null && $valoperBytes !== null
                    && strlen($walletBytes) === 20 && strlen($valoperBytes) === 20
                    && $walletBytes === $valoperBytes) {
                    return [
                        'wallet_address' => $wallet->wallet_address,
                        'chain_id'       => (int) $wallet->chain_id,
                        'role'           => 'operator',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Match wallet to collection ownership via RPC.
     * Checks owner() first (creator), then balanceOf (holder).
     *
     * @param list<WalletWithChain> $wallets
     * @param EntityForClaim $entity
     * @return array{wallet_address: string, chain_id: int, role: string}|null
     */
    private static function matchCollection(array $wallets, object $entity): ?array {
        $contractAddr = $entity->contract_address ?? '';
        if (!$contractAddr) {
            return null;
        }

        // Delegate to trust-engine's BlockchainQueryService if available.
        $queryClass = '\\BCC\\Trust\\Services\\wallet\\BlockchainQueryService';
        if (!class_exists($queryClass)) {
            return null;
        }

        foreach ($wallets as $wallet) {
            $addr     = $wallet->wallet_address;
            $chainType = $entity->chain_type ?? 'evm';

            $role = match ($chainType) {
                'evm'    => $queryClass::getEthRole($addr, $contractAddr),
                'solana' => $queryClass::getSolanaRole($addr, $contractAddr),
                default  => 'none',
            };

            if ($role === 'creator') {
                return [
                    'wallet_address' => $addr,
                    'chain_id'       => (int) $wallet->chain_id,
                    'role'           => 'creator',
                ];
            }

            if ($role === 'holder') {
                return [
                    'wallet_address' => $addr,
                    'chain_id'       => (int) $wallet->chain_id,
                    'role'           => 'holder',
                ];
            }
        }

        return null;
    }

    /**
     * Decode a bech32 address to its raw address bytes (binary string).
     * Returns null on invalid input (including failed checksum). Pure function — no instance state.
     *
     * Delegates to the shared Bech32 support class which performs full
     * polymod checksum verification before returning bytes.
     */
    private static function bech32DecodeToBytes(string $bech32): ?string {
        return Bech32::decodeToBytes($bech32);
    }

    /**
     * @param EntityForClaim $entity
     */
    private static function noMatchMessage(string $entityType, object $entity): string {
        if ($entityType === 'validator') {
            $moniker = $entity->moniker ?? 'this validator';
            return "None of your connected wallets match the operator address for {$moniker}. Connect the wallet that runs this validator.";
        }
        $name = $entity->collection_name ?? 'this collection';
        return "None of your connected wallets own or hold tokens from {$name}.";
    }

    private static function successMessage(string $entityType, string $role): string {
        $labels = [
            'operator' => 'Verified as operator',
            'creator'  => 'Verified as creator',
            'holder'   => 'Verified as holder',
        ];
        return $labels[$role] ?? 'Claim verified';
    }
}
