<?php
/**
 * H6 PeepSoChatPrefsTest: PeepSo's option store, so the reader's two site
 * options (`messages_chat_enable`, `messages_friends_only`) can be set per
 * test. Load in a separate process only.
 *
 * PeepSoFriends is NOT declared here: whether it exists is part of the rule
 * (no friends plugin, no friends-only). A test that needs it requires
 * peepso-friends-present-stub.php.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('PeepSo', false)) {
        final class PeepSo
        {
            /** @var array<string, mixed> */
            public static array $options = [];

            /**
             * @param mixed $default
             * @return mixed
             */
            public static function get_option(string $key, $default = null)
            {
                return array_key_exists($key, self::$options) ? self::$options[$key] : $default;
            }
        }
    }
}
