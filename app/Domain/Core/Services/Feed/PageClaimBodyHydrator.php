<?php
/**
 * Page-claim body hydration for the §F3 feed brain.
 *
 * Extracted verbatim from FeedRankingService (Phase 3.2 split): the
 * per-kind `page_claim` body loader, reading the bcc_onchain_claims
 * sidecar via the cross-domain ClaimRepository static surface.
 *
 * @package BCC\Trust\Core\Services\Feed
 */

namespace BCC\Trust\Core\Services\Feed;

use BCC\Trust\Onchain\Repositories\ClaimRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class PageClaimBodyHydrator
{
    /**
     * Bulk-load page_claim bodies. Returns map keyed by
     * bcc_onchain_claims.id.
     *
     * @param list<int> $claimIds
     * @return array<int, array<string, mixed>>
     */
    public function loadPageClaimBodies(array $claimIds): array
    {
        if ($claimIds === []) {
            return [];
        }

        $claims = ClaimRepository::findManyByIds($claimIds);
        $bodies = [];
        foreach ($claims as $id => $claim) {
            $role = (string) $claim->claim_role;
            $bodies[$id] = [
                'claim_id'    => (int) $claim->id,
                'entity_type' => (string) $claim->entity_type,
                'entity_id'   => (int) $claim->entity_id,
                'role'        => $role,
                'verified_at' => self::toIso8601((string) ($claim->verified_at ?? '')),
                // Pre-rendered summary per §A2 — frontend renders verbatim.
                // Page name resolution would add a query per feed page;
                // until that's worth it, "this page" stands in.
                'summary'     => $role !== ''
                    ? "Claimed this page as {$role}."
                    : 'Claimed this page.',
            ];
        }
        return $bodies;
    }

    private static function toIso8601(string $mysqlDatetime): string
    {
        if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
