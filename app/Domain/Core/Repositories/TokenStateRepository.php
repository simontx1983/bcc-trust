<?php
/**
 * Token State Repository: a user's bearer-token state, read so a FAILED
 * read can never pass for an empty one (F-462).
 *
 * Three user-meta values decide whether a signed, unexpired token still
 * counts (JwtToken):
 *   - `bcc_token_version` (tv): the revocation counter, bumped by "sign out
 *     everywhere", a password change and a suspension;
 *   - `bcc_jwt_denylist`: tokens signed out one at a time (F-120);
 *   - `bcc_jwt_retired`: tokens exchanged by a refresh (F-282).
 *
 * ⚠⚠ WHY NOT get_user_meta(). It reads through WordPress's meta cache, and
 * update_meta_cache() caches an EMPTY array when its SELECT fails. tv then
 * reads 0 and both lists read [], so a revoked, signed-out or retired
 * token is ACCEPTED. With a persistent object cache that lasts until the
 * user's meta is next written, and batch priming can poison many users at
 * once. Here the three values come from ONE query, through bcc-core's
 * ProbesReads:
 *   - clearLastError(), then query() checked `=== false`;
 *   - then last_error, then the rows (trustedRows());
 *   - never get_col()/get_results() plus last_error, which can hand back
 *     the PREVIOUS query's rows when query() bails before flush().
 * A genuine "no rows" means tv 0 and empty lists, which is correct. A
 * failed read is null, and the caller must refuse the token.
 *
 * Deliberately UNCACHED: a cache in front of this would bring back the
 * poisoning it exists to remove. It is one indexed query (user_id +
 * meta_key) per token decode.
 *
 * @package BCC\Trust\Core\Repositories
 */

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class TokenStateRepository
{
    use \BCC\Core\Repositories\ProbesReads;

    public const TOKEN_VERSION_KEY = 'bcc_token_version';
    public const DENYLIST_KEY      = 'bcc_jwt_denylist';
    public const RETIRED_KEY       = 'bcc_jwt_retired';

    /**
     * Rows read at most. Each key normally has one row; WordPress allows
     * duplicates, and the first one (lowest umeta_id) is what
     * get_user_meta(…, true) would have answered, so that is the one used.
     */
    private const MAX_ROWS = 30;

    /**
     * The user's token state, or null when it could not be read.
     *
     * Values are as stored: tv as an int, each list unserialised as
     * get_user_meta() would have given it (JwtToken validates the shape).
     *
     * @return array{tv: int, denylist: mixed, retired: mixed}|null null = the read failed
     */
    public static function read(int $userId): ?array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->usermeta}
              WHERE user_id = %d AND meta_key IN (%s, %s, %s)
              ORDER BY umeta_id ASC
              LIMIT %d",
            $userId,
            self::TOKEN_VERSION_KEY,
            self::DENYLIST_KEY,
            self::RETIRED_KEY,
            self::MAX_ROWS
        );
        if (!is_string($sql)) {
            return null;
        }

        $rows = self::trustedRows($sql);
        if ($rows === null) {
            return null;
        }

        $first = [];
        foreach ($rows as $row) {
            $key = isset($row->meta_key) ? (string) $row->meta_key : '';
            if ($key !== '' && !array_key_exists($key, $first)) {
                $first[$key] = $row->meta_value ?? null;
            }
        }

        return [
            'tv'       => (int) maybe_unserialize($first[self::TOKEN_VERSION_KEY] ?? '0'),
            'denylist' => maybe_unserialize($first[self::DENYLIST_KEY] ?? ''),
            'retired'  => maybe_unserialize($first[self::RETIRED_KEY] ?? ''),
        ];
    }
}
