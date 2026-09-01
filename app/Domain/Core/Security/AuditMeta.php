<?php
/**
 * Audit metadata encoder — the ONLY place `$meta` becomes durable.
 *
 * WHY THIS EXISTS
 * ---------------
 * `AuditLogger::log()` has always accepted `array $meta` and always thrown it
 * away: `wp_bcc_trust_activity` had no column to put it in. Persisting it fixes
 * a real silent data-loss bug (~70 call sites carrying context that was never
 * recorded), but it also promotes every one of those payloads from "transient"
 * to "durable for 90 days, then archived indefinitely". A static survey of the
 * tree found **112 distinct meta keys across 70 call sites**, several carrying
 * data that must not simply be written verbatim:
 *
 *  - `RateLimiter` puts the RAW CLIENT IP in `$meta['ip']`. The table already
 *    stores `ip_address` as VARBINARY and the read path masks it through
 *    {@see AuditLogRepository::maskIpBinary()}. Writing the raw IP into meta
 *    would create a SECOND, UNMASKED IP CHANNEL that bypasses that masking
 *    entirely — the same value, same row, no mask. This is the sharpest case.
 *  - `CronService` stores `substr($e->getMessage(), 0, 255)` — raw exception
 *    text, which routinely carries file paths and SQL fragments.
 *  - `PasswordAuthController` stores an UNSALTED `sha1($email)`, a stable and
 *    lookup-reversible cross-reference key over a known user base.
 *  - `UserStatusController` / `DeviceFingerprinter` store the FULL device
 *    fingerprint hash — while DeviceFingerprinter's own server-fingerprint
 *    call already truncates to 20 chars. Inconsistent today; durable tomorrow.
 *  - `ModerationService::logAction()` injects `admin_name` (a display name)
 *    into EVERY moderation record, though it is already derivable from
 *    `admin_id`.
 *  - `FraudDetector` stores an unbounded `voter_ids` array.
 *
 * ONE BOUNDARY, NOT SEVENTY CALL SITES
 * ------------------------------------
 * Redaction is applied centrally, here, for the same reason PR 5a routed all
 * identifier normalisation through one service: a policy spread across 70 call
 * sites is a policy that the 71st caller silently opts out of. Callers keep
 * their existing signatures and learn nothing new.
 *
 * DEFENCE IN DEPTH
 * ----------------
 * Key-name rules alone are brittle — a future caller writing `client_ip`
 * instead of `ip` would sail straight past them. So the policy has three
 * independent layers:
 *
 *   1. per-key transforms for the known-sensitive keys (drop / mask / truncate)
 *   2. VALUE-SHAPE scrubbing regardless of key name — anything that looks like
 *      an IP or an email address is masked wherever it appears, including
 *      inside a raw exception message
 *   3. structural caps (depth, elements, scalar length, total encoded bytes)
 *      so no payload can bloat the table, truncating with an explicit marker
 *      rather than silently
 *
 * ENCODE FAILURE IS NOT EMPTINESS
 * -------------------------------
 * `encode()` returns a shaped array, never a bare `?string`, precisely so that
 * "there was no metadata" and "the metadata could not be encoded" cannot
 * collapse into the same `null`. The caller must be able to tell them apart:
 * one is normal, the other needs a degradation metric.
 *
 * @package BCC\Trust\Core\Security
 * @since 2026-09-01 (durable audit metadata)
 */

namespace BCC\Trust\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Core\Repositories\AuditLogRepository;

final class AuditMeta {

    /**
     * Hard ceiling on the stored JSON. The column is LONGTEXT, so this is a
     * policy limit, not a storage one: audit metadata is context, not a
     * payload store, and an unbounded blob per row would make the table's
     * growth unpredictable.
     */
    public const MAX_ENCODED_BYTES = 4096;

    /** Nesting beyond this is flattened to a marker. */
    public const MAX_DEPTH = 4;

    /** Per-array element cap (applies to lists like `voter_ids`). */
    public const MAX_ARRAY_ELEMENTS = 25;

    /** Per-scalar string cap, applied before the total-size cap. */
    public const MAX_SCALAR_LENGTH = 255;

    /** Appended wherever this class shortened a value. Never silent. */
    public const TRUNCATION_MARKER = '…[truncated]';

    /** Replaces a value that policy removes entirely. */
    public const REDACTED_MARKER = '[redacted]';

    /**
     * Keys dropped outright: the value is either PII that adds nothing the row
     * does not already carry, or a durable cross-reference key.
     *
     * `admin_name` — a display name, already derivable from `admin_id`.
     * `email_hash` — unsalted sha1 of an email; durable storage turns it into
     *                a permanent join key against any known address.
     *
     * @var list<string>
     */
    private const DROP_KEYS = [
        'admin_name',
        'email_hash',
    ];

    /**
     * Keys whose value is an IP literal. Masked through the SAME function the
     * read path uses for `ip_address`, so there is exactly one definition of
     * "how much of an IP we keep" in the codebase.
     *
     * @var list<string>
     */
    private const IP_KEYS = [
        'ip',
        'client_ip',
        'ip_address',
        'remote_addr',
    ];

    /**
     * OPAQUE IDENTIFIERS shortened to a prefix. These are hashes, not prose:
     * a prefix is still useful for correlation and carries no sentence, no
     * path and no quoted value. 20 chars matches what DeviceFingerprinter
     * already does for the server fingerprint, making an existing
     * inconsistency consistent in the safer direction.
     *
     * @var array<string, int>
     */
    private const TRUNCATE_KEYS = [
        'fingerprint'        => 20,
        'server_fingerprint' => 20,
        'device_fingerprint' => 20,
    ];

    /**
     * FREE TEXT — content is REPLACED, never merely shortened.
     *
     * TRUNCATION IS NOT REDACTION. `substr($e->getMessage(), 0, 120)` keeps
     * the FIRST 120 characters, and the first 120 characters of an exception
     * are exactly where the damage lives: the SQL fragment, the absolute file
     * path, the connection string, the quoted value that failed. Shortening a
     * leak does not stop it being a leak — it just makes it a shorter one, and
     * it is now durable for 90 days and archived after that.
     *
     * The same holds for moderation notes, which are private operator prose
     * about a member and may name third parties.
     *
     * So these keys keep no content at all, and no derivative of it. Each is
     * replaced with `{omitted, len}` — enough to show an operator that text
     * existed and roughly how much, and nothing that could identify it.
     *
     * Deliberately NOT stored: any hash or fingerprint of the omitted text.
     * See {@see describeText()} — a digest over guessable prose is a
     * confirmation oracle, which is the same reason `email_hash` is dropped
     * outright above. Correlation of repeated errors belongs in a bounded
     * `error_code` / exception-class value chosen at the caller.
     *
     * The operational cost is near zero: the sites that pass these keys
     * already write the full text to the FILE log next to the audit call
     * (e.g. `CronService` logs the quarantine error via `Logger::error` on the
     * line after its `AuditLogger::log`), and the file log has its own, much
     * shorter retention. Nothing that was previously diagnosable stops being
     * diagnosable; it just stops being permanent.
     *
     * @var list<string>
     */
    private const STRUCTURED_TEXT_KEYS = [
        'last_error',
        'error',
        'error_message',
        'exception',
        'exception_message',
        'message',
        'detail',
        'details',
        'trace',
        'stack_trace',
        'query',
        'sql',
        'note',
        'notes',
        'reason_text',
        'moderation_note',
        'moderation_notes',
        'admin_note',
        'admin_notes',
        'report_text',
        'body',
        'content',
        'comment',
    ];

    /**
     * Encode metadata for durable storage.
     *
     * @param  array<string, mixed> $meta
     * @return array{json: string|null, failed: bool}
     *         json=null, failed=false  -> genuinely no metadata (store NULL)
     *         json=string              -> store this
     *         json=null, failed=true   -> encoding failed; store NULL, but the
     *                                     caller MUST record a degradation
     *                                     metric and still write the base row
     */
    public static function encode(array $meta): array {
        if ($meta === []) {
            return ['json' => null, 'failed' => false];
        }

        $clean = self::redact($meta);

        if ($clean === []) {
            // Everything in the payload was dropped by policy. That is a
            // successful encode of "nothing worth keeping", not a failure.
            return ['json' => null, 'failed' => false];
        }

        // wp_json_encode() returns false rather than throwing on malformed
        // UTF-8 or a resource/closure that survived redaction.
        $json = wp_json_encode($clean, 0, self::MAX_DEPTH + 1);

        if (!is_string($json)) {
            return ['json' => null, 'failed' => true];
        }

        if (strlen($json) > self::MAX_ENCODED_BYTES) {
            // Do not emit invalid JSON by cutting the string. Replace the
            // payload with a well-formed marker object that records what
            // happened, so an operator reading the row knows the difference
            // between "no metadata" and "metadata too large".
            $marker = wp_json_encode([
                '_truncated'     => true,
                '_original_keys' => array_slice(array_keys($clean), 0, self::MAX_ARRAY_ELEMENTS),
                '_original_size' => strlen($json),
            ]);

            return is_string($marker)
                ? ['json' => $marker, 'failed' => false]
                : ['json' => null, 'failed' => true];
        }

        return ['json' => $json, 'failed' => false];
    }

    /**
     * Apply the redaction policy. Public so tests can assert the policy
     * directly and so an operator-facing preview can show what would be kept.
     *
     * @param  array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function redact(array $meta): array {
        return self::walk($meta, 0);
    }

    /**
     * @param  array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    private static function walk(array $input, int $depth): array {
        if ($depth >= self::MAX_DEPTH) {
            return ['_depth_capped' => true];
        }

        $out   = [];
        $count = 0;

        foreach ($input as $key => $value) {
            if ($count >= self::MAX_ARRAY_ELEMENTS) {
                $out['_elements_omitted'] = count($input) - $count;
                break;
            }
            $count++;

            $lookup = is_string($key) ? strtolower($key) : '';

            if ($lookup !== '' && in_array($lookup, self::DROP_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::walk($value, $depth + 1);
                continue;
            }

            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                $out[$key] = $value;
                continue;
            }

            if (!is_string($value)) {
                // Objects, resources, closures. Never attempt to serialise
                // them into an audit row — record the type and move on.
                $out[$key] = self::REDACTED_MARKER . '(' . gettype($value) . ')';
                continue;
            }

            if ($lookup !== '' && in_array($lookup, self::IP_KEYS, true)) {
                $out[$key] = self::maskIpString($value);
                continue;
            }

            if ($lookup !== '' && in_array($lookup, self::STRUCTURED_TEXT_KEYS, true)) {
                $out[$key] = self::describeText($value);
                continue;
            }

            $limit = ($lookup !== '' && isset(self::TRUNCATE_KEYS[$lookup]))
                ? self::TRUNCATE_KEYS[$lookup]
                : self::MAX_SCALAR_LENGTH;

            $out[$key] = self::truncate(self::scrubValue($value), $limit);
        }

        return $out;
    }

    /**
     * Replace free text with a structured descriptor that keeps NONE of it.
     *
     * NO DIGEST. An earlier revision stored a truncated unsalted SHA-256 of
     * the omitted text as a "correlation handle". That was a mistake, and its
     * own docblock admitted the flaw: anyone holding a SUSPECTED sentence
     * could hash it and confirm whether that exact sentence was recorded.
     * For private moderation notes — operator prose about a member, often
     * naming third parties — that is a confirmation oracle over a small,
     * highly guessable message space, retained for 90 days and then archived
     * indefinitely.
     *
     * It is the same defect this class already rejects in `email_hash`: a hash
     * of low-entropy input is not anonymisation, it is a durable matching
     * handle. Something that is explicitly "not a security boundary" has no
     * business being kept forever.
     *
     * If recurring application errors need correlating later, the right answer
     * is a bounded, structured value chosen AT THE CALLER — an exception class
     * name or a stable `error_code` — passed under its own key, where it is
     * meaningful and reviewable. Not a fingerprint of the raw message.
     *
     * @return array{omitted: string, len: int}
     */
    private static function describeText(string $value): array {
        return [
            'omitted' => 'free_text',
            'len'     => function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value),
        ];
    }

    /**
     * Layer 2 — mask IP and email literals wherever they appear, whatever the
     * key is called. This is what protects against a future caller inventing a
     * new key name, and against an IP or address embedded inside a raw
     * exception message.
     */
    private static function scrubValue(string $value): string {
        $value = self::scrubCredentials($value);

        // Emails first: an address can contain characters the IP patterns
        // would otherwise chew into.
        $value = (string) preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            static fn(array $m): string => self::maskEmail($m[0]),
            $value
        );

        // IPv4 dotted quad, only when every octet is in range.
        $value = (string) preg_replace_callback(
            '/\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/',
            static function (array $m): string {
                foreach ([1, 2, 3, 4] as $i) {
                    if ((int) $m[$i] > 255) {
                        return $m[0]; // not an IP (a version string, a decimal)
                    }
                }
                return self::maskIpString($m[0]);
            },
            $value
        );

        // IPv6 — require at least three groups plus a colon-pair or five
        // groups, so ordinary "key: value" text and timestamps are not eaten.
        $value = (string) preg_replace_callback(
            '/\b(?:[0-9A-Fa-f]{1,4}:){2,7}(?::|[0-9A-Fa-f]{1,4})\b/',
            static function (array $m): string {
                $masked = self::maskIpString($m[0]);
                return $masked === self::REDACTED_MARKER ? $m[0] : $masked;
            },
            $value
        );

        return $value;
    }

    /**
     * Strip credential-shaped material from ANY string, whatever key it is
     * under. Free-text keys never reach this (their content is replaced
     * wholesale), so this is the net for a secret that turns up somewhere
     * nobody classified — a connection string in a `dsn` field, a header
     * echoed into `context`, a token pasted into an operator-supplied value.
     *
     * Deliberately conservative about what it declares a secret, because a
     * false positive corrupts legitimate context. It matches an explicit
     * `key = value` shape for known credential words, a `Bearer` token, and a
     * URL with inline userinfo — all three of which are unambiguous.
     */
    private static function scrubCredentials(string $value): string {
        // ORDER MATTERS. The scheme rules run FIRST, because the generic
        // `keyword = value` rule below would otherwise treat the SCHEME word
        // as the value: on `Authorization: Bearer eyJhbGci…` it matches
        // `Authorization:` + `Bearer`, redacts the word "Bearer", and leaves
        // the actual token standing. A stored-row test caught exactly that.

        // Bearer / Basic authorization values.
        $value = (string) preg_replace(
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._\-\/+=]{8,}/i',
            '$1 ' . self::REDACTED_MARKER,
            $value
        );

        // scheme://user:password@host — the userinfo half only.
        $value = (string) preg_replace(
            '#\b([a-z][a-z0-9+.\-]*://)[^/\s:@]+:[^/\s@]+@#i',
            '$1' . self::REDACTED_MARKER . '@',
            $value
        );

        // password=…, api_key: …, secret => …, authorization=…
        // The lookahead keeps this from re-consuming a scheme word (or an
        // already-inserted marker) as though it were the secret.
        $value = (string) preg_replace(
            '/\b(pass(?:word|wd)?|secret|token|api[_\-]?key|apikey|auth(?:orization)?|access[_\-]?key|private[_\-]?key|client[_\-]?secret|session[_\-]?id)\b\s*(=>|[:=])\s*(?!(?:Bearer|Basic)\b)(?!' . preg_quote(self::REDACTED_MARKER, '/') . ')("[^"]*"|\'[^\']*\'|\S+)/i',
            '$1$2' . self::REDACTED_MARKER,
            $value
        );

        return $value;
    }

    /**
     * Mask an IP given as text by routing it through the canonical binary
     * masker the read path already uses. Reuse rather than a second rule:
     * if the masking policy ever changes, it changes in one place.
     */
    private static function maskIpString(string $ip): string {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            // Not parseable as an address. It reached an IP-shaped position,
            // so fail closed rather than pass it through.
            return self::REDACTED_MARKER;
        }

        $masked = AuditLogRepository::maskIpBinary($binary);

        return $masked !== '' ? $masked : self::REDACTED_MARKER;
    }

    /**
     * Keep the first character of the local part and the domain, so operators
     * can still correlate "same domain" without the address being readable.
     */
    private static function maskEmail(string $email): string {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return self::REDACTED_MARKER;
        }

        return substr($email, 0, 1) . '***@' . substr($email, $at + 1);
    }

    /**
     * Multibyte-safe truncation that never splits a UTF-8 sequence — a broken
     * sequence would make wp_json_encode() fail and cost the whole payload.
     */
    private static function truncate(string $value, int $limit): string {
        if ($limit <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') <= $limit) {
                return $value;
            }
            return mb_substr($value, 0, $limit, 'UTF-8') . self::TRUNCATION_MARKER;
        }

        if (strlen($value) <= $limit) {
            return $value;
        }

        $cut = substr($value, 0, $limit);

        // Drop a dangling partial UTF-8 sequence at the cut point.
        while ($cut !== '' && !self::isValidUtf8($cut)) {
            $cut = substr($cut, 0, -1);
        }

        return $cut . self::TRUNCATION_MARKER;
    }

    private static function isValidUtf8(string $value): bool {
        return (bool) preg_match('//u', $value);
    }
}
