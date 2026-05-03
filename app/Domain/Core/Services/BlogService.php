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
use BCC\Trust\Core\Repositories\HiddenActivityRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class BlogService
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT     = 50;

    public function __construct(
        private readonly HiddenActivityRepository $hiddenRepo
    ) {
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
                self::hydrateBlogBody($row),
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
     * @param object{act_external_id: int|numeric-string} $row
     * @return array{excerpt: string, full_text: string, wp_post_id: int}
     */
    private static function hydrateBlogBody(object $row): array
    {
        $postId = (int) $row->act_external_id;
        if ($postId <= 0) {
            return ['excerpt' => '', 'full_text' => '', 'wp_post_id' => 0];
        }
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return ['excerpt' => '', 'full_text' => '', 'wp_post_id' => $postId];
        }
        return [
            'excerpt'    => (string) $post->post_excerpt,
            'full_text'  => (string) $post->post_content,
            'wp_post_id' => $postId,
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
