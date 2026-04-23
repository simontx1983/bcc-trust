<?php

namespace BCC\Trust\Disputes\DTO;

use BCC\Trust\Disputes\Domain\PanelDecision;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Full panel entry with joined panelist display name.
 *
 * Produced by getPanelistsForDispute() — used in the admin dispute-detail view
 * to show every panelist and their vote.
 *
 * Nullability:
 *   - id, dispute_id, panelist_user_id: NOT NULL — strict.
 *   - decision: NULL = pending vote (domain state), validated when non-null.
 *   - note: NULL-able column.
 *   - assigned_at: NOT NULL per schema, strict string.
 *   - voted_at: NULL until vote cast.
 *   - display_name: LEFT JOIN on wp_users; null when user has been deleted
 *     or the join fails. Tolerant per the fail-soft display rule.
 */
final class PanelEntryFullDTO
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $dispute_id,
        public readonly int     $panelist_user_id,
        public readonly ?string $decision,
        public readonly ?string $note,
        public readonly string  $assigned_at,
        public readonly ?string $voted_at,
        public readonly ?string $display_name,
    ) {
        $dto = 'PanelEntryFullDTO';
        DTOAssert::positiveInt($id,               $dto, 'id');
        DTOAssert::positiveInt($dispute_id,       $dto, 'dispute_id');
        DTOAssert::positiveInt($panelist_user_id, $dto, 'panelist_user_id');
        if ($decision !== null) {
            PanelDecision::assert($decision);
        }
        DTOAssert::datetime($assigned_at,      $dto, 'assigned_at');
        DTOAssert::nullableDatetime($voted_at, $dto, 'voted_at');
    }
}
