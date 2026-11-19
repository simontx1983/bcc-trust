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

use BCC\Core\Contracts\ActivityReadFailedException;
use BCC\Core\DB\AdvisoryLock;
use BCC\Core\Log\Logger;
use BCC\Core\PeepSo\PeepSoStatusWriter;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Repositories\PostEngagementRepository;
use BCC\Trust\Core\Services\FeedItemReportVisibility;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\FeedItemGate;
use BCC\Trust\Core\Support\FeedUnavailable;
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

        // F-346 — a QUOTE is its own route, not a body on /repost.
        //
        // ⭐ WHY SEPARATE, since one route with an optional body looks
        // tidier: under the ratified X model the two actions have DIFFERENT
        // limit rules — a plain repost is once per member per root and 409s
        // on a second, a quote is unlimited. One route whose once-rule
        // flipped on the presence of a body would mean a client sending
        // `body: ""` instead of omitting it silently crossed between
        // "capped" and "unlimited". Two routes, two rules, nothing inferred.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/feed/(?P<id>\d+)/quote',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'addQuote'],
                'permission_callback' => '__return_true',
                // ⚠⚠ NO `required` AND NO `type`, DELIBERATELY.
                //
                // `WP_REST_Request::has_valid_params()` runs inside
                // dispatch AFTER the permission callback and BEFORE this
                // handler, so `required` made WordPress answer its own
                // `rest_missing_callback_param` 400 — outside the §9
                // envelope, and ahead of the handler's 401, so an
                // ANONYMOUS caller who omitted the body was told which
                // parameter was missing instead of to sign in. The handler
                // owns the refusal and answers one `bcc_invalid_request`
                // 400 for missing, blank and non-string alike.
                //
                // `type` was never load-bearing: `register_rest_route()`
                // attaches no default `validate_callback`, and
                // `has_valid_params()` only calls one that is set, so a
                // declared type with no callback of its own validates
                // nothing. Removed rather than left to imply a check that
                // does not exist.
                'args'                => [
                    'body' => [
                        'description' => 'The quote text; the handler validates it.',
                    ],
                ],
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
        // ⭐ The ROOT ladder — the double gate, the comment refusal and the
        // read check — lives in one helper shared with the QUOTE route.
        // Everything after this point differs between the two (this route
        // locks and enforces once-only; a quote does neither), but what
        // decides WHAT is being republished must not, and two copies of it
        // is exactly how the root's copy would quietly lose a check.
        $root = self::resolveQuotableRoot($userId, $actId);
        if ($root instanceof WP_REST_Response) {
            return $root;
        }
        $rootActId = $root;

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
                // ⚠⚠ BYTE-IDENTICAL to the "root did not resolve" 404 above,
                // and that is the whole point of the message being this
                // unhelpful.
                //
                // This route is deliberately NOT audience-gated, and
                // `resolveRepostRoot()` takes ANY act id — it carries no
                // module allowlist. So a distinguishable body here ("you
                // have not reposted this" vs "post not found") answered
                // *does act N exist* for any id a caller cared to try,
                // including ids this route would never act on. One body, and
                // the route tells them only that nothing happened.
                //
                // ⚠ The cost is a member who taps undo with nothing to undo
                // reading "Post not found.", which is confusing. The client
                // should not offer undo unless `viewer_has_reposted`, and a
                // confusing message is the cheaper of the two problems.
                return ApiResponse::error('bcc_not_found', 'Post not found.', 404);
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
     * The 404 for a root that is a COMMENT activity rather than a top-level
     * post, or null when it is a post.
     *
     * Reuses `CommentRepository::getCommentMeta()` — the same seam
     * `GroupInteractionGate::resolveLiveTarget()` already uses to tell a
     * comment act from a post act (§11), rather than a second hand-rolled
     * `act_comment_object_id` read that could drift from it.
     *
     * ⚠ `getCommentMeta()` answers only for a PUBLISHED comment, so an
     * unpublished one returns null here and is NOT caught by this check.
     * That is already covered and needs no second guard: the gate above
     * resolves the same act through `PeepSoActivityRepository::getById()`,
     * whose `act_status` is `wp_posts.post_status` under an alias, and
     * refuses anything that is not `publish`.
     */
    private static function rootIsComment(int $rootActId): ?WP_REST_Response
    {
        $comment = Plugin::instance()->commentRepository()->getCommentMeta($rootActId);
        if ($comment === null) {
            return null;
        }

        return ApiResponse::error('bcc_not_found', 'Post not found.', 404);
    }

    /**
     * May this member READ the root — the permalink's whole question.
     *
     * `FeedRankingService::getActivityById()` is the single-item gate
     * `GET /feed/{id}` itself uses, so a post this member cannot open by
     * link is a post they cannot repost — by construction, with no second
     * ladder to fall out of step with it.
     *
     * ⚠ Fails CLOSED: it answers null both for "not visible" and for a
     * read it could not complete, and the caller turns both into one 404.
     *
     * ⚠ KNOWN, ACCEPTED NARROWING FOR AN ADMINISTRATOR. A §F-02-exempt
     * reader skips the profile gate, and the permalink's group-tagged
     * recovery pass only runs when that gate actually ran — so bcc-core's
     * `isVisibleGlobally()` refuses a `members_only` group post for an
     * admin and nothing recovers it. An admin therefore cannot repost a
     * members_only group post. That is a pre-existing property of the
     * permalink rather than something this route introduces, and refusing
     * is the safe direction, so it is recorded here instead of worked
     * around.
     */
    private static function viewerCanReadRoot(int $userId, int $rootActId): ?WP_REST_Response
    {
        // ⚠⚠ F-275 — a read that DID NOT RUN is not "not visible".
        // `getActivityById()` throws rather than answering null for a failed
        // read (bcc-core, via #732), and turning that into this route's 404
        // would tell the member their post is gone because the database
        // hiccuped. It is a retryable 503, matching the once-only check's
        // own `bcc_unavailable` on the same route.
        //
        // ⚠ The try and catch bodies are single statements on purpose:
        // FeedReadFailureWiringTest's matcher allows no braces inside
        // either, so a nested block here would fail that guard loudly
        // instead of passing on a guess.
        try {
            $item = Plugin::instance()->feedRankingService()->getActivityById($rootActId, $userId);
        } catch (ActivityReadFailedException) {
            return FeedUnavailable::response(FeedUnavailable::POST_MESSAGE);
        }

        return $item === null
            ? ApiResponse::error('bcc_not_found', 'Post not found.', 404)
            : null;
    }

    /**
     * POST /feed/{id}/quote — repost WITH something to say.
     *
     * ⭐ UNLIMITED, which is the whole difference from {@see addRepost()}.
     * Under the X model (user decision, 2026-11-18) a member gets ONE plain
     * repost per root but as many quotes as they like, so this route takes
     * NO advisory lock, runs NO once-only check and can never answer 409.
     * The lock in addRepost() exists solely to make "once" true; there is no
     * "once" here to protect.
     *
     * ⚠⚠ THE WRITE IS DELEGATED TO PostsService::createStatus(), on purpose.
     * A quote IS a status post that also carries `act_repost_id`, so every
     * status rule has to apply to it: the §D2 500-character cap, mention
     * validation, the sanitising, the moderation hooks, the status burst
     * seatbelt and the §A3 `bcc_post_created` event. Re-implementing any of
     * that here is exactly the duplication §11 forbids, and the copy that
     * drifts is always the one in the REST layer.
     *
     * What this handler owns is the part createStatus() knows nothing about:
     * that `:id` resolves to a repostable ROOT this member may interact with
     * and can actually read.
     */
    public function addQuote(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in to repost.', 401);
        }

        // ⚠ A THROTTLE OF ITS OWN, BEFORE THE GATES, and not redundant with
        // the seatbelt inside createStatus(). That one guards the WRITE, and
        // it is only reached after the gates below have answered — so
        // without this, act ids could be probed through this route at full
        // speed. Same numbers as a status post, because a quote is one.
        if (!\BCC\Core\Security\Throttle::allow(
            'quote_' . $userId,
            BCC_TRUST_RATE_LIMIT_STATUS_POST,
            BCC_TRUST_RATE_WINDOW_STATUS_POST
        )) {
            return ApiResponse::error('bcc_rate_limited', 'Slow down a moment.', 429);
        }

        $actId = (int) $request->get_param('id');
        if ($actId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'A post id is required.', 400);
        }

        $refusal = self::resolveQuotableRoot($userId, $actId);
        if ($refusal instanceof WP_REST_Response) {
            return $refusal;
        }
        $rootActId = $refusal;

        // The route's OWN requirement: a quote with nothing in it is a plain
        // repost, and that is the other route. createStatus() would also
        // refuse empty content, but with a status-shaped message.
        $bodyRaw = $request->get_param('body');
        $body    = is_string($bodyRaw) ? trim($bodyRaw) : '';
        if ($body === '') {
            return ApiResponse::error(
                'bcc_invalid_request',
                'A quote needs something to say — use repost for a plain one.',
                400
            );
        }

        // ⚠ NAMED argument for the root, so visibility, hall feed and page
        // target keep createStatus()'s OWN defaults. Passing them
        // positionally would mean restating them here — and
        // `VISIBILITY_DEFAULT` is private to PostsService anyway, so a
        // positional call cannot even name it. The member's stored default
        // audience (F-291) is applied further down, inside the writer.
        $result = Plugin::instance()->postsService()->createStatus(
            $userId,
            $body,
            repostOfRootActId: $rootActId
        );
        if (isset($result['error'])) {
            return ApiResponse::fromServiceError($result);
        }

        // The new post's own feed id rides along so a client can link
        // straight to the quote it just made.
        return self::buildStateResponse($rootActId, $userId, [
            'quote_feed_id' => (string) ($result['feed_id'] ?? ''),
        ]);
    }

    /**
     * The shared "is `:id` a root this member may quote or repost" ladder:
     * the resolved ROOT act id, or the WP_REST_Response to refuse with.
     *
     * Extracted so {@see addQuote()} and {@see addRepost()} cannot drift on
     * the part that decides WHAT is being republished — the double gate, the
     * comment refusal and the read check. Everything after it differs
     * between the two routes; everything in it must not.
     */
    private static function resolveQuotableRoot(int $userId, int $actId): WP_REST_Response|int
    {
        $submittedRefusal = self::gateInteraction($userId, $actId);
        if ($submittedRefusal !== null) {
            return $submittedRefusal;
        }

        $rootActId = PeepSoActivityRepository::resolveRepostRoot($actId);
        if ($rootActId <= 0) {
            return ApiResponse::error('bcc_not_found', 'Post not found.', 404);
        }

        $commentRoot = self::rootIsComment($rootActId);
        if ($commentRoot !== null) {
            return $commentRoot;
        }

        if ($rootActId !== $actId) {
            $rootRefusal = self::gateInteraction($userId, $rootActId);
            if ($rootRefusal !== null) {
                return $rootRefusal;
            }
        }

        $unreadable = self::viewerCanReadRoot($userId, $rootActId);
        if ($unreadable !== null) {
            return $unreadable;
        }

        // ⭐ ONLY PUBLIC POSTS MAY BE REPOSTED OR QUOTED (user decision
        // 2026-11-18 — X's protected-post rule). A root that is not visible
        // to EVERY member is refused for everyone, including its own author:
        // reposting republishes to an audience the original author never
        // chose, so the test is that nobody was excluded from the original.
        //
        // That is a question about the POST, not about this viewer, which is
        // why it takes no viewer id. `members_only` PASSES — it is the
        // default audience (§F-291) and every member can see it; what this
        // refuses is friends-only, only-me, and anything inside a group that
        // is not `public_all`.
        //
        // ⚠⚠ ORDERED AFTER THE READ CHECK ON PURPOSE, and the 403 is only
        // safe because of it. By this line the viewer has already been shown
        // they CAN read the root, so naming the real reason discloses
        // nothing they do not already see — and a 404 here would tell them
        // "not found" about a post in front of them. The opaque 404 stays
        // for the case that needs it: a root they cannot read.
        $rootRow = PeepSoActivityRepository::getById($rootActId);
        if ($rootRow === null) {
            // Vanished between reads. Not a privacy answer — a missing one.
            return ApiResponse::error('bcc_not_found', 'Post not found.', 404);
        }
        if (!FeedItemReportVisibility::isPublicToEveryMember($rootRow)) {
            return ApiResponse::error(
                'bcc_permission_denied',
                'Only public posts can be reposted.',
                403
            );
        }

        return $rootActId;
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
     *
     * @param array<string, mixed> $extra route-specific fields merged on top
     *   of the shared state block, so both write routes answer the SAME
     *   shape plus whatever only one of them can say.
     */
    private static function buildStateResponse(int $rootActId, int $viewerId, array $extra = []): WP_REST_Response
    {
        $mine = PostEngagementRepository::viewerReposts($rootActId, $viewerId);

        $response = ApiResponse::ok($extra + [
            'root_feed_id'        => 'feed_' . $rootActId,
            // ⚠ PLAIN reposts only, distinct members. A quote is not counted
            // here and is not capped — see quoteCount().
            'repost_count'        => PostEngagementRepository::repostCount($rootActId),
            // A raw total, not distinct members: quotes are unlimited per
            // member, so two quotes by one member are two posts.
            'quote_count'         => PostEngagementRepository::quoteCount($rootActId),
            // null (unreadable) renders as false: the viewer's own affordance
            // must not latch on an error, and the next read corrects it.
            'viewer_has_reposted' => is_array($mine) && $mine !== [],
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
