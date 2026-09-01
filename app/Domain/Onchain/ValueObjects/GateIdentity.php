<?php
/**
 * The resolved on-chain identity a holder gate is actually gating on.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * A holder gate carries two things that look like an identity:
 *
 *   `_bcc_gate_contract_address`  post meta, historically lower-cased,
 *                                 and on the eight production Solana
 *                                 gates it is a Magic Eden SYMBOL, not
 *                                 an address at all.
 *   `_bcc_gate_collection_id`     a foreign key to the collections row,
 *                                 whose `canonical_identifier` column is
 *                                 the chain-aware validated identity.
 *
 * Before PR 5b the gate evaluated on the FIRST of those. A symbol can
 * never equal the mint Solana DAS returns in `grouping[].group_value`, so
 * the holdings count was structurally always `0` — and a `0` from a
 * successful provider call is a REAL count, which `eligibilityVerdict`
 * turned into INELIGIBLE. The gate said "you do not hold this" with
 * confidence, having asked a question that had no valid answer.
 *
 * This value object makes the collection row authoritative and makes the
 * unresolved case a FIRST-CLASS OUTCOME rather than a silent zero.
 *
 * ── WHY A TYPED RESULT AND NOT `?string` ────────────────────────────────
 * Same reason {@see NftCollectionIdentity} is not a nullable string: a
 * `null` collapses "this gate cannot be evaluated" into "this gate has no
 * identity", and a caller that forgets to check would pass the null
 * onward to a provider. A refusal here carries a machine-readable reason
 * and `canonical()` throws rather than returning anything at all.
 *
 * ── ONE REASON, DELIBERATELY ────────────────────────────────────────────
 * Every unresolvable shape — missing link, duplicated meta, contradictory
 * chain, invalid identifier, wrong chain, unloadable row — reports the
 * SAME public reason, `collection_identity_unresolved`. The distinctions
 * matter to an operator reading the log, not to the caller deciding what
 * to do, and they must never reach an end user: which particular way a
 * gate is misconfigured is not something the wire should narrate. The
 * specific detail goes to the file log via GateIdentityResolver.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 * @since PR 5b — Solana holder-gate identity repair
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class GateIdentity
{
    /**
     * The single public reason a gate could not be evaluated.
     *
     * Deliberately NOT "provider unavailable": nothing was asked of any
     * provider, and telling a client to retry would be a lie — retrying
     * cannot resolve a misconfigured gate. An operator repair can.
     */
    public const REASON_UNRESOLVED = 'collection_identity_unresolved';

    private function __construct(
        private readonly bool $resolved,
        private readonly ?string $canonicalIdentifier,
        private readonly ?string $chainSlug,
        private readonly ?string $chainFamily,
        private readonly ?int $collectionId,
        private readonly string $reason,
    ) {
    }

    public static function resolved(
        string $canonicalIdentifier,
        string $chainSlug,
        string $chainFamily,
        int $collectionId
    ): self {
        return new self(
            true,
            $canonicalIdentifier,
            $chainSlug,
            $chainFamily,
            $collectionId,
            ''
        );
    }

    public static function unresolved(): self
    {
        return new self(false, null, null, null, null, self::REASON_UNRESOLVED);
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * The validated, chain-aware canonical identity to hand a provider.
     *
     * @throws \LogicException when called on an unresolved identity — a
     *         caller that does not check `isResolved()` first must fail
     *         loudly, never reach a provider with a bad question.
     */
    public function canonical(): string
    {
        if (!$this->resolved || $this->canonicalIdentifier === null) {
            throw new \LogicException(
                'GateIdentity::canonical() called on an unresolved gate identity'
            );
        }

        return $this->canonicalIdentifier;
    }

    /** @throws \LogicException when unresolved. */
    public function chainSlug(): string
    {
        if (!$this->resolved || $this->chainSlug === null) {
            throw new \LogicException(
                'GateIdentity::chainSlug() called on an unresolved gate identity'
            );
        }

        return $this->chainSlug;
    }

    /** @throws \LogicException when unresolved. */
    public function chainFamily(): string
    {
        if (!$this->resolved || $this->chainFamily === null) {
            throw new \LogicException(
                'GateIdentity::chainFamily() called on an unresolved gate identity'
            );
        }

        return $this->chainFamily;
    }

    /** @throws \LogicException when unresolved. */
    public function collectionId(): int
    {
        if (!$this->resolved || $this->collectionId === null) {
            throw new \LogicException(
                'GateIdentity::collectionId() called on an unresolved gate identity'
            );
        }

        return $this->collectionId;
    }

    /** Machine-readable reason; empty string when resolved. */
    public function reason(): string
    {
        return $this->reason;
    }
}
