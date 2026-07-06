<?php

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\Contracts\WalletSignalWriteInterface;
use BCC\Core\ServiceLocator;

/**
 * Delegate: routes all wallet signal operations through the
 * WalletSignalWriteInterface contract (implemented by bcc-trust's Onchain domain).
 *
 * Data now lives in bcc_onchain_signals (unified table). The former
 * bcc_trust_wallet_signals table is no longer written to.
 *
 * All public methods preserve the original static call signatures so
 * existing callers (CronService, PeepSoIntegration, WalletVerificationService)
 * require zero changes.
 */
final class WalletSignalRepository
{
    /**
     * @return WalletSignalWriteInterface|null
     */
    private static function service(): ?WalletSignalWriteInterface
    {
        if (!ServiceLocator::hasRealService(WalletSignalWriteInterface::class)) {
            return null;
        }
        return ServiceLocator::resolveWalletSignalWrite();
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function upsert(
        int    $userId,
        string $chain,
        string $walletAddress,
        string $role,
        float  $trustBoost,
        int    $fraudReduction,
        string $contractAddress = '',
        array  $extra = []
    ): void {
        $svc = self::service();
        if ($svc) {
            $svc->upsertTrustSignal($userId, $chain, $walletAddress, $role, $trustBoost, $fraudReduction, $contractAddress, $extra);
        }
    }

    /**
     * @param list<array<string, mixed>> $collections
     */
    public static function saveCollections(
        int    $userId,
        string $chain,
        array  $collections,
        float  $trustBoost
    ): void {
        $svc = self::service();
        if (!$svc) {
            return;
        }

        // Resolve wallet address from chain for this user — the new
        // saveCollections signature requires it. Look up via the existing
        // signal to get the wallet_address.
        /** @var object{wallet_address: string}|null $existing */
        $existing = $svc->getTrustSignalForUserChain($userId, $chain);
        if (!$existing) {
            return;
        }

        $svc->saveCollections($userId, $chain, $existing->wallet_address, $collections, $trustBoost);
    }

    /**
     * @return object|null
     */
    public static function getForUserChain(int $userId, string $chain): ?object
    {
        $svc = self::service();
        return $svc ? $svc->getTrustSignalForUserChain($userId, $chain) : null;
    }

    /**
     * @return array<string, object>
     */
    public static function getAllForUser(int $userId): array
    {
        $svc = self::service();
        return $svc ? $svc->getAllTrustSignalsForUser($userId) : [];
    }

    public static function disconnect(int $userId, string $chain): void
    {
        $svc = self::service();
        if ($svc) {
            $svc->disconnectTrustSignal($userId, $chain);
        }
    }

    public static function getTotalTrustBoost(int $userId): float
    {
        $svc = self::service();
        return $svc ? $svc->getTotalTrustBoost($userId) : 0.0;
    }

    public static function deleteForUser(int $userId): void
    {
        $svc = self::service();
        if ($svc) {
            $svc->deleteForUser($userId);
        }
    }
}
