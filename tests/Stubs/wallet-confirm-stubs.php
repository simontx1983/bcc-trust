<?php
/**
 * F-253 doubles for AccountWalletConfirmTest: bcc-core's wallet seam (not
 * autoloaded in the unit suite) and the wallet-link reads.
 *
 * State lives in $GLOBALS['__bcc_wc']:
 *   challenges  array<string, array{purpose: string, message: string}>
 *               keyed "user:lowercased address": what GET /auth/nonce issued
 *   consumed    list<array{user: int, address: string, purpose: string}>
 *   issued      list<array{user: int, address: string, purpose: string}>
 *   links       list<array{id: int, user_id: int, chain_id: int, address: string, verified_at: int|null}>
 *               (verified_at: a unix time, rendered as WalletRepository's UTC DATETIME)
 *   deleted     list<int>  wp_delete_user() calls
 *
 * WalletIdentityService::consumeChallenge mirrors core's contract (pinned
 * by core's WalletChallengePurposeTest): single-use, null on a purpose
 * mismatch (still spent), default purpose 'verify'. WalletVerifier accepts
 * exactly "sig-of:" . message, so a test proves the STORED message was the
 * one checked.
 *
 * Load AFTER account-prehijack-stubs.php and pending-email-stubs.php.
 */

declare(strict_types=1);

namespace BCC\Core\Crypto {
    if (!class_exists(__NAMESPACE__ . '\\WalletChallenge', false)) {
        final class WalletChallenge
        {
            public const PURPOSE_SIGN_IN = 'sign_in';
            public const PURPOSE_VERIFY  = 'verify';
            public const PURPOSE_CONFIRM_EMAIL_CHANGE    = 'confirm_email_change';
            public const PURPOSE_CONFIRM_PASSWORD_CHANGE = 'confirm_password_change';
            public const PURPOSE_CONFIRM_ACCOUNT_DELETE  = 'confirm_account_delete';
            public const AUTHED_PURPOSES = [
                self::PURPOSE_VERIFY,
                self::PURPOSE_CONFIRM_EMAIL_CHANGE,
                self::PURPOSE_CONFIRM_PASSWORD_CHANGE,
                self::PURPOSE_CONFIRM_ACCOUNT_DELETE,
            ];
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\WalletVerifier', false)) {
        final class WalletVerifier
        {
            /** @param array<string, mixed> $extra */
            public static function verify(string $chainType, string $message, string $signature, string $address, array $extra = []): bool
            {
                return $signature === 'sig-of:' . $message;
            }
        }
    }
}

namespace BCC\Core\Wallet {
    if (!class_exists(__NAMESPACE__ . '\\WalletIdentityService', false)) {
        final class WalletIdentityService
        {
            /** @return array<string, mixed> */
            public static function generateChallenge(int $userId, string $chainSlug, int $chainId, string $walletAddress, string $purpose = 'verify'): array
            {
                $GLOBALS['__bcc_wc']['issued'][] = ['user' => $userId, 'address' => strtolower($walletAddress), 'purpose' => $purpose];
                $message = 'msg:' . $purpose . ':' . bin2hex(random_bytes(4));
                $GLOBALS['__bcc_wc']['challenges'][$userId . ':' . strtolower($walletAddress)] = ['purpose' => $purpose, 'message' => $message, 'chain_slug' => $chainSlug];
                return [
                    'purpose' => $purpose, 'nonce' => 'n', 'message' => $message, 'chain_slug' => $chainSlug,
                    'chain_id' => $chainId, 'wallet_address' => strtolower($walletAddress), 'expires_at' => time() + 300,
                ];
            }

            /** @return array<string, mixed>|null */
            public static function consumeChallenge(int $userId, string $walletAddress, string $expectedPurpose = 'verify'): ?array
            {
                $key = $userId . ':' . strtolower($walletAddress);
                $GLOBALS['__bcc_wc']['consumed'][] = ['user' => $userId, 'address' => strtolower($walletAddress), 'purpose' => $expectedPurpose];
                $c = $GLOBALS['__bcc_wc']['challenges'][$key] ?? null;
                unset($GLOBALS['__bcc_wc']['challenges'][$key]);
                if ($c === null || $c['purpose'] !== $expectedPurpose) {
                    return null;
                }
                return $c;
            }
        }
    }
}

namespace BCC\Core {
    if (!class_exists(__NAMESPACE__ . '\\ServiceLocator', false)) {
        final class ServiceLocator
        {
            public static function resolveChainRead(): object
            {
                return new class {
                    public function getBySlug(string $slug): ?object
                    {
                        return match ($slug) {
                            'ethereum' => (object) ['id' => 1, 'slug' => 'ethereum', 'chain_type' => 'evm'],
                            // Another EVM chain: the same address is valid on both.
                            'base'     => (object) ['id' => 2, 'slug' => 'base', 'chain_type' => 'evm'],
                            default    => null,
                        };
                    }

                    public function resolveId(string $slug): ?int
                    {
                        return $slug === 'ethereum' ? 1 : null;
                    }

                    public function getById(int $id): ?object
                    {
                        return $id === 1 ? (object) ['id' => 1, 'slug' => 'ethereum', 'chain_type' => 'evm'] : null;
                    }
                };
            }
        }
    }
}

namespace {
    if (!function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags(string $s): string
        {
            return trim(strip_tags($s));
        }
    }
    if (!function_exists('wp_unslash')) {
        /** @param mixed $v @return mixed */
        function wp_unslash($v)
        {
            return is_string($v) ? stripslashes($v) : $v;
        }
    }

    if (!class_exists('PeepSo', false)) {
        class PeepSo
        {
            /** @param mixed $default @return mixed */
            public static function get_option(string $key, $default = null)
            {
                return $key === 'site_registration_allowdelete' ? 1 : $default;
            }

            public static function get_page(string $name): string
            {
                return '';
            }
        }
    }
    if (!function_exists('wp_delete_user')) {
        function wp_delete_user(int $id): bool
        {
            $GLOBALS['__bcc_wc']['deleted'][] = $id;
            return true;
        }
    }
    if (!function_exists('wp_logout')) {
        function wp_logout(): void
        {
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    /**
     * The two wallet-link reads the proof makes. Loaded before anything
     * autoloads the real repository (which needs bcc-core's DB::table()).
     */
    if (!class_exists(__NAMESPACE__ . '\WalletRepository', false)) {
        final class WalletRepository
        {
            public static function findIdByUserChainAddress(int $userId, int $chainId, string $walletAddress): int
            {
                foreach ($GLOBALS['__bcc_wc']['links'] as $l) {
                    if ($l['user_id'] === $userId && $l['chain_id'] === $chainId && $l['address'] === $walletAddress) {
                        return $l['id'];
                    }
                }
                return 0;
            }

            public static function oldestVerifiedAt(int $userId): ?string
            {
                $times = [];
                foreach ($GLOBALS['__bcc_wc']['links'] as $l) {
                    if ($l['user_id'] === $userId && $l['verified_at'] !== null) {
                        $times[] = $l['verified_at'];
                    }
                }
                return $times === [] ? null : gmdate('Y-m-d H:i:s', min($times));
            }

            public static function getById(int $walletLinkId): ?object
            {
                foreach ($GLOBALS['__bcc_wc']['links'] as $l) {
                    if ($l['id'] === $walletLinkId) {
                        return (object) [
                            'id' => $l['id'], 'user_id' => $l['user_id'], 'wallet_address' => $l['address'],
                            'chain_id' => $l['chain_id'],
                            'verified_at' => $l['verified_at'] === null ? null : gmdate('Y-m-d H:i:s', $l['verified_at']),
                        ];
                    }
                }
                return null;
            }
        }
    }
}
