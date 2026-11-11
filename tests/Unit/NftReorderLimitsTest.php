<?php

declare(strict_types=1);

// ── Stubs at production FQNs (guarded — first wins) ─────────────────────────
namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    if (!class_exists('WP_REST_Request', false)) {
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

    if (!class_exists('WP_REST_Response', false)) {
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

            /** @return mixed */
            public function get_data()
            {
                return $this->data;
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

    $GLOBALS['__f30'] = ['throttled' => false, 'throttle_keys' => [], 'reorder_calls' => []];
}

namespace BCC\Trust\Onchain\Controllers {
    if (!function_exists(__NAMESPACE__ . '\\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return 7;
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(__NAMESPACE__ . '\\Throttle', false)) {
        final class Throttle
        {
            public static function allow(string $action, int $limit = 10, int $window = 60, ?string $key = null): bool
            {
                $GLOBALS['__f30']['throttle_keys'][] = $action;
                return !$GLOBALS['__f30']['throttled'];
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    // ApiResponse::ok() reads this; keep it DB-free.
    if (!class_exists(__NAMESPACE__ . '\\ReactionTypeRegistry', false)) {
        class ReactionTypeRegistry
        {
            /** @return array<string, int|null> */
            public static function all(): array
            {
                return [];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    // Records what the handler would write, without a DB.
    if (!class_exists(__NAMESPACE__ . '\\NftSelectionService', false)) {
        final class NftSelectionService
        {
            /** @param list<int> $orderedIds */
            public static function reorder(int $userId, array $orderedIds): int
            {
                $GLOBALS['__f30']['reorder_calls'][] = [$userId, $orderedIds];
                return count($orderedIds);
            }
        }
    }
}

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Trust\Onchain\Controllers\NftSelectionController;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use WP_REST_Request;
    use WP_REST_Response;

    /**
     * F-30 — POST /nft-selections/reorder had no size or rate limit: the
     * repository runs one UPDATE per id, so one request with a huge
     * ordered_ids array meant that many writes. The handler now throttles
     * per user ('nft_reorder', like its siblings) and rejects a list longer
     * than REORDER_MAX_IDS with bcc_invalid_request 422 — both BEFORE the
     * service (and therefore any write) is reached.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class NftReorderLimitsTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['__f30'] = ['throttled' => false, 'throttle_keys' => [], 'reorder_calls' => []];
        }

        /** @param list<mixed> $ids */
        private function call(array $ids): WP_REST_Response
        {
            return NftSelectionController::rest_reorder(new WP_REST_Request(['ordered_ids' => $ids]));
        }

        /** @return array<string, mixed> */
        private function body(WP_REST_Response $r): array
        {
            $data = $r->get_data();
            return is_array($data) ? $data : [];
        }

        public function testAListAtTheCapIsAccepted(): void
        {
            $ids = range(1, NftSelectionController::REORDER_MAX_IDS);

            $r = $this->call($ids);

            self::assertSame(200, $r->get_status());
            self::assertCount(1, $GLOBALS['__f30']['reorder_calls']);
            self::assertSame($ids, $GLOBALS['__f30']['reorder_calls'][0][1]);
        }

        public function testAListOverTheCapIs422AndWritesNothing(): void
        {
            $r = $this->call(range(1, NftSelectionController::REORDER_MAX_IDS + 1));

            self::assertSame(422, $r->get_status());
            self::assertSame('bcc_invalid_request', $this->body($r)['error']['code'] ?? null);
            self::assertSame([], $GLOBALS['__f30']['reorder_calls'], 'an oversize list must not reach the write');
        }

        public function testAHugeListIsRejectedWithoutTouchingTheService(): void
        {
            $r = $this->call(range(1, 150000));

            self::assertSame(422, $r->get_status());
            self::assertSame([], $GLOBALS['__f30']['reorder_calls']);
        }

        public function testAThrottledCallerIs429AndWritesNothing(): void
        {
            $GLOBALS['__f30']['throttled'] = true;

            $r = $this->call([3, 1, 2]);

            self::assertSame(429, $r->get_status());
            self::assertSame('bcc_rate_limited', $this->body($r)['error']['code'] ?? null);
            self::assertSame([], $GLOBALS['__f30']['reorder_calls']);
        }

        public function testTheThrottleUsesTheReorderBucket(): void
        {
            $this->call([1]);

            self::assertSame(['nft_reorder'], $GLOBALS['__f30']['throttle_keys']);
        }
    }
}
