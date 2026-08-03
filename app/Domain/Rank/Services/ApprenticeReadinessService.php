<?php
/**
 * ApprenticeReadinessService — the §5 New Member → Apprentice path
 * (Rank Phase 5; approved plan R1).
 *
 * Readiness (§5.1): minimum profile setup (QuestValidator's
 * complete_profile rule) + at least one approved verified identity
 * (email OR wallet) + a qualifying first contribution (post / blog /
 * comment — reviews can NOT qualify, §5.2: New Members are not
 * eligible for reputation-bearing actions). The FIRST qualifying
 * contribution starts the 24-hour confirmation clock (rank_pending,
 * INSERT IGNORE).
 *
 * Confirmation (R1 — NO HELD state): pending reports and
 * report-volume auto-hide have ZERO effect on the clock. At due_at the
 * sweep awards Apprentice iff all six conditions hold:
 *   1. the contribution row still exists;
 *   2. the author has not self-deleted it (post not trashed/deleted);
 *   3. no administrator/moderator has independently removed it
 *      (covered by 1–2 for removals and by 4 for report-actioned
 *      hides — auto-hides via wp_bcc_hidden_activities leave the
 *      wp_posts row published and DO NOT void);
 *   4. no report against it is resolved with action taken (status=1);
 *   5. no formal moderation finding (Phase 8 introduces findings;
 *      none can exist yet);
 *   6. the author is not suspended.
 * A report upheld AFTER promotion reverses the contribution evidence
 * through the ordinary ledger path — never a silent demotion.
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign Phase 5 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

use BCC\Trust\Core\Repositories\ContentReportRepository;
use BCC\Trust\Rank\Repositories\RankPendingRepository;
use BCC\Trust\Rank\Repositories\RankStateRepository;

if (!defined('ABSPATH')) {
    exit;
}

class ApprenticeReadinessService
{
    /** §5.2 — the contribution must survive 24 hours. */
    private const CONFIRMATION_SECONDS = 86400;

    public function __construct(
        private readonly RankStateRepository $rankState,
        private readonly RankPendingRepository $pending,
        private readonly ContentReportRepository $reports,
        private readonly \BCC\Trust\Core\Application\WalletVerificationReadService $wallets
    ) {
    }

    /**
     * Hook entry — a potentially-qualifying contribution just published.
     * Starts the confirmation clock when the member is a New Member
     * whose profile + identity readiness already holds. Idempotent
     * (PK user_id INSERT IGNORE; ranked members short-circuit).
     */
    public function onQualifyingContribution(int $userId, string $sourceType, int $sourceId, int $actId): void
    {
        if ($userId <= 0 || $sourceId <= 0) {
            return;
        }
        if ($this->rankState->getForUser($userId) !== null) {
            return; // Already ranked.
        }
        if (!$this->profileReady($userId) || !$this->identityVerified($userId)) {
            // Not ready yet — a later contribution after readiness
            // completes will start the clock (§5.4).
            return;
        }

        $this->pending->start($userId, $sourceType, $sourceId, $actId, self::CONFIRMATION_SECONDS);
    }

    /**
     * The 5-minute sweep body — resolve every due confirmation via the
     * R1 predicate. Bounded by the repository's sweep batch.
     *
     * @return int Apprentices awarded this tick.
     */
    public function runConfirmationSweep(): int
    {
        $awarded = 0;

        foreach ($this->pending->listDue() as $row) {
            $userId = (int) $row->user_id;

            $voidReason = $this->confirmationBlocker(
                $userId,
                (int) $row->source_id,
                (int) $row->content_act_id
            );

            if ($voidReason !== null) {
                $this->pending->resolve($userId, 'voided', $voidReason);
                \BCC\Core\Log\Logger::info('[bcc-trust] apprentice confirmation voided', [
                    'user_id' => $userId,
                    'reason'  => $voidReason,
                ]);
                continue;
            }

            if (!$this->rankState->awardApprentice($userId)) {
                continue; // Row already exists (race) — leave pending resolution to the winner.
            }
            $this->pending->resolve($userId, 'confirmed');
            $awarded++;

            \BCC\Core\Log\Logger::audit('rank_apprentice_awarded', ['user_id' => $userId]);
            // The existing promotion chain: bell notification +
            // celebration stash both subscribe to this action.
            do_action('bcc_rank_awarded', $userId, 'apprentice', '');
        }

        return $awarded;
    }

    /**
     * The R1 six-condition predicate. Returns null when the award may
     * proceed, else the machine-readable void reason. Pending reports
     * (status 0) and auto-hides are deliberately NOT consulted.
     */
    private function confirmationBlocker(int $userId, int $sourceId, int $actId): ?string
    {
        $post = get_post($sourceId);
        if ($post === null) {
            return 'content_removed'; // Conditions 1–3: deleted or removed.
        }
        if ($post->post_status !== 'publish') {
            return 'content_unpublished'; // Trash/draft — self-delete or removal.
        }
        if ((int) $post->post_author !== $userId) {
            return 'author_mismatch';
        }

        // Condition 4: a report resolved WITH ACTION (status=1). Reports
        // key feed items by activity id.
        if ($actId > 0 && $this->reports->hasUpheldForTarget('feed_item', $actId)) {
            return 'report_upheld';
        }

        // Condition 5: formal findings — Phase 8 machinery; none exist yet.

        // Condition 6: suspension.
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)) {
            return 'suspended';
        }

        return null;
    }

    /**
     * §5.1 readiness snapshot for the /me/progression New Member view.
     *
     * @return array{profile_setup: bool, verified_identity: bool, qualifying_contribution: bool, confirmation_due_at: string|null}
     */
    public function readinessFor(int $userId): array
    {
        $pending = $this->pending->getForUser($userId);

        return [
            'profile_setup'           => $this->profileReady($userId),
            'verified_identity'       => $this->identityVerified($userId),
            'qualifying_contribution' => $pending !== null,
            'confirmation_due_at'     => $pending !== null && $pending->status === 'pending'
                ? (string) $pending->due_at
                : null,
        ];
    }

    private function profileReady(int $userId): bool
    {
        return \BCC\Trust\Core\Services\Quest\QuestValidator::validate($userId, 'complete_profile');
    }

    private function identityVerified(int $userId): bool
    {
        $emailVerified = get_user_meta($userId, \BCC\Trust\Core\REST\Auth\AuthSupport::META_EMAIL_VERIFIED, true) === '1';

        return $emailVerified || $this->wallets->hasVerifiedWallet($userId);
    }
}
