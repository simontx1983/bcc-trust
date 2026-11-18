<?php
/**
 * Stubs for PerIdentityThrottleTest and WalletSignInVerifyBeforeConsumeTest —
 * loaded BEFORE account-prehijack-stubs.php.
 *
 * Subprocess-only. Fakes the bcc-core collaborators WalletAuthController::
 * walletLogin / walletSignup need, in $GLOBALS['__bcc_wl']:
 *   - WalletIdentityService live anonymous challenges, either
 *       challenges  array<string address, list<array{chain_id: int, message: string}>>
 *     or (PerIdentityThrottleTest's shorthand)
 *       nonces      array<string address, int>  — that many uses of one
 *                   chain-4 challenge "sign me <address>";
 *     peekAnonymousChallenges() never consumes; consumeAnonymousChallenge()
 *     records 'consumed' => "<address>#<chainId>" and removes the challenge
 *     (consume_fails makes it lose the race: returns null);
 *   - ServiceLocator::resolveChainRead()->getById — chains 4 (cosmos),
 *     7 (ethereum, evm) and 8 (base, evm);
 *   - WalletVerifier::verify — true only for a "<chainType>|<message>|<sig>"
 *     listed in 'valid'; every call is recorded in 'verified'.
 */

declare(strict_types=1);

namespace BCC\Core\Wallet {
    if (!class_exists(WalletIdentityService::class, false)) {
        final class WalletIdentityService
        {
            /** @return list<array<string, mixed>> */
            public static function peekAnonymousChallenges(string $address): array
            {
                $wl = $GLOBALS['__bcc_wl'] ?? [];
                if (isset($wl['challenges'][$address])) {
                    return array_values($wl['challenges'][$address]);
                }
                return empty($wl['nonces'][$address]) ? [] : [['chain_id' => 4, 'message' => 'sign me ' . $address]];
            }

            /** @return array<string, mixed>|null */
            public static function consumeAnonymousChallenge(string $address, ?int $chainId = null): ?array
            {
                $GLOBALS['__bcc_wl']['consumed'][] = $address . '#' . ($chainId ?? 'any');
                if (!empty($GLOBALS['__bcc_wl']['consume_fails'])) {
                    return null;
                }
                if (isset($GLOBALS['__bcc_wl']['challenges'][$address])) {
                    foreach ($GLOBALS['__bcc_wl']['challenges'][$address] as $i => $c) {
                        if ($chainId === null || (int) $c['chain_id'] === $chainId) {
                            unset($GLOBALS['__bcc_wl']['challenges'][$address][$i]);
                            return $c;
                        }
                    }
                    return null;
                }
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
                        return [
                            4 => (object) ['id' => 4, 'slug' => 'cosmos', 'chain_type' => 'cosmos'],
                            7 => (object) ['id' => 7, 'slug' => 'ethereum', 'chain_type' => 'evm'],
                            8 => (object) ['id' => 8, 'slug' => 'base', 'chain_type' => 'evm'],
                        ][$id] ?? null;
                    }
                };
            }

            /** F-294: the link write; answers $GLOBALS['__bcc_wl']['link_result'] (default 0 = failed). */
            public static function resolveWalletLinkWrite(): object
            {
                return new class {
                    public function linkWallet(int $userId, string $chainSlug, string $address, int $a = 0, string $b = 'user', string $c = ''): int
                    {
                        return (int) ($GLOBALS['__bcc_wl']['link_result'] ?? 0);
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
                $call = $chainType . '|' . $message . '|' . $signature;
                $GLOBALS['__bcc_wl']['verified'][] = $call;
                return in_array($call, $GLOBALS['__bcc_wl']['valid'] ?? [], true);
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
