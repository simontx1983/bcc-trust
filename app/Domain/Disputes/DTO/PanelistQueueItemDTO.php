<?php

namespace BCC\Trust\Disputes\DTO;

use BCC\Trust\Disputes\Domain\DisputeStatus;
use BCC\Trust\Disputes\Domain\PanelDecision;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Panelist's-view dispute row — same 16 DisputeDetail columns plus `my_decision`
 * (this panelist's vote on this dispute, null if not yet voted).
 *
 * Separate from DisputeDetailDTO because `my_decision` is only present in the
 * panelist-queue SQL; adding it to DisputeDetailDTO with null semantics would
 * be the "maybe null but actually not" lie we're forbidden from introducing.
 *
 * Same cross-field invariant as DisputeDetail: accepts + rejects ≤ size.
 */
final class PanelistQueueItemDTO
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
        public readonly ?string $my_decision,
    ) {
        $dto = 'PanelistQueueItemDTO';
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
        if ($my_decision !== null) {
            PanelDecision::assert($my_decision);
        }
    }
}
