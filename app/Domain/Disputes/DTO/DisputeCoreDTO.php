<?php

namespace BCC\Trust\Disputes\DTO;

use BCC\Trust\Disputes\Domain\DisputeStatus;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core dispute row — used by getDisputeById() and castPanelVoteAtomic() re-reads.
 *
 * Property names match DB column names (snake_case) to minimise the diff against
 * existing consumer code — every existing `$d->panel_accepts` access continues to
 * work once $d is a DTO rather than stdClass.
 *
 * Enforces trust-critical invariants at construction:
 *   - All IDs are positive ints (BIGINT UNSIGNED columns in DB)
 *   - Status is a valid DisputeStatus value
 *   - Panel counts are non-negative
 *   - panel_accepts + panel_rejects ≤ panel_size (cannot have more votes than panelists)
 *
 * Any violation throws LogicException immediately.
 */
final class DisputeCoreDTO
{
    public function __construct(
        public readonly int    $id,
        public readonly string $status,
        public readonly int    $vote_id,
        public readonly int    $page_id,
        public readonly int    $voter_id,
        public readonly int    $reporter_id,
        public readonly int    $panel_accepts,
        public readonly int    $panel_rejects,
        public readonly int    $panel_size,
    ) {
        $dto = 'DisputeCoreDTO';
        DTOAssert::positiveInt($id,          $dto, 'id');
        DisputeStatus::assert($status);
        DTOAssert::positiveInt($vote_id,     $dto, 'vote_id');
        DTOAssert::positiveInt($page_id,     $dto, 'page_id');
        DTOAssert::positiveInt($voter_id,    $dto, 'voter_id');
        DTOAssert::positiveInt($reporter_id, $dto, 'reporter_id');
        DTOAssert::positiveInt($panel_size,       $dto, 'panel_size');
        DTOAssert::nonNegativeInt($panel_accepts, $dto, 'panel_accepts');
        DTOAssert::nonNegativeInt($panel_rejects, $dto, 'panel_rejects');
        DTOAssert::panelTally($panel_accepts, $panel_rejects, $panel_size, $dto);
    }
}
