<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HOW MANY DELEGATORS A VALIDATOR HAS — OR **UNKNOWN**, WHICH IS NOT ZERO.
 *
 * ── THE DEFECT THIS EXISTS TO REMOVE ────────────────────────────────────
 * The enrichment path used to read the count like this:
 *
 *     $delegations     = $this->lcdGet(".../validators/{$valoper}/delegations", …);
 *     $delegator_count = (int) ($delegations['pagination']['total'] ?? 0);
 *
 * `lcdGet()` folds EVERY failure — transport error, 429, 4xx refusal, 5xx,
 * unparseable JSON — into `null`. `null['pagination']['total']` is absent,
 * `?? 0` supplies zero, and `(int)` makes it look deliberate. So a provider
 * that refused the request wrote **0 delegators** over a real, previously
 * correct number, on a field that is persisted and rendered on validator
 * cards ({@see \BCC\Trust\Core\Services\CardViewService}) and in the Hall
 * chain profile ({@see \BCC\Trust\Core\Services\HallsService}).
 *
 * It is provider-independent: any endpoint that ever fails this one call
 * corrupts the column. It was found while auditing a candidate endpoint that
 * refuses this route outright, but the bug was always live.
 *
 * ⚠ **UNKNOWN AND ZERO ARE DIFFERENT FACTS.** This class returns `null` for
 * "I could not learn the number" and an `int >= 0` only when a structurally
 * valid success response stated one. Callers persist the PREVIOUS value on
 * null — never zero, never a guess.
 *
 * ── WHY THE PARSE IS FUSSY ──────────────────────────────────────────────
 * cosmos-sdk serialises `pagination.total` as a uint64 **JSON string**, so
 * the naive `(int)` cast is lossy in three separate ways:
 *
 *   - `(int) "abc"`            === 0   → malformed becomes "no delegators"
 *   - `(int) "12.9"`           === 12  → silently truncates
 *   - `(int) "99999999999999999999"` saturates at PHP_INT_MAX on 64-bit
 *     (and differs on 32-bit) → an overflow becomes a plausible number
 *
 * Every one of those is rejected here as UNKNOWN rather than converted.
 */
final class DelegatorCount
{
    /**
     * Extract the delegator total from a `/…/delegations` response body.
     *
     * @param  array<string, mixed>|null $data decoded LCD body, or null.
     * @return int|null                  >= 0 when explicitly stated; null when unknown.
     */
    public static function fromDelegationsResponse(?array $data): ?int
    {
        if ($data === null) {
            return null;
        }

        $pagination = $data['pagination'] ?? null;
        if (!is_array($pagination)) {
            return null;
        }
        // `count_total` was requested; a body without `total` did not answer
        // the question. Absent is unknown, not zero.
        if (!array_key_exists('total', $pagination)) {
            return null;
        }

        return self::parseTotal($pagination['total']);
    }

    /**
     * PURE. A cosmos-sdk uint64 total -> a non-negative PHP int, or null.
     *
     * Accepts a JSON string of digits (the wire format) or a genuine int.
     * Rejects floats outright: a float cannot represent every uint64 exactly,
     * so accepting one would be the lossy conversion this class exists to
     * prevent.
     *
     * @param mixed $raw
     */
    public static function parseTotal($raw): ?int
    {
        if (is_int($raw)) {
            return $raw >= 0 ? $raw : null;
        }

        if (!is_string($raw)) {
            // bool, float, array, null, object — none is a uint64.
            return null;
        }

        $candidate = trim($raw);
        // Digits only. No sign, no decimal point, no exponent, no whitespace
        // inside. "-1", "1.0", "1e3", "0x10" and "" are all unknown.
        if ($candidate === '' || preg_match('/^[0-9]+$/', $candidate) !== 1) {
            return null;
        }

        // Normalise leading zeros so "007" compares as "7".
        $normalized = ltrim($candidate, '0');
        if ($normalized === '') {
            return 0;
        }

        // Overflow check done on the STRING, before any cast can saturate.
        $max = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($max)) {
            return null;
        }
        if (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0) {
            return null;
        }

        return (int) $normalized;
    }

    /**
     * Resolve what to persist: the freshly learned value when known, else the
     * value already stored.
     *
     * Keeping this here rather than at the call site means every caller gets
     * the same answer to "what happens when the provider did not tell us",
     * and a test can pin it without touching the network.
     */
    public static function resolve(?int $fetched, ?int $previous): ?int
    {
        return $fetched ?? $previous;
    }
}
