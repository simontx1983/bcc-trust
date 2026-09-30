<?php

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Metadata captured for ONE contract at intake, where every field carries its
 * own state rather than collapsing into a nullable value.
 *
 * ── THE DISTINCTION THIS EXISTS TO PRESERVE ─────────────────────────────
 * `image_url = NULL` answers two completely different questions with the same
 * byte:
 *
 *   ABSENT   we asked, the provider answered, the collection has no image.
 *   UNKNOWN  we never got an answer — timeout, no API key, breaker open,
 *            malformed payload.
 *
 * The first is a fact about the collection and should be shown as "none". The
 * second is a fact about the attempt and must be shown as "not checked", and
 * must stay eligible for a later read. Flattening UNKNOWN to "none" is how a
 * failed fetch becomes a permanent lie — the row looks complete, so nothing
 * ever retries it.
 *
 * ⚠ THE WRITER MUST NOT STORE AN UNKNOWN FIELD AS NULL. It writes nothing for
 * that column, leaving whatever was there (which `addManual`'s COALESCE
 * already preserves), and records the shortfall in `metadata_state`.
 *
 * ── ROLLED-UP STATE ─────────────────────────────────────────────────────
 * `state()` summarises the fields into the value stored in
 * `onchain_collections.metadata_state`:
 *
 *   complete     every attempted field resolved (to a value or to ABSENT)
 *   partial      at least one resolved, at least one UNKNOWN
 *   unavailable  nothing resolved — the read failed as a whole
 *
 * Paired with `metadata_checked_at`, that answers "when did we last look, and
 * how far did we get?" without a caller having to infer it from NULLs.
 */
final class IntakeMetadata
{
    /** The provider answered with a value. */
    public const KNOWN = 'known';

    /** The provider answered, and the field genuinely has no value. */
    public const ABSENT = 'absent';

    /** No answer for a field that WAS applicable. Says nothing about the collection. */
    public const UNKNOWN = 'unknown';

    /**
     * This provider/family does not supply this field at all.
     *
     * ── WHY THIS IS NOT "UNKNOWN" ───────────────────────────────────────
     * UNKNOWN means "we should have been able to read this and could not" — a
     * shortfall worth retrying. NOT_APPLICABLE means "there was never anything
     * to read here", which is not a shortfall at all.
     *
     * Without the distinction, a completely successful read was permanently
     * `partial`: EVM deliberately fetches no description, and Solana
     * deliberately fetches no description or symbol, so those fields could
     * never resolve and `complete` was unreachable for two of three families.
     * A state that can never be reached tells a reader nothing.
     *
     * ⚠ NOT_APPLICABLE IS NEVER WRITTEN AS A VALUE, and never downgrades a
     * successful read.
     */
    public const NOT_APPLICABLE = 'not_applicable';

    // Rolled-up states — these are the stored `metadata_state` vocabulary.
    public const STATE_COMPLETE    = 'complete';
    public const STATE_PARTIAL     = 'partial';
    public const STATE_UNAVAILABLE = 'unavailable';

    /** The fields this object tracks, in a fixed order. */
    public const FIELDS = ['name', 'symbol', 'description', 'image_url', 'total_supply'];

    /**
     * Which fields each family's providers can actually supply.
     *
     * ── WHY THIS IS DECLARED PER FAMILY ─────────────────────────────────
     * These are not preferences, they are what the documented APIs return:
     *
     *   Cosmos  `contract_info` carries name, symbol, description and image,
     *           and the `num_tokens` probe carries supply — all five.
     *   EVM     Alchemy `getContractMetadata` carries name, symbol, image and
     *           totalSupply. It does NOT carry a collection description in a
     *           form BCC treats as collection-authored, so description is not
     *           applicable.
     *   Solana  DAS gives the collection NFT's own name and image. It does not
     *           give a collection-level symbol or description, and its
     *           `supply` object describes EDITION PRINTS of that one NFT, not
     *           the collection's item count — so neither is applicable.
     *
     * @var array<string, list<string>>
     */
    private const APPLICABLE_BY_FAMILY = [
        'cosmos' => ['name', 'symbol', 'description', 'image_url', 'total_supply'],
        'evm'    => ['name', 'symbol', 'image_url', 'total_supply'],
        // ⚠ `total_supply` IS applicable on Solana as of review round 4: the
        // verified `getAssetsByGroup` result carries a `total`, so a membership
        // count is something this family ATTEMPTS. When that number cannot be
        // proved collection-wide it is UNKNOWN — a real shortfall an operator
        // can name — which is a different thing from NOT_APPLICABLE.
        // DAS still exposes no collection-level symbol or description.
        'solana' => ['name', 'image_url', 'total_supply'],
    ];

    /** @var array<string, array{value: mixed, state: string}> */
    private array $fields = [];

    private function __construct()
    {
        foreach (self::FIELDS as $f) {
            $this->fields[$f] = ['value' => null, 'state' => self::UNKNOWN];
        }
    }

    /**
     * Start with every field UNKNOWN — the honest default before anyone has
     * asked anything.
     *
     * ⚠ Prefer {@see forFamily()} in a probe: it marks the fields that family
     * cannot supply as NOT_APPLICABLE up front, so a fully successful read
     * reports `complete` instead of being permanently `partial`.
     */
    public static function unknown(): self
    {
        return new self();
    }

    /**
     * Start with this family's unsupplyable fields already NOT_APPLICABLE.
     *
     * An unrecognised family gets everything UNKNOWN — the fail-closed
     * reading, because a family nobody declared might supply anything.
     */
    public static function forFamily(string $family): self
    {
        $m = new self();

        $applicable = self::APPLICABLE_BY_FAMILY[strtolower($family)] ?? self::FIELDS;
        foreach (self::FIELDS as $f) {
            if (!in_array($f, $applicable, true)) {
                $m->fields[$f] = ['value' => null, 'state' => self::NOT_APPLICABLE];
            }
        }

        return $m;
    }

    /**
     * Which fields a family can supply — for tests and for the admin screen's
     * "populated out of attempted" display.
     *
     * @return list<string>
     */
    public static function applicableFieldsFor(string $family): array
    {
        return self::APPLICABLE_BY_FAMILY[strtolower($family)] ?? self::FIELDS;
    }

    /**
     * Record a field the provider ANSWERED.
     *
     * A null or empty `$value` means the provider answered and the field has
     * no value → ABSENT. Anything else → KNOWN. ⚠ Callers must only reach this
     * method when a response was actually parsed; feeding it a null produced by
     * a timeout is exactly the conflation this class prevents — use
     * {@see withUnknown()} (or simply leave the field alone) for that.
     */
    public function withAnswered(string $field, mixed $value): self
    {
        if (!in_array($field, self::FIELDS, true)) {
            return $this;
        }
        // ⚠ A field this family cannot supply stays NOT_APPLICABLE. If a probe
        // somehow has a value for it, that value came from somewhere the family
        // declaration says it cannot have — most likely the wrong object, which
        // is exactly how the Solana member's name would become the
        // collection's. Refuse it rather than record it.
        if ($this->fields[$field]['state'] === self::NOT_APPLICABLE) {
            return $this;
        }
        $clone = clone $this;
        $empty = $value === null || (is_string($value) && trim($value) === '');
        $clone->fields[$field] = $empty
            ? ['value' => null, 'state' => self::ABSENT]
            : ['value' => $value, 'state' => self::KNOWN];

        return $clone;
    }

    /** Record explicitly that a field could not be read. */
    public function withUnknown(string $field): self
    {
        if (!in_array($field, self::FIELDS, true)) {
            return $this;
        }
        // Same reason as withAnswered(): a field nobody attempted is not a
        // shortfall, and marking it UNKNOWN would hold a good read at partial.
        if ($this->fields[$field]['state'] === self::NOT_APPLICABLE) {
            return $this;
        }
        $clone = clone $this;
        $clone->fields[$field] = ['value' => null, 'state' => self::UNKNOWN];

        return $clone;
    }

    public function stateOf(string $field): string
    {
        return $this->fields[$field]['state'] ?? self::UNKNOWN;
    }

    public function isKnown(string $field): bool
    {
        return $this->stateOf($field) === self::KNOWN;
    }

    /**
     * TRUE when this field needs no further attention: the provider answered
     * (KNOWN or ABSENT), or it was never applicable.
     *
     * ⚠ NOT_APPLICABLE counts as resolved DELIBERATELY. It is not a shortfall,
     * so it must not hold a successful read at `partial` forever.
     */
    public function isResolved(string $field): bool
    {
        return $this->stateOf($field) !== self::UNKNOWN;
    }

    /** Does this family's provider supply this field at all? */
    public function isApplicable(string $field): bool
    {
        return $this->stateOf($field) !== self::NOT_APPLICABLE;
    }

    /** The value, or null when ABSENT or UNKNOWN. Check the state first. */
    public function valueOf(string $field): mixed
    {
        return $this->fields[$field]['value'] ?? null;
    }

    /**
     * Only the fields safe to WRITE: those the provider actually answered with
     * a value.
     *
     * ⚠ ABSENT and UNKNOWN are both excluded, for different reasons. UNKNOWN
     * must not overwrite a stored value with null. ABSENT is a real answer, but
     * writing null for it is indistinguishable from UNKNOWN once stored, and
     * `metadata_state` already records the shortfall — so the row keeps
     * whatever it had and the state column carries the nuance.
     *
     * @return array<string, mixed>
     */
    public function writableFields(): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            if ($this->fields[$f]['state'] === self::KNOWN) {
                $out[$f] = $this->fields[$f]['value'];
            }
        }

        return $out;
    }

    /**
     * The rolled-up value for `onchain_collections.metadata_state`.
     *
     * Deliberately computed, never passed in: a caller that could set it by
     * hand could describe a failed read as `complete`.
     */
    public function state(): string
    {
        $applicable = 0;
        $resolved   = 0;

        foreach (self::FIELDS as $f) {
            if ($this->fields[$f]['state'] === self::NOT_APPLICABLE) {
                continue; // never attempted, never a shortfall
            }
            $applicable++;
            if ($this->fields[$f]['state'] !== self::UNKNOWN) {
                $resolved++;
            }
        }

        // A family with nothing applicable has nothing outstanding. That is
        // vacuously complete, not "unavailable" — `unavailable` has to mean a
        // read that was attempted and got nowhere, or it stops being
        // actionable.
        if ($applicable === 0) {
            return self::STATE_COMPLETE;
        }

        if ($resolved === 0) {
            return self::STATE_UNAVAILABLE;
        }

        return $resolved === $applicable
            ? self::STATE_COMPLETE
            : self::STATE_PARTIAL;
    }

    /** How many APPLICABLE fields resolved. */
    public function resolvedCount(): int
    {
        $n = 0;
        foreach (self::FIELDS as $f) {
            $st = $this->fields[$f]['state'];
            if ($st !== self::UNKNOWN && $st !== self::NOT_APPLICABLE) {
                $n++;
            }
        }

        return $n;
    }

    /** How many fields this family could have supplied. */
    public function applicableCount(): int
    {
        $n = 0;
        foreach (self::FIELDS as $f) {
            if ($this->fields[$f]['state'] !== self::NOT_APPLICABLE) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Per-field states, for the admin screen's "populated out of attempted"
     * display and for tests that must assert UNKNOWN specifically.
     *
     * @return array<string, string>
     */
    public function stateMap(): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = $this->fields[$f]['state'];
        }

        return $out;
    }
}
