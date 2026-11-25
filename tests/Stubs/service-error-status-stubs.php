<?php
/**
 * Doubles for ServiceErrorStatusTest: just enough WordPress and BCC for the
 * REAL BlogCoverImageEndpoint::upload() to reach its service-error branch.
 *
 *   - WP_REST_Request carries only the file params the handler reads.
 *   - WP_REST_Response holds data, status and headers.
 *   - get_current_user_id() answers $GLOBALS['__bcc_ses']['user'].
 *   - Plugin::instance()->blogCoverImageWriter() is a writer that answers
 *     $GLOBALS['__bcc_ses']['result'] and records each call.
 *
 * Load from setUp() of a #[RunTestsInSeparateProcesses] test only: the
 * Plugin double would shadow the real class for every later test.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            /** @param array<string, mixed> $files */
            public function __construct(private array $files = [])
            {
            }

            /** @return array<string, mixed> */
            public function get_file_params(): array
            {
                return $this->files;
            }
        }
    }

    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            /** @var array<string, string> */
            private array $headers = [];

            public function __construct(private mixed $data = null, private int $status = 200)
            {
            }

            public function get_data(): mixed
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

    if (!class_exists('WP_REST_Server', false)) {
        class WP_REST_Server
        {
            public const CREATABLE = 'POST';
        }
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_ses']['user'] ?? 0);
        }
    }
}

namespace BCC\Trust\Core {
    if (!class_exists(__NAMESPACE__ . '\\Plugin', false)) {
        final class Plugin
        {
            private static ?self $instance = null;

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            public function blogCoverImageWriter(): ServiceErrorStubCoverWriter
            {
                return new ServiceErrorStubCoverWriter();
            }
        }

        final class ServiceErrorStubCoverWriter
        {
            /**
             * @param array<string, mixed> $file
             * @return array<string, mixed>
             */
            public function upload(int $authorId, array $file): array
            {
                $GLOBALS['__bcc_ses']['calls'][] = [$authorId, $file['name'] ?? null];
                /** @var array<string, mixed> */
                return $GLOBALS['__bcc_ses']['result'];
            }
        }
    }
}
