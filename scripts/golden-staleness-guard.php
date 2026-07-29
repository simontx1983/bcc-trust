<?php
/**
 * Golden-fixture staleness guard — the CI half of the golden-master net.
 *
 * WHY THIS EXISTS
 * ---------------
 * `scripts/verify-golden.sh` is the real characterization net, but it needs a
 * LIVE WordPress site with a seeded database. CI has neither, which is why the
 * script was never wired into a workflow — and because nothing ran it, drift
 * accumulated silently for months: `cards.json` still carried
 * `foreman_insignia` (removed in contract v1.36) and `card_tier` / `tier_label`
 * (removed in v1.57), while the manifest still pinned `/locals`, a route the
 * v1.55 Local→Hall rename had deleted. By the time anyone ran it, it failed for
 * reasons unrelated to their change, so they learned to ignore it.
 *
 * This guard catches that ENTIRE class of rot WITHOUT a live site, so it can
 * run on every push. It does not replace verify-golden.sh — it cannot detect a
 * VALUE changing — but it does prove the fixtures still describe endpoints and
 * fields that exist.
 *
 * CHECKS
 * ------
 *  1. Manifest ↔ fixture parity. Every manifest entry has a fixture and every
 *     fixture has an entry. Catches an orphaned fixture for a dropped entry
 *     (exactly what tests/golden/locals.json became).
 *  2. Manifest routes still exist. The first path segment of each entry must
 *     appear in a register_rest_route() call. Segment-level rather than exact,
 *     because manifest paths are concrete instances of parameterised routes
 *     (`/users/phillipcosmos` → `/users/(?P<handle>…)`). Would have failed the
 *     day `/locals` was retired.
 *  3. Every fixture field is still emitted by PHP. Each JSON object key must
 *     appear as a single- or double-quoted string literal somewhere in the
 *     plugin sources. A key no PHP file mentions cannot be produced by any
 *     response, so the fixture pinning it is provably stale. Verified
 *     zero-false-positive across all 243 keys in the current fixture set;
 *     `foreman_insignia`, `card_tier` and `tier_label` all have zero PHP
 *     literals and would each have failed this check.
 *
 * Runs against the checked-in tree only. No DB, no HTTP, no WordPress.
 *
 * Exit: 0 = clean · 1 = stale fixture/manifest · 2 = setup error.
 *
 * @package BCC_Trust
 */

declare(strict_types=1);

$root       = dirname(__DIR__);
$manifest   = $root . '/scripts/golden/manifest.txt';
$goldenDir  = $root . '/tests/golden';

if (!is_file($manifest)) {
    fwrite(STDERR, "[golden-guard] missing manifest: {$manifest}\n");
    exit(2);
}
if (!is_dir($goldenDir)) {
    fwrite(STDERR, "[golden-guard] missing fixture dir: {$goldenDir}\n");
    exit(2);
}

// ── Parse the manifest ──────────────────────────────────────────────────────

/** @var array<string, string> $entries name => path */
$entries = [];
foreach (file($manifest, FILE_IGNORE_NEW_LINES) as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || $trimmed[0] === '#') {
        continue;
    }
    $parts = preg_split('/\s+/', $trimmed);
    if (count($parts) < 2) {
        continue;
    }
    $entries[$parts[0]] = $parts[1];
}

if ($entries === []) {
    fwrite(STDERR, "[golden-guard] manifest declares no entries\n");
    exit(2);
}

$failures = [];

// ── 1. Manifest ↔ fixture parity ────────────────────────────────────────────

$fixtures = [];
foreach ((array) glob($goldenDir . '/*.json') as $path) {
    $fixtures[basename((string) $path, '.json')] = (string) $path;
}

foreach ($entries as $name => $path) {
    if (!isset($fixtures[$name])) {
        $failures[] = "manifest entry '{$name}' ({$path}) has no fixture at tests/golden/{$name}.json";
    }
}
foreach ($fixtures as $name => $_path) {
    if (!isset($entries[$name])) {
        $failures[] = "orphan fixture tests/golden/{$name}.json — no manifest entry declares it";
    }
}

// ── Collect plugin PHP source once ──────────────────────────────────────────

$sourceDirs = array_values(array_filter([
    $root . '/app',
    $root . '/includes',
    dirname($root) . '/bcc-core/src',
    dirname($root) . '/bcc-search',
], 'is_dir'));

$blob = '';
$phpFiles = 0;
foreach ($sourceDirs as $dir) {
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static function ($current): bool {
                $name = $current->getFilename();
                return !in_array($name, ['vendor', 'node_modules', 'tests'], true);
            }
        )
    );
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $blob .= file_get_contents($file->getPathname()) . "\n";
            $phpFiles++;
        }
    }
}

if ($phpFiles === 0) {
    fwrite(STDERR, "[golden-guard] scanned no PHP files — wrong working directory?\n");
    exit(2);
}

// ── 2. Manifest routes still registered ─────────────────────────────────────

foreach ($entries as $name => $path) {
    $segment = strtok(ltrim($path, '/'), '/');
    if ($segment === false || $segment === '') {
        continue;
    }
    // The segment must appear inside a route literal, not merely anywhere in
    // the source — 'ranks' shows up in plenty of unrelated prose.
    $found = preg_match(
        '#register_rest_route\s*\((?:[^;]*?)[\'"]/?' . preg_quote($segment, '#') . '[\'"/]#s',
        $blob
    ) === 1;

    if (!$found) {
        // Fall back to a route-path constant referencing the segment, which is
        // how most endpoints in this plugin declare themselves.
        $found = preg_match(
            '#ROUTE_PATH\s*=\s*[\'"]/?' . preg_quote($segment, '#') . '[\'"/]#',
            $blob
        ) === 1;
    }

    if (!$found) {
        $failures[] = "manifest entry '{$name}' pins {$path}, but no register_rest_route()"
            . " or ROUTE_PATH declares a '/{$segment}' route — the endpoint looks retired";
    }
}

// ── 3. Every fixture field is still emitted by PHP ──────────────────────────

/** @param mixed $node */
function bcc_collect_keys($node, array &$out): void
{
    if (is_array($node)) {
        foreach ($node as $k => $v) {
            if (is_string($k)) {
                $out[$k] = true;
            }
            bcc_collect_keys($v, $out);
        }
    }
}

$totalKeys = 0;
foreach ($fixtures as $name => $path) {
    if (!isset($entries[$name])) {
        continue; // already reported as an orphan
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if ($decoded === null) {
        $failures[] = "fixture tests/golden/{$name}.json is not valid JSON";
        continue;
    }

    $keys = [];
    bcc_collect_keys($decoded, $keys);
    $totalKeys += count($keys);

    foreach (array_keys($keys) as $key) {
        // Normalisation placeholders are written by verify-golden.sh, not by PHP.
        if (str_contains((string) $key, '{{')) {
            continue;
        }
        if (str_contains($blob, "'" . $key . "'") || str_contains($blob, '"' . $key . '"')) {
            continue;
        }
        $failures[] = "fixture tests/golden/{$name}.json pins field '{$key}',"
            . ' but no PHP file contains that string literal — nothing can emit it,'
            . ' so the fixture is stale (re-run scripts/verify-golden.sh --capture)';
    }
}

// ── Report ──────────────────────────────────────────────────────────────────

printf(
    "[golden-guard] %d manifest entr%s · %d fixture%s · %d field%s · %d PHP file%s scanned\n",
    count($entries),
    count($entries) === 1 ? 'y' : 'ies',
    count($fixtures),
    count($fixtures) === 1 ? '' : 's',
    $totalKeys,
    $totalKeys === 1 ? '' : 's',
    $phpFiles,
    $phpFiles === 1 ? '' : 's'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAIL: golden fixtures/manifest are stale.\n\n");
    foreach (array_unique($failures) as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    fwrite(STDERR, "\n");
    exit(1);
}

echo "PASS: every manifest route exists and every pinned field is still emitted by PHP.\n";
exit(0);
