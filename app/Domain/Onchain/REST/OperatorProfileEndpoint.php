<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\REST;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Services\BlogCoverImageWriter;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\OperatorProfileRepository;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Private operator profile read/write surface.
 *
 * One profile belongs to the WordPress account, not to a validator or
 * chain. Optional page overrides let the same operator tailor details
 * for an individual validator community. Every page write is re-authorized
 * against the single verified validator operator at request time.
 */
final class OperatorProfileEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const BASE_ROUTE      = '/me/operator-profile';
    private const PAGE_ROUTE      = '/me/operator-profile/pages/(?P<page_id>\d+)';
    private const LOGO_ROUTE      = '/me/operator-profile/logo';
    private const MAX_PAGES       = 100;

    public static function register(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::BASE_ROUTE, [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'get'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [new self(), 'updateShared'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route(self::ROUTE_NAMESPACE, self::PAGE_ROUTE, [
            [
                'methods'             => 'PATCH',
                'callback'            => [new self(), 'updatePageOverride'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'page_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [new self(), 'resetPageOverride'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'page_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        register_rest_route(self::ROUTE_NAMESPACE, self::LOGO_ROUTE, [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [new self(), 'uploadLogo'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [new self(), 'deleteLogo'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    public function get(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return self::unauthorized();
        }

        $repository = new OperatorProfileRepository();
        $profile    = $repository->findProfileForUser($userId);
        $claims     = ClaimRepository::listVerifiedValidatorPagesForUser($userId, self::MAX_PAGES);
        $pageIds    = [];
        foreach ($claims as $claim) {
            $pageId = isset($claim->page_id) ? (int) $claim->page_id : 0;
            if ($pageId > 0) {
                $pageIds[] = $pageId;
            }
        }
        $overrides = $repository->findOverridesForUser($userId, $pageIds);
        $items     = [];

        foreach ($claims as $claim) {
            $pageId = isset($claim->page_id) && (int) $claim->page_id > 0
                ? (int) $claim->page_id
                : null;
            $pageOverrides = $pageId !== null ? ($overrides[$pageId] ?? self::emptyOverrides()) : self::emptyOverrides();
            $items[] = [
                'page_id'   => $pageId,
                'page_slug' => isset($claim->page_slug) && (string) $claim->page_slug !== ''
                    ? (string) $claim->page_slug
                    : null,
                'chain'     => [
                    'slug' => isset($claim->chain_slug) ? (string) $claim->chain_slug : null,
                    'name' => isset($claim->chain_name) ? (string) $claim->chain_name : null,
                    'type' => isset($claim->chain_type) ? (string) $claim->chain_type : null,
                ],
                'validator' => [
                    'id'      => (int) $claim->validator_id,
                    'moniker' => isset($claim->moniker) && (string) $claim->moniker !== ''
                        ? (string) $claim->moniker
                        : null,
                ],
                'overrides' => $pageOverrides,
                'effective' => self::effectiveProfile($profile, $pageOverrides),
            ];
        }

        return self::privateResponse([
            'profile' => self::profileForWire($profile),
            'pages'   => $items,
            'total'   => count($items),
            'limit'   => self::MAX_PAGES,
        ]);
    }

    public function updateShared(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return self::unauthorized();
        }
        if (($suspended = self::refuseIfSuspended($userId)) !== null) {
            return $suspended;
        }

        $parsed = self::parsePatch($request, false);
        if (isset($parsed['response'])) {
            return $parsed['response'];
        }
        $fields = [];
        foreach ($parsed['fields'] ?? [] as $field => $value) {
            if (is_string($value)) {
                $fields[$field] = $value;
            }
        }
        if ($fields === []) {
            return self::privateError('bcc_invalid_request', 'Provide at least one supported profile field.', 400);
        }
        if (ClaimRepository::listVerifiedValidatorPagesForUser($userId, 1) === []) {
            return self::privateError('bcc_forbidden', 'A verified validator operator claim is required to manage this profile.', 403);
        }

        if (!(new OperatorProfileRepository())->updateProfile($userId, $fields)) {
            return self::privateError('bcc_profile_save_failed', 'The operator profile could not be saved.', 503);
        }

        Logger::audit('operator_profile_updated', [
            'user_id' => $userId,
            'fields'  => array_keys($fields),
            'via'     => 'rest',
        ]);

        return $this->get($request);
    }

    public function updatePageOverride(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return self::unauthorized();
        }
        if (($suspended = self::refuseIfSuspended($userId)) !== null) {
            return $suspended;
        }

        $pageId = (int) $request->get_param('page_id');
        $gate   = self::requireVerifiedOperator($pageId, $userId);
        if ($gate !== null) {
            return $gate;
        }

        $parsed = self::parsePatch($request, true);
        if (isset($parsed['response'])) {
            return $parsed['response'];
        }
        $fields = $parsed['fields'] ?? [];
        if ($fields === []) {
            return self::privateError('bcc_invalid_request', 'Provide at least one supported override field.', 400);
        }

        if (!(new OperatorProfileRepository())->updatePageOverrides($userId, $pageId, $fields)) {
            return self::privateError('bcc_profile_save_failed', 'The page profile override could not be saved.', 503);
        }

        Logger::audit('operator_profile_page_override_updated', [
            'user_id' => $userId,
            'page_id' => $pageId,
            'fields'  => array_keys($fields),
            'via'     => 'rest',
        ]);

        return $this->get($request);
    }

    public function resetPageOverride(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return self::unauthorized();
        }
        if (($suspended = self::refuseIfSuspended($userId)) !== null) {
            return $suspended;
        }

        $pageId = (int) $request->get_param('page_id');
        $gate   = self::requireVerifiedOperator($pageId, $userId);
        if ($gate !== null) {
            return $gate;
        }

        if (!(new OperatorProfileRepository())->deletePageOverrides($pageId)) {
            return self::privateError('bcc_profile_save_failed', 'The page profile override could not be reset.', 503);
        }

        Logger::audit('operator_profile_page_override_reset', [
            'user_id' => $userId,
            'page_id' => $pageId,
            'via'     => 'rest',
        ]);

        return $this->get($request);
    }

    public function uploadLogo(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return self::unauthorized();
        }
        if (($suspended = self::refuseIfSuspended($userId)) !== null) {
            return $suspended;
        }
        if (ClaimRepository::listVerifiedValidatorPagesForUser($userId, 1) === []) {
            return self::privateError('bcc_forbidden', 'A verified validator operator claim is required to manage this profile.', 403);
        }

        $files = $request->get_file_params();
        $file  = isset($files['logo']) && is_array($files['logo']) ? $files['logo'] : null;
        if ($file === null) {
            return self::privateError('bcc_invalid_request', 'No image uploaded (expected field "logo").', 400);
        }

        $result = (new BlogCoverImageWriter())->upload($userId, $file);
        if (!isset($result['ok'])) {
            $error = isset($result['error']) && is_string($result['error'])
                ? $result['error']
                : 'bcc_upload_failed';
            $message = isset($result['message']) && is_string($result['message'])
                ? $result['message']
                : 'The operator logo could not be uploaded.';
            $status = match ($error) {
                'bcc_unauthorized'     => 401,
                'bcc_rate_limited'     => 429,
                'bcc_invalid_request'  => 400,
                default                => 503,
            };
            return self::privateError($error, $message, $status);
        }

        $attachmentId = (int) $result['attachment_id'];
        // Read the outgoing id BEFORE the write; after it, the row no
        // longer remembers what this upload replaced.
        $previousId = self::currentLogoAttachmentId($userId);

        if (!(new OperatorProfileRepository())->setLogoAttachment($userId, $attachmentId)) {
            wp_delete_attachment($attachmentId, true);
            return self::privateError('bcc_profile_save_failed', 'The operator logo could not be saved.', 503);
        }

        // Only once the new id is durably stored — a failed save above
        // keeps the old logo, so it must still be there.
        self::discardSupersededLogo($userId, $previousId, $attachmentId);

        Logger::audit('operator_profile_logo_set', [
            'user_id'       => $userId,
            'attachment_id' => $attachmentId,
            'via'           => 'rest',
        ]);

        $response = self::privateResponse([
            'logo_url' => (string) $result['url'],
        ]);
        return $response;
    }

    public function deleteLogo(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return self::unauthorized();
        }
        if (($suspended = self::refuseIfSuspended($userId)) !== null) {
            return $suspended;
        }
        if (ClaimRepository::listVerifiedValidatorPagesForUser($userId, 1) === []) {
            return self::privateError('bcc_forbidden', 'A verified validator operator claim is required to manage this profile.', 403);
        }

        $previousId = self::currentLogoAttachmentId($userId);

        if (!(new OperatorProfileRepository())->setLogoAttachment($userId, null)) {
            return self::privateError('bcc_profile_save_failed', 'The operator logo could not be removed.', 503);
        }

        // "Remove logo" now actually removes the file, not just the pointer.
        self::discardSupersededLogo($userId, $previousId, null);

        Logger::audit('operator_profile_logo_cleared', [
            'user_id' => $userId,
            'via'     => 'rest',
        ]);

        return self::privateResponse(['logo_url' => null]);
    }

    /**
     * @return array{fields?: array<string, string|null>, response?: WP_REST_Response}
     */
    private static function parsePatch(WP_REST_Request $request, bool $allowNull): array
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return ['response' => self::privateError('bcc_invalid_request', 'A JSON object body is required.', 400)];
        }

        $unknown = array_diff(array_keys($body), OperatorProfileRepository::PROFILE_FIELDS);
        if ($unknown !== []) {
            return ['response' => self::privateError('bcc_invalid_request', 'The request contains unsupported profile fields.', 400)];
        }

        $fields = [];
        foreach (OperatorProfileRepository::PROFILE_FIELDS as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($value === null && $allowNull) {
                $fields[$field] = null;
                continue;
            }
            if (!is_string($value)) {
                return ['response' => self::privateError('bcc_invalid_request', 'Profile fields must be strings; null is allowed only to inherit a shared field.', 400)];
            }

            $sanitized = self::sanitizeField($field, $value);
            if ($sanitized === null) {
                return ['response' => self::privateError('bcc_invalid_request', 'One or more profile fields are invalid or too long.', 400)];
            }
            $fields[$field] = $sanitized;
        }

        return ['fields' => $fields];
    }

    private static function sanitizeField(string $field, string $value): ?string
    {
        $limits = [
            'brand_name'  => 100,
            'bio'         => 1000,
            'website_url' => 255,
            'x_url'       => 255,
            'discord_url' => 255,
            'telegram_url' => 255,
        ];
        $max = $limits[$field] ?? 0;
        if ($max <= 0 || strlen($value) > $max * 4) {
            return null;
        }

        if ($field === 'bio') {
            $sanitized = trim(sanitize_textarea_field($value));
        } elseif (str_ends_with($field, '_url')) {
            $sanitized = esc_url_raw(trim($value), ['http', 'https']);
            if ($sanitized !== '') {
                $parts  = wp_parse_url($sanitized);
                $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
                $host   = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
                if (!in_array($scheme, ['http', 'https'], true)
                    || $host === ''
                    || isset($parts['user'])
                    || isset($parts['pass'])
                ) {
                    return null;
                }
            }
        } else {
            $sanitized = trim(sanitize_text_field($value));
        }

        if (function_exists('mb_strlen')) {
            $length = mb_strlen($sanitized, 'UTF-8');
        } else {
            $measured = preg_match_all('/./us', $sanitized);
            $length   = $measured === false ? strlen($sanitized) : $measured;
        }
        if ($length > $max || strlen($sanitized) > ($max * 4)) {
            return null;
        }

        return $sanitized;
    }

    private static function requireVerifiedOperator(int $pageId, int $userId): ?WP_REST_Response
    {
        if ($pageId <= 0) {
            return self::privateError('bcc_invalid_request', 'A validator page id is required.', 400);
        }

        $decision = ValidatorPageOperatorAuthority::decide($pageId, $userId);
        if ($decision['decision'] !== ValidatorPageOperatorAuthority::DECISION_OPERATOR) {
            return self::privateError(
                'bcc_forbidden',
                'Only the verified operator of this validator page can edit its profile.',
                403,
                ['reason_code' => ValidatorPageOperatorAuthority::publicReason($decision)]
            );
        }

        return null;
    }

    /** @return array<string, string|null> */
    private static function emptyOverrides(): array
    {
        $empty = [];
        foreach (OperatorProfileRepository::PROFILE_FIELDS as $field) {
            $empty[$field] = null;
        }
        return $empty;
    }

    /**
     * @param array<string, mixed>|null      $profile
     * @param array<string, string|null>     $overrides
     * @return array<string, string|null>
     */
    private static function effectiveProfile(?array $profile, array $overrides): array
    {
        $effective = [];
        foreach (OperatorProfileRepository::PROFILE_FIELDS as $field) {
            $override = $overrides[$field] ?? null;
            $shared   = $profile[$field] ?? '';
            $effective[$field] = $override !== null
                ? $override
                : (is_string($shared) ? $shared : '');
        }
        $effective['logo_url'] = self::profileLogoUrl($profile);
        return $effective;
    }

    /**
     * @param array<string, mixed>|null $profile
     * @return array<string, string|null>|null
     */
    private static function profileForWire(?array $profile): ?array
    {
        if ($profile === null) {
            return null;
        }

        $logoUrl = self::profileLogoUrl($profile);
        unset($profile['user_id'], $profile['logo_attachment_id']);
        $profile['logo_url'] = is_string($logoUrl) && $logoUrl !== '' ? $logoUrl : null;
        return $profile;
    }

    /** @param array<string, mixed>|null $profile */
    private static function profileLogoUrl(?array $profile): ?string
    {
        $attachmentId = $profile['logo_attachment_id'] ?? null;
        if (!is_int($attachmentId) || $attachmentId <= 0) {
            return null;
        }
        // ⚠ BOUNDED SIZE, NOT 'full'. BlogCoverImageWriter accepts up to
        // 8 MiB and 24 million pixels, and this URL is rendered on every
        // validator card including directory grids — 'full' shipped the
        // original to each one. 'medium' matches the card-avatar
        // convention (resolvePageAvatarUrl uses 'thumbnail') and
        // wp_get_attachment_image_url falls back to the full URL when the
        // intermediate size is missing, so nothing breaks for an image
        // uploaded before this size existed.
        $url = wp_get_attachment_image_url($attachmentId, 'medium');
        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Delete a logo attachment this profile has stopped pointing at.
     *
     * ⚠ THE LEAK THIS CLOSES. Every logo change overwrote (or nulled)
     * `logo_attachment_id` and left the previous attachment in the media
     * library — unbounded storage growth, and the old image stayed
     * PUBLICLY FETCHABLE at its original URL after an operator pressed
     * "remove logo". Nothing ever collected them.
     *
     * ⛔ Only an attachment AUTHORED BY THIS USER is deleted. The id comes
     * from our own row, which only this user's own upload writes, so this
     * is belt-and-braces — but `wp_delete_attachment($id, true)` is
     * irreversible and must never be able to reach another member's
     * media. A mismatch is logged and the file retained, because leaking
     * a file is recoverable and deleting someone else's is not.
     */
    private static function discardSupersededLogo(int $userId, ?int $previousId, ?int $currentId): void
    {
        if ($previousId === null || $previousId <= 0 || $previousId === $currentId) {
            return;
        }

        $author = (int) get_post_field('post_author', $previousId);
        if ($author !== $userId) {
            Logger::audit('operator_profile_logo_orphan_retained', [
                'user_id'       => $userId,
                'attachment_id' => $previousId,
                'reason'        => 'author_mismatch',
            ]);
            return;
        }

        wp_delete_attachment($previousId, true);

        Logger::audit('operator_profile_logo_superseded_deleted', [
            'user_id'       => $userId,
            'attachment_id' => $previousId,
            'via'           => 'rest',
        ]);
    }

    /**
     * The attachment id this profile currently points at, if any.
     * Read BEFORE a write so the superseded file can be collected after.
     */
    private static function currentLogoAttachmentId(int $userId): ?int
    {
        $profile = (new OperatorProfileRepository())->findProfileForUser($userId);
        if ($profile === null) {
            return null;
        }

        $id = $profile['logo_attachment_id'] ?? null;

        return is_int($id) && $id > 0 ? $id : null;
    }

    private static function unauthorized(): WP_REST_Response
    {
        return self::privateError('bcc_unauthorized', 'Sign in to manage your operator profile.', 401);
    }

    /**
     * §M suspension gate (F-109 review M1): a suspended operator cannot
     * change the public operator profile, overrides or logo. Admin bypass
     * OFF, the same gate as PageDelegatorGroupEndpoint and the page claim
     * route. Uses this endpoint's private, no-store envelope.
     */
    private static function refuseIfSuspended(int $userId): ?WP_REST_Response
    {
        if (\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)) {
            return null;
        }

        return self::privateError('bcc_forbidden', 'Your account is suspended.', 403);
    }

    /** @param array<string, mixed>|null $data */
    private static function privateError(string $code, string $message, int $status, ?array $data = null): WP_REST_Response
    {
        $response = ApiResponse::error($code, $message, $status, $data);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    /** @param array<string, mixed> $data */
    private static function privateResponse(array $data): WP_REST_Response
    {
        $response = ApiResponse::ok($data);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }
}
