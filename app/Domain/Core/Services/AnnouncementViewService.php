<?php
/**
 * Announcement view-models — api-contract-v1.md §4.32.3, §4.32.11, §4.32.12.
 *
 * ── ⚠⚠ TWO PRODUCERS, NOT ONE WITH A FLAG ───────────────────────────────
 * §4.32.11 (as resolved in umbrella #177): the public list and the rotator
 * carry **no `capabilities`, no `status` and no `body`** — for ANY viewer,
 * **including the resolved operator**. An operator reading the public list
 * gets byte-for-byte what an anonymous reader gets; their drafts live on
 * `/me/...` and nowhere else.
 *
 * That is enforced STRUCTURALLY: {@see self::publicRow()} has no parameter
 * from which it could learn a viewer and no branch that could emit those
 * three fields. A `bool $isOwner` flag would have been the obvious design
 * and is the wrong one — a flag can be inverted by a later edit, defaulted
 * wrongly, or threaded from the wrong variable, and the failure mode is a
 * private state on a **cacheable** public response.
 *
 * Same choice as `AnnouncementCapabilityResolver`, where the guarantee is
 * the ABSENCE of a public-list capability producer. A reflection test pins
 * the public method inventory here too, so adding a producer fails CI
 * rather than shipping.
 *
 * ── WHY THIS IS A SERVICE AND NOT AN `*Announcement*Serializer` CLASS ────
 * There is no `*Serializer` class anywhere under `app/`. The house
 * convention is private shaper methods on a service returning
 * `array<string, mixed>`, with shapes pinned by `@phpstan-type` aliases in
 * the class docblock — `CardViewService` and
 * `CommentService::shapeCommentRow()`. This follows that rather than
 * inventing a parallel idiom (§11).
 *
 * ── BATCH-THEN-SHAPE ────────────────────────────────────────────────────
 * The shapers perform **zero queries**. Side data — today just comment
 * counts — is batched by the caller and handed in as a map, exactly as
 * `CommentService::listByFeedId()` batches badges, vouches, handles,
 * avatars, stokes, media and reply counts before shaping. A per-row lookup
 * here would be an N+1 on a public read path.
 *
 * ⚠ A missing key in `$commentCounts` means "no comments", rendered as a
 * **measured 0**. The repository deliberately omits zero-count ids from the
 * map, so an absent key and a real zero cannot be confused — and neither is
 * ever a substituted activity count.
 *
 * ⚠⚠ Do NOT align this with the FEED projection. `FeedHydrationPipeline`
 * (merged, #320) deliberately gives an announcement **no count at all,
 * not a zero**, because the feed cannot source the real one. The
 * announcement view-model carries a real count per §4.32.3. Different
 * surfaces, both correct; "fixing" either to match the other is a
 * regression.
 *
 * ── DATES ───────────────────────────────────────────────────────────────
 * ISO-8601 UTC, and **no server-rendered label**. §4.32.3 makes
 * announcement dates a deliberate divergence from `posted_at_label` (§3.3)
 * — recorded there so it reads as intentional rather than as drift, and
 * repeated here because a reader who knows §3.3 will expect a label.
 *
 * Pure: no WordPress, no DB, no clock, no `$wpdb`. Every input is a
 * parameter, so the whole matrix is unit-testable with no bootstrap.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver;
use BCC\Trust\Core\Announcements\AnnouncementVisibility;
use BCC\Trust\Core\Support\KeysetCursor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type PublicRow array{
 *     id: string, title: string, summary: string,
 *     published_at: ?string, updated_at: ?string,
 *     is_pinned: bool, is_archived: bool, archived_at: ?string,
 *     comment_count: int, comments_enabled: bool,
 *     links: array{self: string}
 * }
 * @phpstan-type Pagination array{next_cursor: ?string, has_more: bool}
 * @phpstan-type PublicPage array{items: list<PublicRow>, pagination: Pagination}
 */
final class AnnouncementViewService
{
    /** Opaque wire prefix, so an id is never mistaken for a raw post id. */
    public const ID_PREFIX = 'ann_';

    // ── Public collections (§4.32.11: no capabilities, no status, no body)

    /**
     * The `?state=active` / `?state=archived` list, and the rotator.
     *
     * ⚠ Takes **no viewer argument of any kind**. That is the guarantee,
     * not an omission: there is no value a caller could pass that would
     * make this emit `capabilities`, `status` or `body`, so an operator and
     * an anonymous reader receive identical bytes.
     *
     * @param list<object> $rows repository rows
     * @param array<int, int> $commentCounts batched; absent id ⇒ 0
     * @param 'ann_active'|'ann_archived' $surface which cursor to mint
     *
     * @return PublicPage
     */
    public static function publicPage(
        array $rows,
        bool $hasMore,
        array $commentCounts,
        string $surface,
        string $validatorSlug
    ): array {
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::publicRow($row, $commentCounts, $validatorSlug);
        }

        return [
            'items'      => $items,
            'pagination' => self::pagination($rows, $hasMore, $surface),
        ];
    }

    /**
     * The rotator page. Same rows, same shaper — but `pagination` is
     * **inert** (§4.32.4/§4.32.12: capped at 5, the client never reads it).
     *
     * Reusing the list envelope is deliberate per §4.32.14: a second
     * response shape would add contract surface for no gain. Minting no
     * cursor is what makes it inert, rather than a flag the client must
     * honour.
     *
     * @param list<object> $rows
     * @param array<int, int> $commentCounts
     * @return PublicPage
     */
    public static function rotatorPage(
        array $rows,
        array $commentCounts,
        string $validatorSlug
    ): array {
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::publicRow($row, $commentCounts, $validatorSlug);
        }

        return [
            'items'      => $items,
            'pagination' => ['next_cursor' => null, 'has_more' => false],
        ];
    }

    // ── Owner collection and detail (capabilities, row-scoped) ───────────

    /**
     * The `/me/...` owner list — the only list that carries capabilities,
     * and the only one whose rows may be unpublished (§4.32.11).
     *
     * ⚠ `status` IS present here, and is absent from the public list by
     * construction above. §4.32.13: a list item carries no `status` because
     * a public list is `published` by definition and an owner list is
     * already filtered by the `?status=` the caller asked for — but the
     * owner needs to see which of the three non-public states a row is in,
     * so it is emitted per row rather than inferred from the query.
     *
     * @param list<object> $rows
     * @param array<int, int> $commentCounts
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     * @param array<string, mixed> $viewer standing, for the comment/report gates
     *
     * @return array{items: list<array<string, mixed>>, pagination: Pagination}
     */
    public static function ownerPage(
        array $rows,
        bool $hasMore,
        array $commentCounts,
        array $decision,
        array $viewer,
        bool $featureEnabled,
        string $validatorSlug
    ): array {
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::ownerRow(
                $row,
                $commentCounts,
                $decision,
                $viewer,
                $featureEnabled,
                $validatorSlug
            );
        }

        return [
            'items'      => $items,
            'pagination' => self::pagination($rows, $hasMore, KeysetCursor::SURFACE_ANNOUNCEMENTS_OWNER),
        ];
    }

    /**
     * The detail view-model — the only shape carrying `body`.
     *
     * @param array<int, int> $commentCounts
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     * @param array<string, mixed> $viewer
     *
     * @return array<string, mixed>
     */
    public static function detail(
        object $row,
        array $commentCounts,
        array $decision,
        array $viewer,
        bool $featureEnabled,
        string $validatorSlug
    ): array {
        $model = self::ownerRow($row, $commentCounts, $decision, $viewer, $featureEnabled, $validatorSlug);

        // `body` is Markdown SOURCE (§4.32.3), detail only, never rendered
        // server-side — §9 keeps UI out of PHP.
        $model['body'] = self::str($row, 'post_content');

        return $model;
    }

    // ── shapers (zero queries) ───────────────────────────────────────────

    /**
     * ⚠⚠ THE PUBLIC ROW. The whole §4.32.11 guarantee is that this method
     * has no way to emit `capabilities`, `status` or `body` — it receives
     * no decision, no viewer and no feature flag, so there is nothing to
     * branch on.
     *
     * @param array<int, int> $commentCounts
     * @return PublicRow
     */
    private static function publicRow(object $row, array $commentCounts, string $validatorSlug): array
    {
        $id        = self::rowId($row);
        $published = self::iso(self::str($row, 'post_date_gmt'));
        $updated   = self::iso(self::str($row, 'post_modified_gmt'));

        return [
            'id'               => self::ID_PREFIX . $id,
            'title'            => self::str($row, 'post_title'),
            // §4.32.3: required, first-class, stored in post_excerpt, and
            // NEVER inferred from the body.
            'summary'          => self::str($row, 'post_excerpt'),
            'published_at'     => $published,
            'updated_at'       => $updated,
            'is_pinned'        => self::flag($row, 'is_pinned'),
            'is_archived'      => self::archivedAt($row) !== null,
            'archived_at'      => self::archivedAt($row),
            'comment_count'    => $commentCounts[$id] ?? 0,
            'comments_enabled' => self::commentsEnabled($row),
            'links'            => ['self' => self::permalink($validatorSlug, $id)],
        ];
    }

    /**
     * The owner/detail row: the public fields plus `status` and row-scoped
     * capabilities.
     *
     * ⚠ Capabilities are passed through from
     * `AnnouncementCapabilityResolver::forRow()` **verbatim** — this service
     * never computes, filters or reorders them. So it is agnostic to
     * whether the reportability slice's `can_report` is present, and there
     * is exactly one place where a capability is decided.
     *
     * @param array<int, int> $commentCounts
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     * @param array<string, mixed> $viewer
     *
     * @return array<string, mixed>
     */
    private static function ownerRow(
        object $row,
        array $commentCounts,
        array $decision,
        array $viewer,
        bool $featureEnabled,
        string $validatorSlug
    ): array {
        $model  = self::publicRow($row, $commentCounts, $validatorSlug);
        $status = self::status($row);

        // ⚠ `updated_at` is surfaced ONLY when it differs materially from
        // `published_at` (§4.32.3). Equal-or-absent becomes null rather
        // than a duplicate timestamp the client would have to compare.
        if ($model['updated_at'] === $model['published_at']) {
            $model['updated_at'] = null;
        }

        $model['status'] = $status;
        $model['capabilities'] = AnnouncementCapabilityResolver::forRow(
            $decision,
            [
                'status'           => $status,
                'is_archived'      => $model['is_archived'],
                'comments_enabled' => $model['comments_enabled'],
            ],
            $viewer,
            $featureEnabled
        );

        return $model;
    }

    /**
     * `{next_cursor, has_more}` — the shape declared in `BlogService` and
     * `Feed\FeedRankingService`.
     *
     * ⚠ Do NOT copy `CommentsEndpoint`, which emits `next_cursor` flat with
     * no `pagination` wrapper and no `has_more`. It is the outlier.
     *
     * ⚠ The cursor is minted from the LAST row of the page and only when
     * there is genuinely more — `$hasMore` comes from the repository's
     * `limit + 1` over-fetch, not from `count($rows) === $limit`, which
     * would mint a cursor for an empty next page.
     *
     * @param list<object> $rows
     * @return Pagination
     */
    private static function pagination(array $rows, bool $hasMore, string $surface): array
    {
        if (!$hasMore || $rows === []) {
            return ['next_cursor' => null, 'has_more' => false];
        }

        $last = $rows[count($rows) - 1];
        $key  = self::cursorKeyFor($last, $surface);
        $id   = self::rowId($last);

        if ($key === '' || $id <= 0) {
            // A row we cannot address is a row we cannot page past. Better
            // to end the list than to hand out a cursor that resets it.
            return ['next_cursor' => null, 'has_more' => false];
        }

        return [
            'next_cursor' => KeysetCursor::encode($surface, $key, $id),
            'has_more'    => true,
        ];
    }

    /**
     * The cursor key must be the SAME expression the repository ordered by,
     * per surface — otherwise the next page starts in the wrong place.
     *
     *   ann_active     "<pinned>|<post_date_gmt>"  (composite: pinned first)
     *   ann_archived   archived_at
     *   ann_owner      effective_at
     */
    private static function cursorKeyFor(object $row, string $surface): string
    {
        if ($surface === KeysetCursor::SURFACE_ANNOUNCEMENTS_ARCHIVED) {
            return (string) (self::archivedAtRaw($row) ?? '');
        }

        if ($surface === KeysetCursor::SURFACE_ANNOUNCEMENTS_OWNER) {
            return self::str($row, 'effective_at');
        }

        return (self::flag($row, 'is_pinned') ? '1' : '0') . '|' . self::str($row, 'post_date_gmt');
    }

    // ── field readers (defensive: a row may come from anywhere) ──────────

    private static function rowId(object $row): int
    {
        return isset($row->ID) ? (int) $row->ID : 0;
    }

    private static function str(object $row, string $field): string
    {
        $v = $row->{$field} ?? null;

        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * ⚠ Strict-ish: MySQL hands back `'1'`/`'0'` strings for a CASE
     * expression, so this accepts the numeric truth of the column but never
     * an arbitrary truthy string — `'false'` and `'no'` must not read as
     * pinned.
     */
    private static function flag(object $row, string $field): bool
    {
        $v = $row->{$field} ?? null;

        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v === 1;
        }

        return $v === '1';
    }

    /** Raw archival timestamp, or null. Empty string is NOT archived. */
    private static function archivedAtRaw(object $row): ?string
    {
        $v = $row->archived_at ?? null;
        if (!is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    private static function archivedAt(object $row): ?string
    {
        $raw = self::archivedAtRaw($row);

        return $raw === null ? null : self::iso($raw);
    }

    /**
     * §4.32.7 per-announcement switch.
     *
     * ⚠ Absent meta reads as ENABLED, not disabled — §4.32.7 makes
     * discussion the default and `comments_enabled: false` an explicit
     * operator choice. The guard that matters (no comment can be created
     * while the feature is off, and none on an archived row) is the
     * capability gate and the write path, not this projection.
     */
    private static function commentsEnabled(object $row): bool
    {
        $v = $row->comments_enabled ?? null;

        if ($v === null) {
            return true;
        }
        if (!is_scalar($v)) {
            return true;
        }

        $v = trim((string) $v);

        // An explicit '0' / '' disables; anything else (including absent)
        // leaves the discussion open.
        return $v !== '0' && $v !== '';
    }

    /**
     * WordPress `post_status` → the §4.32.13 READ state.
     *
     * ⚠⚠ `blocked` IS NOT PRODUCED HERE, AND THAT IS DELIBERATE.
     *
     * §4.32.13 defines it as a row forced to `publish` without a valid
     * authorization marker and moved to a non-public state **by reconciler
     * arm C**. There is no WordPress `post_status` for it, so telling a
     * blocked row from an ordinary `draft` requires a marker that the
     * reconciler writes — and **the reconciler does not exist yet**.
     *
     * An earlier draft of this method read an `is_blocked` column. That
     * column does not exist, and inventing a meta key for it here would be
     * guessing at the reconciler's storage and then depending on the guess.
     * So this returns `draft` for every non-published, non-scheduled row,
     * and the open item is recorded rather than papered over:
     *
     *   ⚠ When reconciler arm C lands it must define its marker, this
     *     method must read it, and the owner list must accept
     *     `?status=blocked` (see `AnnouncementRepository::ownerStatuses()`,
     *     which refuses it today for the same reason).
     *
     * Returning `draft` is the fail-safe direction: `draft` and `blocked`
     * are both **non-public**, so a blocked row is never exposed by this
     * mapping — it is merely labelled less precisely to its own operator.
     *
     * An unrecognised status is also `draft`: a row whose state we have not
     * been taught is a row whose publication cannot be vouched for.
     */
    private static function status(object $row): string
    {
        $wp = self::str($row, 'post_status');

        if ($wp === 'future') {
            return AnnouncementCapabilityResolver::STATUS_SCHEDULED;
        }

        if ($wp === 'publish') {
            return AnnouncementCapabilityResolver::STATUS_PUBLISHED;
        }

        return AnnouncementCapabilityResolver::STATUS_DRAFT;
    }

    /**
     * MySQL UTC datetime → ISO-8601 UTC. Returns null for an empty or
     * unparseable value rather than inventing an epoch date.
     */
    private static function iso(string $mysqlUtc): ?string
    {
        $mysqlUtc = trim($mysqlUtc);
        if ($mysqlUtc === '' || str_starts_with($mysqlUtc, '0000-00-00')) {
            return null;
        }

        $ts = strtotime($mysqlUtc . ' UTC');

        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * The canonical frontend permalink (§4.32.3 `links.self`).
     *
     * Slug-shaped, matching the contract's worked examples
     * (`/v/<slug>/a/<id>`). The validator slug is supplied by the caller —
     * this service resolves nothing.
     */
    private static function permalink(string $validatorSlug, int $id): string
    {
        return '/v/' . $validatorSlug . '/a/' . $id;
    }

    /**
     * Published so a test can assert the meta keys this projection reads
     * are the ones `AnnouncementVisibility` defines — two spellings of the
     * same key is how a projection silently stops reflecting reality.
     *
     * @return array<string, string>
     */
    public static function metaKeysRead(): array
    {
        return [
            'archived_at'      => AnnouncementVisibility::META_ARCHIVED_AT,
            'comments_enabled' => AnnouncementVisibility::META_COMMENTS_ENABLED,
        ];
    }
}
