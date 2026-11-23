<?php
/**
 * PostGroupFactsRepository — a post's GROUP facts (which groups it is
 * tagged to, its per-post visibility, the group privacy recorded when it was
 * made public_all), read so that a failed read is a REFUSAL (F-461).
 *
 * ⚠⚠ Read straight from `postmeta`, by one statement, through ProbesReads,
 * never get_post_meta(): a read that cannot be trusted is `null`, never
 * "no group".
 *
 * ⚠⚠ READ THROUGH bcc-core's ProbesReads::trustedRows(), NEVER get_row() /
 * get_results(). `wpdb::query()` can bail BEFORE `flush()` (a failed
 * reconnect, an emptied statement), returning false with no `last_error`
 * while `last_result` still holds the PREVIOUS query's rows. trustedRows()
 * checks query()'s own return, so those rows are never taken as this post's.
 *
 * Every gate that asks "is this a group post, and may this viewer act on
 * it" reads here: FeedItemReportVisibility (reports, the comment routes'
 * F-265 checks, the group permalink rescue), GroupInteractionGate
 * (reactions, stoke, helpful-mark, comment stoke) and
 * CommentService::gateForParent. Each fails CLOSED on null.
 *
 * Not cached, deliberately: one indexed read (postmeta's post_id index) per
 * gated decision.
 *
 * @package BCC\Trust\Core\Repositories
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories;

use BCC\Core\Feed\GroupSyndicationGate;
use BCC\Core\Repositories\ProbesReads;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type PostGroupFacts array{
 *   group_raw: string,
 *   group_ids: list<int>,
 *   visibility: string,
 *   privacy_at_post: string
 * }
 */
final class PostGroupFactsRepository
{
    use ProbesReads;

    /** PeepSo's group tag on a post. A post can carry more than one. */
    public const META_GROUP_ID = 'peepso_group_id';

    /** BCC's per-post group visibility (members_only, public_group, public_all). */
    public const META_VISIBILITY = '_bcc_post_visibility';

    /**
     * More rows than this for one post cannot be proven complete (the read
     * is bounded), so the facts are refused rather than guessed. No real
     * post comes near it: one row per group tag plus two markers.
     */
    private const ROW_CAP = 100;

    /**
     * The post's group facts, or NULL when they cannot be read. Each gate
     * must treat null as "refuse".
     *
     * The shape mirrors what the gates used to take from get_post_meta():
     *
     *   - `group_raw`: the FIRST `peepso_group_id` value by meta_id, as
     *     `get_post_meta(…, true)` returned it ('' when there is none). Kept
     *     raw because the gates read it differently, each in its own strict
     *     direction: the report gate treats any non-empty value as tagged,
     *     the membership gates cast it with `(int) … > 0`.
     *   - `group_ids`: every positive `peepso_group_id`, in meta_id order,
     *     as `get_post_meta(…, false)` returned them.
     *   - `visibility`, `privacy_at_post`: the first value of each, '' when
     *     absent.
     *
     * @phpstan-return PostGroupFacts|null
     */
    public static function forPost(int $postId): ?array
    {
        if ($postId <= 0) {
            return null;
        }

        global $wpdb;

        $rows = self::trustedRows((string) $wpdb->prepare(
            "SELECT meta_key, meta_value
               FROM {$wpdb->postmeta}
              WHERE post_id = %d
                AND meta_key IN (%s, %s, %s)
              ORDER BY meta_id ASC
              LIMIT %d",
            $postId,
            self::META_GROUP_ID,
            self::META_VISIBILITY,
            GroupSyndicationGate::META_PRIVACY_AT_POST,
            self::ROW_CAP + 1
        ));
        if ($rows === null || count($rows) > self::ROW_CAP) {
            return null;
        }

        $groupValues   = [];
        $visibility    = null;
        $privacyAtPost = null;
        foreach ($rows as $row) {
            $key   = (string) ($row->meta_key ?? '');
            $value = (string) ($row->meta_value ?? '');
            if ($key === self::META_GROUP_ID) {
                $groupValues[] = $value;
            } elseif ($key === self::META_VISIBILITY) {
                $visibility ??= $value;
            } elseif ($key === GroupSyndicationGate::META_PRIVACY_AT_POST) {
                $privacyAtPost ??= $value;
            }
        }

        return [
            'group_raw'       => $groupValues[0] ?? '',
            'group_ids'       => array_values(array_filter(
                array_map('intval', $groupValues),
                static fn(int $id): bool => $id > 0
            )),
            'visibility'      => $visibility ?? '',
            'privacy_at_post' => $privacyAtPost ?? '',
        ];
    }
}
