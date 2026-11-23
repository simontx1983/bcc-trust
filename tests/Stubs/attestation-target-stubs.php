<?php

declare(strict_types=1);

/**
 * AttestationTargetValidationTest: a driven world for
 * AttestationService::checkCastEligibility()'s target checks.
 * Load only in a separate process, BEFORE AttestationService is autoloaded,
 * so these declarations win over the real collaborators.
 *
 * ⚠ BCC_TRUST_TEST_MODE is deliberately NOT defined here: assertFraudClear()
 * returns early under it, and the fraud tests would pass vacuously. The
 * real BCC_TRUST_FRAUD_* thresholds are loaded instead.
 */

namespace {
    if (!defined('BCC_TRUST_FRAUD_HIGH')) {
        require_once dirname(__DIR__, 2) . '/includes/config/fraud-detection.php';
    }

    final class AttestTargetWorld
    {
        /** @var array<int, array{type: string, status: string, author: int, page_type: string}> */
        public static array $posts = [];
        /** @var array<int, true> existing user ids */
        public static array $users = [];
        /** @var array<int, bool> profile id => may the current viewer see it (default true) */
        public static array $viewable = [];
        /** @var array<int, bool|null> other user id => block state with the attestor (null = unreadable) */
        public static array $blocked = [];
        /** @var object|null the attestation row findOneById() returns (reaffirm tests) */
        public static ?object $attestation = null;
        /** @var int how often the reaffirm reached its per-attestor lock */
        public static int $lockAttempts = 0;
        /** @var list<int> attestors Permissions::is_not_suspended() refuses */
        public static array $suspended = [];
        /** @var list<int> attestors with no rank_state row (New Members) */
        public static array $newMembers = [];
        /** The attestor's reputation tier; null = the test helper's default. */
        public static ?string $tier = null;
        /** F-469: the attestor's reputation row could not be read. */
        public static bool $tierUnreadable = false;
        /** The attestor's fraud_score (UserInfoRepository::getByUserId). */
        public static int $fraudScore = 0;
        /** @var array<int, list<int>> SECRET page id => the viewers bcc-core's rule lets in */
        public static array $secretPages = [];
        /** Is the current viewer a site admin (ProfileAccess's shared exemption)? */
        public static bool $admin = false;

        public static function reset(): void
        {
            self::$posts        = [];
            self::$users        = [];
            self::$viewable     = [];
            self::$blocked      = [];
            self::$attestation  = null;
            self::$lockAttempts = 0;
            self::$suspended    = [];
            self::$newMembers   = [];
            self::$tier         = null;
            self::$tierUnreadable = false;
            self::$fraudScore   = 0;
            self::$secretPages  = [];
            self::$admin        = false;

            // The one $wpdb read a reaffirm makes before its lock:
            // AttestationRepository::findOneById().
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';

                public function prepare(string $sql, mixed ...$args): string
                {
                    return $sql;
                }

                public function get_row(string $sql): ?object
                {
                    return AttestTargetWorld::$attestation;
                }
            };
        }
    }

    if (!class_exists('WP_User', false)) {
        class WP_User
        {
            public int $ID;

            public function __construct(int $id)
            {
                $this->ID = $id;
            }
        }
    }
    if (!class_exists('WP_Post', false)) {
        final class WP_Post
        {
            public int $ID = 0;
            public string $post_type = '';
            public string $post_status = '';
            public string $post_author = '0';
        }
    }

    if (!function_exists('get_userdata')) {
        /** @return \WP_User|false */
        function get_userdata(int $userId)
        {
            return isset(AttestTargetWorld::$users[$userId]) ? new \WP_User($userId) : false;
        }
    }
    if (!function_exists('get_post')) {
        /** @param int|\WP_Post|null $post */
        function get_post($post = null): ?\WP_Post
        {
            $id  = (int) $post;
            $row = AttestTargetWorld::$posts[$id] ?? null;
            if ($row === null) {
                return null;
            }
            $p              = new \WP_Post();
            $p->ID          = $id;
            $p->post_type   = $row['type'];
            $p->post_status = $row['status'];
            $p->post_author = (string) $row['author'];
            return $p;
        }
    }
    if (!function_exists('get_post_type')) {
        /** @param int|\WP_Post|null $post @return string|false */
        function get_post_type($post = null)
        {
            $row = AttestTargetWorld::$posts[(int) $post] ?? null;
            return $row === null ? false : $row['type'];
        }
    }
    if (!function_exists('get_post_meta')) {
        /** @return mixed */
        function get_post_meta(int $postId, string $key = '', bool $single = false)
        {
            if ($key === '_bcc_page_type') {
                return AttestTargetWorld::$posts[$postId]['page_type'] ?? '';
            }
            return '';
        }
    }
}

namespace BCC\Core\Permissions {
    if (!class_exists(__NAMESPACE__ . '\\Permissions', false)) {
        final class Permissions
        {
            /** The admin bypass applies only when the caller asks for it ($admin = the user is a site admin). */
            public static function is_not_suspended(?int $userId, bool $allowAdminBypass = true): bool
            {
                if ($allowAdminBypass && \AttestTargetWorld::$admin) {
                    return true;
                }
                return !in_array((int) $userId, \AttestTargetWorld::$suspended, true);
            }
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists(__NAMESPACE__ . '\\AdvisoryLock', false)) {
        /**
         * Always busy: a reaffirm that reaches its lock has passed every
         * target check, and stops there with 429 before renewing anything.
         */
        final class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool
            {
                \AttestTargetWorld::$lockAttempts++;
                return false;
            }

            public static function release(string $key): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\PeepSoBlockRepository', false)) {
        final class PeepSoBlockRepository
        {
            public static function isMutuallyBlockedOrUnknown(int $userA, int $userB): ?bool
            {
                return array_key_exists($userB, \AttestTargetWorld::$blocked)
                    ? \AttestTargetWorld::$blocked[$userB]
                    : false;
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\PeepSoPagePrivacy', false)) {
        /** The shared secret-page rule; its own SQL is tested in bcc-core and bcc-trust's integration suite. */
        final class PeepSoPagePrivacy
        {
            public static function isVisibleTo(int $pageId, int $viewerId): bool
            {
                return !isset(\AttestTargetWorld::$secretPages[$pageId])
                    || in_array($viewerId, \AttestTargetWorld::$secretPages[$pageId], true);
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    if (!class_exists(__NAMESPACE__ . '\\ProfileAccess', false)) {
        final class ProfileAccess
        {
            public static function canViewProfile(int $ownerId): bool
            {
                return \AttestTargetWorld::$viewable[$ownerId] ?? true;
            }

            /** PageAccess's admin exemption. */
            public static function viewerIsExemptFromVisibility(): bool
            {
                return \AttestTargetWorld::$admin;
            }
        }
    }
}

namespace BCC\Trust\Core {
    if (!class_exists(__NAMESPACE__ . '\\Plugin', false)) {
        final class Plugin
        {
            private static ?self $instance = null;

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            /** Every attestor is an Apprentice or higher, unless listed in $newMembers. */
            public function rankStateRepository(): object
            {
                return new class {
                    public function getForUser(int $userId): ?object
                    {
                        return in_array($userId, \AttestTargetWorld::$newMembers, true) ? null : (object) ['user_id' => $userId];
                    }
                };
            }
        }
    }
}
