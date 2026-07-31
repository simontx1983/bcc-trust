<?php
/**
 * RankEvidenceIngestor — the single entry point through which Rank
 * evidence reaches the ledger (Rank Phase 4, shadow).
 *
 * Declarative routing: `sources` in rank-scoring.php maps a source
 * type to its categories; `points` supplies the draft raw value (⚖ —
 * ratified at Phase 9 calibration). Idempotency comes from the
 * ledger's UNIQUE(event_uid, category) + INSERT IGNORE, so hook
 * re-fires are no-ops. The §4.4 event cap (2.0) is allocated across a
 * single event's categories at ingest inside a transaction (layered
 * cross-source kickers share accounting via
 * RankEventsRepository::activeCappedTotalForEvent when they land in
 * later phases). The R3 representative rule zeroes capped_value for
 * non-representative members of confirmed clusters — raw kept for
 * audit, reversal restores by recalculation.
 *
 * This class is also the ONLY selfPage↔user_id normalization point in
 * the Rank domain (subjectFromPage) — everything downstream speaks
 * user ids.
 *
 * Nothing reads the ledger for authorization yet; failures surface via
 * the rank_scoring `evidence_ingest_failed` DegradationMetric recorded
 * by the Plugin subscriber wiring.
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign Phase 4 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Rank\Repositories\RankEventsRepository;
use BCC\Trust\Rank\Support\RankScoringConfig;

if (!defined('ABSPATH')) {
    exit;
}

class RankEvidenceIngestor
{
    /**
     * sources-map key → points-map key (the two maps use slightly
     * different vocabulary; keep the translation in ONE place).
     */
    private const POINT_KEY_BY_SOURCE = [
        'post'          => 'post',
        'comment'       => 'comment',
        'review'        => 'review',
        'report_upheld' => 'upheld_report',
        'helpful_mark'  => 'helping_min',
        'recognition'   => 'recognition_weight_factor',
        'outcome'       => 'proven_outcome',
        'stewardship'   => 'stewardship',
        'onboarding'    => 'onboarding_assist',
    ];

    public function __construct(
        private readonly RankEventsRepository $events,
        private readonly IndependenceResolver $independence,
        private readonly RankScoringConfig $config
    ) {
    }

    /**
     * Ingest one evidence event for a subject. Unknown source types
     * ingest nothing (fail-safe: a typo can never mint credit).
     * Throws only on DB failure — the subscriber wiring records the
     * DegradationMetric.
     *
     * @param int $relationshipUserId The counterparty identity the
     *        per-recognizer / per-recipient caps key on (recognizer for
     *        'recognition', 0 when the event has no counterparty).
     */
    public function ingest(int $subjectUserId, string $sourceType, int $sourceId, int $relationshipUserId = 0): void
    {
        if ($subjectUserId <= 0 || $sourceId <= 0) {
            return;
        }

        $categories = $this->config->sources[$sourceType] ?? null;
        $pointKey   = self::POINT_KEY_BY_SOURCE[$sourceType] ?? null;
        if ($categories === null || $pointKey === null) {
            return; // Unknown source — fail-safe no-op.
        }
        // 'login_month' (time) is derived from login_days at calculation
        // time, never ledgered — config documents it; nothing routes here.

        $raw = $this->config->points[$pointKey] ?? 0.0;
        if ($raw <= 0.0) {
            return;
        }

        $eventUid = "{$sourceType}:{$sourceId}:{$subjectUserId}";

        // R3 representative rule: non-representative members of a
        // confirmed cluster record raw for audit, count zero.
        $zeroCredit = $this->independence->isZeroCredit($subjectUserId);

        $rawByCategory = array_fill_keys($categories, $raw);
        $capped        = self::allocateWithinCap($rawByCategory, $this->config->eventCap);

        TransactionManager::run(function () use (
            $eventUid, $subjectUserId, $sourceType, $sourceId, $relationshipUserId, $rawByCategory, $capped, $zeroCredit
        ): bool {
            foreach ($rawByCategory as $category => $rawValue) {
                $ok = $this->events->append(
                    $eventUid,
                    $subjectUserId,
                    $sourceType,
                    $sourceId,
                    $category,
                    $relationshipUserId,
                    $rawValue,
                    $zeroCredit ? 0.0 : $capped[$category]
                );
                if (!$ok) {
                    throw new \RuntimeException("rank_events append failed for {$eventUid}/{$category}");
                }
            }
            return true;
        });
    }

    /**
     * Reverse all evidence of one source event (§14.1: reversed
     * evidence stops contributing immediately).
     */
    public function reverse(string $sourceType, int $sourceId, string $reason): void
    {
        if ($this->events->reverseBySource($sourceType, $sourceId, $reason) < 0) {
            throw new \RuntimeException("rank_events reversal failed for {$sourceType}:{$sourceId}");
        }
    }

    /**
     * The only selfPage↔user_id normalization point in the Rank domain.
     * Returns 0 for non-self pages (entity pages earn no member Rank).
     */
    public static function subjectFromPage(int $pageId): int
    {
        if (!\BCC\Trust\Core\Services\MemberSelfPageService::isSelfPage($pageId)) {
            return 0;
        }

        return \BCC\Trust\Core\Services\MemberSelfPageService::ownerOfSelfPage($pageId);
    }

    /**
     * §4.4 event-cap allocation: one event's combined category credit
     * may not exceed the cap. Sequential allocation in category order —
     * deterministic, and the order is the config's category order.
     * Pure; unit-tested directly.
     *
     * @param array<string, float> $rawByCategory
     * @return array<string, float>
     */
    public static function allocateWithinCap(array $rawByCategory, float $cap): array
    {
        $remaining = max(0.0, $cap);
        $out       = [];
        foreach ($rawByCategory as $category => $raw) {
            $granted         = min(max(0.0, $raw), $remaining);
            $out[$category]  = round($granted, 4);
            $remaining      -= $granted;
        }
        return $out;
    }
}
