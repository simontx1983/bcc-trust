<?php

namespace BCC\Trust\Disputes\DTO;

use BCC\Trust\Disputes\Domain\DisputeStatus;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dispute orphaned in reconciliation — committed (status accepted/rejected)
 * but adjudication never completed (adjudication_status = pending|failed).
 *
 * Consumed by DisputeScheduler's retry loop, which decides whether to
 * re-fire penalty hooks and increment reopen_count. Any corruption here
 * directly affects whether a user gets penalised or not — strict validation.
 *
 * Status is NARROWED: only 'accepted' and 'rejected' are valid. The repository
 * query filters on these exact values, so a 'reviewing' or 'dismissed' status
 * here would indicate DB integrity failure and must fail-fast.
 */
final class OrphanedDisputeDTO
{
    /** Statuses legitimately produced by getOrphanedDisputes() (subset of DisputeStatus). */
    private const VALID_STATUSES = [
        DisputeStatus::ACCEPTED,
        DisputeStatus::REJECTED,
    ];

    public function __construct(
        public readonly int    $id,
        public readonly int    $vote_id,
        public readonly int    $page_id,
        public readonly int    $voter_id,
        public readonly int    $reporter_id,
        public readonly string $status,
        public readonly int    $reopen_count,
    ) {
        $dto = 'OrphanedDisputeDTO';
        DTOAssert::positiveInt($id,          $dto, 'id');
        DTOAssert::positiveInt($vote_id,     $dto, 'vote_id');
        DTOAssert::positiveInt($page_id,     $dto, 'page_id');
        DTOAssert::positiveInt($voter_id,    $dto, 'voter_id');
        DTOAssert::positiveInt($reporter_id, $dto, 'reporter_id');
        DisputeStatus::assert($status);
        // Domain-narrowing: the repository SQL filters status to these two
        // values; any other status here indicates DB integrity failure.
        DTOAssert::enum($status, self::VALID_STATUSES, $dto, 'status');
        DTOAssert::nonNegativeInt($reopen_count, $dto, 'reopen_count');
    }
}
