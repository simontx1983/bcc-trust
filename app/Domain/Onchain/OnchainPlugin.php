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

    // ── Validator/delegator communities ────────────────────────────────

    private ?\BCC\Trust\Onchain\Services\DelegationEligibilityService $delegationEligibilityService = null;
    public function delegationEligibilityService(): \BCC\Trust\Onchain\Services\DelegationEligibilityService
    {
        return $this->delegationEligibilityService ??= new \BCC\Trust\Onchain\Services\DelegationEligibilityService();
    }

    private ?\BCC\Trust\Onchain\Services\ValidatorGroupProvisioningService $validatorGroupProvisioningService = null;
    public function validatorGroupProvisioningService(): \BCC\Trust\Onchain\Services\ValidatorGroupProvisioningService
    {
        return $this->validatorGroupProvisioningService ??= new \BCC\Trust\Onchain\Services\ValidatorGroupProvisioningService();
    }

    private ?\BCC\Trust\Onchain\Services\ValidatorGroupGateService $validatorGroupGateService = null;
    public function validatorGroupGateService(): \BCC\Trust\Onchain\Services\ValidatorGroupGateService
    {
        // Opt-out state deliberately shares the NFT gate service's
        // group-id-keyed user_meta map (single opt-out story per group).
        return $this->validatorGroupGateService ??= new \BCC\Trust\Onchain\Services\ValidatorGroupGateService(
            $this->nftGroupGateService(),
            $this->delegationEligibilityService()
        );
    }

    private ?\BCC\Trust\Onchain\Services\ValidatorGroupRevokeService $validatorGroupRevokeService = null;
    public function validatorGroupRevokeService(): \BCC\Trust\Onchain\Services\ValidatorGroupRevokeService
    {
        return $this->validatorGroupRevokeService ??= new \BCC\Trust\Onchain\Services\ValidatorGroupRevokeService(
            $this->delegationEligibilityService()
        );
    }
}
