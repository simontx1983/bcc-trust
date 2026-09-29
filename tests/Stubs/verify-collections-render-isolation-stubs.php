<?php

declare(strict_types=1);

/**
 * Doubles for rendering the REAL `VerifyCollectionsPage::render_page()` with
 * every outbound and write surface RECORDED.
 *
 * ── WHAT IS REAL ────────────────────────────────────────────────────────
 * `VerifyCollectionsPage` itself (all of `render_page()` and its row
 * renderers), `CollectionDemandService`, `CollectionStateClassifier`,
 * `DiscoveryReadiness`, the provisioning value objects — and, crucially,
 * the transports themselves. Every transport is recorded ON PURPOSE: if
 * anything on the render path ever calls it again, it genuinely issues its
 * request through the recording ApiRetry below and the test sees it. A fake
 * client would have to be told to record, and a test that only proves the
 * fake was never called proves nothing about the production client.
 *
 * ── WHAT IS FAKED, AND WHY EACH FAKE RECORDS ────────────────────────────
 *   - Every HTTP transport a PHP file in this plugin can reach: ApiRetry,
 *     SafeHttpClient, and the `wp_remote_*` / `wp_safe_remote_*` functions.
 *     All return a WP_Error — "every outside service is unavailable" — and
 *     all append the URL to `BccRenderWorld::$http`.
 *   - Every write surface: options, transients and the object cache.
 *   - The breaker-probe surface: `AdvisoryLock::acquire()` (the half-open
 *     probe is `GET_LOCK('bcc_cb_probe_<id>')`) and
 *     `OnchainCircuitBreaker::isOpen()` (which claims it).
 *   - Repositories, which read fixtures. `WalletRepository` is the TRAP: it
 *     holds 200 linked Cosmos Hub wallets and records every lookup, so a
 *     render path that fetched them to send anywhere would be seen.
 *   - The two scanner panels, which are rendered elsewhere and proven
 *     network-free on staging by the live render control; faked here so this
 *     suite pins the Verify page's own code.
 */

namespace {
    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            private string $code;
            private string $message;

            public function __construct(string $code = '', string $message = '')
            {
                $this->code    = $code;
                $this->message = $message;
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }

    foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400] as $c => $v) {
        if (!defined($c)) {
            define($c, $v);
        }
    }

    if (!class_exists('BccRenderWorld', false)) {
        final class BccRenderWorld
        {
            /** @var list<array{via: string, url: string}> every outbound attempt */
            public static array $http = [];

            /** @var list<string> every option/transient/cache write, as "kind:key" */
            public static array $writes = [];

            /** @var list<string> every advisory-lock or breaker probe touch */
            public static array $probes = [];

            /** @var list<string> every WalletRepository call */
            public static array $walletLookups = [];

            /** @var list<string> every log line, message + JSON context */
            public static array $log = [];

            /** @var list<object> the admin listing */
            public static array $rows = [];

            /** @var list<object> active chains */
            public static array $chains = [];

            /** @var list<object{chain_id: string, contract_address: string, wallets: string}> */
            public static array $index = [];

            public static bool $indexReadFails = false;

            /** @var list<string> the 200 linked Cosmos Hub wallet addresses */
            public static array $hubWallets = [];

            public static function reset(): void
            {
                self::$http          = [];
                self::$writes        = [];
                self::$probes        = [];
                self::$walletLookups = [];
                self::$log           = [];
                self::$rows          = [];
                self::$chains        = [];
                self::$index         = [];
                self::$indexReadFails = false;
                self::$hubWallets    = [];
            }

            /** @return \WP_Error */
            public static function unavailable(string $via, string $url)
            {
                self::$http[] = ['via' => $via, 'url' => $url];

                return new \WP_Error('http_request_failed', 'outside service unavailable (test)');
            }
        }
    }

    // ── identity / request ─────────────────────────────────────────────
    if (!function_exists('current_user_can')) {
        function current_user_can(...$args): bool { return true; }
    }
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int { return 1; }
    }
    if (!function_exists('wp_die')) {
        /** @param mixed $message */
        function wp_die($message = '', $title = '', $args = []): void
        {
            throw new \RuntimeException('wp_die: ' . (is_string($message) ? $message : ''));
        }
    }

    // ── escaping / formatting (behaviour-faithful, not decorative) ─────
    if (!function_exists('esc_html')) {
        function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES); }
    }
    if (!function_exists('esc_attr')) {
        function esc_attr($text): string { return htmlspecialchars((string) $text, ENT_QUOTES); }
    }
    if (!function_exists('esc_url')) {
        function esc_url($url): string { return htmlspecialchars((string) $url, ENT_QUOTES); }
    }
    if (!function_exists('esc_url_raw')) {
        function esc_url_raw($url): string { return preg_match('#^https?://[^\s<>"]+$#i', (string) $url) === 1 ? (string) $url : ''; }
    }
    if (!function_exists('__')) {
        function __($text, $domain = ''): string { return (string) $text; }
    }
    if (!function_exists('esc_html__')) {
        function esc_html__($text, $domain = ''): string { return esc_html($text); }
    }
    if (!function_exists('esc_attr__')) {
        function esc_attr__($text, $domain = ''): string { return esc_attr($text); }
    }
    if (!function_exists('sanitize_key')) {
        function sanitize_key($key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)) ?? ''; }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($str): string { return trim(strip_tags((string) $str)); }
    }
    if (!function_exists('wp_unslash')) {
        function wp_unslash($value) { return $value; }
    }
    if (!function_exists('number_format_i18n')) {
        function number_format_i18n($number, int $decimals = 0): string { return number_format((float) $number, $decimals); }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data, int $options = 0, int $depth = 512) { return json_encode($data, $options, $depth); }
    }
    if (!function_exists('admin_url')) {
        function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
    }
    if (!function_exists('add_query_arg')) {
        function add_query_arg(...$args): string
        {
            if (is_array($args[0] ?? null)) {
                $params = array_filter($args[0], static fn($v) => $v !== false && $v !== null);
                $url    = (string) ($args[1] ?? '');
            } else {
                $params = [(string) $args[0] => $args[1] ?? ''];
                $url    = (string) ($args[2] ?? '');
            }
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }
    }
    if (!function_exists('get_permalink')) {
        function get_permalink($post = 0): string { return 'https://example.test/?p=' . (int) $post; }
    }
    if (!function_exists('wp_create_nonce')) {
        function wp_create_nonce($action = -1): string { return 'nonce'; }
    }
    if (!function_exists('wp_nonce_field')) {
        function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true): string
        {
            $html = '<input type="hidden" name="' . esc_attr($name) . '" value="nonce">';
            if ($echo) {
                echo $html;
            }
            return $html;
        }
    }
    if (!function_exists('checked')) {
        function checked($checked, $current = true, $echo = true): string
        {
            $out = ((string) $checked === (string) $current) ? " checked='checked'" : '';
            if ($echo) {
                echo $out;
            }
            return $out;
        }
    }
    if (!function_exists('selected')) {
        function selected($selected, $current = true, $echo = true): string
        {
            $out = ((string) $selected === (string) $current) ? " selected='selected'" : '';
            if ($echo) {
                echo $out;
            }
            return $out;
        }
    }
    if (!function_exists('apply_filters')) {
        function apply_filters($hook, $value, ...$args) { return $value; }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing): bool { return $thing instanceof \WP_Error; }
    }

    // ── reads that must stay reads ─────────────────────────────────────
    if (!function_exists('get_option')) {
        function get_option($name, $default = false) { return $default; }
    }
    if (!function_exists('get_transient')) {
        function get_transient($key) { return false; }
    }
    if (!function_exists('wp_cache_get')) {
        function wp_cache_get($key, $group = '', $force = false, &$found = null) { $found = false; return false; }
    }

    // ── every write surface, recorded ──────────────────────────────────
    if (!function_exists('update_option')) {
        function update_option($name, $value, $autoload = null): bool { \BccRenderWorld::$writes[] = 'option:' . $name; return true; }
    }
    if (!function_exists('add_option')) {
        function add_option($name, $value = '', $deprecated = '', $autoload = null): bool { \BccRenderWorld::$writes[] = 'option:' . $name; return true; }
    }
    if (!function_exists('delete_option')) {
        function delete_option($name): bool { \BccRenderWorld::$writes[] = 'option-delete:' . $name; return true; }
    }
    if (!function_exists('set_transient')) {
        function set_transient($key, $value, $ttl = 0): bool { \BccRenderWorld::$writes[] = 'transient:' . $key; return true; }
    }
    if (!function_exists('delete_transient')) {
        function delete_transient($key): bool { \BccRenderWorld::$writes[] = 'transient-delete:' . $key; return true; }
    }
    if (!function_exists('wp_cache_set')) {
        function wp_cache_set($key, $data, $group = '', $expire = 0): bool { \BccRenderWorld::$writes[] = 'cache:' . $group . ':' . $key; return true; }
    }
    if (!function_exists('wp_cache_add')) {
        function wp_cache_add($key, $data, $group = '', $expire = 0): bool { \BccRenderWorld::$writes[] = 'cache:' . $group . ':' . $key; return true; }
    }
    if (!function_exists('wp_cache_delete')) {
        function wp_cache_delete($key, $group = ''): bool { \BccRenderWorld::$writes[] = 'cache-delete:' . $group . ':' . $key; return true; }
    }

    // ── every HTTP function, recorded and unavailable ──────────────────
    foreach (['wp_remote_get', 'wp_remote_post', 'wp_remote_head', 'wp_safe_remote_get', 'wp_safe_remote_post'] as $fn) {
        if (!function_exists($fn)) {
            eval('function ' . $fn . '($url, $args = []) { return \BccRenderWorld::unavailable("' . $fn . '", (string) $url); }');
        }
    }
    if (!function_exists('wp_remote_request')) {
        function wp_remote_request($url, $args = []) { return \BccRenderWorld::unavailable('wp_remote_request', (string) $url); }
    }
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response) { return is_array($response) ? (int) ($response['code'] ?? 0) : ''; }
    }
    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body($response): string { return is_array($response) ? (string) ($response['body'] ?? '') : ''; }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            private static function rec(string $level, string $m, array $c): void
            {
                \BccRenderWorld::$log[] = $level . ' ' . $m . ' ' . (string) json_encode($c);
            }
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void { self::rec('error', $m, $c); }
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void { self::rec('warning', $m, $c); }
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void { self::rec('info', $m, $c); }
            /** @param array<string, mixed> $c */
            public static function debug(string $m, array $c = []): void { self::rec('debug', $m, $c); }
        }
    }
}

namespace BCC\Core\Http {
    if (!class_exists(SafeHttpClient::class, false)) {
        final class SafeHttpClient
        {
            /** @param array<string, mixed> $args */
            public static function get(string $url, array $args = []) { return \BccRenderWorld::unavailable('SafeHttpClient::get', $url); }
            /** @param array<string, mixed> $args */
            public static function post(string $url, array $args = []) { return \BccRenderWorld::unavailable('SafeHttpClient::post', $url); }
            /**
             * @param list<string>         $urls
             * @param array<string, mixed> $args
             * @return array<int, \WP_Error>
             */
            public static function getBatchSameHost(array $urls, array $args = []): array
            {
                $out = [];
                foreach (array_values($urls) as $i => $u) {
                    $out[$i] = \BccRenderWorld::unavailable('SafeHttpClient::getBatchSameHost', $u);
                }
                return $out;
            }
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists(AdvisoryLock::class, false)) {
        final class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool { \BccRenderWorld::$probes[] = 'lock:' . $key; return true; }
            public static function release(string $key): void { \BccRenderWorld::$probes[] = 'release:' . $key; }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        final class PeepSoGroupRepository
        {
            public static function countGroupMembers(int $groupId): int { return 0; }
        }
    }
}

namespace BCC\Trust\Onchain\Support {
    if (!class_exists(ApiRetry::class, false)) {
        final class ApiRetry
        {
            /** @param array<string, mixed> $args @param array<string, mixed> $options */
            public static function get(string $url, array $args = [], array $options = []) { return \BccRenderWorld::unavailable('ApiRetry::get', $url); }
            /** @param array<string, mixed> $args @param array<string, mixed> $options */
            public static function post(string $url, array $args = [], array $options = []) { return \BccRenderWorld::unavailable('ApiRetry::post', $url); }
            /** @param array<string, mixed> $options */
            public static function request(callable $fn, array $options = []) { return \BccRenderWorld::unavailable('ApiRetry::request', 'callable'); }
            /**
             * @param list<string> $urls @param array<string, mixed> $args @param array<string, mixed> $options
             * @return array<int, \WP_Error>
             */
            public static function getBatchSameHost(array $urls, array $args = [], array $options = []): array
            {
                $out = [];
                foreach (array_values($urls) as $i => $u) {
                    $out[$i] = \BccRenderWorld::unavailable('ApiRetry::getBatchSameHost', $u);
                }
                return $out;
            }
        }
    }

    if (!class_exists(OnchainCircuitBreaker::class, false)) {
        final class OnchainCircuitBreaker
        {
            public static function isOpen(int $chainId): bool { \BccRenderWorld::$probes[] = 'isOpen:' . $chainId; return false; }
            public static function isResting(int $chainId): bool { \BccRenderWorld::$probes[] = 'isResting:' . $chainId; return false; }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(CollectionRepository::class, false)) {
        final class CollectionRepository
        {
            /** @return array{items: list<object>, total: int, pages: int, available: bool} */
            public static function listForAdminState(string $tab, int $page = 1, int $perPage = 50, ?string $chainSlug = null, ?string $tokenStandard = null): array
            {
                $rows  = \BccRenderWorld::$rows;
                $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
                return ['items' => $slice, 'total' => count($rows), 'pages' => (int) ceil(max(1, count($rows)) / $perPage), 'available' => true];
            }

            /** @return array{counts: array<string, int>, available: bool} */
            public static function countsByState(?string $chainSlug = null, ?string $tokenStandard = null): array
            {
                return ['counts' => ['discovered_unverified' => count(\BccRenderWorld::$rows)], 'available' => true];
            }

            /** @return list<string> */
            public static function getDistinctTokenStandards(): array { return ['CW-721', 'ERC-721']; }
        }
    }

    if (!class_exists(ChainRepository::class, false)) {
        final class ChainRepository
        {
            /** @return list<object> */
            public static function getActive(?string $chainType = null): array
            {
                return array_values(array_filter(
                    \BccRenderWorld::$chains,
                    static fn(object $c): bool => $chainType === null || $c->chain_type === $chainType
                ));
            }
            public static function getById(int $id): ?object
            {
                foreach (\BccRenderWorld::$chains as $c) { if ((int) $c->id === $id) { return $c; } }
                return null;
            }
            public static function getBySlug(string $slug): ?object
            {
                foreach (\BccRenderWorld::$chains as $c) { if ($c->slug === $slug) { return $c; } }
                return null;
            }
        }
    }

    if (!class_exists(CollectionSignalRepository::class, false)) {
        final class CollectionSignalRepository
        {
            /** @return list<object> */
            public static function countsByCollection(?int $chainId = null, int $limit = 500): array { return []; }
        }
    }

    if (!class_exists(GatedGroupRepository::class, false)) {
        final class GatedGroupRepository
        {
            public static function findGroupForCollection(int $chainId, string $contract): ?int { return null; }
        }
    }

    if (!class_exists(CosmwasmContractRepository::class, false)) {
        final class CosmwasmContractRepository
        {
            /** @param list<int> $chainIds @param list<string> $addresses @return list<object> */
            public static function findManyForChains(array $chainIds, array $addresses): array { return []; }
        }
    }

    if (!class_exists(CosmwasmCodeFamilyRepository::class, false)) {
        final class CosmwasmCodeFamilyRepository
        {
            /** @param list<int> $chainIds @param list<int> $codeIds @return list<object> */
            public static function findManyForChains(array $chainIds, array $codeIds): array { return []; }
        }
    }

    if (!class_exists(NftHoldingsRepository::class, false)) {
        final class NftHoldingsRepository
        {
            /** @return list<object>|null */
            public static function countDistinctWalletsPerContract(int $limit = 500): ?array
            {
                return \BccRenderWorld::$indexReadFails ? null : array_slice(\BccRenderWorld::$index, 0, $limit);
            }
        }
    }

    if (!class_exists(WalletRepository::class, false)) {
        /**
         * ⚠ THE TRAP. Holds 200 linked Cosmos Hub wallets and records every
         * call. `listAddressesForChain` no longer exists in production; it is
         * kept here so a mutant that RESTORES the render-time fan-out has
         * something to call, and is therefore caught by the HTTP spy rather
         * than by a fatal that would read as a kill for the wrong reason.
         */
        final class WalletRepository
        {
            /** @return list<string> */
            public static function listAddressesForChain(int $chainId, int $limit = 200): array
            {
                \BccRenderWorld::$walletLookups[] = 'listAddressesForChain:' . $chainId;
                return array_slice(\BccRenderWorld::$hubWallets, 0, $limit);
            }

            /** @return list<object> */
            public static function getForUser(int $userId, ?string $chainSlug = null, bool $verifiedOnly = false): array
            {
                \BccRenderWorld::$walletLookups[] = 'getForUser:' . $userId;
                return array_map(
                    static fn(string $a, int $i): object => (object) ['id' => $i + 1, 'chain_id' => 8, 'wallet_address' => $a],
                    \BccRenderWorld::$hubWallets,
                    array_keys(\BccRenderWorld::$hubWallets)
                );
            }
        }
    }

    if (!class_exists(NftSpamContractRepository::class, false)) {
        final class NftSpamContractRepository
        {
            public const RULE_DENY  = 'deny';
            public const RULE_ALLOW = 'allow';
            public static function getRule(int $chainId, string $contract): ?string { return null; }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!class_exists(CosmwasmDiscoveryHealthSnapshot::class, false)) {
        final class CosmwasmDiscoveryHealthSnapshot
        {
            /** @return array<string, mixed> */
            public static function buildSummary(): array { return ['chains' => [], 'issues' => []]; }
        }
    }
}

namespace BCC\Trust\Onchain\Admin\Views {
    if (!class_exists(CosmwasmScannerPanel::class, false)) {
        final class CosmwasmScannerPanel
        {
            /** @param array<string, mixed> $summary */
            public static function render(array $summary): void { echo '<div data-fake="scanner-panel"></div>'; }
            public static function renderCandidateDetail(object $collection, object $candidate, ?object $family, bool $isVerified, int $colspan): void {}
        }
    }

    if (!class_exists(DiscoveryScanPanel::class, false)) {
        final class DiscoveryScanPanel
        {
            public static function render(object $chain, bool $scannable, string $whyNot = ''): void { echo '<div data-fake="scan-panel"></div>'; }
        }
    }
}
