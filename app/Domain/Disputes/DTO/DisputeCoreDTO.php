<?php

namespace BCC\Trust\Disputes\DTO;

use BCC\Trust\Disputes\Domain\DisputeStatus;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core dispute row — used by getDisputeById() consumers (force-resolve,
 * admin actions, the dispute-vote service).
 *
 * Property names match DB column names (snake_case). Panel tally fields
 * retired with the panel path (Rank Phase 6 Wave 3, D-7) — vote state
 * lives on the poll engine.
 *
 * Enforces trust-critical invariants at construction:
 *   - All IDs are positive ints (BIGINT UNSIGNED columns in DB)
 *   - Status is a valid DisputeStatus value
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
    ) {
        $dto = 'DisputeCoreDTO';
        DTOAssert::positiveInt($id,          $dto, 'id');
        DisputeStatus::assert($status);
        DTOAssert::positiveInt($vote_id,     $dto, 'vote_id');
        DTOAssert::positiveInt($page_id,     $dto, 'page_id');
        DTOAssert::positiveInt($voter_id,    $dto, 'voter_id');
        DTOAssert::positiveInt($reporter_id, $dto, 'reporter_id');
    }
}
