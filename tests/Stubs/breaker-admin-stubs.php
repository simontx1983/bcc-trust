<?php

declare(strict_types=1);

/**
 * Doubles for rendering the REAL breaker admin tab against the REAL
 * {@see \BCC\Trust\Onchain\Support\OnchainCircuitBreaker}.
 *
 * ── WHY BOTH ARE REAL ───────────────────────────────────────────────────
 * The claim under test is "rendering this page acquires no advisory lock and
 * consumes no half-open probe". A faked breaker would make that claim about
 * the fake. So the breaker is production code and the ADVISORY LOCK is the
 * spy: `BccBreakerStore::$lockAttempts` records every acquire attempt, so
 * "no lock was requested" is OBSERVED, not inferred from a passing render.
 *
 * ⚠ THE LOCK SPY IS THE WHOLE POINT. `isOpen()` in the half-open window
 * calls `AdvisoryLock::acquire()`; `phase()` never does. An empty
 * `$lockAttempts` after a render is therefore proof the page took the
 * observing path, and it fails loudly if anyone routes it back through
 * `isOpen()`.
 *
 * @package BCC\Trust\Onchain\Tests\Stubs
 */

namespace {

// ⚠ INSIDE THE BRACED GLOBAL NAMESPACE. A file using braced namespaces may
// contain no code outside them — a bare require at the top is a fatal parse
// error, not a warning.
//
// Brings the real breaker's two-store surface (options + transient + object
// cache), the AdvisoryLock spy, the logger and the atomic counter repository.
require_once __DIR__ . '/breaker-real-stubs.php';

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = ''): string
    {
        return esc_html($text);
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return esc_html($text);
    }
}
if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}
}

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(__NAMESPACE__ . '\\ChainRepository', false)) {
        /**
         * The chain list the admin tab iterates. Deliberately minimal: the
         * page reads only `id` and `slug`.
         */
        final class ChainRepository
        {
            /** @var list<object> */
            public static array $active = [];

            public static function reset(): void
            {
                self::$active = [];
            }

            /** @param list<array{id: int, slug: string}> $rows */
            public static function seed(array $rows): void
            {
                self::$active = array_map(
                    static fn(array $r): object => (object) ['id' => $r['id'], 'slug' => $r['slug']],
                    $rows
                );
            }

            /** @return list<object> */
            public static function getActive(?string $chainType = null): array
            {
                return self::$active;
            }
        }
    }
}
