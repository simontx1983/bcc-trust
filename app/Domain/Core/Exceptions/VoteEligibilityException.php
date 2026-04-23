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

class VoteEligibilityException extends \RuntimeException {}
