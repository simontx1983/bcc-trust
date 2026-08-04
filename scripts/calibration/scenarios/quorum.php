<?php
/**
 * Scenario 4 — quorum reachability: from the day-90/180/365 weight
 * distributions, for eligible-pool sizes E ∈ {5,10,15,20,30,50}:
 *
 *   - deterministic: sum of the top-10 weights vs the §18.1 dual
 *     quorum (>=10 voters AND >=7.5 combined weight, post-cap);
 *   - probabilistic: 500 trials per participation rate (30% / 50%),
 *     P(>=10 participants whose combined weight >= 7.5).
 *
 * Quorum test = the MIRRORED PollService arithmetic (CalibPollMath).
 * No clusters in the honest pool, so the suspected cap is inert here.
 */

declare(strict_types=1);

/** @return array<string, mixed> */
function calib_scenario_quorum(): array
{
    $config = calib_config();

    $poolSizes = [5, 10, 15, 20, 30, 50];
    $participationRates = [0.3, 0.5];
    $trials = 500;

    $table = [];
    foreach ([90, 180, 365] as $day) {
        $all = calib_member_weights($day);

        foreach ($poolSizes as $e) {
            // Deterministic pool sample (Fisher-Yates on a seeded stream).
            mt_srand(800000 + $day * 1000 + $e);
            $idx = range(0, count($all) - 1);
            for ($i = count($idx) - 1; $i > 0; $i--) {
                $j = mt_rand(0, $i);
                [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
            }
            $pool = [];
            foreach (array_slice($idx, 0, $e) as $k) {
                $pool[] = $all[$k]['weight'];
            }

            $sorted = $pool;
            rsort($sorted);
            $top10 = $e >= $config->quorumVoters
                ? round(array_sum(array_slice($sorted, 0, $config->quorumVoters)), 4)
                : null;
            $reachable = $e >= $config->quorumVoters
                && CalibPollMath::meetsQuorum($config->quorumVoters, (float) $top10, $config);

            $probs = [];
            foreach ($participationRates as $p) {
                $success = 0;
                for ($t = 0; $t < $trials; $t++) {
                    $voters = 0;
                    $weight = 0.0;
                    foreach ($pool as $w) {
                        if (mt_rand() / mt_getrandmax() < $p) {
                            $voters++;
                            $weight += $w;
                        }
                    }
                    if (CalibPollMath::meetsQuorum($voters, $weight, $config)) {
                        $success++;
                    }
                }
                $probs[sprintf('p_%d', (int) round($p * 100))] = round($success / $trials, 3);
            }

            $table[] = [
                'day'                 => $day,
                'pool_size'           => $e,
                'top10_weight'        => $top10,
                'top10_reaches_quorum' => $reachable,
                'quorum_probability'  => $probs,
            ];
        }
    }

    // Smallest pool where a binding outcome is feasible more often than
    // not, per participation rate (day-365 distribution).
    $feasibleAt = [];
    foreach ($participationRates as $p) {
        $key = sprintf('p_%d', (int) round($p * 100));
        $min = null;
        foreach ($table as $row) {
            if ($row['day'] === 365 && $row['quorum_probability'][$key] >= 0.5) {
                $min = $min === null ? $row['pool_size'] : min($min, $row['pool_size']);
            }
        }
        $feasibleAt[$key] = $min;
    }

    return [
        'scenario' => 'quorum_reachability',
        'model'    => [
            'quorum'  => ['voters' => $config->quorumVoters, 'weight' => $config->quorumWeight],
            'trials'  => $trials,
            'note'    => 'plan EXPECTS early Inconclusive dominance; this table locates where binding becomes feasible',
        ],
        'table'    => $table,
        'min_pool_for_p50_binding_day365' => $feasibleAt,
        'targets'  => [
            'reachability_reported' => [
                'target' => 'INFO: honest report of quorum reachability (no hard pass/fail — plan expects early Inconclusive)',
                'actual' => $feasibleAt,
                'pass'   => null,
            ],
        ],
    ];
}
