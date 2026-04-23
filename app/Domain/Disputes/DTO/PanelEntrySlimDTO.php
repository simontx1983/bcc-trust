<?php

namespace BCC\Trust\Disputes\DTO;

use BCC\Trust\Disputes\Domain\PanelDecision;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal panel entry — just the entry id and the panelist's decision (if any).
 *
 * Returned by getPanelAssignment() to let the controller check "does this user
 * have an assignment, and have they voted yet?" without fetching the full row.
 *
 * Nullability:
 *   - id: NOT NULL in schema, strict (logic field)
 *   - decision: NULL = "not yet voted" — legitimate domain state. When non-null,
 *     must be 'accept' or 'reject' (validated).
 */
final class PanelEntrySlimDTO
{
    public function __construct(
        public readonly int     $id,
        public readonly ?string $decision,
    ) {
        DTOAssert::positiveInt($id, 'PanelEntrySlimDTO', 'id');
        if ($decision !== null) {
            PanelDecision::assert($decision);
        }
    }
}
