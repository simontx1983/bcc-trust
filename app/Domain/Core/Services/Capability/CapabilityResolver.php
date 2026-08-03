<?php
/**
 * CapabilityResolver — the single authorization entry point (approved
 * plan §25), AUTHORITATIVE since the Phase 5 atomic cutover.
 *
 * Contract:
 *   - implements NO parallel rules — every definitive verdict comes
 *     from delegating to the canonical gate (§11: no parallel rule
 *     implementations). See CapabilityCatalog for the per-key map.
 *   - fails closed: unknown keys, unauthenticated principals, missing
 *     context, and unbuilt features all resolve DENY or UNKNOWN — never
 *     ALLOWED without a delegate saying so. Enforcing callers must
 *     treat UNKNOWN as DENY.
 *
 * The write_review delegate carries the Phase 5 final policy directly
 * (Apprentice+ AND Neutral+ — see writeReviewGate); vouch/stand_behind
 * delegate to AttestationService::checkCastEligibility, which carries
 * the New-Member exclusion inside it. The post-cutover authorization
 * matrix (tests/Unit/CapabilityMatrixTest.php) pins the routing.
 *
 * Delegates are resolved lazily through protected hooks (overridden in
 * the matrix test) so the resolver itself stays dependency-free.
 *
 * @package BCC\Trust\Core\Services\Capability
 * @since Rank redesign Phase 3 (2026-07-31); authoritative Phase 5
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Capability;

use BCC\Trust\Core\Services\AttestationException;
use BCC\Trust\Core\ValueObjects\CapabilityDecision;

if (!defined('ABSPATH')) {
    exit;
}

class CapabilityResolver
{
    /**
     * Resolve one capability for one principal. Context keys are
     * capability-specific (documented per branch below); missing
     * required context yields UNKNOWN, never a guess.
     *
     * @param array<string, mixed> $context
     */
    public function can(int $userId, string $key, array $context = []): CapabilityDecision
    {
        if (!CapabilityCatalog::isKnown($key)) {
            return CapabilityDecision::denied('unknown_capability', 'fail_closed');
        }

        if ($userId <= 0) {
            return CapabilityDecision::denied('not_authenticated', 'fail_closed');
        }

        switch ($key) {
            case CapabilityCatalog::WRITE_REVIEW:
                return $this->writeReviewGate($userId)
                    ? CapabilityDecision::allowed('feature_access')
                    : CapabilityDecision::denied('feature_gate', 'feature_access');

            case CapabilityCatalog::VOUCH:
            case CapabilityCatalog::STAND_BEHIND:
                return $this->resolveAttestation($userId, $key, $context);

            case CapabilityCatalog::OPEN_DISPUTE:
                return $this->resolveOpenDispute($userId, $context);

            case CapabilityCatalog::CAST_MEANINGFUL_VOTE:
            case CapabilityCatalog::DOWNVOTE:
                // VoteEligibilityChecker::check consumes rate-limit quota
                // — not shadowable. The live gate stays the only judge.
                return CapabilityDecision::unknown('deferred_to_live_gate', 'live_gate');

            default:
                // FUTURE keys (incl. cast_dispute_vote — known documented
                // divergence vs the legacy panel route until Phase 6
                // retires it; see CapabilityCatalog header).
                return CapabilityDecision::denied('feature_not_built', 'future');
        }
    }

    /**
     * vouch / stand_behind — context: target_kind (string), target_id (int).
     *
     * @param array<string, mixed> $context
     */
    private function resolveAttestation(int $userId, string $kind, array $context): CapabilityDecision
    {
        $targetKind = isset($context['target_kind']) && is_string($context['target_kind'])
            ? $context['target_kind']
            : '';
        $targetId = isset($context['target_id']) ? (int) $context['target_id'] : 0;

        if ($targetKind === '' || $targetId <= 0) {
            return CapabilityDecision::unknown('missing_context', 'attestation_gate');
        }

        try {
            $this->attestationGate($userId, $targetKind, $targetId, $kind);
        } catch (AttestationException $e) {
            return CapabilityDecision::denied($e->errorCode, 'attestation_gate');
        }

        return CapabilityDecision::allowed('attestation_gate');
    }

    /**
     * open_dispute — context: page_id (int). Mirrors the DisputeController
     * chain: not-suspended (permission callback) + affected-party
     * (Permissions::owns_page). Rank never blocks opening (§20.4).
     *
     * @param array<string, mixed> $context
     */
    private function resolveOpenDispute(int $userId, array $context): CapabilityDecision
    {
        if (!$this->notSuspended($userId)) {
            return CapabilityDecision::denied('suspended', 'permissions');
        }

        $pageId = isset($context['page_id']) ? (int) $context['page_id'] : 0;
        if ($pageId <= 0) {
            return CapabilityDecision::unknown('missing_context', 'permissions');
        }

        return $this->ownsPage($pageId, $userId)
            ? CapabilityDecision::allowed('permissions')
            : CapabilityDecision::denied('not_affected_party', 'permissions');
    }

    // ── delegate hooks (overridden by the authorization matrix test) ──

    /**
     * Rank Phase 5 final policy: Apprentice-or-higher (a rank_state row
     * exists — New Members excluded, fail-safe on the missing row) AND
     * current Trust Neutral-or-higher. Journeyman is never required
     * (§20.1). Replaced the legacy Level-2 FeatureAccessService gate at
     * the atomic cutover.
     */
    protected function writeReviewGate(int $userId): bool
    {
        $plugin = \BCC\Trust\Core\Plugin::instance();

        if ($plugin->rankStateRepository()->getForUser($userId) === null) {
            return false;
        }

        $config = $plugin->rankScoringConfig();
        $tier   = $plugin->reputationRepository()->getTier($userId);

        return $config->tierOrdFor($tier) >= $config->tierOrdFor('neutral');
    }

    /** @throws AttestationException when ineligible */
    protected function attestationGate(int $userId, string $targetKind, int $targetId, string $kind): void
    {
        \BCC\Trust\Core\Plugin::instance()
            ->attestationService()
            ->checkCastEligibility($userId, $targetKind, $targetId, $kind);
    }

    protected function notSuspended(int $userId): bool
    {
        return \BCC\Core\Permissions\Permissions::is_not_suspended($userId, false);
    }

    protected function ownsPage(int $pageId, int $userId): bool
    {
        return \BCC\Core\Permissions\Permissions::owns_page($pageId, $userId);
    }
}
