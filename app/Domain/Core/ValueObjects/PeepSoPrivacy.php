<?php
/**
 * Mirrors PeepSo's group privacy flag (is_open / is_closed / is_secret)
 * so callers reason about a single value instead of three booleans.
 *
 * The value comes from bcc-core's `PeepSoGroupPrivacy` read (F-460): the
 * STRICTEST of the group's `peepso_group_privacy` rows, read through
 * ProbesReads rather than get_post_meta(). This enum only maps that answer,
 * so every group gate in the domain gets the same rule as bcc-core's list
 * predicate (`notSecretSql()`).
 *
 * @package BCC\Trust\Core\ValueObjects
 */

namespace BCC\Trust\Core\ValueObjects;

use BCC\Core\Repositories\PeepSoGroupPrivacy;

if (!defined('ABSPATH')) {
    exit;
}

enum PeepSoPrivacy: string {
    case Open   = 'open';
    case Closed = 'closed';
    case Secret = 'secret';

    /**
     * This group's privacy, from bcc-core's probed read.
     *
     * ⚠ Read through ProbesReads, never get_post_meta() (F-460): a read that
     * cannot be trusted answers null, and callers fail closed. This one call
     * feeds GroupContextResolver, and through it every group gate.
     *
     * See fromStoredValue() for what each answer becomes.
     */
    public static function fromGroupPostId(int $groupId): self {
        return self::fromStoredValue(PeepSoGroupPrivacy::privacyForGroup($groupId));
    }

    /**
     * The enum for one answer of `PeepSoGroupPrivacy::privacyForGroup()` /
     * `privacyForGroups()`.
     *
     *   2    → Secret
     *   1    → Closed
     *   0    → Open (also a group with no privacy row, deliberately; see
     *          bcc-core's class docblock)
     *   null → **Secret**: the read could not be trusted
     *
     * ⚠ UNKNOWN IS SECRET, the strictest answer, so an unreadable value is
     * handled exactly like a secret group at every gate. Membership is read
     * separately, so members are unaffected. An open group whose read fails
     * is briefly treated as secret for everyone else; that is the intended
     * trade.
     */
    public static function fromStoredValue(?int $value): self {
        return match ($value) {
            PeepSoGroupPrivacy::SECRET, null => self::Secret,
            PeepSoGroupPrivacy::CLOSED       => self::Closed,
            default                          => self::Open,
        };
    }
}
