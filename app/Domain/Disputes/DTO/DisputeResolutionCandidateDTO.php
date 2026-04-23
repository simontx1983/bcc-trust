<?php

namespace BCC\Trust\Disputes\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dispute candidate row used by the auto-resolve and reconciliation paths.
 *
 * Property names match DB columns (snake_case) to keep existing consumer
 * access patterns like `$row->panel_accepts` working after the stdClass → DTO
 * transition.
 *
 * Distinct from DisputeCoreDTO because the scheduler queries pre-filter on
 * status and don't SELECT the column — there is no status field here, and
 * adding a nullable one would be the "maybe null but actually not" lie
 * we're forbidden from introducing.
 *
 * Same cross-field invariants as DisputeCoreDTO: panel counts are non-negative
 * and accepts + rejects ≤ size. Any corruption throws at the repository
 * boundary before the scheduler can tip an outcome based on bad data.
 */
final class DisputeResolutionCandidateDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $vote_id,
        public readonly int $page_id,
        public readonly int $voter_id,
        public readonly int $reporter_id,
        public readonly int $panel_accepts,
        public readonly int $panel_rejects,
        public readonly int $panel_size,
    ) {
        $dto = 'DisputeResolutionCandidateDTO';
        DTOAssert::positiveInt($id,          $dto, 'id');
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
