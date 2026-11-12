<?php
/**
 * Stubs for NftPieceRouteGateTest (subprocess-only).
 *
 * Doubles the collaborators NftPieceEndpoint / NftPieceViewModelBuilder
 * reach at their REAL FQCNs, so the production classes run unchanged.
 * Everything that would touch $wpdb or an upstream provider records its
 * call instead, which lets the tests prove what was NOT reached.
 *
 * Fixture ($GLOBALS['__bcc_np']):
 *   throttle_denied  bool                      Throttle::allow() answers !this
 *   throttle_calls   list<string>              actions passed to Throttle::allow
 *   throttle_keys    list<string>              "action|key" per call ("" key = per-caller default)
 *   throttle_deny    list<string>              "action|key" entries to refuse (F-57)
 *   fetcher_cosmos   bool                      FetcherFactory returns an UNCONSTRUCTED CosmosFetcher
 *   fetcher_object   bool                      FetcherFactory returns a plain object (no driver)
 *                                              instead of throwing — the builder then answers
 *                                              "no metadata" without any real provider call
 *   chains           array<string, object>     slug → chain row
 *   collections      array<string, object>     "chainId|contract" → collection row
 *   collection_calls list<string>              "chainId|contract" lookups
 *   fetcher_calls    int                       FetcherFactory::make_for_chain calls
 *   pieces           array<string, object>     "chainId|contract|token" → cached piece row
 */

declare(strict_types=1);

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        final class Throttle
        {
            public static function allow(string $action, int $limit = 10, int $window = 60, ?string $key = null): bool
            {
                $GLOBALS['__bcc_np']['throttle_calls'][] = $action;
                $entry = $action . '|' . ($key ?? '');
                $GLOBALS['__bcc_np']['throttle_keys'][] = $entry;
                if (!empty($GLOBALS['__bcc_np']['throttle_denied'])) {
                    return false;
                }
                return !in_array($entry, $GLOBALS['__bcc_np']['throttle_deny'] ?? [], true);
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    final class ChainRepository
    {
        public static function getBySlug(string $slug): ?object
        {
            return $GLOBALS['__bcc_np']['chains'][$slug] ?? null;
        }
    }

    final class CollectionRepository
    {
        public static function findByChainContract(int $chainId, string $contract): ?object
        {
            $key = $chainId . '|' . $contract;
            $GLOBALS['__bcc_np']['collection_calls'][] = $key;
            return $GLOBALS['__bcc_np']['collections'][$key] ?? null;
        }
    }

    final class NftCollectionPiecesRepository
    {
        public static function findByTriple(int $chainId, string $contract, string $tokenId): ?object
        {
            return $GLOBALS['__bcc_np']['pieces'][$chainId . '|' . $contract . '|' . $tokenId] ?? null;
        }
    }

    final class NftHoldingsRepository
    {
        /** @return list<object> */
        public static function findOwnersByTokenId(int $chainId, string $contract, string $tokenId, int $limit): array
        {
            return [];
        }
    }
}

namespace BCC\Trust\Onchain\Factories {
    final class FetcherFactory
    {
        public static function make_for_chain(object $chain): object
        {
            $GLOBALS['__bcc_np']['fetcher_calls'] = ($GLOBALS['__bcc_np']['fetcher_calls'] ?? 0) + 1;
            if (!empty($GLOBALS['__bcc_np']['fetcher_cosmos'])) {
                // A real CosmosFetcher type with NO constructor run: it passes the
                // builder's instanceof check, and any method call would fail loudly,
                // so a test using it proves the refusal came before any LCD query.
                return (new \ReflectionClass(\BCC\Trust\Onchain\Fetchers\CosmosFetcher::class))->newInstanceWithoutConstructor();
            }
            if (!empty($GLOBALS['__bcc_np']['fetcher_object'])) {
                return new \stdClass();
            }
            throw new \RuntimeException('upstream fetcher must not be reached in this test');
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    final class HoldingsService
    {
        /** @return array{indexer_state: array<string, string>, indexer_state_label: array<string, string>} */
        public static function getIndexerStateForChain(string $chainSlug): array
        {
            return ['indexer_state' => [], 'indexer_state_label' => []];
        }
    }
}

namespace {
    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            /** @var mixed */
            public $data;
            public int $status;
            /** @var array<string, string> */
            public array $headers = [];

            /** @param mixed $data */
            public function __construct($data = null, int $status = 200)
            {
                $this->data   = $data;
                $this->status = $status;
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
        }
    }

    if (!function_exists('get_option')) {
        /**
         * ApiResponse::ok() reads an option for its envelope meta. Always
         * the caller's default here.
         *
         * @return mixed
         */
        function get_option($option, $default = false)
        {
            return $default;
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
}
