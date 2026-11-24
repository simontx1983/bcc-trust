<?php
/**
 * The one double `profile-section-visibility-stubs.php` does not provide.
 *
 * ⚠⚠ SUBPROCESS-ONLY, and it is meant to be loaded ALONGSIDE
 * `profile-section-visibility-stubs.php`, never instead of it. That file
 * is where the fixture and the `get_user_meta` shim live, and it
 * deliberately keeps `ProfileSectionVisibility`, `ProfileAccess` and
 * `PrivacySettings` REAL — which is the whole point: a stubbed gate would
 * prove only that a stub returns what it was told.
 *
 * ⛔ It declares `\BCC\Core\Permissions\Permissions`, so the same ban
 * applies transitively: never load this pair alongside
 * `profile-access-stubs.php`, `feed-f02-wiring-stubs.php` or
 * `profile-group-visibility-stubs.php`.
 *
 * This file adds `WP_User` only. Every consumer of the card bio resolver
 * needs one, and the stub above has no reason to carry it.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_User', false)) {
        class WP_User
        {
            public int $ID;

            /** The standard WP profile-bio field — what the card bio reads. */
            public string $description;

            public string $display_name = 'Stub Member';
            public string $user_login    = 'stub-member';

            public function __construct(int $id = 0, string $description = '')
            {
                $this->ID          = $id;
                $this->description = $description;
            }
        }
    }
}
