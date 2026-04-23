<?php

namespace BCC\Trust\Disputes\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dispute status — string-backed validator (not a PHP enum).
 *
 * Only these four values may appear in the bcc_disputes.status column.
 *
 * 'closed' is DELIBERATELY NOT listed here: that string appears in the outgoing
 * REST response at DisputeController.php to mask the final resolution from
 * panelists before all votes are cast. It is a presentation-only value and
 * must never enter the DB or a DTO.
 */
final class DisputeStatus
{
    public const REVIEWING         = 'reviewing';
    public const ACCEPTED          = 'accepted';
    public const REJECTED          = 'rejected';
    public const DISMISSED         = 'dismissed';
    /**
     * TTL expired without reaching quorum. Distinguished from REJECTED so
     * that the adjudicator is NOT invoked, no reporter penalty fires, and
     * the result email uses "not decided" language instead of "rejected."
     * The underlying vote remains untouched (same net effect as rejected
     * for the page owner), but the reporter is not penalised for panelist
     * silence.
     */
    public const TIMEOUT_NO_QUORUM = 'timeout_no_quorum';

    /** @var list<string> */
    private const ALL = [
        self::REVIEWING,
        self::ACCEPTED,
        self::REJECTED,
        self::DISMISSED,
        self::TIMEOUT_NO_QUORUM,
    ];

    /**
     * Fail-fast validator: returns the value when valid, throws LogicException otherwise.
     * No defaults, no fallbacks, no coercion — dispute state is a state machine.
     */
    public static function assert(string $value): string
    {
        if (!in_array($value, self::ALL, true)) {
            throw new \LogicException("Invalid dispute status: '{$value}'");
        }
        return $value;
    }
}
