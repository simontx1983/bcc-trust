<?php
/**
 * HandleService — §B6 handle validation, uniqueness, and cooldown.
 *
 * Responsibilities (single concern, no DB-write side effects):
 *   - Format validation per §B6 (3–20 chars, lowercase a–z + 0–9 + '-',
 *     no leading/trailing/consecutive hyphens).
 *   - Reserved-handle blocklist (filterable for admin extension).
 *   - Uniqueness check across `wp_usermeta.bcc_handle`.
 *   - 7-day cooldown query (returns the epoch second a user's cooldown
 *     expires, or null when no cooldown is active).
 *
 * Not handled here:
 *   - Persisting bcc_handle / bcc_handle_last_changed — callers
 *     (signup + PATCH) write the meta themselves so they own the
 *     audit trail and the cooldown-arming decision (initial signup
 *     does NOT arm the cooldown; only `change` paths do).
 *
 * The constants META_HANDLE / META_HANDLE_LAST_CHANGED / COOLDOWN_SECONDS
 * are shared with the endpoint handlers — single source of truth for
 * the meta keys + window.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §B6)
 */

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class HandleService
{
    /** wp_usermeta key for the public BCC handle (per §B3). */
    public const META_HANDLE = 'bcc_handle';

    /** wp_usermeta key for the last-change timestamp (epoch seconds). */
    public const META_HANDLE_LAST_CHANGED = 'bcc_handle_last_changed';

    /** §B6 cooldown window — 7 days in seconds. */
    public const COOLDOWN_SECONDS = 604800; // 7 * 24 * 60 * 60

    /** §B6 length bounds. */
    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 20;

    /** Format-violation error code. */
    public const ERR_INVALID = 'bcc_invalid_handle';

    /** Reserved-handle error code. */
    public const ERR_RESERVED = 'bcc_handle_reserved';

    /**
     * Default reserved blocklist. Filterable via `bcc_reserved_handles`
     * so admins can extend without a code change. Lowercase only —
     * comparison is exact-match on the already-lowercased handle.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'admin', 'administrator', 'root', 'owner',
        'bcc', 'blue-collar', 'blue-collar-crypto',
        'support', 'help', 'contact',
        'system', 'api', 'null', 'undefined',
        'moderator', 'mod',
        'login', 'signup', 'signin', 'register', 'logout',
        'me', 'self', 'dashboard', 'settings', 'about',
    ];

    /**
     * Validate handle format + reserved status. Returns null on success
     * or one of the ERR_* codes on failure. Caller picks the user-facing
     * message based on the code.
     *
     * Pre-condition: $handle should already be lowercased + trimmed by
     * the caller. The validator does NOT mutate input.
     */
    public function validate(string $handle): ?string
    {
        $len = strlen($handle);
        if ($len < self::MIN_LENGTH || $len > self::MAX_LENGTH) {
            return self::ERR_INVALID;
        }

        // Allowed chars: a–z, 0–9, '-' only.
        if (preg_match('/^[a-z0-9-]+$/', $handle) !== 1) {
            return self::ERR_INVALID;
        }

        // No leading or trailing hyphen.
        if ($handle[0] === '-' || $handle[$len - 1] === '-') {
            return self::ERR_INVALID;
        }

        // No consecutive hyphens.
        if (str_contains($handle, '--')) {
            return self::ERR_INVALID;
        }

        if ($this->isReserved($handle)) {
            return self::ERR_RESERVED;
        }

        return null;
    }

    public function isReserved(string $handle): bool
    {
        /** @var list<string> $reserved */
        $reserved = (array) apply_filters('bcc_reserved_handles', self::RESERVED);
        return in_array($handle, $reserved, true);
    }

    /**
     * True when the handle is free for $excludeUserId (or for any user
     * if $excludeUserId is null). Excluding the current user lets PATCH
     * accept a no-op rename without colliding with itself.
     */
    public function isAvailable(string $handle, ?int $excludeUserId = null): bool
    {
        $users = get_users([
            'meta_key'   => self::META_HANDLE,
            'meta_value' => $handle,
            'fields'     => 'ID',
            'number'     => 1,
        ]);

        if ($users === []) {
            return true;
        }

        $foundId = (int) $users[0];
        return $excludeUserId !== null && $foundId === $excludeUserId;
    }

    /**
     * Returns the epoch second at which the user's cooldown expires,
     * or null when no cooldown is active (either never set or already
     * elapsed).
     *
     * Treats a missing or zero value as "no cooldown" — the initial
     * signup path intentionally does NOT arm the cooldown so users can
     * still change immediately if they typo'd at signup.
     */
    public function cooldownExpiresAt(int $userId): ?int
    {
        $lastChanged = (int) get_user_meta($userId, self::META_HANDLE_LAST_CHANGED, true);
        if ($lastChanged <= 0) {
            return null;
        }

        $expiresAt = $lastChanged + self::COOLDOWN_SECONDS;
        return $expiresAt > time() ? $expiresAt : null;
    }

    public function getHandle(int $userId): string
    {
        return (string) get_user_meta($userId, self::META_HANDLE, true);
    }
}
