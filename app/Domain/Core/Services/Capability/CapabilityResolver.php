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
    use CredibilityAtoms;

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
            case CapabilityCatalog::CAST_DISPUTE_VOTE:
                // Participation paths opt out of the suspension admin-bypass
                // (Permissions::is_not_suspended intent) — a suspended
                // member, admin included, cannot cast a peer-trust signal.
                // Mirrors the open_dispute gate below + DisputeVoteService.
                if (!$this->notSuspended($userId)) {
                    return CapabilityDecision::denied('suspended', 'permissions');
                }
                // Same Phase 5 final policy shape for both write actions:
                // Apprentice+ (rank_state row) AND Trust Neutral+. The
                // dispute-vote live gate (DisputeVoteService) additionally
                // enforces §18 party exclusion + fraud hard-block per
                // dispute — this key answers the feature-level question.
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

            case CapabilityCatalog::CREATE_COMMUNITY:
                // §21.2 — the full custody gate (rank/tier/recovery + cap +
                // cooldown). Transfer-away is deliberately looser (below):
                // the cooldown blocks ACQUIRING custody, never
                // relinquishing it.
                return $this->resolveCustodyAcquisition($userId);

            case CapabilityCatalog::RECEIVE_COMMUNITY:
                // ⚠⚠ THE SAME GATE, BUT THE COUNT IS READ FIRST — and the
                // reason is disclosure, not ordering taste.
                //
                // This decision is evaluated FOR the receiver and reported
                // to the GIVER, who must never learn the receiver's status;
                // every real sub-reason therefore collapses to the single
                // generic `receiver_ineligible`. But `unavailable` does NOT
                // collapse, because it is retryable rather than a refusal.
                // So with the count read AFTER the status gates, a count
                // fault would show the giver:
                //
                //   403 receiver_ineligible — receiver fails a status gate
                //   503 unavailable         — receiver passes all of them
                //
                // which is exactly the status probe the collapse exists to
                // close, open for the length of the fault. Reading the
                // count FIRST makes `unavailable` independent of the
                // receiver: during a fault every receiver gets the same
                // 503, whatever their status.
                //
                // ⚠ Create keeps its own order, which the contract
                // documents as the gate sequence. There is no third party
                // to disclose to there — the reasons are the caller's own.
                return $this->resolveCustodyAcquisition($userId, countBeforeStatusGates: true);

            case CapabilityCatalog::TRANSFER_COMMUNITY:
                return $this->resolveTransferCommunity($userId);

            case CapabilityCatalog::POST_RANKED_HALL_FEED:
                return $this->resolvePostRankedHallFeed($userId);

            case CapabilityCatalog::LIST_AS_MENTOR:
                return $this->resolveListAsMentor($userId);

            default:
                // FUTURE keys — no such feature exists yet; fail closed.
                // receive_rank_vote_multiplier stays here on purpose
                // (Rank Phase 7): the multiplier policy already lives in
                // the §16.6 weight formula, and its recovery-pause value
                // is a Phase 8 owner decision — moving the key out of
                // this branch before that decision would invent policy.
                return CapabilityDecision::denied('feature_not_built', 'future');
        }
    }

    // ── Rank Phase 7 community-privilege gates (§21.2–§21.4, §14.2) ──
    //
    // Deny reasons are STABLE snake_case vocabulary (surfaced to the
    // frontend via error.data.reason); the FIRST failing gate wins and
    // the order below is spec-locked. Delegates (rankAtLeast /
    // tierAtLeast / inRecovery / custodyState / notSuspended) are
    // protected hooks so CapabilityMatrixTest can subclass; the
    // comparison math over custodyState's facts runs HERE and is
    // exercised by the matrix directly.

    /**
     * create_community / receive_community — §21.2 acquisition gate:
     * Apprentice+ AND Neutral+ AND not suspended AND not in recovery
     * AND under the per-rank ownership cap AND outside the 30-day
     * global custody cooldown.
     */
    private function resolveCustodyAcquisition(
        int $userId,
        bool $countBeforeStatusGates = false
    ): CapabilityDecision {
        // ⚠⚠ RECEIVE reads the count HERE, before any status gate, so that
        // `unavailable` cannot depend on the receiver — see the
        // RECEIVE_COMMUNITY case in can() for why that matters. The default
        // is false, so CREATE's order is unchanged and every other caller
        // keeps the documented sequence.
        $custody = null;
        if ($countBeforeStatusGates) {
            $custody = $this->custodyState($userId);
            if ($custody === null) {
                return CapabilityDecision::denied('unavailable', 'community_custody');
            }
        }

        if (!$this->notSuspended($userId)) {
            return CapabilityDecision::denied('suspended', 'community_custody');
        }
        // Missing rank_state row = New Member; a present row is always
        // at least Apprentice, so this single check covers both the
        // no-row and the (impossible-by-construction) sub-Apprentice
        // cases — fail closed either way.
        if (!$this->rankAtLeast($userId, 'apprentice')) {
            return CapabilityDecision::denied('new_member', 'community_custody');
        }
        if (!$this->tierAtLeast($userId, 'neutral')) {
            return CapabilityDecision::denied('below_neutral', 'community_custody');
        }
        if ($this->inRecovery($userId)) {
            return CapabilityDecision::denied('in_recovery', 'community_custody');
        }

        // Already read above when the caller asked for it; otherwise read
        // here, in the documented position.
        $custody ??= $this->custodyState($userId);

        // ⛔ FAIL CLOSED ON AN UNREADABLE COUNT, and before the comparison.
        // This gate decides a DURABLE write: both keys that reach here —
        // CREATE_COMMUNITY and RECEIVE_COMMUNITY — grant custody of a
        // community, and what it admits stays granted after the fault
        // clears. "We could not count" is not "under the cap".
        //
        // ⚠ Deliberately a DENY and not an `unknown()` decision: callers
        // treat unknown as deferred-to-a-live-gate (see the
        // `deferred_to_live_gate` branch in can()), and there is no live
        // gate behind this one to defer to.
        if ($custody === null) {
            return CapabilityDecision::denied('unavailable', 'community_custody');
        }

        if ($custody['owned'] >= $custody['cap']) {
            return CapabilityDecision::denied('cap_reached', 'community_custody');
        }
        if ($this->cooldownActive($custody)) {
            return CapabilityDecision::denied('cooldown_active', 'community_custody');
        }

        return CapabilityDecision::allowed('community_custody');
    }

    /**
     * transfer_community — the GIVER's gate (§21.2): suspension, New
     * Member (an owner can't be one, but fail closed), and recovery
     * pause (§14.2) only. NO cap check and NO cooldown check — the
     * cooldown blocks acquiring custody, never giving it away (a
     * cooldown that trapped ownership would be a hostage mechanic).
     */
    private function resolveTransferCommunity(int $userId): CapabilityDecision
    {
        if (!$this->notSuspended($userId)) {
            return CapabilityDecision::denied('suspended', 'community_custody');
        }
        if (!$this->rankAtLeast($userId, 'apprentice')) {
            return CapabilityDecision::denied('new_member', 'community_custody');
        }
        if ($this->inRecovery($userId)) {
            return CapabilityDecision::denied('in_recovery', 'community_custody');
        }

        return CapabilityDecision::allowed('community_custody');
    }

    /**
     * post_ranked_hall_feed — §21.3 write gate: Journeyman+ AND
     * Neutral+ AND not suspended AND not in recovery. Hall membership
     * is checked at the gate site (PostsService::gateGroupPost — the
     * group-scoped facts live there), not here.
     */
    private function resolvePostRankedHallFeed(int $userId): CapabilityDecision
    {
        if (!$this->notSuspended($userId)) {
            return CapabilityDecision::denied('suspended', 'ranked_hall');
        }
        if (!$this->rankAtLeast($userId, 'apprentice')) {
            return CapabilityDecision::denied('new_member', 'ranked_hall');
        }
        if (!$this->rankAtLeast($userId, 'journeyman')) {
            return CapabilityDecision::denied('below_journeyman', 'ranked_hall');
        }
        if (!$this->tierAtLeast($userId, 'neutral')) {
            return CapabilityDecision::denied('below_neutral', 'ranked_hall');
        }
        if ($this->inRecovery($userId)) {
            return CapabilityDecision::denied('in_recovery', 'ranked_hall');
        }

        return CapabilityDecision::allowed('ranked_hall');
    }

    /**
     * list_as_mentor — §21.4 eligibility: Veteran AND tier ≥ Trusted
     * AND not suspended AND not in recovery. The explicit opt-in is
     * NOT part of this key — listing composes opt-in AND this verdict
     * live (MentorListingService), so a pause needs no sweep and no
     * materialized flag.
     */
    private function resolveListAsMentor(int $userId): CapabilityDecision
    {
        if (!$this->notSuspended($userId)) {
            return CapabilityDecision::denied('suspended', 'mentor_listing');
        }
        if (!$this->rankAtLeast($userId, 'apprentice')) {
            return CapabilityDecision::denied('new_member', 'mentor_listing');
        }
        if (!$this->rankAtLeast($userId, 'veteran')) {
            return CapabilityDecision::denied('below_veteran', 'mentor_listing');
        }
        if (!$this->tierAtLeast($userId, 'trusted')) {
            return CapabilityDecision::denied('below_trusted', 'mentor_listing');
        }
        if ($this->inRecovery($userId)) {
            return CapabilityDecision::denied('in_recovery', 'mentor_listing');
        }

        return CapabilityDecision::allowed('mentor_listing');
    }

    /**
     * §21.2 cooldown comparison over custodyState's facts. Strictly
     * LESS-THAN: at exactly cooldown_seconds elapsed the cooldown has
     * expired and the action is allowed again.
     *
     * @param array{owned: int, cap: int, last_event_ts: int|null, cooldown_seconds: int} $custody
     */
    private function cooldownActive(array $custody): bool
    {
        if ($custody['last_event_ts'] === null) {
            return false;
        }
        return (time() - $custody['last_event_ts']) < $custody['cooldown_seconds'];
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
        // Rank-presence (Apprentice+, New Members excluded) AND tier
        // Neutral+, composed from the shared credibility atoms.
        // Deliberately no suspension / fraud / recovery gate here — that
        // asymmetry (enforced instead at the REST layer, §20.1) is
        // unchanged by the atom consolidation.
        return $this->hasRankState($userId) && $this->tierAtLeast($userId, 'neutral');
    }

    /** @throws AttestationException when ineligible */
    protected function attestationGate(int $userId, string $targetKind, int $targetId, string $kind): void
    {
        \BCC\Trust\Core\Plugin::instance()
            ->attestationService()
            ->checkCastEligibility($userId, $targetKind, $targetId, $kind);
    }

    protected function ownsPage(int $pageId, int $userId): bool
    {
        return \BCC\Core\Permissions\Permissions::owns_page($pageId, $userId);
    }

    // ── Rank Phase 7 delegate hooks (overridden by the matrix test) ──
    // rankAtLeast / tierAtLeast / inRecovery (and notSuspended above) now
    // come from the shared CredibilityAtoms trait — one definition, §11.
    // The matrix test overrides them on its subclass exactly as before;
    // the Phase 7 composite gates below compose these atoms with the
    // §21.2 cap / cooldown facts, which stay local.

    /**
     * §21.2 custody facts: currently-owned User-kind group count, the
     * per-rank ownership cap, the last custody event (create / receive
     * / transfer_out — epoch seconds, null = never), and the cooldown
     * window. The COMPARISONS over these facts live in the caller
     * (resolveCustodyAcquisition / cooldownActive) so the matrix test
     * exercises the real math against faked facts.
     *
     * ⚠ NULL means the owned-group count could not be READ (F-503). It is
     * not "owns none" and not "no cap" — the caller must refuse rather than
     * compare against a number it does not have.
     *
     * @return array{owned: int, cap: int, last_event_ts: int|null, cooldown_seconds: int}|null
     */
    protected function custodyState(int $userId): ?array
    {
        $plugin = \BCC\Trust\Core\Plugin::instance();
        $config = $plugin->rankScoringConfig();

        // Callers gate on rankAtLeast('apprentice') before reading
        // custody facts, so a row exists; the apprentice fallback is a
        // conservative floor for any future caller that doesn't.
        $row  = $plugin->rankStateRepository()->getForUser($userId);
        $rank = $row !== null ? (string) $row->rank_slug : 'apprentice';
        $cap  = $config->communityCaps[$rank] ?? $config->communityCaps['apprentice'];

        $lastAt = $plugin->communityOwnershipLogRepository()->lastCustodyEventAt($userId);
        $lastTs = null;
        if ($lastAt !== null) {
            // Ledger datetimes are UTC (written via gmdate) — pin the
            // zone on parse; MySQL session tz is NOT UTC on this host.
            $parsed = strtotime($lastAt . ' UTC');
            $lastTs = $parsed === false ? null : $parsed;
        }

        // ⚠⚠ THE STRICT TWIN, and the difference is the whole point.
        // `countOwnedUserGroups()` returns `int`, and `get_var()` answers
        // null for a read that did not run — which `(int)` turns into 0.
        // A cap comparison then reads a failed read as "owns none", the
        // most permissive answer available, and admits a write that stays
        // granted after the fault clears.
        //
        // `countOwnedUserGroupsOrFail()` answers null instead, and null
        // travels out of here as null so the CALLER refuses. "Unknown" must
        // never be collapsed into a number here — a 0 substituted at this
        // layer would be indistinguishable from a real count by the time it
        // reached the comparison.
        $owned = \BCC\Core\Repositories\PeepSoGroupRepository::countOwnedUserGroupsOrFail($userId);
        if ($owned === null) {
            return null;
        }

        return [
            'owned'            => $owned,
            'cap'              => $cap,
            'last_event_ts'    => $lastTs,
            'cooldown_seconds' => $config->communityCooldownDays * 86400,
        ];
    }
}
