<?php

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\PostEngagementRepository;
use BCC\Trust\Core\Repositories\PostReachRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * F-324 — the owner-only analytics read for one post.
 *
 * ── THREE SYSTEMS, NEVER BLENDED ─────────────────────────────────────
 * The product requirement is explicit: REACH, ENGAGEMENT and TRUST stay
 * separate in data, API and UI, and there is never a composite score. This
 * service returns three independent sections, each with its own
 * `available` flag, and computes no overall number of any kind.
 *
 * ⚠ The ONE place they touch: `engagement_rate` divides engagement by
 * reach's daily-unique-viewer total. That is the approved design — the rate
 * lives inside Engagement and is reported there — but it means a Reach
 * outage makes the RATE unavailable while the engagement COUNTS stay
 * available. Nothing else crosses: Reach never feeds Trust, and Trust feeds
 * nothing.
 *
 * ── TRUST IS NOT PER-POST, BY DECISION ───────────────────────────────
 * `trust` always returns `available: false`. Attestations target
 * `user_profile` / `validator_card` / `project_card` / `creator_card` —
 * there is no activity-post target kind — and per-post vouching previously
 * existed and was deliberately migrated away with its table dropped. So
 * this is not a gap to fill later: showing profile-level trust inside a
 * post panel would imply the post caused it. The UI says trust is earned on
 * your profile, not per post.
 *
 * ── ⚠⚠ ID DISCIPLINE ────────────────────────────────────────────────
 * This method takes an **act_id**. Four of the five reads want that id; the
 * comment count is the exception and wants the post's **wp_posts.ID**
 * (`act_external_id`), because `peepso_activities.act_comment_object_id`
 * stores the parent's wp_post id. Getting it wrong returns a confident
 * zero rather than an error, so the resolution happens once, here, from the
 * activity row — never by passing whichever id is in scope.
 *
 * @phpstan-type ReachTotals array{
 *     views: int,
 *     daily_unique_viewers: int,
 *     profile_visits: int,
 *     by_source: array<string, array{views: int, daily_unique_viewers: int, profile_visits: int}>
 * }
 *
 * @package BCC_Trust
 * @subpackage Services
 * @since F-324 post analytics V1 (2026-11-12)
 */
final class PostAnalyticsService
{
    /**
     * Analytics for a post the viewer authored.
     *
     * @return array{error: string, message: string}|array{
     *     act_id: int,
     *     reach: array<string, mixed>,
     *     engagement: array<string, mixed>,
     *     trust: array{available: bool, reason: string}
     * }
     */
    public function forOwner(int $actId, int $viewerId): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }
        if ($actId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid post id.'];
        }

        $activity = PeepSoActivityRepository::getById($actId);
        if ($activity === null) {
            return ['error' => 'bcc_not_found', 'message' => 'Post not found.'];
        }

        // Owner-only. 403 rather than the 404 MeFindingAppealEndpoint uses:
        // a post's existence is already public, so distinguishing "not
        // yours" from "not here" leaks nothing here, and it matches the
        // sibling owner-only read on GET /posts/{id}.
        if ((int) $activity->act_user_id !== $viewerId) {
            return ['error' => 'bcc_forbidden', 'message' => 'You can only see analytics for your own posts.'];
        }

        // Resolved ONCE, from the activity row — see the ID DISCIPLINE note.
        $wpPostId = (int) ($activity->act_external_id ?? 0);

        // Read reach ONCE. engagement_rate needs the same figure, and an
        // earlier revision of this method queried it a second time —
        // two identical queries per request for one number.
        $reach = PostReachRepository::reachForPost($actId);

        return [
            'act_id'     => $actId,
            'reach'      => $this->reachSection($reach),
            'engagement' => $this->engagementSection($actId, $wpPostId, $reach),
            'trust'      => [
                'available' => false,
                'reason'    => 'trust_is_profile_scoped',
            ],
        ];
    }

    /**
     * REACH. `available: false` when the read failed — never zeros, because
     * "nobody read it" and "the query broke" must not look the same.
     *
     * @phpstan-param ReachTotals|null $reach
     * @return array<string, mixed>
     */
    private function reachSection(?array $reach): array
    {
        if ($reach === null) {
            return ['available' => false];
        }

        return [
            'available'            => true,
            'views'                => $reach['views'],
            'daily_unique_viewers' => $reach['daily_unique_viewers'],
            'profile_visits'       => $reach['profile_visits'],
            'by_source'            => $reach['by_source'],
        ];
    }

    /**
     * ENGAGEMENT: reactions + comments + reposts + saves, and the rate.
     *
     * Reactions and comments come from the existing readers rather than new
     * queries (§11); only reposts and saves needed a new one.
     *
     * ⚠ Each count can be null (unknown) independently — a missing PeepSo
     * table, a failed read. A null count is reported as null and EXCLUDED
     * from the rate's numerator rather than silently treated as zero, and
     * the section says which counts it could not read.
     *
     * @phpstan-param ReachTotals|null $reach
     * @return array<string, mixed>
     */
    private function engagementSection(int $actId, int $wpPostId, ?array $reach): array
    {
        $reactions = $this->reactionCount($actId);
        $comments  = $this->commentCount($wpPostId);
        $reposts   = PostEngagementRepository::repostCount($actId);
        $saves     = PostEngagementRepository::saveCount($actId);

        $counts = [
            'reactions' => $reactions,
            'comments'  => $comments,
            'reposts'   => $reposts,
            'saves'     => $saves,
        ];

        $unreadable = array_keys(array_filter($counts, static fn ($v): bool => $v === null));

        // Available unless EVERY count is unreadable. (An earlier revision
        // compared a list of keys against the count map — two shapes that
        // can never be equal, so it always said "available".)
        return [
            'available'       => count($unreadable) < count($counts),
            'unreadable'      => $unreadable,
            'reactions'       => $reactions,
            'comments'        => $comments,
            'reposts'         => $reposts,
            'saves'           => $saves,
            'engagement_rate' => $this->engagementRate($counts, $reach),
        ];
    }

    /**
     * Total reactions of every type.
     *
     * ⚠ Returns int, NOT ?int, unlike every sibling count here. That is not
     * an oversight: `countsByActId()` returns `[]` both for "no reactions"
     * and for an unreadable table, so this genuinely cannot report "unknown"
     * — see the note in the body. PHPStan flagged the nullable return as
     * unreachable, which is correct.
     *
     * @see PeepSoReactionRepository::countsByActId
     */
    private function reactionCount(int $actId): int
    {
        $byType = (new PeepSoReactionRepository())->countsByActId($actId);

        // That reader returns [] both for "no reactions" and for an
        // unreadable table, so it cannot distinguish them. Treating [] as
        // zero is the honest reading of its contract — it is documented as
        // returning counts, and its own defensive posture is zeros — so this
        // is 0, not null. Called out because every other count here can be
        // null.
        return array_sum(array_map('intval', $byType));
    }

    /** Visible comments on the post. ⚠ Keyed by wp_post id, not act_id. */
    private function commentCount(int $wpPostId): ?int
    {
        if ($wpPostId <= 0) {
            return null;
        }

        $map = (new CommentRepository())->countsByParentPostIds([$wpPostId]);

        // Absent key means zero by that reader's documented contract.
        return (int) ($map[$wpPostId] ?? 0);
    }

    /**
     * `(reactions + comments + reposts + saves) / daily unique viewers`.
     *
     * ⚠⚠ A CONSERVATIVE LOWER BOUND, and the API docs and panel copy must
     * say so. The denominator is the SUM of daily unique viewers, which
     * over-counts a reader who returns on two days, so the true rate is
     * greater than or equal to the number reported. There is no lifetime
     * unique count to divide by, on purpose: the dedupe hash is salted per
     * day precisely so no durable per-reader identity exists.
     *
     * ⚠ Returns null — never 0 — when there are no measured viewers. A post
     * with no viewers has an UNDEFINED rate, not a 0% one, and rendering
     * "0%" would read as "nobody engaged" when the truth is "nothing to
     * divide by".
     *
     * @param array<string, int|null> $counts
     * @phpstan-param ReachTotals|null $reach Read once by the caller; null
     *        means Reach was unavailable, so there is no denominator and the
     *        rate is unknown rather than zero.
     */
    private function engagementRate(array $counts, ?array $reach): ?float
    {
        if ($reach === null) {
            return null; // Reach unavailable → no denominator.
        }

        $viewers = (int) $reach['daily_unique_viewers'];
        if ($viewers <= 0) {
            return null;
        }

        // Unknown counts are excluded rather than counted as zero.
        $numerator = 0;
        foreach ($counts as $value) {
            if ($value !== null) {
                $numerator += (int) $value;
            }
        }

        return round($numerator / $viewers, 4);
    }
}
