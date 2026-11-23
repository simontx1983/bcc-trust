<?php
/**
 * MemberSearchCandidates — the ONE member-name matcher, shared by every
 * user search that takes free text: the /members directory `q` and the
 * @-mention picker (/users/mention-search).
 *
 * `q` → user ids whose DISPLAY NAME or @HANDLE (bcc_handle meta) contains
 * it. Never user_login, user_nicename (WordPress derives it from the
 * login) or email (F-175), and never a display name that equals the
 * login (F-302). A member who hides their real name (§K2
 * real_name_hidden) is NOT matched by display name, only by handle
 * (F-28), or a name search would link it to their @handle. A leading "@"
 * is ignored, so "@alice" finds handle "alice".
 *
 * These are CANDIDATE ids only. Callers pass them to PeepSoUserSearch as
 * `include` with an EMPTY search term, so PeepSo's privacy filter set
 * (bans, profile level, blocks, hide-from-listing, discovery opt-out) is
 * still applied in its query. ⛔ Never pass the raw query to
 * PeepSoUserSearch: its own search matches first/last name plus
 * user_login, the very oracle this class exists to avoid.
 *
 * Both lookups go through WP_User_Query, which escapes `search` and adds
 * the LIKE wildcards itself, so the needle is passed raw. Pre-escaping or
 * adding "%" here would match a literal percent sign.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use BCC\Core\Repositories\UserMetaRepository;
use BCC\Trust\Onchain\Repositories\GuardsReadFailures;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberSearchCandidates
{
    use GuardsReadFailures;

    /**
     * Default bound on EACH of the two lookups (display name, then @handle;
     * §3: no unbounded reads). Far above the directory's size today.
     *
     * ⚠ Two known bounds, documented rather than changed (F-432 review):
     *   - The two lookups are merged without re-capping, so ids() returns
     *     up to 2 × $max ids (2000 at the default), not $max.
     *   - Each lookup is a get_users() in its default order, login ASC, cut
     *     at $max. A query broad enough to match more than $max members
     *     keeps the first $max BY LOGIN, so login order still decides who
     *     can be a candidate at all (the F-54 residual). Callers sort and
     *     cut afterwards (bcc-search sorts before its own cut, F-431), but
     *     cannot recover a member this cut already dropped.
     */
    public const MAX_CANDIDATES = 1000;

    /** The §K2 "hide my real name" flag (PrivacySettings' `real_name_hidden`). */
    private const REAL_NAME_HIDDEN_META = 'bcc_privacy_real_name_hidden';

    /**
     * F-432: what a caller that opts into $throwOnReadFailure adds to both
     * lookups.
     *
     * ⚠ WP_User_Query caches its result in `user-queries` EVEN WHEN ITS
     * QUERY FAILED (wp-includes/class-wp-user-query.php, WP 7.0.2), and the
     * persistent object cache then serves that "nobody matched" until any
     * user changes. Measured on the Local site: after a failed lookup, the
     * same lookup ran no query and answered [] again. get_users() already
     * turns `count_total` off, so no FOUND_ROWS() runs after the main query
     * to overwrite its `last_error`.
     */
    private const FAIL_CLOSED_QUERY_ARGS = ['cache_results' => false];

    /**
     * @param bool $throwOnReadFailure F-432. false (the default): a lookup that
     *        did not run answers as "nobody matched", as it always has; the
     *        /members directory and the @-mention picker keep that. true: it
     *        throws instead, so a caller can answer "unavailable" (bcc-search's
     *        users vertical answers 503). APPENDED, so a caller passes it
     *        positionally and an older bcc-trust simply ignores it.
     * @return list<int>
     * @throws RepositoryReadFailure when $throwOnReadFailure and a lookup did not run
     */
    public static function ids(string $needle, int $max = self::MAX_CANDIDATES, bool $throwOnReadFailure = false): array
    {
        $needle = trim($needle);
        $handle = ltrim($needle, '@');
        $max    = max(1, min($max, self::MAX_CANDIDATES));
        if ($needle === '') {
            return [];
        }

        $failClosed = $throwOnReadFailure ? self::FAIL_CLOSED_QUERY_ARGS : [];

        self::clearReadErrorIf($throwOnReadFailure);
        $byName = get_users($failClosed + [
            'search'         => '*' . $needle . '*',
            'search_columns' => ['display_name'],
            'fields'         => 'ID',
            'number'         => $max,
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => 'bcc_privacy_real_name_hidden', 'compare' => 'NOT EXISTS'],
                ['key' => 'bcc_privacy_real_name_hidden', 'value' => '1', 'compare' => '!='],
            ],
        ]);
        self::throwIfReadFailed($throwOnReadFailure, 'display name lookup');

        // A display_name that IS the login (wp-admin / WP-CLI accounts, and a
        // member renamed before F-280 whose display_name still holds their
        // u_<original handle> login) is not a public name: matching it would
        // make every caller a login probe (F-302 review). Such a member is
        // still found by their handle below. One primed batch, then cache
        // reads; the login is compared, never searched.
        //
        // ⚠ F-456: wpdb clears `last_error` at the start of EVERY query, so a
        // single check after this whole step saw only its LAST query.
        // cache_users() runs two (the usermeta prime, then the users rows),
        // and get_userdata() may run one per id, so a failed users prime, or a
        // failed row read for any id but the last, slipped through and dropped
        // candidates silently. Each read is checked right after it runs:
        //   - after cache_users(): its users-row query, the last of its two.
        //     A failed usermeta prime is not checked; nothing below reads
        //     user meta (display_name and user_login are wp_users columns);
        //   - after each get_userdata() that answers false: an unreadable row
        //     answers false too, and only a genuine miss leaves no error.
        if ($byName !== []) {
            $nameIds = array_map('intval', $byName);
            self::clearReadErrorIf($throwOnReadFailure);
            cache_users($nameIds);
            self::throwIfReadFailed($throwOnReadFailure, 'user rows prime');
            // No reset before each get_userdata(): a row it has to read is a
            // query, which clears last_error itself, and every earlier read
            // was already checked.
            $byName = array_values(array_filter($nameIds, static function (int $id) use ($throwOnReadFailure): bool {
                $user = get_userdata($id);
                if ($user === false) {
                    self::throwIfReadFailed($throwOnReadFailure, 'user row read');
                    return false; // a genuine miss: no such user
                }
                return strcasecmp(trim((string) $user->display_name), trim((string) $user->user_login)) !== 0;
            }));
        }

        // F-512: a member is matched by display name only when NONE of their
        // real_name_hidden rows is truthy. The meta_query above cannot say
        // that (a member holding both a '0' and a '1' row passes it through
        // the '0' row); it stays as a pre-filter, so members whose rows are
        // all '1' never take a candidate slot. Read through bcc-core's
        // UserMetaRepository (every stored value, through ProbesReads), the
        // same reader and the same "any truthy row hides" rule as the
        // display path. A read that cannot be trusted keeps NO name match:
        // only handle matches remain, or it throws when the caller asked.
        if ($byName !== []) {
            $byName = self::withoutHiddenRealNames($byName, $throwOnReadFailure);
        }

        $byHandle = [];
        if ($handle !== '') {
            self::clearReadErrorIf($throwOnReadFailure);
            $byHandle = get_users($failClosed + [
                'meta_key'     => 'bcc_handle',
                'meta_value'   => $handle,
                'meta_compare' => 'LIKE',
                'fields'       => 'ID',
                'number'       => $max,
            ]);
            self::throwIfReadFailed($throwOnReadFailure, 'handle lookup');
        }

        $ids = [];
        foreach (array_merge($byName, $byHandle) as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * F-512: the name matches whose real_name_hidden rows are ALL falsy (or
     * absent). One bounded read of every stored value; a truthy row on any
     * of them hides the name. Unreadable keeps none.
     *
     * @param list<int> $ids
     * @return list<int>
     * @throws RepositoryReadFailure when $throwOnReadFailure and the read did not run
     */
    private static function withoutHiddenRealNames(array $ids, bool $throwOnReadFailure): array
    {
        $flags = UserMetaRepository::valuesOrUnknown($ids, [self::REAL_NAME_HIDDEN_META]);
        if ($flags === null) {
            if ($throwOnReadFailure) {
                throw new RepositoryReadFailure(self::class, 'ids: real-name flags', 'the read did not run');
            }
            return [];
        }

        return array_values(array_filter($ids, static function (int $id) use ($flags): bool {
            foreach ($flags[$id][self::REAL_NAME_HIDDEN_META] ?? [] as $value) {
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Start each fail-closed step from a clean `last_error`, so an error left
     * by an earlier, unrelated query is never blamed on this lookup. A step
     * served from the user cache runs no query and leaves it clean.
     */
    private static function clearReadErrorIf(bool $failClosed): void
    {
        if ($failClosed) {
            self::clearReadError();
        }
    }

    /**
     * Logs and throws RepositoryReadFailure ({@see GuardsReadFailures::guardReadOrThrow()})
     * when the step that just ran left a `last_error`.
     *
     * @throws RepositoryReadFailure
     */
    private static function throwIfReadFailed(bool $failClosed, string $step): void
    {
        if ($failClosed) {
            self::guardReadOrThrow('ids: ' . $step);
        }
    }
}
