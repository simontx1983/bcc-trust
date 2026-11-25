<?php
/**
 * Stubs for DisputeCreateFailureMappingTest ONLY: the `WP_REST_Response`
 * that ApiResponse::error() builds.
 *
 * ⚠⚠ SUBPROCESS-ONLY, required from setUp(): a class declaration cannot be
 * undone, so the consuming test is `#[RunTestsInSeparateProcesses]` +
 * `#[PreserveGlobalState(false)]`.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_REST_Response', false)) {
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

            public function header(string $key, string $value, bool $replace = true): void
            {
                unset($replace);
                $this->headers[$key] = $value;
            }
        }
    }
}
