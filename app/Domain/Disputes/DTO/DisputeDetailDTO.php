<?php

namespace BCC\Trust\Disputes\DTO;

use BCC\Trust\Disputes\Domain\DisputeStatus;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Full dispute row with joined display fields.
 *
 * Produced by:
 *   - getDisputeDetailForAdmin() (admin detail view)
 *   - getByReporterPaginated()   (reporter's own disputes list)
 *
 * Both queries select the same 16 columns in the same roles — safe to share a
 * DTO between them. The panelist queue uses a superset with `my_decision`
 * (see PanelistQueueItemDTO).
 *
 * Fail-soft display / fail-fast logic:
 *   - LOGIC (strict):  id, status, vote_id, page_id, voter_id, reporter_id,
 *                      panel_accepts, panel_rejects, panel_size, created_at.
 *                      Plus cross-field invariant accepts+rejects≤size.
 *   - DISPLAY (tolerant): reason (NOT NULL but schema DEFAULT ''), evidence_url,
 *                         resolved_at, page_title, reporter_name, voter_name.
 *                         Nullable fields are LEFT JOIN / nullable columns.
 */
final class DisputeDetailDTO
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $vote_id,
        public readonly int     $page_id,
        public readonly int     $voter_id,
        public readonly int     $reporter_id,
        public readonly string  $reason,
        public readonly ?string $evidence_url,
        public readonly string  $status,
        public readonly int     $panel_accepts,
        public readonly int     $panel_rejects,
        public readonly int     $panel_size,
        public readonly string  $created_at,
        public readonly ?string $resolved_at,
        public readonly ?string $page_title,
        public readonly ?string $reporter_name,
        public readonly ?string $voter_name,
    ) {
        $dto = 'DisputeDetailDTO';
        DTOAssert::positiveInt($id,          $dto, 'id');
        DTOAssert::positiveInt($vote_id,     $dto, 'vote_id');
        DTOAssert::positiveInt($page_id,     $dto, 'page_id');
        DTOAssert::positiveInt($voter_id,    $dto, 'voter_id');
        DTOAssert::positiveInt($reporter_id, $dto, 'reporter_id');
        // reason is display-only (formatDispute passes through to response);
        // NOT NULL but DEFAULT '' per schema — no non-empty check.
        DisputeStatus::assert($status);
        DTOAssert::positiveInt($panel_size,       $dto, 'panel_size');
        DTOAssert::nonNegativeInt($panel_accepts, $dto, 'panel_accepts');
        DTOAssert::nonNegativeInt($panel_rejects, $dto, 'panel_rejects');
        DTOAssert::panelTally($panel_accepts, $panel_rejects, $panel_size, $dto);
        DTOAssert::datetime($created_at,          $dto, 'created_at');
        DTOAssert::nullableDatetime($resolved_at, $dto, 'resolved_at');
    }
}
