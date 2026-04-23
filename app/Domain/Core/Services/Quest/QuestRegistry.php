<?php
/**
 * Quest Registry
 *
 * Single source of truth for all available quests. Each quest defines:
 *  - slug: unique identifier, used in DB and action hooks
 *  - label/hint: human-readable text for the frontend
 *  - weight_bonus: added to quest_multiplier on completion
 *  - unlocks: array of capabilities granted (e.g., 'endorsements')
 *  - category: grouping for UI (identity, engagement)
 *
 * Quests are defined in code, not the database. Adding a new quest is a
 * single array entry — the table just tracks completion timestamps.
 *
 * @package BCC\Trust\Core\Services\Quest
 */

namespace BCC\Trust\Core\Services\Quest;

if (!defined('ABSPATH')) {
    exit;
}

class QuestRegistry {

    /** @var array<string, array{label: string, hint: string, weight_bonus: float, unlocks: string[], category: string}>|null */
    private static ?array $quests = null;

    /**
     * @return array<string, array{label: string, hint: string, weight_bonus: float, unlocks: string[], category: string}>
     */
    public static function all(): array {
        if (self::$quests !== null) {
            return self::$quests;
        }

        return self::$quests = [
            'connect_wallet' => [
                'label'        => 'Connect a Wallet',
                'hint'         => 'Prove on-chain identity for higher credibility.',
                'weight_bonus' => BCC_QUEST_BONUS_CONNECT_WALLET,
                'unlocks'      => [],
                'category'     => 'identity',
            ],
            'verify_github' => [
                'label'        => 'Verify GitHub Account',
                'hint'         => 'Proves code ownership — boosts your vote weight.',
                'weight_bonus' => BCC_QUEST_BONUS_VERIFY_GITHUB,
                'unlocks'      => [],
                'category'     => 'identity',
            ],
            'verify_x' => [
                'label'        => 'Verify X Account',
                'hint'         => 'Proves your social identity on X (Twitter).',
                'weight_bonus' => BCC_QUEST_BONUS_VERIFY_X,
                'unlocks'      => [],
                'category'     => 'identity',
            ],
            'share_x' => [
                'label'        => 'Share Profile on X',
                'hint'         => 'Spread the word and help grow the community.',
                'weight_bonus' => BCC_QUEST_BONUS_SHARE_X,
                'unlocks'      => [],
                'category'     => 'engagement',
            ],
            'complete_profile' => [
                'label'        => 'Complete Your Profile',
                'hint'         => 'Fill in your bio, avatar, and links.',
                'weight_bonus' => BCC_QUEST_BONUS_COMPLETE_PROFILE,
                'unlocks'      => [],
                'category'     => 'engagement',
            ],
            'first_vote' => [
                'label'        => 'Cast Your First Vote',
                'hint'         => 'Participate in the trust system.',
                'weight_bonus' => BCC_QUEST_BONUS_FIRST_VOTE,
                'unlocks'      => [],
                'category'     => 'engagement',
            ],
            'explore_projects' => [
                'label'        => 'Explore 3 Projects',
                'hint'         => 'Browse and evaluate real projects.',
                'weight_bonus' => BCC_QUEST_BONUS_EXPLORE_PROJECTS,
                'unlocks'      => [],
                'category'     => 'engagement',
            ],
        ];
    }

    /**
     * Get the weight bonus for a specific quest slug.
     */
    public static function bonusFor(string $slug): float {
        return self::all()[$slug]['weight_bonus'] ?? 0.0;
    }

    /**
     * Get all quest slugs.
     *
     * @return string[]
     */
    public static function slugs(): array {
        return array_keys(self::all());
    }

    /**
     * Check if a quest slug unlocks a specific capability.
     */
    public static function unlocks(string $slug, string $capability): bool {
        $quest = self::all()[$slug] ?? null;
        return $quest && in_array($capability, $quest['unlocks'], true);
    }

}
