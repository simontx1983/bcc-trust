<?php

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\PageReadModelRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trust Attestation Layer — score synthesis (Slice E).
 *
 * Folds a subject's active vouch/stand_behind attestations into its trust
 * score by producing the absolute `attestation_bonus` value that the canonical
 * formula adds. This is the synthesis-math owner; TrustScoreService remains the
 * sole owner of the FORMULA — exactly the relationship ContributionScoreService
 * has with contribution_bonus.
 *
 * Per-row weight is the immutable cast-time `weight_at_time` (already baking in
 * wallet-age × reciprocity × cohort-overlap — NOT re-applied here, that would
 * double-penalise). Synthesis applies, in order: time decay (DecayResolver, the
 * locked curve) → the §J.4 40% Elite-tier source cap → the 1.3× signal-source
 * diversity multiplier → the new-entity velocity cap (entity cards only) → the
 * hard ceiling. Every tuning value is filterable (see includes/config/attestation.php).
 *
 * @package BCC\Trust\Core\Services
 * @since Slice E (2026-06-26)
 */
final class AttestationScoreSynthesis
{
    public function __construct(
        private readonly AttestationRepository $attestationRepo,
        private readonly ReputationRepository $reputationRepo,
        private readonly ScoreRepository $scoreRepo,
        private readonly MemberSelfPageService $selfPageService
    ) {
    }

    /**
     * Recompute and persist one target's attestation_bonus, then invalidate the
     * page-score cache + enqueue the read-model re-sync. The score-row page is
     * resolved through the locked target_id invariant: a `user_profile` target
     * carries a RAW user id whose score row is its self-page (selfPageId), and
     * the row is ensured first so the bonus UPDATE has somewhere to land; every
     * `*_card` kind carries the peepso-page id directly.
     */
    public function recomputeFor(string $targetKind, int $targetId): void
    {
        if (!in_array($targetKind, AttestationRepository::TARGET_KINDS, true) || $targetId <= 0) {
            return;
        }

        if (in_array($targetKind, AttestationRepository::PAGE_TARGET_KINDS, true)) {
            $pageId = $targetId;            // *_card target_id IS the score-row page id
        } else {
            // user_profile (the only non-page kind): target_id is a raw user id.
            $pageId = $this->selfPageService->ensureSelfPage($targetId);
        }
        if ($pageId <= 0) {
            return;
        }

        $bonus = $this->synthesize($targetKind, $targetId);
        $this->scoreRepo->applyAttestationBonus($pageId, $bonus);
        $this->scoreRepo->invalidateCache($pageId);
        PageReadModelRepository::enqueueDirty($pageId);
    }

    /**
     * The bounded attestation_bonus for one target (read-time synthesis). Pure
     * of side effects; resolves the live tuning + elite-attestor set + entity
     * card age, then delegates the arithmetic to {@see computeBonus}.
     */
    public function synthesize(string $targetKind, int $targetId, ?\DateTimeInterface $now = null): float
    {
        $rows = $this->attestationRepo->listActiveForSynthesis($targetKind, $targetId);
        if ($rows === []) {
            return 0.0;
        }

        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Elite-tier attestor set, for the §J.4 elite-source cap. Batched.
        $attestorIds = [];
        foreach ($rows as $r) {
            $attestorIds[(int) $r->attestor_user_id] = true;
        }
        $tiers    = $this->reputationRepo->getTiersForUsers(array_keys($attestorIds));
        $eliteIds = [];
        foreach ($tiers as $uid => $tier) {
            if ($tier === 'elite') {
                $eliteIds[] = (int) $uid;
            }
        }

        // Velocity cap — ENTITY CARDS ONLY, within the first window-days of the
        // card's existence. Never applies to member self-pages.
        $velocityCap = null;
        if (in_array($targetKind, AttestationRepository::PAGE_TARGET_KINDS, true)) {
            $windowDays = (int) apply_filters('bcc_attest_new_entity_window_days', BCC_ATTEST_NEW_ENTITY_WINDOW_DAYS);
            $ageDays    = self::entityCardAgeDays($targetId, $now);
            if ($ageDays !== null && $ageDays <= $windowDays) {
                $velocityCap = (float) apply_filters('bcc_attest_new_entity_velocity_cap', BCC_ATTEST_NEW_ENTITY_VELOCITY_CAP);
            }
        }

        return self::computeBonus(
            $rows,
            $eliteIds,
            $now,
            (float) apply_filters('bcc_attest_ceiling', BCC_ATTEST_CEILING),
            (float) apply_filters('bcc_attest_elite_source_cap_pct', BCC_ATTEST_ELITE_SOURCE_CAP_PCT),
            (float) apply_filters('bcc_attest_diversity_multiplier', BCC_ATTEST_DIVERSITY_MULTIPLIER),
            (int) apply_filters('bcc_attest_diversity_min_sources', BCC_ATTEST_DIVERSITY_MIN_SOURCES),
            $velocityCap
        );
    }

    /**
     * The pure synthesis arithmetic — no I/O, fully unit-testable with a fixed
     * clock + explicit tuning. Decay is computed via DecayResolver's static
     * factor (per-row baseline = reaffirmed_at ?? created_at). The elite-source
     * cap clamps elite-attestor weight to at most `$eliteSourceCapPct` of the
     * running total; the diversity multiplier rewards >= `$diversityMinSources`
     * distinct backers; the optional velocity cap then the ceiling bound it.
     *
     * @param list<object{attestor_user_id: int, weight_at_time: float, created_at: string, reaffirmed_at: ?string}> $rows
     * @param list<int> $eliteAttestorIds attestor ids currently at Elite tier
     */
    public static function computeBonus(
        array $rows,
        array $eliteAttestorIds,
        \DateTimeInterface $now,
        float $ceiling,
        float $eliteSourceCapPct,
        float $diversityMultiplier,
        int $diversityMinSources,
        ?float $velocityCap
    ): float {
        if ($rows === []) {
            return 0.0;
        }

        $eliteSet = [];
        foreach ($eliteAttestorIds as $id) {
            $eliteSet[(int) $id] = true;
        }

        $nowTs        = $now->getTimestamp();
        $totalDecayed = 0.0;
        $eliteDecayed = 0.0;
        $sources      = [];

        foreach ($rows as $row) {
            $weight = (float) $row->weight_at_time;
            if ($weight <= 0.0) {
                continue;
            }
            $baselineStr = ($row->reaffirmed_at !== null && $row->reaffirmed_at !== '')
                ? $row->reaffirmed_at
                : $row->created_at;
            $baselineTs = self::parseUtc($baselineStr);
            if ($baselineTs === null) {
                continue;
            }
            $ageDays = (int) max(0, intdiv($nowTs - $baselineTs, 86400));
            $dw      = $weight * DecayResolver::factorForAgeDays($ageDays);

            $totalDecayed += $dw;
            $attestor = (int) $row->attestor_user_id;
            $sources[$attestor] = true;
            if (isset($eliteSet[$attestor])) {
                $eliteDecayed += $dw;
            }
        }

        if ($totalDecayed <= 0.0) {
            return 0.0;
        }

        // §J.4 Elite-tier source cap: elite weight may contribute at most
        // `$eliteSourceCapPct` of the total — strip the over-cap excess.
        $eliteCapAbsolute = $eliteSourceCapPct * $totalDecayed;
        if ($eliteDecayed > $eliteCapAbsolute) {
            $totalDecayed -= ($eliteDecayed - $eliteCapAbsolute);
        }

        // Diversity multiplier — gated on breadth of distinct backers.
        $multiplier = (count($sources) >= $diversityMinSources) ? $diversityMultiplier : 1.0;
        $bonus      = $totalDecayed * $multiplier;

        // Velocity cap (entity cards, new-entity window) then the hard ceiling.
        if ($velocityCap !== null) {
            $bonus = min($bonus, $velocityCap);
        }
        $bonus = min($bonus, $ceiling);

        return round(max(0.0, $bonus), 2);
    }

    /**
     * Age in whole days of an entity card (peepso-page post), or null if the
     * post date can't be resolved. Used only for the entity velocity cap.
     */
    private static function entityCardAgeDays(int $postId, \DateTimeInterface $now): ?int
    {
        $dateStr = get_post_field('post_date_gmt', $postId);
        if (!is_string($dateStr)) {
            return null;
        }
        $ts = self::parseUtc($dateStr);
        if ($ts === null) {
            return null;
        }
        return (int) max(0, intdiv($now->getTimestamp() - $ts, 86400));
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
}
