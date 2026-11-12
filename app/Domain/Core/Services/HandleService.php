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

    /**
     * wp_usermeta key (non-unique, one row per handle) recording a handle
     * this member held before: written when a member renames (F-280), by
     * the renamed-original-handle backfill, and by the login-handle
     * rotation migration (a handle that equalled the login). A retired
     * handle resolves to its member (old links keep working) and is never
     * available to ANOTHER member. Its owner may reclaim it, unless it
     * equals their login (see isAvailable()).
     */
    public const META_HANDLE_RETIRED = 'bcc_handle_retired';

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
     *
     * $assignForUserId is for the INTERNAL handle-assignment path only
     * (bcc_trust_bch_find_free_variant): it lets that path write the
     * reserved 'member-{id}' form for that same user. User-facing
     * callers (signup, rename) never pass it.
     */
    public function validate(string $handle, ?int $assignForUserId = null): ?string
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

        // 'member-{id}' is the system fallback handle for member {id}
        // (PublicIdentity). Nobody may CHOOSE one, or they could squat
        // another member's fallback handle and links. Only the internal
        // assignment path may write it, and only for its own id
        // ($assignForUserId).
        if (preg_match('/^member-(\d+)$/', $handle, $m) === 1
            && ($assignForUserId === null || (int) $m[1] !== $assignForUserId)
        ) {
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
        // A retired handle stays reserved against every OTHER member: old
        // /u/<retired> links resolve to the member who held it, so handing
        // it to someone else would hijack those links. Its owner may take
        // it back (F-280: a rename is not a one-way door), except one that
        // equals their login: the login-handle rotation retired those so
        // the login is never on show again.
        if (self::isRetiredAgainst($handle, $excludeUserId)) {
            return false;
        }

        // Every signup mints the login u_<handle> (AuthSupport::deriveLogin)
        // and wp_users.user_login is UNIQUE, so a handle whose login
        // already exists can never be signed up: it was some member's
        // original handle, and they renamed away from it before F-280
        // retired renamed handles. Report it taken HERE, or availability
        // says "free" and signup then answers 409 (F-280). The member who
        // owns that login may still take it back.
        $loginOwner = username_exists(\BCC\Trust\Core\REST\Auth\AuthSupport::deriveLogin($handle));
        if ($loginOwner !== false && $loginOwner !== null && (int) $loginOwner !== $excludeUserId) {
            return false;
        }

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

    /**
     * The user who held $handle before it was retired, or null when it
     * was never retired. Exact match only: this resolves retired handles
     * (renames and the login-handle rotation), never a login lookup.
     */
    public static function retiredOwner(string $handle): ?int
    {
        $handle = strtolower(trim($handle));
        if ($handle === '') {
            return null;
        }
        $users = get_users([
            'meta_key'   => self::META_HANDLE_RETIRED,
            'meta_value' => $handle,
            'fields'     => 'ID',
            'number'     => 1,
        ]);
        if ($users === []) {
            return null;
        }
        $id = (int) $users[0];
        return $id > 0 ? $id : null;
    }

    /**
     * The member who holds $handle as their CURRENT handle, for sign-in by
     * handle (F-280). Null when nobody holds it, AND when more than one
     * member does: bcc_handle has no UNIQUE constraint and the rename
     * path checks then writes, so a duplicate is possible, and sign-in
     * must never pick one of two accounts. Exact match only; a RETIRED
     * handle never signs in (it is a link alias, not a credential).
     */
    public static function currentOwner(string $handle): ?int
    {
        $handle = strtolower(trim($handle));
        if ($handle === '') {
            return null;
        }
        $users = get_users([
            'meta_key'   => self::META_HANDLE,
            'meta_value' => $handle,
            'fields'     => 'ID',
            'number'     => 2,
        ]);
        if (count($users) !== 1) {
            return null;
        }
        $id = (int) $users[0];
        return $id > 0 ? $id : null;
    }

    /**
     * True when $handle is retired and may NOT go to $userId: it is
     * retired by another member, or it is $userId's own retired handle
     * that equals their login. $userId null (signup) = every retired
     * handle is reserved.
     */
    private static function isRetiredAgainst(string $handle, ?int $userId): bool
    {
        $handle = strtolower(trim($handle));
        if ($handle === '') {
            return false;
        }
        $others = get_users([
            'meta_key'   => self::META_HANDLE_RETIRED,
            'meta_value' => $handle,
            'fields'     => 'ID',
            'number'     => 1,
            'exclude'    => $userId !== null && $userId > 0 ? [$userId] : [],
        ]);
        if ($others !== []) {
            return true;
        }
        if ($userId === null || $userId <= 0) {
            return false;
        }
        /** @var list<string> $own */
        $own = (array) get_user_meta($userId, self::META_HANDLE_RETIRED, false);
        if (!in_array($handle, array_map('strtolower', $own), true)) {
            return false;
        }
        $user = get_userdata($userId);
        return $user === false || strcasecmp($handle, (string) $user->user_login) === 0;
    }
}
