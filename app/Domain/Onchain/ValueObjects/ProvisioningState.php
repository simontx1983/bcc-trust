<?php
/**
 * The closed state machine for holder-community provisioning intent.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * Before PR 6, `is_verified = 1` did double duty: it was both the
 * ELIGIBILITY predicate for a holder community and the AUTHORIZATION to
 * create one. `GatedGroupProvisioningService::provisionAll()` enumerated
 * `CollectionRepository::listVerified()`, and that enumeration WAS the
 * decision — so ticking Verify created a live PeepSo community within ~24h
 * with no second approval (issue #215).
 *
 * This class is the durable record of an operator having actually asked.
 * Verification stays NECESSARY; it stops being SUFFICIENT.
 *
 * ── WHY A TRANSITION TABLE AND NOT FOUR STRING CONSTANTS ────────────────
 * The interesting invariants are not "which values exist" but "which moves
 * are legal", and those are easy to violate one caller at a time. Encoding
 * them once, here, means a new caller cannot invent `provisioned -> failed`
 * or resurrect a withdrawn request without this file saying so. The
 * companion field rules ({@see fieldViolations()}) are in the same place for
 * the same reason: a `failed` row that has lost its requester is a
 * contradictory row, and nothing else in the tree would notice.
 *
 * ── THE ONE ASYMMETRY, STATED ───────────────────────────────────────────
 * `provisioned` is a terminal state with respect to unverification.
 * Removing verification withdraws a PENDING request (`requested`/`failed`
 * -> `none`) but NEVER touches `provisioned`: the community exists, people
 * may be in it, and deleting or archiving it is not something a checkbox
 * should do. That asymmetry is deliberate and is issue #215's stated rule.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 * @since PR 6 — collection administration and explicit provisioning
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class ProvisioningState
{
    /** No operator has asked for a community. The default for every row. */
    public const NONE = 'none';

    /** An administrator explicitly asked. Carries who, and when. */
    public const REQUESTED = 'requested';

    /** A live, gated community exists for this collection. */
    public const PROVISIONED = 'provisioned';

    /** An attempt was made and refused or failed. Retryable, and visible. */
    public const FAILED = 'failed';

    /**
     * Every legal value, in lifecycle order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::NONE, self::REQUESTED, self::PROVISIONED, self::FAILED];
    }

    /** PURE. Is this string one of the four? */
    public static function isValid(string $state): bool
    {
        return in_array($state, self::all(), true);
    }

    /**
     * The complete legal transition table.
     *
     * Read as: from => list of states it may move to. A state is always
     * allowed to stay where it is ONLY where that is a meaningful idempotent
     * no-op, so the self-edges below are explicit rather than universal:
     * `provisioned -> provisioned` is a real, expected repeat request,
     * whereas `requested -> requested` is also legitimate (asking twice) but
     * `failed -> failed` is NOT — a retry goes through `requested`, so that a
     * second attempt is always preceded by recorded intent.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::NONE => [
                self::REQUESTED,   // operator asks
            ],
            self::REQUESTED => [
                self::REQUESTED,   // asking twice is idempotent
                self::PROVISIONED, // the attempt succeeded
                self::FAILED,      // the attempt was refused or failed
                self::NONE,        // verification removed -> intent withdrawn
            ],
            self::FAILED => [
                self::REQUESTED,   // retry — always via recorded intent
                self::NONE,        // verification removed -> intent withdrawn
            ],
            self::PROVISIONED => [
                self::PROVISIONED, // repeat request is a no-op
            ],
        ];
    }

    /**
     * PURE. May `$from` legally become `$to`?
     *
     * An unknown state on either side is not a transition question, it is a
     * corrupt-data question, and the answer is no.
     */
    public static function canTransition(string $from, string $to): bool
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }

        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    /**
     * PURE. Check the per-state field invariants.
     *
     * These are the rules that make a row self-consistent, independent of how
     * it got there. They are returned as a list of machine-readable reasons
     * rather than a bool so a test — and the `needs_attention` tab — can say
     * WHICH invariant a contradictory row breaks.
     *
     * ── THE ONE DELIBERATE EXEMPTION ────────────────────────────────────
     * `provisioned` normally carries the requester who authorized it. Rows
     * backfilled by the PR 6 migration cannot: those 28 communities predate
     * the concept of a request entirely, and inventing a requester for them
     * would be fabricating an authorization that never happened. So
     * `provisioned` accepts a NULL requester, and only that state does.
     * A NULL requester on `requested` or `failed` is a genuine contradiction.
     *
     * @param string      $state
     * @param string|null $requestedAt  DATETIME string, or null
     * @param int|null    $requestedBy  user id, or null
     * @param string|null $failureCode  bounded code, or null
     * @return list<string> empty when the row is consistent
     */
    public static function fieldViolations(
        string $state,
        ?string $requestedAt,
        ?int $requestedBy,
        ?string $failureCode
    ): array {
        $violations = [];

        if (!self::isValid($state)) {
            return ['unknown_state'];
        }

        // A requester id of 0 is not "absent", it is a bad write. Treat it as
        // present-but-invalid so it cannot masquerade as the NULL exemption.
        $hasRequester = $requestedBy !== null;
        $hasStamp     = $requestedAt !== null && $requestedAt !== '';
        $hasCode      = $failureCode !== null && $failureCode !== '';

        if ($hasRequester && $requestedBy <= 0) {
            $violations[] = 'requested_by_not_positive';
        }

        if ($hasCode && !ProvisioningFailureCode::isValid((string) $failureCode)) {
            $violations[] = 'failure_code_outside_vocabulary';
        }

        switch ($state) {
            case self::NONE:
                if ($hasStamp)     { $violations[] = 'none_with_requested_at'; }
                if ($hasRequester) { $violations[] = 'none_with_requested_by'; }
                if ($hasCode)      { $violations[] = 'none_with_failure_code'; }
                break;

            case self::REQUESTED:
                if (!$hasStamp)     { $violations[] = 'requested_without_requested_at'; }
                if (!$hasRequester) { $violations[] = 'requested_without_requested_by'; }
                if ($hasCode)       { $violations[] = 'requested_with_failure_code'; }
                break;

            case self::FAILED:
                // The requester is PRESERVED across a failure — that is how a
                // retry knows who authorized it, and how the audit trail stays
                // attributable when the attempt is repeated.
                if (!$hasStamp)     { $violations[] = 'failed_without_requested_at'; }
                if (!$hasRequester) { $violations[] = 'failed_without_requested_by'; }
                if (!$hasCode)      { $violations[] = 'failed_without_failure_code'; }
                break;

            case self::PROVISIONED:
                if ($hasCode) { $violations[] = 'provisioned_with_failure_code'; }
                // requester/stamp may be NULL — migration-backfilled legacy
                // communities only. See the docblock above. When one is
                // present the other must be too, or the row is half-written.
                if ($hasRequester !== $hasStamp) {
                    $violations[] = 'provisioned_with_partial_requester';
                }
                break;
        }

        return $violations;
    }

    /**
     * Operator-facing label for a state. Plain words, not the raw token.
     */
    public static function label(string $state): string
    {
        switch ($state) {
            case self::NONE:
                return 'No community requested';
            case self::REQUESTED:
                return 'Community requested';
            case self::PROVISIONED:
                return 'Community created';
            case self::FAILED:
                return 'Community creation failed';
            default:
                return 'Unrecognised provisioning state';
        }
    }
}
