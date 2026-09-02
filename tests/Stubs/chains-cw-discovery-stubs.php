<?php

/**
 * Stubs for the Chains ▸ NFT Discovery ▸ CosmWasm/CW-721 route tests.
 *
 * Layers on the Batch 1 admin-action stubs (wp_die / wp_safe_redirect /
 * check_admin_referer / Logger / AuditLogger shims) and adds only what the
 * discovery route touches: the chain registry's opt-in column, the worker's
 * opt-in reader, and the health snapshot the sub-tab renders from.
 *
 * Deliberately SEPARATE from cosmwasm-discovery-stubs.php. That file is
 * shared with the CosmWasm worker/retry work and carries a much larger fake
 * world; adding the admin request-boundary shims there would widen a file
 * two workstreams edit. `sanitize_key` lives HERE for the same reason — the
 * notice builder is the only thing that needs it, and it is exercised from
 * this route path.
 */

declare(strict_types=1);

namespace {

    if (!function_exists('sanitize_key')) {
        function sanitize_key(string $key): string
        {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
        }
    }

    if (!function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . ltrim($path, '/');
        }
    }

    if (!function_exists('esc_url')) {
        function esc_url(string $url): string
        {
            return htmlspecialchars($url, ENT_QUOTES);
        }
    }

    if (!function_exists('esc_attr')) {
        function esc_attr(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES);
        }
    }

    if (!function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES);
        }
    }

    if (!function_exists('wp_nonce_field')) {
        /** Emits the nonce ACTION verbatim so a DOM test can assert its scope. */
        function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
        {
            $html = '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES)
                . '" data-nonce-action="' . htmlspecialchars($action, ENT_QUOTES) . '" value="nonce">';
            if ($echo) {
                echo $html;
            }
            return $html;
        }
    }

    if (!function_exists('wp_create_nonce')) {
        /**
         * Returns a token that ENCODES its own action.
         *
         * The Add Collection form mints one nonce per selectable chain and
         * hangs it on the `<option>`, so the property a render test has to be
         * able to check is that each option carries a nonce scoped to ITS OWN
         * chain. A stub returning a constant would make a form that shipped
         * one shared nonce for every chain look identical to a correct one.
         */
        function wp_create_nonce(string $action = '-1'): string
        {
            return 'nonce:' . $action;
        }
    }

    if (!function_exists('wp_json_encode')) {
        /**
         * The confirmation text goes through
         * AdminActionSupport::confirmLiteral(), which JSON-encodes it so a
         * quote or newline cannot break out of the onclick attribute.
         *
         * @param mixed $data
         * @return string|false
         */
        function wp_json_encode($data, int $options = 0, int $depth = 512)
        {
            return json_encode($data, $options, $depth);
        }
    }

    if (!function_exists('number_format_i18n')) {
        function number_format_i18n(int $n): string
        {
            return (string) $n;
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(ChainRepository::class, false)) {
        /**
         * The authoritative chain registry.
         *
         * `$rows` holds the FULL row so a test can prove the discovery write
         * is surgical, and `$discoveryWrites` records every call so "at most
         * one write" is a count, not a hope.
         */
        final class ChainRepository
        {
            /** @var array<int, object> */
            public static array $rows = [];

            /** @var list<array{chain_id: int, enable: bool}> */
            public static array $discoveryWrites = [];

            public static bool $writeResult = true;
            public static ?\Throwable $writeThrows = null;

            /** Force the read-back to disagree, or to be unreadable. */
            public static ?bool $readBackOverride = null;
            public static bool $readBackNull = false;

            public static int $cacheBusts = 0;

            public static function getById(int $id): ?object
            {
                if (self::$readBackNull && self::$discoveryWrites !== []) {
                    return null;
                }

                return self::$rows[$id] ?? null;
            }

            /** @return list<object> */
            public static function getActive(): array
            {
                return array_values(self::$rows);
            }

            /**
             * Admin-only projection — includes deactivated chains.
             *
             * The NFT discovery control plane reads through this rather than
             * getActive(), because a deactivated chain that still carries
             * capability state is exactly what an operator comes to the page
             * to look at.
             *
             * @return list<object>
             */
            public static function getAll(): array
            {
                return array_values(self::$rows);
            }

            public static function setCosmwasmNftDiscoveryEnabled(int $chainId, bool $enable): bool
            {
                self::$discoveryWrites[] = ['chain_id' => $chainId, 'enable' => $enable];

                if (self::$writeThrows !== null) {
                    throw self::$writeThrows;
                }

                if (!self::$writeResult) {
                    return false;
                }

                self::$cacheBusts++;

                if (isset(self::$rows[$chainId])) {
                    $stored = self::$readBackOverride ?? $enable;
                    self::$rows[$chainId]->cosmwasm_nft_discovery_enabled = $stored ? '1' : '0';
                }

                return true;
            }

            /**
             * A normally-configured, NFT-capable chain.
             *
             * ── WHY THE TWO CAPABILITY COLUMNS DEFAULT TO '1' HERE ──────
             * In PRODUCTION they are `DEFAULT 0`, and PR 3 ships no writer
             * for either — a stock install has every chain refused. That is
             * the intended fail-closed state and it is proven by the tests
             * that seed `false` explicitly.
             *
             * This helper is the OTHER fixture: "a chain an operator has
             * already granted". Defaulting it to capable keeps every test
             * that predates the capability gate exercising the behaviour it
             * was written to pin, instead of silently all landing on the
             * same refusal and asserting nothing about the code under test.
             *
             * Both are explicit parameters, so no test is capable by
             * accident.
             */
            public static function seed(
                int $id,
                string $slug = 'cosmos',
                bool $optedIn = false,
                bool $bccSupportsNft = true,
                bool $manualDiscovery = true
            ): void {
                self::$rows[$id] = (object) [
                    'id'                                  => $id,
                    'slug'                                => $slug,
                    'name'                                => ucfirst($slug),
                    'chain_type'                          => 'cosmos',
                    'is_active'                           => 1,
                    'rest_url'                            => 'https://' . $slug . '.example',
                    'description'                         => 'About ' . $slug . '.',
                    'icon_url'                            => 'https://cdn.example/' . $slug . '.png',
                    'color'                               => '#123456',
                    'cosmwasm_nft_discovery_enabled'      => $optedIn ? '1' : '0',
                    'bcc_supports_nft_collections'        => $bccSupportsNft ? '1' : '0',
                    'manual_collection_discovery_enabled' => $manualDiscovery ? '1' : '0',
                ];
            }

            /**
             * Drop a capability column entirely, as a pre-migration
             * projection would.
             *
             * `unset` and not `= null`: the production reader branches on
             * `array_key_exists`, because "the column is absent" and "the
             * column holds NULL" are different facts and only the first one
             * means "this install cannot say".
             */
            public static function dropColumn(int $id, string $column): void
            {
                if (isset(self::$rows[$id])) {
                    unset(self::$rows[$id]->{$column});
                }
            }

            public static function reset(): void
            {
                self::$rows             = [];
                self::$discoveryWrites  = [];
                self::$writeResult      = true;
                self::$writeThrows      = null;
                self::$readBackOverride = null;
                self::$readBackNull     = false;
                self::$cacheBusts       = 0;
            }
        }
    }

    /**
     * The driver-override store.
     *
     * ── IT RETURNS THE REAL VALUE OBJECT ────────────────────────────────
     * `ChainNftCapabilityOverrides` is a pure value object with no database
     * access, so it autoloads and is used verbatim. Faking it too would
     * mean the tests could not tell `loaded([])` — "read fine, no overrides,
     * registry defaults apply" — from `unavailable(...)` — "we know nothing"
     * — which is the single most load-bearing distinction in the model.
     *
     * Default is `loaded([])`: a readable store with no override rows, i.e.
     * what every install actually has, since PR 3 ships no writer for this
     * table either.
     */
    if (!class_exists(ChainNftCapabilityRepository::class, false)) {
        final class ChainNftCapabilityRepository
        {
            /** @var array<int, \BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides> */
            public static array $forChain = [];

            /** Reads observed, so a test can assert the store is read ONCE per render. */
            public static int $reads = 0;

            public static function getForChain(int $chainId): \BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides
            {
                self::$reads++;

                return self::$forChain[$chainId]
                    ?? \BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides::loaded([]);
            }

            /**
             * @param list<array{operation: string, driver_key: string, enabled: bool, priority: int}> $rows
             */
            public static function seedLoaded(int $chainId, array $rows = []): void
            {
                self::$forChain[$chainId] =
                    \BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides::loaded($rows);
            }

            /** REASON_READ_FAILED / REASON_OVERFLOW / REASON_MALFORMED / REASON_INVALID_CHAIN. */
            public static function seedUnavailable(int $chainId, string $reason): void
            {
                self::$forChain[$chainId] =
                    \BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides::unavailable($reason);
            }

            public static function reset(): void
            {
                self::$forChain = [];
                self::$reads    = 0;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Workers {

    if (!class_exists(CosmwasmDiscoveryWorker::class, false)) {
        final class CosmwasmDiscoveryWorker
        {
            /**
             * Reads the opt-in exactly as production does: `=== '1'` is the
             * only true, a missing column is null ("cannot store the
             * answer"), and everything else is false.
             */
            public static function discoveryOptInState(object $chain): ?bool
            {
                if (!property_exists($chain, 'cosmwasm_nft_discovery_enabled')) {
                    return null;
                }

                $raw = $chain->cosmwasm_nft_discovery_enabled;
                if ($raw === null) {
                    return null;
                }

                return (string) $raw === '1';
            }

            /** Recorded so a test can prove the route starts NO work. */
            public static int $passes = 0;

            public static function runBackfillForChain(int $chainId, ?object $budget = null): void
            {
                self::$passes++;
            }

            public static function reset(): void
            {
                self::$passes = 0;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {

    if (!class_exists(CosmwasmClassifier::class, false)) {
        /** Only the classification vocabulary the status row reads. */
        final class CosmwasmClassifier
        {
            public const CONFIRMED    = 'confirmed_cw721';
            public const PROBABLE     = 'probable_cw721';
            public const NOT_CW721    = 'not_cw721';
            public const INCONCLUSIVE = 'inconclusive';
            public const UNREACHABLE  = 'temporarily_unreachable';
        }
    }

    if (!class_exists(CosmwasmDiscoveryHealthSnapshot::class, false)) {
        /**
         * The single authoritative status source both surfaces read.
         *
         * `$summaryCalls` is what proves the sub-tab does not roll its own
         * eligibility: if the tab ever computed a verdict itself, it would
         * stop needing this.
         */
        final class CosmwasmDiscoveryHealthSnapshot
        {
            public const ELIGIBILITY_ELIGIBLE           = 'eligible';
            public const ELIGIBILITY_NOT_OPTED_IN       = 'not_opted_in';
            public const ELIGIBILITY_UNSUPPORTED        = 'unsupported';
            public const ELIGIBILITY_PAUSED             = 'paused';
            public const ELIGIBILITY_ALLOWLIST_EXCLUDED = 'allowlist_excluded';
            public const ELIGIBILITY_UNKNOWN            = 'unknown';

            public static int $summaryCalls = 0;

            /** @var list<array<string, mixed>> */
            public static array $chains = [];

            /** @return array<string, mixed> */
            public static function buildSummary(): array
            {
                self::$summaryCalls++;

                return ['chains' => self::$chains];
            }

            public static function eligibilityLabel(string $eligibility): string
            {
                $map = [
                    self::ELIGIBILITY_ELIGIBLE           => 'Eligible',
                    self::ELIGIBILITY_NOT_OPTED_IN       => 'Not opted in',
                    self::ELIGIBILITY_UNSUPPORTED        => 'No CosmWasm module',
                    self::ELIGIBILITY_PAUSED             => 'Paused',
                    self::ELIGIBILITY_ALLOWLIST_EXCLUDED => 'Outside canary allowlist',
                ];

                return $map[$eligibility] ?? 'Unknown';
            }

            /** @param list<string>|null $allowlist */
            public static function eligibilityReason(string $eligibility, ?array $allowlist): string
            {
                return 'reason: ' . $eligibility;
            }

            // ── VC-B3a: presentation helpers the status row reuses ───────
            //
            // Deliberately faked as PASS-THROUGH/IDENTITY shapes. The point
            // of the parity tests is that the renderer prints what the
            // snapshot supplies; if these fakes invented formatting, a
            // renderer that recomputed a label could still look correct.

            public static function stateLabel(string $state): string
            {
                return 'state:' . $state;
            }

            public static function progressLabel(
                string $state,
                int $maxCodeId,
                bool $cursorOpen,
                int $familiesKnown
            ): string {
                return 'progress:' . $state;
            }

            public static function formatDuration(int $seconds): string
            {
                return $seconds . 's';
            }

            /**
             * Faithful to the real contract: "CW-721" is CONFIRMED plus
             * PROBABLE only. Summing every bucket would fold the settled
             * non-NFT count into the NFT total, and a test built on that
             * would assert the wrong number.
             *
             * @param array<string, int> $byClassification
             */
            public static function cw721Total(array $byClassification): int
            {
                return (int) ($byClassification[CosmwasmClassifier::CONFIRMED] ?? 0)
                    + (int) ($byClassification[CosmwasmClassifier::PROBABLE] ?? 0);
            }

            /**
             * @param array<string, mixed> $overrides
             * @return array<string, mixed>
             */
            public static function chainRow(int $chainId, string $slug, bool $optedIn, array $overrides = []): array
            {
                return array_merge([
                    'chain_id'           => $chainId,
                    'slug'               => $slug,
                    'name'               => ucfirst($slug),
                    'discovery_opted_in' => $optedIn,
                    'unsupported'        => false,
                    'paused'             => false,
                    'eligibility'        => $optedIn ? self::ELIGIBILITY_ELIGIBLE : self::ELIGIBILITY_NOT_OPTED_IN,
                    'eligibility_reason' => $optedIn ? 'Opted in and scannable.' : 'No operator has opted this chain in.',
                ], $overrides);
            }

            public static function reset(): void
            {
                self::$summaryCalls = 0;
                self::$chains       = [];
            }
        }
    }
}

namespace {

    // Loaded LAST on purpose. The Batch 1 action stubs define their own
    // narrower ChainRepository; requiring them first would let it win the
    // class_exists guard and shadow the richer fake above, which is how
    // the VC-A stub family broke once already. Everything this file
    // defines is therefore declared BEFORE this require, and the action
    // stubs fill in only what is still missing (wp_die, wp_safe_redirect,
    // check_admin_referer, Logger, AuditLogger, BccAdminTestState).
    require_once __DIR__ . '/onchain-admin-action-stubs.php';
}
