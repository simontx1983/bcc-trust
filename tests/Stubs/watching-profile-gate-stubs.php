<?php
/**
 * Doubles for WatchingProfileGateTest (§F-02 on the watch surfaces).
 *
 * ⚠⚠ SUBPROCESS-ONLY, required from the test's setUp(), never at file level:
 * it declares a fake BCC\Trust\Core\Plugin, RateLimiter and card
 * prefetchers, and a class declaration cannot be undone. Loaded in the
 * parent process they would replace the real classes for the whole unit run.
 *
 * Plugin::$watchCalls       every WatchingService::watch() call, as [viewer, kind, id]
 * Plugin::$cardViewResolved how often the card builder was resolved
 * MemberCardPrefetcher::$primed every member id list a card batch was primed for
 */

declare(strict_types=1);

namespace BCC\Trust\Core {
    if (!class_exists(Plugin::class, false)) {
        /** The two services the watching code resolves through the container. */
        final class Plugin
        {
            /** @var list<array{int, string, int}> */
            public static array $watchCalls = [];
            public static int $cardViewResolved = 0;

            public static function instance(): self
            {
                return new self();
            }

            public function watchingService(): object
            {
                return new class {
                    /** @return array{status: string, item: array<string, mixed>} */
                    public function watch(int $viewerId, string $targetKind, int $targetId): array
                    {
                        Plugin::$watchCalls[] = [$viewerId, $targetKind, $targetId];

                        return ['status' => 'watched', 'item' => ['card_id' => $targetId]];
                    }
                };
            }

            public function cardViewService(): object
            {
                self::$cardViewResolved++;

                return new class {
                    /** @return array{kind: string, id: int} */
                    public function getMemberCardForList(int $userId, int $viewerId, mixed $bundle): array
                    {
                        return ['kind' => 'member', 'id' => $userId];
                    }

                    /** @return array{kind: string, id: int} */
                    public function getPageCardForList(string $kind, int $pageId, int $viewerId, mixed $bundle): array
                    {
                        return ['kind' => $kind, 'id' => $pageId];
                    }
                };
            }
        }
    }
}

namespace BCC\Trust\Core\Security {
    if (!class_exists(RateLimiter::class, false)) {
        final class RateLimiter
        {
            public static function allow(string $action): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    if (!class_exists(MemberCardPrefetcher::class, false)) {
        final class MemberCardPrefetcher
        {
            /** @var list<list<int>> */
            public static array $primed = [];

            /**
             * @param list<int> $userIds
             * @return array<string, mixed>
             */
            public static function primeFor(array $userIds, int $viewerId): array
            {
                self::$primed[] = $userIds;

                return [];
            }
        }
    }

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
        /** ApiResponse::ok() reads it; no reaction types here. */
        final class ReactionTypeRegistry
        {
            /** @return array<string, int|null> */
            public static function all(): array
            {
                return [];
            }
        }
    }
}

namespace {
    // WatchingEndpoint asks the GLOBAL function (profile-access-stubs shadows
    // it only inside BCC\Trust\Core\Support); both read the same fixture.
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_profile_access']['viewer_id'] ?? 0);
        }
    }

    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            /** @param array<string, mixed> $params */
            public function __construct(private array $params = [])
            {
            }

            public function get_param(string $key): mixed
            {
                return $this->params[$key] ?? null;
            }
        }
    }
}
