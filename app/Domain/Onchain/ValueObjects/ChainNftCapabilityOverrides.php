<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE RESULT OF READING ONE CHAIN'S NFT DRIVER OVERRIDES — WHICH IS NOT THE
 * SAME THING AS THE ROWS.
 *
 * ── WHY THIS TYPE EXISTS ────────────────────────────────────────────────
 * The repository used to return a plain `list`, and an empty list meant two
 * irreconcilable things:
 *
 *   "the read succeeded and this chain has no overrides"
 *       -> registry defaults apply. Correct, and the normal case.
 *
 *   "the table is missing, the read failed, or the rows were unusable"
 *       -> we know NOTHING about what the operator configured.
 *
 * Because an absent override means "registry default applies", collapsing
 * the second case into the first is FAIL-OPEN in the worst possible way: an
 * operator disables `cosmwasm_enumeration` on a chain, the capability table
 * later becomes unreadable, and the disabled driver silently comes back —
 * defeating the entire narrow-only promise of the table, at exactly the
 * moment the system is least healthy.
 *
 * So the two are different values, and only {@see isAvailable()} ones may be
 * intersected with the registry. An unavailable result must fail the
 * capability verdict closed.
 *
 * ── UNAVAILABLE COVERS MORE THAN "THE QUERY ERRORED" ────────────────────
 * A read is unavailable when it is missing, failed, malformed, or TRUNCATED.
 * Truncation matters on its own: the query is bounded, and a bounded read
 * that hit its ceiling is a SUBSET of the operator's configuration. Applying
 * a subset would apply some restrictions and silently drop others — so a
 * partial override set is never applied.
 */
final class ChainNftCapabilityOverrides
{
    /** Read failed, the table is absent, or the driver could not answer. */
    public const REASON_READ_FAILED = 'read_failed';

    /** The bounded read came back at its ceiling — the set may be a subset. */
    public const REASON_OVERFLOW = 'overflow';

    /** A row was structurally unusable (empty operation or driver key). */
    public const REASON_MALFORMED = 'malformed';

    /** The caller asked about a chain id that cannot be resolved. */
    public const REASON_INVALID_CHAIN = 'invalid_chain';

    /**
     * @param list<array{operation: string, driver_key: string, enabled: bool, priority: int}> $rows
     */
    private function __construct(
        private readonly bool $available,
        private readonly array $rows,
        private readonly ?string $reason
    ) {
    }

    /**
     * A SUCCESSFUL read. `$rows` may legitimately be empty — that means
     * "this chain has no overrides", and registry defaults apply.
     *
     * @param list<array{operation: string, driver_key: string, enabled: bool, priority: int}> $rows
     */
    public static function loaded(array $rows): self
    {
        return new self(true, $rows, null);
    }

    /**
     * The overrides could NOT be established. Carries a coarse reason for
     * the operator log — never SQL, never connection details.
     */
    public static function unavailable(string $reason): self
    {
        return new self(false, [], $reason);
    }

    /**
     * Did we actually learn what this chain's overrides are?
     *
     * Callers MUST branch on this before touching {@see rows()}. An
     * unavailable result's `rows()` is empty, and treating that emptiness as
     * "no overrides" is the exact bug this type prevents.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * The override rows. Empty on an unavailable result — meaningless there,
     * so check {@see isAvailable()} first.
     *
     * @return list<array{operation: string, driver_key: string, enabled: bool, priority: int}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /** Coarse reason this result is unavailable, or null when it is not. */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
