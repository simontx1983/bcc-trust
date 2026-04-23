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
    /**
     * Cross-field invariant: total votes (accepts + rejects) cannot exceed
     * panel size. Centralised so the trust-critical "cannot have more votes
     * than panelists" rule has one source of truth across every DTO that
     * carries the panel tally.
     *
     * Self-sufficient by design: it delegates to positiveInt/nonNegativeInt
     * for the component-level checks so the invariant can be invoked in
     * isolation without prior validation. DTO constructors typically still
     * call those helpers first for focused per-field error messages — the
     * internal delegation here acts as a safety net for any caller that
     * skips that setup.
     */
    public static function panelTally(int $accepts, int $rejects, int $size, string $dto): void
    {
        self::positiveInt($size,       $dto, 'panel_size');
        self::nonNegativeInt($accepts, $dto, 'panel_accepts');
        self::nonNegativeInt($rejects, $dto, 'panel_rejects');
        if ($accepts + $rejects > $size) {
            throw new \LogicException(
                "{$dto}: panel tally must satisfy accepts+rejects ≤ size (got {$accepts}+{$rejects} > {$size})"
            );
        }
    }
}
