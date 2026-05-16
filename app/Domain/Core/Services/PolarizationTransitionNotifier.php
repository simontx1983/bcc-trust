<?php
/**
 * PolarizationTransitionNotifier — daily cron worker that detects
 * §J.8 divergence-state transitions and fires the 24-hour heads-up
 * to the affected operator (§J.7 `divergence_state_warning`).
 *
 * Plan §9 critical-risk-mitigation item #6.
 *
 * **Sweep algorithm:**
 *
 *   1. Build the candidate set — targets that saw ANY activity in
 *      the last 48 hours:
 *        - `bcc_trust_attestations` rows created / reaffirmed /
 *           revoked since now-48h
 *        - `bcc_disputes` rows created / reviewed since now-48h
 *      48h gives a 1-day cushion against cron drift / missed ticks
 *      so a state-changing event can't escape between sweeps.
 *
 *   2. For each candidate, classify via `DivergenceStateClassifier`.
 *
 *   3. Compare the new state vs the stored prior state in
 *      `bcc_target_divergence_state`. Three branches:
 *        - No prior row → INSERT, treat as "first observation"
 *          (no notification fires — we'd otherwise spam every
 *          historical user on first sweep).
 *        - Prior row, same state → no-op.
 *        - Prior row, different state → UPDATE; if new state is
 *          `polarizing` or `disputed` AND last_notified_at is
 *          either NULL or > 24h ago, fire the notification and
 *          stamp last_notified_at.
 *
 *   4. Resolve the recipient:
 *        - target_kind = user_profile → recipient = target_id
 *          (the user themselves)
 *        - target_kind in {validator_card, project_card, creator_card}
 *          → recipient = post_author of the backing peepso-page
 *          (target_id is the post ID)
 *        - post_author = 0 (unclaimed page) → drop, no recipient
 *
 *   5. Fire via `NotificationDispatcher::onDivergenceStateTransitioned`.
 *      Bell pref + push pref toggles are honored inside the dispatcher.
 *
 * **V1 scope reality:** the classifier in V1 doesn't produce
 * `polarizing` (Slice E.5 cache dependency). So the only state this
 * notifier actively fires for in V1 is `disputed`. The `polarizing`
 * path is wired and forward-compatible — flips on automatically when
 * the classifier starts producing the state.
 *
 * **Cron schedule:** daily, via WP-Cron `bcc_trust_divergence_state_sweep`
 * hook. Registered in `bcc-trust.php` activation + plugins_loaded
 * self-heal (per the V2 NFT cron-drift incident pattern).
 *
 * **Never throws:** fire-and-forget posture per the
 * `AccountSecurityMailer` precedent. Sweep failures degrade silently
 * with a DegradationMetric counter and a Logger::warning. The
 * underlying classifier writes still happen on next tick.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer PR-8b (2026-05-14)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\TargetDivergenceStateRepository;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

final class PolarizationTransitionNotifier
{
    /** Sweep window — covers cron drift up to one full day. */
    public const SWEEP_WINDOW_HOURS = 48;

    /** Coalescing window for the 24-hour heads-up rule (§J.7). */
    public const NOTIFY_COOLDOWN_HOURS = 24;

    /** States that warrant a heads-up. Other transitions are silent. */
    private const NOTIFY_STATES = [
        DivergenceStateClassifier::STATE_POLARIZING,
        DivergenceStateClassifier::STATE_DISPUTED,
    ];

    public function __construct(
        private readonly AttestationRepository $attestationRepo,
        private readonly TargetDivergenceStateRepository $stateRepo,
        private readonly DivergenceStateClassifier $classifier,
        private readonly NotificationDispatcher $dispatcher
    ) {
    }

    /**
     * Run one sweep. Idempotent under multiple invocations within the
     * same window (the 24h `last_notified_at` cooldown prevents repeat
     * notifications). Returns a lightweight summary for cron-log /
     * observability hooks.
     *
     * @return array{
     *   candidates: int,
     *   transitions: int,
     *   notifications_fired: int,
     *   skipped_no_recipient: int,
     *   skipped_cooldown: int,
     *   errors: int
     * }
     */
    public function sweep(): array
    {
        $candidates          = 0;
        $transitions         = 0;
        $notificationsFired  = 0;
        $skippedNoRecipient  = 0;
        $skippedCooldown     = 0;
        $errors              = 0;

        try {
            $since = gmdate('Y-m-d H:i:s', time() - (self::SWEEP_WINDOW_HOURS * 3600));
            $candidateSet = $this->buildCandidateSet($since);
            $candidates = count($candidateSet);

            foreach ($candidateSet as $candidate) {
                try {
                    $outcome = $this->processCandidate(
                        $candidate['target_kind'],
                        $candidate['target_id']
                    );
                    $transitions         += $outcome['transitioned'] ? 1 : 0;
                    $notificationsFired  += $outcome['notified'] ? 1 : 0;
                    $skippedNoRecipient  += $outcome['skipped_no_recipient'] ? 1 : 0;
                    $skippedCooldown     += $outcome['skipped_cooldown'] ? 1 : 0;
                } catch (Throwable $perTarget) {
                    $errors++;
                    Logger::warning('[PolarizationTransitionNotifier] per-target failure', [
                        'target_kind' => $candidate['target_kind'],
                        'target_id'   => $candidate['target_id'],
                        'error'       => $perTarget->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $sweepError) {
            // Never throw out of the sweep — the cron hook would log and
            // bail otherwise. Per the §A3 dispatcher rule, the worker
            // degrades silently and the next tick re-attempts.
            $errors++;
            Logger::warning('[PolarizationTransitionNotifier] sweep failure', [
                'error' => $sweepError->getMessage(),
            ]);
        }

        return [
            'candidates'           => $candidates,
            'transitions'          => $transitions,
            'notifications_fired'  => $notificationsFired,
            'skipped_no_recipient' => $skippedNoRecipient,
            'skipped_cooldown'     => $skippedCooldown,
            'errors'               => $errors,
        ];
    }

    /**
     * Process one (target_kind, target_id) candidate. Pure-ish: persists
     * the new state to the sidecar and conditionally fires the notifier,
     * returns flags the sweep aggregates into its summary. Per-target
     * exceptions bubble to the caller for logging.
     *
     * @return array{
     *   transitioned: bool,
     *   notified: bool,
     *   skipped_no_recipient: bool,
     *   skipped_cooldown: bool
     * }
     */
    private function processCandidate(string $targetKind, int $targetId): array
    {
        $newState = $this->classifier->classify($targetKind, $targetId);
        $prior    = $this->stateRepo->findByTarget($targetKind, $targetId);

        // First-observation case: stamp the row but don't notify. Avoids
        // notification floods on initial sweep against an existing data set.
        if ($prior === null) {
            $this->stateRepo->upsertState($targetKind, $targetId, $newState);
            return [
                'transitioned'         => false,
                'notified'             => false,
                'skipped_no_recipient' => false,
                'skipped_cooldown'     => false,
            ];
        }

        $priorState = isset($prior->current_state) ? (string) $prior->current_state : '';
        if ($priorState === $newState) {
            // No-op — bump computed_at via upsert so observability shows
            // the row is being touched, but no transition.
            $this->stateRepo->upsertState($targetKind, $targetId, $newState);
            return [
                'transitioned'         => false,
                'notified'             => false,
                'skipped_no_recipient' => false,
                'skipped_cooldown'     => false,
            ];
        }

        // Transition detected. Always persist the new state.
        $this->stateRepo->upsertState($targetKind, $targetId, $newState);

        // Only the polarizing / disputed states warrant a heads-up.
        if (!in_array($newState, self::NOTIFY_STATES, true)) {
            return [
                'transitioned'         => true,
                'notified'             => false,
                'skipped_no_recipient' => false,
                'skipped_cooldown'     => false,
            ];
        }

        // 24h coalescing — even on real transitions, suppress if we
        // already notified inside the window. Defends against cron
        // double-fires and flapping classifications.
        $lastNotifiedRaw = isset($prior->last_notified_at) && is_string($prior->last_notified_at)
            ? $prior->last_notified_at
            : '';
        if ($lastNotifiedRaw !== '' && $lastNotifiedRaw !== '0000-00-00 00:00:00') {
            $lastNotifiedTs = strtotime($lastNotifiedRaw . ' UTC');
            if ($lastNotifiedTs !== false) {
                $ageSeconds = time() - $lastNotifiedTs;
                if ($ageSeconds < self::NOTIFY_COOLDOWN_HOURS * 3600) {
                    return [
                        'transitioned'         => true,
                        'notified'             => false,
                        'skipped_no_recipient' => false,
                        'skipped_cooldown'     => true,
                    ];
                }
            }
        }

        $recipientId = self::resolveRecipient($targetKind, $targetId);
        if ($recipientId <= 0) {
            return [
                'transitioned'         => true,
                'notified'             => false,
                'skipped_no_recipient' => true,
                'skipped_cooldown'     => false,
            ];
        }

        $this->dispatcher->onDivergenceStateTransitioned(
            $recipientId,
            $newState,
            $targetKind,
            $targetId
        );
        $this->stateRepo->markNotified($targetKind, $targetId);

        return [
            'transitioned'         => true,
            'notified'             => true,
            'skipped_no_recipient' => false,
            'skipped_cooldown'     => false,
        ];
    }

    /**
     * Build the union candidate set — attestation-touched targets +
     * dispute-touched pages. Pages are normalized into the entity-card
     * target_kind taxonomy.
     *
     * @return list<array{target_kind: string, target_id: int}>
     */
    private function buildCandidateSet(string $sinceMysqlUtc): array
    {
        $set = [];
        $seen = [];

        // Attestation-driven candidates carry target_kind verbatim.
        foreach ($this->attestationRepo->listTargetsWithRecentActivity($sinceMysqlUtc) as $row) {
            $key = $row['target_kind'] . ':' . $row['target_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $set[] = $row;
        }

        // Dispute-driven candidates are page-scoped — map post_id to
        // the entity card target_kind. user_profile disputes don't
        // exist in V1, so every dispute resolves to one of the three
        // entity-card kinds. The classifier itself reads
        // `_bcc_page_type` post_meta to disambiguate downstream.
        foreach (DisputeRepository::listPagesWithRecentDisputeActivity($sinceMysqlUtc) as $pageId) {
            $pageType = (string) get_post_meta($pageId, '_bcc_page_type', true);
            $targetKind = self::pageTypeToTargetKind($pageType);
            if ($targetKind === null) {
                continue;
            }
            $key = $targetKind . ':' . $pageId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $set[] = [
                'target_kind' => $targetKind,
                'target_id'   => $pageId,
            ];
        }

        return $set;
    }

    /**
     * Map `_bcc_page_type` meta values to the §J.4 target_kind taxonomy.
     * Mirrors the inverse direction in `PageTypeMap::KIND_TO_PAGE_TYPE`.
     * Returns null when the page-type meta is missing or unrecognized —
     * caller drops the candidate.
     */
    private static function pageTypeToTargetKind(string $pageType): ?string
    {
        return match ($pageType) {
            'validator' => 'validator_card',
            'builder'   => 'project_card',
            'nft'       => 'creator_card',
            default     => null,
        };
    }

    /**
     * Resolve the user_id to notify for a (target_kind, target_id):
     *   - user_profile target → target_id IS the user
     *   - *_card target → post_author of the backing peepso-page
     *
     * Returns 0 when:
     *   - target_kind is user_profile but target_id <= 0
     *   - target_kind is a card but the post doesn't exist
     *   - post_author = 0 (unclaimed placeholder page — no owner
     *     to notify)
     */
    private static function resolveRecipient(string $targetKind, int $targetId): int
    {
        if ($targetKind === 'user_profile') {
            return $targetId > 0 ? $targetId : 0;
        }
        $post = get_post($targetId);
        if (!($post instanceof \WP_Post)) {
            return 0;
        }
        return (int) $post->post_author;
    }
}
