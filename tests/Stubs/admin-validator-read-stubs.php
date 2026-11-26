<?php

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
}

namespace BCC\Trust\Core\Services {
    function get_avatar_url(int $id): string { return 'https://example.test/avatar/' . $id; }
}

namespace BCC\Trust\Onchain\Repositories {
    final class AdminValidatorRepository
    {
        public static array $bindings = [];
        public static array $claims = [];
        public static array $users = [];
        public static array $calls = [];
        public static ?array $health = null;
        public static ?array $event = null;
        public static ?string $fail = null;
        private static function read(string $method, array $args = []): void {
            self::$calls[] = [$method, $args];
            if (self::$fail === $method) { throw new RepositoryReadFailure(self::class, $method, 'unavailable'); }
        }
        public static function bindings(?int $pageId = null): array {
            self::read(__FUNCTION__, [$pageId]);
            return array_values(array_filter(self::$bindings, static fn (array $row): bool => $pageId === null || $row['page_id'] === $pageId));
        }
        public static function claims(array $ids): array { self::read(__FUNCTION__, $ids); return array_intersect_key(self::$claims, array_flip($ids)); }
        public static function users(array $ids): array { self::read(__FUNCTION__, $ids); return array_intersect_key(self::$users, array_flip($ids)); }
        public static function linkEvent(int $page, int $user, int $validator): ?array { self::read(__FUNCTION__, [$page, $user, $validator]); return self::$event; }
        public static function logHealth(): ?array { self::read(__FUNCTION__); return self::$health; }
        public static function searchUserIds(string $query): array { self::read(__FUNCTION__, [$query]); return array_keys(self::$users); }
        public static function pageTitles(array $ids): array { self::read(__FUNCTION__, $ids); return []; }
    }
    final class ClaimRepository
    {
        public static array $owners = [];
        public static bool $fail = false;
        public static function ownerClaimsForPagesStrict(array $ids): array {
            if (self::$fail) { throw new RepositoryReadFailure(self::class, __FUNCTION__, 'unavailable'); }
            return array_intersect_key(self::$owners, array_flip($ids));
        }
        public static function ownerClaimsForPageStrict(int $id, bool $observe = true): array { return self::ownerClaimsForPagesStrict([$id])[$id] ?? []; }
    }
    final class ValidatorRelationshipLogRepository { public static function history(?int $page, ?int $user, ?int $before, int $limit): array { return []; } }
}

namespace BCC\Trust\Onchain\Services {
    final class ValidatorClaimSupport { public static function assess(string ...$args): array { return ['claimable' => true]; } }
    final class ValidatorRelationshipService {
        public const STAFF_REVOKE_REASONS = ['linked_in_error', 'evidence_withdrawn', 'owner_requested', 'ownership_disputed'];
        public static function lockedForStaff(string $method): bool { return $method !== 'staff'; }
    }
    final class RelationshipLogHealthCheck { public static function run(): never { throw new \LogicException('GET must not run health verifier'); } }
}
