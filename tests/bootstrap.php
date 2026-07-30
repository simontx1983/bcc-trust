<?php
/**
 * PHPUnit bootstrap for bcc-trust unit tests.
 *
 * Deliberately minimal:
 *   - Defines ABSPATH so production files' "if (!defined('ABSPATH')) exit;"
 *     guard lets them parse when required in-process.
 *   - Loads the REAL plugin configuration (includes/config.php) so tests
 *     exercise the same constants production runs with — stubbed values let
 *     threshold changes ship untested.
 *   - Registers Composer's autoloader so PHPUnit itself can be loaded.
 *
 * No WordPress core is required. DB-dependent code is tested via in-namespace
 * stubs defined in individual test files (see DisputeResolverTest).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// The real configuration modules (trust-weights, scoring, tiers, ranks,
// limits, stoke, attestation, ...) — pure defines, WP-free, the same file
// production requires from bcc-trust.php. Loaded before any test file so a
// per-test `require_once` of an individual module dedupes and guarded
// per-test mirrors become no-ops against the real values.
require_once dirname(__DIR__) . '/includes/config.php';

// Dispute constants live in bcc-trust.php (the plugin main file), which unit
// tests cannot require without booting the whole plugin — mirror them
// guarded. Production owner: bcc-trust.php. Remove these when the constants
// move into includes/config/.
if (!defined('BCC_DISPUTES_PANEL_SIZE')) {
    define('BCC_DISPUTES_PANEL_SIZE', 5);
}
if (!defined('BCC_DISPUTES_TTL_DAYS')) {
    define('BCC_DISPUTES_TTL_DAYS', 7);
}

require_once __DIR__ . '/../vendor/autoload.php';
