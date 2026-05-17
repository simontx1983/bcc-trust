<?php
/**
 * Blog Service — composes the §D6 per-user blog tab response.
 *
 * Surfaces blog-kinded peepso_activities rows for a single author with
 * the FULL body hydrated (post_excerpt + post_content), in contrast to
 * the Floor pipeline which hydrates only post_excerpt.
 *
 * Responsibilities:
 *   - Author scope: filter `act_user_id = userId AND act_module_id = 'blog'`
 *   - Body hydration: read post_excerpt + post_content from wp_posts
 *   - Author hydration: single get_userdata call (the surface is per-user)
 *   - Cursor pagination: same opaque format as FeedEndpoint
 *
 * Out of scope (V1):
 *   - Reactions, social proof, permissions per-item — defaults only.
 *     Adding them later means swapping in ActivityFeedService's batched
 *     hydrators when blog reactions land.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §D6 blog tab)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Feed\FeedItemNormalizer;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Repositories\BlogChainTagRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class BlogService
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT     = 50;

    public function __construct(
        private readonly HiddenActivityRepository $hiddenRepo,
        // PR-A — chain-tag join read-side. Optional in the signature
        // so the legacy 1-arg constructor still works for tests; the
        // Plugin singleton always supplies the real repo.
        private readonly ?BlogChainTagRepository $chainTagRepo = null
    ) {
    }

    private function chainTags(): BlogChainTagRepository
    {
        return $this->chainTagRepo ?? new BlogChainTagRepository();
    }

    /**
     * Return paginated blog items authored by $userId, newest-first.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    public function getUserBlog(
        int $userId,
        int $viewerId,
        ?string $cursor = null,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        unset($viewerId); // V1: blog rows are public; reactions/permissions
                          // hydrate as defaults. When per-viewer permissions
                          // land, this becomes the gate.

        if ($userId <= 0) {
            return self::emptyPage();
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));

        [$cursorTime, $cursorActId] = self::decodeCursor($cursor);

        // §K1 Phase C — moderation-hidden act_ids excluded so a flagged
        // blog post doesn't surface in its author's tab either.
        $hidden = $this->hiddenRepo->getAllHiddenIds();

        // Over-fetch by 1 to detect has_more without a separate count.
        $rows = PeepSoActivityRepository::getActivities(
            [$userId],
            ['blog'],
            $cursorTime,
            $cursorActId,
            $limit + 1,
            null,
            $hidden === [] ? null : $hidden
        );

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $author = self::hydrateAuthor($userId);

        $items = [];
        foreach ($rows as $row) {
            $items[] = FeedItemNormalizer::normalize(
                $row,
                $author,
                $this->hydrateBlogBody($row),
                null,  // reactions  — V1 default
                null,  // socialProof — V1 default
                null,  // attachedCard — V1 default
                []     // permissions — V1 default
            );
        }

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last       = $rows[count($rows) - 1];
            $nextCursor = self::encodeCursor((string) $last->act_time, (int) $last->act_id);
        }

        return [
            'items'      => $items,
            'pagination' => [
                'next_cursor' => $nextCursor,
                'has_more'    => $hasMore,
            ],
        ];
    }

    /**
     * Per-row body hydrator — full_text included (blog-tab context, vs.
     * the Floor context which omits it; see ActivityFeedService::resolveBody).
     *
     * PR-A extends the body shape with the crypto-blog composer's
     * sidecar fields:
     *   - title           — closes the existing §3.3.8 contract gap
     *                       (the spec listed it; the hydrator omitted it)
     *   - category        — string|null from `_bcc_blog_category` post_meta
     *   - tags            — list<string>; [] when meta absent or invalid
     *   - chain_tags      — list of resolved chain rows (id/slug/name/color/icon)
     *   - disclosure      — {tickers, note}|null from `_bcc_blog_disclosure`
     *   - cover_image_url — large-size thumbnail URL or null when no thumbnail
     *
     * Backward compatibility: pre-PR-A blog posts have no `_bcc_blog_*`
     * post_meta keys + no chain-tag rows + no thumbnail. The hydrator
     * returns `null`/`[]` for every new field in that case rather than
     * throwing — readers must handle absence gracefully.
     *
     * @param object{act_external_id: int|numeric-string} $row
     * @return array{
     *   excerpt: string,
     *   full_text: string,
     *   wp_post_id: int,
     *   title: string,
     *   category: ?string,
     *   tags: list<string>,
     *   chain_tags: list<array{id: int, slug: string, name: string, color: ?string, icon_url: ?string}>,
     *   disclosure: ?array{tickers: list<string>, note: string},
     *   cover_image_url: ?string,
     *   cover_image_id: ?int,
     *   sources: list<string>
     * }
     */
    private function hydrateBlogBody(object $row): array
    {
        $postId = (int) $row->act_external_id;
        return $this->hydrateForPostId($postId, true);
    }

    /**
     * Public §D6 body hydrator keyed on the wp_post id directly.
     *
     * Used by:
     *   - {@see hydrateBlogBody} (this class) — wraps for the blog-tab
     *     read surface (`/users/:handle/blog`). `$includeFullText=true`.
     *   - {@see FeedRankingService::loadBlogBodies} — for the Floor
     *     feed surface. `$includeFullText=false` (Floor context omits
     *     `full_text` per the §3.3.8 contract — the body is rendered
     *     by the standalone blog tab, not inline on the Floor).
     *
     * Stripping `full_text` server-side keeps Floor payloads small
     * (a 60KB body × 20 items = 1.2MB; 20 items × ~1KB excerpt is
     * negligible). The frontend's `BlogExcerptBody` only reads
     * title/excerpt/category/chain_tags/cover_image_url for the Floor
     * card; full_text never surfaces there.
     *
     * @return array{
     *   excerpt: string,
     *   full_text: string,
     *   wp_post_id: int,
     *   title: string,
     *   category: ?string,
     *   tags: list<string>,
     *   chain_tags: list<array{id: int, slug: string, name: string, color: ?string, icon_url: ?string}>,
     *   disclosure: ?array{tickers: list<string>, note: string},
     *   cover_image_url: ?string,
     *   cover_image_id: ?int,
     *   sources: list<string>
     * }
     */
    public function hydrateForPostId(int $postId, bool $includeFullText): array
    {
        if ($postId <= 0) {
            return self::emptyBody(0);
        }
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return self::emptyBody($postId);
        }
        $body = $this->loadBodyFromPost($post);
        if (!$includeFullText) {
            $body['full_text'] = '';
        }
        return $body;
    }

    /**
     * Internal body builder — pulled out of hydrateBlogBody so both
     * the activity-row path (`hydrateBlogBody`) and the direct
     * post-id path (`hydrateForPostId`) share one implementation.
     *
     * @return array{
     *   excerpt: string,
     *   full_text: string,
     *   wp_post_id: int,
     *   title: string,
     *   category: ?string,
     *   tags: list<string>,
     *   chain_tags: list<array{id: int, slug: string, name: string, color: ?string, icon_url: ?string}>,
     *   disclosure: ?array{tickers: list<string>, note: string},
     *   cover_image_url: ?string,
     *   cover_image_id: ?int,
     *   sources: list<string>
     * }
     */
    private function loadBodyFromPost(\WP_Post $post): array
    {
        $postId = (int) $post->ID;

        // ── Free-form tags from JSON post_meta ──────────────────────
        //
        // Pre-PR-A posts have no meta key → tags = []. A corrupted
        // JSON blob (manual SQL edit, schema drift) also resolves to
        // [] — we never throw inside a hydrator.
        $tagsRaw = get_post_meta($postId, '_bcc_blog_tags', true);
        $tags    = [];
        if (is_string($tagsRaw) && $tagsRaw !== '') {
            $decoded = json_decode($tagsRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $t) {
                    if (is_string($t) && $t !== '') {
                        $tags[] = $t;
                    }
                }
            }
        }

        // ── Category (string|null) ──────────────────────────────────
        $catRaw  = get_post_meta($postId, '_bcc_blog_category', true);
        $category = (is_string($catRaw) && $catRaw !== '') ? $catRaw : null;

        // ── Disclosure block ────────────────────────────────────────
        $discRaw = get_post_meta($postId, '_bcc_blog_disclosure', true);
        $disclosure = null;
        if (is_string($discRaw) && $discRaw !== '') {
            $decoded = json_decode($discRaw, true);
            if (is_array($decoded) && isset($decoded['tickers'], $decoded['note'])) {
                /** @var list<string> $tickersOut */
                $tickersOut = [];
                if (is_array($decoded['tickers'])) {
                    foreach ($decoded['tickers'] as $t) {
                        if (is_string($t) && $t !== '') {
                            $tickersOut[] = $t;
                        }
                    }
                }
                $note = is_string($decoded['note']) ? $decoded['note'] : '';
                $disclosure = ['tickers' => $tickersOut, 'note' => $note];
            }
        }

        // ── Chain tags — resolve via FK to bcc_onchain_chains ────────
        //
        // PR-A reads per-post because BlogService is a single-author
        // tab — the per-row chain-tag fetch is bounded by
        // BLOG_CHAIN_TAGS_MAX. When the Floor renderer adopts blog
        // excerpts at multi-author scale it should batch via
        // BlogChainTagRepository::findByPostIds + a single
        // ChainRepository fetch (already cache-backed).
        $chainIds = $this->chainTags()->findByPostId($postId);
        /** @var list<array{id: int, slug: string, name: string, color: ?string, icon_url: ?string}> $chainRows */
        $chainRows = [];
        foreach ($chainIds as $cid) {
            $chain = ChainRepository::getById($cid);
            if ($chain === null) {
                // Chain was deactivated / deleted after the blog was
                // tagged — silently drop from the hydrated body rather
                // than emitting a row with stale identifiers.
                continue;
            }
            // Defensive null-coalesce — pre-migration rows could still
            // be in the DB cache during the window between code deploy
            // and the dbDelta re-run picking up the new color column.
            // The ChainRow phpdoc declares `color: string|null` so this
            // narrows cleanly.
            $color   = is_string($chain->color)    ? $chain->color    : null;
            $iconUrl = is_string($chain->icon_url) ? $chain->icon_url : null;
            $chainRows[] = [
                'id'       => (int) $chain->id,
                'slug'     => (string) $chain->slug,
                'name'     => (string) $chain->name,
                'color'    => $color,
                'icon_url' => $iconUrl,
            ];
        }

        // ── Cover image — large-size URL or null + attachment id ─────
        //
        // `cover_image_id` lets the edit-flow composer round-trip an
        // existing cover without re-uploading: the composer pre-fills
        // its CoverImageValue from `{attachment_id, url}` and only
        // sends `cover_image_id` back on PATCH when the writer
        // actually changed it (omit-to-unchanged contract).
        $coverUrl = get_the_post_thumbnail_url($postId, 'large');
        $coverImageUrl = is_string($coverUrl) ? $coverUrl : null;
        $coverImageId  = (int) get_post_thumbnail_id($postId);
        if ($coverImageId <= 0) {
            $coverImageId = null;
        }

        // ── Sources — numbered citations, free strings ───────────────
        //
        // Stored as JSON-encoded list<string> on `_bcc_blog_sources`.
        // Empty/missing meta key → []. Same defensive-parse posture
        // as tags: corrupted JSON yields [], never throws.
        $sourcesRaw = get_post_meta($postId, '_bcc_blog_sources', true);
        $sources    = [];
        if (is_string($sourcesRaw) && $sourcesRaw !== '') {
            $decoded = json_decode($sourcesRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $s) {
                    if (is_string($s) && $s !== '') {
                        $sources[] = $s;
                    }
                }
            }
        }

        return [
            'excerpt'         => (string) $post->post_excerpt,
            'full_text'       => (string) $post->post_content,
            'wp_post_id'      => $postId,
            'title'           => (string) $post->post_title,
            'category'        => $category,
            'tags'            => $tags,
            'chain_tags'      => $chainRows,
            'disclosure'      => $disclosure,
            'cover_image_url' => $coverImageUrl,
            'cover_image_id'  => $coverImageId,
            'sources'         => $sources,
        ];
    }

    /**
     * Empty-body shape — keeps the contract surface stable when the
     * underlying wp_post can't be loaded (deleted, never existed,
     * or the act_external_id was zero). Every new PR-A field is
     * present with its null/[] sentinel so the frontend never has
     * to branch on "field exists vs missing".
     *
     * @return array{
     *   excerpt: string,
     *   full_text: string,
     *   wp_post_id: int,
     *   title: string,
     *   category: null,
     *   tags: list<string>,
     *   chain_tags: list<array{id: int, slug: string, name: string, color: ?string, icon_url: ?string}>,
     *   disclosure: null,
     *   cover_image_url: null,
     *   cover_image_id: null,
     *   sources: list<string>
     * }
     */
    private static function emptyBody(int $postId): array
    {
        return [
            'excerpt'         => '',
            'full_text'       => '',
            'wp_post_id'      => $postId,
            'title'           => '',
            'category'        => null,
            'tags'            => [],
            'chain_tags'      => [],
            'disclosure'      => null,
            'cover_image_url' => null,
            'cover_image_id'  => null,
            'sources'         => [],
        ];
    }

    /**
     * Single-user author hydration — minimal block matching what
     * ActivityFeedService::hydrateAuthors emits, but skipping the bulk
     * lookups since this surface is always one author.
     *
     * @return array<string, mixed>
     */
    private static function hydrateAuthor(int $userId): array
    {
        $user = get_userdata($userId);
        if ($user === false) {
            return [
                'kind'                  => 'user',
                'id'                    => $userId,
                'handle'                => '',
                'display_name'          => '',
                'avatar_url'            => '',
                'card_tier'             => null,
                'rank_label'            => null,
                'is_in_good_standing'   => true,
                'is_followed_by_viewer' => false,
            ];
        }

        $handleRaw = get_user_meta($userId, 'bcc_handle', true);
        $handle    = is_string($handleRaw) && $handleRaw !== '' ? $handleRaw : $user->user_login;
        $avatarUrl = get_avatar_url($userId);

        return [
            'kind'                  => 'user',
            'id'                    => $userId,
            'handle'                => $handle,
            'display_name'          => $user->display_name !== '' ? $user->display_name : $user->user_login,
            'avatar_url'            => is_string($avatarUrl) ? $avatarUrl : '',
            // Trust-derived fields — leave as conservative defaults.
            // The blog tab is a per-user surface; the profile header
            // already shows the canonical card with proper trust state.
            'card_tier'             => null,
            'rank_label'            => null,
            'is_in_good_standing'   => true,
            'is_followed_by_viewer' => false,
        ];
    }

    /**
     * Cursor format mirrors ActivityFeedService — base64url-encoded JSON
     * `{"t":"<iso8601>","id":<act_id>}`. Kept independent so ranker /
     * non-ranker surfaces don't accidentally cross-decode each other.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private static function decodeCursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return [null, null];
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) {
            return [null, null];
        }

        $data = json_decode($decoded, true);
        if (!is_array($data) || !isset($data['t'], $data['id'])) {
            return [null, null];
        }

        $iso = (string) $data['t'];
        $ts  = strtotime($iso);
        if ($ts === false) {
            return [null, null];
        }

        return [gmdate('Y-m-d H:i:s', $ts), (int) $data['id']];
    }

    private static function encodeCursor(string $mysqlDatetime, int $actId): string
    {
        $ts  = strtotime($mysqlDatetime . ' UTC');
        $iso = $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : '';

        $payload = json_encode(['t' => $iso, 'id' => $actId], JSON_UNESCAPED_SLASHES);
        return rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
    }

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: null, has_more: false}}
     */
    private static function emptyPage(): array
    {
        return [
            'items'      => [],
            'pagination' => [
                'next_cursor' => null,
                'has_more'    => false,
            ],
        ];
    }
}
