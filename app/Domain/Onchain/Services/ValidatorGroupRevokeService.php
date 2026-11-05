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
 * and SWEEP_GROUP_BUDGET groups per tick; the per-wallet 5-minute delegation
 * cache inside the eligibility service means a group's members sharing a
 * validator cost one LCD call per member-wallet, not per (member, group) pair.
 *
 * The walk is a keyset on group id, so every group is eventually reached
 * however many exist. The saved cursor means "resume at the first group with
 * an id at or above this, at this member offset"; the offset only applies if
 * that exact group still exists. A removed member is told by bell, and only a
 * removed member: see {@see notifyRemoved()}.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
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

    /**
     * Groups visited per tick. A group of nothing but its owner spends no member
     * budget, so this is what bounds a tick across many of them.
     */
    public const SWEEP_GROUP_BUDGET = 500;

    /** Option storing the rotation cursor across ticks (own cursor —
     *  never shared with the NFT sweep's). */
    private const CURSOR_OPTION = 'bcc_validator_group_revoke_cursor';

    /** Member-read page size per group (PeepSoGroupRepository caps at 100). */
    private const MEMBER_PAGE_SIZE = 100;

    public function __construct(
        private readonly DelegationEligibilityService $eligibility,
        /** @var (\Closure(int, int): void)|null Replaces the bell, for tests only. */
        private readonly ?\Closure $removalNotifier = null,
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

        $stats = ['checked' => 0, 'revoked' => 0, 'skipped_unknown' => 0, 'groups_touched' => 0];

        $cursor  = $this->readCursor();
        $startId = $cursor['group_id'];

        $processed = 0;
        $visited   = 0;
        $afterId   = max(0, $startId - 1);
        $wrapped   = false;

        while ($visited < self::SWEEP_GROUP_BUDGET) {
            $groupIds = ValidatorGroupRepository::listValidatorGroupIdsAfter($afterId, self::SWEEP_GROUP_BUDGET - $visited);

            if ($groupIds === []) {
                if ($wrapped) {
                    break; // No delegator communities at all.
                }
                // Past the last group: go round to the first.
                $wrapped = true;
                $afterId = 0;
                continue;
            }

            foreach ($groupIds as $groupId) {
                if ($wrapped && $groupId >= $startId) {
                    // Back where this tick started: the rotation is complete.
                    $this->writeCursor(['group_id' => $groupId, 'offset' => 0]);
                    return $stats;
                }

                $visited++;
                $afterId = $groupId;

                $config = ValidatorGroupRepository::getGateConfig($groupId);
                if ($config === null) {
                    continue; // No longer a delegator community — skip.
                }

                // The saved offset belongs to the saved group. If that group
                // is gone, this is the next one, and it starts from its top.
                $offset = (!$wrapped && $groupId === $startId) ? $cursor['offset'] : 0;

                if (!$this->sweepGroup($groupId, $config, $offset, $batchCap, $processed, $stats)) {
                    return $stats; // Out of budget; sweepGroup saved the cursor.
                }
            }
        }

        // Spent the group budget, or found nothing to do: next tick carries on
        // past the last group visited.
        $this->writeCursor(['group_id' => $afterId + 1, 'offset' => 0]);

        return $stats;
    }

    /**
     * Page through one group's active members. False when the member budget ran
     * out inside it, having saved where to resume.
     *
     * ⚠ THE OFFSET ADVANCES PAST MEMBERS STILL PRESENT, NOT PAST THE PAGE.
     * listGroupMembers() is OFFSET-paged, and a removal moves every later row up
     * one, so adding the full page size skips as many members as were removed.
     * A member who stayed, who is the owner, or whose leave() was refused is
     * still in the list, so they count; only a real removal doesn't. That can't
     * spin: a removal spends budget and shortens the list, and anything else
     * moves the offset.
     *
     * @param array{checked: int, revoked: int, skipped_unknown: int, groups_touched: int} $stats
     */
    private function sweepGroup(int $groupId, ValidatorGatedGroupConfig $config, int $offset, int $batchCap, int &$processed, array &$stats): bool
    {
        $touchedThisGroup = false;

        while (true) {
            if ($processed >= $batchCap) {
                // Out of budget mid-group — resume here next tick.
                $this->writeCursor(['group_id' => $groupId, 'offset' => $offset]);
                if ($touchedThisGroup) {
                    $stats['groups_touched']++;
                }
                return false;
            }

            $remaining = $batchCap - $processed;
            $pageSize  = (int) min(self::MEMBER_PAGE_SIZE, $remaining);
            $members   = PeepSoGroupRepository::listGroupMembers($groupId, $offset, $pageSize);
            if ($members === []) {
                break; // End of this group's member list.
            }

            $removedThisPage = 0;

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
                        $removedThisPage++;
                    }
                    continue;
                }
                // ELIGIBLE → keep.
            }

            $offset += count($members) - $removedThisPage;
            if (count($members) < $pageSize) {
                break; // Short page = end of group.
            }
        }

        if ($touchedThisGroup) {
            $stats['groups_touched']++;
        }

        return true;
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
        // ⚠ leave() answers true when there is no row to remove, so it can't
        // tell us this sweep removed anyone. Someone who left between the member
        // read and now, or whom an overlapping tick already removed, is not
        // removed here: no audit row, no notice.
        if (PeepSoGroupRepository::getMembershipStatus($userId, $groupId) === null) {
            return false;
        }

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

        // Audit first (accountability), then the bell in notifyRemoved().
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

        $this->notifyRemoved($userId, $groupId);

        return true;
    }

    /**
     * The bell, and only after a real removal: this runs on one line of one path,
     * so an UNKNOWN verdict, a refused leave() and any leave outside this sweep
     * can't reach it. The removal has already happened and is audited, so nothing
     * going wrong here may undo it or stop the rest of the sweep.
     */
    private function notifyRemoved(int $userId, int $groupId): void
    {
        try {
            if ($this->removalNotifier !== null) {
                ($this->removalNotifier)($userId, $groupId);
                return;
            }
            Plugin::instance()->notificationDispatcher()->onDelegatorGroupMemberRemoved($userId, $groupId);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] delegator-group removal notice failed', [
                'user_id'  => $userId,
                'group_id' => $groupId,
                'error'    => $e->getMessage(),
            ]);
        }
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
