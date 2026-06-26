<?php

namespace BCC\Trust\Onchain;

use BCC\Trust\Onchain\Services\GatedGroupProvisioningService;
use BCC\Trust\Onchain\Services\NftGroupGateService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Singleton service container for the Onchain bounded context.
 *
 * Owns the construction of Onchain-namespace services so that Core's
 * Plugin no longer wires them. All accessors are lazy-initialized,
 * dependency-free singletons (no-arg constructors).
 */
final class OnchainPlugin
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private ?NftGroupGateService $nftGroupGateService = null;
    public function nftGroupGateService(): NftGroupGateService
    {
        return $this->nftGroupGateService ??= new NftGroupGateService();
    }

    private ?\BCC\Trust\Onchain\Services\NftGroupRevokeService $nftGroupRevokeService = null;
    public function nftGroupRevokeService(): \BCC\Trust\Onchain\Services\NftGroupRevokeService
    {
        return $this->nftGroupRevokeService ??= new \BCC\Trust\Onchain\Services\NftGroupRevokeService();
    }

    private ?GatedGroupProvisioningService $gatedGroupProvisioningService = null;
    public function gatedGroupProvisioningService(): GatedGroupProvisioningService
    {
        return $this->gatedGroupProvisioningService ??= new GatedGroupProvisioningService();
    }
}
