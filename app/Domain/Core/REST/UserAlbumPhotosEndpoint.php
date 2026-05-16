<?php
/**
 * UserAlbumPhotosEndpoint — GET /bcc/v1/users/{slug}/albums/{id}/photos.
 *
 * Drill-down companion to UserAlbumsEndpoint. Returns every photo row
 * in a single PeepSo album (bounded by the repository's hard cap),
 * with viewer-aware privacy enforced server-side: the album's own
 * `pho_album_acc` is re-checked here so a stale album_id leaked from
 * the list endpoint can't be replayed by a viewer who later loses
 * access (friend-state flip, etc).
 *
 * Each photo carries:
 *   - id           → pho_id
 *   - photo_url    → resolved via PhotoRepository::resolvePhotoUrl
 *                    (S3-aware; empty string when V1 can't resolve)
 *   - source_post  → { id, url } for the wp_post the photo was
 *                    posted to (FloorItem deeplink). null when the
 *                    photo has no parent post.
 *
 * No SELECT *. No raw $wpdb here — repos do the reads.
 *
 * @package BCC\Trust\Core\REST
 * @since   §3.1 Photos drill-down (2026-05-14)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Core\Repositories\PeepSoAlbumRepository;
use BCC\Trust\Core\Repositories\PhotoRepository;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\PeepSoAlbumAccess;
use BCC\Trust\Core\Support\PeepSoFriendGate;
use BCC\Trust\Core\Support\UserSlugResolver;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class UserAlbumPhotosEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/users/(?P<slug>' . UsersEndpoint::HANDLE_PATTERN . ')/albums/(?P<album_id>\d+)/photos',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'getList'],
                'permission_callback' => '__return_true',
                'args' => [
                    'slug'     => ['required' => true, 'sanitize_callback' => 'sanitize_user'],
                    'album_id' => ['required' => true, 'sanitize_callback' => 'absint'],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getList(WP_REST_Request $request): WP_REST_Response
    {
        $slug     = (string) $request->get_param('slug');
        $albumId  = (int) $request->get_param('album_id');
        $targetId = UserSlugResolver::resolve($slug);
        if ($targetId === 0) {
            return ApiResponse::error('bcc_not_found', 'User not found.', 404);
        }
        if ($albumId <= 0) {
            return ApiResponse::error('bcc_not_found', 'Album not found.', 404);
        }

        $album = PeepSoAlbumRepository::findOneByIdAndOwner($albumId, $targetId);
        if ($album === null) {
            // Same response shape as a missing user — don't leak
            // "exists but you can't see it" to a probing viewer.
            return ApiResponse::error('bcc_not_found', 'Album not found.', 404);
        }

        $viewerId = get_current_user_id();
        $isSelf   = ($viewerId > 0 && $viewerId === $targetId);
        $isFriend = PeepSoFriendGate::areFriends($viewerId, $targetId);
        $access   = (int) ($album->pho_album_acc ?? 0);

        if (!PeepSoAlbumAccess::canView($access, $viewerId, $isSelf, $isFriend)) {
            return ApiResponse::error('bcc_not_found', 'Album not found.', 404);
        }

        $photoRepo = new PhotoRepository();
        $rows      = $photoRepo->findByAlbumId($albumId, $targetId);

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->composeItem($row);
        }

        $response = ApiResponse::ok(['items' => $items]);
        $response->header('Cache-Control', 'private, max-age=30');
        return $response;
    }

    /**
     * Build the per-photo view-model row. Same `photo_url` resolver as
     * PhotoBody so floor and album surfaces agree on URL shape — S3
     * fallback degrades to "" identically.
     *
     * @param object{pho_id: int|numeric-string, pho_post_id: int|numeric-string, pho_owner_id: int|numeric-string, pho_filesystem_name: string, pho_ext: string|null, pho_token: string|null, pho_stored: int|numeric-string|null} $row
     * @return array<string, mixed>
     */
    private function composeItem(object $row): array
    {
        $photoId = (int) ($row->pho_id ?? 0);
        $postId  = (int) ($row->pho_post_id ?? 0);
        $url     = PhotoRepository::resolvePhotoUrl($row);

        $sourcePost = null;
        if ($postId > 0) {
            $permalink = get_permalink($postId);
            if ($permalink !== false && $permalink !== '') {
                $sourcePost = [
                    'id'  => $postId,
                    'url' => (string) $permalink,
                ];
            }
        }

        return [
            'id'          => $photoId,
            'photo_url'   => $url,
            'source_post' => $sourcePost,
        ];
    }
}
