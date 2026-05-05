<?php
/**
 * Outcome of NftGroupGateService::joinIfEligible.
 *
 * Service stays decoupled from user-facing copy: returns machine-readable
 * `code` + balance info; the REST endpoint formats the final unlock hint
 * with collection-aware copy.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class JoinResult {

    public const CODE_OK                  = 'ok';
    public const CODE_ALREADY_MEMBER      = 'already_member';
    public const CODE_NOT_ELIGIBLE        = 'not_eligible';
    public const CODE_OPT_OUT_ACTIVE      = 'opt_out_active';
    public const CODE_NOT_A_HOLDER_GROUP  = 'not_a_holder_group';
    public const CODE_CHAIN_UNSUPPORTED   = 'chain_unsupported';

    public function __construct(
        public readonly bool   $success,
        public readonly string $code,
        public readonly ?int   $minBalance = null,
        public readonly ?int   $userBalance = null,
    ) {}

    public static function ok(int $minBalance): self {
        return new self(true, self::CODE_OK, $minBalance);
    }

    public static function alreadyMember(int $minBalance): self {
        return new self(true, self::CODE_ALREADY_MEMBER, $minBalance);
    }

    public static function notEligible(int $minBalance, int $userBalance): self {
        return new self(false, self::CODE_NOT_ELIGIBLE, $minBalance, $userBalance);
    }

    public static function optOutActive(int $minBalance): self {
        return new self(false, self::CODE_OPT_OUT_ACTIVE, $minBalance);
    }

    public static function notAHolderGroup(): self {
        return new self(false, self::CODE_NOT_A_HOLDER_GROUP);
    }

    public static function chainUnsupported(int $minBalance): self {
        return new self(false, self::CODE_CHAIN_UNSUPPORTED, $minBalance);
    }
}
