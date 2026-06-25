<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Contracts\ChainReadInterface;
use BCC\Trust\Onchain\Repositories\ChainRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Implementation of ChainReadInterface.
 *
 * Thin adapter that delegates to the existing static ChainRepository, which
 * remains the storage-level repository. Provides a cross-Domain read contract
 * so Domain/Core resolves chain config via ServiceLocator instead of reaching
 * into the Onchain domain's repository directly.
 *
 * @phpstan-import-type ChainRow from ChainReadInterface
 */
final class ChainReadService implements ChainReadInterface
{
    /** @return ChainRow|null */
    public function getBySlug(string $slug): ?object
    {
        return ChainRepository::getBySlug($slug);
    }

    /** @return ChainRow|null */
    public function getById(int $chainId): ?object
    {
        return ChainRepository::getById($chainId);
    }

    /** @return list<ChainRow> */
    public function getActive(?string $chainType = null): array
    {
        return ChainRepository::getActive($chainType);
    }

    public function resolveId(string $slug): ?int
    {
        return ChainRepository::resolveId($slug);
    }

    /**
     * @param list<int> $groupIds
     * @return array<int, string>
     */
    public function resolveSlugsForGroups(array $groupIds): array
    {
        return ChainRepository::resolveSlugsForGroups($groupIds);
    }
}
