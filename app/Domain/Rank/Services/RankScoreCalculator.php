<?php
/**
 * RankScoreCalculator — pure, config-driven Rank Score computation
 * from ledger rows (Rank Phase 4, shadow — nothing gates on its
 * output until Phase 5's promotion engine consumes it).
 *
 * Inputs are plain values (ledger rows, login stats, cluster map) so
 * the §30 invariants are provable in unit tests with no WordPress and
 * no DB. Cap order (approved plan): representative collapse → per-type
 * / per-recipient / per-recognizer share caps → category ceilings →
 * findings penalties (Phase 8 — the caller resolves the active
 * penalty sum, already bounded by the §15.3 caps) → inactivity decay.
 *
 * Time is DERIVED (distinct login months × config rate, capped), never
 * ledgered. Decay derives from the last explicit-login day: zero
 * through the 365-day grace, then step-per-30-days — a login self-heals
 * with zero writes (§12.3).
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign Phase 4 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

use BCC\Trust\Rank\Support\RankScoringConfig;

if (!defined('ABSPATH')) {
    exit;
}

class RankScoreCalculator
{
    public function __construct(
        private readonly RankScoringConfig $config
    ) {
    }

    /**
     * @param list<object{category: string, source_type: string, relationship_user_id: numeric-string|int, capped_value: numeric-string|float}> $activeEvents
     *        Active ledger rows for ONE subject
     *        (RankEventsRepository::listActiveForSubject shape).
     * @param int $distinctLoginMonths LoginDaysRepository::distinctMonthCount
     * @param string|null $lastLoginDay 'Y-m-d' or null (never logged in)
     * @param array<int, int> $memberToRepresentative Active confirmed
     *        cluster map (IndependenceResolver's map shape) — collapses
     *        clustered RELATIONSHIP identities to their representative
     *        so a ring of recognizers counts as one voice.
     * @param string|null $today 'Y-m-d' override for tests; null = now.
     * @param float $findingPenalty Resolved active misconduct penalty
     *        (§15.3, Phase 8) — the CALLER sums active unexpired finding
     *        penalties and applies the total-active cap; the calculator
     *        stays pure and just subtracts.
     *
     * @return array{
     *     categories: array<string, float>,
     *     decay: float,
     *     finding_penalty: float,
     *     total: float
     * }
     */
    public function calculate(
        array $activeEvents,
        int $distinctLoginMonths,
        ?string $lastLoginDay,
        array $memberToRepresentative = [],
        ?string $today = null,
        float $findingPenalty = 0.0
    ): array {
        $categories = [];
        foreach (array_keys($this->config->categoryMax) as $category) {
            $categories[$category] = 0.0;
        }

        // ── Evidence categories ─────────────────────────────────────
        // Group capped values per category, keyed by the cap identity
        // that category's share cap uses (§8.2 type / §9.3 recipient /
        // §10.3 recognizer). Clustered relationship identities collapse
        // to their representative FIRST, so a recognizer ring shares
        // one identity bucket (one voice) before the share cap applies.
        $byCategoryAndIdentity = [];
        foreach ($activeEvents as $event) {
            $category = (string) $event->category;
            if (!isset($categories[$category]) || $category === 'time') {
                continue; // Unknown category rows are inert; time is derived.
            }

            $identity = match ($category) {
                'contribution' => (string) $event->source_type,
                'helping', 'recognition' => (string) $this->collapse(
                    (int) $event->relationship_user_id,
                    $memberToRepresentative
                ),
                default => '*',
            };

            $byCategoryAndIdentity[$category][$identity] =
                ($byCategoryAndIdentity[$category][$identity] ?? 0.0)
                + (float) $event->capped_value;
        }

        $shareCapByCategory = [
            'contribution' => $this->config->shareCaps['contribution_type'],
            'helping'      => $this->config->shareCaps['helping_recipient'],
            'recognition'  => $this->config->shareCaps['recognizer'],
        ];

        foreach ($byCategoryAndIdentity as $category => $identities) {
            $ceiling  = $this->config->categoryMax[$category];
            $shareCap = isset($shareCapByCategory[$category])
                ? $shareCapByCategory[$category] * $ceiling
                : $ceiling;

            $sum = 0.0;
            foreach ($identities as $value) {
                $sum += min($value, $shareCap);
            }

            $categories[$category] = round(min($sum, $ceiling), 4);
        }

        // ── Time (derived, §12.1) ───────────────────────────────────
        $categories['time'] = round(min(
            $distinctLoginMonths * $this->config->timeCreditPerMonth,
            $this->config->timeCreditCap
        ), 4);

        // ── Findings penalty (§15.3, Phase 8) then decay (§12.3) ────
        // Order per the approved plan: penalties subtract after the
        // category ceilings, before/alongside decay; the floor is 0.
        $findingPenalty = max(0.0, $findingPenalty);
        $decay          = $this->decayFor($lastLoginDay, $today);

        $total = max(0.0, min(100.0, array_sum($categories) - $findingPenalty - $decay));

        return [
            'categories'      => $categories,
            'decay'           => $decay,
            'finding_penalty' => round($findingPenalty, 4),
            'total'           => round($total, 4),
        ];
    }

    /**
     * §12.3: no decay through the 365-day grace; then points_per_step
     * for each further step_days period. A member who has NEVER logged
     * in (null) has nothing to decay from a login clock — their score
     * is bounded by the evidence they also don't have.
     */
    public function decayFor(?string $lastLoginDay, ?string $today = null): float
    {
        if ($lastLoginDay === null) {
            return 0.0;
        }

        $todayTs = $today !== null ? strtotime($today . ' 00:00:00 UTC') : time();
        $lastTs  = strtotime($lastLoginDay . ' 00:00:00 UTC');
        if ($todayTs === false || $lastTs === false || $lastTs >= $todayTs) {
            return 0.0;
        }

        $daysSince = (int) floor(($todayTs - $lastTs) / 86400);
        if ($daysSince <= $this->config->decayGraceDays) {
            return 0.0;
        }

        $steps = (int) floor(($daysSince - $this->config->decayGraceDays) / $this->config->decayStepDays);

        return round($steps * $this->config->decayPointsPerStep, 4);
    }

    /** @param array<int, int> $map */
    private function collapse(int $relationshipUserId, array $map): int
    {
        if ($relationshipUserId <= 0) {
            return 0;
        }

        return $map[$relationshipUserId] ?? $relationshipUserId;
    }
}
