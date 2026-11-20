<?php
/**
 * H6: "peepso-friends is active". PeepSoChatPrefs (like PeepSoChatModel)
 * answers friends-only = false when this class does not exist. Load in a
 * separate process only.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('PeepSoFriends', false)) {
        final class PeepSoFriends
        {
        }
    }
}
