<?php
/**
 * Quest Progress Service
 *
 * Tracks quest completion and computes the quest multiplier for a user.
 * The multiplier is derived (not stored) from completed quests in the
 * bcc_trust_quest_log table, cached in WordPress object cache.
 *
 * Completion signals arrive via action hooks from any plugin:
 *   do_action('bcc_trust_quest_signal', $userId, 'connect_wallet');
 *
 * This service listens and records completions idempotently (UNIQUE key
 * on user_id + quest_slug prevents duplicates).
 *
 * @package BCC\Trust\Core\Services\Quest
 */

namespace BCC\Trust\Core\Services\Quest;

use BCC\Trust\Core\Repositories\QuestProgressRepository;

if (!defined('ABSPATH')) {
    exit;
}

class QuestProgressService {

    private QuestProgressRepository $repo;

    public function __construct(?QuestProgressRepository $repo = null) {
        $this->repo = $repo ?? new QuestProgressRepository();
    }

    /**
     * Record quest completion for a user. Idempotent — duplicate signals
     * are silently ignored via INSERT IGNORE (UNIQUE key on user+slug).
     *
     * @return bool True if this was a NEW completion, false if already done.
     */
    public function complete(int $userId, string $slug): bool {
        if (!in_array($slug, QuestRegistry::slugs(), true)) {
            return false;
        }

        $isNew = $this->repo->insertCompletion($userId, $slug);

        if ($isNew) {
            $this->invalidateCache($userId);

            /**
             * Fires when a user completes a quest for the first time.
             *
             * @param int    $userId     The user who completed the quest.
             * @param string $slug       The quest slug.
             * @param float  $multiplier The user's new quest multiplier.
             */
            do_action(
                'bcc_trust_quest_completed',
                $userId,
                $slug,
                $this->getMultiplier($userId)
            );
        }

        return $isNew;
    }

    /**
     * Revoke a quest completion when the underlying action is reversed.
     * Examples: wallet disconnect, GitHub unlink, fraud flag.
     *
     * @return bool True if the quest was actually revoked, false if it wasn't completed.
     */
    public function revoke(int $userId, string $slug): bool {
        $revoked = $this->repo->deleteCompletion($userId, $slug);

        if ($revoked) {
            $this->invalidateCache($userId);

            do_action('bcc_trust_quest_revoked', $userId, $slug);
        }

        return $revoked;
    }

    /**
     * Get the quest multiplier for a user.
     *
     * multiplier = 1.0 + sum(bonus for each completed quest)
     * Cached in object cache for BCC_QUEST_CACHE_TTL seconds.
     *
     * @return float Between BCC_QUEST_MULTIPLIER_BASE (1.0) and BCC_QUEST_MULTIPLIER_MAX (1.30).
     */
    public function getMultiplier(int $userId): float {
        $cacheKey = "quest_mult_" . BCC_QUEST_CACHE_VER . "_{$userId}";
        $cached   = wp_cache_get($cacheKey, BCC_QUEST_CACHE_GROUP);

        if ($cached !== false) {
            return (float) $cached;
        }

        $completed  = $this->getCompletedSlugs($userId);
        $multiplier = BCC_QUEST_MULTIPLIER_BASE;

        foreach ($completed as $slug) {
            $multiplier += QuestRegistry::bonusFor($slug);
        }

        $multiplier = min($multiplier, BCC_QUEST_MULTIPLIER_MAX);

        wp_cache_set($cacheKey, $multiplier, BCC_QUEST_CACHE_GROUP, BCC_QUEST_CACHE_TTL);

        return $multiplier;
    }

    /**
     * Check if a user can endorse.
     *
     * Requires exactly ONE identity proof — any of:
     *   - Completed profile (PeepSo fields filled)
     *   - Connected wallet
     *   - Verified GitHub
     *   - Verified X
     *
     * The quest system is a progression reward (vote weight multiplier),
     * not an endorsement gate. The endorsement gate only needs proof
     * you're a real person. The account age gate (7 days) in
     * EndorsementService provides the Sybil time-delay.
     */
    public function canEndorse(int $userId): bool {
        $progress = $this->getEndorseProgress($userId);
        return $progress['unlocked'];
    }

    /**
     * Get endorsement unlock progress for a user.
     *
     * @return array{unlocked: bool, completed: int, total: int, has_identity: bool, missing_reason: string|null}
     */
    public function getEndorseProgress(int $userId): array {
        $completed      = $this->getCompletedSlugs($userId);
        $count          = count($completed);
        $total          = count(QuestRegistry::all());
        $identitySlugs  = ['complete_profile', 'connect_wallet', 'verify_github', 'verify_x'];
        $hasIdentity    = !empty(array_intersect($completed, $identitySlugs));

        $reason = null;
        if (!$hasIdentity) {
            $reason = 'Verify your identity to unlock endorsements — complete your profile, connect a wallet, or verify GitHub/X.';
        }

        return [
            'unlocked'       => $hasIdentity,
            'completed'      => $count,
            'total'          => $total,
            'has_identity'   => $hasIdentity,
            'missing_reason' => $reason,
        ];
    }

    /**
     * Get full progress for a user (used by REST endpoint / frontend).
     *
     * @return array{
     *     quests: array<string, array{label: string, hint: string, done: bool, completed_at: ?string, weight_bonus: float, unlocks: string[], category: string}>,
     *     multiplier: float,
     *     completed_count: int,
     *     total_count: int,
     *     pct: int
     * }
     */
    public function getProgress(int $userId): array {
        $completedMap = $this->getCompletedMap($userId);
        $allQuests    = QuestRegistry::all();

        $quests         = [];
        $completedCount = 0;
        $bonusSum       = 0.0;

        foreach ($allQuests as $slug => $quest) {
            $done = isset($completedMap[$slug]);
            if ($done) {
                $completedCount++;
                $bonusSum += $quest['weight_bonus'];
            }

            $quests[$slug] = [
                'label'        => $quest['label'],
                'hint'         => $quest['hint'],
                'done'         => $done,
                'completed_at' => $completedMap[$slug] ?? null,
                'weight_bonus' => $quest['weight_bonus'],
                'unlocks'      => $quest['unlocks'],
                'category'     => $quest['category'],
            ];
        }

        $total      = count($allQuests);
        $multiplier = min(BCC_QUEST_MULTIPLIER_BASE + $bonusSum, BCC_QUEST_MULTIPLIER_MAX);

        return [
            'quests'          => $quests,
            'multiplier'      => $multiplier,
            'completed_count' => $completedCount,
            'total_count'     => $total,
            'pct'             => $total > 0 ? (int) round(($completedCount / $total) * 100) : 0,
        ];
    }

    /**
     * Get completed quest slugs for a user (cached).
     *
     * @return string[]
     */
    private function getCompletedSlugs(int $userId): array {
        return array_keys($this->getCompletedMap($userId));
    }

    /**
     * Get completed quests as slug => completed_at map (cached).
     *
     * @return array<string, string>
     */
    private function getCompletedMap(int $userId): array {
        $cacheKey = "quest_map_" . BCC_QUEST_CACHE_VER . "_{$userId}";
        $cached   = wp_cache_get($cacheKey, BCC_QUEST_CACHE_GROUP);

        if (is_array($cached)) {
            return $cached;
        }

        $map = $this->repo->getCompletedMap($userId);

        wp_cache_set($cacheKey, $map, BCC_QUEST_CACHE_GROUP, BCC_QUEST_CACHE_TTL);

        return $map;
    }

    /**
     * Invalidate all cached quest data for a user.
     */
    public function invalidateCache(int $userId): void {
        wp_cache_delete("quest_mult_" . BCC_QUEST_CACHE_VER . "_{$userId}", BCC_QUEST_CACHE_GROUP);
        wp_cache_delete("quest_map_" . BCC_QUEST_CACHE_VER . "_{$userId}", BCC_QUEST_CACHE_GROUP);
    }

    // -------------------------------------------------------------------------
    // Hook listeners — wired in boot file
    // -------------------------------------------------------------------------

    /**
     * Handle quest signal from any plugin.
     * Called via: do_action('bcc_trust_quest_signal', $userId, $slug)
     *
     * For quests that require server-side validation (complete_profile,
     * explore_projects), the validator checks that the user genuinely
     * completed the action before awarding the quest. This prevents
     * rogue plugins from gaming the quest multiplier via hook injection.
     */
    public function onQuestSignal(int $userId, string $slug): void {
        if ($userId <= 0) {
            return;
        }

        // Enforce server-side validation for gameable quests.
        if (QuestValidator::requiresValidation($slug)) {
            if (!QuestValidator::validate($userId, $slug)) {
                return;
            }
        }

        $this->complete($userId, $slug);
    }

    /**
     * Auto-complete first_vote quest when a vote is cast.
     * Hooked to bcc_trust_vote_cast which fires ($voterId, $pageId, $categoryId).
     * Third param is categoryId — unused here.
     */
    public function onVoteCast(int $voterId, int $pageId, int $categoryId): void {
        $this->complete($voterId, 'first_vote');
    }
}
