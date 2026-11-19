<?php
/**
 * FeedUnavailable — the one answer a feed surface gives when its activity
 * read did not run (F-275).
 *
 * Before this, a failed read came back from bcc-core as an empty page, so
 * the member saw "no posts" (or a 404 on a permalink) with nothing telling
 * the client to try again. FeedRankingService's ActivityFeedService is now
 * built to throw {@see \BCC\Core\Contracts\ActivityReadFailedException}
 * instead (see Plugin::feedRankingService()), and every feed route turns
 * that into this response.
 *
 * ⚠ `private, no-store`, on EVERY route, including /feed/hot, whose success
 * is `public, max-age=60`. A shared cache must never hold an outage and
 * keep serving it after the database recovers.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedUnavailable
{
    public const MESSAGE      = 'The feed is temporarily unavailable. Please try again.';
    public const POST_MESSAGE = 'This post is temporarily unavailable. Please try again.';

    /** 503 bcc_unavailable, never cached. */
    public static function response(string $message = self::MESSAGE): WP_REST_Response
    {
        $response = ApiResponse::error('bcc_unavailable', $message, 503);
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }
}
