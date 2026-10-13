<?php
/**
 * Private storage for validator announcements, plus the four guards that
 * keep WordPress's own comment surfaces from serving them.
 *
 * api-contract-v1.md §4.32 (storage) and §4.32.10 (the switch). This is
 * unit **A1**: the type exists and the guards are live; there are NO
 * routes, NO service, NO UI and NO writer. Nothing can create an
 * announcement yet.
 *
 * ## ⚠ The guards are registered UNCONDITIONALLY
 *
 * They do NOT consult `bcc_announcements_enabled`. Turning the feature off
 * must never *open* the bypass surface — a switch that also disabled the
 * guards would mean the safest-looking configuration was the least safe
 * one. The switch gates routes and surfaces; it never gates a guard.
 *
 * ## The four guards, and what each is for
 *
 * | # | Guard | Closes |
 * |---|---|---|
 * | 1 | `comments_open` → always false | core REST create (`rest_comment_closed`) and `wp-comments-post.php` (`comment_closed`) |
 * | 2 | `comments_clauses` → exclude our comment type | every `WP_Comment_Query`: the core REST collection, wp-admin list tables, feeds, widgets |
 * | 3 | `show_in_rest => false` on the type | single-comment routes, post-scoped collections, create |
 * | 4 | `map_meta_cap` → deny `edit_comment` for our type | core mutation paths |
 *
 * Guard 2 exists because guard 3 leaves exactly one gap: `GET
 * /wp/v2/comments` **without** `?post=` skips the post loop, and
 * `WP_REST_Comments_Controller::check_read_permission()` ends by allowing
 * `edit_comment` (→ `moderate_comments`) — so an Editor or Administrator
 * could otherwise enumerate announcement comments, including removed ones
 * with bodies and author identity, outside the audited admin route.
 * Single-comment routes bypass `WP_Comment_Query` entirely, which is why
 * guard 3 is still needed and guard 2 is not a substitute for it.
 *
 * **Guard 2 needs no bypass flag.** Our own reader will be a bcc-trust
 * Repository using `$wpdb` directly (the §1 house convention). Measured
 * across all three BCC plugins: ZERO occurrences of `new WP_Comment_Query`
 * or `get_comments(`, and `wp_comments` is touched in exactly two places,
 * both read-only `COUNT(*)` in `UserSyncRepository`. So the exclusion is
 * unconditional and there is no escape hatch to misuse. Writes are
 * unaffected — `wp_insert_comment`, `wp_set_comment_status` and
 * `get_comment($id)` all bypass `WP_Comment_Query`.
 *
 * ## ⚠⚠ `show_in_rest => false` is a security control, not a preference
 *
 * Verified by reading WordPress **7.0.2** source in this environment:
 * `WP_REST_Posts_Controller::check_read_permission()`
 * (`class-wp-rest-posts-controller.php:1784`) opens with
 * `check_is_post_type_allowed()` (`:1762`), which returns true only on
 * `! empty( $post_type->show_in_rest )` — and that test sits **before**
 * the `'publish' === $post->post_status || current_user_can('read_post')`
 * line. So it denies for every role, administrators included.
 *
 * A step worth recording because it looks like a bypass and is not:
 * `WP_Post_Type::get_rest_controller()` (`class-wp-post-type.php:869`)
 * returns **null** when `show_in_rest` is false, but
 * `WP_REST_Comments_Controller::check_read_post_permission()` (`:1870`)
 * constructs a fresh `WP_REST_Posts_Controller` anyway and routes to the
 * same denial.
 *
 * ## ⚠⚠⚠ THE `post_password` RESIDUAL
 *
 * Immediately above that call, the same method has:
 *
 * ```php
 * if ( post_password_required( $post ) ) {
 *     $result = current_user_can( 'edit_post', $post->ID );
 * } else {
 *     $result = $posts_controller->check_read_permission( $post );
 * }
 * ```
 *
 * **A password-protected post never reaches `check_read_permission()` at
 * all.** On that branch anyone holding the mapped `edit_post` capability
 * reads the comment, and `show_in_rest => false` is bypassed entirely
 * because it is only consulted in the `else`.
 *
 * Two independent defences, and this class carries both rather than
 * relying on either:
 *
 *   1. **Custom `capability_type` with `map_meta_cap => true`** — an
 *      administrator does not hold `edit_bcc_announcement` by default, so
 *      the mapped `edit_post` check fails. This is the load-bearing one.
 *   2. **`post_password` is forced empty** on every save of this type
 *      ({@see forcePasswordEmpty}), so the dangerous branch is
 *      unreachable rather than merely unlikely.
 *
 * Defence 2 exists because "nothing sets a password on this type" is not
 * a guarantee — a plugin, an import, a migration or hand-written SQL can.
 * The guard enforces the invariant instead of assuming it.
 *
 * ## ⚠ The `note` comment type
 *
 * `WP_REST_Comments_Controller::check_read_permission()` opens:
 *
 * ```php
 * if ( 'note' !== $comment->comment_type && ! empty( $comment->comment_post_ID ) ) {
 * ```
 *
 * For `comment_type === 'note'` the **entire post-permission check is
 * skipped** — WordPress 7.0 treats notes as an internal editorial channel.
 * Our type is {@see COMMENT_TYPE}, so we take the post path; the constant
 * exists partly so that can be asserted rather than assumed, and guard 2
 * excludes our type by name regardless of what core does with notes.
 *
 * @package BCC\Trust\Core\Announcements
 */

namespace BCC\Trust\Core\Announcements;

if (!defined('ABSPATH')) {
    exit;
}

final class AnnouncementPostType
{
    /**
     * 16 characters — under the `wp_posts.post_type` `varchar(20)` limit
     * that a 22-character slug previously exceeded.
     */
    public const POST_TYPE = 'bcc_announcement';

    /**
     * Our comment type. Deliberately NOT `comment` (which would make the
     * exclusion in guard 2 catch every ordinary comment on the site) and
     * deliberately NOT `note` (which core special-cases out of the
     * post-permission check entirely).
     */
    public const COMMENT_TYPE = 'bcc_announcement_comment';

    /** Custom capability base, so generic `edit_posts` cannot satisfy it. */
    public const CAPABILITY_SINGULAR = 'bcc_announcement';
    public const CAPABILITY_PLURAL   = 'bcc_announcements';

    public static function register(): void
    {
        add_action('init', [self::class, 'registerPostType']);

        // ⚠ Guards are added at load, NOT inside the init callback and NOT
        // behind the feature switch. A filter registered later than a
        // consumer runs is a filter that did not apply.
        add_filter('comments_open', [self::class, 'closeComments'], 10, 2);
        add_filter('comments_clauses', [self::class, 'excludeFromCommentQueries'], 10, 2);
        add_filter('map_meta_cap', [self::class, 'denyCommentEditing'], 10, 4);
        add_filter('wp_insert_post_data', [self::class, 'forcePasswordEmpty'], 10, 2);
    }

    public static function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'label'               => 'Validator Announcements',
            // No WordPress front-end route — the canonical URL is the
            // Next.js page.
            'public'              => false,
            'publicly_queryable'  => false,
            // Hidden from wp-admin entirely. This is also what keeps
            // `post_content` (Markdown source, §7.1) from being echoed by
            // any PHP surface.
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'exclude_from_search' => true,
            'has_archive'         => false,
            'rewrite'             => false,
            // 🔒 SECURITY CONTROL — see the class docblock. Flipping this
            // to true opens the core single-comment routes, the
            // post-scoped collection AND comment creation at once, for
            // every role including administrators.
            'show_in_rest'        => false,
            // `comments` is deliberately absent: the discussion is ours,
            // not PeepSo's and not core's.
            'supports'            => ['title', 'editor', 'excerpt', 'author', 'revisions'],
            'capability_type'     => [self::CAPABILITY_SINGULAR, self::CAPABILITY_PLURAL],
            'map_meta_cap'        => true,
        ]);
    }

    // ── Guard 1 ───────────────────────────────────────────────────────────

    /**
     * `comments_open` is false for this type in EVERY state.
     *
     * Not consulted: `_bcc_announcement_comments_enabled`. That meta gates
     * only our own create endpoint (§6.4). Letting it reach here would
     * re-open `wp-comments-post.php` and the core REST create path, which
     * is the whole reason this guard is unconditional.
     *
     * @param  bool    $open
     * @param  int     $postId
     * @return bool
     */
    public static function closeComments($open, $postId): bool
    {
        return self::isAnnouncement($postId) ? false : (bool) $open;
    }

    // ── Guard 2 ───────────────────────────────────────────────────────────

    /**
     * Exclude our comment type from EVERY `WP_Comment_Query`.
     *
     * ⚠ Appends to the existing WHERE rather than replacing it, and
     * returns the clause array untouched when the fragment is already
     * present — a filter that runs twice must not double the predicate.
     *
     * ⚠ The comparison is on `comment_type`, so a row whose type is
     * stored with unexpected case or padding would NOT be excluded by a
     * naive equality. The predicate therefore excludes on the stored value
     * AND on a trimmed, lowercased comparison, so a malformed record is
     * still withheld. "We only ever write the canonical value" is an
     * assumption about the writer, not a property of the table.
     *
     * @param  array<string, string> $clauses
     * @param  mixed                 $query
     * @return array<string, string>
     */
    public static function excludeFromCommentQueries($clauses, $query = null): array
    {
        if (!is_array($clauses)) {
            return [];
        }

        $fragment = self::commentExclusionSql();
        $where    = isset($clauses['where']) && is_string($clauses['where']) ? $clauses['where'] : '';

        if ($where !== '' && str_contains($where, $fragment)) {
            return $clauses;
        }

        $clauses['where'] = $where === '' ? $fragment : $where . ' AND ' . $fragment;
        return $clauses;
    }

    /**
     * The exclusion predicate, exposed so the integration suite can run
     * the REAL fragment against real rows rather than a reconstruction of
     * it.
     *
     * Excludes on the exact stored value and on a normalized comparison,
     * so a record stored as `BCC_Announcement_Comment ` is withheld too.
     */
    public static function commentExclusionSql(): string
    {
        $type = self::COMMENT_TYPE;
        return "( comment_type <> '{$type}'"
            . " AND LOWER(TRIM(comment_type)) <> '{$type}' )";
    }

    // ── Guard 3 is `show_in_rest => false`, above. ────────────────────────

    // ── Guard 4 ───────────────────────────────────────────────────────────

    /**
     * Deny `edit_comment` (and therefore `moderate_comments`-derived
     * access) for our comment type, whatever the actor's role.
     *
     * @param  array<int, string> $caps
     * @param  string             $cap
     * @param  int                $userId
     * @param  array<int, mixed>  $args
     * @return array<int, string>
     */
    public static function denyCommentEditing($caps, $cap, $userId, $args): array
    {
        if (!is_array($caps)) {
            $caps = [];
        }
        if ($cap !== 'edit_comment' || !isset($args[0])) {
            return $caps;
        }

        $comment = function_exists('get_comment') ? get_comment((int) $args[0]) : null;
        if (!is_object($comment) || !isset($comment->comment_type)) {
            // Unknown comment: do NOT widen. Leave the decision to core.
            return $caps;
        }

        if (self::isAnnouncementCommentType((string) $comment->comment_type)) {
            // `do_not_allow` is WordPress's canonical hard deny — no role
            // and no filter further down the chain grants it.
            return ['do_not_allow'];
        }

        return $caps;
    }

    // ── The post_password residual ─────────────────────────────────────────

    /**
     * Force `post_password` empty on every save of this type.
     *
     * ⚠ THIS IS NOT BELT-AND-BRACES. A password on an announcement makes
     * `WP_REST_Comments_Controller::check_read_post_permission()` take the
     * `post_password_required()` branch, which returns
     * `current_user_can('edit_post', …)` and NEVER CONSULTS
     * `show_in_rest`. Custom capabilities are what make that branch fail
     * today; this makes the branch unreachable.
     *
     * Enforced rather than assumed because a plugin, an import, a
     * migration or hand-written SQL can set a password even though our own
     * write path never offers one.
     *
     * @param  array<string, mixed> $data
     * @param  array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public static function forcePasswordEmpty($data, $postarr = []): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (($data['post_type'] ?? '') === self::POST_TYPE) {
            $data['post_password'] = '';
        }
        return $data;
    }

    // ── Predicates ────────────────────────────────────────────────────────

    /**
     * Normalized comment-type test. Trimmed and lowercased, so a record
     * stored with unexpected case or whitespace is still recognised as
     * ours — the guards must hold for records nothing of ours created.
     */
    public static function isAnnouncementCommentType(string $commentType): bool
    {
        return strtolower(trim($commentType)) === self::COMMENT_TYPE;
    }

    /** @param mixed $postId */
    private static function isAnnouncement($postId): bool
    {
        if (!function_exists('get_post_type')) {
            return false;
        }
        $id = (int) (is_object($postId) && isset($postId->ID) ? $postId->ID : $postId);
        return $id > 0 && get_post_type($id) === self::POST_TYPE;
    }
}
