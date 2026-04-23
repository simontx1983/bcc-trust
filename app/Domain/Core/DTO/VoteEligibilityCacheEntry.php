<?php

namespace BCC\Trust\Core\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cached eligibility verdict for a (user, vote_type) pair.
 *
 * Written by VoteEligibilityChecker after a live check and read back on
 * subsequent checks within the 120s CACHE_TTL window. Keeping the cache
 * payload in a typed readonly object lets downstream readers use typed
 * property access rather than trusting a stdClass shape.
 *
 * `reason` is always a short string (≤100 chars, truncated at write time)
 * or null when the cached verdict is "eligible".
 */
final class VoteEligibilityCacheEntry
{
    public function __construct(
        public readonly bool    $is_eligible,
        public readonly ?string $reason,
    ) {}
}
