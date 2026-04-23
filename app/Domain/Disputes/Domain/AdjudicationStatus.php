<?php

namespace BCC\Trust\Disputes\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adjudication status — tracks whether the trust-engine penalty has been applied.
 *
 * 'none'      — dispute not yet resolved, or no adjudication attempted.
 * 'pending'   — resolution in progress / awaiting adjudicator call.
 * 'completed' — adjudicator reported success; penalty applied.
 * 'failed'    — adjudicator threw or returned failure; needs reconciliation.
 */
final class AdjudicationStatus
{
    public const NONE      = 'none';
    public const PENDING   = 'pending';
    public const COMPLETED = 'completed';
    public const FAILED    = 'failed';

    /** @var list<string> */
    private const ALL = [
        self::NONE,
        self::PENDING,
        self::COMPLETED,
        self::FAILED,
    ];

    public static function assert(string $value): string
    {
        if (!in_array($value, self::ALL, true)) {
            throw new \LogicException("Invalid adjudication status: '{$value}'");
        }
        return $value;
    }
}
