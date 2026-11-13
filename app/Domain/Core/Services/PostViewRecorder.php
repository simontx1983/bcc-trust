<?php

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Repositories\PostReachRepository;
use BCC\Trust\Core\Security\IpResolver;
use BCC\Trust\Core\Support\ViewerFingerprint;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * F-324 — the counting rules for a post view.
 *
 * Separate from `PostAnalyticsService` on purpose: that one READS for an
 * owner, this one WRITES for a viewer. They share no state and have
 * opposite failure policies.
 *
 * ⚠⚠ EVERYTHING HERE FAILS OPEN. A view that cannot be counted is a lost
 * view, and that is the correct trade: analytics must never break the thing
 * being measured. This follows bcc-search's `SearchTermsRepository`, whose
 * writer opens *"Analytics can NEVER break a search — the whole body is
 * swallow-and-log."* No method here throws, and the beacon endpoint always
 * answers 204 regardless of what happened inside.
 *
 * ── WHAT IS NOT COUNTED ──────────────────────────────────────────────
 *   - the author's own views of their own post;
 *   - views of a post that does not resolve;
 *   - an unrecognised `source` (the whitelist lives on the repository);
 *   - a second view by the same reader of the same post on the same UTC day
 *     is counted as a VIEW but not as a unique viewer.
 *
 * ── ⚠ ON BOT EXCLUSION, AND WHY THERE IS NO UA BLOCKLIST HERE ────────
 * The beacon itself is the bot exclusion: it is fired by the client when a
 * post is actually rendered, so crawlers that do not execute it never
 * register a view. That covers the ordinary case.
 *
 * I deliberately did NOT add a user-agent gate on top, and specifically did
 * not reuse `DeviceFingerprinter::BOT_USER_AGENTS`. That list is tuned for
 * SCORED automation detection — each hit adds 15 confidence points and is
 * weighed against header and client signals — not for a boolean verdict,
 * and it contains broad substrings (`java`, `php`, `node`, `ruby`). Used as
 * a hard gate, a single substring collision silently drops a real member's
 * view, and silent under-counting is indistinguishable from "nobody read
 * it". If a UA gate is wanted later it should be its own narrow,
 * high-confidence list with a test per entry — not this one repurposed.
 *
 * @package BCC_Trust
 * @subpackage Services
 * @since F-324 post analytics V1 (2026-11-12)
 */
final class PostViewRecorder
{
    /**
     * Record one view of a post.
     *
     * @param int    $actId    The post's act_id (NOT a wp_posts.ID).
     * @param string $source   One of PostReachRepository::SOURCES.
     * @param int    $viewerId The signed-in viewer, or 0 when signed out.
     * @return string A short outcome for logging and tests:
     *                'counted_unique' | 'counted_repeat' | 'ignored_author'
     *                | 'ignored_unknown_post' | 'ignored_bad_source'
     *                | 'ignored_write_failed'
     *                ⚠ The endpoint does NOT return this to the client — a
     *                caller must not be able to probe whether a post exists
     *                or who wrote it by reading beacon outcomes.
     */
    public static function record(int $actId, string $source, int $viewerId): string
    {
        try {
            if (!in_array($source, PostReachRepository::SOURCES, true)) {
                return 'ignored_bad_source';
            }

            $activity = PeepSoActivityRepository::getById($actId);
            if ($activity === null) {
                return 'ignored_unknown_post';
            }

            // An author reading their own post is not reach. Excluded before
            // any write, and before the fingerprint is even built.
            $authorId = (int) ($activity->act_user_id ?? 0);
            if ($viewerId > 0 && $authorId === $viewerId) {
                return 'ignored_author';
            }

            $day = gmdate('Y-m-d');

            // ⚠ A null fingerprint (no HMAC key, or a signed-out viewer with
            // no IP and no user-agent at all) must count the view as
            // NON-unique rather than as a fresh viewer. Same for a null
            // claim, which means "could not tell". Over-counting unique
            // viewers on a DB hiccup would be worse than under-counting.
            $hash = ViewerFingerprint::forDay(
                $day,
                $viewerId,
                $viewerId > 0 ? '' : IpResolver::getClientIp(),
                $viewerId > 0 ? '' : self::userAgent(),
                ViewerFingerprint::PURPOSE_VIEW
            );

            $isUnique = false;
            if ($hash !== null) {
                $isUnique = PostReachRepository::claimDailyViewer($actId, $day, $hash) === true;
            }

            $written = PostReachRepository::incrementView($actId, $day, $source, $isUnique);
            if (!$written) {
                return 'ignored_write_failed';
            }

            return $isUnique ? 'counted_unique' : 'counted_repeat';
        } catch (\Throwable $e) {
            // Fail open, loudly in the log and silently to the reader.
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] post-view beacon failed', [
                    'act_id' => $actId,
                    'source' => $source,
                    'error'  => $e->getMessage(),
                ]);
            }

            return 'ignored_write_failed';
        }
    }

    /**
     * Attribute a profile visit to the post the reader came from.
     *
     * Capped at one per (post, reader, day) by the same dedupe claim the
     * view count uses, so one reader clicking through repeatedly cannot
     * inflate it. ⚠ A different claim key from the view's, or a repeat
     * click would be refused by the view's own claim and the visit lost.
     *
     * @return string 'counted' | 'ignored_author' | 'ignored_unknown_post'
     *                | 'ignored_bad_source' | 'ignored_repeat'
     *                | 'ignored_write_failed'
     */
    public static function recordProfileVisit(int $actId, string $source, int $viewerId): string
    {
        try {
            if (!in_array($source, PostReachRepository::SOURCES, true)) {
                return 'ignored_bad_source';
            }

            $activity = PeepSoActivityRepository::getById($actId);
            if ($activity === null) {
                return 'ignored_unknown_post';
            }

            $authorId = (int) ($activity->act_user_id ?? 0);
            if ($viewerId > 0 && $authorId === $viewerId) {
                return 'ignored_author';
            }

            $day = gmdate('Y-m-d');

            // The visit claim uses PURPOSE_PROFILE_VISIT, so it occupies a
            // code space disjoint from the view claim for the same reader and
            // day — neither can consume the other.
            //
            // ⚠ CONSEQUENCE, stated because it changes the dedupe table's
            // sizing: a reader who views a post AND clicks through to the
            // author leaves TWO rows for that (post, day), not one. Both
            // still expire within 48 hours and both are salted one-way
            // codes, so the privacy story is unchanged — but the row-cap
            // headroom is per claim, not per reader.
            $hash = ViewerFingerprint::forDay(
                $day,
                $viewerId,
                $viewerId > 0 ? '' : IpResolver::getClientIp(),
                $viewerId > 0 ? '' : self::userAgent(),
                ViewerFingerprint::PURPOSE_PROFILE_VISIT
            );

            // ⚠ No code (no key, or a signed-out reader with no signal at
            // all) means the cap cannot be enforced for this request. Refuse
            // rather than attribute: an uncapped visit is inflatable by one
            // reader clicking repeatedly, which is exactly what the cap is
            // for. Unlike a view, a missed profile visit costs nothing.
            if ($hash === null) {
                return 'ignored_repeat';
            }
            if (PostReachRepository::claimDailyViewer($actId, $day, $hash) !== true) {
                return 'ignored_repeat';
            }

            return PostReachRepository::incrementProfileVisit($actId, $day, $source)
                ? 'counted'
                : 'ignored_write_failed';
        } catch (\Throwable $e) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] profile-visit attribution failed', [
                    'act_id' => $actId,
                    'error'  => $e->getMessage(),
                ]);
            }

            return 'ignored_write_failed';
        }
    }

    private static function userAgent(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Bounded: a hostile client can send a very long UA, and this value
        // goes into an HMAC message, not storage, but there is no reason to
        // hash megabytes.
        return is_string($ua) ? substr($ua, 0, 255) : '';
    }
}
