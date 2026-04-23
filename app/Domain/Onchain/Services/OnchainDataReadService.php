<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Contracts\OnchainDataReadInterface;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Implementation of OnchainDataReadInterface.
 *
 * Delegates to existing ValidatorRepository and CollectionService,
 * providing a cross-plugin contract for peepso-integration.
 *
 * @phpstan-import-type CollectionDisplay from CollectionRepository
 */
final class OnchainDataReadService implements OnchainDataReadInterface
{
    /** @return array{items: object[], total: int, pages: int} */
    public function getValidatorsForProject(int $projectId, int $page = 1, int $perPage = 8, string $orderBy = 'total_stake'): array
    {
        return ValidatorRepository::getForProject($projectId, $page, $perPage, $orderBy);
    }

    /** @return array{items: object[], total: int, pages: int} */
    public function getCollectionsForProject(int $projectId, int $page = 1, int $perPage = 8, string $orderBy = 'total_volume', bool $includeHidden = false): array
    {
        return CollectionService::getForProject($projectId, $page, $perPage, $orderBy, $includeHidden);
    }

    /** @return array{active_count: int, chains_count: int, total_stake: float, total_delegators: int, top_validator: object|null} */
    public function getValidatorAggregateStats(int $projectId): array
    {
        $row = ValidatorRepository::getAggregateStatsForProject($projectId);
        $top = ValidatorRepository::getTopValidatorForProject($projectId);

        return [
            'active_count'     => $row ? (int) $row->active_count : 0,
            'chains_count'     => $row ? (int) $row->chains_count : 0,
            'total_stake'      => $row ? (float) $row->total_stake : 0.0,
            'total_delegators' => $row ? (int) $row->total_delegators : 0,
            'top_validator'    => $top,
        ];
    }

    /** @return array{items: object[], total: int, pages: int} */
    public function getAllCollectionsForProject(int $projectId): array
    {
        return CollectionService::getAllForProject($projectId);
    }

    /**
     * @param list<CollectionDisplay> $items
     * @return list<\stdClass>
     */
    public function enrichCollectionsWithBadges(array $items, int $ownerId, int $viewerId = 0): array
    {
        return CollectionService::enrichWithBadges($items, $ownerId, $viewerId);
    }

    /**
     * @param list<CollectionDisplay> $onchainItems
     * @param array<int, array<string, mixed>> $manualRows
     * @return list<\stdClass>
     */
    public function mergeCollectionsWithManual(array $onchainItems, array $manualRows): array
    {
        return CollectionService::mergeWithManual($onchainItems, $manualRows);
    }
}
