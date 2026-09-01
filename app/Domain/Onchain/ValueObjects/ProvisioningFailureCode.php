<?php
/**
 * The closed vocabulary of reasons a holder community could not be created.
 *
 * ── WHY A CLOSED SET AND NOT A MESSAGE ──────────────────────────────────
 * Issue #215 proposed `provisioning_last_error VARCHAR(255)`. That is a
 * DURABLE FREE-TEXT CHANNEL, and durable free text is exactly what the PR 5b
 * audit work removed: it is where provider prose, an exception message, a SQL
 * fragment or an operator note eventually lands, and once it is in a column it
 * is in every backup and every export. {@see \BCC\Core\Security\AuditMeta}
 * refuses 22 free-text keys outright for the same reason.
 *
 * A bounded code answers the operator question that actually gets asked —
 * "which failure is this, and can I retry it?" — while the prose that helps
 * a developer stays in the short-retention file log, correlated by the audit
 * row. The two halves are deliberately different retention classes.
 *
 * ── WHY THESE EIGHT ─────────────────────────────────────────────────────
 * The first three mirror bcc-core's canonical `gated_group_provision`
 * DegradationMetrics events exactly, so the durable code and the metric never
 * disagree about what happened. The remaining five name refusals that have no
 * metric today and deliberately do not get one in PR 6: adding a fourth event
 * would need bcc-core plus pattern-registry.md plus GOLDEN_PATHS.md together,
 * and `subsystem-count-guard.php` would hold umbrella CI red until all three
 * landed. The taxonomy lives here instead.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 * @since PR 6 — collection administration and explicit provisioning
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class ProvisioningFailureCode
{
    /** PeepSoGroup class is not loaded at all; the whole sweep short-circuits. */
    public const PEEPSO_ABSENT = 'peepso_absent';

    /** No administrator user exists to own the community. */
    public const NO_ADMIN_OWNER = 'no_admin_owner';

    /** `new PeepSoGroup` returned a 0-id group, or its constructor threw. */
    public const GROUP_CREATE_FAILED = 'group_create_failed';

    /**
     * The gate metadata could not be written, or did not survive its
     * postcondition re-read. A community reaching this state has been
     * compensated — see
     * {@see \BCC\Trust\Onchain\Services\GatedGroupProvisioningService}.
     */
    public const GATE_WRITE_REFUSED = 'gate_write_refused';

    /**
     * `canonical_identifier` is NULL, or does not validate for its chain's
     * family. A gate built on it could never be satisfied, so none is built.
     */
    public const IDENTITY_UNRESOLVED = 'identity_unresolved';

    /** The collection has no name yet, so the community cannot be named. */
    public const AWAITING_METADATA = 'awaiting_metadata';

    /** Verification was withdrawn between the request and the attempt. */
    public const NOT_VERIFIED = 'not_verified';

    /** The recorded requesting administrator no longer resolves to a user. */
    public const OWNER_UNRESOLVED = 'owner_unresolved';

    /**
     * Every legal value, in a stable order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PEEPSO_ABSENT,
            self::NO_ADMIN_OWNER,
            self::GROUP_CREATE_FAILED,
            self::GATE_WRITE_REFUSED,
            self::IDENTITY_UNRESOLVED,
            self::AWAITING_METADATA,
            self::NOT_VERIFIED,
            self::OWNER_UNRESOLVED,
        ];
    }

    /** PURE. Is this string one of the eight? */
    public static function isValid(string $code): bool
    {
        return in_array($code, self::all(), true);
    }

    /**
     * The longest legal code, used to prove the column is wide enough.
     *
     * A test asserts this against the VARCHAR(32) declaration rather than
     * trusting that nobody ever adds a longer one.
     */
    public static function maxLength(): int
    {
        $max = 0;
        foreach (self::all() as $code) {
            $max = max($max, strlen($code));
        }

        return $max;
    }

    /**
     * Operator-facing label. Deliberately plain, and deliberately NOT
     * derived from the code by string munging — an operator reads this,
     * and "peepso absent" is not a sentence.
     */
    public static function label(string $code): string
    {
        switch ($code) {
            case self::PEEPSO_ABSENT:
                return 'PeepSo Groups is not active';
            case self::NO_ADMIN_OWNER:
                return 'No administrator available to own the community';
            case self::GROUP_CREATE_FAILED:
                return 'Community creation failed';
            case self::GATE_WRITE_REFUSED:
                return 'Gate configuration could not be written; the community was removed';
            case self::IDENTITY_UNRESOLVED:
                return 'The collection has no resolved on-chain identity';
            case self::AWAITING_METADATA:
                return 'The collection is still awaiting a name';
            case self::NOT_VERIFIED:
                return 'Verification was removed before the community was created';
            case self::OWNER_UNRESOLVED:
                return 'The requesting administrator could not be resolved';
            default:
                // Never string-munge an unknown code into a label: that would
                // present a value the vocabulary does not contain as though
                // it were legitimate.
                return 'Unrecognised failure code';
        }
    }
}
