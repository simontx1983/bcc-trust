<?php
/**
 * Vote Eligibility Exception
 *
 * Thrown by VoteEligibilityChecker when a vote must be rejected before any
 * writes occur. Controllers should catch this and return a 403 response.
 *
 * Separate from \Exception so callers can distinguish eligibility failures
 * (user error, return 403) from unexpected runtime errors (return 500).
 *
 * @package BCC\Trust\Core\Exceptions
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Exceptions;

if (!defined('ABSPATH')) {
    exit;
}

class VoteEligibilityException extends \RuntimeException
{
    /**
     * F-391: the exception CODE of a refusal by a rate limit (the daily
     * downvote cap, the general vote limit), as opposed to an eligibility
     * rule. Callers answer it with bcc_rate_limited 429, not 403: the
     * RateLimiter recorded the denial, so the REST Envelope adds the time
     * left (Retry-After, retry_after_seconds).
     */
    public const RATE_LIMITED = 429;
}
