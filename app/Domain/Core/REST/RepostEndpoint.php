<?php
/**
 * Repost Endpoint — handles /bcc/v1/feed/{id}/repost (F-346).
 *
 * Routes registered:
 *   - POST   /feed/{id}/repost — repost (once per member per post → 409)
 *   - DELETE /feed/{id}/repost — un-repost (removes the member's own)
 *
 * Both routes are auth-required. Auth is checked inside each handler so
 * unauthenticated requests return the canonical error envelope.
 *
 * A repost is a REAL POST on the member's own wall, not a counter row:
 * PeepSo stores the relationship in `act_repost_id` on the new activity,
 * and `PeepSoStatusWriter::createSelfStatus()` is the only seam that
 * reaches that column. So a repost carries the REPOSTER's own audience
 * (their stored default, via F-291), while the ROOT keeps its own — the
 * two are separate posts with separate audiences, and that is what makes
 * the read-side rule necessary rather than optional.
 *
 * ── ⭐ EVERYTHING HERE IS KEYED ON THE ROOT ──────────────────────────
 * PeepSo's `add_post` calls `get_repost_root()` before storing, so every
 * repost row holds the CHAIN ROOT, never its immediate parent. Three
 * things therefore have to agree on the root and not on the id a client
 * submitted:
 *
 *   - the once-only rule — keyed on a submitted id, it is bypassed by
 *     reposting somebody else's repost of the same post;
 *   - the advisory lock — two requests naming different ids for the same
 *     underlying content must contend for the SAME lock;
 *   - the count — `repostCount()` reads `act_repost_id`, which is the root.
 *
 * {@see PeepSoActivityRepository::resolveRepostRoot()} is the one hop that
 * resolves it, and it fails CLOSED (0) for a vanished post.
 *
 * @package BCC\Trust\Core\REST
 */

namespace BCC\Trust\Core\REST;

use BCC\Core\DB\AdvisoryLock;
use BCC\Core\Log\Logger;
use BCC\Core\PeepSo\PeepSoStatusWriter;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Repositories\PostEngagementRepository;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\FeedItemGate;
use BCC\Trust\Core\Support\GroupInteractionGate;
use BCC\Trust\Core\Support\NoReactionsGate;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class RepostEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /**
     * Per-user-per-minute throttle. Tighter than Stoke's 60 because a
     * repost CREATES A POST — the abuse shape is wall flooding, not
     * tap-spam on one card.
     */
    private const REPOST_RATE_LIMIT  = 15;
    private const REPOST_RATE_WINDOW = 60;

    /**
     * Seconds to wait for the per-(member, root) lock. Matches
     * `AttestationService::LOCK_WAIT_SECS`, the once-per-(actor, target)
     * precedent in this codebase.
     */
    private const LOCK_WAIT_SECONDS = 5;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/feed/(?P<id>\d+)/repost',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'addRepost'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/feed/(?P<id>\d+)/repost',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'removeRepost'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Repost — once per member per root post.
     *
     * Gate order mirrors {@see StokeEndpoint::addStoke()} exactly for the
     * first five steps (throttle before credentials, §M suspension parity),
     * then adds what is specific to this write.
     */
    public function addRepost(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('repost', self::REPOST_RATE_LIMIT, self::REPOST_RATE_WINDOW)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many reposts. Please wait.', 429);
        }

        // §M suspension-gate parity — participation writes opt out of the
        // suspension admin-bypass: a suspended member, admin included,
        // cannot repost. Throttle stays FIRST (throttle-before-credentials).
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)) {
            return ApiResponse::error('bcc_forbidden', 'Your account is suspended.', 403);
        }

        $actId = (int) $request->get_param('id');
        if ($actId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid post id.', 400);
        }

        // The SUBMITTED id first: it must be something this route may touch
        // and this viewer may interact with, exactly as Stoke requires.
        $submittedRefusal = self::gateInteraction($userId, $actId);
        if ($submittedRefusal !== null) {
            return $submittedRefusal;
        }

        $rootActId = PeepSoActivityRepository::resolveRepostRoot($actId);
        if ($rootActId <= 0) {
            // Fails closed: the activity or its backing post is gone.
            return ApiResponse::error('bcc_not_found', 'Post not found.', 404);
        }

        // ⚠⚠ THE SECOND GATE, ON THE ROOT. Without it, reposting someone
        // else's repost republishes content from a group this member cannot
        // read: the SUBMITTED post is the repost, which sits on its author's
        // own wall and passes every check above, while the content it
        // carries belongs to the root's audience. The submitted gate alone
        // is therefore not a gate on the thing being republished.
        //
        // Skipped when the submitted id IS the root — the gate above already
        // answered for it, and asking twice costs a second set of queries
        // for the same verdict.
        if ($rootActId !== $actId) {
            $rootRefusal = self::gateInteraction($userId, $rootActId);
            if ($rootRefusal !== null) {
                return $rootRefusal;
            }
        }

        // ⚠ ONE LOCK PER (MEMBER, ROOT), held across the check-then-write.
        // The check and the create are two statements and nothing in the
        // DATABASE can enforce once-per-member (see
        // PostEngagementRepository::repostCount() for why no unique key is
        // possible), so a double-POST race is the only thing standing
        // between the rule and a duplicate. Mirrors
        // AttestationService::cast(), the once-per-(actor, target) precedent.
        $lockKey = 'bcc_repost_' . $userId . '_' . $rootActId;
        if (!self::acquireLock($lockKey)) {
            // Contention, OR the lock primitive is unavailable — both read
            // as "try again", never as permission to skip the check.
            return ApiResponse::error('bcc_rate_limited', 'Please try again in a moment.', 429);
        }

        try {
            $existing = PostEngagementRepository::viewerReposts($rootActId, $userId);
            if ($existing === null) {
                // ⚠ FAIL CLOSED. An unreadable table must not be taken as
                // "has not reposted yet" — that is precisely the read whose
                // failure would create the duplicate. Retryable 503, the
                // same posture the F-99 write paths take.
                return ApiResponse::error(
                    'bcc_unavailable',
                    'Could not check your reposts right now. Please try again.',
                    503
                );
            }

            if ($existing !== []) {
                return ApiResponse::error('bcc_conflict', 'You have already reposted this.', 409);
            }

            // Empty content is deliberate and is what makes this a repost
            // rather than a quote: createSelfStatus() accepts '' ONLY when a
            // root is passed, and PeepSo itself allows an empty body only
            // when $repost resolved (activity.php:163).
            $written = PeepSoStatusWriter::createSelfStatus(
                $userId,
                '',
                0,
                'members_only',
                $rootActId
            );

            if (($written['ok'] ?? false) !== true) {
                Logger::error('[RepostEndpoint] repost write failed', [
                    'root_act_id' => $rootActId,
                    'reason'      => (string) ($written['reason'] ?? 'unknown'),
                ]);
                return ApiResponse::error('bcc_internal_error', 'Could not repost.', 500);
            }

            return self::buildStateResponse($rootActId, $userId);
        } finally {
            self::releaseLock($lockKey);
        }
    }

    /**
     * Un-repost — removes THIS MEMBER'S reposts of the root.
     *
     * ⚠⚠ DELIBERATELY NOT AUDIENCE-GATED, and this is the one place this
     * route diverges from Stoke. A repost's root can stop being readable
     * after the fact (its author narrows the audience, the group turns
     * secret), and an audience gate here would then STRAND the member with
     * a post on their own wall they are not allowed to remove. The action
     * only ever touches rows this member owns, so there is nothing for an
     * audience check to protect.
     *
     * ⚠ WHAT REPLACES IT: a member with no repost of this root gets a 404,
     * not Stoke's idempotent 200. Without the audience gate, an idempotent
     * success would answer "does act N exist" and hand back its repost
     * count for ANY id — an existence-and-engagement oracle on content the
     * caller may not be able to see. Answering only when the caller owned a
     * repost closes it: they demonstrably already knew.
     */
    public function removeRepost(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('repost', self::REPOST_RATE_LIMIT, self::REPOST_RATE_WINDOW)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many reposts. Please wait.', 429);
        }

        // §M suspension-gate parity — the toggle is frozen BOTH ways for a
        // suspended account, exactly as Stoke freezes un-stoke.
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)) {
            return ApiResponse::error('bcc_forbidden', 'Your account is suspended.', 403);
        }

        $actId = (int) $request->get_param('id');
        if ($actId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid post id.', 400);
        }

        $rootActId = PeepSoActivityRepository::resolveRepostRoot($actId);
        if ($rootActId <= 0) {
            return ApiResponse::error('bcc_not_found', 'Post not found.', 404);
        }

        // Same lock as the create, so an un-repost cannot interleave with a
        // repost of the same root by the same member and leave the pair in
        // a state neither of them intended.
        $lockKey = 'bcc_repost_' . $userId . '_' . $rootActId;
        if (!self::acquireLock($lockKey)) {
            return ApiResponse::error('bcc_rate_limited', 'Please try again in a moment.', 429);
        }

        try {
            $mine = PostEngagementRepository::viewerReposts($rootActId, $userId);
            if ($mine === null) {
                return ApiResponse::error(
                    'bcc_unavailable',
                    'Could not check your reposts right now. Please try again.',
                    503
                );
            }

            if ($mine === []) {
                // See the docblock: nothing of this caller's here, so this
                // route says nothing about whether the root exists.
                return ApiResponse::error('bcc_not_found', 'You have not reposted this.', 404);
            }

            foreach ($mine as $row) {
                // ⚠ TRASH, NOT DELETE, and not PeepSoActivity::delete_post.
                // `wp_trash_post` is what PostsService::deletePost uses, and
                // it is the right granularity here: it flips post_status, so
                // the row stops counting (every read filters
                // `post_status = 'publish'`) while PeepSo's own delete would
                // cascade `act_repost_id` and take OTHER members' reposts of
                // the same root with it.
                $trashed = wp_trash_post($row['post_id']);
                if ($trashed === false || $trashed === null) {
                    Logger::error('[RepostEndpoint] un-repost trash failed', [
                        'act_id'  => $row['act_id'],
                        'post_id' => $row['post_id'],
                    ]);
                    return ApiResponse::error('bcc_internal_error', 'Could not un-repost.', 500);
                }

                // Same event the owner-delete path fires, with the same
                // argument order — it is what evicts the post from the
                // warmed hot-feed page (F-277) and what the Rank evidence
                // ledger keys its reversal on.
                do_action('bcc_post_deleted', $userId, $row['act_id'], 'owner', $row['post_id']);
            }

            return self::buildStateResponse($rootActId, $userId);
        } finally {
            self::releaseLock($lockKey);
        }
    }

    /**
     * The interaction gate applied to an act id: route scope, then
     * audience + group membership, then the no-engagement rule.
     *
     * Extracted so the SUBMITTED id and the ROOT are checked by the same
     * three gates in the same order (§11) — the alternative is two copies
     * that drift, and the root's copy is the one that would quietly lose a
     * check.
     */
    private static function gateInteraction(int $userId, int $actId): ?WP_REST_Response
    {
        // F-345: only a FEED ITEM takes engagement here. A review reply
        // (module 206) and any other off-feed activity is not a post on
        // this route; same 404 as an unknown id.
        $notFeedItem = FeedItemGate::check($actId);
        if ($notFeedItem !== null) {
            return $notFeedItem;
        }

        $gateError = GroupInteractionGate::check($userId, $actId);
        if ($gateError !== null) {
            return $gateError;
        }

        // An announcement takes no engagement at all (contract §4.32.15),
        // and a repost is engagement that also REPUBLISHES it.
        //
        // ⚠ Written as call / guard / return rather than returning the gate's
        // answer directly. The one-liner is tidier and behaves identically,
        // but NoReactionsGateTest's per-file inventory counts that three-part
        // token shape and requires the three counts to match, so the tidy
        // form would leave this route OUT of the only guard that catches a
        // future refactor dropping the call. (The tokens are deliberately
        // not quoted anywhere in this file's comments — that inventory
        // counts raw substrings, so a comment naming them inflates the count
        // and breaks the very guard being satisfied.)
        $noReactions = NoReactionsGate::check($actId);
        if ($noReactions !== null) {
            return $noReactions;
        }

        return null;
    }

    /**
     * Fails CLOSED when bcc-core's AdvisoryLock primitive is unavailable —
     * a missing class and a lock held elsewhere both map to false, exactly
     * as MyGroupsEndpoint::acquire() does. The caller turns that into a
     * retry, never into an unlocked write.
     */
    private static function acquireLock(string $key): bool
    {
        if (!class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            return false;
        }

        return AdvisoryLock::acquire($key, self::LOCK_WAIT_SECONDS);
    }

    private static function releaseLock(string $key): void
    {
        if (class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            AdvisoryLock::release($key);
        }
    }

    /**
     * The repost state of the ROOT for this viewer.
     *
     * ⚠ `repost_count` is `int|null` and null means "could not read", not
     * zero — the F-197/F-216/F-230 posture the repository enforces. A
     * successful write can still answer null here (the write and the count
     * are two reads); that is honest rather than a contradiction, and the
     * client shows no number rather than a wrong one.
     */
    private static function buildStateResponse(int $rootActId, int $viewerId): WP_REST_Response
    {
        $mine = PostEngagementRepository::viewerReposts($rootActId, $viewerId);

        $response = ApiResponse::ok([
            'root_feed_id'        => 'feed_' . $rootActId,
            'repost_count'        => PostEngagementRepository::repostCount($rootActId),
            // null (unreadable) renders as false: the viewer's own affordance
            // must not latch on an error, and the next read corrects it.
            'viewer_has_reposted' => is_array($mine) && $mine !== [],
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
