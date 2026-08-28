<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE OUTCOME OF ONE WRITE — WHICH IS THREE ANSWERS, NOT TWO.
 *
 * ── WHY `bool` IS NOT ENOUGH ────────────────────────────────────────────
 * Every write helper in this codebase used to return `$result !== false`,
 * and that collapses three genuinely different facts into one:
 *
 *   false      the database REFUSED — nothing happened, and we know it
 *   0 rows     the statement ran and changed NOTHING
 *   1..n rows  the statement ran and the row moved
 *
 * A boolean answers `true` to the middle two alike, and the capability
 * editor cannot be built on that. It has to promise three things that all
 * hinge on the distinction:
 *
 *   • a genuine no-op writes NO audit event and bumps NO generation;
 *   • a mutation bumps the generation EVEN IF the postcondition read then
 *     fails, because the database really did move and a cache holding the
 *     previous generation is now wrong;
 *   • a refusal is never reported as either.
 *
 * ── AND WHY A PRE-READ CANNOT SUBSTITUTE ────────────────────────────────
 * "Read first, and if the row already says what we want, call it a no-op"
 * looks equivalent and is not: between that read and the write, a
 * concurrent request can apply the same change. The pre-read says "we will
 * mutate", the write reports 0 rows, and the caller bumps a generation and
 * writes an audit event for work another request did. The affected-row
 * count is the only observation of what THIS statement did, so it is the
 * one this type carries.
 *
 * ── AN UNKNOWABLE COUNT IS TREATED AS A MUTATION ────────────────────────
 * `wpdb::query()` returns `int|bool`. Our statements are all DML and yield
 * an int, but a `true` — a driver quirk, a shim, a DDL path — means "it
 * ran and I cannot tell you how much". {@see fromWpdb()} calls that a
 * mutation rather than a no-op: over-bumping a generation costs one cache
 * miss, while a missed bump serves a stale answer indefinitely, and
 * claiming "nothing changed" about a statement we cannot account for is
 * the one reading with no safe failure mode.
 */
final class RepositoryWriteResult
{
    private function __construct(
        private readonly bool $executed,
        private readonly int $affectedRows
    ) {
    }

    /** The database refused the statement. Nothing changed. */
    public static function failure(): self
    {
        return new self(false, 0);
    }

    /** The statement ran and affected this many rows (may legitimately be 0). */
    public static function executed(int $affectedRows): self
    {
        return new self(true, max(0, $affectedRows));
    }

    /**
     * Map a raw `wpdb::query()` return.
     *
     * `=== false` is the ONLY failure test: `wpdb::query()` returns 0 — a
     * falsy int — from a statement that ran perfectly and matched nothing,
     * so a truthiness check reads every successful no-op as an error. Same
     * trap the capability table's migration documents at its `ALTER`.
     *
     * @param mixed $result whatever `wpdb::query()` handed back
     */
    public static function fromWpdb(mixed $result): self
    {
        if ($result === false) {
            return self::failure();
        }
        if (is_int($result)) {
            return self::executed($result);
        }

        // Ran, count unknown — see the class docblock.
        return self::executed(1);
    }

    /** The database refused. Never report this as a change OR as a no-op. */
    public function isFailure(): bool
    {
        return !$this->executed;
    }

    /** The statement ran AND moved at least one row. */
    public function mutated(): bool
    {
        return $this->executed && $this->affectedRows > 0;
    }

    /**
     * The statement ran and changed nothing.
     *
     * NOT on its own a licence to report "already in the desired state" —
     * the caller must still re-read and confirm the state it wanted is the
     * state that is there. A `DELETE` matching no row and a `DELETE` racing
     * another request both land here.
     */
    public function isNoOp(): bool
    {
        return $this->executed && $this->affectedRows === 0;
    }

    public function affectedRows(): int
    {
        return $this->affectedRows;
    }
}
