<?php

declare(strict_types=1);

/**
 * Stub world for `StargazeFanOutRemovedTest` and `CollectionStancePanelStatusTest`.
 *
 * ── WHY A DEDICATED FILE ────────────────────────────────────────────────
 * `nft-indexer-stubs.php` fakes a SINGLE chain and a `FetcherFactory` that
 * always builds an `EvmFetcher`. The claim under test here is about five
 * different surfaces, several wallets, and TWO chain families at once —
 * a Cosmos wallet whose evidence is unreadable must not erase the EVM
 * wallet's real rows. Bending the single-chain world into that shape would
 * make the fixtures the thing under test.
 *
 * ── THE ANTI-VACUITY RULE THIS FILE EXISTS TO SERVE ─────────────────────
 * "Zero requests" only means something when a request was POSSIBLE. So:
 *   - `BccFanOutWorld::$http` records EVERY transport a plugin file can
 *     reach (ApiRetry get/post/batch, SafeHttpClient, wp_remote_*), and
 *     every one of them is AVAILABLE and would succeed if called;
 *   - the recording fetcher genuinely returns rows when asked, so a path
 *     that still called `fetch_collections()` would visibly persist them;
 *   - fixtures carry MULTIPLE wallets with real bech32/0x addresses, so a
 *     leak has something to leak.
 *
 * Nothing here is a no-op that would make a passing test meaningless.
 */

namespace {

    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            /** @var array<string, list<string>> */
            private array $errors = [];

            public function __construct(string $code = '', string $message = '')
            {
                if ($code !== '') {
                    $this->errors[$code][] = $message;
                }
            }

            public function get_error_code(): string
            {
                return (string) (array_key_first($this->errors) ?? '');
            }

            public function get_error_message(): string
            {
                $code = $this->get_error_code();
                return $code === '' ? '' : (string) ($this->errors[$code][0] ?? '');
            }
        }
    }

    if (!class_exists('BccFanOutWorld', false)) {
        /**
         * The recorder. Every observable side effect the five paths could
         * produce lands here, so a test asserts on measurements rather than
         * on the absence of a mock call.
         */
        final class BccFanOutWorld
        {
            /** @var list<array{via: string, url: string}> every outbound attempt */
            public static array $http = [];

            /** @var list<string> hooks handed to wp_schedule_single_event/wp_schedule_event */
            public static array $scheduled = [];

            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public static array $log = [];

            /** @var list<string> cache/transient keys written */
            public static array $cacheWrites = [];

            /**
             * Live transient store — reads see what writes put there.
             *
             * @var array<string, mixed>
             */
            public static array $transients = [];

            /**
             * Seeded `peepso-page` posts, keyed by slug, for the creator
             * gallery's slug → post resolution. Empty means the endpoint
             * legitimately 404s — still a valid observation, because an
             * anonymous GET must schedule nothing either way.
             *
             * @var array<string, \WP_Post>
             */
            public static array $posts = [];

            /**
             * Every transient write WITH its payload, so a test can assert
             * on what was stored and not merely that something was.
             *
             * @var list<array{key: string, value: mixed}>
             */
            public static array $transientWrites = [];

            /** @var list<string> every `fetch_collections` call, as "chainId:address" */
            public static array $fetchCollectionsCalls = [];

            /** @var list<int> wallet link ids stamped as refreshed */
            public static array $refreshStamps = [];

            /** @var list<int> collection row ids backed off */
            public static array $backoffs = [];

            /** @var list<array{wallet_link_id: int, rows: int}> persisted batches */
            public static array $persisted = [];

            // ── Seeded inputs (the "world", as opposed to the recordings) ──

            /** Signed-in viewer for the member-facing paths. */
            public static int $currentUserId = 0;

            /**
             * Per-chain-family capability override. Null entries fall back to
             * the real post-PR-7.12 answer, so a test must OPT IN to pretend
             * Cosmos still advertises wallet discovery.
             *
             * @var array<string, list<string>>
             */
            public static array $capabilities = [];

            /**
             * Seeded `list_holdings()` results, keyed by wallet address.
             * `complete` is the load-bearing flag: false means the walk did
             * not resolve, which must never read as "owns nothing".
             *
             * @var array<string, array{items: list<array<string, mixed>>, complete?: bool, truncated?: bool}>
             */
            public static array $holdings = [];

            /**
             * Seeded `count_holdings()` answers, keyed "address|contract".
             * A MISSING key returns null — provider unreadable — which is the
             * case that must not grant access and must not revoke.
             *
             * @var array<string, int|null>
             */
            public static array $counts = [];

            public static function reset(): void
            {
                self::$http                  = [];
                self::$scheduled             = [];
                self::$log                   = [];
                self::$cacheWrites           = [];
                self::$fetchCollectionsCalls = [];
                self::$refreshStamps         = [];
                self::$backoffs              = [];
                self::$persisted             = [];
                self::$currentUserId         = 0;
                self::$capabilities          = [];
                self::$holdings              = [];
                self::$counts                = [];
                // ⚠ The transient store MUST be cleared here. It is a live
                // read/write cache, so a payload left behind would be
                // served to the next test — and the one thing this world
                // exists to measure is whether an INCOMPLETE walk ever gets
                // cached. A leaked transient would answer that question
                // with the previous test's data.
                self::$transients            = [];
                self::$transientWrites       = [];
                self::$posts                 = [];
            }

            /** Records the attempt and returns a SUCCESS-shaped response. */
            public static function record(string $via, string $url): array
            {
                self::$http[] = ['via' => $via, 'url' => $url];

                return ['response' => ['code' => 200], 'body' => '{"collections":[],"total":0}'];
            }

            /** Everything recorded, flattened — for leak assertions. */
            public static function allRecordedText(): string
            {
                return (string) json_encode([
                    self::$http,
                    self::$log,
                    self::$cacheWrites,
                    self::$scheduled,
                ]);
            }
        }
    }

    // ── WordPress surface ───────────────────────────────────────────────

    // Time constants. The REAL HoldingsService caches with DAY_IN_SECONDS
    // and the seed path uses HOUR_IN_SECONDS; without these the services
    // under test fatal before they can refuse anything, and the refusal is
    // the whole point.
    foreach ([
        'MINUTE_IN_SECONDS' => 60,
        'HOUR_IN_SECONDS'   => 3600,
        'DAY_IN_SECONDS'    => 86400,
        'WEEK_IN_SECONDS'   => 604800,
        'YEAR_IN_SECONDS'   => 31536000,
    ] as $bccStubConstName => $bccStubConstValue) {
        if (!defined($bccStubConstName)) {
            define($bccStubConstName, $bccStubConstValue);
        }
    }
    unset($bccStubConstName, $bccStubConstValue);

    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing): bool { return $thing instanceof \WP_Error; }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data, int $options = 0, int $depth = 512) { return json_encode($data, $options, $depth); }
    }
    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body($response): string { return is_array($response) ? (string) ($response['body'] ?? '') : ''; }
    }
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response): int { return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0; }
    }
    if (!function_exists('esc_url_raw')) {
        function esc_url_raw(string $url): string { return $url; }
    }
    if (!function_exists('esc_html')) {
        function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES); }
    }
    if (!function_exists('__')) {
        function __($text, $domain = ''): string { return (string) $text; }
    }
    if (!function_exists('current_time')) {
        function current_time(string $type, $gmt = 0): string { return gmdate('Y-m-d H:i:s'); }
    }
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int { return \BccFanOutWorld::$currentUserId ?? 0; }
    }
    // PR 7.14: a stance write resumes its wallet walk from user meta. In-memory,
    // per test process; nothing here asserts on it.
    if (!function_exists('get_user_meta')) {
        function get_user_meta(int $userId, string $key = '', bool $single = false) { return $GLOBALS['__bcc_fanout_user_meta'][$userId][$key] ?? ''; }
    }
    if (!function_exists('update_user_meta')) {
        function update_user_meta(int $userId, string $key, $value): bool { $GLOBALS['__bcc_fanout_user_meta'][$userId][$key] = $value; return true; }
    }
    if (!function_exists('delete_user_meta')) {
        function delete_user_meta(int $userId, string $key): bool { unset($GLOBALS['__bcc_fanout_user_meta'][$userId][$key]); return true; }
    }

    // ⚠ The transports. Each one is REAL enough to succeed, so a surviving
    // caller produces a visible record instead of a silent failure that
    // could be mistaken for "nothing happened".
    if (!function_exists('wp_remote_get')) {
        function wp_remote_get(string $url, array $args = []) { return \BccFanOutWorld::record('wp_remote_get', $url); }
    }
    if (!function_exists('wp_remote_post')) {
        function wp_remote_post(string $url, array $args = []) { return \BccFanOutWorld::record('wp_remote_post', $url); }
    }
    if (!function_exists('wp_safe_remote_get')) {
        function wp_safe_remote_get(string $url, array $args = []) { return \BccFanOutWorld::record('wp_safe_remote_get', $url); }
    }

    // ── Post lookup (the creator-slug resolution the gallery does first) ──
    //
    // The endpoint resolves a slug to a `peepso-page` post before it reads
    // anything. Without these it fatals there, and a fatal would mask the
    // only claim under test: that the anonymous GET schedules nothing and
    // contacts nobody. Seed `BccFanOutWorld::$posts` to resolve a creator;
    // leave it empty and the endpoint legitimately 404s — which is still a
    // valid observation, because scheduling must not happen either way.
    if (!class_exists('WP_Post', false)) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_type = 'peepso-page';
            public string $post_name = '';
        }
    }

    if (!function_exists('get_posts')) {
        /**
         * ⚠ HONOURS `fields => 'ids'`, because the caller does.
         * `CreatorGalleryEndpoint` asks for ids and then runs
         * `get_post((int) $candidates[0])`; a stub returning WP_Post objects
         * makes PHP warn "Object of class WP_Post could not be converted to
         * int" and the fixture silently stops resolving.
         *
         * @param  array<string, mixed> $args
         * @return list<\WP_Post>|list<int>
         */
        function get_posts(array $args = []): array
        {
            $name = (string) ($args['name'] ?? '');
            $post = \BccFanOutWorld::$posts[$name] ?? null;
            if (!$post instanceof \WP_Post) {
                return [];
            }

            return ($args['fields'] ?? '') === 'ids' ? [$post->ID] : [$post];
        }
    }

    if (!function_exists('get_post')) {
        function get_post($postId = 0)
        {
            foreach (\BccFanOutWorld::$posts as $post) {
                if ($post instanceof \WP_Post && $post->ID === (int) $postId) {
                    return $post;
                }
            }

            return null;
        }
    }

    if (!function_exists('get_post_meta')) {
        function get_post_meta($postId, string $key = '', bool $single = false)
        {
            // Every seeded post is a creator page; `nft` is the real
            // `_bcc_page_type` value PageTypeMap maps 'creator' to.
            return $key === '_bcc_page_type' ? 'nft' : '';
        }
    }

    if (!function_exists('wp_schedule_single_event')) {
        function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
        {
            \BccFanOutWorld::$scheduled[] = $hook;
            return true;
        }
    }
    if (!function_exists('wp_schedule_event')) {
        function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
        {
            \BccFanOutWorld::$scheduled[] = $hook;
            return true;
        }
    }
    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled(string $hook, array $args = []) { return false; }
    }

    // ⚠ THE TRANSIENT SEAM IS REAL, NOT A NO-OP.
    //
    // The stance panel's Cosmos branch runs the REAL `HoldingsService`,
    // whose whole contract is "never cache an incomplete walk". A stub that
    // threw writes away would make that assertion unfalsifiable: the test
    // would pass whether the service cached an incomplete result or not.
    // So reads return what was written, and every write is recorded with
    // its payload for inspection.
    if (!function_exists('get_transient')) {
        function get_transient(string $key)
        {
            return \BccFanOutWorld::$transients[$key] ?? false;
        }
    }
    if (!function_exists('set_transient')) {
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            \BccFanOutWorld::$cacheWrites[]      = $key;
            \BccFanOutWorld::$transients[$key]   = $value;
            \BccFanOutWorld::$transientWrites[]  = ['key' => $key, 'value' => $value];

            return true;
        }
    }
    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool { return true; }
    }
    if (!function_exists('wp_cache_get')) {
        function wp_cache_get(string $key, string $group = '') { return false; }
    }
    if (!function_exists('wp_cache_set')) {
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            \BccFanOutWorld::$cacheWrites[] = $group . ':' . $key;
            return true;
        }
    }
    if (!function_exists('apply_filters')) {
        function apply_filters(string $hook, $value, ...$args) { return $value; }
    }
    if (!function_exists('get_option')) {
        function get_option(string $option, $default = false) { return $default; }
    }
    if (!function_exists('update_option')) {
        function update_option(string $option, $value, ?bool $autoload = null): bool { return true; }
    }
}

namespace BCC\Core\Log {

    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void { self::add('error', $m, $c); }
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void { self::add('warning', $m, $c); }
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void { self::add('info', $m, $c); }
            /** @param array<string, mixed> $c */
            public static function audit(string $m, array $c = []): void { self::add('audit', $m, $c); }

            /** @param array<string, mixed> $c */
            private static function add(string $level, string $m, array $c): void
            {
                \BccFanOutWorld::$log[] = ['level' => $level, 'message' => $m, 'context' => $c];
            }
        }
    }
}

namespace BCC\Core\DB {

    if (!class_exists(__NAMESPACE__ . '\\AdvisoryLock', false)) {
        final class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool { return true; }
            public static function release(string $key): void {}
        }
    }
}

namespace BCC\Trust\Onchain\Support {

    if (!class_exists(__NAMESPACE__ . '\\ApiRetry', false)) {
        final class ApiRetry
        {
            /** @param array<string, mixed> $args @param array<string, mixed> $options */
            public static function get(string $url, array $args = [], array $options = [])
            {
                return \BccFanOutWorld::record('ApiRetry::get', $url);
            }

            /** @param array<string, mixed> $args @param array<string, mixed> $options */
            public static function post(string $url, array $args = [], array $options = [])
            {
                return \BccFanOutWorld::record('ApiRetry::post', $url);
            }

            /**
             * @param list<string> $urls @param array<string, mixed> $args @param array<string, mixed> $options
             * @return array<int, array<string, mixed>>
             */
            public static function getBatchSameHost(array $urls, array $args = [], array $options = []): array
            {
                $out = [];
                foreach (array_values($urls) as $i => $u) {
                    $out[$i] = \BccFanOutWorld::record('ApiRetry::getBatchSameHost', $u);
                }
                return $out;
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\ChainSupport', false)) {
        final class ChainSupport
        {
            /** @return list<string> */
            public static function supported(): array
            {
                return ['cosmos', 'ethereum', 'solana', 'osmosis', 'injective'];
            }
        }
    }
}

namespace {

    // ── Minimal REST surface ────────────────────────────────────────────
    //
    // Enough of WP's REST classes to invoke the REAL CreatorGalleryEndpoint
    // and the REAL CollectionStancesEndpoint. The point is behavioural: an
    // anonymous GET must be *called* and observed to schedule nothing, not
    // merely inspected for the absence of a call site.

    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            /** @param array<string, mixed> $params */
            public function __construct(private array $params = []) {}

            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }

            /** @param mixed $value */
            public function set_param(string $key, $value): void
            {
                $this->params[$key] = $value;
            }
        }
    }

    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            /** @var array<string, string> */
            public array $headers = [];

            /** @param mixed $data */
            public function __construct(public $data = null, public int $status = 200) {}

            public function header(string $key, string $value): void
            {
                $this->headers[$key] = $value;
            }

            public function get_data()
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }
        }
    }

    if (!class_exists('WP_REST_Server', false)) {
        class WP_REST_Server
        {
            public const READABLE  = 'GET';
            public const CREATABLE = 'POST';
            public const DELETABLE = 'DELETE';
        }
    }
}

namespace BCC\Trust\Core\Support {

    if (!class_exists(__NAMESPACE__ . '\\ApiResponse', false)) {
        final class ApiResponse
        {
            /** @param mixed $data */
            public static function ok($data, int $status = 200): \WP_REST_Response
            {
                return new \WP_REST_Response(['data' => $data, '_meta' => ['version' => 'test']], $status);
            }

            public static function error(string $code, string $message, int $status = 400): \WP_REST_Response
            {
                return new \WP_REST_Response(
                    ['error' => ['code' => $code, 'message' => $message], '_meta' => ['version' => 'test']],
                    $status
                );
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\PageTypeMap', false)) {
        final class PageTypeMap
        {
            /**
             * ⚠ The REAL shape, copied from the production class. An earlier
             * draft of this fake invented `CREATOR` + `all()`, which the
             * gallery endpoint never reads — it fataled on the missing
             * constant instead, and a fatal is not the refusal under test.
             *
             * @var array<string, string>
             */
            public const KIND_TO_PAGE_TYPE = [
                'validator' => 'validator',
                'project'   => 'builder',
                'creator'   => 'nft',
            ];

            /** @var array<string, string> */
            public const PAGE_TYPE_TO_KIND = [
                'validator' => 'validator',
                'builder'   => 'project',
                'nft'       => 'creator',
            ];
        }
    }
}

namespace BCC\Core {

    if (!interface_exists(__NAMESPACE__ . '\\PageOwnerResolverInterface', false)) {
        interface PageOwnerResolverInterface
        {
            public function getPageForOwner(int $userId): int;
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\ServiceLocator', false)) {
        final class ServiceLocator
        {
            public static function resolvePageOwnerResolver(): PageOwnerResolverInterface
            {
                return new class implements PageOwnerResolverInterface {
                    public function getPageForOwner(int $userId): int
                    {
                        return 0;
                    }
                };
            }
        }
    }
}

namespace BCC\Trust\Onchain\Support {

    if (!class_exists(__NAMESPACE__ . '\\ExplorerLinkBuilder', false)) {
        final class ExplorerLinkBuilder
        {
            public static function addressUrl(?string $explorerUrl, string $address): ?string
            {
                return $explorerUrl === null || $explorerUrl === ''
                    ? null
                    : rtrim($explorerUrl, '/') . '/address/' . $address;
            }
        }
    }
}

namespace BCC\Core\Security {

    if (!class_exists(__NAMESPACE__ . '\\Throttle', false)) {
        final class Throttle
        {
            /** Always allows: the tests are about disclosure, not rate limits. */
            public static function allow(string $key, int $limit, int $window): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(__NAMESPACE__ . '\\WalletRepository', false)) {
        final class WalletRepository
        {
            /** @var array<int, list<object>> userId → wallet rows */
            public static array $byUser = [];

            /** @var array<int, list<object>> postId → wallet rows */
            public static array $byProject = [];

            public static function reset(): void
            {
                self::$byUser    = [];
                self::$byProject = [];
            }

            /** @return list<object> */
            public static function getForUser(int $userId, ?string $type = null, bool $withChain = false): array
            {
                return self::$byUser[$userId] ?? [];
            }

            /**
             * PR 7.14 fail-closed sibling (the ownership evaluator reads
             * wallets through it); this stub world never fails a read.
             *
             * @return list<object>
             */
            public static function getForUserOrThrow(int $userId, ?string $type = null, bool $withChain = false): array
            {
                return self::getForUser($userId, $type, $withChain);
            }

            /** @return list<object> */
            public static function getForProject(int $postId, ?string $walletType = null): array
            {
                return self::$byProject[$postId] ?? [];
            }

            /**
             * ⚠ NOT `self::$byUser + self::$byProject`. Both arrays are
             * keyed by id — one by user id, the other by post id — and `+`
             * unions by KEY, so a project whose post id happened to equal a
             * user id would silently drop one side's wallets. The 4-hourly
             * cron resolves its wallets through here, so that collision
             * would make a "zero requests" assertion pass because the
             * fixture vanished, not because the code refused.
             */
            public static function getById(int $walletLinkId): ?object
            {
                $groups = array_merge(array_values(self::$byUser), array_values(self::$byProject));
                foreach ($groups as $wallets) {
                    foreach ($wallets as $w) {
                        if ((int) ($w->id ?? 0) === $walletLinkId) {
                            return $w;
                        }
                    }
                }

                return null;
            }

            public static function markHoldingsRefreshed(int $walletLinkId): bool
            {
                \BccFanOutWorld::$refreshStamps[] = $walletLinkId;
                return true;
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\ChainRepository', false)) {
        final class ChainRepository
        {
            /** @var array<int, object> chainId → chain row */
            public static array $byId = [];

            public static function reset(): void
            {
                self::$byId = [];
            }

            public static function getById(int $chainId): ?object
            {
                return self::$byId[$chainId] ?? null;
            }

            public static function getBySlug(string $slug): ?object
            {
                foreach (self::$byId as $chain) {
                    if ((string) ($chain->slug ?? '') === $slug) {
                        return $chain;
                    }
                }
                return null;
            }

            /** @return list<object> */
            public static function getActive(): array
            {
                return array_values(self::$byId);
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\CollectionRepository', false)) {
        final class CollectionRepository
        {
            /** @var list<object> rows the 4h cron will see as expired */
            public static array $expired = [];

            /** @var array<int, list<object>> chainId → collection rows */
            public static array $byChain = [];

            /** @var array<int, bool> walletLinkId → already seeded */
            public static array $seeded = [];

            public static function reset(): void
            {
                self::$expired = [];
                self::$byChain = [];
                self::$seeded  = [];
            }

            /** @return list<object> */
            public static function getExpiredWalletLinked(int $limit = 50): array
            {
                return array_slice(self::$expired, 0, $limit);
            }

            public static function backoffRow(int $rowId): bool
            {
                \BccFanOutWorld::$backoffs[] = $rowId;
                return true;
            }

            public static function existsForWalletLink(int $walletLinkId): bool
            {
                return self::$seeded[$walletLinkId] ?? false;
            }

            /** @return list<object> */
            public static function listVerifiedByChain(int $chainId, int $limit = 30): array
            {
                $out = [];
                foreach (self::$byChain[$chainId] ?? [] as $row) {
                    if ((int) ($row->is_verified ?? 0) === 1) {
                        $out[] = $row;
                    }
                }
                return array_slice($out, 0, $limit);
            }

            /**
             * PR 7.14 fail-closed sibling; this stub world never fails a read.
             *
             * @return list<object>
             */
            public static function listVerifiedByChainOrThrow(int $chainId, int $limit = 30): array
            {
                return self::listVerifiedByChain($chainId, $limit);
            }

            /**
             * @param  list<string> $contracts
             * @return array<string, bool>
             */
            public static function verifiedMapForContracts(int $chainId, array $contracts): array
            {
                $map = [];
                foreach (self::$byChain[$chainId] ?? [] as $row) {
                    $c = strtolower((string) ($row->contract_address ?? ''));
                    if (in_array($c, array_map('strtolower', $contracts), true)) {
                        $map[$c] = ((int) ($row->is_verified ?? 0) === 1);
                    }
                }
                return $map;
            }

            public static function findTokenStandard(int $chainId, string $contract): ?string
            {
                return null;
            }

            /** PR 7.14 fail-closed sibling; never fails here. */
            public static function findTokenStandardOrThrow(int $chainId, string $contract): ?string
            {
                return self::findTokenStandard($chainId, $contract);
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\NftHoldingsRepository', false)) {
        final class NftHoldingsRepository
        {
            /** @var array<string, list<object>> "walletLinkId|chainId" → stored rows */
            public static array $visible = [];

            public static function reset(): void
            {
                self::$visible = [];
            }

            /** @return list<object> */
            public static function findVisibleForWallet(int $walletLinkId, int $chainId): array
            {
                return self::$visible[$walletLinkId . '|' . $chainId] ?? [];
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\NftSpamContractRepository', false)) {
        final class NftSpamContractRepository
        {
            public const RULE_DENY  = 'deny';
            public const RULE_ALLOW = 'allow';

            /** @var array<string, string> */
            public static array $rules = [];

            public static function reset(): void
            {
                self::$rules = [];
            }

            public static function getRule(int $chainId, string $contract): ?string
            {
                return self::$rules[$chainId . '|' . strtolower($contract)] ?? null;
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\CollectionSignalRepository', false)) {
        final class CollectionSignalRepository
        {
            public const STANCE_WAITLIST = 'waitlist';
            public const STANCE_SPAM     = 'spam';
            public const STANCES         = [self::STANCE_WAITLIST, self::STANCE_SPAM];

            /** @var list<array{user: int, chain: int, contract: string, stance: string}> */
            public static array $writes = [];

            /** @var array<string, string> "userId|chainId|contract" → stance */
            public static array $stances = [];

            public static function reset(): void
            {
                self::$writes  = [];
                self::$stances = [];
            }

            /** @return list<object> */
            public static function countsByCollection(): array
            {
                return [];
            }

            /**
             * @param  list<string> $contracts
             * @return array<string, string>
             */
            public static function getStancesForUser(int $userId, int $chainId, array $contracts): array
            {
                $out = [];
                foreach ($contracts as $contract) {
                    $key = $userId . '|' . $chainId . '|' . strtolower($contract);
                    if (isset(self::$stances[$key])) {
                        $out[strtolower($contract)] = self::$stances[$key];
                    }
                }
                return $out;
            }

            public static function setStance(int $userId, int $chainId, string $contract, string $stance): bool
            {
                self::$writes[] = ['user' => $userId, 'chain' => $chainId, 'contract' => $contract, 'stance' => $stance];
                return true;
            }

            public static function clearStance(int $userId, int $chainId, string $contract): bool
            {
                return true;
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\GatedGroupRepository', false)) {
        final class GatedGroupRepository
        {
            public static function findGroupForCollection(int $chainId, string $contract): ?int
            {
                return null;
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\SignalRepository', false)) {
        final class SignalRepository
        {
            public static function get_permanent(string $address, string $chain): ?object { return null; }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\ValidatorRepository', false)) {
        final class ValidatorRepository
        {
            public static function existsForWalletLink(int $walletLinkId): bool { return true; }
            /** @param array<string, mixed> $data */
            public static function upsert(array $data, int $walletLinkId, int $ttl): bool { return true; }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\DelegationRepository', false)) {
        final class DelegationRepository
        {
            public static function existsForWalletLink(int $walletLinkId): bool { return true; }
        }
    }
}

namespace BCC\Trust\Onchain\Services {

    if (!class_exists(__NAMESPACE__ . '\\CollectionPersistBatch', false)) {
        final class CollectionPersistBatch
        {
            /**
             * @param  array<int, array<string, mixed>> $collections
             * @return array{total: int, created: int, updated: int, failed: int}
             */
            public static function persist(array $collections, int $walletLinkId, int $ttlSeconds): array
            {
                \BccFanOutWorld::$persisted[] = ['wallet_link_id' => $walletLinkId, 'rows' => count($collections)];

                return ['total' => count($collections), 'created' => count($collections), 'updated' => 0, 'failed' => 0];
            }

            /** @param array{total: int, created: int, updated: int, failed: int} $result */
            public static function allPersisted(array $result): bool
            {
                return $result['failed'] === 0;
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\CollectionService', false)) {
        final class CollectionService
        {
            /** @var array<int, array{items: list<object>, total: int, pages: int}> */
            public static array $byProject = [];

            public static function reset(): void
            {
                self::$byProject = [];
            }

            /** @return array{items: list<object>, total: int, pages: int} */
            public static function getForProject(int $postId, int $page, int $perPage, string $sort, bool $force): array
            {
                return self::$byProject[$postId] ?? ['items' => [], 'total' => 0, 'pages' => 1];
            }

            public static function invalidate(int $postId): void {}
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\SignalRefreshService', false)) {
        final class SignalRefreshService
        {
            public static function fetchAndStoreWallet(int $userId, int $pageId, string $chain, string $address): void {}
        }
    }
}

namespace BCC\Trust\Onchain\Factories {

    if (!class_exists(__NAMESPACE__ . '\\FetcherFactory', false)) {
        final class FetcherFactory
        {
            /** Every chain family in the fixtures HAS a driver — so a skip is a decision, not an accident. */
            public static function has_driver(string $chainType): bool
            {
                return in_array($chainType, ['evm', 'solana', 'cosmos'], true);
            }

            /** @param object $chain */
            public static function make_for_chain(object $chain): object
            {
                return new \BccRecordingFetcher($chain);
            }
        }
    }
}

namespace {

    if (!class_exists('BccRecordingFetcher', false)) {
        /**
         * A fetcher that WOULD produce rows and WOULD hit the wire.
         *
         * `supports_feature()` answers from `BccFanOutWorld::$capabilities`,
         * defaulting to the real post-PR-7.12 answer per chain family. A
         * test can flip 'collection' back on for a chain to prove the
         * CALLER (not the fetcher) is what refuses.
         */
        final class BccRecordingFetcher implements
            \BCC\Trust\Onchain\Contracts\FetcherInterface,
            \BCC\Trust\Onchain\Contracts\CountsHoldingsWithCompleteness
        {
            public function __construct(private object $chain) {}

            // ── The rest of FetcherInterface ────────────────────────────
            //
            // ⚠ It must IMPLEMENT the interface, not merely quack like it:
            // `HoldingsService::countFromCacheOrFetch()` type-hints
            // FetcherInterface, so a duck-typed fake fatals with a TypeError
            // before the code under test can refuse anything — and a fatal
            // is not the refusal being measured.

            /** @return array<string, mixed> */
            public function fetch_all_validators(?\BCC\Trust\Onchain\Support\ProviderOutcomeReceipt $outcome = null): array { return []; }

            /** @return array<string, mixed> */
            public function enrich_validator(string $address, ?object $existingRow = null): array { return []; }

            /** @return array<string, mixed> */
            public function fetch_delegations(string $delegatorAddress): array { return []; }

            /** @return array<int, array<string, mixed>> */
            public function fetch_top_collections(int $limit = 100): array { return []; }

            public function get_chain(): object { return $this->chain; }

            public function last_fetch_error(): ?string { return null; }

            public function supports_feature(string $feature): bool
            {
                $type = (string) ($this->chain->chain_type ?? '');
                $map  = \BccFanOutWorld::$capabilities[$type] ?? null;
                if (is_array($map)) {
                    return in_array($feature, $map, true);
                }

                // Post-PR-7.12 truth: Cosmos has NO wallet-discovery driver.
                return $type === 'cosmos'
                    ? in_array($feature, ['validator', 'delegations', 'holdings_count', 'holdings_list'], true)
                    : in_array($feature, ['collection', 'holdings_count', 'holdings_list'], true);
            }

            /** @return array<int, array<string, mixed>> */
            public function fetch_collections(string $walletAddress, int $chainId = 0): array
            {
                \BccFanOutWorld::$fetchCollectionsCalls[] = $chainId . ':' . $walletAddress;

                // Reaches the wire, exactly as the real discovery drivers do.
                \BccFanOutWorld::record('fetcher::fetch_collections', 'https://provider.invalid/wallets/' . $walletAddress . '/collections');

                return [[
                    'contract_address' => '0xfeed000000000000000000000000000000000001',
                    'collection_name'  => 'Would Have Landed',
                    'chain_id'         => $chainId,
                    'token_standard'   => 'ERC-721',
                    'total_supply'     => null,
                    'image_url'        => null,
                ]];
            }

            /** @return array<string, mixed> */
            public function list_holdings(string $wallet, ?string $cursor = null): array
            {
                $seed = \BccFanOutWorld::$holdings[$wallet] ?? null;
                if ($seed === null) {
                    return ['items' => [], 'truncated' => false, 'cursor' => null, 'complete' => true];
                }

                return [
                    'items'     => $seed['items'],
                    'truncated' => (bool) ($seed['truncated'] ?? false),
                    'cursor'    => null,
                    'complete'  => (bool) ($seed['complete'] ?? true),
                ];
            }

            public function count_holdings(string $wallet, string $contract): ?int
            {
                return \BccFanOutWorld::$counts[$wallet . '|' . strtolower($contract)] ?? null;
            }

            /**
             * PR 7.14: like the real CosmosFetcher this double stands in for,
             * a seeded count is a COMPLETE answer — a seeded 0 means the chain
             * answered "holds none". Without the interface a 0 could not
             * prove anything, and the stance refusal would turn into a 503.
             */
            public function count_holdings_evidence(string $wallet, string $contract): ?\BCC\Trust\Onchain\ValueObjects\HoldingsCount
            {
                $count = $this->count_holdings($wallet, $contract);

                return $count === null ? null : \BCC\Trust\Onchain\ValueObjects\HoldingsCount::exact($count);
            }

            /** CosmosFetcher's worst case: ceil(100 / 30) pages. */
            public function max_requests_per_evidence_read(): int
            {
                return 4;
            }

            /** @return array<string, mixed> */
            public function fetch_validator(string $address): array { return []; }
        }
    }
}
