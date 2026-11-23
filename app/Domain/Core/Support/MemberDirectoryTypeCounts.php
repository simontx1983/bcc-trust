<?php
/**
 * MemberDirectoryTypeCounts — the /members filter-chip counts
 * (`type_counts`, `VALIDATORS · 5`), counted for one viewer.
 *
 * Each chip is the number of members THIS viewer could page through under
 * that type: the owners of a published, non-secret page of the type
 * (bcc-core `PeepSoPageRepository::getUserIdsOwningPagesOfType()`) put
 * through the same `PeepSoUserSearch` privacy filter the directory list
 * itself uses (`UsersEndpoint::members()`). So a chip always equals the
 * list's `pagination.total` for `?type=<that type>` with no other filter.
 * The chips stay independent of `q` and of the active `type` filter, so
 * they don't move while a viewer types.
 * Rationale: F-428 (private tracker).
 *
 * ⚠ An empty owner set is 0 WITHOUT a search: `include => []` is no
 * constraint to WP_User_Query, so it would count every listable member.
 *
 * ⚠ No PeepSo, no counts (all four 0): fail closed, as the list does.
 *
 * Collaborators are injected so a unit test can model them; production
 * uses {@see self::live()}.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberDirectoryTypeCounts
{
    /** The chip types, in wire order (the contract's `type_counts` keys). */
    public const TYPES = ['validator', 'project', 'nft', 'dao'];

    /**
     * @param \Closure(string): list<int> $ownersOf owner ids for a type
     * @param (\Closure(array<string, mixed>, int|null, string): object)|null $newSearch
     *        builds a `PeepSoUserSearch` (args, viewer or null, search term)
     *        and returns it; null when PeepSo is not loaded
     */
    public function __construct(
        private readonly \Closure $ownersOf,
        private readonly ?\Closure $newSearch,
    ) {
    }

    /** The production wiring: bcc-core's owner read and PeepSo's search. */
    public static function live(): self
    {
        return new self(
            static fn (string $type): array =>
                \BCC\Core\Repositories\PeepSoPageRepository::getUserIdsOwningPagesOfType($type),
            class_exists('\\PeepSoUserSearch')
                ? static fn (array $args, ?int $viewer, string $term): object =>
                    new \PeepSoUserSearch($args, $viewer, $term)
                : null
        );
    }

    /**
     * @return array{validator: int, project: int, nft: int, dao: int}
     */
    public function forViewer(int $viewerId): array
    {
        $counts = ['validator' => 0, 'project' => 0, 'nft' => 0, 'dao' => 0];
        if ($this->newSearch === null) {
            return $counts;
        }

        foreach (self::TYPES as $type) {
            $owners = ($this->ownersOf)($type);
            if ($owners === []) {
                continue;
            }

            // The list's own args, minus paging: one id row, and the exact
            // privacy-filtered total WP reads back through FOUND_ROWS(). The
            // search term stays EMPTY, exactly as in members().
            $search = ($this->newSearch)(
                [
                    'number'      => 1,
                    'offset'      => 0,
                    'fields'      => 'ID',
                    'count_total' => true,
                    'include'     => $owners,
                ],
                $viewerId > 0 ? $viewerId : null,
                ''
            );

            $total         = property_exists($search, 'total') ? $search->total : 0;
            $counts[$type] = is_numeric($total) ? max(0, (int) $total) : 0;
        }

        return $counts;
    }
}
