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

    /** The one PERMISSIVE visibility value: syndication outside the group. */
    public const VISIBILITY_GLOBAL = 'public_all';

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
     *   - `visibility`, `privacy_at_post`: folded STRICTEST-WINS across
     *     every row of that key, '' when absent. NOT the first value: with
     *     duplicate rows, first-wins let a permissive one through. See
     *     {@see strictestVisibility()} and {@see strictestPrivacyAtPost()}.
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

        // ⚠⚠ EVERY value is collected, not just the first. Folding the
        // duplicates is done below, STRICTEST-WINS — see the two helpers.
        $groupValues     = [];
        $visibilityRows  = [];
        $privacyRows     = [];
        foreach ($rows as $row) {
            $key   = (string) ($row->meta_key ?? '');
            $value = (string) ($row->meta_value ?? '');
            if ($key === self::META_GROUP_ID) {
                $groupValues[] = $value;
            } elseif ($key === self::META_VISIBILITY) {
                $visibilityRows[] = $value;
            } elseif ($key === GroupSyndicationGate::META_PRIVACY_AT_POST) {
                $privacyRows[] = $value;
            }
        }

        return [
            'group_raw'       => $groupValues[0] ?? '',
            'group_ids'       => array_values(array_filter(
                array_map('intval', $groupValues),
                static fn(int $id): bool => $id > 0
            )),
            'visibility'      => self::strictestVisibility($visibilityRows),
            'privacy_at_post' => self::strictestPrivacyAtPost($privacyRows),
        ];
    }
    /**
     * Fold duplicate `_bcc_post_visibility` rows, STRICTEST-WINS.
     *
     * ⚠⚠ `public_all` is the PERMISSIVE value: it is the one that lets a
     * group-tagged post syndicate OUTSIDE its group. So it is returned only
     * when EVERY row says so. One row disagreeing is enough to withhold it.
     *
     * Duplicate rows should not exist — `attachToGroup()` is the single
     * writer — but "should not exist" is not a gate, and `meta_id` order is
     * not a permission model. Taking the first row made the answer depend on
     * insert order, which nothing guarantees and no caller could inspect.
     *
     * ⚠ When rows disagree this returns a REAL stored value (the first that
     * is not `public_all`) rather than a synthesised one, so the field stays
     * truthful about what is on the post. Which non-`public_all` value it is
     * does not matter to any caller: every consumer tests equality with
     * `public_all` and treats everything else alike.
     *
     * @param list<string> $values every row of the key, in meta_id order
     */
    private static function strictestVisibility(array $values): string
    {
        if ($values === []) {
            return '';
        }

        foreach ($values as $value) {
            if ($value !== self::VISIBILITY_GLOBAL) {
                return $value;
            }
        }

        return self::VISIBILITY_GLOBAL;
    }

    /**
     * Fold duplicate `_bcc_post_group_privacy_at_post` rows, STRICTEST-WINS.
     *
     * ⚠⚠ HERE THE PERMISSIVE ANSWER IS "NON-OPEN", which is the opposite
     * of how it reads. A post recorded as made while its group was ALREADY
     * closed or secret is a DELIBERATE syndication, and
     * `GroupSyndicationGate::allows()` admits it early and keeps it public
     * after the group closes. So "made while non-open" is only returned when
     * EVERY row agrees; one open row withholds it and the post is judged on
     * the group's current privacy instead.
     *
     * ⛔ EACH ROW IS JUDGED BY `GroupSyndicationGate::madeWhileNonOpen()`,
     * THE CONSUMER'S OWN PREDICATE, and not by a second copy of the rule.
     * That is the whole point rather than a tidiness preference: the fold is
     * only as strict as the test it shares with the code that acts on the
     * result. An exact `in_array(…, NON_OPEN_PRIVACY, true)` here looks
     * equivalent and is not — the predicate TRIMS first, so the two
     * disagreed about any padded value, and a row this fold read as "open"
     * was read as non-open downstream. One rule, one implementation.
     *
     * @param list<string> $values every row of the key, in meta_id order
     */
    private static function strictestPrivacyAtPost(array $values): string
    {
        if ($values === []) {
            return '';
        }

        foreach ($values as $value) {
            if (!GroupSyndicationGate::madeWhileNonOpen($value)) {
                return $value;
            }
        }

        return $values[0];
    }
}
