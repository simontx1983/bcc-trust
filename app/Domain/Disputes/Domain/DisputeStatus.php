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
 * Since Rank Phase 6 (Wave 3) the dispute's vote runs on the generic
 * poll engine; poll outcomes map onto these terminal values in
 * DisputeVoteService (passed → accepted, failed → rejected,
 * inconclusive → timeout_no_quorum).
 */
final class DisputeStatus
{
    public const REVIEWING         = 'reviewing';
    public const ACCEPTED          = 'accepted';
    public const REJECTED          = 'rejected';
    public const DISMISSED         = 'dismissed';
    /**
     * The community vote expired inconclusive (quorum/majority never
     * met by day-90). Distinguished from REJECTED so that the
     * adjudicator is NOT invoked, no reporter penalty fires, and the
     * result email uses "not decided" language instead of "rejected."
     * The underlying vote remains untouched (same net effect as
     * rejected for the page owner), but the reporter is not penalised
     * for community silence.
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
