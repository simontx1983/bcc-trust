<?php

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The bounded answer to ONE question: "is this one submitted address an NFT
 * collection contract we can support?"
 *
 * ── WHY FOUR STATES AND NOT A BOOLEAN ───────────────────────────────────
 * A boolean forces every unhappy path into "no", and "no" reads to an
 * administrator as *this is not an NFT collection*. That is a claim about the
 * contract. Most failures are claims about US: no API key configured, the node
 * timed out, the breaker is open, the chain has no wasm module. Recording any
 * of those as a negative authenticity verdict is the defect this vocabulary
 * exists to make impossible.
 *
 * So the four states split along one axis — **did we learn anything about the
 * contract?**
 *
 *   VALID        we asked, it answered, it is an NFT contract.
 *   INVALID      we asked, it answered, it is NOT an NFT contract.
 *   UNSUPPORTED  we asked, it answered, and the answer is a standard or a
 *                shape BCC has deliberately not taken on yet (ERC-1155
 *                gating is deferred; a chain with no wasm module).
 *   UNAVAILABLE  we could not ask, or could not understand the answer.
 *                **This says nothing about the contract.**
 *
 * ⚠ Only VALID may create a collection row. INVALID records a decided negative.
 * UNSUPPORTED and UNAVAILABLE write nothing at all — an operator may retry
 * later and get a different answer, and a stored row would outlive the
 * condition that produced it.
 *
 * ── EVIDENCE IS BOUNDED TOKENS, NEVER PROVIDER TEXT ─────────────────────
 * `evidence` is a list from a closed vocabulary. Free provider text is exactly
 * what PR 5b removed from durable storage: it is unbounded, it can carry an
 * address or a key fragment, and it invites a renderer to echo it. A token
 * says what happened without quoting anyone.
 */
final class ContractValidationVerdict
{
    /** The contract answered and is an NFT collection contract. */
    public const VALID = 'valid';

    /** The contract answered and is not an NFT collection contract. */
    public const INVALID = 'invalid';

    /** The contract answered, but with a standard/shape BCC does not support yet. */
    public const UNSUPPORTED = 'unsupported';

    /** We could not obtain an answer. NOT a statement about the contract. */
    public const UNAVAILABLE = 'unavailable';

    // ── Evidence tokens, closed vocabulary ──────────────────────────────

    public const EV_INTERFACE_CONFIRMED   = 'interface_confirmed';
    public const EV_INTERFACE_DENIED      = 'interface_denied';
    public const EV_PROBE_ANSWERED        = 'probe_answered';
    public const EV_PROBE_REFUSED         = 'probe_refused';
    public const EV_NO_CODE_AT_ADDRESS    = 'no_code_at_address';
    public const EV_STANDARD_DEFERRED     = 'standard_deferred';
    public const EV_CHAIN_HAS_NO_WASM     = 'chain_has_no_wasm';
    public const EV_GROUPING_UNVERIFIED   = 'grouping_unverified';
    public const EV_CREDENTIALS_MISSING   = 'credentials_missing';
    public const EV_PROVIDER_TIMEOUT      = 'provider_timeout';
    public const EV_PROVIDER_RATE_LIMITED = 'provider_rate_limited';
    public const EV_PROVIDER_ERROR        = 'provider_error';
    public const EV_BREAKER_OPEN          = 'breaker_open';
    public const EV_MALFORMED_RESPONSE    = 'malformed_response';
    public const EV_BUDGET_EXHAUSTED      = 'budget_exhausted';
    public const EV_FAMILY_UNSUPPORTED    = 'family_unsupported';

    /** @var list<string> */
    private const EVIDENCE = [
        self::EV_INTERFACE_CONFIRMED,
        self::EV_INTERFACE_DENIED,
        self::EV_PROBE_ANSWERED,
        self::EV_PROBE_REFUSED,
        self::EV_NO_CODE_AT_ADDRESS,
        self::EV_STANDARD_DEFERRED,
        self::EV_CHAIN_HAS_NO_WASM,
        self::EV_GROUPING_UNVERIFIED,
        self::EV_CREDENTIALS_MISSING,
        self::EV_PROVIDER_TIMEOUT,
        self::EV_PROVIDER_RATE_LIMITED,
        self::EV_PROVIDER_ERROR,
        self::EV_BREAKER_OPEN,
        self::EV_MALFORMED_RESPONSE,
        self::EV_BUDGET_EXHAUSTED,
        self::EV_FAMILY_UNSUPPORTED,
    ];

    /** @var list<string> */
    private array $evidence;

    /**
     * @param list<string> $evidence bounded tokens; unknown ones are dropped
     */
    private function __construct(
        private string $state,
        private ?string $standard,
        private ?IntakeMetadata $metadata,
        array $evidence
    ) {
        $clean = [];
        foreach ($evidence as $token) {
            if (is_string($token) && in_array($token, self::EVIDENCE, true) && !in_array($token, $clean, true)) {
                $clean[] = $token;
            }
        }
        $this->evidence = $clean;
    }

    /**
     * @param list<string> $evidence
     */
    public static function valid(string $standard, IntakeMetadata $metadata, array $evidence = []): self
    {
        return new self(self::VALID, $standard, $metadata, $evidence);
    }

    /**
     * @param list<string> $evidence
     */
    public static function invalid(array $evidence = []): self
    {
        return new self(self::INVALID, null, null, $evidence);
    }

    /**
     * A decided answer BCC has chosen not to act on yet.
     *
     * @param list<string> $evidence
     */
    public static function unsupported(?string $standard, array $evidence = []): self
    {
        return new self(self::UNSUPPORTED, $standard, null, $evidence);
    }

    /**
     * ⚠ THE FAIL-CLOSED CONSTRUCTOR. Every provider failure lands here.
     *
     * @param list<string> $evidence
     */
    public static function unavailable(array $evidence = []): self
    {
        return new self(self::UNAVAILABLE, null, null, $evidence);
    }

    public function state(): string
    {
        return $this->state;
    }

    /** Only a VALID verdict may create a collection row. */
    public function isValid(): bool
    {
        return $this->state === self::VALID;
    }

    /**
     * Did we learn something about the CONTRACT itself?
     *
     * TRUE for VALID, INVALID and UNSUPPORTED — all three are answers. FALSE
     * for UNAVAILABLE, which is an answer about us. A surface that renders a
     * verdict must not say "not an NFT" unless this is true.
     */
    public function isDecided(): bool
    {
        return $this->state !== self::UNAVAILABLE;
    }

    /**
     * May this verdict write anything durable about the contract?
     *
     * Only VALID. An UNSUPPORTED or UNAVAILABLE answer can change on the next
     * attempt — a stored row would outlive the condition that produced it.
     */
    public function mayPersist(): bool
    {
        return $this->state === self::VALID;
    }

    /** ERC-721, ERC-1155, CW-721 … null when nothing was established. */
    public function standard(): ?string
    {
        return $this->standard;
    }

    /** Metadata captured alongside a VALID verdict; null otherwise. */
    public function metadata(): ?IntakeMetadata
    {
        return $this->metadata;
    }

    /** @return list<string> bounded tokens, safe to log and to render */
    public function evidence(): array
    {
        return $this->evidence;
    }

    public function hasEvidence(string $token): bool
    {
        return in_array($token, $this->evidence, true);
    }
}
