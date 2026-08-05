<?php
/**
 * Scenario 6 — helping restore: does modeling the two REAL helping
 * emitters (helpful_mark receipts + weekly owner stewardship) make the
 * Phase-9-zeroed helping gates REACHABLE again?
 *
 * Runs the honest cohort under TWO configs (identical ledgers, different
 * promotion predicate — see calib_restore_config):
 *
 *   1. SHIPPED  — helping minimums 0, veteran contribution-diversity 4.
 *   2. RESTORE  — helping journeyman 7.5 / veteran 15; diversity 5.
 *
 * Reports, for the honest cohorts:
 *   - the day-400 helping distribution (config-independent): % of each
 *     profile reaching >= 7.5 (Journeyman) and >= 15 (Veteran);
 *   - the contribution-diversity picture: % reaching 5 distinct types,
 *     split owner vs non-owner (stewardship is the only 5th type);
 *   - Journeyman/Veteran promotion reach + median under BOTH configs, so
 *     the RESTORE-vs-SHIPPED shift is explicit;
 *   - an ANTI-FARM assertion computed straight through the REAL
 *     RankScoreCalculator: fewer than 10 distinct markers can NEVER fill
 *     helping, and volume from a small marker pool cannot beat the §9.3
 *     0.10 per-marker cap.
 */

declare(strict_types=1);

use BCC\Trust\Rank\Services\RankScoreCalculator;
use BCC\Trust\Rank\Support\RankScoringConfig;

/**
 * Helping category value the REAL calculator assigns to a member who
 * received $marksEach helpful marks from each of $distinctMarkers
 * distinct credible markers (optionally plus enough weekly stewardship
 * to saturate the identity-0 bucket). Pure — the anti-farm proof.
 */
function calib_helping_for(
    RankScoringConfig $config,
    RankScoreCalculator $calc,
    int $distinctMarkers,
    int $marksEach,
    bool $withStewardship = false
): float {
    $rows = [];
    for ($k = 0; $k < $distinctMarkers; $k++) {
        $markerId = 900001 + $k;
        for ($m = 0; $m < $marksEach; $m++) {
            CalibMemberSimulator::ingest($rows, $config, 'helpful_mark', $markerId, 0, "af:{$k}:{$m}");
        }
    }
    if ($withStewardship) {
        for ($w = 0; $w < 60; $w++) { // 60 weeks ≫ 5 needed to saturate the 2.5 bucket
            CalibMemberSimulator::ingest($rows, $config, 'stewardship', 0, 0, "af:stew:{$w}");
        }
    }
    $result = $calc->calculate($rows, 0, null, [], calib_day_to_date(1), 0.0);
    return $result['categories']['helping'];
}

/**
 * Per-profile + overall promotion summary for one cohort build.
 *
 * @param array{members: list<array<string, mixed>>} $cohort
 * @return array<string, mixed>
 */
function calib_promotion_summary(array $cohort): array
{
    $perProfile = [];
    $allJ = [];
    $allV = [];
    foreach (CalibProfiles::HONEST as $profileName => $_p) {
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
                $allJ[]  = $m['journeyman_day'];
            }
            if ($m['veteran_day'] !== null) {
                $vDays[] = $m['veteran_day'];
                $allV[]  = $m['veteran_day'];
            }
        }
        $perProfile[$profileName] = [
            'n'                    => $count,
            'journeyman_reach_pct' => round(100.0 * count($jDays) / $count, 1),
            'journeyman_median'    => CalibStats::median($jDays),
            'veteran_reach_pct'    => round(100.0 * count($vDays) / $count, 1),
            'veteran_earliest'     => $vDays === [] ? null : min($vDays),
            'veteran_median'       => CalibStats::median($vDays),
        ];
    }

    return [
        'per_profile'         => $perProfile,
        'journeyman_median'   => CalibStats::median($allJ),
        'journeyman_promoted' => count($allJ),
        'veteran_promoted'    => count($allV),
        'veteran_earliest'    => $allV === [] ? null : min($allV),
        'veteran_median'      => CalibStats::median($allV),
    ];
}

/** @return array<string, mixed> */
function calib_scenario_helping(): array
{
    $shippedConfig = calib_config();
    $restoreConfig = calib_restore_config();

    $shipped = calib_build_cohort($shippedConfig, 'shipped');
    $restore = calib_build_cohort($restoreConfig, 'restore');

    // ── (a) day-400 helping distribution (config-independent) ─────────
    $helpingDist = [];
    foreach (CalibProfiles::HONEST as $profileName => $_p) {
        $helping = [];
        $ge75    = 0;
        $ge15    = 0;
        $owners  = 0;
        foreach ($shipped['members'] as $m) {
            if ($m['profile'] !== $profileName) {
                continue;
            }
            $h = (float) $m['day400']['helping'];
            $helping[] = $h;
            if ($h >= 7.5) {
                $ge75++;
            }
            if ($h >= 15.0) {
                $ge15++;
            }
            if ($m['is_owner']) {
                $owners++;
            }
        }
        $cnt = count($helping);
        $helpingDist[$profileName] = [
            'n'             => $cnt,
            'owners_n'      => $owners,
            'pct_ge_7_5'    => $cnt > 0 ? round(100.0 * $ge75 / $cnt, 1) : 0.0,
            'pct_ge_15'     => $cnt > 0 ? round(100.0 * $ge15 / $cnt, 1) : 0.0,
            'helping_min'   => $helping === [] ? null : round(min($helping), 4),
            'helping_median' => CalibStats::median($helping),
            'helping_max'   => $helping === [] ? null : round(max($helping), 4),
        ];
    }

    // ── (b) contribution-diversity-5 reachability ─────────────────────
    // Stewardship is the only 5th contribution source type → every member
    // that reaches 5 distinct types MUST be an owner. Assert it.
    $diversity = [];
    $ownerGe5 = 0;
    $ownerTot = 0;
    $nonOwnerGe5 = 0;
    $nonOwnerTot = 0;
    $anyNonOwnerReached5 = false;
    foreach (CalibProfiles::HONEST as $profileName => $_p) {
        $typesHist = [];
        $pGe5 = 0;
        $cnt  = 0;
        $ownGe5 = 0;
        $ownCnt = 0;
        foreach ($shipped['members'] as $m) {
            if ($m['profile'] !== $profileName) {
                continue;
            }
            $cnt++;
            $t = (int) $m['day400']['contribution_types'];
            $typesHist[(string) $t] = ($typesHist[(string) $t] ?? 0) + 1;
            if ($m['is_owner']) {
                $ownCnt++;
                $ownerTot++;
                if ($t >= 5) {
                    $ownGe5++;
                    $ownerGe5++;
                }
            } else {
                $nonOwnerTot++;
                if ($t >= 5) {
                    $nonOwnerGe5++;
                    $anyNonOwnerReached5 = true;
                }
            }
            if ($t >= 5) {
                $pGe5++;
            }
        }
        ksort($typesHist);
        $diversity[$profileName] = [
            'n'                => $cnt,
            'types_histogram'  => $typesHist,
            'pct_ge_5'         => $cnt > 0 ? round(100.0 * $pGe5 / $cnt, 1) : 0.0,
            'owners_n'         => $ownCnt,
            'owners_pct_ge_5'  => $ownCnt > 0 ? round(100.0 * $ownGe5 / $ownCnt, 1) : 0.0,
        ];
    }

    // ── (c) anti-farm proof (REAL calculator, huge volume) ────────────
    $calc = new RankScoreCalculator($shippedConfig);
    $marksHuge = 50; // ≫ 5, so every marker saturates its 2.5 cap
    $curve = [];
    foreach ([1, 2, 3, 5, 6, 9, 10, 12] as $nm) {
        $curve[(string) $nm] = round(calib_helping_for($shippedConfig, $calc, $nm, $marksHuge), 4);
    }
    $helping9  = calib_helping_for($shippedConfig, $calc, 9, $marksHuge);
    $helping10 = calib_helping_for($shippedConfig, $calc, 10, $marksHuge);
    $helping3volume = calib_helping_for($shippedConfig, $calc, 3, 1000); // volume can't beat the cap
    $helping3five   = calib_helping_for($shippedConfig, $calc, 3, 5);
    // Owner shortcut: stewardship (2.5) lets 5 markers clear Veteran 15.
    $helping5owner  = calib_helping_for($shippedConfig, $calc, 5, $marksHuge, true);
    $helping2owner  = calib_helping_for($shippedConfig, $calc, 2, $marksHuge, true);

    $antiFarmHolds =
        $helping9 < 25.0 - 1e-9                        // <10 markers cannot FILL helping
        && abs($helping9 - 22.5) < 1e-9               // exactly 9 × 2.5
        && abs($helping10 - 25.0) < 1e-9              // 10 markers fill it
        && abs($helping3volume - $helping3five) < 1e-9 // volume irrelevant past the cap
        && abs($helping3volume - 7.5) < 1e-9;         // 3 markers → exactly Journeyman min

    $antiFarm = [
        'per_marker_cap'                   => round($shippedConfig->shareCaps['helping_recipient'] * $shippedConfig->categoryMax['helping'], 4),
        'helping_by_distinct_markers'      => $curve,
        'markers_to_fill_25'               => 10,
        'markers_to_reach_veteran_15'      => 6,
        'markers_to_reach_journeyman_7_5'  => 3,
        'nonowner_9_markers_helping'       => round($helping9, 4),
        'nonowner_10_markers_helping'      => round($helping10, 4),
        'small_pool_volume_helping_3x1000' => round($helping3volume, 4),
        'small_pool_volume_helping_3x5'    => round($helping3five, 4),
        'owner_5_markers_plus_stewardship' => round($helping5owner, 4),
        'owner_2_markers_plus_stewardship' => round($helping2owner, 4),
        'assertion_holds'                  => $antiFarmHolds,
    ];

    // ── (d) promotion under both configs ──────────────────────────────
    $promShipped = calib_promotion_summary($shipped);
    $promRestore = calib_promotion_summary($restore);
    $jMedShift = ($promShipped['journeyman_median'] !== null && $promRestore['journeyman_median'] !== null)
        ? round((float) $promRestore['journeyman_median'] - (float) $promShipped['journeyman_median'], 1)
        : null;

    // ── targets ───────────────────────────────────────────────────────
    $regGe75 = $helpingDist['regular']['pct_ge_7_5'];
    $heaGe75 = $helpingDist['heavy']['pct_ge_7_5'];
    $regGe15 = $helpingDist['regular']['pct_ge_15'];
    $heaGe15 = $helpingDist['heavy']['pct_ge_15'];

    $restoreVeteranReached = $promRestore['veteran_promoted'] > 0;
    $shippedStillPromotes  = $promShipped['journeyman_promoted'] > 0 && $promShipped['veteran_promoted'] > 0;

    $targets = [
        'shipped_baseline_still_promotes' => [
            'target' => 'SHIPPED config: honest cohorts still reach Journeyman AND Veteran (baseline unbroken)',
            'actual' => ['journeyman' => $promShipped['journeyman_promoted'], 'veteran' => $promShipped['veteran_promoted']],
            'pass'   => $shippedStillPromotes,
        ],
        'helping_ge_7_5_reachable' => [
            'target' => 'RESTORE helping Journeyman min (7.5) reachable for regular AND heavy (>=50% of each by day 400)',
            'actual' => ['regular_pct' => $regGe75, 'heavy_pct' => $heaGe75],
            'pass'   => $regGe75 >= 50.0 && $heaGe75 >= 50.0,
        ],
        'helping_ge_15_reachable' => [
            'target' => 'RESTORE helping Veteran min (15) reachable for heavy (>=25% by day 400)',
            'actual' => ['regular_pct' => $regGe15, 'heavy_pct' => $heaGe15],
            'pass'   => $heaGe15 >= 25.0,
        ],
        'diversity5_owner_only' => [
            'target' => 'contribution-diversity-5 reached ONLY by community owners (stewardship is the 5th type)',
            'actual' => ['any_non_owner_reached_5' => $anyNonOwnerReached5, 'owners_ge5' => $ownerGe5, 'owner_total' => $ownerTot],
            'pass'   => !$anyNonOwnerReached5 && $ownerGe5 > 0,
        ],
        'restore_veteran_reachable' => [
            'target' => 'RESTORE config: at least one genuine member still reaches Veteran (diversity 5 + helping 15 not over-gated)',
            'actual' => ['veteran_promoted' => $promRestore['veteran_promoted'], 'earliest' => $promRestore['veteran_earliest']],
            'pass'   => $restoreVeteranReached,
        ],
        'restore_journeyman_not_overgated' => [
            'target' => 'RESTORE config: Journeyman still reachable and median shift vs SHIPPED is bounded (<=45d)',
            'actual' => [
                'shipped_median' => $promShipped['journeyman_median'],
                'restore_median' => $promRestore['journeyman_median'],
                'shift_days'     => $jMedShift,
                'restore_promoted' => $promRestore['journeyman_promoted'],
            ],
            'pass'   => $promRestore['journeyman_promoted'] > 0 && $jMedShift !== null && abs($jMedShift) <= 45.0,
        ],
        'anti_farm_10pct_cap_bites' => [
            'target' => 'ANTI-FARM: marks from <10 distinct markers cannot fill helping; volume cannot beat the 0.10 per-marker cap',
            'actual' => $antiFarm['assertion_holds'],
            'pass'   => $antiFarmHolds,
        ],
    ];

    return [
        'scenario' => 'helping_restore',
        'model'    => [
            'epoch'            => CALIB_EPOCH,
            'horizon_days'     => CALIB_MAX_DAY,
            'helping_category_max'  => $shippedConfig->categoryMax['helping'],
            'per_marker_share_cap'  => round($shippedConfig->shareCaps['helping_recipient'] * $shippedConfig->categoryMax['helping'], 4),
            'helpful_mark_points'   => $shippedConfig->points['helping_min'],
            'stewardship_points'    => $shippedConfig->points['stewardship'],
            'restore_overrides' => [
                'category_minimums.journeyman.helping' => 7.5,
                'category_minimums.veteran.helping'    => 15.0,
                'diversity_minimums.veteran_categories' => 5,
            ],
            'assumed_rates' => array_map(
                static fn (array $p): array => [
                    'helpful_received_per_month' => $p['helpful_received_per_month'],
                    'distinct_markers'           => $p['distinct_markers'],
                    'owner_period'               => $p['owner_period'],
                ],
                CalibProfiles::HONEST
            ),
            'stewardship_activation_day' => CalibProfiles::STEWARDSHIP_ACTIVATION_DAY,
            'stewardship_period_days'    => CalibProfiles::STEWARDSHIP_PERIOD_DAYS,
        ],
        'helping_distribution'    => $helpingDist,
        'contribution_diversity'  => [
            'per_profile'            => $diversity,
            'owners_ge5'             => $ownerGe5,
            'owners_total'           => $ownerTot,
            'non_owners_ge5'         => $nonOwnerGe5,
            'non_owners_total'       => $nonOwnerTot,
            'any_non_owner_reached_5' => $anyNonOwnerReached5,
        ],
        'anti_farm'  => $antiFarm,
        'promotion'  => [
            'shipped' => $promShipped,
            'restore' => $promRestore,
            'journeyman_median_shift_days' => $jMedShift,
        ],
        'targets'    => $targets,
    ];
}
