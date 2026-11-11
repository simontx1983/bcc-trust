<?php
/**
 * Fraud whitelist — the single reader of the admin IP / device whitelists.
 *
 * Admins maintain the lists in Moderation (ModerationService::addToWhitelist
 * / removeFromWhitelist write the `bcc_trust_whitelist_{type}` options, keyed
 * by the exact IP or fingerprint string). Before F-103 nothing but the admin
 * badges READ them, so whitelisting a shared office IP or family device
 * changed nothing: the shared-device penalty kept firing. The detectors now
 * consult this class, and ModerationService's own checks delegate here, so
 * there is one definition of "whitelisted".
 *
 * @package BCC\Trust\Core\Security
 */

namespace BCC\Trust\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

final class FraudWhitelist
{
    public static function isIpWhitelisted(string $ip): bool
    {
        return $ip !== '' && isset(self::listFor('ip')[$ip]);
    }

    public static function isFingerprintWhitelisted(string $fingerprint): bool
    {
        return $fingerprint !== '' && isset(self::listFor('fingerprint')[$fingerprint]);
    }

    /** @return array<string, mixed> value => admin meta */
    private static function listFor(string $type): array
    {
        $list = get_option("bcc_trust_whitelist_{$type}", []);
        return is_array($list) ? $list : [];
    }
}
