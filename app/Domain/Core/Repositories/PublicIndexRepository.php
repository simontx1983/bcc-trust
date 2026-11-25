<?php
/**
 * PublicIndexRepository — the SQL half of the public index (F-518, the app
 * sitemap): which members and pages a signed-out visitor may see listed.
 *
 * Every read here answers for an ANONYMOUS viewer, whoever is signed in.
 * Nothing reads the current user, so a signed-in or admin request can never
 * widen what the public index lists.
 *
 * ⚠⚠ READ THROUGH bcc-core's ProbesReads, NEVER get_col() / get_results().
 * A failed read must be told apart from an empty one: an index that read a
 * failure as "nobody is suspended" or "no page is secret" would advertise
 * exactly the members and pages it must not. Every method answers null when
 * its read cannot be trusted, and the caller fails closed (lists nothing).
 *
 * The member predicate is built ONCE ({@see memberWhereSql()}) and used both
 * for the keyset list and for the single-member check behind the profile's
 * `indexable` flag, so the sitemap and the page's robots tag cannot disagree.
 * The handle is usermeta, read afterwards by
 * {@see \BCC\Trust\Core\Services\PublicProfileIndex} through bcc-core's
 * UserMetaRepository. (It used to read three moderation flags there too;
 * F-582 retired them, and suspension was never among them — that is this
 * class's own listing clause.)
 *
 * @package BCC\Trust\Core\Repositories
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Core\Repositories\PeepSoPageRepository;
use BCC\Core\Repositories\ProbesReads;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type PublicPageRow array{id: int, slug: string, page_type: string}
 */
final class PublicIndexRepository
{
    use ProbesReads;

    /** Largest page any keyset read here answers. */
    public const PAGE_MAX = 1000;

    /**
     * PeepSo roles that never appear in a public listing: awaiting email
     * validation ('register'), awaiting admin approval ('verified', PeepSo's
     * own name for it) and banned ('ban'). The same set PeepSo's own user
     * search excludes (peepso/classes/usersearch.php).
     */
    private const UNLISTED_ROLES = ['register', 'verified', 'ban'];

    /**
     * The highest `usr_profile_acc` an anonymous viewer may read. ProfileAccess
     * snaps a raw value to the nearest STRICTER rung before PeepSo's gate, and
     * only the `<= 10` rung (0, 10 and the −1 force-public value) passes for a
     * signed-out viewer. 20 (members), 30 (friends) and 40 (owner) do not.
     */
    private const PUBLIC_PROFILE_ACC_MAX = 10;

    private const PAGE_POST_TYPE = 'peepso-page';
    private const POST_STATUS    = 'publish';
    private const META_PAGE_TYPE = '_bcc_page_type';

    public function __construct(private readonly UserInfoRepository $userInfo)
    {
    }

    /**
     * Member ids after `$afterId`, ascending, that pass the SQL half of the
     * public predicate. At most `$limit`.
     *
     * @param bool $withLegacyOptOut also honour the legacy listing opt-out: PrivacySettings::legacyOptOutStillRead()
     * @return list<int>|null null = the read failed, or the arguments are out of range
     */
    public function memberIdsAfter(int $afterId, int $limit, bool $withLegacyOptOut): ?array
    {
        if ($afterId < 0 || $limit < 1 || $limit > self::PAGE_MAX) {
            return null;
        }

        global $wpdb;

        [$from, $where] = $this->memberClauses($withLegacyOptOut);

        $rows = self::trustedRows((string) $wpdb->prepare(
            "SELECT {$wpdb->users}.ID AS id
               FROM {$wpdb->users}{$from}
              WHERE {$where}
                AND {$wpdb->users}.ID > %d
              ORDER BY {$wpdb->users}.ID ASC
              LIMIT %d",
            $afterId,
            $limit
        ));
        if ($rows === null) {
            return null;
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Does this one member pass the SQL half of the public predicate? The
     * same clauses as {@see memberIdsAfter()}, constrained to one id.
     *
     * @param bool $withLegacyOptOut also honour the legacy listing opt-out: PrivacySettings::legacyOptOutStillRead()
     * @return bool|null null = the read failed
     */
    public function memberIsCandidate(int $userId, bool $withLegacyOptOut): ?bool
    {
        if ($userId <= 0) {
            return false;
        }

        global $wpdb;

        [$from, $where] = $this->memberClauses($withLegacyOptOut);

        $rows = self::trustedRows((string) $wpdb->prepare(
            "SELECT {$wpdb->users}.ID AS id
               FROM {$wpdb->users}{$from}
              WHERE {$where}
                AND {$wpdb->users}.ID = %d
              LIMIT 1",
            $userId
        ));
        if ($rows === null) {
            return null;
        }

        return $rows !== [];
    }

    /**
     * Published, non-secret peepso-pages with a slug, after `$afterId`,
     * ascending. `page_type` is the FIRST `_bcc_page_type` value (what
     * get_post_meta(…, true) answers), '' when the page has none.
     *
     * "Listable" is bcc-core's PeepSoPageRepository::listablePageSql(), the
     * rule every page count and owner list uses, so the sitemap lists
     * exactly the pages those surfaces count. The outer type / status
     * conditions repeat part of it so the id-ordered scan stays on the
     * type_status index rather than walking every post.
     *
     * @return list<PublicPageRow>|null null = the read failed, or the arguments are out of range
     */
    public function pagesAfter(int $afterId, int $limit): ?array
    {
        if ($afterId < 0 || $limit < 1 || $limit > self::PAGE_MAX) {
            return null;
        }

        global $wpdb;

        $listable = PeepSoPageRepository::listablePageSql('p.ID');

        $rows = self::trustedRowsAssoc((string) $wpdb->prepare(
            "SELECT p.ID AS id,
                    p.post_name AS slug,
                    COALESCE((SELECT pt.meta_value
                                FROM {$wpdb->postmeta} pt
                               WHERE pt.post_id  = p.ID
                                 AND pt.meta_key = %s
                               ORDER BY pt.meta_id ASC
                               LIMIT 1), '') AS page_type
               FROM {$wpdb->posts} p
              WHERE p.post_type   = %s
                AND p.post_status = %s
                AND p.post_name  <> ''
                AND p.ID > %d
                AND {$listable}
              ORDER BY p.ID ASC
              LIMIT %d",
            self::META_PAGE_TYPE,
            self::PAGE_POST_TYPE,
            self::POST_STATUS,
            $afterId,
            $limit
        ));
        if ($rows === null) {
            return null;
        }

        $out = [];
        foreach ($rows as $row) {
            $id   = (int) ($row['id'] ?? 0);
            $slug = (string) ($row['slug'] ?? '');
            if ($id <= 0 || $slug === '') {
                continue;
            }
            $out[] = [
                'id'        => $id,
                'slug'      => $slug,
                'page_type' => (string) ($row['page_type'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The FROM additions and the WHERE condition of the member predicate,
     * for an anonymous viewer. `{$wpdb->users}` is the base table.
     *
     *   - a PeepSo user row exists (INNER JOIN: no row, no listing — PeepSo
     *     never wrote one, so nothing can say the profile is public);
     *   - the role is not register / verified / ban;
     *   - the profile access is public (≤ 10);
     *   - not suspended: UserInfoRepository's own listing clause, the one the
     *     member directory uses, for viewer 0 (so no self-exemption applies);
     *   - not opted out of listings: UserSyncRepository::listingOptOutSql(),
     *     the ONE fragment the member directory shares (F-519): no truthy row
     *     on the hide-from-listings key, nor on its legacy predecessor while
     *     `$withLegacyOptOut` (the fold is not done). The caller decides it;
     *     this class reads no option.
     *
     * @return array{0: string, 1: string}
     */
    private function memberClauses(bool $withLegacyOptOut): array
    {
        global $wpdb;

        $suspension = $this->userInfo->listingSuspensionClause(0);

        // Constants of [a-z] only, so quoting them inline is safe; the
        // dynamic values all go through prepare() in the callers.
        $roles = "'" . implode("', '", self::UNLISTED_ROLES) . "'";

        $from = " INNER JOIN {$wpdb->prefix}peepso_users bcc_pub_acc"
            . " ON bcc_pub_acc.usr_id = {$wpdb->users}.ID"
            . $suspension['from'];

        $where = "bcc_pub_acc.usr_role NOT IN ({$roles})"
            . ' AND bcc_pub_acc.usr_profile_acc <= ' . self::PUBLIC_PROFILE_ACC_MAX
            . $suspension['where']
            . ' AND ' . UserSyncRepository::listingOptOutSql("{$wpdb->users}.ID", $withLegacyOptOut);

        return [$from, $where];
    }
}
