<?php
/**
 * Envelope Recognition — Verification Harness
 *
 * Locks the four envelope shapes that BCC\Trust\Core\REST\Envelope's
 * `isAlreadyEnveloped()` MUST recognize, plus the must-NOT cases that
 * guard against double-wrapping or false positives.
 *
 * Coverage (per stabilization-plan-2026-05-13.md Phase α):
 *
 *   Canonical shapes:
 *     1. { data, _meta }                     → success envelope (RECOGNIZED)
 *     2. { error: { code, message, status }} → error envelope   (RECOGNIZED)
 *
 *   Legacy bcc-trust/v1 shape (the 2026-05-13 Phase α addition):
 *     3. { success: true, data }             → trust envelope   (RECOGNIZED)
 *
 *   Must NOT trigger recognition (would cause skipped-wrap regressions):
 *     4. Non-array input
 *     5. Empty array
 *     6. { success: false, data }            (strict `=== true` match)
 *     7. { success: 1, data }                (boolean type match)
 *     8. { success: true, data, _meta }      (canonical wins; legacy rule skips)
 *     9. WP_Error raw shape { code, message, data: { status } } — no `error` key
 *    10. Arbitrary payload { some: "value" }
 *
 * Run:
 *   php wp-content/plugins/bcc-trust/tests/EnvelopeRecognitionTest.php
 *
 * Standalone CLI script — mirrors UserViewServiceFlagsTest.php's
 * pattern. PHPUnit is NOT a bcc-trust dev dependency. Hand-rolled
 * assertions. Uses Reflection to exercise the private static
 * isAlreadyEnveloped() directly, so this test is a pure-function
 * lock independent of WP_REST_Response runtime.
 *
 * If a future contributor loosens the legacy-shape recognition rule,
 * this test catches the regression at CI time before the silent
 * double-wrap returns to production.
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

// Web-exposure guard — refuse to run via a web SAPI even if the dir
// becomes web-reachable. Mirrors UserViewServiceFlagsTest.php.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// Envelope.php requires ABSPATH to be defined (web-context exit guard
// at line 5). Define it so the class file loads cleanly under raw CLI.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once __DIR__ . '/../app/Domain/Core/REST/Envelope.php';

// ─────────────────────────────────────────────────────────────────────
// Harness
// ─────────────────────────────────────────────────────────────────────

$failures = 0;
$passed   = 0;

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function assert_eq(string $label, $actual, $expected): void
{
    global $failures, $passed;
    if ($actual === $expected) {
        $passed++;
        echo "  ✓ $label" . PHP_EOL;
    } else {
        $failures++;
        echo "  ✗ $label" . PHP_EOL;
        echo "      expected: " . var_export($expected, true) . PHP_EOL;
        echo "      actual:   " . var_export($actual, true) . PHP_EOL;
    }
}

// Reflection access to the private method. The method is private by
// design (internal contract), but it is a pure function on its input
// and locking its recognition behavior is exactly what this test does.
$reflection = new ReflectionClass(\BCC\Trust\Core\REST\Envelope::class);
$method     = $reflection->getMethod('isAlreadyEnveloped');
$method->setAccessible(true);

/**
 * @param mixed $data
 */
function is_enveloped($data): bool
{
    global $method;
    return (bool) $method->invoke(null, $data);
}

// ─────────────────────────────────────────────────────────────────────
// 1. Canonical success envelope: { data, _meta }
// ─────────────────────────────────────────────────────────────────────
echo PHP_EOL . "Canonical success envelope { data, _meta }:" . PHP_EOL;

assert_eq(
    'minimal canonical success',
    is_enveloped(['data' => ['ok' => true], '_meta' => ['request_id' => 'abc']]),
    true
);
assert_eq(
    'canonical with null data',
    is_enveloped(['data' => null, '_meta' => []]),
    true
);
assert_eq(
    'canonical with extra siblings (ignored)',
    is_enveloped(['data' => [], '_meta' => [], 'extra' => 'irrelevant']),
    true
);

// ─────────────────────────────────────────────────────────────────────
// 2. Canonical error envelope: { error: { code, message, status, ... } }
// ─────────────────────────────────────────────────────────────────────
echo PHP_EOL . "Canonical error envelope { error: {code, message, status} }:" . PHP_EOL;

assert_eq(
    'minimal canonical error',
    is_enveloped([
        'error' => ['code' => 'bcc_unauthorized', 'message' => 'Sign in.', 'status' => 401],
    ]),
    true
);
assert_eq(
    'canonical error with data payload',
    is_enveloped([
        'error' => [
            'code'    => 'bcc_invalid_request',
            'message' => 'Bad input.',
            'status'  => 422,
            'data'    => ['field' => 'email'],
        ],
    ]),
    true
);

// ─────────────────────────────────────────────────────────────────────
// 3. Legacy bcc-trust/v1 success envelope: { success: true, data }
//    (the 2026-05-13 Phase α addition)
// ─────────────────────────────────────────────────────────────────────
echo PHP_EOL . "Legacy trust envelope { success: true, data } (Phase α addition):" . PHP_EOL;

assert_eq(
    'minimal legacy trust success',
    is_enveloped(['success' => true, 'data' => ['auth_url' => 'https://x.com/oauth']]),
    true
);
assert_eq(
    'legacy trust success with null data',
    is_enveloped(['success' => true, 'data' => null]),
    true
);
assert_eq(
    'legacy trust success with complex data',
    is_enveloped([
        'success' => true,
        'data'    => [
            'connected' => true,
            'handle'    => '@example',
            'verified'  => false,
        ],
    ]),
    true
);

// ─────────────────────────────────────────────────────────────────────
// 4. Must NOT trigger recognition — false-positive guards
// ─────────────────────────────────────────────────────────────────────
echo PHP_EOL . "Must-NOT-recognize cases (false-positive guards):" . PHP_EOL;

assert_eq(
    'non-array input (string)',
    is_enveloped('hello'),
    false
);
assert_eq(
    'non-array input (null)',
    is_enveloped(null),
    false
);
assert_eq(
    'non-array input (int)',
    is_enveloped(42),
    false
);
assert_eq(
    'empty array',
    is_enveloped([]),
    false
);

// Strict `=== true` match: false-success must not be recognized
assert_eq(
    'success=false, data present (not recognized)',
    is_enveloped(['success' => false, 'data' => ['anything']]),
    false
);

// Strict boolean type match: integer 1 is NOT === true
assert_eq(
    'success=1 (integer not bool), data present (not recognized)',
    is_enveloped(['success' => 1, 'data' => ['anything']]),
    false
);

// Strict boolean type match: string "true" is NOT === true
assert_eq(
    'success="true" (string not bool), data present (not recognized)',
    is_enveloped(['success' => 'true', 'data' => ['anything']]),
    false
);

// Canonical wins: { success: true, data, _meta } hits the FIRST rule
// (canonical success), not the legacy rule. The legacy rule requires
// `!_meta` so it cannot match. End result: still recognized — the test
// confirms that BOTH rules can fire on overlapping inputs without
// producing different answers.
assert_eq(
    'canonical AND legacy markers present (canonical wins)',
    is_enveloped(['success' => true, 'data' => [], '_meta' => []]),
    true
);

// WP_Error raw shape { code, message, data: { status } } — no `error`
// key, so the canonical-error rule doesn't fire. The wrap() flow
// separately reshapes this via looksLikeWpError(), but isAlreadyEnveloped
// itself must return false so wrap() takes that path.
assert_eq(
    'WP_Error raw shape (not recognized; wrap() handles separately)',
    is_enveloped([
        'code'    => 'x_error',
        'message' => 'Boom.',
        'data'    => ['status' => 500],
    ]),
    false
);

// Arbitrary handler payload — must be wrapped, not passed through.
assert_eq(
    'arbitrary handler payload (gets wrapped by Envelope)',
    is_enveloped(['some_field' => 'value', 'list' => [1, 2, 3]]),
    false
);

// Edge: a handler that emits `{success: true}` WITHOUT `data` is NOT
// recognized as a legacy envelope (no `data` sibling). It gets wrapped
// canonically. This is correct — handlers must explicitly emit `data`
// to opt into the legacy shape.
assert_eq(
    'success=true without data sibling (not recognized; gets wrapped)',
    is_enveloped(['success' => true, 'message' => 'ok']),
    false
);

// Edge: `{data: x}` alone without `_meta` and without `success` is NOT
// recognized either (would otherwise let a handler emit `{data: 5}`
// and accidentally skip wrapping).
assert_eq(
    'data alone without _meta or success (not recognized; gets wrapped)',
    is_enveloped(['data' => [1, 2, 3]]),
    false
);

// ─────────────────────────────────────────────────────────────────────
// Result
// ─────────────────────────────────────────────────────────────────────
echo PHP_EOL;
echo str_repeat('─', 60) . PHP_EOL;
$total = $passed + $failures;
if ($failures === 0) {
    echo "PASS: $passed/$total assertions" . PHP_EOL;
    exit(0);
}
echo "FAIL: $failures/$total assertions failed" . PHP_EOL;
exit(1);
