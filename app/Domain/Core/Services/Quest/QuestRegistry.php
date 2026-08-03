<?php
/**
 * Quest Registry
 *
 * Single source of truth for all available quests. Each quest defines:
 *  - slug: unique identifier, used in DB and action hooks
 *  - label/hint: human-readable text for the frontend
 *  - unlocks: array of capabilities granted (e.g., 'endorsements')
 *  - category: grouping for UI (identity, engagement)
 *
 * D-1 (Rank Phase 6): quests grant NO vote, Trust, or Rank power —
 * the per-quest weight_bonus field and bonusFor() are retired.
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

    /** @var array<string, array{label: string, hint: string, unlocks: string[], category: string}>|null */
    private static ?array $quests = null;

    /**
     * @return array<string, array{label: string, hint: string, unlocks: string[], category: string}>
     */
    public static function all(): array {
        if (self::$quests !== null) {
            return self::$quests;
        }

        return self::$quests = [
            'connect_wallet' => [
                'label'        => 'Connect a Wallet',
                'hint'         => 'Prove your on-chain identity.',
                'unlocks'      => [],
                'category'     => 'identity',
            ],
            'verify_github' => [
                'label'        => 'Verify GitHub Account',
                'hint'         => 'Proves ownership of the code you claim.',
                'unlocks'      => [],
                'category'     => 'identity',
            ],
            'verify_x' => [
                'label'        => 'Verify X Account',
                'hint'         => 'Proves your social identity on X (Twitter).',
                'unlocks'      => [],
                'category'     => 'identity',
            ],
            'share_x' => [
                'label'        => 'Share Profile on X',
                'hint'         => 'Spread the word and help grow the community.',
                'unlocks'      => [],
                'category'     => 'engagement',
            ],
            'complete_profile' => [
                'label'        => 'Complete Your Profile',
                'hint'         => 'Fill in your bio, avatar, and links.',
                'unlocks'      => [],
                'category'     => 'engagement',
            ],
            'first_vote' => [
                'label'        => 'Cast Your First Vote',
                'hint'         => 'Participate in the trust system.',
                'unlocks'      => [],
                'category'     => 'engagement',
            ],
            'explore_projects' => [
                'label'        => 'Explore 3 Projects',
                'hint'         => 'Browse and evaluate real projects.',
                'unlocks'      => [],
                'category'     => 'engagement',
            ],
        ];
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
