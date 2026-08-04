<?php
/**
 * Scenario 1 — honest cohorts: 200 members × 4 activity profiles,
 * day-stepped 0..400. Finds each member's first Journeyman-hold and
 * first Veteran-hold day (real calculator + mirrored requirement
 * predicate) and reports promotion-day distributions against the plan
 * targets.
 */

declare(strict_types=1);

/**
 * Shared cohort provider (memoized) — weights/quorum/decay reuse it.
 *
 * @return array{
 *     members: list<array{member_id: int, profile: string, index: int, seed: int, tier_qualified: bool, journeyman_day: int|null, veteran_day: int|null}>,
 *     day400_snapshots: array<string, array<string, mixed>>
 * }
 */
function calib_honest_cohort(): array
{
    static $cohort = null;
    if ($cohort !== null) {
        return $cohort;
    }

    $config = calib_config();
    $calc   = calib_calculator();

    $profiles   = CalibProfiles::HONEST;
    $n          = CalibProfiles::COHORT_N;
    $cohortSize = count($profiles) * $n;

    $members   = [];
    $snapshots = [];
    $memberId  = 0;

    foreach ($profiles as $profileName => $profile) {
        for ($i = 0; $i < $n; $i++) {
            $memberId++;
            // Exactly 80% tier-qualified (deterministic, not drawn).
            $tierQualified = ($i % 5) !== 0;
            $seed          = CalibMemberSimulator::seed("honest:{$profileName}:{$i}");

            $gen = CalibMemberSimulator::generate($config, $profile, $memberId, $cohortSize, $seed, CALIB_MAX_DAY);

            $jDay = CalibMemberSimulator::firstHoldDay(
                $calc, $config, $gen, 'journeyman', CALIB_MAX_DAY,
                $tierQualified, CalibProfiles::TRUSTED_FROM_DAY
            );
            $vDay = null;
            if ($jDay !== null) {
                // Veteran requirements dominate Journeyman's in this model,
                // so Veteran can only hold at or after the Journeyman day.
                $vDay = CalibMemberSimulator::firstHoldDay(
                    $calc, $config, $gen, 'veteran', CALIB_MAX_DAY,
                    $tierQualified, CalibProfiles::TRUSTED_FROM_DAY,
                    [], [], $jDay
                );
            }

            // Why-blocked snapshot at day 400 for the first tier-qualified
            // member of each profile (diagnostic for the report).
            if ($i === 1) {
                $a = CalibMemberSimulator::assessAt(
                    $calc, $config, $gen, CALIB_MAX_DAY,
                    $tierQualified, CalibProfiles::TRUSTED_FROM_DAY
                );
                $snapshots[$profileName] = [
                    'day'                => CALIB_MAX_DAY,
                    'total'              => $a['total'],
                    'categories'         => $a['categories'],
                    'contribution_types' => $a['contribution_types'],
                    'recognizers'        => $a['recognizers'],
                    'outcomes'           => $a['outcomes'],
                    'outcome_types'      => $a['outcome_types'],
                    'journeyman_terms'   => $a['terms']['journeyman'],
                    'veteran_terms'      => $a['terms']['veteran'],
                ];
            }

            $members[] = [
                'member_id'      => $memberId,
                'profile'        => $profileName,
                'index'          => $i,
                'seed'           => $seed,
                'tier_qualified' => $tierQualified,
                'journeyman_day' => $jDay,
                'veteran_day'    => $vDay,
            ];

            unset($gen);
        }
    }

    return $cohort = ['members' => $members, 'day400_snapshots' => $snapshots];
}

/** @return array<string, mixed> */
function calib_scenario_honest(): array
{
    $cohort = calib_honest_cohort();

    $perProfile = [];
    $allJDays   = [];
    $allVDays   = [];

    foreach (CalibProfiles::HONEST as $profileName => $profile) {
        $jDays = [];
        $vDays = [];
        $count = 0;
        foreach ($cohort['members'] as $m) {
            if ($m['profile'] !== $profileName) {
                continue;
            }
            $count++;
            if ($m['journeyman_day'] !== null) {
                $jDays[] = $m['journeyman_day'];
                $allJDays[] = $m['journeyman_day'];
            }
            if ($m['veteran_day'] !== null) {
                $vDays[] = $m['veteran_day'];
                $allVDays[] = $m['veteran_day'];
            }
        }
        $perProfile[$profileName] = [
            'n'                    => $count,
            'journeyman'           => CalibStats::daySummary($jDays),
            'journeyman_reach_pct' => round(100.0 * count($jDays) / $count, 1),
            'veteran'              => CalibStats::daySummary($vDays),
            'veteran_reach_pct'    => round(100.0 * count($vDays) / $count, 1),
        ];
    }

    $vBy = static function (int $day) use ($allVDays): int {
        return count(array_filter($allVDays, static fn (int $d): bool => $d <= $day));
    };

    $jMedian   = CalibStats::median($allJDays);
    $earliestJ = $allJDays === [] ? null : min($allJDays);
    $earliestV = $allVDays === [] ? null : min($allVDays);

    $targets = [
        'journeyman_median_75_110' => [
            'target' => 'median first-Journeyman day within 75-110 (over promoted members)',
            'actual' => $jMedian,
            'pass'   => $jMedian !== null && $jMedian >= 75 && $jMedian <= 110,
        ],
        'earliest_journeyman_ge_45' => [
            'target' => 'earliest Journeyman day >= 45 (trust-window floor)',
            'actual' => $earliestJ,
            'pass'   => $earliestJ !== null && $earliestJ >= 45,
        ],
        'earliest_veteran_ge_120' => [
            'target' => 'earliest Veteran day >= 120 (veteran trust-window floor)',
            'actual' => $earliestV,
            'pass'   => $earliestV === null || $earliestV >= 120,
        ],
        'veteran_plan_band_info' => [
            'target' => 'INFO: plan expects first Veteran in months 10-16 (~day 300-480; horizon is 400)',
            'actual' => $earliestV,
            'pass'   => null,
        ],
    ];

    return [
        'scenario'    => 'honest_cohorts',
        'model'       => [
            'epoch'            => CALIB_EPOCH,
            'horizon_days'     => CALIB_MAX_DAY,
            'members_per_profile' => CalibProfiles::COHORT_N,
            'tier_model'       => '80% of members neutral+ from day 0 and trusted+ from day '
                . CalibProfiles::TRUSTED_FROM_DAY . ' (ASSUMED); 20% caution (never qualify)',
            'profiles'         => CalibProfiles::HONEST,
        ],
        'per_profile' => $perProfile,
        'overall'     => [
            'journeyman'          => CalibStats::daySummary($allJDays),
            'journeyman_promoted' => count($allJDays),
            'veteran'             => CalibStats::daySummary($allVDays),
            'veteran_promoted'    => count($allVDays),
            'veteran_by_day_120'  => $vBy(120),
            'veteran_by_day_180'  => $vBy(180),
            'veteran_by_day_365'  => $vBy(365),
            'journeyman_histogram_30d' => CalibStats::histogram($allJDays, 30.0, 0.0, 420.0),
            'veteran_histogram_30d'    => CalibStats::histogram($allVDays, 30.0, 0.0, 420.0),
        ],
        'day400_why_blocked' => $cohort['day400_snapshots'],
        'targets'     => $targets,
    ];
}
