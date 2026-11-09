<?php
/**
 * Collaborators for {@see \BCC\Trust\Onchain\Admin\SettingsPage::render_das_warnings()}.
 *
 * ── WHAT IS FAKED, AND WHAT DELIBERATELY IS NOT ─────────────────────────
 * FAKED — only what would otherwise reach a database or WordPress:
 *
 *   ChainRepository              the active-chain list the panel iterates
 *   get_option                   via nft-discovery-matrix-stubs.php, which
 *                                installs the shim in BOTH the reader's and
 *                                the writer's namespace over ONE store
 *   esc_html / human_time_diff   the two output helpers the panel calls
 *
 * REAL — everything that decides anything:
 *
 *   NftProviderReadiness   the attribution predicate this panel must consult
 *   NftDriverRegistry      which drivers serve a chain at all
 *   EndpointDescriptor     description vs identity
 *   SolanaEndpoints        which endpoint is in use RIGHT NOW
 *   AdminActionSupport     the operator-safe excerpt
 *   SettingsPage           the renderer itself, invoked by reflection
 *
 * That split is the entire point. #272 was a SECOND COPY of the attribution
 * rule living inside the renderer, and a suite that faked readiness would be
 * asserting against its own fixture — it could not tell the two copies
 * apart, which is exactly how the divergence survived.
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

namespace {
    // The reader/writer `get_option` shims and the shared option store.
    require_once __DIR__ . '/nft-discovery-matrix-stubs.php';

    if (!function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists('esc_attr')) {
        function esc_attr(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    /**
     * Deterministic on purpose: the panel only decides whether to print the
     * "first detected" line at all, and a real elapsed-time string would make
     * the assertion depend on the clock.
     */
    if (!function_exists('human_time_diff')) {
        function human_time_diff(int $from, int $to = 0): string
        {
            return '2 hours';
        }
    }
}

namespace BCC\Trust\Onchain\Admin {

    use BCC\Trust\Onchain\Support\NftCapabilityOptionState;

    /**
     * ⚠⚠ THE PANEL'S OWN NAMESPACE IS SHIMMED ON PURPOSE, even though the
     * fixed renderer never calls `get_option()`.
     *
     * Without this, restoring the old option-reading renderer makes the suite
     * die on an unresolvable global `get_option()` — an ERROR, which proves
     * only that the harness is incomplete. With it, the old code RUNS, reads
     * the mark it used to read, and is killed by the OBSERVABLE: it prints a
     * notice for an endpoint that is no longer configured.
     *
     * A mutation control that cannot execute the mutant is not a control.
     */
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

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(__NAMESPACE__ . '\\ChainRepository', false)) {
        /**
         * The active-chain list. Rows carry every field the real readiness
         * derivation reads — `chain_type` and `rpc_url` above all, because
         * those are what decide whether a stored mark still attaches.
         */
        final class ChainRepository
        {
            /** @var list<object> */
            public static array $active = [];

            public static function reset(): void
            {
                self::$active = [];
            }

            /** @param list<object> $rows */
            public static function seed(array $rows): void
            {
                self::$active = $rows;
            }

            /** @return list<object> */
            public static function getActive(?string $chainType = null): array
            {
                if ($chainType === null) {
                    return self::$active;
                }

                return array_values(array_filter(
                    self::$active,
                    static fn(object $c): bool => (string) ($c->chain_type ?? '') === $chainType
                ));
            }
        }
    }
}
