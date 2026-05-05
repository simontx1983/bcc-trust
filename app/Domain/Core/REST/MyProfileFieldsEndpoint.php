<?php
/**
 * MyProfileFieldsEndpoint — handles /bcc/v1/me/profile/fields.
 *
 * Three routes for the user's PeepSo-backed profile-fields surface
 * (the V2 Phase 2.5 mirror of PeepSo's About sub-tab):
 *
 *   GET   /me/profile/fields                       — schema + values + visibility
 *   PATCH /me/profile/fields/{key}                 — update value
 *   PATCH /me/profile/fields/{key}/visibility      — update Public / Members / Private
 *
 * Field schema lives in WP posts of post_type `peepso_user_field`,
 * configured by an admin in PeepSo's wp-admin UI. We never invent
 * fields — we read PeepSo's catalogue and expose it 1:1, so admins
 * can add/rename/reorder fields without a code change here.
 *
 * Storage:
 *   - Value      → user_meta `peepso_user_field_{key}` (or wp_users
 *                  column for is_core=1 fields, handled by PeepSo).
 *   - Visibility → user_meta `peepso_user_field_{key}_acc`, integer
 *                  matching PeepSo::ACCESS_PUBLIC=10 / MEMBERS=20 /
 *                  PRIVATE=40.
 *
 * We delegate to `PeepSoField::save()` and `PeepSoField::save_acc()`
 * so PeepSo's own profile surfaces (legacy templates, search index,
 * profile-completeness counter) stay coherent with our writes.
 *
 * Auth: required. Self-only — every route operates on
 * `get_current_user_id()`.
 *
 * Cache: `no-store` on every response (per-viewer, mutation-bearing).
 *
 * @package BCC\Trust\Core\REST
 * @since V2 Phase 2.5 (Profile fields mirror)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyProfileFieldsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /**
     * Hard cap on text-ish field length to prevent runaway inputs.
     * PeepSo's lengthmax validator (per-field, admin-configured)
     * narrows further.
     */
    private const TEXT_FIELD_MAX_LENGTH = 5000;

    /**
     * Visibility tokens exposed to the frontend, mapped to PeepSo's
     * integer access constants. Order matches PeepSo's UI.
     *
     * @var array<string, int>
     */
    private const VISIBILITY_TOKENS = [
        'public'  => 10, // PeepSo::ACCESS_PUBLIC
        'members' => 20, // PeepSo::ACCESS_MEMBERS
        'private' => 40, // PeepSo::ACCESS_PRIVATE
    ];

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/profile/fields',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'getAll'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/profile/fields/(?P<key>[a-zA-Z0-9_\-]+)',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$instance, 'patchValue'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'key' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/profile/fields/(?P<key>[a-zA-Z0-9_\-]+)/visibility',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$instance, 'patchVisibility'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'key' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ]
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Read
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getAll(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $peepsoUser = self::peepsoUserOrError($userId);
        if ($peepsoUser instanceof WP_REST_Response) {
            return $peepsoUser;
        }

        $items = self::buildItems($peepsoUser, $userId);
        $stats = self::extractStats($peepsoUser);

        $resp = ApiResponse::ok([
            'fields' => $items,
            'stats'  => $stats,
        ]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    // ──────────────────────────────────────────────────────────────────
    // Write — value
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function patchValue(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $key = (string) $request->get_param('key');
        if ($key === '') {
            return ApiResponse::error('bcc_invalid_request', 'Field key is required.', 422);
        }

        if (!$request->has_param('value')) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Body must include `value`.',
                422
            );
        }

        $field = self::loadField($key, $userId);
        if ($field === null) {
            return ApiResponse::error(
                'bcc_not_found',
                sprintf('No profile field with key `%s`.', $key),
                404
            );
        }

        if (self::fieldIsLockedForEdit($field)) {
            return ApiResponse::error(
                'bcc_forbidden',
                'This field is read-only.',
                403
            );
        }

        $rawValue = $request->get_param('value');
        $sanitized = self::sanitizeValueForField($field, $rawValue);

        if (!$field->save($sanitized, false)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                self::firstValidationMessage($field->validation_errors, 'Could not save field.'),
                422
            );
        }

        // Reload to pick up coerced storage form (PeepSo may normalize).
        $reloaded = self::loadField($key, $userId);
        if ($reloaded === null) {
            return ApiResponse::error('bcc_internal_error', 'Could not reload field.', 500);
        }

        $resp = ApiResponse::ok(self::buildItem($reloaded));
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    // ──────────────────────────────────────────────────────────────────
    // Write — visibility
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function patchVisibility(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $key = (string) $request->get_param('key');
        if ($key === '') {
            return ApiResponse::error('bcc_invalid_request', 'Field key is required.', 422);
        }

        $token = $request->get_param('visibility');
        if (!is_string($token) || !isset(self::VISIBILITY_TOKENS[$token])) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Field `visibility` must be one of: public, members, private.',
                422
            );
        }

        $field = self::loadField($key, $userId);
        if ($field === null) {
            return ApiResponse::error(
                'bcc_not_found',
                sprintf('No profile field with key `%s`.', $key),
                404
            );
        }

        if (self::fieldVisibilityIsLocked($field)) {
            return ApiResponse::error(
                'bcc_forbidden',
                'Visibility for this field is locked by the administrator.',
                403
            );
        }

        $accInt = self::VISIBILITY_TOKENS[$token];
        $field->save_acc($accInt);

        $reloaded = self::loadField($key, $userId);
        if ($reloaded === null) {
            return ApiResponse::error('bcc_internal_error', 'Could not reload field.', 500);
        }

        $resp = ApiResponse::ok(self::buildItem($reloaded));
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers — load
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return \PeepSoUser|WP_REST_Response
     */
    private static function peepsoUserOrError(int $userId)
    {
        if (!class_exists('\\PeepSoUser') || !class_exists('\\PeepSoProfileFields')) {
            return ApiResponse::error(
                'bcc_peepso_unavailable',
                'Profile fields require PeepSo. The administrator should activate the PeepSo plugin.',
                503
            );
        }

        return \PeepSoUser::get_instance($userId);
    }

    /**
     * Resolve a single PeepSoField for the current user by its catalogue key
     * (post_name on the peepso_user_field CPT). Returns null when not found
     * or the user can't access it (filtered out by PeepSo's load_fields).
     */
    private static function loadField(string $key, int $userId): ?\PeepSoField
    {
        if (!class_exists('\\PeepSoField')) {
            return null;
        }

        $field = \PeepSoField::get_field_by_id($key, $userId);
        if (!($field instanceof \PeepSoField)) {
            return null;
        }

        // Block hidden / admin-only fields from self-edit (defense in depth).
        if (self::fieldIsHidden($field)) {
            return null;
        }

        return $field;
    }

    /**
     * @param \PeepSoUser $peepsoUser
     * @return list<array<string, mixed>>
     */
    private static function buildItems(\PeepSoUser $peepsoUser, int $userId): array
    {
        unset($userId);

        // load_fields() populates $profile_fields keyed by field->key.
        // Pass context='profile' so PeepSo skips registration-only fields.
        $peepsoUser->profile_fields->load_fields([], 'profile');
        $fields = $peepsoUser->profile_fields->get_fields();

        $items = [];
        foreach ($fields as $field) {
            if (!($field instanceof \PeepSoField)) {
                continue;
            }
            if (self::fieldIsHidden($field)) {
                continue;
            }
            $items[] = self::buildItem($field);
        }

        return $items;
    }

    /**
     * @param \PeepSoUser $peepsoUser
     * @return array{filled: int, total: int, completeness: int}
     */
    private static function extractStats(\PeepSoUser $peepsoUser): array
    {
        $stats = $peepsoUser->profile_fields->profile_fields_stats;
        if (!is_array($stats)) {
            return ['filled' => 0, 'total' => 0, 'completeness' => 0];
        }

        return [
            'filled'       => isset($stats['filled_all']) ? (int) $stats['filled_all'] : 0,
            'total'        => isset($stats['fields_all']) ? (int) $stats['fields_all'] : 0,
            'completeness' => isset($stats['completeness']) ? (int) $stats['completeness'] : 0,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers — view-model
    // ──────────────────────────────────────────────────────────────────

    /**
     * Build the wire shape for a single field. The frontend uses `type`
     * to pick the right input control; `options` is non-null only for
     * select-style fields; `max_length` is null when unconstrained.
     *
     * @return array<string, mixed>
     */
    private static function buildItem(\PeepSoField $field): array
    {
        $type = self::resolveType($field);
        $key  = self::trimKey($field->key);

        return [
            'key'                => $key,
            'label'              => $field->title !== '' ? $field->title : $key,
            'help_text'          => $field->desc !== '' ? $field->desc : null,
            'type'               => $type,
            'value'              => self::normalizeValueForWire($field),
            'visibility'         => self::tokenForAcc((int) $field->acc),
            'visibility_locked'  => self::fieldVisibilityIsLocked($field),
            'editable'           => !self::fieldIsLockedForEdit($field),
            'required'           => self::isRequired($field),
            'max_length'         => self::maxLength($field, $type),
            'options'            => self::resolveOptions($field, $type),
            'order'              => self::propInt($field, 'meta', 'order'),
        ];
    }

    private static function trimKey(string $rawKey): string
    {
        if (class_exists('\\PeepSoField')) {
            return \PeepSoField::user_meta_key_trim($rawKey);
        }
        // Fallback: strip the prefix manually.
        return preg_replace('/^peepso_user_field_/', '', $rawKey) ?? $rawKey;
    }

    /**
     * Return the wire type. We narrow PeepSo's dozen-or-so subclasses
     * down to a small set the frontend has explicit input components
     * for; anything else falls back to "text".
     */
    private static function resolveType(\PeepSoField $field): string
    {
        $cls = get_class($field);
        switch ($cls) {
            case 'PeepSoFieldText':
                // Text vs textarea is a render-method preference; expose both.
                $method = self::propString($field, 'meta', 'method_form');
                return $method === '_render_form_textarea' ? 'textarea' : 'text';
            case 'PeepSoFieldTextDate':
                return 'date';
            case 'PeepSoFieldTextEmail':
                return 'email';
            case 'PeepSoFieldTextUrl':
            case 'PeepSoFieldTextUrlPreset':
                return 'url';
            case 'PeepSoFieldSelectSingle':
                return 'select_single';
            case 'PeepSoFieldSelectMulti':
                return 'select_multi';
            case 'PeepSoFieldSelectBool':
                return 'select_bool';
            case 'PeepSoFieldCountry':
                return 'country';
            case 'PeepSoFieldLocation':
                return 'location';
            default:
                return 'text';
        }
    }

    /**
     * @return list<array{value: string, label: string}>|null
     */
    private static function resolveOptions(\PeepSoField $field, string $type): ?array
    {
        if (!in_array($type, ['select_single', 'select_multi', 'select_bool', 'country'], true)) {
            return null;
        }
        if (!method_exists($field, 'get_options')) {
            return null;
        }

        /** @var mixed $rawOptions */
        $rawOptions = $field->get_options();
        if (!is_array($rawOptions)) {
            return null;
        }

        $out = [];
        foreach ($rawOptions as $value => $label) {
            $out[] = [
                'value' => (string) $value,
                'label' => is_string($label) ? $label : (string) $value,
            ];
        }
        return $out;
    }

    /**
     * Normalize the stored value into a stable wire shape:
     *   text/textarea/date/url/email/select_single/select_bool/country → string
     *   select_multi → list<string>
     *   location     → object/string passthrough
     *
     * Empty values surface as "" (or [] for select_multi) so the
     * frontend doesn't need to distinguish missing vs blank.
     *
     * @return mixed
     */
    private static function normalizeValueForWire(\PeepSoField $field)
    {
        $value = $field->value;
        if ($value === false || $value === null) {
            return $field->data_type === 'array' ? [] : '';
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                $out[] = is_scalar($v) ? (string) $v : '';
            }
            return $out;
        }

        if (is_object($value)) {
            return $value;
        }

        return (string) $value;
    }

    /**
     * Map PeepSo's integer access constants back to our wire tokens.
     * Unknown values fall through to "members" (the safe default — same
     * as PeepSo's get_acc()).
     */
    private static function tokenForAcc(int $acc): string
    {
        foreach (self::VISIBILITY_TOKENS as $token => $intValue) {
            if ($intValue === $acc) {
                return $token;
            }
        }
        return 'members';
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers — sanitization + access flags
    // ──────────────────────────────────────────────────────────────────

    /**
     * Sanitize at the API boundary before handing to PeepSoField::save().
     * PeepSo runs its own validation but doesn't strip XSS — that's our job.
     *
     * @param mixed $raw
     * @return mixed
     */
    private static function sanitizeValueForField(\PeepSoField $field, $raw)
    {
        $type = self::resolveType($field);

        switch ($type) {
            case 'text':
                $s = is_scalar($raw) ? (string) $raw : '';
                $s = sanitize_text_field($s);
                return self::truncate($s, self::TEXT_FIELD_MAX_LENGTH);

            case 'textarea':
                $s = is_scalar($raw) ? (string) $raw : '';
                $s = sanitize_textarea_field($s);
                return self::truncate($s, self::TEXT_FIELD_MAX_LENGTH);

            case 'email':
                $s = is_scalar($raw) ? (string) $raw : '';
                $s = sanitize_email($s);
                return $s;

            case 'url':
                $s = is_scalar($raw) ? (string) $raw : '';
                return esc_url_raw($s);

            case 'date':
                $s = is_scalar($raw) ? (string) $raw : '';
                $s = trim($s);
                if ($s === '') {
                    return '';
                }
                // Accept YYYY, YYYY-MM, or YYYY-MM-DD; reject anything else.
                if (!preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $s)) {
                    return '';
                }
                return $s;

            case 'select_single':
            case 'select_bool':
            case 'country':
                $s = is_scalar($raw) ? (string) $raw : '';
                return sanitize_text_field($s);

            case 'select_multi':
                if (!is_array($raw)) {
                    return [];
                }
                $out = [];
                foreach ($raw as $v) {
                    if (is_scalar($v)) {
                        $out[] = sanitize_text_field((string) $v);
                    }
                }
                return $out;

            case 'location':
                // Location is opaque to us — could be a place_id string or
                // a structured object. Pass-through as text after stripping
                // tags, capped.
                $s = is_scalar($raw) ? (string) $raw : '';
                $s = sanitize_text_field($s);
                return self::truncate($s, self::TEXT_FIELD_MAX_LENGTH);

            default:
                $s = is_scalar($raw) ? (string) $raw : '';
                return self::truncate(sanitize_text_field($s), self::TEXT_FIELD_MAX_LENGTH);
        }
    }

    private static function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        // Use mb_substr if available for unicode safety.
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $max);
        }
        return substr($s, 0, $max);
    }

    /** Field is hidden from self-edit entirely (registration-only or admin-only). */
    private static function fieldIsHidden(\PeepSoField $field): bool
    {
        if (self::propInt($field, 'meta', 'user_registration_only') === 1) {
            return true;
        }
        if (self::propInt($field, 'meta', 'admin_only') === 1) {
            return true;
        }
        if (self::propInt($field, 'meta', 'user_admin_visible_only') === 1
            && !current_user_can('edit_users')
        ) {
            return true;
        }
        if ((int) $field->published !== 1) {
            return true;
        }
        return false;
    }

    /** Field is read-only — visible but cannot be edited. */
    private static function fieldIsLockedForEdit(\PeepSoField $field): bool
    {
        if (self::propInt($field, 'meta', 'user_disable_edit') === 1) {
            return true;
        }
        if (self::propInt($field, 'meta', 'user_admin_editable_only') === 1
            && !current_user_can('edit_users')
        ) {
            return true;
        }
        return false;
    }

    /** Visibility is locked when admin disabled per-field privacy controls. */
    private static function fieldVisibilityIsLocked(\PeepSoField $field): bool
    {
        return self::propInt($field, 'meta', 'user_disable_acc') === 1;
    }

    private static function isRequired(\PeepSoField $field): bool
    {
        $validation = isset($field->meta->validation) ? $field->meta->validation : null;
        if (is_object($validation) && isset($validation->required)) {
            return (int) $validation->required === 1;
        }
        if (is_array($validation) && isset($validation['required'])) {
            return (int) $validation['required'] === 1;
        }
        return false;
    }

    /**
     * Per-field max length, derived from PeepSo's lengthmax validator
     * when set. Returns null when unconstrained.
     */
    private static function maxLength(\PeepSoField $field, string $type): ?int
    {
        if (!in_array($type, ['text', 'textarea', 'email', 'url'], true)) {
            return null;
        }
        $validation = isset($field->meta->validation) ? $field->meta->validation : null;
        if (is_object($validation) && isset($validation->lengthmax_value)) {
            $n = (int) $validation->lengthmax_value;
            return $n > 0 ? $n : null;
        }
        if (is_array($validation) && isset($validation['lengthmax_value'])) {
            $n = (int) $validation['lengthmax_value'];
            return $n > 0 ? $n : null;
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers — meta accessors (PeepSoField->meta is a stdClass with
    // mixed types; centralize the casts)
    // ──────────────────────────────────────────────────────────────────

    private static function propString(\PeepSoField $field, string $key1, string $key2): string
    {
        $value = $field->prop($key1, $key2);
        return is_string($value) ? $value : '';
    }

    private static function propInt(\PeepSoField $field, string $key1, string $key2): int
    {
        $value = $field->prop($key1, $key2);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return (int) $value;
        }
        return 0;
    }

    /**
     * @param array<string, mixed> $errors
     */
    private static function firstValidationMessage(array $errors, string $fallback): string
    {
        foreach ($errors as $msg) {
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
        }
        return $fallback;
    }
}
