<?php
/**
 * Scenario 5 — decay + recovery:
 *
 * (a) A heavy member goes fully inactive at day 200. Config decay is
 *     grace 365d → 1pt per 30d step off the LAST-LOGIN clock, so
 *     nothing decays inside the day-400 window; this scenario alone
 *     runs to day 800 (brief instruction) and cross-checks the REAL
 *     RankScoreCalculator decay/total against the config arithmetic at
 *     every sampled day (plus a far-future floor probe).
 *
 * (b) Evidence loss: one tier-qualified member per profile loses 30%
 *     of their pre-day-150 events (reversal ⇒ rows leave the active
 *     set). Reports whether Journeyman requirements survive, and if
 *     lost, days-to-recover at the profile's ongoing cadence vs the
 *     §14.1 90-day recovery grace.
 */

declare(strict_types=1);

/** @return array<string, mixed> */
function calib_scenario_decay(): array
{
    $config = calib_config();
    $calc   = calib_calculator();

    // ── (a) inactivity decay ─────────────────────────────────────────
    $stopDay = 200;
    $horizon = 800;
    $profile = CalibProfiles::HONEST['heavy'];
    $seed    = CalibMemberSimulator::seed('decay:heavy:0');
    $gen     = CalibMemberSimulator::generate($config, $profile, 401, 800, $seed, $horizon, $stopDay);

    $lastLoginDay = $gen['last_login_by_day'][$horizon];
    if ($lastLoginDay === null) {
        throw new RuntimeException('decay scenario: member never logged in');
    }

    $sampleDays = [200, 300, 400, 500, $lastLoginDay + 365, $lastLoginDay + 366,
        $lastLoginDay + 394, $lastLoginDay + 395, $lastLoginDay + 396,
        $lastLoginDay + 425, $lastLoginDay + 426, $lastLoginDay + 455, 700, 750, 800];
    $sampleDays = array_values(array_unique(array_filter($sampleDays, static fn (int $d): bool => $d <= $horizon)));
    sort($sampleDays);

    $decayRows = [];
    $decayMatchesConfig = true;
    $noDecayThrough400 = true;
    $prevTotal = null;
    $monotoneNonIncreasing = true;
    foreach ($sampleDays as $day) {
        $a = CalibMemberSimulator::assessAt(
            $calc, $config, $gen, $day, true, CalibProfiles::TRUSTED_FROM_DAY
        );
        $daysSince = $day - $lastLoginDay;
        $expectedSteps = $daysSince > $config->decayGraceDays
            ? intdiv($daysSince - $config->decayGraceDays, $config->decayStepDays)
            : 0;
        $expected = round($expectedSteps * $config->decayPointsPerStep, 4);
        if (abs($a['decay'] - $expected) > 1e-9) {
            $decayMatchesConfig = false;
        }
        if ($day <= 400 && $a['decay'] > 0.0) {
            $noDecayThrough400 = false;
        }
        if ($prevTotal !== null && $a['total'] > $prevTotal + 1e-9 && $day > $stopDay + 31) {
            // After activity stops (and the last login month is complete)
            // the total may only fall.
            $monotoneNonIncreasing = false;
        }
        $prevTotal = $a['total'];
        $decayRows[] = [
            'day'             => $day,
            'days_since_login' => $daysSince,
            'decay_real'      => $a['decay'],
            'decay_expected'  => $expected,
            'total'           => $a['total'],
        ];
    }

    // Floor probe: far enough out that decay exceeds the whole score —
    // the REAL calculator must floor at 0, never go negative.
    $floorProbe = CalibMemberSimulator::assessAt(
        $calc, $config, ['rows' => $gen['rows'], 'prefix' => [3000 => $gen['prefix'][$horizon]],
            'login_months_by_day' => [3000 => $gen['login_months_by_day'][$horizon]],
            'last_login_by_day' => [3000 => $lastLoginDay]],
        3000, true, CalibProfiles::TRUSTED_FROM_DAY
    );
    $floorHolds = $floorProbe['total'] >= 0.0;

    // ── (b) evidence loss + recovery ─────────────────────────────────
    $cohort = calib_honest_cohort();
    $byProfileIdx = [];
    foreach ($cohort['members'] as $m) {
        if ($m['index'] === 1) {
            $byProfileIdx[$m['profile']] = $m;
        }
    }

    $recovery = [];
    $recoveryWithinGrace = true;
    $profileNames = array_keys(CalibProfiles::HONEST);
    foreach ($profileNames as $pIdx => $profileName) {
        $m = $byProfileIdx[$profileName];
        $gen2 = CalibMemberSimulator::generate(
            $config, CalibProfiles::HONEST[$profileName], $m['member_id'],
            count($profileNames) * CalibProfiles::COHORT_N, $m['seed'], CALIB_MAX_DAY
        );

        // Distinct event uids with sim_day <= 150; seeded 30% reversal.
        $uids = [];
        foreach ($gen2['rows'] as $row) {
            if ($row->sim_day <= 150) {
                $uids[$row->sim_uid] = true;
            }
        }
        $uidList = array_keys($uids);
        mt_srand(850000 + $pIdx);
        for ($i = count($uidList) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$uidList[$i], $uidList[$j]] = [$uidList[$j], $uidList[$i]];
        }
        $reverseCount = (int) floor(count($uidList) * 0.30);
        $excluded = array_fill_keys(array_slice($uidList, 0, $reverseCount), true);

        $entry = [
            'profile'              => $profileName,
            'member_id'            => $m['member_id'],
            'events_before_150'    => count($uidList),
            'events_reversed'      => $reverseCount,
            'journeyman_day_orig'  => $m['journeyman_day'],
        ];

        if ($m['journeyman_day'] !== null && $m['journeyman_day'] <= 150) {
            $at150 = CalibMemberSimulator::assessAt(
                $calc, $config, $gen2, 150, $m['tier_qualified'],
                CalibProfiles::TRUSTED_FROM_DAY, [], $excluded
            );
            if ($at150['satisfied']['journeyman']) {
                $entry['requirements_after_loss'] = 'retained';
                $entry['days_to_recover'] = 0;
            } else {
                $entry['requirements_after_loss'] = 'lost';
                $reDay = CalibMemberSimulator::firstHoldDay(
                    $calc, $config, $gen2, 'journeyman', CALIB_MAX_DAY,
                    $m['tier_qualified'], CalibProfiles::TRUSTED_FROM_DAY,
                    [], $excluded, 150
                );
                $entry['days_to_recover'] = $reDay === null ? null : $reDay - 150;
                $entry['recovered_within_90d_grace'] = $reDay !== null
                    && ($reDay - 150) <= $config->recoveryGraceDays;
                if ($entry['recovered_within_90d_grace'] !== true) {
                    $recoveryWithinGrace = false;
                }
                $entry['journeyman_blocking_at_150'] = array_keys(array_filter(
                    $at150['terms']['journeyman'],
                    static fn (bool $ok): bool => !$ok
                ));
            }
        } else {
            $newJDay = CalibMemberSimulator::firstHoldDay(
                $calc, $config, $gen2, 'journeyman', CALIB_MAX_DAY,
                $m['tier_qualified'], CalibProfiles::TRUSTED_FROM_DAY, [], $excluded
            );
            $entry['requirements_after_loss'] = 'n/a (not Journeyman by day 150)';
            $entry['journeyman_day_with_loss'] = $newJDay;
        }

        $recovery[] = $entry;
    }

    return [
        'scenario' => 'decay_recovery',
        'config'   => [
            'decay_grace_days'      => $config->decayGraceDays,
            'decay_step_days'       => $config->decayStepDays,
            'decay_points_per_step' => $config->decayPointsPerStep,
            'recovery_grace_days'   => $config->recoveryGraceDays,
        ],
        'inactivity' => [
            'stop_day'        => $stopDay,
            'last_login_day'  => $lastLoginDay,
            'first_decay_day_expected' => $lastLoginDay + $config->decayGraceDays + $config->decayStepDays,
            'samples'         => $decayRows,
            'floor_probe_day3000_total' => $floorProbe['total'],
        ],
        'evidence_loss' => $recovery,
        'targets' => [
            'decay_matches_config' => [
                'target' => 'REAL calculator decay == steps x points per config at every sampled day',
                'actual' => $decayMatchesConfig,
                'pass'   => $decayMatchesConfig,
            ],
            'no_decay_inside_grace' => [
                'target' => 'zero decay through day 400 (grace 365d > sim window; confirmed from config)',
                'actual' => $noDecayThrough400,
                'pass'   => $noDecayThrough400,
            ],
            'score_floor_at_zero' => [
                'target' => 'total never negative under unbounded decay (day-3000 probe)',
                'actual' => $floorProbe['total'],
                'pass'   => $floorHolds,
            ],
            'total_monotone_after_stop' => [
                'target' => 'total non-increasing once activity stops (time credit frozen, decay only)',
                'actual' => $monotoneNonIncreasing,
                'pass'   => $monotoneNonIncreasing,
            ],
            'recovery_within_grace' => [
                'target' => 'members losing requirements at day 150 re-satisfy within the 90-day grace at their own cadence (INFO if none lost)',
                'actual' => $recovery,
                'pass'   => $recoveryWithinGrace,
            ],
        ],
    ];
}
