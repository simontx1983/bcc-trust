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

$scenarioFns = [
    'honest'  => 'calib_scenario_honest',
    'rings'   => 'calib_scenario_rings',
    'weights' => 'calib_scenario_weights',
    'quorum'  => 'calib_scenario_quorum',
    'decay'   => 'calib_scenario_decay',
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
}

$lines[] = '';
file_put_contents($resultsDir . '/summary.txt', implode("\n", $lines));
fwrite(STDOUT, "Summary written to results/summary.txt\n");
