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

require_once __DIR__ . '/../vendor/autoload.php';

// Namespace-scoped WP shims for CronService's schedule self-healing, plus the
// bcc-core collaborators it calls by fully-qualified name.
//
// Loaded here rather than from the test file on purpose: PHPUnit loads every
// test into one process, so a shim declared in a test file only wins if that
// file loads first. `apply_filters` in BCC\Trust\Core\Services and
// `AdvisoryLock` are each already declared by several suites, and alphabetical
// load order is not a contract. The defaults match what those suites already
// assume, so this makes the harness deterministic without changing behaviour.
require_once __DIR__ . '/Stubs/cron-schedule-stubs.php';

// Global `wp_json_encode`, declared here for the same load-order reason as
// the shims above — and with WordPress's real signature.
//
// Three Stubs/ files already declare a guarded mirror, but one of them is
// `wp_json_encode($data): string`, which coerces a json_encode() FAILURE into
// the empty string. Any suite that happened to load that file first would make
// an encoding failure look like a successful encode of "" — which is precisely
// the distinction AuditMeta exists to preserve. Declaring the faithful version
// first makes all three guarded mirrors no-ops and removes the load-order
// dependency, exactly as the cron-schedule shims above do.
//
// Faithful to core in the way that matters here: json_encode's `false` on an
// unencodable value is returned as `false`, not swallowed. Core additionally
// retries once after repairing invalid UTF-8; production callers therefore see
// FEWER failures than this shim, never more, so a test that passes here cannot
// be hiding a production failure.
if (!function_exists('wp_json_encode')) {
    /**
     * @param  mixed $data
     * @return string|false
     */
    function wp_json_encode($data, int $options = 0, int $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}
