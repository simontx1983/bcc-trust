<?php
/**
 * Scenario 2 — ring sims (anti-gaming proof): rings of R ∈ {3,5,10}
 * accounts, mutual attest/help at heavy cadence, NO outside
 * recognition. Each ring runs twice:
 *
 *   (a) CONFIRMED cluster finding active — ingest-time R3 zero-credit
 *       for non-representatives + calculator-side representative
 *       collapse (memberToRepresentative all→rep);
 *   (b) SUSPECTED only — share caps and requirement minimums alone.
 *
 * Adversary-favourable tier model: every ring member is tier-qualified
 * (neutral+ day 0, trusted+ day 90).
 */

declare(strict_types=1);

/** @return array<string, mixed> */
function calib_scenario_rings(): array
{
    $config = calib_config();
    $calc   = calib_calculator();

    $out = [];
    foreach ([3, 5, 10] as $r) {
        $seed = CalibMemberSimulator::seed("ring:{$r}");
        $ring = CalibRingSimulator::generate($r, $seed, CALIB_MAX_DAY);

        $repIdx = 0;
        $repId  = $repIdx + 1;

        // Confirmed member→representative map (member ids are idx+1).
        $clusterMap = [];
        for ($i = 0; $i < $r; $i++) {
            $clusterMap[$i + 1] = $repId;
        }

        $evidenceSum = static function (array $categories): float {
            // Ledgered evidence only — time is DERIVED from logins, never
            // ledgered, so it is excluded from the evidence comparison.
            return round(
                $categories['contribution'] + $categories['helping']
                + $categories['recognition'] + $categories['outcomes'],
                4
            );
        };

        $assessMember = static function (int $idx, string $treatment, array $map) use ($ring, $config, $calc, $repIdx, $r): array {
            $mat = CalibRingSimulator::materialize($ring, $idx, $treatment, $repIdx, $config, CALIB_MAX_DAY);
            $gen = [
                'rows'                => $mat['rows'],
                'prefix'              => $mat['prefix'],
                'login_months_by_day' => $ring['members'][$idx]['login_months_by_day'],
                'last_login_by_day'   => $ring['members'][$idx]['last_login_by_day'],
            ];
            return [
                'gen' => $gen,
                'at180' => CalibMemberSimulator::assessAt(
                    $calc, $config, $gen, 180, true, CalibProfiles::TRUSTED_FROM_DAY, $map
                ),
            ];
        };

        // ── (a) CONFIRMED ────────────────────────────────────────────
        $confirmed = [];
        $combinedEvidence = 0.0;
        $nonRepAllZero = true;
        $nonRepRecognitionZero = true;
        $nonRepFailAt180 = true;
        foreach (range(0, $r - 1) as $idx) {
            $a = $assessMember($idx, 'confirmed', $clusterMap)['at180'];
            $combinedEvidence += $evidenceSum($a['categories']);
            $isRep = $idx === $repIdx;
            if (!$isRep) {
                if ($a['categories']['recognition'] !== 0.0) {
                    $nonRepRecognitionZero = false;
                }
                if ($evidenceSum($a['categories']) !== 0.0) {
                    $nonRepAllZero = false;
                }
                if ($a['satisfied']['journeyman']) {
                    $nonRepFailAt180 = false;
                }
            }
            $confirmed['members'][] = [
                'member'             => $idx + 1,
                'is_representative'  => $isRep,
                'total'              => $a['total'],
                'categories'         => $a['categories'],
                'evidence_sum'       => $evidenceSum($a['categories']),
                'recognizers'        => $a['recognizers'],
                'journeyman_at_180'  => $a['satisfied']['journeyman'],
            ];
        }

        // Honest-equivalent control: ONE member, identical activity,
        // counterparties remapped to R−1 independent external ids.
        $twin = $assessMember($repIdx, 'twin', [])['at180'];
        $twinEvidence = $evidenceSum($twin['categories']);

        $confirmed['combined_evidence_sum']     = round($combinedEvidence, 4);
        $confirmed['honest_twin_evidence_sum']  = $twinEvidence;
        $confirmed['honest_twin']               = [
            'total'             => $twin['total'],
            'categories'        => $twin['categories'],
            'recognizers'       => $twin['recognizers'],
            'journeyman_at_180' => $twin['satisfied']['journeyman'],
        ];
        $confirmed['note_time_category'] = 'time is login-derived, not ledgered evidence: '
            . 'each ring member still accrues time credit, so the TIME-inclusive ring total '
            . 'exceeds one member\'s; the collapse claim is about ledgered evidence.';

        $confirmed['assertions'] = [
            'non_rep_recognition_exactly_zero' => $nonRepRecognitionZero,
            'non_rep_all_evidence_zero'        => $nonRepAllZero,
            'all_non_rep_fail_promotion_180'   => $nonRepFailAt180,
            'combined_le_honest_equivalent'    => $combinedEvidence <= $twinEvidence + 1e-9,
        ];

        // ── (b) SUSPECTED only ───────────────────────────────────────
        $suspected = ['members' => []];
        $anyPromotedByDay = [];
        foreach (range(0, $r - 1) as $idx) {
            $res = $assessMember($idx, 'suspected', []);
            $a   = $res['at180'];
            $jDay = CalibMemberSimulator::firstHoldDay(
                $calc, $config, $res['gen'], 'journeyman', CALIB_MAX_DAY,
                true, CalibProfiles::TRUSTED_FROM_DAY
            );
            $vDay = $jDay === null ? null : CalibMemberSimulator::firstHoldDay(
                $calc, $config, $res['gen'], 'veteran', CALIB_MAX_DAY,
                true, CalibProfiles::TRUSTED_FROM_DAY, [], [], $jDay
            );
            if ($jDay !== null) {
                $anyPromotedByDay[] = $jDay;
            }
            $blocking = array_keys(array_filter(
                $a['terms']['journeyman'],
                static fn (bool $ok): bool => !$ok
            ));
            $suspected['members'][] = [
                'member'                    => $idx + 1,
                'journeyman_first_hold_day' => $jDay,
                'veteran_first_hold_day'    => $vDay,
                'total_at_180'              => $a['total'],
                'categories_at_180'         => $a['categories'],
                'recognizers_at_180'        => $a['recognizers'],
                'journeyman_blocking_at_180' => $blocking,
            ];
        }
        $suspected['journeyman_blocked_for_all'] = $anyPromotedByDay === [];
        $suspected['earliest_journeyman_day']    = $anyPromotedByDay === [] ? null : min($anyPromotedByDay);

        $out["R{$r}"] = ['confirmed' => $confirmed, 'suspected' => $suspected];
    }

    $targets = [
        'confirmed_non_rep_zero_recognition' => [
            'target' => 'CONFIRMED: every non-representative earns exactly 0.0 recognition credit (all R)',
            'actual' => array_map(static fn (array $x): bool => $x['confirmed']['assertions']['non_rep_recognition_exactly_zero'], $out),
            'pass'   => !in_array(false, array_map(static fn (array $x): bool => $x['confirmed']['assertions']['non_rep_recognition_exactly_zero'], $out), true),
        ],
        'confirmed_non_rep_fail_180' => [
            'target' => 'CONFIRMED: 100% of non-representative members fail promotion at day 180 (all R)',
            'actual' => array_map(static fn (array $x): bool => $x['confirmed']['assertions']['all_non_rep_fail_promotion_180'], $out),
            'pass'   => !in_array(false, array_map(static fn (array $x): bool => $x['confirmed']['assertions']['all_non_rep_fail_promotion_180'], $out), true),
        ],
        'confirmed_collapse_to_one_identity' => [
            'target' => 'CONFIRMED: ring combined ledgered evidence <= one honest member equivalent (all R)',
            'actual' => array_map(static fn (array $x): bool => $x['confirmed']['assertions']['combined_le_honest_equivalent'], $out),
            'pass'   => !in_array(false, array_map(static fn (array $x): bool => $x['confirmed']['assertions']['combined_le_honest_equivalent'], $out), true),
        ],
        'suspected_r3_blocked' => [
            'target' => 'SUSPECTED R=3: journeyman blocked for every member (recognizer minimum 3 needs >= 3 independent recognizers)',
            'actual' => $out['R3']['suspected']['journeyman_blocked_for_all'],
            'pass'   => $out['R3']['suspected']['journeyman_blocked_for_all'],
        ],
        'suspected_r5_r10_report' => [
            'target' => 'INFO: SUSPECTED R=5 / R=10 outcomes reported honestly (caps alone may not block)',
            'actual' => [
                'R5_earliest_journeyman'  => $out['R5']['suspected']['earliest_journeyman_day'],
                'R10_earliest_journeyman' => $out['R10']['suspected']['earliest_journeyman_day'],
            ],
            'pass'   => null,
        ],
    ];

    return [
        'scenario' => 'rings',
        'model'    => [
            'cadence'    => CalibProfiles::RING,
            'tier_model' => 'every ring member tier-qualified (adversary-favourable)',
            'horizon'    => CALIB_MAX_DAY,
        ],
        'rings'    => $out,
        'targets'  => $targets,
    ];
}
