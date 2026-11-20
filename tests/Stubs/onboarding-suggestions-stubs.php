<?php
/**
 * Stubs for OnboardingSuggestionsChainBiasTest (F-439). Load ONLY from a
 * #[RunTestsInSeparateProcesses] class: this declares a fake
 * BCC\Trust\Core\Plugin and card prefetcher, and a class declaration cannot
 * be undone (same pattern as watching-profile-gate-stubs.php).
 *
 *   Plugin::$discovery  the PageDiscoveryService the endpoint gets (a test
 *                       sets a scripted subclass)
 *   Plugin::$cardCalls  every getPageCardForList() call, as [kind, pageId]
 *   Plugin::$noCard     page ids that build no card for the viewer (null)
 *   PageCardPrefetcher::$primed  every primeFor() id list
 *
 * Cards come back as ['card_kind' => kind, 'page_id' => id], so a test reads
 * a bucket's page ids straight off the response.
 */

declare(strict_types=1);

namespace BCC\Trust\Core {
    if (!class_exists(Plugin::class, false)) {
        final class Plugin
        {
            public static ?Services\PageDiscoveryService $discovery = null;
            /** @var list<array{0: string, 1: int}> */
            public static array $cardCalls = [];
            /** @var list<int> */
            public static array $noCard = [];

            public static function instance(): self
            {
                return new self();
            }

            public function pageDiscoveryService(): Services\PageDiscoveryService
            {
                if (self::$discovery === null) {
                    throw new \LogicException('test did not set Plugin::$discovery');
                }
                return self::$discovery;
            }

            public function cardViewService(): object
            {
                return new class {
                    /** @return array{card_kind: string, page_id: int}|null */
                    public function getPageCardForList(string $kind, int $pageId, int $viewerId, mixed $bundle): ?array
                    {
                        Plugin::$cardCalls[] = [$kind, $pageId];
                        if (in_array($pageId, Plugin::$noCard, true)) {
                            return null;
                        }
                        return ['card_kind' => $kind, 'page_id' => $pageId];
                    }
                };
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    if (!class_exists(PageCardPrefetcher::class, false)) {
        final class PageCardPrefetcher
        {
            /** @var list<list<int>> */
            public static array $primed = [];

            /**
             * @param list<int> $pageIds
             * @return array<string, mixed>
             */
            public static function primeFor(array $pageIds, int $viewerId): array
            {
                self::$primed[] = $pageIds;
                return [];
            }
        }
    }

    if (!class_exists(ReactionTypeRegistry::class, false)) {
        final class ReactionTypeRegistry
        {
            /** @return list<array<string, mixed>> */
            public static function all(): array
            {
                return [];
            }
        }
    }
}

namespace {
    if (!class_exists('WP_REST_Server')) {
        class WP_REST_Server
        {
            public const READABLE  = 'GET';
            public const CREATABLE = 'POST';
            public const EDITABLE  = 'POST, PUT, PATCH';
        }
    }

    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            /** @param array<string, mixed> $params */
            public function __construct(private array $params = [])
            {
            }

            /** @return mixed */
            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }
        }
    }

    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            /** @var array<string, string> */
            private array $headers = [];

            /** @param mixed $data */
            public function __construct(private $data = null, private int $status = 200)
            {
            }

            /** @return mixed */
            public function get_data()
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }

            public function header(string $key, string $value): void
            {
                $this->headers[$key] = $value;
            }

            /** @return array<string, string> */
            public function get_headers(): array
            {
                return $this->headers;
            }
        }
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_current_user'] ?? 0);
        }
    }
}
