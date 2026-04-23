<?php

namespace BCC\Trust\Disputes\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Panel decision — non-null values only (nullability is handled at the DTO level
 * and represents "panelist has not yet voted", which is a legitimate domain state,
 * NOT a data error).
 *
 * Use like:
 *     $decision = $row['decision'];
 *     if ($decision !== null) {
 *         $decision = PanelDecision::assert($decision);
 *     }
 */
final class PanelDecision
{
    public const ACCEPT = 'accept';
    public const REJECT = 'reject';

    /** @var list<string> */
    private const ALL = [
        self::ACCEPT,
        self::REJECT,
    ];

    public static function assert(string $value): string
    {
        if (!in_array($value, self::ALL, true)) {
            throw new \LogicException("Invalid panel decision: '{$value}'");
        }
        return $value;
    }
}
