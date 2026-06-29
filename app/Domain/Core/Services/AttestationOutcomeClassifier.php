<?php
/**
 * AttestationOutcomeClassifier — the §J.3.2.1 Operator Reliability engine
 * (Slice 2).
 *
 * Turns an attestor's active attestations into a single numeric reliability
 * ∈ [0,1] plus a track-record breakdown, by classifying each attestation's
 * OUTCOME (what happened to the target since the cast) into a per-outcome
 * "goodness," then taking a WEIGHTED AVERAGE of those goodnesses weighted by
 * (kind_weight × early_read_mult).
 *
 *   reliability = clamp01( Σ(goodness × kw × erm) ÷ Σ(kw × erm) )
 *
 * This is the synthesis-math sibling of AttestationScoreSynthesis: a pure,
 * unit-testable static compute core ({@see computeReliability} +
 * {@see earlyReadMultiplier}) with a thin instance method
 * ({@see classifyForAttestor}) that does the DB I/O and feeds the core.
 *
 * ── OUTCOME CLASSIFICATION (§J.3.2.1) ───────────────────────────────────────
 * Looking at the TARGET's fate SINCE the attestation's created_at:
 *
 *   - disputed-and-upheld (NEGATIVE) → the target's score-row page had a
 *     vote-dispute that resolved 'rejected' after the cast. goodness = 0.0.
 *   - disputed-and-dismissed (VINDICATED) → a dispute resolved
 *     accepted / dismissed / timeout_no_quorum after the cast. goodness = 1.0.
 *   - further attestations (POSITIVE) → no upheld dispute AND ≥1 DISTINCT
 *     OTHER attestor cast on the target after the cast. goodness = 1.0.
 *   - clean & active (MILD POSITIVE) → no dispute, no further attestors.
 *     goodness = 0.5.
 *
 * ── DISPUTE DIRECTION IS LOAD-BEARING ───────────────────────────────────────
 * A vote-dispute contests a DOWNVOTE against the target. Per
 * DisputeRepository::computeVerdict, 'accepted' means the panel ACCEPTED the
 * dispute → the downvote is removed → the target is VINDICATED; 'rejected'
 * means the panel REJECTED the dispute → the downvote STANDS → the target's
 * negative mark is UPHELD. So for an attestor who BACKED that target:
 *
 *      dispute 'rejected'  ⇒  target's negative mark UPHELD  ⇒  NEGATIVE
 *      dispute 'accepted'  ⇒  target VINDICATED              ⇒  POSITIVE
 *      'dismissed' / 'timeout_no_quorum'                     ⇒  POSITIVE (vindicated)
 *
 * This mapping is isolated to the single documented, filterable spot
 * {@see disputeOutcomeFor} so the direction can never silently flip.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer — Slice 2 (2026-06-28)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Disputes\Domain\DisputeStatus;
use BCC\Trust\Disputes\Repositories\DisputeRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class AttestationOutcomeClassifier
{
    /** Outcome → track_record bucket key, also the goodness selector. */
    public const OUTCOME_DISPUTED_UPHELD    = 'disputed_upheld';
    public const OUTCOME_DISPUTED_DISMISSED = 'disputed_dismissed';
    public const OUTCOME_FURTHER_ATTEST     = 'further_attest';
    public const OUTCOME_CLEAN_ACTIVE       = 'clean_active';

    public function __construct(
        private readonly AttestationRepository $attestationRepo
    ) {
    }

    /**
     * Early-read multiplier for one attestation — pure, no I/O.
     *
     * Vouch is abundant; ordering carries no reliability signal, so vouch is
     * always 1.0×. stand_behind is the scarce, high-conviction signal where
     * being early matters: 1st → $m1, 2nd–5th → $m25, 6th–20th → $m620,
     * 21st+ → $m21plus. When $isWithinFirstProtected is true (the operator's
     * own first-N stand_behind casts, §J.1 first-mover protection) the
     * multiplier is pinned to 1.0× regardless of order.
     */
    public static function earlyReadMultiplier(
        string $kind,
        int $orderInTarget,
        bool $isWithinFirstProtected,
        float $m1,
        float $m25,
        float $m620,
        float $m21plus
    ): float {
        if ($kind !== 'stand_behind') {
            return 1.0;
        }
        if ($isWithinFirstProtected) {
            return 1.0;
        }
        if ($orderInTarget <= 1) {
            return $m1;
        }
        if ($orderInTarget <= 5) {
            return $m25;
        }
        if ($orderInTarget <= 20) {
            return $m620;
        }
        return $m21plus;
    }

    /**
     * The pure reliability arithmetic — no I/O, fully unit-testable.
     *
     * Weighted average of per-row goodness, weighted by (kind_weight ×
     * early_read_mult), clamped to [0,1]. Empty input (no attestations) and a
     * zero total weight both yield 0.0 — an operator with no signal has no
     * reliability number, not a divide-by-zero.
     *
     * @param list<array{goodness: float, kind_weight: float, early_read_mult: float}> $rows
     */
    public static function computeReliability(array $rows): float
    {
        if ($rows === []) {
            return 0.0;
        }

        $weightedSum = 0.0;
        $totalWeight = 0.0;
        foreach ($rows as $row) {
            $weight = (float) $row['kind_weight'] * (float) $row['early_read_mult'];
            if ($weight <= 0.0) {
                continue;
            }
            $weightedSum += (float) $row['goodness'] * $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        $reliability = $weightedSum / $totalWeight;
        return round(max(0.0, min(1.0, $reliability)), 4);
    }

    /**
     * Weighted-average goodness over a SUBSET of rows — the pure core behind
     * the two §J.3.2.1 Early Read sub-tracks (consensus_reliability,
     * early_read_accuracy). Same arithmetic as {@see computeReliability}
     * (weight = kind_weight × early_read_mult), factored so each sub-track is
     * a clamp01 weighted average of its own row subset and so unit tests can
     * pin the multiplier weighting independently. Empty subset / zero total
     * weight → 0.0.
     *
     * @param list<array{goodness: float, kind_weight: float, early_read_mult: float}> $rows
     */
    public static function weightedGoodness(array $rows): float
    {
        // Identical normalization to computeReliability — sub-tracks are the
        // same weighted-average over a filtered row subset, never a second
        // formula. Delegating keeps the two in lockstep by construction.
        return self::computeReliability($rows);
    }

    /**
     * Classify every active attestation an operator has cast, returning the
     * §J.5 self-mirror reliability payload fields: the numeric reliability,
     * the counted-attestation total (the standing-gate denominator), and the
     * track-record outcome breakdown.
     *
     * Pipeline (all DB reads batched — no N+1):
     *   1. load the operator's active attestations, oldest-first;
     *   2. resolve each target's score-row page id (user_profile → self-page;
     *      *_card → target_id IS the page id);
     *   3. ONE batched fetch of every target page's resolved disputes;
     *   4. per attestation: dispute outcome (if any) else further-attestor
     *      probe → goodness; early-read multiplier (stand_behind + the first-N
     *      protection from step 1's chronological order);
     *   5. weighted-average via {@see computeReliability} + tally track_record.
     *
     * @return array{
     *   reliability: float,
     *   counted: int,
     *   consensus_reliability: float,
     *   early_read_accuracy: float,
     *   track_record: array{
     *     total_attestations: int,
     *     outcomes: array{
     *       targets_disputed_and_upheld: int,
     *       targets_disputed_and_dismissed: int,
     *       targets_received_further_attestations: int,
     *       targets_clean_and_active: int
     *     }
     *   }
     * }
     */
    public function classifyForAttestor(int $userId): array
    {
        $empty = self::emptyResult();
        if ($userId <= 0) {
            return $empty;
        }

        $attestations = $this->attestationRepo->listActiveByAttestor($userId);
        if ($attestations === []) {
            return $empty;
        }

        // Filtered tuning (read once; passed positionally to the pure core).
        $goodUpheld    = (float) apply_filters('bcc_reliability_goodness_disputed_upheld', BCC_RELIABILITY_GOODNESS_DISPUTED_UPHELD);
        $goodDismissed = (float) apply_filters('bcc_reliability_goodness_disputed_dismissed', BCC_RELIABILITY_GOODNESS_DISPUTED_DISMISSED);
        $goodFurther   = (float) apply_filters('bcc_reliability_goodness_further_attest', BCC_RELIABILITY_GOODNESS_FURTHER_ATTEST);
        $goodClean     = (float) apply_filters('bcc_reliability_goodness_clean_active', BCC_RELIABILITY_GOODNESS_CLEAN_ACTIVE);

        $kwVouch       = (float) apply_filters('bcc_reliability_kind_vouch', BCC_RELIABILITY_KIND_VOUCH);
        $kwStandBehind = (float) apply_filters('bcc_reliability_kind_stand_behind', BCC_RELIABILITY_KIND_STAND_BEHIND);

        $m1      = (float) apply_filters('bcc_reliability_early_1st', BCC_RELIABILITY_EARLY_1ST);
        $m25     = (float) apply_filters('bcc_reliability_early_2_5', BCC_RELIABILITY_EARLY_2_5);
        $m620    = (float) apply_filters('bcc_reliability_early_6_20', BCC_RELIABILITY_EARLY_6_20);
        $m21plus = (float) apply_filters('bcc_reliability_early_21plus', BCC_RELIABILITY_EARLY_21PLUS);
        $protect = (int) apply_filters('bcc_reliability_early_protect_first', BCC_RELIABILITY_EARLY_PROTECT_FIRST);

        // Slice 4 — Early Read sub-track band. A stand_behind at or below this
        // order_in_target is a pre-consensus call.
        $preConsensusMaxOrder = (int) apply_filters('bcc_reliability_preconsensus_max_order', BCC_RELIABILITY_PRECONSENSUS_MAX_ORDER);

        // Resolve each attestation's target page id once + collect the set for
        // the single batched dispute fetch. Keyed by attestation index so the
        // per-row classification below can look its page id back up.
        $pageIdByIndex = [];
        $pageIdSet     = [];
        foreach ($attestations as $i => $att) {
            $pageId = $this->resolveTargetPageId($att->target_kind, $att->target_id);
            $pageIdByIndex[$i] = $pageId;
            if ($pageId > 0) {
                $pageIdSet[$pageId] = true;
            }
        }
        $disputesByPage = $pageIdSet === []
            ? []
            : DisputeRepository::listResolvedForPages(array_keys($pageIdSet));

        $rows     = [];
        // Slice 4 sub-track row subsets, same row shape as $rows so they feed
        // the identical weighted-average core. consensus = every stand_behind;
        // earlyRead = pre-consensus stand_behind NOT under first-mover protection.
        $standBehindRows = [];
        $earlyReadRows   = [];
        $outcomes = [
            'targets_disputed_and_upheld'           => 0,
            'targets_disputed_and_dismissed'        => 0,
            'targets_received_further_attestations' => 0,
            'targets_clean_and_active'              => 0,
        ];

        // First-mover protection: the operator's OWN first N stand_behind casts
        // (chronologically — the list is created_at ASC) get multiplier 1.0
        // regardless of order_in_target.
        $standBehindSeen = 0;

        foreach ($attestations as $i => $att) {
            $kind = $att->kind;

            $isProtected = false;
            if ($kind === 'stand_behind') {
                $isProtected = $standBehindSeen < $protect;
                $standBehindSeen++;
            }

            $pageId   = $pageIdByIndex[$i];
            $disputes = ($pageId > 0 && isset($disputesByPage[$pageId])) ? $disputesByPage[$pageId] : [];
            $outcome  = self::classifyOutcome(
                $disputes,
                $att->created_at,
                $att->target_kind,
                $att->target_id,
                $userId,
                $this->attestationRepo
            );

            switch ($outcome) {
                case self::OUTCOME_DISPUTED_UPHELD:
                    $goodness = $goodUpheld;
                    $outcomes['targets_disputed_and_upheld']++;
                    break;
                case self::OUTCOME_DISPUTED_DISMISSED:
                    $goodness = $goodDismissed;
                    $outcomes['targets_disputed_and_dismissed']++;
                    break;
                case self::OUTCOME_FURTHER_ATTEST:
                    $goodness = $goodFurther;
                    $outcomes['targets_received_further_attestations']++;
                    break;
                default:
                    $goodness = $goodClean;
                    $outcomes['targets_clean_and_active']++;
                    break;
            }

            $kindWeight = $kind === 'stand_behind' ? $kwStandBehind : $kwVouch;
            $erm        = self::earlyReadMultiplier(
                $kind,
                $att->attestation_order_in_target,
                $isProtected,
                $m1,
                $m25,
                $m620,
                $m21plus
            );

            $weightedRow = [
                'goodness'        => $goodness,
                'kind_weight'     => $kindWeight,
                'early_read_mult' => $erm,
            ];
            $rows[] = $weightedRow;

            // Sub-track accumulation (stand_behind ONLY — vouch is excluded
            // from both sub-tracks). consensus_reliability spans every
            // stand_behind; early_read_accuracy is the pre-consensus subset
            // (order ≤ band) MINUS the first-mover-protected casts (those have
            // no early-read signal — the operator joined already-crowded
            // targets while building history, and $isProtected reflects that).
            if ($kind === 'stand_behind') {
                $standBehindRows[] = $weightedRow;
                if (!$isProtected && $att->attestation_order_in_target <= $preConsensusMaxOrder) {
                    $earlyReadRows[] = $weightedRow;
                }
            }
        }

        $reliability = self::computeReliability($rows);
        $counted     = count($rows);

        return [
            'reliability'           => $reliability,
            'counted'               => $counted,
            'consensus_reliability' => self::weightedGoodness($standBehindRows),
            'early_read_accuracy'   => self::weightedGoodness($earlyReadRows),
            'track_record' => [
                'total_attestations' => $counted,
                'outcomes'           => $outcomes,
            ],
        ];
    }

    /**
     * Classify ONE attestation's outcome. An upheld/vindicating dispute that
     * resolved after the cast takes precedence; otherwise the further-attestor
     * probe decides between further_attest and clean_active.
     *
     * @param list<object{status: string, resolved_at: string}> $disputes
     */
    private static function classifyOutcome(
        array $disputes,
        string $castCreatedAt,
        string $targetKind,
        int $targetId,
        int $attestorUserId,
        AttestationRepository $attestationRepo
    ): string {
        $disputeOutcome = self::disputeOutcomeFor($disputes, $castCreatedAt);
        if ($disputeOutcome !== null) {
            return $disputeOutcome;
        }

        // No upheld/vindicating dispute → did anyone else follow the call?
        $others = $attestationRepo->countDistinctAttestorsOnTargetSince(
            $targetKind,
            $targetId,
            $attestorUserId,
            $castCreatedAt
        );
        return $others > 0 ? self::OUTCOME_FURTHER_ATTEST : self::OUTCOME_CLEAN_ACTIVE;
    }

    /**
     * The ONE documented, isolated dispute-direction mapping (§J.3.2.1).
     *
     * Returns the dispute-driven outcome for an attestation, or null when no
     * dispute resolved after the cast (so the caller falls through to the
     * further-attestor probe). When multiple disputes resolved after the cast,
     * an UPHELD ('rejected') one dominates — a single upheld negative judgment
     * against the target is the load-bearing signal, even if other disputes
     * vindicated.
     *
     * Direction (LOAD-BEARING — see class docblock):
     *   - 'rejected'  → target's negative mark UPHELD → OUTCOME_DISPUTED_UPHELD
     *   - 'accepted' / 'dismissed' / 'timeout_no_quorum'
     *                 → target VINDICATED → OUTCOME_DISPUTED_DISMISSED
     *
     * @param list<object{status: string, resolved_at: string}> $disputes
     */
    private static function disputeOutcomeFor(array $disputes, string $castCreatedAt): ?string
    {
        $sawVindicating = false;
        foreach ($disputes as $d) {
            // Only disputes that resolved AFTER the cast bear on this call.
            if (!self::resolvedAfter($d->resolved_at, $castCreatedAt)) {
                continue;
            }
            if ($d->status === DisputeStatus::REJECTED) {
                // Negative mark upheld — dominates immediately.
                return self::OUTCOME_DISPUTED_UPHELD;
            }
            if (
                $d->status === DisputeStatus::ACCEPTED
                || $d->status === DisputeStatus::DISMISSED
                || $d->status === DisputeStatus::TIMEOUT_NO_QUORUM
            ) {
                $sawVindicating = true;
            }
        }
        return $sawVindicating ? self::OUTCOME_DISPUTED_DISMISSED : null;
    }

    /**
     * True when $resolvedAt is a parseable timestamp strictly after $castAt.
     * Both are MySQL datetimes; parsed as UTC (disputes store resolved_at via
     * UTC_TIMESTAMP() / gmdate). Unparseable values fail closed (return false)
     * so a malformed timestamp never fabricates a dispute outcome.
     */
    private static function resolvedAfter(string $resolvedAt, string $castAt): bool
    {
        $r = self::parseUtc($resolvedAt);
        $c = self::parseUtc($castAt);
        if ($r === null || $c === null) {
            return false;
        }
        return $r > $c;
    }

    /** Parse a 'Y-m-d H:i:s' UTC datetime to a unix timestamp; null if invalid/zero. */
    private static function parseUtc(string $datetime): ?int
    {
        $datetime = trim($datetime);
        if ($datetime === '' || str_starts_with($datetime, '0000')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Resolve a target to its trust-score-row page id, per the locked
     * AttestationRepository target_id invariant: user_profile carries a RAW
     * user id whose row is its self-page; every *_card carries the page id
     * directly. Returns 0 when unresolvable.
     */
    private function resolveTargetPageId(string $targetKind, int $targetId): int
    {
        if ($targetId <= 0) {
            return 0;
        }
        if ($targetKind === 'user_profile') {
            return MemberSelfPageService::selfPageId($targetId);
        }
        if (in_array($targetKind, AttestationRepository::PAGE_TARGET_KINDS, true)) {
            return $targetId;
        }
        return 0;
    }

    /**
     * @return array{
     *   reliability: float,
     *   counted: int,
     *   consensus_reliability: float,
     *   early_read_accuracy: float,
     *   track_record: array{
     *     total_attestations: int,
     *     outcomes: array{
     *       targets_disputed_and_upheld: int,
     *       targets_disputed_and_dismissed: int,
     *       targets_received_further_attestations: int,
     *       targets_clean_and_active: int
     *     }
     *   }
     * }
     */
    private static function emptyResult(): array
    {
        return [
            'reliability'           => 0.0,
            'counted'               => 0,
            'consensus_reliability' => 0.0,
            'early_read_accuracy'   => 0.0,
            'track_record' => [
                'total_attestations' => 0,
                'outcomes'           => [
                    'targets_disputed_and_upheld'           => 0,
                    'targets_disputed_and_dismissed'        => 0,
                    'targets_received_further_attestations' => 0,
                    'targets_clean_and_active'              => 0,
                ],
            ],
        ];
    }
}
