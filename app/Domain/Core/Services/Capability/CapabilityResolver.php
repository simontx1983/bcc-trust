<?php
/**
 * CapabilityResolver — the future single authorization entry point
 * (approved plan §25), shipped SHADOW-ONLY in Phase 3 (Addendum A2).
 *
 * Phase 3 contract:
 *   - implements NO rules of its own — every definitive verdict comes
 *     from delegating to today's canonical gate (§11: no parallel rule
 *     implementations). See CapabilityCatalog for the per-key map.
 *   - enforces NOTHING. Live gates remain authoritative everywhere;
 *     CapabilityShadow compares and logs, and can never change an
 *     allow/deny result.
 *   - fails closed: unknown keys, unauthenticated principals, missing
 *     context, and unbuilt features all resolve DENY or UNKNOWN — never
 *     ALLOWED without a delegate saying so.
 *
 * The Phase 5 atomic cutover replaces the delegate bodies with the new
 * Rank semantics and starts enforcing resolver output; the pre-cutover
 * matrix (tests/Unit/CapabilityMatrixTest.php) pins today's verdicts so
 * that flip is visible as exactly one fixture change.
 *
 * Delegates are resolved lazily through protected hooks (overridden in
 * the matrix test) so the resolver itself stays dependency-free.
 *
 * @package BCC\Trust\Core\Services\Capability
 * @since Rank redesign Phase 3 (2026-07-31)
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

    // ── delegate hooks (overridden by the pre-cutover matrix test) ──

    protected function writeReviewGate(int $userId): bool
    {
        $access = \BCC\Trust\Core\Plugin::instance()
            ->featureAccessService()
            ->canPerform($userId, 'write_review');

        return ($access['allowed'] ?? false) === true;
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
