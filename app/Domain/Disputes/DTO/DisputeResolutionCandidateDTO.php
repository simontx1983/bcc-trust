<?php

namespace BCC\Trust\Disputes\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dispute candidate row used by the backstop-sweep and reconciliation paths.
 *
 * Property names match DB columns (snake_case).
 *
 * Distinct from DisputeCoreDTO because the scheduler queries pre-filter on
 * status and don't SELECT the column — there is no status field here, and
 * adding a nullable one would be the "maybe null but actually not" lie
 * we're forbidden from introducing.
 */
final class DisputeResolutionCandidateDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $vote_id,
        public readonly int $page_id,
        public readonly int $voter_id,
        public readonly int $reporter_id,
    ) {
        $dto = 'DisputeResolutionCandidateDTO';
        DTOAssert::positiveInt($id,          $dto, 'id');
        DTOAssert::positiveInt($vote_id,     $dto, 'vote_id');
        DTOAssert::positiveInt($page_id,     $dto, 'page_id');
        DTOAssert::positiveInt($voter_id,    $dto, 'voter_id');
        DTOAssert::positiveInt($reporter_id, $dto, 'reporter_id');
    }
}
