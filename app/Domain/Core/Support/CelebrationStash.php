<?php
/**
 * Celebration Stash — single-slot pending-celebration store per user.
 *
 * Backs the §O1.2 Heavy-intensity moments that fire out-of-band from
 * the originating action (rank-up, level-up, tier upgrade). Per §A3
 * subscribers run async, so the response from the user's triggering
 * request can't include the celebration inline. The stash decouples
 * the two: the subscriber pushes, the next /me/celebrations/pending
 * fetch reads-and-clears.
 *
 * Single-slot by design: rank-ups are rare, and stacking heavy
 * celebrations in a queue would dilute the moment. If a second
 * celebration fires before the first is consumed, the newer one
 * overwrites — losing one celebratory toast is a softer failure than
 * showing a backlog of stale ones.
 *
 * Storage: `wp_usermeta` under the key `bcc_pending_celebration`,
 * value is a JSON-encoded array{kind,label,icon,fired_at}. Using
 * usermeta (not a dedicated table) because the row count is bounded
 * by active users with unread celebrations — never grows beyond
 * one-per-user — and usermeta gets the WP object cache for free.
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04, §O1.2)
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class CelebrationStash
{
    private const META_KEY = 'bcc_pending_celebration';

    /**
     * Stash a Heavy-intensity celebration for a user. Overwrites any
     * unread prior celebration (single-slot by design — see class
     * docstring). Caller passes the kind/label/icon; this class does
     * not decide what's celebratable, only how it's stored.
     *
     * @param string $kind  Stable identifier (e.g. 'rank_up'). Used by
     *                      the frontend to pick the right icon/animation
     *                      preset.
     * @param string $label Server-rendered headline ("You're now a
     *                      Journeyman."). Per §A2 the frontend renders
     *                      this verbatim — never derives or pluralises.
     * @param string $icon  Frontend asset key. Stable string the
     *                      frontend maps to a Lucide / SVG asset.
     */
    public static function pushHeavy(int $userId, string $kind, string $label, string $icon): void
    {
        if ($userId <= 0) {
            return;
        }
        $payload = [
            'kind'     => $kind,
            'label'    => $label,
            'icon'     => $icon,
            'fired_at' => time(),
        ];
        update_user_meta($userId, self::META_KEY, wp_json_encode($payload));
    }

    /**
     * Read the pending celebration without clearing it. Used by the
     * GET /me/celebrations/pending endpoint so the frontend can render
     * the toast and then decide when to consume.
     *
     * Returns null when nothing is stashed OR when the stored payload
     * fails to decode (defensive — a corrupt blob shouldn't break the
     * /me endpoint).
     *
     * @return array{kind: string, label: string, icon: string, fired_at: int}|null
     */
    public static function peek(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $raw = get_user_meta($userId, self::META_KEY, true);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $kind  = isset($decoded['kind']) && is_string($decoded['kind']) ? $decoded['kind'] : '';
        $label = isset($decoded['label']) && is_string($decoded['label']) ? $decoded['label'] : '';
        $icon  = isset($decoded['icon']) && is_string($decoded['icon']) ? $decoded['icon'] : '';
        $firedAt = isset($decoded['fired_at']) && is_int($decoded['fired_at']) ? $decoded['fired_at'] : 0;
        if ($kind === '' || $label === '') {
            return null;
        }
        return [
            'kind'     => $kind,
            'label'    => $label,
            'icon'     => $icon,
            'fired_at' => $firedAt,
        ];
    }

    /**
     * Clear the pending celebration. Returns true when something was
     * cleared, false when nothing was stashed (lets the consume
     * endpoint stay idempotent).
     */
    public static function clear(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $existing = get_user_meta($userId, self::META_KEY, true);
        if (!is_string($existing) || $existing === '') {
            return false;
        }
        delete_user_meta($userId, self::META_KEY);
        return true;
    }
}
