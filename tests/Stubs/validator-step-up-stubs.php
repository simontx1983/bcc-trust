<?php

declare(strict_types=1);

/**
 * ValidatorStepUpServiceTest: recording fakes for the step-up service's
 * collaborators. Load only in a separate process, from setUp(), BEFORE the
 * service is autoloaded. Never at a test file's top level (that leaks into
 * the whole unit run).
 *
 * State lives in StepUpWorld.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }
    if (!defined('BCC_ENCRYPTION_KEY')) {
        define('BCC_ENCRYPTION_KEY', 'step-up-test-key-not-a-secret');
    }

    final class StepUpWorld
    {
        public static bool $mailAccepted = true;
        public static ?bool $hasPassword = true;
        public static string $credential = 'password';
        /** @var array<string, bool> limiter key prefix => allowed */
        public static array $limits = [];
        /** @var array<string, object> id => row */
        public static array $rows = [];
        /** @var list<array{string, string, string}> [to, code, label] */
        public static array $mails = [];
        /** @var list<array<string, mixed>> */
        public static array $logRows = [];
        /** @var list<string> */
        public static array $audits = [];
        public static string $now = '';
        public static ?\Closure $beforeMarkCodeVerified = null;

        public static function reset(): void
        {
            self::$mailAccepted = true;
            self::$hasPassword = true;
            self::$credential  = 'password';
            self::$limits      = [];
            self::$rows        = [];
            self::$mails       = [];
            self::$logRows     = [];
            self::$audits      = [];
            self::$beforeMarkCodeVerified = null;
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

    if (!class_exists('WP_User', false)) {
        class WP_User
        {
            public string $user_email = 'staff@example.org';
        }
    }

    if (!function_exists('get_userdata')) {
        function get_userdata(int $id): \WP_User|false
        {
            return $id > 0 ? new \WP_User() : false;
        }
    }
}

namespace BCC\Trust\Core\REST {
    if (!class_exists(__NAMESPACE__ . '\\AccountCredentialCheck', false)) {
        final class AccountCredentialCheck
        {
            public static function confirm(\WP_REST_Request $request, int $userId, string $purpose): string
            {
                return \StepUpWorld::$credential;
            }
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!class_exists(__NAMESPACE__ . '\\AccountRecoveryService', false)) {
        final class AccountRecoveryService
        {
            public static function hasPassword(int $userId): ?bool
            {
                return \StepUpWorld::$hasPassword;
            }
        }
    }
    if (!class_exists(__NAMESPACE__ . '\\AuthMailer', false)) {
        final class AuthMailer
        {
            public static function sendValidatorStepUpCode(string $to, string $code, string $label): bool
            {
                \StepUpWorld::$mails[] = [$to, $code, $label];
                return \StepUpWorld::$mailAccepted;
            }
        }
    }
}

namespace BCC\Trust\Core\Security {
    if (!class_exists(__NAMESPACE__ . '\\RateLimiter', false)) {
        final class RateLimiter
        {
            public static function allowByKey(string $key, int $limit, int $window, bool $countDenied = true): bool
            {
                foreach (\StepUpWorld::$limits as $prefix => $allowed) {
                    if (str_starts_with($key, $prefix)) {
                        return $allowed;
                    }
                }
                return true;
            }

            public static function retryAfterByKey(string $key, int $limit, int $window): int
            {
                return 120;
            }
        }
    }
    if (!class_exists(__NAMESPACE__ . '\\AuditLogger', false)) {
        final class AuditLogger
        {
            /** @param array<string, mixed> $meta */
            public static function log(string $action, ?int $targetId = null, array $meta = [], ?string $targetType = null, ?int $userId = null): void
            {
                \StepUpWorld::$audits[] = $action . ':' . (string) $userId;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!class_exists(__NAMESPACE__ . '\\RelationshipLogWriter', false)) {
        final class RelationshipLogWriter
        {
            /** @param array<string, mixed> $event */
            public static function appendStandalone(array $event): bool
            {
                \StepUpWorld::$logRows[] = $event;
                return true;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\ValidatorStepUpRepository', false)) {
        /** In-memory twin with the SQL's conditions (the SQL itself is proven on MySQL). */
        final class ValidatorStepUpRepository
        {
            public const MAX_ATTEMPTS = 5;
            public const MAX_LIFETIME_SECONDS = 1800;
            public const CODE_REQUIRED_ACTIONS = ['link_owner', 'revoke_owner', 'cancel_link'];

            /** @param array<string, mixed> $row */
            public static function create(array $row): void
            {
                \StepUpWorld::$rows[$row['id']] = (object) ($row + ['attempts' => 0, 'used_at' => null, 'credential_verified' => 1, 'code_verified_at' => null]);
            }

            public static function find(string $id, int $userId): ?object
            {
                $row = \StepUpWorld::$rows[$id] ?? null;
                return $row !== null && (int) $row->user_id === $userId ? $row : null;
            }

            public static function countAttempt(string $id, int $userId, string $now): bool
            {
                $row = self::find($id, $userId);
                if ($row === null || $row->used_at !== null || strcmp((string) $row->expires_at, $now) <= 0 || $row->attempts >= self::MAX_ATTEMPTS) {
                    return false;
                }
                $row->attempts++;
                return true;
            }

            public static function markCodeVerified(string $id, int $userId, string $now, string $expectedCodeHash): bool
            {
                $before = \StepUpWorld::$beforeMarkCodeVerified;
                \StepUpWorld::$beforeMarkCodeVerified = null;
                if ($before !== null) {
                    $before($id);
                }
                $row = self::find($id, $userId);
                if ($row === null || $row->code_hash !== $expectedCodeHash || $row->code_verified_at !== null || $row->used_at !== null
                    || strcmp((string) $row->expires_at, $now) <= 0 || $row->attempts > self::MAX_ATTEMPTS
                ) {
                    return false;
                }
                $row->code_verified_at = $now;
                return true;
            }

            /** @param array<string, mixed> $b */
            public static function consume(array $b, string $now): bool
            {
                $row = self::find((string) $b['id'], (int) $b['user_id']);
                if ($row === null || $row->used_at !== null || strcmp((string) $row->expires_at, $now) <= 0
                    || ($row->code_hash !== null && $row->code_verified_at === null)
                    || ($row->code_hash === null && in_array($row->action, self::CODE_REQUIRED_ACTIONS, true))
                    || $row->action !== $b['action'] || (int) $row->page_id !== $b['page_id']
                    || (int) $row->target_user_id !== $b['target_user_id'] || $row->role !== $b['role']
                ) {
                    return false;
                }
                $row->used_at = $now;
                return true;
            }

            public static function replaceCode(string $id, int $userId, string $hash, string $expires, string $resendAfter, string $now): bool
            {
                $row = self::find($id, $userId);
                if ($row === null || $row->code_hash === null || ($row->resend_after !== null && strcmp((string) $row->resend_after, $now) > 0)) {
                    return false;
                }
                $cap                   = gmdate('Y-m-d H:i:s', (int) strtotime((string) $row->created_at . ' UTC') + self::MAX_LIFETIME_SECONDS);
                $row->code_hash        = $hash;
                $row->code_verified_at = null;
                $row->expires_at       = strcmp($expires, $cap) < 0 ? $expires : $cap;
                $row->resend_after     = $resendAfter;
                return true;
            }

            public static function purgeExpired(string $before): int
            {
                return 0;
            }
        }
    }
}
