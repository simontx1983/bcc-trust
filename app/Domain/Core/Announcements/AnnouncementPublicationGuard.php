<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Announcements;

use BCC\Core\Log\Logger;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as Authority;

/**
 * Publication-time authority re-check — reconciler arm C.
 *
 * ── ⚠⚠ THE PROBLEM THIS EXISTS FOR ──────────────────────────────────────────
 * A3 writes the publication marker when the operator issues `publish` or
 * `schedule`. For `schedule` that authorization is captured now and acted on
 * LATER, by WordPress's own cron, with no operator session. So a claim revoked,
 * removed or made ambiguous between scheduling and the due instant would
 * otherwise publish the notice anyway, under an authorization that no longer
 * holds. That was the one residual risk A3 named and did not solve; this closes
 * it.
 *
 * ── WHAT CORE ACTUALLY DOES, TRACED NOT ASSUMED (WP 6.7.2) ──────────────────
 * `_future_post_hook()` schedules `publish_future_post` for the row's
 * `post_date_gmt`. On that event, `check_and_publish_future_post()` runs at
 * priority 10, returns early unless `post_status === 'future'`, and otherwise
 * calls `wp_publish_post()`.
 *
 * ⚠ `wp_publish_post()` writes the status with a DIRECT `$wpdb->update` and
 * never goes through `wp_insert_post()`. So **no filter can veto it** —
 * `wp_insert_post_data` does not fire. Only actions fire, and they fire AFTER
 * the row is already `publish`.
 *
 * That shapes the guard into two layers with deliberately different strengths:
 *
 *   LAYER 1 — A TRUE VETO, cron path only.
 *     Hooked on `publish_future_post` at priority 1, i.e. BEFORE core's
 *     callback. If authority fails we block the row here; core's callback then
 *     sees `post_status !== 'future'` and returns early. Publication never
 *     happens, so there is NO window in which the row is public. This is the
 *     path that matters, because it is the path a scheduled notice takes.
 *
 *   LAYER 2 — A REVERT BACKSTOP, every other path.
 *     Hooked on `transition_post_status`. Catches an entry into `publish` from
 *     anywhere — wp-admin, WP-CLI, another plugin calling `wp_publish_post()`
 *     or `wp_insert_post()` directly, a REST route that is not ours. It cannot
 *     veto, because by the time any action fires the status is already written;
 *     it reverts immediately, in the same request, before a response is sent.
 *
 * ⚠ The honest limit of layer 2: between core's `$wpdb->update` and this hook
 * the row is `publish` WITH a valid marker, so a concurrent request in another
 * process could read it. The window is microseconds inside one request and
 * cannot be closed by any action-based guard, because core offers no filter on
 * that write. Layer 1 removes the window entirely for the scheduled path, which
 * is the only path where authorization is stale by construction. Stated rather
 * than papered over.
 *
 * ── FAIL CLOSED ─────────────────────────────────────────────────────────────
 * Blocking does three things, in this order: strip the publication marker (so
 * visibility predicate 3 fails even if a status write races), move the row to
 * `draft` (so predicate 1 fails too), then stamp the blocked marker. An
 * unreadable authority answer counts as failure, not as permission.
 */
final class AnnouncementPublicationGuard
{
    /**
     * Set when arm C has withheld publication. Presence IS the `blocked` read
     * state, which until now had no producer and so was unreachable.
     */
    public const META_BLOCKED_AT = '_bcc_announcement_blocked_at';

    /** A bounded reason. ⚠ Never the authority's private denial vocabulary. */
    public const META_BLOCKED_REASON = '_bcc_announcement_blocked_reason';

    /**
     * ⚠ A CLOSED set, and deliberately coarser than the authority's reasons.
     * `claim_absent`, `claim_ambiguous` and `not_claimer` are private
     * vocabulary (§4.32.11); collapsing them to one operator-facing reason is
     * what stops the blocked state becoming a disclosure channel.
     */
    public const REASON_AUTHORITY_LOST = 'authority_lost';
    public const REASON_UNVERIFIABLE   = 'authority_unverifiable';

    public static function register(): void
    {
        // LAYER 1 — the veto. Priority 1 so it runs before core's
        // check_and_publish_future_post() at priority 10.
        add_action('publish_future_post', [self::class, 'vetoScheduledPublication'], 1, 1);

        // LAYER 2 — the backstop, for every path that is not the cron.
        add_action('transition_post_status', [self::class, 'onTransition'], 10, 3);
    }

    /**
     * LAYER 1. Runs before core decides to publish a due row.
     *
     * @param int|\WP_Post $post
     */
    public static function vetoScheduledPublication($post): void
    {
        $postId = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
        if ($postId <= 0) {
            return;
        }

        $row = get_post($postId);
        if (!$row instanceof \WP_Post || $row->post_type !== AnnouncementPostType::POST_TYPE) {
            return;
        }
        // Only a row core would actually publish is our business.
        if ($row->post_status !== 'future') {
            return;
        }

        if (self::stillAuthorized($row)) {
            // ⚠⚠ THE MARKER IS WRITTEN HERE, AT PROMOTION — not at schedule
            // time. This is the second of the three moments the CRUD design
            // doc's §6.1 named, chosen on measured evidence: writing it at
            // schedule time left an OVERDUE scheduled row (past date, marker
            // present) publicly readable in the interval between core's status
            // write and the backstop, because predicate 2 had stopped
            // refusing. A row that is not yet public now carries no marker at
            // all, so predicate 3 refuses throughout that interval.
            //
            // ⚠ Written BEFORE core publishes (this hook is priority 1, core's
            // is 10), and only after stillAuthorized() has just re-resolved
            // the SAME operator authority for the user the row records. The
            // guard mints no authority of its own.
            self::affirm($row);

            return;
        }

        // ⚠ Blocking here means core's callback sees a non-`future` status and
        // returns without publishing. The notice is never public.
        self::block($row, self::reasonFor($row));
    }

    /**
     * Write the publication-authorization marker for a row whose authority has
     * just been re-confirmed.
     *
     * ⚠ The user id must be `post_author`, because visibility predicate 3
     * compares the marker's user id against `post_author` numerically. Writing
     * the marker's own recorded user instead would be correct only while the
     * two agree, and silently unpublishable when they do not.
     */
    private static function affirm(\WP_Post $post): void
    {
        $postId = (int) $post->ID;

        update_post_meta($postId, AnnouncementVisibility::META_AUTHORIZED_AT, gmdate('Y-m-d H:i:s'));
        update_post_meta($postId, AnnouncementVisibility::META_AUTHORIZED_USER_ID, (string) (int) $post->post_author);
    }

    /**
     * LAYER 2. Any entry into `publish`, from any path.
     *
     * @param string   $new
     * @param string   $old
     * @param \WP_Post $post
     */
    public static function onTransition($new, $old, $post): void
    {
        if (!$post instanceof \WP_Post || $post->post_type !== AnnouncementPostType::POST_TYPE) {
            return;
        }

        // ⚠⚠ LEAVING `publish` RETIRES THE MARKER. A published row demoted to
        // draft used to keep it, and that stale marker was MEASURED as a real
        // exposure: re-publishing such a row by any non-cron path satisfied all
        // three visibility predicates for the whole interval between core's
        // status write and this backstop (interval probe, cases B and F).
        //
        // The marker means "authorized to publish at time T". Once the row is
        // not public that authorization is spent, so retiring it is the honest
        // semantic as well as the safe one.
        //
        // ⚠ THIS IS NOT A POST-PUBLICATION REVOCATION POLICY. It fires only
        // when something has ALREADY taken the row out of `publish`; it never
        // un-publishes anything. A row that stays published keeps its marker
        // untouched.
        if ($old === 'publish' && $new !== 'publish') {
            delete_post_meta((int) $post->ID, AnnouncementVisibility::META_AUTHORIZED_AT);
            delete_post_meta((int) $post->ID, AnnouncementVisibility::META_AUTHORIZED_USER_ID);

            return;
        }

        if ($new !== 'publish') {
            return;
        }
        // Nothing to do when it was already public.
        if ($old === 'publish') {
            return;
        }

        if (self::stillAuthorized($post)) {
            return;
        }

        self::block($post, self::reasonFor($post));
    }

    /**
     * Re-resolve authority FRESH for the row's page and its authorizing user.
     *
     * ⚠⚠ The user checked is the one named by the publication marker, falling
     * back to `post_author`. It is NOT `get_current_user_id()`: on the cron
     * path there is no current user at all, and using the current user would
     * make the answer depend on who happened to trigger wp-cron.
     *
     * ⚠ The page is `post_parent`, which is where A3 stores it. A row with no
     * parent cannot be authorized, so it fails closed.
     */
    private static function stillAuthorized(\WP_Post $post): bool
    {
        $pageId = (int) $post->post_parent;
        if ($pageId <= 0) {
            return false;
        }

        $userId = (int) get_post_meta((int) $post->ID, AnnouncementVisibility::META_AUTHORIZED_USER_ID, true);
        if ($userId <= 0) {
            $userId = (int) $post->post_author;
        }
        if ($userId <= 0) {
            return false;
        }

        try {
            $decision = Authority::decide($pageId, $userId);
        } catch (\Throwable $e) {
            // ⚠ An unreadable authority answer is NOT permission. The read
            // side established the same rule for an unreadable repository: a
            // verification that cannot be completed is a verification that
            // failed.
            return false;
        }

        return ($decision['decision'] ?? null) === Authority::DECISION_OPERATOR;
    }

    /** @return string one of the two bounded reasons */
    private static function reasonFor(\WP_Post $post): string
    {
        $pageId = (int) $post->post_parent;
        $userId = (int) get_post_meta((int) $post->ID, AnnouncementVisibility::META_AUTHORIZED_USER_ID, true);
        if ($userId <= 0) {
            $userId = (int) $post->post_author;
        }

        if ($pageId <= 0 || $userId <= 0) {
            return self::REASON_UNVERIFIABLE;
        }

        try {
            Authority::decide($pageId, $userId);
        } catch (\Throwable $e) {
            return self::REASON_UNVERIFIABLE;
        }

        // ⚠ Collapsed on purpose. Whether the claim was revoked, removed or is
        // ambiguous is private vocabulary; the operator-facing reason says only
        // that authority no longer holds.
        return self::REASON_AUTHORITY_LOST;
    }

    /**
     * Close the row, marker first.
     *
     * ⚠⚠ ORDER IS LOAD-BEARING. The publication marker goes FIRST, because it
     * is visibility predicate 3: once it is gone the row cannot be public even
     * if a concurrent write puts the status back to `publish`. Reverting the
     * status first would leave a window in the opposite direction.
     */
    private static function block(\WP_Post $post, string $reason): void
    {
        $postId = (int) $post->ID;
        $now    = gmdate('Y-m-d H:i:s');

        // 1. Predicate 3 — remove the authorization.
        delete_post_meta($postId, AnnouncementVisibility::META_AUTHORIZED_AT);
        delete_post_meta($postId, AnnouncementVisibility::META_AUTHORIZED_USER_ID);

        // 2. Predicate 1 — take it out of a public status.
        // ⚠ Through the REPOSITORY, which owns the direct write. §1 permits raw
        // $wpdb only there, and this guard is a forbidden layer — the same
        // mistake arch-guardrails already caught once in this slice.
        // ⚠ The write is direct rather than wp_update_post() because
        // wp_update_post() would re-enter the transition machinery and recurse
        // into this very guard. Core's wp_publish_post() writes it the same way.
        try {
            (new \BCC\Trust\Core\Repositories\AnnouncementRepository())
                ->forceStatus($postId, 'draft');
        } catch (\Throwable $e) {
            // ⚠ The marker is already gone, so predicate 3 ALREADY fails and
            // the row cannot be public even though the status write did not
            // land. Logged at error because the row is now in a shape an
            // operator should look at, not because it is exposed.
            Logger::error(
                '[bcc-trust] announcement blocked but the status write failed; it is non-public via the absent marker',
                ['announcement_id' => $postId]
            );
        }

        // 3. Record that arm C did this, which is what makes the state
        //    distinguishable from an ordinary draft.
        update_post_meta($postId, self::META_BLOCKED_AT, $now);
        update_post_meta($postId, self::META_BLOCKED_REASON, $reason);

        // ⚠ Also clear any pending cron event, or core will try again on the
        // next tick and log a second block every time.
        wp_clear_scheduled_hook('publish_future_post', [$postId]);

        Logger::warning(
            '[bcc-trust] announcement publication withheld: operator authority no longer holds',
            [
                'announcement_id' => $postId,
                'page_id'         => (int) $post->post_parent,
                'reason'          => $reason,
            ]
        );
    }

    /** Whether arm C has blocked this row. */
    public static function isBlocked(int $announcementId): bool
    {
        $v = get_post_meta($announcementId, self::META_BLOCKED_AT, true);

        return is_string($v) && trim($v) !== '';
    }

    /**
     * Clear the blocked state — the permitted recovery path.
     *
     * ⚠ Called ONLY from the write layer, after a fresh operator authority
     * check has already passed for the acting operator. It does not itself
     * authorize anything; clearing the marker without that check would make
     * recovery a bypass.
     */
    public static function clearBlock(int $announcementId): void
    {
        delete_post_meta($announcementId, self::META_BLOCKED_AT);
        delete_post_meta($announcementId, self::META_BLOCKED_REASON);
    }
}
