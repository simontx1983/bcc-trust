<?php
/**
 * Fixture-backed stubs for HeliusIngestUnmarkTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses),
 * so the fake FQN classes below cannot shadow the real classes elsewhere.
 *
 * Fixture shape ($GLOBALS['__bcc_helius_fixture']):
 *   ingest_throws  bool            NftHoldingsIndexer::ingest throws when true
 *   delete_result  int|null        HeliusSeenSignaturesRepository::deleteSignatures returns this
 *   seen           array<string,bool>  signatures already marked (markSeen → false replay)
 *   marked         list<string>    signatures markSeen accepted this run
 *   deleted_args   list<list<string>>  deleteSignatures call args
 *   ingest_calls   int             NftHoldingsIndexer::ingest invocation count
 *   metrics        list<array{string,string}>  DegradationMetrics::record calls
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(HeliusSeenSignaturesRepository::class, false)) {
        class HeliusSeenSignaturesRepository
        {
            public static function markSeen(string $signature): bool
            {
                $f = &$GLOBALS['__bcc_helius_fixture'];
                if (!empty($f['seen'][$signature])) {
                    return false; // replay
                }
                $f['seen'][$signature] = true;
                $f['marked'][]         = $signature;
                return true;
            }

            /** @param list<string> $signatures */
            public static function deleteSignatures(array $signatures): ?int
            {
                $GLOBALS['__bcc_helius_fixture']['deleted_args'][] = $signatures;
                return $GLOBALS['__bcc_helius_fixture']['delete_result'];
            }
        }
    }

    if (!class_exists(ChainRepository::class, false)) {
        class ChainRepository
        {
            public static function getBySlug(string $slug): ?object
            {
                return (object) ['id' => 1, 'chain_type' => 'solana'];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!class_exists(NftHoldingsIndexer::class, false)) {
        class NftHoldingsIndexer
        {
            /** @param list<mixed> $events */
            public static function ingest(int $chainId, array $events): void
            {
                $GLOBALS['__bcc_helius_fixture']['ingest_calls']++;
                if (!empty($GLOBALS['__bcc_helius_fixture']['ingest_throws'])) {
                    throw new \RuntimeException('simulated ingest failure');
                }
            }
        }
    }

    if (!class_exists(HeliusDeliveryLog::class, false)) {
        class HeliusDeliveryLog
        {
            public const OUTCOME_PROCESSED   = 'processed';
            public const OUTCOME_AUTH_FAILED = 'auth_failed';
            public const OUTCOME_NO_PAYLOAD  = 'no_payload';
            public const OUTCOME_NO_CHAIN    = 'no_chain';

            /** @param array<string, mixed> $data */
            public static function record(array $data): void
            {
            }
        }
    }
}

namespace BCC\Trust\Onchain\Factories {
    if (!class_exists(FetcherFactory::class, false)) {
        class FetcherFactory
        {
            public static function has_driver(string $chainType): bool
            {
                return true;
            }

            public static function make_for_chain(object $chain): object
            {
                return new \BCC\Trust\Onchain\Fetchers\SolanaFetcher();
            }
        }
    }
}

namespace BCC\Trust\Onchain\Fetchers {
    if (!class_exists(SolanaFetcher::class, false)) {
        class SolanaFetcher
        {
            /**
             * @param array<string, mixed> $tx
             * @return list<array<string, mixed>>
             */
            public function normalizeWebhookPayload(array $tx, int $chainId): array
            {
                // One synthetic event per transaction so $allEvents is non-empty.
                return [['sig' => $tx['signature'] ?? '', 'chain' => $chainId]];
            }
        }
    }
}

namespace BCC\Trust\Core\Security {
    if (!class_exists(IpResolver::class, false)) {
        class IpResolver
        {
            public static function getClientIp(): string
            {
                return '198.51.100.7';
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        class Logger
        {
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
            }

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void
            {
            }
        }
    }
}

namespace BCC\Core\Observability {
    if (!class_exists(DegradationMetrics::class, false)) {
        class DegradationMetrics
        {
            public static function record(string $subsystem, string $event): void
            {
                $GLOBALS['__bcc_helius_fixture']['metrics'][] = [$subsystem, $event];
            }
        }
    }
}

namespace {
    // Minimal WP surface used by handle().
    if (!function_exists('update_option')) {
        function update_option(string $name, $value, $autoload = null): bool { return true; }
    }
    if (!function_exists('get_option')) {
        function get_option(string $name, $default = false) { return $default; }
    }
    if (!function_exists('get_transient')) {
        function get_transient(string $key) { return false; }
    }
    if (!function_exists('set_transient')) {
        function set_transient(string $key, $value, int $exp = 0): bool { return true; }
    }
    if (!function_exists('array_is_list')) {
        function array_is_list(array $a): bool { return $a === [] || array_keys($a) === range(0, count($a) - 1); }
    }

    if (!class_exists('WP_REST_Server')) {
        class WP_REST_Server
        {
            public const CREATABLE = 'POST';
        }
    }

    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            /** @param array<string, mixed> $json */
            public function __construct(private string $auth = '', private array $json = [])
            {
            }

            public function get_header(string $key): string
            {
                return strtolower($key) === 'authorization' ? $this->auth : '';
            }

            /** @return array<int|string, mixed> */
            public function get_json_params(): array
            {
                return $this->json;
            }
        }
    }

    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            /** @var mixed */
            public $data;
            public int $status;

            /** @param mixed $data */
            public function __construct($data = null, int $status = 200)
            {
                $this->data   = $data;
                $this->status = $status;
            }

            public function get_status(): int
            {
                return $this->status;
            }

            public function header(string $key, string $value, bool $replace = true): void
            {
            }
        }
    }
}
