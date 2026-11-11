<?php
/**
 * PeepSoFriendGate — bcc-side wrapper for PeepSo's friend-graph check.
 *
 * Single shared helper that calls `PeepSoFriendsModel::are_friends`
 * with the right defensive guards:
 *   - Returns false when PeepSo's Friends plugin isn't loaded (so
 *     consumers degrade gracefully instead of crashing on missing
 *     class).
 *   - Returns false when either user_id is non-positive (anon viewer)
 *     or when both ids match (self-friendship is undefined; PeepSo's
 *     own method would return false but we short-circuit before the
 *     class_exists call).
 *
 * Replaces 3 inline copies:
 *   - MessagesService::areFriends (private static)
 *   - UserAlbumsEndpoint::viewerIsFriend (private instance method)
 *   - UserAlbumPhotosEndpoint::viewerIsFriend (private instance method)
 *
 * The 3 copies differed cosmetically — MessagesService used
 * `new PeepSoFriendsModel()`, the album endpoints used
 * `PeepSoFriendsModel::get_instance()`. PeepSo's `get_instance()`
 * itself just `return new self()` for this model class, so the two
 * forms are functionally identical. This helper uses `get_instance()`
 * for consistency with the rest of the PeepSo plugin's API style.
 *
 * @package BCC\Trust\Core\Support
 * @since 2026-05-15 (cleanup pass)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoFriendGate
{
    /**
     * ⚠⚠ ALWAYS RETURNS FALSE. See the comment on the return statement
     * before relying on, or "fixing", this.
     *
     * Intended contract: true when `$a` and `$b` are mutual friends in
     * PeepSo's friend graph; false when either id is non-positive, the
     * two ids match, or the PeepSo Friends plugin isn't loaded.
     */
    public static function areFriends(int $a, int $b): bool
    {
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return false;
        }
        if (!class_exists('PeepSoFriendsModel')) {
            return false;
        }
        $model = \PeepSoFriendsModel::get_instance();
        // ⚠⚠ This comparison can NEVER be true, so this method has always
        // returned false — for every pair, including while peepso-friends
        // was installed and a real friendship row existed.
        //
        // `PeepSoFriendsModel::are_friends()` ends in
        // `$wpdb->get_var('SELECT `fnd_id` …')` and returns that value
        // when it is not null, FALSE otherwise. On a match it is therefore
        // an `fnd_id` — a STRING such as "42" — which no strict `=== true`
        // can satisfy. It is truthy, never `true`.
        //
        // So the fail-closed behaviour callers rely on (level-30 albums
        // resolving to owner-only, friends-only DMs refusing every sender)
        // is ACCIDENTAL rather than designed, and it PREDATES the removal
        // of peepso-friends: the plugin being gone is not what made
        // friends-only gates deny.
        //
        // ⛔ Do NOT "fix" this to `!== false` as a type-correctness
        // cleanup. BCC has no friends feature, and a correct comparison
        // would RESTORE friends-only visibility the product has decided
        // against — a silent widening of who can see an album. If a
        // friends feature ever returns, this is the line to change and
        // `!== false` is the right form, but that is a product decision.
        //
        // ⚠ And note the asymmetry with friendIdsOf() below, which reads
        // `get_friends_ids()` and was ACCURATE. This class was never
        // uniformly inert: a caller gated on the batch helper honoured
        // friendship, a caller gated on this one never did.
        return $model->are_friends($a, $b) === true;
    }

    /**
     * Batch sibling of areFriends — the viewer's whole friend set as an
     * O(1) lookup map, for eligibility passes that would otherwise call
     * areFriends once per candidate (card lists resolve up to 50 page
     * operators per request).
     *
     * Same friend graph, same guards — but NOT the same outcome as
     * areFriends(). `get_friends_ids()` returns an array of ids, so this
     * method reported real friendships while areFriends() was returning
     * false for everyone (see its comment). Do not assume the two agree
     * historically.
     *
     * PeepSo MayFly-caches `get_friends_ids`, so repeat calls in a
     * request are cheap; fetch it lazily (only when a friends_only
     * recipient actually appears).
     *
     * @return array<int, true> keyed by friend user id
     */
    public static function friendIdsOf(int $userId): array
    {
        if ($userId <= 0 || !class_exists('PeepSoFriendsModel')) {
            return [];
        }
        $ids = \PeepSoFriendsModel::get_instance()->get_friends_ids($userId);
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $out[$id] = true;
            }
        }
        return $out;
    }
}
