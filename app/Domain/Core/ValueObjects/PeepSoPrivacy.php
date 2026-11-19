<?php
/**
 * Mirrors PeepSo's group privacy flag (is_open / is_closed / is_secret)
 * so callers reason about a single value instead of three booleans.
 *
 * ⚠ A group can carry MORE THAN ONE `peepso_group_privacy` row
 * (`add_post_meta` without `$unique`, a migration, a direct DB edit), and
 * this resolver answers for every group gate in the domain, so it takes the
 * STRICTEST row rather than the first one. See fromGroupPostId().
 *
 * @package BCC\Trust\Core\ValueObjects
 */

namespace BCC\Trust\Core\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

enum PeepSoPrivacy: string {
    case Open   = 'open';
    case Closed = 'closed';
    case Secret = 'secret';

    /**
     * PeepSo stores privacy as `peepso_group_privacy` post meta with
     * integer values 0/1/2 (PeepSoGroupPrivacy::PRIVACY_OPEN/CLOSED/SECRET).
     * The `is_open` / `is_closed` / `is_secret` booleans are derived
     * object properties on PeepSoGroup, not separate meta keys.
     *
     * ⚠ READS EVERY ROW AND TAKES THE STRICTEST (F-429 follow-up). This
     * used to pass `$single = true`, which returns only the FIRST row by
     * `meta_id`. A group carrying '0' and then '2' therefore resolved to
     * **Open** — and because this one call feeds GroupContextResolver, that
     * answer reached every group gate at once: `resolveGroupAccess` skipped
     * the secret 404, so a non-member could open a secret group by id or
     * slug and read its `public_group` / `public_all` teasers, and feed
     * hydration emitted its name and link. The list and search reads had
     * already been fixed to mean "ANY row reads 2"
     * (bcc-core `PeepSoGroupPrivacy::notSecretSql()`), so the group was
     * hidden from every list while remaining openable by id — the two
     * halves disagreed.
     *
     * Strictest wins: any 2 → Secret, else any 1 → Closed, else Open. A 2
     * returns immediately because nothing outranks it.
     *
     * ⚠ An ABSENT or unparseable row is still Open, deliberately
     * unchanged. PeepSo and `createPlainGroup` always write privacy at
     * creation, so no-rows is a theoretical edge
     * ({@see \BCC\Trust\Core\Support\PublicAllPolicy}), and promoting
     * corrupt meta to Secret would hide live groups on the strength of a
     * malformed row — a different decision, with a far wider blast radius
     * than the one this fix is for.
     */
    public static function fromGroupPostId(int $groupId): self {
        $rows = get_post_meta($groupId, 'peepso_group_privacy', false);
        if (!is_array($rows)) {
            return self::Open;
        }

        $strictest = self::Open;
        foreach ($rows as $row) {
            if (!is_scalar($row)) {
                continue;
            }
            $value = (int) $row;
            if ($value === 2) {
                return self::Secret;
            }
            if ($value === 1) {
                $strictest = self::Closed;
            }
        }

        return $strictest;
    }
}
