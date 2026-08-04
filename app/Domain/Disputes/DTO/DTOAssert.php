<?php

namespace BCC\Trust\Disputes\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dispute-flavoured DTO assertions.
 *
 * Inherits the cross-plugin primitive checks (positiveInt, datetime, enum,
 * etc.) from the canonical core implementation and adds the dispute-domain
 * cross-field invariants on top.
 *
 * @see \BCC\Core\DTO\DTOAssert  for the inherited primitive checks and the
 *                               stable "{DTO}: {field} must be {rule} (got {value})"
 *                               error-message contract every helper preserves.
 */
final class DTOAssert extends \BCC\Core\DTO\DTOAssert
{
    // panelTally() retired with the five-member panel (Rank Phase 6
    // Wave 3, D-7) — dispute-vote invariants live on the poll engine.
}
