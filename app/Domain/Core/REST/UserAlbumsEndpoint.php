<?php
/**
 * UserAlbumsEndpoint — handles GET /bcc/v1/users/{slug}/albums.
 *
 * Read-only view of a user's PeepSo photo albums for the §3.1 Photos
 * tab → Albums sub-tab. Mirrors PeepSo's own
 * `PeepSoPhotosAlbumModel::get_user_photos_album` privacy gates so
 * the frontend never has to derive visibility from a raw row:
 *
 *   ACCESS_PUBLIC  (10) — always visible
 *   ACCESS_MEMBERS (20) — visible to logged-in viewers
 *   ACCESS_FRIENDS (30) — visible to friends (+ owner). Honored only
 *                         when the PeepSo Friends plugin is loaded;
 *                         otherwise filtered as PRIVATE.
 *   ACCESS_PRIVATE (40) — visible to owner only
 *
 * Cover URL is resolved via {@see PhotoRepository::resolvePhotoUrl()}
 * so S3-only deployments degrade the same way the feed photo body
 * does (empty string, frontend gracefully omits the cover).
 *
 * Per §1: no raw $wpdb here. All DB reads route through
 * {@see PeepSoAlbumRepository::getAlbumsByOwner()}.
 *
 * @package BCC\Trust\Core\REST
 * @since   §3.1 Photos extension (2026-05-14)
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

final class UserAlbumsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/users/(?P<slug>' . UsersEndpoint::HANDLE_PATTERN . ')/albums',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'getList'],
                'permission_callback' => '__return_true',
                'args' => [
                    'slug' => ['required' => true, 'sanitize_callback' => 'sanitize_user'],
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
        $targetId = UserSlugResolver::resolve($slug);
        if ($targetId === 0) {
            return ApiResponse::error('bcc_not_found', 'User not found.', 404);
        }

        $viewerId = get_current_user_id();
        $isSelf   = ($viewerId > 0 && $viewerId === $targetId);
        $isFriend = PeepSoFriendGate::areFriends($viewerId, $targetId);

        $rows = PeepSoAlbumRepository::getAlbumsByOwner($targetId);
        if ($rows === []) {
            return $this->respond([]);
        }

        $items = [];
        foreach ($rows as $row) {
            $acc = (int) ($row->pho_album_acc ?? 0);
            if (!PeepSoAlbumAccess::canView($acc, $viewerId, $isSelf, $isFriend)) {
                continue;
            }
            $items[] = $this->composeItem($row, $targetId);
        }

        return $this->respond($items);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function respond(array $items): WP_REST_Response
    {
        $response = ApiResponse::ok(['items' => $items]);
        // Same caching posture as UserGroupsEndpoint — short private TTL
        // because the row set is viewer-aware (friend-state visibility).
        $response->header('Cache-Control', 'private, max-age=30');
        return $response;
    }

    /**
     * Build the view-model row the frontend renders verbatim. Order
     * mirrors the rest of /bcc/v1 — IDs first, display strings next,
     * counts/derived fields last.
     *
     * @return array<string, mixed>
     */
    private function composeItem(object $row, int $ownerId): array
    {
        $albumId  = (int) ($row->pho_album_id ?? 0);
        $isSystem = ((int) ($row->pho_system_album ?? 0)) === 1;
        $title    = $this->remapTitle((string) ($row->pho_album_name ?? ''), $isSystem);

        return [
            'id'              => $albumId,
            'title'           => $title,
            'description'     => $this->nullableString($row->pho_album_desc ?? null),
            'photo_count'     => (int) ($row->photo_count ?? 0),
            'cover_url'       => $this->resolveCoverUrl($row, $ownerId),
            'privacy'         => $this->privacySlug((int) ($row->pho_album_acc ?? 0)),
            'privacy_label'   => $this->privacyLabel((int) ($row->pho_album_acc ?? 0)),
            'is_system_album' => $isSystem,
            'created_at'      => (string) ($row->pho_created ?? ''),
        ];
    }

    /**
     * BCC-vocabulary rename for PeepSo's default system album titles.
     * "Stream Photos" → "Floor Photos" so the surface stays in BCC's
     * floor / shop / file metaphor instead of PeepSo's social-stream
     * vocabulary. Only applies to system albums (pho_system_album=1)
     * so user-created albums named "Stream Photos" by coincidence
     * keep their title.
     *
     * Filterable via `bcc_album_title_remap` for future system names
     * (Profile, Cover, etc.) without a code change.
     */
    private function remapTitle(string $title, bool $isSystem): string
    {
        if (!$isSystem) {
            return $title;
        }
        $defaults = [
            'Stream Photos' => 'Floor Photos',
        ];

        /** @var array<string, string> $map */
        $map = apply_filters('bcc_album_title_remap', $defaults);
        return $map[$title] ?? $title;
    }

    /**
     * Build a synthetic PhotoRow object so PhotoRepository's S3-aware
     * URL resolver can do the work. Keeps the local-storage convention
     * + the future S3 path in one place rather than re-implementing.
     */
    private function resolveCoverUrl(object $row, int $ownerId): string
    {
        $filename = $this->nullableString($row->cover_filesystem_name ?? null);
        if ($filename === null || $filename === '') {
            return '';
        }

        $synthetic = (object) [
            'pho_owner_id'        => $ownerId,
            'pho_filesystem_name' => $filename,
            'pho_stored'          => $row->cover_stored ?? 0,
            'pho_token'           => $row->cover_token ?? null,
        ];

        /** @phpstan-ignore-next-line — synthetic object satisfies PhotoRow shape */
        return PhotoRepository::resolvePhotoUrl($synthetic);
    }

    /**
     * Stable enum the frontend keys on. Matches the rest of the §3.1
     * privacy vocabulary (`public` / `site_members` / `friends_only`
     * / `only_me`) so MemberPrivacy and album privacy share copy.
     */
    private function privacySlug(int $access): string
    {
        return match ($access) {
            PeepSoAlbumAccess::ACCESS_PUBLIC  => 'public',
            PeepSoAlbumAccess::ACCESS_MEMBERS => 'site_members',
            PeepSoAlbumAccess::ACCESS_FRIENDS => 'friends_only',
            PeepSoAlbumAccess::ACCESS_PRIVATE => 'only_me',
            default                           => 'only_me',
        };
    }

    /**
     * Server-authoritative display label. Frontend renders verbatim
     * per §A2 / §S — no client-side enum→label mapping. Filterable
     * via `bcc_album_privacy_label` so the copy stays editable
     * without a frontend release.
     */
    private function privacyLabel(int $access): string
    {
        $defaults = [
            PeepSoAlbumAccess::ACCESS_PUBLIC  => 'Public',
            PeepSoAlbumAccess::ACCESS_MEMBERS => 'Members',
            PeepSoAlbumAccess::ACCESS_FRIENDS => 'Friends',
            PeepSoAlbumAccess::ACCESS_PRIVATE => 'Private',
        ];
        $label = $defaults[$access] ?? 'Private';

        /** @var string $filtered */
        $filtered = apply_filters('bcc_album_privacy_label', $label, $access);
        return (string) $filtered;
    }

    /**
     * Normalize PeepSo's nullable VARCHAR columns. Returns null only
     * when the column is actually null; preserves empty strings so the
     * frontend can distinguish "no description set" from "set to ''".
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return (string) $value;
    }
}
