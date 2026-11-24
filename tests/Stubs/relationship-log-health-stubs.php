<?php

declare(strict_types=1);

/**
 * RelationshipLogHealthCheckTest: recording fakes. Load only in a separate
 * process, from setUp(), BEFORE the health check is autoloaded. State lives
 * in HealthWorld.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    final class HealthWorld
    {
        /** @var array{ok: bool, rows_checked: int, head_seq: int, first_bad_seq: int|null, reason: string|null} */
        public static array $verdict = ['ok' => true, 'rows_checked' => 3, 'head_seq' => 3, 'first_bad_seq' => null, 'reason' => null];
        /** @var array{seq: int, row_hash: string} */
        public static array $head = ['seq' => 3, 'row_hash' => 'aaaa'];
        /** @var array<string, mixed> */
        public static array $options = [];
        /** @var list<array{string, string}> [to, subject] */
        public static array $mails = [];
        /** @var list<string> */
        public static array $metrics = [];
        /** @var list<array{int, string}> */
        public static array $anchored = [];
    }

    if (!class_exists('WP_User', false)) {
        class WP_User
        {
            public string $user_email = '';
        }
    }

    function get_userdata(int $id): \WP_User
    {
        $u             = new \WP_User();
        $u->user_email = 'admin' . $id . '@example.org';
        return $u;
    }

    function get_bloginfo(string $show = ''): string
    {
        return 'BCC';
    }

    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    function update_option(string $key, mixed $value, mixed $autoload = null): bool
    {
        \HealthWorld::$options[$key] = $value;
        return true;
    }

    function get_option(string $key, mixed $default = false): mixed
    {
        return \HealthWorld::$options[$key] ?? $default;
    }
}

namespace BCC\Trust\Onchain\Services {
    final class RelationshipLogVerifier
    {
        /** @return array{ok: bool, rows_checked: int, head_seq: int, first_bad_seq: int|null, reason: string|null} */
        public static function verify(): array
        {
            return \HealthWorld::$verdict;
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    final class ValidatorRelationshipLogRepository
    {
        /** @return array{seq: int, row_hash: string} */
        public static function head(): array
        {
            return \HealthWorld::$head;
        }
    }
}

namespace BCC\Trust\Onchain\Support {
    final class RelationshipLogAnchor
    {
        public static function advance(int $seq, string $rowHash): void
        {
            \HealthWorld::$anchored[] = [$seq, $rowHash];
        }
    }
}

namespace BCC\Trust\Core\Support {
    final class AdminRecipients
    {
        /** @return list<int> */
        public static function ids(): array
        {
            return [1, 2];
        }
    }
}

namespace BCC\Core\Mail {
    final class BccMailer
    {
        public static function send(string $to, string $subject, string $html, string $failureEvent): void
        {
            \HealthWorld::$mails[] = [$to, $subject];
        }
    }
}

namespace BCC\Core\Observability {
    final class DegradationMetrics
    {
        public static function record(string $subsystem, string $event = 'activation'): void
        {
            \HealthWorld::$metrics[] = $subsystem . '/' . $event;
        }
    }
}

namespace BCC\Core\Log {
    final class Logger
    {
        /** @param array<string, mixed> $c */
        public static function error(string $m, array $c = []): void {}
        /** @param array<string, mixed> $c */
        public static function warning(string $m, array $c = []): void {}
    }
}
