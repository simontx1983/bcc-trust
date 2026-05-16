<?php
/**
 * One-off verification harness for ValidatorPageMinter.
 *
 * Bootstraps WordPress, runs the minter in dry-run mode for one chain,
 * and prints the slugs that would be created. NOT part of the plugin
 * release — delete after the backfill ships.
 *
 * Usage (from the WP root):
 *   php wp-content/plugins/bcc-trust/scripts/test-validator-minter.php
 */

declare(strict_types=1);

// Locate WP root: 4 levels up from this file.
$wpRoot = realpath(__DIR__ . '/../../../..');
if ($wpRoot === false || !file_exists($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "ERROR: could not locate wp-load.php (expected at {$wpRoot}/wp-load.php)\n");
    exit(1);
}

define('WP_USE_THEMES', false);
require $wpRoot . '/wp-load.php';

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Services\ValidatorPageMinter;

$argChain = $argv[1] ?? 'akash';
$argMax   = isset($argv[2]) ? (int) $argv[2] : 10;

$chain = ChainRepository::getBySlug($argChain);
if ($chain === null) {
    fwrite(STDERR, "ERROR: unknown chain slug '{$argChain}'\n");
    exit(1);
}
$chainId = (int) $chain->id;

$eligible = ValidatorRepository::countMissingPlaceholderPage($chainId);
echo "Chain {$argChain} (id={$chainId}) — eligible={$eligible}, max={$argMax}\n";
echo str_repeat('─', 80) . "\n";

$rows = ValidatorRepository::findMissingPlaceholderPage($chainId, $argMax, 0);
foreach ($rows as $row) {
    $slug = ValidatorPageMinter::buildSlug(
        (string) ($row->moniker ?? ''),
        $row->chain_slug,
        $row->operator_address
    );
    printf(
        "id=%-4d moniker=%-32s addr=%s\n         → slug=/v/%s\n",
        $row->id,
        substr($row->moniker ?? '(none)', 0, 32),
        $row->operator_address,
        $slug
    );
}

echo "\nRunning mintMissing(chain={$chainId}, dryRun=true, max={$argMax})...\n";
$result = ValidatorPageMinter::mintMissing($chainId, true, $argMax);

echo "scanned    = {$result['scanned']}\n";
echo "would_mint = {$result['minted']}\n";
echo "skipped    = {$result['skipped']}\n";
echo "errors     = {$result['errors']}\n";
if (!empty($result['error_log'])) {
    echo "errors:\n";
    foreach ($result['error_log'] as $e) {
        echo "  • {$e}\n";
    }
}
