<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded operational faults, and bounded refusals.
 *
 * ── WHY THESE ARE NOT stop_reason VALUES ────────────────────────────────
 * `CosmwasmPassStopReason` describes why a PASS stopped — it is a statement
 * about discovery work that actually ran. These describe why the LEDGER
 * could not carry a run forward, or why a request was never created at all.
 * Keeping them in a separate column means an operator can tell "the pass
 * ran and hit its ceiling" apart from "the executor never came back",
 * without either vocabulary having to grow a member that means the other.
 *
 * ── WHY THERE IS NO FREE-TEXT COLUMN ────────────────────────────────────
 * PR 5b removed the durable free-text channel from the audit trail because
 * it is where provider prose and exception messages eventually land. The
 * same rule applies here: every explanation is a closed token in
 * VARCHAR(40), and the detail belongs in the short-retention application
 * log, correlated by run uuid.
 */
final class DiscoveryRunError
{
    // ── Faults that end a run ───────────────────────────────────────────

    /** The executor claimed the run the maximum number of times. */
    public const MAX_ATTEMPTS_EXHAUSTED = 'max_attempts_exhausted';

    /** The pass threw. Detail is in the file log, never here. */
    public const EXECUTION_FAILED = 'execution_failed';

    /** A checkpoint or ledger read failed mid-run; nothing was concluded. */
    public const READ_UNAVAILABLE = 'read_unavailable';

    /**
     * The terminal write was issued and NOT confirmed.
     *
     * The one fault that must never be reported as success. The run stays
     * leased, the lease expires, and the reaper returns it — which is the
     * only safe response to "we do not know whether the result landed".
     */
    public const TERMINAL_WRITE_UNCONFIRMED = 'terminal_write_unconfirmed';

    /** PeepSo/driver prerequisites vanished between request and execution. */
    public const CHAIN_NOT_READY = 'chain_not_ready';

    // ── Refusals: no row is created, nothing is contacted ───────────────

    /** No such chain, or it is inactive. */
    public const CHAIN_UNKNOWN = 'chain_unknown';

    /** The chain family has no discovery driver. */
    public const CHAIN_UNSUPPORTED = 'chain_unsupported';

    /** `cosmwasm_nft_discovery_enabled` is off, or unreadable. */
    public const DISCOVERY_DISABLED = 'discovery_disabled';

    /** A run for this chain and job kind is already active. */
    public const ALREADY_ACTIVE = 'already_active';

    /** Repeated insert/read races; the caller should simply try again. */
    public const CONTENTION = 'contention';

    /** The named user does not exist or lacks `manage_options`. */
    public const OPERATOR_UNRESOLVED = 'operator_unresolved';

    /** The ledger insert itself failed. No run id was issued. */
    public const QUEUE_WRITE_FAILED = 'queue_write_failed';

    /** The checked authorization audit could not be committed. */
    public const AUDIT_UNCOMMITTED = 'audit_uncommitted';

    /** Not a requestable job kind, or an unknown scan mode. */
    public const UNSUPPORTED_REQUEST = 'unsupported_request';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::MAX_ATTEMPTS_EXHAUSTED,
            self::EXECUTION_FAILED,
            self::READ_UNAVAILABLE,
            self::TERMINAL_WRITE_UNCONFIRMED,
            self::CHAIN_NOT_READY,
            self::CHAIN_UNKNOWN,
            self::CHAIN_UNSUPPORTED,
            self::DISCOVERY_DISABLED,
            self::ALREADY_ACTIVE,
            self::CONTENTION,
            self::OPERATOR_UNRESOLVED,
            self::QUEUE_WRITE_FAILED,
            self::AUDIT_UNCOMMITTED,
            self::UNSUPPORTED_REQUEST,
        ];
    }

    public static function isValid(string $code): bool
    {
        return in_array($code, self::all(), true);
    }

    /** Longest value, proving VARCHAR(40) is sufficient. */
    public static function maxLength(): int
    {
        // Fold over a guaranteed-non-empty seed, for the same reason as
        // DiscoveryJobKind::maxLength().
        $longest = 0;
        foreach (self::all() as $code) {
            $longest = max($longest, strlen($code));
        }

        return $longest;
    }
}
