<?php

/**
 * Additional stubs for the AJAX handlers, the Chain Identity save, and the
 * confirmation-render assertions.
 *
 * Layered on top of onchain-admin-action-stubs.php (required below) so the
 * handler-only tests stay lean. Every definition is guarded, so load order
 * between the two files does not matter.
 */

declare(strict_types=1);

namespace {

    // Must live INSIDE a namespace block: a file using braced namespace
    // syntax may not carry statements outside one.
    require_once __DIR__ . '/onchain-admin-action-stubs.php';

    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    if (!class_exists('BccAjaxResponse', false)) {
        /** Thrown by the wp_send_json_* shims — they terminate in real WP. */
        class BccAjaxResponse extends \RuntimeException
        {
            public bool $success;

            /** @var array<string, mixed> */
            public array $data;

            /** @param array<string, mixed> $data */
            public function __construct(bool $success, array $data)
            {
                parent::__construct(($success ? 'success' : 'error'));
                $this->success = $success;
                $this->data = $data;
            }

            public function message(): string
            {
                return (string) ($this->data['message'] ?? '');
            }
        }
    }

    if (!class_exists('BccAjaxRecorder', false)) {
        /**
         * Records wp_send_json_* payloads.
         *
         * Recording is necessary as well as throwing: in production
         * wp_send_json_*() calls wp_die(), which EXITS, so a surrounding
         * `catch (\Throwable)` never sees it. In-process we can only throw,
         * and a broad catch in the handler would swallow that — turning a
         * genuine success into a false failure. The recorder captures the
         * FIRST response, which is the one production would have sent.
         */
        final class BccAjaxRecorder
        {
            /** @var list<\BccAjaxResponse> */
            public static array $responses = [];

            public static function reset(): void
            {
                self::$responses = [];
            }

            public static function first(): \BccAjaxResponse
            {
                if (self::$responses === []) {
                    throw new \RuntimeException('No wp_send_json_* response was recorded.');
                }
                return self::$responses[0];
            }
        }
    }

    if (!function_exists('wp_send_json_success')) {
        /** @param array<string, mixed> $data */
        function wp_send_json_success(array $data = []): void
        {
            $r = new \BccAjaxResponse(true, $data);
            \BccAjaxRecorder::$responses[] = $r;
            throw $r;
        }
    }

    if (!function_exists('wp_send_json_error')) {
        /** @param array<string, mixed> $data */
        function wp_send_json_error(array $data = []): void
        {
            $r = new \BccAjaxResponse(false, $data);
            \BccAjaxRecorder::$responses[] = $r;
            throw $r;
        }
    }

    if (!function_exists('check_ajax_referer')) {
        function check_ajax_referer(string $action = '-1', string $queryArg = '', bool $die = true): bool
        {
            \BccAdminTestState::$nonceChecks[] = ['action' => $action, 'arg' => $queryArg];

            if (\BccAdminTestState::$validNonceAction !== $action) {
                throw new \BccAdminDie('ajax_nonce_failed:' . $action, 403);
            }

            return true;
        }
    }

    if (!function_exists('wp_next_scheduled')) {
        /** @param mixed ...$args @return int|false */
        function wp_next_scheduled(...$args)
        {
            return $GLOBALS['bcc_test_next_scheduled'] ?? false;
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

    if (!function_exists('esc_textarea')) {
        function esc_textarea(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES);
        }
    }

    if (!function_exists('wp_json_encode')) {
        /** @param mixed $data @return string|false */
        function wp_json_encode($data)
        {
            return json_encode($data);
        }
    }

    if (!function_exists('wp_nonce_field')) {
        /**
         * Emits the nonce ACTION verbatim as a data attribute so a render
         * test can assert the exact nonce scope — the security-relevant
         * property that distinguishes a per-chain from a shared nonce.
         */
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

    if (!class_exists('BccFakeFetcher', false)) {
        /** Fake chain fetcher — never touches the network. */
        class BccFakeFetcher
        {
            public bool $supports = true;

            /** @var list<array<string, mixed>> */
            public array $validators = [['id' => 1]];

            /** @var list<array<string, mixed>> */
            public array $collections = [['id' => 1]];

            public ?string $fetchError = null;
            public ?\Throwable $throws = null;

            /** Records the limit the page asked for — pins fetch_top_collections(100). */
            public static ?int $lastCollectionsLimit = null;

            public function supports_feature(string $feature): bool
            {
                return $this->supports;
            }

            /** @return list<array<string, mixed>> */
            public function fetch_all_validators(): array
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }
                return $this->validators;
            }

            public function last_fetch_error(): ?string
            {
                return $this->fetchError;
            }

            /** @return list<array<string, mixed>> */
            public function fetch_top_collections(int $limit): array
            {
                self::$lastCollectionsLimit = $limit;
                if ($this->throws !== null) {
                    throw $this->throws;
                }
                return $this->collections;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Factories {

    if (!class_exists(FetcherFactory::class, false)) {
        final class FetcherFactory
        {
            public static bool $hasDriver = true;
            public static ?object $fetcher = null;

            /** @param mixed $chainType */
            public static function has_driver($chainType): bool
            {
                return self::$hasDriver;
            }

            /** @param mixed $chain */
            public static function make_for_chain($chain): object
            {
                return self::$fetcher ?? new \BccFakeFetcher();
            }

            public static function reset(): void
            {
                self::$hasDriver = true;
                self::$fetcher = null;
                \BccFakeFetcher::$lastCollectionsLimit = null;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(ValidatorRepository::class, false)) {
        final class ValidatorRepository
        {
            /** @var array{total: int, new: int, updated: int, unchanged: int} */
            public static array $stats = ['total' => 3, 'new' => 1, 'updated' => 2, 'unchanged' => 0];
            public static int $upsertCalls = 0;

            /**
             * @param list<array<string, mixed>> $validators
             * @return array{total: int, new: int, updated: int, unchanged: int}
             */
            public static function bulkUpsert(array $validators, int $ttl): array
            {
                self::$upsertCalls++;
                return self::$stats;
            }

            /** @return array<int, mixed> */
            public static function getCountsByChain(): array
            {
                return [];
            }

            public static function reset(): void
            {
                self::$upsertCalls = 0;
                self::$stats = ['total' => 3, 'new' => 1, 'updated' => 2, 'unchanged' => 0];
            }
        }
    }

    if (!class_exists(CollectionRepository::class, false)) {
        final class CollectionRepository
        {
            public static int $count = 7;
            public static int $upsertCalls = 0;

            /** @param list<array<string, mixed>> $collections */
            public static function bulkUpsert(array $collections, int $ttl): int
            {
                self::$upsertCalls++;
                return self::$count;
            }

            /** @return array<int, mixed> */
            public static function getCountsByChain(): array
            {
                return [];
            }

            public static function reset(): void
            {
                self::$upsertCalls = 0;
                self::$count = 7;
            }
        }
    }
}

namespace BCC\Core\Cron {

    if (!class_exists(AsyncDispatcher::class, false)) {
        final class AsyncDispatcher
        {
            /** @var list<array{hook: string, ts: int}> */
            public static array $scheduled = [];

            /** @param array<mixed> $args */
            public static function scheduleSingle(int $timestamp, string $hook, array $args = [], string $group = ''): void
            {
                self::$scheduled[] = ['hook' => $hook, 'ts' => $timestamp];
            }

            public static function reset(): void
            {
                self::$scheduled = [];
            }
        }
    }
}
