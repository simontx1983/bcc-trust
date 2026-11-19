<?php
/**
 * Stubs for ReleaseWalletSignupEmailsTest (subprocess-isolated).
 *
 * Runs the real bcc_trust_release_wallet_signup_unverified_emails() without
 * WordPress or MySQL. State in $GLOBALS['__bcc_rel']:
 *   salt             wp_salt('auth')
 *   candidates       array<int, string>  user id => user_email: the rows the
 *                    SELECT matches (a moved row stops matching, as it
 *                    would: its user_email is a placeholder then)
 *   meta             array<int, array<string, string>>  user meta
 *   wallets          array<int, list<array>>  WalletRepository::getForUserOrThrow
 *   wallet_read_fails  true → getForUserOrThrow throws
 *   select_fails     true → the SELECT sets last_error
 *   update_return    $wpdb->update() return (default 1)
 *   meta_write_fails true → update_user_meta() returns false
 *   email_exists     array<string, int>  address → holder id
 *   prepared         list<list<mixed>>  prepare() args, per call
 *   updates          list<array{id: int, email: string}>
 *   cache_cleaned    list<int>
 *   logs             list<array{0: string, 1: array<string, mixed>}>
 */

declare(strict_types=1);

namespace {
    if (!defined('BCC_TRUST_MIGRATION_COMPLETE'))   { define('BCC_TRUST_MIGRATION_COMPLETE', 'complete'); }
    if (!defined('BCC_TRUST_MIGRATION_INCOMPLETE')) { define('BCC_TRUST_MIGRATION_INCOMPLETE', 'incomplete'); }
    if (!defined('BCC_TRUST_MIGRATION_MORE'))       { define('BCC_TRUST_MIGRATION_MORE', 'more'); }

    if (!function_exists('wp_salt')) {
        function wp_salt(string $scheme = 'auth'): string
        {
            return (string) ($GLOBALS['__bcc_rel']['salt'] ?? '');
        }
    }
    if (!function_exists('email_exists')) {
        /** @return int|false */
        function email_exists(string $email)
        {
            $id = $GLOBALS['__bcc_rel']['email_exists'][strtolower($email)] ?? null;
            return $id === null ? false : (int) $id;
        }
    }
    if (!function_exists('clean_user_cache')) {
        function clean_user_cache(int $id): void
        {
            $GLOBALS['__bcc_rel']['cache_cleaned'][] = $id;
        }
    }
    if (!function_exists('get_user_meta')) {
        /** Like core: no key → every key, each with a list of values. @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            $meta = $GLOBALS['__bcc_rel']['meta'][$userId] ?? [];
            if ($key === '') {
                return array_map(static fn($v): array => [(string) $v], $meta);
            }
            return array_key_exists($key, $meta) ? (string) $meta[$key] : '';
        }
    }
    if (!function_exists('update_user_meta')) {
        /** @param mixed $value */
        function update_user_meta(int $userId, string $key, $value): bool
        {
            if (!empty($GLOBALS['__bcc_rel']['meta_write_fails'])) {
                return false;
            }
            $GLOBALS['__bcc_rel']['meta'][$userId][$key] = (string) $value;
            return true;
        }
    }

    $GLOBALS['wpdb'] = new class {
        public string $users      = 'wp_users';
        public string $usermeta   = 'wp_usermeta';
        public string $last_error = '';
        /** @var list<mixed> */
        private array $args = [];

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            $this->args = $args;
            $GLOBALS['__bcc_rel']['prepared'][] = $args;
            return $sql;
        }

        /** Keyset page: candidates with id > cursor (arg 0), at most LIMIT (last arg). @return list<object>|null */
        public function get_results(string $sql)
        {
            if (!empty($GLOBALS['__bcc_rel']['select_fails'])) {
                $this->last_error = 'stub select failed';
                return null;
            }
            $cursor = (int) $this->args[0];
            $limit  = (int) end($this->args);
            $rows   = [];
            $all    = $GLOBALS['__bcc_rel']['candidates'] ?? [];
            ksort($all);
            foreach ($all as $id => $email) {
                if ($id > $cursor && count($rows) < $limit) {
                    $rows[] = (object) ['ID' => (string) $id, 'user_email' => $email];
                }
            }
            return $rows;
        }

        /**
         * @param array<string, mixed> $data
         * @param array<string, mixed> $where
         * @return int|false
         */
        public function update(string $table, array $data, array $where)
        {
            $ret = $GLOBALS['__bcc_rel']['update_return'] ?? 1;
            if ($ret !== false) {
                $id = (int) $where['ID'];
                $GLOBALS['__bcc_rel']['updates'][] = ['id' => $id, 'email' => (string) $data['user_email']];
                unset($GLOBALS['__bcc_rel']['candidates'][$id]);
            }
            return $ret;
        }
    };
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(WalletRepository::class, false)) {
        final class WalletRepository
        {
            /** @return list<array<string, mixed>> */
            public static function getForUserOrThrow(int $userId, ?string $walletType = null, bool $verifiedOnly = false): array
            {
                if (!empty($GLOBALS['__bcc_rel']['wallet_read_fails'])) {
                    throw new \RuntimeException('stub wallet read failed');
                }
                return $GLOBALS['__bcc_rel']['wallets'][$userId] ?? [];
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void
            {
                $GLOBALS['__bcc_rel']['logs'][] = [$m, $c];
            }
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
                $GLOBALS['__bcc_rel']['logs'][] = [$m, $c];
            }
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                $GLOBALS['__bcc_rel']['logs'][] = [$m, $c];
            }
        }
    }
}
