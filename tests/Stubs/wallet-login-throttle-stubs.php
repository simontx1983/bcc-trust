<?php
/**
 * Stubs for PerIdentityThrottleTest — loaded BEFORE account-prehijack-stubs.php.
 *
 * Subprocess-only. Fakes the bcc-core collaborators WalletAuthController::
 * walletLogin needs up to (and including) the signature verify:
 *   - WalletIdentityService::consumeAnonymousChallenge — returns a challenge
 *     only for addresses listed in $GLOBALS['__bcc_wl']['nonces'] (single-use);
 *   - ServiceLocator::resolveChainRead()->getById — one chain (id 4, cosmos);
 *   - WalletVerifier::verify — always false (the tests observe the throttle,
 *     which runs BEFORE the verify: 429 vs the verify's 401).
 */

declare(strict_types=1);

namespace BCC\Core\Wallet {
    if (!class_exists(WalletIdentityService::class, false)) {
        final class WalletIdentityService
        {
            /** @return array<string, mixed>|null */
            public static function consumeAnonymousChallenge(string $address): ?array
            {
                $n = &$GLOBALS['__bcc_wl']['nonces'];
                if (empty($n[$address])) {
                    return null;
                }
                $n[$address]--;
                return ['chain_id' => 4, 'message' => 'sign me ' . $address];
            }
        }
    }
}

namespace BCC\Core {
    if (!class_exists(ServiceLocator::class, false)) {
        final class ServiceLocator
        {
            public static function resolveChainRead(): object
            {
                return new class {
                    public function getById(int $id): ?object
                    {
                        return $id === 4 ? (object) ['id' => 4, 'slug' => 'cosmos', 'chain_type' => 'cosmos'] : null;
                    }
                };
            }
        }
    }
}

namespace BCC\Core\Observability {
    if (!class_exists(DegradationMetrics::class, false)) {
        final class DegradationMetrics
        {
            public static function record(string $subsystem, string $event = 'activation'): void
            {
                $GLOBALS['__bcc_ph']['metrics'][] = $subsystem . ':' . $event;
            }
        }
    }
}

namespace BCC\Core\Crypto {
    if (!class_exists(WalletVerifier::class, false)) {
        final class WalletVerifier
        {
            /** @param array<string, mixed> $extra */
            public static function verify(string $chainType, string $message, string $signature, string $address, array $extra = []): bool
            {
                return false;
            }
        }
    }
}

namespace {
    if (!function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags(string $s): string
        {
            return strip_tags($s);
        }
    }
    if (!function_exists('wp_unslash')) {
        /** @param mixed $v @return mixed */
        function wp_unslash($v)
        {
            return is_string($v) ? stripslashes($v) : $v;
        }
    }
}
