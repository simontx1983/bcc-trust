<?php
/**
 * PushPayload — V2 Phase 1 push body builders.
 *
 * One static method per push event type. Each returns the JSON-ready
 * structure the service worker expects:
 *
 *   {
 *     title: string,
 *     body:  string,
 *     url:   string,
 *     tag?:  string  // for OS-shell deduplication when multiple pushes
 *                    // for the same subject are still on-screen
 *   }
 *
 * Aggregation rule (P1.E3): when count > 1, render with the count
 * prefix; otherwise render with actor + subject. Page name is
 * pulled from the FIRST queued payload — the typical case (multiple
 * reviews/endorsements on the same page) makes this correct; the
 * cross-page edge case is acceptable noise for V1.
 *
 * @package BCC\Trust\Core\Support
 * @since V2 Phase 1
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class PushPayload
{
    /**
     * @param array<string, mixed> $first First queued payload — provides
     *   actor handle + page metadata for the rendered body.
     * @return array{title: string, body: string, url: string, tag?: string}
     */
    public static function forReview(int $count, array $first): array
    {
        $actor    = self::stringFrom($first, 'actor_handle');
        $pageId   = self::intFrom($first, 'page_id');
        $pageName = self::stringFrom($first, 'page_name');

        $title = $pageName !== '' ? $pageName : 'Blue Collar Crypto';
        $body  = $count > 1
            ? ($pageName !== ''
                ? sprintf('%d new reviews on %s.', $count, $pageName)
                : sprintf('%d new reviews on your page.', $count))
            : ($actor !== ''
                ? sprintf('@%s wrote a review.', $actor)
                : 'New review on your page.');

        $payload = [
            'title' => $title,
            'body'  => $body,
            'url'   => $pageId > 0 ? self::pageUrl($pageId) : '/',
        ];
        if ($pageId > 0) {
            $payload['tag'] = 'bcc-review-' . $pageId;
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $first
     * @return array{title: string, body: string, url: string, tag?: string}
     */
    public static function forEndorse(int $count, array $first): array
    {
        $actor    = self::stringFrom($first, 'actor_handle');
        $pageId   = self::intFrom($first, 'page_id');
        $pageName = self::stringFrom($first, 'page_name');

        $title = $pageName !== '' ? $pageName : 'Blue Collar Crypto';
        $body  = $count > 1
            ? ($pageName !== ''
                ? sprintf('%d new endorsements on %s.', $count, $pageName)
                : sprintf('%d new endorsements on your page.', $count))
            : ($actor !== ''
                ? sprintf('@%s endorsed your page.', $actor)
                : 'New endorsement on your page.');

        $payload = [
            'title' => $title,
            'body'  => $body,
            'url'   => $pageId > 0 ? self::pageUrl($pageId) : '/',
        ];
        if ($pageId > 0) {
            $payload['tag'] = 'bcc-endorse-' . $pageId;
        }
        return $payload;
    }

    /**
     * Dispute outcomes don't aggregate naturally — each dispute is
     * a distinct decision. We still respect the count, but the
     * "2 dispute outcomes" wording feels right since the user is the
     * reporter on each.
     *
     * @param array<string, mixed> $first
     * @return array{title: string, body: string, url: string, tag?: string}
     */
    public static function forDisputeOutcome(int $count, array $first): array
    {
        $disputeId = self::intFrom($first, 'dispute_id');
        $outcome   = self::stringFrom($first, 'outcome'); // 'accepted', 'rejected', 'timeout_no_quorum'

        $title = 'Dispute resolved';
        if ($count > 1) {
            $body = sprintf('%d disputes you reported were resolved.', $count);
        } else {
            $body = match ($outcome) {
                'accepted'           => 'Your dispute was accepted — the vote was removed.',
                'rejected'           => 'Your dispute was reviewed; the vote stands.',
                'timeout_no_quorum'  => 'Your dispute couldn\'t be decided — not enough panel votes.',
                default              => 'A dispute you reported was resolved.',
            };
        }

        return [
            'title' => $title,
            'body'  => $body,
            'url'   => $disputeId > 0
                ? sprintf('/disputes/%d', $disputeId)
                : '/disputes',
        ];
    }

    /**
     * @param array<string, mixed> $first First queued payload — provides
     *   actor handle + activity row id for the single-event body + url.
     *   Aggregated bodies (count > 1) drop the actor + surface
     *   "N new mentions" because the queue can mix mention sources
     *   (a post mention AND a comment mention land in the same
     *   `(recipient, 'mention')` debounce window).
     * @return array{title: string, body: string, url: string, tag?: string}
     */
    public static function forMention(int $count, array $first): array
    {
        $actor    = self::stringFrom($first, 'actor_handle');
        $actId    = self::intFrom($first, 'act_id');
        $isComment = isset($first['is_comment']) && $first['is_comment'] === true;

        $title = 'Blue Collar Crypto';
        if ($count > 1) {
            $body = sprintf('%d new mentions.', $count);
        } elseif ($actor !== '') {
            $body = $isComment
                ? sprintf('@%s mentioned you in a comment.', $actor)
                : sprintf('@%s mentioned you in a post.', $actor);
        } else {
            $body = 'You were mentioned.';
        }

        // count==1 with a known activity row → deep-link to the floor
        // focused on it (mirrors NotificationViewService::resolveLink for
        // REACTION). count>1 (mixed sources) → floor.
        $url = ($count === 1 && $actId > 0)
            ? sprintf('/?focus=%d', $actId)
            : '/';

        return [
            'title' => $title,
            'body'  => $body,
            'url'   => $url,
            // Per-recipient tag (no id needed since the debounce window
            // is already per (recipient, eventType) — only one mention
            // push can be on-screen for a given recipient at a time).
            'tag'   => 'bcc-mention',
        ];
    }

    /**
     * @param array<string, mixed> $first First queued payload — provides
     *   actor handle, group_id, Local name, and slug for the body + url.
     *   Aggregated bodies (count > 1) drop the actor + surface
     *   "N new posts in {Local}." because the queue can mix authors
     *   (a single 5-min debounce window can include posts from
     *   multiple authors in the same Local).
     * @return array{title: string, body: string, url: string, tag?: string}
     */
    public static function forLocalPost(int $count, array $first): array
    {
        $actor     = self::stringFrom($first, 'actor_handle');
        $groupId   = self::intFrom($first, 'group_id');
        $localName = self::stringFrom($first, 'local_name');
        $localSlug = self::stringFrom($first, 'local_slug');

        $title = $localName !== '' ? $localName : 'Blue Collar Crypto';
        $body  = $count > 1
            ? ($localName !== ''
                ? sprintf('%d new posts in %s.', $count, $localName)
                : sprintf('%d new posts in your Local.', $count))
            : ($actor !== ''
                ? ($localName !== ''
                    ? sprintf('@%s posted in %s.', $actor, $localName)
                    : sprintf('@%s posted in your Local.', $actor))
                : 'New post in your Local.');

        $payload = [
            'title' => $title,
            'body'  => $body,
            'url'   => $localSlug !== ''
                ? '/locals/' . $localSlug
                : '/locals',
        ];
        if ($groupId > 0) {
            // Per-Local OS-level tag — multiple Locals push concurrently
            // without collapsing into each other on the OS shell.
            $payload['tag'] = 'bcc-local-post-' . $groupId;
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $first First queued payload — provides
     *   commenter handle + post act_id for the body + url. Aggregated
     *   bodies (count > 1) drop the actor + render
     *   "N new comments on your post." Across a 5-min debounce window
     *   the queue can mix commenters; the singular actor only reads
     *   correctly when count === 1.
     * @return array{title: string, body: string, url: string, tag?: string}
     */
    public static function forCommentReceived(int $count, array $first): array
    {
        $actor   = self::stringFrom($first, 'actor_handle');
        $actId   = self::intFrom($first, 'act_id');
        $postId  = self::intFrom($first, 'post_id');

        $title = 'Blue Collar Crypto';
        $body  = $count > 1
            ? sprintf('%d new comments on your post.', $count)
            : ($actor !== ''
                ? sprintf('@%s commented on your post.', $actor)
                : 'New comment on your post.');

        $payload = [
            'title' => $title,
            'body'  => $body,
            // Mirror REACTION + MENTION deep-link convention: jump to
            // the floor focused on the parent post. The FE has no
            // comment-anchor consumer in V1 — the user scrolls to find
            // the new comment in the thread.
            'url'   => $actId > 0 ? sprintf('/?focus=%d', $actId) : '/',
        ];
        if ($postId > 0) {
            // Per-post tag so two posts with concurrent comments don't
            // collapse into one OS-shell notification.
            $payload['tag'] = 'bcc-comment-' . $postId;
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $first
     * @return array{title: string, body: string, url: string, tag?: string}
     */
    public static function forPanelistSelected(int $count, array $first): array
    {
        $disputeId = self::intFrom($first, 'dispute_id');
        $pageName  = self::stringFrom($first, 'page_name');

        $title = 'Panel duty';
        $body  = $count > 1
            ? sprintf('You\'ve been selected to review %d disputes.', $count)
            : ($pageName !== ''
                ? sprintf('You\'ve been selected to review a dispute about %s.', $pageName)
                : 'You\'ve been selected to review a new dispute.');

        return [
            'title' => $title,
            'body'  => $body,
            'url'   => $count > 1
                ? '/disputes/panel'
                : ($disputeId > 0
                    ? sprintf('/disputes/%d', $disputeId)
                    : '/disputes/panel'),
        ];
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function stringFrom(array $arr, string $key): string
    {
        if (!isset($arr[$key])) {
            return '';
        }
        $value = $arr[$key];
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function intFrom(array $arr, string $key): int
    {
        if (!isset($arr[$key])) {
            return 0;
        }
        $value = $arr[$key];
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Page permalink resolver. Falls back to `/` when the page is
     * deleted between enqueue + flush — the user lands on home rather
     * than a 404.
     */
    private static function pageUrl(int $pageId): string
    {
        $url = get_permalink($pageId);
        if (!is_string($url) || $url === '') {
            return '/';
        }
        return $url;
    }
}
