<?php
/**
 * Result of asking NftCollectionIdentifier to canonicalise a collection
 * identifier.
 *
 * ── WHY A TYPED RESULT AND NOT `?string` ────────────────────────────────
 * A nullable string collapses two answers that must never be confused:
 *
 *     "this is not a collection identity"   -> refuse the write
 *     "this row has no canonical identity"  -> legacy, leave it alone
 *
 * `canonical_identifier` is NULLable in the database precisely because the
 * second state exists (99 legacy Magic Eden aliases, 24 of them verified and
 * community-linked, quarantined for PR 5b). If the service returned `null`
 * for a refusal, a caller that forgot to check would write that `null`
 * straight into the column and silently manufacture a new legacy row.
 *
 * So a refusal is a distinct object carrying a machine-readable reason, and
 * `canonical()` on a refusal throws rather than returning anything at all.
 *
 * @package BCC\Trust\Onchain\Support
 * @since PR 5a — canonical NFT collection identity
 */

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class NftCollectionIdentity
{
    // ── Refusal reasons (stable, non-secret, safe to log/count) ─────────
    public const REASON_EMPTY              = 'empty';
    public const REASON_TOO_LONG           = 'too_long';
    public const REASON_UNSUPPORTED_FAMILY = 'unsupported_family';
    public const REASON_BAD_EVM_SHAPE      = 'bad_evm_shape';
    public const REASON_BAD_BECH32         = 'bad_bech32';
    public const REASON_NOT_BASE58_MINT    = 'not_base58_mint';

    private function __construct(
        private readonly bool $accepted,
        private readonly ?string $canonical,
        private readonly string $reason
    ) {
    }

    public static function accept(string $canonical): self
    {
        return new self(true, $canonical, '');
    }

    public static function refuse(string $reason): self
    {
        return new self(false, null, $reason);
    }

    public function isAccepted(): bool
    {
        return $this->accepted;
    }

    /**
     * The canonical database identity.
     *
     * @throws \LogicException when called on a refusal — a caller that does
     *         not check `isAccepted()` first must fail loudly, never write.
     */
    public function canonical(): string
    {
        if (!$this->accepted || $this->canonical === null) {
            throw new \LogicException(
                'NftCollectionIdentity::canonical() called on a refusal (' . $this->reason . ')'
            );
        }

        return $this->canonical;
    }

    /** Machine-readable refusal reason; empty string when accepted. */
    public function reason(): string
    {
        return $this->reason;
    }
}
