<?php

declare(strict_types=1);

/**
 * The four PeepSo entry points PeepSoUpkeep calls, recording each call in
 * $GLOBALS['__bcc_up']['calls']. A name listed in $GLOBALS['__bcc_up']['throw']
 * throws instead. Load only in a separate process, after
 * peepso-upkeep-stubs.php.
 */

namespace {
    if (!function_exists('bcc_up_record')) {
        /** @param list<mixed> $args */
        function bcc_up_record(string $name, array $args): void
        {
            $GLOBALS['__bcc_up']['calls'][] = [$name, $args];
            if (in_array($name, $GLOBALS['__bcc_up']['throw'] ?? [], true)) {
                throw new \RuntimeException($name . ' failed');
            }
        }
    }

    if (!class_exists('PeepSo', false)) {
        final class PeepSo
        {
            private static ?self $instance = null;

            public static function get_instance(): self
            {
                return self::$instance ??= new self();
            }

            public function hashtags_build_posts(int $limit = 10): int
            {
                bcc_up_record(__FUNCTION__, [$limit]);

                return 0;
            }

            public function hashtags_build_hashtags(int $limit = 10): int
            {
                bcc_up_record(__FUNCTION__, [$limit]);

                return 0;
            }
        }
    }

    if (!class_exists('PeepSo3_Mayfly', false)) {
        final class PeepSo3_Mayfly
        {
            public static function clr(mixed ...$args): int
            {
                bcc_up_record('mayfly_clr', $args);

                return 0;
            }
        }
    }

    if (!class_exists('PeepSoRegisterAvatarAjax', false)) {
        final class PeepSoRegisterAvatarAjax
        {
            public static function purge_old(int $ttl): int
            {
                bcc_up_record(__FUNCTION__, [$ttl]);

                return 0;
            }
        }
    }
}
