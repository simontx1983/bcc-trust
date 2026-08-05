<?php
/**
 * Rank Phase 9 calibration harness — entrypoint.
 *
 *   php scripts/calibration/run.php               # full suite
 *   php scripts/calibration/run.php --scenario=X  # one of: honest|rings|weights|quorum|decay
 *
 * Pure PHP: no WordPress, no DB, no network. Deterministic — every
 * random draw is mt_srand-seeded from fixed labels, every timestamp
 * derives from CALIB_EPOCH, so results/*.json reproduce byte-identically.
 *
 * Writes results/<scenario>.json (raw distributions + stats + PASS/FAIL
 * per target) and results/summary.txt (human rollup of every scenario
 * with a results file present).
 *
 * NOT wired into CI (like scripts/perf) — run manually at calibration
 * checkpoints.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/scenarios/honest.php';
require_once __DIR__ . '/scenarios/rings.php';
require_once __DIR__ . '/scenarios/weights.php';
require_once __DIR__ . '/scenarios/quorum.php';
require_once __DIR__ . '/scenarios/decay.php';
require_once __DIR__ . '/scenarios/helping.php';

$scenarioFns = [
    'honest'  => 'calib_scenario_honest',
    'rings'   => 'calib_scenario_rings',
    'weights' => 'calib_scenario_weights',
    'quorum'  => 'calib_scenario_quorum',
    'decay'   => 'calib_scenario_decay',
    'helping' => 'calib_scenario_helping',
];

$requested = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--scenario=(\w+)$/', $arg, $m) === 1) {
        $requested = $m[1];
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        fwrite(STDERR, "Usage: php run.php [--scenario=" . implode('|', array_keys($scenarioFns)) . "]\n");
        exit(2);
    }
}
if ($requested !== null && !isset($scenarioFns[$requested])) {
    fwrite(STDERR, "Unknown scenario '{$requested}'. Valid: " . implode(', ', array_keys($scenarioFns)) . "\n");
    exit(2);
}

$resultsDir = __DIR__ . '/results';
if (!is_dir($resultsDir) && !mkdir($resultsDir, 0777, true)) {
    fwrite(STDERR, "Cannot create {$resultsDir}\n");
    exit(1);
}

// Guard: the mirrored poll arithmetic must match hand-computed
// PollService semantics before anything runs.
CalibPollMath::selfCheck(calib_config());

$toRun = $requested === null ? array_keys($scenarioFns) : [$requested];

foreach ($toRun as $name) {
    $t0 = microtime(true);
    fwrite(STDOUT, "Running scenario: {$name} ...\n");
    /** @var array<string, mixed> $result */
    $result = $scenarioFns[$name]();
    $elapsed = round(microtime(true) - $t0, 1);

    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) {
        fwrite(STDERR, "JSON encode failed for {$name}\n");
        exit(1);
    }
    file_put_contents($resultsDir . "/{$name}.json", $json . "\n");

    foreach ($result['targets'] as $key => $t) {
        $status = $t['pass'] === null ? 'INFO' : ($t['pass'] ? 'PASS' : 'FAIL');
        fwrite(STDOUT, sprintf("  [%s] %s\n", $status, $key));
    }
    fwrite(STDOUT, "  done in {$elapsed}s -> results/{$name}.json\n");
}

// ── summary.txt over every scenario with a results file ──────────────
$lines = [];
$lines[] = 'Rank Phase 9 calibration harness — summary';
$lines[] = 'Epoch day 0 = ' . CALIB_EPOCH . ' (deterministic; see README.md for real-vs-mirrored math)';
$lines[] = str_repeat('=', 72);

foreach (array_keys($scenarioFns) as $name) {
    $path = $resultsDir . "/{$name}.json";
    if (!is_file($path)) {
        continue;
    }
    /** @var array<string, mixed> $result */
    $result = json_decode((string) file_get_contents($path), true);
    $lines[] = '';
    $lines[] = strtoupper($name) . ' (' . $result['scenario'] . ')';
    $lines[] = str_repeat('-', 72);
    foreach ($result['targets'] as $key => $t) {
        $status = $t['pass'] === null ? 'INFO' : ($t['pass'] ? 'PASS' : 'FAIL');
        $actual = is_scalar($t['actual']) || $t['actual'] === null
            ? var_export($t['actual'], true)
            : json_encode($t['actual'], JSON_UNESCAPED_SLASHES);
        $lines[] = sprintf('[%s] %s', $status, $key);
        $lines[] = '       target: ' . $t['target'];
        $lines[] = '       actual: ' . $actual;
    }

    if ($name === 'honest') {
        $lines[] = '';
        $lines[] = '  per-profile first-Journeyman day (n | reach% | min/p25/median/p75/max):';
        foreach ($result['per_profile'] as $profile => $p) {
            $j = $p['journeyman'];
            $lines[] = sprintf(
                '    %-9s n=%d  reach=%5.1f%%  %s / %s / %s / %s / %s',
                $profile, $p['n'], $p['journeyman_reach_pct'],
                var_export($j['min'], true), var_export($j['p25'], true),
                var_export($j['median'], true), var_export($j['p75'], true),
                var_export($j['max'], true)
            );
        }
        $o = $result['overall'];
        $lines[] = sprintf(
            '  veteran: promoted=%d  by day 120/180/365 = %d/%d/%d  earliest=%s',
            $o['veteran_promoted'], $o['veteran_by_day_120'], $o['veteran_by_day_180'],
            $o['veteran_by_day_365'], var_export($o['veteran']['min'], true)
        );
    }

    if ($name === 'quorum') {
        $lines[] = '';
        $lines[] = '  reachability (day | pool | top10 weight | P(quorum) @30% | @50%):';
        foreach ($result['table'] as $row) {
            $lines[] = sprintf(
                '    day %-3d  E=%-3d  top10=%-7s  p30=%.3f  p50=%.3f',
                $row['day'], $row['pool_size'],
                $row['top10_weight'] === null ? 'n/a' : sprintf('%.2f', $row['top10_weight']),
                $row['quorum_probability']['p_30'], $row['quorum_probability']['p_50']
            );
        }
    }

    if ($name === 'helping') {
        $lines[] = '';
        $lines[] = '  day-400 helping (n | owners | %>=7.5 | %>=15 | min/median/max):';
        foreach ($result['helping_distribution'] as $profile => $d) {
            $lines[] = sprintf(
                '    %-9s n=%d  own=%-3d  >=7.5=%5.1f%%  >=15=%5.1f%%  %s / %s / %s',
                $profile, $d['n'], $d['owners_n'], $d['pct_ge_7_5'], $d['pct_ge_15'],
                var_export($d['helping_min'], true), var_export($d['helping_median'], true),
                var_export($d['helping_max'], true)
            );
        }
        $lines[] = '';
        $lines[] = '  contribution diversity-5 (owner-gated):';
        foreach ($result['contribution_diversity']['per_profile'] as $profile => $d) {
            $lines[] = sprintf(
                '    %-9s types=%s  %%>=5=%5.1f%%  owners %%>=5=%5.1f%%',
                $profile,
                json_encode($d['types_histogram'], JSON_UNESCAPED_SLASHES),
                $d['pct_ge_5'], $d['owners_pct_ge_5']
            );
        }
        $cd = $result['contribution_diversity'];
        $lines[] = sprintf(
            '    owners>=5=%d/%d  non-owners>=5=%d/%d  any_non_owner_reached_5=%s',
            $cd['owners_ge5'], $cd['owners_total'], $cd['non_owners_ge5'], $cd['non_owners_total'],
            $cd['any_non_owner_reached_5'] ? 'true' : 'false'
        );
        $lines[] = '';
        $lines[] = '  anti-farm helping(distinct markers x 50 marks): '
            . json_encode($result['anti_farm']['helping_by_distinct_markers'], JSON_UNESCAPED_SLASHES);
        $lines[] = sprintf(
            '    9 markers=%.2f (<25 => cannot fill)  10 markers=%.2f  3x1000 marks=%.2f (cap bites)  owner 5mk+steward=%.2f',
            $result['anti_farm']['nonowner_9_markers_helping'],
            $result['anti_farm']['nonowner_10_markers_helping'],
            $result['anti_farm']['small_pool_volume_helping_3x1000'],
            $result['anti_farm']['owner_5_markers_plus_stewardship']
        );
        $lines[] = '';
        $lines[] = '  promotion  SHIPPED vs RESTORE (journeyman median | veteran promoted | earliest):';
        $ps = $result['promotion']['shipped'];
        $pr = $result['promotion']['restore'];
        $lines[] = sprintf(
            '    SHIPPED  J-median=%s  V-promoted=%d  V-earliest=%s',
            var_export($ps['journeyman_median'], true), $ps['veteran_promoted'],
            var_export($ps['veteran_earliest'], true)
        );
        $lines[] = sprintf(
            '    RESTORE  J-median=%s  V-promoted=%d  V-earliest=%s  (J-median shift %s d)',
            var_export($pr['journeyman_median'], true), $pr['veteran_promoted'],
            var_export($pr['veteran_earliest'], true),
            var_export($result['promotion']['journeyman_median_shift_days'], true)
        );
        $lines[] = '    per-profile J-reach% / V-reach% (SHIPPED -> RESTORE):';
        foreach ($ps['per_profile'] as $profile => $sp) {
            $rp = $pr['per_profile'][$profile];
            $lines[] = sprintf(
                '      %-9s J %5.1f%%->%5.1f%%   V %5.1f%%->%5.1f%%',
                $profile, $sp['journeyman_reach_pct'], $rp['journeyman_reach_pct'],
                $sp['veteran_reach_pct'], $rp['veteran_reach_pct']
            );
        }
    }
}

$lines[] = '';
file_put_contents($resultsDir . '/summary.txt', implode("\n", $lines));
fwrite(STDOUT, "Summary written to results/summary.txt\n");
