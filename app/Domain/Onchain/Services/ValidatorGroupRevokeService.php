<?php
/**
 * Validator/delegator community membership REVOKE sweep.
 *
 * Exact sibling of {@see NftGroupRevokeService} with the delegation
 * verdict instead of the holdings verdict. There is NO membership-
 * provenance ledger — PeepSo's peepso_group_members is the only record —
 * so revoke is periodic RE-VERIFICATION: for every current member of
 * every delegator community, re-derive delegation eligibility against
 * the live LCD and remove the ones we are CERTAIN no longer qualify.
 *
 * The three-outcome verdict (DelegationEligibilityService) is
 * load-bearing:
 *   - ELIGIBLE   → keep.
 *   - INELIGIBLE → revoke (PeepSoGroupWriter::leave + audit). NO opt-out
 *                  is written — re-delegate = instant re-qualify.
 *   - UNKNOWN    → SKIP. An LCD outage must NEVER trigger a revoke; the
 *                  next sweep retries.
 *
 * Owner safety: operators OWN their delegator community and are never
 * revoked — the owner-role short-circuit here plus
 * PeepSoGroupWriter::leave's own owner refusal.
 *
 * Runs on the EXISTING `bcc_gated_group_revoke_sweep` twicedaily cron
 * (the bcc-trust.php handler calls both sweeps) with its OWN rotation
 * cursor option, so the NFT sweep's budget/rotation is untouched.
 *
 * Permanent-opt-out trap: PeepSoGroupWriter::leave fires
 * peepso_action_group_user_delete, whose listener writes a PERMANENT
 * opt-out for non-self removals. This sweep sets the SHARED
 * {@see NftGroupRevokeService::$systemRevokeInProgress} guard around its
 * leave() calls so the listener skips the permanent opt-out for OUR
 * automated revokes — while still writing it for genuine PeepSo-UI mod
 * evictions (the listener's delegator-kind arm).
 *
 * Bounded + rotated: at most SWEEP_BATCH_SIZE member re-verifications
 * per tick; the per-wallet 5-minute delegation cache inside the
 * eligibility service means a group's members sharing a validator cost
 * one LCD call per member-wallet, not per (member, group) pair.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\Repositories\ValidatorGroupRepository;
use BCC\Trust\Onchain\ValueObjects\DelegationVerdict;
use BCC\Trust\Onchain\ValueObjects\ValidatorGatedGroupConfig;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidatorGroupRevokeService
{
    /** Default per-tick cap on member re-verifications. Filterable. */
    public const SWEEP_BATCH_SIZE = 200;

    /** Option storing the rotation cursor across ticks (own cursor —
     *  never shared with the NFT sweep's). */
    private const CURSOR_OPTION = 'bcc_validator_group_revoke_cursor';

    /** Member-read page size per group (PeepSoGroupRepository caps at 100). */
    private const MEMBER_PAGE_SIZE = 100;

    public function __construct(
        private readonly DelegationEligibilityService $eligibility,
    ) {}

    /**
     * Run one bounded, rotated revoke tick.
     *
     * @return array{checked: int, revoked: int, skipped_unknown: int, groups_touched: int}
     */
    public function sweep(): array
    {
        $batchCap = (int) apply_filters('bcc_validator_group_revoke_batch_size', self::SWEEP_BATCH_SIZE);
        if ($batchCap < 1) {
            $batchCap = self::SWEEP_BATCH_SIZE;
        }

        $groupIds = ValidatorGroupRepository::listAllValidatorGroupIds();
        $stats = ['checked' => 0, 'revoked' => 0, 'skipped_unknown' => 0, 'groups_touched' => 0];
        if ($groupIds === []) {
            return $stats;
        }

        // Cursor → (groupIndex, memberOffset); same group_id + offset
        // persistence idiom as NftGroupRevokeService so the cursor stays
        // stable when groups are added/removed between ticks.
        $cursor       = $this->readCursor();
        $startGroupId = $cursor['group_id'];
        $startOffset  = $cursor['offset'];

        $startIdx = array_search($startGroupId, $groupIds, true);
        if ($startIdx === false) {
            $startIdx    = 0;
            $startOffset = 0;
        }

        $processed   = 0;
        $groupsCount = count($groupIds);
        $nextCursor  = ['group_id' => $groupIds[0], 'offset' => 0];
        $wrapped     = false;

        for ($step = 0; $step < $groupsCount; $step++) {
            $idx     = ($startIdx + $step) % $groupsCount;
            $groupId = $groupIds[$idx];
            $offset  = ($step === 0) ? $startOffset : 0;

            $config = ValidatorGroupRepository::getGateConfig($groupId);
            if ($config === null) {
                continue; // No longer a delegator community — skip.
            }

            $touchedThisGroup = false;

            // Page through this group's active members.
            while (true) {
                if ($processed >= $batchCap) {
                    // Out of budget mid-group — resume here next tick.
                    $nextCursor = ['group_id' => $groupId, 'offset' => $offset];
                    $this->writeCursor($nextCursor);
                    if ($touchedThisGroup) {
                        $stats['groups_touched']++;
                    }
                    return $stats;
                }

                $remaining = $batchCap - $processed;
                $pageSize  = (int) min(self::MEMBER_PAGE_SIZE, $remaining);
                $members   = PeepSoGroupRepository::listGroupMembers($groupId, $offset, $pageSize);
                if ($members === []) {
                    break; // End of this group's member list.
                }

                foreach ($members as $member) {
                    $userId = (int) $member->user_id;
                    if ($userId <= 0) {
                        continue;
                    }

                    // Owners (the operator) are never revoked.
                    // PeepSoGroupWriter::leave also refuses them; the
                    // short-circuit avoids a pointless LCD call.
                    if ((string) $member->role === 'member_owner') {
                        continue;
                    }

                    $touchedThisGroup = true;
                    $stats['checked']++;
                    $processed++;

                    $verdict = $this->verdictForMember($userId, $config);

                    if ($verdict->isUnknown()) {
                        // LCD couldn't verify — FAIL OPEN on revoke:
                        // skip, retry next tick. Never revoke on a hiccup.
                        $stats['skipped_unknown']++;
                        continue;
                    }

                    if ($verdict->isIneligible()) {
                        if ($this->revokeMember($userId, $groupId, $config, $verdict)) {
                            $stats['revoked']++;
                        }
                        continue;
                    }
                    // ELIGIBLE → keep.
                }

                $offset += count($members);
                if (count($members) < $pageSize) {
                    break; // Short page = end of group.
                }
            }

            if ($touchedThisGroup) {
                $stats['groups_touched']++;
            }

            $nextIdx    = ($idx + 1) % $groupsCount;
            $nextCursor = ['group_id' => $groupIds[$nextIdx], 'offset' => 0];

            if ($nextIdx === $startIdx) {
                $wrapped = true;
                break;
            }
        }

        if ($wrapped) {
            $nextCursor = ['group_id' => $groupIds[0], 'offset' => 0];
        }
        $this->writeCursor($nextCursor);

        return $stats;
    }

    /**
     * Re-derive a member's delegation verdict. Isolated so a single
     * member's failure can't escape the loop; an unexpected throw is
     * treated as UNKNOWN (skip), never a revoke.
     */
    private function verdictForMember(int $userId, ValidatorGatedGroupConfig $config): DelegationVerdict
    {
        try {
            return $this->eligibility->verdictFor($userId, $config);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] validator revoke-sweep eligibility check failed', [
                'user_id'  => $userId,
                'group_id' => $config->groupId,
                'error'    => $e->getMessage(),
            ]);
            return DelegationVerdict::unknown($config->minStake, null);
        }
    }

    /**
     * Revoke one ineligible member. Sets the SHARED system-revoke guard
     * around the leave() so the peepso_action_group_user_delete listener
     * skips the permanent opt-out — this is a re-verification revoke,
     * NOT a mod eviction; the user re-qualifies instantly on
     * re-delegation (NO opt-out of any kind is written).
     *
     * Returns true only on a real removal (owner-refusal /
     * PeepSo-absence returns false → no audit row).
     */
    private function revokeMember(int $userId, int $groupId, ValidatorGatedGroupConfig $config, DelegationVerdict $verdict): bool
    {
        NftGroupRevokeService::$systemRevokeInProgress = true;
        try {
            $left = \BCC\Core\PeepSo\PeepSoGroupWriter::leave($userId, $groupId);
        } finally {
            // Always clear — even on a throw — so the guard can't stay
            // stuck on and suppress a later genuine mod-eviction opt-out.
            NftGroupRevokeService::$systemRevokeInProgress = false;
        }

        if (!$left) {
            return false;
        }

        // SILENT revoke — no notification. Audit only (accountability).
        AuditLogger::log(
            'validator_group_revoked',
            $groupId,
            [
                'reason'           => 'reverification_ineligible',
                'min_stake'        => $config->minStake,
                'best_known_stake' => $verdict->bestKnownStake,
                'via'              => 'revoke_sweep',
            ],
            'group',
            $userId
        );

        return true;
    }

    /**
     * @return array{group_id: int, offset: int}
     */
    private function readCursor(): array
    {
        $raw = get_option(self::CURSOR_OPTION, []);
        if (!is_array($raw)) {
            return ['group_id' => 0, 'offset' => 0];
        }
        return [
            'group_id' => isset($raw['group_id']) ? (int) $raw['group_id'] : 0,
            'offset'   => isset($raw['offset']) ? max(0, (int) $raw['offset']) : 0,
        ];
    }

    /**
     * @param array{group_id: int, offset: int} $cursor
     */
    private function writeCursor(array $cursor): void
    {
        update_option(self::CURSOR_OPTION, [
            'group_id' => (int) $cursor['group_id'],
            'offset'   => max(0, (int) $cursor['offset']),
        ], false);
    }
}
