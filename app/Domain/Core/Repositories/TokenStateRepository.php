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
 * ⚠⚠ WHY NOT get_user_meta(). It reads through WordPress's meta cache,
 * which can't tell a failed read from an empty one. These values decide
 * authentication, so they come from ONE query, through bcc-core's
 * ProbesReads:
 *   - clearLastError(), then query() checked `=== false`;
 *   - then last_error, then the rows (trustedRows());
 *   - never get_col()/get_results() plus last_error, which can hand back
 *     the PREVIOUS query's rows when query() bails before flush().
 * A genuine "no rows" means tv 0 and empty lists, which is correct. A
 * failed read is null, and the caller must refuse the token.
 *
 * Deliberately UNCACHED: a cache in front of this would bring back the
 * ambiguity it exists to remove. It is one indexed query (user_id +
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
     * Rows read at most. Each key normally has one row, so a FULL read means
     * rows may be missing, and read() answers null (unknown) rather than
     * decide on part of the state.
     *
     * Repair (F-508), should it ever happen: that member's bearer requests
     * answer 503 until the extra rows are gone, and each read logs "token
     * state read failed". Ops removes the member's rows for the three keys
     * except the newest of each (`wp user meta list <id>`, then
     * `wp db query` on those umeta_ids), then revokes their sessions.
     */
    private const MAX_ROWS = 30;

    /**
     * The user's token state, or null when it could not be read.
     *
     * ⚠ DUPLICATE ROWS. update_metadata() looks for the key's existing rows
     * with a get_col(); when that SELECT fails it falls through to
     * add_metadata() and INSERTS a second row. Reading the first row only
     * (what get_user_meta(…, true) does) would then silently drop a
     * revocation or sign-out whose insert landed. So every row counts:
     *   - tv is the HIGHEST stored value: a revocation only ever raises it;
     *   - each list comes back as EVERY stored value, oldest row first, and
     *     JwtToken takes their union.
     *
     * @return array{tv: int, denylist: list<mixed>, retired: list<mixed>}|null null = the read failed, or was full
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
        if ($rows === null || count($rows) >= self::MAX_ROWS) {
            return null;
        }

        $tv       = 0;
        $denylist = [];
        $retired  = [];
        foreach ($rows as $row) {
            $key   = isset($row->meta_key) ? (string) $row->meta_key : '';
            $value = maybe_unserialize($row->meta_value ?? '');
            if ($key === self::TOKEN_VERSION_KEY) {
                $tv = max($tv, (int) $value);
            } elseif ($key === self::DENYLIST_KEY) {
                $denylist[] = $value;
            } elseif ($key === self::RETIRED_KEY) {
                $retired[] = $value;
            }
        }

        return ['tv' => $tv, 'denylist' => $denylist, 'retired' => $retired];
    }
}
