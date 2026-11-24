<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A validator's on-chain `description.security_contact`, as read for a memo
 * claim's warning email.
 *
 * It is the one input a single lying endpoint could use to redirect that
 * warning, so it counts only when every proof endpoint returns the SAME
 * value (outer whitespace trimmed):
 *
 *   agreed       every endpoint returned this non-empty value ({@see $value})
 *   unset        every endpoint returned an empty field
 *   disagreed    the endpoints returned different values (no value is given)
 *   unavailable  an endpoint could not be read, or the field was unreadable
 *                or over-long (no value is given)
 *
 * The field is free text and is often a URL. Whether `agreed` is an email
 * address, and what to do otherwise (no email, flag for staff), is the
 * caller's decision, not this object's.
 */
final class CosmosSecurityContact
{
    public const AGREED      = 'agreed';
    public const NOT_SET     = 'unset';
    public const DISAGREED   = 'disagreed';
    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $state,
        public readonly ?string $value = null,
    ) {
    }

    public static function agreed(string $value): self
    {
        return new self(self::AGREED, $value);
    }

    public static function notSet(): self
    {
        return new self(self::NOT_SET);
    }

    public static function disagreed(): self
    {
        return new self(self::DISAGREED);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }
}
