<?php
/**
 * Mirrors PeepSo's group privacy flag (is_open / is_closed / is_secret)
 * so callers reason about a single value instead of three booleans.
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
     */
    public static function fromGroupPostId(int $groupId): self {
        $value = get_post_meta($groupId, 'peepso_group_privacy', true);
        return match ((int) $value) {
            2       => self::Secret,
            1       => self::Closed,
            default => self::Open,
        };
    }
}
