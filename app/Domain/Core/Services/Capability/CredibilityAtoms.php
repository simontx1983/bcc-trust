<?php
/**
 * CredibilityAtoms — the shared atomic reads behind every "is this member
 * credible enough?" gate (§9.2 / §10.1 / §20.1 / §21.2).
 *
 * These six predicates were copy-pasted verbatim between
 * `RankCredibilityGate` (the helping-emitter credibility SSoT) and
 * `CapabilityResolver` (the write/custody capability gates). Lifting them
 * into ONE trait (§11) means the suspension / rank-presence / tier /
 * recovery / fraud rules each have a single definition; the composite
 * gates stay in their own classes and compose these atoms.
 *
 * Every method is `protected` so the existing subclass-override test
 * seam is preserved unchanged — `RankCredibilityGateTest` and
 * `CapabilityMatrixTest` script these reads on anonymous subclasses, and
 * a trait method is overridden by the using class's subclass exactly as
 * an inherited method would be.
 *
 * Collaborators are reached through `Plugin::instance()` (same as the
 * originals), so the trait carries no constructor dependencies and can be
 * mixed into any service that already lives inside the plugin.
 *
 * @package BCC\Trust\Core\Services\Capability
 * @since Rank redesign (credibility-predicate consolidation)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Capability;

if (!defined('ABSPATH')) {
    exit;
}

trait CredibilityAtoms
{
    /**
     * Rungs in ladder order — mirrors RankScoringConfig::RANKS
     * (apprentice < journeyman < veteran; Master must never appear,
     * §3.2). A method rather than a constant because PHP 8.2 traits
     * cannot declare constants; unknown slugs fail closed at the call
     * site via the null-coalesced lookup.
     *
     * @return array<string, int>
     */
    protected function rankOrderMap(): array
    {
        return ['apprentice' => 0, 'journeyman' => 1, 'veteran' => 2];
    }

    /** Not suspended — no admin bypass (the `false` is load-bearing). */
    protected function notSuspended(int $userId): bool
    {
        return \BCC\Core\Permissions\Permissions::is_not_suspended($userId, false);
    }

    /** Apprentice+ — a rank_state row exists (New Members have none). */
    protected function hasRankState(int $userId): bool
    {
        return \BCC\Trust\Core\Plugin::instance()
            ->rankStateRepository()
            ->getForUser($userId) !== null;
    }

    /**
     * Is the user's rank at least $slug? Missing rank_state row = New
     * Member = false (fail-safe); unknown $slug can never be satisfied.
     */
    protected function rankAtLeast(int $userId, string $slug): bool
    {
        $order    = $this->rankOrderMap();
        $required = $order[$slug] ?? null;
        if ($required === null) {
            return false;
        }

        $row = \BCC\Trust\Core\Plugin::instance()->rankStateRepository()->getForUser($userId);
        if ($row === null) {
            return false;
        }

        $actual = $order[(string) $row->rank_slug] ?? null;
        return $actual !== null && $actual >= $required;
    }

    /**
     * Is the user's current trust tier at least $tierSlug? Tier via
     * ReputationRepository::getTier (canonical), ordinals via the
     * validated rank-scoring config (tierOrdFor).
     */
    protected function tierAtLeast(int $userId, string $tierSlug): bool
    {
        $plugin = \BCC\Trust\Core\Plugin::instance();
        $config = $plugin->rankScoringConfig();
        $tier   = $plugin->reputationRepository()->getTier($userId);

        return $config->tierOrdFor($tier) >= $config->tierOrdFor($tierSlug);
    }

    /**
     * §14.2 recovery pause — rank_state.recovery_status === 'grace'.
     * Missing row = not in recovery.
     */
    protected function inRecovery(int $userId): bool
    {
        $row = \BCC\Trust\Core\Plugin::instance()->rankStateRepository()->getForUser($userId);
        return $row !== null && (string) $row->recovery_status === 'grace';
    }

    /**
     * fraud_score below the HIGH block threshold (single fraud rule,
     * §11): a missing user_info row is fraud-clear, and the automated-
     * test bypass lets fresh test accounts whose score isn't computed
     * yet through.
     */
    protected function fraudClear(int $userId): bool
    {
        if (defined('BCC_TRUST_TEST_MODE') && \BCC_TRUST_TEST_MODE) {
            return true;
        }
        $info = \BCC\Trust\Core\Plugin::instance()->userInfoRepository()->getByUserId($userId);
        if ($info === null) {
            return true;
        }
        $fraudScore = isset($info->fraud_score) ? (int) $info->fraud_score : 0;
        return !(defined('BCC_TRUST_FRAUD_HIGH') && $fraudScore >= (int) \BCC_TRUST_FRAUD_HIGH);
    }
}
