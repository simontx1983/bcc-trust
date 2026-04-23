<?php
/**
 * Score Mutation Logger
 *
 * Listens to trust events and records score changes to bcc_trust_score_events.
 * Captures before/after score + tier so users and admins can see WHY a score changed.
 *
 * Distinct from Security\AuditLogger, which records actor-scoped activity
 * (who did what, when). This class records value-scoped mutations
 * (what score moved, by how much, triggered by which event).
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\ScoreEventRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ScoreMutationLogger
{
    /**
     * In-memory snapshot of scores captured BEFORE the mutation.
     * Keyed by page_id → {score, tier}.
     *
     * @var array<int, array{score: float, tier: string}>
     */
    private static array $snapshots = [];

    /**
     * Register hooks. Called once from Plugin boot.
     */
    public static function register(): void
    {
        // Capture pre-mutation snapshot (priority 5 = before score delta is applied).
        add_action('bcc_trust_vote_pre',        [self::class, 'snapshotBeforeVote'], 5, 2);
        add_action('bcc_trust_endorsement_pre',  [self::class, 'snapshotBeforeEndorsement'], 5, 2);

        // Record post-mutation delta (priority 25 = after cache invalidation at 20).
        add_action('bcc_trust_vote_changed',          [self::class, 'recordVoteChange'], 25, 3);
        add_action('bcc_trust_endorsement_added',     [self::class, 'recordEndorsementAdded'], 25, 2);
        add_action('bcc_trust_endorsement_removed',   [self::class, 'recordEndorsementRemoved'], 25, 2);
        add_action('bcc_trust_score_recalculated',    [self::class, 'recordRecalculation'], 25, 1);
    }

    /* ── Pre-mutation snapshots ──────────────────────────────── */

    /**
     * @param int $voterId
     * @param int $pageId
     */
    public static function snapshotBeforeVote(int $voterId, int $pageId): void
    {
        self::captureSnapshot($pageId);
    }

    /**
     * @param int $endorserId
     * @param int $pageId
     */
    public static function snapshotBeforeEndorsement(int $endorserId, int $pageId): void
    {
        self::captureSnapshot($pageId);
    }

    private static function captureSnapshot(int $pageId): void
    {
        if (isset(self::$snapshots[$pageId])) {
            return; // Already captured this request.
        }

        $rm = Plugin::instance()->pageReadModelRepository()->getByPageId($pageId);
        if ($rm) {
            self::$snapshots[$pageId] = [
                'score' => (float) $rm->trust_score,
                'tier'  => $rm->reputation_tier ?? 'neutral',
            ];
        } else {
            // Fallback: read from scores table.
            try {
                $score = Plugin::instance()->scoreRepository()->getByPageId($pageId);
                self::$snapshots[$pageId] = [
                    'score' => $score ? (float) $score->getTotalScore() : (float) BCC_TRUST_NEUTRAL_SCORE,
                    'tier'  => $score ? $score->getReputationTier() : 'neutral',
                ];
            } catch (\Exception $e) {
                self::$snapshots[$pageId] = ['score' => (float) BCC_TRUST_NEUTRAL_SCORE, 'tier' => 'neutral'];
            }
        }
    }

    /* ── Post-mutation recording ─────────────────────────────── */

    /**
     * @param int    $voterId
     * @param int    $pageId
     * @param int    $categoryId
     */
    public static function recordVoteChange(int $voterId, int $pageId, int $categoryId = 0): void
    {
        self::recordEvent($pageId, 'vote_changed', $voterId, [
            'category_id' => $categoryId,
        ]);
    }

    /**
     * @param int $endorserId
     * @param int $pageId
     */
    public static function recordEndorsementAdded(int $endorserId, int $pageId): void
    {
        self::recordEvent($pageId, 'endorsement_added', $endorserId);
    }

    /**
     * @param int $endorserId
     * @param int $pageId
     */
    public static function recordEndorsementRemoved(int $endorserId, int $pageId): void
    {
        self::recordEvent($pageId, 'endorsement_removed', $endorserId);
    }

    /**
     * @param int $pageId
     */
    public static function recordRecalculation(int $pageId): void
    {
        self::recordEvent($pageId, 'recalculation', 0);
    }

    /* ── Internal ────────────────────────────────────────────── */

    /**
     * @param array<string, mixed> $meta
     */
    private static function recordEvent(int $pageId, string $eventType, int $actorId, array $meta = []): void
    {
        $before = self::$snapshots[$pageId] ?? null;

        // Read fresh score after mutation.
        $after = self::readCurrentScore($pageId);

        // Skip recording if score didn't actually change (idempotent vote, etc.)
        if ($before && $after
            && abs($before['score'] - $after['score']) < 0.01
            && $before['tier'] === $after['tier']
        ) {
            return;
        }

        try {
            $repo = new ScoreEventRepository();
            $repo->record(
                $pageId,
                $eventType,
                $before ? $before['score'] : null,
                $after ? $after['score'] : null,
                $before ? $before['tier'] : null,
                $after ? $after['tier'] : null,
                self::buildReason($eventType, $actorId),
                $actorId ?: null,
                !empty($meta) ? $meta : null
            );
        } catch (\Throwable $e) {
            // Audit logging must never break the mutation path.
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[ScoreAudit] Failed to record event', [
                    'page_id' => $pageId,
                    'event'   => $eventType,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // Clear snapshot so next mutation on same page captures fresh state.
        unset(self::$snapshots[$pageId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readCurrentScore(int $pageId): ?array
    {
        try {
            $score = Plugin::instance()->scoreRepository()->getByPageId($pageId);
            if ($score) {
                return [
                    'score' => (float) $score->getTotalScore(),
                    'tier'  => $score->getReputationTier(),
                ];
            }
        } catch (\Exception $e) {
            // silent
        }
        return null;
    }

    private static function buildReason(string $eventType, int $actorId): string
    {
        switch ($eventType) {
            case 'vote_changed':
                return $actorId
                    ? sprintf('Vote by user #%d', $actorId)
                    : 'Vote change';
            case 'endorsement_added':
                return sprintf('Endorsement by user #%d', $actorId);
            case 'endorsement_removed':
                return sprintf('Endorsement revoked by user #%d', $actorId);
            case 'recalculation':
                return 'Periodic score recalculation';
            default:
                return $eventType;
        }
    }
}
