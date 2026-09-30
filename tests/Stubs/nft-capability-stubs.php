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

/**
 * ── ⚠⚠ THE WRITER LIVES IN A DIFFERENT NAMESPACE FROM THE READER ────────
 * `NftProviderReadiness` reads the DAS-unsupported mark from
 * `…\Onchain\Support`; `SolanaFetcher` WRITES it from `…\Onchain\Fetchers`.
 * Namespace-scoped shims are resolved per namespace, so a shim installed only
 * for the reader leaves the writer calling the real `update_option()` — which
 * does not exist in the unit suite.
 *
 * That is not a theoretical gap. Two mutations that made the writer persist a
 * credentialed URL survived the whole suite, because no test could run the
 * writer at all. Both namespaces are shimmed here, over ONE shared store, so the
 * round trip from writer to reader is exercised end to end.
 */
namespace BCC\Trust\Onchain\Fetchers {

    use BCC\Trust\Onchain\Support\NftCapabilityOptionState;

    if (!function_exists(__NAMESPACE__ . '\\update_option')) {
        /** @param mixed $value */
        function update_option(string $name, $value, $autoload = null): bool
        {
            if (!NftCapabilityOptionState::$active) {
                return \function_exists('update_option') ? \update_option($name, $value, $autoload) : false;
            }
            NftCapabilityOptionState::$options[$name] = $value;

            return true;
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
