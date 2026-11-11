<?php

namespace BCC\Trust\Core\Services\Admin;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\SuspensionRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Services\Vote\VoteRemovalService;
use BCC\Trust\Core\Services\TrustScoreService;
use BCC\Core\Log\Logger;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Infrastructure\EdgeCache;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Moderation Service
 *
 * Migrated from includes/admin/moderation-handlers.php.
 * Contains all admin moderation action logic: suspend/unsuspend users,
 * clear votes/fingerprints, reanalyze, score override, ring actions,
 * whitelist management, and bulk actions.
 *
 * @package BCC\Trust\Core\Services\Admin
 */
final class ModerationService
{
    private ScoreRepository $scoreRepository;
    private UserInfoRepository $userInfoRepository;
    private VoteRemovalService $voteRemovalService;
    private SuspensionRepository $suspensionRepository;
    private \BCC\Trust\Core\Services\EliteOverridePin $elitePin;
    private \BCC\Trust\Core\Services\ScoreOverrideLedger $overrideLedger;
    /** @var (\Closure(int): void)|null re-evaluates a page's elite flag; null = Plugin's service */
    private ?\Closure $eliteRecompute;

    public function __construct(
        ScoreRepository $scoreRepository,
        UserInfoRepository $userInfoRepository,
        VoteRemovalService $voteRemovalService,
        ?\BCC\Trust\Core\Services\EliteOverridePin $elitePin = null,
        ?\BCC\Trust\Core\Services\ScoreOverrideLedger $overrideLedger = null,
        ?\Closure $eliteRecompute = null
    ) {
        $this->scoreRepository      = $scoreRepository;
        $this->userInfoRepository   = $userInfoRepository;
        $this->voteRemovalService   = $voteRemovalService;
        $this->suspensionRepository = \BCC\Trust\Core\Plugin::instance()->suspensionRepository();
        $this->elitePin             = $elitePin ?? new \BCC\Trust\Core\Services\EliteOverridePin();
        $this->overrideLedger       = $overrideLedger ?? new \BCC\Trust\Core\Services\ScoreOverrideLedger();
        $this->eliteRecompute       = $eliteRecompute;
    }

    // ──────────────────────────────────────────────────────────────
    // SUSPEND / UNSUSPEND
    // ──────────────────────────────────────────────────────────────

    /** Why a suspension was refused or skipped (F-114). */
    private const SUSPEND_SKIP_LABELS = [
        'self'              => 'you cannot suspend yourself',
        'admin'             => 'an administrator cannot be suspended; remove their admin role first',
        'already_suspended' => 'already suspended',
    ];

    /** Per-admin, short-lived: the names a bulk or ring suspend skipped, for the next page view. */
    private const SUSPEND_SKIPS_TRANSIENT = 'bcc_trust_suspend_skips_';

    /**
     * The one guard for every suspend path: single, bulk and ring (F-114).
     * Null = go ahead.
     *
     *  - 'self': the acting admin;
     *  - 'admin': a manage_options account (demote first, user decision);
     *  - 'already_suspended': re-suspending wrote a SECOND suspensions row and
     *    overwrote the original reason in fraud_triggers.
     *
     * The suspended read goes to the table, not the row cache.
     *
     * @return 'self'|'admin'|'already_suspended'|null
     */
    private function suspensionRefusal(int $userId, int $adminId): ?string
    {
        if ($userId === $adminId) {
            return 'self';
        }
        if (user_can($userId, 'manage_options')) {
            return 'admin';
        }
        if ($this->userInfoRepository->getSuspendedUserIds([$userId]) !== []) {
            return 'already_suspended';
        }
        return null;
    }

    /**
     * Suspend atomically, then the after-commit work.
     *
     * Atomic: the suspensions row and the user_info gate flag must land
     * together. Written non-transactionally, a crash between them leaves a
     * suspensions row with is_suspended still 0 — an "audited but ungated"
     * window where every enforcement path (Permissions::is_not_suspended,
     * getSuspendedUserIds) still lets the account through.
     *
     * The caches are flushed AFTER the commit (F-114): flushed inside it, a
     * read before COMMIT re-cached the unsuspended state for up to the TTL.
     *
     * @throws \Throwable when the transaction fails (nothing was written)
     */
    private function suspendAtomically(int $userId, int $adminId, string $reason, string $notes): void
    {
        $this->ensureUserInfoRow($userId);
        $fraudScore = $this->userInfoRepository->getFraudScore($userId);

        \BCC\Trust\Core\Security\TransactionManager::run(function () use ($userId, $adminId, $reason, $notes, $fraudScore) {
            $this->suspensionRepository->create($userId, $adminId, $reason, $notes, $fraudScore);
            $this->userInfoRepository->suspendUser($userId, $reason);
        });

        $this->userInfoRepository->notifySuspensionChanged($userId);
        $this->revokeSessions($userId);
    }

    /**
     * Unsuspend atomically, then the after-commit cache flush (F-114).
     *
     * @throws \Throwable when the transaction fails (nothing was written)
     */
    private function unsuspendAtomically(int $userId, int $adminId): void
    {
        \BCC\Trust\Core\Security\TransactionManager::run(function () use ($userId, $adminId) {
            $this->suspensionRepository->closeActive($userId, $adminId);
            $this->userInfoRepository->unsuspendUser($userId);
        });

        $this->userInfoRepository->notifySuspensionChanged($userId);
    }

    /**
     * Remember what a bulk or ring suspend skipped, for the admin's next page.
     *
     * @param array<int, string> $skipped user id => skip key
     */
    private function rememberSuspendSkips(int $adminId, array $skipped): void
    {
        if ($skipped === []) {
            delete_transient(self::SUSPEND_SKIPS_TRANSIENT . $adminId);
            return;
        }
        set_transient(self::SUSPEND_SKIPS_TRANSIENT . $adminId, $skipped, 300);
    }

    /**
     * One line per user the current admin's last bulk or ring suspend
     * skipped, e.g. "alice: already suspended". Read once: it is cleared.
     *
     * @return list<string>
     */
    public function takeSuspendSkips(): array
    {
        $key     = self::SUSPEND_SKIPS_TRANSIENT . get_current_user_id();
        $skipped = get_transient($key);
        delete_transient($key);
        if (!is_array($skipped)) {
            return [];
        }

        $lines = [];
        foreach ($skipped as $uid => $why) {
            $user    = get_userdata((int) $uid);
            $name    = $user !== false ? (string) $user->user_login : '#' . (int) $uid;
            $lines[] = $name . ': ' . (self::SUSPEND_SKIP_LABELS[$why] ?? 'skipped');
        }
        return $lines;
    }

    /**
     * Suspend a user by inserting a suspension record and flagging user_info.
     *
     * @param int $userId The user to suspend.
     * @return array{success: bool, message: string}
     */
    public function suspendUser(int $userId, string $reason = 'manual_suspension', string $notes = ''): array
    {
        $refusal = $this->suspensionRefusal($userId, get_current_user_id());
        if ($refusal !== null) {
            $message = 'User not suspended: ' . self::SUSPEND_SKIP_LABELS[$refusal] . '.';
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            return ['success' => false, 'message' => $message];
        }

        try {
            $this->suspendAtomically($userId, get_current_user_id(), $reason, $notes);
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] suspend_failed', [
                'user_id'  => $userId,
                'db_error' => $e->getMessage(),
            ]);
            echo '<div class="notice notice-error"><p>Failed to suspend user: database error.</p></div>';
            return ['success' => false, 'message' => 'Failed to suspend user: database error.'];
        }

        $this->logAction('admin_suspend', $userId, [
            'reason' => $reason,
            'notes'  => $notes,
        ]);

        // The suspended flag rides on the member cards in the anon
        // /members payload — drop the edge copies so it shows promptly.
        EdgeCache::purge(EdgeCache::TAG_MEMBERS);

        echo '<div class="notice notice-success"><p>User suspended. Reason: ' . esc_html($reason) . '</p></div>';
        return ['success' => true, 'message' => 'User suspended. Reason: ' . $reason];
    }

    /**
     * Unsuspend a user by closing the active suspension record and clearing the flag.
     *
     * @param int $userId The user to unsuspend.
     * @return array{success: bool, message: string}
     */
    public function unsuspendUser(int $userId): array
    {
        try {
            $this->unsuspendAtomically($userId, get_current_user_id());
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] unsuspend_failed', [
                'user_id'  => $userId,
                'db_error' => $e->getMessage(),
            ]);
            echo '<div class="notice notice-error"><p>Failed to unsuspend user: database error.</p></div>';
            return ['success' => false, 'message' => 'Failed to unsuspend user: database error.'];
        }

        $this->logAction('admin_unsuspend', $userId);

        // Mirror of suspendUser — clear the stale suspended flag from
        // the edge-cached member directory.
        EdgeCache::purge(EdgeCache::TAG_MEMBERS);

        echo '<div class="notice notice-success"><p>User unsuspended.</p></div>';
        return ['success' => true, 'message' => 'User unsuspended.'];
    }

    // ──────────────────────────────────────────────────────────────
    // CLEAR VOTES
    // ──────────────────────────────────────────────────────────────

    /**
     * Remove all active votes cast by a user (moderation removal: restorable,
     * re-vote blocked) and recalculate the affected pages.
     *
     * Delegates to VoteRemovalService, the single bulk-removal path, which
     * leaves dispute-removed votes untouched and audits the removed vote ids.
     *
     * @param int $userId The voter whose votes to clear.
     * @return array{success: bool, message: string, pages_affected: int}
     */
    public function clearVotes(int $userId): array
    {
        try {
            $result = $this->voteRemovalService
                ->removeVotesCastBy([$userId], 'admin_clear_votes', get_current_user_id());
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] clear_votes_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            echo '<div class="notice notice-error"><p>Failed to clear votes: database error. Nothing was removed.</p></div>';
            return ['success' => false, 'message' => 'Failed to clear votes: database error.', 'pages_affected' => 0];
        }

        $message = sprintf(
            'Votes cleared: %d removed across %d pages (%d recalculated now, %d queued for the recalculation cron).',
            $result['removed'],
            $result['pages'],
            $result['recalculated'],
            $result['queued']
        );
        echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        return ['success' => true, 'message' => $message, 'pages_affected' => $result['pages']];
    }

    /**
     * Restore every vote of this user that moderation removed (status 3 → 1)
     * and is not yet purged, then recalculate the affected pages. The undo
     * for clearVotes() / ring invalidation, per voter.
     *
     * @param int $userId The voter whose moderated votes to restore.
     * @return array{success: bool, message: string, pages_affected: int}
     */
    public function restoreVotes(int $userId): array
    {
        try {
            $result = $this->voteRemovalService
                ->restoreModeratedVotesCastBy([$userId], 'admin_restore_votes', get_current_user_id());
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] restore_votes_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            echo '<div class="notice notice-error"><p>Failed to restore votes: database error. Nothing was restored.</p></div>';
            return ['success' => false, 'message' => 'Failed to restore votes: database error.', 'pages_affected' => 0];
        }

        $message = sprintf(
            'Votes restored: %d across %d pages (%d recalculated now, %d queued for the recalculation cron).',
            $result['removed'],
            $result['pages'],
            $result['recalculated'],
            $result['queued']
        );
        echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        return ['success' => true, 'message' => $message, 'pages_affected' => $result['pages']];
    }

    /**
     * How many of this user's moderation-removed votes restoreVotes() would
     * bring back. Null when the count cannot be read (the button is then
     * hidden rather than claiming zero).
     */
    public function restorableVoteCount(int $userId): ?int
    {
        try {
            return $this->voteRemovalService->countRestorableFor($userId);
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] restorable_vote_count_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────────
    // RESET FRAUD SCORE
    // ──────────────────────────────────────────────────────────────

    /**
     * Admin reset of a member's fraud score — current AND peak — to 0
     * (F-103, user decision). The undo for a wrongly accumulated score (e.g.
     * a household that shared a device before the once-per-window rule).
     *
     * The score and peak go to 0. (Shared devices no longer add points at
     * all since F-184, so there is no per-device penalty record to keep or
     * clear.) Drops cached fraud / rate-limit state and flags every
     * page the member voted on so the fraud discount on their votes is
     * recomputed. Audited with the replaced values.
     *
     * @return array{success: bool, message: string}
     */
    public function resetFraudScore(int $userId): array
    {
        try {
            $previous = $this->userInfoRepository->resetFraudScore($userId);
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] reset_fraud_score_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            echo '<div class="notice notice-error"><p>Failed to reset the fraud score: database error.</p></div>';
            return ['success' => false, 'message' => 'Failed to reset the fraud score: database error.'];
        }

        if ($previous === null) {
            echo '<div class="notice notice-error"><p>No trust record exists for this user, so there is nothing to reset.</p></div>';
            return ['success' => false, 'message' => 'No trust record for this user.'];
        }

        \BCC\Trust\Core\Security\FraudDetector::clearCache($userId);
        \BCC\Trust\Core\Security\RateLimiter::invalidateUserCache($userId);
        $pagesFlagged = $this->userInfoRepository->markVotedPagesForRecalculation($userId);

        $this->logAction('admin_fraud_score_reset', $userId, [
            'previous_fraud_score' => $previous['fraud_score'],
            'previous_peak_score'  => $previous['peak_fraud_score'],
            'pages_flagged'        => $pagesFlagged,
        ]);

        $message = sprintf(
            'Fraud score reset to 0 (was %d, peak %d). %d voted pages queued for recalculation. The score reflects live signals and can rise again if new ones appear.',
            $previous['fraud_score'],
            $previous['peak_fraud_score'],
            $pagesFlagged
        );
        echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        return ['success' => true, 'message' => $message];
    }

    // ──────────────────────────────────────────────────────────────
    // CLEAR FINGERPRINTS
    // ──────────────────────────────────────────────────────────────

    /**
     * Delete all device fingerprints for a user and reset fingerprint-related fields.
     *
     * @param int $userId The user whose fingerprints to clear.
     * @return array{success: bool, message: string}
     */
    public function clearFingerprints(int $userId): array
    {
        // Delete fingerprint records
        (new \BCC\Trust\Core\Repositories\DeviceFingerprintRepository())->deleteForUser($userId);

        // Clear fingerprint/automation data in user_info
        $this->userInfoRepository->clearFingerprint($userId);

        $this->logAction('admin_clear_fingerprints', $userId);

        echo '<div class="notice notice-success"><p>Device fingerprints cleared.</p></div>';
        return ['success' => true, 'message' => 'Device fingerprints cleared.'];
    }

    // ──────────────────────────────────────────────────────────────
    // REANALYZE USER
    // ──────────────────────────────────────────────────────────────

    /**
     * Re-run fraud analysis for a user via FraudDetector and update their scores.
     *
     * @param int $userId The user to reanalyze.
     * @return array{success: bool, message: string, new_score?: int}
     */
    public function reanalyzeUser(int $userId): array
    {
        $newScore = $this->reanalyze($userId);

        $this->logAction('admin_reanalyze', $userId, ['new_score' => $newScore]);

        echo '<div class="notice notice-success"><p>Reanalysis complete. New fraud score: <strong>' . intval($newScore) . '</strong></p></div>';
        return ['success' => true, 'message' => 'Reanalysis complete.', 'new_score' => (int) $newScore];
    }

    /**
     * Recompute and PERSIST a user's fraud fields; returns the new score.
     * The one reanalysis for the single and bulk actions: bulk used to only
     * clear the detector cache and report success (F-114).
     *
     * @throws \Throwable when the analysis or the write fails
     */
    private function reanalyze(int $userId): int
    {
        \BCC\Trust\Core\Security\FraudDetector::clearCache($userId);
        $newScore = (int) \BCC\Trust\Core\Security\FraudDetector::getEnhancedFraudScore($userId);
        $analysis = \BCC\Trust\Core\Security\FraudDetector::analyzeFraud($userId);

        $this->userInfoRepository->updateFraudFields(
            $userId,
            $newScore,
            $analysis['risk_level'] ?? 'unknown',
            (int) ($analysis['details']['behavior_score'] ?? 0)
        );

        return $newScore;
    }

    // ──────────────────────────────────────────────────────────────
    // MANUAL TRUST SCORE OVERRIDE
    // ──────────────────────────────────────────────────────────────

    /**
     * Override the trust score for all pages owned by a user.
     *
     * Persistence model (audit conflict #11 fix): the override is stored
     * as ADMIN FIAT in `penalty_adjustment` — the clobber-safe accumulator
     * already inside the canonical formula — NOT as a write to the
     * `total_score` OUTPUT column. The previous implementation wrote the
     * output directly and then fired `bcc.trust.recalculate_score`, whose
     * subscriber recomputed the score from inputs and reverted the
     * override milliseconds later (and every later vote/cron recalc would
     * have reverted it anyway). Because the adjustment is a formula input,
     * every subsequent recalc now REPRODUCES the admin's target instead of
     * erasing it.
     *
     * @param int $userId   The page owner whose scores to override.
     * @param int $newScore The new score (clamped 0-100).
     * @return array{success: bool, message: string, tier?: string, pages_updated?: int}
     */
    public function overrideScore(int $userId, int $newScore, string $reason = ''): array
    {
        $newScore = min(100, max(0, $newScore));

        if (empty($reason)) {
            echo '<div class="notice notice-error"><p>Override reason is required.</p></div>';
            return ['success' => false, 'message' => 'Override reason is required.'];
        }

        // The admin's INTENT: does this target grant Elite? Evaluated as fiat
        // (gate and native floor treated as met), it decides only the elite
        // PIN below, which keeps the eligibility flag set until an admin
        // removes it.
        //
        // ⚠ It is NOT the tier the pages end up with (F-177). The stored tier
        // still applies the native-conduct floor, where native = the score
        // WITHOUT onchain_bonus (user decision: on-chain depth alone must not
        // mint Elite). So the notice reports each page's real tier, read back
        // below, and never this intent.
        $grantElite = TrustScoreService::tierFor((float) $newScore, (float) $newScore, true) === 'elite';

        // One override or undo per user at a time: the ledger is a
        // read-modify-write, and two admins racing would double-apply.
        $lock = 'bcc_score_override_' . $userId;
        if (!\BCC\Core\DB\AdvisoryLock::acquire($lock, 5)) {
            echo '<div class="notice notice-error"><p>Another score change for this member is in progress. Try again.</p></div>';
            return ['success' => false, 'message' => 'Another score change for this member is in progress.'];
        }

        $updated  = 0;
        $now      = gmdate('Y-m-d H:i:s');
        $adminId  = (int) get_current_user_id();
        $ledgerId = '';
        /** @var array<int, array{delta: float, prev_pin: array{admin_id: int, granted_at: string}|null}> $ledgerPages */
        $ledgerPages = [];
        /** @var array<int, string> $pageTiers page id => the tier the page actually has now */
        $pageTiers = [];

        try {
            $affectedPageIds = $this->scoreRepository->getPageIdsByOwner($userId);

            foreach ($affectedPageIds as $pid) {
                $pid = (int) $pid;

                // 1. Fresh canonical recompute FIRST, so the delta below is
                //    measured against the value every future recalc will
                //    reproduce — not against a stale row.
                do_action('bcc.trust.recalculate_score', $pid);

                $row     = $this->scoreRepository->getByPageId($pid);
                $current = $row !== null ? $row->getTotalScore() : 50.0;
                $delta   = (float) $newScore - $current;

                // 2. Accumulate the difference as admin fiat. applyPenalty
                //    recomputes total_score + reputation_tier inline via the
                //    canonical formula, mirrors the tier, and invalidates the
                //    score cache — total_score lands exactly on the target.
                $applied = 0.0;
                try {
                    if (abs($delta) > 0.0001) {
                        $this->scoreRepository->applyPenalty($pid, $delta);
                        $applied = $delta;
                    }
                    $updated++;
                } catch (\Throwable $e) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] score override failed for page', [
                        'page_id' => $pid,
                        'user_id' => $userId,
                        'error'   => $e->getMessage(),
                    ]);
                    continue;
                }

                // F-113: record exactly what changed, so undo can reverse it.
                $ledgerPages[$pid] = ['delta' => $applied, 'prev_pin' => $this->elitePin->read($pid)];

                // 3. Elite. An override TO elite STICKS until an admin
                //    removes it (product decision): the pin is what the
                //    nightly sweep honours. Any other target REMOVES a pin
                //    and re-evaluates the flag, so an earlier manual grant
                //    can no longer outlive an override down.
                if ($grantElite) {
                    $this->elitePin->pin($pid, $adminId, $now);
                    $this->scoreRepository->setEliteEligibility($pid, true, $now);
                } else {
                    $this->elitePin->unpin($pid);
                }

                // 4. Second recompute now REPRODUCES the target (the adjustment
                //    is a formula input) and pushes it to the read model via the
                //    priority-20 subscriber — proving durability on the spot.
                do_action('bcc.trust.recalculate_score', $pid);

                if (!$grantElite) {
                    $this->recomputeElite($pid);
                }

                $pageTiers[$pid] = $this->resultingTier($pid);
            }

            $ledgerId = $ledgerPages === []
                ? ''
                : $this->overrideLedger->push($userId, $adminId, $newScore, $reason, $now, $ledgerPages);
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lock);
        }

        $tierSummary = self::summariseTiers($pageTiers);

        $this->logAction('admin_score_override', $userId, [
            'new_score'     => $newScore,
            'new_tier'      => $tierSummary,
            'page_tiers'    => $pageTiers,
            'reason'        => $reason,
            'pages_updated' => $updated,
            'ledger_id'     => $ledgerId,
            'elite_pinned'  => $grantElite,
        ]);

        $eliteNote = '';
        if ($grantElite) {
            $eliteNote = ' Elite granted manually; stays until removed.';
            $belowFloor = count(array_filter($pageTiers, static fn (string $t): bool => $t !== 'elite'));
            if ($belowFloor > 0) {
                $eliteNote .= sprintf(
                    ' %d page(s) stay below Elite until their native score (without the on-chain bonus) reaches %d.',
                    $belowFloor,
                    (int) TrustScoreService::eliteNativeFloor()
                );
            }
        }
        $undoNote  = ($ledgerPages !== [] && $ledgerId === '')
            ? ' ⚠ The undo record could not be saved, so this override cannot be undone.'
            : '';
        echo '<div class="notice notice-success"><p>Trust score overridden to <strong>' . $newScore . '</strong> (' . esc_html($tierSummary) . ') for ' . intval($updated) . ' page(s). Reason: ' . esc_html($reason) . '.' . esc_html($eliteNote . $undoNote) . '</p></div>';
        return ['success' => true, 'message' => 'Trust score overridden.', 'tier' => $tierSummary, 'page_tiers' => $pageTiers, 'pages_updated' => $updated, 'ledger_id' => $ledgerId];
    }

    /**
     * The tier a page has NOW, by the canonical ladder with the same inputs
     * the stored tier uses (F-177): the total score, the NATIVE score
     * (without onchain_bonus, clamped independently: computeNative) and the
     * resolved elite gate. Read back after the override's recompute, so the
     * admin notice can never claim a tier the page did not get.
     */
    private function resultingTier(int $pageId): string
    {
        $row = $this->scoreRepository->getByPageId($pageId);
        if ($row === null) {
            return 'unknown';
        }
        $native = TrustScoreService::computeNative(
            $row->getPositiveScore(),
            $row->getNegativeScore(),
            $row->getContributionBonus(),
            $row->getPenaltyAdjustment(),
            $row->getAttestationBonus()
        );
        // isEliteEligible() already resolves the never-evaluated grandfather case.
        return TrustScoreService::tierFor($row->getTotalScore(), $native, $row->isEliteEligible());
    }

    /**
     * "elite", or for pages that differ "elite ×1, trusted ×2".
     *
     * @param array<int, string> $pageTiers
     */
    private static function summariseTiers(array $pageTiers): string
    {
        $counts = array_count_values($pageTiers);
        if (count($counts) <= 1) {
            return (string) (array_key_first($counts) ?? 'no pages');
        }
        $parts = [];
        foreach ($counts as $tier => $n) {
            $parts[] = $tier . ' ×' . $n;
        }
        return implode(', ', $parts);
    }

    /**
     * Undo the member's LATEST not-yet-undone score override (F-113).
     *
     * Applies the inverse of each page's recorded delta (penalties are
     * additive, so this cancels exactly even if dispute penalties landed
     * since), restores the elite pin that existed before the override, then
     * recomputes the score and the elite flag. Refuses when there is nothing
     * to undo. ⚠ Only the last ScoreOverrideLedger::MAX_ENTRIES overrides per
     * member are undoable.
     *
     * @return array{success: bool, message: string, pages_restored?: int}
     */
    public function undoScoreOverride(int $userId): array
    {
        $lock = 'bcc_score_override_' . $userId;
        if ($userId <= 0 || !\BCC\Core\DB\AdvisoryLock::acquire($lock, 5)) {
            echo '<div class="notice notice-error"><p>Another score change for this member is in progress. Try again.</p></div>';
            return ['success' => false, 'message' => 'Another score change for this member is in progress.'];
        }

        $restored = 0;
        try {
            $entry = $this->overrideLedger->latestUndoable($userId);
            if ($entry === null) {
                echo '<div class="notice notice-error"><p>There is no score override to undo for this member.</p></div>';
                return ['success' => false, 'message' => 'There is no score override to undo.'];
            }

            // Mark FIRST: a crash mid-way must not leave the entry undoable,
            // or a retry would apply the inverse twice.
            if (!$this->overrideLedger->markUndone($userId, $entry['id'])) {
                echo '<div class="notice notice-error"><p>Could not record the undo; nothing was changed.</p></div>';
                return ['success' => false, 'message' => 'Could not record the undo; nothing was changed.'];
            }

            foreach ($entry['pages'] as $pidKey => $page) {
                $pid = (int) $pidKey;
                try {
                    if (abs($page['delta']) > 0.0001) {
                        $this->scoreRepository->applyPenalty($pid, -$page['delta']);
                    }
                } catch (\Throwable $e) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] score override undo failed for page', [
                        'page_id' => $pid,
                        'user_id' => $userId,
                        'error'   => $e->getMessage(),
                    ]);
                    continue;
                }

                $prev = $page['prev_pin'];
                if ($prev !== null) {
                    $this->elitePin->pin($pid, $prev['admin_id'], $prev['granted_at']);
                } else {
                    $this->elitePin->unpin($pid);
                }

                do_action('bcc.trust.recalculate_score', $pid);
                $this->recomputeElite($pid);
                $restored++;
            }
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lock);
        }

        $this->logAction('admin_score_override_undone', $userId, [
            'ledger_id'      => $entry['id'],
            'undone_score'   => $entry['new_score'],
            'pages_restored' => $restored,
        ]);

        echo '<div class="notice notice-success"><p>Score override undone for ' . intval($restored) . ' page(s).</p></div>';
        return ['success' => true, 'message' => 'Score override undone.', 'pages_restored' => $restored];
    }

    /**
     * What undoScoreOverride() would undo next, for the admin Undo button:
     * the latest not-yet-undone override. Null when there is nothing to
     * undo or the record cannot be read; the button is then hidden.
     *
     * @return array{new_score: int, at: string, reason: string}|null
     */
    public function latestUndoableOverride(int $userId): ?array
    {
        try {
            $entry = $this->overrideLedger->latestUndoable($userId);
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] latest_undoable_override_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
        if ($entry === null) {
            return null;
        }
        return ['new_score' => $entry['new_score'], 'at' => $entry['at'], 'reason' => $entry['reason']];
    }

    /** Re-evaluate a page's elite flag (it honours the pin). */
    private function recomputeElite(int $pageId): void
    {
        if ($this->eliteRecompute !== null) {
            ($this->eliteRecompute)($pageId);
            return;
        }
        \BCC\Trust\Core\Plugin::instance()->eliteEligibilityService()->recomputeFor($pageId);
    }

    // ──────────────────────────────────────────────────────────────
    // VOTE RING ACTIONS
    // ──────────────────────────────────────────────────────────────

    /**
     * Handle vote ring moderation actions (invalidate votes or suspend all members).
     *
     * Expects the nonce 'bcc_trust_ring_action' to have been verified by the caller.
     *
     * @param array  $userIds Array of user IDs in the ring.
     * @param int[]  $userIds
     * @param string $action  One of 'invalidate_votes' or 'suspend_all'.
     * @param string $note    suspend_all: the admin's reason, kept as the suspension note.
     * @return void
     */
    public function handleRingAction(array $userIds, string $action, string $note = ''): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('bcc_trust_ring_action');

        $userIds = array_filter(array_map('absint', $userIds));

        if (empty($userIds)) {
            wp_safe_redirect(add_query_arg('ring_msg', 'no_users', wp_get_referer()));
            exit;
        }

        $adminId = get_current_user_id();

        switch ($action) {

            case 'invalidate_votes':
                // Remove every active vote cast BY ring members — including
                // their votes on each other's pages, which is how a ring
                // inflates scores. Third-party votes on ring-owned pages are
                // deliberately NOT removed here (product decision, F-104):
                // they come from people outside the ring. Moderation status,
                // restorable, dispute-removed rows untouched, vote ids audited.
                try {
                    $result = $this->voteRemovalService
                        ->removeVotesCastBy(array_values($userIds), 'admin_ring_invalidate_votes', $adminId);
                    $msg = 'invalidated_' . $result['pages'];
                } catch (\Throwable $e) {
                    Logger::error('[bcc-trust] ring_invalidate_votes_failed', [
                        'ring_users' => $userIds,
                        'error'      => $e->getMessage(),
                    ]);
                    // Unrecognised by the Rings tab on purpose: it renders
                    // the "check the application log" warning. Nothing was
                    // removed (the removal is one transaction).
                    $msg = 'invalidate_failed';
                }
                break;

            case 'suspend_all':
                // The Rings form REQUIRES a reason; it used to be dropped
                // for a fixed note (F-114). The reason code stays 'vote_ring'.
                $note      = trim($note) !== '' ? $note : 'Suspended as part of vote ring action.';
                $suspended = 0;
                /** @var array<int, string> $skipped */
                $skipped   = [];
                foreach ($userIds as $uid) {
                    $refusal = $this->suspensionRefusal($uid, $adminId);
                    if ($refusal !== null) {
                        $skipped[$uid] = $refusal;
                        continue;
                    }

                    try {
                        $this->suspendAtomically($uid, $adminId, 'vote_ring', $note);
                        $suspended++;
                    } catch (\Throwable $e) {
                        // Skip this user but keep going; count only the ones
                        // that actually landed atomically.
                        Logger::error('[bcc-trust] ring_suspend_failed', [
                            'user_id'  => $uid,
                            'db_error' => $e->getMessage(),
                        ]);
                    }
                }

                $this->logAction('admin_ring_suspend_all', 0, [
                    'ring_users' => $userIds,
                    'suspended'  => $suspended,
                    'skipped'    => $skipped,
                    'note'       => $note,
                ]);
                $this->rememberSuspendSkips($adminId, $skipped);

                $msg = 'suspended_' . $suspended . '_skipped_' . count($skipped);
                break;

            default:
                $msg = 'unknown_action';
        }

        wp_safe_redirect(add_query_arg(['tab' => 'rings', 'ring_msg' => $msg],
            admin_url('admin.php?page=bcc-trust-dashboard')));
        exit;
    }

    // ──────────────────────────────────────────────────────────────
    // IP / DEVICE WHITELIST
    // ──────────────────────────────────────────────────────────────

    /**
     * Add an IP or fingerprint to the whitelist.
     *
     * @param string $type  One of 'ip' or 'fingerprint'.
     * @param string $value The IP address or fingerprint hash.
     * @return array{success: bool, message: string}
     */
    public function addToWhitelist(string $type, string $value): array
    {
        $note = sanitize_text_field($_POST['whitelist_note'] ?? '');

        if (empty($value) || !in_array($type, ['ip', 'fingerprint'], true)) {
            echo '<div class="notice notice-error"><p>Invalid whitelist entry.</p></div>';
            return ['success' => false, 'message' => 'Invalid whitelist entry.'];
        }

        $list = $this->getWhitelist($type);
        if (!isset($list[$value])) {
            $list[$value] = [
                'added_by' => get_current_user_id(),
                'added_at' => current_time('mysql'),
                'note'     => $note,
            ];
            update_option("bcc_trust_whitelist_{$type}", $list, false);
            $this->logAction('admin_whitelist_add', 0, ['type' => $type, 'value' => $value]);
        }

        echo '<div class="notice notice-success"><p>' . esc_html(strtoupper($type)) . ' <code>' . esc_html($value) . '</code> added to whitelist.</p></div>';
        return ['success' => true, 'message' => strtoupper($type) . ' ' . $value . ' added to whitelist.'];
    }

    /**
     * Remove an IP or fingerprint from the whitelist.
     *
     * @param string $type  One of 'ip' or 'fingerprint'.
     * @param string $value The IP address or fingerprint hash.
     * @return array{success: bool, message: string}
     */
    public function removeFromWhitelist(string $type, string $value): array
    {
        if (empty($value) || !in_array($type, ['ip', 'fingerprint'], true)) {
            return ['success' => false, 'message' => 'Invalid whitelist entry.'];
        }

        $list = $this->getWhitelist($type);
        unset($list[$value]);
        update_option("bcc_trust_whitelist_{$type}", $list, false);

        $this->logAction('admin_whitelist_remove', 0, ['type' => $type, 'value' => $value]);

        echo '<div class="notice notice-success"><p>Removed <code>' . esc_html($value) . '</code> from whitelist.</p></div>';
        return ['success' => true, 'message' => 'Removed ' . $value . ' from whitelist.'];
    }

    // ──────────────────────────────────────────────────────────────
    // BULK USER ACTION
    // ──────────────────────────────────────────────────────────────

    /**
     * Handle bulk moderation actions on multiple users.
     *
     * Expects the nonce 'bcc_trust_bulk_action' to have been verified by the caller.
     *
     * @param array  $userIds Array of user IDs.
     * @param int[]  $userIds
     * @param string $action  One of 'suspend', 'unsuspend', 'clear_votes', 'reanalyze'.
     * @return void
     */
    public function handleBulkAction(array $userIds, string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('bcc_trust_bulk_action');

        $userIds = array_filter(array_map('absint', $userIds));

        if ($action === '-1' || empty($userIds)) {
            wp_safe_redirect(add_query_arg('bulk', 'no_action', wp_get_referer()));
            exit;
        }

        $adminId   = get_current_user_id();
        $processed = 0;
        /** @var array<int, string> $skipped */
        $skipped   = [];

        foreach ($userIds as $uid) {
            switch ($action) {
                case 'suspend':
                    $refusal = $this->suspensionRefusal($uid, $adminId);
                    if ($refusal !== null) {
                        $skipped[$uid] = $refusal;
                        break;
                    }
                    try {
                        $this->suspendAtomically($uid, $adminId, 'manual_suspension', '');
                        $processed++;
                    } catch (\Throwable $e) {
                        // Log + skip; count only users who actually suspended,
                        // so the "N processed" report can't claim a swallowed
                        // failure as success.
                        Logger::error('[bcc-trust] bulk_suspend_failed', [
                            'user_id'  => $uid,
                            'db_error' => $e->getMessage(),
                        ]);
                    }
                    break;

                case 'unsuspend':
                    try {
                        $this->unsuspendAtomically($uid, $adminId);
                        $processed++;
                    } catch (\Throwable $e) {
                        Logger::error('[bcc-trust] bulk_unsuspend_failed', [
                            'user_id'  => $uid,
                            'db_error' => $e->getMessage(),
                        ]);
                    }
                    break;

                case 'clear_votes':
                    // Same single removal path as clearVotes(): recalc,
                    // votes_cast reset, caches and an audit entry with the
                    // vote ids — the bulk case used to do none of that (F-81).
                    try {
                        $this->voteRemovalService
                            ->removeVotesCastBy([$uid], 'admin_clear_votes', $adminId);
                        $processed++;
                    } catch (\Throwable $e) {
                        Logger::error('[bcc-trust] bulk_clear_votes_failed', [
                            'user_id'  => $uid,
                            'db_error' => $e->getMessage(),
                        ]);
                    }
                    break;

                case 'reanalyze':
                    // The same recompute + persist as reanalyzeUser(); it
                    // used to clear the detector cache only (F-114).
                    try {
                        $this->reanalyze($uid);
                        $processed++;
                    } catch (\Throwable $e) {
                        Logger::error('[bcc-trust] bulk_reanalyze_failed', [
                            'user_id' => $uid,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                    break;
            }
        }

        $this->logAction('admin_bulk_' . $action, 0, [
            'users'     => $userIds,
            'processed' => $processed,
            'skipped'   => $skipped,
        ]);
        $this->rememberSuspendSkips($adminId, $skipped);

        wp_safe_redirect(add_query_arg(
            ['bulk' => $processed, 'bulk_skipped' => count($skipped)],
            wp_get_referer()
        ));
        exit;
    }

    // ──────────────────────────────────────────────────────────────
    // ADMIN ACTION LOGGER
    // ──────────────────────────────────────────────────────────────

    /**
     * Log an admin moderation action to the audit trail.
     *
     * @param string $action   Action slug, e.g. 'admin_suspend'.
     * @param int                   $targetId Subject user ID (0 for ring/bulk actions).
     * @param array<string, mixed>  $data     Extra context stored as JSON.
     */
    public function logAction(string $action, int $targetId, array $data = []): void
    {
        $data['admin_id']   = get_current_user_id();
        $data['admin_name'] = wp_get_current_user()->display_name ?? '';

        AuditLogger::log($action, $targetId, $data, 'user');
    }

    // ──────────────────────────────────────────────────────────────
    // WHITELIST HELPERS (private)
    // ──────────────────────────────────────────────────────────────

    /**
     * Get the whitelist array for a given type.
     *
     * @param string $type One of 'ip' or 'fingerprint'.
     * @return array<string, mixed> Associative array [value => meta].
     */
    private function getWhitelist(string $type): array
    {
        return (array) get_option("bcc_trust_whitelist_{$type}", []);
    }

    /**
     * Returns whitelisted IPs as an associative array [ip => meta].
     *
     * @return array<string, mixed>
     */
    public function getIpWhitelist(): array
    {
        return $this->getWhitelist('ip');
    }

    /**
     * Returns whitelisted fingerprints as an associative array [fp => meta].
     *
     * @return array<string, mixed>
     */
    public function getFingerprintWhitelist(): array
    {
        return $this->getWhitelist('fingerprint');
    }

    /**
     * Returns true if the given IP is whitelisted.
     *
     * @param string $ip The IP address to check.
     * @return bool
     */
    public function isIpWhitelisted(string $ip): bool
    {
        return \BCC\Trust\Core\Security\FraudWhitelist::isIpWhitelisted($ip);
    }

    /**
     * Returns true if the given fingerprint is whitelisted.
     *
     * @param string $fp The fingerprint hash to check.
     * @return bool
     */
    public function isFingerprintWhitelisted(string $fp): bool
    {
        return \BCC\Trust\Core\Security\FraudWhitelist::isFingerprintWhitelisted($fp);
    }

    // ──────────────────────────────────────────────────────────────
    // SUSPEND-PATH HELPERS (F-112 / F-119)
    // ──────────────────────────────────────────────────────────────

    /**
     * Make sure the user has a user_info row before suspending (F-112).
     * The gate every enforcement path reads (is_suspended) lives on that
     * row, so a missing one used to make a suspension a silent no-op. Reuses
     * UserSyncService, the one place that builds a row from the WP user.
     * UserInfoRepository::suspendUser() still throws if the row is absent.
     */
    private function ensureUserInfoRow(int $userId): void
    {
        if ($userId <= 0 || $this->userInfoRepository->exists($userId)) {
            return;
        }
        \BCC\Trust\Core\Plugin::instance()->userSyncService()->sync($userId);
    }

    /**
     * Revoke every Bearer token the user holds (F-119), so a session minted
     * before the suspension stops authenticating everywhere at once, not
     * just at routes that check suspension. Called only after the suspension
     * transaction committed. Same mechanism as a password change.
     */
    private function revokeSessions(int $userId): void
    {
        \BCC\Trust\Core\Support\JwtToken::revokeAllForUser($userId);
    }
}
