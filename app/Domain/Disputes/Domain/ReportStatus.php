<?php

namespace BCC\Trust\Disputes\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * User-report status — used by bcc_user_reports.status.
 *
 * 'open'      — newly submitted, awaiting review.
 * 'reviewed'  — admin acknowledged, no penalty applied.
 * 'penalized' — admin applied a penalty to the reported user.
 * 'dismissed' — admin rejected the report.
 */
final class ReportStatus
{
    public const OPEN      = 'open';
    public const REVIEWED  = 'reviewed';
    public const PENALIZED = 'penalized';
    public const DISMISSED = 'dismissed';

    /** @var list<string> */
    private const ALL = [
        self::OPEN,
        self::REVIEWED,
        self::PENALIZED,
        self::DISMISSED,
    ];

    public static function assert(string $value): string
    {
        if (!in_array($value, self::ALL, true)) {
            throw new \LogicException("Invalid report status: '{$value}'");
        }
        return $value;
    }
}
