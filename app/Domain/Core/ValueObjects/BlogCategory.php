<?php
/**
 * Blog category discriminator (§D6 crypto-blog composer, PR-A).
 *
 * Mirrors {@see GroupType} — a tiny string-backed PHP 8 enum that
 * REST handlers parse via {@see self::tryFromString()} for safe
 * narrowing of inbound user input (no exceptions on unknown values
 * — REST returns `bcc_invalid_request` instead).
 *
 * Six cases enumerate the V1.5 composer surface; new cases require
 * a contract bump in docs/api-contract-v1.md §3.3.8 and a frontend
 * picker update. Adding cases is non-breaking for existing rows —
 * the post_meta value is opaque to readers, and the BlogService
 * hydrator returns `null` for unparseable category strings.
 *
 * @package BCC\Trust\Core\ValueObjects
 * @since V1.5 (2026-05, §D6 crypto-blog composer PR-A)
 */

namespace BCC\Trust\Core\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

enum BlogCategory: string {
    case News     = 'news';
    case Analysis = 'analysis';
    case Guide    = 'guide';
    case Opinion  = 'opinion';
    case Tools    = 'tools';
    case Events   = 'events';

    /**
     * Safe parser for inbound REST values.
     *
     * Returns `null` when:
     *   - the value is null
     *   - the value is not a known case
     *
     * Use this from REST handlers instead of `BlogCategory::from()`
     * — the latter throws on unknown strings, which would surface as
     * a 500 rather than the expected `bcc_invalid_request` envelope.
     */
    public static function tryFromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }
        return self::tryFrom($value);
    }
}
