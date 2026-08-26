<?php
/**
 * Namespace-scoped WP shims for the NFT capability model tests.
 *
 * ── WHY NAMESPACE-SCOPED ────────────────────────────────────────────────
 * PHP resolves an UNQUALIFIED call to `get_option()` inside
 * `BCC\Trust\Onchain\Support` by looking for
 * `BCC\Trust\Onchain\Support\get_option` FIRST and only then falling back to
 * the global function. Declaring the shim in the production namespace is the
 * house "fake at the production FQN" pattern: the code under test is the
 * real code, unmodified, and only its collaborator is swapped.
 *
 * No WordPress core is loaded by the unit suite, so without this the
 * readiness derivation could not be exercised at all.
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support {

    /**
     * Option store for the readiness tests.
     *
     * `$active` gates the shim so a test file that loads this stub but wants
     * real behaviour is unaffected — the same discipline
     * tests/Stubs/cron-schedule-stubs.php uses for CronHealState.
     */
    final class NftCapabilityOptionState
    {
        public static bool $active = false;

        /** @var array<string, mixed> */
        public static array $options = [];

        public static function reset(): void
        {
            self::$active  = true;
            self::$options = [];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $name, $default = false)
        {
            if (!NftCapabilityOptionState::$active) {
                return \function_exists('get_option') ? \get_option($name, $default) : $default;
            }

            return NftCapabilityOptionState::$options[$name] ?? $default;
        }
    }
}
