<?php
/**
 * The Hall-only facts a community card carries (topic Halls, F-597): what
 * kind of Hall it is, its topic's label, and — on a chain Hall — how many
 * validators its chain has. One helper for every producer of a Hall's card
 * (GET /groups, GET /groups/:slug, GET /halls), so they can never disagree.
 *
 * Bounded: one post-meta read for the page's Halls
 * (HallRepository::kindFactsForHalls) and one cached aggregate for the
 * validator counts (ValidatorRepository::getCountsByChainOrUnknown). A read
 * that cannot be trusted leaves its fact null, and the card then leaves the
 * stat out (or reads the neutral "HALL"); it never shows a guessed value.
 *
 * @package BCC\Trust\Core\Support
 */

namespace BCC\Trust\Core\Support;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\HallRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class HallCardFacts {

    /**
     * @param list<int>               $hallIds    the Halls on the page (not other groups)
     * @param array<int, string|null> $chainSlugs group id => chain slug, already resolved by the caller
     * @return array<int, array{hall_kind: 'chain'|'topic'|null, topic_label: string|null, validator_count: int|null}>
     */
    public static function forHalls(array $hallIds, array $chainSlugs): array {
        if ($hallIds === []) {
            return [];
        }

        $kinds = HallRepository::kindFactsForHalls($hallIds) ?? [];

        $chainHalls = array_filter($hallIds, static fn (int $id): bool => ($kinds[$id]['hall_kind'] ?? null) === 'chain');
        $counts     = $chainHalls === [] ? [] : ValidatorRepository::getCountsByChainOrUnknown();

        $out = [];
        foreach ($hallIds as $id) {
            $kind      = $kinds[$id]['hall_kind'] ?? null;
            $out[$id] = [
                'hall_kind'       => $kind,
                'topic_label'     => $kinds[$id]['topic']['label'] ?? null,
                'validator_count' => $kind === 'chain' ? self::validatorCount($counts, $chainSlugs[$id] ?? null) : null,
            ];
        }

        return $out;
    }

    /**
     * The chain's validator count, or null when it is unknown (the counts
     * could not be read, or the chain no longer resolves). A chain with no
     * validators is a real 0.
     *
     * @param array<int, object{cnt: numeric-string|int}>|null $counts
     */
    private static function validatorCount(?array $counts, ?string $chainSlug): ?int {
        if ($counts === null || $chainSlug === null || $chainSlug === '') {
            return null;
        }
        $chainId = ChainRepository::resolveIdAnyState($chainSlug);
        if ($chainId === null) {
            return null;
        }

        return (int) ($counts[$chainId]->cnt ?? 0);
    }
}
