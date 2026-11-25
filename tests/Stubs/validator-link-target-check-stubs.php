<?php

declare(strict_types=1);

/**
 * ValidatorLinkTargetCheckTest: fakes for the check's three reads. Load only
 * in a separate process, from setUp(), BEFORE the check is autoloaded. State
 * lives in TargetWorld.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    final class TargetWorld
    {
        /** null = the existence read fails */
        public static ?bool $exists = true;
        /** core's tri-state: true suspended, false not, null unknown */
        public static ?bool $suspended = false;
        public static string $role = 'member';
        public static bool $peepsoLoaded = true;
    }

    if (!class_exists('PeepSoUser', false)) {
        final class PeepSoUser
        {
            public static function get_instance(int $userId): self
            {
                if (!\TargetWorld::$peepsoLoaded) {
                    throw new \RuntimeException('PeepSo not loaded');
                }
                return new self();
            }

            public function get_user_role(): string
            {
                return \TargetWorld::$role;
            }
        }
    }
}

namespace BCC\Core\Permissions {
    final class Permissions
    {
        public static function suspensionState(?int $userId = null, bool $allowAdminBypass = true): ?bool
        {
            return \TargetWorld::$suspended;
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    final class RepositoryReadFailure extends \RuntimeException
    {
    }

    final class ClaimRepository
    {
        public static function accountExistsStrict(int $userId): bool
        {
            if (\TargetWorld::$exists === null) {
                throw new RepositoryReadFailure('read failed');
            }
            return \TargetWorld::$exists;
        }
    }
}
