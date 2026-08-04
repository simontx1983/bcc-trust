<?php
/**
 * MentorListingService — composes the §21.4 mentor listing (Rank
 * redesign Phase 7).
 *
 * Listing = explicit opt-in AND live can('list_as_mentor') — Veteran
 * AND tier ≥ Trusted AND not suspended AND not in Rank recovery. The
 * eligibility half is NEVER materialized: when any condition fails the
 * listing pauses on the next read, with no sweep and no flag to
 * un-stick (spec §21.4 "listing pauses live").
 *
 * Opt-in storage: `bcc_mentor_opt_in` wp_usermeta, '1'/'0' — the same
 * bounded meta-flag idiom as NotificationPrefs. Opting in requires NO
 * eligibility (a non-Veteran may pre-opt-in; they list automatically
 * the day they qualify). Surface: MyProfilePrefsEndpoint (read/write)
 * and UsersEndpoint's `mentors=1` directory filter (enumeration).
 *
 * @package BCC\Trust\Core\Services
 * @since Rank redesign Phase 7 (2026-08-04)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) {
    exit;
}

class MentorListingService
{
    /** wp_usermeta key for the explicit mentor opt-in ('1'/'0'). */
    public const META_OPT_IN = 'bcc_mentor_opt_in';

    /**
     * Enumeration ceiling for the directory candidate pool. Mentor
     * candidacy requires Veteran (months of evidence) — hundreds of
     * simultaneous opt-ins is already generous headroom; revisit with
     * pagination if a real install approaches it.
     */
    public const CANDIDATE_LIMIT = 500;

    public function isOptedIn(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        return get_user_meta($userId, self::META_OPT_IN, true) === '1';
    }

    public function setOptIn(int $userId, bool $optIn): void
    {
        if ($userId <= 0) {
            return;
        }
        update_user_meta($userId, self::META_OPT_IN, $optIn ? '1' : '0');
    }

    /**
     * The viewer-facing listing state: whether the user is actively
     * listed right now, and — when eligibility fails — the resolver's
     * stable deny reason so the frontend can explain the pause
     * (below_veteran / below_trusted / in_recovery / suspended /
     * new_member). `reason` is null while eligible, regardless of
     * opt-in (a not-opted-in-but-eligible user just sees the toggle).
     *
     * @return array{opted_in: bool, listed: bool, reason: string|null}
     */
    public function listingFor(int $userId): array
    {
        $optedIn  = $this->isOptedIn($userId);
        $decision = $this->capability($userId, 'list_as_mentor');

        return [
            'opted_in' => $optedIn,
            'listed'   => $optedIn && $decision->isAllowed(),
            'reason'   => $decision->isAllowed() ? null : $decision->reason,
        ];
    }

    /**
     * User ids actively listed as mentors RIGHT NOW: the bounded
     * opt-in enumeration filtered through the live eligibility key.
     * Per-candidate resolver evaluation is acceptable because the
     * candidate pool is the opt-in set (bounded above), not the member
     * base — and each evaluation is a handful of PK reads.
     *
     * @return list<int>
     */
    public function activeMentorIds(): array
    {
        $out = [];
        foreach ($this->optedInUserIds() as $userId) {
            if ($this->capability($userId, 'list_as_mentor')->isAllowed()) {
                $out[] = $userId;
            }
        }
        return $out;
    }

    /**
     * Which of `$userIds` are actively listed mentors right now? Built
     * for list surfaces (/members rows): ONE bounded opt-in enumeration
     * + live capability checks only for the (typically empty)
     * intersection — never a per-row meta read.
     *
     * @param list<int> $userIds
     * @return array<int, true> user_id => true for listed mentors
     */
    public function listedAmong(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $optedIn = array_fill_keys($this->optedInUserIds(), true);

        $out = [];
        foreach ($userIds as $userId) {
            if (isset($optedIn[$userId]) && $this->capability($userId, 'list_as_mentor')->isAllowed()) {
                $out[$userId] = true;
            }
        }
        return $out;
    }

    // ── collaborator hooks (overridden by MentorListingServiceTest) ──

    /**
     * Bounded opt-in enumeration. Reuses the generic usermeta-flag
     * reader (NotificationRepository::findUserIdsByMetaFlag — meta_key
     * + meta_value='1', LIMIT-bounded) rather than duplicating the SQL;
     * the method is key-agnostic despite living on the notification
     * repository.
     *
     * @return list<int>
     */
    protected function optedInUserIds(): array
    {
        return \BCC\Trust\Core\Plugin::instance()
            ->notificationRepository()
            ->findUserIdsByMetaFlag(self::META_OPT_IN, self::CANDIDATE_LIMIT);
    }

    protected function capability(int $userId, string $key): \BCC\Trust\Core\ValueObjects\CapabilityDecision
    {
        return \BCC\Trust\Core\Plugin::instance()->capabilityResolver()->can($userId, $key);
    }
}
