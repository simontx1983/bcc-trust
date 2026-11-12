<?php
/**
 * NFT-gated group membership REVOKE sweep.
 *
 * There is NO membership-provenance ledger — PeepSo's
 * peepso_group_members is the only record of who is in a gated group
 * (see GatedGroupRepository: "No parallel ledger"). So we cannot do a
 * targeted teardown of "members who joined via the gate." Revoke is
 * therefore implemented as periodic RE-VERIFICATION: for every current
 * member of every gated group, re-derive eligibility against the live
 * gate config and remove the ones we are CERTAIN no longer qualify.
 *
 * The three-outcome verdict (HoldingsService::eligibilityVerdict) is
 * load-bearing here:
 *   - ELIGIBLE   → keep.
 *   - INELIGIBLE → revoke (PeepSoGroupWriter::leave + audit). Real-zero
 *                  across every wallet. NO opt-out is written — the user
 *                  re-qualifies instantly the moment they re-acquire.
 *   - UNKNOWN    → SKIP. A provider outage / breaker-open chain must
 *                  NEVER trigger a revoke. The next sweep retries. This
 *                  is the entire reason Part 1 (fail-open) exists.
 *
 * Owner safety: PeepSoGroupWriter::leave refuses to remove group owners
 * (returns false). We never audit a non-removal.
 *
 * Permanent-opt-out trap: PeepSoGroupWriter::leave fires
 * peepso_action_group_user_delete, whose listener writes a PERMANENT
 * opt-out for non-self removals (treating them as mod-evictions). This
 * sweep runs in cron (no current user), so without a guard the listener
 * would mis-read our automated revoke as a mod-eviction and lock the
 * user out forever. {@see $systemRevokeInProgress} is the flag the
 * listener checks to skip the permanent opt-out for OUR leaves — while
 * still writing it for genuine PeepSo-UI mod removals.
 *
 * Bounded + rotated: each tick processes at most SWEEP_BATCH_SIZE
 * (member, group) pairs and persists a rotation cursor across
 * groups+members so every member is eventually covered, not just the
 * first N. Never unbounded RPC in one tick.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\EligibilityVerdict;
use BCC\Trust\Onchain\ValueObjects\GatedGroupConfig;

if (!defined('ABSPATH')) {
    exit;
}

final class NftGroupRevokeService
{
    /** Cron hook fired twicedaily — see bcc-trust.php registration. */
    public const CRON_HOOK = 'bcc_gated_group_revoke_sweep';

    /** Default per-tick cap on (member) re-verifications. Filterable. */
    public const SWEEP_BATCH_SIZE = 200;

    /** Option storing the rotation cursor across ticks. */
    private const CURSOR_OPTION = 'bcc_gated_group_revoke_cursor';

    /**
     * Member-read page size per group. PeepSoGroupRepository::listGroupMembers
     * caps at 100 internally; we page through a group in chunks of this
     * size so a single huge group can't be read unbounded.
     */
    private const MEMBER_PAGE_SIZE = 100;

    /**
     * F-69: one sweep at a time. Two overlapping ticks (a slow tick still
     * running when the next cron fires, or a manual run beside the cron)
     * read the same members, both revoke them, and both write the audit
     * row — duplicated audit history. Session-scoped MySQL advisory lock,
     * the same primitive gated-group provisioning uses; non-blocking, so
     * the overlapping tick skips instead of queueing.
     */
    public const SWEEP_LOCK = 'bcc_gated_group_revoke_sweep';

    /**
     * F-278: single-event re-check of ONE member after the indexer saw one of
     * their wallets lose a holding. Args: [user_id, group_id].
     */
    public const RECHECK_HOOK = 'bcc_gated_group_member_recheck';

    /** PeepSo statuses that hold access (the sweep's member list). */
    private const ACTIVE_STATUSES = ['member', 'member_owner', 'member_manager', 'member_moderator', 'member_readonly'];

    /**
     * TRUE only for the brief window around a system-initiated
     * PeepSoGroupWriter::leave() in this sweep. The
     * peepso_action_group_user_delete listener (bcc-trust.php) reads this
     * to decide whether the removal is a mod-eviction (→ permanent
     * opt-out) or our automated re-verification revoke (→ NO opt-out, the
     * user re-qualifies on re-acquire).
     *
     * Public + static because the listener registers as a plain closure
     * with no DI handle to this instance.
     */
    public static bool $systemRevokeInProgress = false;

    /**
     * @param (\Closure(): ProviderRequestBudget)|null $budgetFactory Replaces the
     *        per-tick provider budget, for TESTS ONLY — so the wall-clock stop
     *        can be pinned deterministically. Production constructs this with
     *        no arguments and always gets the SURFACE_REVOKE budget.
     */
    public function __construct(
        private readonly ?\Closure $budgetFactory = null,
    ) {}

    /**
     * Run one bounded, rotated revoke tick.
     *
     * PR 7.14:
     *   - one provider budget per tick (HoldingsService::SURFACE_REVOKE). When
     *     a member's check no longer fits, the tick stops and the cursor points
     *     at THAT member, so the next tick starts with them. A member whose
     *     check alone exceeds a whole tick is skipped as unknown instead, so
     *     one member cannot stall the rotation;
     *   - the member offset advances past KEPT members only. A removal shifts
     *     every later row up by one, so adding the full page size skipped as
     *     many members as were removed;
     *   - `skipped_reasons` counts skips by bounded reason code.
     *
     * F-69: the whole tick runs under SWEEP_LOCK. When another tick holds
     * it, this one does nothing and reports `skipped_overlap: true`. When
     * the lock primitive itself is missing it also does nothing (fail
     * closed: an unserialized sweep is the bug) and logs an error.
     *
     * @return array{checked: int, revoked: int, skipped_unknown: int, groups_touched: int, skipped_reasons: array<string, int>, stopped_on_budget: bool, skipped_overlap: bool}
     */
    public function sweep(): array
    {
        if (!class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            \BCC\Core\Log\Logger::error('[bcc-trust] Holder-group revoke sweep cannot be serialized: AdvisoryLock is unavailable', [
                'lock' => self::SWEEP_LOCK,
            ]);
            return self::emptyStats(true);
        }
        if (!\BCC\Core\DB\AdvisoryLock::acquire(self::SWEEP_LOCK, 0)) {
            return self::emptyStats(true); // Another tick is running — never overlap.
        }

        try {
            return $this->runSweep();
        } finally {
            \BCC\Core\DB\AdvisoryLock::release(self::SWEEP_LOCK);
        }
    }

    /**
     * @return array{checked: int, revoked: int, skipped_unknown: int, groups_touched: int, skipped_reasons: array<string, int>, stopped_on_budget: bool, skipped_overlap: bool}
     */
    private static function emptyStats(bool $skippedOverlap): array
    {
        return [
            'checked'           => 0,
            'revoked'           => 0,
            'skipped_unknown'   => 0,
            'groups_touched'    => 0,
            'skipped_reasons'   => [],
            'stopped_on_budget' => false,
            'skipped_overlap'   => $skippedOverlap,
        ];
    }

    /**
     * The tick itself; only ever called under SWEEP_LOCK by sweep().
     *
     * @return array{checked: int, revoked: int, skipped_unknown: int, groups_touched: int, skipped_reasons: array<string, int>, stopped_on_budget: bool, skipped_overlap: bool}
     */
    private function runSweep(): array
    {
        $batchCap = (int) apply_filters('bcc_gated_group_revoke_batch_size', self::SWEEP_BATCH_SIZE);
        if ($batchCap < 1) {
            $batchCap = self::SWEEP_BATCH_SIZE;
        }

        $groupIds = GatedGroupRepository::listAllGatedGroupIds();
        $stats    = self::emptyStats(false);
        if ($groupIds === []) {
            return $stats;
        }

        $budget = $this->budgetFactory !== null
            ? ($this->budgetFactory)()
            : HoldingsService::verificationBudget(HoldingsService::SURFACE_REVOKE);

        // Resolve the rotation cursor → (groupIndex, memberOffset). The
        // cursor is a flat "global member offset" we walk forward; when it
        // runs off the end of the gated-group set we wrap to 0. Persisting
        // group_id + offset (rather than a raw integer) keeps the cursor
        // stable even if a group is added/removed between ticks.
        $cursor       = $this->readCursor();
        $startGroupId = $cursor['group_id'];
        $startOffset  = $cursor['offset'];

        // Order groups deterministically (listAllGatedGroupIds already
        // returns ORDER BY ID ASC). Find where the cursor points; if the
        // cursored group no longer exists, fall back to the start.
        $startIdx = array_search($startGroupId, $groupIds, true);
        if ($startIdx === false) {
            $startIdx    = 0;
            $startOffset = 0;
        }

        $processed   = 0;
        $groupsCount = count($groupIds);
        $nextCursor  = ['group_id' => $groupIds[0], 'offset' => 0];
        $wrapped     = false;

        // Walk groups starting from the cursor, wrapping once around the
        // whole set so a tick can finish a near-end group then pick up the
        // earlier ones — but never loops forever (the $wrapped guard +
        // batch cap both terminate it).
        for ($step = 0; $step < $groupsCount; $step++) {
            $idx     = ($startIdx + $step) % $groupsCount;
            $groupId = $groupIds[$idx];
            // Only the very first group in this tick honours the saved
            // intra-group offset; subsequent groups start at 0.
            $offset  = ($step === 0) ? $startOffset : 0;

            $config = GatedGroupRepository::getGateConfig($groupId);
            if ($config === null) {
                continue; // No longer a holder group — skip.
            }

            // PR 5b: resolve the gate's identity from its linked collection
            // row BEFORE looking at a single member.
            //
            // This is the path where the old defect did real damage. An
            // alias-backed Solana gate made `count_holdings` return a REAL
            // `0`, which became INELIGIBLE, which this sweep treats as
            // grounds to EVICT — so a genuine holder could be removed from a
            // community because the question asked about them was invalid.
            //
            // An unresolved identity now skips the whole group: no provider
            // call, no verdict, and above all no revocation.
            $identity = GateIdentityResolver::resolve($config);
            if (!$identity->isResolved()) {
                continue;
            }
            $chainSlug = $identity->chainSlug();
            $canonical = $identity->canonical();

            $touchedThisGroup = false;

            // Page through this group's active members.
            while (true) {
                if ($processed >= $batchCap) {
                    // Out of budget mid-group — persist a cursor pointing at
                    // THIS group + the offset we reached so the next tick
                    // resumes here.
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

                // Where the member being looked at sits in the list AS IT IS
                // NOW. It moves past a member who stays; it does not move
                // past one who was removed, because the next row has taken
                // that place.
                $position = $offset;

                foreach ($members as $member) {
                    $userId = (int) $member->user_id;

                    // Owners are never revoked. PeepSoGroupWriter::leave
                    // also refuses them, but short-circuiting here avoids a
                    // pointless eligibility RPC for the owner row.
                    if ($userId <= 0 || $this->isOwnerRole((string) $member->role)) {
                        $position++;
                        continue;
                    }

                    $verdict = $this->verdictForMember($userId, $chainSlug, $canonical, $config, $budget);

                    if ($verdict->isBudgetExhausted() && $stats['checked'] > 0) {
                        // Out of provider budget before this member could be
                        // decided. Nothing was done to them; the next tick
                        // starts here.
                        $stats['stopped_on_budget'] = true;
                        $this->writeCursor(['group_id' => $groupId, 'offset' => $position]);
                        if ($touchedThisGroup) {
                            $stats['groups_touched']++;
                        }
                        return $stats;
                    }

                    $touchedThisGroup = true;
                    $stats['checked']++;
                    $processed++;

                    if ($verdict->isUnknown()) {
                        // Not proven either way: a failed read, an unavailable
                        // chain, incomplete evidence, a provider outage — or a
                        // single member too expensive for a whole tick. FAIL
                        // OPEN — skip, retry next tick. Never revoke without
                        // proof.
                        $stats['skipped_unknown']++;
                        $stats['skipped_reasons'][$verdict->reason] = ($stats['skipped_reasons'][$verdict->reason] ?? 0) + 1;
                        $position++;
                        continue;
                    }

                    if ($verdict->isIneligible()
                        && $this->revokeMember($userId, $groupId, $config, $verdict)
                    ) {
                        $stats['revoked']++;
                        continue; // removed: the next row now sits at $position
                    }

                    // ELIGIBLE, or a removal PeepSo refused → still listed.
                    $position++;
                }

                $offset = $position;
                if (count($members) < $pageSize) {
                    break; // Short page = end of group.
                }
            }

            if ($touchedThisGroup) {
                $stats['groups_touched']++;
            }

            // Finished this group within budget. Advance the cursor to the
            // NEXT group at offset 0. If we've wrapped back to the start
            // group, stop.
            $nextIdx = ($idx + 1) % $groupsCount;
            $nextCursor = ['group_id' => $groupIds[$nextIdx], 'offset' => 0];

            if ($nextIdx === $startIdx) {
                $wrapped = true;
                break;
            }
        }

        // Whole eligible set covered this tick (small install) or wrapped.
        if ($wrapped) {
            // Reset to the beginning so the next tick starts fresh.
            $nextCursor = ['group_id' => $groupIds[0], 'offset' => 0];
        }
        $this->writeCursor($nextCursor);

        return $stats;
    }

    /**
     * F-278: re-check ONE member of ONE holder group now, with the sweep's
     * rules — ELIGIBLE keeps, INELIGIBLE revokes (system revoke: no opt-out,
     * audited), UNKNOWN keeps. Owners are never revoked. Runs under the
     * sweep's lock; 'locked' means a sweep tick is running and the caller
     * should retry shortly.
     *
     * @return 'revoked'|'kept'|'unknown'|'not_member'|'owner'|'not_holder_group'|'unresolved'|'locked'
     */
    public function recheckMember(int $userId, int $groupId): string
    {
        if ($userId <= 0 || $groupId <= 0) {
            return 'not_member';
        }
        if (!\BCC\Core\DB\AdvisoryLock::acquire(self::SWEEP_LOCK, 0)) {
            return 'locked';
        }

        try {
            $config = GatedGroupRepository::getGateConfig($groupId);
            if ($config === null) {
                return 'not_holder_group';
            }
            $status = PeepSoGroupRepository::getMembershipStatus($userId, $groupId);
            if ($status === null || !in_array($status, self::ACTIVE_STATUSES, true)) {
                return 'not_member';
            }
            if ($this->isOwnerRole($status)) {
                return 'owner';
            }
            $identity = GateIdentityResolver::resolve($config);
            if (!$identity->isResolved()) {
                return 'unresolved';
            }

            $budget = $this->budgetFactory !== null
                ? ($this->budgetFactory)()
                : HoldingsService::verificationBudget(HoldingsService::SURFACE_REVOKE);
            $verdict = $this->verdictForMember($userId, $identity->chainSlug(), $identity->canonical(), $config, $budget);

            if ($verdict->isIneligible()) {
                return $this->revokeMember($userId, $groupId, $config, $verdict, 'holding_lost_recheck') ? 'revoked' : 'kept';
            }
            return $verdict->isUnknown() ? 'unknown' : 'kept';
        } finally {
            \BCC\Core\DB\AdvisoryLock::release(self::SWEEP_LOCK);
        }
    }

    /**
     * Re-derive a member's eligibility verdict. Isolated for testability
     * and so a single member's failure can't escape the loop.
     */
    private function verdictForMember(
        int $userId,
        string $chainSlug,
        string $canonicalIdentifier,
        GatedGroupConfig $config,
        ProviderRequestBudget $budget
    ): EligibilityVerdict {
        try {
            // PR 5b: the CANONICAL identity from the linked collection row,
            // resolved once per group by the caller. Never
            // `$config->contractAddress` — that is the legacy display alias,
            // and on the eight production Solana gates it is a marketplace
            // symbol that no provider can answer for.
            return HoldingsService::eligibilityVerdict(
                $userId,
                $chainSlug,
                $canonicalIdentifier,
                $config->minBalance,
                $budget
            );
        } catch (\Throwable $e) {
            // Bounded: the exception class, never its message (which can
            // carry an endpoint, an address or provider text).
            \BCC\Core\Log\Logger::warning('[bcc-trust] revoke-sweep eligibility check failed', [
                'user_id'     => $userId,
                'group_id'    => $config->groupId,
                'error_class' => get_class($e),
            ]);
            // Treat an unexpected throw as UNKNOWN — fail open (skip),
            // never revoke on an error we didn't anticipate.
            return EligibilityVerdict::unknown($config->minBalance, null);
        }
    }

    /**
     * Revoke one ineligible member. Sets the system-revoke guard around
     * the leave() so the peepso_action_group_user_delete listener skips
     * the permanent opt-out (this is a re-verification revoke, NOT a mod
     * eviction — the user re-qualifies instantly on re-acquire).
     *
     * Returns true only on a real removal (owner-refusal / PeepSo-absence
     * returns false → no audit row).
     */
    private function revokeMember(int $userId, int $groupId, GatedGroupConfig $config, EligibilityVerdict $verdict, string $via = 'revoke_sweep'): bool
    {
        self::$systemRevokeInProgress = true;
        try {
            $left = \BCC\Core\PeepSo\PeepSoGroupWriter::leave($userId, $groupId);
        } finally {
            // Always clear — even if leave() throws — so a stray exception
            // can't leave the guard stuck on and suppress a later genuine
            // mod-eviction's permanent opt-out.
            self::$systemRevokeInProgress = false;
        }

        if (!$left) {
            // Owner (leave refuses) or PeepSo absent — no state transition,
            // so no audit row.
            return false;
        }

        // SILENT revoke — no notification. Audit only (accountability).
        // No opt-out written: the user re-qualifies the instant they
        // re-acquire the NFT (the next join / sweep adds them back).
        AuditLogger::log(
            'holder_group_revoked',
            $groupId,
            [
                'reason'             => 'reverification_ineligible',
                'min_balance'        => $config->minBalance,
                'best_known_balance' => $verdict->bestKnownBalance,
                'via'                => $via,
            ],
            'group',
            $userId
        );

        return true;
    }

    private function isOwnerRole(string $role): bool
    {
        return $role === 'member_owner';
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
