<?php
/**
 * EliteOverridePin — an admin's manual Elite grant that STICKS (F-113).
 *
 * Product decision 2026-11-10 (user): an admin score override TO elite
 * stays elite against the nightly eligibility sweep until an admin removes
 * it. Before this, ModerationService::overrideScore() set elite_eligible=1
 * and EliteEligibilityService::recomputeFor() (the nightly cron, attestation
 * and dispute events) silently overwrote it with the evaluated verdict, so
 * the grant "didn't take". Meanwhile an override DOWN never cleared it.
 *
 * Persisted WITHOUT a schema change, in a split store behind one resolver:
 *   - entity pages are real wp_posts: post meta on the page itself, so the
 *     pin stays with the page through an ownership transfer;
 *   - member self-pages are synthetic ids (MemberSelfPageService::ID_BASE +
 *     user_id), NOT wp_posts, so the pin is user meta on the owner. It goes
 *     with the user on deletion (wp_delete_user removes all user meta).
 *
 * The value is {admin_id, granted_at}. A read error (WordPress returns ''
 * or false) or a malformed value reads as NOT pinned. This FAILS CLOSED: a
 * DB blip can never make a page elite.
 *
 * Collaborator hooks are protected (same seam as RankCredibilityGate) so the
 * unit suite can exercise the real logic without WordPress.
 *
 * @package BCC\Trust\Core\Services
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) {
    exit;
}

class EliteOverridePin
{
    /** Post meta (entity page) holding the pin. */
    public const META_PAGE = '_bcc_admin_elite_override';

    /** User meta (self-page owner) holding the pin for their self-page. */
    public const META_SELF = '_bcc_admin_elite_override_self';

    /** Pin a page elite on behalf of $adminId. */
    public function pin(int $pageId, int $adminId, string $grantedAtMysql): bool
    {
        if ($pageId <= 0 || $adminId <= 0) {
            return false;
        }
        $value = (string) wp_json_encode(['admin_id' => $adminId, 'granted_at' => $grantedAtMysql]);

        return MemberSelfPageService::isSelfPage($pageId)
            ? $this->writeUserMeta(MemberSelfPageService::ownerOfSelfPage($pageId), $value)
            : $this->writePostMeta($pageId, $value);
    }

    /** Remove the pin (undo, override down, admin removal). */
    public function unpin(int $pageId): void
    {
        if ($pageId <= 0) {
            return;
        }
        if (MemberSelfPageService::isSelfPage($pageId)) {
            $this->deleteUserMeta(MemberSelfPageService::ownerOfSelfPage($pageId));
        } else {
            $this->deletePostMeta($pageId);
        }
    }

    /**
     * Is this page manually pinned elite? FAILS CLOSED: a read error, an
     * absent marker and a malformed value all answer false.
     */
    public function isElitePinned(int $pageId): bool
    {
        return $this->read($pageId) !== null;
    }

    /**
     * The pin's {admin_id, granted_at}, or null when absent, unreadable or
     * malformed (fail closed). Used by undo to restore an earlier pin exactly.
     *
     * @return array{admin_id: int, granted_at: string}|null
     */
    public function read(int $pageId): ?array
    {
        if ($pageId <= 0) {
            return null;
        }
        $raw = MemberSelfPageService::isSelfPage($pageId)
            ? $this->readUserMeta(MemberSelfPageService::ownerOfSelfPage($pageId))
            : $this->readPostMeta($pageId);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || (int) ($decoded['admin_id'] ?? 0) <= 0) {
            return null;
        }
        return ['admin_id' => (int) $decoded['admin_id'], 'granted_at' => (string) ($decoded['granted_at'] ?? '')];
    }

    // ── collaborator hooks ──────────────────────────────────────────────

    protected function readPostMeta(int $pageId): mixed
    {
        return get_post_meta($pageId, self::META_PAGE, true);
    }

    protected function writePostMeta(int $pageId, string $value): bool
    {
        return update_post_meta($pageId, self::META_PAGE, $value) !== false
            || $this->readPostMeta($pageId) === $value; // false also means "unchanged"
    }

    protected function deletePostMeta(int $pageId): void
    {
        delete_post_meta($pageId, self::META_PAGE);
    }

    protected function readUserMeta(int $userId): mixed
    {
        return $userId > 0 ? get_user_meta($userId, self::META_SELF, true) : '';
    }

    protected function writeUserMeta(int $userId, string $value): bool
    {
        if ($userId <= 0) {
            return false;
        }
        return update_user_meta($userId, self::META_SELF, $value) !== false
            || $this->readUserMeta($userId) === $value;
    }

    protected function deleteUserMeta(int $userId): void
    {
        if ($userId > 0) {
            delete_user_meta($userId, self::META_SELF);
        }
    }
}
