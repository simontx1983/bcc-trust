<?php
/**
 * DisputeVoteException — domain failure carrier for the dispute-vote
 * surface (Rank redesign Phase 6, Wave 3).
 *
 * Carries a machine `kind` (mapped by DisputeController onto the
 * `bcc_dispute_vote_*` REST error codes) plus a `reason` slug for the
 * forbidden case so the client can distinguish "not ranked" from
 * "party to the dispute" without parsing prose.
 *
 * @package BCC\Trust\Disputes\Services
 * @since Rank redesign Phase 6 (2026-08-03)
 */

declare(strict_types=1);

namespace BCC\Trust\Disputes\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class DisputeVoteException extends \RuntimeException
{
    /** Voter fails a §18 eligibility gate (reason says which). */
    public const KIND_FORBIDDEN = 'forbidden';

    /** Dispute/poll missing entirely (404). */
    public const KIND_NOT_FOUND = 'not_found';

    /** Poll closed, expired-frozen, or dispute no longer reviewing. */
    public const KIND_CLOSED = 'closed';

    /** §17.4 recast budget (2 changes) exhausted. */
    public const KIND_RECAST_EXHAUSTED = 'recast_exhausted';

    /** §17.4 24h ballot-action cooldown not yet elapsed. */
    public const KIND_COOLDOWN = 'cooldown';

    /** Withdraw with no active ballot. */
    public const KIND_NO_BALLOT = 'no_ballot';

    public function __construct(
        public readonly string $kind,
        public readonly string $reason,
        string $message
    ) {
        parent::__construct($message);
    }
}
