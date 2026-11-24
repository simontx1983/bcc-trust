<?php
/**
 * Stubs for MentionAudienceTest and BellWindowGateTest. Loaded ONLY inside a
 * PHPUnit subprocess (RunTestsInSeparateProcesses), so nothing here leaks
 * into other suites.
 *
 * Real code under test: NotificationDispatcher (the mention, hall-post and
 * comment-received bells and their windows), MentionExtractor,
 * MentionPolicy, NotificationPrefs and PushDispatcher::enqueue(). Doubles, in
 * $GLOBALS['__bcc_mna']:
 *   meta           array<int, array<string, string>>  user meta (bell/push prefs, handles)
 *   unmentionable  list<int>   ids PeepSoUserSearch filters out (MentionPolicy's gate)
 *   bells          list<array{to: int, from: int, type: string, external_id: int, act_id: int}>
 *   pushes         list<string>  every transient key written (set_transient), in order
 *   transients     array<string, array{value: mixed, ttl: int, expires: int}>  the store
 *                  get_transient() reads; an expired entry reads as absent
 *   now            int         the store's clock (default: time())
 *   deleted        list<string>  keys passed to delete_transient()
 *   transient_read_fails  bool  every get_transient() answers false
 *   bell_write_fails      bool  PeepSoNotificationWriter refuses the write
 *   next_bell_id   int         when set, each written bell answers this row id, then it counts up
 *   logs           list<string>  Logger messages
 *
 * Tests: MentionAudienceTest, BellWindowGateTest, MentionBellWindowTest,
 * PushCarriesBellIdTest.
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
        /** @return mixed false when absent or expired, as WordPress answers */
        function get_transient(string $key)
        {
            if (!empty($GLOBALS['__bcc_mna']['transient_read_fails'])) {
                // A cache read that fails: WordPress answers false, the same
                // as "no such transient".
                return false;
            }
            $entry = $GLOBALS['__bcc_mna']['transients'][$key] ?? null;
            if (!is_array($entry)) {
                return false;
            }
            $now = $GLOBALS['__bcc_mna']['now'] ?? time();
            if ($entry['expires'] !== 0 && $entry['expires'] <= $now) {
                return false;
            }
            return $entry['value'];
        }
    }
    if (!function_exists('set_transient')) {
        /** @param mixed $value */
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            $GLOBALS['__bcc_mna']['pushes'][] = $key;
            $now = $GLOBALS['__bcc_mna']['now'] ?? time();
            $GLOBALS['__bcc_mna']['transients'][$key] = [
                'value'   => $value,
                'ttl'     => $ttl,
                'expires' => $ttl > 0 ? $now + $ttl : 0,
            ];
            return true;
        }
    }
    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            $GLOBALS['__bcc_mna']['deleted'][] = $key;
            unset($GLOBALS['__bcc_mna']['transients'][$key]);
            return true;
        }
    }

    // A bell written with a row id bumps the recipient's badge generation
    // (BadgesService → CacheGeneration). Without these, that bump throws and
    // the dispatcher's catch swallows it, push included.
    if (!function_exists('wp_cache_incr')) {
        /**
         * @param int|string $key
         * @return int|false
         */
        function wp_cache_incr($key, int $offset = 1, string $group = '')
        {
            return false;
        }
    }
    if (!function_exists('wp_cache_set')) {
        /**
         * @param int|string $key
         * @param mixed      $data
         */
        function wp_cache_set($key, $data, string $group = '', int $expire = 0): bool
        {
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
            /**
             * `bell_write_fails` (bool): the write is refused, as PeepSo's
             * writer answers a failed insert, and no bell is recorded.
             *
             * @return array{ok: bool, reason?: string}
             */
            public static function addNotification(int $from, int $to, string $message, string $type, int $moduleId, int $externalId, int $actId): array
            {
                if (!empty($GLOBALS['__bcc_mna']['bell_write_fails'])) {
                    return ['ok' => false, 'reason' => 'simulated write failure'];
                }
                $GLOBALS['__bcc_mna']['bells'][] = ['to' => $to, 'from' => $from, 'type' => $type, 'external_id' => $externalId, 'act_id' => $actId, 'message' => $message];
                if (isset($GLOBALS['__bcc_mna']['next_bell_id'])) {
                    // F-516: the row id PeepSo's writer answers, when a test asks for one.
                    return ['ok' => true, 'notification_id' => $GLOBALS['__bcc_mna']['next_bell_id']++];
                }
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
