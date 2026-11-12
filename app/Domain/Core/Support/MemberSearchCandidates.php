<?php
/**
 * MemberSearchCandidates — the ONE member-name matcher, shared by every
 * user search that takes free text: the /members directory `q` and the
 * @-mention picker (/users/mention-search).
 *
 * `q` → user ids whose DISPLAY NAME or @HANDLE (bcc_handle meta) contains
 * it. Never user_login, user_nicename (WordPress derives it from the
 * login) or email (F-175). A member who hides their real name (§K2
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

if (!defined('ABSPATH')) {
    exit;
}

final class MemberSearchCandidates
{
    /**
     * Default bound on each half of the candidate set (§3: no unbounded
     * reads). Far above the directory's size today.
     */
    public const MAX_CANDIDATES = 1000;

    /**
     * @return list<int>
     */
    public static function ids(string $needle, int $max = self::MAX_CANDIDATES): array
    {
        $needle = trim($needle);
        $handle = ltrim($needle, '@');
        $max    = max(1, min($max, self::MAX_CANDIDATES));
        if ($needle === '') {
            return [];
        }

        $byName = get_users([
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
        $byHandle = $handle === '' ? [] : get_users([
            'meta_key'     => 'bcc_handle',
            'meta_value'   => $handle,
            'meta_compare' => 'LIKE',
            'fields'       => 'ID',
            'number'       => $max,
        ]);

        $ids = [];
        foreach (array_merge($byName, $byHandle) as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }
}
