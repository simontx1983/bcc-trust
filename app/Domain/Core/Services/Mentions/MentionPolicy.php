<?php
declare(strict_types=1);

/**
 * MentionPolicy — privacy gate for @-mentions, write-side.
 *
 * Wraps PeepSoUserSearch's privacy filter set so that mention-target
 * acceptance is decided through the SAME query path as the
 * /users/mention-search dropdown (§4.4) — single source of truth, no
 * "approved by picker, rejected by writer" surprises and no parallel
 * privacy rules to drift between dropdown and write-time.
 *
 * Rejected categories (NEVER exposed to the caller — privacy posture
 * per §3.3.12):
 *   - banned users (`acc.usr_role` ∈ register/verified/ban)
 *   - PRIVATE-profile users (`usr_profile_acc = 40`)
 *   - members-only users when viewer is anon (`usr_profile_acc = 20`)
 *   - bidirectional `peepso_user_blocked` (viewer→target AND target→viewer,
 *     gated on `user_blocking_enable`)
 *   - hidden via `peepso_is_hide_profile_from_user_listing` user-meta
 *     (gated on `allow_hide_user_from_user_listing`)
 *   - BCC `bcc_privacy_discovery_optout` (PrivacySettings hooks
 *     `peepso_user_search_args` to splice this in — inherited for free)
 *
 * Strict-reject: filterMentionable returns ONLY the surviving ids.
 * Callers use array_diff against their input to find the rejected
 * id(s) for the `bcc_invalid_mention_target` error payload (echoes
 * the offending id but NOT the failure category).
 *
 * @package BCC\Trust\Core\Services\Mentions
 * @since v1.5 (Phase 1d)
 */

namespace BCC\Trust\Core\Services\Mentions;

if (!defined('ABSPATH')) {
    exit;
}

final class MentionPolicy
{
    /**
     * Return the subset of $candidateIds that are mentionable from
     * $viewerId's perspective. Empty when no candidate passes — or
     * when PeepSo isn't installed (we fail-closed: no PeepSo → no
     * privacy filter → reject all rather than risk leaking).
     *
     * Order is preserved relative to $candidateIds; duplicates are
     * collapsed (downstream callers want one error per mention even
     * if the same id appears twice).
     *
     * @param list<int> $candidateIds
     * @return list<int>
     */
    public static function filterMentionable(int $viewerId, array $candidateIds): array
    {
        if ($candidateIds === []) {
            return [];
        }
        if (!class_exists('PeepSoUserSearch')) {
            return [];
        }

        $unique = [];
        foreach ($candidateIds as $id) {
            $idInt = (int) $id;
            if ($idInt > 0) {
                $unique[$idInt] = true;
            }
        }
        if ($unique === []) {
            return [];
        }
        $uniqueIds = array_keys($unique);

        // PeepSoUserSearch always force-sets fields=ID in its constructor
        // and applies the canonical privacy filter set in pre_user_query.
        // Passing `include` constrains the WP_User_Query to ONLY these
        // candidate ids — the filter set then strips out the
        // banned/blocked/hidden ones, returning exactly the surviving
        // subset. Bounded by count($uniqueIds), so the query is cheap
        // even on a 100k-user site.
        $search = new \PeepSoUserSearch(
            [
                'include' => $uniqueIds,
                'number'  => count($uniqueIds),
            ],
            $viewerId > 0 ? $viewerId : null,
            ''
        );

        /** @var list<int|numeric-string> $rawResults */
        $rawResults = $search->results;
        $allowed = [];
        foreach ($rawResults as $row) {
            $idInt = (int) $row;
            if ($idInt > 0) {
                $allowed[$idInt] = true;
            }
        }

        $out = [];
        $emitted = [];
        foreach ($candidateIds as $id) {
            $idInt = (int) $id;
            if ($idInt > 0 && isset($allowed[$idInt]) && !isset($emitted[$idInt])) {
                $out[] = $idInt;
                $emitted[$idInt] = true;
            }
        }
        return $out;
    }
}
