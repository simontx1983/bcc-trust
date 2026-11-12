<?php
/**
 * Holder-group RECONCILE sweep (auto-join for opted-in holders).
 *
 * Twicedaily on CRON_HOOK, and on demand from the admin "Run sweep now"
 * button (HolderGroupsPage). Each tick walks the next BATCH_SIZE users who
 * opted into hands-off auto-join (USER_META_AUTO_JOIN = '1'), in user_id
 * order from a persisted rotation cursor, and calls
 * NftGroupGateService::reconcileForUser() on each. That method owns every
 * rule about WHO joins WHAT (opt-in, suspension, eligibility, opt-outs,
 * audit); this class only owns the walk.
 *
 * F-93, the three things the walk must get right (it used to be an inline
 * closure in bcc-trust.php that got all three wrong):
 *
 *   1. The cursor moves AFTER the work, one user at a time. It used to be
 *      saved before the loop, so a fatal or a timeout mid-batch silently
 *      skipped the rest of the batch until the next full wrap. Now a tick
 *      that dies resumes at the first user it did not finish. A wrap (short
 *      batch) is written only after the whole batch is done.
 *   2. One user's failure is that user's. reconcileForUser() throwing used
 *      to abort the batch (and, with the early cursor, skip the rest of it).
 *      Now it is caught, logged, counted in `errored`, and the walk moves on;
 *      the user is retried on the next wrap. Advancing past it (rather than
 *      holding the cursor) keeps one poisoned user from stalling everyone
 *      behind it.
 *   3. One sweep at a time. Two overlapping ticks (a slow tick still running
 *      when cron fires again, or cron beside a manual run) would reconcile
 *      the same users twice. The whole tick runs under SWEEP_LOCK, the same
 *      session-scoped, non-blocking advisory lock as the revoke sweeps
 *      (NftGroupRevokeService::SWEEP_LOCK, ValidatorGroupRevokeService::
 *      SWEEP_LOCK, F-69/F-204), under its OWN name. The overlapping tick
 *      skips and reports `skipped_overlap: true`. Without the lock primitive
 *      it fails closed: nothing runs, and an error says why.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\GatedGroupRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class NftGroupReconcileSweepService
{
    /** Cron hook fired twicedaily — see bcc-trust.php registration. */
    public const CRON_HOOK = 'bcc_gated_group_reconcile_sweep';

    /** Opted-in users reconciled per tick. */
    public const BATCH_SIZE = 20;

    /** Option holding the last user_id finished (0 = start from the top). */
    public const CURSOR_OPTION = 'bcc_gated_group_reconcile_cursor';

    /** Its own lock: the revoke sweeps must never block (or be blocked by) this one. */
    public const SWEEP_LOCK = 'bcc_gated_group_reconcile_sweep';

    public function __construct(
        private readonly NftGroupGateService $gate,
        /** @var (\Closure(int): array{joined: int, skipped: int})|null Replaces reconcileForUser, for tests only. */
        private readonly ?\Closure $reconciler = null,
        /** @var (\Closure(int, int): list<int>)|null Replaces the opted-in user read, for tests only. */
        private readonly ?\Closure $userReader = null,
    ) {}

    /**
     * Run one bounded, rotated reconcile tick under SWEEP_LOCK.
     *
     * @return array{checked: int, users_touched: int, joins_total: int, errored: int, skipped_overlap: bool, lock_unavailable: bool}
     */
    public function sweep(): array
    {
        if (!class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            \BCC\Core\Log\Logger::error('[bcc-trust] Holder-group reconcile sweep cannot be serialized: AdvisoryLock is unavailable', [
                'lock' => self::SWEEP_LOCK,
            ]);
            // Skipped like an overlap, but flagged so the admin page states
            // the real reason instead of "another sweep was running".
            return self::emptyStats(true, true);
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
     * @return array{checked: int, users_touched: int, joins_total: int, errored: int, skipped_overlap: bool, lock_unavailable: bool}
     */
    private static function emptyStats(bool $skippedOverlap, bool $lockUnavailable = false): array
    {
        return [
            'checked'          => 0,
            'users_touched'    => 0,
            'joins_total'      => 0,
            'errored'          => 0,
            'skipped_overlap'  => $skippedOverlap,
            'lock_unavailable' => $lockUnavailable,
        ];
    }

    /**
     * @return array{checked: int, users_touched: int, joins_total: int, errored: int, skipped_overlap: bool, lock_unavailable: bool}
     */
    private function runSweep(): array
    {
        $stats   = self::emptyStats(false);
        $afterId = max(0, (int) get_option(self::CURSOR_OPTION, 0));

        $userIds = $this->userReader !== null
            ? ($this->userReader)($afterId, self::BATCH_SIZE)
            : GatedGroupRepository::listAutoJoinUserIdsAfter($afterId, self::BATCH_SIZE);

        if ($userIds === []) {
            // End of the walk — wrap so the next tick starts from the top.
            if ($afterId > 0) {
                update_option(self::CURSOR_OPTION, 0, false);
            }
            return $stats;
        }

        foreach ($userIds as $uid) {
            $uid = (int) $uid;
            $stats['checked']++;

            try {
                $result = $this->reconciler !== null
                    ? ($this->reconciler)($uid)
                    : $this->gate->reconcileForUser($uid);
                if ($result['joined'] > 0) {
                    $stats['joins_total'] += $result['joined'];
                    $stats['users_touched']++;
                }
            } catch (\Throwable $e) {
                $stats['errored']++;
                \BCC\Core\Log\Logger::warning('[bcc-trust] Holder-group reconcile failed for a user; moving on', [
                    'user_id' => $uid,
                    'error'   => $e->getMessage(),
                ]);
            }

            // Saved AFTER this user is done (or has failed and been logged),
            // so a tick that dies here resumes at the next user, not past
            // the rest of the batch.
            update_option(self::CURSOR_OPTION, $uid, false);
        }

        // A short batch reached the end: wrap, now that all of it is done.
        if (count($userIds) < self::BATCH_SIZE) {
            update_option(self::CURSOR_OPTION, 0, false);
        }

        return $stats;
    }
}
