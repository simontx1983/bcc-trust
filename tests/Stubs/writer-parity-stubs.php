<?php
/**
 * Stubs for WriterVisibilityParityTest — proves each of the three create
 * paths (status / photo / gif) threads `$visibility` into the shared
 * `gateGroupPost` and that the PeepSo writer is (or isn't) invoked.
 *
 * Builds on public-all-stubs.php (fixture + resolver WP fns +
 * PeepSoGroupRepository + ReputationRepository) and adds:
 *   - a Plugin singleton double whose groupsService() returns the REAL
 *     GroupsService (so the gate runs real authz off the fixture);
 *   - empty doubles for PostsService's constructor deps (never called on
 *     the create-status/photo/gif paths);
 *   - Throttle (allow) + do_action/apply_filters + Logger + rate consts;
 *   - the three PeepSo writer statics as SPIES: they record every call in
 *     $GLOBALS['__bcc_puball_fixture']['writer_calls'][kind] and return a
 *     success envelope, so a test can assert "writer not invoked" (reject)
 *     vs "writer invoked once with the expected visibility" (proceed).
 *
 * Subprocess-only. All definitions class_exists/function_exists-guarded.
 */

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/public-all-stubs.php';

    if (!defined('BCC_TRUST_RATE_LIMIT_STATUS_POST')) {
        define('BCC_TRUST_RATE_LIMIT_STATUS_POST', 5);
    }
    if (!defined('BCC_TRUST_RATE_WINDOW_STATUS_POST')) {
        define('BCC_TRUST_RATE_WINDOW_STATUS_POST', 120);
    }

    /** Record a writer invocation for later assertion. */
    function bcc_puball_record_writer(string $kind, int $authorId, int $groupId, string $visibility): void
    {
        $GLOBALS['__bcc_puball_fixture']['writer_calls'][$kind][] = [
            'author_id'  => $authorId,
            'group_id'   => $groupId,
            'visibility' => $visibility,
        ];
    }
}

namespace BCC\Trust\Core {
    if (!class_exists(Plugin::class, false)) {
        final class Plugin
        {
            private static ?Plugin $inst = null;
            private ?\BCC\Trust\Core\Services\GroupsService $gs = null;

            public static function instance(): self
            {
                return self::$inst ??= new self();
            }

            public function groupsService(): \BCC\Trust\Core\Services\GroupsService
            {
                return $this->gs ??= new \BCC\Trust\Core\Services\GroupsService(
                    new \BCC\Trust\Core\Repositories\ReputationRepository(),
                    new \BCC\Trust\Core\Services\GroupContextResolver()
                );
            }
        }
    }
}

namespace BCC\Trust\Core\Services {
    // PostsService ctor deps — never touched on the create paths under test.
    if (!class_exists(VoteService::class, false)) {
        final class VoteService
        {
            public function __construct()
            {
            }
        }
    }
    if (!class_exists(FeatureAccessService::class, false)) {
        final class FeatureAccessService
        {
            public function __construct()
            {
            }
        }
    }

    if (!function_exists('BCC\\Trust\\Core\\Services\\do_action')) {
        function do_action($hook, ...$args): void
        {
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Services\\apply_filters')) {
        /** @return mixed */
        function apply_filters($hook, $value, ...$args)
        {
            return $value;
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(VoteRepository::class, false)) {
        final class VoteRepository
        {
            public function __construct()
            {
            }
        }
    }
    if (!class_exists(BlogChainTagRepository::class, false)) {
        final class BlogChainTagRepository
        {
            public function __construct()
            {
            }
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        class Throttle
        {
            public static function allow(string $key, int $limit, int $window, ?string $bucketKey = null): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        class Logger
        {
            /** @param array<string,mixed> $c */
            public static function info(string $m, array $c = []): void
            {
            }
            /** @param array<string,mixed> $c */
            public static function error(string $m, array $c = []): void
            {
            }
            /** @param array<string,mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoStatusWriter::class, false)) {
        class PeepSoStatusWriter
        {
            /** @return array<string, mixed> */
            public static function createSelfStatus(int $authorId, string $content, int $groupId = 0, string $visibility = 'members_only'): array
            {
                \bcc_puball_record_writer('status', $authorId, $groupId, $visibility);
                return ['ok' => true, 'post_id' => 101, 'act_id' => 202];
            }
        }
    }
    if (!class_exists(PeepSoPhotoWriter::class, false)) {
        class PeepSoPhotoWriter
        {
            /**
             * @param array<string, mixed> $file
             * @return array<string, mixed>
             */
            public static function createSelfPhotoPost(int $authorId, array $file, string $caption, int $groupId = 0, string $visibility = 'members_only'): array
            {
                \bcc_puball_record_writer('photo', $authorId, $groupId, $visibility);
                return ['ok' => true, 'post_id' => 101, 'act_id' => 202, 'photo_id' => 303];
            }
        }
    }
    if (!class_exists(PeepSoGifWriter::class, false)) {
        class PeepSoGifWriter
        {
            /** @return array<string, mixed> */
            public static function createSelfGifPost(int $authorId, string $url, string $caption, int $groupId = 0, string $visibility = 'members_only'): array
            {
                \bcc_puball_record_writer('gif', $authorId, $groupId, $visibility);
                return ['ok' => true, 'post_id' => 101, 'act_id' => 202];
            }
        }
    }
}
