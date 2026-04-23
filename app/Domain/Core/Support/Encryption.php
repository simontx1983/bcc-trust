<?php

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AES-256-GCM encryption helpers.
 */
final class Encryption
{
    public static function encrypt(string $plaintext): string
    {
        if (!defined('BCC_ENCRYPTION_KEY') || $plaintext === '') {
            return '';
        }

        $key = hash('sha256', BCC_ENCRYPTION_KEY, true);
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            return '';
        }
        return base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $data): string
    {
        if (!defined('BCC_ENCRYPTION_KEY') || $data === '') {
            return '';
        }

        $key = hash('sha256', BCC_ENCRYPTION_KEY, true);
        $raw = base64_decode($data, true);

        if ($raw === false || strlen($raw) < 29) {
            return '';
        }

        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $pt !== false ? $pt : '';
    }
}
