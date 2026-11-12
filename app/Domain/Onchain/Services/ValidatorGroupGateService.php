<?php
/**
 * Validator/delegator community join gate.
 *
 * Exact mirror of {@see NftGroupGateService::joinIfEligible} with the
 * delegation gate instead of the holdings gate:
 *
 *   config → opt-out → not-member → cosmos chain → LIVE delegation
 *   verdict (DelegationEligibilityService) → PeepSoGroupWriter::join.
 *
 * Opt-out state REUSES the NFT opt-out machinery (NftGroupGateService's
 * `bcc_gated_groups_optout` user_meta map) — it is group-id-keyed and
 * kind-agnostic, so delegator communities share the same TTL'd/permanent
 * semantics and the same peepso_action_group_user_delete listener.
 *
 * Fail-closed: UNKNOWN (LCD outage / unreadable amounts) refuses the
 * join — never bring someone into a gated community during an outage.
 * There is NO auto-join / reconcile path for delegator communities
 * (V1 cut) — every join is explicit.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\ValidatorGroupRepository;
use BCC\Trust\Onchain\ValueObjects\JoinResult;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidatorGroupGateService {

    /**
     * F-156 — total upstream budget for ONE join attempt, in seconds.
     *
     * A synchronous, user-facing bound: the user is holding a request open
     * while the LCD is walked. It covers the WHOLE eligibility check, not
     * one call — see where it is converted to an instant below.
     *
     * ⚠ 20s IS NOT A TRIM OF THE RETRY TAIL; IT IS SHORTER THAN ONE
     * UNBOUNDED CALL. Cosmos's per-attempt timeout is 15s, so this budget
     * buys one full attempt and then whatever is left (ApiRetry shrinks the
     * next attempt's timeout to the remaining budget and refuses to start
     * one below {@see \BCC\Trust\Onchain\Support\ApiRetry::MIN_ATTEMPT_BUDGET}).
     * That is deliberate, and it is what "bounded" has to mean when a single
     * timeout is most of the budget. The alternative is not "4 attempts,
     * sooner" — it is the 68s-per-call, multi-page, multi-wallet walk this
     * replaces.
     *
     * ⚠ Safe because the gate FAILS CLOSED: running out of budget reaches
     * the user as the same retryable `verify_unavailable` an LCD outage
     * already produced. It cannot admit anyone, and it cannot remove anyone.
     */
    private const JOIN_GATE_DEADLINE = 20;

    public function __construct(
        private readonly NftGroupGateService $optOuts,
        private readonly DelegationEligibilityService $eligibility,
    ) {}

    /**
     * Verify delegation eligibility, respect opt-out, and join via
     * PeepSoGroupWriter.
     *
     * Idempotent: if the user is already a member, returns
     * JoinResult::alreadyMember (success=true, no PeepSo write).
     *
     * JoinResult::minBalance carries the min-stake gate rounded up to an
     * int for shape parity; the REST layer reads the float threshold off
     * the config when building copy.
     */
    public function joinIfEligible(int $userId, int $groupId): JoinResult {
        if ($userId <= 0 || $groupId <= 0) {
            return JoinResult::notAHolderGroup();
        }

        $config = ValidatorGroupRepository::getGateConfig($groupId);
        if ($config === null) {
            return JoinResult::notAHolderGroup();
        }
        $minWire = (int) ceil($config->minStake);

        // Shared, group-id-keyed opt-out map — same store the NFT gate
        // and the mod-eviction listener write. An explicit Join OVERRIDES a
        // voluntary (TTL'd) opt-out (F-158, same rule as holder groups); only
        // a PERMANENT moderator-eviction opt-out still refuses. Auto-add and
        // the listing keep honouring every opt-out.
        if ($this->optOuts->isPermanentOptOut($userId, $groupId)) {
            return JoinResult::optOutActive($minWire);
        }
        // A PeepSo ban is a moderator removal too (F-271): 403, not a 503.
        if ($this->optOuts->isBannedFrom($userId, $groupId)) {
            return JoinResult::optOutActive($minWire);
        }

        $memberships = \BCC\Core\Repositories\PeepSoGroupRepository::findUserMemberships(
            $userId,
            [$groupId]
        );
        if (isset($memberships[$groupId])) {
            return JoinResult::alreadyMember($minWire);
        }

        // V1: cosmos-only. A missing or non-cosmos chain row is a
        // configuration state, not an outage — surface chain_unsupported.
        $chain = ChainRepository::getById($config->chainId);
        if ($chain === null || (string) $chain->chain_type !== 'cosmos') {
            return JoinResult::chainUnsupported($minWire);
        }

        // Three-outcome verdict. JOIN fails CLOSED: on UNKNOWN (LCD
        // outage) we refuse to add the user — we can't prove they
        // delegate. They retry once the LCD recovers.
        //
        // ⚠⚠ F-156: AND NOW BOUNDED IN TIME TOO, not just in outcome.
        // Unbounded, this walk runs up to `DELEGATIONS_MAX_PAGES` pages per
        // verified wallet at 68s a page. The budget is converted to an
        // INSTANT here so it bounds that whole fan-out rather than each call
        // inside it; see JOIN_GATE_DEADLINE for why 20s, and
        // verdictsForUser() for why nothing downstream defaults it.
        $verdict = $this->eligibility->verdictFor(
            $userId,
            $config,
            time() + self::JOIN_GATE_DEADLINE
        );
        if ($verdict->isUnknown()) {
            return JoinResult::verifyUnavailable($minWire);
        }
        if (!$verdict->isEligible()) {
            return JoinResult::notEligible(
                $minWire,
                (int) floor($verdict->bestKnownStake ?? 0.0)
            );
        }

        // BCC gate passed (delegation verified + opt-out clear +
        // not-a-member) — the writer below is the trusted-backend door
        // that bypasses PeepSo's UI approval, so it must only ever run
        // on this line, directly after the checks above. Honor the
        // writer's verdict: false = PeepSo absent OR an existing banned
        // membership row (the writer refuses to flip a group-level ban
        // back to member). Surface the same transient fail-closed 503
        // the UNKNOWN verdict uses — nothing was written, so nothing may
        // be reported as joined.
        if (!\BCC\Core\PeepSo\PeepSoGroupWriter::join($userId, $groupId)) {
            return JoinResult::verifyUnavailable($minWire);
        }
        $this->optOuts->clearOptOut($userId, $groupId);

        return JoinResult::ok($minWire);
    }
}
