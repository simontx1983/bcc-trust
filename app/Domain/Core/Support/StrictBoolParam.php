<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parse a request boolean, REFUSING anything that is not one (F-294).
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * The prefs routes read their switches with a bare
 * `filter_var($raw, FILTER_VALIDATE_BOOLEAN)`. That returns `false` for
 * everything it does not recognise, so `hide_from_search: "maybe"` (or a
 * typo, or a number other than 0/1) silently turned the setting OFF and
 * answered 200: the caller believed it had saved something it had not.
 * A privacy switch is exactly where a silent "off" does harm.
 *
 * `null` here means "not a boolean": the handler answers 422 with its own
 * code, before writing anything. The same reasoning as BoundedIntParam:
 * a schema validator would replace the house error code with
 * `rest_invalid_param`, so the refusal comes from the handler.
 *
 * Accepted (case-insensitive, surrounding whitespace ignored): a real
 * bool; the ints 1 / 0; the strings "1"/"0", "true"/"false", "yes"/"no",
 * "on"/"off". An empty string is NOT accepted (FILTER_VALIDATE_BOOLEAN
 * would read it as false).
 */
final class StrictBoolParam
{
    public static function parse(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return $raw === 1 ? true : ($raw === 0 ? false : null);
        }
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        /** @var bool|null $parsed */
        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed;
    }
}
