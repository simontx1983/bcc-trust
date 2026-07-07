<?php

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\Contracts\WalletSignalWriteInterface;
use BCC\Trust\Onchain\Repositories\SignalRepository;

/**
 * Contract implementation: routes trust-engine wallet signal writes
 * to the unified bcc_onchain_signals table via SignalRepository.
 */
class WalletSignalWriteService implements WalletSignalWriteInterface
{
    public function upsertTrustSignal(
        int    $userId,
        string $chain,
        string $walletAddress,
        string $role,
        string $contractAddress = '',
        array  $extra = []
    ): void {
        SignalRepository::upsertTrustSignal(
            $userId, $chain, $walletAddress, $role, $contractAddress, $extra
        );
    }

    public function saveCollections(
        int    $userId,
        string $chain,
        string $walletAddress,
        array  $collections
    ): void {
        SignalRepository::saveCollections($userId, $chain, $walletAddress, $collections);
    }

    public function disconnectTrustSignal(int $userId, string $chain): void
    {
        SignalRepository::disconnectTrustSignal($userId, $chain);
    }

    public function getTrustSignalForUserChain(int $userId, string $chain): ?object
    {
        return SignalRepository::getTrustSignalForUserChain($userId, $chain);
    }

    public function getAllTrustSignalsForUser(int $userId): array
    {
        return SignalRepository::getAllTrustSignalsForUser($userId);
    }

    public function deleteForUser(int $userId): void
    {
        SignalRepository::deleteForUser($userId);
    }
}
