<?php

namespace BCC\Trust\Disputes\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable view of a vote row as resolved from TrustReadServiceInterface.
 *
 * **CROSS-PLUGIN BOUNDARY** — this is the only DTO in bcc-disputes that adapts
 * data produced outside this plugin (bcc-trust-engine's vote read model).
 * All three fields are LOGIC fields that drive dispute-submission decisions:
 *   - page_id        → page-ownership gate (Permissions::owns_page)
 *   - voter_user_id  → self-dispute prevention gate
 *   - vote_type      → upvote-not-disputable gate
 *
 * A silent or wrong value here directly opens a privilege-escalation path:
 * a voter_user_id that coerces to 0 would bypass the self-dispute guard;
 * a vote_type of 0 is a "no direction" value that could mis-route through
 * either branch of the up/down check. Validate strictly, assume NOTHING
 * about upstream correctness.
 *
 * vote_type accepts the canonical {-1, +1} convention used by bcc-onchain-
 * signals. Zero is rejected (meaningless vote direction); other magnitudes
 * are rejected (trust-engine would need to evolve weighted voting, which
 * would require a contract change anyway).
 */
final class VoteContextDTO
{
    /** Canonical vote directions — adapters must not silently widen this. */
    private const VALID_VOTE_TYPES = [-1, 1];

    public function __construct(
        public readonly int $page_id,
        public readonly int $voter_user_id,
        public readonly int $vote_type,
    ) {
        $dto = 'VoteContextDTO';
        DTOAssert::positiveInt($page_id,       $dto, 'page_id');
        DTOAssert::positiveInt($voter_user_id, $dto, 'voter_user_id');
        if (!in_array($vote_type, self::VALID_VOTE_TYPES, true)) {
            // Do NOT widen to "non-zero int" — a vote_type of e.g. 5 would be
            // treated as an upvote by the existing > 0 gate but is not a
            // documented contract value. Reject anything outside {-1, 1} so
            // trust-engine changes surface here, not as silent misclassification.
            throw new \LogicException(
                "VoteContextDTO: vote_type must be -1 or 1 (got {$vote_type})"
            );
        }
    }
}
