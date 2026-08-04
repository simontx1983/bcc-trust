<?php
/**
 * Quest Progress Service
 *
 * Tracks quest completion in the bcc_trust_quest_log table, cached in
 * WordPress object cache. Quests are onboarding guidance / achievements
 * only (D-1, Rank Phase 6) — completion grants no vote, Trust, or Rank
 * power.
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
             */
            do_action(
                'bcc_trust_quest_completed',
                $userId,
                $slug
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
     * Check if a user can endorse.
     *
     * Requires exactly ONE identity proof — any of:
     *   - Completed profile (PeepSo fields filled)
     *   - Connected wallet
     *   - Verified GitHub
     *   - Verified X
     *
     * The quest system is onboarding guidance, not an endorsement gate.
     * The endorsement gate only needs proof you're a real person. The
     * account age gate (7 days) in EndorsementService provides the
     * Sybil time-delay.
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
     *     quests: array<string, array{label: string, hint: string, done: bool, completed_at: ?string, unlocks: string[], category: string}>,
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

        foreach ($allQuests as $slug => $quest) {
            $done = isset($completedMap[$slug]);
            if ($done) {
                $completedCount++;
            }

            $quests[$slug] = [
                'label'        => $quest['label'],
                'hint'         => $quest['hint'],
                'done'         => $done,
                'completed_at' => $completedMap[$slug] ?? null,
                'unlocks'      => $quest['unlocks'],
                'category'     => $quest['category'],
            ];
        }

        $total = count($allQuests);

        return [
            'quests'          => $quests,
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
        wp_cache_delete("quest_map_" . BCC_QUEST_CACHE_VER . "_{$userId}", BCC_QUEST_CACHE_GROUP);
    }

    /**
     * Quests whose completion is a durable, cheaply re-checkable server-side
     * fact (a verified wallet/GitHub/X, a filled profile, a cast vote, three
     * explored projects). These can be backfilled by re-running their
     * validator. `share_x` is deliberately excluded — its validator can hit
     * the X API, so it stays signal-driven only.
     */
    private const RECONCILABLE_SLUGS = [
        'connect_wallet',
        'verify_github',
        'verify_x',
        'complete_profile',
        'first_vote',
        'explore_projects',
    ];

    /** Throttle window for the reconcile scan (seconds). */
    private const RECONCILE_TTL = 21600; // 6 hours

    /**
     * Self-heal quests completed before their emitter existed.
     *
     * The signal emitters (wallet-verified, github/x-verified, first vote, …)
     * only fire on NEW actions. A user who did those things earlier has no
     * quest_log row, so their multiplier understates reality. This re-runs the
     * authoritative validator for each durable quest and records any that now
     * pass — idempotent (complete() is INSERT IGNORE) and persisted, so the
     * corrected multiplier also reaches the vote path.
     *
     * Throttled to once per RECONCILE_TTL per user via a transient so the
     * validator sweep never runs on the hot path; new completions are still
     * immediate via their emitters regardless of this throttle. Best-effort:
     * a validator that throws is skipped, never fatal.
     */
    public function reconcile(int $userId): void {
        if ($userId <= 0) {
            return;
        }

        $throttleKey = 'bcc_quest_reconciled_' . $userId;
        if (get_transient($throttleKey)) {
            return;
        }
        set_transient($throttleKey, 1, self::RECONCILE_TTL);

        $completed = $this->getCompletedMap($userId);

        foreach (self::RECONCILABLE_SLUGS as $slug) {
            if (isset($completed[$slug])) {
                continue;
            }
            try {
                if (QuestValidator::validate($userId, $slug)) {
                    $this->complete($userId, $slug);
                }
            } catch (\Throwable $e) {
                // Onchain/PeepSo dependency unavailable — skip this slug.
            }
        }
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
