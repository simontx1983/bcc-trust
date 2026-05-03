<?php
/**
 * Flag-Slug Composer — Verification Harness
 *
 * Exercises the V1-contract `flags: string[]` wiring across both the
 * UserViewService composer and the CardViewService routing layer:
 *
 *   1. UserViewService::composeFlagSlugs (pure decision function) —
 *      full 16-case truth table for the four V1 slugs:
 *      suspended · shadow_limited · hidden · under_review.
 *
 *   2. UserViewService::resolveFlags (integration) — exercises the WP
 *      hooks through in-process stubs for Permissions::is_not_suspended
 *      and get_user_meta. Verifies the ordering, dedupe-by-construction,
 *      and the userId guard.
 *
 *   3. CardViewService::buildFlags (routing) — verified via source
 *      inspection (Reflection on the class is heavyweight because the
 *      service has DI'd repositories). Confirms buildFlags delegates
 *      to UserViewService::resolveFlags and that both V1 call sites
 *      (page card + member card) route through it.
 *
 * Run:
 *   php wp-content/plugins/bcc-trust/tests/UserViewServiceFlagsTest.php
 *
 * Standalone CLI script — matches bcc-core/tests/CosmosVerifierTest.php.
 * PHPUnit is NOT a bcc-trust dev dependency, so this script uses
 * hand-rolled assertions and runs without a test framework.
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

namespace BCC\Core\Permissions {
    // Minimal stub for the Permissions facade UserViewService::resolveFlags
    // depends on. Tests mutate `$suspended` to drive each scenario.
    if (!class_exists(Permissions::class, false)) {
        final class Permissions
        {
            /** @var array<int, int> */
            public static array $suspended = [];

            public static function is_not_suspended(?int $userId = null, bool $allowAdminBypass = true): bool
            {
                unset($allowAdminBypass); // matches WP-side signature; not consulted in stub
                if ($userId === null) {
                    return true;
                }
                return !in_array($userId, self::$suspended, true);
            }
        }
    }
}

namespace {

    // Web-exposure guard — refuse to run via a web SAPI even if the dir
    // becomes web-reachable. Mirrors bcc-core/tests/CosmosVerifierTest.php.
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }

    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/../../../../');
    }

    // Minimal stub for get_user_meta. Tests mutate the global map below
    // before each scenario.
    if (!function_exists('get_user_meta')) {
        $GLOBALS['__bcc_test_user_meta'] = [];
        /**
         * @param int|string $userId
         */
        function get_user_meta($userId, string $key = '', bool $single = false) // phpcs:ignore
        {
            unset($single); // matches WP signature; not consulted in stub
            $map = $GLOBALS['__bcc_test_user_meta'];
            return $map[(int) $userId . ':' . $key] ?? '';
        }
    }

    require_once __DIR__ . '/../app/Domain/Core/Services/UserViewService.php';

    $passed = 0;
    $failed = 0;

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    function bcc_assert_equal($expected, $actual, string $label): void // phpcs:ignore
    {
        global $passed, $failed;
        if ($expected === $actual) {
            $passed++;
            echo "  PASS: {$label}\n";
        } else {
            $failed++;
            echo "  FAIL: {$label}\n";
            echo '    Expected: ' . var_export($expected, true) . "\n";
            echo '    Actual:   ' . var_export($actual, true) . "\n";
        }
    }

    // ── 1. composeFlagSlugs (pure) — 16-case truth table ────────────────
    echo "\n[composeFlagSlugs] truth-table coverage\n";

    bcc_assert_equal([], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(false, false, false, false), 'no flags → []');
    bcc_assert_equal(['suspended'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(true, false, false, false), 'suspended only');
    bcc_assert_equal(['shadow_limited'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(false, true, false, false), 'shadow_limited only');
    bcc_assert_equal(['hidden'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(false, false, true, false), 'hidden only');
    bcc_assert_equal(['under_review'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(false, false, false, true), 'under_review only');

    bcc_assert_equal(['suspended', 'shadow_limited'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(true, true, false, false), 'suspended + shadow_limited');
    bcc_assert_equal(['suspended', 'hidden'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(true, false, true, false), 'suspended + hidden');
    bcc_assert_equal(['suspended', 'under_review'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(true, false, false, true), 'suspended + under_review');
    bcc_assert_equal(['shadow_limited', 'hidden'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(false, true, true, false), 'shadow_limited + hidden');
    bcc_assert_equal(['shadow_limited', 'under_review'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(false, true, false, true), 'shadow_limited + under_review');
    bcc_assert_equal(['hidden', 'under_review'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(false, false, true, true), 'hidden + under_review');

    bcc_assert_equal(['suspended', 'shadow_limited', 'hidden'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(true, true, true, false), 'three flags (not under_review)');
    bcc_assert_equal(['suspended', 'shadow_limited', 'under_review'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(true, true, false, true), 'three flags (not hidden)');
    bcc_assert_equal(['suspended', 'hidden', 'under_review'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(true, false, true, true), 'three flags (not shadow_limited)');
    bcc_assert_equal(['shadow_limited', 'hidden', 'under_review'], \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(false, true, true, true), 'three flags (not suspended)');

    bcc_assert_equal(
        ['suspended', 'shadow_limited', 'hidden', 'under_review'],
        \BCC\Trust\Core\Services\UserViewService::composeFlagSlugs(true, true, true, true),
        'all four flags'
    );

    // ── 2. resolveFlags (integration via stubs) ─────────────────────────
    //
    // Each scenario uses a UNIQUE userId so the per-request static $memo
    // inside resolveFlags doesn't return a cached value from a prior
    // scenario's state. The memo behaviour is verified explicitly at
    // the end of this section.
    echo "\n[resolveFlags] integration via Permissions + get_user_meta stubs\n";

    \BCC\Core\Permissions\Permissions::$suspended = [];
    $GLOBALS['__bcc_test_user_meta'] = [];

    bcc_assert_equal([], \BCC\Trust\Core\Services\UserViewService::resolveFlags(42), 'no signals → []');
    bcc_assert_equal([], \BCC\Trust\Core\Services\UserViewService::resolveFlags(0), 'invalid userId (0) → []');
    bcc_assert_equal([], \BCC\Trust\Core\Services\UserViewService::resolveFlags(-1), 'invalid userId (-1) → []');

    \BCC\Core\Permissions\Permissions::$suspended = [43];
    bcc_assert_equal(['suspended'], \BCC\Trust\Core\Services\UserViewService::resolveFlags(43), 'suspended user');

    \BCC\Core\Permissions\Permissions::$suspended = [];
    $GLOBALS['__bcc_test_user_meta']['44:bcc_shadow_limited'] = '1';
    bcc_assert_equal(['shadow_limited'], \BCC\Trust\Core\Services\UserViewService::resolveFlags(44), 'shadow_limited via user_meta string "1"');

    $GLOBALS['__bcc_test_user_meta']['45:bcc_shadow_limited'] = '1';
    $GLOBALS['__bcc_test_user_meta']['45:bcc_hidden']         = 1;
    bcc_assert_equal(['shadow_limited', 'hidden'], \BCC\Trust\Core\Services\UserViewService::resolveFlags(45), 'shadow_limited + hidden');

    $GLOBALS['__bcc_test_user_meta']['46:bcc_shadow_limited'] = '1';
    $GLOBALS['__bcc_test_user_meta']['46:bcc_hidden']         = 1;
    $GLOBALS['__bcc_test_user_meta']['46:bcc_under_review']   = 'yes';
    \BCC\Core\Permissions\Permissions::$suspended = [46];
    bcc_assert_equal(
        ['suspended', 'shadow_limited', 'hidden', 'under_review'],
        \BCC\Trust\Core\Services\UserViewService::resolveFlags(46),
        'all four signals (suspended + 3 user_meta)'
    );

    // Verify falsy meta values do NOT add a flag.
    \BCC\Core\Permissions\Permissions::$suspended = [];
    $GLOBALS['__bcc_test_user_meta'] = [
        '99:bcc_shadow_limited' => '',
        '99:bcc_hidden'         => '0',
        '99:bcc_under_review'   => 0,
    ];
    bcc_assert_equal([], \BCC\Trust\Core\Services\UserViewService::resolveFlags(99), 'falsy meta values ("", "0", 0) → []');

    // ── filter_var-correctness regression tests ─────────────────────────
    // These would fail under a naive `(bool) get_user_meta()` cast —
    // the literal strings "false", "no", "off" all cast to true with
    // (bool) and would surface phantom flags in production.
    echo "\n[resolveFlags] filter_var truthiness — string 'false'/'no'/'off' must map to false\n";

    \BCC\Core\Permissions\Permissions::$suspended = [];
    $GLOBALS['__bcc_test_user_meta'] = [
        '100:bcc_shadow_limited' => 'false',
        '100:bcc_hidden'         => 'no',
        '100:bcc_under_review'   => 'off',
    ];
    bcc_assert_equal([], \BCC\Trust\Core\Services\UserViewService::resolveFlags(100), 'string "false"/"no"/"off" → []');

    $GLOBALS['__bcc_test_user_meta'] = [
        '101:bcc_shadow_limited' => 'true',
        '101:bcc_hidden'         => 'yes',
        '101:bcc_under_review'   => 'on',
    ];
    bcc_assert_equal(
        ['shadow_limited', 'hidden', 'under_review'],
        \BCC\Trust\Core\Services\UserViewService::resolveFlags(101),
        'string "true"/"yes"/"on" → all three flags'
    );

    // ── per-request memoisation ─────────────────────────────────────────
    echo "\n[resolveFlags] per-request memoisation\n";

    \BCC\Core\Permissions\Permissions::$suspended = [];
    $GLOBALS['__bcc_test_user_meta'] = ['200:bcc_hidden' => '1'];
    bcc_assert_equal(['hidden'], \BCC\Trust\Core\Services\UserViewService::resolveFlags(200), 'memo: first call resolves from state');

    // Mutate the underlying state — the memo MUST return the cached
    // result, not the new state. This locks the per-request semantics:
    // moderation actions taken mid-request don't bleed into the in-flight
    // response.
    \BCC\Core\Permissions\Permissions::$suspended = [200];
    $GLOBALS['__bcc_test_user_meta']['200:bcc_under_review'] = '1';
    bcc_assert_equal(
        ['hidden'],
        \BCC\Trust\Core\Services\UserViewService::resolveFlags(200),
        'memo: state mutation does NOT invalidate the in-request cache'
    );

    // A different userId must NOT hit the cached entry — verify the
    // memo is keyed by userId, not a global toggle. Set up 201 with
    // its OWN distinct state (suspended); a memo collision bug would
    // return the cached 200 entry (['hidden']) instead.
    \BCC\Core\Permissions\Permissions::$suspended[] = 201;
    bcc_assert_equal(
        ['suspended'],
        \BCC\Trust\Core\Services\UserViewService::resolveFlags(201),
        'memo: distinct userId resolves independently'
    );
    \BCC\Core\Permissions\Permissions::$suspended = [];

    // Invalid userIds (0, -1) MUST NOT pollute the memo. Calling with
    // 0, then with a real id, must still resolve from current state.
    $GLOBALS['__bcc_test_user_meta'] = ['202:bcc_under_review' => '1'];
    bcc_assert_equal([], \BCC\Trust\Core\Services\UserViewService::resolveFlags(0), 'memo: invalid userId guard short-circuits');
    bcc_assert_equal(['under_review'], \BCC\Trust\Core\Services\UserViewService::resolveFlags(202), 'memo: invalid-id call did not pollute cache');

    // ── 3. CardViewService::buildFlags routing (source inspection) ──────
    echo "\n[CardViewService::buildFlags] routes through UserViewService::resolveFlags\n";

    $cardServiceSrc = (string) file_get_contents(__DIR__ . '/../app/Domain/Core/Services/CardViewService.php');

    $hasBuildFlagsRouting = (bool) preg_match(
        '/private\s+static\s+function\s+buildFlags\s*\(\s*int\s+\$ownerId\s*\)\s*:\s*array\s*\{[^}]*UserViewService::resolveFlags\s*\(\s*\$ownerId\s*\)/s',
        $cardServiceSrc
    );
    bcc_assert_equal(true, $hasBuildFlagsRouting, 'buildFlags(int $ownerId) → UserViewService::resolveFlags($ownerId)');

    preg_match_all('/[\'"]flags[\'"]\s*=>\s*self::buildFlags\(/', $cardServiceSrc, $matches);
    bcc_assert_equal(2, count($matches[0]), 'two call sites route through self::buildFlags (page card + member card)');

    // No legacy `flags => []` stubs left behind in CardViewService values.
    $hasLegacyStub = (bool) preg_match("/['\"]flags['\"]\\s*=>\\s*\\[\\s*\\]/", $cardServiceSrc);
    bcc_assert_equal(false, $hasLegacyStub, "no remaining 'flags' => [] stubs in CardViewService");

    // PHPDoc typedef must declare slug shape, not the legacy object.
    bcc_assert_equal(
        true,
        str_contains($cardServiceSrc, '@phpstan-type Flag string'),
        'CardViewService @phpstan-type Flag = string (V1 contract slug)'
    );
    bcc_assert_equal(
        false,
        str_contains($cardServiceSrc, '@phpstan-type Flag array{code:'),
        'legacy @phpstan-type Flag array{code,...} removed'
    );

    // ── Summary ─────────────────────────────────────────────────────────
    echo "\n";
    echo "  {$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
