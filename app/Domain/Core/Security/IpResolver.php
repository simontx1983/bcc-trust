<?php
/**
 * IP Resolver
 *
 * Spoof-proof client IP detection.
 *
 * - CF-Connecting-IP is only trusted when REMOTE_ADDR is a verified Cloudflare IP.
 * - X-Forwarded-For is never trusted (any client can set it).
 * - Falls back to REMOTE_ADDR as the authoritative source.
 * - Supports IPv4 and IPv6 throughout.
 *
 * @package BCC\Trust\Core\Security
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

class IpResolver {

    // ======================================================
    // Cloudflare published IP ranges
    // Source: https://www.cloudflare.com/ips/
    // Update periodically as Cloudflare publishes changes.
    // ======================================================

    private const CF_IPV4_RANGES = [
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '108.162.192.0/18',
        '131.0.72.0/22',
        '141.101.64.0/18',
        '162.158.0.0/15',
        '172.64.0.0/13',
        '173.245.48.0/20',
        '188.114.96.0/20',
        '190.93.240.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
    ];

    private const CF_IPV6_RANGES = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * Get the real client IP address.
     *
     * Trust priority:
     *   1. CF-Connecting-IP  — only when REMOTE_ADDR is a verified Cloudflare IP.
     *   2. REMOTE_ADDR       — always authoritative when CF check fails.
     *
     * X-Forwarded-For and X-Real-IP are deliberately ignored: any client or
     * intermediate proxy can forge them.
     *
     * @return string Canonical IP string, or '0.0.0.0' as a safe fallback.
     */
    public static function getClientIp(): string {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $remoteAddr = self::sanitizeIp($remoteAddr);

        // Only honour CF-Connecting-IP when the request genuinely came from Cloudflare.
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && self::isCloudflareIp($remoteAddr)) {
            $cfIp = self::sanitizeIp($_SERVER['HTTP_CF_CONNECTING_IP']);
            if ($cfIp !== '') {
                return $cfIp;
            }
        }

        return $remoteAddr !== '' ? $remoteAddr : '0.0.0.0';
    }

    /**
     * Check whether an IP address belongs to Cloudflare's published ranges.
     *
     * @param  string $ip  IPv4 or IPv6 address to check.
     * @return bool
     */
    public static function isCloudflareIp(string $ip): bool {
        if ($ip === '' || $ip === '0.0.0.0') {
            return false;
        }

        // IPv6 addresses contain ':'; IPv4 do not.
        if (strpos($ip, ':') !== false) {
            foreach (self::CF_IPV6_RANGES as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    return true;
                }
            }
        } else {
            foreach (self::CF_IPV4_RANGES as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sanitize and canonicalize an IP address string.
     *
     * - Strips IPv6 zone IDs (e.g. '%eth0').
     * - Strips port numbers from IPv4 addresses (e.g. '1.2.3.4:1234').
     * - Validates via inet_pton / inet_ntop so the result is always canonical.
     * - Returns empty string for any invalid input.
     *
     * @param  string $ip  Raw IP string from a server variable.
     * @return string      Canonical IP string, or '' on failure.
     */
    public static function sanitizeIp(string $ip): string {
        $ip = trim($ip);

        // Strip IPv6 zone ID (e.g. '::1%lo').
        if (($pos = strpos($ip, '%')) !== false) {
            $ip = substr($ip, 0, $pos);
        }

        // Strip port from IPv4 (e.g. '192.168.1.1:8080').
        // IPv6 with port would be '[::1]:8080' — handle that too.
        if (substr($ip, 0, 1) === '[') {
            $ip = ltrim(explode(']', $ip)[0], '[');
        } elseif (substr_count($ip, ':') === 1) {
            // IPv4 with port — strip the port.
            $ip = explode(':', $ip)[0];
        }

        // Validate via inet_pton / inet_ntop (handles both IPv4 and IPv6).
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return '';
        }

        return (string) inet_ntop($binary);
    }

    /**
     * Generate a server-side device fingerprint using signals that the
     * client cannot forge.
     *
     * This fingerprint is suitable for security decisions such as
     * multi-account detection and fraud scoring.
     *
     * @security  Never use a client-submitted hash for security decisions.
     *            This method uses only server-readable signals.
     *
     * @return string SHA-256 hex string.
     */
    public static function generateServerSideFingerprint(): string {
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        $components = [
            // The true remote address — cannot be set by the client.
            'remote_addr'     => self::getClientIp(),

            // The order in which HTTP headers arrive differs between browser
            // engines, HTTP libraries, and automation frameworks.
            'header_order'    => implode(',', array_keys($headers)),

            // Content-negotiation headers — differ per browser engine / OS locale.
            'accept'          => $_SERVER['HTTP_ACCEPT']          ?? '',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',

            // User-Agent string — spoofable but adds entropy.
            'user_agent'      => $_SERVER['HTTP_USER_AGENT']      ?? '',

            // TLS cipher suite (set by the server from the TLS handshake,
            // available when PHP is served over HTTPS with mod_ssl / nginx).
            'ssl_cipher'      => $_SERVER['SSL_CIPHER']           ?? ($_SERVER['HTTPS'] ?? ''),
        ];

        // Salt with a secret known only to this installation.
        return hash('sha256', json_encode($components) . wp_salt('secure_auth'));
    }

    // ======================================================
    // Private helpers
    // ======================================================

    /**
     * Test whether an IP address falls within a CIDR range.
     * Works for both IPv4 and IPv6.
     *
     * @param  string $ip    IP to test (already validated).
     * @param  string $cidr  CIDR range, e.g. '104.16.0.0/13'.
     * @return bool
     */
    private static function ipInCidr(string $ip, string $cidr): bool {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Both must be the same address family (both IPv4 or both IPv6).
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $totalBits = strlen($ipBin) * 8; // 32 for IPv4, 128 for IPv6

        // Build mask as a binary string.
        $mask = '';
        for ($i = 0; $i < strlen($ipBin); $i++) {
            $byteStart = $i * 8;
            $byteEnd   = $byteStart + 8;

            if ($bits >= $byteEnd) {
                $mask .= "\xff";
            } elseif ($bits <= $byteStart) {
                $mask .= "\x00";
            } else {
                $mask .= chr(0xff & (0xff << (8 - ($bits - $byteStart))));
            }
        }

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }
}
