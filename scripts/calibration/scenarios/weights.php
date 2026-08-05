<?php
/**
 * Scenario 3 — vote-weight distribution: every honest-cohort member's
 * effective weight at days 90/180/365 through the REAL
 * VoteWeightCalculator (maturity × rank × trust × fraud, ceiling-
 * clamped). Trust scores are modeled tier-consistently (see
 * calib_trust_draw); fraud signals are clean (discount 1.0).
 */

declare(strict_types=1);

/**
 * Tier-consistent trust-score draw (MODELING ASSUMPTION):
 *   non-qualified (20%): caution, 30-44;
 *   qualified, day < trusted-from: neutral, 45-59;
 *   qualified, later: trusted, 60-80.
 *
 * @return array{tier: string, score: float}
 */
function calib_trust_draw(int $memberId, int $day, bool $tierQualified): array
{
    mt_srand(700000 + $memberId * 10 + ($day % 97));
    $uniform = static function (float $lo, float $hi): float {
        return round($lo + (mt_rand() / mt_getrandmax()) * ($hi - $lo), 2);
    };
    if (!$tierQualified) {
        return ['tier' => 'caution', 'score' => $uniform(30.0, 44.0)];
    }
    if ($day < CalibProfiles::TRUSTED_FROM_DAY) {
        return ['tier' => 'neutral', 'score' => $uniform(45.0, 59.0)];
    }
    return ['tier' => 'trusted', 'score' => $uniform(60.0, 80.0)];
}

/**
 * Effective weights for the full honest cohort at one snapshot day
 * (memoized — the quorum scenario reuses these).
 *
 * @return list<array{member_id: int, profile: string, rank: string, tier: string, trust_score: float, weight: float}>
 */
function calib_member_weights(int $day): array
{
    static $cache = [];
    if (isset($cache[$day])) {
        return $cache[$day];
    }

    $cohort = calib_honest_cohort();
    $vwc    = calib_vote_weight_calculator();
    $now    = new DateTimeImmutable(calib_day_to_datetime($day), new DateTimeZone('UTC'));

    $weights = [];
    foreach ($cohort['members'] as $m) {
        $rank = 'apprentice';
        if ($m['veteran_day'] !== null && $m['veteran_day'] <= $day) {
            $rank = 'veteran';
        } elseif ($m['journeyman_day'] !== null && $m['journeyman_day'] <= $day) {
            $rank = 'journeyman';
        }

        $draw = calib_trust_draw($m['member_id'], $day, $m['tier_qualified']);

        // REAL production weight math: clean fraud signals, apprentice
        // epoch = account creation (day 0), not in recovery.
        $vw = $vwc->calculate(
            $rank,
            calib_day_to_datetime(0),
            $draw['score'],
            $draw['tier'],
            [],
            0,
            $now,
            false
        );

        $weights[] = [
            'member_id'   => $m['member_id'],
            'profile'     => $m['profile'],
            'rank'        => $rank,
            'tier'        => $draw['tier'],
            'trust_score' => $draw['score'],
            'weight'      => $vw->effective,
        ];
    }

    return $cache[$day] = $weights;
}

/** @return array<string, mixed> */
function calib_scenario_weights(): array
{
    $config = calib_config();

    $days = [90, 180, 365];
    $perDay = [];
    $allPass = ['ceiling' => true, 'gini' => true];

    foreach ($days as $day) {
        $rows    = calib_member_weights($day);
        $weights = array_map(static fn (array $r): float => $r['weight'], $rows);

        $max       = max($weights);
        $overCount = count(array_filter(
            $weights,
            static fn (float $w): bool => $w > $config->voteCeiling + 1e-9
        ));
        $gini = CalibStats::gini($weights);

        $rankMix = ['apprentice' => 0, 'journeyman' => 0, 'veteran' => 0];
        foreach ($rows as $r) {
            $rankMix[$r['rank']]++;
        }

        if ($overCount > 0) {
            $allPass['ceiling'] = false;
        }
        if ($gini === null || $gini > 0.65) {
            $allPass['gini'] = false;
        }

        $perDay["day_{$day}"] = [
            'n'              => count($weights),
            'max'            => round($max, 4),
            'count_over_ceiling' => $overCount,
            'min'            => round(min($weights), 4),
            'median'         => CalibStats::median($weights),
            'gini'           => $gini,
            'rank_mix'       => $rankMix,
            'histogram_0_10' => CalibStats::histogram($weights, 0.1, 0.0, 1.8),
        ];
    }

    return [
        'scenario' => 'vote_weights',
        'model'    => [
            'trust_scores' => 'tier-consistent draws: caution 30-44, neutral 45-59, trusted 60-80 (ASSUMED)',
            'fraud'        => 'clean signals, fraud_score 0 (discount 1.0)',
            'maturity'     => 'tenure epoch = signup = day 0 for every member (accounts created day 0)',
        ],
        'per_day'  => $perDay,
        'targets'  => [
            'max_weight_le_1_75' => [
                'target' => 'max effective weight <= vote_ceiling (' . $config->voteCeiling . '), zero members above, all snapshot days',
                'actual' => array_map(static fn (array $d): float => $d['max'], $perDay),
                'pass'   => $allPass['ceiling'],
            ],
            'gini_le_0_65' => [
                'target' => 'Gini coefficient of effective weights <= 0.65, all snapshot days',
                'actual' => array_map(static fn (array $d): ?float => $d['gini'], $perDay),
                'pass'   => $allPass['gini'],
            ],
        ],
    ];
}
