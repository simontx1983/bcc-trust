<?php
/**
 * Stubs for MentionAudienceTest. Loaded ONLY inside a PHPUnit subprocess
 * (RunTestsInSeparateProcesses), so nothing here leaks into other suites.
 *
 * Real code under test: NotificationDispatcher::dispatchMentionsFor() and
 * onMentioned(), MentionExtractor, MentionPolicy, NotificationPrefs and
 * PushDispatcher::enqueue(). Doubles, in $GLOBALS['__bcc_mna']:
 *   meta           array<int, array<string, string>>  user meta (bell/push prefs, handles)
 *   unmentionable  list<int>   ids PeepSoUserSearch filters out (MentionPolicy's gate)
 *   bells          list<array{to: int, from: int, type: string, external_id: int, act_id: int}>
 *   pushes         list<string>  push queue keys written (set_transient)
 *   logs           list<string>  Logger messages
 */

declare(strict_types=1);

namespace {
    if (!defined('BCC_NOTIFICATION_MODULE_ID')) {
        define('BCC_NOTIFICATION_MODULE_ID', 9000);
    }

    if (!function_exists('get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            return $GLOBALS['__bcc_mna']['meta'][$userId][$key] ?? '';
        }
    }
    if (!function_exists('get_userdata')) {
        /** @return object|false */
        function get_userdata(int $userId)
        {
            return $userId > 0 ? (object) ['ID' => $userId] : false;
        }
    }
    if (!function_exists('get_transient')) {
        /** @return mixed */
        function get_transient(string $key)
        {
            return false;
        }
    }
    if (!function_exists('set_transient')) {
        /** @param mixed $value */
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            $GLOBALS['__bcc_mna']['pushes'][] = $key;
            return true;
        }
    }

    if (!class_exists('PeepSoUserSearch', false)) {
        /** MentionPolicy's privacy filter: the candidates minus `unmentionable`. */
        final class PeepSoUserSearch
        {
            /** @var list<int> */
            public array $results;

            /** @param array{include: list<int>} $args */
            public function __construct(array $args, ?int $viewerId, string $query)
            {
                $this->results = array_values(array_diff($args['include'], $GLOBALS['__bcc_mna']['unmentionable'] ?? []));
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(__NAMESPACE__ . '\PeepSoNotificationWriter', false)) {
        final class PeepSoNotificationWriter
        {
            /** @return array{ok: bool} */
            public static function addNotification(int $from, int $to, string $message, string $type, int $moduleId, int $externalId, int $actId): array
            {
                $GLOBALS['__bcc_mna']['bells'][] = ['to' => $to, 'from' => $from, 'type' => $type, 'external_id' => $externalId, 'act_id' => $actId];
                return ['ok' => true];
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_mna']['logs'][] = $message;
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_mna']['logs'][] = $message;
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\PeepSoBlockRepository', false)) {
        final class PeepSoBlockRepository
        {
            public static function isMutuallyBlockedOrUnknown(int $a, int $b): ?bool
            {
                return false;
            }
        }
    }
}

namespace BCC\Core\Cron {
    if (!class_exists(__NAMESPACE__ . '\AsyncDispatcher', false)) {
        final class AsyncDispatcher
        {
            /** @param list<mixed> $args */
            public static function scheduleSingle(int $timestamp, string $hook, array $args = [], string $group = ''): void
            {
            }
        }
    }
}

namespace BCC\Core\Contracts {
    // PageOwnerResolver implements it; nothing on the mention path calls it.
    if (!interface_exists(__NAMESPACE__ . '\PageOwnerResolverInterface', false)) {
        interface PageOwnerResolverInterface
        {
        }
    }
}
