<?php
/**
 * H6: "peepso-messages is active", with a PeepSoChatModel whose methods
 * THROW. Load in a separate process only.
 *
 * BCC code may check that the class exists, but must never call it:
 * chat_enabled() writes a default when its get_user_meta() read is empty,
 * and a failed meta read is empty too. Every chat setting is read through
 * PeepSoChatPrefs instead. A test world that loads this stub fails loudly if
 * anything reaches for the old API again.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('PeepSoChatModel', false)) {
        final class PeepSoChatModel
        {
            public static function chat_enabled(int $userId): bool
            {
                throw new \LogicException('PeepSoChatModel::chat_enabled() writes on read; use PeepSoChatPrefs.');
            }

            public static function chat_friends_only(int $userId): bool
            {
                throw new \LogicException('Read chat_friends_only through PeepSoChatPrefs.');
            }
        }
    }
}
