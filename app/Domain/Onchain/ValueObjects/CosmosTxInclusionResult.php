<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The answer to "was this transaction included?" for a memo claim.
 *
 *   included         both endpoints agree: code 0, same height, same block
 *                    time, byte-identical bytes ({@see $inclusion} is set)
 *   not_yet          anything short of that: not indexed yet on either node,
 *                    a node unavailable or off-network, a budget or deadline
 *                    run out, an open breaker, or the nodes disagreeing.
 *                    The member may submit again; nothing is decided.
 *   failed_on_chain  both nodes agree the transaction ran and FAILED
 *                    ({@see $txCode} is its non-zero code)
 *   unsupported      no proof endpoints for this chain (yet)
 *   invalid_hash     not 64 hex characters (no request was made)
 *   invalid_validator the operator address is not this chain's valoper
 *                    (no request was made)
 */
final class CosmosTxInclusionResult
{
    public const INCLUDED          = 'included';
    public const NOT_YET           = 'not_yet';
    public const FAILED_ON_CHAIN   = 'failed_on_chain';
    public const UNSUPPORTED       = 'unsupported';
    public const INVALID_HASH      = 'invalid_hash';
    public const INVALID_VALIDATOR = 'invalid_validator';

    private function __construct(
        public readonly string $state,
        public readonly ?CosmosTxInclusion $inclusion = null,
        public readonly ?int $txCode = null,
    ) {
    }

    public static function included(CosmosTxInclusion $inclusion): self
    {
        return new self(self::INCLUDED, $inclusion);
    }

    public static function notYet(): self
    {
        return new self(self::NOT_YET);
    }

    public static function failedOnChain(int $code): self
    {
        return new self(self::FAILED_ON_CHAIN, null, $code);
    }

    public static function unsupported(): self
    {
        return new self(self::UNSUPPORTED);
    }

    public static function invalidHash(): self
    {
        return new self(self::INVALID_HASH);
    }

    public static function invalidValidator(): self
    {
        return new self(self::INVALID_VALIDATOR);
    }

    public function isIncluded(): bool
    {
        return $this->state === self::INCLUDED && $this->inclusion !== null;
    }
}
