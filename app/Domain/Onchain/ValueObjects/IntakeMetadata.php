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

    /** No answer. Says nothing about the collection. */
    public const UNKNOWN = 'unknown';

    // Rolled-up states — these are the stored `metadata_state` vocabulary.
    public const STATE_COMPLETE    = 'complete';
    public const STATE_PARTIAL     = 'partial';
    public const STATE_UNAVAILABLE = 'unavailable';

    /** The fields this object tracks, in a fixed order. */
    public const FIELDS = ['name', 'symbol', 'description', 'image_url', 'total_supply'];

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
     */
    public static function unknown(): self
    {
        return new self();
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

    /** TRUE when the provider answered — whether or not there was a value. */
    public function isResolved(string $field): bool
    {
        return $this->stateOf($field) !== self::UNKNOWN;
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
        $resolved = 0;
        foreach (self::FIELDS as $f) {
            if ($this->fields[$f]['state'] !== self::UNKNOWN) {
                $resolved++;
            }
        }

        if ($resolved === 0) {
            return self::STATE_UNAVAILABLE;
        }

        return $resolved === count(self::FIELDS)
            ? self::STATE_COMPLETE
            : self::STATE_PARTIAL;
    }

    /** How many fields were resolved, out of how many attempted. */
    public function resolvedCount(): int
    {
        $n = 0;
        foreach (self::FIELDS as $f) {
            if ($this->fields[$f]['state'] !== self::UNKNOWN) {
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
