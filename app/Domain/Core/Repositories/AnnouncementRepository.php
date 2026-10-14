<?php
/**
 * Announcement reads — api-contract-v1.md §4.32.1, §4.32.4, §4.32.12.
 *
 * The first code in the project that returns announcement DATA. Everything
 * built before it returned a DECISION — who may act
 * ({@see \BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority}), what
 * the client may be told
 * ({@see \BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver}),
 * which rows are public
 * ({@see \BCC\Trust\Core\Announcements\AnnouncementVisibility}). This is
 * where the §4.32.11 public/owner split either holds or leaks.
 *
 * ── FOUR READS, FOUR ORDERS, FOUR CURSOR TAGS ───────────────────────────
 * §4.32.12 gives every collection a DIFFERENT order, so each read pairs its
 * own `KeysetCursor::SURFACE_*` tag with its own `ORDER BY` in one place:
 *
 *   listActive()    ann_active     pinned first (<=1), then newest published_at
 *   listArchived()  ann_archived   newest archived_at  <- a DIFFERENT column
 *   rotator()       (no cursor)    pinned first then newest, capped at 5
 *   listForOwner()  ann_owner      newest effective date
 *
 * The active and archived lists are the pair most likely to be conflated —
 * both order by a datetime and an id — and they order by different columns.
 * Applying one list's cursor to the other would skip or repeat rows with
 * nothing in the response to show it happened, which is why the cursor
 * carries a surface tag and why the tag lives next to the ORDER BY.
 *
 * ── ⚠⚠ FAIL-CLOSED READS: AN ERROR IS NOT "NO ANNOUNCEMENTS" ────────────
 * Every read clears `$wpdb->last_error`, runs, and throws
 * {@see RepositoryException} if it is non-empty — the
 * `PageReadModelRepository::readScoreRowOrThrow()` idiom.
 *
 * This matters more here than almost anywhere else in the codebase. A
 * failed query that returned `[]` would be indistinguishable from "this
 * validator has not posted any announcements" — and an empty announcement
 * bar looks COMPLETELY NORMAL. The failure would be invisible: no error, no
 * log line a reader would notice, just a validator's official notices
 * silently missing from their own page.
 *
 * ── ⚠⚠ WHY BOTH TABLE NAMES ARE INJECTED ────────────────────────────────
 * Not stylistic — forced. The integration harness's shared `wp_posts` is a
 * REDUCED table (`ID, post_title, post_name, post_type, post_status`) with
 * **no `post_author` and no `post_date_gmt`**
 * (`tests/Integration/bootstrap.php:707`). Two of the three §4.32.1
 * predicates read exactly those missing columns, so this SQL could not be
 * integration-tested against the shared table at all.
 *
 * Resolving both tables in the constructor — defaulting from `$wpdb`, the
 * way `PageReadModelRepository` resolves its table once — keeps `$wpdb`
 * inside the repository (§1) while letting the suite own its own fixture
 * tables. It is the same move that made `AnnouncementVisibility` testable,
 * and the reason #324's suite stopped poisoning its neighbours.
 *
 * ── UNCACHED, DELIBERATELY ──────────────────────────────────────────────
 * No cache, and §5 does not require one — it requires generation-counter
 * invalidation for caches that EXIST. There is no measured need: the
 * feature is disabled, so there is no traffic. A cache would need five
 * invalidation points (publish, pin, archive, edit, comment count), three
 * of which are the operations §4.32 is most emphatic about, and a stale
 * announcement bar shows a validator's official notice saying the wrong
 * thing. §4.32.11 makes the public list viewer-independent, so a cache key
 * will need no viewer component whenever one is wanted — that property is
 * what makes deferring safe rather than a debt.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver;
use BCC\Trust\Core\Announcements\AnnouncementPostType;
use BCC\Trust\Core\Announcements\AnnouncementPublicationGuard;
use BCC\Trust\Core\Announcements\AnnouncementVisibility;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Support\KeysetCursor;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type Row object{
 *     ID: int|numeric-string,
 *     post_title: string,
 *     post_excerpt: string,
 *     post_content: string,
 *     post_status: string,
 *     post_author: int|numeric-string,
 *     post_date_gmt: string,
 *     post_modified_gmt: string,
 *     is_pinned: int|numeric-string,
 *     archived_at: ?string,
 *     comments_enabled: ?string,
 *     publish_at: ?string
 * }
 * @phpstan-type Page array{rows: list<Row>, has_more: bool}
 */
final class AnnouncementRepository
{
    /**
     * §4.32.4 limits. `DEFAULT` when the caller sends none, `MAX` as the
     * clamp — never an unbounded read (§2, and arch-guardrails checks
     * `get_results`/`get_col` for a bound).
     */
    public const LIMIT_DEFAULT = 20;
    public const LIMIT_MAX     = 50;

    /** §4.32.4: the rotator is capped and mints no cursor. */
    public const ROTATOR_CAP = 5;

    /**
     * Pin meta. ⚠ Pinning is NOT a post column — it is meta, so it joins
     * like archival does and sorts on a derived expression.
     */
    public const META_PINNED_AT = '_bcc_announcement_pinned_at';

    /** Scheduled rows carry their due date here (§4.32.13 `schedule`). */
    public const META_PUBLISH_AT = '_bcc_announcement_publish_at';

    /**
     * ⚠⚠ THE COMMENT-COUNT PREDICATE LIVES HERE, IN ONE PLACE, ON PURPOSE.
     *
     * §4.32.7 requires that a removed comment stays as a public tombstone
     * and **counts toward `comment_count` so the rendered count matches**.
     * Whether a tombstone is `comment_approved = '0'` or a meta flag is
     * **A5's** design decision and is not made here.
     *
     * Today the count is a MEASURED ZERO — nothing writes announcement
     * comments yet — so the choice is not load-bearing. It will be the
     * moment A5 lands, which is why it is one named constant to change
     * rather than a predicate spread across call sites.
     *
     * ⚠ Do NOT confuse this with the FEED projection, which deliberately
     * emits no count at all for an announcement rather than a zero
     * (`FeedHydrationPipeline`, merged in #320). Different surfaces, both
     * correct; "fixing" either to match the other is a regression.
     */
    public const COMMENT_COUNT_APPROVED_STATES = ['1', '0'];

    private string $postsTable;
    private string $metaTable;
    private string $commentsTable;

    /**
     * Table names default from `$wpdb` and are overridable so the
     * integration suite can own its fixtures — see the class docblock.
     *
     * @throws \InvalidArgumentException on a non-identifier table name
     */
    public function __construct(
        ?string $postsTable = null,
        ?string $metaTable = null,
        ?string $commentsTable = null
    ) {
        global $wpdb;

        $this->postsTable    = self::identifier($postsTable ?? (string) $wpdb->posts, 'Posts table');
        $this->metaTable     = self::identifier($metaTable ?? (string) $wpdb->postmeta, 'Meta table');
        $this->commentsTable = self::identifier(
            $commentsTable ?? (string) $wpdb->prefix . 'comments',
            'Comments table'
        );
    }

    // ── Public reads (§4.32.11: published public data only) ──────────────

    /**
     * `?state=active` — pinned first (at most one), then newest first.
     *
     * ⚠ `is_pinned` is a field the OPERATOR CAN CHANGE, so a page boundary
     * can shift under a reader mid-scroll (§4.32.12). That is exactly why
     * the cursor key is the composite `pinned|published_at` and why the row
     * id is a required tiebreak: ordering by the datetime alone is not a
     * total order, and two announcements published in the same second would
     * page unstably.
     *
     * @return Page
     * @throws RepositoryException when the read fails
     */
    public function listActive(int $pageId, int $limit, ?string $cursor): array
    {
        return $this->publicPage(
            $pageId,
            $limit,
            $cursor,
            KeysetCursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            AnnouncementVisibility::notArchivedSql($this->metaTable, 'p'),
            'active'
        );
    }

    /**
     * `?state=archived` — newest `archived_at` first. The archive is the
     * event being listed (§4.32.12), so it orders by a DIFFERENT column
     * from the active list.
     *
     * ⚠ Archived announcements are still PUBLIC (§4.32.6) — they keep their
     * permalink and their original `published_at`. So this read applies the
     * same §4.32.1 visibility predicate and differs only in the archival
     * filter.
     *
     * @return Page
     * @throws RepositoryException when the read fails
     */
    public function listArchived(int $pageId, int $limit, ?string $cursor): array
    {
        return $this->publicPage(
            $pageId,
            $limit,
            $cursor,
            KeysetCursor::SURFACE_ANNOUNCEMENTS_ARCHIVED,
            AnnouncementVisibility::archivedSql($this->metaTable, 'p'),
            'archived'
        );
    }

    /**
     * The rotator — pinned first then newest, hard-capped at 5.
     *
     * Takes no cursor and mints none: §4.32.4 caps it and §4.32.12 makes
     * its `pagination` inert. Expressed by having no cursor PARAMETER, so a
     * caller cannot page it.
     *
     * @return list<Row>
     * @throws RepositoryException when the read fails
     */
    public function rotator(int $pageId): array
    {
        $page = $this->publicPage(
            $pageId,
            self::ROTATOR_CAP,
            null,
            KeysetCursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            AnnouncementVisibility::notArchivedSql($this->metaTable, 'p'),
            'active'
        );

        return $page['rows'];
    }

    // ── Owner read — the ONLY one that may return unpublished rows ───────

    /**
     * `GET /me/validators/:pageId/announcements?status=…`
     *
     * ⚠⚠ The only read that returns draft, scheduled or `blocked` rows, and
     * the only one that does NOT apply the §4.32.1 public predicate. It is
     * therefore the only read that can leak, so:
     *
     *   - it takes the authority's DECISION ARRAY, never a page id and a
     *     viewer id, and never a bool. §4.32.2 forbids `post_author`,
     *     mirror claim rows, page metadata, PeepSo membership and read
     *     models as authorization inputs — and a bool accepts all five
     *     without a trace. Same discipline as
     *     `AnnouncementCapabilityResolver`;
     *   - a decision that is not `DECISION_OPERATOR` returns an EMPTY page
     *     rather than throwing, because the route turns that into the
     *     generic `not_claimer` denial (§4.32.11) and a distinguishable
     *     exception here would be a second channel for the same fact;
     *   - it additionally binds `post_author` to the resolved operator, so
     *     even a correct decision cannot read another operator's drafts.
     *     ⚠ That is a CONSISTENCY CHECK on the row, not an authorization
     *     input: it can only ever narrow the result.
     *
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     * @param list<string> $statuses subset of draft|scheduled|blocked
     *
     * @return Page
     * @throws RepositoryException when the read fails
     */
    public function listForOwner(
        int $pageId,
        array $decision,
        array $statuses,
        int $limit,
        ?string $cursor
    ): array {
        if (($decision['decision'] ?? null) !== ValidatorPageOperatorAuthority::DECISION_OPERATOR) {
            return ['rows' => [], 'has_more' => false];
        }

        $operatorId = $decision['operator_user_id'] ?? null;
        if (!is_int($operatorId) || $operatorId <= 0) {
            // RESOLVED without a usable id is a contradiction. Empty rather
            // than trusting half a decision.
            return ['rows' => [], 'has_more' => false];
        }

        $wanted = self::ownerStatuses($statuses);
        if ($wanted === []) {
            return ['rows' => [], 'has_more' => false];
        }

        // ⚠ No `global $wpdb` here: this method BUILDS the query and
        // {@see self::runPage()} executes it. An earlier revision declared
        // it anyway, which reads as though this method queries — and the
        // whole point of routing through runPage() is that the fail-closed
        // error check lives in exactly one place.
        $limit  = self::clampLimit($limit);
        $fetch  = $limit + 1;
        $params = [AnnouncementPostType::POST_TYPE, $pageId, $operatorId];

        $where = [
            'p.post_type = %s',
            'p.post_parent = %d',
            'p.post_author = %d',
            // ⚠ NOT the public predicate — this read exists to return rows
            // the public predicate refuses. The ownership binding above is
            // what replaces it.
            AnnouncementVisibility::notArchivedSql($this->metaTable, 'p'),
        ];

        $statusIn = [];
        foreach ($wanted as $status) {
            $statusIn[] = '%s';
            $params[]   = self::wpStatusFor($status);
        }
        $where[] = 'p.post_status IN (' . implode(', ', $statusIn) . ')';

        // Effective date: publish_at for a scheduled row, else last
        // modification (§4.32.12).
        $effective = 'COALESCE(NULLIF(mp.meta_value, \'\'), p.post_modified_gmt)';

        $decoded = KeysetCursor::decode(KeysetCursor::SURFACE_ANNOUNCEMENTS_OWNER, $cursor);
        if ($decoded !== null) {
            $where[]  = "({$effective} < %s OR ({$effective} = %s AND p.ID < %d))";
            $params[] = $decoded['key'];
            $params[] = $decoded['key'];
            $params[] = $decoded['id'];
        }

        $sql = 'SELECT ' . $this->columns($effective) . '
                  FROM ' . $this->postsTable . ' p
                  ' . $this->metaJoins() . '
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY effective_at DESC, p.ID DESC
                 LIMIT %d';
        $params[] = $fetch;

        return $this->runPage($sql, $params, $limit);
    }

    // ── Single-row reads, for the detail route (§4.32.4) ─────────────────

    /**
     * One publicly visible announcement, or null.
     *
     * ⚠⚠ Composes the SAME §4.32.1 predicate as the public lists — it is
     * not a relaxed "by id" lookup. A detail route that fetched by id and
     * then checked visibility in PHP would be a second implementation of
     * the three-predicate rule, and §4.32.1 requires all four read paths
     * to enforce all three. Here the predicate IS the lookup.
     *
     * ⚠ Archival is deliberately NOT filtered: an archived announcement
     * keeps its canonical permalink (§4.32.6), so its detail view must
     * still resolve.
     *
     * Returning `null` for "not visible" rather than throwing is what lets
     * the route answer 404 for a missing row, a draft and another
     * operator's row identically.
     *
     * @return \stdClass|null the row as the driver returns it
     * @throws RepositoryException when the read fails
     */
    public function findPublic(int $pageId, int $announcementId): ?\stdClass
    {
        if ($pageId <= 0 || $announcementId <= 0) {
            return null;
        }

        $where = [
            'p.post_type = %s',
            'p.post_parent = %d',
            'p.ID = %d',
            AnnouncementVisibility::publicPredicateSql($this->metaTable, 'p'),
        ];

        return $this->findOne(
            $where,
            [AnnouncementPostType::POST_TYPE, $pageId, $announcementId]
        );
    }

    /**
     * One announcement belonging to the resolved operator, published or
     * not — the detail route's second attempt, used only after
     * {@see self::findPublic()} returns null.
     *
     * Same discipline as {@see self::listForOwner()}:
     *   - takes the authority's DECISION ARRAY, never a page id + viewer
     *     id and never a bool (§4.32.2 forbids five inputs a bool would
     *     silently accept);
     *   - a non-operator or half-resolved decision returns null, so the
     *     route's 404 is indistinguishable from a missing row;
     *   - binds `post_author` to the resolved operator, so even a correct
     *     decision cannot read another operator's draft. ⚠ A consistency
     *     check on the row, not an authorization input — it can only
     *     narrow.
     *
     * ⚠ No `post_status` filter. The owner may read their own row in any
     * state, including a `blocked` one they cannot yet be shown as blocked
     * (see {@see self::ownerStatuses()}). Withholding it would hide an
     * operator's own content from them.
     *
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     *
     * @return \stdClass|null the row as the driver returns it
     * @throws RepositoryException when the read fails
     */
    public function findForOwner(int $pageId, array $decision, int $announcementId): ?\stdClass
    {
        if ($pageId <= 0 || $announcementId <= 0) {
            return null;
        }

        if (($decision['decision'] ?? null) !== ValidatorPageOperatorAuthority::DECISION_OPERATOR) {
            return null;
        }

        $operatorId = $decision['operator_user_id'] ?? null;
        if (!is_int($operatorId) || $operatorId <= 0) {
            return null;
        }

        $where = [
            'p.post_type = %s',
            'p.post_parent = %d',
            'p.ID = %d',
            'p.post_author = %d',
        ];

        return $this->findOne(
            $where,
            [AnnouncementPostType::POST_TYPE, $pageId, $announcementId, $operatorId]
        );
    }

    /**
     * The shared single-row read. One builder so the two lookups cannot
     * drift in their column list or their failure handling.
     *
     * @param list<string> $where
     * @param list<mixed> $params
     *
     * @return \stdClass|null the row as the driver returns it
     * @throws RepositoryException when the read fails
     */
    private function findOne(array $where, array $params): ?\stdClass
    {
        global $wpdb;

        $sql = 'SELECT ' . $this->columns('NULL') . '
                  FROM ' . $this->postsTable . ' p
                  ' . $this->metaJoins() . '
                 WHERE ' . implode(' AND ', $where) . '
                 LIMIT 1';

        // ⚠⚠ FAIL-CLOSED, same as the list reads. A failed lookup must not
        // present as "no such announcement" — the route turns null into a
        // 404, and a 404 caused by a DB fault is indistinguishable from a
        // genuine one.
        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare($sql, ...$params));

        $lastError = (string) $wpdb->last_error;
        if (strlen($lastError) > 0) {
            throw new RepositoryException('announcement_read_failed');
        }

        return $row instanceof \stdClass ? $row : null;
    }

    // ── Batched comment counts (batch-then-shape, never per row) ─────────

    /**
     * Comment counts for a whole page of announcements, in ONE grouped
     * query.
     *
     * ⚠ Never a per-row count. The convention is `CommentService`'s
     * batch-then-shape split: the list method batches every side lookup
     * first and the row shaper performs zero queries. A per-row count here
     * would be an N+1 on a public read path.
     *
     * Returns a map keyed by announcement id. ⚠ An id with no comments is
     * ABSENT from the map, not zero — the caller supplies the zero, so a
     * missing key and a measured zero cannot be confused.
     *
     * @param list<int> $announcementIds
     * @return array<int, int>
     * @throws RepositoryException when the read fails
     */
    public function commentCountsFor(array $announcementIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $announcementIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        global $wpdb;

        $idIn     = implode(', ', array_fill(0, count($ids), '%d'));
        $stateIn  = implode(', ', array_fill(0, count(self::COMMENT_COUNT_APPROVED_STATES), '%s'));
        $params   = array_merge(
            $ids,
            [AnnouncementPostType::COMMENT_TYPE],
            self::COMMENT_COUNT_APPROVED_STATES
        );

        $sql = 'SELECT comment_post_ID AS announcement_id, COUNT(*) AS c
                  FROM ' . $this->commentsTable . '
                 WHERE comment_post_ID IN (' . $idIn . ')
                   AND comment_type = %s
                   AND comment_approved IN (' . $stateIn . ')
                 GROUP BY comment_post_ID';

        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $lastError = (string) $wpdb->last_error;
        if (strlen($lastError) > 0) {
            throw new RepositoryException('announcement_comment_count_failed');
        }

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_object($row) || !isset($row->announcement_id)) {
                continue;
            }
            $out[(int) $row->announcement_id] = isset($row->c) ? (int) $row->c : 0;
        }

        return $out;
    }

    /**
     * Every currently pinned announcement id on a page.
     *
     * ⚠ THIS LIVES HERE BECAUSE §1 PUTS IT HERE. The A3 write layer needs it to
     * hold §4.32.12's at-most-one-pin invariant, and its first version ran the
     * query inside `AnnouncementWriteService` — which `arch-guardrails`
     * correctly rejected with five `WPDB_OUTSIDE_REPO` violations. Raw `$wpdb`
     * belongs in a repository and nowhere else; the service now asks for ids.
     *
     * ⚠ Bounded by LIMIT. The invariant means the real answer is 0 or 1, so a
     * larger result set is already a broken install; the bound stops a broken
     * install turning into an unbounded read.
     *
     * @return list<int>
     * @throws RepositoryException when the read fails
     */
    public function pinnedIdsFor(int $pageId): array
    {
        if ($pageId <= 0) {
            return [];
        }

        global $wpdb;

        $sql = 'SELECT p.ID
                  FROM ' . $this->postsTable . ' p
                  INNER JOIN ' . $this->metaTable . ' m ON m.post_id = p.ID
                 WHERE p.post_type = %s
                   AND p.post_parent = %d
                   AND m.meta_key = %s
                 LIMIT 50';

        $wpdb->last_error = '';
        $ids = $wpdb->get_col($wpdb->prepare(
            $sql,
            AnnouncementPostType::POST_TYPE,
            $pageId,
            self::META_PINNED_AT
        ));

        $lastError = (string) $wpdb->last_error;
        if (strlen($lastError) > 0) {
            // ⚠ Fail closed, like every other read here. An errored query
            // returning [] would tell the writer "nothing is pinned", and it
            // would then pin a second row — breaking the very invariant this
            // method exists to hold.
            throw new RepositoryException('announcement_pinned_ids_failed');
        }

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    /**
     * Page-scoped advisory lock for the at-most-one-pin critical section.
     *
     * ⚠⚠ MEASURED NECESSARY, NOT ADDED DEFENSIVELY. Without it, six parallel
     * processes pinning six different rows on one page left **two** pins
     * surviving (a real run: ids 164 and 167). The sequential test — pin twice,
     * find one — cannot observe that, because it never interleaves the
     * read-then-write. `pin()` reads the current pins, deletes the others and
     * sets its own; two readers that both see "nothing pinned" both set.
     *
     * ⚠ Scoped to the PAGE, not globally: pinning on one validator page must
     * not serialise behind another page's pin.
     *
     * ⚠ Timeout 0 — refuse rather than wait. A queued pin would return 200 to
     * a caller whose pin was then cleared by the request behind it, which is a
     * worse answer than a conflict.
     *
     * ⚠ Routed through `AdvisoryLock` where available so a NULL return (driver
     * or permission error) stays distinguishable from 0 (held elsewhere); the
     * raw `(int)` cast collapses both to false and silently degrades the lock
     * into no lock. Same reasoning as `DisputeRepository`.
     */
    public function acquirePinLock(int $pageId): bool
    {
        $name = self::pinLockName($pageId);

        if (class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            return \BCC\Core\DB\AdvisoryLock::acquire($name, 0);
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) === 1;
    }

    /**
     * ⚠ EXACTLY ONE RELEASE PER ACQUIRE. MySQL's `GET_LOCK` is reentrant per
     * session: a second acquire in the same connection succeeds and needs its
     * own release, so an unmatched pair leaks the lock for the rest of the
     * request. `pin()` acquires once and releases in a `finally`.
     */
    public function releasePinLock(int $pageId): void
    {
        global $wpdb;

        $res = $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::pinLockName($pageId)));
        if ($res === false) {
            Logger::error('[bcc-trust] failed to release the announcement pin lock', [
                'page_id'  => $pageId,
                'db_error' => (string) $wpdb->last_error,
            ]);
        }
    }

    private static function pinLockName(int $pageId): string
    {
        // ⚠ Well under MySQL's 64-character lock-name limit.
        return 'bcc_ann_pin_' . $pageId;
    }

    /**
     * Force a row's `post_status` with a direct write.
     *
     * ⚠⚠ THIS DELIBERATELY BYPASSES `wp_update_post()`, and that is the whole
     * point of its existence. The publication guard calls it from inside
     * `transition_post_status`; `wp_update_post()` would re-enter the
     * transition machinery and recurse into the guard that is calling it.
     * Core's own `wp_publish_post()` writes the status the same way, for the
     * same reason.
     *
     * ⚠ It lives HERE because §1 permits raw `$wpdb` only in a repository.
     * Its first home was the guard itself, which is a forbidden layer — the
     * same mistake `arch-guardrails` had already caught once in this slice.
     *
     * ⚠ The status is validated against a CLOSED set rather than interpolated.
     * A caller-supplied status reaching a direct UPDATE is how a row ends up in
     * a state no predicate accounts for.
     *
     * @throws RepositoryException when the write fails
     */
    public function forceStatus(int $announcementId, string $status): bool
    {
        if ($announcementId <= 0) {
            return false;
        }
        if (!in_array($status, ['draft', 'future', 'publish'], true)) {
            throw new \InvalidArgumentException('not a permitted announcement post_status: ' . $status);
        }

        global $wpdb;

        $wpdb->last_error = '';
        $res = $wpdb->update(
            $this->postsTable,
            ['post_status' => $status],
            ['ID' => $announcementId, 'post_type' => AnnouncementPostType::POST_TYPE]
        );

        $lastError = (string) $wpdb->last_error;
        if (strlen($lastError) > 0) {
            throw new RepositoryException('announcement_force_status_failed');
        }

        // ⚠ `0` means "matched but unchanged" OR "matched nothing"; `false`
        // means error, which is already handled above. The caller treats a
        // non-false return as "the write did not error", and confirms the state
        // with its own read — the Cosmos endpoint-switch lesson.
        clean_post_cache($announcementId);

        return $res !== false;
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * The shared public read. Both public lists differ ONLY in their
     * archival filter and their sort column, so they share one builder —
     * two hand-written copies of a visibility-bearing query is how one of
     * them ends up missing a predicate.
     *
     * @param 'active'|'archived' $order
     * @return Page
     * @throws RepositoryException when the read fails
     */
    private function publicPage(
        int $pageId,
        int $limit,
        ?string $cursor,
        string $surface,
        string $archivalSql,
        string $order
    ): array {
        $limit  = self::clampLimit($limit);
        $fetch  = $limit + 1;
        $params = [AnnouncementPostType::POST_TYPE, $pageId];

        $where = [
            'p.post_type = %s',
            'p.post_parent = %d',
            // ⚠⚠ THE §4.32.1 THREE-PREDICATE RULE. Composed, never
            // re-derived: status, due date, AND a valid
            // publication-authorization marker. Predicate 3 is the one that
            // stops a row moved to `publish` early from becoming public
            // without ever passing the ownership recheck.
            AnnouncementVisibility::publicPredicateSql($this->metaTable, 'p'),
            $archivalSql,
        ];

        // Pinned-first is a COMPOSITE key, so the cursor carries
        // "<pinned><sep><date>" and the WHERE repeats the expression
        // verbatim — MySQL forbids a SELECT alias in WHERE.
        $pinnedExpr = self::PINNED_EXPR;
        $dateExpr   = $order === 'archived' ? self::ARCHIVED_EXPR : 'p.post_date_gmt';
        $sortExpr   = $order === 'archived'
            ? $dateExpr
            : "CONCAT({$pinnedExpr}, '|', {$dateExpr})";

        $orderBy = $order === 'archived'
            ? 'archived_at DESC, p.ID DESC'
            : 'is_pinned DESC, p.post_date_gmt DESC, p.ID DESC';

        $decoded = KeysetCursor::decode($surface, $cursor);
        if ($decoded !== null) {
            $where[]  = "({$sortExpr} < %s OR ({$sortExpr} = %s AND p.ID < %d))";
            $params[] = $decoded['key'];
            $params[] = $decoded['key'];
            $params[] = $decoded['id'];
        }

        $sql = 'SELECT ' . $this->columns('NULL') . '
                  FROM ' . $this->postsTable . ' p
                  ' . $this->metaJoins() . '
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY ' . $orderBy . '
                 LIMIT %d';
        $params[] = $fetch;

        return $this->runPage($sql, $params, $limit);
    }

    /**
     * ⚠ `limit + 1` over-fetch, then slice.
     *
     * Precedent is `BlogService::getUserBlog`, NOT `CommentService`, which
     * infers the cursor from `count($rows) === $limit` — and so MINTS A
     * FALSE-POSITIVE CURSOR on an exact-boundary page, handing the reader a
     * "next page" that is empty.
     *
     * @param list<mixed> $params
     * @return Page
     * @throws RepositoryException when the read fails
     */
    private function runPage(string $sql, array $params, int $limit): array
    {
        global $wpdb;

        // ⚠⚠ FAIL-CLOSED. An errored read must not present as "this
        // validator has no announcements" — an empty bar looks normal, so
        // the failure would be invisible.
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        // Local copy + strlen: PHPStan's wpdb stub narrows last_error to the
        // literal '' (same note as PageReadModelRepository).
        $lastError = (string) $wpdb->last_error;
        if (strlen($lastError) > 0) {
            throw new RepositoryException('announcement_read_failed');
        }

        $rows    = is_array($rows) ? array_values($rows) : [];
        $hasMore = count($rows) > $limit;

        return [
            'rows'     => $hasMore ? array_slice($rows, 0, $limit) : $rows,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Pinned-ness as a sortable 0/1. Meta, not a column — so it is derived
     * and must be repeated verbatim in any WHERE that uses it.
     */
    private const PINNED_EXPR = "CASE WHEN mpin.meta_value IS NOT NULL AND mpin.meta_value <> '' THEN 1 ELSE 0 END";

    /** Archival timestamp, empty-string-safe (an empty meta is NOT archived). */
    private const ARCHIVED_EXPR = "NULLIF(mar.meta_value, '')";

    /**
     * Reconciler arm C's blocked timestamp, empty-string-safe.
     *
     * ⚠ A separate const for the same reason as ARCHIVED_EXPR: it is derived
     * from meta, so it must be repeated verbatim anywhere a WHERE uses it, and
     * an empty meta is NOT blocked.
     */
    private const BLOCKED_EXPR = "NULLIF(mbl.meta_value, '')";

    /**
     * Explicit column list — never `SELECT *` (§2, and arch-guardrails
     * Rule 2 enforces it).
     *
     * `$effectiveExpr` is the owner list's effective-date expression, or
     * `NULL` for the public reads which do not order by it. Passing it in
     * keeps ONE column list rather than two that can drift.
     */
    private function columns(string $effectiveExpr): string
    {
        return 'p.ID,
                p.post_title,
                p.post_excerpt,
                p.post_content,
                p.post_status,
                p.post_author,
                p.post_date_gmt,
                p.post_modified_gmt,
                ' . self::PINNED_EXPR . ' AS is_pinned,
                ' . self::ARCHIVED_EXPR . ' AS archived_at,
                mce.meta_value AS comments_enabled,
                mp.meta_value AS publish_at,
                ' . self::BLOCKED_EXPR . ' AS blocked_at,
                ' . $effectiveExpr . ' AS effective_at';
    }

    /**
     * ⚠ LEFT JOINs for the OPTIONAL meta only (pin, archive,
     * comments-enabled, publish-at). The §4.32.1 authorization marker is
     * NOT joined — `AnnouncementVisibility` asks for it with `EXISTS`,
     * because a duplicated meta key multiplies JOINed rows and a duplicated
     * `_authorized_user_id` (one correct, one wrong) is exactly what a bad
     * migration leaves behind.
     *
     * These four are single-valued by construction and only read, never
     * compared for authorization, so a duplicate would at worst pick one —
     * it cannot grant anything.
     */
    private function metaJoins(): string
    {
        $m = $this->metaTable;

        return "LEFT JOIN {$m} mpin ON mpin.post_id = p.ID AND mpin.meta_key = '"
                . self::META_PINNED_AT . "'
                LEFT JOIN {$m} mar ON mar.post_id = p.ID AND mar.meta_key = '"
                . AnnouncementVisibility::META_ARCHIVED_AT . "'
                LEFT JOIN {$m} mce ON mce.post_id = p.ID AND mce.meta_key = '"
                . AnnouncementVisibility::META_COMMENTS_ENABLED . "'
                LEFT JOIN {$m} mp ON mp.post_id = p.ID AND mp.meta_key = '"
                  . self::META_PUBLISH_AT . "'
                  LEFT JOIN {$m} mbl ON mbl.post_id = p.ID AND mbl.meta_key = '"
                  . AnnouncementPublicationGuard::META_BLOCKED_AT . "'";
    }

    /**
     * §4.32.4: default 20, max 50. A non-positive limit takes the default
     * rather than erroring — a bad `?limit` is a navigation accident, like
     * a bad cursor.
     */
    private static function clampLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::LIMIT_DEFAULT;
        }

        return min($limit, self::LIMIT_MAX);
    }

    /**
     * §4.32.13: the owner list accepts `draft` | `scheduled` | `blocked`
     * and **not** `published`, because a published announcement is served
     * by the public list. An unrecognised value is DROPPED, and an
     * all-unrecognised request yields an empty page rather than silently
     * widening to every status.
     *
     * ⚠⚠ `blocked` IS REFUSED TODAY, and that is a recorded gap rather
     * than an oversight. §4.32.13 defines it as a row forced to `publish`
     * without a valid authorization marker and moved to a non-public state
     * **by reconciler arm C** — which does not exist yet. It has no
     * WordPress `post_status`, so the only thing that could distinguish it
     * from an ordinary `draft` is a marker the reconciler writes.
     *
     * Accepting it would be worse than refusing it: it would map to
     * `draft`, and `?status=blocked` would silently return ORDINARY DRAFTS
     * — a wrong answer that looks like a right one. Refusing yields an
     * empty page, which is true: there are no blocked rows.
     *
     * ⚠ When arm C lands it must define its marker, this method must accept
     * `blocked`, and `AnnouncementViewService::status()` must read it.
     *
     * @param list<string> $statuses
     * @return list<string>
     */
    private static function ownerStatuses(array $statuses): array
    {
        $allowed = [
            AnnouncementCapabilityResolver::STATUS_DRAFT,
            AnnouncementCapabilityResolver::STATUS_SCHEDULED,
            // AnnouncementCapabilityResolver::STATUS_BLOCKED — see above.
        ];

        $out = [];
        foreach ($statuses as $status) {
            if (is_string($status) && in_array($status, $allowed, true) && !in_array($status, $out, true)) {
                $out[] = $status;
            }
        }

        return $out;
    }

    /**
     * Contract read-state → WordPress `post_status`.
     *
     * Only `draft` and `scheduled` reach here (see
     * {@see self::ownerStatuses()}), so the mapping is total:
     * `scheduled` → `future`, `draft` → `draft`. ⚠ Nothing maps to
     * `publish` — a mapping that did would make a non-public row public.
     */
    private static function wpStatusFor(string $contractStatus): string
    {
        return $contractStatus === AnnouncementCapabilityResolver::STATUS_SCHEDULED
            ? 'future'
            : 'draft';
    }

    /**
     * Table names reach SQL as identifiers and cannot be bound, so they are
     * validated rather than escaped — the same rule
     * `AnnouncementVisibility::assertIdentifier()` applies.
     *
     * @throws \InvalidArgumentException
     */
    private static function identifier(string $value, string $label): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' must be a bare SQL identifier.');
        }

        return $value;
    }
}
